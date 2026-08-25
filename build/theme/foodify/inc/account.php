<?php
/**
 * The customer account — presentation for WP-05.
 *
 * WP-05's own words: "The address book and reorder button are the point — not
 * the login itself." So this module shapes what a returning customer sees, and
 * deliberately does NOT implement authentication.
 *
 * WHY THERE IS NO LOGIN FORM IN HERE
 * ----------------------------------
 * Mobile-OTP login arrives in week 11 on a registered SMS gateway, and the
 * gateway waits on DLT registration, which is client-owned. An OTP plugin
 * replaces WooCommerce's login form wholesale. If this file rendered its own
 * form, the plugin would either fight it or silently sit behind it — and a
 * hand-built form that *looks* like OTP but posts a password is exactly the kind
 * of thing that reads as finished and is not.
 *
 * So the template renders WooCommerce's own form. When the OTP plugin lands it
 * takes that over and nothing here changes.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Menu, in the order a food customer actually needs it.
 *
 * WooCommerce ships Dashboard / Orders / Downloads / Addresses / Account details
 * / Logout. Downloads is dead weight on a store that sells no digital products,
 * and its default Dashboard is a paragraph of prose. Orders leads because
 * reordering is the thing people come back for.
 */
add_filter( 'woocommerce_account_menu_items', static function ( array $items ): array {
	unset( $items['downloads'] );   // nothing to download; an empty tab is a dead end

	$ordered = [];
	foreach ( [ 'orders', 'edit-address', 'edit-account', 'customer-logout' ] as $key ) {
		if ( isset( $items[ $key ] ) ) {
			$ordered[ $key ] = $items[ $key ];
		}
	}
	// Anything a plugin added keeps its place rather than disappearing.
	foreach ( $items as $key => $label ) {
		if ( ! isset( $ordered[ $key ] ) && 'dashboard' !== $key ) {
			$ordered[ $key ] = $label;
		}
	}

	$ordered['orders']       = __( 'Orders & reorder', 'foodify' );
	$ordered['edit-address'] = __( 'Saved addresses', 'foodify' );
	$ordered['edit-account'] = __( 'Your details', 'foodify' );

	return $ordered;
} );

/** Land on Orders, not on a dashboard that says nothing. */
add_action( 'woocommerce_account_dashboard', static function (): void {
	if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
		return;
	}
	$orders = wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) );
	printf(
		'<p class="fd-account-lead">%1$s</p><p><a class="wp-element-button" href="%2$s">%3$s</a></p>',
		esc_html__( 'Your past orders are one tap from being your next one.', 'foodify' ),
		esc_url( $orders ),
		esc_html__( 'Orders &amp; reorder', 'foodify' )
	);
}, 5 );

/**
 * Reorder is the PRIMARY action on an order row.
 *
 * WooCommerce lists "View" first and buries "Order again" behind it, which is
 * backwards for a store people buy the same six things from. WP-05: "one-tap
 * reorder as the primary action, not 'view details'."
 */
add_filter( 'woocommerce_my_account_my_orders_actions', static function ( array $actions, $order ): array {
	if ( isset( $actions['order-again'] ) ) {
		$actions['order-again']['name'] = __( 'Reorder', 'foodify' );
		$actions['order-again']['class'] = trim( ( $actions['order-again']['class'] ?? '' ) . ' fd-reorder' );
		// Move it to the front without losing the others.
		$again = $actions['order-again'];
		unset( $actions['order-again'] );
		$actions = [ 'order-again' => $again ] + $actions;
	}
	if ( isset( $actions['view'] ) ) {
		$actions['view']['name']  = __( 'Details', 'foodify' );
		$actions['view']['class'] = trim( ( $actions['view']['class'] ?? '' ) . ' fd-secondary' );
	}
	return $actions;
}, 10, 2 );

/**
 * Say which address is used by default.
 *
 * WooCommerce keeps a billing and a shipping address and calls neither
 * "default", so a customer with two cannot tell which one checkout will use.
 * WP-05 wants a default flag; until the full address book exists, naming the
 * behaviour that is already there beats leaving it unexplained.
 */
add_action( 'woocommerce_before_edit_account_address_form', static function (): void {
	printf(
		'<p class="fd-account-lead">%s</p>',
		esc_html__( 'Your shipping address is the one checkout fills in. Change it here and your next order picks it up automatically.', 'foodify' )
	);
} );

/**
 * WP-05 acceptance: "No source comments, debug output or PHP notices render
 * anywhere on /my-account/."
 *
 * The audit found a developer comment leaking above the login box — the first
 * screen a returning customer sees. This makes a recurrence loud in development
 * instead of waiting for a customer to find it, and stays silent in production
 * rather than replacing one leak with another.
 */
add_action( 'wp_footer', static function (): void {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	$buffer = ob_get_contents();
	if ( is_string( $buffer ) && preg_match( '~//\s*\d+\.\s|<\?php|Notice:|Warning:|Fatal error~', $buffer ) ) {
		echo '<p style="background:#B3261E;color:#fff;padding:12px;font:600 14px system-ui">'
			. 'FOODIFY DEV WARNING: something that looks like source code or a PHP notice is '
			. 'rendering on this account page. WP-05 forbids it. Find it before shipping.</p>';
	}
}, 999 );

