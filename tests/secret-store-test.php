<?php
/**
 * Standalone secret-store test harness.
 *
 * Run: php tests/secret-store-test.php
 */

echo "Running Secret Store Tests...\n\n";

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

	// Provide a deterministic wp_salt for key derivation.
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-for-secret-store-' . $scheme;
	}
}

// Load the secret store class.
require_once __DIR__ . '/../includes/class-gdtg-secret-store.php';

// Helper assertion functions.
function assert_true( $val, $desc ) {
	if ( ! $val ) {
		echo "FAIL: $desc\n";
		exit( 1 );
	}
	echo "PASS: $desc\n";
}

function assert_false( $val, $desc ) {
	assert_true( ! $val, $desc );
}

function assert_equals( $expected, $actual, $desc ) {
	if ( $expected !== $actual ) {
		echo "FAIL: $desc (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
		exit( 1 );
	}
	echo "PASS: $desc\n";
}

function assert_not_empty( $val, $desc ) {
	assert_false( empty( $val ), $desc );
}

function suite( $name ) {
	echo "\n=== $name ===\n";
}

// ─── Tests ────────────────────────────────────────────────────────

suite( 'encrypt/decrypt round-trip' );

$plaintext = 'my-super-secret-client-secret-12345';
$encrypted = GDTG_Secret_Store::encrypt( $plaintext );
assert_not_empty( $encrypted, 'Encrypt returns non-empty string' );
assert_false( is_wp_error( $encrypted ), 'Encrypt does not return WP_Error' );

$decrypted = GDTG_Secret_Store::decrypt( $encrypted );
assert_equals( $plaintext, $decrypted, 'Decrypt returns original plaintext' );

suite( 'encrypt empty string' );

$encrypted_empty = GDTG_Secret_Store::encrypt( '' );
assert_equals( '', $encrypted_empty, 'Encrypt empty string returns empty string' );

$decrypted_empty = GDTG_Secret_Store::decrypt( '' );
assert_equals( '', $decrypted_empty, 'Decrypt empty string returns empty string' );

suite( 'encrypt unique IV per call' );

$enc1 = GDTG_Secret_Store::encrypt( 'same-secret' );
$enc2 = GDTG_Secret_Store::encrypt( 'same-secret' );
assert_false( $enc1 === $enc2, 'Two encryptions of same plaintext produce different ciphertexts' );

suite( 'decrypt tampered ciphertext returns WP_Error' );

$enc_ok = GDTG_Secret_Store::encrypt( 'tamper-test' );
$raw = base64_decode( $enc_ok, true );
$envelope = json_decode( $raw, true );

// Tamper with ciphertext.
$envelope['ct'] = base64_encode( 'tampered-data-here!' );
$tampered = base64_encode( wp_json_encode( $envelope ) );
$result = GDTG_Secret_Store::decrypt( $tampered );
assert_true( is_wp_error( $result ), 'Tampered ciphertext returns WP_Error' );
assert_equals( 'gdtg_secret_decrypt_failed', $result->get_error_code(), 'Tampered ciphertext returns decrypt_failed error code' );

suite( 'decrypt malformed envelope returns WP_Error' );

$result = GDTG_Secret_Store::decrypt( 'not-valid-base64-or-json' );
assert_true( is_wp_error( $result ), 'Malformed envelope returns WP_Error' );

suite( 'decrypt missing envelope fields returns WP_Error' );

$bad_envelope = base64_encode( wp_json_encode( array( 'iv' => 'aaa' ) ) );
$result = GDTG_Secret_Store::decrypt( $bad_envelope );
assert_true( is_wp_error( $result ), 'Missing fields envelope returns WP_Error' );

suite( 'key derivation deterministic with same salt' );

// Two encryptions with same salt should both decrypt correctly.
$enc_a = GDTG_Secret_Store::encrypt( 'deterministic-test' );
$dec_a = GDTG_Secret_Store::decrypt( $enc_a );
assert_equals( 'deterministic-test', $dec_a, 'First encryption round-trips' );

