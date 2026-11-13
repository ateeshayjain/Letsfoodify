<?php
/**
 * WP-06 — the checkout rebuild.
 *
 * The field trim (25 → 9) is inc/checkout-fields.php and shipped with the kit.
 * The saved-address chooser is inc/address-book.php (WP-05). What is left is the
 * flow itself: what the page around the form does, what it promises, and what
 * happens when something goes wrong.
 *
 * WHAT THE AUDITED SITE DID, AND WHY WE ARE NOT DOING IT
 * -----------------------------------------------------
 * The developer comment leaking above the login box was:
 *
 *     // 3. Inject JS step-switching script to show only one box at a time
 *
 * That is a multi-step checkout built by hiding boxes with JavaScript. It breaks
 * in ways that are invisible to the person who built it: browser Back moves
 * through history, not steps; a validation error fires on a hidden step and the
 * customer sees a form with no visible problem; a screen reader walks straight
 * through the hidden boxes. This rebuild is ONE page with a sticky summary. The
 * nine fields fit on a phone screen without steps.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/checkout-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * What the cart page may honestly say about the total.
 *
 * The template used to carry this line unconditionally:
 *
 *     "Shipping and handling are calculated here, before you go to checkout.
 *      Nothing new is added at the payment step."
 *
 * That is a promise the code cannot keep. Shipping in WooCommerce is resolved
 * from a shipping ADDRESS, and a first-time visitor has not given one — so the
 * cart shows whatever the default zone happens to produce, and the number can
 * change at checkout once the real PIN code arrives. The customer was told it
 * would not.
 *
 * This is the same failure shape as an absence check that cannot run: the copy
 * reads as verified and nothing verified it. So the line is now derived from
 * whether shipping is actually known, and each of the three states says
 * something true.
 *
 * @param array{needs_shipping:bool,has_address:bool,shipping_cost:?float} $s
 * @return array{kind:string,message:string}
 */
function foodify_cart_promise( array $s ): array {
	if ( empty( $s['needs_shipping'] ) ) {
		return [ 'kind' => 'none', 'message' => '' ];
	}

	// No address yet, or an address that produced no rate: the honest answer is
	// that shipping is not known, not a promise that it will not change.
	if ( empty( $s['has_address'] ) || null === ( $s['shipping_cost'] ?? null ) ) {
		return [
			'kind'    => 'estimate',
			'message' => 'Delivery is worked out from your PIN code at the next step. Nothing else is added.',
		];
	}

	$cost = (float) $s['shipping_cost'];
	if ( $cost <= 0.0 ) {
		return [
			'kind'    => 'promise',
			'message' => 'Free delivery applied. The total below is what you pay — nothing is added at checkout.',
		];
	}

	return [
		'kind'    => 'promise',
		'message' => 'Delivery is already included in the total below. Nothing further is added at checkout.',
	];
}

/**
 * Pages whose HTML contains one customer's data and must never be cached.
 *
 * Kept as a pure predicate so the list is one thing rather than a condition
 * repeated at three call sites. Deliberately NARROW: widening it to the shop or
 * a product page would disable page caching for the whole storefront and undo
 * the WP-04 performance work.
 */
function foodify_is_private_page( bool $cart, bool $checkout, bool $account ): bool {
	return $cart || $checkout || $account;
}

/**
 * Which header or footer a template part should be on the page being shown.
 *
 * WooCommerce renders the order-received page through the CHECKOUT page, so it
 * inherits templates/page-checkout.html — the stripped checkout chrome, whose
 * only way out is "← Back to cart". After an order the cart is empty and that
 * link leads nowhere useful, and the customer has nothing left to be kept from
 * leaving: the order is placed. So once the order is received, the checkout
 * parts give way to the site's own header and footer. Everything else passes
 * through untouched — the swap is narrow on purpose.
 */
function foodify_checkout_part_slug( string $slug, bool $order_received ): string {
	if ( ! $order_received ) {
		return $slug;
	}
	return [ 'header-checkout' => 'header', 'footer-checkout' => 'footer' ][ $slug ] ?? $slug;
}

/**
 * Which shipping rates a customer may be offered (review BUY-3).
 *
 * The zone carries BOTH free_shipping and flat_rate (bootstrap.sh). WooCommerce
 * lists every available rate and KEEPS a method the customer chose earlier —
 * so someone who saw "Delivery ₹59", then added enough to read "Free delivery
 * unlocked", could still be charged ₹59 because the flat-rate radio was the
 * one left selected. Once a free_shipping rate is available, flat rate is not
 * an option anyone would choose, so it is not offered. Any other method (a
 * pickup, a courier added later) is left alone: this only removes the paid
 * twin of free delivery.
 *
 * @param array<string,string> $methods Rate id => method id, in WooCommerce's order.
 * @return array<int,string> Rate ids to keep, in the same order.
 */
