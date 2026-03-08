<?php

namespace MigWP\Migrator\Services\Snapshots;

use MigWP\Migrator\Services\Crypto\KeyDeriver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;

class FilesystemSnapshotService {
	const ARCHIVE_MAGIC = "FWSNAP1\n";
	const FORMAT_VERSION = 1;
	const COMPRESSION_MIN_SIZE = 1024;

	/**
	 * @var StateStore
	 */
	private $state_store;

	/**
	 * @var Paths
	 */
	private $paths;

	/**
	 * @var ConfigStore
	 */
	private $config_store;

	/**
	 * @var KeyDeriver
	 */
	private $key_deriver;

	public function __construct( StateStore $state_store = null, Paths $paths = null, ConfigStore $config_store = null, KeyDeriver $key_deriver = null ) {
		$this->state_store  = $state_store ?: new StateStore();
		$this->paths        = $paths ?: new Paths();
		$this->config_store = $config_store ?: new ConfigStore();
		$this->key_deriver  = $key_deriver ?: new KeyDeriver();
	}

	/**
	 * Return the latest filesystem snapshot state.
	 *
	 * @return array
	 */
	public function get_latest_state() {
		$state = $this->state_store->read( 'filesystem' );

		if ( empty( $state ) ) {
			return $this->empty_state();
		}

		return $state;
	}

	/**
	 * Create the latest filesystem snapshot.
	 *
	 * @return array|WP_Error
	 */
	public function create_snapshot() {
		return $this->queue_snapshot();
	}

