<?php
/**
 * Lane B account features: Buy again on the home page, and cancel / return in
 * My Account.
 *
 * Three rules worth defending by name:
 *   - A cancel is refused once the order is DISPATCHED (completed, here).
 *   - Money goes back only for a PAID, PREPAID order; COD and unpaid take none.
 *   - The Buy again shelf never reaches a guest page — a cached home page that
 *     carried one customer's purchases would show them to everyone.
 *
 *   php tests/account-actions-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/product-spec.php';   // the grid card's helpers the shelf reuses (BROWSE-7)
require __DIR__ . '/../theme/foodify/inc/cart-stepper.php';  // the card control the shelf shares
require __DIR__ . '/../theme/foodify/inc/buy-again.php';
require __DIR__ . '/../theme/foodify/inc/order-actions.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

$theme = __DIR__ . '/../theme/foodify';

/* ========================================================================
 * 7 · Buy again
 * ===================================================================== */
echo "── buy again: what is offered ──\n";
$orders = [
	[ 'id' => 10, 'status' => 'completed',  'date' => 1000, 'product_ids' => [ 1, 2 ] ],
	[ 'id' => 11, 'status' => 'processing', 'date' => 3000, 'product_ids' => [ 3, 1 ] ],
	[ 'id' => 12, 'status' => 'cancelled',  'date' => 4000, 'product_ids' => [ 9 ] ],
	[ 'id' => 13, 'status' => 'wc-completed', 'date' => 2000, 'product_ids' => [ 4, 5 ] ],
	[ 'id' => 14, 'status' => 'refunded',   'date' => 5000, 'product_ids' => [ 8 ] ],
	[ 'id' => 15, 'status' => 'pending',    'date' => 6000, 'product_ids' => [ 7 ] ],
];
$all = [ 1, 2, 3, 4, 5, 6, 7, 8, 9 ];
check( 'most recent order first, line order within it, de-duplicated', [ 3, 1, 4, 5 ] === foodify_buy_again_ids( $orders, $all ) );
check( 'cancelled, refunded and pending orders do not count as bought', ! array_intersect( [ 7, 8, 9 ], foodify_buy_again_ids( $orders, $all, 10 ) ) );
check( 'the wc- prefix is understood',                  in_array( 4, foodify_buy_again_ids( $orders, $all ), true ) );
check( 'no longer purchasable is skipped, and the next one fills the slot', [ 1, 4, 5, 2 ] === foodify_buy_again_ids( $orders, [ 1, 2, 4, 5 ] ) );
check( 'at most four by default',                       4 === count( foodify_buy_again_ids( $orders, $all ) ) );
check( 'the limit is honoured',                          [ 3 ] === foodify_buy_again_ids( $orders, $all, 1 ) );
check( 'no orders, no shelf',                            [] === foodify_buy_again_ids( [], $all ) );
check( 'orders but nothing purchasable, no shelf',       [] === foodify_buy_again_ids( $orders, [] ) );
check( 'a zero limit is nothing, not everything',        [] === foodify_buy_again_ids( $orders, $all, 0 ) );
$tie = [
	[ 'id' => 20, 'status' => 'completed', 'date' => 500, 'product_ids' => [ 1 ] ],
	[ 'id' => 21, 'status' => 'completed', 'date' => 500, 'product_ids' => [ 2 ] ],
];
check( 'two orders in the same second: the later order id first', [ 2, 1 ] === foodify_buy_again_ids( $tie, $all ) );

echo "── buy again: the cache ──\n";
check( 'a signed-in front page is private',             true === foodify_buy_again_private_response( true, true ) );
check( 'a guest front page stays cacheable (WP-04)',    false === foodify_buy_again_private_response( true, false ) );
check( 'other pages are not touched by this file',      false === foodify_buy_again_private_response( false, true ) );
$src = (string) file_get_contents( $theme . '/inc/buy-again.php' );
check( 'the shortcode renders only for a logged-in user', 1 === preg_match( "~add_shortcode\(\s*'foodify_buy_again'.*?if \( ! is_user_logged_in\(\)~s", $src ) );
check( 'the private response sends no-store and the X-Foodify-Private marker',
	str_contains( $src, "Cache-Control: no-cache, no-store, must-revalidate, max-age=0, private" ) && str_contains( $src, "X-Foodify-Private: 1" ) && str_contains( $src, 'DONOTCACHEPAGE' ) );
