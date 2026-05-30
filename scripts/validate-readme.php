<?php
/**
 * Standalone WordPress.org readme.txt validator.
 *
 * Mirrors the format rules enforced by https://wordpress.org/plugins/about/validator/
 * and the WP.org readme parser used by the directory. Pass --file=<path> to validate
 * a custom path; defaults to ./readme.txt.
 *
 * Exit codes:
 *   0 = no errors (warnings allowed)
 *   1 = one or more errors found
 *
 * Usage: php scripts/validate-readme.php [--file=path/to/readme.txt] [--json]
 */

$opts = getopt( '', [ 'file:', 'json' ] );
$file = $opts['file'] ?? __DIR__ . '/../readme.txt';
$json = isset( $opts['json'] );

if ( ! file_exists( $file ) ) {
	fwrite( STDERR, "readme.txt not found: $file\n" );
	exit( 1 );
}

$lines   = file( $file, FILE_IGNORE_NEW_LINES );
$content = file_get_contents( $file );

$errors   = [];
$warnings = [];

/** Helper: line number for a given regex match */
function line_of( array $lines, int $idx ): string {
	$line = $lines[ $idx ] ?? '';
	return 'line ' . ( $idx + 1 ) . ': ' . trim( $line );
}

// 1. Header must start with === Plugin Name ===
if ( ! isset( $lines[0] ) || ! preg_match( '/^===\s+.+?\s+===\s*$/', $lines[0] ) ) {
	$errors[] = 'First line must be a plugin name header in the form "=== Plugin Name ==="';
}

// 2. Required metadata fields (case-sensitive)
$required_fields = [
	'Contributors:',
	'Tags:',
	'Requires at least:',
	'Tested up to:',
	'Stable tag:',
	'License:',
	'License URI:',
];
foreach ( $required_fields as $field ) {
	$found = false;
	foreach ( $lines as $i => $line ) {
		if ( strpos( $line, $field ) === 0 ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		$errors[] = "Required metadata field missing: $field";
	}
}

// 3. Tags count <= 5 (per WP.org rules)
foreach ( $lines as $i => $line ) {
	if ( preg_match( '/^Tags:\s*(.+)$/', $line, $m ) ) {
		$tags    = array_map( 'trim', explode( ',', $m[1] ) );
		$bad_tag = false;
		foreach ( $tags as $tag ) {
			// Tags must be lowercase, ASCII letters/digits/dashes, no spaces.
			if ( ! preg_match( '/^[a-z0-9_-]+$/', $tag ) ) {
				$errors[] = line_of( $lines, $i ) . " — invalid tag '$tag' (lowercase, no spaces, ASCII only)";
				$bad_tag  = true;
			}
		}
		if ( count( $tags ) > 5 ) {
			$errors[] = line_of( $lines, $i ) . ' — too many tags (' . count( $tags ) . ' > 5)';
		}
		break;
	}
}

// 4. Short description length: should be <= 150 chars
foreach ( $lines as $i => $line ) {
	if ( strpos( $line, 'Contributors:' ) === 0 ) {
		// Description is the first non-empty paragraph after the metadata block.
		$j = $i + 1;
		while ( $j < count( $lines ) && trim( $lines[ $j ] ) === '' ) {
			$j++;
		}
		if ( $j < count( $lines ) ) {
			$desc = trim( $lines[ $j ] );
			if ( strlen( $desc ) > 150 ) {
				$warnings[] = 'Short description is ' . strlen( $desc ) . ' characters (>150 may be truncated in directory)';
			}
		}
		break;
	}
}

// 5. Stable tag matches version pattern
foreach ( $lines as $i => $line ) {
	if ( preg_match( '/^Stable tag:\s*(.+)$/', $line, $m ) ) {
		$stable = trim( $m[1] );
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?(-[a-z0-9.-]+)?$/i', $stable ) ) {
			$errors[] = line_of( $lines, $i ) . " — Stable tag '$stable' must be a numeric version (e.g. 0.2.0 or 1.2.3-rc1)";
		}
		break;
	}
}

// 6. Required top-level sections
$required_sections = [
	'Description',
	'Installation',
	'Frequently Asked Questions',
	'Screenshots',
	'Changelog',
];
foreach ( $required_sections as $section ) {
	$pattern = '/^==\s+' . preg_quote( $section, '/' ) . '\s+==\s*$/m';
	if ( ! preg_match( $pattern, $content ) ) {
		$errors[] = "Required section missing: == $section ==";
	}
}

