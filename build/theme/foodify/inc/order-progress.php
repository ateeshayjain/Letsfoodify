<?php
/**
 * Where an order is, as steps — and "Order again" (client, 9 Nov 2026,
 * Blinkit's order screen).
 *
 * THE STEPS ARE THE STATUSES THIS STORE RECORDS, NOTHING ELSE
 *   Placed    the order exists (pending / on hold: waiting for payment or the
 *             COD confirmation call — still "placed").
 *   Packed    processing is "we are packing it now" (the WP-10 email), so it
 *             is the CURRENT step while processing and done once dispatched.
 *   Shipped   completed is dispatch in this theme (order-actions.php), or the
 *             `foodify_order_dispatched` filter when a courier ships earlier.
 *             The courier link sits here when the `foodify_order_tracking_url`
 *             filter gives one (whatsapp.php reads the same filter); today
 *             nothing does, so there is no link rather than a dead one.
 *   Out for delivery  ONLY when a courier plugin registers that status, like
 *             whatsapp.php's map. A step the store can never reach would sit
 *             grey on every order forever and read as "stuck".
 *   Delivered `_foodify_delivered_at` — the "Mark as delivered" order action
 *             or a courier writing it (the date the return window counts from).
 * Cancelled, refunded and failed orders show no steps: a line saying so. No
 * time promises: the delivery estimate lives on the product page and in the
 * checkout, where the PIN is known.
 *
 * ORDER AGAIN
 *   WooCommerce's orders list has NO order-again action (checked against its
 *   source, wc_get_account_orders_actions: pay · view · cancel). The theme's
 *   earlier "Reorder first" filter relabelled an action that never existed, so
 *   on the live site the orders list had no reorder at all. This adds it, with
 *   the exact URL WooCommerce's own button uses (cart?order_again=ID, nonce
 *   'woocommerce-order_again') — WooCommerce's handler does the adding, checks
 *   the order is the customer's, and skips anything no longer for sale. The
 *   statuses it may come from are widened past "completed" so an order still
 *   being packed, or one cancelled, can be ordered again too.
 *
 * @package Foodify
 */
declare( strict_types = 1 );
defined( 'ABSPATH' ) || exit;

/** Statuses an order may be ordered again from (WooCommerce's default is completed only). */
function foodify_order_again_statuses(): array {
	return [ 'processing', 'completed', 'return-requested', 'cancelled' ];
}

/**
 * The steps for one order.
 *
 * @param array{status:string,dispatched?:bool,delivered_at?:int,ofd_known?:bool,
 *              dates?:array<string,string>,tracking?:string} $f
 * @return array{state:string,steps:array<int,array{key:string,label:string,state:string,date:string}>}
 *         state: 'steps' | 'cancelled' | 'refunded' | 'failed'
 */
function foodify_order_progress( array $f ): array {
	$status = (string) preg_replace( '/^wc-/', '', strtolower( trim( (string) ( $f['status'] ?? '' ) ) ) );
	if ( in_array( $status, [ 'cancelled', 'refunded', 'failed' ], true ) ) {
		return [ 'state' => $status, 'steps' => [] ];
	}
	$dates      = (array) ( $f['dates'] ?? [] );
	$delivered  = (int) ( $f['delivered_at'] ?? 0 ) > 0 || 'delivered' === $status;
	$ofd        = ! empty( $f['ofd_known'] );
	$dispatched = ! empty( $f['dispatched'] ) || $delivered || ( $ofd && 'out-for-delivery' === $status );
	$reached    = 0;   // 0 placed · 1 packed · 2 shipped · 3 out for delivery · 4 delivered
	if ( 'processing' === $status ) {
		$reached = 1;
	}
	if ( $dispatched ) {
		$reached = 2;
	}
	if ( $ofd && 'out-for-delivery' === $status ) {
		$reached = 3;
	}
	if ( $delivered ) {
		$reached = 4;
	}
	$all = [
		0 => [ 'placed', 'Placed' ],
		1 => [ 'packed', 'Packed' ],
		2 => [ 'shipped', 'Shipped' ],
		3 => [ 'out', 'Out for delivery' ],
		4 => [ 'delivered', 'Delivered' ],
	];
	$steps = [];
	foreach ( $all as $i => [ $key, $label ] ) {
		if ( 3 === $i && ! $ofd ) {
			continue;
		}
		// The step being worked on is current; packing is current while processing.
		$state = $i < $reached ? 'done' : ( $i === $reached ? 'current' : 'todo' );
		if ( 4 === $i && 4 === $reached ) {
			$state = 'done';   // nothing is left to happen
		}
		$steps[] = [ 'key' => $key, 'label' => 1 === $i && 'current' === $state ? 'Being packed' : $label, 'state' => $state, 'date' => 'todo' === $state ? '' : (string) ( $dates[ $key ] ?? '' ) ];
	}
	return [ 'state' => 'steps', 'steps' => $steps ];
}

