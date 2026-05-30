<?php
/**
 * DraftSync package dry-run builder.
 *
 * Mirrors the WP.org plugin directory package layout that wp.org's SVN importer
 * produces from a release ZIP. It excludes exactly what `.distignore` lists and
 * produces a local staging directory suitable for visual inspection and for
 * `wp plugin check` against a real WordPress install.
 *
 * Usage: php scripts/build-plugin-package.php [--out=/path/to/staging]
 *
 * Exit:  0 = package built; 1 = fatal error.
 */

$opts = getopt( '', [ 'out:' ] );
$root = dirname( __DIR__ );
$out  = $opts['out'] ?? $root . '/build/draftsync-package';
$slug = 'draftsync';

if ( is_dir( $out ) ) {
	$rii = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $out, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $rii as $f ) {
		$f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
	}
	rmdir( $out );
}
mkdir( $out, 0755, true );

// Read .distignore (gitignore-style patterns).
$distignore_path = $root . '/.distignore';
$patterns       = [];
if ( file_exists( $distignore_path ) ) {
	foreach ( file( $distignore_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$line = trim( $line );
		if ( $line === '' || strpos( $line, '#' ) === 0 ) {
			continue;
		}
		$patterns[] = $line;
	}
}

/**
 * Decide whether a relative path is excluded by any .distignore pattern.
 *
 * Supported semantics (deliberately narrow — matches what WP plugin .distignore
 * actually needs):
 *   - Bare name "node_modules" matches the directory or file at any depth
 *     AND any file/dir beneath it.
 *   - Glob with * and ? (e.g. ".env*", "*.log") matches as fnmatch.
 *   - Trailing slash marks a directory.
 */
function is_excluded( string $rel, array $patterns ): bool {
	$parts = explode( '/', $rel );
	foreach ( $patterns as $p ) {
		$is_dir = str_ends_with( $p, '/' );
		if ( $is_dir ) {
			$p = rtrim( $p, '/' );
		}
		$has_glob = strpbrank( $p, '*?[' );

		// 1. Exact path segment match at any depth
		foreach ( $parts as $segment ) {
			if ( $segment === $p ) {
				return true;
			}
			if ( $has_glob && fnmatch( $p, $segment ) ) {
				return true;
			}
		}

		// 2. Full path match (for ".env*" or full paths)
		if ( $has_glob && fnmatch( $p, basename( $rel ) ) ) {
			return true;
		}
	}
	return false;
}

if ( ! function_exists( 'strpbrank' ) ) {
	function strpbrank( string $s, string $chars ): bool {
		return strpbrk( $s, $chars ) !== false;
	}
}

// Walk repo and copy everything that isn't excluded.
$rii = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

$copied       = 0;
$skipped      = 0;
$skipped_list = [];
foreach ( $rii as $f ) {
	$abs = $f->getRealPath();
	$rel = ltrim( str_replace( $root, '', $abs ), '/' );
	if ( $rel === '' ) {
		continue;
	}
	// Exclude the output directory itself
	if ( strpos( $rel, 'build/draftsync-package' ) === 0 ) {
		continue;
	}
	if ( is_excluded( $rel, $patterns ) ) {
		$skipped++;
		$skipped_list[] = $rel;
		continue;
	}
	$dest = $out . '/' . $rel;
	if ( $f->isDir() ) {
		if ( ! is_dir( $dest ) ) {
			mkdir( $dest, 0755, true );
		}
	} else {
		if ( ! is_dir( dirname( $dest ) ) ) {
			mkdir( dirname( $dest ), 0755, true );
		}
		copy( $abs, $dest );
		$copied++;
	}
}

// List package contents.
$package_files = [];
$pkg_rii = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $out, RecursiveDirectoryIterator::SKIP_DOTS )
);
foreach ( $pkg_rii as $f ) {
	if ( $f->isFile() ) {
		$package_files[] = ltrim( str_replace( realpath( $out ), '', $f->getRealPath() ), '/' );
	}
}
sort( $package_files );

echo "DraftSync package dry run\n";
echo str_repeat( '=', 50 ) . "\n";
echo "Source: $root\n";
echo "Output: $out\n";
echo "Excluded patterns: " . count( $patterns ) . " from .distignore\n";
echo "Copied files: $copied\n";
echo "Excluded files: $skipped\n\n";

echo "Package contents:\n";
foreach ( $package_files as $pf ) {
	echo "  $pf\n";
}

echo "\nExcluded top-level entries:\n";
$tops = [];
foreach ( $skipped_list as $s ) {
	$top           = explode( '/', $s )[0];
	$tops[ $top ] = true;
}
foreach ( array_keys( $tops ) as $t ) {
	echo "  $t\n";
}

$required = [
	"$slug.php" => 'main plugin file',
	'readme.txt' => 'plugin readme',
	'LICENSE' => 'license file',
	'uninstall.php' => 'uninstall cleanup',
	'languages/draftsync.pot' => 'translation template',
];
echo "\nRequired file check:\n";
$missing = [];
foreach ( $required as $file => $why ) {
	$exists = in_array( $file, $package_files, true );
	echo '  ' . ( $exists ? '✓' : '✗' ) . " $file ($why)\n";
	if ( ! $exists ) {
		$missing[] = $file;
	}
}

if ( $missing ) {
	echo "\n✗ Package is missing required files. Submission would fail.\n";
	exit( 1 );
}
echo "\n✓ Package dry run complete and all required files present.\n";
exit( 0 );