	/**
	 * Queue the latest filesystem snapshot.
	 *
	 * @return array|WP_Error
	 */
	public function queue_snapshot() {
		$db_state = $this->state_store->read( 'database' );

		if ( empty( $db_state ) || 'complete' !== ( $db_state['status'] ?? '' ) || empty( $db_state['artifact_file'] ) || ! is_file( $db_state['artifact_file'] ) ) {
			return new WP_Error(
				'filesystem_snapshot_requires_database_snapshot',
				__( 'A completed database snapshot is required before creating a filesystem snapshot', 'migwp-migrator' ),
				[ 'status' => 409 ]
			);
		}

		if ( ! extension_loaded( 'sodium' ) ) {
			return new WP_Error(
				'snapshot_encryption_unavailable',
				__( 'The sodium extension is required for filesystem snapshots', 'migwp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$snapshot_id  = wp_generate_uuid4();
		$created_at   = gmdate( 'c' );
		$worker_token = wp_generate_password( 32, false, false );
		$state       = array_merge(
			$this->empty_state(),
			[
				'status'        => 'queued',
				'snapshot_id'   => $snapshot_id,
				'created_at'    => $created_at,
				'started_at'    => null,
				'updated_at'    => $created_at,
				'current_phase' => 'queued',
				'worker_token'  => $worker_token,
			]
		);

		$this->persist_state( $state );

		return $state;
	}

	/**
	 * Run the queued latest filesystem snapshot for a worker token.
	 *
	 * @param string $worker_token Worker token.
	 *
	 * @return array|WP_Error
	 */
	public function run_queued_snapshot( $worker_token ) {
		$state = $this->state_store->read( 'filesystem' );

		if ( empty( $state ) || empty( $state['worker_token'] ) || $state['worker_token'] !== $worker_token ) {
			return new WP_Error(
				'snapshot_worker_token_invalid',
				__( 'Filesystem snapshot worker token is invalid or superseded', 'migwp-migrator' ),
				[ 'status' => 409 ]
			);
		}

		$db_state = $this->state_store->read( 'database' );

		if ( empty( $db_state ) || 'complete' !== ( $db_state['status'] ?? '' ) || empty( $db_state['artifact_file'] ) || ! is_file( $db_state['artifact_file'] ) ) {
			return new WP_Error(
				'filesystem_snapshot_requires_database_snapshot',
				__( 'A completed database snapshot is required before creating a filesystem snapshot', 'migwp-migrator' ),
				[ 'status' => 409 ]
			);
		}

		if ( ! extension_loaded( 'sodium' ) ) {
			return new WP_Error(
				'snapshot_encryption_unavailable',
				__( 'The sodium extension is required for filesystem snapshots', 'migwp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$state['status']        = 'running';
		$state['started_at']    = gmdate( 'c' );
		$state['updated_at']    = $state['started_at'];
		$state['current_phase'] = 'scanning';
		$state['error']         = null;
		$this->cleanup_previous_artifacts();
		$this->persist_state( $state );

		$config       = $this->config_store->get();
		$entries      = $this->build_manifest( $config, $db_state );
		$manifest     = is_wp_error( $entries ) ? null : $entries;

		if ( is_wp_error( $entries ) ) {
			$this->mark_failed( $state, $entries );

			return $entries;
		}

		$state['items_total']          = count( $manifest['entries'] );
		$state['total_bytes_estimate'] = $manifest['total_bytes'];
		$state['current_phase']        = 'packing';
		$state['updated_at']           = gmdate( 'c' );
		$this->persist_state( $state );

		$dir = $this->paths->ensure_dir( 'snapshots/' . $state['snapshot_id'] );
		if ( is_wp_error( $dir ) ) {
			$this->mark_failed( $state, $dir );

			return $dir;
		}

		$secret_name  = wp_generate_password( 32, false, false ) . '.fwsnap';
		$artifact     = trailingslashit( $dir ) . $secret_name;
		$payload_file = trailingslashit( $dir ) . 'payload.enc';

		$payload_result = $this->write_payload_stream( $payload_file, $manifest, $db_state, $state['snapshot_id'], $state, $worker_token );
		if ( is_wp_error( $payload_result ) ) {
			$this->mark_failed( $state, $payload_result );

			return $payload_result;
		}

		$archive_result = $this->finalize_archive( $artifact, $payload_file, $manifest, $db_state, $state['snapshot_id'], $state['created_at'], $payload_result );
		if ( is_wp_error( $archive_result ) ) {
			$this->mark_failed( $state, $archive_result );

			return $archive_result;
		}

		@unlink( $payload_file );

		$artifact_path = $this->paths->to_transfer_relative_path( $artifact );
		if ( is_wp_error( $artifact_path ) ) {
			$this->mark_failed( $state, $artifact_path );

			return $artifact_path;
		}

		$state['status']           = 'complete';
		$state['updated_at']       = gmdate( 'c' );
		$state['finished_at']      = $state['updated_at'];
		$state['progress_percent'] = 100;
		$state['current_phase']    = 'complete';
		$state['current_item']     = null;
		$state['written_bytes']    = filesize( $artifact );
		$state['result_size']      = filesize( $artifact );
		$state['artifact_file']    = $artifact;
		$state['artifact_path']    = $artifact_path;
		$this->persist_state( $state );

		return $state;
	}

	/**
	 * Format internal state for API responses.
	 *
	 * @param array $state                 Internal state.
	 * @param bool  $include_artifact_path Whether to include the artifact path.
	 *
	 * @return array
	 */
	public function format_state( array $state, $include_artifact_path ) {
		$response = [
			'status'               => $state['status'] ?? 'idle',
			'snapshot_id'          => $state['snapshot_id'] ?? null,
			'created_at'           => $state['created_at'] ?? null,
			'started_at'           => $state['started_at'] ?? null,
			'updated_at'           => $state['updated_at'] ?? null,
			'finished_at'          => $state['finished_at'] ?? null,
			'progress_percent'     => (int) ( $state['progress_percent'] ?? 0 ),
			'processed_bytes'      => (int) ( $state['processed_bytes'] ?? 0 ),
			'total_bytes_estimate' => (int) ( $state['total_bytes_estimate'] ?? 0 ),
			'written_bytes'        => (int) ( $state['written_bytes'] ?? 0 ),
			'current_phase'        => $state['current_phase'] ?? null,
			'current_item'         => $state['current_item'] ?? null,
			'items_done'           => (int) ( $state['items_done'] ?? 0 ),
			'items_total'          => (int) ( $state['items_total'] ?? 0 ),
			'result_size'          => (int) ( $state['result_size'] ?? 0 ),
			'error'                => $state['error'] ?? null,
		];

		if ( $include_artifact_path && 'complete' === ( $state['status'] ?? '' ) && ! empty( $state['artifact_path'] ) ) {
			$response['artifact_path'] = (string) $state['artifact_path'];
		}

		return $response;
	}

	/**
	 * @return array
	 */
	private function empty_state() {
		return [
			'status'               => 'idle',
			'snapshot_id'          => null,
			'created_at'           => null,
			'started_at'           => null,
			'updated_at'           => null,
			'finished_at'          => null,
			'progress_percent'     => 0,
			'processed_bytes'      => 0,
			'total_bytes_estimate' => 0,
			'written_bytes'        => 0,
			'current_phase'        => null,
			'current_item'         => null,
			'items_done'           => 0,
			'items_total'          => 0,
			'result_size'          => 0,
			'error'                => null,
		];
	}

	/**
	 * @param array $config   Snapshot config.
	 * @param array $db_state Database snapshot state.
	 *
	 * @return array|WP_Error
	 */
	private function build_manifest( array $config, array $db_state ) {
		$entries     = [];
		$total_bytes = 0;
		$seen        = [];

		foreach ( $config['include_roots'] as $include_root ) {
			$resolved = $this->resolve_content_path( $include_root );

			if ( is_wp_error( $resolved ) || ! file_exists( $resolved ) ) {
				continue;
			}

			if ( is_file( $resolved ) ) {
				if ( $this->should_exclude_path( $include_root, false, $config ) ) {
					continue;
				}

				$entries[]                = $this->file_entry( $include_root, $resolved );
				$seen[ $include_root ]    = true;
				$total_bytes             += filesize( $resolved );
				continue;
			}

			if ( ! empty( $config['respect_zipignore'] ) && file_exists( $resolved . DIRECTORY_SEPARATOR . '.zipignore' ) ) {
				continue;
			}

			$root_entry = [
				'type'         => 'dir',
				'relative_path'=> $include_root,
				'absolute_path'=> $resolved,
			];

			if ( ! $this->should_exclude_path( $include_root, true, $config ) && empty( $seen[ $include_root ] ) ) {
				$entries[]             = $root_entry;
				$seen[ $include_root ] = true;
			}

			try {
				$directory = new RecursiveDirectoryIterator( $resolved, RecursiveDirectoryIterator::SKIP_DOTS );
				$iterator  = new RecursiveIteratorIterator( $directory, RecursiveIteratorIterator::SELF_FIRST );
			} catch ( \UnexpectedValueException $exception ) {
				continue;
			}
			$skipped   = [];

			foreach ( $iterator as $item ) {
				if ( $item->isLink() ) {
					continue;
				}

				$current_abs = wp_normalize_path( $item->getPathname() );
				$sub_path    = ltrim( substr( $current_abs, strlen( wp_normalize_path( $resolved ) ) ), '/' );
				$current_rel = '' === $sub_path ? $include_root : $include_root . '/' . $sub_path;
				$current_rel = trim( str_replace( '\\', '/', $current_rel ), '/' );

				if ( $this->is_under_skipped_prefix( $current_rel, $skipped ) || $this->should_exclude_path( $current_rel, $item->isDir(), $config ) ) {
					if ( $item->isDir() ) {
						$skipped[] = $current_rel;
					}
					continue;
				}

				if ( $item->isDir() && ! empty( $config['respect_zipignore'] ) && file_exists( $item->getPathname() . DIRECTORY_SEPARATOR . '.zipignore' ) ) {
					$skipped[] = $current_rel;
					continue;
				}

				if ( isset( $seen[ $current_rel ] ) ) {
					continue;
				}

				if ( $item->isDir() ) {
					$entries[] = [
						'type'          => 'dir',
						'relative_path' => $current_rel,
						'absolute_path' => $current_abs,
					];
					$seen[ $current_rel ] = true;
					continue;
				}

				if ( ! $item->isFile() || ! $item->isReadable() ) {
					continue;
				}

				$entries[]              = $this->file_entry( $current_rel, $current_abs );
				$seen[ $current_rel ]   = true;
				$total_bytes           += $item->getSize();
			}
		}

		$db_rel      = '__migwp/database/latest.dbsnap';
		$db_abs      = $db_state['artifact_file'];
		$entries[]   = [
			'type'          => 'dir',
			'relative_path' => '__migwp',
			'absolute_path' => null,
		];
		$entries[]   = [
			'type'          => 'dir',
			'relative_path' => '__migwp/database',
			'absolute_path' => null,
		];
		$entries[]   = $this->file_entry( $db_rel, $db_abs, false );
		$total_bytes += filesize( $db_abs );

		usort(
			$entries,
			static function ( $left, $right ) {
				return strcmp( $left['relative_path'], $right['relative_path'] );
			}
		);

		return [
			'entries'                => $entries,
			'total_bytes'            => $total_bytes,
			'embedded_dump_relative' => $db_rel,
			'embedded_dump_size'     => filesize( $db_abs ),
			'total_files'            => count( array_filter( $entries, static function ( $entry ) { return 'file' === $entry['type']; } ) ),
			'total_directories'      => count( array_filter( $entries, static function ( $entry ) { return 'dir' === $entry['type']; } ) ),
		];
	}

	/**
	 * @param string $payload_file Payload output file.
	 * @param array  $manifest     Manifest metadata.
	 * @param array  $db_state     Database state.
	 * @param string $snapshot_id  Snapshot id.
	 * @param array  $state        Mutable state.
	 *
	 * @return array|WP_Error
	 */
	private function write_payload_stream( $payload_file, array $manifest, array $db_state, $snapshot_id, array &$state, $worker_token ) {
		$salt        = random_bytes( 32 );
		$key         = $this->key_deriver->derive_secretstream_key( migwp_migrator()->get_migration_key(), $snapshot_id, $salt, 'filesystem-snapshot' );
		$init        = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
		$crypto      = $init[0];
		$stream_head = $init[1];
		$handle      = @fopen( $payload_file, 'wb' );

		if ( ! $handle ) {
			return new WP_Error(
				'filesystem_snapshot_payload_open_failed',
				__( 'Could not create the filesystem payload stream', 'migwp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$processed_bytes = 0;
		$written_bytes   = 0;
		$items_done      = 0;
		$payload_bytes   = 0;

		foreach ( $manifest['entries'] as $entry ) {
			if ( is_wp_error( $this->assert_worker_current( $worker_token ) ) ) {
				fclose( $handle );

				return $this->state_store->read( 'filesystem' );
			}

			$state['current_item'] = $entry['relative_path'];
			$state['updated_at']   = gmdate( 'c' );
			$this->persist_state( $state );

			if ( 'dir' === $entry['type'] ) {
				$record = $this->entry_header_binary( 0, $entry['relative_path'], 0, str_repeat( "\0", 32 ) ) . $entry['relative_path'];
				$this->write_secretstream_chunk( $handle, $crypto, $record );
				$written_bytes += strlen( $record );
				$payload_bytes += strlen( $record );
			} else {
				$stored = $this->prepare_file_payload( $entry );
				if ( is_wp_error( $stored ) ) {
					fclose( $handle );

					return $stored;
				}

				$record_header = $this->entry_header_binary(
					$stored['entry_type'],
					$entry['relative_path'],
					$stored['size'],
					$stored['sha256_raw']
				);
				$record_prefix = $record_header . $entry['relative_path'];
				$this->write_secretstream_chunk( $handle, $crypto, $record_prefix );
				$written_bytes += strlen( $record_prefix );
				$payload_bytes += strlen( $record_prefix );

				$source = fopen( $stored['path'], 'rb' );
				if ( ! $source ) {
					if ( ! empty( $stored['cleanup'] ) ) {
						@unlink( $stored['path'] );
					}
					fclose( $handle );

					return new WP_Error(
						'filesystem_snapshot_entry_open_failed',
						sprintf(
							/* translators: %s: relative file path */
							__( 'Could not read snapshot entry %s', 'migwp-migrator' ),
							$entry['relative_path']
						),
						[ 'status' => 500 ]
					);
				}

				while ( ! feof( $source ) ) {
					$chunk = fread( $source, 1024 * 1024 );
					if ( false === $chunk ) {
						fclose( $source );
						if ( ! empty( $stored['cleanup'] ) ) {
							@unlink( $stored['path'] );
						}
						fclose( $handle );

						return new WP_Error(
							'filesystem_snapshot_entry_read_failed',
							sprintf(
								/* translators: %s: relative file path */
								__( 'Could not stream snapshot entry %s', 'migwp-migrator' ),
								$entry['relative_path']
							),
							[ 'status' => 500 ]
						);
					}

					if ( '' !== $chunk ) {
						$this->write_secretstream_chunk( $handle, $crypto, $chunk );
						$written_bytes += strlen( $chunk );
						$payload_bytes += strlen( $chunk );
					}
				}

				fclose( $source );

				if ( ! empty( $stored['cleanup'] ) ) {
					@unlink( $stored['path'] );
				}

				$processed_bytes += $entry['size'];
			}

			$items_done++;
			$state['items_done']       = $items_done;
			$state['processed_bytes']  = $processed_bytes;
			$state['written_bytes']    = filesize( $payload_file );
			$state['progress_percent'] = $this->calculate_progress( $state );
			$state['updated_at']       = gmdate( 'c' );
			$this->persist_state( $state );
		}

		$this->write_secretstream_chunk( $handle, $crypto, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL );
		fclose( $handle );

		return [
			'salt'          => $salt,
			'stream_header' => $stream_head,
			'payload_bytes' => $payload_bytes,
			'encrypted_size'=> filesize( $payload_file ),
		];
	}

	/**
	 * @param string $artifact       Final artifact path.
	 * @param string $payload_file   Encrypted payload file.
	 * @param array  $manifest       Manifest metadata.
	 * @param array  $db_state       DB snapshot state.
	 * @param string $snapshot_id    Snapshot id.
	 * @param string $created_at     Timestamp.
	 * @param array  $payload_result Payload metadata.
	 *
	 * @return true|WP_Error
	 */
	private function finalize_archive( $artifact, $payload_file, array $manifest, array $db_state, $snapshot_id, $created_at, array $payload_result ) {
		$target = @fopen( $artifact, 'wb' );
		$source = @fopen( $payload_file, 'rb' );

		if ( ! $target || ! $source ) {
			if ( $target ) {
				fclose( $target );
			}
			if ( $source ) {
				fclose( $source );
			}

			return new WP_Error(
				'filesystem_snapshot_archive_open_failed',
				__( 'Could not assemble the filesystem snapshot archive', 'migwp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$header = [
			'magic'                    => 'FWSNAP',
			'format_version'           => self::FORMAT_VERSION,
			'snapshot_id'              => $snapshot_id,
			'created_at'               => $created_at,
			'site_url'                 => home_url(),
			'encryption_algorithm_id'  => 'secretstream_xchacha20poly1305',
			'key_derivation_algorithm_id' => 'hkdf-sha256',
			'checksum_algorithm_id'    => 'sha256',
			'total_files'              => $manifest['total_files'],
			'total_directories'        => $manifest['total_directories'],
			'archive_payload_bytes'    => $payload_result['encrypted_size'],
			'dump_relative_path'       => $manifest['embedded_dump_relative'],
			'dump_size'                => $manifest['embedded_dump_size'],
			'database_snapshot_id'     => $db_state['snapshot_id'] ?? null,
			'salt'                     => base64_encode( $payload_result['salt'] ),
			'stream_header'            => base64_encode( $payload_result['stream_header'] ),
		];

		$json = wp_json_encode( $header, JSON_UNESCAPED_SLASHES );

		fwrite( $target, self::ARCHIVE_MAGIC );
		fwrite( $target, pack( 'N', strlen( (string) $json ) ) );
		fwrite( $target, (string) $json );

		while ( ! feof( $source ) ) {
			$chunk = fread( $source, 1024 * 1024 );
			if ( false === $chunk ) {
				fclose( $target );
				fclose( $source );

				return new WP_Error(
					'filesystem_snapshot_archive_copy_failed',
					__( 'Could not finalize the encrypted filesystem snapshot payload', 'migwp-migrator' ),
					[ 'status' => 500 ]
				);
			}

			if ( '' !== $chunk ) {
				fwrite( $target, $chunk );
			}
		}

		fclose( $target );
		fclose( $source );

		return true;
	}

	/**
	 * @param array $entry Manifest file entry.
	 *
	 * @return array|WP_Error
	 */
	private function prepare_file_payload( array $entry ) {
		$path       = $entry['absolute_path'];
		$entry_type = 1;
		$cleanup    = false;

		if ( ! empty( $entry['allow_compression'] ) && $entry['size'] >= self::COMPRESSION_MIN_SIZE && $this->is_compressible_extension( $entry['relative_path'] ) ) {
			$temp_dir = $this->paths->ensure_dir( 'runtime/compress' );
			if ( is_wp_error( $temp_dir ) ) {
				return $temp_dir;
			}

			$gzip_path = trailingslashit( $temp_dir ) . md5( $entry['relative_path'] . '|' . $entry['size'] ) . '.gz';
			$gzip_ok   = $this->stream_gzip_file( $path, $gzip_path );

			if ( ! is_wp_error( $gzip_ok ) && is_file( $gzip_path ) && filesize( $gzip_path ) < filesize( $path ) ) {
				$path       = $gzip_path;
				$entry_type = 2;
				$cleanup    = true;
			} elseif ( file_exists( $gzip_path ) ) {
				@unlink( $gzip_path );
			}
		}

		return [
			'path'       => $path,
			'size'       => filesize( $path ),
			'sha256_raw' => hex2bin( hash_file( 'sha256', $path ) ),
			'entry_type' => $entry_type,
			'cleanup'    => $cleanup,
		];
	}

	/**
	 * @param string $path Relative path.
	 * @param string $abs  Absolute path.
	 * @param bool   $allow_compression Whether compression is allowed.
	 *
	 * @return array
	 */
	private function file_entry( $path, $abs, $allow_compression = true ) {
		return [
			'type'              => 'file',
			'relative_path'     => $path,
			'absolute_path'     => $abs,
			'size'              => filesize( $abs ),
			'allow_compression' => $allow_compression,
		];
	}

	/**
	 * @param string $path   Relative snapshot path.
	 * @param bool   $is_dir Whether it is a directory.
	 * @param array  $config Snapshot config.
	 *
	 * @return bool
	 */
	private function should_exclude_path( $path, $is_dir, array $config ) {
		$path = trim( str_replace( '\\', '/', $path ), '/' );

		foreach ( $config['exclude_paths'] as $excluded ) {
			if ( $path === $excluded || 0 === strpos( $path, $excluded . '/' ) ) {
				return true;
			}
		}

		foreach ( $config['exclude_patterns'] as $pattern ) {
			if ( function_exists( 'fnmatch' ) && fnmatch( $pattern, $path ) ) {
				return true;
			}

			if ( false !== strpos( $path, $pattern ) ) {
				return true;
			}
		}

		if ( $is_dir && preg_match( '#(?:^|/)\.(?:git|svn)$#', $path ) ) {
			return true;
		}

		if ( preg_match( '#(?:^|/)(?:node_modules)(?:/|$)#', $path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param string $include_root Configured include path.
	 *
	 * @return string|WP_Error
	 */
	private function resolve_content_path( $include_root ) {
		$include_root = trim( str_replace( '\\', '/', $include_root ), '/' );

		if ( 0 !== strpos( $include_root, 'wp-content' ) ) {
			return new WP_Error(
				'invalid_snapshot_root',
				__( 'Snapshot include roots must stay under wp-content', 'migwp-migrator' ),
				[ 'status' => 400 ]
			);
		}

		$suffix = ltrim( substr( $include_root, strlen( 'wp-content' ) ), '/' );
		$path   = '' === $suffix ? WP_CONTENT_DIR : trailingslashit( WP_CONTENT_DIR ) . $suffix;

		return wp_normalize_path( $path );
	}

	/**
	 * @param string $path     Relative path.
	 * @param array  $prefixes Excluded prefixes.
	 *
	 * @return bool
	 */
	private function is_under_skipped_prefix( $path, array $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $relative_path Relative archive path.
	 *
	 * @return bool
	 */
	private function is_compressible_extension( $relative_path ) {
		$ext = strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) );

		return in_array(
			$ext,
			[ 'css', 'csv', 'html', 'htm', 'js', 'json', 'log', 'map', 'md', 'php', 'sql', 'svg', 'txt', 'xml', 'yaml', 'yml' ],
			true
		);
	}

	/**
	 * @param string $source_path Source file.
	 * @param string $target_path Temp gzip path.
	 *
	 * @return true|WP_Error
	 */
	private function stream_gzip_file( $source_path, $target_path ) {
		$source = @fopen( $source_path, 'rb' );
		$target = @gzopen( $target_path, 'wb6' );

		if ( ! $source || ! $target ) {
			if ( $source ) {
				fclose( $source );
			}
			if ( $target ) {
				gzclose( $target );
			}

			return new WP_Error(
				'filesystem_snapshot_gzip_open_failed',
				__( 'Could not open a file for entry compression', 'migwp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		while ( ! feof( $source ) ) {
			$chunk = fread( $source, 1024 * 1024 );

			if ( false === $chunk ) {
				fclose( $source );
				gzclose( $target );

				return new WP_Error(
					'filesystem_snapshot_gzip_read_failed',
					__( 'Could not compress a filesystem snapshot entry', 'migwp-migrator' ),
					[ 'status' => 500 ]
				);
			}

			if ( '' !== $chunk ) {
				gzwrite( $target, $chunk );
			}
		}

		fclose( $source );
		gzclose( $target );

		return true;
	}

	/**
	 * @param resource $handle File handle.
	 * @param mixed    $crypto Secretstream state.
	 * @param string   $chunk  Plaintext chunk.
	 * @param int      $tag    Sodium secretstream tag.
	 *
	 * @return void
	 */
	private function write_secretstream_chunk( $handle, &$crypto, $chunk, $tag = 0 ) {
		$cipher = sodium_crypto_secretstream_xchacha20poly1305_push( $crypto, $chunk, '', $tag );
		fwrite( $handle, pack( 'N', strlen( $cipher ) ) );
		fwrite( $handle, $cipher );
	}

	/**
	 * @param int    $type         Entry type.
	 * @param string $path         Relative path.
	 * @param int    $payload_size Stored payload size.
	 * @param string $sha256_raw   Raw SHA-256 bytes.
	 *
	 * @return string
	 */
	private function entry_header_binary( $type, $path, $payload_size, $sha256_raw ) {
		$path_length = strlen( $path );
		$high        = (int) floor( $payload_size / 4294967296 );
		$low         = (int) ( $payload_size % 4294967296 );

		return chr( (int) $type ) . pack( 'N3', $high, $low, $path_length ) . $sha256_raw;
	}

	/**
	 * @param array $state State payload.
	 *
	 * @return int
	 */
	private function calculate_progress( array $state ) {
		if ( empty( $state['items_total'] ) ) {
			return 0;
		}

		return (int) min( 99, floor( ( (int) $state['items_done'] / (int) $state['items_total'] ) * 100 ) );
	}

	/**
	 * @param array $state Snapshot state.
	 *
	 * @return void
	 */
	private function persist_state( array $state ) {
		$this->state_store->write( 'filesystem', $state );
	}

	/**
	 * @param string $worker_token Worker token.
	 *
	 * @return true|WP_Error
	 */
	private function assert_worker_current( $worker_token ) {
		$state = $this->state_store->read( 'filesystem' );

		if ( empty( $state['worker_token'] ) || $state['worker_token'] !== $worker_token ) {
			return new WP_Error(
				'snapshot_worker_superseded',
				__( 'Filesystem snapshot worker has been superseded by a newer job', 'migwp-migrator' ),
				[ 'status' => 409 ]
			);
		}

		return true;
	}

	/**
	 * @param array           $state Snapshot state.
	 * @param string|WP_Error $error Error.
	 *
	 * @return void
	 */
	private function mark_failed( array &$state, $error ) {
		$state['status']        = 'failed';
		$state['updated_at']    = gmdate( 'c' );
		$state['finished_at']   = $state['updated_at'];
		$state['current_phase'] = 'failed';
		$state['current_item']  = null;
		$state['error']         = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
		$this->persist_state( $state );
	}

	/**
	 * Remove the previous latest filesystem artifact tree.
	 *
	 * @return void
	 */
	private function cleanup_previous_artifacts() {
		$state = $this->state_store->read( 'filesystem' );

		if ( ! empty( $state['artifact_file'] ) && file_exists( $state['artifact_file'] ) ) {
			$this->delete_tree( dirname( $state['artifact_file'] ) );
		}
	}

	/**
	 * @param string $path Directory or file.
	 *
	 * @return void
	 */
	private function delete_tree( $path ) {
		if ( is_file( $path ) ) {
			@unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = scandir( $path );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$this->delete_tree( $path . DIRECTORY_SEPARATOR . $item );
		}

		@rmdir( $path );
	}
}
