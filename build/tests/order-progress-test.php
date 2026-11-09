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
	str_contains( $src, "wp_nonce_url( add_query_arg( 'order_again', \$order->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' )" ) );
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

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
