<?php

namespace FlyWP\Migrator\Services\Snapshots;

use WP_Error;

class StateStore {
	/**
	 * @var Paths
	 */
	private $paths;

	public function __construct( Paths $paths = null ) {
		$this->paths = $paths ?: new Paths();
	}

	/**
	 * Read the latest state for a snapshot type.
	 *
	 * @param string $type Snapshot type.
	 *
	 * @return array
	 */
	public function read( $type ) {
		$file = $this->get_state_file( $type );

		if ( is_wp_error( $file ) || ! file_exists( $file ) ) {
			return [];
		}

		$contents = file_get_contents( $file );
		$state    = json_decode( (string) $contents, true );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * Persist latest state for a snapshot type.
	 *
	 * @param string $type  Snapshot type.
	 * @param array  $state State payload.
	 *
	 * @return true|WP_Error
	 */
	public function write( $type, array $state ) {
		$file = $this->get_state_file( $type );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$dir = dirname( $file );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'snapshot_state_directory_unwritable',
				__( 'Could not create snapshot state directory', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$temp_file = $file . '.tmp';
		$payload   = wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === file_put_contents( $temp_file, (string) $payload, LOCK_EX ) ) {
			return new WP_Error(
				'snapshot_state_write_failed',
				__( 'Could not write snapshot state', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! @rename( $temp_file, $file ) ) {
			@unlink( $temp_file );

			return new WP_Error(
				'snapshot_state_commit_failed',
				__( 'Could not finalize snapshot state', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		return true;
	}

	/**
	 * Delete latest state for a snapshot type.
	 *
	 * @param string $type Snapshot type.
	 *
	 * @return void
	 */
	public function delete( $type ) {
		$file = $this->get_state_file( $type );

		if ( ! is_wp_error( $file ) && file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	/**
	 * @param string $type Snapshot type.
	 *
	 * @return string|WP_Error
	 */
	private function get_state_file( $type ) {
		$type = sanitize_key( $type );
		$dir  = $this->paths->ensure_dir( 'state' );

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		return trailingslashit( $dir ) . $type . '.json';
	}
}
