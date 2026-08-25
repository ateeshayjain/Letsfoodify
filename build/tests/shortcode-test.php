<?php
/**
 * Tests the pure halves of inc/shortcodes.php with plain PHP — no WordPress.
 *
 * The free-shipping arithmetic is where this component can lie to a customer:
 * a bar that says "free shipping unlocked" while checkout charges for delivery
 * is worse than no bar at all. So the arithmetic is a pure function and it is
 * tested here rather than discovered on staging.
 *
 * Loads the REAL file behind a handful of no-op stubs, so the tests cannot
 * drift from the shipping code the way a copied-out helper would.
 *
 *   php tests/shortcode-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

// Enough WordPress to let the file load. None of it is exercised by these tests.
foreach ( [ 'add_shortcode', 'add_filter', 'add_action' ] as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return true; }" );
	}
}
foreach ( [ 'esc_html', 'esc_attr', 'esc_url', 'wp_kses_post' ] as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}( \$s = '' ) { return \$s; }" );
	}
}
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }

require __DIR__ . '/../theme/foodify/inc/shortcodes.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
function near( float $a, float $b ): bool { return abs( $a - $b ) < 0.01; }

echo "── free-shipping progress ──\n";

$s = foodify_shipping_progress_state( 400.0, null );
check( 'no free-shipping method -> not applicable', false === $s['applicable'] );

$s = foodify_shipping_progress_state( 400.0, 0.0 );
check( 'threshold of zero -> not applicable', false === $s['applicable'] );

$s = foodify_shipping_progress_state( 0.0, 599.0 );
check( 'empty cart -> 0%, full amount remaining', $s['applicable'] && near( $s['percent'], 0.0 ) && near( $s['remaining'], 599.0 ) );

$s = foodify_shipping_progress_state( 493.0, 599.0 );
check( 'part way -> exact remaining (106)', near( $s['remaining'], 106.0 ) );
check( 'part way -> percent 82.3', near( $s['percent'], 82.3 ) );
check( 'part way -> not yet qualified', false === $s['qualified'] );

$s = foodify_shipping_progress_state( 599.0, 599.0 );
check( 'exactly on the threshold qualifies', $s['qualified'] && near( $s['percent'], 100.0 ) && near( $s['remaining'], 0.0 ) );

$s = foodify_shipping_progress_state( 1200.0, 599.0 );
check( 'over the threshold -> 100%, never above', $s['qualified'] && near( $s['percent'], 100.0 ) );
check( 'over the threshold -> remaining is 0, never negative', near( $s['remaining'], 0.0 ) );

$s = foodify_shipping_progress_state( -50.0, 599.0 );
check( 'negative subtotal clamps to zero rather than inverting the bar', near( $s['percent'], 0.0 ) && near( $s['remaining'], 599.0 ) );

$s = foodify_shipping_progress_state( 0.01, 599.0 );
check( 'a single paisa does not round up to qualified', false === $s['qualified'] );

echo "\n── Google review normalisation ──\n";

$r = foodify_normalise_review( [
	'author_name' => 'Rohit M.', 'rating' => 5, 'text' => 'Six minutes, actual dal chawal at 11,000 feet.',
	'relative_time_description' => '2 weeks ago', 'author_url' => 'https://example.com/u',
] );
check( 'a complete review normalises', is_array( $r ) && 'Rohit M.' === $r['author'] && 5 === $r['rating'] );

check( 'star-only review (no text) is dropped',
	null === foodify_normalise_review( [ 'author_name' => 'A', 'rating' => 5, 'text' => '   ' ] ) );
check( 'review with no author is dropped',
	null === foodify_normalise_review( [ 'author_name' => '', 'rating' => 5, 'text' => 'Good' ] ) );
check( 'rating of 0 is dropped',
	null === foodify_normalise_review( [ 'author_name' => 'A', 'rating' => 0, 'text' => 'Good' ] ) );
check( 'rating above 5 is dropped',
	null === foodify_normalise_review( [ 'author_name' => 'A', 'rating' => 6, 'text' => 'Good' ] ) );
check( 'missing optional fields become empty strings, not null',
	( $x = foodify_normalise_review( [ 'author_name' => 'A', 'rating' => 4, 'text' => 'Good' ] ) )
	&& '' === $x['relative'] && '' === $x['url'] );

printf( "\n  %d passed · %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
