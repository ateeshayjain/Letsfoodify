<?php
/**
 * Offers on the product page — only the ones checkout will actually apply.
 *
 * WHY THIS EXISTS. The buyer's question near the price is "is there a better
 * deal?", and Amazon/Blinkit answer it with an Offers list. The danger is the
 * one this rebuild keeps naming: an offer line is a number, and a number on
 * the product page that checkout does not honour is the "₹210 becomes ₹285"
 * surprise in reverse. So every line here is DERIVED from the rule that
 * charges the money:
 *
 *  - free delivery: the shipping zone's own free_shipping method (min_amount,
 *    ignore_discounts) — the same method WooCommerce consults at checkout;
 *  - the delivery charge below it: that zone's flat_rate cost, only when it is
 *    a plain number (a formula is not a number we can print);
 *  - paying online: foodify_payment_config() — the same config the fee and
 *    the payment-radio label read (payments.php), and only when an online
 *    gateway is actually enabled;
 *  - cash on delivery: only when the COD gateway is enabled, with the cap if
 *    one is set;
 *  - a coupon: only the one named in the option `foodify_public_offer`, read
 *    from the real WC_Coupon — its type, amount, minimum spend and expiry —
 *    and only while it is live and valid for this product. Unset = no line.
 *
 * Nothing is shown when nothing is configured; there is no default offer.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/lane-a-test.php without WordPress.
 * ---------------------------------------------------------------------- */

function foodify_offer_inr( float $amount ): string {
	return function_exists( 'foodify_inr' ) ? foodify_inr( $amount ) : '₹' . number_format( $amount, 0 );
}

/** "Until 30 Nov." from 'YYYY-MM-DD', or '' when absent or unreadable. */
function foodify_offer_until( string $ymd ): string {
	$d = DateTimeImmutable::createFromFormat( '!Y-m-d', trim( $ymd ) );
	return $d && $d->format( 'Y-m-d' ) === trim( $ymd ) ? 'Until ' . $d->format( 'j M' ) . '.' : '';
}

/**
 * The lines, in order: coupon, paying online, free delivery, cash on delivery.
 *
 * @param array{
 *   coupon?:?array{code:string,type:string,amount:float,min_spend?:float,expires?:string,free_shipping?:bool},
 *   payment?:array, prepaid_available?:bool, cod_available?:bool,
 *   free_shipping_min?:?float, shipping_flat?:?float, free_shipping_after_discounts?:bool
 * } $cfg
 * @return array<int,array{key:string,text:string,detail:string}>
 */
