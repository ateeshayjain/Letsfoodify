<?php
/**
 * The cart as a bill (client, 9 Nov 2026 — Blinkit's cart): "Bill details",
 * a "You saved ₹X" line above checkout, the shop's PUBLIC offers to tap
 * instead of typing, and "You might also like" under the cart.
 *
 * WHAT "YOU SAVED" COUNTS, AND ONLY THIS
 *   sale prices (the MRP struck through on the card, × quantity)
 *   + codes (WooCommerce's discount total, tax included)
 *   + the box discount (box-builder.php's fee)
 * The pay-online saving is NOT in it: it is decided at checkout, and the cart
 * already says "Pay online at checkout and save ₹25" (payments.php). A saving
 * shown before it exists is the kind of number this rebuild removes.
 *
 * AVAILABLE OFFERS ARE THE PRODUCT PAGE'S, NOT A NEW LIST
 *   The one coupon named in `foodify_public_offer` (offers.php), read from the
 *   live WC_Coupon and worded by foodify_offer_lines() — the same sentence the
 *   product page prints, so the two pages cannot disagree and no line here is
 *   typed by hand. Unset, expired or used up → no list. The theme never lists
 *   WooCommerce's coupons itself: partner and creator codes (WP-09) are
 *   coupons too, and listing them would hand every partner's code to every
 *   shopper. Apply goes through WooCommerce's own coupon form, which decides
 *   whether the code is valid for this cart.
 *
 * @package Foodify
 */
declare( strict_types = 1 );
defined( 'ABSPATH' ) || exit;

/**
 * Offer lines (foodify_offer_lines() shape) → what the cart lists: the coupon
 * lines only, each with its code. Payment and delivery lines are not offers to
 * apply — the cart already carries them as the free-delivery band and the
 * pay-online hint.
 *
 * @param array<int,array{key:string,text:string,detail:string}> $lines
 * @return array<int,array{code:string,title:string,detail:string}>
 */
function foodify_cart_offers( array $lines, string $code ): array {
	$code = strtoupper( trim( $code ) );
	if ( '' === $code ) {
		return [];
	}
	$out = [];
	foreach ( $lines as $l ) {
		if ( 'coupon' === ( $l['key'] ?? '' ) ) {
			$out[] = [ 'code' => $code, 'title' => (string) $l['text'], 'detail' => (string) ( $l['detail'] ?? '' ) ];
		}
	}
	return $out;
}

/**
 * The list under the cart. `$applied` are the codes already on the cart
 * (any case); those say "Applied" instead of offering a button.
 *
 * @param array<int,array{code:string,title:string,detail:string}> $offers
 * @param array<int,string>                                         $applied
 */
function foodify_public_offers_html( array $offers, array $applied = [] ): string {
	if ( ! $offers ) {
		return '';
	}
	$e  = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	$on = array_flip( array_map( 'strtoupper', array_map( 'strval', $applied ) ) );
	$li = '';
	foreach ( $offers as $o ) {
		$is = isset( $on[ $o['code'] ] );
		$li .= '<li class="fd-cart-offer' . ( $is ? ' is-applied' : '' ) . '">'
			. '<span class="fd-cart-offer__text"><strong class="fd-cart-offer__title">' . $e( $o['title'] ) . '</strong>'
			. ( '' !== $o['detail'] ? '<span class="fd-cart-offer__meta">' . $e( $o['detail'] ) . '</span>' : '' ) . '</span>'
			. ( $is
				? '<span class="fd-cart-offer__applied">Applied</span>'
				: '<button type="button" class="fd-secondary fd-cart-offer__apply" data-fd-offer="' . $e( $o['code'] ) . '" aria-label="' . $e( 'Apply ' . $o['code'] . ': ' . $o['title'] ) . '">Apply</button>' )
			. '</li>';
	}
	return '<section class="fd-offers-list" aria-labelledby="fd-offers-list-title">'
		. '<h2 class="fd-offers-list__title" id="fd-offers-list-title">Available offers</h2>'
		. '<ul class="fd-offers-list__items">' . $li . '</ul></section>';
}

/** Apply = WooCommerce's own coupon form: fill it, press it. Any page without that form: nothing. */
function foodify_public_offers_script(): string {
	return <<<'JS'
(function(){
  document.addEventListener('click',function(e){
    var b=e.target.closest?e.target.closest('[data-fd-offer]'):null;if(!b)return;
    var f=document.getElementById('coupon_code'),go=document.querySelector('[name="apply_coupon"]');if(!f||!go)return;
    e.preventDefault();f.value=b.getAttribute('data-fd-offer');go.click();
  });
})();
JS;
}

/**
 * What the shopper saves on this cart, in whole rupees (see the file comment).
 *
 * @param array<int,array{0:float,1:float,2:int}> $lines [regular price, price paid, quantity], tax included.
 */
function foodify_cart_savings( array $lines, float $codes, float $box ): int {
	$sale = 0.0;
	foreach ( $lines as $l ) {
		$reg = (float) ( $l[0] ?? 0 );
		$now = (float) ( $l[1] ?? 0 );
		$qty = max( 0, (int) ( $l[2] ?? 0 ) );
		if ( $reg > $now && $now >= 0 ) {
			$sale += ( $reg - $now ) * $qty;
		}
	}
	return (int) floor( $sale + max( 0.0, $codes ) + max( 0.0, $box ) + 1e-9 );
}

/** "You saved ₹110 on this order" — nothing under a rupee. */
function foodify_cart_saved_html( int $saved ): string {
	return $saved < 1 ? '' : '<p class="fd-saved">You saved <strong>₹' . number_format( $saved ) . '</strong> on this order</p>';
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** "Cart totals" → "Bill details", on the cart only — the words a bill uses. */
add_filter( 'gettext_woocommerce', static function ( $translation, $text ) {
	return 'Cart totals' === $text ? __( 'Bill details', 'foodify' ) : $translation;
}, 10, 2 );

add_action( 'woocommerce_after_cart_table', static function (): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$coupon = function_exists( 'foodify_offer_coupon' ) ? foodify_offer_coupon() : null;
	$offers = $coupon ? foodify_cart_offers( foodify_offer_lines( [ 'coupon' => $coupon ] ), (string) $coupon['code'] ) : [];
	if ( ! $offers ) {
		return;
	}
	echo foodify_public_offers_html( $offers, WC()->cart->get_applied_coupons() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the builder.
	echo '<script>' . foodify_public_offers_script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static script.
} );

add_action( 'woocommerce_proceed_to_checkout', static function (): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$lines = [];
	foreach ( WC()->cart->get_cart() as $item ) {
		$p = $item['data'] ?? null;
		if ( ! $p instanceof WC_Product ) {
			continue;
		}
		$reg     = (float) wc_get_price_to_display( $p, [ 'price' => (float) $p->get_regular_price() ] );
		$lines[] = [ $reg, (float) wc_get_price_to_display( $p ), (int) $item['quantity'] ];
	}
	$codes = (float) WC()->cart->get_discount_total() + (float) WC()->cart->get_discount_tax();
	$box   = function_exists( 'foodify_box_cart_discount_total' ) ? (float) foodify_box_cart_discount_total() : 0.0;
	echo foodify_cart_saved_html( foodify_cart_savings( $lines, $codes, $box ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from numbers.
}, 1 );
