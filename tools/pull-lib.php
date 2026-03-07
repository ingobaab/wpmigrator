<?php

function flywp_puller_default_config_path() {
	return __DIR__ . '/pull-config.json';
}

function flywp_puller_load_config( $config_path, $cli_poll_interval_seconds = null ) {
	if ( ! extension_loaded( 'curl' ) ) {
		throw new RuntimeException( 'The cURL extension is required.' );
	}

	if ( ! is_file( $config_path ) ) {
		throw new RuntimeException( "Config file not found: {$config_path}" );
	}

	$config = json_decode( (string) file_get_contents( $config_path ), true );

	if ( ! is_array( $config ) ) {
		throw new RuntimeException( "Invalid JSON config: {$config_path}" );
	}

	$required_keys = [
		'base_url',
		'migration_key',
	];

	foreach ( $required_keys as $required_key ) {
		if ( ! array_key_exists( $required_key, $config ) || '' === trim( (string) $config[ $required_key ] ) ) {
			throw new RuntimeException( "Missing required config key: {$required_key}" );
		}
	}

	$base_url              = rtrim( (string) $config['base_url'], '/' );
	$migration_key         = (string) $config['migration_key'];
	$rest_root             = isset( $config['rest_root'] ) && '' !== trim( (string) $config['rest_root'] )
		? (string) $config['rest_root']
		: $base_url . '/wp-json';
	$output_dir            = isset( $config['output_dir'] ) && '' !== trim( (string) $config['output_dir'] )
		? (string) $config['output_dir']
		: sys_get_temp_dir() . '/flywp-puller';
	$poll_interval_seconds = null !== $cli_poll_interval_seconds
		? max( 0.2, (float) $cli_poll_interval_seconds )
		: ( isset( $config['poll_interval_seconds'] ) ? max( 0.2, (float) $config['poll_interval_seconds'] ) : 1.0 );
	$poll_timeout_seconds  = isset( $config['poll_timeout_seconds'] ) ? max( 30, (int) $config['poll_timeout_seconds'] ) : 1800;

	if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) && ! is_dir( $output_dir ) ) {
		throw new RuntimeException( "Could not create output directory: {$output_dir}" );
	}

	return [
		'config_path'            => $config_path,
		'base_url'               => $base_url,
		'migration_key'          => $migration_key,
		'rest_root'              => $rest_root,
		'output_dir'             => $output_dir,
		'poll_interval_seconds'  => $poll_interval_seconds,
		'poll_timeout_seconds'   => $poll_timeout_seconds,
	];
}

function flywp_puller_build_rest_url( $rest_root, $route, array $query = [] ) {
	$url = $rest_root;

	if ( false !== strpos( $rest_root, '?' ) ) {
		$url .= rawurlencode( $route );
	} else {
		$url = rtrim( $rest_root, '/' ) . $route;
	}

	if ( ! empty( $query ) ) {
		$url .= ( false !== strpos( $url, '?' ) ? '&' : '?' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	return $url;
}

function flywp_puller_request_json( $method, $rest_root, $route, $migration_key, array $query = [], $body = null ) {
	$url = flywp_puller_build_rest_url( $rest_root, $route, $query );
	$ch  = curl_init( $url );

	$headers = [
		'X-FlyWP-Key: ' . $migration_key,
		'Accept: application/json',
	];

	$options = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_TIMEOUT        => 0,
		CURLOPT_CONNECTTIMEOUT => 15,
	];

	if ( null !== $body ) {
		$payload                        = json_encode( $body, JSON_UNESCAPED_SLASHES );
		$headers[]                      = 'Content-Type: application/json';
		$options[ CURLOPT_HTTPHEADER ]  = $headers;
		$options[ CURLOPT_POSTFIELDS ]  = $payload;
	}

	curl_setopt_array( $ch, $options );
	$response = curl_exec( $ch );

	if ( false === $response ) {
		$error = curl_error( $ch );
		curl_close( $ch );

		return [
			'ok'      => false,
			'status'  => 0,
			'error'   => $error,
			'body'    => null,
			'raw'     => '',
		];
	}

	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );

	return [
		'ok'      => $status >= 200 && $status < 300,
		'status'  => $status,
		'body'    => json_decode( $response, true ),
		'raw'     => $response,
		'error'   => null,
	];
}

function flywp_puller_format_bytes( $bytes ) {
	$bytes = (float) $bytes;
	$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	$idx   = 0;

	while ( $bytes >= 1024 && $idx < count( $units ) - 1 ) {
		$bytes /= 1024;
		$idx++;
	}

	return sprintf( $bytes >= 100 || 0 === $idx ? '%.0f %s' : '%.1f %s', $bytes, $units[ $idx ] );
}

