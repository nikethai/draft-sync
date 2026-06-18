<?php
/**
 * DraftSync Uninstall Handler
 *
 * Removes plugin-owned data when the plugin is deleted from the Plugins screen.
 * Does NOT run on deactivation — only on deletion.
 *
 * On multisite, iterates every site in the network and cleans each one.
 *
 * @package DraftSync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ─── Helper: known option keys ─────────────────────────────────────────
function gdtg_uninstall_option_keys() {
	return array(
		'gdtg_connection_mode',
		'gdtg_enterprise_client_id',
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
		'gdtg_default_heading_demotion',
		'gdtg_default_min_heading_level',
		'gdtg_default_alignment',
		'gdtg_enterprise_client_secret',
		'gdtg_sync_lock',
		'gdtg_picker_app_id',
		'gdtg_picker_developer_key',
	);
}

// ─── Helper: known post meta keys ──────────────────────────────────────
function gdtg_uninstall_post_meta_keys() {
	return array(
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
		'_gdtg_sync_events',
	);
}

// ─── Helper: known transient prefixes (stored in wp_options) ────────────
function gdtg_uninstall_transient_prefixes() {
	return array(
		'_transient_gdtg_oauth_state_',
		'_transient_timeout_gdtg_oauth_state_',
		'_transient_gdtg_saas_bridge_available_',
		'_transient_timeout_gdtg_saas_bridge_available_',
		'_transient_gdtg_import_job_',
		'_transient_timeout_gdtg_import_job_',
	);
}

// ─── Cleanup: current site ─────────────────────────────────────────────
function gdtg_uninstall_cleanup_current_site() {
	// Options
	foreach ( gdtg_uninstall_option_keys() as $option ) {
		delete_option( $option );
	}

	// Cron hooks
	wp_clear_scheduled_hook( 'gdtg_auto_sync_event' );
	wp_clear_scheduled_hook( 'gdtg_run_queued_sync' );

	// Transients — wildcard delete by prefix via direct SQL
	global $wpdb;

	foreach ( gdtg_uninstall_transient_prefixes() as $prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
	}

	// Per-post lock options — wildcard delete by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'gdtg_sync_lock_' ) . '%'
		)
	);

	// Post meta
	foreach ( gdtg_uninstall_post_meta_keys() as $key ) {
		delete_post_meta_by_key( $key );
	}
}

// ─── Cleanup: all sites (single-site or multisite network) ─────────────
function gdtg_uninstall_cleanup_all_sites() {
	// Multisite: iterate every site in the network.
	if ( function_exists( 'is_multisite' ) && function_exists( 'get_sites' )
		&& function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' )
		&& is_multisite() ) {

		$original_blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

		foreach ( $sites as $site_id ) {
			switch_to_blog( (int) $site_id );
			gdtg_uninstall_cleanup_current_site();
			restore_current_blog();
		}

		// Restore original site context if switch_to_blog changed it.
		if ( function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $original_blog_id );
			restore_current_blog();
		}

		return;
	}

	// Single-site or missing multisite helpers: clean current site only.
	gdtg_uninstall_cleanup_current_site();
}

// ─── Execute ───────────────────────────────────────────────────────────
gdtg_uninstall_cleanup_all_sites();