function foodify_shipping_rates_to_keep( array $methods ): array {
	$free = in_array( 'free_shipping', array_map( 'strval', $methods ), true );
	$keep = [];
	foreach ( $methods as $rate_id => $method_id ) {
		if ( $free && 'flat_rate' === (string) $method_id ) {
			continue;
		}
		$keep[] = (string) $rate_id;
	}
	return $keep;
}

/**
 * Why an order may NOT go to this PIN, or '' when it may (client decision,
 * 8 Nov 2026: "pop up that you don't deliver, and don't let them check out").
 *
 * Only a definite no blocks: delivery zones are set AND the PIN's state (or
 * the state the customer chose, for a PIN filed under two) is in none of them
 * — foodify_delivery_answer()'s "unserviceable", the same answer the product
 * page and the checkout note give. A PIN missing from the table is a likely
 * typo, not a refusal: the field note asks for the city and checkout goes on.
 * No zones set: nothing to judge against, nothing blocks.
 *
 * @param array{city?:string,state?:string}|null $lookup foodify_pin_lookup()'s answer.
 */
function foodify_checkout_pin_refusal( string $pin, ?array $lookup, string $chosen_state, ?array $cfg ): string {
	if ( null === $cfg || ! function_exists( 'foodify_delivery_answer' ) ) {
		return '';
	}
	if ( null !== $lookup && '' === trim( (string) ( $lookup['state'] ?? '' ) ) ) {
		$lookup['state'] = strtoupper( trim( $chosen_state ) );
	}
	$a = foodify_delivery_answer( $pin, $lookup, $cfg, new DateTimeImmutable( 'now' ) );
	return 'unserviceable' === $a['status'] ? $a['message'] . ' Use an address we deliver to.' : '';
}

/**
 * The pop-up checkout opens over an undeliverable PIN. One copy: the checkout
 * script receives it in window.FOODIFY_PIN.blockDialog and the prototype
 * renders this same function. "Change PIN code" is a method="dialog" form, so
 * it closes without script; the checkout script then puts focus back in the
 * PIN field. The message line is filled with the answer for that PIN.
 */
function foodify_pin_block_dialog_html(): string {
	return '<dialog class="fd-pin-block" id="fd-pin-block" aria-labelledby="fd-pin-block-title" aria-describedby="fd-pin-block-msg fd-pin-block-help">'
		. '<h2 class="fd-pin-block__title" id="fd-pin-block-title">We don’t deliver there yet</h2>'
		. '<p class="fd-pin-block__msg" id="fd-pin-block-msg" data-fd-pin-block-msg></p>'
		. '<p class="fd-pin-block__help" id="fd-pin-block-help">This order can’t be placed to this address. Change the PIN code, or choose another saved address.</p>'
		. '<form method="dialog" class="fd-pin-block__actions"><button type="submit" class="wp-element-button" value="change">Change PIN code</button></form>'
		. '</dialog>';
}

/**
 * A delivery method's label in the cart and checkout totals (UX recheck).
 *
 * WooCommerce prints a method as "Title: ₹59" in a row it heads "Shipping".
 * With the methods titled for customers (bootstrap.sh: "Delivery", "Free
 * delivery") and the row headed "Delivery" (foodify_shipping_heading()), that
 * read "Delivery | Delivery: ₹59". When a method's title IS the row heading,
 * the price alone is the label. Any other title — "Free delivery", a courier
 * added later, "Express delivery: ₹99" — still names itself.
 *
 * @param string $full  WooCommerce's label ("Delivery: <span>₹59</span>"), already escaped.
 * @param string $title The method's own title.
 */
function foodify_shipping_method_label( string $full, string $title ): string {
	$prefix = trim( $title ) . ': ';
	if ( 0 === strcasecmp( trim( $title ), foodify_shipping_heading() ) && str_starts_with( $full, $prefix ) ) {
		return substr( $full, strlen( $prefix ) );
	}
	return $full;
}

/** The totals row's heading: the site's word for it, not WooCommerce's "Shipping". */
function foodify_shipping_heading(): string {
	return 'Delivery';
}

/**
 * May the cart apply a quantity the customer typed (review BUY-6)?
 *
 * Only a whole number, 0 or more. An EMPTY field is someone halfway through
 * retyping a number; WooCommerce reads an empty quantity as 0 and REMOVES THE
 * LINE, so applying it after a 0.7 s pause deleted food from the cart while
 * the customer was still typing. "2.5", "-1", "abc" are typos, not orders.
 * The cart script (foodify_cart_qty_script) runs the identical test.
 */
function foodify_cart_qty_applies( string $raw ): bool {
	return 1 === preg_match( '/^\d{1,4}$/', trim( $raw ) );
}