function flywp_puller_ascii_progress_bar( $percent, $width = 30 ) {
	$percent = max( 0, min( 100, (float) $percent ) );
	$filled  = (int) round( ( $percent / 100 ) * $width );

	return '[' . str_repeat( '#', $filled ) . str_repeat( '-', $width - $filled ) . ']';
}

function flywp_puller_print_snapshot_progress( $label, array $status ) {
	$phase     = isset( $status['current_phase'] ) ? (string) $status['current_phase'] : '-';
	$item      = isset( $status['current_item'] ) && null !== $status['current_item'] ? (string) $status['current_item'] : '-';
	$progress  = isset( $status['progress_percent'] ) ? (float) $status['progress_percent'] : 0;
	$processed = isset( $status['processed_bytes'] ) ? (int) $status['processed_bytes'] : 0;
	$total     = isset( $status['total_bytes_estimate'] ) ? (int) $status['total_bytes_estimate'] : 0;

	echo sprintf(
		"%s %-20s %6.1f%%  %s / %s  phase=%s  item=%s\n",
		flywp_puller_ascii_progress_bar( $progress ),
		$label,
		$progress,
		flywp_puller_format_bytes( $processed ),
		flywp_puller_format_bytes( $total ),
		$phase,
		$item
	);
}

function flywp_puller_print_transfer_progress( $label, $downloaded_bytes, $total_bytes, $chunk_index, $total_chunks, $final_path ) {
	$progress = $total_bytes > 0 ? ( $downloaded_bytes / $total_bytes ) * 100 : 0;

	echo sprintf(
		"%s %-20s %6.1f%%  %s / %s  chunks=%d/%d  file=%s\n",
		flywp_puller_ascii_progress_bar( $progress ),
		$label,
		$progress,
		flywp_puller_format_bytes( $downloaded_bytes ),
		flywp_puller_format_bytes( $total_bytes ),
		$chunk_index,
		$total_chunks,
		basename( $final_path )
	);
}

function flywp_puller_sleep_interval( $seconds ) {
	if ( $seconds <= 0 ) {
		return;
	}

	usleep( (int) round( $seconds * 1000000 ) );
}

function flywp_puller_calculate_snapshot_percent( array $status ) {
	if ( isset( $status['progress_percent'] ) && null !== $status['progress_percent'] ) {
		return max( 0, min( 100, (float) $status['progress_percent'] ) );
	}

	$processed = isset( $status['processed_bytes'] ) ? (float) $status['processed_bytes'] : 0;
	$total     = isset( $status['total_bytes_estimate'] ) ? (float) $status['total_bytes_estimate'] : 0;

	if ( $total > 0 ) {
		return max( 0, min( 100, ( $processed / $total ) * 100 ) );
	}

	return 0.0;
}

function flywp_puller_snapshot_digest( array $status ) {
	return md5(
		json_encode(
			[
				$status['status'] ?? null,
				$status['progress_percent'] ?? null,
				$status['processed_bytes'] ?? null,
				$status['total_bytes_estimate'] ?? null,
				$status['current_phase'] ?? null,
				$status['current_item'] ?? null,
			]
		)
	);
}

function flywp_puller_snapshot_phase_payload( $key, $label, array $status ) {
	$status['progress_percent'] = flywp_puller_calculate_snapshot_percent( $status );

	return [
		'key'    => $key,
		'label'  => $label,
		'status' => $status,
	];
}

function flywp_puller_notify( $observer, $event, array $payload ) {
	if ( null !== $observer ) {
		call_user_func( $observer, $event, $payload );
	}
}

function flywp_puller_start_snapshot( $key, $label, $rest_root, $route, $migration_key, $observer = null ) {
	$response = flywp_puller_request_json( 'POST', $rest_root, $route, $migration_key );

	if ( ! $response['ok'] ) {
		$message = "Failed to start {$label}. HTTP {$response['status']}";
		if ( ! empty( $response['raw'] ) ) {
			$message .= "\n" . $response['raw'];
		}

		throw new RuntimeException( $message );
	}

	if ( is_array( $response['body'] ) ) {
		flywp_puller_notify(
			$observer,
			'snapshot.progress',
			flywp_puller_snapshot_phase_payload( $key, $label, $response['body'] )
		);
	}

	return is_array( $response['body'] ) ? $response['body'] : null;
}

