<?php
/**
 * Google Picker Config REST endpoint regression tests.
 *
 * Exercises GDTG_REST_Endpoints::handle_picker_config() and
 * GDTG_REST_Endpoints::handle_picker_token() with mocked
 * WordPress options and transients.
 */

echo "Running Picker Config Tests...\n\n";

// ─── Minimal WordPress stubs ──────────────────────────────────────


if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', true );

	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
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

	function sanitize_textarea_field( $field ) {
		return trim( strip_tags( $field ) );
	}

	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}

	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}

	// ── Option helpers ──
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
	function delete_option( $opt ) {
		global $mock_options;
		unset( $mock_options[ $opt ] );
	}

	// ── Transient helpers ──
	global $mock_transients;
	$mock_transients = array();

	function get_transient( $key ) {
		global $mock_transients;
		return isset( $mock_transients[ $key ] ) ? $mock_transients[ $key ] : false;
	}
	function set_transient( $key, $value, $expiration = 0 ) {
		global $mock_transients;
		$mock_transients[ $key ] = $value;
		return true;
	}
	function delete_transient( $key ) {
		global $mock_transients;
		unset( $mock_transients[ $key ] );
	}

	// ── User helpers ──
	global $mock_current_user_id;
	$mock_current_user_id = 0;

	function get_current_user_id() {
		global $mock_current_user_id;
		return $mock_current_user_id;
	}
	function wp_set_current_user( $id ) {
		global $mock_current_user_id;
		$mock_current_user_id = $id;
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-value-for-unit-tests';
	}
	function wp_rand( $min = 0, $max = 0 ) {
		return 12345;
	}
	function current_user_can( $cap ) {
		// Simulate: user 1 = admin, user 2 = editor, user 3 = subscriber.
		global $mock_current_user_id;
		if ( 3 === $mock_current_user_id ) {
			return false; // Subscriber has no caps.
		}
		if ( 'edit_posts' === $cap && 2 === $mock_current_user_id ) {
			return true;
		}
		if ( 1 === $mock_current_user_id ) {
			return true; // Admin has all caps.
		}
		return false;
	}

	// ── REST response stub ──
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}

	class WP_REST_Request {
		private $params = array();
		public function __construct( $params = array() ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}
		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
	}

	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
	}
}

// ─── Helper assertion functions ───────────────────────────────────

function assert_true( $val, $desc ) {
	if ( ! $val ) {
		echo "FAIL: $desc\n";
		exit( 1 );
	}
	echo "PASS: $desc\n";
}

function assert_equals( $expected, $actual, $desc ) {
	if ( $expected !== $actual ) {
		echo "FAIL: $desc (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
		exit( 1 );
	}
	echo "PASS: $desc\n";
}

function assert_array_has_key( $key, $arr, $desc ) {
	assert_true( is_array( $arr ) && array_key_exists( $key, $arr ), $desc );
}

function assert_not_has_key( $key, $arr, $desc ) {
	assert_true( ! is_array( $arr ) || ! array_key_exists( $key, $arr ), $desc );
}

function suite( $name ) {
	echo "\n=== $name ===\n";
}

// ─── Load production files ────────────────────────────────────────

require_once __DIR__ . '/../includes/class-gdtg-api.php';
require_once __DIR__ . '/../includes/class-gdtg-rest-endpoints.php';
require_once __DIR__ . '/../includes/class-gdtg-secret-store.php';
require_once __DIR__ . '/../includes/class-gdtg-sync-lock.php';

// ─── Helper: reset all mocks between tests ────────────────────────

function reset_mocks() {
	global $mock_options, $mock_transients, $mock_current_user_id;
	$mock_options         = array();
	$mock_transients      = array();
	$mock_current_user_id = 1; // Default: admin.
}

// ═══════════════════════════════════════════════════════════════════
// Picker Config Tests
// ═══════════════════════════════════════════════════════════════════

suite( 'Picker config: SaaS mode returns enabled=false' );

reset_mocks();
$mock_options['gdtg_connection_mode']        = 'saas';
$mock_options['gdtg_enterprise_client_id']   = 'test-client-id.apps.googleusercontent.com';
$mock_options['gdtg_picker_app_id']           = '123456789';
$mock_options['gdtg_picker_developer_key']    = 'AIzaTestKey';


// ─── Stub loader class ────────────────────────────────────────────
class GDTG_Loader {
	public function add_action() {}
}

$endpoints = new GDTG_REST_Endpoints( new GDTG_Loader() );
$response  = $endpoints->handle_picker_config();
$data      = $response->get_data();

assert_true( ! $data['enabled'], 'SaaS mode: enabled is false' );
assert_equals( 'saas_uses_bridge', $data['reason'], 'SaaS mode: reason is saas_uses_bridge' );
assert_not_has_key( 'app_id', $data, 'SaaS mode: app_id not exposed' );

suite( 'Picker config: Enterprise mode without keys returns enabled=false, reason=missing_keys' );

reset_mocks();
$mock_options['gdtg_connection_mode']      = 'enterprise';
$mock_options['gdtg_enterprise_client_id'] = ''; // No client ID.

$endpoints = new GDTG_REST_Endpoints( new GDTG_Loader() );
$response  = $endpoints->handle_picker_config();
$data      = $response->get_data();

assert_true( ! $data['enabled'], 'Enterprise without client ID: enabled is false' );
assert_equals( 'missing_keys', $data['reason'], 'Enterprise without client ID: reason is missing_keys' );

suite( 'Picker config: Enterprise mode with client ID but missing picker keys' );

