<?php
/**
 * WP-07 — payments and COD.
 *
 * The design the client has already seen offers "Pay now — save ₹25" against
 * "Cash on delivery". That is the standard Indian D2C trade: COD carries real
 * cost — courier COD fee, cash handling, and return-to-origin on refused
 * deliveries — so prepaid gets an incentive.
 *
 * TWO THINGS IN HERE ARE MONEY, NOT DECORATION
 * --------------------------------------------
 * 1. The label and the fee must be ONE calculation. If the payment radio says
 *    "save ₹25" and the fee line applies ₹20, the store has lied on the payment
 *    screen. Both read foodify_prepaid_discount().
 *
 * 2. The discount must be recomputed from the method actually submitted, never
 *    carried over from the AJAX round trip. A customer who picks "Pay now",
 *    lets the total update, then switches to COD and places the order must not
 *    keep the prepaid discount. WooCommerce recalculates fees during
 *    process_checkout(), so this is correct only if the callback reads the
 *    POSTED method — which is why foodify_chosen_payment_method() exists and is
 *    tested rather than being an inline $_POST read.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/payments-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * Every number this module can move, in one place.
 *
 * The amounts are CLIENT POLICY, not engineering. ₹25 is what the approved
 * design shows. The COD cap ships at 0 — meaning no cap — deliberately: capping
 * COD is a commercial decision about refused deliveries that nobody has taken
 * yet, and turning it on silently would start refusing orders the client
 * expects to receive.
 */
function foodify_payment_defaults(): array {
	return [
		'prepaid_flat'     => 25.0,   // flat rupees off for paying now
		'prepaid_rate'     => 0.0,    // OR a fraction of the eligible subtotal
		'prepaid_max'      => 25.0,   // ceiling when a rate is used
		'prepaid_min_cart' => 0.0,    // no discount below this eligible subtotal
		'cod_methods'      => [ 'cod' ],
		'cod_max_value'    => 0.0,    // 0 = COD always allowed. See above.
		'fee_taxable'      => false,  // see the GST note on foodify_apply_prepaid_fee()
	];
}

/** Is this gateway a cash-on-delivery one? */
function foodify_is_cod( string $method, array $cfg ): bool {
	$cod = $cfg['cod_methods'] ?? [];
	return in_array( $method, is_array( $cod ) ? $cod : [], true );
}

/**
 * The prepaid discount, in rupees, as a POSITIVE number.
 *
 * @param float  $eligible Cart contents total after any coupon, before this.
 * @param string $method   Gateway id actually chosen.
 */
function foodify_prepaid_discount( float $eligible, string $method, array $cfg ): float {
	$cfg = array_merge( foodify_payment_defaults(), $cfg );

	// An unchosen method is not a prepaid method. Treating "" as prepaid would
	// show the discount before anyone has picked anything and then take it away.
	if ( '' === $method || foodify_is_cod( $method, $cfg ) ) {
		return 0.0;
	}
	if ( $eligible <= 0.0 || $eligible < (float) $cfg['prepaid_min_cart'] ) {
		return 0.0;
	}

	$flat = (float) $cfg['prepaid_flat'];
	$rate = (float) $cfg['prepaid_rate'];
	$amount = $rate > 0.0 ? $eligible * $rate : $flat;

	if ( $rate > 0.0 && (float) $cfg['prepaid_max'] > 0.0 ) {
		$amount = min( $amount, (float) $cfg['prepaid_max'] );
	}

	// A discount can never exceed what is being discounted. Without this a ₹20
	// cart pays −₹5 and WooCommerce happily renders a negative total.
	$amount = min( $amount, $eligible );

	// Whole rupees. Paise on a promotional line reads as a bug, and the label
	// and the fee must round identically or they disagree by a paisa.
	return max( 0.0, round( $amount ) );
}

/**
 * May this cart be paid cash on delivery?
 *
 * @return array{available:bool,reason:string}
 */
