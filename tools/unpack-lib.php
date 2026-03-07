<?php

function flywp_unpack_default_config_path() {
	return __DIR__ . '/pull-config.json';
}

function flywp_unpack_load_config( $config_path ) {
	if ( ! is_file( $config_path ) ) {
		throw new RuntimeException( "Config file not found: {$config_path}" );
	}

	$config = json_decode( (string) file_get_contents( $config_path ), true );

	if ( ! is_array( $config ) || empty( $config['migration_key'] ) ) {
		throw new RuntimeException( 'Config must contain migration_key.' );
	}

	$output_dir = isset( $config['output_dir'] ) && '' !== trim( (string) $config['output_dir'] )
		? (string) $config['output_dir']
		: sys_get_temp_dir() . '/flywp-puller';

	return [
		'config_path'    => $config_path,
		'migration_key'  => (string) $config['migration_key'],
		'output_dir'     => $output_dir,
	];
}

function flywp_unpack_find_latest_artifact( $output_dir ) {
	$candidates = glob( rtrim( $output_dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.fwsnap' );

	if ( ! is_array( $candidates ) || empty( $candidates ) ) {
		throw new RuntimeException( "No .fwsnap artifact found in {$output_dir}" );
	}

	usort(
		$candidates,
		static function ( $left, $right ) {
			return filemtime( $right ) <=> filemtime( $left );
		}
	);

	return $candidates[0];
}

function flywp_unpack_derive_secretstream_key( $migration_key, $snapshot_id, $salt, $purpose ) {
	return hash_hkdf(
		'sha256',
		(string) $migration_key,
		SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
		'flywp-migrator:' . $purpose . ':' . $snapshot_id,
		$salt
	);
}

function flywp_unpack_read_exact( $handle, $length ) {
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

function flywp_unpack_read_u32( $handle ) {
	$raw = flywp_unpack_read_exact( $handle, 4 );

	if ( false === $raw ) {
		return false;
	}

	$unpacked = unpack( 'Nvalue', $raw );

	return (int) $unpacked['value'];
}

function flywp_unpack_stream_plain_chunks( $handle, $state ) {
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

		$ciphertext = flywp_unpack_read_exact( $handle, $length );
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

function flywp_unpack_stream_gzip_file_to_file( $gzip_path, $target_path ) {
	$source = fopen( $gzip_path, 'rb' );
	$target = fopen( $target_path, 'wb' );

	if ( false === $source || false === $target ) {
		if ( false !== $source ) {
			fclose( $source );
		}
		if ( false !== $target ) {
			fclose( $target );
		}

		throw new RuntimeException( "Could not open gzip decode streams for {$gzip_path}" );
	}

	if ( function_exists( 'inflate_init' ) && function_exists( 'inflate_add' ) ) {
		$inflate = inflate_init( ZLIB_ENCODING_GZIP );
		if ( false === $inflate ) {
			fclose( $source );
			fclose( $target );
			throw new RuntimeException( "Could not initialize gzip decoder for {$gzip_path}" );
		}

		while ( ! feof( $source ) ) {
			$chunk = fread( $source, 1024 * 1024 );
			if ( false === $chunk ) {
				fclose( $source );
				fclose( $target );
				throw new RuntimeException( "Could not read gzip payload from {$gzip_path}" );
			}

			if ( '' === $chunk ) {
				continue;
			}

			$decoded = inflate_add( $inflate, $chunk, feof( $source ) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH );
			if ( false === $decoded ) {
				fclose( $source );
				fclose( $target );
				throw new RuntimeException( "Could not decode gzip payload from {$gzip_path}" );
			}

			if ( '' !== $decoded && false === fwrite( $target, $decoded ) ) {
				fclose( $source );
				fclose( $target );
				throw new RuntimeException( "Could not write decoded payload to {$target_path}" );
			}
		}

		fclose( $source );
		fclose( $target );
		return;
	}

	$gzip_bytes = file_get_contents( $gzip_path );
	$inflated   = @gzdecode( (string) $gzip_bytes );

	fclose( $source );

	if ( false === $inflated ) {
		fclose( $target );
		throw new RuntimeException( "Could not gunzip payload from {$gzip_path}" );
	}

	if ( false === fwrite( $target, $inflated ) ) {
		fclose( $target );
		throw new RuntimeException( "Could not write decoded payload to {$target_path}" );
	}

	fclose( $target );
}

function flywp_unpack_normalize_relative_path( $path ) {
	$path  = str_replace( '\\', '/', (string) $path );
	$parts = [];

	foreach ( explode( '/', $path ) as $part ) {
		if ( '' === $part || '.' === $part ) {
			continue;
		}

		if ( '..' === $part ) {
			throw new RuntimeException( "Unsafe archive path: {$path}" );
		}

		$parts[] = $part;
	}

	return implode( '/', $parts );
}

function flywp_unpack_output_path( $output_dir, $relative_path ) {
	$relative_path = flywp_unpack_normalize_relative_path( $relative_path );

	return rtrim( $output_dir, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
}

function flywp_unpack_sql_relative_path( $db_relative_path ) {
	$relative = flywp_unpack_normalize_relative_path( (string) $db_relative_path );

	if ( '' === $relative ) {
		return '__flywp/database/latest.sql';
	}

	$info      = pathinfo( $relative );
	$directory = isset( $info['dirname'] ) && '.' !== $info['dirname'] ? $info['dirname'] . '/' : '';
	$filename  = isset( $info['filename'] ) ? $info['filename'] : 'latest';

	return $directory . $filename . '.sql';
}

function flywp_unpack_mkdir( $dir ) {
	if ( is_dir( $dir ) ) {
		return;
	}

	if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		throw new RuntimeException( "Could not create directory: {$dir}" );
	}
}

function flywp_unpack_load_archive_header( $artifact, $migration_key ) {
	$fp = fopen( $artifact, 'rb' );

	if ( false === $fp ) {
		throw new RuntimeException( "Could not open artifact: {$artifact}" );
	}

	$magic = flywp_unpack_read_exact( $fp, 8 );
	if ( "FWSNAP1\n" !== $magic ) {
		fclose( $fp );
		throw new RuntimeException( 'Invalid archive magic.' );
	}

	$header_length = flywp_unpack_read_u32( $fp );
	$header_json   = flywp_unpack_read_exact( $fp, $header_length );
	$header        = json_decode( (string) $header_json, true );

	if ( ! is_array( $header ) ) {
		fclose( $fp );
		throw new RuntimeException( 'Invalid archive header JSON.' );
	}

	$payload_key = flywp_unpack_derive_secretstream_key(
		$migration_key,
		(string) $header['snapshot_id'],
		base64_decode( (string) $header['salt'], true ),
		'filesystem-snapshot'
	);
	$payload_state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
		base64_decode( (string) $header['stream_header'], true ),
		$payload_key
	);

	return [
		'handle'        => $fp,
		'header'        => $header,
		'payload_state' => $payload_state,
	];
}

function flywp_unpack_decrypt_database_dump( $encrypted_dump_path, $migration_key, $target_dir, $db_relative_path ) {
	$db_handle = fopen( $encrypted_dump_path, 'rb' );
	if ( false === $db_handle ) {
		throw new RuntimeException( "Could not open embedded database dump: {$encrypted_dump_path}" );
	}

	$db_header_length = flywp_unpack_read_u32( $db_handle );
	$db_header_json   = flywp_unpack_read_exact( $db_handle, $db_header_length );
	$db_header        = json_decode( (string) $db_header_json, true );

	if ( ! is_array( $db_header ) ) {
		fclose( $db_handle );
		throw new RuntimeException( 'Invalid embedded database dump header.' );
	}

	$db_key = flywp_unpack_derive_secretstream_key(
		$migration_key,
		(string) $db_header['snapshot'],
		base64_decode( (string) $db_header['salt'], true ),
		(string) $db_header['purpose']
	);
	$db_state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
		base64_decode( (string) $db_header['stream'], true ),
		$db_key
	);

	$db_plain_chunks = flywp_unpack_stream_plain_chunks( $db_handle, $db_state );
	$db_plain_chunks->rewind();

	$decoded_relative = flywp_unpack_sql_relative_path( $db_relative_path );
	$sql_path         = flywp_unpack_output_path( $target_dir, $decoded_relative );
	flywp_unpack_mkdir( dirname( $sql_path ) );

	$sql_handle = fopen( $sql_path, 'wb' );
	if ( false === $sql_handle ) {
		fclose( $db_handle );
		throw new RuntimeException( "Could not open SQL output file: {$sql_path}" );
	}

	$db_final     = false;
	$gzip_bytes   = 0;
	$sql_bytes    = 0;
	$sql_hash_ctx = hash_init( 'sha256' );
	$inflate      = function_exists( 'inflate_init' ) ? inflate_init( ZLIB_ENCODING_GZIP ) : false;
	$gzip_buffer  = '';

	while ( $db_plain_chunks->valid() ) {
		$current = $db_plain_chunks->current();
		$db_plain_chunks->next();

		$gzip_chunk  = $current['message'];
		$gzip_bytes += strlen( $gzip_chunk );

		if ( false !== $inflate ) {
			$decoded = inflate_add( $inflate, $gzip_chunk, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $current['tag'] ? ZLIB_FINISH : ZLIB_SYNC_FLUSH );
			if ( false === $decoded ) {
				fclose( $db_handle );
				fclose( $sql_handle );
				throw new RuntimeException( 'Could not decode embedded database gzip stream.' );
			}

			if ( '' !== $decoded ) {
				if ( false === fwrite( $sql_handle, $decoded ) ) {
					fclose( $db_handle );
					fclose( $sql_handle );
					throw new RuntimeException( "Could not write SQL dump to {$sql_path}" );
				}
				hash_update( $sql_hash_ctx, $decoded );
				$sql_bytes += strlen( $decoded );
			}
		} else {
			$gzip_buffer .= $gzip_chunk;
		}

		if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $current['tag'] ) {
			$db_final = true;
			break;
		}
	}

	fclose( $db_handle );

	if ( ! $db_final ) {
		fclose( $sql_handle );
		throw new RuntimeException( 'Embedded database dump final marker is missing.' );
	}

	if ( false === $inflate ) {
		$sql_dump = @gzdecode( $gzip_buffer );
		if ( false === $sql_dump ) {
			fclose( $sql_handle );
			throw new RuntimeException( 'Could not gunzip embedded database dump.' );
		}
		if ( false === fwrite( $sql_handle, $sql_dump ) ) {
			fclose( $sql_handle );
			throw new RuntimeException( "Could not write SQL dump to {$sql_path}" );
		}
		hash_update( $sql_hash_ctx, $sql_dump );
		$sql_bytes = strlen( $sql_dump );
	}

	fclose( $sql_handle );

	return [
		'db_header'       => $db_header,
		'gzip_bytes'      => $gzip_bytes,
		'sql_bytes'       => $sql_bytes,
		'sql_path'        => $sql_path,
		'sql_sha256'      => hash_final( $sql_hash_ctx ),
	];
}

function flywp_unpack_extract_snapshot( $artifact, $migration_key, $output_dir, $observer = null ) {
	foreach ( [ 'sodium', 'zlib' ] as $extension ) {
		if ( ! extension_loaded( $extension ) ) {
			throw new RuntimeException( "Missing required PHP extension: {$extension}" );
		}
	}

	flywp_unpack_mkdir( $output_dir );

	$archive             = flywp_unpack_load_archive_header( $artifact, $migration_key );
	$fp                  = $archive['handle'];
	$header              = $archive['header'];
	$payload_state       = $archive['payload_state'];
	$plain_chunks        = flywp_unpack_stream_plain_chunks( $fp, $payload_state );
	$expected_entries    = (int) ( $header['total_files'] ?? 0 ) + (int) ( $header['total_directories'] ?? 0 );
	$entry_count         = 0;
	$verified_files      = 0;
	$verified_directories = 0;
	$compressed_files    = 0;
	$saw_final_tag       = false;
	$embedded_dump_info  = null;

	$plain_chunks->rewind();

	try {
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

			$path       = flywp_unpack_normalize_relative_path( $path );
			$target_path = flywp_unpack_output_path( $output_dir, $path );
			$entry_count++;

			flywp_unpack_notify(
				$observer,
				'entry.header',
				[
					'path'         => $path,
					'type'         => $type,
					'payload_size' => $payload_size,
					'entry_count'  => $entry_count,
					'entries_total'=> $expected_entries,
				]
			);

			if ( 0 === $type ) {
				flywp_unpack_mkdir( $target_path );
				$verified_directories++;
				flywp_unpack_notify(
					$observer,
					'entry.directory',
					[
						'path'             => $path,
						'target_path'      => $target_path,
						'verified_dirs'    => $verified_directories,
						'entries_total'    => $expected_entries,
						'entry_count'      => $entry_count,
					]
				);
				continue;
			}

			flywp_unpack_mkdir( dirname( $target_path ) );

			$hash_ctx       = hash_init( 'sha256' );
			$remaining      = $payload_size;
			$is_embedded_db = $path === ( $header['dump_relative_path'] ?? '' );

			if ( 2 === $type ) {
				$temp_path   = $target_path . '.tmp.gz';
				$write_handle = fopen( $temp_path, 'wb' );
				if ( false === $write_handle ) {
					throw new RuntimeException( "Could not open temp gzip file for {$path}" );
				}
			} else {
				$write_handle = fopen( $target_path, 'wb' );
				if ( false === $write_handle ) {
					throw new RuntimeException( "Could not open output file for {$path}" );
				}
				$temp_path = null;
			}

			try {
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

					if ( false === fwrite( $write_handle, $message ) ) {
						throw new RuntimeException( "Could not write extracted payload for {$path}" );
					}

					$remaining -= $consume;
				}
			} finally {
				fclose( $write_handle );
			}

			$actual_hash = hash_final( $hash_ctx, true );
			if ( ! hash_equals( $sha256_raw, $actual_hash ) ) {
				throw new RuntimeException( "Checksum mismatch for {$path}" );
			}

			if ( 2 === $type ) {
				flywp_unpack_stream_gzip_file_to_file( $temp_path, $target_path );
				@unlink( $temp_path );
				$compressed_files++;
			}

			$verified_files++;

			flywp_unpack_notify(
				$observer,
				'entry.file',
				[
					'path'               => $path,
					'target_path'        => $target_path,
					'stored_bytes'       => $payload_size,
					'entry_type'         => $type,
					'verified_files'     => $verified_files,
					'compressed_files'   => $compressed_files,
					'entry_count'        => $entry_count,
					'entries_total'      => $expected_entries,
				]
			);

			if ( $is_embedded_db ) {
				$embedded_dump_info = flywp_unpack_decrypt_database_dump(
					$target_path,
					$migration_key,
					$output_dir,
					$path
				);
			}
		}

		if ( $plain_chunks->valid() ) {
			$current = $plain_chunks->current();
			if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $current['tag'] && '' === $current['message'] ) {
				$saw_final_tag = true;
			}
		}
	} finally {
		fclose( $fp );
	}

	if ( null === $embedded_dump_info ) {
		throw new RuntimeException( 'Embedded database dump was not found in the filesystem snapshot.' );
	}

	$result = [
		'artifact'               => $artifact,
		'output_dir'             => $output_dir,
		'header'                 => $header,
		'entries_total'          => $expected_entries,
		'verified_entries'       => $entry_count,
		'verified_files'         => $verified_files,
		'verified_directories'   => $verified_directories,
		'compressed_files'       => $compressed_files,
		'final_stream_marker'    => $saw_final_tag,
		'embedded_database'      => $embedded_dump_info,
	];

	flywp_unpack_notify( $observer, 'complete', $result );

	return $result;
}

function flywp_unpack_notify( $observer, $event, array $payload ) {
	if ( null !== $observer ) {
		call_user_func( $observer, $event, $payload );
	}
}
