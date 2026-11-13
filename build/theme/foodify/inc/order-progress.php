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
 * THE ORDERS LIST, AS CARDS (client, 9 Nov 2026 — Blinkit's order history)
 *   Each order is a card: where it is ("Delivered", "Being packed") over
 *   "₹720 · 3 Nov, 8:11 am", a row of what was in it ("+2" past four), and
 *   Order again | Details at its foot. Everything else WooCommerce or the
 *   theme offers for that order (Pay, Cancel, Report a problem, Invoice) sits
 *   behind "⋮" — a <details> menu, so it works without JavaScript. The cards
 *   replace WooCommerce's orders TABLE renderer, not its template: same query,
 *   same filters, same pagination. NOT borrowed: "Arrived in 10 minutes"
 *   (no time claims), deleting an order, and ratings on the list.
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

/**
 * What a card says an order is doing, and its mark.
 *
 * @return array{text:string,icon:string} icon: done | wait | stop | alert
 */
function foodify_order_headline( string $status, bool $delivered = false ): array {
	$status = (string) preg_replace( '/^wc-/', '', strtolower( trim( $status ) ) );
	if ( $delivered && in_array( $status, [ 'completed', 'processing' ], true ) ) {
		return [ 'text' => 'Delivered', 'icon' => 'done' ];
	}
	$map = [
		'completed'        => [ 'Shipped', 'done' ],
		'processing'       => [ 'Being packed', 'wait' ],
		'on-hold'          => [ 'Placed', 'wait' ],
		'pending'          => [ 'Waiting for payment', 'wait' ],
		'out-for-delivery' => [ 'Out for delivery', 'wait' ],
		'delivered'        => [ 'Delivered', 'done' ],
		'return-requested' => [ 'Problem reported', 'alert' ],
		'cancelled'        => [ 'Cancelled', 'stop' ],
		'refunded'         => [ 'Refunded', 'stop' ],
		'failed'           => [ 'Payment did not go through', 'stop' ],
	];
	[ $text, $icon ] = $map[ $status ] ?? [ ucfirst( str_replace( '-', ' ', $status ) ), 'wait' ];
	return [ 'text' => $text, 'icon' => $icon ];
}

/**
 * One order as a card.
 *
 * @param array{number:string,status:string,delivered?:bool,total:string,date:string,
 *              items:array<int,array{name:string,img:string}>,
 *              actions:array<string,array{url:string,name:string,aria-label?:string}>} $o
 *        total: WooCommerce's formatted total (HTML). actions: wc_get_account_orders_actions().
 */
function foodify_order_card_html( array $o, int $show = 4 ): string {
	$e     = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	$h     = foodify_order_headline( (string) $o['status'], ! empty( $o['delivered'] ) );
	$no    = (string) $o['number'];
	$icons = [
		'done'  => '<path d="m6 12.5 4 4 8-9"/>',
		'wait'  => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
		'stop'  => '<path d="m7 7 10 10M17 7 7 17"/>',
		'alert' => '<path d="M12 4 3 20h18z"/><path d="M12 10v4M12 17v.01"/>',
	];
	$thumbs = '';
	$items  = array_values( (array) ( $o['items'] ?? [] ) );
	foreach ( array_slice( $items, 0, $show ) as $it ) {
		$thumbs .= '<li class="fd-ocard__thumb">' . ( '' !== (string) $it['img']
			? '<img src="' . $e( (string) $it['img'] ) . '" alt="' . $e( (string) $it['name'] ) . '" width="64" height="64" loading="lazy" decoding="async">'
			: '<span class="fd-ocard__noimg">' . $e( (string) $it['name'] ) . '</span>' ) . '</li>';
	}
	$more = count( $items ) - $show;
	if ( $more > 0 ) {
		$thumbs .= '<li class="fd-ocard__thumb fd-ocard__more"><span aria-hidden="true">+' . $more . '</span><span class="fd-sr">and ' . $more . ' more</span></li>';
	}
	$actions = (array) ( $o['actions'] ?? [] );
	$link    = static function ( array $a, string $cls ) use ( $e ): string {
		return '<a class="' . $cls . '" href="' . $e( (string) $a['url'] ) . '"' . ( ! empty( $a['aria-label'] ) ? ' aria-label="' . $e( (string) $a['aria-label'] ) . '"' : '' ) . '>' . $e( (string) $a['name'] ) . '</a>';
	};
	// The foot: Pay when it is owed, else Order again — then Details.
	$first = isset( $actions['pay'] ) ? 'pay' : ( isset( $actions['order-again'] ) ? 'order-again' : '' );
	$foot  = ( '' !== $first ? $link( $actions[ $first ], 'fd-ocard__act fd-ocard__act--main' ) : '' )
		. ( isset( $actions['view'] ) ? $link( $actions['view'], 'fd-ocard__act' ) : '' );
	$rest  = '';
	foreach ( $actions as $key => $a ) {
		if ( $key === $first || 'view' === $key ) {
			continue;
		}
		$rest .= '<li>' . $link( $a, 'fd-ocard__menuitem' ) . '</li>';
	}
	$menu = '' === $rest ? '' : '<details class="fd-ocard__menu"><summary class="fd-ocard__menubtn" aria-label="' . $e( 'More for order ' . $no ) . '"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg></summary><ul class="fd-ocard__menulist">' . $rest . '</ul></details>';
	return '<li class="fd-ocard is-' . $h['icon'] . '">'
		. '<div class="fd-ocard__head"><span class="fd-ocard__mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' . $icons[ $h['icon'] ] . '</svg></span>'
		. '<div class="fd-ocard__what"><h3 class="fd-ocard__title">' . $e( $h['text'] ) . '<span class="fd-sr"> · order ' . $e( $no ) . '</span></h3>'
		. '<p class="fd-ocard__meta">' . (string) $o['total'] . ' · ' . $e( (string) $o['date'] ) . ' · #' . $e( $no ) . '</p></div>' . $menu . '</div>'
		. ( '' !== $thumbs ? '<ul class="fd-ocard__thumbs">' . $thumbs . '</ul>' : '' )
		. ( '' !== $foot ? '<div class="fd-ocard__foot">' . $foot . '</div>' : '' )
		. '</li>';
}

/** An order line on its own page: picture, name, and the pack ("80 g"). The quantity follows ("× 2"). */
function foodify_order_item_name_html( string $name_html, string $img, string $pack ): string {
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	return ( '' !== $img ? '<img class="fd-oitem__img" src="' . $e( $img ) . '" alt="" width="56" height="56" loading="lazy" decoding="async">' : '<span class="fd-oitem__img is-empty" aria-hidden="true"></span>' )
		. '<span class="fd-oitem__name">' . $name_html . '</span>'
		. ( '' !== trim( $pack ) ? '<span class="fd-oitem__pack">' . $e( trim( $pack ) ) . '</span>' : '' );
}

/**
 * The line under "Order summary", in WooCommerce's own format string so its
 * marks still wrap the number, date and status: "Order #1194 · placed
 * 3 Nov 2026 · Delivered", in place of "Order #1194 was placed on … and is
 * currently …".
 */
function foodify_order_summary_format(): string {
	return 'Order #%1$s · placed %2$s · %3$s';
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
		// A RAW url: whoever prints an action escapes it (WooCommerce's template
		// and this theme's cards both call esc_url). wp_nonce_url() returns one
		// already escaped, so the link went out as "&amp;amp;_wpnonce", the
		// nonce arrived under the name "amp;_wpnonce", and Order again on the
		// orders list opened the cart and added nothing (found by clicking it
		// on a real WooCommerce, 13 Nov 2026).
		'url'        => add_query_arg( [ 'order_again' => $order->get_id(), '_wpnonce' => wp_create_nonce( 'woocommerce-order_again' ) ], wc_get_cart_url() ),
		'name'       => __( 'Order again', 'foodify' ),
		/* translators: %s: order number */
		'aria-label' => sprintf( __( 'Order again: put everything from order %s in your cart', 'foodify' ), $order->get_order_number() ),
		'class'      => 'fd-reorder',
	];
	return [ 'order-again' => $again ] + $actions;
}, 5, 2 );

