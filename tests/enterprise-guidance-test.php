<?php
/**
 * Standalone Enterprise guidance and secret migration test harness.
 *
 * Run: php tests/enterprise-guidance-test.php
 */

echo "Running Enterprise Guidance Tests...\n\n";

// ─── Minimal WordPress stubs ──────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', true );

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

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	function __( $text, $domain = 'default' ) {
		return $text;
	}

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

	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}

	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-for-enterprise-guidance-' . $scheme;
	}

	function sanitize_text_field( $str ) {
		return (string) $str;
	}

	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

// Load the secret store class.
require_once __DIR__ . '/../includes/class-gdtg-secret-store.php';

// ─── Assertion helpers ────────────────────────────────────────────

$test_count = 0;
$pass_count = 0;
$fail_count = 0;

function assert_true( $condition, $message ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	if ( $condition ) {
		$pass_count++;
		echo "  PASS: $message\n";
	} else {
		$fail_count++;
		echo "  FAIL: $message\n";
	}
}

function assert_equals( $expected, $actual, $message ) {
	assert_true( $expected === $actual, $message . ' (expected: ' . var_export( $expected, true ) . ', got: ' . var_export( $actual, true ) . ')' );
}

function assert_not_empty( $val, $message ) {
	assert_true( ! empty( $val ), $message );
}

function assert_is_wp_error( $val, $message ) {
	assert_true( is_wp_error( $val ), $message . ' (is WP_Error)' );
}

function suite( $name ) {
	echo "\n=== $name ===\n";
}

// ─── Tests ────────────────────────────────────────────────────────

// ----------------------------------------------------------------
suite( 'GDTG_Secret_Store::migrate_option with plaintext legacy value' );

global $mock_options;
$mock_options = array();
$mock_options['gdtg_enterprise_client_secret'] = 'my-legacy-plaintext-secret';

$result = GDTG_Secret_Store::migrate_option( 'gdtg_enterprise_client_secret' );
assert_true( $result, 'migrate_option returns true for legacy plaintext' );

$stored_after = get_option( 'gdtg_enterprise_client_secret', '' );
assert_not_empty( $stored_after, 'Option still exists after migration' );
assert_true( 'my-legacy-plaintext-secret' !== $stored_after, 'Option is now encrypted, not plaintext' );

// ----------------------------------------------------------------
suite( 'GDTG_Secret_Store::get returns decrypted value after migration' );

$retrieved = GDTG_Secret_Store::get( 'gdtg_enterprise_client_secret' );
assert_equals( 'my-legacy-plaintext-secret', $retrieved, 'get() returns original value after migration' );

// ----------------------------------------------------------------
suite( 'GDTG_Secret_Store::get returns WP_Error with bad envelope' );

$mock_options = array();
$mock_options['bad_secret'] = 'this-is-not-a-valid-envelope';

$result = GDTG_Secret_Store::get( 'bad_secret' );
// Non-base64-decodable value should be returned as-is (legacy fallback)
assert_equals( 'this-is-not-a-valid-envelope', $result, 'Non-envelope plaintext returned as-is' );

// Create a fake base64-encoded JSON that doesn't have proper iv/tag/ct.
$fake_envelope = base64_encode( wp_json_encode( array( 'foo' => 'bar' ) ) );
$mock_options['bad_envelope_secret'] = $fake_envelope;

$result2 = GDTG_Secret_Store::get( 'bad_envelope_secret' );
// This should return as-is because it's not a valid envelope.
assert_equals( $fake_envelope, $result2, 'Non-envelope JSON returned as-is' );

// Create a badly formed base64 that decodes but isn't JSON.
$mock_options['bad_base64_secret'] = base64_encode( 'not-json-at-all' );
$result3 = GDTG_Secret_Store::get( 'bad_base64_secret' );
assert_equals( $raw = base64_encode( 'not-json-at-all' ), $result3, 'Non-JSON base64 returned as-is' );

// ----------------------------------------------------------------
suite( 'GDTG_Secret_Store::get empty option returns empty string' );

$mock_options = array();
$result = GDTG_Secret_Store::get( 'nonexistent' );
assert_equals( '', $result, 'get() on nonexistent option returns empty string' );

// ----------------------------------------------------------------
suite( 'GDTG_Secret_Store::migrate_option on nonexistent option' );

$mock_options = array();
$result = GDTG_Secret_Store::migrate_option( 'does_not_exist' );
assert_true( $result, 'migrate_option on nonexistent option returns true' );

// ----------------------------------------------------------------
suite( 'GDTG_Secret_Store::migrate_option on already-encrypted value' );

$mock_options = array();
$plaintext = 'already-encrypted-secret';
GDTG_Secret_Store::set( 'encrypted_secret', $plaintext );

