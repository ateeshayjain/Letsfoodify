<?php
/**
 * inc/order-progress.php — where an order is, as steps, and "Order again" on
 * every order (client direction, 9 Nov 2026).
 *
 *   php tests/order-progress-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/order-progress.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
$T = __DIR__ . '/../theme/foodify/';
$states = static fn( array $p ): string => implode( ' ', array_map( static fn( $s ) => $s['key'] . ':' . $s['state'], $p['steps'] ) );
$d = [ 'placed' => 'Mon 9 Nov', 'shipped' => 'Tue 10 Nov', 'delivered' => 'Wed 11 Nov' ];

echo "── the steps are the statuses the store records ──\n";
check( 'pending / on hold: placed is where it is', 'placed:current packed:todo shipped:todo delivered:todo' === $states( foodify_order_progress( [ 'status' => 'on-hold' ] ) ) );
check( 'processing: being packed now', 'placed:done packed:current shipped:todo delivered:todo' === $states( foodify_order_progress( [ 'status' => 'wc-processing' ] ) ) );
check( '… and the step says so', 'Being packed' === foodify_order_progress( [ 'status' => 'processing' ] )['steps'][1]['label'] );
check( 'completed is dispatch: shipped', 'placed:done packed:done shipped:current delivered:todo' === $states( foodify_order_progress( [ 'status' => 'completed', 'dispatched' => true ] ) ) );
check( 'a courier that ships while processing says so through "dispatched"', 'placed:done packed:done shipped:current delivered:todo' === $states( foodify_order_progress( [ 'status' => 'processing', 'dispatched' => true ] ) ) );
check( 'delivered (the recorded date): every step done', 'placed:done packed:done shipped:done delivered:done' === $states( foodify_order_progress( [ 'status' => 'completed', 'dispatched' => true, 'delivered_at' => 1700000000 ] ) ) );
check( 'a problem reported after delivery keeps the delivered steps', 'placed:done packed:done shipped:done delivered:done' === $states( foodify_order_progress( [ 'status' => 'return-requested', 'dispatched' => true, 'delivered_at' => 1 ] ) ) );
check( 'NO "Out for delivery" step unless the store has that status — a step it can never reach would read as stuck',
	! str_contains( $states( foodify_order_progress( [ 'status' => 'completed', 'dispatched' => true ] ) ), 'out:' ) );
check( 'with a courier\'s out-for-delivery status registered: five steps, and it is the current one',
	'placed:done packed:done shipped:done out:current delivered:todo' === $states( foodify_order_progress( [ 'status' => 'out-for-delivery', 'ofd_known' => true ] ) ) );
check( 'cancelled, refunded, failed: no steps', [] === foodify_order_progress( [ 'status' => 'cancelled' ] )['steps'] && 'refunded' === foodify_order_progress( [ 'status' => 'refunded' ] )['state'] && 'failed' === foodify_order_progress( [ 'status' => 'wc-failed' ] )['state'] );
$p = foodify_order_progress( [ 'status' => 'completed', 'dispatched' => true, 'dates' => $d ] );
check( 'dates on the steps reached, none on steps still to come', 'Mon 9 Nov' === $p['steps'][0]['date'] && 'Tue 10 Nov' === $p['steps'][2]['date'] && '' === $p['steps'][3]['date'] );

echo "── the markup ──\n";
$h = foodify_order_progress_html( $p );
check( 'an ordered list under a heading', str_contains( $h, '<section class="fd-track" aria-labelledby="fd-track-title"><h2 class="fd-track__title" id="fd-track-title">Where your order is</h2><ol class="fd-track__steps">' ) );
check( 'the current step carries aria-current="step"', 1 === substr_count( $h, 'aria-current="step"' ) && str_contains( $h, 'is-current" aria-current="step"' ) );
check( 'each step says done / now / next to a screen reader, not only by colour', str_contains( $h, '<span class="fd-sr">Done: </span>Placed' ) && str_contains( $h, '<span class="fd-sr">Now: </span>Shipped' ) && str_contains( $h, '<span class="fd-sr">Next: </span>Delivered' ) );
check( 'no courier link unless one is given', ! str_contains( $h, 'Track with the courier' ) );
$ht = foodify_order_progress_html( $p, 'https://courier.example/t/AWB123' );
check( 'a courier link sits on Shipped, opening a new tab and saying so', str_contains( $ht, 'href="https://courier.example/t/AWB123" target="_blank" rel="noopener">Track with the courier<span class="fd-sr"> (opens a new tab)</span>' ) );
check( '… only https', ! str_contains( foodify_order_progress_html( $p, 'javascript:alert(1)' ), 'href=' ) && ! str_contains( foodify_order_progress_html( $p, 'http://x' ), 'href=' ) );
check( '… and not before the parcel has shipped', ! str_contains( foodify_order_progress_html( foodify_order_progress( [ 'status' => 'processing' ] ), 'https://c.example/' ), 'href=' ) );
check( 'a cancelled order says so in a line', '<p class="fd-track-note">This order was cancelled.</p>' === foodify_order_progress_html( foodify_order_progress( [ 'status' => 'cancelled' ] ) ) );
check( 'escaped', str_contains( foodify_order_progress_html( foodify_order_progress( [ 'status' => 'processing', 'dates' => [ 'placed' => '<b>' ] ] ) ), '&lt;b&gt;' ) );
check( 'its class is its own — .fd-progress is the free-delivery bar', ! preg_match( '/class="fd-progress/', $h ) );

echo "── Order again ──\n";
check( 'from processing, completed, a reported problem and cancelled — not unpaid orders (those say Pay)', [ 'processing', 'completed', 'return-requested', 'cancelled' ] === foodify_order_again_statuses() );
$src = (string) file_get_contents( $T . 'inc/order-progress.php' );
$acc = (string) file_get_contents( $T . 'inc/account.php' );
check( 'it is WooCommerce\'s own order-again URL and nonce — WooCommerce\'s handler does the adding and the owner check',
	str_contains( $src, "add_query_arg( [ 'order_again' => \$order->get_id(), '_wpnonce' => wp_create_nonce( 'woocommerce-order_again' ) ], wc_get_cart_url() )" ) );
// 13 Nov 2026, clicked on a real WooCommerce: wp_nonce_url() returns "&amp;",
// the card escapes again, and the nonce arrived as "amp;_wpnonce" — the button
// opened the cart and added nothing.
check( 'the URL is handed over RAW — never wp_nonce_url(), which is already escaped', ! preg_match( "/'url'\s*=>\s*wp_nonce_url\(/", $src ) );
check( 'it is ADDED, first — WooCommerce\'s orders list has no order-again action to relabel', str_contains( $src, "return [ 'order-again' => \$again ] + \$actions;" ) && ! str_contains( $acc, "isset( \$actions['order-again'] )" ) );
check( 'it says "Order again" and names the order for a screen reader', str_contains( $src, "__( 'Order again', 'foodify' )" ) && str_contains( $src, "Order again: put everything from order %s in your cart" ) );
check( 'WooCommerce\'s handler accepts the same statuses', str_contains( $src, "add_filter( 'woocommerce_valid_order_statuses_for_order_again'" ) );

echo "── wiring ──\n";
check( 'the theme loads it', str_contains( (string) file_get_contents( $T . 'functions.php' ), "require_once FOODIFY_DIR . '/inc/order-progress.php';" ) );
check( 'above the order\'s lines on its page and on Track order (both fire woocommerce_view_order)', str_contains( $src, "add_action( 'woocommerce_view_order'" ) && str_contains( $src, '}, 5 );' ) );
check( 'the courier link comes from the filter whatsapp.php already reads', str_contains( $src, "apply_filters( 'foodify_order_tracking_url', '', \$order )" ) && str_contains( (string) file_get_contents( $T . 'inc/whatsapp.php' ), "apply_filters( 'foodify_order_tracking_url', '', \$order )" ) );
check( 'out for delivery only when that status is registered', str_contains( $src, "isset( wc_get_order_statuses()['wc-out-for-delivery'] )" ) );
$bs = (string) file_get_contents( __DIR__ . '/../scripts/bootstrap.sh' );
check( 'bootstrap.sh makes /track-order/ (the footer links it) only when absent, with WooCommerce\'s tracking form',
	str_contains( $bs, '--post_name=track-order' ) && str_contains( $bs, "[woocommerce_order_tracking]" ) && str_contains( $bs, 'never rewritten' ) );

echo "── the orders list as cards (client, 9 Nov: Blinkit's order history) ──\n";
check( 'where it is, in words: delivered beats shipped once a date is recorded', [ 'text' => 'Delivered', 'icon' => 'done' ] === foodify_order_headline( 'completed', true ) && 'Shipped' === foodify_order_headline( 'wc-completed' )['text'] );
check( 'being packed, waiting for payment, cancelled, a problem reported', 'Being packed' === foodify_order_headline( 'processing' )['text'] && 'Waiting for payment' === foodify_order_headline( 'pending' )['text'] && 'stop' === foodify_order_headline( 'cancelled' )['icon'] && 'alert' === foodify_order_headline( 'return-requested' )['icon'] );
check( 'no time claims: nothing says "minutes"', ! preg_match( '/minute/i', json_encode( array_map( 'foodify_order_headline', [ 'completed', 'processing', 'pending', 'on-hold', 'cancelled' ] ) ) ) );
$acts = [
	'order-again' => [ 'url' => '/cart/?order_again=7&_wpnonce=x', 'name' => 'Order again', 'aria-label' => 'Order again: put everything from order 1194 in your cart' ],
	'view'        => [ 'url' => '/my-account/view-order/7/', 'name' => 'Details' ],
	'foodify-return' => [ 'url' => '/r/', 'name' => 'Report a problem' ],
	'invoice'     => [ 'url' => '/inv/', 'name' => 'Invoice' ],
];
$items = [];
for ( $i = 1; $i <= 6; $i++ ) { $items[] = [ 'name' => 'Meal ' . $i, 'img' => $i < 6 ? '/m' . $i . '.jpg' : '' ]; }
$card = foodify_order_card_html( [ 'number' => '1194', 'status' => 'completed', 'delivered' => true, 'total' => '<span class="amount">₹720</span>', 'date' => '3 Nov, 8:11 am', 'items' => $items, 'actions' => $acts ] );
check( 'headline and "₹720 · 3 Nov, 8:11 am · #1194"', str_contains( $card, '<h3 class="fd-ocard__title">Delivered<span class="fd-sr"> · order 1194</span></h3>' ) && str_contains( $card, '<p class="fd-ocard__meta"><span class="amount">₹720</span> · 3 Nov, 8:11 am · #1194</p>' ) );
check( 'four pictures, then "+2" (said in full to a screen reader)', 4 === substr_count( $card, '<li class="fd-ocard__thumb"><img' ) && str_contains( $card, '<span aria-hidden="true">+2</span><span class="fd-sr">and 2 more</span>' ) );
check( 'each picture is named for its meal', str_contains( $card, 'alt="Meal 1"' ) );
check( 'the foot: Order again | Details', 1 === preg_match( '#<div class="fd-ocard__foot"><a class="fd-ocard__act fd-ocard__act--main" href="/cart/\?order_again=7&amp;_wpnonce=x" aria-label="Order again: put everything from order 1194 in your cart">Order again</a><a class="fd-ocard__act" href="/my-account/view-order/7/">Details</a></div>#', $card ) );
check( 'everything else behind ⋮ — a <details> menu, named for the order', str_contains( $card, '<details class="fd-ocard__menu"><summary class="fd-ocard__menubtn" aria-label="More for order 1194">' ) && str_contains( $card, '>Report a problem</a>' ) && str_contains( $card, '>Invoice</a>' ) && 1 === substr_count( $card, '>Order again</a>' ) );
$pay = foodify_order_card_html( [ 'number' => '9', 'status' => 'pending', 'total' => '₹1', 'date' => 'd', 'items' => [], 'actions' => [ 'pay' => [ 'url' => '/pay/', 'name' => 'Pay' ], 'view' => [ 'url' => '/v/', 'name' => 'Details' ], 'cancel' => [ 'url' => '/c/', 'name' => 'Cancel' ] ] ] );
check( 'an unpaid order leads with Pay; nothing else to hide, no menu when only Cancel… is there', str_contains( $pay, 'fd-ocard__act--main" href="/pay/">Pay</a>' ) && str_contains( $pay, '>Cancel</a>' ) );
check( 'no actions beyond the foot: no ⋮ at all', ! str_contains( foodify_order_card_html( [ 'number' => '1', 'status' => 'processing', 'total' => 't', 'date' => 'd', 'items' => [], 'actions' => [ 'view' => [ 'url' => '/v/', 'name' => 'Details' ] ] ] ), 'fd-ocard__menu' ) );
check( 'escaped', str_contains( foodify_order_card_html( [ 'number' => '<1>', 'status' => 'processing', 'total' => 't', 'date' => '<d>', 'items' => [ [ 'name' => '"x"', 'img' => '' ] ], 'actions' => [] ] ), '&lt;d&gt;' ) );

echo "── the order's own page ──\n";
$n = foodify_order_item_name_html( '<a href="/p/">Dal</a>', '/d.jpg', '80 g' );
check( 'each line: picture, name, pack — WooCommerce adds "× 2" after', '<img class="fd-oitem__img" src="/d.jpg" alt="" width="56" height="56" loading="lazy" decoding="async"><span class="fd-oitem__name"><a href="/p/">Dal</a></span><span class="fd-oitem__pack">80 g</span>' === $n );
check( 'no picture: a placeholder; no pack: nothing', str_contains( foodify_order_item_name_html( 'X', '', '' ), 'fd-oitem__img is-empty' ) && ! str_contains( foodify_order_item_name_html( 'X', '', '' ), 'fd-oitem__pack' ) );
$src = (string) file_get_contents( $T . 'inc/order-progress.php' );
check( 'the list replaces WooCommerce\'s orders RENDERER, keeping its query filter and pagination', str_contains( $src, "remove_action( 'woocommerce_account_orders_endpoint', 'woocommerce_account_orders' );" ) && str_contains( $src, "apply_filters( 'woocommerce_my_account_my_orders_query'" ) && str_contains( $src, 'wc_get_account_orders_actions( $order )' ) && str_contains( $src, "wc_get_endpoint_url( 'orders', \$page + 1 )" ) );
check( 'pictures on order lines only while woocommerce_view_order runs (emails use the same filter)', str_contains( $src, "! doing_action( 'woocommerce_view_order' )" ) );
check( 'the struck MRP is NOT shown on a past order — the order never recorded it', ! str_contains( $src, 'get_regular_price' ) );
$tb = (string) file_get_contents( $T . 'inc/tab-bar.php' );
check( 'the tab bar steps aside on an order\'s page, whose Order again bar owns the bottom', str_contains( $tb, "is_wc_endpoint_url( 'view-order' )" ) );
$css = (string) file_get_contents( $T . 'style.css' );
check( 'on a phone, Order again is a bar fixed to the bottom of the order page', str_contains( $css, ".woocommerce-account .order-again {\n    position: fixed;" ) );

echo "── the order summary line ──\n";
$f = foodify_order_summary_format();
check( 'WooCommerce\'s three places, in a short line: "Order #1194 · placed 3 Nov 2026 · Delivered"', 'Order #1194 · placed 3 Nov 2026 · Delivered' === sprintf( $f, '1194', '3 Nov 2026', 'Delivered' ) );
check( 'it replaces exactly WooCommerce\'s sentence (the order page and Track order both print it)', str_contains( $src, "'Order #%1\$s was placed on %2\$s and is currently %3\$s.' === \$text ? foodify_order_summary_format()" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
