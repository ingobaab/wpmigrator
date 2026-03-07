<?php

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

foreach ( [ 'sodium', 'zlib' ] as $extension ) {
	if ( ! extension_loaded( $extension ) ) {
		fwrite( STDERR, "Missing required PHP extension: {$extension}\n" );
		exit( 1 );
	}
}

$config_path = isset( $argv[1] ) ? $argv[1] : __DIR__ . '/pull-config.json';
$artifact    = isset( $argv[2] ) ? $argv[2] : null;

if ( ! is_file( $config_path ) ) {
	fwrite( STDERR, "Config file not found: {$config_path}\n" );
	exit( 1 );
}

$config = json_decode( (string) file_get_contents( $config_path ), true );

if ( ! is_array( $config ) || empty( $config['migration_key'] ) ) {
	fwrite( STDERR, "Config must contain migration_key.\n" );
	exit( 1 );
}

$output_dir = isset( $config['output_dir'] ) && '' !== trim( (string) $config['output_dir'] )
	? (string) $config['output_dir']
	: sys_get_temp_dir() . '/flywp-puller';

if ( null === $artifact || '' === trim( (string) $artifact ) ) {
	$candidates = glob( rtrim( $output_dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.fwsnap' );

	if ( ! is_array( $candidates ) || empty( $candidates ) ) {
		fwrite( STDERR, "No .fwsnap artifact found in {$output_dir}\n" );
		exit( 1 );
	}

	usort(
		$candidates,
		static function ( $left, $right ) {
			return filemtime( $right ) <=> filemtime( $left );
		}
	);

	$artifact = $candidates[0];
}

if ( ! is_file( $artifact ) ) {
	fwrite( STDERR, "Artifact not found: {$artifact}\n" );
	exit( 1 );
}

function derive_secretstream_key( $migration_key, $snapshot_id, $salt, $purpose ) {
	return hash_hkdf(
		'sha256',
		(string) $migration_key,
		SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
		'flywp-migrator:' . $purpose . ':' . $snapshot_id,
		$salt
	);
}

function read_exact( $handle, $length ) {
	$buffer = '';

	while ( strlen( $buffer ) < $length && ! feof( $handle ) ) {
		$chunk = fread( $handle, $length - strlen( $buffer ) );

		if ( false === $chunk ) {
			return false;
		}

		$buffer .= $chunk;
	}

	return strlen( $buffer ) === $length ? $buffer : false;
}

function read_u32( $handle ) {
	$raw = read_exact( $handle, 4 );

	if ( false === $raw ) {
		return false;
	}

	$unpacked = unpack( 'Nvalue', $raw );

	return (int) $unpacked['value'];
}

function stream_plain_chunks( $handle, $state ) {
	while ( true ) {
		$length_raw = fread( $handle, 4 );

		if ( false === $length_raw ) {
			throw new RuntimeException( 'Could not read encrypted chunk length.' );
		}

		if ( '' === $length_raw ) {
			return;
		}

		if ( 4 !== strlen( $length_raw ) ) {
			throw new RuntimeException( 'Unexpected EOF while reading encrypted chunk length.' );
		}

		$length_info = unpack( 'Nvalue', $length_raw );
		$length      = (int) $length_info['value'];

		$ciphertext = read_exact( $handle, $length );
		if ( false === $ciphertext ) {
			throw new RuntimeException( 'Unexpected EOF while reading encrypted chunk payload.' );
		}

		$pulled = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $ciphertext );
		if ( false === $pulled ) {
			throw new RuntimeException( 'Could not decrypt secretstream chunk.' );
		}

		yield [
			'message' => $pulled[0],
			'tag'     => $pulled[1],
		];
	}
}

function inflate_all( $gzip_bytes ) {
	$inflated = @gzdecode( $gzip_bytes );

	if ( false === $inflated ) {
		throw new RuntimeException( 'Could not gunzip embedded database dump.' );
	}

	return $inflated;
}

$fp = fopen( $artifact, 'rb' );

if ( false === $fp ) {
	fwrite( STDERR, "Could not open artifact: {$artifact}\n" );
	exit( 1 );
}

$magic = read_exact( $fp, 8 );

if ( "FWSNAP1\n" !== $magic ) {
	fwrite( STDERR, "Invalid archive magic.\n" );
	exit( 1 );
}

$header_length = read_u32( $fp );
$header_json   = read_exact( $fp, $header_length );
$header        = json_decode( (string) $header_json, true );

if ( ! is_array( $header ) ) {
	fwrite( STDERR, "Invalid archive header JSON.\n" );
	exit( 1 );
}

echo "Artifact: {$artifact}\n";
echo "Snapshot ID: " . ( $header['snapshot_id'] ?? '(unknown)' ) . "\n";
echo "Created At: " . ( $header['created_at'] ?? '(unknown)' ) . "\n";
echo "Site URL: " . ( $header['site_url'] ?? '(unknown)' ) . "\n";
echo "Files: " . (int) ( $header['total_files'] ?? 0 ) . "\n";
echo "Directories: " . (int) ( $header['total_directories'] ?? 0 ) . "\n";
echo "Payload Bytes: " . (int) ( $header['archive_payload_bytes'] ?? 0 ) . "\n";
echo "Embedded Dump: " . ( $header['dump_relative_path'] ?? '(unknown)' ) . "\n";

$payload_key = derive_secretstream_key(
	$config['migration_key'],
	(string) $header['snapshot_id'],
	base64_decode( (string) $header['salt'], true ),
	'filesystem-snapshot'
);
$payload_state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
	base64_decode( (string) $header['stream_header'], true ),
	$payload_key
);

