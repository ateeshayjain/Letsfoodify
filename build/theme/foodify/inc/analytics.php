<?php
/**
 * WP-13 — GA4 e-commerce instrumentation.
 *
 * Scope §W8: "GA4 e-commerce events verified end to end (view_item →
 * add_to_cart → begin_checkout → purchase)". Verification needs the live site
 * and DebugView (docs/WP-13-NOTES.md is that runbook); this file is the
 * instrumentation, built around three rules that are correctness, not style:
 *
 * 1. PURCHASE FIRES EXACTLY ONCE PER ORDER. People refresh the thank-you page
 *    and reopen it from the confirmation email for days. Every naive
 *    implementation double-counts revenue that way, and inflated revenue is
 *    worse than none — decisions get made on it. The once-flag lives in ORDER
 *    META, server-side; it can do that reliably because WP-06 made the
 *    order-received page no-store, so this page is never served from a cache.
 *
 * 2. ITEM IDS MATCH THE MERCHANT CENTER FEED ("FDY-<id>"). GA4 joins Shopping
 *    and analytics data on item_id; two id schemes silently produce two
 *    disconnected catalogues and nobody notices until the join is needed.
 *
 * 3. NO PII, EVER. No email, phone, name or address in any payload — GA4's own
 *    terms forbid it and DPDP makes it a liability. Pinned by test with the
 *    same detector the partner emails use.
 *
 * SHIPS OFF. With no measurement ID configured, NOTHING loads — no gtag, no
 * dataLayer, no request to Google. Half-installed analytics (the script
 * without the ID, or the ID without the events) is the worst state, because it
 * looks installed; the gate treats it as a failure.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/wp13-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/** One GA4 item. The id is the FEED id — same catalogue, same key. */
function foodify_ga4_item( int $product_id, string $name, float $price, int $qty ): array {
	return [
		'item_id'   => 'FDY-' . $product_id,
		'item_name' => $name,
		'price'     => round( $price, 2 ),
		'quantity'  => max( 1, $qty ),
	];
}

/**
 * Render one gtag event call as a script-safe line.
 *
 * JSON_HEX_TAG is load-bearing, not decoration: this JSON is printed inside a
 * <script> block, and a product named with "</script>" (or an injected one)
 * would END THE BLOCK AT THAT BYTE — the same stop-parsing failure as the
 * feed's ampersand and the device-viewer's payload. Hex-escaping < and >
 * makes the payload inert whatever the catalogue contains.
 */
function foodify_ga4_event_js( string $event, array $params ): string {
	$enc = static fn( $v ): string => (string) json_encode( $v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP );
	return sprintf( 'gtag(%s, %s, %s);', $enc( 'event' ), $enc( $event ), $enc( $params ) );
}

/**
 * The purchase payload, from plain values.
 *
 * Coupon codes are included — that is how WP-09's partner codes become
 * visible in acquisition reports. CODES are not PII; they name a partner's
 * campaign, not a buyer.
 *
 * @param array<int,array> $items foodify_ga4_item() results.
 */
function foodify_ga4_purchase( string $order_number, float $total, float $tax, float $shipping, array $items, array $coupons = [] ): array {
	$p = [
		'transaction_id' => $order_number,
		'currency'       => 'INR',
		'value'          => round( $total, 2 ),
		'tax'            => round( $tax, 2 ),
		'shipping'       => round( $shipping, 2 ),
		'items'          => array_values( $items ),
	];
	if ( $coupons ) {
		$p['coupon'] = implode( ',', array_map( 'strtoupper', $coupons ) );
	}
	return $p;
}

/**
 * Should the purchase event render for this order view?
 *
 * @param ?string $sent_meta The order's once-flag meta ('' / null = never sent).
 */
function foodify_purchase_event_due( ?string $sent_meta, bool $order_is_paid_or_cod ): bool {
	if ( ! $order_is_paid_or_cod ) {
		return false;   // a failed/pending payment page is not a purchase
	}
	return null === $sent_meta || '' === $sent_meta;
}

/** A GA4 measurement id: G- and alphanumerics. Anything else refuses to load. */
function foodify_valid_measurement_id( string $id ): bool {
	return (bool) preg_match( '/^G-[A-Z0-9]{6,14}$/', strtoupper( trim( $id ) ) );
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

const FOODIFY_GA4_SENT_META = '_foodify_ga4_purchase_sent';

/**
 * The measurement ID. SHIPS EMPTY — the client supplies it, same contract as
 * the FSSAI number and the GSTIN. A malformed id is treated as absent rather
 * than half-loading gtag against garbage.
 */
function foodify_ga4_id(): string {
	$id = (string) apply_filters( 'foodify_ga4_measurement_id', '' );
	return foodify_valid_measurement_id( $id ) ? strtoupper( trim( $id ) ) : '';
}

/** gtag loader + config. Nothing renders when the id is absent. */
add_action( 'wp_head', static function (): void {
	$id = foodify_ga4_id();
	if ( '' === $id ) {
		return;
	}
	printf(
		'<script async src="https://www.googletagmanager.com/gtag/js?id=%1$s"></script>' . "\n"
		. "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n"
		. "gtag('js', new Date());gtag('config', '%1$s');</script>\n",
		esc_attr( $id )
	);
}, 5 );

/** Collect events queued server-side and print them once, on the next render. */
function foodify_queue_ga4_event( string $event, array $params ): void {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}
	$q   = WC()->session->get( 'foodify_ga4_queue', [] );
	$q[] = [ 'event' => $event, 'params' => $params ];
	WC()->session->set( 'foodify_ga4_queue', array_slice( (array) $q, -10 ) );
}

