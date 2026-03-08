<?php

namespace MigWP\Migrator\Services\Snapshots;

use WP_Error;

class ConfigStore {
	const OPTION_NAME = 'migwp_migrator_snapshot_config';

	/**
	 * Return the stored config merged with defaults.
	 *
	 * @return array
	 */
	public function get() {
		$stored = get_option( self::OPTION_NAME, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return $this->normalize( array_merge( $this->defaults(), $stored ) );
	}

	/**
	 * Validate and persist config.
	 *
	 * @param array $config Proposed config.
	 *
	 * @return array|WP_Error
	 */
	public function update( array $config ) {
		$normalized = $this->normalize( $config );
		$validation = $this->validate( $normalized );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		update_option( self::OPTION_NAME, $normalized, false );

		return $normalized;
	}

	/**
	 * Frozen default config.
	 *
	 * @return array
	 */
	public function defaults() {
		return [
			'include_roots'      => [
				'wp-content/uploads',
				'wp-content/plugins',
				'wp-content/mu-plugins',
				'wp-content/themes',
			],
			'exclude_paths'      => [
				'wp-content/uploads/migwp-migrator',
				'wp-content/ai1wm-backups',
				'wp-content/updraft',
				'wp-content/backupbuddy_backups',
				'wp-content/backups-dup-lite',
				'wp-content/blogvault',
				'wp-content/snapshots',
				'wp-content/wpvividbackups',
			],
			'exclude_patterns'   => [],
			'respect_zipignore'  => true,
		];
	}

	/**
	 * @param array $config Config payload.
	 *
	 * @return array
	 */
	private function normalize( array $config ) {
		return [
			'include_roots'     => $this->normalize_list( isset( $config['include_roots'] ) ? $config['include_roots'] : [] ),
			'exclude_paths'     => $this->normalize_list( isset( $config['exclude_paths'] ) ? $config['exclude_paths'] : [] ),
			'exclude_patterns'  => $this->normalize_list( isset( $config['exclude_patterns'] ) ? $config['exclude_patterns'] : [] ),
			'respect_zipignore' => ! empty( $config['respect_zipignore'] ),
		];
	}

	/**
	 * @param array $config Config payload.
	 *
	 * @return true|WP_Error
	 */
	private function validate( array $config ) {
		if ( empty( $config['include_roots'] ) ) {
			return new WP_Error(
				'invalid_snapshot_config',
				__( 'At least one include root is required', 'migwp-migrator' ),
				[ 'status' => 400 ]
			);
		}

		foreach ( $config['include_roots'] as $path ) {
			if ( ! $this->is_allowed_snapshot_root( $path ) ) {
				return new WP_Error(
					'invalid_snapshot_config',
					sprintf(
						/* translators: %s: relative include path */
						__( 'Include root "%s" must stay under wp-content', 'migwp-migrator' ),
						$path
					),
					[ 'status' => 400 ]
				);
			}
		}

		return true;
	}

	/**
	 * @param mixed $values Raw list value.
	 *
	 * @return string[]
	 */
	private function normalize_list( $values ) {
		if ( ! is_array( $values ) ) {
			return [];
		}

		$values = array_map(
			static function ( $value ) {
				$value = is_scalar( $value ) ? (string) $value : '';
				$value = trim( str_replace( '\\', '/', $value ), '/' );

				return $value;
			},
			$values
		);

		$values = array_values( array_unique( array_filter( $values ) ) );

		sort( $values );

		return $values;
	}

	/**
	 * @param string $path Relative snapshot include path.
	 *
	 * @return bool
	 */
	private function is_allowed_snapshot_root( $path ) {
		return (bool) preg_match( '#^wp-content(?:/|$)#', $path );
	}
}
