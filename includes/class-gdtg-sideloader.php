<?php
/**
 * Image Sideloader: Safely fetches remote image URLs and uploads them into the WordPress Media Library.
 *
 * Uses a streamed download path to avoid loading large images entirely into memory
 * during `media_sideload_image()`. Falls back to the WordPress built-in path when
 * streaming fails.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDTG_Sideloader {
	/**
	 * Download a remote image URL and register it as an attachment in the WordPress Media Library.
	 *
	 * Tries streamed download first to keep memory low; falls back to media_sideload_image().
	 *
	 * @param string $url     The absolute remote image URL.
	 * @param int    $post_id The post ID to attach the image to.
	 * @param string $desc    Optional. The image alt text/description.
	 * @param array  $options {
	 *     Optional. Import options.
	 *
	 *     @type bool $optimize_images Whether to run SaaS bridge image optimization. Default: from 'gdtg_optimize_images' option.
	 * }
	 * @return int|false      Attachment ID on success, false on failure.
	 */
	public static function sideload( $url, $post_id = 0, $desc = '', $options = [] ) {
		if ( empty( $url ) ) {
			return false;
		}
		// Validate URL scheme before attempting download
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || ! isset( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
			return false;
		}
		// Ensure required admin backend file resources are available
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		// Determine effective optimize_images setting (default 1)
		$optimize_images = '1';
		if ( is_array( $options ) && isset( $options['optimize_images'] ) ) {
			$optimize_images = $options['optimize_images'] ? '1' : '0';
		} else {
			$optimize_images = get_option( 'gdtg_optimize_images', '1' );
		}
		$effective_url = $url;
		// Attempt bridge optimization (works in any mode with a configured bridge URL).
		if ( '1' === $optimize_images ) {
			$api       = new GDTG_API();
			$opt_result = $api->optimize_image( $url );
			if ( ! is_wp_error( $opt_result ) ) {
				$effective_url = $opt_result;
			}
		}
		// Try streamed download path first for memory efficiency.
		$attachment_id = self::sideload_streamed( $effective_url, $post_id, $desc );
		if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
			// Optimize downloaded image if option is active
			if ( '1' === $optimize_images ) {
				self::optimize_attachment( $attachment_id );
			}
			return $attachment_id;
		}
		// Fall back to built-in WordPress media_sideload_image.
		$attachment_id = media_sideload_image( $effective_url, $post_id, $desc, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			error_log( 'DraftSync - Sideloading Error: ' . $attachment_id->get_error_code() );
			return false;
		}
		if ( '1' === $optimize_images ) {
			self::optimize_attachment( $attachment_id );
		}
		return $attachment_id;
	}

	/**
	 * Sideload raw image bytes into the WordPress Media Library.
	 *
	 * Used for embedded images extracted from .docx archives. Writes bytes
	 * to a temporary file, then registers as an attachment. Temp file is
	 * cleaned up on both success and failure.
	 *
	 * @param string $bytes   Raw image bytes.
	 * @param string $filename Filename for the attachment (e.g. 'image1.png').
	 * @param int    $post_id Post ID to attach to (0 for unattached).
	 * @param string $alt     Alt text / description.
	 * @param array  $options Import options.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public static function sideload_from_bytes( $bytes, $filename, $post_id = 0, $alt = '', $options = array() ) {
		if ( empty( $bytes ) || empty( $filename ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Guess MIME type from extension.
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime_map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'svg'  => 'image/svg+xml',
		);
		$mime_type = isset( $mime_map[ $ext ] ) ? $mime_map[ $ext ] : 'application/octet-stream';

		// Reject non-image MIME types.
		if ( 0 !== strpos( $mime_type, 'image/' ) ) {
			return false;
		}

		// Write bytes to temp file.
		$temp_file = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'gdtg-docx-img-' . md5( $filename ) ) : tempnam( sys_get_temp_dir(), 'gdtg-' );
		if ( ! $temp_file ) {
			return false;
		}

		$written = file_put_contents( $temp_file, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			wp_delete_file( $temp_file );
			return false;
		}

		// Build attachment data.
		$attachment = array(
			'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_mime_type' => $mime_type,
			'post_status'    => 'inherit',
			'post_content'   => '',
		);

		if ( $post_id > 0 ) {
			$attachment['post_parent'] = $post_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $temp_file, $post_id > 0 ? $post_id : 0 );

		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			wp_delete_file( $temp_file );
			return false;
		}

		// Generate attachment metadata.
		$metadata = wp_generate_attachment_metadata( $attachment_id, $temp_file );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Set alt text if provided.
		if ( ! empty( $alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		// Optionally optimize.
		$optimize = isset( $options['optimize_images'] ) ? $options['optimize_images'] : get_option( 'gdtg_optimize_images', '1' );
		if ( $optimize ) {
			self::optimize_attachment( $attachment_id );
		}

		return $attachment_id;
	}

	/**
	 * Stream a remote image to a temporary file, then sideload into the media library.
	 *
	 * Avoids loading the entire image into PHP memory. Uses wp_safe_remote_get()
	 * with a streamed response and writes to disk in chunks.
	 *
	 * @param string $url     Remote image URL.
	 * @param int    $post_id Post ID to attach to.
	 * @param string $desc    Image alt text/description.
	 * @return int|false|WP_Error Attachment ID on success, false on failure, WP_Error on file issues.
	 */
	private static function sideload_streamed( $url, $post_id, $desc ) {
		$temp_file = self::temp_download_path( $url );

		// Download with streaming via WordPress HTTP API.
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'  => 30,
				'stream'   => true,
				'filename' => $temp_file,
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			return false;
		}

		$downloaded_file = wp_remote_retrieve_body( $response );
		if ( empty( $downloaded_file ) || ! file_exists( $downloaded_file ) ) {
			if ( ! empty( $downloaded_file ) && file_exists( $downloaded_file ) ) {
				wp_delete_file( $downloaded_file );
			}
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			return false;
		}

		// Build a file array for media_handle_sideload.
		$file_array = [
			'name'     => self::filename_from_url( $url ),
			'tmp_name' => $downloaded_file,
		];

		// If a description is provided, use it for alt text via post data.
		$post_data = [];
		if ( ! empty( $desc ) ) {
			$post_data = [
				'post_excerpt' => sanitize_text_field( $desc ),
				'post_content' => sanitize_text_field( $desc ),
			];
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id, $desc, $post_data );

		// Clean up temp file on failure.
		if ( is_wp_error( $attachment_id ) && file_exists( $downloaded_file ) ) {
			wp_delete_file( $downloaded_file );
		}

		return $attachment_id;
	}

	/**
	 * Generate a temporary file path for streamed download.
	 *
	 * Uses wp_tempnam() without appending an extension. The extension is
	 * determined later by media_handle_sideload() via the file_array name.
	 *
	 * @param string $url Source URL used to generate a unique name.
	 * @return string Absolute path to a writable temp file.
	 */
	private static function temp_download_path( $url ) {
		return function_exists( 'wp_tempnam' ) ? wp_tempnam( 'gdtg-img-' . md5( $url ) ) : tempnam( sys_get_temp_dir(), 'gdtg-' );
	}

	/**
	 * Extract a safe filename from a URL.
	 *
	 * @param string $url Remote image URL.
	 * @return string Safe filename with extension.
	 */
	private static function filename_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$name = $path ? basename( $path ) : 'image';
		// Strip query strings from filename.
		$name = preg_replace( '/\?.*/', '', $name );
		// Ensure a valid extension.
		$ext = self::extension_from_url( $url );
		if ( $ext && ! preg_match( '/\.' . preg_quote( $ext, '/' ) . '$/i', $name ) ) {
			$name .= '.' . $ext;
		}
		// Sanitize.
		return sanitize_file_name( $name );
	}

	/**
	 * Guess a file extension from a URL.
	 *
	 * @param string $url Remote image URL.
	 * @return string Lowercase extension without dot, or 'jpg' if unknown.
	 */
	private static function extension_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return 'jpg';
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$valid = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' ];
		return in_array( $ext, $valid, true ) ? $ext : 'jpg';
	}

	/**
	 * Perform in-place image resizing, quality compression (to 82%), and native WebP conversion.
	 *
	 * @param int $attachment_id The media attachment ID in WordPress.
	 */
	private static function optimize_attachment( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return;
		}

		$mime_type = get_post_mime_type( $attachment_id );
		// Skip if already in WebP format
		if ( $mime_type === 'image/webp' ) {
			return;
		}

		// Load WordPress Image Editor abstraction class
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return;
		}

		// 1. Resize large source images down to a 1600px desktop grid max-width
		$sizes = $editor->get_size();
		if ( $sizes && ( $sizes['width'] > 1600 || $sizes['height'] > 1600 ) ) {
			$editor->resize( 1600, 1600, false );
		}

		// 2. Set quality optimization ratio to 82%
		$editor->set_quality( 82 );

		// 3. Setup new WebP target filepath
		$path_info     = pathinfo( $file_path );
		$new_file_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';

		// Save the compressed image as a native WebP image
		$saved = $editor->save( $new_file_path, 'image/webp' );

		if ( ! is_wp_error( $saved ) ) {
			// 4. Update WordPress media records BEFORE deleting original
			$upload_dir        = wp_get_upload_dir();
			$attached_file_rel = str_replace( $upload_dir['basedir'] . '/', '', $saved['path'] );

			update_attached_file( $attachment_id, $saved['path'] );

			wp_update_post( [
				'ID'             => $attachment_id,
				'post_mime_type' => 'image/webp',
				'guid'           => $upload_dir['baseurl'] . '/' . $attached_file_rel,
			] );

			// 5. Regenerate responsive crop sizes (medium, large, thumbnail) using optimized WebP targets
			$metadata = wp_generate_attachment_metadata( $attachment_id, $saved['path'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			// 6. Delete original only after metadata is safely updated
			if ( ! empty( $metadata ) && file_exists( $file_path ) && $file_path !== $saved['path'] ) {
				wp_delete_file( $file_path );
			}
		}
	}
}
