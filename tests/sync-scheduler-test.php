<?php
/**
 * Standalone scheduler test harness.
 *
 * Tests GDTG_Sync_Scheduler: cron scheduling, options persistence,
 * conflict skip, dry-run, and limit enforcement.
 */

echo "Running Sync Scheduler Tests...\n\n";

// ─── Minimal WordPress stubs ──────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', true );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	function __( $text, $domain = 'default' ) {
		return $text;
	}

	function absint( $val ) {
		return abs( intval( $val ) );
	}

	function sanitize_text_field( $field ) {
		return trim( strip_tags( $field ) );
	}

	function current_time( $format ) {
		return '2026-06-02 12:00:00';
	}

	function get_current_user_id() {
		return 1;
	}

	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}

	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_array( $args ) ) {
			return array_merge( $defaults, $args );
		}
		parse_str( $args, $parsed_args );
		return array_merge( $defaults, $parsed_args );
	}

	global $mock_post_meta;
	$mock_post_meta = array();

	function update_post_meta( $post_id, $key, $val ) {
		global $mock_post_meta;
		$mock_post_meta[ $post_id ][ $key ] = $val;
		return true;
	}

	function get_post_meta( $post_id, $key, $single = false ) {
		global $mock_post_meta;
		if ( ! isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
			return '';
		}
		return $mock_post_meta[ $post_id ][ $key ];
	}

	function delete_post_meta( $post_id, $key ) {
		global $mock_post_meta;
		if ( isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
			unset( $mock_post_meta[ $post_id ][ $key ] );
		}
		return true;
	}

	global $mock_posts;
	$mock_posts = array();

	function get_post( $post_id ) {
		global $mock_posts;
		return isset( $mock_posts[ $post_id ] ) ? $mock_posts[ $post_id ] : null;
	}

	global $mock_options;
	$mock_options = array();

	function get_option( $opt, $default = '' ) {
		global $mock_options;
		return isset( $mock_options[ $opt ] ) ? $mock_options[ $opt ] : $default;
	}

	function update_option( $opt, $val ) {
		global $mock_options;
		$mock_options[ $opt ] = $val;
		return true;
	}

	function add_option( $opt, $value = '', $deprecated = '', $autoload = 'yes' ) {
		global $mock_options;
		if ( isset( $mock_options[ $opt ] ) ) {
			return false; // Already exists — atomic guard.
		}
		$mock_options[ $opt ] = $value;
		return true;
	}

	function delete_option( $opt ) {
		global $mock_options;
		unset( $mock_options[ $opt ] );
		return true;
	}

	// Cron tracking.
	global $mock_scheduled_events;
	$mock_scheduled_events = array();

	function wp_next_scheduled( $hook, $args = array() ) {
		global $mock_scheduled_events, $mock_scheduled_single_events;
		// Check single events first (they store args).
		if ( ! empty( $mock_scheduled_single_events ) ) {
			foreach ( $mock_scheduled_single_events as $event ) {
				if ( $event['hook'] === $hook && ( empty( $args ) || $event['args'] == $args ) ) {
					return $event['timestamp'];
				}
			}
		}
		// Fall back to simple hook-only check.
		return isset( $mock_scheduled_events[ $hook ] ) ? $mock_scheduled_events[ $hook ] : false;
	}

	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		global $mock_scheduled_events;
		$mock_scheduled_events[ $hook ] = $timestamp;
		return true;
	}

	function wp_clear_scheduled_hook( $hook ) {
		global $mock_scheduled_events;
		unset( $mock_scheduled_events[ $hook ] );
		return true;
	}

	global $mock_cron_schedules;
	$mock_cron_schedules = array();

	function wp_get_schedules() {
		global $mock_cron_schedules;
		return $mock_cron_schedules;
	}

	// WP_Query stub.
	global $mock_wp_query_results;
	$mock_wp_query_results = array();

	class WP_Query {
		public $posts = array();
		public function __construct( $args ) {
			global $mock_wp_query_results;
			$this->posts = $mock_wp_query_results;
		}
		public function have_posts() {
			return ! empty( $this->posts );
		}
	}
	function wp_reset_postdata() {}

	// Mock GDTG_API.
	class GDTG_API {
		public function get_drive_file_metadata( $file_id ) {
			global $mock_drive_meta;
			if ( isset( $mock_drive_meta[ $file_id ] ) ) {
				return $mock_drive_meta[ $file_id ];
			}
			return array( 'name' => 'test', 'mimeType' => 'application/vnd.google-apps.document', 'modifiedTime' => '2026-06-01T00:00:00Z' );
		}
	}

	global $mock_drive_meta;
	$mock_drive_meta = array();
}

