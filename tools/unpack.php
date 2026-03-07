<?php

require_once __DIR__ . '/unpack-lib.php';

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$config_path = flywp_unpack_default_config_path();
$artifact    = null;
$target_dir  = null;

for ( $i = 1; $i < $argc; $i++ ) {
	$arg = (string) $argv[ $i ];

	if ( 0 === strpos( $arg, '--config=' ) ) {
		$config_path = substr( $arg, 9 );
		continue;
	}

	if ( 0 === strpos( $arg, '--artifact=' ) ) {
		$artifact = substr( $arg, 11 );
		continue;
	}

	if ( 0 === strpos( $arg, '--output=' ) ) {
		$target_dir = substr( $arg, 9 );
		continue;
	}

	if ( '--help' === $arg || '-h' === $arg ) {
		echo "Usage: php tools/unpack.php [--config=/path/to/pull-config.json] [--artifact=/path/to/file.fwsnap] [--output=/path/to/extracted]\n";
		exit( 0 );
	}

	if ( '-' !== substr( $arg, 0, 1 ) ) {
		if ( null === $artifact ) {
			$artifact = $arg;
			continue;
		}

		if ( null === $target_dir ) {
			$target_dir = $arg;
		}
	}
}

try {
	$config = flywp_unpack_load_config( $config_path );

	if ( null === $artifact || '' === trim( (string) $artifact ) ) {
		$artifact = flywp_unpack_find_latest_artifact( $config['output_dir'] );
	}

	if ( ! is_file( $artifact ) ) {
		throw new RuntimeException( "Artifact not found: {$artifact}" );
	}

	if ( null === $target_dir || '' === trim( (string) $target_dir ) ) {
		$artifact_base = pathinfo( $artifact, PATHINFO_FILENAME );
		$target_dir    = rtrim( $config['output_dir'], '/\\' ) . DIRECTORY_SEPARATOR . $artifact_base . '-extracted';
	}

	echo "Artifact: {$artifact}\n";
	echo "Output dir: {$target_dir}\n";

	$result = flywp_unpack_extract_snapshot(
		$artifact,
		$config['migration_key'],
		$target_dir,
		static function ( $event, array $payload ) {
			if ( 'entry.directory' === $event ) {
				echo sprintf(
					"[dir ] %4d/%4d %s\n",
					(int) $payload['entry_count'],
					(int) $payload['entries_total'],
					$payload['path']
				);
				return;
			}

			if ( 'entry.file' === $event ) {
				$type_label = 2 === (int) $payload['entry_type'] ? 'gzip' : 'file';
				echo sprintf(
					"[%s] %4d/%4d %s (%d bytes)\n",
					$type_label,
					(int) $payload['entry_count'],
					(int) $payload['entries_total'],
					$payload['path'],
					(int) $payload['stored_bytes']
				);
			}
		}
	);

	echo "Snapshot ID: " . ( $result['header']['snapshot_id'] ?? '(unknown)' ) . "\n";
	echo "Site URL: " . ( $result['header']['site_url'] ?? '(unknown)' ) . "\n";
	echo "Verified entries: {$result['verified_entries']}\n";
	echo "Verified files: {$result['verified_files']}\n";
	echo "Verified directories: {$result['verified_directories']}\n";
	echo "Compressed files: {$result['compressed_files']}\n";
	echo "Final stream marker: " . ( $result['final_stream_marker'] ? 'present' : 'missing' ) . "\n";
	echo "Embedded DB SQL: {$result['embedded_database']['sql_path']}\n";
	echo "Embedded DB SQL SHA-256: {$result['embedded_database']['sql_sha256']}\n";
} catch ( RuntimeException $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
