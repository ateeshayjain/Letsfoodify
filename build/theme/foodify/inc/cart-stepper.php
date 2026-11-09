<?php
/**
 * Add → − 1 + on every product card, and a "View cart" bar on phones.
 *
 * Client direction, 9 Nov 2026 (a Blinkit screenshot): once a product is in
 * the cart, its card shows the quantity with − and + in place of the button,
 * and a bar along the bottom of the phone says how much is in the cart and
 * opens it. This replaces "Add another · 1 in cart" (BROWSE-7): the count and
 * both directions now live on the card, so taking one out no longer means a
 * trip to the cart.
 *
 * WHAT IS PRINTED, AND WHY NOTHING ABOUT THE CART IS. Shop, category, search
 * and home pages are cached at the edge. A card printed with "2 in cart" would
 * be served to the next visitor with that visitor's cart nowhere in it. So the
 * page always prints the plain Add button and a hidden bar; the script reads
 * THIS browser's cart from WooCommerce's Store API after load (only when
 * WooCommerce's woocommerce_items_in_cart cookie says there is one) and
 * paints the steppers and the bar from it.
 *
 * WHICH LINE A CARD COUNTS. A pack inside a build-your-own box is a separate
 * cart line for the same product (box-builder.php marks it with a "Box" item
 * line). The card counts and changes only the loose line: − on a card never
 * takes a pack out of a box, which would break the box and its discount.
 *
 * Without JavaScript the Add button is WooCommerce's own ?add-to-cart= link.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The card's control. `$add_url` is WooCommerce's add_to_cart_url() — the
 * no-JavaScript path. `$max` is the most one order may hold (0 = no limit).
 * `$wrapper_class` keeps the product-button block's own wrapper classes, so
 * the card's existing button rules still apply to Add.
 */
function foodify_cart_control_html( int $product_id, string $name, string $add_url, int $max = 0, string $wrapper_class = '' ): string {
	if ( $product_id < 1 ) {
		return '';
	}
	$e    = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	$name = trim( $name );
	return '<div class="' . $e( trim( 'wp-block-button wc-block-components-product-button fd-cartctl ' . $wrapper_class ) ) . '"'
		. ' data-fd-cartctl data-product-id="' . $product_id . '" data-name="' . $e( $name ) . '"'
		. ( $max > 0 ? ' data-max="' . $max . '"' : '' ) . '>'
		. '<a class="wp-block-button__link wp-element-button add_to_cart_button fd-cartctl__add" href="' . $e( $add_url ) . '" rel="nofollow"'
		. ' data-fd-add aria-label="' . $e( 'Add ' . $name . ' to cart' ) . '">Add</a>'
		. '<div class="fd-cartctl__stepper" role="group" aria-label="' . $e( $name . ' in your cart' ) . '" hidden>'
		. '<button type="button" class="fd-cartctl__step" data-fd-dec aria-label="' . $e( 'One fewer ' . $name ) . '">−</button>'
		. '<span class="fd-cartctl__qty" data-fd-qty>0</span>'
		. '<button type="button" class="fd-cartctl__step" data-fd-inc aria-label="' . $e( 'One more ' . $name ) . '">+</button>'
		. '</div></div>';
}

/**
 * The "View cart" bar. Printed hidden and empty: the script fills it from
 * this browser's cart (see the file comment on caching) — up to three of the
 * items' pictures, overlapping, and "2 items". One live region rides along for
 * what the steppers announce.
 */
function foodify_view_cart_bar_html( string $cart_url ): string {
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	return '<a class="fd-viewcart" href="' . $e( $cart_url ) . '" data-fd-viewcart hidden>'
		. '<span class="fd-viewcart__thumb" data-fd-viewcart-thumb aria-hidden="true"></span>'
		. '<span class="fd-viewcart__text"><strong class="fd-viewcart__title">View cart</strong>'
		. '<span class="fd-viewcart__sum" data-fd-viewcart-sum></span></span>'
		. '<span class="fd-viewcart__go" aria-hidden="true">›</span></a>'
		. '<p class="fd-sr" role="status" aria-live="polite" data-fd-cart-status></p>';
}