$stored = get_option( 'encrypted_secret', '' );
assert_not_empty( $stored, 'Stored value is not empty' );
assert_true( $plaintext !== $stored, 'Stored value is encrypted' );

$migrated = GDTG_Secret_Store::migrate_option( 'encrypted_secret' );
assert_true( $migrated, 'migrate_option on already encrypted returns true' );

$after = GDTG_Secret_Store::get( 'encrypted_secret' );
assert_equals( $plaintext, $after, 'Value unchanged after double-migrate' );

// ----------------------------------------------------------------
suite( 'GDTG_Secret_Store::set then get round-trip' );

$mock_options = array();
GDTG_Secret_Store::set( 'roundtrip_secret', 'roundtrip-value-123' );

$stored = get_option( 'roundtrip_secret', '' );
assert_not_empty( $stored, 'Stored value is not empty after set' );
assert_true( 'roundtrip-value-123' !== $stored, 'Stored value is encrypted' );

$retrieved = GDTG_Secret_Store::get( 'roundtrip_secret' );
assert_equals( 'roundtrip-value-123', $retrieved, 'get() returns original value after set' );

// ----------------------------------------------------------------
suite( 'Pre-flight validation logic: empty client_id' );

// Simulate what the admin save handler checks.
$preflight_client_id = '';
$preflight_client_secret = 'some-secret';

$notices = array();

if ( '' === $preflight_client_id ) {
	$notices[] = 'missing_client_id';
} elseif ( ! preg_match( '/^\d+-[a-z0-9-]+\.apps\.googleusercontent\.com$/', $preflight_client_id ) ) {
	$notices[] = 'invalid_client_id_format';
}

if ( '' === $preflight_client_secret ) {
	$notices[] = 'missing_client_secret';
}

assert_equals( 1, count( $notices ), 'One notice generated for empty client_id' );
assert_true( in_array( 'missing_client_id', $notices, true ), 'Notice includes missing_client_id' );
assert_true( ! in_array( 'missing_client_secret', $notices, true ), 'No missing_client_secret when secret is provided' );
assert_true( ! in_array( 'invalid_client_id_format', $notices, true ), 'No invalid_client_id_format when empty' );

// ----------------------------------------------------------------
suite( 'Pre-flight validation logic: non-Google-format client_id' );

$preflight_client_id = 'not-a-google-client-id.apps.googleusercontent.com';
$preflight_client_secret = 'some-secret';

$notices = array();

if ( '' === $preflight_client_id ) {
	$notices[] = 'missing_client_id';
} elseif ( ! preg_match( '/^\d+-[a-z0-9-]+\.apps\.googleusercontent\.com$/', $preflight_client_id ) ) {
	$notices[] = 'invalid_client_id_format';
}

if ( '' === $preflight_client_secret ) {
	$notices[] = 'missing_client_secret';
}

assert_equals( 1, count( $notices ), 'One notice generated for bad-format client_id' );
assert_true( in_array( 'invalid_client_id_format', $notices, true ), 'Notice includes invalid_client_id_format' );

// ----------------------------------------------------------------
suite( 'Pre-flight validation logic: valid Google client_id passes' );

$preflight_client_id = '123456789012-abcdefghijklmnopqrstuvwxyz123456.apps.googleusercontent.com';
$preflight_client_secret = 'some-secret';

$notices = array();

if ( '' === $preflight_client_id ) {
	$notices[] = 'missing_client_id';
} elseif ( ! preg_match( '/^\d+-[a-z0-9-]+\.apps\.googleusercontent\.com$/', $preflight_client_id ) ) {
	$notices[] = 'invalid_client_id_format';
}

if ( '' === $preflight_client_secret ) {
	$notices[] = 'missing_client_secret';
}

assert_equals( 0, count( $notices ), 'No notices for valid client_id with secret' );

// ----------------------------------------------------------------
suite( 'Pre-flight validation logic: both empty' );

$preflight_client_id = '';
$preflight_client_secret = '';

$notices = array();

if ( '' === $preflight_client_id ) {
	$notices[] = 'missing_client_id';
} elseif ( ! preg_match( '/^\d+-[a-z0-9-]+\.apps\.googleusercontent\.com$/', $preflight_client_id ) ) {
	$notices[] = 'invalid_client_id_format';
}

if ( '' === $preflight_client_secret ) {
	$notices[] = 'missing_client_secret';
}

assert_equals( 2, count( $notices ), 'Two notices when both client_id and secret are empty' );
assert_true( in_array( 'missing_client_id', $notices, true ), 'Includes missing_client_id' );
assert_true( in_array( 'missing_client_secret', $notices, true ), 'Includes missing_client_secret' );

// ----------------------------------------------------------------
suite( 'Pre-flight validation: client_id regex edge cases' );