/**
 * The cart page's quantity auto-apply. Returned as source so the prototype can
 * embed the SAME code. Expects WooCommerce's classic cart: inputs `.qty` inside
 * `.woocommerce-cart-form`, and its `update_cart` button.
 */
function foodify_cart_qty_script(): string {
	return <<<'JS'
(function($){
  var timer;
  function ok(v){return /^\d{1,4}$/.test(String(v==null?'':v).trim());}
  $(document.body).on('change input', '.woocommerce-cart-form .qty', function(){
    clearTimeout(timer);
    // Empty or not a whole number: the customer is still typing. WooCommerce
    // reads an empty quantity as 0 and removes the line — never submit that.
    var bad=false;$('.woocommerce-cart-form .qty').each(function(){if(!ok(this.value))bad=true;});
    if(bad)return;
    // A pause, not a keystroke: typing "12" would otherwise submit "1" first.
    timer = setTimeout(function(){
      var $btn = $('.woocommerce-cart-form button[name="update_cart"], .woocommerce-cart-form input[name="update_cart"]');
      if ($btn.length){ $btn.prop('disabled', false).trigger('click'); }
    }, 700);
  });
})(jQuery);
JS;
}

/**
 * The wrong-code message (review BUY-9).
 *
 * WooCommerce says `Coupon "badcode" does not exist!` — the code lower-cased
 * (WooCommerce stores codes that way), an exclamation mark, and no next step.
 * This names the code as the customer typed it would be printed (upper case,
 * as the cart labels "Code NALIN10"), and says what to do. Every other message
 * passes through untouched — in particular code 100, the filtered message,
 * which is how referrals.php says "This is your own referral code — share it
 * with a friend instead."
 *
 * @param int $not_exist WooCommerce's WC_Coupon::E_WC_COUPON_NOT_EXIST (105).
 */
function foodify_coupon_error_text( string $message, int $code, string $coupon_code, int $not_exist = 105 ): string {
	if ( $code !== $not_exist ) {
		return $message;
	}
	$typed = strtoupper( trim( $coupon_code ) );
	if ( '' === $typed ) {
		return 'Please type a code first.';
	}
	return sprintf(
		"We couldn't find the code %s. Check the spelling, or ask the person who gave it to you.",
		htmlspecialchars( $typed, ENT_QUOTES )
	);
}

/**
 * What happens next, on the order-received page (review BUY-22, BUY-11).
 *
 * Replaces WooCommerce's "Thank you. Your order has been received." — which the
 * page already says in its heading — with the three things a customer wants to
 * know: when it arrives, what they still owe, and where the receipt went.
 * PLAIN TEXT (escaped): WooCommerce prints it inside a <p>, and depending on
 * the template version through esc_html() or wp_kses_post() — text survives
 * both, markup would not. esc_html() does not double-encode.
 *
 * @param array{estimate?:string,detail?:string,is_cod?:bool,paid?:bool,total?:string,email?:string} $o
 *        `estimate` is foodify_delivery_estimate()['label'] for the order's PIN
 *        at the moment it was placed ('' when the option is unset or the PIN has
 *        no zone — then no date is printed, rather than a guess).
 */
function foodify_order_next_text( array $o ): string {
	$e     = static fn( $s ): string => htmlspecialchars( (string) $s, ENT_QUOTES );
	$parts = [ 'Your order is placed.' ];
	$when  = trim( (string) ( $o['estimate'] ?? '' ) );
	if ( '' !== $when ) {
		$parts[] = $e( $when ) . '.';
	}
	$total = trim( (string) ( $o['total'] ?? '' ) );
	if ( ! empty( $o['is_cod'] ) ) {
		$parts[] = '' !== $total
			? 'Keep ' . $e( $total ) . ' ready in cash for the delivery.'
			: 'Please pay in cash when it arrives.';
	} elseif ( ! empty( $o['paid'] ) ) {
		$parts[] = 'Your payment is complete.';
	}
	$email = trim( (string) ( $o['email'] ?? '' ) );
	$parts[] = '' !== $email
		? 'We have emailed your receipt to ' . $e( $email ) . ' and will write again when it ships.'
		: 'We will let you know when it ships.';
	return implode( ' ', $parts );
}

/**
 * The checkout PIN script: city and state from the PIN, and — once the
 * delivery option is set — the delivery date under the PIN field.
 *
 * Returned as source (the prototype embeds the SAME code). Expects jQuery,
 * window.FOODIFY_PIN = {endpoint, aliases, delivery} (delivery is the
 * normalised `foodify_delivery_zones` or null), and for the date
 * window.foodifyDelivery (delivery-estimate.php's script). DOM: WooCommerce's
 * #billing_postcode / #shipping_postcode, #*_city, #*_state; it inserts one
 * `span.fd-pin-note` (role=status) after each postcode input and points the
 * input's aria-describedby at it.
 *
 * Rules (review BUY-16, BUY-11):
 *  - STATE is always filled from the PIN: it decides the GST split and the
 *    shipping zone, and a stale state is wrong in a way nobody sees.
 *  - CITY is filled only when it is empty, or still holds the city this script
 *    filled for an earlier PIN. A city the customer typed is theirs.
 *  - A PIN the table does not know says "PIN not found — type your city.";
 *    a lookup that could not run says nothing (that is not "not found").
 *  - The date shows only when the option is set and the PIN has a zone.
 */