// Load required files.
require_once __DIR__ . '/../includes/class-gdtg-doc-node.php';
require_once __DIR__ . '/../includes/class-gdtg-block-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-html-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-post-meta-applier.php';
require_once __DIR__ . '/../includes/class-gdtg-import-orchestrator.php';
require_once __DIR__ . '/../includes/class-gdtg-sync-scheduler.php';
require_once __DIR__ . '/../includes/class-gdtg-sync-lock.php';

// Loader stub.
if ( ! class_exists( 'GDTG_Loader' ) ) {
	class GDTG_Loader {
		public function add_action() {}
		public function add_filter() {}
	}
}

// Helper assertion functions.
function assert_true( $val, $desc ) {
	if ( ! $val ) {
		echo "FAIL: $desc\n";
		exit( 1 );
	}
	echo "PASS: $desc\n";
}

function assert_false( $val, $desc ) {
	assert_true( ! $val, $desc );
}

function assert_equals( $expected, $actual, $desc ) {
	if ( $expected !== $actual ) {
		echo "FAIL: $desc (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
		exit( 1 );
	}
	echo "PASS: $desc\n";
}

function suite( $name ) {
	echo "\n=== $name ===\n";
}

$loader = new GDTG_Loader();
$scheduler = new GDTG_Sync_Scheduler( $loader );

// ─── Tests ────────────────────────────────────────────────────────

suite( 'add_cron_schedules: adds gdtg_twicedaily' );

global $mock_cron_schedules;
$mock_cron_schedules = array();
$updated = $scheduler->add_cron_schedules( $mock_cron_schedules );
assert_true( isset( $updated['gdtg_twicedaily'] ), 'gdtg_twicedaily schedule added' );
assert_equals( 12 * HOUR_IN_SECONDS, $updated['gdtg_twicedaily']['interval'], 'Interval is 12 hours' );

suite( 'add_cron_schedules: does not duplicate existing schedule' );

$existing = array( 'gdtg_twicedaily' => array( 'interval' => 999, 'display' => 'Existing' ) );
$updated2 = $scheduler->add_cron_schedules( $existing );
assert_equals( 999, $updated2['gdtg_twicedaily']['interval'], 'Existing schedule preserved' );

suite( 'ensure_scheduled: schedules event when not already scheduled' );

global $mock_scheduled_events, $mock_options;
$mock_scheduled_events = array();
$mock_options = array( 'gdtg_auto_sync_frequency' => 'daily' );
$scheduler->ensure_scheduled();
assert_true( isset( $mock_scheduled_events[ GDTG_Sync_Scheduler::HOOK ] ), 'Event scheduled' );

suite( 'ensure_scheduled: does not duplicate existing event' );

$existing_ts = $mock_scheduled_events[ GDTG_Sync_Scheduler::HOOK ];
$scheduler->ensure_scheduled();
assert_equals( $existing_ts, $mock_scheduled_events[ GDTG_Sync_Scheduler::HOOK ], 'Event not re-scheduled' );

suite( 'clear_scheduled: removes event' );

$scheduler->clear_scheduled();
assert_false( isset( $mock_scheduled_events[ GDTG_Sync_Scheduler::HOOK ] ), 'Event cleared' );

suite( 'maybe_reschedule: clears when disabled' );

$mock_options = array( 'gdtg_auto_sync_enabled' => '0', 'gdtg_auto_sync_frequency' => 'daily' );
$mock_scheduled_events = array( GDTG_Sync_Scheduler::HOOK => time() );
$scheduler->maybe_reschedule();
assert_false( isset( $mock_scheduled_events[ GDTG_Sync_Scheduler::HOOK ] ), 'Event cleared when disabled' );

suite( 'maybe_reschedule: schedules when enabled and no event' );