/**
 * The steps as an ordered list. `$tracking` is an https courier link for the
 * Shipped step, or ''.
 */
function foodify_order_progress_html( array $p, string $tracking = '' ): string {
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	if ( 'steps' !== ( $p['state'] ?? '' ) ) {
		$say = [ 'cancelled' => 'This order was cancelled.', 'refunded' => 'This order was refunded.', 'failed' => 'Payment for this order did not go through.' ][ $p['state'] ?? '' ] ?? '';
		return '' === $say ? '' : '<p class="fd-track-note">' . $say . '</p>';
	}
	$tracking = 0 === strpos( $tracking, 'https://' ) ? $tracking : '';
	$li       = '';
	foreach ( (array) $p['steps'] as $s ) {
		$link = 'shipped' === $s['key'] && 'todo' !== $s['state'] && '' !== $tracking
			? ' <a class="fd-track__track" href="' . $e( $tracking ) . '" target="_blank" rel="noopener">Track with the courier<span class="fd-sr"> (opens a new tab)</span></a>' : '';
		$sr   = [ 'done' => 'Done: ', 'current' => 'Now: ', 'todo' => 'Next: ' ][ $s['state'] ];
		$li  .= '<li class="fd-track__step is-' . $s['state'] . '"' . ( 'current' === $s['state'] ? ' aria-current="step"' : '' ) . '>'
			. '<span class="fd-track__dot" aria-hidden="true"></span>'
			. '<span class="fd-track__text"><span class="fd-track__label"><span class="fd-sr">' . $sr . '</span>' . $e( $s['label'] ) . '</span>'
			. ( '' !== $s['date'] ? '<span class="fd-track__date">' . $e( $s['date'] ) . '</span>' : '' ) . $link . '</span></li>';
	}
	return '<section class="fd-track" aria-labelledby="fd-track-title"><h2 class="fd-track__title" id="fd-track-title">Where your order is</h2>'
		. '<ol class="fd-track__steps">' . $li . '</ol></section>';
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/**
 * Above the order's lines on My Account → order, and on the Track order page:
 * WooCommerce fires woocommerce_view_order in both, only after it has checked
 * the order is the customer's (account) or the email matches (tracking).
 */
add_action( 'woocommerce_view_order', static function ( $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$facts = function_exists( 'foodify_order_facts' ) ? foodify_order_facts( $order ) : [ 'status' => $order->get_status() ];
	$day   = static fn( $d ): string => $d ? wp_date( 'D j M', is_int( $d ) ? $d : $d->getTimestamp() ) : '';
	$facts['dates'] = [
		'placed'    => $day( $order->get_date_created() ),
		'shipped'   => $day( $order->get_date_completed() ),
		'delivered' => $day( (int) ( $facts['delivered_at'] ?? 0 ) ?: null ),
	];
	$facts['ofd_known'] = function_exists( 'wc_get_order_statuses' ) && isset( wc_get_order_statuses()['wc-out-for-delivery'] );
	$tracking = (string) apply_filters( 'foodify_order_tracking_url', '', $order );
	echo foodify_order_progress_html( foodify_order_progress( $facts ), esc_url_raw( $tracking ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the builder.
}, 5 );

/** Order again may come from more than "completed". WooCommerce's handler reads the same list. */
add_filter( 'woocommerce_valid_order_statuses_for_order_again', static function ( $statuses ): array {
	return array_values( array_unique( array_merge( (array) $statuses, foodify_order_again_statuses() ) ) );
} );

/** The orders list: "Order again" FIRST, with WooCommerce's own order-again URL. */
add_filter( 'woocommerce_my_account_my_orders_actions', static function ( array $actions, $order ): array {
	if ( ! $order instanceof WC_Order || ! $order->has_status( foodify_order_again_statuses() ) || count( $order->get_items() ) < 1 ) {
		return $actions;
	}
	$again = [
		'url'        => wp_nonce_url( add_query_arg( 'order_again', $order->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' ),
		'name'       => __( 'Order again', 'foodify' ),
		/* translators: %s: order number */
		'aria-label' => sprintf( __( 'Order again: put everything from order %s in your cart', 'foodify' ), $order->get_order_number() ),
		'class'      => 'fd-reorder',
	];
	return [ 'order-again' => $again ] + $actions;
}, 5, 2 );
