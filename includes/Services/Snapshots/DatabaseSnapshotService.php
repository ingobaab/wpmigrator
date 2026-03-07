<?php

namespace FlyWP\Migrator\Services\Snapshots;

use FlyWP\Migrator\Services\Crypto\StreamEncryptor;
use mysqli;
use WP_Error;

class DatabaseSnapshotService {
	/**
	 * @var StateStore
	 */
	private $state_store;

	/**
	 * @var Paths
	 */
	private $paths;

	/**
	 * @var StreamEncryptor
	 */
	private $encryptor;

	public function __construct( StateStore $state_store = null, Paths $paths = null, StreamEncryptor $encryptor = null ) {
		$this->state_store = $state_store ?: new StateStore();
		$this->paths       = $paths ?: new Paths();
		$this->encryptor   = $encryptor ?: new StreamEncryptor();
	}

	/**
	 * Return the latest database snapshot state.
	 *
	 * @return array
	 */
	public function get_latest_state() {
		$state = $this->state_store->read( 'database' );

		if ( empty( $state ) ) {
			return $this->empty_state();
		}

		return $state;
	}

	/**
	 * Queue a new latest database snapshot.
	 *
	 * @return array|WP_Error
	 */
	public function queue_snapshot() {
		$snapshot_id = wp_generate_uuid4();
		$created_at  = gmdate( 'c' );
		$worker_token = wp_generate_password( 32, false, false );

		$state = $this->empty_state();
		$state = array_merge(
			$state,
			[
				'status'             => 'queued',
				'snapshot_id'        => $snapshot_id,
				'created_at'         => $created_at,
				'started_at'         => null,
				'updated_at'         => $created_at,
				'current_phase'      => 'queued',
				'items_done'         => 0,
				'items_total'        => 0,
				'processed_bytes'    => 0,
				'total_bytes_estimate' => 0,
				'written_bytes'      => 0,
				'error'              => null,
				'worker_token'       => $worker_token,
			]
		);

		$this->persist_state( $state );

		return $state;
	}