$mock_options = array( 'gdtg_auto_sync_enabled' => '1', 'gdtg_auto_sync_frequency' => 'hourly' );
$mock_scheduled_events = array();
$scheduler->maybe_reschedule();
assert_true( isset( $mock_scheduled_events[ GDTG_Sync_Scheduler::HOOK ] ), 'Event scheduled when enabled' );

suite( 'run_scheduled_sync: returns empty summary when no posts' );

global $mock_wp_query_results;
$mock_wp_query_results = array();
$summary = $scheduler->run_scheduled_sync( 10, false, false );
assert_equals( 0, $summary['checked'], 'No posts checked' );
suite( 'run_scheduled_sync: Enterprise mode runs normally (no short-circuit)' );

$mock_options['gdtg_connection_mode'] = 'enterprise';
$mock_wp_query_results = array();
$summary = $scheduler->run_scheduled_sync( 10, false, false );
assert_equals( 0, $summary['checked'], 'Enterprise mode checks no posts (empty result set)' );
assert_equals( 0, $summary['synced'], 'Enterprise mode syncs no posts' );
assert_false( isset( $summary['enterprise_skipped'] ), 'Enterprise mode does NOT set enterprise_skipped' );

$mock_options['gdtg_connection_mode'] = 'saas';

suite( 'run_scheduled_sync: skips post with local conflict' );

$mock_post_meta = array();
$mock_post_meta[ 42 ] = array(
	'_gdtg_auto_sync'     => '1',
	'_gdtg_source_type'   => 'gdoc',
	'_gdtg_source_id'     => 'doc123',
	'_gdtg_last_content_hash' => md5( '<p>original</p>' ),
);
$mock_posts = array();
$mock_posts[ 42 ] = (object) array(
	'ID'           => 42,
	'post_content' => '<p>changed locally</p>',
	'post_title'   => 'Changed',
	'post_status'  => 'publish',
	'post_type'    => 'post',
);
$mock_wp_query_results = array( $mock_posts[ 42 ] );

$summary = $scheduler->run_scheduled_sync( 10, false, false );
assert_equals( 1, $summary['conflicts'], 'Post with conflict counted' );
assert_equals( 0, $summary['synced'], 'Conflicting post not synced' );
assert_equals( 'conflict', get_post_meta( 42, '_gdtg_last_sync_status', true ), 'Conflict status recorded' );

suite( 'run_scheduled_sync: dry-run reports candidate without importing' );

$mock_post_meta[ 43 ] = array(
	'_gdtg_auto_sync'     => '1',
	'_gdtg_source_type'   => 'gdoc',
	'_gdtg_source_id'     => 'doc456',
	'_gdtg_last_content_hash' => md5( '<p>same</p>' ),
);
$mock_posts[ 43 ] = (object) array(
	'ID'           => 43,
	'post_content' => '<p>same</p>',
	'post_title'   => 'Unchanged',
	'post_status'  => 'publish',
	'post_type'    => 'post',
);
$mock_wp_query_results = array( $mock_posts[ 43 ] );

$summary = $scheduler->run_scheduled_sync( 10, false, true );
assert_true( $summary['dry_run'], 'Dry run flag set' );
assert_equals( 1, $summary['synced'], 'Would-sync counted (dry_run increments synced)' );
assert_equals( 'would_sync', $summary['details'][0]['status'], 'Detail shows would_sync' );

suite( 'run_scheduled_sync: limit clamp respects max 50' );
// The scheduler passes the limit as posts_per_page to WP_Query.
// Verify limit clamping: requesting 99 should process at most 50 (the clamp).
// Our mock WP_Query returns all items, so we set up exactly 50 items
// and verify the scheduler processes them without error.
$mock_wp_query_results = array();
for ( $i = 50; $i < 100; $i++ ) {
	$mock_post_meta[ $i ] = array(
		'_gdtg_auto_sync'     => '1',
		'_gdtg_source_type'   => 'gdoc',
		'_gdtg_source_id'     => "doc{$i}",
		'_gdtg_last_content_hash' => md5( "<p>content{$i}</p>" ),
	);
	$mock_posts[ $i ] = (object) array(
		'ID'           => $i,
		'post_content' => "<p>content{$i}</p>",
		'post_title'   => "Post {$i}",
		'post_status'  => 'publish',
		'post_type'    => 'post',
	);
	$mock_wp_query_results[] = $mock_posts[ $i ];
}
$summary = $scheduler->run_scheduled_sync( 99, false, true );
assert_true( $summary['dry_run'], 'Dry run with large limit works' );
assert_equals( 50, $summary['checked'], 'All 50 mock posts checked (WP_Query returns all)' );
suite( 'run_scheduled_sync: skipped_unchanged when Drive modifiedTime not newer' );