function foodify_checkout_pin_script(): string {
	return <<<'JS'
(function($){
  var cfg    = window.FOODIFY_PIN || {};
  var cache  = {};
  var auto   = {};   // prefix -> the city this script last filled
  var timer;
  var blocked = {};  // prefix -> {pin, msg} while that PIN is one we do not deliver to

  // Which address the order goes to: the shipping one only when the customer ticked it.
  function active(){ return $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping' : 'billing'; }
  function dialog(){
    var d = document.getElementById('fd-pin-block');
    if (d || !cfg.blockDialog) return d;
    var w = document.createElement('div'); w.innerHTML = cfg.blockDialog; d = w.firstChild;
    document.body.appendChild(d);
    d.addEventListener('close', function(){
      var f = document.getElementById(active() + '_postcode');
      if (f) { f.focus(); if (f.select) f.select(); }
    });
    return d;
  }
  function popup(msg){
    var d = dialog(); if (!d) return;
    d.querySelector('[data-fd-pin-block-msg]').textContent = msg;
    if (!d.open) { if (d.showModal) d.showModal(); else d.setAttribute('open', ''); }
  }
  // WooCommerce re-renders #place_order on every update_checkout, so the lock
  // is re-applied after each one; the submit itself is refused below.
  function lock(){
    var on = !!blocked[active()];
    $('#place_order').toggleClass('is-blocked', on).attr('aria-disabled', on ? 'true' : null);
  }
  function block(pre, pin, msg){
    var was = blocked[pre];
    blocked[pre] = { pin: pin, msg: msg };
    lock();
    if ((!was || was.pin !== pin) && pre === active()) popup(msg);
  }
  function unblock(pre){ delete blocked[pre]; lock(); }

  function norm(s){ return (s||'').toString().trim().toLowerCase().replace(/\s+/g,' '); }
  function digits(v){ return (v||'').toString().replace(/\D/g,''); }

  function lookup(pin){
    if (cache[pin] !== undefined) return Promise.resolve(cache[pin]);
    if (typeof window.foodifyPinLookup === 'function') {
      return Promise.resolve(window.foodifyPinLookup(pin)).then(function(r){ cache[pin] = r || null; return cache[pin]; });
    }
    return fetch(cfg.endpoint + pin, { credentials: 'same-origin' })
      .then(function(r){ if (!r.ok) throw r; return r.json(); })
      .then(function(j){
        var po = j && j[0] && j[0].Status === 'Success' && j[0].PostOffice && j[0].PostOffice[0];
        cache[pin] = po ? { city: po.District || '', state: po.State || '' } : null;
        return cache[pin];
      })
      .catch(function(){ return 'error'; });   // not cached: "could not look" is not "not found"
  }

  function setState($state, apiState){
    if (!$state.length || !apiState) return;
    // The site's own endpoint answers with WooCommerce's state code; a
    // repointed service may answer with a name.
    var code = /^[A-Z]{2}$/.test(apiState) ? apiState : (cfg.aliases || {})[norm(apiState)];
    var $opt = code
      ? $state.find('option[value="' + code + '"]')
      : $state.find('option').filter(function(){ return norm($(this).text()) === norm(apiState); });
    if ($opt.length && $state.val() !== $opt.val()) { $state.val($opt.val()).trigger('change'); }
  }

  function note(pre){
    var id = pre + '_postcode_note', $n = $('#' + id);
    if ($n.length) return $n;
    var $f = $('#' + pre + '_postcode');
    if (!$f.length) return $n;
    $n = $('<span class="fd-pin-note" role="status" aria-live="polite"></span>').attr('id', id);
    var $w = $f.closest('.woocommerce-input-wrapper');
    ($w.length ? $w : $f).after($n);
    var d = $f.attr('aria-describedby') || '';
    if ((' ' + d + ' ').indexOf(' ' + id + ' ') < 0) $f.attr('aria-describedby', $.trim(d + ' ' + id));
    return $n;
  }
  function say(pre, kind, text, detail){
    var $n = note(pre);
    if (!$n.length) return;
    $n.attr('class', 'fd-pin-note' + (kind ? ' is-' + kind : '')).empty();
    if (text) $n.append($('<strong class="fd-pin-note__msg"></strong>').text(text));
    if (detail) $n.append(' · ').append($('<span class="fd-pin-note__detail"></span>').text(detail));
  }

  // The date, from the same rules and the same function as the product page.
  function eta(pre, pin, res){
    if (!cfg.delivery || !window.foodifyDelivery) return false;
    var state = (res && res.state) || $('#' + pre + '_state').val() || '';
    var a = window.foodifyDelivery.answer(pin, res ? { city: res.city || '', state: state } : null, cfg.delivery, Date.now());
    if (a.status === 'ok') { say(pre, 'ok', a.estimate.label, a.estimate.detail); unblock(pre); return true; }
    if (a.status === 'unserviceable') { say(pre, 'no', a.message, ''); block(pre, pin, a.message); return true; }
    unblock(pre);
    return false;
  }

  function apply(pre, pin){
    lookup(pin).then(function(res){
      if (digits($('#' + pre + '_postcode').val()) !== pin) return;   // the PIN changed while we looked
      if (res === 'error') { say(pre, '', '', ''); unblock(pre); return; }
      if (!res) { say(pre, 'error', 'PIN not found — type your city.', ''); unblock(pre); return; }
      var $city = $('#' + pre + '_city'), cur = $.trim($city.val() || '');
      if ($city.length && res.city && (cur === '' || cur === auto[pre])) {
        $city.val(res.city).trigger('change');
        auto[pre] = res.city;
      }
      setState($('#' + pre + '_state'), res.state);
      if (!eta(pre, pin, res)) say(pre, '', '', '');
      $(document.body).trigger('update_checkout');
    });
  }

  $(document.body).on('input change', '#billing_postcode, #shipping_postcode', function(){
    var $f  = $(this),
        pin = digits($f.val()),
        pre = $f.attr('id').split('_')[0];
    clearTimeout(timer);
    if (pin.length !== 6) { say(pre, '', '', ''); unblock(pre); return; }
    timer = setTimeout(function(){ apply(pre, pin); }, 250);   // one call, not one per keystroke
  });
  // A PIN that spans two states: the date follows the state the customer picks.
  $(document.body).on('change', '#billing_state, #shipping_state', function(){
    var pre = this.id.split('_')[0], pin = digits($('#' + pre + '_postcode').val());
    if (pin.length === 6 && cache[pin] !== undefined && cache[pin]) eta(pre, pin, cache[pin]);
  });
  // An undeliverable PIN does not reach payment: Place order re-opens the
  // pop-up instead (checkout_place_order returning false stops WooCommerce's
  // submit; the click guard covers a form submitted another way). The server
  // refuses it again in woocommerce_after_checkout_validation.
  $(document.body).on('updated_checkout', lock);
  $(document.body).on('change', '#ship-to-different-address-checkbox', lock);
  $('form.checkout').on('checkout_place_order', function(){
    var b = blocked[active()]; if (!b) return true;
    popup(b.msg); return false;
  });
  document.addEventListener('click', function(e){
    var b = blocked[active()];
    if (b && e.target.closest && e.target.closest('#place_order')) { e.preventDefault(); e.stopImmediatePropagation(); popup(b.msg); }
  }, true);
  // A returning customer's PIN is already filled: check it as the page opens.
  $(function(){
    ['billing', 'shipping'].forEach(function(pre){
      var pin = digits($('#' + pre + '_postcode').val());
      if (pin.length === 6) apply(pre, pin);
    });
  });
})(jQuery);
JS;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

// The order-received page gets the site's header and footer, not the checkout's
// (see foodify_checkout_part_slug). render_block_data runs before a block is
// rendered, so the template part is simply asked for by its other name; the
// template file itself stays the checkout's.
add_filter( 'render_block_data', static function ( array $block ): array {
	if ( 'core/template-part' !== ( $block['blockName'] ?? '' ) || empty( $block['attrs']['slug'] ) ) {
		return $block;
	}
	$received = function_exists( 'is_order_received_page' ) && is_order_received_page();
	$block['attrs']['slug'] = foodify_checkout_part_slug( (string) $block['attrs']['slug'], $received );
	return $block;
} );

/**
 * Cart, checkout and account must not be cached. This is a privacy control.
 *
 * A page cache that serves /checkout/ or /my-account/ to an anonymous visitor
 * serves ONE CUSTOMER'S name, address and phone number TO ANOTHER. It is a data
 * breach, not a performance bug, and it is completely silent: the pages render,
 * the orders go through, and nobody finds out until a customer says they saw
 * somebody else's address.
 *
 * WooCommerce sets DONOTCACHEPAGE itself. This does it again anyway, because:
 *
 *   - I could not verify WooCommerce's current behaviour from this environment
 *     (wordpress.org is unreachable), and asserting it costs one function call;
 *   - the constant only speaks to plugin-level caches. A host or CDN page cache
 *     obeys HTTP headers, so the headers are sent explicitly;
 *   - REVIEW-NOTES item 1 records that the ONE cache measurement anyone has
 *     taken of this site was almost certainly polluted by the tester's own cart
 *     cookie. Nobody has actually confirmed the host's behaviour.
 *
 * Belt and braces on the origin does not prove the CDN obeys it — that is what
 * the assertion in smoke-test.sh is for.
 */
add_action( 'template_redirect', static function (): void {
	$private = foodify_is_private_page(
		function_exists( 'is_cart' ) && is_cart(),
		function_exists( 'is_checkout' ) && is_checkout(),          // covers order-received
		function_exists( 'is_account_page' ) && is_account_page()
	);
	if ( ! $private ) {
		return;   // the storefront stays cacheable; WP-04 depends on it
	}

	foreach ( [ 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB' ] as $flag ) {
		if ( ! defined( $flag ) ) {
			define( $flag, true );
		}
	}

	if ( headers_sent() ) {
		return;
	}
	nocache_headers();
	// no-store is the one that matters: no-cache still permits a shared cache to
	// store the response and revalidate, and a CDN that gets revalidation wrong
	// is exactly the failure being defended against.
	header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, private', true );
	header( 'Pragma: no-cache', true );
	// A marker the blocking gate can assert positively. "No HIT header" and
	// "could not read the headers" are the same result otherwise, and this
	// project has already shipped two gates that could not tell them apart.
	header( 'X-Foodify-Private: 1', true );
}, 0 );

/**
 * The coupon field: reworded, not relocated.
 *
 * WooCommerce renders "Have a coupon? Click here to enter your code" above the
 * form. The received wisdom is to move it into the order summary so it stops
 * sending people off to hunt for codes.
 *
 * DO NOT DO THAT HERE. The order summary is rendered INSIDE `form.checkout`, and
 * WooCommerce's coupon form is a real `<form>`. Nested forms are invalid HTML and
 * every browser drops the inner one — so the coupon inputs become part of the
 * checkout form, and pressing Enter in the coupon field SUBMITS THE ORDER. It
 * would look correct in a screenshot and place wrong orders in production.
 *
 * So it stays where WooCommerce puts it, collapsed as WooCommerce already
 * collapses it, and only the copy changes. WP-09's partner codes are the reason
 * this field exists on this store at all, and naming that is more useful than a
 * generic prompt that reads as "you are paying too much".
 */
add_filter( 'woocommerce_checkout_coupon_message', static function (): string {
	return esc_html__( 'Have a code from a partner or a creator?', 'foodify' )
		. ' <a href="#" class="showcoupon">' . esc_html__( 'Enter it here', 'foodify' ) . '</a>';
} );

/** Same demotion on the cart page. */
add_filter( 'woocommerce_cart_totals_coupon_label', static function ( $label, $coupon ) {
	return is_object( $coupon ) && method_exists( $coupon, 'get_code' )
		? sprintf( /* translators: %s: coupon code */ __( 'Code %s', 'foodify' ), strtoupper( $coupon->get_code() ) )
		: $label;
}, 10, 2 );

/**
 * The cart's total promise, derived rather than asserted. See foodify_cart_promise().
 */
add_action( 'woocommerce_after_cart_totals', static function (): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$cart = WC()->cart;

	$customer    = WC()->customer;
	$has_address = $customer && '' !== trim( (string) $customer->get_shipping_postcode() );

	// null means "no rate resolved", which is NOT the same as free.
	$cost = null;
	if ( $has_address && $cart->needs_shipping() ) {
		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : [];
		$chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', [] ) : [];
		if ( $packages && ! empty( $chosen[0] ) ) {
			$cost = (float) $cart->get_shipping_total();
		}
	}

	$promise = foodify_cart_promise( [
		'needs_shipping' => (bool) $cart->needs_shipping(),
		'has_address'    => (bool) $has_address,
		'shipping_cost'  => $cost,
	] );

	if ( '' === $promise['message'] ) {
		return;
	}
	printf(
		'<p class="fd-cart-promise is-%1$s">%2$s</p>',
		esc_attr( $promise['kind'] ),
		esc_html( $promise['message'] )
	);
} );

/**
 * Validation errors must move FOCUS, not just scroll.
 *
 * WooCommerce scrolls the notice box into view and stops there. On a phone that
 * leaves a red box at the top and the cursor nowhere, so the customer scrolls
 * back down hunting for which field is wrong; with a screen reader the error is
 * announced and then abandoned. Both are the same defect — the page said
 * something went wrong and did not say where.
 *
 * The notice region is made focusable and focused, then the first invalid field
 * is focused, so the very next keystroke lands in the field that needs fixing.
 */
add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	wp_register_script( 'foodify-checkout', false, [ 'jquery' ], FOODIFY_VERSION, true );
	wp_enqueue_script( 'foodify-checkout' );
	wp_add_inline_script( 'foodify-checkout', <<<'JS'
(function($){
  function focusFirstProblem(){
    var $notice = $('.woocommerce-error, .woocommerce-NoticeGroup .woocommerce-error').first();
    if ($notice.length){
      $notice.attr({ role: 'alert', tabindex: '-1' });
      try { $notice[0].focus({ preventScroll: false }); } catch (e) { $notice[0].focus(); }
    }
    // Then the field itself, so the next keystroke lands where it is needed.
    var $bad = $('.woocommerce-invalid').first().find('input, select, textarea').first();
    if ($bad.length){
      setTimeout(function(){
        try { $bad[0].focus({ preventScroll: true }); } catch (e) { $bad[0].focus(); }
      }, 60);
    }
  }
  $(document.body).on('checkout_error', focusFirstProblem);
  // A server-side failure re-renders the page rather than firing the event.
  if ($('.woocommerce-error').length) { $(focusFirstProblem); }
})(jQuery);
JS
	);
} );