$enc_b = GDTG_Secret_Store::encrypt( 'another-value' );
$dec_b = GDTG_Secret_Store::decrypt( $enc_b );
assert_equals( 'another-value', $dec_b, 'Second encryption round-trips' );

// Cross-decrypt: decrypt enc_a with same key (implicit) should work.
$dec_cross = GDTG_Secret_Store::decrypt( $enc_a );
assert_equals( 'deterministic-test', $dec_cross, 'Cross-decrypt works with same salt' );

suite( 'get/set round-trip' );

global $mock_options;
$mock_options = array();

GDTG_Secret_Store::set( 'test_secret_option', 'hello-world' );
$stored_raw = get_option( 'test_secret_option', '' );
assert_not_empty( $stored_raw, 'Stored option is not empty' );
assert_false( 'hello-world' === $stored_raw, 'Stored option is encrypted (not plaintext)' );

$retrieved = GDTG_Secret_Store::get( 'test_secret_option' );
assert_equals( 'hello-world', $retrieved, 'get() returns decrypted value' );

suite( 'get empty option returns empty string' );

$retrieved_empty = GDTG_Secret_Store::get( 'nonexistent_option' );
assert_equals( '', $retrieved_empty, 'get() on nonexistent option returns empty string' );

suite( 'migrate_option: legacy plaintext → encrypted' );

$mock_options = array();
$mock_options['legacy_secret'] = 'plaintext-legacy-value';

$migrated = GDTG_Secret_Store::migrate_option( 'legacy_secret' );
assert_true( $migrated, 'migrate_option returns true' );

$stored_after = get_option( 'legacy_secret', '' );
assert_not_empty( $stored_after, 'Option still exists after migration' );
assert_false( 'plaintext-legacy-value' === $stored_after, 'Option is now encrypted' );

$retrieved_after = GDTG_Secret_Store::get( 'legacy_secret' );
assert_equals( 'plaintext-legacy-value', $retrieved_after, 'get() returns original value after migration' );

suite( 'migrate_option: already encrypted → no-op' );

// Re-migrate the already-encrypted value.
$migrated_again = GDTG_Secret_Store::migrate_option( 'legacy_secret' );
assert_true( $migrated_again, 'migrate_option on already-encrypted returns true' );
$retrieved_again = GDTG_Secret_Store::get( 'legacy_secret' );
assert_equals( 'plaintext-legacy-value', $retrieved_again, 'Value unchanged after double-migrate' );

suite( 'migrate_option: nonexistent option → no-op' );

$migrated_nonexistent = GDTG_Secret_Store::migrate_option( 'does_not_exist' );
assert_true( $migrated_nonexistent, 'migrate_option on nonexistent returns true' );

suite( 'set empty string clears option' );

GDTG_Secret_Store::set( 'test_clear_option', 'some-value' );
$before = GDTG_Secret_Store::get( 'test_clear_option' );
assert_equals( 'some-value', $before, 'Value set before clear' );

GDTG_Secret_Store::set( 'test_clear_option', '' );
$after = GDTG_Secret_Store::get( 'test_clear_option' );
assert_equals( '', $after, 'Empty set clears value' );

suite( 'ciphertext envelope structure' );

$enc = GDTG_Secret_Store::encrypt( 'structure-test' );
$raw = base64_decode( $enc, true );
assert_not_empty( $raw, 'Envelope is valid base64' );

$envelope = json_decode( $raw, true );
assert_true( is_array( $envelope ), 'Envelope is JSON object' );
assert_true( isset( $envelope['iv'] ), 'Envelope has iv field' );
assert_true( isset( $envelope['tag'] ), 'Envelope has tag field' );
assert_true( isset( $envelope['ct'] ), 'Envelope has ct field' );

$iv = base64_decode( $envelope['iv'], true );
assert_equals( 16, strlen( $iv ), 'IV is 16 bytes' );

$tag = base64_decode( $envelope['tag'], true );
assert_equals( 16, strlen( $tag ), 'Tag is 16 bytes' );

echo "\n==================================================\n";
echo "Secret Store Test Suite PASSED successfully!\n";
echo "==================================================\n\n";