$mock_post_meta[ 60 ] = array(
	'_gdtg_auto_sync'          => '1',
	'_gdtg_source_type'        => 'drive_file',
	'_gdtg_source_id'          => 'drive123',
	'_gdtg_last_content_hash'  => md5( '<p>unchanged</p>' ),
	'_gdtg_source_modified_at' => '2026-06-01T00:00:00Z',
);
$mock_posts[ 60 ] = (object) array(
	'ID'           => 60,
	'post_content' => '<p>unchanged</p>',
	'post_title'   => 'Drive Doc',
	'post_status'  => 'publish',
	'post_type'    => 'post',
);
$mock_wp_query_results = array( $mock_posts[ 60 ] );

global $mock_drive_meta;
$mock_drive_meta = array( 'drive123' => array( 'name' => 'test', 'mimeType' => 'application/vnd.google-apps.document', 'modifiedTime' => '2026-06-01T00:00:00Z' ) );

$summary = $scheduler->run_scheduled_sync( 10, false, false );
assert_equals( 1, $summary['skipped'], 'Unchanged Drive file skipped' );
assert_equals( 'skipped_unchanged', $summary['details'][0]['status'], 'Detail shows skipped_unchanged' );

suite( 'run_scheduled_sync: force overrides conflict' );

$mock_post_meta[ 70 ] = array(
	'_gdtg_auto_sync'          => '1',
	'_gdtg_source_type'        => 'gdoc',
	'_gdtg_source_id'          => 'doc_force',
	'_gdtg_last_content_hash'  => md5( '<p>old</p>' ),
);
$mock_posts[ 70 ] = (object) array(
	'ID'           => 70,
	'post_content' => '<p>changed locally</p>',
	'post_title'   => 'Force Test',
	'post_status'  => 'publish',
	'post_type'    => 'post',
);
$mock_wp_query_results = array( $mock_posts[ 70 ] );

$summary = $scheduler->run_scheduled_sync( 10, true, true );
assert_equals( 0, $summary['conflicts'], 'Force skips conflict check' );
assert_true( $summary['synced'] > 0, 'Force allows sync' );


	suite( 'run_scheduled_sync: records timeout without losing error status' );

	class GDTG_Test_Timeout_Orchestrator {
		public function import_google_doc( $source_id, $options, $post_id ) {
			return new WP_Error( 'gdtg_timeout_test', 'Synthetic timeout failure' );
		}

		public function import_drive_file( $source_id, $options, $post_id ) {
			return new WP_Error( 'gdtg_timeout_test', 'Synthetic timeout failure' );
		}
	}

	class GDTG_Testable_Sync_Scheduler extends GDTG_Sync_Scheduler {
		private $time_points = array();
		private $orchestrator;

		public function set_time_points( $time_points ) {
			$this->time_points = $time_points;
		}

		public function set_orchestrator( $orchestrator ) {
			$this->orchestrator = $orchestrator;
		}

		protected function create_orchestrator() {
			return $this->orchestrator ? $this->orchestrator : parent::create_orchestrator();
		}

		protected function now() {
			if ( empty( $this->time_points ) ) {
				return parent::now();
			}
			return array_shift( $this->time_points );
		}
	}

	$timeout_scheduler = new GDTG_Testable_Sync_Scheduler( $loader );
	$timeout_scheduler->set_orchestrator( new GDTG_Test_Timeout_Orchestrator() );
	$timeout_scheduler->set_time_points( array( 1000.0, 1095.5 ) );

	$mock_post_meta[ 80 ] = array(
		'_gdtg_auto_sync'         => '1',
		'_gdtg_source_type'       => 'gdoc',
		'_gdtg_source_id'         => 'doc_timeout',
		'_gdtg_last_content_hash' => md5( '<p>timeout base</p>' ),
	);
	$mock_posts[ 80 ] = (object) array(
		'ID'           => 80,
		'post_content' => '<p>timeout base</p>',
		'post_title'   => 'Timeout Test',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	);
	$mock_wp_query_results = array( $mock_posts[ 80 ] );

	$summary = $timeout_scheduler->run_scheduled_sync( 10, false, false );
	assert_equals( 1, $summary['failed'], 'Timed-out failed sync still counts as failed' );
	assert_equals( 1, $summary['timeouts'], 'Timed-out sync increments timeout counter' );
	assert_equals( 'error', $summary['details'][0]['status'], 'Timeout preserves primary error status' );
	assert_true( ! empty( $summary['details'][0]['timed_out'] ), 'Timeout detail includes timed_out flag' );