/* -------------------------------------------------------------------------
 * WP-05 — post-purchase account claim.
 * ---------------------------------------------------------------------- */

/**
 * Offer an account on the order-received page, not before it.
 *
 * A "create an account" checkbox at checkout is one more decision in front of
 * the money. After the order is placed the customer has already typed
 * everything an account needs, and the offer is a favour rather than a toll.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It links THIS order and no others. Linking every past order with the same
 * email address would be the more generous behaviour and it is a hole: the only
 * thing proving who is standing here is the order key in the URL, which proves
 * they placed *this* order and nothing about the rest. Someone forwarded a
 * confirmation email would inherit a stranger's order history.
 *
 * Older orders come back when they sign in properly — WooCommerce links guest
 * orders on login by email, which is a different trust decision made by
 * WooCommerce and gated behind an actual password or OTP.
 */
add_action( 'woocommerce_thankyou', static function ( $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order || is_user_logged_in() || $order->get_customer_id() ) {
		return;
	}
	$email = $order->get_billing_email();
	if ( ! $email ) {
		return;
	}

	if ( email_exists( $email ) ) {
		printf(
			'<section class="fd-claim"><h2>%1$s</h2><p>%2$s</p><p><a class="wp-element-button" href="%3$s">%4$s</a></p></section>',
			esc_html__( 'You already have an account', 'foodify' ),
			esc_html__( 'Sign in and this order joins your history, ready to reorder in one tap.', 'foodify' ),
			esc_url( wc_get_page_permalink( 'myaccount' ) ),
			esc_html__( 'Sign in', 'foodify' )
		);
		return;
	}

	printf(
		'<section class="fd-claim"><h2>%1$s</h2><p>%2$s</p>'
		. '<form method="post" class="fd-claim__form">%3$s'
		. '<input type="hidden" name="foodify_claim_order" value="%4$s">'
		. '<button type="submit" class="wp-element-button">%5$s</button></form>'
		. '<p class="fd-claim__note">%6$s</p></section>',
		esc_html__( 'Save this for next time?', 'foodify' ),
		esc_html__( 'We can keep this order and this address on your account, so your next one is a single tap.', 'foodify' ),
		wp_nonce_field( 'foodify_claim_' . $order->get_id(), 'foodify_claim_nonce', true, false ),
		esc_attr( (string) $order->get_id() ),
		esc_html__( 'Create my account', 'foodify' ),
		esc_html( sprintf(
			/* translators: %s: customer email address */
			__( 'We will use %s. No password to invent — you sign in with your mobile number.', 'foodify' ),
			$email
		) )
	);
} );

/**
 * Perform the claim.
 *
 * Three gates, all required: a nonce tied to this order id, an order key in the
 * URL matching this order, and an order that is still unclaimed. The order key
 * is the same secret WooCommerce uses to let a guest view their own receipt —
 * without it, knowing an order NUMBER would be enough, and order numbers are
 * sequential.
 */
add_action( 'template_redirect', static function (): void {
	if ( empty( $_POST['foodify_claim_order'] ) || is_user_logged_in() ) {
		return;
	}
	$order_id = absint( wp_unslash( $_POST['foodify_claim_order'] ) );
	$nonce    = isset( $_POST['foodify_claim_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['foodify_claim_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'foodify_claim_' . $order_id ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_customer_id() ) {
		return;
	}
	$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( ! hash_equals( (string) $order->get_order_key(), $key ) ) {
		return;   // not the person who placed this order
	}

	$email = $order->get_billing_email();
	if ( ! $email || email_exists( $email ) ) {
		return;
	}

	$user_id = wc_create_new_customer(
		$email,
		'',                       // WooCommerce generates the username
		wp_generate_password( 24 ),
		[
			'first_name' => $order->get_billing_first_name(),
			'source'     => 'order-claim',
		]
	);
	if ( is_wp_error( $user_id ) ) {
		wc_add_notice( __( 'We could not create the account. Your order is safe — please try from the account page.', 'foodify' ), 'error' );
		return;
	}

	$order->set_customer_id( $user_id );
	$order->save();

	// Seed the address book from what they just typed, so "Saved addresses" is
	// not empty on the first visit.
	if ( function_exists( 'foodify_address_seed_from_wc' ) && function_exists( 'foodify_save_address_book' ) ) {
		$seed = foodify_address_seed_from_wc( [
			'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
			'phone'      => $order->get_billing_phone(),
			'address_1'  => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
			'address_2'  => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
			'city'       => $order->get_shipping_city() ?: $order->get_billing_city(),
			'state'      => $order->get_shipping_state() ?: $order->get_billing_state(),
			'postcode'   => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
			'label'      => __( 'Delivery address', 'foodify' ),
		], time() );
		if ( $seed ) {
			foodify_save_address_book( $user_id, $seed );
		}
	}

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );

	wc_add_notice( __( 'Account created. Your order is saved — reorder it any time.', 'foodify' ), 'success' );
	wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
	exit;
} );
