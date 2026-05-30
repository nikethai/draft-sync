<?php
/**
 * Plugin Name:       DraftSync
 * Plugin URI:        https://cortisol.dev/draftsync
 * Description:       Import Google Docs and .docx files into editable native Gutenberg blocks.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Cortisol
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path:       /languages
 * Text Domain:       draftsync
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Core Plugin Constants
define( 'GDTG_VERSION', '0.2.0' );
define( 'GDTG_PATH', plugin_dir_path( __FILE__ ) );


// Packaged default SaaS bridge URL — replaced by GDTG_SAAS_BRIDGE_BASE_URL constant
// or gdtg_saas_bridge_base_url option.
if ( ! defined( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT' ) ) {
	define( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT', 'https://draftsync.cortisol.icu' );
}
define( 'GDTG_URL', plugin_dir_url( __FILE__ ) );
define( 'GDTG_BASENAME', plugin_basename( __FILE__ ) );
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}
if ( ! defined( 'GDTG_LARGE_DOC_BYTE_THRESHOLD' ) ) {
	define( 'GDTG_LARGE_DOC_BYTE_THRESHOLD', 10 * MB_IN_BYTES );
}

// Autoload Core Classes
require_once GDTG_PATH . 'includes/class-gdtg-loader.php';
require_once GDTG_PATH . 'includes/class-gdtg-admin.php';
require_once GDTG_PATH . 'includes/class-gdtg-api.php';
require_once GDTG_PATH . 'includes/class-gdtg-doc-node.php';
require_once GDTG_PATH . 'includes/class-gdtg-block-renderer.php';
require_once GDTG_PATH . 'includes/class-gdtg-html-renderer.php';
require_once GDTG_PATH . 'includes/class-gdtg-sideloader.php';
require_once GDTG_PATH . 'includes/class-gdtg-parser.php';
require_once GDTG_PATH . 'includes/class-gdtg-rest-endpoints.php';
require_once GDTG_PATH . 'includes/class-gdtg-zip-validator.php';
require_once GDTG_PATH . 'includes/class-gdtg-docx-parser.php';
require_once GDTG_PATH . 'includes/class-gdtg-import-orchestrator.php';
require_once GDTG_PATH . 'includes/class-gdtg-large-doc-streamer.php';
require_once GDTG_PATH . 'includes/class-gdtg-post-meta-applier.php';
require_once GDTG_PATH . 'includes/class-gdtg-sync-scheduler.php';
require_once GDTG_PATH . 'includes/class-gdtg-secret-store.php';
require_once GDTG_PATH . 'includes/class-gdtg-sync-lock.php';
require_once GDTG_PATH . 'includes/class-gdtg-sync-log.php';

// Load WP-CLI commands only in CLI context.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once GDTG_PATH . 'includes/class-gdtg-cli-command.php';
}

/**
 * Main Controller Class for Google Docs to Gutenberg
 */
class Google_Docs_To_Gutenberg {
	/**
	 * Loader instance
	 */
	protected $loader;

	/**
	 * Admin instance
	 */
	protected $admin;

	/**
	 * REST Endpoints instance
	 */
	protected $rest_endpoints;
	/**
	 * Sync scheduler instance
	 */
	protected $sync_scheduler;

	/**
	 * Initialize the plugin
	 */
	public function __construct() {
		$this->loader         = new GDTG_Loader();
		$this->admin          = new GDTG_Admin( $this->loader );
		$this->rest_endpoints = new GDTG_REST_Endpoints( $this->loader );
		$this->sync_scheduler = new GDTG_Sync_Scheduler( $this->loader );
		$this->boot();
	}

	/**
	 * Run the action/filter registration
	 */
	private function boot() {
		$this->loader->run();
	}
}

/**
 * Load plugin text domain for translations
 */
function google_docs_to_gutenberg_load_textdomain() {
	load_plugin_textdomain(
		'draftsync',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'google_docs_to_gutenberg_load_textdomain', 9 );

/**
 * One-time migration: clear stale SaaS bridge URL option left over from the
 * old ds.wearesection.vn domain.  The option (priority 2 in
 * saas_bridge_base_url()) silently outranks the packaged default, so installs
 * that ever saved the old value keep resolving to a dead host until the
 * option is explicitly removed.
 *
 * Fires on every page load but does a single cheap get_option + delete_option
 * and then flips a flag so subsequent loads are a no-op.
 */
function gdtg_migrate_bridge_url() {
	$option = get_option( 'gdtg_saas_bridge_base_url', '' );
	if ( '' === $option ) {
		return;
	}
	$host = wp_parse_url( $option, PHP_URL_HOST );
	if ( is_string( $host ) && 'ds.wearesection.vn' === $host ) {
		delete_option( 'gdtg_saas_bridge_base_url' );
	}
}
add_action( 'init', 'gdtg_migrate_bridge_url' );


/**
 * Instantiate the plugin
 */
function run_google_docs_to_gutenberg() {
	new Google_Docs_To_Gutenberg();
}
add_action( 'plugins_loaded', 'run_google_docs_to_gutenberg' );
// Clear scheduled auto-sync on plugin deactivation.
register_deactivation_hook( __FILE__, function() {
	$scheduler = new GDTG_Sync_Scheduler( new GDTG_Loader() );
	$scheduler->clear_scheduled();
} );

// Register WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'plugins_loaded', function() {
		WP_CLI::add_command( 'draftsync', 'GDTG_CLI_Command' );
	} );
}