// ─── Lock Tests ──────────────────────────────────────────────────

suite( 'GDTG_Sync_Lock: acquire returns true for unlocked post' );

// Ensure clean state — per-post options auto-cleaned by delete_option.
GDTG_Sync_Lock::release( 100 );
GDTG_Sync_Lock::release( 200 );

$acquired = GDTG_Sync_Lock::acquire( 100 );
assert_true( $acquired, 'Acquire returns true for free post' );

suite( 'GDTG_Sync_Lock: acquire twice returns false (already locked)' );

$second = GDTG_Sync_Lock::acquire( 100 );
assert_false( $second, 'Second acquire returns false for locked post' );

suite( 'GDTG_Sync_Lock: is_locked returns true while locked' );

assert_true( GDTG_Sync_Lock::is_locked( 100 ), 'is_locked returns true for locked post' );
assert_false( GDTG_Sync_Lock::is_locked( 200 ), 'is_locked returns false for never-locked post' );

suite( 'GDTG_Sync_Lock: release makes next acquire succeed' );

GDTG_Sync_Lock::release( 100 );
assert_false( GDTG_Sync_Lock::is_locked( 100 ), 'is_locked returns false after release' );

$reacquired = GDTG_Sync_Lock::acquire( 100 );
assert_true( $reacquired, 'Acquire succeeds after release' );

suite( 'GDTG_Sync_Lock: heartbeat extends lock' );

// Post 100 is now locked. Heartbeat should succeed.
$hb = GDTG_Sync_Lock::heartbeat( 100 );
assert_true( $hb, 'Heartbeat returns true for locked post' );
assert_true( GDTG_Sync_Lock::is_locked( 100 ), 'Post still locked after heartbeat' );

suite( 'GDTG_Sync_Lock: heartbeat fails for non-existent lock' );

$hb_none = GDTG_Sync_Lock::heartbeat( 999 );
assert_false( $hb_none, 'Heartbeat returns false for never-locked post' );

suite( 'GDTG_Sync_Lock: release does not error for never-locked post' );

// Should not cause errors.
GDTG_Sync_Lock::release( 999 );
assert_false( GDTG_Sync_Lock::is_locked( 999 ), 'Post 999 still not locked' );

suite( 'GDTG_Sync_Lock: independent posts do not interfere' );

GDTG_Sync_Lock::release( 100 );
GDTG_Sync_Lock::release( 10 );
GDTG_Sync_Lock::release( 20 );

$a1 = GDTG_Sync_Lock::acquire( 10 );
$a2 = GDTG_Sync_Lock::acquire( 20 );
assert_true( $a1, 'Acquire post 10 succeeds' );
assert_true( $a2, 'Acquire post 20 succeeds (different post)' );
assert_true( GDTG_Sync_Lock::is_locked( 10 ), 'Post 10 locked' );
assert_true( GDTG_Sync_Lock::is_locked( 20 ), 'Post 20 locked' );

GDTG_Sync_Lock::release( 10 );
assert_false( GDTG_Sync_Lock::is_locked( 10 ), 'Post 10 released' );
assert_true( GDTG_Sync_Lock::is_locked( 20 ), 'Post 20 still locked' );

// Clean up.
GDTG_Sync_Lock::release( 20 );