function foodify_cod_availability( float $cart_total, array $cfg ): array {
	$cfg = array_merge( foodify_payment_defaults(), $cfg );
	$cap = (float) $cfg['cod_max_value'];

	if ( $cap > 0.0 && $cart_total > $cap ) {
		return [
			'available' => false,
			'reason'    => sprintf(
				'Cash on delivery is available on orders up to ₹%s. Please pay online for this one.',
				number_format( $cap, 0, '.', ',' )
			),
		];
	}
	return [ 'available' => true, 'reason' => '' ];
}

/**
 * Which gateway is actually chosen, in order of authority.
 *
 * THIS IS THE SECURITY-RELEVANT ONE. The posted value wins because it is what
 * the order will be created with; the session is a cache of an earlier choice
 * and can be stale by exactly one switch — which is the switch that matters.
 *
 * WooCommerce sends the method three ways depending on the request:
 *   - `payment_method` on the checkout POST and on update_order_review;
 *   - inside the `post_data` query string that update_order_review also sends;
 *   - not at all on a plain cart page render, where the session is all there is.
 */
function foodify_chosen_payment_method( array $posted, ?string $session, string $fallback = '' ): string {
	$direct = $posted['payment_method'] ?? '';
	if ( is_string( $direct ) && '' !== $direct ) {
		return $direct;
	}

	$post_data = $posted['post_data'] ?? '';
	if ( is_string( $post_data ) && '' !== $post_data ) {
		$parsed = [];
		parse_str( $post_data, $parsed );
		$nested = $parsed['payment_method'] ?? '';
		if ( is_string( $nested ) && '' !== $nested ) {
			return $nested;
		}
	}

	if ( is_string( $session ) && '' !== $session ) {
		return $session;
	}
	return $fallback;
}

