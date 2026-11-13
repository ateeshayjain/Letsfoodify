<?php
/**
 * WP-04 — tests the script-deferral decision.
 *
 * Deferring the wrong script is how a performance pass breaks a store: an inline
 * script attached to a handle prints straight after that handle's tag and is NOT
 * deferred, so deferring its dependency runs the inline code before the library
 * exists. This theme would break ITSELF that way — the PIN-code lookup in
 * inc/checkout-fields.php is inline on a jQuery-dependent handle, and it runs on
 * the checkout page.
 *
 * The decision is a pure function, so the rule is checked here rather than
 * discovered by a customer whose city field stopped filling in.
 *
 *   php tests/perf-test.php
 */
declare( strict_types = 1 );

// Load only the function under test; functions.php expects WordPress.
$src = file_get_contents( __DIR__ . '/../theme/foodify/functions.php' );
$start = strpos( $src, 'function foodify_defer_script_tag' );
$end   = strpos( $src, "\n}\n", $start ) + 3;
eval( substr( $src, $start, $end - $start ) );
$start = strpos( $src, 'function foodify_defer_blocked' );
$end   = strpos( $src, "\n}\n", $start ) + 3;
eval( substr( $src, $start, $end - $start ) );

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

const NEVER = [ 'jquery', 'jquery-core', 'jquery-migrate', 'wp-polyfill' ];
const TAG   = '<script src="https://letsfoodify.com/wp-includes/js/x.js" id="x-js"></script>';

function deferred( string $tag, string $handle, bool $inline = false ): bool {
	return str_contains( foodify_defer_script_tag( $tag, $handle, $inline, NEVER ), ' defer src=' );
}

echo "── script deferral ──\n";

check( 'an ordinary script is deferred', deferred( TAG, 'foodify-x' ) );

check( 'jQuery is never deferred',       ! deferred( TAG, 'jquery' ) );
check( 'jquery-core is never deferred',  ! deferred( TAG, 'jquery-core' ) );
check( 'wp-polyfill is never deferred',  ! deferred( TAG, 'wp-polyfill' ) );

check( 'a handle carrying inline data is left synchronous',
	! deferred( TAG, 'foodify-pincode', true ) );

check( 'an already-deferred tag is untouched',
	1 === substr_count(
		foodify_defer_script_tag( '<script defer src="/a.js"></script>', 'x', false, NEVER ),
		'defer'
	) );
check( 'an async tag is not also made defer',
	! str_contains( foodify_defer_script_tag( '<script async src="/a.js"></script>', 'x', false, NEVER ), 'defer' ) );
check( 'a module is left alone',
	! str_contains( foodify_defer_script_tag( '<script type="module" src="/a.js"></script>', 'x', false, NEVER ), 'defer' ) );

check( 'an inline-only tag with no src is untouched',
	'<script>var a=1;</script>' === foodify_defer_script_tag( '<script>var a=1;</script>', 'x', false, NEVER ) );

check( 'the src attribute survives deferral',
	str_contains( foodify_defer_script_tag( TAG, 'foodify-x', false, NEVER ), 'src="https://letsfoodify.com/wp-includes/js/x.js"' ) );

check( 'the real interaction: jQuery stays sync so the PIN lookup still runs',
	! deferred( TAG, 'jquery' ) && ! deferred( TAG, 'foodify-pincode', true ) );

echo "── what a synchronous script needs stays synchronous (first real WooCommerce render, 13 Nov 2026) ──\n";
// WordPress's own graph, as the shop page prints it: wp-i18n carries inline
// data and needs wp-hooks; wp-data carries inline data and needs a chain.
$deps = [
	'wp-i18n'        => [ 'wp-hooks' ],
	'wp-hooks'       => [],
	'wp-data'        => [ 'wp-compose', 'wp-i18n' ],
	'wp-compose'     => [ 'wp-element' ],
	'wp-element'     => [ 'react' ],
	'react'          => [ 'wp-polyfill' ],
	'wc-price-format' => [ 'wp-data' ],
	'foodify-x'      => [],
];
$blocked = foodify_defer_blocked( $deps, [ 'jquery', 'wp-i18n', 'wp-data' ] );
check( 'the dependency of an inline-carrying handle is blocked (wp-hooks under wp-i18n)', in_array( 'wp-hooks', $blocked, true ) );
check( 'and so is the whole chain under it (react under wp-data)', in_array( 'react', $blocked, true ) && in_array( 'wp-compose', $blocked, true ) );
check( 'the synchronous handles themselves are in the list', in_array( 'wp-i18n', $blocked, true ) && in_array( 'jquery', $blocked, true ) );
check( 'a script that only DEPENDS ON a synchronous one may still be deferred', ! in_array( 'wc-price-format', $blocked, true ) );
check( 'an unrelated script may still be deferred', ! in_array( 'foodify-x', $blocked, true ) );
check( 'a dependency cycle ends', [] !== foodify_defer_blocked( [ 'a' => [ 'b' ], 'b' => [ 'a' ] ], [ 'a' ] ) );
check( 'a handle nobody registered is harmless', [ 'ghost' ] === foodify_defer_blocked( [], [ 'ghost' ] ) );

printf( "\n  %d passed · %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
