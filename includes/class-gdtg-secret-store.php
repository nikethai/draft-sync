<?php
/**
 * Encrypted at-rest secret storage for DraftSync.
 *
 * Uses AES-256-GCM with a key derived from wp_salt('auth') so that
 * secrets are bound to the WordPress installation and never stored
 * in plaintext.
 *
 * @package DraftSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Secret_Store
 *
 * Static methods only. No constructor needed.
 */
class GDTG_Secret_Store {

	/**
	 * AES cipher method.
	 *
	 * @var string
	 */
	const CIPHER = 'aes-256-gcm';

	/**
	 * IV length in bytes.
	 *
	 * @var int
	 */
	const IV_LEN = 16;

	/**
	 * GCM tag length in bytes.
	 *
	 * @var int
	 */
	const TAG_LEN = 16;

	/**
	 * Derive a 32-byte encryption key from wp_salt('auth').
	 *
	 * Returns WP_Error if the salt is unavailable.
	 *
	 * @return string|WP_Error 32-byte binary key or WP_Error.
	 */
	private static function derive_key() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return new WP_Error(
				'gdtg_secret_missing_wp_salt',
				__( 'WordPress salt function unavailable.', 'draftsync' )
			);
		}

		$salt = wp_salt( 'auth' );
		if ( ! is_string( $salt ) || '' === $salt ) {
			return new WP_Error(
				'gdtg_secret_missing_salt',
				__( 'WordPress auth salt is empty.', 'draftsync' )
			);
		}

		if ( ! function_exists( 'hash' ) || ! in_array( 'sha256', hash_algos(), true ) ) {
			return new WP_Error(
				'gdtg_secret_missing_hash',
				__( 'SHA-256 hashing not available.', 'draftsync' )
			);
		}

		return hash( 'sha256', $salt, true );
	}

	/**
	 * Check whether the required OpenSSL cipher is available.
	 *
	 * @return bool
	 */
	private static function is_cipher_available() {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$ciphers = openssl_get_cipher_methods();
		return is_array( $ciphers ) && in_array( self::CIPHER, $ciphers, true );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext The secret to encrypt.
	 * @return string|WP_Error Base64-encoded JSON envelope {iv, tag, ct} or WP_Error.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) ) {
			return new WP_Error(
				'gdtg_secret_invalid_input',
				__( 'Plaintext must be a string.', 'draftsync' )
			);
		}

		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! self::is_cipher_available() ) {
			return new WP_Error(
				'gdtg_secret_cipher_missing',
				__( 'AES-256-GCM cipher not available on this host.', 'draftsync' )
			);
		}

		$key = self::derive_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$iv = openssl_random_pseudo_bytes( self::IV_LEN );
		if ( false === $iv || strlen( $iv ) !== self::IV_LEN ) {
			return new WP_Error(
				'gdtg_secret_iv_failed',
				__( 'Failed to generate IV.', 'draftsync' )
			);
		}

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LEN
		);

		if ( false === $ciphertext || null === $tag ) {
			return new WP_Error(
				'gdtg_secret_encrypt_failed',
				__( 'Encryption failed.', 'draftsync' )
			);
		}

		$envelope = array(
			'iv'  => base64_encode( $iv ),
			'tag' => base64_encode( $tag ),
			'ct'  => base64_encode( $ciphertext ),
		);

		return base64_encode( wp_json_encode( $envelope ) );
	}

	/**
	 * Decrypt a ciphertext envelope.
	 *
	 * @param string $ciphertext Base64-encoded JSON envelope produced by encrypt().
	 * @return string|WP_Error Decrypted plaintext or WP_Error.
	 */
	public static function decrypt( $ciphertext ) {
		if ( ! is_string( $ciphertext ) || '' === $ciphertext ) {
			return '';
		}

		if ( ! self::is_cipher_available() ) {
			return new WP_Error(
				'gdtg_secret_cipher_missing',
				__( 'AES-256-GCM cipher not available on this host.', 'draftsync' )
			);
		}

		$key = self::derive_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$raw = base64_decode( $ciphertext, true );
		if ( false === $raw ) {
			return new WP_Error(
				'gdtg_secret_decode_failed',
				__( 'Failed to decode ciphertext envelope.', 'draftsync' )
			);
		}

		$envelope = json_decode( $raw, true );
		if ( ! is_array( $envelope ) || ! isset( $envelope['iv'], $envelope['tag'], $envelope['ct'] ) ) {
			return new WP_Error(
				'gdtg_secret_invalid_envelope',
				__( 'Ciphertext envelope is malformed.', 'draftsync' )
			);
		}

		$iv  = base64_decode( $envelope['iv'], true );
		$tag = base64_decode( $envelope['tag'], true );
		$ct  = base64_decode( $envelope['ct'], true );

		if ( false === $iv || false === $tag || false === $ct ) {
			return new WP_Error(
				'gdtg_secret_decode_failed',
				__( 'Failed to decode envelope fields.', 'draftsync' )
			);
		}

		if ( strlen( $iv ) !== self::IV_LEN || strlen( $tag ) !== self::TAG_LEN ) {
			return new WP_Error(
				'gdtg_secret_invalid_envelope',
				__( 'Envelope IV or tag has wrong length.', 'draftsync' )
			);
		}

		$plaintext = openssl_decrypt(
			$ct,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			return new WP_Error(
				'gdtg_secret_decrypt_failed',
				__( 'Decryption failed — tampered or corrupted ciphertext.', 'draftsync' )
			);
		}

		return $plaintext;
	}

	/**
	 * Read an option value, decrypting if it is stored as an encrypted envelope.
	 *
	 * Legacy plaintext values are returned as-is (backward compatibility).
	 *
	 * @param string $option_name The option key to read.
	 * @return string|WP_Error Decrypted value or WP_Error.
	 */
	public static function get( $option_name ) {
		if ( ! function_exists( 'get_option' ) ) {
			return new WP_Error(
				'gdtg_secret_missing_wp',
				__( 'WordPress get_option unavailable.', 'draftsync' )
			);
		}

		$stored = get_option( $option_name, '' );

		if ( '' === $stored ) {
			return '';
		}

		// Try to detect encrypted envelope: base64-decodable JSON with iv/tag/ct.
		$raw = base64_decode( $stored, true );
		if ( false === $raw ) {
			// Not base64 — legacy plaintext.
			return $stored;
		}

		$parsed = json_decode( $raw, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['iv'], $parsed['tag'], $parsed['ct'] ) ) {
			// Not our envelope — legacy plaintext.
			return $stored;
		}

		// Looks like an envelope — try decrypting.
		$decrypted = self::decrypt( $stored );
		if ( is_wp_error( $decrypted ) ) {
			return $decrypted;
		}

		return $decrypted;
	}

	/**
	 * Encrypt a value and store it as a WordPress option.
	 *
	 * @param string $option_name The option key.
	 * @param string $plaintext   The secret to encrypt and store.
	 * @return bool True on success, false on failure.
	 */
	public static function set( $option_name, $plaintext ) {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		if ( '' === $plaintext ) {
			update_option( $option_name, '' );
			return true;
		}

		$encrypted = self::encrypt( $plaintext );
		if ( is_wp_error( $encrypted ) ) {
			return false;
		}

		return update_option( $option_name, $encrypted );
	}

	/**
	 * Migrate a WordPress option from legacy plaintext to encrypted storage.
	 *
	 * If the option value does not parse as our encrypted envelope JSON,
	 * it is treated as legacy plaintext and re-encrypted in place.
	 *
	 * @param string $option_name The option key to migrate.
	 * @return bool True if migration succeeded or was unnecessary, false on error.
	 */
	public static function migrate_option( $option_name ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}

		$stored = get_option( $option_name, null );

		if ( null === $stored ) {
			// Option doesn't exist — nothing to migrate.
			return true;
		}

		if ( '' === $stored ) {
			return true;
		}

		// Check if already encrypted.
		$raw = base64_decode( $stored, true );
		if ( false !== $raw ) {
			$parsed = json_decode( $raw, true );
			if ( is_array( $parsed ) && isset( $parsed['iv'], $parsed['tag'], $parsed['ct'] ) ) {
				// Already encrypted.
				return true;
			}
		}

		// Legacy plaintext — encrypt it.
		$encrypted = self::encrypt( $stored );
		if ( is_wp_error( $encrypted ) ) {
			return false;
		}

		return update_option( $option_name, $encrypted );
	}
}