function foodify_offer_lines( array $cfg ): array {
	$lines = [];
	$add   = static function ( string $key, string $text, array $detail ) use ( &$lines ): void {
		$lines[] = [ 'key' => $key, 'text' => $text, 'detail' => trim( implode( ' ', array_filter( $detail ) ) ) ];
	};

	$c = $cfg['coupon'] ?? null;
	if ( is_array( $c ) && '' !== trim( (string) ( $c['code'] ?? '' ) ) ) {
		$code   = strtoupper( trim( (string) $c['code'] ) );
		$amount = (float) ( $c['amount'] ?? 0 );
		$type   = (string) ( $c['type'] ?? '' );
		$what   = '';
		if ( $amount > 0 && 'percent' === $type && $amount <= 100 ) {
			$what = rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . '% off your order';
		} elseif ( $amount > 0 && 'fixed_cart' === $type ) {
			$what = foodify_offer_inr( $amount ) . ' off your order';
		} elseif ( $amount > 0 && 'fixed_product' === $type ) {
			$what = foodify_offer_inr( $amount ) . ' off each pack';
		}
		if ( ! empty( $c['free_shipping'] ) ) {
			$what = '' === $what ? 'free delivery' : $what . ' and free delivery';
		}
		if ( '' !== $what ) {
			$min = (float) ( $c['min_spend'] ?? 0 );
			$add( 'coupon', "Use code {$code}: {$what}", [
				$min > 0 ? 'On orders of ' . foodify_offer_inr( $min ) . ' or more.' : '',
				foodify_offer_until( (string) ( $c['expires'] ?? '' ) ),
			] );
		}
	}

	$p = (array) ( $cfg['payment'] ?? [] );
	if ( ! empty( $cfg['prepaid_available'] ) && $p ) {
		$flat = (float) ( $p['prepaid_flat'] ?? 0 );
		$rate = (float) ( $p['prepaid_rate'] ?? 0 );
		$max  = (float) ( $p['prepaid_max'] ?? 0 );
		$minc = (float) ( $p['prepaid_min_cart'] ?? 0 );
		$what = '';
		// The same precedence as foodify_prepaid_discount(): a rate, when set, wins.
		if ( $rate > 0 ) {
			$pct  = rtrim( rtrim( number_format( $rate * 100, 2, '.', '' ), '0' ), '.' );
			$what = $pct . '%' . ( $max > 0 ? ' (up to ' . foodify_offer_inr( round( $max ) ) . ')' : '' );
		} elseif ( $flat > 0 ) {
			$what = foodify_offer_inr( round( $flat ) );
		}
		if ( '' !== $what ) {
			$add( 'prepaid', "Pay online and save {$what}", [
				'Taken off at checkout when you choose to pay now.',
				$minc > 0 ? 'On orders of ' . foodify_offer_inr( $minc ) . ' or more.' : '',
			] );
		}
	}

	$min = $cfg['free_shipping_min'] ?? null;
	if ( null !== $min && (float) $min > 0 ) {
		$flat  = $cfg['shipping_flat'] ?? null;
		$after = ! empty( $cfg['free_shipping_after_discounts'] );
		$below = null !== $flat && (float) $flat > 0 ? foodify_offer_inr( (float) $flat ) . ' delivery below that' : '';
		$add( 'shipping', 'Free delivery on orders of ' . foodify_offer_inr( (float) $min ) . ' or more', [
			'' !== $below ? $below . ( $after ? ', counted after any coupon.' : '.' ) : ( $after ? 'Counted after any coupon.' : '' ),
		] );
	}

	if ( ! empty( $cfg['cod_available'] ) ) {
		$cap = (float) ( $p['cod_max_value'] ?? 0 );
		$add( 'cod', 'Cash on delivery available', [ $cap > 0 ? 'On orders up to ' . foodify_offer_inr( $cap ) . '.' : '' ] );
	}

	return $lines;
}

/** The block's markup, or '' when there are no lines. */
function foodify_offers_html( array $lines ): string {
	if ( ! $lines ) {
		return '';
	}
	$e   = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$out = '<div class="fd-offers"><p class="fd-offers__title">Offers</p><ul class="fd-offers__list">';
	foreach ( $lines as $l ) {
		$out .= '<li class="fd-offer fd-offer--' . $e( $l['key'] ) . '"><span class="fd-offer__text">' . $e( $l['text'] ) . '</span>'
			. ( '' !== $l['detail'] ? '<span class="fd-offer__detail">' . $e( $l['detail'] ) . '</span>' : '' )
			. '</li>';
	}
	return $out . '</ul></div>';
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/**
 * The shipping facts the customer's zone would apply: the flat rate (only a
 * plain number) and whether free shipping is compared after discounts. The
 * threshold itself comes from foodify_free_shipping_threshold() (shortcodes.php),
 * so the cart bar and this line read one function.
 *
 * @return array{free_shipping_min:?float,shipping_flat:?float,free_shipping_after_discounts:bool}
 */
function foodify_offer_shipping_facts(): array {
	$facts = [ 'free_shipping_min' => null, 'shipping_flat' => null, 'free_shipping_after_discounts' => false ];
	if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_shipping_zone' ) ) {
		return $facts;
	}
	$facts['free_shipping_min'] = function_exists( 'foodify_free_shipping_threshold' ) ? foodify_free_shipping_threshold() : null;

	$customer = WC()->customer;
	$zone     = wc_get_shipping_zone( [ 'destination' => [
		'country'  => $customer ? $customer->get_shipping_country() : 'IN',
		'state'    => $customer ? $customer->get_shipping_state() : '',
		'postcode' => $customer ? $customer->get_shipping_postcode() : '',
	] ] );
	$flats = [];
	foreach ( $zone ? $zone->get_shipping_methods( true ) : [] as $m ) {
		if ( 'flat_rate' === $m->id ) {
			$cost    = trim( (string) $m->get_option( 'cost' ) );
			$flats[] = is_numeric( $cost ) ? (float) $cost : null;
		}
		if ( 'free_shipping' === $m->id && 'no' === $m->get_option( 'ignore_discounts' ) ) {
			$facts['free_shipping_after_discounts'] = true;
		}
	}
	// One flat rate with a plain cost is a number we can print; anything else is not.
	$facts['shipping_flat'] = 1 === count( $flats ) ? $flats[0] : null;
	return $facts;
}