$plain_chunks = stream_plain_chunks( $fp, $payload_state );
$plain_chunks->rewind();

$expected_entries     = (int) ( $header['total_files'] ?? 0 ) + (int) ( $header['total_directories'] ?? 0 );
$entry_count          = 0;
$verified_files       = 0;
$verified_directories = 0;
$compressed_files     = 0;
$embedded_dump_bytes  = null;
$saw_final_tag        = false;

while ( $entry_count < $expected_entries ) {
	if ( ! $plain_chunks->valid() ) {
		throw new RuntimeException( 'Encrypted payload ended before all header-declared entries were read.' );
	}

	$current = $plain_chunks->current();
	$plain_chunks->next();

	if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $current['tag'] ) {
		throw new RuntimeException( 'Encountered final secretstream tag before all entries were read.' );
	}

	$record = $current['message'];
	if ( strlen( $record ) < 45 ) {
		throw new RuntimeException( 'Entry record header is too short.' );
	}

	$type_info    = unpack( 'Ctype/Nhigh/Nlow/Npath_length', substr( $record, 0, 13 ) );
	$type         = (int) $type_info['type'];
	$payload_size = ( (int) $type_info['high'] * 4294967296 ) + (int) $type_info['low'];
	$path_length  = (int) $type_info['path_length'];
	$sha256_raw   = substr( $record, 13, 32 );
	$path         = substr( $record, 45 );

	if ( strlen( $path ) !== $path_length ) {
		throw new RuntimeException( 'Entry path length does not match header.' );
	}

	$entry_count++;

	if ( 0 === $type ) {
		$verified_directories++;
		continue;
	}

	$hash_ctx      = hash_init( 'sha256' );
	$remaining     = $payload_size;
	$stored_bytes  = '';
	$is_embedded   = $path === ( $header['dump_relative_path'] ?? '' );

	while ( $remaining > 0 ) {
		if ( ! $plain_chunks->valid() ) {
			throw new RuntimeException( "Unexpected EOF while reading payload for {$path}" );
		}

		$current = $plain_chunks->current();
		$plain_chunks->next();

		if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $current['tag'] ) {
			throw new RuntimeException( "Unexpected final tag inside payload for {$path}" );
		}

		$message = $current['message'];
		$consume = min( $remaining, strlen( $message ) );

		if ( $consume !== strlen( $message ) ) {
			throw new RuntimeException( "Payload framing mismatch for {$path}" );
		}

		hash_update( $hash_ctx, $message );

		if ( $is_embedded ) {
			$stored_bytes .= $message;
		}

		$remaining -= $consume;
	}

	$actual_hash = hash_final( $hash_ctx, true );
	if ( ! hash_equals( $sha256_raw, $actual_hash ) ) {
		throw new RuntimeException( "Checksum mismatch for {$path}" );
	}

	$verified_files++;
	if ( 2 === $type ) {
		$compressed_files++;
	}

	if ( $is_embedded ) {
		$embedded_dump_bytes = $stored_bytes;
	}
}

if ( $plain_chunks->valid() ) {
	$current = $plain_chunks->current();
	if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $current['tag'] && '' === $current['message'] ) {
		$saw_final_tag = true;
	}
}

echo "Verified entries: {$entry_count}\n";
echo "Verified files: {$verified_files}\n";
echo "Verified directories: {$verified_directories}\n";
echo "Compressed files: {$compressed_files}\n";
echo "Final stream marker: " . ( $saw_final_tag ? 'present' : 'missing' ) . "\n";

if ( null === $embedded_dump_bytes ) {
	throw new RuntimeException( 'Embedded database dump was not found in the filesystem snapshot.' );
}

$db_handle = fopen( 'php://temp', 'w+b' );
fwrite( $db_handle, $embedded_dump_bytes );
rewind( $db_handle );

$db_header_length = read_u32( $db_handle );
$db_header_json   = read_exact( $db_handle, $db_header_length );
$db_header        = json_decode( (string) $db_header_json, true );

if ( ! is_array( $db_header ) ) {
	throw new RuntimeException( 'Invalid embedded database dump header.' );
}

$db_key = derive_secretstream_key(
	$config['migration_key'],
	(string) $db_header['snapshot'],
	base64_decode( (string) $db_header['salt'], true ),
	(string) $db_header['purpose']
);
$db_state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
	base64_decode( (string) $db_header['stream'], true ),
	$db_key
);

$db_plain_chunks = stream_plain_chunks( $db_handle, $db_state );
$db_plain_chunks->rewind();
$gzip_dump = '';
$db_final  = false;

while ( $db_plain_chunks->valid() ) {
	$current = $db_plain_chunks->current();
	$db_plain_chunks->next();

	if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $current['tag'] ) {
		$gzip_dump .= $current['message'];
		$db_final = true;
		break;
	}

	$gzip_dump .= $current['message'];
}

$sql_dump = inflate_all( $gzip_dump );

echo "Embedded DB snapshot ID: " . ( $db_header['snapshot'] ?? '(unknown)' ) . "\n";
echo "Embedded DB gzip bytes: " . strlen( $gzip_dump ) . "\n";
echo "Embedded DB SQL bytes: " . strlen( $sql_dump ) . "\n";
echo "Embedded DB SQL SHA-256: " . hash( 'sha256', $sql_dump ) . "\n";
echo "Embedded DB final marker: " . ( $db_final ? 'present' : 'missing' ) . "\n";

$preview_lines = array_slice( preg_split( "/\r\n|\n|\r/", $sql_dump ), 0, 5 );
echo "SQL preview:\n";
foreach ( $preview_lines as $line ) {
	echo $line . "\n";
}