// 7. Upgrade Notice is required when Stable tag differs from previous changelog version
// (we cannot detect that here without history, so warn but do not error).
if ( ! preg_match( '/^==\s+Upgrade Notice\s+==\s*$/m', $content ) ) {
	$warnings[] = 'No == Upgrade Notice == section (recommended for any non-initial release)';
}

// 8. Screenshots section must have at least 1 numbered entry, each matching screenshot-N.(png|jpg|jpeg|gif)
if ( preg_match( '/^==\s+Screenshots\s+==\s*$(.+?)(?=^==\s+|\Z)/sm', $content, $m ) ) {
	$body  = $m[1];
	$items = preg_match_all( '/^\s*(\d+)\.\s+(.+)$/m', $body, $matches );
	if ( $items === 0 ) {
		$errors[] = 'Screenshots section is empty; list at least one numbered screenshot with caption';
	} else {
		$nums = array_map( 'intval', $matches[1] );
		$sorted = $nums;
		sort( $sorted );
		if ( $nums !== $sorted ) {
			$errors[] = 'Screenshots list is not in ascending order';
		}
		if ( $nums[0] !== 1 ) {
			$errors[] = 'Screenshots list must start at 1 (got ' . $nums[0] . ')';
		}
	}
}

// 9. Stable tag consistency with plugin header (if both exist)
$plugin_header = file_get_contents( __DIR__ . '/../draftsync.php' );
if ( preg_match( '/Version:\s*([\d.]+)/', $plugin_header, $vm ) ) {
	$version = $vm[1];
	foreach ( $lines as $i => $line ) {
		if ( preg_match( '/^Stable tag:\s*(.+)$/', $line, $m ) ) {
			if ( trim( $m[1] ) !== $version ) {
				$errors[] = line_of( $lines, $i ) . " — Stable tag '{$m[1]}' does not match main plugin header Version '$version'";
			}
			break;
		}
	}
}

// 10. License value must be in the known list
foreach ( $lines as $i => $line ) {
	if ( preg_match( '/^License:\s*(.+)$/', $line, $m ) ) {
		$known = [ 'GPL-2.0', 'GPL-2.0-or-later', 'GPLv2', 'GPLv2-or-later', 'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0' ];
		$value = trim( $m[1] );
		if ( ! in_array( $value, $known, true ) ) {
			$warnings[] = line_of( $lines, $i ) . " — License value '$value' is not in the WP.org-allowed list (may still be valid; manual review)";
		}
		break;
	}
}

// 11. Detect any tab characters in readme.txt (not allowed by parser)
foreach ( $lines as $i => $line ) {
	if ( strpos( $line, "\t" ) !== false ) {
		$errors[] = line_of( $lines, $i ) . ' — readme.txt must not contain tab characters';
		break;
	}
}

// 12. Detect trailing whitespace on lines (cleanup warning)
foreach ( $lines as $i => $line ) {
	if ( preg_match( '/\s+$/', $line ) ) {
		$warnings[] = line_of( $lines, $i ) . ' — trailing whitespace';
		break;
	}
}

// Output
if ( $json ) {
	echo json_encode(
		[
			'file'     => realpath( $file ),
			'errors'   => $errors,
			'warnings' => $warnings,
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
} else {
	$rel = str_replace( dirname( __DIR__ ), '.', realpath( $file ) );
	echo "readme.txt validator — $rel\n";
	echo str_repeat( '=', 50 ) . "\n";
	if ( $errors ) {
		echo "ERRORS (" . count( $errors ) . "):\n";
		foreach ( $errors as $e ) {
			echo "  ✗ $e\n";
		}
	}
	if ( $warnings ) {
		echo "\nWARNINGS (" . count( $warnings ) . "):\n";
		foreach ( $warnings as $w ) {
			echo "  ! $w\n";
		}
	}
	if ( ! $errors && ! $warnings ) {
		echo "  ✓ All checks passed.\n";
	} elseif ( ! $errors ) {
		echo "\n  ✓ No errors. Warnings may be acceptable but should be reviewed.\n";
	} else {
		echo "\n  ✗ Validation failed.\n";
	}
}

exit( $errors ? 1 : 0 );