/**
 * Cart quantity applies itself.
 *
 * WooCommerce requires a separate "Update cart" click after changing a quantity,
 * and the button only becomes enabled once something changes. People change the
 * number, see the line total stay put, and conclude it did not work — or worse,
 * proceed to checkout with the old quantity. The button stays in the markup for
 * anyone without JavaScript; this only removes the need to find it.
 */
add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return;
	}
	wp_register_script( 'foodify-cart', false, [ 'jquery' ], FOODIFY_VERSION, true );
	wp_enqueue_script( 'foodify-cart' );
	wp_add_inline_script( 'foodify-cart', foodify_cart_qty_script() );   // empty / non-whole quantities never submit (BUY-6)
	// WooCommerce stopped loading wc-cart-fragments by itself (it now comes
	// only with the classic mini-cart widget), and the free-delivery bar above
	// the cart is refreshed BY a fragment (shortcodes.php). Without the script
	// the bar kept saying "₹174 away from free delivery" over a ₹905 cart —
	// seen on the first real WooCommerce render, 13 Nov 2026. WooCommerce's
	// cart script already asks for the refresh; this is what answers.
	if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
		wp_enqueue_script( 'wc-cart-fragments' );
	}
} );

/**
 * Guest checkout stays the default path.
 *
 * The scope says it in as many words: "Guest checkout preserved and primary."
 * WooCommerce's own "Returning customer? Click here to login" sits above the
 * form and reads like a gate. The link stays — people who have an account want
 * it, and WP-05's OTP plugin will take it over — but the wording stops implying
 * that signing in is the way through.
 */