function flywp_puller_wait_for_snapshot( $key, $label, $rest_root, $route, $migration_key, $poll_interval_seconds, $poll_timeout_seconds, $observer = null, array $initial_status = null ) {
	$deadline    = time() + $poll_timeout_seconds;
	$last_digest = null;

	if ( null !== $initial_status ) {
		$initial_status['progress_percent'] = flywp_puller_calculate_snapshot_percent( $initial_status );
		$last_digest                        = flywp_puller_snapshot_digest( $initial_status );
	}

	while ( true ) {
		$response = flywp_puller_request_json( 'GET', $rest_root, $route, $migration_key );

		if ( ! $response['ok'] || ! is_array( $response['body'] ) ) {
			$message = "Failed to poll {$route}. HTTP {$response['status']}";
			if ( ! empty( $response['raw'] ) ) {
				$message .= "\n" . $response['raw'];
			}

			throw new RuntimeException( $message );
		}

		$status = $response['body'];
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

		if ( $digest !== $last_digest ) {
			flywp_puller_notify(
				$observer,
				'snapshot.progress',
				flywp_puller_snapshot_phase_payload( $key, $label, $status )
			);
			$last_digest = $digest;
		}

		if ( 'complete' === ( $status['status'] ?? '' ) ) {
			return $status;
		}

		if ( 'failed' === ( $status['status'] ?? '' ) ) {
			throw new RuntimeException( "{$label} failed: " . ( $status['error'] ?? 'unknown error' ) );
		}

		if ( time() >= $deadline ) {
			throw new RuntimeException( "{$label} timed out after {$poll_timeout_seconds} seconds." );
		}

		flywp_puller_sleep_interval( $poll_interval_seconds );
	}
}

function flywp_puller_transfer_payload( array $payload ) {
	$progress_percent = 0;
	if ( ! empty( $payload['size'] ) ) {
		$progress_percent = max( 0, min( 100, ( (float) ( $payload['downloaded_bytes'] ?? 0 ) / (float) $payload['size'] ) * 100 ) );
	}

	$payload['progress_percent'] = $progress_percent;

	return $payload;
}

