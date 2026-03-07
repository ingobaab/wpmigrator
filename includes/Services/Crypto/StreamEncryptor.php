<?php

namespace FlyWP\Migrator\Services\Crypto;

use WP_Error;

class StreamEncryptor {
	/**
	 * @var KeyDeriver
	 */
	private $key_deriver;

	public function __construct( KeyDeriver $key_deriver = null ) {
		$this->key_deriver = $key_deriver ?: new KeyDeriver();
	}

	/**
	 * Encrypt a file with sodium secretstream and a small JSON header.
	 *
	 * @param string $source_path   Unencrypted source file path.
	 * @param string $target_path   Final encrypted path.
	 * @param string $migration_key Base shared secret.
	 * @param string $snapshot_id   Snapshot identifier.
	 * @param string $purpose       Derivation purpose.
	 * @param array  $metadata      Extra public metadata.
	 *
	 * @return array|WP_Error
	 */
	public function encrypt_file( $source_path, $target_path, $migration_key, $snapshot_id, $purpose, array $metadata = [] ) {
		if ( ! extension_loaded( 'sodium' ) ) {
			return new WP_Error(
				'snapshot_encryption_unavailable',
				__( 'The sodium extension is required for snapshot encryption', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$salt = random_bytes( 32 );
		$key  = $this->key_deriver->derive_secretstream_key( $migration_key, $snapshot_id, $salt, $purpose );

		$state_header = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
		$stream_state = $state_header[0];
		$stream_head  = $state_header[1];

		$header = array_merge(
			[
				'magic'      => 'FWMIGRATOR',
				'version'    => 1,
				'snapshot'   => $snapshot_id,
				'purpose'    => $purpose,
				'algorithm'  => 'secretstream_xchacha20poly1305',
				'kdf'        => 'hkdf-sha256',
				'salt'       => base64_encode( $salt ),
				'stream'     => base64_encode( $stream_head ),
			],
			$metadata
		);

		$source = @fopen( $source_path, 'rb' );
		$target = @fopen( $target_path, 'wb' );

		if ( ! $source || ! $target ) {
			if ( $source ) {
				fclose( $source );
			}
			if ( $target ) {
				fclose( $target );
			}

			return new WP_Error(
				'snapshot_encryption_io_error',
				__( 'Could not open snapshot files for encryption', 'flywp-migrator' ),
				[ 'status' => 500 ]
			);
		}

		$header_json = wp_json_encode( $header, JSON_UNESCAPED_SLASHES );
		$header_len  = pack( 'N', strlen( (string) $header_json ) );

		fwrite( $target, $header_len );
		fwrite( $target, (string) $header_json );

		while ( ! feof( $source ) ) {
			$chunk = fread( $source, 1024 * 1024 );

			if ( false === $chunk ) {
				fclose( $source );
				fclose( $target );

				return new WP_Error(
					'snapshot_encryption_read_failed',
					__( 'Could not read snapshot source during encryption', 'flywp-migrator' ),
					[ 'status' => 500 ]
				);
			}

			if ( '' === $chunk && feof( $source ) ) {
				break;
			}

			$tag        = feof( $source ) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0;
			$ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push( $stream_state, $chunk, '', $tag );
			fwrite( $target, pack( 'N', strlen( $ciphertext ) ) );
			fwrite( $target, $ciphertext );
		}

		fclose( $source );
		fclose( $target );

		return [
			'header' => $header,
			'size'   => filesize( $target_path ),
		];
	}
}