$home = (string) file_get_contents( $theme . '/templates/front-page.html' );
check( 'the home page carries the shelf',               1 === substr_count( $home, '[foodify_buy_again]' ) );
check( 'it sits under the hero, before the trust strip',
	strpos( $home, 'foodify/hero' ) < strpos( $home, '[foodify_buy_again]' ) && strpos( $home, '[foodify_buy_again]' ) < strpos( $home, 'foodify/trust-strip' ) );

echo "── buy again: the markup ──\n";
$shelf = foodify_buy_again_html( [
	[ 'id' => 3, 'name' => 'Rajma <b>Chawal</b>', 'url' => '/product/rajma/', 'price' => '₹210', 'img' => '', 'add_url' => '/?add-to-cart=3' ],
	[ 'id' => 4, 'name' => 'Lemon Poha', 'url' => '/product/poha/', 'price' => '₹99', 'img' => '/i/poha.jpg', 'add_url' => '/?add-to-cart=4' ],
] );
check( 'nothing to offer renders nothing at all',        '' === foodify_buy_again_html( [] ) );
check( 'one card per product',                           2 === substr_count( $shelf, 'class="fd-again__item"' ) );
check( 'a name is escaped',                              str_contains( $shelf, 'Rajma &lt;b&gt;Chawal&lt;/b&gt;' ) && ! str_contains( $shelf, '<b>Chawal' ) );
check( 'Add to cart works without JavaScript (a real add-to-cart link)', str_contains( $shelf, 'href="/?add-to-cart=4" rel="nofollow"' ) );
check( 'the button names its product for a screen reader', str_contains( $shelf, 'aria-label="Add Lemon Poha to cart"' ) );
check( 'each card carries the grid\'s own control: Add, then − 1 + (cart-stepper.php)',
	str_contains( $shelf, foodify_cart_control_html( 4, 'Lemon Poha', '/?add-to-cart=4', 0, 'fd-again__ctl' ) ) );
check( 'a missing image is a placeholder, not a broken <img>', str_contains( $shelf, '<span class="fd-again__img is-empty" aria-hidden="true"></span>' ) );
check( 'the section is on the wide rail and labelled',   str_contains( $shelf, 'class="fd-again alignwide" aria-labelledby="fd-again-title"' ) );
check( 'the shelf has no add-to-cart script of its own any more', ! function_exists( 'foodify_buy_again_script' ) && ! str_contains( $shelf, 'data-endpoint' ) );
// BROWSE-7 / ACCOUNT-20 / BROWSE-17: the card carries what the grid card carries, and says when it is in the cart.
$card = foodify_buy_again_html( [ [ 'id' => 5, 'name' => 'Dal Fry', 'url' => '/p/', 'price' => '₹175', 'add_url' => '/?add-to-cart=5',
	'chip' => [ 'label' => 'Hot water · 6 min', 'modifier' => 'fd-chip--hot' ], 'servings' => 2, 'veg' => true ] ] );
check( 'BROWSE-7: the prep line is the grid card\'s own', str_contains( $card, foodify_card_prep_html( [ 'label' => 'Hot water · 6 min', 'modifier' => 'fd-chip--hot' ], 2 ) ) && str_contains( $card, 'Hot water · 6 min' ) && str_contains( $card, 'Serves 2' ) );
check( 'BROWSE-7: the veg mark sits before the name', str_contains( $card, '<span class="fd-again__name">' . foodify_veg_mark_html( 'vegetarian' ) . 'Dal Fry</span>' ) );
check( 'BROWSE-7: no veg mark without a vegetarian declaration', ! str_contains( foodify_buy_again_html( [ [ 'id' => 6, 'name' => 'X', 'url' => '', 'price' => '', 'add_url' => '', 'veg' => false ] ] ), 'fd-veg' ) );
check( 'nothing about the cart is printed: the stepper reads it in the browser', str_contains( $card, 'data-fd-cartctl data-product-id="5"' ) && ! str_contains( $card, 'in cart' ) );

