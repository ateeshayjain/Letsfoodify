<?php
/**
 * WP-11 — the courier manifest.
 *
 * `bootstrap.sh` has carried the line "cap and PIN allowlist are set in the
 * Shiprocket step" since the kit was written. THERE WAS NO SHIPROCKET STEP —
 * the same shape as `wp foodify coupons reconcile` in WP-09: a comment naming a
 * thing that does not exist, which reads as reassurance to anyone who does not
 * go looking.
 *
 * THE ONE NUMBER THAT MUST NEVER BE WRONG
 * ---------------------------------------
 * The COD amount on a manifest is what the delivery agent COLLECTS AT THE DOOR.
 *
 *   - Non-zero on a PREPAID order and the courier takes money the customer has
 *     already paid. The customer is charged twice, complains, and is right.
 *   - Wrong on a COD order and the shop is short, per parcel, with no record of
 *     why.
 *
 * Neither shows up in testing, because both need a real delivery to surface. So
 * the payload builder is pure, `cod_amount` is derived from the payment method
 * rather than copied from a field, and it is asserted in both directions.
 *
 * WEIGHT IS REFUSED, NOT GUESSED. Couriers bill on the higher of actual and
 * volumetric weight, and a default of 0.5 kg on a parcel that is really 1.2 kg
 * is a per-parcel loss nobody reconciles. A manifest with an unknown weight is
 * incomplete and says so.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/fulfilment-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * What the courier collects at the door, in rupees.
 *
 * Derived from the payment method, never read from a field. A "cod_amount"
 * carried alongside a payment method is two sources for one fact, and they drift
 * the first time somebody marks a COD order as paid by hand.
 */
function foodify_cod_amount( string $payment_method, float $order_total, array $cod_methods = [ 'cod' ] ): float {
	if ( ! in_array( $payment_method, $cod_methods, true ) ) {
		return 0.0;   // prepaid: the courier collects NOTHING
	}
	return max( 0.0, round( $order_total, 2 ) );
}

/**
 * Total shipped weight in kilograms, or null when it cannot be known.
 *
 * @param array<int,array{qty:int,weight:?float}> $items
 */
function foodify_parcel_weight( array $items, float $packaging_kg = 0.05 ): ?float {
	if ( ! $items ) {
		return null;
	}
	$total = 0.0;
	foreach ( $items as $item ) {
		$w = $item['weight'] ?? null;
		if ( null === $w || $w <= 0.0 ) {
			return null;   // one unknown line makes the parcel weight unknown
		}
		$total += (float) $w * max( 1, (int) ( $item['qty'] ?? 1 ) );
	}
	return round( $total + $packaging_kg, 3 );
}

/**
 * Build the manifest row.
 *
 * @return array{payload:array,missing:array<int,string>,ready:bool}
 */
function foodify_courier_payload( array $o, array $cod_methods = [ 'cod' ] ): array {
	$method = (string) ( $o['payment_method'] ?? '' );
	$total  = (float) ( $o['order_total'] ?? 0.0 );
	$weight = foodify_parcel_weight( (array) ( $o['items'] ?? [] ) );

	$payload = [
		'order_number'    => (string) ( $o['order_number'] ?? '' ),
		'name'            => trim( (string) ( $o['name'] ?? '' ) ),
		'phone'           => preg_replace( '/\D/', '', (string) ( $o['phone'] ?? '' ) ) ?? '',
		'address_1'       => trim( (string) ( $o['address_1'] ?? '' ) ),
		'address_2'       => trim( (string) ( $o['address_2'] ?? '' ) ),
		'city'            => trim( (string) ( $o['city'] ?? '' ) ),
		'state'           => strtoupper( trim( (string) ( $o['state'] ?? '' ) ) ),
		'postcode'        => preg_replace( '/\D/', '', (string) ( $o['postcode'] ?? '' ) ) ?? '',
		'pickup_postcode' => preg_replace( '/\D/', '', (string) ( $o['pickup_postcode'] ?? '' ) ) ?? '',
		'payment_mode'    => in_array( $method, $cod_methods, true ) ? 'COD' : 'Prepaid',
		'cod_amount'      => foodify_cod_amount( $method, $total, $cod_methods ),
		'order_total'     => round( $total, 2 ),
		'weight_kg'       => $weight,
		'items'           => array_map(
			static fn( array $i ): array => [
				'sku'  => (string) ( $i['sku'] ?? '' ),
				'name' => (string) ( $i['name'] ?? '' ),
				'qty'  => (int) ( $i['qty'] ?? 0 ),
			],
			(array) ( $o['items'] ?? [] )
		),
	];

	// A manifest missing any of these is not dispatchable, and finding that out
	// at the courier's API is finding out late.
	$missing = [];
	foreach ( [ 'order_number', 'name', 'address_1', 'city', 'state', 'pickup_postcode' ] as $f ) {
		if ( '' === $payload[ $f ] ) {
			$missing[] = $f;
		}
	}
	if ( ! preg_match( '/^[6-9]\d{9}$/', $payload['phone'] ) ) {
		$missing[] = 'phone';   // the agent cannot call ahead, which is most failed deliveries
	}
	if ( ! preg_match( '/^[1-9]\d{5}$/', $payload['postcode'] ) ) {
		$missing[] = 'postcode';
	}
	if ( null === $payload['weight_kg'] ) {
		$missing[] = 'weight_kg';
	}
	if ( ! $payload['items'] ) {
		$missing[] = 'items';
	}

	return [ 'payload' => $payload, 'missing' => $missing, 'ready' => ! $missing ];
}

