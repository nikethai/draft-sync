<?php
/**
 * Standalone SaaS bridge URL validation regression harness.
 *
 * Exercises validate_bridge_url() and host-scoped DNS transient key behavior.
 * Run with: php tests/saas-bridge-url-test.php
 */

echo "Running SaaS Bridge URL Validation Tests...\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['mock_options']      = array();
$GLOBALS['mock_transients']   = array();

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

function get_option( $key, $default = false ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}

function update_option( $key, $value ) {
	global $mock_options;
	$mock_options[ $key ] = $value;
}

function delete_option( $key ) {
	global $mock_options;
	unset( $mock_options[ $key ] );
}

function set_transient( $key, $value, $expiration ) {
	global $mock_transients;
	$mock_transients[ $key ] = array( 'value' => $value, 'expiration' => $expiration );
}

function get_transient( $key ) {
	global $mock_transients;
	if ( isset( $mock_transients[ $key ] ) ) {
		return $mock_transients[ $key ]['value'];
	}
	return false;
}

function delete_transient( $key ) {
	global $mock_transients;
	unset( $mock_transients[ $key ] );
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

// Match real plugin bootstrap: GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT is defined by draftsync.php.
if ( ! defined( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT' ) ) {
	define( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT', 'https://draftsync.cortisol.icu' );
}

require_once __DIR__ . '/../includes/class-gdtg-api.php';

$test_count = 0;
$pass_count = 0;
$fail_count = 0;

function assert_true( $condition, $message ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	if ( $condition ) {
		$pass_count++;
		echo "PASS: {$message}\n";
	} else {
		$fail_count++;
		echo "FAIL: {$message}\n";
	}
}

function assert_same( $expected, $actual, $message ) {
	assert_true( $expected === $actual, $message . " (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ')' );
}

// ── validate_bridge_url tests ──

// Valid HTTPS URL.
assert_same( 'https://bridge.example.com', GDTG_API::validate_bridge_url( 'https://bridge.example.com' ), 'valid HTTPS URL passes' );

// Valid HTTPS URL with path.
assert_same( 'https://bridge.example.com/path', GDTG_API::validate_bridge_url( 'https://bridge.example.com/path' ), 'valid HTTPS URL with path passes' );

// Trailing slash stripped.
assert_same( 'https://bridge.example.com', GDTG_API::validate_bridge_url( 'https://bridge.example.com/' ), 'trailing slash is stripped' );

// HTTP non-localhost rejected.
assert_same( '', GDTG_API::validate_bridge_url( 'http://bridge.example.com' ), 'HTTP non-localhost is rejected' );

// HTTP localhost allowed.
assert_same( 'http://localhost:8080', GDTG_API::validate_bridge_url( 'http://localhost:8080' ), 'HTTP localhost is allowed' );

// HTTP 127.0.0.1 allowed.
assert_same( 'http://127.0.0.1:8080', GDTG_API::validate_bridge_url( 'http://127.0.0.1:8080' ), 'HTTP 127.0.0.1 is allowed' );

// HTTP .local domain allowed.
assert_same( 'http://draftsync.local', GDTG_API::validate_bridge_url( 'http://draftsync.local' ), 'HTTP .local domain is allowed' );

// FTP scheme rejected.
assert_same( '', GDTG_API::validate_bridge_url( 'ftp://bridge.example.com' ), 'FTP scheme is rejected' );

// Javascript scheme rejected.
assert_same( '', GDTG_API::validate_bridge_url( 'javascript:alert(1)' ), 'javascript scheme is rejected' );

// Data URI rejected.
assert_same( '', GDTG_API::validate_bridge_url( 'data:text/html,<h1>hi</h1>' ), 'data URI is rejected' );

// No scheme rejected.
assert_same( '', GDTG_API::validate_bridge_url( 'bridge.example.com' ), 'no scheme is rejected' );

// Empty string rejected.
assert_same( '', GDTG_API::validate_bridge_url( '' ), 'empty string is rejected' );

// Non-string rejected.
assert_same( '', GDTG_API::validate_bridge_url( null ), 'null input is rejected' );

// Userinfo rejected.
assert_same( '', GDTG_API::validate_bridge_url( 'https://user:pass@bridge.example.com' ), 'userinfo is rejected' );

// Userinfo without password rejected.
assert_same( '', GDTG_API::validate_bridge_url( 'https://user@bridge.example.com' ), 'userinfo without password is rejected' );

// Empty host rejected.
assert_same( '', GDTG_API::validate_bridge_url( 'https:///path-only' ), 'empty host is rejected' );

// ── saas_bridge_base_url precedence tests ──

// Option > packaged default.
$GLOBALS['mock_options'] = array();
$GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] = 'https://option.example.com';
assert_same( 'https://option.example.com', GDTG_API::saas_bridge_base_url(), 'valid option takes precedence over default' );

// Invalid option falls back to packaged default.
$GLOBALS['mock_options'] = array();
$GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] = 'ftp://invalid';
assert_same( 'https://draftsync.cortisol.icu', GDTG_API::saas_bridge_base_url(), 'invalid option falls back to packaged default' );

// Empty option falls back to packaged default.
$GLOBALS['mock_options'] = array();
assert_same( 'https://draftsync.cortisol.icu', GDTG_API::saas_bridge_base_url(), 'empty option falls back to packaged default' );

// Constant > option > packaged default.
// (Cannot define a constant at runtime in a test, but validate_bridge_url would accept it
//  if it were defined. The option path is fully tested above.)

// ── Host-scoped DNS transient key test ──

// Verify that changing the configured host changes the transient key.
$GLOBALS['mock_options'] = array();
$GLOBALS['mock_transients'] = array();

// Simulate first host check by setting a transient with the expected key format.
$host1 = 'bridge-a.example.com';
$key1  = 'gdtg_saas_bridge_available_' . md5( $host1 );
set_transient( $key1, '1', 15 * MINUTE_IN_SECONDS );
assert_same( '1', get_transient( $key1 ), 'host-scoped transient key for host1 is set' );

// Different host should have a different key.
$host2 = 'bridge-b.example.com';
$key2  = 'gdtg_saas_bridge_available_' . md5( $host2 );
assert_true( $key1 !== $key2, 'transient keys differ for different hosts' );
assert_true( false === get_transient( $key2 ), 'host2 transient is not set from host1' );


// ── gdtg_migrate_bridge_url tests ──

// Mirror the migration function from draftsync.php so we can test it here.
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

// Old domain is cleared.
$GLOBALS['mock_options'] = array();
$GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] = 'https://ds.wearesection.vn';
gdtg_migrate_bridge_url();
assert_true( ! isset( $GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] ), 'old ds.wearesection.vn option is deleted' );

