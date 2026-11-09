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
check( 'free delivery -> says so, in the one name (decision 6)', false !== stripos( $p['message'], 'free delivery' ) && false === stripos( $p['message'], 'shipping' ) );

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

echo "── flat rate is hidden once free delivery applies (BUY-3) ──\n";
$keep = foodify_shipping_rates_to_keep( [ 'flat_rate:2' => 'flat_rate', 'free_shipping:1' => 'free_shipping' ] );
check( 'free + flat -> only free is offered (a stale ₹59 choice cannot survive)', [ 'free_shipping:1' ] === $keep );
check( 'below the minimum (no free rate) -> flat rate stays', [ 'flat_rate:2' ] === foodify_shipping_rates_to_keep( [ 'flat_rate:2' => 'flat_rate' ] ) );
check( 'other methods are left alone', [ 'free_shipping:1', 'local_pickup:3' ] === foodify_shipping_rates_to_keep( [ 'free_shipping:1' => 'free_shipping', 'flat_rate:2' => 'flat_rate', 'local_pickup:3' => 'local_pickup' ] ) );
check( 'no rates -> none', [] === foodify_shipping_rates_to_keep( [] ) );
$flow = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/checkout-flow.php' );
check( 'the rule is wired to woocommerce_package_rates', 1 === preg_match( "/add_filter\\(\\s*'woocommerce_package_rates'.*?foodify_shipping_rates_to_keep\\(/s", $flow ) );
// "Delivery | Delivery: ₹59" (UX recheck): the title that repeats the heading goes.
$amt = '<span class="woocommerce-Price-amount amount">₹59</span>';
check( 'a method titled "Delivery" shows its price alone', $amt === foodify_shipping_method_label( 'Delivery: ' . $amt, 'Delivery' ) );
check( '"Free delivery" keeps its words (it has no price to show)', 'Free delivery' === foodify_shipping_method_label( 'Free delivery', 'Free delivery' ) );
check( 'another method names itself', 'Express delivery: ' . $amt === foodify_shipping_method_label( 'Express delivery: ' . $amt, 'Express delivery' ) );
check( 'a title only starting with the heading is not cut', 'Delivery partner: ' . $amt === foodify_shipping_method_label( 'Delivery partner: ' . $amt, 'Delivery partner' ) );
check( 'the totals row is headed "Delivery"', 'Delivery' === foodify_shipping_heading() );
check( 'both are wired', 1 === preg_match( "/'woocommerce_shipping_package_name'.*?foodify_shipping_heading\\(/s", $flow )
	&& 1 === preg_match( "/'woocommerce_cart_shipping_method_full_label'.*?foodify_shipping_method_label\\(/s", $flow ) );
$boot = (string) file_get_contents( __DIR__ . '/../scripts/bootstrap.sh' );
check( 'the methods carry the customer titles "Free delivery" and "Delivery" (BUY-15)', str_contains( $boot, '"title":"Free delivery"' ) && str_contains( $boot, '"title":"Delivery"' ) );

echo "── an empty quantity never removes a cart line (BUY-6) ──\n";
check( 'an empty field does not apply', false === foodify_cart_qty_applies( '' ) && false === foodify_cart_qty_applies( '  ' ) );
check( 'a whole number applies, 0 included (typing 0 is a removal on purpose)', foodify_cart_qty_applies( '3' ) && foodify_cart_qty_applies( '0' ) && foodify_cart_qty_applies( ' 12 ' ) );
check( 'typos do not apply', ! foodify_cart_qty_applies( '2.5' ) && ! foodify_cart_qty_applies( '-1' ) && ! foodify_cart_qty_applies( 'abc' ) && ! foodify_cart_qty_applies( '1e2' ) );
$cjs = foodify_cart_qty_script();
check( 'the cart script runs the same test before it submits', str_contains( $cjs, '/^\d{1,4}$/' ) && 1 === preg_match( '/if\(bad\)return;.*setTimeout/s', $cjs ) );
check( 'the cart enqueues that script, not a copy', str_contains( $flow, "wp_add_inline_script( 'foodify-cart', foodify_cart_qty_script() )" ) );

