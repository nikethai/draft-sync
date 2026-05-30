<?php
/**
 * Per-post sync lock to prevent duplicate concurrent sync work.
 *
 * Uses per-post wp_options rows (gdtg_sync_lock_{post_id}) with
 * add_option() for atomic acquire — two concurrent callers cannot
 * both claim the same lock.  Stale (expired) locks are reclaimed
 * automatically before retry.
 *
 * @package DraftSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Sync_Lock
 *
 * Static methods only.
 */
class GDTG_Sync_Lock {

	/**
	 * Option key prefix for per-post lock entries.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'gdtg_sync_lock_';

	/**
	 * Legacy option key (single-array format, v0.3.0 only).
	 *
	 * Kept for cleanup only — no longer written.
	 *
	 * @var string
	 */
	const LEGACY_OPTION_KEY = 'gdtg_sync_lock';

	/**
	 * Build the per-post option key.
	 *
	 * @param int $post_id The post ID.
	 * @return string Option name.
	 */
	private static function lock_key( $post_id ) {
		return self::OPTION_PREFIX . (int) $post_id;
	}

	/**
	 * Acquire a lock for a post (atomic).
	 *
	 * Uses add_option() which is an atomic INSERT — only one caller
	 * can succeed when two requests race for the same post.
	 *
	 * Stale (expired) locks are deleted and retried once.
	 *
	 * @param int $post_id The post ID to lock.
	 * @param int $ttl     Lock time-to-live in seconds (default 120).
	 * @return bool True if lock was acquired, false if already locked.
	 */
	public static function acquire( $post_id, $ttl = 120 ) {
		global $wpdb;

		$post_id = absint( $post_id );
		if ( 0 === $post_id ) {
			return false;
		}

		if ( ! function_exists( 'get_option' ) || ! function_exists( 'add_option' ) ) {
			return false;
		}

		$key        = self::lock_key( $post_id );
		$now        = time();
		$expires_at = $now + max( 1, (int) $ttl );
		$existing   = (int) get_option( $key, 0 );

		if ( $existing > $now ) {
			return false;
		}

		if ( add_option( $key, $expires_at, '', 'no' ) ) {
			self::cleanup_legacy_lock( $post_id );
			return true;
		}

		$existing = (int) get_option( $key, 0 );
		if ( $existing > $now ) {
			return false;
		}

		if ( $existing > 0 && function_exists( 'update_option' ) ) {
			return update_option( $key, $expires_at );
		}

		return false;
	}

	/**
	 * Release a lock for a post.
	 *
	 * @param int $post_id The post ID to unlock.
	 * @return void
	 */
	public static function release( $post_id ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id ) {
			return;
		}

		if ( ! function_exists( 'delete_option' ) ) {
			return;
		}

		delete_option( self::lock_key( $post_id ) );
	}

	/**
	 * Check whether a post is currently locked.
	 *
	 * @param int $post_id The post ID to check.
	 * @return bool True if locked and not expired.
	 */
	public static function is_locked( $post_id ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id ) {
			return false;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$existing = (int) get_option( self::lock_key( $post_id ), 0 );
		return $existing > time();
	}

	/**
	 * Extend the lock TTL for a post (heartbeat).
	 *
	 * Only extends if the lock is currently held (not expired).
	 *
	 * @param int $post_id The post ID.
	 * @param int $ttl     Additional time in seconds from now (default 120).
	 * @return bool True if heartbeat succeeded, false if no lock exists.
	 */
	public static function heartbeat( $post_id, $ttl = 120 ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id ) {
			return false;
		}

		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}

		$key      = self::lock_key( $post_id );
		$existing = (int) get_option( $key, 0 );

		if ( $existing <= time() ) {
			return false; // No valid lock to extend.
		}

		return update_option( $key, time() + max( 1, (int) $ttl ) );
	}

	/**
	 * Remove a post from the legacy single-array lock format.
	 *
	 * Called after a successful acquire so that the old gdtg_sync_lock
	 * option doesn't accumulate stale entries forever.
	 *
	 * @param int $post_id The post ID that was just locked.
	 * @return void
	 */
	private static function cleanup_legacy_lock( $post_id ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$locks = get_option( self::LEGACY_OPTION_KEY, array() );
		if ( ! is_array( $locks ) || ! isset( $locks[ $post_id ] ) ) {
			return;
		}

		unset( $locks[ $post_id ] );
		if ( empty( $locks ) ) {
			delete_option( self::LEGACY_OPTION_KEY );
		} else {
			update_option( self::LEGACY_OPTION_KEY, $locks );
		}
	}
}