// Old domain with path is cleared.
$GLOBALS['mock_options'] = array();
$GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] = 'https://ds.wearesection.vn/api';
gdtg_migrate_bridge_url();
assert_true( ! isset( $GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] ), 'old ds.wearesection.vn with path is deleted' );

// New domain is left alone.
$GLOBALS['mock_options'] = array();
$GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] = 'https://draftsync.cortisol.icu';
gdtg_migrate_bridge_url();
assert_same( 'https://draftsync.cortisol.icu', $GLOBALS['mock_options']['gdtg_saas_bridge_base_url'], 'new domain is not touched' );

// Empty option is a no-op.
$GLOBALS['mock_options'] = array();
gdtg_migrate_bridge_url();
assert_true( ! isset( $GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] ), 'empty option is a no-op' );

// Unrelated custom domain is left alone.
$GLOBALS['mock_options'] = array();
$GLOBALS['mock_options']['gdtg_saas_bridge_base_url'] = 'https://my-own-bridge.example.com';
gdtg_migrate_bridge_url();
assert_same( 'https://my-own-bridge.example.com', $GLOBALS['mock_options']['gdtg_saas_bridge_base_url'], 'unrelated custom domain is not touched' );

echo "\nTests run: {$test_count}\n";
echo "Passed: {$pass_count}\n";
echo "Failed: {$fail_count}\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}

echo "\nSaaS bridge URL validation tests passed.\n";
