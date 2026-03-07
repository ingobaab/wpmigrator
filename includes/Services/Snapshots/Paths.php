<?php

namespace FlyWP\Migrator\Services\Snapshots;

use WP_Error;

class Paths {
	/**
	 * Get the base runtime directory under uploads.
	 *
	 * @return string|WP_Error
	 */
	public function get_base_dir() {
		$upload_dir = wp_upload_dir();

		if ( empty( $upload_dir['basedir'] ) ) {
			return new WP_Error(
				'snapshot_uploads_unavailable',
				__( 'Uploads directory is not available', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$uploads_base_dir = $upload_dir['basedir'];

		if ( ! file_exists( $uploads_base_dir ) && ! wp_mkdir_p( $uploads_base_dir ) ) {
			return new WP_Error(
				'snapshot_uploads_directory_unwritable',
				__( 'Could not create the WordPress uploads directory', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! is_dir( $uploads_base_dir ) || ! is_writable( $uploads_base_dir ) ) {
			return new WP_Error(
				'snapshot_uploads_directory_unwritable',
				__( 'The WordPress uploads directory is not writable', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		return trailingslashit( $uploads_base_dir ) . 'flywp-migrator';
	}

	/**
	 * Ensure a runtime subdirectory exists.
	 *
	 * @param string $relative Relative path below the base runtime directory.
	 *
	 * @return string|WP_Error
	 */
	public function ensure_dir( $relative ) {
		$base_dir = $this->get_base_dir();

		if ( is_wp_error( $base_dir ) ) {
			return $base_dir;
		}

		$relative = trim( str_replace( '\\', '/', $relative ), '/' );
		$path     = $base_dir;

		if ( '' !== $relative ) {
			$path = trailingslashit( $base_dir ) . $relative;
		}

		if ( wp_mkdir_p( $path ) ) {
			return $path;
		}

		return new WP_Error(
			'snapshot_directory_unwritable',
			__( 'Could not create snapshot runtime directory', 'flywp-migrator' ),
			[ 'status' => 500 ]
		);
	}

	/**
	 * Convert an absolute path under uploads to the relative path required by /files/*.
	 *
	 * @param string $absolute_path Absolute path.
	 *
	 * @return string|WP_Error
	 */
	public function to_transfer_relative_path( $absolute_path ) {
		$upload_dir = wp_upload_dir();
		$base_dir   = isset( $upload_dir['basedir'] ) ? wp_normalize_path( $upload_dir['basedir'] ) : '';
		$absolute   = wp_normalize_path( $absolute_path );

		if ( '' === $base_dir || 0 !== strpos( $absolute, trailingslashit( $base_dir ) ) ) {
			return new WP_Error(
				'invalid_transfer_path',
				__( 'Artifact path is outside uploads', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		return ltrim( substr( $absolute, strlen( $base_dir ) ), '/' );
	}
}
