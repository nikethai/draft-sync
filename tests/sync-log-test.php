<?php
/**
 * Standalone sync log test harness.
 *
 * Validates record, read, clear, cap, context allowlisting,
 * and safe behavior on non-existent posts.
 *
 * @package GoogleDocsToGutenberg
 */

echo "Running Sync Log Tests...\n\n";

// ─── Minimal WordPress stubs ──────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// Define MB_IN_BYTES if not set (needed by sync-log.php guard).
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

// Mock post meta storage.
global $mock_post_meta;
$mock_post_meta = array();

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		global $mock_post_meta;
		if ( ! isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
			return $single ? '' : array();
		}
		return $single ? $mock_post_meta[ $post_id ][ $key ] : array( $mock_post_meta[ $post_id ][ $key ] );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		global $mock_post_meta;
		if ( ! isset( $mock_post_meta[ $post_id ] ) ) {
			$mock_post_meta[ $post_id ] = array();
		}
		$mock_post_meta[ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		global $mock_post_meta;
		unset( $mock_post_meta[ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $val ) {
		return abs( (int) $val );
	}
}

if ( ! function_exists( 'time' ) ) {
	// Already provided by PHP.
}

// ─── Helper assertions ────────────────────────────────────────────

function assert_true( $val, $desc ) {
	if ( ! $val ) {
		echo "  FAIL: $desc\n";
		exit( 1 );
	}
	echo "  PASS: $desc\n";
}

function assert_equals( $expected, $actual, $desc ) {
	if ( $expected !== $actual ) {
		echo "  FAIL: $desc (expected: " . var_export( $expected, true ) . ", got: " . var_export( $actual, true ) . ")\n";
		exit( 1 );
	}
	echo "  PASS: $desc\n";
}

function suite( $name ) {
	echo "\n=== $name ===\n";
}

// ─── Load the class ───────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-gdtg-sync-log.php';

// ─── Tests ────────────────────────────────────────────────────────

suite( 'record + read round-trip' );

global $mock_post_meta;
$mock_post_meta = array();

GDTG_Sync_Log::record( 1, 'info', 'Test message', array( 'step' => 'test_start' ) );
$events = GDTG_Sync_Log::read( 1 );
assert_equals( 1, count( $events ), 'One event recorded and read back' );
assert_equals( 'info', $events[0]['level'], 'Event level is info' );
assert_equals( 'Test message', $events[0]['message'], 'Event message preserved' );
assert_equals( 'test_start', $events[0]['context']['step'], 'Context step preserved' );

suite( 'record caps at 50 entries (FIFO)' );

$mock_post_meta = array();
for ( $i = 1; $i <= 51; $i++ ) {
	GDTG_Sync_Log::record( 1, 'info', "Event $i", array() );
}
$events = GDTG_Sync_Log::read( 1, 100 );
assert_equals( 50, count( $events ), 'Only 50 events stored (capped)' );
assert_equals( 'Event 51', $events[0]['message'], 'Newest event is Event 51' );
assert_equals( 'Event 2', $events[49]['message'], 'Oldest stored event is Event 2 (Event 1 evicted)' );

suite( 'read respects limit' );

$events = GDTG_Sync_Log::read( 1, 3 );
assert_equals( 3, count( $events ), 'Read limit of 3 respected' );

suite( 'clear empties events' );

GDTG_Sync_Log::clear( 1 );
$events = GDTG_Sync_Log::read( 1 );
assert_equals( 0, count( $events ), 'Clear empties all events' );

suite( 'record is safe on post_id 0' );

GDTG_Sync_Log::record( 0, 'info', 'Should not throw', array() );
$events = GDTG_Sync_Log::read( 0 );
assert_equals( 0, count( $events ), 'Post 0 returns empty array' );

suite( 'clear is safe on post_id 0' );

GDTG_Sync_Log::clear( 0 );
// No fatal error = pass.
assert_true( true, 'Clear on post 0 does not throw' );

suite( 'context allowlist drops unknown keys' );

$mock_post_meta = array();
GDTG_Sync_Log::record( 2, 'info', 'Context test', array(
	'step'              => 'test_step',
	'error_code'        => 'err_123',
	'secret_token'      => 'should-be-dropped',
	'raw_payload'       => 'also-dropped',
	'deferred'          => true,
	'source_modified_at' => '2026-06-01T00:00:00Z',
) );
$events = GDTG_Sync_Log::read( 2, 1 );
$ctx = $events[0]['context'];
assert_true( isset( $ctx['step'] ), 'Allowlisted key "step" kept' );
assert_true( isset( $ctx['error_code'] ), 'Allowlisted key "error_code" kept' );
assert_true( isset( $ctx['deferred'] ), 'Allowlisted key "deferred" kept' );
assert_true( isset( $ctx['source_modified_at'] ), 'Allowlisted key "source_modified_at" kept' );
assert_true( ! isset( $ctx['secret_token'] ), 'Unknown key "secret_token" dropped' );
assert_true( ! isset( $ctx['raw_payload'] ), 'Unknown key "raw_payload" dropped' );

suite( 'level sanitized via whitelist' );

$mock_post_meta = array();
GDTG_Sync_Log::record( 3, 'critical', 'Bad level test', array() );
$events = GDTG_Sync_Log::read( 3, 1 );
assert_equals( 'info', $events[0]['level'], 'Invalid level "critical" defaults to "info"' );

suite( 'message length capped at 240 chars' );

$mock_post_meta = array();
$long_msg = str_repeat( 'a', 300 );
GDTG_Sync_Log::record( 4, 'info', $long_msg, array() );
$events = GDTG_Sync_Log::read( 4, 1 );
assert_true( strlen( $events[0]['message'] ) <= 240, 'Message capped at 240 characters' );

suite( 'read on non-existent post returns empty array' );

$events = GDTG_Sync_Log::read( 99999 );
assert_equals( array(), $events, 'Non-existent post returns empty array' );

echo "\n==================================================\n";
echo "Sync Log Test Suite PASSED successfully!\n";
echo "==================================================\n\n";