add_action( 'wp_footer', static function (): void {
	if ( '' === foodify_ga4_id() ) {
		return;
	}
	$lines = [];

	// Events queued by server-side hooks (the non-AJAX add-to-cart path).
	if ( function_exists( 'WC' ) && WC()->session ) {
		foreach ( (array) WC()->session->get( 'foodify_ga4_queue', [] ) as $e ) {
			if ( isset( $e['event'], $e['params'] ) ) {
				$lines[] = foodify_ga4_event_js( (string) $e['event'], (array) $e['params'] );
			}
		}
		WC()->session->set( 'foodify_ga4_queue', [] );
	}

	// view_item — the product being looked at.
	if ( function_exists( 'is_product' ) && is_product() ) {
		global $product;
		if ( $product instanceof WC_Product ) {
			$lines[] = foodify_ga4_event_js( 'view_item', [
				'currency' => 'INR',
				'value'    => round( (float) $product->get_price(), 2 ),
				'items'    => [ foodify_ga4_item( $product->get_id(), $product->get_name(), (float) $product->get_price(), 1 ) ],
			] );
		}
	}

	// begin_checkout — the checkout form, not the order-received page.
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && WC()->cart && ! WC()->cart->is_empty() ) {
		$items = [];
		foreach ( WC()->cart->get_cart() as $line ) {
			$p = $line['data'] ?? null;
			if ( $p instanceof WC_Product ) {
				$items[] = foodify_ga4_item( $p->get_id(), $p->get_name(), (float) $p->get_price(), (int) $line['quantity'] );
			}
		}
		$lines[] = foodify_ga4_event_js( 'begin_checkout', [
			'currency' => 'INR',
			'value'    => round( (float) WC()->cart->get_total( 'edit' ), 2 ),
			'items'    => $items,
		] );
	}

	// purchase — once per order, ever. See the header.
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order instanceof WC_Order ) {
			$due = foodify_purchase_event_due(
				(string) $order->get_meta( FOODIFY_GA4_SENT_META ),
				$order->is_paid() || 'cod' === $order->get_payment_method() || $order->has_status( [ 'processing', 'on-hold', 'completed' ] )
			);
			if ( $due ) {
				$items = [];
				foreach ( $order->get_items() as $item ) {
					$p       = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
					$items[] = foodify_ga4_item(
						$p ? $p->get_id() : 0,
						(string) $item->get_name(),
						(float) $item->get_total() / max( 1, (int) $item->get_quantity() ),
						(int) $item->get_quantity()
					);
				}
				$lines[] = foodify_ga4_event_js( 'purchase', foodify_ga4_purchase(
					(string) $order->get_order_number(),
					(float) $order->get_total(),
					(float) $order->get_total_tax(),
					(float) $order->get_shipping_total(),
					$items,
					$order->get_coupon_codes()
				) );
				$order->update_meta_data( FOODIFY_GA4_SENT_META, current_time( 'mysql' ) );
				$order->save();
			}
		}
	}

	if ( $lines ) {
		echo "<script>\n" . implode( "\n", $lines ) . "\n</script>\n";   // phpcs:ignore WordPress.Security.EscapeOutput -- JSON_HEX-escaped at build
	}

	// add_to_cart for the AJAX path: WooCommerce fires `added_to_cart` on body.
	echo "<script>document.body.addEventListener&&jQuery&&jQuery(document.body).on('added_to_cart',function(){gtag('event','add_to_cart',{currency:'INR'});});</script>\n";
} );

/** add_to_cart for the non-AJAX path — queued server-side, printed next render. */
add_action( 'woocommerce_add_to_cart', static function ( $key, $product_id, $qty ): void {
	if ( '' === foodify_ga4_id() ) {
		return;
	}
	$p = wc_get_product( $product_id );
	if ( ! $p ) {
		return;
	}
	foodify_queue_ga4_event( 'add_to_cart', [
		'currency' => 'INR',
		'value'    => round( (float) $p->get_price() * max( 1, (int) $qty ), 2 ),
		'items'    => [ foodify_ga4_item( (int) $product_id, $p->get_name(), (float) $p->get_price(), (int) $qty ) ],
	] );
}, 10, 3 );
