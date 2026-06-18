<?php
/**
 * Google API / Centralized SaaS Bridge Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDTG_API {
	/**
	 * Main API Google Docs Endpoint
	 */
	private $api_base = 'https://docs.googleapis.com/v1/documents/';

	/**
	 * Max retry attempts for transient HTTP failures.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Base backoff delay in seconds.
	 *
	 * @var int
	 */
	private $retry_delay_base = 1;

	/**
	 * Validate and sanitize a bridge base URL.
	 *
	 * Rules:
	 * - Must have a scheme (http or https).
	 * - Must have a host.
	 * - Scheme must be https, except for localhost / .local / loopback dev hosts.
	 * - Reject userinfo (user:pass@host).
	 * - Returns the trimmed URL on success, or empty string on invalid input.
	 *
	 * @param string $url Raw URL to validate.
	 * @return string Validated URL (trailing slash trimmed) or empty string.
	 */
	public static function validate_bridge_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( false === $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parsed['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		// Reject userinfo (user:pass@host).
		if ( ! empty( $parsed['user'] ) ) {
			return '';
		}

		$host = strtolower( $parsed['host'] );

		// HTTPS required unless this is a dev host.
		if ( 'https' !== $scheme ) {
			$is_local = 'localhost' === $host
				|| '127.0.0.1' === $host
				|| '::1' === $host
				|| substr( $host, -6 ) === '.local';
			if ( ! $is_local ) {
				return '';
			}
		}

		return rtrim( $url, '/' );
	}

	/**
	 * Resolve the SaaS bridge base URL with precedence:
	 * 1. GDTG_SAAS_BRIDGE_BASE_URL constant (wp-config.php override)
	 * 2. gdtg_saas_bridge_base_url option (programmatic, hidden in UI)
	 * 3. GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT constant (packaged fallback)
	 *
	 * Each source is validated; invalid values are silently skipped.
	 *
	 * @return string Base URL with trailing slash trimmed, or empty string if no valid source.
	 */
	public static function saas_bridge_base_url() {
		// Priority 1: constant override.
		if ( defined( 'GDTG_SAAS_BRIDGE_BASE_URL' ) && is_string( GDTG_SAAS_BRIDGE_BASE_URL ) && '' !== GDTG_SAAS_BRIDGE_BASE_URL ) {
			$validated = self::validate_bridge_url( GDTG_SAAS_BRIDGE_BASE_URL );
			if ( '' !== $validated ) {
				return $validated;
			}
		}

		// Priority 2: option.
		$option = get_option( 'gdtg_saas_bridge_base_url', '' );
		if ( '' !== $option ) {
			$validated = self::validate_bridge_url( $option );
			if ( '' !== $validated ) {
				return $validated;
			}
		}

		// Priority 3: packaged default.
		if ( defined( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT' ) ) {
			$validated = self::validate_bridge_url( GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT );
			if ( '' !== $validated ) {
				return $validated;
			}
		}

		return '';
	}

	/**
	 * Perform an HTTP GET with bounded exponential backoff on retryable failures.
	 *
	 * Retries on: wp_remote_get WP_Error, HTTP 429, HTTP 5xx.
	 * Does NOT retry on 4xx (except 429).
	 *
	 * @param string $url     Request URL.
	 * @param array  $args    wp_remote_get args.
	 * @param int    $attempt Current attempt number (internal use).
	 * @return array|WP_Error Response array or WP_Error.
	 */
	private function retry_get( $url, $args, $attempt = 1 ) {
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			if ( $attempt < $this->max_retries ) {
				sleep( $this->retry_delay_base * pow( 2, $attempt - 1 ) );
				return $this->retry_get( $url, $args, $attempt + 1 );
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 500 && $attempt < $this->max_retries ) {
			sleep( $this->retry_delay_base * pow( 2, $attempt - 1 ) );
			return $this->retry_get( $url, $args, $attempt + 1 );
		}

		if ( 429 === $code && $attempt < $this->max_retries ) {
			$delay = $this->compute_429_delay( $response, $attempt );
			sleep( $delay );
			return $this->retry_get( $url, $args, $attempt + 1 );
		}

		return $response;
	}

	/**
	 * Compute backoff delay for a 429 response honoring the Retry-After header.
	 *
	 * Uses max(Retry-After, exponential_backoff), capped at 30 seconds.
	 *
	 * @param array $response HTTP response array.
	 * @param int   $attempt  Retry attempt number.
	 * @return int Delay in seconds (1–30).
	 */
	private function compute_429_delay( $response, $attempt ) {
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		$ra_seconds  = 0;

		if ( '' !== $retry_after ) {
			if ( is_numeric( $retry_after ) ) {
				$ra_seconds = (int) $retry_after;
			} elseif ( is_string( $retry_after ) ) {
				$ra_time = strtotime( $retry_after );
				if ( false !== $ra_time ) {
					$ra_seconds = $ra_time - time();
				}
			}
		}

		$ra_seconds    = max( 0, (int) $ra_seconds );
		$backoff       = $this->retry_delay_base * pow( 2, $attempt - 1 );
		$delay         = max( $ra_seconds, (int) $backoff );

		return min( $delay, 30 );
	}

	/**
	 * Perform an HTTP POST with bounded exponential backoff on retryable failures.
	 *
	 * @param string $url     Request URL.
	 * @param array  $args    wp_remote_post args.
	 * @param int    $attempt Current attempt number (internal use).
	 * @return array|WP_Error Response array or WP_Error.
	 */
	private function retry_post( $url, $args, $attempt = 1 ) {
		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			if ( $attempt < $this->max_retries ) {
				sleep( $this->retry_delay_base * pow( 2, $attempt - 1 ) );
				return $this->retry_post( $url, $args, $attempt + 1 );
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 500 && $attempt < $this->max_retries ) {
			sleep( $this->retry_delay_base * pow( 2, $attempt - 1 ) );
			return $this->retry_post( $url, $args, $attempt + 1 );
		}

		if ( 429 === $code && $attempt < $this->max_retries ) {
			$delay = $this->compute_429_delay( $response, $attempt );
			sleep( $delay );
			return $this->retry_post( $url, $args, $attempt + 1 );
		}

		return $response;
	}
	/**
	 * Get the active access token based on the current connection mode
	 *
	 * @return string|false Access token or false on failure.
	 */
	public function get_access_token() {
		$mode = get_option( 'gdtg_connection_mode', 'saas' );

		if ( $mode === 'enterprise' ) {
			return $this->get_enterprise_access_token();
		} else {
			return $this->get_saas_access_token();
		}
	}

	/**
	 * Retrieve a Google Doc's raw structural JSON
	 *
	 * @param string $doc_id The alphanumeric Google Doc ID.
	 * @return string|WP_Error JSON payload or WP_Error on failure.
	 */
	public function fetch_google_doc( $doc_id ) {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'gdtg_no_token', __( 'Please connect your Google account in Settings first.', 'draftsync' ) );
		}

		$url      = $this->api_base . $doc_id;
		$response = $this->retry_get(
			$url,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code === 401 ) {
			// Access Token may have expired just now. Force refresh and try once more.
			$this->force_refresh_token();
			$token = $this->get_access_token();

			if ( $token ) {
				$response = $this->retry_get(
					$url,
					[
						'headers' => [
							'Authorization' => 'Bearer ' . $token,
							'Accept'        => 'application/json',
						],
						'timeout' => 30,
					]
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$code = wp_remote_retrieve_response_code( $response );
				$body = wp_remote_retrieve_body( $response );
			}
		}

		if ( $code !== 200 ) {
			$error_data = json_decode( $body, true );
			$message    = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : __( 'Failed to fetch document from Google.', 'draftsync' );
			return new WP_Error( 'gdtg_api_error', $message );
		}

		return $body;
	}
	private function get_enterprise_access_token() {
		$access_token = GDTG_Secret_Store::get( 'gdtg_enterprise_access_token' );
		if ( is_wp_error( $access_token ) ) {
			return false;
		}
		// Migrate legacy plaintext access tokens to encrypted envelopes on read.
		GDTG_Secret_Store::migrate_option( 'gdtg_enterprise_access_token' );
		$token_expires = get_option( 'gdtg_enterprise_token_expires', 0 );

		// If token is expired or expiring in next 60 seconds, refresh it
		if ( empty( $access_token ) || time() >= ( $token_expires - 60 ) ) {
			$refreshed = $this->refresh_enterprise_token();
			if ( $refreshed ) {
				$re_read = GDTG_Secret_Store::get( 'gdtg_enterprise_access_token' );
				return is_wp_error( $re_read ) ? false : $re_read;
			}
			return false;
		}

		return $access_token;
	}

	private function refresh_enterprise_token() {
		$client_id     = get_option( 'gdtg_enterprise_client_id', '' );
		$client_secret = GDTG_Secret_Store::get( 'gdtg_enterprise_client_secret' );
		if ( is_wp_error( $client_secret ) ) {
			$client_secret = get_option( 'gdtg_enterprise_client_secret', '' );
		}
		$refresh_token = GDTG_Secret_Store::get( 'gdtg_enterprise_refresh_token' );
		if ( is_wp_error( $refresh_token ) ) {
			return false;
		}
		// Migrate legacy plaintext refresh tokens to encrypted envelopes on read.
		GDTG_Secret_Store::migrate_option( 'gdtg_enterprise_refresh_token' );

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
			return false;
		}

		$response = $this->retry_post(
			'https://oauth2.googleapis.com/token',
			[
				'body' => [
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			return false;
		}

		$data = json_decode( $body, true );
		if ( isset( $data['access_token'] ) ) {
			// Store the refreshed access token encrypted. If the store call
			// fails, refuse to advance the expiry — we never want a
			// half-encrypted state.
			$stored = GDTG_Secret_Store::set( 'gdtg_enterprise_access_token', sanitize_text_field( $data['access_token'] ) );
			if ( ! $stored ) {
				return false;
			}
			$expires_in = isset( $data['expires_in'] ) ? intval( $data['expires_in'] ) : 3600;
			update_option( 'gdtg_enterprise_token_expires', time() + $expires_in );
			// Some Google OAuth responses include a rotated refresh token.
			// If present, store it encrypted too.
			if ( isset( $data['refresh_token'] ) ) {
				GDTG_Secret_Store::set( 'gdtg_enterprise_refresh_token', sanitize_text_field( $data['refresh_token'] ) );
			}
			return true;
		}

		return false;
	}


	/**
	 * Retrieve SaaS mode token and refresh if needed
	 */
	private function get_saas_access_token() {
		$token_expires = get_option( 'gdtg_saas_token_expires', 0 );
		$access_token  = get_option( 'gdtg_saas_access_token', '' );

		// If token is expired or expiring soon, refresh it
		if ( empty( $access_token ) || time() >= ( $token_expires - 60 ) ) {
			$refreshed = $this->refresh_saas_token();
			if ( $refreshed ) {
				return get_option( 'gdtg_saas_access_token', '' );
			}
			return false;
		}

		return $access_token;
	}

	/**
	 * Perform token refresh proxying through our Centralized SaaS Serverless Bridge
	 */
	private function refresh_saas_token() {
		$refresh_token = get_option( 'gdtg_saas_refresh_token', '' );
		if ( empty( $refresh_token ) ) {
			return false;
		}

		// Centralized SaaS Bridge API Endpoint URL
		$base_url = self::saas_bridge_base_url();
		if ( '' === $base_url ) {
			return false;
		}
		$saas_bridge_url = $base_url . '/api/refresh';

		$response = $this->retry_post(
			$saas_bridge_url,
			[
				'body'    => [
					'refresh_token' => $refresh_token,
				],
				'headers' => [
					'Accept' => 'application/json',
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			return false;
		}

		$data = json_decode( $body, true );
		if ( isset( $data['access_token'] ) ) {
			update_option( 'gdtg_saas_access_token', $data['access_token'] );
			$expires_in = isset( $data['expires_in'] ) ? intval( $data['expires_in'] ) : 3600;
			update_option( 'gdtg_saas_token_expires', time() + $expires_in );

			if ( isset( $data['refresh_token'] ) ) {
				update_option( 'gdtg_saas_refresh_token', $data['refresh_token'] );
			}
			return true;
		}

		return false;
	}

	/**
	 * Force immediate expiration and refresh
	 */
	public function force_refresh_token() {
		$mode = get_option( 'gdtg_connection_mode', 'saas' );
		if ( $mode === 'enterprise' ) {
			update_option( 'gdtg_enterprise_token_expires', 0 );
			$this->refresh_enterprise_token();
		} else {
			update_option( 'gdtg_saas_token_expires', 0 );
			$this->refresh_saas_token();
		}
	}

	/**
	 * Request SaaS bridge image optimization for a given source URL.
	 *
	 * @param string $source_url The remote image URL.
	 * @return string|WP_Error Normalized optimized URL or WP_Error.
	 */
	public function optimize_image( $source_url ) {
		if ( empty( $source_url ) ) {
			return new WP_Error( 'invalid_source_url', __( 'Source URL is empty.', 'draftsync' ) );
		}

		$parsed = wp_parse_url( $source_url );
		if ( ! $parsed || ! isset( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
			return new WP_Error( 'invalid_source_url', __( 'Invalid source URL scheme.', 'draftsync' ) );
		}


		$token = $this->get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'gdtg_no_token', __( 'Please connect your account to optimize images.', 'draftsync' ) );
		}

		$base_url = self::saas_bridge_base_url();
		if ( '' === $base_url ) {
			return new WP_Error( 'gdtg_no_bridge', __( 'SaaS bridge URL is not configured.', 'draftsync' ) );
		}
		$saas_bridge_url = $base_url . '/api/optimize';

		$response = $this->retry_post(
			$saas_bridge_url,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'body'    => wp_json_encode( [
					'source_url' => $source_url,
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code === 401 ) {
			// Try force refresh once and retry
			$this->force_refresh_token();
			$token = $this->get_access_token();
			if ( $token ) {
				$response = $this->retry_post(
					$saas_bridge_url,
					[
						'headers' => [
							'Authorization' => 'Bearer ' . $token,
							'Content-Type'  => 'application/json',
							'Accept'        => 'application/json',
						],
						'body'    => wp_json_encode( [
							'source_url' => $source_url,
						] ),
						'timeout' => 15,
					]
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$code = wp_remote_retrieve_response_code( $response );
				$body = wp_remote_retrieve_body( $response );
			}
		}

		if ( $code !== 200 ) {
		/* translators: %d: HTTP status code returned by the SaaS bridge */
			return new WP_Error( 'gdtg_optimize_failed', sprintf( __( 'SaaS bridge returned HTTP status %d.', 'draftsync' ), $code ) );
		}

		$data = json_decode( $body, true );
		$optimized_url = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['optimized_url'] ) ) {
				$optimized_url = $data['optimized_url'];
			} elseif ( isset( $data['url'] ) ) {
				$optimized_url = $data['url'];
			}
		}

		if ( empty( $optimized_url ) ) {
			return new WP_Error( 'gdtg_invalid_response', __( 'SaaS bridge response missing optimized URL.', 'draftsync' ) );
		}

		$parsed_opt = wp_parse_url( $optimized_url );
		if ( ! $parsed_opt || ! isset( $parsed_opt['scheme'] ) || ! in_array( strtolower( $parsed_opt['scheme'] ), [ 'http', 'https' ], true ) ) {
			return new WP_Error( 'gdtg_invalid_response_url', __( 'SaaS bridge returned an invalid URL scheme.', 'draftsync' ) );
		}

		return $optimized_url;
	}

	// ─── Google Drive API Methods ─────────────────────────────────

	/**
	 * Fetch file metadata from Google Drive API.
	 *
	 * @param string $file_id The Drive file ID.
	 * @return array|WP_Error Metadata array with 'name' and 'mimeType', or WP_Error.
	 */
	public function get_drive_file_metadata( $file_id ) {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'gdtg_no_token', __( 'Please connect your Google account in Settings first.', 'draftsync' ) );
		}

		$url      = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?fields=name,mimeType,modifiedTime';
		$response = $this->retry_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code ) {
			$this->force_refresh_token();
			$token = $this->get_access_token();
			if ( $token ) {
				$response = $this->retry_get(
					$url,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
						),
						'timeout' => 10,
					)
				);
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$code = wp_remote_retrieve_response_code( $response );
				$body = wp_remote_retrieve_body( $response );
			}
		}

		if ( 200 !== $code ) {
			return new WP_Error( 'gdtg_drive_metadata_error', __( 'Could not retrieve file information from Google Drive.', 'draftsync' ) );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['mimeType'] ) ) {
			return new WP_Error( 'gdtg_drive_metadata_error', __( 'Invalid response from Google Drive.', 'draftsync' ) );
		}

		return $data;
	}

	/**
	 * Download a file from Google Drive.
	 *
	 * @param string $file_id The Drive file ID.
	 * @return string|WP_Error Raw file bytes or WP_Error on failure.
	 */
	public function fetch_drive_file( $file_id ) {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'gdtg_no_token', __( 'Please connect your Google account in Settings first.', 'draftsync' ) );
		}

		$url      = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?alt=media';
		$response = $this->retry_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code ) {
			$this->force_refresh_token();
			$token = $this->get_access_token();
			if ( $token ) {
				$response = $this->retry_get(
					$url,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
						),
						'timeout' => 60,
					)
				);
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$code = wp_remote_retrieve_response_code( $response );
				$body = wp_remote_retrieve_body( $response );
			}
		}

		if ( 200 !== $code ) {
			return new WP_Error( 'gdtg_drive_download_error', __( 'Failed to download file from Google Drive.', 'draftsync' ) );
		}

		return $body;
	}

	/**
	 * Export a Google Doc as HTML using the Drive export endpoint.
	 *
	 * Used by the import orchestrator as a safe fallback for oversized
	 * Google Docs (raw JSON over GDTG_LARGE_DOC_BYTE_THRESHOLD). Returns
	 * the exported HTML on success, WP_Error on failure.
	 *
	 * @param string $doc_id The Google Doc ID.
	 * @return string|WP_Error HTML payload or WP_Error on failure.
	 */
	public function export_google_doc_as_html( $doc_id ) {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'gdtg_no_token', __( 'Please connect your Google account in Settings first.', 'draftsync' ) );
		}

		$url      = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $doc_id ) . '/export?mimeType=text/html';
		$response = $this->retry_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'text/html',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code ) {
			// Token may have expired; force refresh and try once more.
			$this->force_refresh_token();
			$token = $this->get_access_token();
			if ( $token ) {
				$response = $this->retry_get(
					$url,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
							'Accept'        => 'text/html',
						),
						'timeout' => 30,
					)
				);
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$code = wp_remote_retrieve_response_code( $response );
				$body = wp_remote_retrieve_body( $response );
			}
		}

		if ( 200 !== $code ) {
			$error_data   = json_decode( $body, true );
			$message      = isset( $error_data['error']['message'] )
				? $error_data['error']['message']
				: __( 'Failed to export document from Google Drive.', 'draftsync' );
			return new WP_Error( 'gdtg_export_error', $message );
		}

		return $body;
	}
}
