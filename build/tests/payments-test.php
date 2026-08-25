<?php
/**
 * Tests the pure half of inc/payments.php — no WordPress, no gateway.
 *
 * Everything here is money. Two failure modes are worth naming before the
 * assertions, because they are what the tests are shaped around:
 *
 *  1. THE LABEL AND THE FEE DISAGREEING. The payment radio says "Save ₹25" and
 *     the fee line applies something else. The store has then lied on the
 *     payment screen, and it is the kind of lie that gets a screenshot.
 *
 *  2. KEEPING THE PREPAID DISCOUNT ON A COD ORDER. Pick "Pay now", let the
 *     total update, switch to COD, place the order. If the discount is read
 *     from a stale session instead of the submitted method, the store ships at
 *     ₹25 under and nothing anywhere reports an error.
 *
 *   php tests/payments-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../theme/foodify/inc/payments.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

$C = foodify_payment_defaults();

echo "── the prepaid discount ──\n";

check( 'razorpay earns the flat ₹25', 25.0 === foodify_prepaid_discount( 620.0, 'razorpay', $C ) );
check( 'COD earns nothing',            0.0 === foodify_prepaid_discount( 620.0, 'cod', $C ) );
check( 'an UNCHOSEN method earns nothing — not a default prepaid',
	0.0 === foodify_prepaid_discount( 620.0, '', $C ) );
check( 'an empty cart earns nothing',  0.0 === foodify_prepaid_discount( 0.0, 'razorpay', $C ) );

// The clamp. Without it a ₹20 cart pays −₹5 and WooCommerce renders it happily.
check( 'a discount never exceeds the cart',
	20.0 === foodify_prepaid_discount( 20.0, 'razorpay', $C ) );
check( 'a cart smaller than the discount never goes negative',
	foodify_prepaid_discount( 10.0, 'razorpay', $C ) <= 10.0 );

echo "── percentage mode ──\n";

$pct = array_merge( $C, [ 'prepaid_flat' => 0.0, 'prepaid_rate' => 0.05, 'prepaid_max' => 100.0 ] );
check( '5% of ₹620 is ₹31', 31.0 === foodify_prepaid_discount( 620.0, 'razorpay', $pct ) );
check( 'the ceiling binds on a big cart',
	100.0 === foodify_prepaid_discount( 8000.0, 'razorpay', $pct ) );
// Paise on a promotional line reads as a bug, and the label and the fee must
// round the SAME way or they disagree by a paisa on screen.
check( '5% of ₹617 rounds to whole rupees',
	31.0 === foodify_prepaid_discount( 617.0, 'razorpay', $pct ) );

$min = array_merge( $C, [ 'prepaid_min_cart' => 500.0 ] );
check( 'below the minimum cart, nothing', 0.0 === foodify_prepaid_discount( 499.0, 'razorpay', $min ) );
check( 'at the minimum cart, the discount', 25.0 === foodify_prepaid_discount( 500.0, 'razorpay', $min ) );

echo "── the label and the fee are ONE calculation ──\n";

foreach ( [ [ 620.0, $C ], [ 20.0, $C ], [ 617.0, $pct ], [ 8000.0, $pct ] ] as [ $cart, $cfg ] ) {
	$fee   = foodify_prepaid_discount( $cart, 'razorpay', $cfg );
	$label = foodify_gateway_saving_label( $cart, 'razorpay', $cfg );
	$shown = (float) preg_replace( '/[^0-9]/', '', $label );
	check( sprintf( 'cart ₹%.0f: label says ₹%.0f, fee applies ₹%.0f', $cart, $shown, $fee ),
		$shown === $fee );
}
check( 'COD gets no saving label', '' === foodify_gateway_saving_label( 620.0, 'cod', $C ) );

echo "── which method is authoritative ──\n";

// THE ONE THAT MATTERS. The session says razorpay because that is what they
// picked a moment ago; the POST says cod because that is what they are placing
// the order with. The POST must win.
check( 'the SUBMITTED method beats a stale session',
	'cod' === foodify_chosen_payment_method( [ 'payment_method' => 'cod' ], 'razorpay' ) );
check( 'and the discount goes with it',
	0.0 === foodify_prepaid_discount(
		620.0,
		foodify_chosen_payment_method( [ 'payment_method' => 'cod' ], 'razorpay' ),
		$C
	) );

check( 'the session is used when nothing was posted',
	'razorpay' === foodify_chosen_payment_method( [], 'razorpay' ) );
check( 'nothing anywhere -> empty, which earns no discount',
	'' === foodify_chosen_payment_method( [], null ) );

// update_order_review sends the form as a `post_data` query string.
check( 'the method is found inside post_data',
	'cod' === foodify_chosen_payment_method(
		[ 'post_data' => 'billing_city=Noida&payment_method=cod&terms=1' ], 'razorpay' ) );
check( 'a direct field still outranks post_data',
	'razorpay' === foodify_chosen_payment_method(
		[ 'payment_method' => 'razorpay', 'post_data' => 'payment_method=cod' ], null ) );
check( 'an empty posted value falls through rather than blanking the choice',
	'razorpay' === foodify_chosen_payment_method( [ 'payment_method' => '' ], 'razorpay' ) );
check( 'malformed post_data does not throw or win',
	'razorpay' === foodify_chosen_payment_method( [ 'post_data' => '%%%' ], 'razorpay' ) );

echo "── COD availability ──\n";

// Ships uncapped ON PURPOSE. Capping COD is a commercial decision about refused
// deliveries that the client has not taken, and turning it on quietly would
// start refusing orders they expect to receive.
$s = foodify_cod_availability( 50000.0, $C );
check( 'by default COD is never refused, at any value', true === $s['available'] );

$cap = array_merge( $C, [ 'cod_max_value' => 3000.0 ] );
check( 'under the cap, allowed',  true  === foodify_cod_availability( 2999.0, $cap )['available'] );
check( 'exactly at the cap, allowed', true === foodify_cod_availability( 3000.0, $cap )['available'] );
check( 'over the cap, refused',   false === foodify_cod_availability( 3001.0, $cap )['available'] );
check( 'the refusal names the number and what to do instead',
	false !== strpos( foodify_cod_availability( 3001.0, $cap )['reason'], '3,000' )
	&& false !== stripos( foodify_cod_availability( 3001.0, $cap )['reason'], 'pay online' ) );

echo "── what counts as cash on delivery ──\n";

check( 'cod is COD',            true  === foodify_is_cod( 'cod', $C ) );
check( 'razorpay is not',       false === foodify_is_cod( 'razorpay', $C ) );
// A second COD-style gateway (a courier's own cash collection) must be
// declarable, or it silently earns the prepaid discount.
$two = array_merge( $C, [ 'cod_methods' => [ 'cod', 'cod_delhivery' ] ] );
check( 'a second cash gateway can be declared', true === foodify_is_cod( 'cod_delhivery', $two ) );
check( 'and it earns no prepaid discount', 0.0 === foodify_prepaid_discount( 620.0, 'cod_delhivery', $two ) );
check( 'while the default config would have PAID it the discount — which is the bug',
	25.0 === foodify_prepaid_discount( 620.0, 'cod_delhivery', $C ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