/**
 * "1 item", "3 items". No amount: the client wants the count only (9 Nov 2026),
 * here and on the header's cart — the total is on the cart page, with delivery.
 * The script builds the same words (itemsText); a test holds the two together.
 */
function foodify_view_cart_summary( int $count ): string {
	return $count < 1 ? '' : $count . ( 1 === $count ? ' item' : ' items' );
}

/**
 * The browser half. Talks to WooCommerce's Store API (wc/store/v1) unless the
 * page provides window.foodifyCartApi with the same four calls — the
 * prototype does, over its own cart. Every call resolves to a Store API cart.
 *
 * Taps are reconciled, not queued: each card holds the quantity the shopper
 * asked for; one request per product is in flight; when it returns and the
 * cart still differs from what was asked, one more goes. Ten fast taps on +
 * are two or three requests, and the number on the card never runs backwards.
 */
function foodify_cart_stepper_script(): string {
	return <<<'JS'
(function(){
  var cfg = window.FOODIFY_CART || {};
  var BOX = cfg.boxLabel || 'Box';
  var nonce = '', cart = null, want = {}, busy = {}, quiet = false;

  function http(method, path, body){
    var h = { 'Content-Type': 'application/json' };
    if (nonce) h.Nonce = nonce;
    return fetch((cfg.api || '/wp-json/wc/store/v1/') + path, { method: method, credentials: 'same-origin', headers: h, body: body ? JSON.stringify(body) : undefined })
      .then(function(r){
        var n = r.headers.get('Nonce'); if (n) nonce = n;
        return r.json().then(function(j){ if (!r.ok) throw j; return j; });
      });
  }
  // The cart's nonce comes back on any Store API response; a cached page cannot carry one.
  function ready(){ return nonce ? Promise.resolve() : http('GET', 'cart'); }
  var store = {
    get:    function(){ return http('GET', 'cart'); },
    add:    function(id, q){ return ready().then(function(){ return http('POST', 'cart/add-item', { id: id, quantity: q }); }); },
    set:    function(key, q){ return ready().then(function(){ return http('POST', 'cart/update-item', { key: key, quantity: q }); }); },
    remove: function(key){ return ready().then(function(){ return http('POST', 'cart/remove-item', { key: key }); }); }
  };
  function api(){ return window.foodifyCartApi || store; }

  function boxed(it){ return (it.item_data || []).some(function(d){ return (d.name || d.key) === BOX; }); }
  function loose(id){
    return ((cart && cart.items) || []).filter(function(it){ return +it.id === id && !boxed(it); })[0] || null;
  }
  function inCart(id){ var l = loose(id); return l ? +l.quantity : 0; }
  function shown(id){ return want[id] != null ? want[id] : inCart(id); }
  function limit(el, id){
    var l = loose(id), m = l && l.quantity_limits && +l.quantity_limits.maximum;
    var d = +(el.getAttribute('data-max') || 0);
    return Math.min(m > 0 ? m : 9999, d > 0 ? d : 9999);
  }
  // foodify_view_cart_summary()'s words.
  function itemsText(n){ return n < 1 ? '' : n + (n === 1 ? ' item' : ' items'); }

  function paint(){
    [].forEach.call(document.querySelectorAll('[data-fd-cartctl]'), function(el){
      var id = +el.getAttribute('data-product-id'), q = shown(id), name = el.getAttribute('data-name') || '';
      var add = el.querySelector('[data-fd-add]'), st = el.querySelector('.fd-cartctl__stepper');
      el.classList.toggle('is-in-cart', q > 0);
      add.hidden = q > 0; st.hidden = q < 1;
      el.querySelector('[data-fd-qty]').textContent = String(q);
      st.setAttribute('aria-label', name + ': ' + q + ' in your cart');
      var inc = el.querySelector('[data-fd-inc]');
      inc.disabled = q >= limit(el, id);
      el.setAttribute('aria-busy', busy[id] ? 'true' : 'false');
    });
    var bar = document.querySelector('[data-fd-viewcart]');
    if (bar) {
      var n = (cart && +cart.items_count) || 0;
      bar.hidden = n < 1;
      bar.querySelector('[data-fd-viewcart-sum]').textContent = itemsText(n);
      // Up to three of the items' pictures, overlapping, newest first; with none, the count.
      var th = bar.querySelector('[data-fd-viewcart-thumb]'), pics = [];
      ((cart && cart.items) || []).slice().reverse().forEach(function(it){
        var im = it.images && it.images[0], src = im && (im.thumbnail || im.src);
        if (src && pics.length < 3 && pics.indexOf(src) < 0) pics.push(src);
      });
      th.innerHTML = '';
      pics.forEach(function(src){ var i = new Image(); i.src = src; i.alt = ''; i.width = 40; i.height = 40; th.appendChild(i); });
      if (!pics.length && n) th.textContent = String(n);
      th.classList.toggle('is-count', !pics.length);
      bar.setAttribute('aria-label', n ? 'View cart, ' + itemsText(n) : 'View cart');
    }
  }
  function say(msg){ var s = document.querySelector('[data-fd-cart-status]'); if (s) { s.textContent = ''; s.textContent = msg; } }
  // The header mini-cart refreshes on these (buy-again.php uses the same pair).
  function tell(added){
    quiet = true;
    document.body.dispatchEvent(new CustomEvent(added ? 'wc-blocks_added_to_cart' : 'wc-blocks_removed_from_cart', { bubbles: true, cancelable: true }));
    quiet = false;
  }

  function sync(id, name){
    if (busy[id]) return;
    var target = want[id], cur = inCart(id);
    if (target == null || target === cur) { delete want[id]; paint(); return; }
    busy[id] = true; paint();
    var line = loose(id);
    var p = !line ? api().add(id, target - cur) : target < 1 ? api().remove(line.key) : api().set(line.key, target);
    p.then(function(c){
      cart = c; busy[id] = false;
      var now = inCart(id);
      tell(now >= cur);
      if (want[id] == null || want[id] === now) { delete want[id]; say(now ? name + ': ' + now + ' in your cart' : name + ' removed from your cart'); }
      sync(id, name);
    }).catch(function(err){
      busy[id] = false; delete want[id]; paint();
      say((err && err.message ? String(err.message).replace(/<[^>]*>/g, '') : 'Your cart did not change.') + ' Please try again.');
    });
  }

  document.addEventListener('click', function(e){
    var t = e.target.closest ? e.target.closest('[data-fd-add],[data-fd-inc],[data-fd-dec]') : null;
    var el = t && t.closest('[data-fd-cartctl]'); if (!el) return;
    e.preventDefault();
    var id = +el.getAttribute('data-product-id'), name = el.getAttribute('data-name') || 'It', q = shown(id);
    if (t.hasAttribute('data-fd-add') || t.hasAttribute('data-fd-inc')) q = Math.min(q + 1, limit(el, id));
    else q = Math.max(q - 1, 0);
    want[id] = q; paint();
    // Keep the keyboard where the shopper is: Add becomes the stepper's +, the last − becomes Add.
    if (t.hasAttribute('data-fd-add')) el.querySelector('[data-fd-inc]').focus();
    else if (q === 0) el.querySelector('[data-fd-add]').focus();
    sync(id, name);
  });

  // The product page's own Add to cart adds in place too: no reload, no trip to
  // the cart — the bar comes up and the shopper keeps browsing (client, 9 Nov).
  // A simple product's form only (one "add-to-cart" button, one quantity); a
  // variable or grouped form keeps WooCommerce's own submit.
  document.addEventListener('submit', function(e){
    var f = e.target;
    if (!f.matches || !f.matches('form.cart') || f.querySelector('[name=variation_id]') || f.querySelectorAll('input[name^="quantity"]').length > 1) return;
    var b = f.querySelector('[name="add-to-cart"]'), id = b ? +b.value : 0;
    if (!id) return;
    e.preventDefault();
    if (b.getAttribute('aria-disabled') === 'true') return;
    var q = Math.max(1, parseInt((f.querySelector('input[name="quantity"], input.qty') || {}).value, 10) || 1);
    var name = (((f.closest('main') || document).querySelector('.product_title, h1') || {}).textContent || 'It').trim();
    var label = b.textContent;
    b.setAttribute('aria-disabled', 'true');
    api().add(id, q).then(function(c){
      cart = c; paint(); tell(true);
      say(q + ' × ' + name + ' added to your cart');
      b.textContent = 'Added ✓';
      setTimeout(function(){ b.textContent = label; b.removeAttribute('aria-disabled'); }, 1400);
    }).catch(function(){
      // WooCommerce's own add, which reports its own errors on the next page.
      b.removeAttribute('aria-disabled');
      var h = document.createElement('input'); h.type = 'hidden'; h.name = 'add-to-cart'; h.value = String(id); f.appendChild(h);
      HTMLFormElement.prototype.submit.call(f);
    });
  });

  function hydrate(){
    var has = window.foodifyCartApi || /(?:^|;\s*)woocommerce_items_in_cart=1/.test(document.cookie);
    if (!has) { cart = null; paint(); return; }
    api().get().then(function(c){ cart = c; paint(); }).catch(function(){ paint(); });
  }
  // Something else changed the cart (the mini-cart drawer, buy-again, a box): read it again.
  ['wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart'].forEach(function(ev){
    document.body.addEventListener(ev, function(){ if (!quiet) hydrate(); });
  });
  // Back/forward restores a page from memory with the cart as it was then.
  window.addEventListener('pageshow', function(e){ if (e.persisted) hydrate(); });
  window.foodifyCartRefresh = hydrate;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hydrate); else hydrate();
})();
JS;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/**
 * Cards: WooCommerce's Product Button becomes the control for a product that
 * can simply be added — simple, purchasable, in stock. Anything else (sold
 * out with its "Notify me", a variable product) keeps WooCommerce's button.
 */
