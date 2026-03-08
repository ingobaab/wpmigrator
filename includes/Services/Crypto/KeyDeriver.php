<?php

namespace MigWP\Migrator\Services\Crypto;

class KeyDeriver {
	/**
	 * Derive a per-snapshot secretstream key with HKDF-SHA256.
	 *
	 * @param string $migration_key Base shared secret.
	 * @param string $snapshot_id   Snapshot identifier.
	 * @param string $salt          Binary salt.
	 * @param string $purpose       Derivation purpose.
	 *
	 * @return string
	 */
	public function derive_secretstream_key( $migration_key, $snapshot_id, $salt, $purpose ) {
		return hash_hkdf(
			'sha256',
			(string) $migration_key,
			SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
			'migwp-migrator:' . $purpose . ':' . $snapshot_id,
			$salt
		);
	}
}