echo "── a wrong coupon code says which, and what to do (BUY-9) ──\n";
check( 'not-exist -> names the code in capitals, says what to do',
	"We couldn't find the code BADCODE. Check the spelling, or ask the person who gave it to you." === foodify_coupon_error_text( 'Coupon "badcode" does not exist!', 105, 'badcode' ) );
check( 'no exclamation mark, no "Coupon"', ! str_contains( foodify_coupon_error_text( 'x', 105, 'badcode' ), '!' ) );
check( 'the own-referral message (code 100) is kept',
	'This is your own referral code — share it with a friend instead.' === foodify_coupon_error_text( 'This is your own referral code — share it with a friend instead.', 100, 'priya50' ) );
check( 'other WooCommerce messages pass through', 'Coupon usage limit has been reached.' === foodify_coupon_error_text( 'Coupon usage limit has been reached.', 106, 'x' ) );
check( 'a code with markup is escaped', ! str_contains( foodify_coupon_error_text( '', 105, '<b>x' ), '<B>' ) );

echo "── order received: what happens next, once (BUY-22, BUY-11) ──\n";
$t = foodify_order_next_text( [ 'estimate' => 'Delivers Thu 12 – Sat 14 Nov', 'is_cod' => true, 'total' => '₹644', 'email' => 'priya@example.com' ] );
check( 'it says when it arrives', str_contains( $t, 'Delivers Thu 12 – Sat 14 Nov.' ) );
check( 'what is still owed, on cash on delivery', str_contains( $t, 'Keep ₹644 ready in cash' ) );
check( 'plain text, so it survives whichever escaping the template applies', $t === strip_tags( $t ) );
check( 'and where the receipt went', str_contains( $t, 'priya@example.com' ) );
check( 'it does not repeat "Thank you. Your order has been received."', ! str_contains( $t, 'has been received' ) );
$t = foodify_order_next_text( [ 'estimate' => '', 'is_cod' => false, 'paid' => true, 'email' => 'a@b.in' ] );
check( 'no delivery option set -> no date, and no guessed one', ! str_contains( $t, 'Delivers' ) && str_contains( $t, 'Your payment is complete.' ) );
$t = foodify_order_next_text( [ 'is_cod' => false, 'paid' => false ] );
check( 'an online order not yet paid does not claim it is', ! str_contains( $t, 'payment is complete' ) );
check( 'customer data is escaped', ! str_contains( foodify_order_next_text( [ 'email' => '<script>' ] ), '<script>' ) );
check( 'the duplicate payment row is dropped on that page only', 1 === preg_match( "/woocommerce_get_order_item_totals.*?is_order_received_page\\(\\).*?unset\\( \\\$rows\\['payment_method'\\] \\)/s", $flow ) );

echo "── checkout PIN: state always, city only when empty, the date (BUY-16, BUY-11) ──\n";
$pjs = foodify_checkout_pin_script();
check( 'it cannot end its script block early', ! str_contains( $pjs, '</script' ) );
check( 'a city the customer typed is never overwritten', str_contains( $pjs, "(cur === '' || cur === auto[pre])" ) );
check( 'a PIN the table does not know says so, with the fix', str_contains( $pjs, "'PIN not found — type your city.'" ) );
check( 'a lookup that could not run is not "not found"', str_contains( $pjs, "if (res === 'error')" ) );
check( 'the date comes from the product page\'s own function', str_contains( $pjs, 'window.foodifyDelivery.answer(' ) );
check( 'and only while the delivery option is set', str_contains( $pjs, 'if (!cfg.delivery || !window.foodifyDelivery) return false;' ) );
$fields = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/checkout-fields.php' );
check( 'checkout passes the option (null when unset) and the shared script', str_contains( $fields, "'delivery' => \$delivery" ) && str_contains( $fields, 'foodify_delivery_script()' ) && str_contains( $fields, 'foodify_checkout_pin_script()' ) );

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

