<?php
/**
 * Standalone API token lifecycle regression harness.
 *
 * Exercises mode routing, refresh persistence, expiry skew, and forced refresh
 * behavior in GDTG_API. Run with: php tests/api-token-test.php
 */

echo "Running API Token Regression Tests...\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

$GLOBALS['mock_options'] = array();
$GLOBALS['mock_http_post_queue'] = array();
$GLOBALS['mock_http_post_calls'] = array();

if ( ! class_exists( 'WP_Error' ) ) {
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
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	return $text;
}
function sanitize_text_field( $field ) {
	return trim( strip_tags( (string) $field ) );
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-api-token-salt-' . $scheme;
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function get_option( $key, $default = false ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}

function update_option( $key, $value ) {
	global $mock_options;
	$mock_options[ $key ] = $value;
	return true;
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

function wp_remote_post( $url, $args ) {
	global $mock_http_post_queue, $mock_http_post_calls;
	$mock_http_post_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( empty( $mock_http_post_queue ) ) {
		return array(
			'response' => array( 'code' => 500 ),
			'body'     => json_encode( array( 'error' => 'queue empty' ) ),
		);
	}
	return array_shift( $mock_http_post_queue );
}

function wp_remote_get( $url, $args ) {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '{}',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}


// Match real plugin bootstrap: packaged default constant.
if ( ! defined( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT' ) ) {
	define( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT', 'https://draftsync.cortisol.icu' );
}
require_once __DIR__ . '/../includes/class-gdtg-secret-store.php';
require_once __DIR__ . '/../includes/class-gdtg-api.php';

function assert_true( $condition, $message ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	if ( $condition ) {
		$pass_count++;
		echo "PASS: {$message}\n";
		return;
	}
	$fail_count++;
	echo "FAIL: {$message}\n";
}

function assert_same( $expected, $actual, $message ) {
	assert_true( $expected === $actual, $message . " (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ')' );
}

function reset_api_mocks() {
	global $mock_options, $mock_http_post_queue, $mock_http_post_calls;
	$mock_options = array();
	$mock_http_post_queue = array();
	$mock_http_post_calls = array();
}

function queue_post_response( $code, $body_array ) {
	global $mock_http_post_queue;
	$mock_http_post_queue[] = array(
		'response' => array( 'code' => $code ),
		'body'     => json_encode( $body_array ),
	);
}

function invoke_private_method( $object, $method_name, $args = array() ) {
	$method = new ReflectionMethod( get_class( $object ), $method_name );
	$method->setAccessible( true );
	return $method->invokeArgs( $object, $args );
}

$api = new GDTG_API();

reset_api_mocks();
$mock_options['gdtg_saas_access_token'] = 'saas-cached';
$mock_options['gdtg_saas_token_expires'] = time() + 3600;
assert_same( 'saas-cached', $api->get_access_token(), 'default mode routes to cached SaaS token' );
assert_same( 0, count( $mock_http_post_calls ), 'cached SaaS token does not trigger refresh' );

reset_api_mocks();
$mock_options['gdtg_connection_mode'] = 'enterprise';
$mock_options['gdtg_enterprise_access_token'] = 'enterprise-cached';
$mock_options['gdtg_enterprise_token_expires'] = time() + 3600;
assert_same( 'enterprise-cached', $api->get_access_token(), 'enterprise mode routes to enterprise cached token' );
assert_same( 0, count( $mock_http_post_calls ), 'cached enterprise token does not trigger refresh' );

reset_api_mocks();
$mock_options['gdtg_saas_refresh_token'] = 'refresh-me';
$mock_options['gdtg_saas_access_token'] = 'stale';
$mock_options['gdtg_saas_token_expires'] = time() + 30;
queue_post_response( 200, array( 'access_token' => 'saas-refreshed', 'expires_in' => 120 ) );
assert_same( 'saas-refreshed', invoke_private_method( $api, 'get_saas_access_token' ), 'SaaS getter refreshes inside 60-second skew window' );
assert_same( 1, count( $mock_http_post_calls ), 'SaaS getter performs one refresh request inside skew window' );
assert_same( 'saas-refreshed', $mock_options['gdtg_saas_access_token'], 'SaaS refresh stores new access token' );
assert_true( $mock_options['gdtg_saas_token_expires'] > time(), 'SaaS refresh stores future expiry' );

reset_api_mocks();
$mock_options['gdtg_saas_access_token'] = 'still-valid';
$mock_options['gdtg_saas_token_expires'] = time() + 120;
assert_same( 'still-valid', invoke_private_method( $api, 'get_saas_access_token' ), 'SaaS getter keeps cached token outside skew window' );
assert_same( 0, count( $mock_http_post_calls ), 'SaaS getter skips refresh outside skew window' );

reset_api_mocks();
$mock_options['gdtg_saas_refresh_token'] = 'refresh-empty';
$mock_options['gdtg_saas_access_token'] = '';
$mock_options['gdtg_saas_token_expires'] = time() + 3600;
queue_post_response( 200, array( 'access_token' => 'saas-from-empty', 'expires_in' => 3600 ) );
assert_same( 'saas-from-empty', invoke_private_method( $api, 'get_saas_access_token' ), 'empty SaaS access token forces refresh' );
assert_same( 1, count( $mock_http_post_calls ), 'empty SaaS access token triggers one refresh request' );

reset_api_mocks();
assert_true( false === invoke_private_method( $api, 'refresh_saas_token' ), 'missing SaaS refresh token returns false' );
assert_same( 0, count( $mock_http_post_calls ), 'missing SaaS refresh token skips HTTP call' );

reset_api_mocks();
$mock_options['gdtg_saas_refresh_token'] = 'bridge-refresh';
queue_post_response( 200, array( 'access_token' => 'saas-success', 'expires_in' => 600, 'refresh_token' => 'rotated-refresh' ) );
assert_true( true === invoke_private_method( $api, 'refresh_saas_token' ), 'successful SaaS refresh returns true' );
assert_same( 'saas-success', $mock_options['gdtg_saas_access_token'], 'successful SaaS refresh stores access token' );
assert_same( 'rotated-refresh', $mock_options['gdtg_saas_refresh_token'], 'successful SaaS refresh stores rotated refresh token' );
assert_true( $mock_options['gdtg_saas_token_expires'] >= time() + 590, 'successful SaaS refresh stores expected expiry horizon' );
assert_same( GDTG_API::saas_bridge_base_url() . '/api/refresh', $mock_http_post_calls[0]['url'], 'SaaS refresh hits bridge endpoint' );

reset_api_mocks();
$mock_options['gdtg_saas_refresh_token'] = 'default-expiry';
queue_post_response( 200, array( 'access_token' => 'saas-default-expiry' ) );
assert_true( true === invoke_private_method( $api, 'refresh_saas_token' ), 'SaaS refresh succeeds without expires_in' );
assert_true( $mock_options['gdtg_saas_token_expires'] >= time() + 3590, 'SaaS refresh falls back to 3600-second expiry' );

reset_api_mocks();
$mock_options['gdtg_saas_refresh_token'] = 'bad-refresh';
queue_post_response( 400, array( 'error' => 'invalid_grant' ) );
assert_true( false === invoke_private_method( $api, 'refresh_saas_token' ), 'non-200 SaaS refresh returns false' );
assert_true( ! isset( $mock_options['gdtg_saas_access_token'] ), 'failed SaaS refresh does not persist access token' );

reset_api_mocks();
assert_true( false === invoke_private_method( $api, 'refresh_enterprise_token' ), 'enterprise refresh requires all credentials' );
assert_same( 0, count( $mock_http_post_calls ), 'enterprise refresh without credentials skips HTTP' );

reset_api_mocks();
$mock_options['gdtg_enterprise_client_id'] = 'client-id';
$mock_options['gdtg_enterprise_client_secret'] = 'client-secret';
$mock_options['gdtg_enterprise_refresh_token'] = 'enterprise-refresh';
queue_post_response( 200, array( 'access_token' => 'enterprise-success', 'expires_in' => 900 ) );
assert_true( true === invoke_private_method( $api, 'refresh_enterprise_token' ), 'successful enterprise refresh returns true' );
assert_same( 'enterprise-success', GDTG_Secret_Store::get( 'gdtg_enterprise_access_token' ), 'successful enterprise refresh stores access token (decrypted)' );
assert_true( $mock_options['gdtg_enterprise_token_expires'] >= time() + 890, 'successful enterprise refresh stores expiry' );
assert_same( 'https://oauth2.googleapis.com/token', $mock_http_post_calls[0]['url'], 'enterprise refresh hits Google OAuth token endpoint' );

reset_api_mocks();
$mock_options['gdtg_enterprise_access_token'] = 'enterprise-skew';
$mock_options['gdtg_enterprise_token_expires'] = time() + 30;
$mock_options['gdtg_enterprise_client_id'] = 'client-id';
$mock_options['gdtg_enterprise_client_secret'] = 'client-secret';
$mock_options['gdtg_enterprise_refresh_token'] = 'enterprise-refresh';
queue_post_response( 200, array( 'access_token' => 'enterprise-refreshed', 'expires_in' => 300 ) );
assert_same( 'enterprise-refreshed', invoke_private_method( $api, 'get_enterprise_access_token' ), 'enterprise getter refreshes inside 60-second skew window' );
assert_same( 1, count( $mock_http_post_calls ), 'enterprise getter performs one refresh request inside skew window' );

reset_api_mocks();
$mock_options['gdtg_enterprise_access_token'] = 'enterprise-valid';
$mock_options['gdtg_enterprise_token_expires'] = time() + 120;
assert_same( 'enterprise-valid', invoke_private_method( $api, 'get_enterprise_access_token' ), 'enterprise getter keeps cached token outside skew window' );
assert_same( 0, count( $mock_http_post_calls ), 'enterprise getter skips refresh outside skew window' );

reset_api_mocks();
$mock_options['gdtg_connection_mode'] = 'saas';
$mock_options['gdtg_saas_refresh_token'] = 'force-saas';
$mock_options['gdtg_saas_token_expires'] = time() + 5000;
queue_post_response( 200, array( 'access_token' => 'saas-forced', 'expires_in' => 180 ) );
$api->force_refresh_token();
assert_same( 'saas-forced', $mock_options['gdtg_saas_access_token'], 'force_refresh_token refreshes active SaaS token' );
assert_true( ! isset( $mock_options['gdtg_enterprise_token_expires'] ), 'force_refresh_token in SaaS mode does not touch enterprise expiry' );

reset_api_mocks();
$mock_options['gdtg_connection_mode'] = 'enterprise';
$mock_options['gdtg_enterprise_client_id'] = 'client-id';
$mock_options['gdtg_enterprise_client_secret'] = 'client-secret';
$mock_options['gdtg_enterprise_refresh_token'] = 'enterprise-force';
$mock_options['gdtg_enterprise_token_expires'] = time() + 5000;
queue_post_response( 200, array( 'access_token' => 'enterprise-forced', 'expires_in' => 240 ) );
$api->force_refresh_token();
assert_same( 'enterprise-forced', GDTG_Secret_Store::get( 'gdtg_enterprise_access_token' ), 'force_refresh_token refreshes active enterprise token (decrypted)' );
assert_true( ! isset( $mock_options['gdtg_saas_token_expires'] ), 'force_refresh_token in enterprise mode does not touch SaaS expiry' );

reset_api_mocks();
$mock_options['gdtg_saas_refresh_token'] = 'broken-refresh';
queue_post_response( 400, array( 'error' => 'invalid_grant' ) );
assert_true( false === invoke_private_method( $api, 'get_saas_access_token' ), 'SaaS getter returns false when refresh fails' );

reset_api_mocks();
$mock_options['gdtg_enterprise_client_id'] = 'client-id';
$mock_options['gdtg_enterprise_client_secret'] = 'client-secret';
$mock_options['gdtg_enterprise_refresh_token'] = 'enterprise-refresh';
queue_post_response( 400, array( 'error' => 'invalid_grant' ) );
assert_true( false === invoke_private_method( $api, 'get_enterprise_access_token' ), 'enterprise getter returns false when refresh fails' );


// ── Phase 3: Retry-After 429 handling ──

// Test: compute_429_delay honors numeric Retry-After header
reset_api_mocks();
$response = array('response' => array('code' => 429), 'headers' => array('retry-after' => '5'));
$delay = invoke_private_method($api, 'compute_429_delay', array($response, 1));
assert_same(5, $delay, 'Retry-After: 5 surfaces correctly (max of 5 and backoff=1)');

// Test: compute_429_delay uses max of Retry-After and exponential backoff
reset_api_mocks();
$response2 = array('response' => array('code' => 429), 'headers' => array('retry-after' => '2'));
$delay2 = invoke_private_method($api, 'compute_429_delay', array($response2, 3)); // backoff for attempt 3: 1 * 2^2 = 4
assert_same(4, $delay2, 'Retry-After: 2 with backoff=4 delays 4 seconds (max)');

// Test: compute_429_delay caps at 30 seconds
reset_api_mocks();
$response3 = array('response' => array('code' => 429), 'headers' => array('retry-after' => '120'));
$delay3 = invoke_private_method($api, 'compute_429_delay', array($response3, 1));
assert_same(30, $delay3, 'Retry-After: 120 capped at 30 seconds');

// Test: compute_429_delay with HTTP-date Retry-After
reset_api_mocks();
$future_time = gmdate('D, d M Y H:i:s \G\M\T', time() + 7);
$response4 = array('response' => array('code' => 429), 'headers' => array('retry-after' => $future_time));
$delay4 = invoke_private_method($api, 'compute_429_delay', array($response4, 1));
assert_true($delay4 >= 6 && $delay4 <= 30, 'HTTP-date Retry-After yields reasonable delay (got ' . $delay4 . ')');

// Test: compute_429_delay with no Retry-After header
reset_api_mocks();
$response5 = array('response' => array('code' => 429), 'headers' => array());
$delay5 = invoke_private_method($api, 'compute_429_delay', array($response5, 2)); // backoff for attempt 2: 1 * 2^1 = 2
assert_same(2, $delay5, 'Missing Retry-After falls back to exponential backoff');
echo "\nTests run: {$test_count}\n";
echo "Passed: {$pass_count}\n";
echo "Failed: {$fail_count}\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}

echo "\nAPI token regression tests passed.\n";
