<?php
/**
 * Standalone OAuth state regression harness.
 *
 * Exercises CSRF state generation and validation in GDTG_Admin without a full
 * WordPress bootstrap. Run with: php tests/oauth-state-test.php
 */

echo "Running OAuth State Regression Tests...\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['mock_transients'] = array();
$GLOBALS['mock_password_sequence'] = array();

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	global $mock_password_sequence;
	if ( ! empty( $mock_password_sequence ) ) {
		return array_shift( $mock_password_sequence );
	}
	return str_repeat( 's', $length );
}

function set_transient( $key, $value, $expiration ) {
	global $mock_transients;
	$mock_transients[ $key ] = array(
		'value'      => $value,
		'expiration' => $expiration,
	);
	return true;
}

function get_transient( $key ) {
	global $mock_transients;
	if ( ! array_key_exists( $key, $mock_transients ) ) {
		return false;
	}
	return $mock_transients[ $key ]['value'];
}

function delete_transient( $key ) {
	global $mock_transients;
	unset( $mock_transients[ $key ] );
	return true;
}

class GDTG_Test_Loader {
	public $actions = array();
	public function add_action( $hook, $component, $callback ) {
		$this->actions[] = array( $hook, $component, $callback );
	}
}

require_once __DIR__ . '/../includes/class-gdtg-admin.php';

$test_count = 0;
$pass_count = 0;
$fail_count = 0;

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

function invoke_private_method( $object, $method_name, $args = array() ) {
	$method = new ReflectionMethod( get_class( $object ), $method_name );
	$method->setAccessible( true );
	return $method->invokeArgs( $object, $args );
}

$loader = new GDTG_Test_Loader();
$admin  = new GDTG_Admin( $loader );

assert_same( 7, count( $loader->actions ), 'admin registers expected core hooks' );

$mock_password_sequence = array( '12345678901234567890123456789012' );
$state = invoke_private_method( $admin, 'generate_oauth_state', array( 'saas' ) );
$state_key = 'gdtg_oauth_state_saas_' . $state;
assert_same( '12345678901234567890123456789012', $state, 'generate_oauth_state returns 32-character token' );
assert_true( isset( $mock_transients[ $state_key ] ), 'state generation stores transient' );
if ( isset( $mock_transients[ $state_key ] ) ) {
	assert_same( 'saas', $mock_transients[ $state_key ]['value'], 'state transient stores flow value' );
	assert_same( 15 * MINUTE_IN_SECONDS, $mock_transients[ $state_key ]['expiration'], 'state transient stores 15-minute TTL' );
}

$single_use_state = 'abcdefghijklmnopqrstuvwx12345678';
set_transient( 'gdtg_oauth_state_saas_' . $single_use_state, 'saas', 15 * MINUTE_IN_SECONDS );
assert_true( true === invoke_private_method( $admin, 'validate_oauth_state', array( $single_use_state, 'saas' ) ), 'valid state passes once for explicit flow' );
assert_true( ! isset( $mock_transients[ 'gdtg_oauth_state_saas_' . $single_use_state ] ), 'valid state is deleted after successful validation' );
assert_true( false === invoke_private_method( $admin, 'validate_oauth_state', array( $single_use_state, 'saas' ) ), 'replayed state fails after consumption' );

$expired_state = 'expiredstatevalue1234567890abcd';
assert_true( false === invoke_private_method( $admin, 'validate_oauth_state', array( $expired_state, 'saas' ) ), 'missing/expired state fails validation' );

assert_true( false === invoke_private_method( $admin, 'validate_oauth_state', array( '', 'saas' ) ), 'empty state fails validation' );
assert_true( false === invoke_private_method( $admin, 'validate_oauth_state', array( null, 'saas' ) ), 'non-string state fails validation' );

$legacy_state = 'legacyfallbackstate1234567890abcd';
set_transient( 'gdtg_oauth_state_enterprise_' . $legacy_state, 'enterprise', 15 * MINUTE_IN_SECONDS );
assert_true( true === invoke_private_method( $admin, 'validate_oauth_state', array( $legacy_state ) ), 'legacy fallback validates existing enterprise state without flow argument' );
assert_true( ! isset( $mock_transients[ 'gdtg_oauth_state_enterprise_' . $legacy_state ] ), 'legacy fallback deletes consumed enterprise state' );

$mismatch_state = 'mismatchstatevalue1234567890abcd';
set_transient( 'gdtg_oauth_state_enterprise_' . $mismatch_state, 'enterprise', 15 * MINUTE_IN_SECONDS );
assert_true( false === invoke_private_method( $admin, 'validate_oauth_state', array( $mismatch_state, 'saas' ) ), 'mismatched explicit flow fails validation' );
assert_true( isset( $mock_transients[ 'gdtg_oauth_state_enterprise_' . $mismatch_state ] ), 'failed explicit-flow validation does not consume other-flow state' );

echo "\nTests run: {$test_count}\n";
echo "Passed: {$pass_count}\n";
echo "Failed: {$fail_count}\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}

echo "\nOAuth state regression tests passed.\n";