/* ========================================================================
 * 10 · Cancel
 * ===================================================================== */
echo "── cancel: only before dispatch ──\n";
foreach ( [ 'pending', 'on-hold', 'processing', 'wc-processing' ] as $s ) {
	check( "a {$s} order can be cancelled", foodify_can_cancel( [ 'status' => $s ] ) );
}
foreach ( [ 'completed', 'return-requested', 'cancelled', 'refunded', 'failed', 'checkout-draft', '' ] as $s ) {
	check( "a '{$s}' order cannot", ! foodify_can_cancel( [ 'status' => $s ] ) );
}
check( 'a processing order a courier already has cannot (the dispatched flag wins)', ! foodify_can_cancel( [ 'status' => 'processing', 'dispatched' => true ] ) );

echo "── cancel: whose order ──\n";
check( 'the order\'s own customer may act',              foodify_order_owner_ok( 42, 42 ) );
check( 'another customer may not',                       ! foodify_order_owner_ok( 42, 43 ) );
check( 'a guest order (customer 0) is nobody\'s to act on here', ! foodify_order_owner_ok( 0, 0 ) );

echo "── cancel: the money ──\n";
check( 'a paid prepaid order is refunded in full of what remains', [ 'refund' => true, 'amount' => 420.0 ] === foodify_cancel_refund_plan( [ 'is_cod' => false, 'paid' => true, 'remaining' => 420 ] ) );
check( 'a COD order is not refunded — it took no money', [ 'refund' => false, 'amount' => 0.0 ] === foodify_cancel_refund_plan( [ 'is_cod' => true, 'paid' => true, 'remaining' => 420 ] ) );
check( 'an unpaid prepaid order is not refunded',        [ 'refund' => false, 'amount' => 0.0 ] === foodify_cancel_refund_plan( [ 'is_cod' => false, 'paid' => false, 'remaining' => 420 ] ) );
check( 'nothing left to refund, no refund',              [ 'refund' => false, 'amount' => 0.0 ] === foodify_cancel_refund_plan( [ 'is_cod' => false, 'paid' => true, 'remaining' => 0 ] ) );
check( 'missing facts mean no refund, never a guess',    false === foodify_cancel_refund_plan( [] )['refund'] );

echo "── cancel: what the customer is told ──\n";
$plan = [ 'refund' => true, 'amount' => 1250.0 ];
check( 'before: a refund says how much and where',       'You paid ₹1,250. We send it back to the way you paid as soon as you cancel.' === foodify_cancel_money_line( $plan, false ) );
check( 'before: COD says nothing was paid',              str_contains( foodify_cancel_money_line( [ 'refund' => false ], true ), 'cash on delivery' ) );
check( 'after: no money involved',                       'Order #1194 is cancelled. Nothing was charged, so there is nothing to refund.' === foodify_cancel_message( '1194', [ 'refund' => false ], null ) );
check( 'after: refund sent',                             str_contains( foodify_cancel_message( '1194', $plan, true ), 'Your refund of ₹1,250 has been sent' ) );
$failed = foodify_cancel_message( '1194', $plan, false );
check( 'after: refund failed — still cancelled, and honest about the money',
	str_contains( $failed, 'is cancelled' ) && str_contains( $failed, 'could not send your ₹1,250 refund automatically' ) && str_contains( $failed, 'by hand' ) );
check( 'a failed refund is never described as refunded', ! str_contains( $failed, 'has been sent' ) );
check( 'rupees: Indian grouping',                        '₹1,25,000' === foodify_order_rupees( 125000 ) && '₹999' === foodify_order_rupees( 999 ) );
check( 'rupees: paise only when there are any',          '₹99.50' === foodify_order_rupees( 99.5 ) && '₹1,250' === foodify_order_rupees( 1250.0 ) );
$mail = foodify_manual_refund_email( [ 'number' => '1194', 'amount' => 420, 'error' => 'Gateway timeout', 'admin_url' => 'https://x/wp-admin/post.php?post=1194' ] );
check( 'the shop is told to refund by hand, with the amount and the gateway\'s reason',
	'Refund needed by hand: order #1194' === $mail['subject'] && str_contains( $mail['body'], '₹420' ) && str_contains( $mail['body'], 'Gateway timeout' ) );