add_filter( 'woocommerce_checkout_login_message', static function (): string {
	return esc_html__( 'Already ordered from us? Sign in to fill this in automatically — or just carry on below as a guest.', 'foodify' );
} );

/**
 * Nothing in the order summary should be a surprise.
 *
 * Printed inside the review table, where the customer is looking at the number
 * they are about to pay, rather than in the footer where trust copy goes to die.
 */
add_action( 'woocommerce_review_order_after_order_total', static function (): void {
	printf(
		'<tr class="fd-order-note"><td colspan="2">%s</td></tr>',
		esc_html__( 'GST is included. This is the final amount — no handling, convenience or platform fee is added.', 'foodify' )
	);
} );

/**
 * Flat rate is not offered once free delivery is (review BUY-3). See
 * foodify_shipping_rates_to_keep(). Late priority, after any plugin adds rates.
 * When the rate the customer had chosen disappears, WooCommerce falls back to
 * the first available one — the free one.
 */
add_filter( 'woocommerce_package_rates', static function ( $rates ) {
	if ( ! is_array( $rates ) || count( $rates ) < 2 ) {
		return $rates;
	}
	$methods = [];
	foreach ( $rates as $id => $rate ) {
		$methods[ (string) $id ] = is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : '';
	}
	$keep = array_flip( foodify_shipping_rates_to_keep( $methods ) );
	return array_filter( $rates, static fn( $id ): bool => isset( $keep[ (string) $id ] ), ARRAY_FILTER_USE_KEY );
}, 100 );

