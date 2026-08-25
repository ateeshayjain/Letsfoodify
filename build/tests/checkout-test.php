<?php
/**
 * Tests the pure half of inc/checkout-flow.php — no WordPress.
 *
 * The thing under test is a SENTENCE, which sounds like a strange thing to write
 * assertions about. It is the right thing to test: the cart page used to promise
 * "nothing new is added at the payment step" unconditionally, and shipping in
 * WooCommerce is resolved from an address the visitor has not given yet. The
 * copy read as verified and nothing had verified it — the same shape as an
 * absence check that cannot run.
 *
 *   php tests/checkout-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../theme/foodify/inc/checkout-flow.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

echo "── the cart may only promise what it knows ──\n";

$p = foodify_cart_promise( [ 'needs_shipping' => true, 'has_address' => false, 'shipping_cost' => null ] );
check( 'no address -> an estimate, not a promise', 'estimate' === $p['kind'] );
check( 'no address -> says where the number comes from', false !== strpos( $p['message'], 'PIN code' ) );
check( 'no address -> never claims the total will not change',
	false === stripos( $p['message'], 'nothing is added at checkout' ) );

// The dangerous case. An address is known but no rate resolved — a PIN outside
// every shipping zone, or a method not yet chosen. Treating that as free is how
// a total silently grows between the cart and the payment page.
$p = foodify_cart_promise( [ 'needs_shipping' => true, 'has_address' => true, 'shipping_cost' => null ] );
check( 'address but NO resolved rate -> still an estimate', 'estimate' === $p['kind'] );

$p = foodify_cart_promise( [ 'needs_shipping' => true, 'has_address' => true, 'shipping_cost' => 0.0 ] );
check( 'free shipping resolved -> a promise', 'promise' === $p['kind'] );
check( 'free shipping -> says so', false !== stripos( $p['message'], 'free shipping' ) );

$p = foodify_cart_promise( [ 'needs_shipping' => true, 'has_address' => true, 'shipping_cost' => 79.0 ] );
check( 'paid shipping resolved -> a promise', 'promise' === $p['kind'] );
check( 'paid shipping -> does NOT claim it is free', false === stripos( $p['message'], 'free' ) );
check( 'paid shipping -> says it is already in the total', false !== stripos( $p['message'], 'included in the total' ) );

$p = foodify_cart_promise( [ 'needs_shipping' => false, 'has_address' => false, 'shipping_cost' => null ] );
check( 'nothing to ship -> no line at all', 'none' === $p['kind'] && '' === $p['message'] );

// A missing key must not be read as zero. Shipping that costs nothing and
// shipping that is unknown are different answers.
$p = foodify_cart_promise( [ 'needs_shipping' => true, 'has_address' => true ] );
check( 'a missing shipping_cost key is unknown, not free', 'estimate' === $p['kind'] );

echo "── which pages are private ──\n";

check( 'cart is private',     true  === foodify_is_private_page( true, false, false ) );
check( 'checkout is private', true  === foodify_is_private_page( false, true, false ) );
check( 'account is private',  true  === foodify_is_private_page( false, false, true ) );
// The narrowness IS the feature. Widening this disables page caching for the
// whole storefront and undoes WP-04.
check( 'everything else stays cacheable', false === foodify_is_private_page( false, false, false ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