/* ========================================================================
 * 10 · Returns
 * ===================================================================== */
echo "── returns: the window ──\n";
check( 'unset option = off',                             0 === foodify_return_window_days( false ) );
check( 'an empty string = off',                          0 === foodify_return_window_days( '' ) );
check( '"7" = 7 days',                                   7 === foodify_return_window_days( '7' ) );
check( 'an int is read as days',                         10 === foodify_return_window_days( 10 ) );
check( '"7 days" is refused, not guessed',               0 === foodify_return_window_days( '7 days' ) );
check( 'zero and absurd values are off',                 0 === foodify_return_window_days( '0' ) && 0 === foodify_return_window_days( '1000' ) );

$day  = 86400;
$done = [ 'status' => 'completed', 'completed_at' => 1000000 ];
check( 'off when the shop has set no window',            'off' === foodify_return_open( $done, 0, 1000000 )['state'] );
check( 'open on the day it was completed',               foodify_return_open( $done, 7, 1000000 )['open'] );
check( 'open at the last second of the window',          foodify_return_open( $done, 7, 1000000 + 7 * $day )['open'] );
check( 'closed one second later',                        'closed' === foodify_return_open( $done, 7, 1000000 + 7 * $day + 1 )['state'] );
check( 'the window counts from delivery when it is recorded',
	foodify_return_open( $done + [ 'delivered_at' => 1000000 + 4 * $day ], 7, 1000000 + 10 * $day )['open'] );
check( 'it says when it closes',                         1000000 + 7 * $day === foodify_return_open( $done, 7, 1000000 )['closes'] );
check( 'not before dispatch (processing is a cancel, not a return)', 'not_yet' === foodify_return_open( [ 'status' => 'processing' ], 7, 1 )['state'] );
check( 'already requested',                              'requested' === foodify_return_open( [ 'status' => 'wc-return-requested' ], 7, 1 )['state'] );
check( 'a requested return shows as requested even with returns off', 'requested' === foodify_return_open( [ 'status' => 'return-requested' ], 0, 1 )['state'] );
check( 'no date at all is unknown, not open',            'unknown' === foodify_return_open( [ 'status' => 'completed' ], 7, 1 )['state'] );
check( 'a cancelled order has no return',                ! foodify_return_open( [ 'status' => 'cancelled', 'completed_at' => 1 ], 7, 1 )['open'] );

echo "── returns: the reasons ──\n";
check( 'exactly four reasons',                           [ 'damaged', 'wrong_item', 'missing_item', 'quality' ] === array_keys( foodify_return_reasons() ) );
check( 'a listed reason is accepted',                    'wrong_item' === foodify_return_reason( ' Wrong_Item ' ) );
check( 'anything else is refused',                       null === foodify_return_reason( 'changed my mind' ) && null === foodify_return_reason( '' ) );
check( 'the note loses tags and runs of whitespace',     'hi there' === foodify_return_note( "<b>hi</b>\n\n  there" ) );
check( 'the note is capped at 500 characters',           500 === mb_strlen( foodify_return_note( str_repeat( 'दाल ', 300 ) ) ) );
$rmail = foodify_return_email( [ 'number' => '1194', 'reason' => 'damaged', 'note' => 'Pouch split', 'admin_url' => 'https://x/o' ] );
check( 'the shop email names the order and the reason',  'Return requested: order #1194 — Arrived damaged' === $rmail['subject'] && str_contains( $rmail['body'], 'Pouch split' ) );

/* ========================================================================
 * The markup on the view-order page
 * ===================================================================== */