	/**
	 * Run the queued latest database snapshot for a worker token.
	 *
	 * @param string $worker_token Worker token.
	 *
	 * @return array|WP_Error
	 */
	public function run_queued_snapshot( $worker_token ) {
		global $wpdb;

		if ( ! extension_loaded( 'mysqli' ) ) {
			return new WP_Error(
				'snapshot_mysqli_unavailable',
				__( 'The mysqli extension is required for database snapshots', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! extension_loaded( 'zlib' ) ) {
			return new WP_Error(
				'snapshot_zlib_unavailable',
				__( 'The zlib extension is required for database snapshots', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$state = $this->state_store->read( 'database' );

		if ( empty( $state ) || empty( $state['worker_token'] ) || $state['worker_token'] !== $worker_token ) {
			return new WP_Error(
				'snapshot_worker_token_invalid',
				__( 'Database snapshot worker token is invalid or superseded', 'flywp-migrator' ),
				[ 'status' => 409 ]
			);
		}

		$started_at             = gmdate( 'c' );
		$state['status']        = 'running';
		$state['started_at']    = $started_at;
		$state['updated_at']    = $started_at;
		$state['current_phase'] = 'preparing';
		$state['error']         = null;
		$this->cleanup_previous_artifacts();
		$this->persist_state( $state );

		$dir = $this->paths->ensure_dir( 'snapshots/database/' . $state['snapshot_id'] );
		if ( is_wp_error( $dir ) ) {
			$this->mark_failed( $state, $dir );

			return $dir;
		}

		$sql_path       = trailingslashit( $dir ) . 'database.sql';
		$gzip_path      = trailingslashit( $dir ) . 'database.sql.gz';
		$encrypted_path = trailingslashit( $dir ) . wp_generate_password( 20, false, false ) . '.dbsnap';

		$connection = $this->connect();
		if ( is_wp_error( $connection ) ) {
			$this->mark_failed( $state, $connection );

			return $connection;
		}

		$tables         = $this->get_tables( $connection, $wpdb->prefix );
		$total_bytes    = $this->get_total_bytes_estimate( $connection, $wpdb->prefix );
		$table_count    = count( $tables );

		$state['items_total']          = $table_count;
		$state['total_bytes_estimate'] = $total_bytes;
		$state['current_phase']        = 'dumping';
		$this->persist_state( $state );

		$sql_handle = @fopen( $sql_path, 'wb' );
		if ( ! $sql_handle ) {
			$error = new WP_Error(
				'snapshot_sql_open_failed',
				__( 'Could not create temporary SQL dump', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
			$this->mark_failed( $state, $error );

			return $error;
		}

		$this->write_sql_header( $sql_handle );

		$processed_bytes = 0;
		$items_done      = 0;

		foreach ( $tables as $table ) {
			if ( is_wp_error( $this->assert_worker_current( $worker_token ) ) ) {
				fclose( $sql_handle );
				$connection->close();

				return $this->state_store->read( 'database' );
			}

			$table_result = $this->dump_table( $connection, $table, $sql_handle, $processed_bytes );

			if ( is_wp_error( $table_result ) ) {
				fclose( $sql_handle );
				$this->mark_failed( $state, $table_result );

				return $table_result;
			}

			$items_done++;
			$state['items_done']      = $items_done;
			$state['processed_bytes'] = $processed_bytes;
			$state['written_bytes']   = filesize( $sql_path );
			$state['current_item']    = $table['name'];
			$state['updated_at']      = gmdate( 'c' );
			$state['progress_percent'] = $this->calculate_progress( $state );
			$this->persist_state( $state );
		}

		fclose( $sql_handle );
		$connection->close();

		$state['current_phase']  = 'compressing';
		$state['current_item']   = basename( $sql_path );
		$state['updated_at']     = gmdate( 'c' );
		$this->persist_state( $state );

		$gzip_result = $this->gzip_file( $sql_path, $gzip_path );
		if ( is_wp_error( $gzip_result ) ) {
			$this->mark_failed( $state, $gzip_result );

			return $gzip_result;
		}

		$state['written_bytes'] = filesize( $gzip_path );
		$state['current_phase'] = 'encrypting';
		$state['current_item']  = basename( $gzip_path );
		$state['updated_at']    = gmdate( 'c' );
		$this->persist_state( $state );

		$encryption_result = $this->encryptor->encrypt_file(
			$gzip_path,
			$encrypted_path,
			flywp_migrator()->get_migration_key(),
			$state['snapshot_id'],
			'database-snapshot',
			[
				'created_at'        => $state['created_at'],
				'compression'       => 'gzip',
				'site_url'          => home_url(),
				'database'          => DB_NAME,
				'table_prefix'      => $wpdb->prefix,
				'uncompressed_size' => filesize( $sql_path ),
				'gzip_size'         => filesize( $gzip_path ),
			]
		);

		if ( is_wp_error( $encryption_result ) ) {
			$this->mark_failed( $state, $encryption_result );

			return $encryption_result;
		}

		@unlink( $sql_path );
		@unlink( $gzip_path );

		$state['status']           = 'complete';
		$state['finished_at']      = gmdate( 'c' );
		$state['updated_at']       = $state['finished_at'];
		$state['current_phase']    = 'complete';
		$state['current_item']     = null;
		$state['progress_percent'] = 100;
		$state['written_bytes']    = filesize( $encrypted_path );
		$state['result_size']      = filesize( $encrypted_path );
		$state['artifact_file']    = $encrypted_path;
		$this->persist_state( $state );

		return $state;
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
	 * @param array $state Snapshot state.
	 *
	 * @return void
	 */
	private function persist_state( array $state ) {
		$this->state_store->write( 'database', $state );
	}

	/**
	 * @param string $worker_token Worker token.
	 *
	 * @return true|WP_Error
	 */
	private function assert_worker_current( $worker_token ) {
		$state = $this->state_store->read( 'database' );

		if ( empty( $state['worker_token'] ) || $state['worker_token'] !== $worker_token ) {
			return new WP_Error(
				'snapshot_worker_superseded',
				__( 'Database snapshot worker has been superseded by a newer job', 'flywp-migrator' ),
				[ 'status' => 409 ]
			);
		}

		return true;
	}

	/**
	 * @param array           $state Snapshot state.
	 * @param WP_Error|string $error Error object or string.
	 *
	 * @return array
	 */
	private function mark_failed( array $state, $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;

		$state['status']        = 'failed';
		$state['error']         = $message;
		$state['finished_at']   = gmdate( 'c' );
		$state['updated_at']    = $state['finished_at'];
		$state['current_phase'] = 'failed';
		$state['current_item']  = null;
		$this->persist_state( $state );

		return $state;
	}

	/**
	 * @return mysqli|WP_Error
	 */
	private function connect() {
		$params = $this->parse_db_host( DB_HOST );
		$db     = mysqli_init();

		if ( ! $db ) {
			return new WP_Error(
				'snapshot_db_init_failed',
				__( 'Could not initialize mysqli', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$db->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 );

		$connected = @$db->real_connect(
			$params['host'],
			DB_USER,
			DB_PASSWORD,
			DB_NAME,
			$params['port'],
			$params['socket']
		);

		if ( ! $connected ) {
			return new WP_Error(
				'snapshot_db_connect_failed',
				$db->connect_error,
				[ 'status' => 500 ]
			);
		}

		$db->set_charset( defined( 'DB_CHARSET' ) && DB_CHARSET ? DB_CHARSET : 'utf8mb4' );

		return $db;
	}

	/**
	 * @param mysqli $connection Database connection.
	 * @param string $prefix     Table prefix.
	 *
	 * @return array[]
	 */
	private function get_tables( mysqli $connection, $prefix ) {
		$tables = [];
		$sql    = sprintf(
			"SHOW FULL TABLES FROM `%s` LIKE '%s'",
			$connection->real_escape_string( DB_NAME ),
			$connection->real_escape_string( $prefix ) . '%'
		);
		$result = $connection->query( $sql );

		if ( ! $result ) {
			return $tables;
		}

		while ( $row = $result->fetch_row() ) {
			$tables[] = [
				'name' => $row[0],
				'type' => isset( $row[1] ) ? strtolower( $row[1] ) : 'base table',
			];
		}

		$result->free();

		return $tables;
	}

	/**
	 * @param mysqli $connection Database connection.
	 * @param string $prefix     Table prefix.
	 *
	 * @return int
	 */
	private function get_total_bytes_estimate( mysqli $connection, $prefix ) {
		$total  = 0;
		$sql    = sprintf(
			"SHOW TABLE STATUS FROM `%s` LIKE '%s'",
			$connection->real_escape_string( DB_NAME ),
			$connection->real_escape_string( $prefix ) . '%'
		);
		$result = $connection->query( $sql );

		if ( ! $result ) {
			return $total;
		}

		while ( $row = $result->fetch_assoc() ) {
			$total += isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0;
		}

		$result->free();

		return $total;
	}

	/**
	 * @param resource $handle SQL file handle.
	 *
	 * @return void
	 */
	private function write_sql_header( $handle ) {
		fwrite( $handle, "-- FlyWP Migrator database snapshot\n" );
		fwrite( $handle, '-- Created at: ' . gmdate( 'c' ) . "\n" );
		fwrite( $handle, "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n" );
		fwrite( $handle, "SET time_zone = '+00:00';\n" );
		fwrite( $handle, "/*!40101 SET NAMES utf8mb4 */;\n\n" );
	}

	/**
	 * @param mysqli   $connection      Database connection.
	 * @param array    $table           Table metadata.
	 * @param resource $handle          SQL file handle.
	 * @param int      $processed_bytes Running byte estimate.
	 *
	 * @return true|WP_Error
	 */
	private function dump_table( mysqli $connection, $table, $handle, &$processed_bytes ) {
		$table_label = isset( $table['name'] ) ? $table['name'] : '';
		$table_type  = isset( $table['type'] ) ? $table['type'] : 'base table';
		$table_name  = '`' . str_replace( '`', '``', $table_label ) . '`';
		$create_sql  = $this->get_create_statement( $connection, $table_label );

		if ( is_wp_error( $create_sql ) ) {
			return $create_sql;
		}

		fwrite( $handle, '-- Object: ' . $table_label . "\n" );
		fwrite( $handle, ( 'view' === $table_type ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ' ) . $table_name . ";\n" );
		fwrite( $handle, $create_sql . ";\n\n" );

		if ( 'view' === $table_type ) {
			return true;
		}

		$result = $connection->query( 'SELECT * FROM ' . $table_name, MYSQLI_USE_RESULT );
		if ( false === $result ) {
			return new WP_Error(
				'snapshot_table_read_failed',
				sprintf(
					/* translators: %s: table name */
					__( 'Could not read table %s', 'flywp-migrator' ),
					$table_label
				),
				[ 'status' => 500 ]
			);
		}

		$fields      = $result->fetch_fields();
		$field_names = array_map(
			static function ( $field ) {
				return '`' . str_replace( '`', '``', $field->name ) . '`';
			},
			$fields
		);

		while ( $row = $result->fetch_assoc() ) {
			$values = [];

			foreach ( $fields as $field ) {
				$value = isset( $row[ $field->name ] ) ? $row[ $field->name ] : null;
				$values[] = $this->sql_value( $connection, $value );
				if ( null !== $value ) {
					$processed_bytes += strlen( (string) $value );
				}
			}

			$line = 'INSERT INTO ' . $table_name . ' (' . implode( ', ', $field_names ) . ') VALUES (' . implode( ', ', $values ) . ");\n";
			fwrite( $handle, $line );
		}

		$result->free();
		fwrite( $handle, "\n" );

		return true;
	}

	/**
	 * @param mysqli $connection Database connection.
	 * @param string $table      Table name.
	 *
	 * @return string|WP_Error
	 */
	private function get_create_statement( mysqli $connection, $table ) {
		$table_name = '`' . str_replace( '`', '``', $table ) . '`';
		$result     = $connection->query( 'SHOW CREATE TABLE ' . $table_name );

		if ( ! $result ) {
			return new WP_Error(
				'snapshot_table_schema_failed',
				sprintf(
					/* translators: %s: table name */
					__( 'Could not read schema for table %s', 'flywp-migrator' ),
					$table
				),
				[ 'status' => 500 ]
			);
		}

		$row = $result->fetch_assoc();
		$result->free();

		if ( isset( $row['Create Table'] ) ) {
			return $row['Create Table'];
		}

		if ( isset( $row['Create View'] ) ) {
			return $row['Create View'];
		}

		return new WP_Error(
			'snapshot_table_schema_missing',
			sprintf(
				/* translators: %s: table name */
				__( 'Schema for table %s was empty', 'flywp-migrator' ),
				$table
			),
			[ 'status' => 500 ]
		);
	}

	/**
	 * @param mysqli     $connection Database connection.
	 * @param string|int $value      Field value.
	 *
	 * @return string
	 */
	private function sql_value( mysqli $connection, $value ) {
		if ( null === $value ) {
			return 'NULL';
		}

		return "'" . $connection->real_escape_string( (string) $value ) . "'";
	}

	/**
	 * @param string $source_path Source file.
	 * @param string $target_path Target file.
	 *
	 * @return true|WP_Error
	 */
	private function gzip_file( $source_path, $target_path ) {
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
				'snapshot_gzip_open_failed',
				__( 'Could not open files for gzip compression', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		while ( ! feof( $source ) ) {
			$chunk = fread( $source, 1024 * 1024 );

			if ( false === $chunk ) {
				fclose( $source );
				gzclose( $target );

				return new WP_Error(
					'snapshot_gzip_read_failed',
					__( 'Could not read SQL dump during compression', 'flywp-migrator' ),
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
	 * Remove the prior latest artifact tree before creating a replacement snapshot.
	 *
	 * @return void
	 */
	private function cleanup_previous_artifacts() {
		$state = $this->state_store->read( 'database' );

		if ( ! empty( $state['artifact_file'] ) && file_exists( $state['artifact_file'] ) ) {
			$artifact_dir = dirname( $state['artifact_file'] );
			$this->delete_tree( $artifact_dir );
		}
	}

	/**
	 * @param string $path Directory path.
	 *
	 * @return void
	 */
	private function delete_tree( $path ) {
		if ( ! is_dir( $path ) ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}

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

	/**
	 * @param array $state Snapshot state.
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
	 * Parse DB_HOST into host, port, and socket parts.
	 *
	 * @param string $host Raw DB_HOST value.
	 *
	 * @return array
	 */
	private function parse_db_host( $host ) {
		$socket = null;
		$port   = null;

		if ( false !== strpos( $host, ':' ) && substr_count( $host, ':' ) === 1 ) {
			list( $host, $suffix ) = explode( ':', $host, 2 );
			if ( is_numeric( $suffix ) ) {
				$port = (int) $suffix;
			} else {
				$socket = $suffix;
			}
		}

		return [
			'host'   => $host,
			'port'   => $port ?: ini_get( 'mysqli.default_port' ),
			'socket' => $socket ?: ini_get( 'mysqli.default_socket' ),
		];
	}
}
