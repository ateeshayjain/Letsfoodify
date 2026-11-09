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
 * THE ITEMS, AS ONE CARD (client, 9 Nov 2026 — Blinkit's checkout)
 *   "Shipment of 3 items" over the lines, with the delivery date for this
 *   device's PIN; each line: picture · name, pack size, Remove · − N + with
 *   the struck and paid price. The − / + only change WooCommerce's own
 *   quantity field and fire its input event: checkout-flow.php's auto-apply
 *   (foodify_cart_qty_script) submits it, so a page without JavaScript keeps
 *   the plain field and its Update cart button.
 *   On a phone the totals' Proceed becomes a bar fixed to the bottom:
 *   "Delivering · By Wed 11 Nov · 201301 · Change", then the total and
 *   "Proceed to checkout ›". Not borrowed: a paid gift bag (no such product
 *   or fee exists — the client's call) and "Move to wishlist" (no wishlist).
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

/** Over the lines: how many, and when they arrive (filled in the browser from this device's PIN). */
function foodify_cart_ship_html( int $count ): string {
	if ( $count < 1 ) {
		return '';
	}
	return '<div class="fd-cart-ship">'
		. '<svg class="fd-cart-ship__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7"/><path d="M7.5 18.5a1.5 1.5 0 1 0 0-.01M17.5 18.5a1.5 1.5 0 1 0 0-.01"/></svg>'
		. '<div class="fd-cart-ship__text"><p class="fd-cart-ship__title">Shipment of ' . $count . ( 1 === $count ? ' item' : ' items' ) . '</p>'
		. '<p class="fd-cart-ship__when" data-fd-dline hidden><span data-fd-dline-text></span> <button type="button" class="fd-linkbtn" data-fd-dline-open>Change</button></p></div>'
		. '</div>';
}

/**
 * − N + around WooCommerce's own quantity field. A field WooCommerce prints as
 * hidden (sold one per order) is left alone: there is nothing to step.
 */
function foodify_cart_qty_html( string $field, string $name ): string {
	if ( '' === $field || false !== strpos( $field, 'type="hidden"' ) ) {
		return $field;
	}
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	return '<div class="fd-cartqty" data-fd-cartqty>'
		. '<button type="button" class="fd-cartqty__step" data-fd-cartqty-step="-1" aria-label="' . $e( 'One fewer ' . $name ) . '">−</button>'
		. $field
		. '<button type="button" class="fd-cartqty__step" data-fd-cartqty-step="1" aria-label="' . $e( 'One more ' . $name ) . '">+</button>'
		. '</div>';
}

/** The pack size under the name: "80 g". */
function foodify_cart_pack_html( string $pack ): string {
	$pack = trim( $pack );
	return '' === $pack ? '' : '<span class="fd-cart-line__pack">' . htmlspecialchars( $pack, ENT_QUOTES, 'UTF-8' ) . '</span>';
}

/** The line's total with the price it would have been struck through before it, when it is lower. */
function foodify_cart_struck_html( string $subtotal_html, string $was_html ): string {
	return '' === $was_html ? $subtotal_html : '<del class="fd-cart-line__was">' . $was_html . '</del> ' . $subtotal_html;
}

/** The phone pay bar's total: "₹618 / TOTAL". */
function foodify_cart_paybar_total_html( string $total_html ): string {
	return '<span class="fd-paybar__total"><span class="fd-paybar__amount">' . $total_html . '</span><span class="fd-paybar__label">Total</span></span>';
}

/** The phone pay bar's delivery row, filled from this device's PIN like the header line. */
function foodify_cart_paybar_to_html(): string {
	return '<p class="fd-paybar__to" data-fd-dline hidden><span class="fd-paybar__to-label">Delivering</span> <span data-fd-dline-text></span> <button type="button" class="fd-linkbtn" data-fd-dline-open>Change</button></p>';
}

/**
 * − / + : step WooCommerce's field (within its min and max) and fire its input
 * event; checkout-flow.php's auto-apply submits it after a pause, so five
 * quick taps are one update. After WooCommerce redraws the cart, the delivery
 * rows are filled again.
 */
function foodify_cart_lines_script(): string {
	return <<<'JS'
(function(){
  document.addEventListener('click',function(e){
    var b=e.target.closest?e.target.closest('[data-fd-cartqty-step]'):null;if(!b)return;
    var f=b.closest('[data-fd-cartqty]').querySelector('input.qty');if(!f)return;
    e.preventDefault();
    var n=(parseInt(f.value,10)||0)+parseInt(b.getAttribute('data-fd-cartqty-step'),10),
        min=f.hasAttribute('min')?parseInt(f.getAttribute('min'),10)||0:0,max=parseInt(f.getAttribute('max'),10);
    if(n<min)n=min;if(max>0&&n>max)n=max;
    f.value=String(n);f.dispatchEvent(new Event('input',{bubbles:true}));f.dispatchEvent(new Event('change',{bubbles:true}));
  });
  if(window.jQuery)window.jQuery(document.body).on('updated_wc_div updated_cart_totals',function(){document.dispatchEvent(new CustomEvent('foodify:pin'));});
})();
JS;
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

add_action( 'woocommerce_cart_totals_after_order_total', static function (): void {
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
	$saved = foodify_cart_saved_html( foodify_cart_savings( $lines, $codes, $box ) );
	if ( '' !== $saved ) {
		echo '<tr class="fd-saved-row"><td colspan="2">' . $saved . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from numbers.
	}
}, 20 );

/* The items card. Each filter runs on the cart page only: the same filters
   draw the mini-cart and the checkout's order review, which keep their own look. */
add_action( 'woocommerce_before_cart_table', static function (): void {
	if ( function_exists( 'WC' ) && WC()->cart ) {
		echo foodify_cart_ship_html( (int) WC()->cart->get_cart_contents_count() ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from a number.
	}
} );
add_filter( 'woocommerce_cart_item_quantity', static function ( $html, $key, $item ) {
	$p = $item['data'] ?? null;
	return is_cart() && $p instanceof WC_Product ? foodify_cart_qty_html( (string) $html, $p->get_name() ) : $html;
}, 20, 3 );
add_filter( 'woocommerce_cart_item_name', static function ( $name, $item ) {
	$p = $item['data'] ?? null;
	if ( ! is_cart() || ! $p instanceof WC_Product ) {
		return $name;
	}
	$id = $p->get_parent_id() ?: $p->get_id();
	return $name . foodify_cart_pack_html( (string) get_post_meta( $id, '_foodify_net_quantity', true ) );
}, 20, 2 );
add_filter( 'woocommerce_cart_item_subtotal', static function ( $subtotal, $item ) {
	$p = $item['data'] ?? null;
	if ( ! is_cart() || ! $p instanceof WC_Product || ! $p->is_on_sale() ) {
		return $subtotal;
	}
	$qty = (int) ( $item['quantity'] ?? 1 );
	$reg = (float) wc_get_price_to_display( $p, [ 'price' => (float) $p->get_regular_price(), 'qty' => $qty ] );
	$now = (float) wc_get_price_to_display( $p, [ 'qty' => $qty ] );
	return $reg > $now ? foodify_cart_struck_html( (string) $subtotal, wc_price( $reg ) ) : $subtotal;
}, 20, 2 );
add_filter( 'woocommerce_cart_item_remove_link', static function ( $link ) {
	return is_cart() ? (string) preg_replace( '/>\s*&times;\s*<\/a>|>\s*×\s*<\/a>/u', '>Remove</a>', (string) $link, 1 ) : $link;
}, 20 );
// The phone pay bar: WooCommerce's own Proceed button, with the delivery row and the total beside it.
add_action( 'woocommerce_proceed_to_checkout', static function (): void {
	if ( function_exists( 'WC' ) && WC()->cart ) {
		echo foodify_cart_paybar_to_html() . foodify_cart_paybar_total_html( WC()->cart->get_total() ); // phpcs:ignore WordPress.Security.EscapeOutput -- WooCommerce's formatted total.
	}
}, 5 );
add_action( 'wp_footer', static function (): void {
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		echo '<script>' . foodify_cart_lines_script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static script.
	}
}, 30 );