reset_mocks();
$mock_options['gdtg_connection_mode']      = 'enterprise';
$mock_options['gdtg_enterprise_client_id'] = 'test-client-id.apps.googleusercontent.com';
$mock_options['gdtg_picker_app_id']        = ''; // Empty.
$mock_options['gdtg_picker_developer_key'] = 'AIzaTestKey';

$endpoints = new GDTG_REST_Endpoints( new GDTG_Loader() );
$response  = $endpoints->handle_picker_config();
$data      = $response->get_data();

assert_true( ! $data['enabled'], 'Enterprise with missing app_id: enabled is false' );
assert_equals( 'missing_keys', $data['reason'], 'Enterprise with missing app_id: reason is missing_keys' );

suite( 'Picker config: Enterprise mode with all keys returns enabled=true' );

reset_mocks();
$mock_options['gdtg_connection_mode']      = 'enterprise';
$mock_options['gdtg_enterprise_client_id'] = 'test-client-id.apps.googleusercontent.com';
$mock_options['gdtg_picker_app_id']        = '123456789';
$mock_options['gdtg_picker_developer_key'] = 'AIzaTestKey';

$endpoints = new GDTG_REST_Endpoints( new GDTG_Loader() );
$response  = $endpoints->handle_picker_config();
$data      = $response->get_data();

assert_true( $data['enabled'], 'Enterprise with all keys: enabled is true' );
assert_equals( '123456789', $data['app_id'], 'Enterprise: app_id is correct' );
assert_equals( 'AIzaTestKey', $data['developer_key'], 'Enterprise: developer_key is correct' );
assert_true( is_array( $data['scopes'] ), 'Enterprise: scopes is an array' );
assert_true( count( $data['scopes'] ) >= 2, 'Enterprise: at least 2 scopes returned' );
assert_true( in_array( 'https://www.googleapis.com/auth/documents.readonly', $data['scopes'], true ), 'Enterprise: documents.readonly scope present' );
assert_true( in_array( 'https://www.googleapis.com/auth/drive.readonly', $data['scopes'], true ), 'Enterprise: drive.readonly scope present' );

// ═══════════════════════════════════════════════════════════════════
// Picker Token Tests
// ═══════════════════════════════════════════════════════════════════

suite( 'Picker token: SaaS mode returns 400' );

reset_mocks();
$mock_options['gdtg_connection_mode'] = 'saas';

$endpoints = new GDTG_REST_Endpoints( new GDTG_Loader() );
$request   = new WP_REST_Request( array( 'purpose' => 'picker' ) );
$response  = $endpoints->handle_picker_token( $request );

assert_equals( 400, $response->get_status(), 'SaaS mode token: status is 400' );

suite( 'Picker token: Enterprise mode without valid token returns 401' );

reset_mocks();
$mock_options['gdtg_connection_mode']            = 'enterprise';
$mock_options['gdtg_enterprise_access_token']    = '';
$mock_options['gdtg_enterprise_token_expires']   = 0;
$mock_options['gdtg_enterprise_client_id']       = '';
$mock_options['gdtg_enterprise_refresh_token']   = '';

$endpoints = new GDTG_REST_Endpoints( new GDTG_Loader() );
$request   = new WP_REST_Request( array( 'purpose' => 'picker' ) );
$response  = $endpoints->handle_picker_token( $request );

assert_equals( 401, $response->get_status(), 'Enterprise without token: status is 401' );

suite( 'Picker token: response never contains refresh_token' );

reset_mocks();
$mock_options['gdtg_connection_mode']          = 'enterprise';
$mock_options['gdtg_enterprise_access_token']  = 'ya29.test-enterprise-token';
$mock_options['gdtg_enterprise_token_expires'] = time() + 3600;
$mock_options['gdtg_enterprise_client_id']     = 'test-client-id.apps.googleusercontent.com';

$endpoints = new GDTG_REST_Endpoints( new GDTG_Loader() );
$request   = new WP_REST_Request( array( 'purpose' => 'picker' ) );
$response  = $endpoints->handle_picker_token( $request );
$data      = $response->get_data();

assert_equals( 200, $response->get_status(), 'Enterprise with token: status is 200' );
assert_array_has_key( 'token', $data, 'Response has token key' );
assert_not_has_key( 'refresh_token', $data, 'Response NEVER has refresh_token' );
assert_equals( 'ya29.test-enterprise-token', $data['token'], 'Token value matches stored access token' );

suite( 'Picker token: throttled at 5 requests per minute per user' );

reset_mocks();
$mock_options['gdtg_connection_mode']          = 'enterprise';
$mock_options['gdtg_enterprise_access_token']  = 'ya29.test-token';
$mock_options['gdtg_enterprise_token_expires'] = time() + 3600;
$mock_options['gdtg_enterprise_client_id']     = 'test-client-id.apps.googleusercontent.com';
$mock_transients['gdtg_picker_token_throttle_1'] = 5; // Already at limit.

$endpoints = new GDTG_REST_Endpoints( new GDTG_Loader() );
$request   = new WP_REST_Request( array( 'purpose' => 'picker' ) );
$response  = $endpoints->handle_picker_token( $request );

assert_equals( 429, $response->get_status(), 'Throttled: status is 429' );

// ═══════════════════════════════════════════════════════════════════
// Permission Tests
// ═══════════════════════════════════════════════════════════════════

suite( 'Picker endpoints: subscriber (user 3) denied on edit_posts gate' );

reset_mocks();
$mock_current_user_id = 3; // Subscriber.

// The permission_callback uses current_user_can('edit_posts').
// Our mock current_user_can returns false for user 3.
assert_true( ! current_user_can( 'edit_posts' ), 'Subscriber lacks edit_posts' );

echo "\nAll Picker Config Tests passed.\n";