add_filter( 'render_block_woocommerce/product-button', static function ( string $html, array $block, $instance = null ): string {
	if ( is_admin() || ! function_exists( 'wc_get_product' ) ) {
		return $html;
	}
	$id      = is_object( $instance ) && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : (int) get_the_ID();
	$product = $id > 0 ? wc_get_product( $id ) : null;
	if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return $html;
	}
	$max   = (int) $product->get_max_purchase_quantity();
	$align = preg_match( '/class="wp-block-button([^"]*)"/', $html, $m ) ? trim( str_replace( [ 'wp-block-button', 'wc-block-components-product-button' ], '', $m[1] ) ) : '';
	return foodify_cart_control_html( $id, $product->get_name(), $product->add_to_cart_url(), $max > 0 ? $max : 0, $align );
}, 10, 3 );

/** The bar, on pages where buying continues — not the cart, checkout or account. */
add_action( 'wp_footer', static function (): void {
	if ( ! function_exists( 'wc_get_cart_url' ) || is_admin()
		|| ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
		return;
	}
	echo foodify_view_cart_bar_html( wc_get_cart_url() ) . "\n";   // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
}, 5 );

add_action( 'wp_enqueue_scripts', static function (): void {
	if ( is_admin() || ! function_exists( 'wc_get_cart_url' ) ) {
		return;
	}
	wp_register_script( 'foodify-cart-stepper', false, [], FOODIFY_VERSION, true );
	wp_enqueue_script( 'foodify-cart-stepper' );
	wp_add_inline_script(
		'foodify-cart-stepper',
		'window.FOODIFY_CART = ' . wp_json_encode( [
			'api'      => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'boxLabel' => __( 'Box', 'foodify' ),   // box-builder.php's item line, which marks a pack inside a box
		] ) . ";\n" . foodify_cart_stepper_script()
	);
} );
