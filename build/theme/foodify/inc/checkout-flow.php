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
			'message' => 'Shipping is calculated from your PIN code at the next step. Nothing else is added.',
		];
	}

	$cost = (float) $s['shipping_cost'];
	if ( $cost <= 0.0 ) {
		return [
			'kind'    => 'promise',
			'message' => 'Free shipping applied. The total below is what you pay — nothing is added at checkout.',
		];
	}

	return [
		'kind'    => 'promise',
		'message' => 'Shipping is already included in the total below. Nothing further is added at checkout.',
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

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

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
	wp_add_inline_script( 'foodify-cart', <<<'JS'
(function($){
  var timer;
  $(document.body).on('change input', '.woocommerce-cart-form .qty', function(){
    clearTimeout(timer);
    // A pause, not a keystroke: typing "12" would otherwise submit "1" first.
    timer = setTimeout(function(){
      var $btn = $('.woocommerce-cart-form button[name="update_cart"], .woocommerce-cart-form input[name="update_cart"]');
      if ($btn.length){ $btn.prop('disabled', false).trigger('click'); }
    }, 700);
  });
})(jQuery);
JS
	);
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
