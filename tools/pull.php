<?php

require_once __DIR__ . '/pull-lib.php';

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$config_path                = flywp_puller_default_config_path();
$cli_poll_interval_seconds  = null;

for ( $i = 1; $i < $argc; $i++ ) {
	$arg = (string) $argv[ $i ];

	if ( 0 === strpos( $arg, '--config=' ) ) {
		$config_path = substr( $arg, 9 );
		continue;
	}

	if ( 0 === strpos( $arg, '--poll-interval=' ) ) {
		$cli_poll_interval_seconds = (float) substr( $arg, 16 );
		continue;
	}

	if ( '--help' === $arg || '-h' === $arg ) {
		echo "Usage: php tools/pull.php [--config=/path/to/pull-config.json] [--poll-interval=1]\n";
		exit( 0 );
	}

	if ( '-' !== substr( $arg, 0, 1 ) ) {
		$config_path = $arg;
	}
}

try {
	$config = flywp_puller_load_config( $config_path, $cli_poll_interval_seconds );

	echo "Base URL: {$config['base_url']}\n";
	echo "REST root: {$config['rest_root']}\n";
	echo "Output dir: {$config['output_dir']}\n";
	echo "Poll interval: {$config['poll_interval_seconds']}s\n";

	$last_snapshot_digest = [
		'database'   => null,
		'filesystem' => null,
	];

	flywp_puller_run(
		$config,
		static function ( $event, array $payload ) use ( &$last_snapshot_digest ) {
			if ( 'snapshot.progress' === $event ) {
				$key    = $payload['key'];
				$label  = strtolower( $payload['label'] );
				$status = $payload['status'];
				$digest = md5(
					json_encode(
						[
							$status['status'] ?? null,
							$status['progress_percent'] ?? null,
							$status['items_done'] ?? null,
							$status['items_total'] ?? null,
							$status['current_phase'] ?? null,
							$status['current_item'] ?? null,
							$status['written_bytes'] ?? null,
						]
					)
				);

				if ( null === $last_snapshot_digest[ $key ] ) {
					echo "\n== {$label} ==\n";
					echo 'Starting via /flywp-migrator/v1/snapshot/' . $key . "\n";
				}

				if ( $digest !== $last_snapshot_digest[ $key ] ) {
					flywp_puller_print_snapshot_progress( $label, $status );
					$last_snapshot_digest[ $key ] = $digest;
				}

				return;
			}

			if ( 'snapshot.complete' === $event ) {
				$key        = $payload['key'];
				$snapshot_id = $payload['status']['snapshot_id'] ?? '(unknown)';
				echo ucfirst( $key ) . " snapshot complete: {$snapshot_id}\n";
				return;
			}

			if ( 'transfer.started' === $event ) {
				echo "Artifact path: {$payload['artifact_path']}\n";
				echo "File size: {$payload['size']} bytes\n";
				echo "Chunk size: {$payload['chunk_size']} bytes\n";
				echo "Total chunks: {$payload['total_chunks']}\n";
				echo "ETag: {$payload['etag']}\n";
				echo "\n== Transfer ==\n";
				return;
			}

			if ( 'transfer.progress' === $event ) {
				flywp_puller_print_transfer_progress(
					'Transfer',
					(int) $payload['downloaded_bytes'],
					(int) $payload['size'],
					(int) $payload['chunk_index'],
					(int) $payload['total_chunks'],
					(string) $payload['final_path']
				);
				return;
			}

			if ( 'transfer.complete' === $event ) {
				echo "Download complete: {$payload['final_path']}\n";
				echo "Final SHA-256: {$payload['sha256']}\n";
			}
		}
	);
} catch ( RuntimeException $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