/** "Delivery | ₹59", not "Shipping | Delivery: ₹59". See foodify_shipping_method_label(). */
add_filter( 'woocommerce_shipping_package_name', static function ( $name, $i = 0 ) {
	return 0 === (int) $i ? foodify_shipping_heading() : $name;
}, 10, 2 );
add_filter( 'woocommerce_cart_shipping_method_full_label', static function ( $label, $method = null ) {
	$title = is_object( $method ) && method_exists( $method, 'get_label' ) ? (string) $method->get_label() : '';
	return foodify_shipping_method_label( (string) $label, $title );
}, 10, 2 );

/**
 * The order itself is refused for a PIN we do not deliver to — the pop-up is
 * the courtesy, this is the rule (it holds with scripts off or a stale tab).
 * foodify_checkout_pin_refusal().
 */
add_action( 'woocommerce_after_checkout_validation', static function ( array $data, WP_Error $errors ): void {
	if ( ! function_exists( 'foodify_delivery_config' ) || ! function_exists( 'foodify_pin_lookup' ) ) {
		return;
	}
	$pre = ! empty( $data['ship_to_different_address'] ) ? 'shipping' : 'billing';
	$pin = (string) ( $data[ $pre . '_postcode' ] ?? '' );
	$why = foodify_checkout_pin_refusal(
		$pin,
		foodify_pin_lookup( $pin, foodify_pin_data( FOODIFY_DIR . '/assets/data/pincodes.json' ) ),
		(string) ( $data[ $pre . '_state' ] ?? '' ),
		foodify_delivery_config()
	);
	if ( '' !== $why ) {
		$errors->add( $pre . '_postcode', $why );
	}
}, 20, 2 );