function flywp_puller_download_artifact( $rest_root, $migration_key, $artifact_path, $output_dir, $observer = null ) {
	$meta = flywp_puller_request_json(
		'GET',
		$rest_root,
		'/flywp-migrator/v1/files/meta',
		$migration_key,
		[
			'path' => $artifact_path,
		]
	);

	if ( ! $meta['ok'] || ! is_array( $meta['body'] ) ) {
		$message = "Failed to fetch /files/meta. HTTP {$meta['status']}";
		if ( ! empty( $meta['raw'] ) ) {
			$message .= "\n" . $meta['raw'];
		}

		throw new RuntimeException( $message );
	}

	$meta_body    = $meta['body'];
	$total_chunks = isset( $meta_body['total_chunks'] ) ? (int) $meta_body['total_chunks'] : 0;
	$chunk_size   = isset( $meta_body['chunk_size'] ) ? (int) $meta_body['chunk_size'] : 0;
	$file_size    = isset( $meta_body['size'] ) ? (int) $meta_body['size'] : 0;
	$etag         = isset( $meta_body['etag'] ) ? (string) $meta_body['etag'] : '';

	if ( $total_chunks < 1 || $chunk_size < 1 || $file_size < 0 || '' === $etag ) {
		throw new RuntimeException( 'Invalid /files/meta response.' );
	}

	$safe_name     = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $artifact_path );
	$safe_name     = trim( (string) $safe_name, '_' );
	$safe_name     = '' !== $safe_name ? $safe_name : 'artifact';
	$final_path    = rtrim( $output_dir, '/\\' ) . DIRECTORY_SEPARATOR . basename( $artifact_path );
	$partial_path  = rtrim( $output_dir, '/\\' ) . DIRECTORY_SEPARATOR . $safe_name . '.part';
	$progress_path = rtrim( $output_dir, '/\\' ) . DIRECTORY_SEPARATOR . $safe_name . '.progress.json';

	$progress = [
		'artifact_path'   => $artifact_path,
		'etag'            => $etag,
		'chunk_size'      => $chunk_size,
		'total_chunks'    => $total_chunks,
		'downloaded'      => [],
		'downloaded_size' => 0,
	];

	if ( is_file( $progress_path ) ) {
		$existing = json_decode( (string) file_get_contents( $progress_path ), true );
		if (
			is_array( $existing ) &&
			( $existing['artifact_path'] ?? '' ) === $artifact_path &&
			( $existing['etag'] ?? '' ) === $etag
		) {
			$progress = array_merge( $progress, $existing );
		}
	}

	$downloaded_map = [];
	foreach ( (array) $progress['downloaded'] as $downloaded_chunk ) {
		$downloaded_map[ (int) $downloaded_chunk ] = true;
	}

	flywp_puller_notify(
		$observer,
		'transfer.started',
		flywp_puller_transfer_payload(
			[
				'label'            => 'Transfer',
				'status'           => 'running',
				'artifact_path'    => $artifact_path,
				'size'             => $file_size,
				'chunk_size'       => $chunk_size,
				'total_chunks'     => $total_chunks,
				'etag'             => $etag,
				'chunk_index'      => 0,
				'downloaded_bytes' => (int) $progress['downloaded_size'],
				'final_path'       => $final_path,
			]
		)
	);

	$next_chunk = 0;
	while ( isset( $downloaded_map[ $next_chunk ] ) ) {
		$next_chunk++;
	}

	$fp = fopen( $partial_path, 'c+b' );
	if ( false === $fp ) {
		throw new RuntimeException( "Could not open partial file for writing: {$partial_path}" );
	}

	try {
		for ( $chunk_index = $next_chunk; $chunk_index < $total_chunks; $chunk_index++ ) {
			$url              = flywp_puller_build_rest_url(
				$rest_root,
				'/flywp-migrator/v1/files/stream',
				[
					'path'  => $artifact_path,
					'chunk' => $chunk_index,
				]
			);
			$response_headers = [];
			$ch               = curl_init( $url );

			curl_setopt_array(
				$ch,
				[
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_HTTPHEADER     => [
						'X-FlyWP-Key: ' . $migration_key,
					],
					CURLOPT_TIMEOUT        => 0,
					CURLOPT_CONNECTTIMEOUT => 15,
					CURLOPT_HEADERFUNCTION => static function ( $curl_handle, $header_line ) use ( &$response_headers ) {
						$trimmed = trim( $header_line );
						if ( '' === $trimmed || false === strpos( $trimmed, ':' ) ) {
							return strlen( $header_line );
						}

						list( $name, $value ) = explode( ':', $trimmed, 2 );
						$response_headers[ strtolower( trim( $name ) ) ] = trim( $value );

						return strlen( $header_line );
					},
				]
			);

			$payload = curl_exec( $ch );

			if ( false === $payload ) {
				$error = curl_error( $ch );
				curl_close( $ch );
				throw new RuntimeException( 'Chunk request failed: ' . $error );
			}

			$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			curl_close( $ch );

			if ( 200 !== $status ) {
				throw new RuntimeException( "Unexpected /files/stream status {$status} for chunk {$chunk_index}\n{$payload}" );
			}

			$expected_checksum = $response_headers['x-flywp-chunk-checksum'] ?? '';
			$response_etag     = $response_headers['x-flywp-file-etag'] ?? '';
			$response_index    = isset( $response_headers['x-flywp-chunk-index'] ) ? (int) $response_headers['x-flywp-chunk-index'] : -1;
			$response_total    = isset( $response_headers['x-flywp-total-chunks'] ) ? (int) $response_headers['x-flywp-total-chunks'] : -1;
			$actual_checksum   = hash( 'sha256', $payload );
			$offset            = $chunk_index * $chunk_size;

			if ( '' === $expected_checksum || $expected_checksum !== $actual_checksum ) {
				throw new RuntimeException( "Checksum mismatch for chunk {$chunk_index}" );
			}

			if ( $response_etag !== $etag ) {
				throw new RuntimeException( "ETag mismatch during chunk {$chunk_index}" );
			}

			if ( $response_index !== $chunk_index || $response_total !== $total_chunks ) {
				throw new RuntimeException( 'Chunk metadata changed during download.' );
			}

			if ( 0 !== fseek( $fp, $offset ) ) {
				throw new RuntimeException( "Could not seek to offset {$offset}" );
			}

			$written = fwrite( $fp, $payload );
			if ( false === $written || $written !== strlen( $payload ) ) {
				throw new RuntimeException( "Could not write chunk {$chunk_index}" );
			}

			fflush( $fp );
			$downloaded_map[ $chunk_index ] = true;
			ksort( $downloaded_map );
			$progress['downloaded']      = array_map( 'intval', array_keys( $downloaded_map ) );
			$progress['downloaded_size'] = filesize( $partial_path );
			file_put_contents( $progress_path, json_encode( $progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			flywp_puller_notify(
				$observer,
				'transfer.progress',
				flywp_puller_transfer_payload(
					[
						'label'            => 'Transfer',
						'status'           => 'running',
						'artifact_path'    => $artifact_path,
						'size'             => $file_size,
						'chunk_size'       => $chunk_size,
						'total_chunks'     => $total_chunks,
						'etag'             => $etag,
						'chunk_index'      => $chunk_index + 1,
						'downloaded_bytes' => (int) $progress['downloaded_size'],
						'final_path'       => $final_path,
					]
				)
			);
		}
	} finally {
		fclose( $fp );
	}

	clearstatcache( true, $partial_path );
	$assembled_size = is_file( $partial_path ) ? filesize( $partial_path ) : -1;
	if ( $assembled_size !== $file_size ) {
		throw new RuntimeException( "Final file size mismatch. Expected {$file_size}, got {$assembled_size}" );
	}

	if ( ! rename( $partial_path, $final_path ) ) {
		throw new RuntimeException( "Could not rename {$partial_path} to {$final_path}" );
	}

	@unlink( $progress_path );

	$result = [
		'path'       => $final_path,
		'sha256'     => hash_file( 'sha256', $final_path ),
		'size'       => $file_size,
		'chunk_size' => $chunk_size,
		'total_chunks' => $total_chunks,
		'etag'       => $etag,
	];

	flywp_puller_notify(
		$observer,
		'transfer.complete',
		flywp_puller_transfer_payload(
			[
				'label'            => 'Transfer',
				'status'           => 'complete',
				'artifact_path'    => $artifact_path,
				'size'             => $file_size,
				'chunk_size'       => $chunk_size,
				'total_chunks'     => $total_chunks,
				'etag'             => $etag,
				'chunk_index'      => $total_chunks,
				'downloaded_bytes' => $file_size,
				'final_path'       => $final_path,
				'sha256'           => $result['sha256'],
			]
		)
	);

	return $result;
}

function flywp_puller_run( array $config, $observer = null ) {
	flywp_puller_notify(
		$observer,
		'job.started',
		[
			'base_url'              => $config['base_url'],
			'rest_root'             => $config['rest_root'],
			'output_dir'            => $config['output_dir'],
			'poll_interval_seconds' => $config['poll_interval_seconds'],
		]
	);

	$database_initial = flywp_puller_start_snapshot(
		'database',
		'Database Snapshot',
		$config['rest_root'],
		'/flywp-migrator/v1/snapshot/database',
		$config['migration_key'],
		$observer
	);

	$database_status = flywp_puller_wait_for_snapshot(
		'database',
		'Database Snapshot',
		$config['rest_root'],
		'/flywp-migrator/v1/snapshot/database',
		$config['migration_key'],
		$config['poll_interval_seconds'],
		$config['poll_timeout_seconds'],
		$observer,
		$database_initial
	);

	flywp_puller_notify(
		$observer,
		'snapshot.complete',
		flywp_puller_snapshot_phase_payload( 'database', 'Database Snapshot', $database_status )
	);

	$filesystem_initial = flywp_puller_start_snapshot(
		'filesystem',
		'Filesystem Snapshot',
		$config['rest_root'],
		'/flywp-migrator/v1/snapshot/filesystem',
		$config['migration_key'],
		$observer
	);

	$filesystem_status = flywp_puller_wait_for_snapshot(
		'filesystem',
		'Filesystem Snapshot',
		$config['rest_root'],
		'/flywp-migrator/v1/snapshot/filesystem',
		$config['migration_key'],
		$config['poll_interval_seconds'],
		$config['poll_timeout_seconds'],
		$observer,
		$filesystem_initial
	);

	flywp_puller_notify(
		$observer,
		'snapshot.complete',
		flywp_puller_snapshot_phase_payload( 'filesystem', 'Filesystem Snapshot', $filesystem_status )
	);

	$artifact_path = isset( $filesystem_status['artifact_path'] ) ? (string) $filesystem_status['artifact_path'] : '';
	if ( '' === $artifact_path ) {
		throw new RuntimeException( 'Filesystem snapshot completed without artifact_path.' );
	}

	$transfer = flywp_puller_download_artifact(
		$config['rest_root'],
		$config['migration_key'],
		$artifact_path,
		$config['output_dir'],
		$observer
	);

	$result = [
		'database_status'   => $database_status,
		'filesystem_status' => $filesystem_status,
		'transfer'          => $transfer,
	];

	flywp_puller_notify( $observer, 'job.complete', $result );

	return $result;
}