/** The saving named on the payment radio. Same numbers as the fee, by construction. */
function foodify_gateway_saving_label( float $eligible, string $gateway_id, array $cfg ): string {
	$amount = foodify_prepaid_discount( $eligible, $gateway_id, $cfg );
	if ( $amount <= 0.0 ) {
		return '';
	}
	return sprintf( 'Save ₹%s', number_format( $amount, 0, '.', ',' ) );
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** Config, filterable in one place rather than one filter per number. */
function foodify_payment_config(): array {
	return (array) apply_filters( 'foodify_payment_config', foodify_payment_defaults() );
}

/** Read the chosen gateway from the live request. */
function foodify_current_payment_method(): string {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only; the
	// order itself is nonce-checked by WooCommerce before anything is created.
	$posted = [];
	foreach ( [ 'payment_method', 'post_data' ] as $key ) {
		if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
			$posted[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
	}
	// phpcs:enable
	$session = ( function_exists( 'WC' ) && WC()->session )
		? (string) WC()->session->get( 'chosen_payment_method' )
		: null;

	return foodify_chosen_payment_method( $posted, $session, '' );
}

/**
 * The prepaid discount, as a negative fee.
 *
 * GST — FLAGGED, NOT DECIDED. The fee ships NON-TAXABLE, which means the GST
 * recorded on the order is computed on the pre-discount subtotal. That is
 * deliberately the conservative error: the store over-declares GST by a few
 * rupees rather than under-declaring it. Over-declaring is a small cost;
 * under-declaring is a compliance exposure.
 *
 * The correct treatment for a GST-INCLUSIVE store is that the ₹25 is itself
 * gross and contains tax at the blended rate of the basket — which a single
 * WooCommerce fee line cannot express when the basket mixes 5% and 12% items.
 *
 * The two alternatives were considered and rejected here:
 *   - a taxable fee at the standard class: wrong in the other direction on a
 *     mixed basket, and it can UNDER-declare;
 *   - an auto-applied hidden coupon, which handles inclusive tax correctly but
 *     (a) inflates get_total_discount(), which is the revenue basis WP-09 pays
 *     partners on, and (b) cannot coexist with an individual-use partner code —
 *     WooCommerce removes all other coupons, so either the partner's code or
 *     the prepaid discount silently disappears.
 *
 * This needs the client's CA before launch, and it is WP-11's to settle. The
 * filter is here so the answer is a one-line change:
 *
 *     add_filter( 'foodify_payment_config', fn( $c ) => $c + [ 'fee_taxable' => true ] );
 */
add_action( 'woocommerce_cart_calculate_fees', static function ( $cart ): void {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	if ( ! $cart instanceof WC_Cart ) {
		return;
	}
	$cfg = foodify_payment_config();

	// After coupons: a discount is applied to what is actually payable.
	$eligible = (float) $cart->get_cart_contents_total();
	$amount   = foodify_prepaid_discount( $eligible, foodify_current_payment_method(), $cfg );

	if ( $amount <= 0.0 ) {
		return;
	}
	$cart->add_fee( __( 'Prepaid saving', 'foodify' ), -$amount, (bool) $cfg['fee_taxable'] );
}, 20 );

/**
 * Recalculate when the customer switches method.
 *
 * WooCommerce refreshes the order review on a payment-method change, but the
 * fee is only recalculated if the totals are marked dirty. Without this the
 * customer picks COD and the "Prepaid saving" line stays on screen until
 * something else nudges it — and a discount that is visible but not real is the
 * worst of both.
 */
add_action( 'woocommerce_checkout_update_order_review', static function (): void {
	if ( function_exists( 'WC' ) && WC()->cart ) {
		WC()->cart->calculate_totals();
	}
}, 20 );

/**
 * COD availability.
 *
 * Ships as a no-op — foodify_payment_defaults() sets cod_max_value to 0. Capping
 * COD is a commercial call about refused deliveries that the client has not
 * made, and switching it on silently would start refusing orders they expect.
 */
add_filter( 'woocommerce_available_payment_gateways', static function ( $gateways ) {
	if ( ! is_array( $gateways ) || is_admin() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $gateways;
	}
	$cfg   = foodify_payment_config();
	$total = (float) WC()->cart->get_cart_contents_total();
	$state = foodify_cod_availability( $total, $cfg );

	if ( ! $state['available'] ) {
		foreach ( (array) $cfg['cod_methods'] as $id ) {
			unset( $gateways[ $id ] );
		}
	}
	return $gateways;
}, 20 );

/**
 * Name the saving on the payment option itself.
 *
 * Reads the SAME function as the fee, so the label cannot drift from the money.
 * Note it computes against the gateway being rendered, not the one currently
 * chosen — the point is to tell someone sitting on COD what they would save by
 * moving.
 */
add_filter( 'woocommerce_gateway_title', static function ( $title, $gateway_id ) {
	if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $title;
	}
	if ( ! WC()->cart ) {
		return $title;
	}
	$saving = foodify_gateway_saving_label(
		(float) WC()->cart->get_cart_contents_total(),
		(string) $gateway_id,
		foodify_payment_config()
	);
	if ( '' === $saving ) {
		return $title;
	}
	return $title . ' <span class="fd-pay-saving">' . esc_html( $saving ) . '</span>';
}, 10, 2 );

/**
 * A CROSS-PACKAGE SEAM THAT COD EXPOSES — fixed in coupon-attribution.php.
 *
 * WP-09's partner attribution fires on `woocommerce_order_status_processing`.
 * That is the right hook and not the obvious one: a COD order never fires
 * `woocommerce_payment_complete`, so hooking payment-complete — which is what
 * the scope document literally specifies in §6 — would have silently failed to
 * credit a partner on every cash order. On an Indian D2C food store that is
 * likely most of them, and nothing would have errored. The partner would just
 * never hear about their sales.
 *
 * But binding attribution to ONE status makes it depend on COD landing there.
 * I could not verify how WooCommerce's COD gateway decides that status —
 * wordpress.org is unreachable from this environment — and I am not going to
 * write a guard against an option key I cannot confirm exists. This project has
 * already shipped invented Rank Math sub-keys that a --dry-run could not catch,
 * because writing to a key nothing reads succeeds.
 *
 * So the fix does not depend on knowing: attribution now ALSO fires on
 * `woocommerce_order_status_completed`, sharing the existing idempotency meta.
 * Whatever path a COD order takes to being real, the partner is credited once —
 * late at worst, rather than never. See inc/coupon-attribution.php.
 */