/** A wrong code says which code and what to do (review BUY-9). */
add_filter( 'woocommerce_coupon_error', static function ( $err, $err_code, $coupon = null ) {
	$not_exist = defined( 'WC_Coupon::E_WC_COUPON_NOT_EXIST' ) ? (int) WC_Coupon::E_WC_COUPON_NOT_EXIST : 105;
	$code      = is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ? (string) $coupon->get_code() : '';
	return foodify_coupon_error_text( (string) $err, (int) $err_code, $code, $not_exist );
}, 10, 3 );

/**
 * The order-received page says what happens next, not "thank you" twice
 * (review BUY-22), including the delivery date for the order's PIN as it was
 * when the order was placed (BUY-11) — computed from the order's own timestamp,
 * so a reload tomorrow does not move the date.
 */
add_filter( 'woocommerce_thankyou_order_received_text', static function ( $text, $order = null ) {
	if ( ! $order instanceof WC_Order ) {
		return $text;
	}
	$estimate = '';
	if ( function_exists( 'foodify_delivery_config' ) && function_exists( 'foodify_delivery_estimate' ) ) {
		$cfg = foodify_delivery_config();
		$pin = (string) ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() );
		$st  = (string) ( $order->get_shipping_state() ?: $order->get_billing_state() );
		$at  = $order->get_date_created();
		if ( null !== $cfg && '' !== $pin && $at ) {
			$est      = foodify_delivery_estimate( preg_replace( '/\D/', '', $pin ), $st, $cfg, new DateTimeImmutable( '@' . $at->getTimestamp() ) );
			$estimate = $est ? (string) $est['label'] : '';
		}
	}
	$cod_methods = function_exists( 'foodify_payment_config' ) ? (array) ( foodify_payment_config()['cod_methods'] ?? [ 'cod' ] ) : [ 'cod' ];
	$total       = (float) $order->get_total();
	return foodify_order_next_text( [
		'estimate' => $estimate,
		'is_cod'   => in_array( $order->get_payment_method(), $cod_methods, true ),
		'paid'     => $order->is_paid(),
		'total'    => function_exists( 'foodify_order_rupees' ) ? foodify_order_rupees( $total ) : wp_strip_all_tags( wc_price( $total ) ),
		'email'    => (string) $order->get_billing_email(),
	] );
}, 10, 2 );

/**
 * The order-received page lists the payment method in WooCommerce's overview
 * AND again in the order-details totals. One is enough (BUY-22): the totals
 * row goes on that page only — emails and My Account keep theirs.
 */
add_filter( 'woocommerce_get_order_item_totals', static function ( $rows ) {
	if ( is_array( $rows ) && function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		unset( $rows['payment_method'] );
	}
	return $rows;
}, 20 );

/*
 * One name per thing, after the order too (first real WooCommerce order,
 * 13 Nov 2026). The cart and checkout say "Delivery"; the order's own totals
 * said "Shipping:". And with one address typed (ship to the address given —
 * woocommerce_ship_to_destination = billing_only) WooCommerce heads it
 * "Billing address", which is not what the customer gave it for.
 */
add_filter( 'woocommerce_get_order_item_totals', static function ( $rows ) {
	if ( is_array( $rows ) && isset( $rows['shipping']['label'] ) ) {
		$rows['shipping']['label'] = __( 'Delivery:', 'foodify' );
	}
	return $rows;
} );
add_filter( 'gettext_woocommerce', static function ( $translation, $text ) {
	if ( 'Billing address' === $text && function_exists( 'wc_ship_to_billing_address_only' ) && wc_ship_to_billing_address_only() && ! is_admin() ) {
		return __( 'Delivery address', 'foodify' );
	}
	return $translation;
}, 10, 2 );
