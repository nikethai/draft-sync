<?php
/**
 * Modern Admin Settings Dashboard and Settings Registry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDTG_Admin {
	/**
	 * Loader instance
	 */
	protected $loader;

	/**
	 * Constructor
	 */
	public function __construct( $loader ) {
		$this->loader = $loader;
		$this->init_hooks();
	}

	/**
	 * Register actions and filters
	 */
	private function init_hooks() {
		$this->loader->add_action( 'admin_menu', $this, 'register_admin_menu' );
		$this->loader->add_action( 'admin_init', $this, 'register_settings' );
		$this->loader->add_action( 'admin_init', $this, 'handle_oauth_redirect' );
		$this->loader->add_action( 'admin_init', $this, 'handle_imported_docs_redirect' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$this->loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_editor_assets' );
		$this->loader->add_action( 'admin_init', $this, 'register_privacy_policy_content' );
	}
	/**
	 * Generate a cryptographically secure OAuth state parameter and store it as a transient.
	 *
	 * @return string The generated state token.
	 */
	private function generate_oauth_state( $flow = 'saas' ) {
		$state = wp_generate_password( 32, false );
		set_transient( 'gdtg_oauth_state_' . $flow . '_' . $state, $flow, 15 * MINUTE_IN_SECONDS );
		return $state;
	}
	/**
	 * Validate and consume an OAuth state parameter.
	 *
	 * @param string $state The state token from the callback.
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_oauth_state( $state, $flow = '' ) {
		if ( empty( $state ) || ! is_string( $state ) ) {
			return false;
		}
		if ( ! empty( $flow ) ) {
			$stored = get_transient( 'gdtg_oauth_state_' . $flow . '_' . $state );
			if ( false === $stored ) {
				return false;
			}
			delete_transient( 'gdtg_oauth_state_' . $flow . '_' . $state );
			return true;
		}
		// Legacy fallback: try both flows for states in flight before the upgrade.
		foreach ( array( 'saas', 'enterprise' ) as $try_flow ) {
			$stored = get_transient( 'gdtg_oauth_state_' . $try_flow . '_' . $state );
			if ( false !== $stored ) {
				delete_transient( 'gdtg_oauth_state_' . $try_flow . '_' . $state );
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether the public SaaS bridge auth endpoint is resolvable from the current host.
	 *
	 * Avoids sending users to a dead hostname when the bridge/domain is offline.
	 *
	 * @return bool
	 */
	private function is_saas_bridge_available() {
		$base_url = GDTG_API::saas_bridge_base_url();
		$host = wp_parse_url( $base_url . '/api/auth', PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		// Scope transient by host so a config change invalidates the cache.
		$transient_key = 'gdtg_saas_bridge_available_' . md5( $host );
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		// Guard: dns_get_record may not exist on some hosts.
		if ( ! function_exists( 'dns_get_record' ) ) {
			set_transient( $transient_key, '0', 15 * MINUTE_IN_SECONDS );
			return false;
		}
		// Scope warning handling to this single call so DNS errors do not leak into admin HTML.
		set_error_handler( function () { return true; } );
		try {
			$records = dns_get_record( $host, DNS_A + DNS_AAAA + DNS_CNAME );
		} catch ( \Throwable $e ) {
			$records = false;
		} finally {
			restore_error_handler();
		}
		$available = is_array( $records ) && ! empty( $records );
		set_transient( $transient_key, $available ? '1' : '0', 15 * MINUTE_IN_SECONDS );
		return $available;
	}

	/**
	 * Register top-level dashboard menu item and Imported Docs submenu.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'DraftSync', 'draftsync' ),
			__( 'DraftSync', 'draftsync' ),
			'manage_options',
			'gdtg-settings',
			[ $this, 'render_dashboard' ],
			'dashicons-cloud-import',
			80
		);

		add_submenu_page(
			'gdtg-settings',
			__( 'Imported Docs', 'draftsync' ),
			__( 'Imported Docs', 'draftsync' ),
			'manage_options',
			'gdtg-imported-docs',
			[ $this, 'render_imported_docs' ]
		);
	}
	/**
	 * Redirect Imported Docs submenu requests early, before output starts.
	 */
	public function handle_imported_docs_redirect() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		if ( 'gdtg-imported-docs' !== $page ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=gdtg-settings&tab=imported-docs' ) );
		exit;
	}

	/**
	 * Fallback renderer for Imported Docs submenu page.
	 *
	 * This should normally not execute because admin_init redirects first.
	 */
	public function render_imported_docs() {
		$this->render_dashboard();
	}

	/**
	 * Enqueue Gutenberg Editor React sidebar assets
	 */
	public function enqueue_editor_assets() {
		$asset_file = GDTG_PATH . 'build/index.asset.php';
		
		// Fallback defaults if asset file is not yet generated by webpack
		$dependencies = [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ];
		$version      = GDTG_VERSION;

		if ( file_exists( $asset_file ) ) {
			$assets       = require $asset_file;
			$dependencies = $assets['dependencies'];
			$version      = $assets['version'];
		}

		wp_enqueue_script(
			'gdtg-editor-sidebar',
			GDTG_URL . 'build/index.js',
			$dependencies,
			$version,
			true
		);

		wp_enqueue_style(
			'gdtg-editor-sidebar-style',
			GDTG_URL . 'build/index.css',
			[],
			$version
		);
		wp_enqueue_style( 'gdtg-import-caution', GDTG_URL . 'assets/css/import-caution.css', [], GDTG_VERSION );
		wp_style_add_data( 'gdtg-editor-sidebar-style', 'rtl', 'replace' );

		wp_localize_script(
			'gdtg-editor-sidebar',
			'GDTG_Settings',
			[
				'rest_url'          => esc_url_raw( rest_url( 'gdtg/v1/import' ) ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'post_id'           => get_the_ID(),
				'import_images'     => get_option( 'gdtg_import_images', '1' ) === '1',
				'import_tables'     => get_option( 'gdtg_import_tables', '1' ) === '1',
				'overwrite'         => get_option( 'gdtg_overwrite', '0' ) === '1',
				'import_as_draft'   => get_option( 'gdtg_import_as_draft', '1' ) === '1',
				'output_mode'       => sanitize_text_field( get_option( 'gdtg_output_mode', 'gutenberg' ) ),
				'optimize_images'   => get_option( 'gdtg_optimize_images', '1' ) === '1',
				'heading_demotion'  => (int) get_option( 'gdtg_default_heading_demotion', 0 ),
				'min_heading_level' => (int) get_option( 'gdtg_default_min_heading_level', 1 ),
				'default_alignment' => sanitize_text_field( get_option( 'gdtg_default_alignment', '' ) ),
				'supported_post_types' => $this->get_supported_post_types(),
				'post_source_type'  => get_post_meta( get_the_ID(), '_gdtg_source_type', true ) ?: '',
				'post_auto_sync'    => get_post_meta( get_the_ID(), '_gdtg_auto_sync', true ) ?: '0',
				'connection_mode'       => sanitize_text_field( get_option( 'gdtg_connection_mode', 'saas' ) ),
				'drive_browser_enabled' => ! empty( get_option( 'gdtg_enterprise_client_id', '' ) ),
				'picker_config_url'     => esc_url_raw( rest_url( 'gdtg/v1/picker/config' ) ),
				'picker_token_url'      => esc_url_raw( rest_url( 'gdtg/v1/auth/token' ) ),
			]
		);
	}

	/**
	 * Enqueue admin settings page assets (CSS + JS) and localize REST auth for manager tab.
	 */
	public function enqueue_admin_assets( $hook ) {
		$is_settings = ( false !== strpos( $hook, 'gdtg-settings' ) );
		$is_docs     = ( false !== strpos( $hook, 'gdtg-imported-docs' ) );

		if ( ! $is_settings && ! $is_docs ) {
			return;
		}

		$css = GDTG_URL . 'assets/css/admin-settings.css';
		$js  = GDTG_URL . 'assets/js/admin-settings.js';
		wp_enqueue_style( 'gdtg-admin-settings', $css, [], GDTG_VERSION );
		wp_enqueue_style( 'gdtg-import-caution', GDTG_URL . 'assets/css/import-caution.css', [], GDTG_VERSION );
		wp_enqueue_script( 'gdtg-admin-settings-js', $js, [], GDTG_VERSION, true );

		// Localize REST auth for the manager tab (vanilla JS uses fetch)
		wp_localize_script(
			'gdtg-admin-settings-js',
			'GDTG_Admin',
			[
				'rest_url'          => esc_url_raw( rest_url( 'gdtg/v1/' ) ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'picker_config_url' => esc_url_raw( rest_url( 'gdtg/v1/picker/config' ) ),
				'picker_token_url'  => esc_url_raw( rest_url( 'gdtg/v1/auth/token' ) ),
			]
		);
	}

	/**
	 * Return supported post types for the editor sidebar's post-type selector.
	 *
	 * @return array Array of { value, label } objects for selectable post types.
	 */
	private function get_supported_post_types() {
		$post_types = get_post_types( [ 'public' => true, 'show_ui' => true ], 'objects' );
		$result = [];
		foreach ( $post_types as $slug => $pt ) {
			if ( ! current_user_can( $pt->cap->edit_posts ) ) {
				continue;
			}
			$result[] = [
				'value' => $slug,
				'label' => $pt->labels->singular_name ?: $slug,
			];
		}
		return $result;
	}

	/**
	 * Register WordPress settings APIs
	 */
	public function register_settings() {
		// Connection Modes and Keys
		register_setting( 'gdtg_settings_group', 'gdtg_connection_mode', [
			'sanitize_callback' => function ( $value ) {
				return in_array( $value, [ 'saas', 'enterprise' ], true ) ? $value : 'saas';
			},
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_enterprise_client_id', [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_enterprise_client_secret', [
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'gdtg_settings_group', 'gdtg_saas_bridge_base_url', [
			'sanitize_callback' => function ( $value ) {
				$validated = GDTG_API::validate_bridge_url( $value );
				return '' !== $validated ? $validated : get_option( 'gdtg_saas_bridge_base_url', '' );
			},
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_default_category', [
			'sanitize_callback' => 'absint',
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_default_author', [
			'sanitize_callback' => 'absint',
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_optimize_images', [
			'sanitize_callback' => function ( $value ) { return $value ? '1' : '0'; },
		] );
		// Phase 1.5: Import defaults
		register_setting( 'gdtg_settings_group', 'gdtg_import_images', [
			'sanitize_callback' => function ( $value ) { return $value ? '1' : '0'; },
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_import_tables', [
			'sanitize_callback' => function ( $value ) { return $value ? '1' : '0'; },
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_overwrite', [
			'sanitize_callback' => function ( $value ) { return $value ? '1' : '0'; },
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_import_as_draft', [
			'sanitize_callback' => function ( $value ) { return $value ? '1' : '0'; },
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_output_mode', [
			'sanitize_callback' => function ( $value ) {
				return in_array( $value, [ 'gutenberg', 'classic' ], true ) ? $value : 'gutenberg';
			},
		] );
		// Auto-sync settings
		register_setting( 'gdtg_settings_group', 'gdtg_auto_sync_enabled', [
			'sanitize_callback' => function ( $value ) { return $value ? '1' : '0'; },
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_auto_sync_frequency', [
			'sanitize_callback' => function ( $value ) {
				return in_array( $value, [ 'off', 'hourly', 'twicedaily', 'daily' ], true ) ? $value : 'daily';
			},
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_auto_sync_limit', [
			'sanitize_callback' => function ( $value ) {
				return max( 1, min( 50, absint( $value ) ) );
			},
		] );
		// Style override defaults
		register_setting( 'gdtg_settings_group', 'gdtg_default_heading_demotion', [
			'sanitize_callback' => function ( $value ) {
				return max( 0, min( 5, absint( $value ) ) );
			},
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_default_min_heading_level', [
			'sanitize_callback' => function ( $value ) {
				return max( 1, min( 6, absint( $value ) ) );
			},
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_default_alignment', [
			'sanitize_callback' => function ( $value ) {
				return in_array( $value, [ '', 'left', 'center', 'right' ], true ) ? $value : '';
			},
		] );

		// Phase 2: Google Picker config (non-secret, safe for localized data).
		register_setting( 'gdtg_settings_group', 'gdtg_picker_app_id', [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'gdtg_settings_group', 'gdtg_picker_developer_key', [
			'sanitize_callback' => 'sanitize_text_field',
		] );
	}
	/**
	 * Catch OAuth callback parameters in redirect URLs
	 */
	public function handle_oauth_redirect() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// 1. Centralized SaaS Auth Callback — requires code + state (fail-closed: no token-in-query)
		if ( isset( $_GET['gdtg_saas_callback'] ) ) {
			$code  = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
			$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
			if ( ! $this->validate_oauth_state( $state, 'saas' ) ) {
				wp_die( esc_html__( 'Invalid or expired OAuth state. Please try connecting again.', 'draftsync' ), '', [ 'response' => 403 ] );
			}
			if ( empty( $code ) ) {
				// Fail closed — the SaaS bridge must support code exchange.
				wp_die( esc_html__( 'Authorization code missing. The SaaS bridge must support code exchange for secure authentication.', 'draftsync' ), '', [ 'response' => 400 ] );
			}
			// Exchange authorization code for tokens via SaaS bridge
			$response = wp_remote_post(
				GDTG_API::saas_bridge_base_url() . '/api/token',
				[
					'body'    => [
						'code'           => $code,
						'redirect_uri'   => admin_url( 'admin.php?page=gdtg-settings&gdtg_saas_callback=1' ),
						'site_url'       => home_url(),
						'plugin_version' => GDTG_VERSION,
					],
					'headers' => [ 'Accept' => 'application/json' ],
					'timeout' => 15,
				]
			);
			if ( is_wp_error( $response ) ) {
				wp_die( esc_html__( 'Failed to exchange authorization code with SaaS bridge.', 'draftsync' ), '', [ 'response' => 500 ] );
			}
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) || empty( $data['access_token'] ) || empty( $data['refresh_token'] ) ) {
				wp_die( esc_html__( 'SaaS bridge returned an invalid response. Token exchange failed.', 'draftsync' ), '', [ 'response' => 500 ] );
			}
			update_option( 'gdtg_connection_mode', 'saas' );
			update_option( 'gdtg_saas_access_token', sanitize_text_field( $data['access_token'] ) );
			update_option( 'gdtg_saas_refresh_token', sanitize_text_field( $data['refresh_token'] ) );
			$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600;
			update_option( 'gdtg_saas_token_expires', time() + $expires_in );
			update_option( 'gdtg_saas_connected', 1 );
			wp_safe_redirect( admin_url( 'admin.php?page=gdtg-settings&tab=connection&connection=success' ) );
			exit;
		}
		// 2. Direct OAuth Client OAuth Callback
		if ( isset( $_GET['code'] ) && 'enterprise' === get_option( 'gdtg_connection_mode', 'saas' ) && ! isset( $_GET['gdtg_saas_callback'] ) ) {
			$code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$state         = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
			$client_id     = get_option( 'gdtg_enterprise_client_id', '' );
			$client_secret = GDTG_Secret_Store::get( 'gdtg_enterprise_client_secret' );
			if ( is_wp_error( $client_secret ) ) {
				$client_secret = get_option( 'gdtg_enterprise_client_secret', '' );
			}
			if ( ! $this->validate_oauth_state( $state, 'enterprise' ) ) {
				wp_die( esc_html__( 'Invalid or expired OAuth state. Please try connecting again.', 'draftsync' ), '', [ 'response' => 403 ] );
			}
			// Exchange auth code for tokens
			$response = wp_remote_post(
				'https://oauth2.googleapis.com/token',
				[
					'body'    => [
						'code'          => $code,
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'redirect_uri'  => admin_url( 'admin.php?page=gdtg-settings' ),
						'grant_type'    => 'authorization_code',
					],
					'timeout' => 15,
				]
			);
			if ( ! is_wp_error( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );
				if ( isset( $data['access_token'] ) ) {
				$access_token = sanitize_text_field( $data['access_token'] );
				// Store OAuth access token encrypted at rest. The
				// access token is always non-empty on this path and must be
				// stored, so any storage failure here is a hard error.
				$access_stored = GDTG_Secret_Store::set( 'gdtg_enterprise_access_token', $access_token );
				if ( ! $access_stored ) {
					wp_die( esc_html__( 'Failed to securely store OAuth access token.', 'draftsync' ), '', array( 'response' => 500 ) );
				}
				// Refresh token: Google routinely omits this on re-consent
				// (only the first consent returns one). Only write when the
				// response includes a non-empty value so we don't clobber a
				// previously stored valid refresh token with an empty one.
				if ( ! empty( $data['refresh_token'] ) ) {
					$refresh_token = sanitize_text_field( $data['refresh_token'] );
					$refresh_stored = GDTG_Secret_Store::set( 'gdtg_enterprise_refresh_token', $refresh_token );
					if ( ! $refresh_stored ) {
					wp_die( esc_html__( 'Failed to securely store OAuth refresh token.', 'draftsync' ), '', array( 'response' => 500 ) );
					}
				}
				$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600;
				update_option( 'gdtg_enterprise_token_expires', time() + $expires_in );
				update_option( 'gdtg_enterprise_connected', 1 );
					wp_safe_redirect( admin_url( 'admin.php?page=gdtg-settings&tab=connection&connection=success' ) );
					exit;
				}
			}
		}
		// Disconnect Action — nonce-protected
		if ( isset( $_GET['action'] ) && 'gdtg_disconnect' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			check_admin_referer( 'gdtg_disconnect' );
			delete_option( 'gdtg_saas_access_token' );
			delete_option( 'gdtg_saas_refresh_token' );
			delete_option( 'gdtg_saas_connected' );
			delete_option( 'gdtg_enterprise_access_token' );
			delete_option( 'gdtg_enterprise_refresh_token' );
			delete_option( 'gdtg_enterprise_connected' );
			wp_safe_redirect( admin_url( 'admin.php?page=gdtg-settings&tab=connection&disconnected=true' ) );
			exit;
		}
	}
	public function render_dashboard() {
		// Save settings if submitted
		if ( isset( $_POST['submit'] ) ) {
			check_admin_referer( 'gdtg-settings-nonce' );
			$mode = sanitize_text_field( wp_unslash( $_POST['gdtg_connection_mode'] ?? 'saas' ) );
			$mode = in_array( $mode, [ 'saas', 'enterprise' ], true ) ? $mode : 'saas';
			update_option( 'gdtg_connection_mode', $mode );
			update_option( 'gdtg_default_category', absint( $_POST['gdtg_default_category'] ?? 1 ) );
			$heading_demotion = absint( $_POST['gdtg_default_heading_demotion'] ?? 0 );
			update_option( 'gdtg_default_heading_demotion', max( 0, min( 5, $heading_demotion ) ) );
			$min_heading = absint( $_POST['gdtg_default_min_heading_level'] ?? 1 );
			update_option( 'gdtg_default_min_heading_level', max( 1, min( 6, $min_heading ) ) );
			$alignment = sanitize_text_field( wp_unslash( $_POST['gdtg_default_alignment'] ?? '' ) );
			update_option( 'gdtg_default_alignment', in_array( $alignment, [ '', 'left', 'center', 'right' ], true ) ? $alignment : '' );

			update_option( 'gdtg_default_author', absint( $_POST['gdtg_default_author'] ?? 1 ) );
			update_option( 'gdtg_optimize_images', isset( $_POST['gdtg_optimize_images'] ) ? '1' : '0' );

			update_option( 'gdtg_import_images', isset( $_POST['gdtg_import_images'] ) ? '1' : '0' );
			update_option( 'gdtg_import_tables', isset( $_POST['gdtg_import_tables'] ) ? '1' : '0' );
			update_option( 'gdtg_overwrite', isset( $_POST['gdtg_overwrite'] ) ? '1' : '0' );
			update_option( 'gdtg_import_as_draft', isset( $_POST['gdtg_import_as_draft'] ) ? '1' : '0' );
			$output_mode = sanitize_text_field( wp_unslash( $_POST['gdtg_output_mode'] ?? 'gutenberg' ) );
			update_option( 'gdtg_output_mode', in_array( $output_mode, [ 'gutenberg', 'classic' ], true ) ? $output_mode : 'gutenberg' );

			update_option( 'gdtg_auto_sync_enabled', isset( $_POST['gdtg_auto_sync_enabled'] ) ? '1' : '0' );
			$frequency = sanitize_text_field( wp_unslash( $_POST['gdtg_auto_sync_frequency'] ?? 'daily' ) );
			update_option( 'gdtg_auto_sync_frequency', in_array( $frequency, [ 'off', 'hourly', 'twicedaily', 'daily' ], true ) ? $frequency : 'daily' );
			$sync_limit = absint( $_POST['gdtg_auto_sync_limit'] ?? 10 );
			update_option( 'gdtg_auto_sync_limit', max( 1, min( 50, $sync_limit ) ) );

			$auto_sync_enabled = get_option( 'gdtg_auto_sync_enabled', '0' );
			if ( class_exists( 'GDTG_Sync_Scheduler' ) ) {
				$scheduler = new GDTG_Sync_Scheduler( new GDTG_Loader() );
				if ( '1' === $auto_sync_enabled ) {
					$scheduler->clear_scheduled();
					$scheduler->ensure_scheduled();
				} else {
					$scheduler->clear_scheduled();
				}
			}

			if ( 'enterprise' === $mode ) {
				update_option( 'gdtg_enterprise_client_id', sanitize_text_field( wp_unslash( $_POST['gdtg_enterprise_client_id'] ?? '' ) ) );
				$submitted_secret = sanitize_text_field( wp_unslash( $_POST['gdtg_enterprise_client_secret'] ?? '' ) );

				// Migrate legacy plaintext secret before writing new encrypted value.
				GDTG_Secret_Store::migrate_option( 'gdtg_enterprise_client_secret' );

				if ( '' !== $submitted_secret ) {
					GDTG_Secret_Store::set( 'gdtg_enterprise_client_secret', $submitted_secret );
				}
			}

		// Pre-flight validation for Direct OAuth mode.
			if ( 'enterprise' === $mode ) {
				$preflight_client_id    = trim( (string) get_option( 'gdtg_enterprise_client_id', '' ) );
				$preflight_client_secret = GDTG_Secret_Store::get( 'gdtg_enterprise_client_secret' );
				if ( is_wp_error( $preflight_client_secret ) ) {
					$preflight_client_secret = get_option( 'gdtg_enterprise_client_secret', '' );
				}

				$preflight_notices = array();

				if ( '' === $preflight_client_id ) {
				$preflight_notices[] = __( 'Missing Client ID — Google OAuth Client ID is required for Direct OAuth mode.', 'draftsync' );
				} elseif ( ! preg_match( '/^\d+-[a-z0-9-]+\.apps\.googleusercontent\.com$/', $preflight_client_id ) ) {
					$preflight_notices[] = __( "Client ID doesn't look like a Google OAuth web client ID.", 'draftsync' );
				}

				if ( '' === $preflight_client_secret ) {
				$preflight_notices[] = __( 'Missing Client Secret — Google OAuth Client Secret is required for Direct OAuth mode.', 'draftsync' );
				}

				foreach ( $preflight_notices as $notice ) {
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
				}
			}
			// Phase 2: Save Picker config fields (non-secret).
			update_option( 'gdtg_picker_app_id', sanitize_text_field( wp_unslash( $_POST['gdtg_picker_app_id'] ?? '' ) ) );
			update_option( 'gdtg_picker_developer_key', sanitize_text_field( wp_unslash( $_POST['gdtg_picker_developer_key'] ?? '' ) ) );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'draftsync' ) . '</p></div>';
		}

		// ── State variables ──
		$current_mode    = get_option( 'gdtg_connection_mode', 'saas' );
		$is_connected    = ( 'enterprise' === $current_mode ) ? get_option( 'gdtg_enterprise_connected', 0 ) : get_option( 'gdtg_saas_connected', 0 );
		$client_id       = get_option( 'gdtg_enterprise_client_id', '' );
		$client_secret   = GDTG_Secret_Store::get( 'gdtg_enterprise_client_secret' );
		if ( is_wp_error( $client_secret ) ) {
			$client_secret = get_option( 'gdtg_enterprise_client_secret', '' );
		}
		$optimize_images = get_option( 'gdtg_optimize_images', '1' );
		$import_images   = get_option( 'gdtg_import_images', '1' );
		$import_tables   = get_option( 'gdtg_import_tables', '1' );
		$overwrite       = get_option( 'gdtg_overwrite', '0' );
		$import_as_draft = get_option( 'gdtg_import_as_draft', '1' );
		$output_mode     = get_option( 'gdtg_output_mode', 'gutenberg' );
		$auto_sync_enabled = get_option( 'gdtg_auto_sync_enabled', '0' );
		$auto_sync_freq   = get_option( 'gdtg_auto_sync_frequency', 'daily' );
		$auto_sync_limit  = get_option( 'gdtg_auto_sync_limit', 10 );

		// OAuth URLs
		$default_heading_demotion = get_option( 'gdtg_default_heading_demotion', 0 );
		$default_min_heading_level = get_option( 'gdtg_default_min_heading_level', 1 );
		$default_alignment = get_option( 'gdtg_default_alignment', '' );
		$saas_state            = $this->generate_oauth_state( 'saas' );
		$saas_bridge_available = $this->is_saas_bridge_available();
		$saas_auth_url         = $saas_bridge_available
			? GDTG_API::saas_bridge_base_url() . '/api/auth?' . http_build_query([
				'redirect_uri' => admin_url( 'admin.php?page=gdtg-settings&gdtg_saas_callback=1' ),
				'state'        => $saas_state,
			])
			: '';

		$google_auth_url = '';
		if ( 'enterprise' === $current_mode && $client_id ) {
			$enterprise_state = $this->generate_oauth_state( 'enterprise' );
			$google_auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
				'client_id'     => $client_id,
				'redirect_uri'  => admin_url( 'admin.php?page=gdtg-settings' ),
				'response_type' => 'code',
				'scope'         => 'https://www.googleapis.com/auth/documents.readonly https://www.googleapis.com/auth/drive.readonly',
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $enterprise_state,
			]);
		}

		$disconnect_url = wp_nonce_url( admin_url( 'admin.php?page=gdtg-settings&action=gdtg_disconnect' ), 'gdtg_disconnect' );
		?>
<div class="wrap gdtg-wrap">
	<div class="gdtg-header">
		<div>
			<h1><?php esc_html_e( 'DraftSync', 'draftsync' ); ?> <span class="gdtg-version-badge">v<?php echo esc_html( GDTG_VERSION ); ?></span></h1>
			<p><?php esc_html_e( 'Import Google Docs and .docx files into Gutenberg with native block fidelity.', 'draftsync' ); ?></p>
		</div>
		<div>
			<?php if ( $is_connected ) : ?>
				<span class="gdtg-status gdtg-status--connected"><?php esc_html_e( 'Connected', 'draftsync' ); ?></span>
			<?php else : ?>
				<span class="gdtg-status gdtg-status--disconnected"><?php esc_html_e( 'Disconnected', 'draftsync' ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'gdtg-settings-nonce' ); ?>

		<!-- Tab navigation -->
		<nav class="nav-tab-wrapper gdtg-nav-tabs">
			<a href="#" class="nav-tab" data-tab="import"><?php esc_html_e( 'Import Defaults', 'draftsync' ); ?></a>
			<a href="#" class="nav-tab" data-tab="connection"><?php esc_html_e( 'Connection', 'draftsync' ); ?></a>
			<a href="#" class="nav-tab" data-tab="sync"><?php esc_html_e( 'Scheduled Sync', 'draftsync' ); ?></a>
			<a href="#" class="nav-tab" data-tab="imported-docs"><?php esc_html_e( 'Imported Docs', 'draftsync' ); ?></a>
			<a href="#" class="nav-tab" data-tab="help"><?php esc_html_e( 'Help', 'draftsync' ); ?></a>
		</nav>
		<!-- Tab: Connection -->
		<div id="gdtg-tab-connection" class="gdtg-tab-panel">
			<div class="gdtg-card">
				<h2><?php esc_html_e( 'Google Account', 'draftsync' ); ?></h2>

				<?php if ( $is_connected ) : ?>
					<div class="gdtg-license-notice gdtg-license-notice--active">
						<?php esc_html_e( 'DraftSync is connected. You can import Google Docs from the editor sidebar or Imported Docs tab.', 'draftsync' ); ?>
					</div>
					<div style="margin-top: 12px;">
						<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Disconnect Google Account', 'draftsync' ); ?>
						</a>
					</div>
				<?php elseif ( $saas_auth_url ) : ?>
					<div class="gdtg-license-notice">
						<?php esc_html_e( 'Connect your Google account to import Google Docs into WordPress.', 'draftsync' ); ?>
					</div>
					<div style="margin-top: 12px;">
						<a href="<?php echo esc_url( $saas_auth_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Connect Google Account', 'draftsync' ); ?>
						</a>
					</div>
					<p class="description" style="margin-top: 8px;"><?php esc_html_e( 'You can disconnect anytime.', 'draftsync' ); ?></p>
				<?php else : ?>
					<div class="gdtg-license-notice">
						<?php esc_html_e( 'Google connection is temporarily unavailable. Please try again later.', 'draftsync' ); ?>
					</div>
					<div style="margin-top: 12px;">
						<button type="button" class="button button-primary" disabled aria-disabled="true">
							<?php esc_html_e( 'Connect Google Account', 'draftsync' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>
				<div class="gdtg-card" style="margin-top: 16px;">
					<h3><?php esc_html_e( 'About External Services', 'draftsync' ); ?></h3>
					<p><?php esc_html_e( 'When you use Google Docs import, DraftSync contacts Google APIs (docs.googleapis.com, www.googleapis.com, oauth2.googleapis.com) to fetch document content. If you use the SaaS connection mode, authentication is brokered through the DraftSync OAuth bridge. No document content passes through the bridge. OAuth tokens are stored locally in your WordPress database and are deleted when you disconnect or uninstall the plugin.', 'draftsync' ); ?></p>
					<p><?php esc_html_e( 'You can also import .docx files locally without any external services.', 'draftsync' ); ?></p>
				</div>
		<!-- Direct OAuth Setup Guidance -->
			<div class="gdtg-card gdtg-card--enterprise-guide" style="margin-top: 16px;">
			<h3><?php esc_html_e( 'Direct OAuth Setup Guide', 'draftsync' ); ?></h3>
			<p><?php esc_html_e( 'Direct OAuth mode lets you use your own Google Cloud OAuth 2.0 credentials for direct, self-hosted authentication. This is ideal when you need compliance control, run on a custom domain, or prefer not to use the SaaS bridge.', 'draftsync' ); ?></p>
			<ol class="gdtg-enterprise-steps">
				<li><?php esc_html_e( 'Go to Google Cloud Console and create a Web Application OAuth 2.0 client.', 'draftsync' ); ?> <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Google Cloud Console', 'draftsync' ); ?> &#8599;</a></li>
				<li><?php esc_html_e( 'Paste the Client ID and Client Secret below.', 'draftsync' ); ?></li>
				<li><?php esc_html_e( 'Click Save Settings, then Connect Google Account.', 'draftsync' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'Direct OAuth (BYO-key) is supported for imports from the admin screen, Gutenberg sidebar, WP-CLI, and scheduled auto-sync.', 'draftsync' ); ?></p>
			</div>
		<!-- Direct OAuth connection configuration -->
			<div class="gdtg-card" style="margin-top: 16px;">
				<h3><?php esc_html_e( 'Connection Mode', 'draftsync' ); ?></h3>
				<div class="gdtg-form-row">
					<label>
						<input type="radio" name="gdtg_connection_mode" value="saas" <?php checked( $current_mode, 'saas' ); ?> />
						<?php esc_html_e( 'SaaS (default) — authentication brokered through DraftSync bridge', 'draftsync' ); ?>
					</label>
				</div>
				<div class="gdtg-form-row">
					<label>
						<input type="radio" name="gdtg_connection_mode" value="enterprise" <?php checked( $current_mode, 'enterprise' ); ?> />
					<?php esc_html_e( 'Direct OAuth — direct Google OAuth with your own client credentials', 'draftsync' ); ?>
					</label>
				</div>
				<div id="gdtg-enterprise-fields" style="<?php echo 'enterprise' === $current_mode ? '' : 'display:none;'; ?> margin-top: 12px;">
					<div class="gdtg-form-row">
						<label for="gdtg_enterprise_client_id"><?php esc_html_e( 'Google OAuth Client ID', 'draftsync' ); ?></label>
						<input type="text" id="gdtg_enterprise_client_id" name="gdtg_enterprise_client_id" class="regular-text" value="<?php echo esc_attr( $client_id ); ?>" autocomplete="off" />
					</div>
					<div class="gdtg-form-row">
						<label for="gdtg_enterprise_client_secret"><?php esc_html_e( 'Google OAuth Client Secret', 'draftsync' ); ?></label>
						<input type="password" id="gdtg_enterprise_client_secret" name="gdtg_enterprise_client_secret" class="regular-text" value="" autocomplete="off" placeholder="<?php echo ! empty( $client_secret ) ? esc_attr__( '•••••••• (stored encrypted)', 'draftsync' ) : ''; ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to keep the current secret. The secret is stored encrypted at rest.', 'draftsync' ); ?></p>
					</div>
					<div class="gdtg-form-row">
						<label><?php esc_html_e( 'Redirect URI', 'draftsync' ); ?></label>
						<div class="gdtg-copy-row">
							<input type="text" id="gdtg_enterprise_redirect_uri" class="regular-text" readonly value="<?php echo esc_attr( admin_url( 'admin.php?page=gdtg-settings' ) ); ?>" />
							<button type="button" id="gdtg-copy-redirect-uri" class="button button-secondary"><?php esc_html_e( 'Copy', 'draftsync' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'Add this exact URL as an Authorized redirect URI in your Google Cloud OAuth client settings.', 'draftsync' ); ?></p>
					</div>
					<div class="gdtg-form-row gdtg-json-import">
						<label for="gdtg_json_import"><?php esc_html_e( 'Import OAuth Client JSON', 'draftsync' ); ?></label>
						<div class="gdtg-json-import-row">
							<input type="file" id="gdtg_json_import" accept="application/json,.json" />
							<button type="button" id="gdtg-import-json-btn" class="button button-secondary"><?php esc_html_e( 'Import JSON', 'draftsync' ); ?></button>
						</div>
						<p id="gdtg-json-import-status" class="description" style="display:none;"></p>
						<p class="description"><?php esc_html_e( 'Downloaded from Google Cloud Console > Credentials. Parsed client-side — the file is never uploaded.', 'draftsync' ); ?></p>
					</div>
					<div class="gdtg-form-row">
						<label for="gdtg_picker_app_id"><?php esc_html_e( 'Google Picker App ID', 'draftsync' ); ?></label>
						<input type="text" id="gdtg_picker_app_id" name="gdtg_picker_app_id" class="regular-text" value="<?php echo esc_attr( get_option( 'gdtg_picker_app_id', '' ) ); ?>" autocomplete="off" />
					</div>
					<div class="gdtg-form-row">
						<label for="gdtg_picker_developer_key"><?php esc_html_e( 'Google Picker Developer Key', 'draftsync' ); ?></label>
						<input type="text" id="gdtg_picker_developer_key" name="gdtg_picker_developer_key" class="regular-text" value="<?php echo esc_attr( get_option( 'gdtg_picker_developer_key', '' ) ); ?>" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Optional: Google Picker App ID and Developer Key let users select a Doc or Drive file from inside the editor without copy-pasting a URL. Get them at', 'draftsync' ); ?> <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">https://console.cloud.google.com/apis/credentials</a></p>

					<?php if ( 'enterprise' === $current_mode && $client_id ) : ?>
					<div class="gdtg-form-row">
						<a href="<?php echo esc_url( $google_auth_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Connect Google Account (Direct OAuth)', 'draftsync' ); ?>
						</a>
					</div>
					<?php endif; ?>
					</div>
				</div>
			</div>
		</div>


		<div class="gdtg-grid">
			<div class="gdtg-main-col">
				<!-- Tab: Import Defaults -->
				<div id="gdtg-tab-import" class="gdtg-tab-panel">
					<div class="gdtg-card">
						<h2><?php esc_html_e( 'Import Configurations', 'draftsync' ); ?></h2>

						<div class="gdtg-form-row">
							<label for="gdtg_default_category"><?php esc_html_e( 'Default Target Category', 'draftsync' ); ?></label>
							<?php
							wp_dropdown_categories([
								'name'             => 'gdtg_default_category',
								'selected'         => get_option( 'gdtg_default_category', 1 ),
								'hide_empty'       => 0,
							]);
							?>
						</div>

						<div class="gdtg-form-row">
							<label for="gdtg_default_author"><?php esc_html_e( 'Default Post Author', 'draftsync' ); ?></label>
							<?php
							wp_dropdown_users([
								'name'     => 'gdtg_default_author',
								'selected' => get_option( 'gdtg_default_author', 1 ),
							]);
							?>
						</div>

						<div class="gdtg-form-row">
							<label for="gdtg_optimize_images">
								<input type="checkbox" id="gdtg_optimize_images" name="gdtg_optimize_images" value="1" <?php checked( $optimize_images, '1' ); ?>>
								<?php esc_html_e( 'Optimize & Compress Images (Auto-WebP)', 'draftsync' ); ?>
							</label>
							<p class="description" style="margin-left: 24px;"><?php esc_html_e( 'Auto-resizes to max 1600px and converts to WebP. Saves disk space and improves LCP.', 'draftsync' ); ?></p>
						</div>

						<div class="gdtg-section-heading"><?php esc_html_e( 'Import Defaults', 'draftsync' ); ?></div>

						<div class="gdtg-form-row">
							<label for="gdtg_output_mode"><?php esc_html_e( 'Default Output Mode', 'draftsync' ); ?></label>
							<select name="gdtg_output_mode" id="gdtg_output_mode">
								<option value="gutenberg" <?php selected( $output_mode, 'gutenberg' ); ?>><?php esc_html_e( 'Gutenberg Blocks', 'draftsync' ); ?></option>
								<option value="classic" <?php selected( $output_mode, 'classic' ); ?>><?php esc_html_e( 'Classic HTML', 'draftsync' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Classic mode produces clean HTML without block comments.', 'draftsync' ); ?></p>
						</div>

						<div class="gdtg-checkbox-row">
							<label for="gdtg_import_images">
								<input type="checkbox" id="gdtg_import_images" name="gdtg_import_images" value="1" <?php checked( $import_images, '1' ); ?>>
								<?php esc_html_e( 'Import Images by Default', 'draftsync' ); ?>
							</label>
						</div>
						<div class="gdtg-checkbox-row">
							<label for="gdtg_import_tables">
								<input type="checkbox" id="gdtg_import_tables" name="gdtg_import_tables" value="1" <?php checked( $import_tables, '1' ); ?>>
								<?php esc_html_e( 'Import Tables by Default', 'draftsync' ); ?>
							</label>
						</div>
						<div class="gdtg-checkbox-row">
							<label for="gdtg_overwrite">
								<input type="checkbox" id="gdtg_overwrite" name="gdtg_overwrite" value="1" <?php checked( $overwrite, '1' ); ?>>
								<?php esc_html_e( 'Overwrite Existing Content by Default', 'draftsync' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When off, new content appends to the existing post.', 'draftsync' ); ?></p>
						</div>
						<div class="gdtg-checkbox-row">
							<label for="gdtg_import_as_draft">
								<input type="checkbox" id="gdtg_import_as_draft" name="gdtg_import_as_draft" value="1" <?php checked( $import_as_draft, '1' ); ?>>
								<?php esc_html_e( 'Import New Posts as Draft by Default', 'draftsync' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When off, new posts publish immediately if the user can publish.', 'draftsync' ); ?></p>
						</div>

						<div class="gdtg-section-heading"><?php esc_html_e( 'Style Defaults', 'draftsync' ); ?></div>

						<div class="gdtg-form-row">
							<label for="gdtg_default_heading_demotion"><?php esc_html_e( 'Default Heading Demotion', 'draftsync' ); ?></label>
							<input type="number" id="gdtg_default_heading_demotion" name="gdtg_default_heading_demotion" value="<?php echo esc_attr( $default_heading_demotion ); ?>" min="0" max="5" step="1" style="width: 80px;">
							<p class="description"><?php esc_html_e( 'Shift all heading levels down by this amount (0 = no change, 5 = H1→H6).', 'draftsync' ); ?></p>
						</div>

						<div class="gdtg-form-row">
							<label for="gdtg_default_min_heading_level"><?php esc_html_e( 'Default Minimum Heading Level', 'draftsync' ); ?></label>
							<select name="gdtg_default_min_heading_level" id="gdtg_default_min_heading_level">
								<option value="1" <?php selected( $default_min_heading_level, 1 ); ?>><?php esc_html_e( 'H1 (default)', 'draftsync' ); ?></option>
								<option value="2" <?php selected( $default_min_heading_level, 2 ); ?>>H2</option>
								<option value="3" <?php selected( $default_min_heading_level, 3 ); ?>>H3</option>
								<option value="4" <?php selected( $default_min_heading_level, 4 ); ?>>H4</option>
								<option value="5" <?php selected( $default_min_heading_level, 5 ); ?>>H5</option>
								<option value="6" <?php selected( $default_min_heading_level, 6 ); ?>>H6</option>
							</select>
							<p class="description"><?php esc_html_e( 'Smallest heading level allowed. H1 = most prominent.', 'draftsync' ); ?></p>
						</div>

						<div class="gdtg-form-row">
							<label for="gdtg_default_alignment"><?php esc_html_e( 'Default Text Alignment', 'draftsync' ); ?></label>
							<select name="gdtg_default_alignment" id="gdtg_default_alignment">
								<option value="" <?php selected( $default_alignment, '' ); ?>><?php esc_html_e( 'Keep original', 'draftsync' ); ?></option>
								<option value="left" <?php selected( $default_alignment, 'left' ); ?>><?php esc_html_e( 'Left', 'draftsync' ); ?></option>
								<option value="center" <?php selected( $default_alignment, 'center' ); ?>><?php esc_html_e( 'Center', 'draftsync' ); ?></option>
								<option value="right" <?php selected( $default_alignment, 'right' ); ?>><?php esc_html_e( 'Right', 'draftsync' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Fallback alignment for paragraphs when none is defined.', 'draftsync' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Tab: Scheduled Sync -->
				<div id="gdtg-tab-sync" class="gdtg-tab-panel">
					<div class="gdtg-card">
						<h2><?php esc_html_e( 'Scheduled Auto-Sync', 'draftsync' ); ?></h2>

						<div class="gdtg-checkbox-row">
							<label for="gdtg_auto_sync_enabled">
								<input type="checkbox" id="gdtg_auto_sync_enabled" name="gdtg_auto_sync_enabled" value="1" <?php checked( $auto_sync_enabled, '1' ); ?>>
								<?php esc_html_e( 'Enable scheduled auto-sync for linked documents', 'draftsync' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Automatically pulls updates from Google Docs on a recurring schedule.', 'draftsync' ); ?></p>
						</div>

						<div class="gdtg-form-row">
							<label for="gdtg_auto_sync_frequency"><?php esc_html_e( 'Sync Frequency', 'draftsync' ); ?></label>
							<select name="gdtg_auto_sync_frequency" id="gdtg_auto_sync_frequency">
								<option value="off" <?php selected( $auto_sync_freq, 'off' ); ?>><?php esc_html_e( 'Off', 'draftsync' ); ?></option>
								<option value="hourly" <?php selected( $auto_sync_freq, 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'draftsync' ); ?></option>
								<option value="twicedaily" <?php selected( $auto_sync_freq, 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'draftsync' ); ?></option>
								<option value="daily" <?php selected( $auto_sync_freq, 'daily' ); ?>><?php esc_html_e( 'Daily', 'draftsync' ); ?></option>
							</select>
						</div>

						<div class="gdtg-form-row">
							<label for="gdtg_auto_sync_limit"><?php esc_html_e( 'Batch Size (per run)', 'draftsync' ); ?></label>
							<input type="number" id="gdtg_auto_sync_limit" name="gdtg_auto_sync_limit" value="<?php echo esc_attr( $auto_sync_limit ); ?>" min="1" max="50" step="1" style="width: 80px;">
							<p class="description"><?php esc_html_e( 'Maximum documents to sync in one cron run (1–50).', 'draftsync' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Tab: Imported Docs -->
				<div id="gdtg-tab-imported-docs" class="gdtg-tab-panel">
					<div class="gdtg-card">
						<h2><?php esc_html_e( 'Import New Document', 'draftsync' ); ?></h2>

						<div class="gdtg-import-form">
							<div class="gdtg-import-mode-tabs">
								<button type="button" class="gdtg-import-mode-tab active" data-import-mode="url"><?php esc_html_e( 'Google Doc URL', 'draftsync' ); ?></button>
								<button type="button" class="gdtg-import-mode-tab" data-import-mode="file"><?php esc_html_e( 'Upload .docx', 'draftsync' ); ?></button>
							</div>

							<div id="gdtg-import-url-section" class="gdtg-import-section">
								<div class="gdtg-form-row">
									<label for="gdtg-import-doc-url"><?php esc_html_e( 'Google Doc URL', 'draftsync' ); ?></label>
									<input type="text" id="gdtg-import-doc-url" class="regular-text" placeholder="https://docs.google.com/document/d/...">
								</div>
								<div id="gdtg-picker-row" class="gdtg-form-row">
									<button type="button" id="gdtg-admin-picker-btn" class="button button-secondary" disabled>
										<span class="dashicons dashicons-search" style="margin-top:5px;"></span>
										<?php esc_html_e( 'Choose from Google Drive', 'draftsync' ); ?>
									</button>
									<div id="gdtg-admin-picker-error" class="gdtg-picker-error" style="color:#d63638;margin-top:6px;display:none;"></div>
									<div id="gdtg-admin-picker-hint" class="gdtg-picker-hint" style="color:#646970;margin-top:6px;font-size:12px;display:none;"></div>
								</div>
							</div>

							<div id="gdtg-import-file-section" class="gdtg-import-section" style="display:none;">
								<div class="gdtg-form-row">
									<label><?php esc_html_e( '.docx File', 'draftsync' ); ?></label>
									<input type="file" id="gdtg-import-docx-file" class="gdtg-import-file-input" accept=".docx" style="display:none;">
									<label for="gdtg-import-docx-file" class="button button-secondary gdtg-import-file-button"><?php esc_html_e( 'Browse…', 'draftsync' ); ?></label>
									<div id="gdtg-import-docx-name" class="gdtg-import-docx-name">No file selected.</div>
								</div>
							</div>

							<div class="gdtg-import-options">
								<p class="gdtg-import-options-label"><?php esc_html_e( 'Content Options', 'draftsync' ); ?></p>
								<div class="gdtg-checkbox-row">
									<label for="gdtg-import-images">
										<input type="checkbox" id="gdtg-import-images" checked>
										<?php esc_html_e( 'Import Images', 'draftsync' ); ?>
									</label>
								</div>
								<div class="gdtg-checkbox-row">
									<label for="gdtg-import-tables">
										<input type="checkbox" id="gdtg-import-tables" checked>
										<?php esc_html_e( 'Import Tables', 'draftsync' ); ?>
									</label>
								</div>
								<div class="gdtg-import-destructive gdtg-caution-zone">
									<p class="gdtg-caution-zone__label"><?php esc_html_e( 'Content policy', 'draftsync' ); ?></p>
									<div class="gdtg-checkbox-row">
										<label for="gdtg-import-overwrite">
											<input type="checkbox" id="gdtg-import-overwrite">
											<?php esc_html_e( 'Overwrite Existing Content', 'draftsync' ); ?>
										</label>
									</div>
								</div>
								<div class="gdtg-checkbox-row">
									<label for="gdtg-import-as-draft">
										<input type="checkbox" id="gdtg-import-as-draft">
										<?php esc_html_e( 'Import as Draft', 'draftsync' ); ?>
									</label>
								</div>
								<div class="gdtg-form-row">
									<label for="gdtg-import-output-mode"><?php esc_html_e( 'Output Mode', 'draftsync' ); ?></label>
									<select id="gdtg-import-output-mode">
										<option value="gutenberg"><?php esc_html_e( 'Gutenberg Blocks', 'draftsync' ); ?></option>
										<option value="classic"><?php esc_html_e( 'Classic HTML', 'draftsync' ); ?></option>
									</select>
								</div>
							</div>

							<div class="gdtg-import-actions">
								<button type="button" id="gdtg-import-submit" class="button button-primary">
									<?php esc_html_e( 'Import & Parse Document', 'draftsync' ); ?>
								</button>
								<span id="gdtg-import-result" class="gdtg-import-result"></span>
							</div>
						</div>
					</div>


					<div class="gdtg-card">
						<h2><?php esc_html_e( 'Imported Docs', 'draftsync' ); ?></h2>

						<!-- Remote Sources table container -->
						<div id="gdtg-imported-docs-sources" class="gdtg-imported-docs-section">
							<h3><?php esc_html_e( 'Remote Sources', 'draftsync' ); ?></h3>
							<div id="gdtg-imported-docs-sources-table" class="gdtg-imported-docs-table">
								<p class="description gdtg-imported-docs-loading"><?php esc_html_e( 'Loading\u2026', 'draftsync' ); ?></p>
							</div>
						</div>

						<!-- Local Uploads table container -->
						<div id="gdtg-imported-docs-local" class="gdtg-imported-docs-section">
							<h3><?php esc_html_e( 'Local Uploads', 'draftsync' ); ?></h3>
							<div id="gdtg-imported-docs-local-table" class="gdtg-imported-docs-table">
								<p class="description gdtg-imported-docs-loading"><?php esc_html_e( 'Loading\u2026', 'draftsync' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Tab: Help -->
				<div id="gdtg-tab-help" class="gdtg-tab-panel">
					<div class="gdtg-card">
						<h2><?php esc_html_e( 'User Guideline', 'draftsync' ); ?></h2>
						<?php require GDTG_PATH . 'includes/admin-help-tab.php'; ?>
					</div>
				</div>

				<!-- Save button (shared across all tabs) -->
				<div class="gdtg-save-bar">
					<?php submit_button( __( 'Save Settings', 'draftsync' ), 'primary', 'submit', false ); ?>
				</div>

			</div>

			<!-- Right sidebar -->
			<div class="gdtg-sidebar">
				<div class="gdtg-card">
					<h2><?php esc_html_e( 'Quick Guide', 'draftsync' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Open any post in the block editor.', 'draftsync' ); ?></li>
						<li><?php esc_html_e( 'Click the cloud sync icon in the top toolbar.', 'draftsync' ); ?></li>
						<li><?php esc_html_e( 'Paste your Google Doc URL.', 'draftsync' ); ?></li>
						<li><?php esc_html_e( 'Click Import/Synchronize Now.', 'draftsync' ); ?></li>
					</ol>
				</div>
			</div>
		</div>
	</form>
</div>
		<?php
	}

	/**
	 * Register privacy policy content for the WordPress privacy policy page.
	 *
	 * Discloses external services, data sent, and local data storage.
	 */
	public function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			'<p class="privacy-policy-tutorial">%s</p>' .
			'<p>%s</p>' .
			'<ul>' .
			'<li>%s</li>' .
			'<li>%s</li>' .
			'<li>%s</li>' .
			'</ul>' .
			'<p>%s</p>',
			__( 'DraftSync — External Service & Data Disclosure', 'draftsync' ),
			__( 'When you connect a Google account to import Google Docs, this plugin contacts the following external services:', 'draftsync' ),
			__( 'Google APIs (docs.googleapis.com, www.googleapis.com, oauth2.googleapis.com) to fetch document content and file metadata.', 'draftsync' ),
			__( 'The DraftSync SaaS OAuth bridge (if you use the default SaaS connection mode) to broker Google authentication. No document content passes through the bridge.', 'draftsync' ),
			__( 'OAuth access tokens, refresh tokens, and your connection mode are stored locally in your WordPress database. These are deleted when you disconnect or uninstall the plugin.', 'draftsync' ),
			__( 'You can use the plugin without any external services by importing local .docx files.', 'draftsync' )
		);

		wp_add_privacy_policy_content(
		__( 'DraftSync', 'draftsync' ),
			wp_kses_post( $content )
		);
	}
}
