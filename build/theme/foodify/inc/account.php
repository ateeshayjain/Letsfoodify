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