echo "── the view-order block ──\n";
$base = [ 'order_id' => 1194, 'number' => '1194', 'care_email' => 'care@letsfoodify.com', 'nonce_cancel' => 'n1', 'nonce_return' => 'n2' ];
$c = foodify_order_actions_html( $base + [ 'cancel' => true, 'money' => 'Nothing has been charged for this order.', 'return' => [ 'state' => 'not_yet', 'closes' => 0 ] ] );
check( 'cancel is a two-step: a disclosure, then a confirm button', str_contains( $c, '<details class="fd-order-cancel" id="fd-cancel">' ) && str_contains( $c, 'Yes, cancel order #1194' ) );
check( 'the confirm says what happens to the money before it is pressed', str_contains( $c, 'Nothing has been charged for this order.' ) );
check( 'it posts with the action, the order and the nonce', str_contains( $c, 'name="foodify_order_action" value="cancel"' ) && str_contains( $c, 'name="order_id" value="1194"' ) && str_contains( $c, 'name="foodify_order_nonce" value="n1"' ) );
check( 'it is a POST, never a link',                     str_contains( $c, '<form method="post"' ) && ! str_contains( $c, 'href="?' ) );
check( 'no return form on an order still being packed',  ! str_contains( $c, 'fd-return' ) );

$r = foodify_order_actions_html( $base + [ 'cancel' => false, 'return' => [ 'state' => 'open', 'closes' => 1 ], 'closes_label' => 'Thu 12 Nov', 'delivered' => true ] );
check( 'an open return is a form with the four reasons, one required', 4 === substr_count( $r, 'type="radio" name="reason"' ) && 4 === substr_count( $r, ' required>' ) );
check( 'it says until when',                             str_contains( $r, 'You can report it until Thu 12 Nov.' ) );
check( 'the note is optional and capped in the browser too', str_contains( $r, 'maxlength="500"' ) && str_contains( $r, '(optional)' ) );
check( 'no cancel on a dispatched order',                ! str_contains( $r, 'fd-cancel' ) );
check( 'no photo upload (out of scope)',                 ! str_contains( $r, 'type="file"' ) );

$off = foodify_order_actions_html( $base + [ 'cancel' => false, 'return' => [ 'state' => 'off', 'closes' => 0 ], 'delivered' => true ] );
check( 'returns off: "Contact us about a problem with your order" + the care address',
	str_contains( $off, 'Contact us about a problem with your order: <a href="mailto:care@letsfoodify.com?subject=Order%20%231194">care@letsfoodify.com</a>' ) );
check( 'returns off: no form',                           ! str_contains( $off, '<form' ) );
check( 'returns off and no care address: nothing, rather than a dead line',
	'' === foodify_order_actions_html( [ 'care_email' => '' ] + $base + [ 'cancel' => false, 'return' => [ 'state' => 'off', 'closes' => 0 ], 'delivered' => true ] ) );
check( 'requested: says so, no second form',
	str_contains( foodify_order_actions_html( $base + [ 'cancel' => false, 'return' => [ 'state' => 'requested', 'closes' => 0 ] ] ), 'Problem reported.' ) );
// ACCOUNT-12: one name through the flow, the next step in one sentence, no "return" in customer copy.
$r2 = foodify_order_actions_html( $base + [ 'customer_email' => 'priya@example.com', 'cancel' => false, 'return' => [ 'state' => 'open', 'closes' => 1 ], 'closes_label' => 'Thu 12 Nov', 'delivered' => true ] );
check( 'ACCOUNT-12: "Report a problem" opens it, "Send report" sends it', str_contains( $r2, '>Report a problem</summary>' ) && str_contains( $r2, '>Send report</button>' ) );
check( 'ACCOUNT-12: it says what happens next and where the reply goes', str_contains( $r2, 'We will look into it and reply to priya@example.com, the email on this order.' ) );
$q2 = foodify_order_actions_html( $base + [ 'customer_email' => 'priya@example.com', 'cancel' => false, 'return' => [ 'state' => 'requested', 'closes' => 0 ] ] );
check( 'ACCOUNT-12: after sending, "Problem reported" + the same next step', str_contains( $q2, '<strong>Problem reported.</strong> We will look into it and reply to priya@example.com' ) );
$closed2 = foodify_order_actions_html( $base + [ 'cancel' => false, 'return' => [ 'state' => 'closed', 'closes' => 1 ], 'closes_label' => 'Mon 2 Nov' ] );
$visible = static fn( string $h ): string => (string) preg_replace( '/<[^>]+>/', ' ', $h );   // text, not class names or field values
check( 'ACCOUNT-12: the word "return" appears in no customer copy (open, sent, closed)', ! preg_match( '/return/i', $visible( $r2 . $q2 . $closed2 ) ) );
check( 'ACCOUNT-12: the next step without an email still says how', 'We will look into it and reply by email.' === foodify_report_next_step( '' ) );
check( 'closed: says when it closed and how to reach a person',
	str_contains( foodify_order_actions_html( $base + [ 'cancel' => false, 'return' => [ 'state' => 'closed', 'closes' => 1 ], 'closes_label' => 'Mon 2 Nov' ] ), 'closed on Mon 2 Nov' ) );