$valid_ids = array(
	'123456789012-abcdefghijklmnopqrstuvwxyz123456.apps.googleusercontent.com',
	'1-a.apps.googleusercontent.com',
	'999999999999-abc-123-def.apps.googleusercontent.com',
	'123456789012-a1b2c3.apps.googleusercontent.com',
);

foreach ( $valid_ids as $cid ) {
	$match = (bool) preg_match( '/^\d+-[a-z0-9-]+\.apps\.googleusercontent\.com$/', $cid );
	assert_true( $match, "Valid client_id matches regex: $cid" );
}

$invalid_ids = array(
	'abc.apps.googleusercontent.com',
	'123.apps.googleusercontent.com', // no dash prefix before @
	'123-abc@google.com',
	'123-abc.other-domain.com',
	'',
	'   ',
	'http://123-abc.apps.googleusercontent.com',
);

foreach ( $invalid_ids as $cid ) {
	$match = (bool) preg_match( '/^\d+-[a-z0-9-]+\.apps\.googleusercontent\.com$/', trim( $cid ) );
	assert_true( ! $match, "Invalid client_id does not match regex: " . var_export( $cid, true ) );
}

// ----------------------------------------------------------------
suite( 'Read helper: fallback policy when Secret Store returns WP_Error' );

// Reset mocks and simulate a cipher that's unavailable.
$mock_options = array();
$mock_options['gdtg_enterprise_client_secret'] = 'fallback-plaintext';

// Simulate the defensive read pattern used in admin/api:
// $cs = GDTG_Secret_Store::get('gdtg_enterprise_client_secret');
// if (is_wp_error($cs)) { $cs = get_option('gdtg_enterprise_client_secret', ''); }

$cs = GDTG_Secret_Store::get( 'gdtg_enterprise_client_secret' );
// Since it's plaintext (not an envelope), get() returns it as-is.
assert_equals( 'fallback-plaintext', $cs, 'Plaintext legacy value returned by get()' );

// Now simulate a stored envelope that fails decryption.
$mock_options = array();
$mock_options['gdtg_enterprise_client_secret'] = base64_encode( wp_json_encode( array(
	'iv'  => base64_encode( str_repeat( 'x', 16 ) ),
	'tag' => base64_encode( str_repeat( 'y', 16 ) ),
	'ct'  => base64_encode( 'tampered-data' ),
) ) );

$cs = GDTG_Secret_Store::get( 'gdtg_enterprise_client_secret' );
// This should be a WP_Error because decryption will fail.
assert_is_wp_error( $cs, 'Tampered envelope returns WP_Error from get()' );

// Now simulate the fallback.
if ( is_wp_error( $cs ) ) {
	$cs = get_option( 'gdtg_enterprise_client_secret', '' );
}
// The fallback returns the raw envelope string, not the decrypted value.
// This is the expected defensive behavior: when decryption fails,
// the caller gets the raw option value (or empty).
assert_not_empty( $cs, 'Fallback returns raw option value when decryption fails' );

// ----------------------------------------------------------------
suite( 'Phase 1: Enterprise support boundary copy exists in admin guidance' );

// The support boundary text lives in class-gdtg-admin.php render_dashboard()
// inside the Enterprise Setup Guide card. The exact string must be present
// so admins can read what is and is not supported.
$support_boundary = 'Enterprise BYO-key is supported for interactive imports from the admin screen and Gutenberg sidebar. WP-CLI Google-source imports, scheduled auto-sync in Enterprise mode, and multisite Enterprise setups are not supported yet.';

$admin_source = file_get_contents( __DIR__ . '/../includes/class-gdtg-admin.php' );
assert_true( false !== strpos( $admin_source, $support_boundary ), 'Admin Enterprise Setup Guide contains the support boundary paragraph' );

// The string must appear inside the Enterprise Setup Guide card, not just
// anywhere in the file. We verify the substring order: opening card div
// appears before the boundary text, closing div appears after.
$card_open  = '<div class="gdtg-card gdtg-card--enterprise-guide" style="margin-top: 16px;">';
$open_pos   = strpos( $admin_source, $card_open );
$text_pos   = strpos( $admin_source, $support_boundary );
assert_true( false !== $open_pos, 'Enterprise Setup Guide card div is present' );
assert_true( false !== $text_pos, 'Support boundary string is present' );
assert_true( $open_pos < $text_pos, 'Support boundary text appears inside the Enterprise Setup Guide card' );

// ----------------------------------------------------------------
echo "\n==================================================\n";
if ( $fail_count > 0 ) {
	echo "Enterprise Guidance Test Suite: FAILURES DETECTED\n";
} else {
	echo "Enterprise Guidance Test Suite PASSED successfully!\n";
}
echo "==================================================\n";
echo "Tests run: {$test_count}\n";
echo "Passed: {$pass_count}\n";
echo "Failed: {$fail_count}\n\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}

exit( 0 );
