<?php
/**
 * ZIP Validator: Reusable security hardening for .docx (ZIP) file validation.
 *
 * Validates a ZIP archive against security constraints before any content
 * is read. Does not extract to disk — all reads go through ZipArchive
 * getFromName() or getStream().
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Zip_Validator
 *
 * Stateless validator. Call validate() once per file; returns true or WP_Error.
 */
class GDTG_Zip_Validator {

	/**
	 * Validate a .docx file for security constraints.
	 *
	 * @param string $file_path Absolute path to the .docx file.
	 * @return true|WP_Error True if valid, WP_Error describing the failure.
	 */
	public static function validate( $file_path ) {
		// 1. File exists and is readable.
		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'gdtg_docx_not_found', __( 'File not found or not readable.', 'draftsync' ) );
		}

		// 2. File size limit.
		$max_size = apply_filters( 'gdtg_docx_max_upload_size', 10 * 1024 * 1024 );
		$file_size = filesize( $file_path );
		if ( $file_size > $max_size ) {
			return new WP_Error(
				'gdtg_docx_too_large',
				sprintf(
					/* translators: %s: maximum file size */
					__( 'File exceeds maximum size of %s.', 'draftsync' ),
					size_format( $max_size )
				)
			);
		}

		// 3. ZIP magic bytes: PK\x03\x04.
		$magic = file_get_contents( $file_path, false, null, 0, 4 );
		if ( false === $magic || strlen( $magic ) < 4 ) {
			return new WP_Error( 'gdtg_docx_read_error', __( 'Cannot read file for ZIP validation.', 'draftsync' ) );
		}
		if ( 'PK' . "\x03" . "\x04" !== $magic ) {
			return new WP_Error( 'gdtg_docx_bad_magic', __( 'File is not a valid ZIP archive.', 'draftsync' ) );
		}

		// 4. Open with ZipArchive.
		$zip = new ZipArchive();
		$result = $zip->open( $file_path, ZipArchive::RDONLY );
		if ( true !== $result ) {
			return new WP_Error( 'gdtg_docx_zip_open', __( 'Cannot open ZIP archive.', 'draftsync' ) );
		}

		// 5. Entry count guard.
		$max_entries = 10000;
		if ( $zip->numFiles > $max_entries ) {
			$zip->close();
			return new WP_Error(
				'gdtg_docx_too_many_entries',
				sprintf(
					/* translators: %d: maximum entry count */
					__( 'Archive contains too many entries (max %d).', 'draftsync' ),
					$max_entries
				)
			);
		}

		// 6. Validate each entry.
		$total_uncompressed = 0;
		$max_entry_size     = 50 * 1024 * 1024;   // 50MB per entry.
		$max_total_size     = 100 * 1024 * 1024;   // 100MB total.
		$max_ratio          = 100;                  // 100:1 compression ratio.

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name ) {
				continue;
			}

			// 6a. Path traversal check.
			$normalized = str_replace( '\\', '/', $name );
			if (
				strpos( $normalized, '..' ) !== false
				|| strpos( $normalized, "\0" ) !== false
				|| ( strlen( $normalized ) > 0 && '/' === $normalized[0] )
				|| strpos( $normalized, './' ) === 0
			) {
				$zip->close();
				return new WP_Error(
					'gdtg_docx_path_traversal',
					__( 'Archive contains unsafe entry names.', 'draftsync' )
				);
			}

			// 6b. Nested zip check.
			$lower_name = strtolower( $name );
			if ( preg_match( '/\.zip$/', $lower_name ) ) {
				$zip->close();
				return new WP_Error(
					'gdtg_docx_nested_zip',
					__( 'Archive contains nested ZIP files.', 'draftsync' )
				);
			}

			// 6c. Per-entry uncompressed size.
			$stat = $zip->statIndex( $i );
			if ( false !== $stat ) {
				$uncompressed = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
				$compressed   = isset( $stat['comp_size'] ) ? (int) $stat['comp_size'] : 0;

				if ( $uncompressed > $max_entry_size ) {
					$zip->close();
					return new WP_Error(
						'gdtg_docx_entry_too_large',
						sprintf(
							/* translators: %s: maximum entry size */
							__( 'Archive entry exceeds maximum uncompressed size of %s.', 'draftsync' ),
							size_format( $max_entry_size )
						)
					);
				}

				$total_uncompressed += $uncompressed;

				// 6d. Compression ratio guard.
				if ( $compressed > 0 && $uncompressed > 0 ) {
					$ratio = $uncompressed / $compressed;
					if ( $ratio > $max_ratio ) {
						$zip->close();
						return new WP_Error(
							'gdtg_docx_zip_bomb',
							__( 'Archive has suspicious compression ratio.', 'draftsync' )
						);
					}
				}
			}
		}

		// 7. Total uncompressed size guard.
		if ( $total_uncompressed > $max_total_size ) {
			$zip->close();
			return new WP_Error(
				'gdtg_docx_total_too_large',
				sprintf(
					/* translators: %s: maximum total size */
					__( 'Archive total uncompressed size exceeds %s.', 'draftsync' ),
					size_format( $max_total_size )
				)
			);
		}

		// 8. Required entry check: word/document.xml must exist.
		if ( false === $zip->locateName( 'word/document.xml' ) ) {
			$zip->close();
			return new WP_Error(
				'gdtg_docx_no_document',
				__( 'Archive does not contain a valid document.', 'draftsync' )
			);
		}

		$zip->close();
		return true;
	}
}
