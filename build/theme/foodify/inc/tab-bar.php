<?php
/**
 * The phone's bottom tab bar (client, 9 Nov 2026 — Blinkit's bottom bar):
 * Home · Categories · Order again · Account.
 *
 * WHERE IT IS NOT
 *   A product page's bottom belongs to its sticky Add to cart bar, and the
 *   cart and checkout's to their own Proceed / Place order — two bars stacked
 *   there would put the one button that matters under a menu. So the tab bar
 *   is not printed on those pages at all. Everywhere else, the View cart bar
 *   floats directly ABOVE it (style.css, "Tab bar"). From 782px it is hidden:
 *   the header carries the same doors.
 *
 * CACHED PAGES
 *   Which tab is current is decided by the URL alone (the page being shown),
 *   never by who is signed in, so the bar is safe in an edge-cached page.
 *   "Order again" goes to the account's orders, where every order has an
 *   Order again button (order-progress.php); signed out, WooCommerce asks
 *   for the mobile number first.
 *
 * @package Foodify
 */
declare( strict_types = 1 );
defined( 'ABSPATH' ) || exit;

/** The four tabs, in order. Icons are 24-unit path data, drawn as strokes. */
function foodify_tab_bar_tabs(): array {
	return [
		'home'  => [ 'label' => 'Home', 'icon' => [ 'M4 11 12 4l8 7', 'M6 9.5V20h12V9.5', 'M10 20v-5h4v5' ] ],
		'cats'  => [ 'label' => 'Categories', 'icon' => [ 'M4 4h7v7H4z', 'M13 4h7v7h-7z', 'M4 13h7v7H4z', 'M13 13h7v7h-7z' ] ],
		'again' => [ 'label' => 'Order again', 'icon' => [ 'M4 12a8 8 0 0 1 13.7-5.6L20 8.7', 'M20 4v4.7h-4.7', 'M20 12a8 8 0 0 1-13.7 5.6L4 15.3', 'M4 20v-4.7h4.7' ] ],
		'acct'  => [ 'label' => 'Account', 'icon' => [ 'M12 4a4 4 0 1 0 0 8a4 4 0 1 0 0-8', 'M4.5 20a7.5 7.5 0 0 1 15 0' ] ],
	];
}

/**
 * Which tab the page being shown is, or '' for none.
 *
 * @param array{front?:bool,shop?:bool,category?:bool,search?:bool,account?:bool,orders?:bool} $page
 */
function foodify_tab_bar_current( array $page ): string {
	if ( ! empty( $page['orders'] ) ) {
		return 'again';
	}
	if ( ! empty( $page['account'] ) ) {
		return 'acct';
	}
	if ( ! empty( $page['shop'] ) || ! empty( $page['category'] ) ) {
		return 'cats';
	}
	return ! empty( $page['front'] ) ? 'home' : '';
}

/**
 * The bar. `$urls` maps each tab key to its link.
 *
 * @param array<string,string> $urls
 */
function foodify_tab_bar_html( array $urls, string $current = '' ): string {
	$e  = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	$li = '';
	foreach ( foodify_tab_bar_tabs() as $key => $t ) {
		if ( empty( $urls[ $key ] ) ) {
			continue;
		}
		$paths = '';
		foreach ( $t['icon'] as $d ) {
			$paths .= '<path d="' . $e( $d ) . '"/>';
		}
		$li .= '<li><a class="fd-tabbar__tab' . ( $key === $current ? ' is-current' : '' ) . '" href="' . $e( $urls[ $key ] ) . '"' . ( $key === $current ? ' aria-current="page"' : '' ) . '>'
			. '<svg class="fd-tabbar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>'
			. '<span class="fd-tabbar__label">' . $e( $t['label'] ) . '</span></a></li>';
	}
	return '' === $li ? '' : '<nav class="fd-tabbar" aria-label="Shortcuts"><ul class="fd-tabbar__tabs">' . $li . '</ul></nav>';
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

add_action( 'wp_footer', static function (): void {
	if ( is_admin() || ! function_exists( 'wc_get_page_permalink' )
		|| ( function_exists( 'is_product' ) && is_product() )
		|| ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
		return;
	}
	$account = wc_get_page_permalink( 'myaccount' );
	$urls    = [
		'home'  => home_url( '/' ),
		'cats'  => wc_get_page_permalink( 'shop' ),
		'again' => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : $account,
		'acct'  => $account,
	];
	$orders  = function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'orders' ) || is_wc_endpoint_url( 'view-order' ) );
	$current = foodify_tab_bar_current( [
		'front'    => is_front_page(),
		'shop'     => function_exists( 'is_shop' ) && is_shop(),
		'category' => function_exists( 'is_product_taxonomy' ) && is_product_taxonomy(),
		'account'  => function_exists( 'is_account_page' ) && is_account_page(),
		'orders'   => $orders,
	] );
	echo foodify_tab_bar_html( $urls, $current ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
}, 6 );
