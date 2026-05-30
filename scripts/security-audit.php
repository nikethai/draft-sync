<?php
/**
 * Standalone static security audit for DraftSync plugin source.
 *
 * Partial stand-in for `wp plugin check` (Plugin Check, Plugin repo category)
 * when a full WordPress test environment is unavailable. It walks includes/ and
 * src/ and flags common Plugin Check / common-issue patterns.
 *
 * Output: --json for machine-readable, otherwise human-readable.
 * Exit:   0 = no errors, 1 = errors present (warnings allowed).
 */

$opts = getopt( '', [ 'json', 'root:' ] );
$root = $opts['root'] ?? dirname( __DIR__ );

$targets = [ $root . '/includes', $root . '/src' ];
$files   = [];
foreach ( $targets as $t ) {
	if ( is_dir( $t ) ) {
		$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $t ) );
		foreach ( $rii as $f ) {
			if ( $f->isFile() && preg_match( '/\.(php|js)$/', $f->getFilename() ) ) {
				$files[] = $f->getPathname();
			}
		}
	}
}

$findings = [];

function add_finding( array &$findings, string $severity, string $file, int $line, string $rule, string $msg ): void {
	$findings[] = compact( 'severity', 'file', 'line', 'rule', 'msg' );
}

function scan_file( string $file, string $root, array &$findings ): void {
	$rel    = str_replace( $root . '/', '', $file );
	$lines  = file( $file );
	$src    = file_get_contents( $file );
	$php    = preg_match_all( '/<\?php(.*?)\?>/s', $src, $m ) ? implode( "\n", $m[1] ) : $src;

	// 1. Real @-suppression of errors (not docblock, not quoted identifiers, not phpcs suppression)
	$stripped = preg_replace(
		[
			'/\/\*\*[\s\S]*?\*\//',          // entire docblock
			'/^\s*\*\s*@\w+/m',               // docblock annotation lines
			"/['\"]@[\w\/.-]+/",              // quoted @-prefixed identifiers (imports, emails)
			'/phpcs:(?:ignore|disable)/',     // phpcs suppression comments
		],
		'',
		$php
	);
	if ( preg_match_all( '/(?<![\w\/])@(?![\w\*])/', $stripped, $hits, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $hits[0] as $hit ) {
			$pos       = $hit[1];
			$line_no   = substr_count( substr( $php, 0, $pos ), "\n" ) + 1;
			$line_text = $lines[ $line_no - 1 ] ?? '';
			// Skip lines that contain phpcs suppressions or are inside comments
			if ( strpos( $line_text, 'phpcs:' ) !== false || strpos( $line_text, '*' ) === 0 ) {
				continue;
			}
			add_finding( $findings, 'warning', $rel, $line_no, 'no-silenced-errors', 'Possible @-suppression of error (Plugin Check flags these).' );
		}
	}

	// 2. Direct echo of $_GET / $_POST / $_REQUEST / $_SERVER
	if ( preg_match_all( '/echo\s+\$_(GET|POST|REQUEST|SERVER)\[/', $php, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			$pos     = strpos( $php, $hit );
			$line_no = substr_count( substr( $php, 0, $pos ), "\n" ) + 1;
			add_finding( $findings, 'error', $rel, $line_no, 'unsanitized-input', 'Echo of unescaped superglobal value — must use esc_* after sanitize.' );
		}
	}

	// 3. file_get_contents / fopen on user-supplied input
	if ( preg_match_all( '/(file_get_contents|fopen|readfile)\s*\(\s*\$_(GET|POST|REQUEST)/', $php, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			$pos     = strpos( $php, $hit );
			$line_no = substr_count( substr( $php, 0, $pos ), "\n" ) + 1;
			add_finding( $findings, 'error', $rel, $line_no, 'unvalidated-url', 'File read on user-supplied input — must validate and use wp_remote_get() instead.' );
		}
	}

	// 4. register_rest_route without permission_callback
	if ( preg_match_all( '/register_rest_route\s*\(\s*[^,]+,\s*[^,]+,\s*\[(.*?)\]\s*\)/s', $php, $routes, PREG_SET_ORDER ) ) {
		foreach ( $routes as $route ) {
			if ( ! preg_match( '/permission_callback/', $route[1] ) ) {
				$pos     = strpos( $php, $route[0] );
				$line_no = substr_count( substr( $php, 0, $pos ), "\n" ) + 1;
				add_finding( $findings, 'error', $rel, $line_no, 'rest-no-permission', 'register_rest_route() without permission_callback — Plugin Check will reject.' );
			}
		}
	}

	// 5. eval / create_function
	if ( preg_match_all( '/\b(eval|create_function)\s*\(/', $php, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			$pos     = strpos( $php, $hit );
			$line_no = substr_count( substr( $php, 0, $pos ), "\n" ) + 1;
			add_finding( $findings, 'error', $rel, $line_no, 'no-eval', 'eval/create_function usage — Plugin Check error.' );
		}
	}

	// 6. extract() on user input
	if ( preg_match_all( '/\bextract\s*\(\s*\$_(GET|POST|REQUEST)/', $php, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			$pos     = strpos( $php, $hit );
			$line_no = substr_count( substr( $php, 0, $pos ), "\n" ) + 1;
			add_finding( $findings, 'error', $rel, $line_no, 'no-extract', 'extract() on user input — variable overwrite risk.' );
		}
	}

	// 7. update_option with $_POST/$_GET without preceding nonce check (heuristic)
	if ( preg_match_all( '/update_option\s*\(\s*[^,]+,\s*\$_(GET|POST|REQUEST)/', $php, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			$pos     = strpos( $php, $hit );
			$line_no = substr_count( substr( $php, 0, $pos ), "\n" ) + 1;
			$window  = implode( "\n", array_slice( $lines, max( 0, $line_no - 31 ), 30 ) );
			if ( ! preg_match( '/check_admin_referer|wp_verify_nonce|wp_create_nonce/', $window ) ) {
				add_finding( $findings, 'warning', $rel, $line_no, 'missing-nonce', 'update_option() with user input but no nonce check found in the preceding 30 lines.' );
			}
		}
	}

	// 8. JS: dangerouslySetInnerHTML with unsanitized value
	if ( preg_match_all( '/dangerouslySetInnerHTML\s*=\s*\{\s*\{\s*__html\s*:\s*([^,}]+)/', $src, $hits ) ) {
		foreach ( $hits[1] as $idx => $val ) {
			if ( ! preg_match( '/sanitize|wp_kses|esc_/', $val ) ) {
				$pos     = strpos( $src, $hits[0][ $idx ] );
				$line_no = substr_count( substr( $src, 0, $pos ), "\n" ) + 1;
				add_finding( $findings, 'warning', $rel, $line_no, 'unsafe-html-attribute', 'dangerouslySetInnerHTML appears to receive unsanitized content.' );
			}
		}
	}
}

foreach ( $files as $f ) {
	scan_file( $f, $root, $findings );
}

$is_json = isset( $opts['json'] );
if ( $is_json ) {
	echo json_encode( [ 'findings' => $findings ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} else {
	$err_count  = count( array_filter( $findings, fn( $f ) => $f['severity'] === 'error' ) );
	$warn_count = count( array_filter( $findings, fn( $f ) => $f['severity'] === 'warning' ) );
	echo "DraftSync static security audit\n";
	echo str_repeat( '=', 50 ) . "\n";
	echo "Scanned " . count( $files ) . " files under $root/includes and $root/src.\n";
	echo "Findings: $err_count error(s), $warn_count warning(s).\n\n";
	if ( $findings ) {
		foreach ( $findings as $f ) {
			$icon = $f['severity'] === 'error' ? '✗' : '!';
			echo "  $icon {$f['file']}:{$f['line']} [{$f['rule']}] {$f['msg']}\n";
		}
	} else {
		echo "  ✓ No findings.\n";
	}
}

exit( $err_count > 0 ? 1 : 0 );