echo "── An address we do not deliver to stops the order (client decision 8 Nov) ──\n";
require_once __DIR__ . '/../theme/foodify/inc/delivery-estimate.php';
$zones = foodify_delivery_normalise_config( [
	'zones' => [
		[ 'label' => 'NCR', 'states' => [ 'DL' ], 'pin_prefixes' => [ '2013' ], 'min_days' => 1, 'max_days' => 2 ],
		[ 'label' => 'North', 'states' => [ 'HR', 'PB' ], 'pin_prefixes' => [], 'min_days' => 2, 'max_days' => 4 ],
	],
	'cutoff_hour' => 14, 'no_dispatch_weekdays' => [ 7 ], 'no_delivery_weekdays' => [ 7 ], 'holidays' => [],
] );
check( 'the test zones are a valid setting', null !== $zones );
$why = foodify_checkout_pin_refusal( '744101', [ 'city' => 'South Andaman', 'state' => 'AN' ], '', $zones );
check( 'a PIN in no zone is refused, naming the PIN and the place', str_contains( $why, "don't deliver to 744101 (South Andaman)" ) && str_contains( $why, 'Use an address we deliver to.' ) );
check( 'a PIN in a zone by state goes through', '' === foodify_checkout_pin_refusal( '110001', [ 'city' => 'New Delhi', 'state' => 'DL' ], '', $zones ) );
check( 'a PIN-prefix zone beats the state (201301 is UP, but 2013 is served)', '' === foodify_checkout_pin_refusal( '201301', [ 'city' => 'Gautam Buddha Nagar', 'state' => 'UP' ], '', $zones ) );
check( 'a PIN missing from the table is a likely typo, not a refusal', '' === foodify_checkout_pin_refusal( '999999', null, '', $zones ) );
check( 'a PIN filed under two states follows the state the customer chose — outside', '' !== foodify_checkout_pin_refusal( '744101', [ 'city' => 'X', 'state' => '' ], 'an', $zones ) );
check( '— and inside', '' === foodify_checkout_pin_refusal( '134101', [ 'city' => 'X', 'state' => '' ], 'HR', $zones ) );
check( 'no zones set: nothing to judge against, nothing is refused', '' === foodify_checkout_pin_refusal( '744101', [ 'city' => 'X', 'state' => 'AN' ], '', null ) );
$dlg = foodify_pin_block_dialog_html();
check( 'the pop-up is a labelled modal with the answer line and one way out', str_starts_with( $dlg, '<dialog class="fd-pin-block"' ) && str_contains( $dlg, 'aria-labelledby="fd-pin-block-title"' )
	&& str_contains( $dlg, 'data-fd-pin-block-msg' ) && str_contains( $dlg, '<form method="dialog"' ) && str_contains( $dlg, '>Change PIN code</button>' ) );
$pjs = foodify_checkout_pin_script();
check( 'the script blocks only on "unserviceable" and lifts the block on any other answer', str_contains( $pjs, "block(pre, pin, a.message)" ) && substr_count( $pjs, 'unblock(pre)' ) >= 5 );
check( 'Place order is refused while blocked (checkout_place_order returns false) and re-opens the pop-up', 1 === preg_match( "/checkout_place_order', function\(\)\{\s*var b = blocked\[active\(\)\]; if \(!b\) return true;\s*popup\(b\.msg\); return false;/", $pjs ) );
check( 'the lock survives WooCommerce re-rendering the button', str_contains( $pjs, "on('updated_checkout', lock)" ) );
check( 'a PIN already filled is checked as the page opens', str_contains( $pjs, "if (pin.length === 6) apply(pre, pin);" ) );
check( 'the server refuses it too', 1 === preg_match( "/'woocommerce_after_checkout_validation'.*?foodify_checkout_pin_refusal\(/s", $flow ) );
$fields2 = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/checkout-fields.php' );
check( 'the page receives the pop-up only where zones can refuse', str_contains( $fields2, "'blockDialog' => null !== \$delivery" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