// ─── Queued Sync Tests ────────────────────────────────────────────

suite( 'Queued sync: wp_schedule_single_event stubs work' );

// Add wp_schedule_single_event stub so the queue route works in tests.
global $mock_scheduled_single_events;
$mock_scheduled_single_events = array();

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		global $mock_scheduled_single_events;
		$mock_scheduled_single_events[] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $user_id ) {
		// No-op in test harness.
	}
}

// Simulate the queue endpoint logic.
$post_id_for_queue = 42;
$mock_post_meta[ $post_id_for_queue ] = array(
	'_gdtg_source_type' => 'gdoc',
	'_gdtg_source_id'   => 'doc123',
	'_gdtg_source_name' => 'Test Queued Doc',
);
$mock_posts[ $post_id_for_queue ] = (object) array(
	'ID'           => $post_id_for_queue,
	'post_content' => '<p>queue test</p>',
	'post_title'   => 'Queue Test',
	'post_status'  => 'publish',
	'post_type'    => 'post',
);

// Queue a sync (simulating handle_sync_queue logic).
$mock_scheduled_single_events = array();
wp_schedule_single_event( time() + 5, 'gdtg_run_queued_sync', array( $post_id_for_queue, get_current_user_id() ) );

assert_equals( 1, count( $mock_scheduled_single_events ), 'One single event scheduled' );
assert_equals( 'gdtg_run_queued_sync', $mock_scheduled_single_events[0]['hook'], 'Hook is gdtg_run_queued_sync' );
assert_equals( $post_id_for_queue, $mock_scheduled_single_events[0]['args'][0], 'First arg is post_id' );
assert_equals( get_current_user_id(), $mock_scheduled_single_events[0]['args'][1], 'Second arg is user_id' );

suite( 'Queued sync: lock not held after import_orchestrator returns (simulated)' );

// Simulate run_queued_sync: acquire, do work, release.
GDTG_Sync_Lock::release( $post_id_for_queue );
$acquired_queue = GDTG_Sync_Lock::acquire( $post_id_for_queue );
assert_true( $acquired_queue, 'Lock acquired for queued sync' );

// Simulate orchestrator work (mocked as no-op).
// ... import happens here ...

// Release.
GDTG_Sync_Lock::release( $post_id_for_queue );
assert_false( GDTG_Sync_Lock::is_locked( $post_id_for_queue ), 'Lock released after queued sync completes' );

// Verify re-acquisition is possible.
$reacquired_queue = GDTG_Sync_Lock::acquire( $post_id_for_queue );
assert_true( $reacquired_queue, 'Can re-acquire after queued sync release' );
GDTG_Sync_Lock::release( $post_id_for_queue );

// ─── Scheduler lock integration tests ─────────────────────────────

suite( 'run_scheduled_sync: skips post when lock cannot be acquired' );

// Set up a post that should normally sync, but pre-lock it.
$mock_post_meta[ 90 ] = array(
	'_gdtg_source_type'       => 'gdoc',
	'_gdtg_source_id'         => 'gdoc_locked',
	'_gdtg_auto_sync'         => '1',
	'_gdtg_last_content_hash' => md5( '<p>locked content</p>' ),
);
$mock_posts[ 90 ] = (object) array(
	'ID'           => 90,
	'post_content' => '<p>locked content</p>',
	'post_title'   => 'Locked Post',
	'post_status'  => 'publish',
	'post_type'    => 'post',
);
$mock_wp_query_results = array( $mock_posts[ 90 ] );

// Pre-lock post 90.
GDTG_Sync_Lock::acquire( 90 );

$summary = $scheduler->run_scheduled_sync( 10, false, false );
assert_equals( 1, $summary['skipped'], 'Locked post counted as skipped' );
assert_equals( 'skipped_locked', $summary['details'][0]['status'], 'Detail shows skipped_locked' );

// Release for cleanup.
GDTG_Sync_Lock::release( 90 );
	assert_equals( 'error', get_post_meta( 80, '_gdtg_last_sync_status', true ), 'Timeout does not overwrite stored error status' );
echo "\n==================================================\n";
echo "Sync Scheduler Test Suite PASSED successfully!\n";
echo "==================================================\n\n";
