<?php
/**
 * Static assertion test: verify uninstall.php cleans up every site on a multisite network.
 *
 * Mocks WordPress multisite functions and asserts cleanup is dispatched per-site.
 * Runs in a separate process from the single-site test to avoid function redefinition.
 */

// ─── Mock tracking globals ─────────────────────────────────────────────
$GLOBALS['mock_delete_option_calls'] = array();
$GLOBALS['mock_delete_post_meta_calls'] = array();
$GLOBALS['mock_wpdb_queries']         = array();
$GLOBALS['mock_cleared_hooks']        = array();
$GLOBALS['mock_switched_to']          = array();
$GLOBALS['mock_restore_calls']        = 0;
$GLOBALS['mock_is_multisite']         = true;
$GLOBALS['mock_sites']               = array( 101, 102 );

// ─── Mock WordPress single-site functions ───────────────────────────────
function delete_option( $option ) {
	$GLOBALS['mock_delete_option_calls'][] = $option;
	return true;
}

function delete_post_meta_by_key( $key ) {
	$GLOBALS['mock_delete_post_meta_calls'][] = $key;
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['mock_cleared_hooks'][] = $hook;
	return true;
}

// ─── Mock WordPress multisite functions ─────────────────────────────────
function is_multisite() {
	return $GLOBALS['mock_is_multisite'];
}

function get_sites( $args = array() ) {
	return $GLOBALS['mock_sites'];
}

function switch_to_blog( $site_id ) {
	$GLOBALS['mock_switched_to'][] = (int) $site_id;
	return true;
}

function restore_current_blog() {
	$GLOBALS['mock_restore_calls']++;
	return true;
}

function get_current_blog_id() {
	return 1;
}

// ─── Mock $wpdb ────────────────────────────────────────────────────────
$GLOBALS['wpdb'] = new class {
	public $options = 'wp_options';
	public function query( $sql ) {
		$GLOBALS['mock_wpdb_queries'][] = $sql;
		return true;
	}
	public function prepare( $sql, ...$args ) {
		return vsprintf(
			str_replace( '%s', '%s', $sql ),
			array_map( function ( $a ) { return is_string( $a ) ? "'$a'" : $a; }, $args )
		);
	}
	public function esc_like( $s ) { return $s; }
};

// ─── Execute uninstall.php ─────────────────────────────────────────────
define( 'WP_UNINSTALL_PLUGIN', true );
require __DIR__ . '/../uninstall.php';

// ─── Assertions ─────────────────────────────────────────────────────────
$failed = 0;
$passed = 0;

function gdtg_ms_assert( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
	} else {
		$failed++;
		echo "FAIL: $label\n";
	}
}

function gdtg_ms_assert_contains( $haystack, $needle, $label ) {
	gdtg_ms_assert( in_array( $needle, $haystack, true ), $label );
}

// --- Multisite dispatch ---
gdtg_ms_assert_contains( $GLOBALS['mock_switched_to'], 101, 'Switched to site 101' );
gdtg_ms_assert_contains( $GLOBALS['mock_switched_to'], 102, 'Switched to site 102' );
gdtg_ms_assert(
	$GLOBALS['mock_restore_calls'] >= 2,
	'restore_current_blog called at least once per site (got ' . $GLOBALS['mock_restore_calls'] . ')'
);

// --- Options cleaned per site (2 sites = 2x calls) ---
$option_keys = array(
	'gdtg_connection_mode',
	'gdtg_enterprise_client_id',
	'gdtg_enterprise_client_secret',
	'gdtg_enterprise_access_token',
	'gdtg_enterprise_refresh_token',
	'gdtg_enterprise_token_expires',
	'gdtg_enterprise_connected',
	'gdtg_saas_access_token',
	'gdtg_saas_refresh_token',
	'gdtg_saas_token_expires',
	'gdtg_saas_connected',
	'gdtg_saas_bridge_base_url',
	'gdtg_default_category',
	'gdtg_default_author',
	'gdtg_optimize_images',
	'gdtg_import_images',
	'gdtg_import_tables',
	'gdtg_overwrite',
	'gdtg_import_as_draft',
	'gdtg_output_mode',
	'gdtg_auto_sync_enabled',
	'gdtg_auto_sync_frequency',
	'gdtg_auto_sync_limit',
);

$site_count = count( $GLOBALS['mock_sites'] );

foreach ( $option_keys as $opt ) {
	$count = count( array_keys( $GLOBALS['mock_delete_option_calls'], $opt, true ) );
	gdtg_ms_assert(
		$count >= $site_count,
		"Option '$opt' deleted at least $site_count times (got $count)"
	);
}

// --- Sensitive options specifically ---
$sensitive = array(
	'gdtg_enterprise_client_secret',
	'gdtg_enterprise_access_token',
	'gdtg_enterprise_refresh_token',
	'gdtg_saas_access_token',
	'gdtg_saas_refresh_token',
);

foreach ( $sensitive as $opt ) {
	$count = count( array_keys( $GLOBALS['mock_delete_option_calls'], $opt, true ) );
	gdtg_ms_assert(
		$count >= $site_count,
		"Sensitive option '$opt' deleted at least $site_count times (got $count)"
	);
}

// --- Cron hooks cleared per site ---
gdtg_ms_assert(
	count( array_keys( $GLOBALS['mock_cleared_hooks'], 'gdtg_auto_sync_event', true ) ) >= $site_count,
	'gdtg_auto_sync_event cleared per site'
);

// --- Post meta deleted per site ---
$meta_keys = array(
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

foreach ( $meta_keys as $key ) {
	$count = count( array_keys( $GLOBALS['mock_delete_post_meta_calls'], $key, true ) );
	gdtg_ms_assert(
		$count >= $site_count,
		"Post meta '$key' deleted at least $site_count times (got $count)"
	);
}

// --- Transient queries executed per site ---
$transient_prefixes = array(
	'gdtg_oauth_state_',
	'gdtg_saas_bridge_available_',
	'gdtg_import_job_',
);

foreach ( $transient_prefixes as $prefix ) {
	$count = 0;
	foreach ( $GLOBALS['mock_wpdb_queries'] as $q ) {
		if ( strpos( $q, $prefix ) !== false ) {
			$count++;
		}
	}
	gdtg_ms_assert(
		$count >= $site_count,
		"Transient prefix '$prefix' cleaned at least $site_count times (got $count)"
	);
}

// ─── Report ─────────────────────────────────────────────────────────────
echo "\n" . str_repeat( '=', 50 ) . "\n";
echo "Uninstall Multisite Cleanup Test\n";
echo str_repeat( '=', 50 ) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ( $failed > 0 ) {
	echo "\nRESULT: FAIL — $failed assertion(s) failed.\n";
	exit( 1 );
}

echo "\nRESULT: PASS — all assertions verified.\n";
exit( 0 );
