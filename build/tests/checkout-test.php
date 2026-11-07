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

echo "── the order-received page wears the site's header ──\n";

check( 'checkout keeps its stripped header',  'header-checkout' === foodify_checkout_part_slug( 'header-checkout', false ) );
check( 'checkout keeps its stripped footer',  'footer-checkout' === foodify_checkout_part_slug( 'footer-checkout', false ) );
// "← Back to cart" after an order points at an empty cart.
check( 'order received gets the site header', 'header' === foodify_checkout_part_slug( 'header-checkout', true ) );
check( 'and the site footer',                 'footer' === foodify_checkout_part_slug( 'footer-checkout', true ) );
check( 'any other part passes through',       'header' === foodify_checkout_part_slug( 'header', true ) );
check( 'unknown parts are left alone',        'promo'  === foodify_checkout_part_slug( 'promo', true ) );

echo "── PIN codes are answered on-site, with the right state ──\n";

require_once __DIR__ . '/../theme/foodify/inc/pincodes.php';
$pins = foodify_pin_data( __DIR__ . '/../theme/foodify/assets/data/pincodes.json' );
check( 'the bundled table loads and is the whole country (15,000+ PINs)', count( $pins['pins'] ?? [] ) > 15000 );
check( 'it carries its licence attribution', false !== stripos( (string) ( $pins['source'] ?? '' ), 'Open Data License' ) );
$h = foodify_pin_lookup( '201304', $pins );
check( '201304 is Gautam Buddha Nagar, UP', $h && 'Gautam Buddha Nagar' === $h['city'] && 'UP' === $h['state'] );
// The state decides the GST split and the shipping zone. Old India Post
// snapshots predate Telangana and Ladakh.
check( 'Hyderabad (500001) is Telangana, not Andhra Pradesh', 'TS' === ( foodify_pin_lookup( '500001', $pins )['state'] ?? '' ) );
check( 'Leh (194101) is Ladakh, not Jammu & Kashmir', 'LA' === ( foodify_pin_lookup( '194101', $pins )['state'] ?? '' ) );
check( 'East Godavari (533101) stays Andhra Pradesh', 'AP' === ( foodify_pin_lookup( '533101', $pins )['state'] ?? '' ) );
check( 'a PIN filed under two states gives no state — the customer picks', '' === ( foodify_pin_lookup( '396235', $pins )['state'] ?? 'x' ) );
check( 'an unknown PIN is null, not a guess', null === foodify_pin_lookup( '999999', $pins ) );
check( 'five digits is null', null === foodify_pin_lookup( '20130', $pins ) );
check( 'no city carries an India Post tag like "(CGH)"', ! preg_grep( '/\(/', $pins['districts'] ?? [] ) );
check( 'the response keeps the shape the checkout script reads',
	'Success' === foodify_pin_response( $h )[0]['Status'] && 'UP' === foodify_pin_response( $h )[0]['PostOffice'][0]['State']
	&& 'Error' === foodify_pin_response( null )[0]['Status'] );
check( 'a missing table degrades to no lookup, not an error', [] === foodify_pin_data( '/nonexistent/pincodes.json' ) );
// The reason this exists: checkout must not send PIN codes to a third party.
$src = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/checkout-fields.php' );
check( 'checkout\'s default endpoint is the site\'s own',
	1 === preg_match( "/apply_filters\\(\\s*'foodify_pincode_endpoint',\\s*rest_url\\(/", $src ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