/**
 * Is this PIN serviceable?
 *
 * An EMPTY allowlist means "everywhere", not "nowhere". A courier integration
 * that ships with an empty list and reads it as a deny-all refuses every order
 * on the store the day it goes live.
 */
function foodify_pin_serviceable( string $postcode, array $allow = [], array $block = [] ): bool {
	$pin = preg_replace( '/\D/', '', $postcode ) ?? '';
	if ( 6 !== strlen( $pin ) ) {
		return false;
	}
	if ( in_array( $pin, $block, true ) ) {
		return false;
	}
	return $allow ? in_array( $pin, $allow, true ) : true;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

function foodify_order_courier_payload( WC_Order $order ): array {
	$profile = function_exists( 'foodify_business_profile' ) ? foodify_business_profile() : [];
	$items   = [];
	foreach ( $order->get_items() as $item ) {
		$product  = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
		$weight   = $product && '' !== (string) $product->get_weight() ? (float) $product->get_weight() : null;
		$items[]  = [
			'sku'    => $product ? (string) $product->get_sku() : '',
			'name'   => (string) $item->get_name(),
			'qty'    => (int) $item->get_quantity(),
			'weight' => $weight,
		];
	}

	$cod = function_exists( 'foodify_payment_config' ) ? (array) foodify_payment_config()['cod_methods'] : [ 'cod' ];

	return foodify_courier_payload( [
		'order_number'    => (string) $order->get_order_number(),
		'name'            => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		'phone'           => (string) $order->get_billing_phone(),
		'address_1'       => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
		'address_2'       => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
		'city'            => $order->get_shipping_city() ?: $order->get_billing_city(),
		'state'           => $order->get_shipping_state() ?: $order->get_billing_state(),
		'postcode'        => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
		'pickup_postcode' => (string) ( $profile['postal'] ?? '' ),
		'payment_method'  => (string) $order->get_payment_method(),
		'order_total'     => (float) $order->get_total(),
		'items'           => $items,
	], $cod );
}

/**
 * Say on the order screen when a parcel cannot be manifested.
 *
 * A missing weight or an unreachable phone number is cheap to fix while the
 * order is on a screen and expensive to fix once it is in a van.
 */
add_action( 'woocommerce_admin_order_data_after_shipping_address', static function ( $order ): void {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$m = foodify_order_courier_payload( $order );
	if ( $m['ready'] ) {
		printf(
			'<p class="foodify-manifest"><strong>%1$s</strong> %2$s · %3$s</p>',
			esc_html__( 'Courier:', 'foodify' ),
			esc_html( $m['payload']['payment_mode'] . ( $m['payload']['cod_amount'] > 0 ? ' ₹' . number_format( $m['payload']['cod_amount'], 2 ) : '' ) ),
			esc_html( $m['payload']['weight_kg'] . ' kg' )
		);
		return;
	}
	printf(
		'<p class="foodify-manifest" style="color:#b32d2e"><strong>%1$s</strong> %2$s</p>',
		esc_html__( 'Not dispatchable —', 'foodify' ),
		esc_html( implode( ', ', $m['missing'] ) )
	);
} );