check( 'a cancelled order shows nothing',                '' === foodify_order_actions_html( $base + [ 'cancel' => false, 'return' => [ 'state' => 'not_yet', 'closes' => 0 ] ] ) );
check( 'the number is escaped',                          str_contains( foodify_order_actions_html( [ 'number' => '<x>' ] + $base + [ 'cancel' => true, 'return' => [ 'state' => 'not_yet', 'closes' => 0 ] ] ), '#&lt;x&gt;' ) );

echo "── the glue does the checks it claims ──\n";
$oa = (string) file_get_contents( $theme . '/inc/order-actions.php' );
check( 'every write verifies a nonce tied to the action AND the order', str_contains( $oa, "wp_verify_nonce( \$nonce, 'foodify_order_' . \$action . '_' . \$order_id )" ) );
check( 'every write checks the order belongs to the signed-in customer', str_contains( $oa, 'foodify_order_owner_ok( (int) $order->get_customer_id(), get_current_user_id() )' ) );
check( 'the handler needs a logged-in user',             str_contains( $oa, "|| ! is_user_logged_in() ||" ) );
check( 'the cancel is re-checked on submit, not trusted from the page', str_contains( $oa, 'if ( ! foodify_can_cancel( $facts ) )' ) );
check( 'the status is set to cancelled before any refund is attempted',
	strpos( $oa, "update_status( 'cancelled'" ) < strpos( $oa, '? foodify_cancel_refund( $order' ) );
check( 'the refund goes through the gateway',            str_contains( $oa, "'refund_payment' => true" ) );
check( 'a failed refund emails the shop',                1 === preg_match( '~if \( ! is_wp_error\( \$refund \) \) \{\s*return true;\s*\}.*wp_mail\(~s', $oa ) );
check( 'the Return requested status is registered and listed', str_contains( $oa, "register_post_status( 'wc-return-requested'" ) && str_contains( $oa, "\$out['wc-return-requested']" ) );
check( 'ACCOUNT-12: the customer sees it as "Problem reported" in their orders list', str_contains( $oa, "_x( 'Problem reported', 'Order status', 'foodify' )" ) && ! str_contains( $oa, "_x( 'Return requested', 'Order status'" ) );
check( 'the return window is read from the option, never a number in the theme', str_contains( $oa, "get_option( 'foodify_return_window_days', '' )" ) );
check( 'wp-admin names the option when returns are off', str_contains( $oa, '<code>foodify_return_window_days</code>' ) );

echo "── the scripts parse ──\n";
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
check( 'node is available', '' !== $node );
if ( '' !== $node ) {
	foreach ( [ 'cart stepper' => foodify_cart_stepper_script(), 'order actions' => foodify_order_actions_script() ] as $name => $code ) {
		$tmp = tempnam( sys_get_temp_dir(), 'fdjs' ) . '.js';
		file_put_contents( $tmp, $code );
		exec( escapeshellarg( $node ) . ' --check ' . escapeshellarg( $tmp ) . ' 2>&1', $o, $rc );
		check( "the {$name} script parses", 0 === $rc );
		@unlink( $tmp );
	}
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