/** The public coupon, as foodify_offer_lines() wants it — or null unless it is live for this product. */
function foodify_offer_coupon( WC_Product $product ): ?array {
	$code = trim( (string) get_option( 'foodify_public_offer', '' ) );
	if ( '' === $code || ! class_exists( 'WC_Coupon' ) ) {
		return null;
	}
	$c = new WC_Coupon( $code );
	if ( ! $c->get_id() || 'publish' !== get_post_status( $c->get_id() ) ) {
		return null;
	}
	$expires = $c->get_date_expires();
	if ( $expires && $expires->getTimestamp() < time() ) {
		return null;
	}
	if ( $c->get_usage_limit() > 0 && $c->get_usage_count() >= $c->get_usage_limit() ) {
		return null;
	}
	if ( $c->get_email_restrictions() ) {
		return null;   // restricted to named customers: not a public offer
	}
	$ids = array_filter( [ $product->get_id(), $product->get_parent_id() ] );
	$cats = (array) $product->get_category_ids();
	if ( ( $c->get_product_ids() && ! array_intersect( $ids, $c->get_product_ids() ) )
		|| array_intersect( $ids, $c->get_excluded_product_ids() )
		|| ( $c->get_product_categories() && ! array_intersect( $cats, $c->get_product_categories() ) )
		|| array_intersect( $cats, $c->get_excluded_product_categories() ) ) {
		return null;   // checkout would refuse it for this product
	}
	return [
		'code'          => $c->get_code(),
		'type'          => $c->get_discount_type(),
		'amount'        => (float) $c->get_amount(),
		'min_spend'     => (float) $c->get_minimum_amount(),
		'expires'       => $expires ? $expires->date( 'Y-m-d' ) : '',
		'free_shipping' => (bool) $c->get_free_shipping(),
	];
}

/** Everything the lines need, from the live store. */
function foodify_offer_config( WC_Product $product ): array {
	$payment  = function_exists( 'foodify_payment_config' ) ? foodify_payment_config() : [];
	$cod_ids  = (array) ( $payment['cod_methods'] ?? [] );
	$prepaid  = false;
	$cod      = false;
	$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
	foreach ( $gateways as $id => $g ) {
		if ( 'yes' !== ( $g->enabled ?? 'no' ) ) {
			continue;
		}
		if ( in_array( (string) $id, $cod_ids, true ) ) {
			$cod = true;
		} else {
			$prepaid = true;
		}
	}
	return [
		'coupon'            => foodify_offer_coupon( $product ),
		'payment'           => $payment,
		'prepaid_available' => $prepaid,
		'cod_available'     => $cod,
	] + foodify_offer_shipping_facts();
}

/** Under the price block on the product page, after the yield strip. */
add_filter( 'render_block_woocommerce/product-price', static function ( string $html, array $block ): string {
	if ( '' === $html || ! empty( $block['attrs']['isDescendentOfQueryLoop'] ) || ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product || ! $product->is_in_stock() ) {
		return $html;
	}
	return $html . foodify_offers_html( foodify_offer_lines( foodify_offer_config( $product ) ) ); // Escaped in the builder.
}, 30, 2 );
