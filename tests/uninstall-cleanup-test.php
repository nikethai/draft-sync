<?php
/**
 * Static assertion test: verify uninstall.php covers every known DraftSync option,
 * meta key, transient pattern, and cron hook.
 *
 * Does not require WordPress — mocks the functions called by uninstall.php.
 */

// ─── Mock WordPress functions for isolated static assertions ────────────
$mock_delete_option_calls = array();
$mock_delete_post_meta_calls = array();
$mock_wpdb_queries = array();
$mock_cleared_hooks = array();

function delete_option( $option ) {
	global $mock_delete_option_calls;
	$mock_delete_option_calls[] = $option;
	return true;
}

function delete_post_meta_by_key( $key ) {
	global $mock_delete_post_meta_calls;
	$mock_delete_post_meta_calls[] = $key;
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	global $mock_cleared_hooks;
	$mock_cleared_hooks[] = $hook;
	return true;
}

// Mock $wpdb as a global object
$GLOBALS['wpdb'] = new class {
	public $options = 'wp_options';
	public function query( $sql ) {
		global $mock_wpdb_queries;
		$mock_wpdb_queries[] = $sql;
		return true;
	}
	public function prepare( $sql, ...$args ) {
		return vsprintf( str_replace( '%s', '%s', $sql ), array_map( function($a) { return is_string($a) ? "'$a'" : $a; }, $args ) );
	}
	public function esc_like( $s ) { return $s; }
};

// Simulate uninstall context
define( 'WP_UNINSTALL_PLUGIN', true );
require __DIR__ . '/../uninstall.php';

// ─── Assertions ─────────────────────────────────────────────────────────
$failed = 0;
$passed = 0;

function assert_contains( $haystack, $needle, $label ) {
	global $passed, $failed;
	if ( in_array( $needle, $haystack, true ) ) {
		$passed++;
	} else {
		$failed++;
		echo "FAIL: $label — '$needle' not found\n";
	}
}

function assert_hook_cleared( $hook ) {
	global $mock_cleared_hooks, $passed, $failed;
	if ( in_array( $hook, $mock_cleared_hooks, true ) ) {
		$passed++;
	} else {
		$failed++;
		echo "FAIL: Cron hook '$hook' not cleared\n";
	}
}

// Sensitive options that MUST be deleted (tokens, secrets)
$sensitive_options = array(
	'gdtg_enterprise_access_token',
	'gdtg_enterprise_refresh_token',
	'gdtg_saas_access_token',
	'gdtg_saas_refresh_token',
);

foreach ( $sensitive_options as $opt ) {
	assert_contains( $mock_delete_option_calls, $opt, "Sensitive option $opt deleted" );
}

// All known options
$all_options = array(
	'gdtg_connection_mode',
	'gdtg_enterprise_client_id',
	'gdtg_enterprise_access_token',
	'gdtg_enterprise_refresh_token',
	'gdtg_enterprise_token_expires',
	'gdtg_enterprise_connected',
	'gdtg_saas_access_token',
	'gdtg_saas_refresh_token',
	'gdtg_saas_token_expires',
	'gdtg_saas_bridge_base_url',
	'gdtg_license_key',
	'gdtg_default_category',
	'gdtg_default_author',
	'gdtg_saas_connected',
	'gdtg_optimize_images',
	'gdtg_import_images',
	'gdtg_import_tables',
	'gdtg_overwrite',
	'gdtg_import_as_draft',
	'gdtg_output_mode',
	'gdtg_auto_sync_enabled',
	'gdtg_auto_sync_frequency',
	'gdtg_auto_sync_limit',
	'gdtg_picker_app_id',
	'gdtg_picker_developer_key',
);

foreach ( $all_options as $opt ) {
	assert_contains( $mock_delete_option_calls, $opt, "Option $opt deleted" );
}

// Cron hooks
assert_hook_cleared( 'gdtg_auto_sync_event' );

// All known post meta keys
$all_meta_keys = array(
	'_gdtg_source_type',
	'_gdtg_source_id',
	'_gdtg_source_name',
	'_gdtg_source_url',
	'_gdtg_last_imported_at',
	'_gdtg_import_options',
	'_gdtg_last_content_hash',
	'_gdtg_sync_user_id',
	'_gdtg_auto_sync',
	'_gdtg_last_sync_status',
	'_gdtg_last_sync_checked_at',
	'_gdtg_last_sync_error',
	'_gdtg_source_modified_at',
);

foreach ( $all_meta_keys as $key ) {
	assert_contains( $mock_delete_post_meta_calls, $key, "Post meta $key deleted" );
}

// At least one transient query was made for each prefix
$expected_transient_prefixes = array(
	'gdtg_oauth_state_',
	'gdtg_saas_bridge_available_',
	'gdtg_import_job_',
);

foreach ( $expected_transient_prefixes as $prefix ) {
	$found = false;
	foreach ( $mock_wpdb_queries as $q ) {
		if ( strpos( $q, $prefix ) !== false ) {
			$found = true;
			break;
		}
	}
	if ( $found ) {
		$passed++;
	} else {
		$failed++;
		echo "FAIL: Transient prefix '$prefix' not cleaned\n";
	}
}

// ─── Report ─────────────────────────────────────────────────────────────
echo "\n" . str_repeat( '=', 50 ) . "\n";
echo "Uninstall Cleanup Coverage Test\n";
echo str_repeat( '=', 50 ) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ( $failed > 0 ) {
	echo "\nRESULT: FAIL — $failed assertion(s) failed.\n";
	exit( 1 );
}

echo "\nRESULT: PASS — all assertions verified.\n";
exit( 0 );
