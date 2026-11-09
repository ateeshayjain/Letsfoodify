<?php
/**
 * inc/cart-stepper.php — Add → − 1 + on cards, the View cart bar, and the
 * product page's in-place add (client direction, 9 Nov 2026).
 *
 * The behaviour itself (taps, reconciliation, box lines, the bar, the product
 * form) runs in a real browser in tests/preview-doors.js, against the theme's
 * own script. This file holds the markup, the words and the wiring.
 *
 *   php tests/cart-stepper-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/cart-stepper.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

echo "── the card's control ──\n";
$c = foodify_cart_control_html( 12, 'Dal <Fry>', '/?add-to-cart=12', 20, 'alignwide' );
check( 'one control per product, carrying its id and escaped name', str_contains( $c, 'data-fd-cartctl data-product-id="12" data-name="Dal &lt;Fry&gt;"' ) );
check( 'it keeps the product-button wrapper, so the card\'s button rules still apply', str_starts_with( $c, '<div class="wp-block-button wc-block-components-product-button fd-cartctl alignwide"' ) );
check( 'Add is WooCommerce\'s own add-to-cart link — it works without JavaScript', str_contains( $c, 'href="/?add-to-cart=12" rel="nofollow"' ) && str_contains( $c, '>Add</a>' ) && str_contains( $c, 'aria-label="Add Dal &lt;Fry&gt; to cart"' ) );
check( 'the stepper is printed hidden at 0: nothing about a cart reaches a cached page', str_contains( $c, 'class="fd-cartctl__stepper" role="group"' ) && str_contains( $c, ' hidden>' ) && str_contains( $c, 'data-fd-qty>0<' ) );
check( '− and + are buttons named for the product', str_contains( $c, 'data-fd-dec aria-label="One fewer Dal &lt;Fry&gt;">−</button>' ) && str_contains( $c, 'data-fd-inc aria-label="One more Dal &lt;Fry&gt;">+</button>' ) );
check( 'a purchase limit rides along', str_contains( $c, 'data-max="20"' ) && ! str_contains( foodify_cart_control_html( 12, 'X', '/a' ), 'data-max' ) );
check( 'no product, no control', '' === foodify_cart_control_html( 0, 'X', '/a' ) );

echo "── the View cart bar ──\n";
$bar = foodify_view_cart_bar_html( '/cart/' );
check( 'a link to the cart, printed hidden and empty', str_starts_with( $bar, '<div class="fd-viewcart" data-fd-viewcart hidden>' ) && str_contains( $bar, '<a class="fd-viewcart__link" href="/cart/">' ) && str_contains( $bar, 'data-fd-viewcart-sum></span>' ) );
check( 'the free-delivery strip rides on top, hidden until the script knows the cart', str_contains( $bar, '<p class="fd-viewcart__free" data-fd-viewcart-free hidden>' ) );
check( 'one live region for every change', 1 === substr_count( $bar, 'role="status" aria-live="polite" data-fd-cart-status' ) );
check( '"1 item", "3 items" — no amount (client, 9 Nov)', '1 item' === foodify_view_cart_summary( 1 ) && '3 items' === foodify_view_cart_summary( 3 ) && '' === foodify_view_cart_summary( 0 ) );

echo "── the script ──\n";
$js = foodify_cart_stepper_script();
check( 'the script builds the bar\'s words the same way', str_contains( $js, "function itemsText(n){ return n < 1 ? '' : n + (n === 1 ? ' item' : ' items'); }" ) && ! str_contains( $js, 'money(' ) );
check( 'it talks to the Store API: cart, add-item, update-item, remove-item', str_contains( $js, "http('GET', 'cart')" ) && str_contains( $js, "'cart/add-item'" ) && str_contains( $js, "'cart/update-item'" ) && str_contains( $js, "'cart/remove-item'" ) );
check( 'the nonce comes from a response, never from the (cacheable) page', str_contains( $js, "r.headers.get('Nonce')" ) && ! str_contains( $js, 'wp_create_nonce' ) );
check( 'a page with no cart cookie makes no request', str_contains( $js, 'woocommerce_items_in_cart=1' ) );
check( 'a pack inside a box is never the card\'s line', str_contains( $js, "function boxed(it){ return (it.item_data || []).some(function(d){ return (d.name || d.key) === BOX; }); }" ) && str_contains( $js, '!boxed(it)' ) );
check( 'taps are reconciled — one request per product in flight', str_contains( $js, 'if (busy[id]) return;' ) && str_contains( $js, 'sync(id, name);' ) );
check( 'the header mini-cart is told, without the script hearing itself', str_contains( $js, "'wc-blocks_added_to_cart'" ) && str_contains( $js, "if (!quiet) hydrate();" ) );
check( 'Back restores a cart the way it is now', str_contains( $js, "addEventListener('pageshow'" ) );
check( 'the product form adds in place for a simple product only', str_contains( $js, "f.matches('form.cart')" ) && str_contains( $js, "[name=variation_id]" ) && str_contains( $js, "'Added ✓'" ) );
check( '… and falls back to WooCommerce\'s own submit, add-to-cart field included', str_contains( $js, "h.name = 'add-to-cart'" ) && str_contains( $js, 'HTMLFormElement.prototype.submit.call(f)' ) );
check( 'the prototype (or a test) can stand in for the Store API', str_contains( $js, 'window.foodifyCartApi || store' ) );

echo "── wiring ──\n";
$src  = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/cart-stepper.php' );
$fns  = (string) file_get_contents( __DIR__ . '/../theme/foodify/functions.php' );
$boxs = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/box-builder.php' );
check( 'the theme loads it', str_contains( $fns, "require_once FOODIFY_DIR . '/inc/cart-stepper.php';" ) );
check( 'cards: simple, purchasable, in stock — everything else keeps WooCommerce\'s button',
	1 === preg_match( "/render_block_woocommerce\\/product-button.*?is_type\\( 'simple' \\).*?is_purchasable\\(\\).*?is_in_stock\\(\\)/s", $src ) );
check( 'the bar is not printed on the cart, checkout or account', 1 === preg_match( "/'wp_footer'.*?is_cart\\(\\).*?is_checkout\\(\\).*?is_account_page\\(\\)/s", $src ) );
check( 'the box label it skips is the one box-builder prints', str_contains( $src, "'boxLabel' => __( 'Box', 'foodify' )" ) && str_contains( $boxs, "'key'   => __( 'Box', 'foodify' )" ) );
$css = (string) file_get_contents( __DIR__ . '/../theme/foodify/style.css' );
check( 'the bar sits on a product page\'s sticky bar rather than under it', str_contains( $css, 'body:has(.fd-sticky-atc:not([hidden])) .fd-viewcart:not([hidden])' ) );
check( 'hidden halves stay hidden whatever the button rules say', str_contains( $css, '.fd-cartctl.fd-cartctl > [hidden] { display: none; }' ) );

echo "── free delivery on the bar (client, 9 Nov) ──\n";
$f = foodify_view_cart_free( 565.0, 599.0 );
check( '"Add ₹34 more for free delivery", with the meter at 94%', $f['show'] && ! $f['unlocked'] && 'Add ₹34 more for free delivery' === $f['text'] && 94.3 === $f['percent'] );
check( 'a part rupee rounds UP (₹33.50 short is "₹34")', 'Add ₹34 more for free delivery' === foodify_view_cart_free( 565.5, 599.0 )['text'] );
check( 'at the minimum: "Free delivery unlocked"', foodify_view_cart_free( 599.0, 599.0 )['unlocked'] && 'Free delivery unlocked' === foodify_view_cart_free( 650.0, 599.0 )['text'] );
check( 'no free-delivery minimum, or an empty cart: no strip', ! foodify_view_cart_free( 300.0, null )['show'] && ! foodify_view_cart_free( 0.0, 599.0 )['show'] );
check( 'thousands are grouped the Indian way', 'Add ₹1,234 more for free delivery' === foodify_view_cart_free( 100.0, 1334.0 )['text'] );
$js = foodify_cart_stepper_script();
check( 'the script\'s basis is WooCommerce\'s: items with tax, minus CODES (total_discount) — the box discount is a fee and stays out',
	str_contains( $js, "(+t.total_items || 0) + (+t.total_items_tax || 0) - (+t.total_discount || 0) - (+t.total_discount_tax || 0)" ) && ! str_contains( $js, 'total_fees' ) );
check( '… and builds the same words', str_contains( $js, "'Add ₹' + Math.ceil(min - basis).toLocaleString('en-IN') + ' more for free delivery'" ) && str_contains( $js, "'Free delivery unlocked'" ) );
check( 'the minimum comes from the one function the cart band uses', str_contains( (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/cart-stepper.php' ), "'freeMin'  => function_exists( 'foodify_free_shipping_threshold' ) ? foodify_free_shipping_threshold() : null" ) );

echo "── the product page's sticky bar ──\n";
$ctl = foodify_cart_control_html( 9, 'Dal', '/?add-to-cart=9', 0, 'fd-sticky-atc__ctl' );
$sb  = foodify_sticky_atc_html( '185', '<span class="amount">₹185</span>', 'Add to cart', 'pack', 'packs', $ctl );
check( 'the bar keeps its own button (it presses the real form, quantity and all)', str_contains( $sb, '<button type="button" class="wp-element-button">Add to cart</button>' ) );
check( '… and carries the card\'s control, which takes over once the meal is in the cart', str_contains( $sb, $ctl ) && str_contains( $css, '.fd-sticky-atc:has(.fd-sticky-atc__ctl.is-in-cart) > .wp-element-button { display: none; }' ) );
check( 'escaped: units and price figure', str_contains( foodify_sticky_atc_html( '1"', 'x', 'A', 'p<', 'q', '' ), 'data-unit="1&quot;"' ) && str_contains( foodify_sticky_atc_html( '1', 'x', 'A', 'p<', 'q', '' ), '1 p&lt;</span>' ) );
$pd = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/product-display.php' );
check( 'product-display prints the bar through the builder, with the control for a simple product', str_contains( $pd, 'echo foodify_sticky_atc_html(' ) && str_contains( $pd, "'fd-sticky-atc__ctl'" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
