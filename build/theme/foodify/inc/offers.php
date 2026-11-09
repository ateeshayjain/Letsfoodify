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
 *  - cash on delivery (an ASSURANCE line since Nov 2026, foodify_cod_line —
 *    a way to pay, not an offer): only when the COD gateway is enabled, with the cap if
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
 * The lines, in order: coupon, paying online, free delivery.
 *
 * Cash on delivery is NOT an offer — it is a way to pay, and it used to be
 * printed here and nowhere else (UX review 8 Nov 2026, PRODUCT-12). It is now
 * one line among the assurances (foodify_cod_line(), product-display.php).
 * Each line carries `short`, the few words the collapsed Offers summary uses.
 *
 * @param array{
 *   coupon?:?array{code:string,type:string,amount:float,min_spend?:float,expires?:string,free_shipping?:bool},
 *   payment?:array, prepaid_available?:bool, cod_available?:bool,
 *   free_shipping_min?:?float, shipping_flat?:?float, free_shipping_after_discounts?:bool
 * } $cfg
 * @return array<int,array{key:string,text:string,detail:string,short:string}>
 */
function foodify_offer_lines( array $cfg ): array {
	$lines = [];
	$add   = static function ( string $key, string $text, array $detail, string $short ) use ( &$lines ): void {
		$lines[] = [ 'key' => $key, 'text' => $text, 'detail' => trim( implode( ' ', array_filter( $detail ) ) ), 'short' => $short ];
	};

	$c = $cfg['coupon'] ?? null;
	if ( is_array( $c ) && '' !== trim( (string) ( $c['code'] ?? '' ) ) ) {
		$code   = strtoupper( trim( (string) $c['code'] ) );
		$amount = (float) ( $c['amount'] ?? 0 );
		$type   = (string) ( $c['type'] ?? '' );
		$what   = '';
		$off    = '';
		if ( $amount > 0 && 'percent' === $type && $amount <= 100 ) {
			$off  = rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . '% off';
			$what = $off . ' your order';
		} elseif ( $amount > 0 && 'fixed_cart' === $type ) {
			$off  = foodify_offer_inr( $amount ) . ' off';
			$what = $off . ' your order';
		} elseif ( $amount > 0 && 'fixed_product' === $type ) {
			$off  = foodify_offer_inr( $amount ) . ' off each pack';
			$what = $off;
		}
		if ( ! empty( $c['free_shipping'] ) ) {
			$what = '' === $what ? 'free delivery' : $what . ' and free delivery';
			$off  = '' === $off ? 'free delivery' : $off . ' + free delivery';
		}
		if ( '' !== $what ) {
			$min = (float) ( $c['min_spend'] ?? 0 );
			$add( 'coupon', "Use code {$code}: {$what}", [
				$min > 0 ? 'On orders of ' . foodify_offer_inr( $min ) . ' or more.' : '',
				foodify_offer_until( (string) ( $c['expires'] ?? '' ) ),
			], "Code {$code}: {$off}" );
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
			], "Pay online, save {$what}" );
		}
	}

	$min = $cfg['free_shipping_min'] ?? null;
	if ( null !== $min && (float) $min > 0 ) {
		$flat  = $cfg['shipping_flat'] ?? null;
		$after = ! empty( $cfg['free_shipping_after_discounts'] );
		$below = null !== $flat && (float) $flat > 0 ? foodify_offer_inr( (float) $flat ) . ' delivery below that' : '';
		$add( 'shipping', 'Free delivery on orders of ' . foodify_offer_inr( (float) $min ) . ' or more', [
			'' !== $below ? $below . ( $after ? ', counted after any coupon.' : '.' ) : ( $after ? 'Counted after any coupon.' : '' ),
		], 'Free delivery from ' . foodify_offer_inr( (float) $min ) );
	}

	return $lines;
}

/**
 * The cash-on-delivery assurance line, or '' when the COD gateway is off.
 * Same inputs as foodify_offer_lines(): `cod_available` and the payment
 * config's `cod_max_value`.
 */
function foodify_cod_line( array $cfg ): string {
	if ( empty( $cfg['cod_available'] ) ) {
		return '';
	}
	$cap = (float) ( ( (array) ( $cfg['payment'] ?? [] ) )['cod_max_value'] ?? 0 );
	return $cap > 0 ? 'Cash on delivery on orders up to ' . foodify_offer_inr( $cap ) : 'Cash on delivery available';
}

/** "2 offers: Pay online, save ₹25 · Free delivery from ₹599" — the collapsed line. */
function foodify_offers_summary( array $lines ): string {
	$n = count( $lines );
	if ( 0 === $n ) {
		return '';
	}
	$short = array_map( static fn( array $l ): string => (string) ( $l['short'] ?? $l['text'] ), $lines );
	return ( 1 === $n ? '1 offer: ' : $n . ' offers: ' ) . implode( ' · ', $short );
}

/**
 * The block's markup, or '' when there are no lines.
 *
 * One line, closed — UX review 8 Nov 2026, PRODUCT-3/10: three offers, each
 * with a detail sentence, sat between the price and Add to cart and pushed the
 * button below the first screen. Now a <details> under the button: the summary
 * names every offer in a few words, the list behind it carries the conditions.
 * A native <details> needs no script and is keyboard- and reader-operable.
 */
function foodify_offers_html( array $lines ): string {
	if ( ! $lines ) {
		return '';
	}
	$e   = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$out = '<details class="fd-offers"><summary class="fd-offers__title">' . $e( foodify_offers_summary( $lines ) ) . '</summary><ul class="fd-offers__list">';
	foreach ( $lines as $l ) {
		$out .= '<li class="fd-offer fd-offer--' . $e( $l['key'] ) . '"><span class="fd-offer__text">' . $e( $l['text'] ) . '</span>'
			. ( '' !== $l['detail'] ? '<span class="fd-offer__detail">' . $e( $l['detail'] ) . '</span>' : '' )
			. '</li>';
	}
	return $out . '</ul></details>';
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

/**
 * The public coupon, as foodify_offer_lines() wants it — or null unless it is
 * live (and, given a product, valid for it). The cart passes no product: there
 * WooCommerce itself checks the code against every line when it is applied.
 */
function foodify_offer_coupon( ?WC_Product $product = null ): ?array {
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
	if ( null === $product ) {
		return foodify_offer_coupon_facts( $c, $expires );
	}
	$ids = array_filter( [ $product->get_id(), $product->get_parent_id() ] );
	$cats = (array) $product->get_category_ids();
	if ( ( $c->get_product_ids() && ! array_intersect( $ids, $c->get_product_ids() ) )
		|| array_intersect( $ids, $c->get_excluded_product_ids() )
		|| ( $c->get_product_categories() && ! array_intersect( $cats, $c->get_product_categories() ) )
		|| array_intersect( $cats, $c->get_excluded_product_categories() ) ) {
		return null;   // checkout would refuse it for this product
	}
	return foodify_offer_coupon_facts( $c, $expires );
}

/** What the offer line reads from a live coupon. */
function foodify_offer_coupon_facts( WC_Coupon $c, $expires ): array {
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

/**
 * Under the add-to-cart form, after the delivery check (priority 10,
 * delivery-estimate.php) — decision 5 of the UX review. It used to hang off the
 * price block, between the price and the button.
 */
add_filter( 'render_block_woocommerce/add-to-cart-form', static function ( string $html ): string {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product || ! $product->is_in_stock() ) {
		return $html;
	}
	return $html . foodify_offers_html( foodify_offer_lines( foodify_offer_config( $product ) ) ); // Escaped in the builder.
}, 11 );