/*
 * On ONE order's page the actions are this theme's own, under the table: Order
 * again, and "Cancel this order" with its reasons (order-actions.php).
 * WooCommerce 8+ also prints an "Actions:" row inside the table with the same
 * two as bare buttons — on the first real render (13 Nov 2026) the page had
 * Order again twice and two ways to cancel, one of which skipped the reasons.
 */
add_filter( 'woocommerce_my_account_my_orders_actions', static function ( array $actions ): array {
	return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ? [] : $actions;
}, 99 );

/* "₹59 via Delivery" — the method is called Delivery, so the row said it twice. */
add_filter( 'woocommerce_order_shipping_to_display_shipped_via', '__return_empty_string' );

/** The orders list as cards — WooCommerce's own query and filters, the theme's markup. */
add_action( 'init', static function (): void {
	remove_action( 'woocommerce_account_orders_endpoint', 'woocommerce_account_orders' );
	add_action( 'woocommerce_account_orders_endpoint', 'foodify_account_orders' );
} );

function foodify_account_orders( $current_page ): void {
	$page   = empty( $current_page ) ? 1 : absint( $current_page );
	$result = wc_get_orders( apply_filters( 'woocommerce_my_account_my_orders_query', [
		'customer' => get_current_user_id(),
		'page'     => $page,
		'paginate' => true,
	] ) );
	do_action( 'woocommerce_before_account_orders', 0 < $result->total );
	if ( ! $result->total ) {
		wc_print_notice( esc_html__( 'No order has been made yet.', 'woocommerce' ) . ' <a class="woocommerce-Button wc-forward button" href="' . esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ) . '">' . esc_html__( 'Browse products', 'woocommerce' ) . '</a>', 'notice' );
		do_action( 'woocommerce_after_account_orders', false );
		return;
	}
	$cards = '';
	foreach ( $result->orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$p       = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			$img_id  = $p ? (int) $p->get_image_id() : 0;
			$items[] = [ 'name' => $item->get_name(), 'img' => $img_id ? (string) wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '' ];
		}
		$created = $order->get_date_created();
		$cards  .= foodify_order_card_html( [
			'number'    => (string) $order->get_order_number(),
			'status'    => $order->get_status(),
			'delivered' => (int) $order->get_meta( '_foodify_delivered_at' ) > 0,
			'total'     => wp_kses_post( $order->get_formatted_order_total() ),
			'date'      => $created ? wp_date( 'j M, g:i a', $created->getTimestamp() ) : '',
			'items'     => $items,
			'actions'   => wc_get_account_orders_actions( $order ),
		] );
	}
	echo '<ul class="fd-ocards">' . $cards . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the builder.
	if ( 1 < $result->max_num_pages ) {
		echo '<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">';
		if ( 1 !== $page ) {
			echo '<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button" href="' . esc_url( wc_get_endpoint_url( 'orders', $page - 1 ) ) . '">' . esc_html__( 'Previous', 'woocommerce' ) . '</a>';
		}
		if ( (int) $result->max_num_pages !== $page ) {
			echo '<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button" href="' . esc_url( wc_get_endpoint_url( 'orders', $page + 1 ) ) . '">' . esc_html__( 'Next', 'woocommerce' ) . '</a>';
		}
		echo '</div>';
	}
	do_action( 'woocommerce_after_account_orders', true );
}

/* The order's own page (and Track order): each line with its picture and pack.
   Only while woocommerce_view_order runs — emails use the same filter. */
add_filter( 'woocommerce_order_item_name', static function ( $name, $item ) {
	if ( ! doing_action( 'woocommerce_view_order' ) || ! $item instanceof WC_Order_Item_Product ) {
		return $name;
	}
	$p   = $item->get_product();
	$img = $p && $p->get_image_id() ? (string) wp_get_attachment_image_url( (int) $p->get_image_id(), 'thumbnail' ) : '';
	$id  = $p ? ( $p->get_parent_id() ?: $p->get_id() ) : 0;
	return foodify_order_item_name_html( (string) $name, $img, $id ? (string) get_post_meta( $id, '_foodify_net_quantity', true ) : '' );
}, 20, 2 );

/* The sentence at the top of an order (its own page and Track order), shortened. */
add_filter( 'gettext_woocommerce', static function ( $translation, $text ) {
	return 'Order #%1$s was placed on %2$s and is currently %3$s.' === $text ? foodify_order_summary_format() : $translation;
}, 10, 2 );
