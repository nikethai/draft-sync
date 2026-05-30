<?php
/**
 * Sync event log helper.
 *
 * Stores a bounded FIFO list of structured sync events per linked post
 * in `_gdtg_sync_events` post meta.  Public API is entirely static and
 * safe to call when WP_Post does not exist.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Sync_Log
 */
class GDTG_Sync_Log {

	/**
	 * Maximum number of events retained per post.
	 *
	 * @var int
	 */
	const MAX_EVENTS = 50;

	/**
	 * Allowlisted context keys.
	 *
	 * @var string[]
	 */
	const ALLOWED_CONTEXT_KEYS = array( 'step', 'error_code', 'deferred', 'source_modified_at', 'progress' );

	/**
	 * Allowed log levels.
	 *
	 * @var string[]
	 */
	const ALLOWED_LEVELS = array( 'info', 'warning', 'error' );

	/**
	 * Record a sync event for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $level   One of info, warning, error.
	 * @param string $message Human-readable message.
	 * @param array  $context Optional context (only allowlisted keys kept).
	 * @return void
	 */
	public static function record( $post_id, $level, $message, $context = array() ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id ) {
			return;
		}

		// Sanitize level via whitelist.
		$level = in_array( $level, self::ALLOWED_LEVELS, true ) ? $level : 'info';

		// Sanitize message.
		$message = wp_kses_post( $message );
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 240 );
		} else {
			$message = substr( $message, 0, 240 );
		}

		// Filter context to allowlisted keys only.
		$safe_context = array();
		if ( is_array( $context ) ) {
			foreach ( self::ALLOWED_CONTEXT_KEYS as $key ) {
				if ( array_key_exists( $key, $context ) ) {
					$safe_context[ $key ] = $context[ $key ];
				}
			}
		}

		$event = array(
			'ts'      => time(),
			'level'   => $level,
			'message' => $message,
			'context' => $safe_context,
		);

		// Read existing events, prepend new one, cap to MAX_EVENTS.
		$events      = self::raw_events( $post_id );
		array_unshift( $events, $event );
		if ( count( $events ) > self::MAX_EVENTS ) {
			$events = array_slice( $events, 0, self::MAX_EVENTS );
		}

		update_post_meta( $post_id, '_gdtg_sync_events', wp_json_encode( $events ) );
	}

	/**
	 * Read recent sync events for a post, newest first.
	 *
	 * @param int $post_id Post ID.
	 * @param int $limit   Maximum number of events to return.
	 * @return array Array of event objects, newest first.
	 */
	public static function read( $post_id, $limit = 20 ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id ) {
			return array();
		}

		$events = self::raw_events( $post_id );
		if ( empty( $events ) ) {
			return array();
		}

		$limit = max( 1, min( (int) $limit, self::MAX_EVENTS ) );
		return array_slice( $events, 0, $limit );
	}

	/**
	 * Clear all sync events for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function clear( $post_id ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id ) {
			return;
		}
		delete_post_meta( $post_id, '_gdtg_sync_events' );
	}

	/**
	 * Read raw events array from post meta (newest-first).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function raw_events( $post_id ) {
		$raw = get_post_meta( $post_id, '_gdtg_sync_events', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$events = json_decode( $raw, true );
		if ( ! is_array( $events ) ) {
			return array();
		}
		// Sort by ts descending (newest first) in case stored out of order.
		usort( $events, function ( $a, $b ) {
			$ta = isset( $a['ts'] ) ? (int) $a['ts'] : 0;
			$tb = isset( $b['ts'] ) ? (int) $b['ts'] : 0;
			return $tb - $ta;
		} );
		return $events;
	}
}
