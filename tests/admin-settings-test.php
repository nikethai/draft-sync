<?php
/**
 * Focused admin settings regression tests for privacy policy content and bridge availability caching.
 */

echo "Running Admin Settings Regression Tests...\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['mock_transients'] = array();
$GLOBALS['mock_privacy_calls'] = array();
$GLOBALS['mock_bridge_base_url'] = 'https://bridge.example.com';

function __( $text, $domain = 'default' ) {
	return $text;
}

function wp_kses_post( $content ) {
	return $content;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function set_transient( $key, $value, $expiration ) {
	$GLOBALS['mock_transients'][ $key ] = $value;
	return true;
}

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['mock_transients'] ) ? $GLOBALS['mock_transients'][ $key ] : false;
}

function wp_add_privacy_policy_content( $plugin_name, $content ) {
	$GLOBALS['mock_privacy_calls'][] = array(
		'plugin_name' => $plugin_name,
		'content'     => $content,
	);
}

class GDTG_API {
	public static function saas_bridge_base_url() {
		return $GLOBALS['mock_bridge_base_url'];
	}
}

class GDTG_Test_Loader {
	public $actions = array();

	public function add_action( $hook, $object, $method ) {
		$this->actions[] = array(
			'hook'   => $hook,
			'method' => $method,
		);
	}
}

require_once __DIR__ . '/../includes/class-gdtg-admin.php';

$test_count = 0;
$pass_count = 0;
$fail_count = 0;

function assert_true( $condition, $message ) {
	global $test_count, $pass_count, $fail_count;
	++$test_count;
	if ( $condition ) {
		++$pass_count;
		echo "PASS: {$message}\n";
		return;
	}

	++$fail_count;
	echo "FAIL: {$message}\n";
}

function assert_same( $expected, $actual, $message ) {
	assert_true(
		$expected === $actual,
		$message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
	);
}

function invoke_private_method( $object, $method_name, $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method_name );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

$loader = new GDTG_Test_Loader();
$admin  = new GDTG_Admin( $loader );

$registered_methods = array_column( $loader->actions, 'method' );
assert_true( in_array( 'register_privacy_policy_content', $registered_methods, true ), 'Admin registers privacy policy hook' );

$GLOBALS['mock_privacy_calls'] = array();
$admin->register_privacy_policy_content();
assert_same( 1, count( $GLOBALS['mock_privacy_calls'] ), 'Privacy policy content registered once' );
if ( ! empty( $GLOBALS['mock_privacy_calls'] ) ) {
	$privacy_call = $GLOBALS['mock_privacy_calls'][0];
	assert_same( 'DraftSync', $privacy_call['plugin_name'], 'Privacy policy registration uses plugin name' );
	assert_true( false !== strpos( $privacy_call['content'], 'DraftSync SaaS OAuth bridge' ), 'Privacy policy mentions SaaS OAuth bridge' );
	assert_true( false !== strpos( $privacy_call['content'], 'docs.googleapis.com' ), 'Privacy policy mentions Google APIs' );
	assert_true( false !== strpos( $privacy_call['content'], 'stored locally in your WordPress database' ), 'Privacy policy mentions local token storage' );
}

$GLOBALS['mock_bridge_base_url'] = 'https://bridge.example.com';
$GLOBALS['mock_transients'] = array(
	'gdtg_saas_bridge_available_' . md5( 'bridge.example.com' ) => '1',
);
assert_true( true === invoke_private_method( $admin, 'is_saas_bridge_available' ), 'Cached bridge availability true returns true' );

$GLOBALS['mock_transients'] = array(
	'gdtg_saas_bridge_available_' . md5( 'bridge.example.com' ) => '0',
);
assert_true( false === invoke_private_method( $admin, 'is_saas_bridge_available' ), 'Cached bridge availability false returns false' );

$GLOBALS['mock_bridge_base_url'] = 'not-a-url';
$GLOBALS['mock_transients'] = array();
assert_true( false === invoke_private_method( $admin, 'is_saas_bridge_available' ), 'Invalid bridge URL host returns false without DNS lookup' );

echo "\nTests run: {$test_count}\n";
echo "Passed: {$pass_count}\n";
echo "Failed: {$fail_count}\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}

echo "\nAdmin settings regression tests passed.\n";
