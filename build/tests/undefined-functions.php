<?php
/**
 * Every foodify_* function the theme CALLS must be DEFINED somewhere in it.
 *
 * WHY THIS EXISTS
 * ---------------
 * `foodify_attributed_coupons()` was called twice in inc/coupon-attribution.php
 * and defined nowhere. An earlier verification pass extracted a duplicated rule
 * "into one function", updated both call sites, and never wrote the function.
 * A document reported it fixed.
 *
 * Every `php -l` in this repository passed for weeks afterwards, because an
 * undefined function is a RUNTIME error, not a syntax error — and nothing here
 * has ever run against PHP with WooCommerce loaded. The blast radius was the
 * money path: any order using a partner coupon reaching `processing` would have
 * fatalled mid-status-transition, on a live store, silently until it wasn't.
 *
 * `php -l` proves a file parses. It does not prove the file works, and a
 * document saying something was fixed is not evidence. This is the cheapest
 * check that would have caught it, so it runs in the gate.
 *
 *   php tests/undefined-functions.php
 */
declare( strict_types = 1 );

$theme = __DIR__ . '/../theme/foodify';
$files = [];
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme ) );
foreach ( $it as $f ) {
	if ( $f->isFile() && 'php' === strtolower( $f->getExtension() ) ) {
		$files[] = $f->getPathname();
	}
}
sort( $files );

$defined = [];
$called  = [];   // name => [file:line, ...]

foreach ( $files as $file ) {
	$src    = file_get_contents( $file );
	$tokens = token_get_all( $src );
	$rel    = str_replace( dirname( __DIR__ ) . '/', '', $file );

	for ( $i = 0; $i < count( $tokens ); $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) ) {
			continue;
		}
		// A declaration: `function name(`
		if ( T_FUNCTION === $t[0] ) {
			for ( $j = $i + 1; $j < count( $tokens ); $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$defined[ strtolower( $tokens[ $j ][1] ) ] = true;
				}
				break;   // anything else (`(`, `&`) means a closure or an arrow fn
			}
			continue;
		}
		// A call: T_STRING followed by `(`, not preceded by `function`/`->`/`::`.
		if ( T_STRING !== $t[0] ) {
			continue;
		}
		$name = strtolower( $t[1] );
		if ( 0 !== strpos( $name, 'foodify_' ) ) {
			continue;
		}
		$prev = $tokens[ $i - 1 ] ?? null;
		if ( is_array( $prev ) && in_array( $prev[0], [ T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ], true ) ) {
			continue;
		}
		$next = null;
		for ( $k = $i + 1; $k < count( $tokens ); $k++ ) {
			if ( is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
				continue;
			}
			$next = $tokens[ $k ];
			break;
		}
		if ( '(' === $next ) {
			$called[ $name ][] = $rel . ':' . $t[2];
		}
	}
}

// Names passed as callable STRINGS — add_action( 'hook', 'foodify_thing' ) — are
// calls too, and they fail exactly as loudly at runtime.
//
// ONLY the second argument. The first is the HOOK name, and this theme names its
// own hooks `foodify_*` too — `apply_filters( 'foodify_pincode_endpoint', … )`
// is a filter this theme OFFERS, not a function it calls. A first draft of this
// scanner reported all four of them as fatal bugs, which is the failure mode a
// gate has to avoid above all others: cry wolf once and it gets ignored, and the
// real one goes with it.
foreach ( $files as $file ) {
	$rel = str_replace( dirname( __DIR__ ) . '/', '', $file );
	foreach ( file( $file ) as $n => $line ) {
		// Second argument of a hook registration or a shortcode, plus any
		// callable handed to a higher-order function.
		//
		// THE FIRST VERSION OF THIS SCANNER MISSED THE LAST CASE and passed a
		// genuinely undefined `array_map( 'foodify_csv_cell', … )` that I found
		// by hand minutes after declaring the scanner working. A gate with a
		// hole in it is worse than no gate, because it is trusted.
		$patterns = [
			"/(?:add|remove)_(?:action|filter)\s*\(\s*'[^']*'\s*,\s*'(foodify_[a-z0-9_]+)'/",
			"/add_shortcode\s*\(\s*'[^']*'\s*,\s*'(foodify_[a-z0-9_]+)'/",
			"/(?:array_map|array_filter|array_walk|array_reduce|usort|uasort|uksort|call_user_func|call_user_func_array|register_shutdown_function)\s*\(\s*(?:[^,()]*,\s*)?'(foodify_[a-z0-9_]+)'/",
		];
		$hits = [];
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $line, $m ) ) {
				$hits = array_merge( $hits, $m[1] );
			}
		}
		if ( $hits ) {
			foreach ( $hits as $name ) {
				$called[ strtolower( $name ) ][] = $rel . ':' . ( $n + 1 );
			}
		}
	}
}

$missing = [];
foreach ( $called as $name => $sites ) {
	if ( ! isset( $defined[ $name ] ) ) {
		$missing[ $name ] = array_unique( $sites );
	}
}

printf( "scanned %d files · %d foodify_* functions defined · %d called\n\n", count( $files ), count( $defined ), count( $called ) );

if ( ! $missing ) {
	printf( "  \033[32mPASS\033[0m every foodify_* function called by the theme is defined in it\n\n1 passed, 0 failed\n" );
	exit( 0 );
}

foreach ( $missing as $name => $sites ) {
	printf( "  \033[31mFAIL\033[0m %s() is called but never defined\n", $name );
	foreach ( $sites as $site ) {
		printf( "         %s\n", $site );
	}
}
printf( "\n0 passed, %d failed\n", count( $missing ) );
exit( 1 );
