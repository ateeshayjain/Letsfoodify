<?php
/**
 * The account's first screen as a profile (client, 9 Nov 2026 — Blinkit's
 * Profile): your name and number, three tiles, then "Your information" as a
 * list of round-icon rows, then Help, then Log out.
 *
 * ONLY THE DOORS THIS SHOP HAS
 *   The list is built from wc_get_account_menu_items() at run time — Orders,
 *   Repeat orders, Saved addresses, Your details, Refer a friend, whatever a
 *   plugin adds — so a section the shop does not have is never shown, and one
 *   it adds later appears without a theme change. Blinkit's wallet, wishlist,
 *   recipes, prescriptions, gift cards and appearance switch have no
 *   counterpart here and are not invented.
 *
 * ON A PHONE the account's side menu is hidden on every account page: this
 * screen IS the menu, and every other account page starts with "‹ Profile".
 *
 * @package Foodify
 */
declare( strict_types = 1 );
defined( 'ABSPATH' ) || exit;

/** 24-unit stroke icons, by account menu key. Unknown keys get the generic page. */
function foodify_profile_icon( string $key ): string {
	$p = [
		'orders'          => 'M6 3h12l1 18H5zM9 7a3 3 0 0 0 6 0',
		'repeat-orders'   => 'M4 12a8 8 0 0 1 13.7-5.6L20 8.7M20 4v4.7h-4.7M20 12a8 8 0 0 1-13.7 5.6L4 15.3M4 20v-4.7h4.7',
		'edit-address'    => 'M12 21s-7-6.1-7-11a7 7 0 0 1 14 0c0 4.9-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5a2.5 2.5 0 0 0 0 5',
		'address-book'    => 'M12 21s-7-6.1-7-11a7 7 0 0 1 14 0c0 4.9-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5a2.5 2.5 0 0 0 0 5',
		'edit-account'    => 'M12 4a4 4 0 1 0 0 8a4 4 0 1 0 0-8M4.5 20a7.5 7.5 0 0 1 15 0',
		'refer-a-friend'  => 'M4 10h16v10H4zM2 7h20v3H2zM12 7v13M12 7c-2-4-6-4-6-1s6 1 6 1zM12 7c2-4 6-4 6-1s-6 1-6 1z',
		'customer-logout' => 'M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10',
		'help'            => 'M12 21a9 9 0 1 0 0-18a9 9 0 0 0 0 18zM9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17v.01',
		'track'           => 'M3 6h11v10H3zM14 9h4l3 3v4h-7M7.5 18.5a1.5 1.5 0 1 0 0-.01M17.5 18.5a1.5 1.5 0 1 0 0-.01',
	];
	$d = $p[ $key ] ?? 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h7';
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . $d . '"/></svg>';
}

/**
 * The profile screen.
 *
 * @param array{name:string,phone?:string,email?:string,
 *              tiles:array<int,array{key:string,label:string,url:string}>,
 *              groups:array<int,array{title:string,items:array<int,array{key:string,label:string,url:string}>}>,
 *              logout?:string} $p
 */
function foodify_profile_html( array $p ): string {
	$e   = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	$out = '<section class="fd-profile" aria-labelledby="fd-profile-name">'
		. '<h2 class="fd-profile__name" id="fd-profile-name">' . $e( '' !== trim( (string) $p['name'] ) ? (string) $p['name'] : 'Your account' ) . '</h2>';
	$facts = '';
	if ( '' !== trim( (string) ( $p['phone'] ?? '' ) ) ) {
		$facts .= '<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg><span class="fd-sr">Mobile </span>' . $e( (string) $p['phone'] ) . '</li>';
	}
	if ( '' !== trim( (string) ( $p['email'] ?? '' ) ) ) {
		$facts .= '<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18v12H3zM3 7l9 6 9-6"/></svg><span class="fd-sr">Email </span>' . $e( (string) $p['email'] ) . '</li>';
	}
	$out .= '' !== $facts ? '<ul class="fd-profile__facts">' . $facts . '</ul>' : '';
	$tiles = '';
	foreach ( (array) ( $p['tiles'] ?? [] ) as $t ) {
		$tiles .= '<li><a class="fd-profile__tile" href="' . $e( (string) $t['url'] ) . '">' . foodify_profile_icon( (string) $t['key'] ) . '<span>' . $e( (string) $t['label'] ) . '</span></a></li>';
	}
	$out .= '' !== $tiles ? '<ul class="fd-profile__tiles">' . $tiles . '</ul>' : '';
	foreach ( (array) ( $p['groups'] ?? [] ) as $i => $g ) {
		$li = '';
		foreach ( (array) $g['items'] as $it ) {
			$li .= '<li><a class="fd-profile__row" href="' . $e( (string) $it['url'] ) . '"><span class="fd-profile__icon">' . foodify_profile_icon( (string) $it['key'] ) . '</span><span class="fd-profile__label">' . $e( (string) $it['label'] ) . '</span><svg class="fd-profile__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg></a></li>';
		}
		if ( '' === $li ) {
			continue;
		}
		$id   = 'fd-profile-g' . (int) $i;
		$out .= '<h3 class="fd-profile__group" id="' . $id . '">' . $e( (string) $g['title'] ) . '</h3><ul class="fd-profile__list" aria-labelledby="' . $id . '">' . $li . '</ul>';
	}
	if ( '' !== (string) ( $p['logout'] ?? '' ) ) {
		$out .= '<ul class="fd-profile__list fd-profile__list--out"><li><a class="fd-profile__row" href="' . $e( (string) $p['logout'] ) . '"><span class="fd-profile__icon">' . foodify_profile_icon( 'customer-logout' ) . '</span><span class="fd-profile__label">Log out</span></a></li></ul>';
	}
	return $out . '</section>';
}

/**
 * The account menu, split the profile's way: the information rows (every
 * item but the dashboard and Log out, in the menu's order) and the log-out
 * URL apart.
 *
 * @param array<string,string> $menu key => label (wc_get_account_menu_items()).
 * @param callable(string):string $url key => URL.
 * @return array{items:array<int,array{key:string,label:string,url:string}>,logout:string}
 */
function foodify_profile_menu( array $menu, callable $url ): array {
	$items  = [];
	$logout = '';
	foreach ( $menu as $key => $label ) {
		$key = (string) $key;
		if ( 'dashboard' === $key ) {
			continue;
		}
		if ( 'customer-logout' === $key ) {
			$logout = $url( $key );
			continue;
		}
		$items[] = [ 'key' => $key, 'label' => foodify_profile_text( (string) $label ), 'url' => $url( $key ) ];
	}
	return [ 'items' => $items, 'logout' => $logout ];
}

/** Labels can carry markup ("Orders &amp; reorder"): plain text, decoded once. */
function foodify_profile_text( string $s ): string {
	return trim( html_entity_decode( strip_tags( $s ), ENT_QUOTES, 'UTF-8' ) );
}

/**
 * "‹ Profile" at the top of every other account page — a phone has no side
 * menu. On an order's own page it is "‹ Orders": back is the list it came from.
 */
function foodify_profile_back_html( string $url, string $label = 'Profile' ): string {
	// "‹ Orders" shows at every width (an order page has no other way back); "‹ Profile" on a phone only.
	return '<p class="fd-profile-back' . ( 'Profile' === $label ? '' : ' is-always' ) . '"><a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '"><span aria-hidden="true">‹</span> ' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '</a></p>';
}

/**
 * The account page's heading (client, 9 Nov 2026): "Profile" on the first
 * screen once signed in, "Order summary" on an order's page. Anything else —
 * signed out, another section — keeps the heading it had ('' = no change).
 */
function foodify_account_heading( bool $signed_in, string $endpoint ): string {
	if ( ! $signed_in ) {
		return '';
	}
	if ( '' === $endpoint ) {
		return 'Profile';
	}
	return 'view-order' === $endpoint ? 'Order summary' : '';
}

/** Put a heading's words in place of a rendered block heading's text. */
function foodify_replace_heading_text( string $html, string $text ): string {
	if ( '' === $text ) {
		return $html;
	}
	$out = preg_replace( '#(<h[1-6][^>]*>).*?(</h[1-6]>)#s', '${1}' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '${2}', $html, 1 );
	return is_string( $out ) ? $out : $html;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

/**
 * The header's account button, made into the one this theme styles.
 *
 * FOUND ON THE FIRST REAL WOOCOMMERCE RENDER (13 Nov 2026). WooCommerce 11
 * prints the Customer Account block as
 *   <div class="wp-block-woocommerce-customer-account"><a class="wc-block-customer-account__link">
 * — not the .wc-block-customer-account / __account-link pair style.css (and the
 * prototype) were written against, so the round 44px icon never applied and
 * the header read "Login" as a text link. And for a signed-in customer it
 * swaps the icon for their Gravatar: a request to gravatar.com from every
 * page, carrying a hash of their email address. This adds the two classes,
 * puts the person icon back, and says "Sign in" / "My account" like the rest
 * of the site. Anything it does not recognise is returned untouched.
 */
function foodify_account_button_html( string $html ): string {
	if ( false === strpos( $html, 'wp-block-woocommerce-customer-account' ) ) {
		return $html;
	}
	$icon = '<svg class="wc-block-customer-account__account-icon" xmlns="http://www.w3.org/2000/svg" viewBox="-5 -5 25 25" aria-hidden="true" focusable="false">'
		. '<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M8.00009 8.34785C10.3096 8.34785 12.1819 6.47909 12.1819 4.17393C12.1819 1.86876 10.3096 0 8.00009 0C5.69055 0 3.81824 1.86876 3.81824 4.17393C3.81824 6.47909 5.69055 8.34785 8.00009 8.34785ZM0.333496 15.6522C0.333496 15.8444 0.489412 16 0.681933 16H15.3184C15.5109 16 15.6668 15.8444 15.6668 15.6522V14.9565C15.6668 12.1428 13.7821 9.73911 10.0912 9.73911H5.90931C2.21828 9.73911 0.333645 12.1428 0.333645 14.9565L0.333496 15.6522Z"/></svg>';
	if ( false === strpos( $html, 'wc-block-customer-account ' ) && false === strpos( $html, 'wc-block-customer-account"' ) ) {
		$html = (string) preg_replace( '/class="\s*wp-block-woocommerce-customer-account\b/', 'class="wc-block-customer-account wp-block-woocommerce-customer-account', $html, 1 );
	}
	if ( false === strpos( $html, 'wc-block-customer-account__account-link' ) ) {
		$html = (string) preg_replace( '/class="\s*wc-block-customer-account__link\b/', 'class="wc-block-customer-account__link wc-block-customer-account__account-link', $html, 1 );
	}
	$html = (string) preg_replace( '/<img\b[^>]*\bwc-block-customer-account__avatar\b[^>]*>/', $icon, $html );
	return (string) preg_replace(
		[ '/>\s*(?:Log ?in)\s*</i', '/>\s*My Account\s*</' ],
		[ '>Sign in<', '>My account<' ],
		$html
	);
}

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

add_action( 'woocommerce_account_dashboard', static function (): void {
	if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
		return;
	}
	$user  = wp_get_current_user();
	$name  = trim( (string) get_user_meta( $user->ID, 'first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'last_name', true ) );
	$menu  = foodify_profile_menu( wc_get_account_menu_items(), static fn( string $k ): string => (string) wc_get_account_endpoint_url( $k ) );
	$addr  = isset( wc_get_account_menu_items()['address-book'] ) ? 'address-book' : 'edit-address';
	echo foodify_profile_html( [ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the builder.
		'name'   => '' !== $name ? $name : (string) $user->display_name,
		'phone'  => (string) get_user_meta( $user->ID, 'billing_phone', true ),
		'email'  => (string) $user->user_email,
		'tiles'  => [
			[ 'key' => 'orders', 'label' => __( 'Orders', 'foodify' ), 'url' => (string) wc_get_account_endpoint_url( 'orders' ) ],
			[ 'key' => $addr, 'label' => __( 'Addresses', 'foodify' ), 'url' => (string) wc_get_account_endpoint_url( $addr ) ],
			[ 'key' => 'help', 'label' => __( 'Help', 'foodify' ), 'url' => home_url( '/contact-us/' ) ],
		],
		'groups' => [
			[ 'title' => __( 'Your information', 'foodify' ), 'items' => $menu['items'] ],
			[ 'title' => __( 'Help', 'foodify' ), 'items' => [
				[ 'key' => 'track', 'label' => __( 'Track an order', 'foodify' ), 'url' => home_url( '/track-order/' ) ],
				[ 'key' => 'help', 'label' => __( 'Contact us', 'foodify' ), 'url' => home_url( '/contact-us/' ) ],
			] ],
		],
		'logout' => $menu['logout'],
	] );
}, 5 );

add_action( 'woocommerce_account_content', static function (): void {
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
		echo is_wc_endpoint_url( 'view-order' ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the builder.
			? foodify_profile_back_html( (string) wc_get_account_endpoint_url( 'orders' ), __( 'Orders', 'foodify' ) )
			: foodify_profile_back_html( (string) wc_get_page_permalink( 'myaccount' ) );
	}
}, 5 );

/* The page title block on the account page. Product cards' titles (which carry
   __woocommerceNamespace) are never touched. */
add_filter( 'render_block_core/post-title', static function ( string $html, array $block ): string {
	if ( '' === $html || ! empty( $block['attrs']['__woocommerceNamespace'] ) || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return $html;
	}
	$endpoint = function_exists( 'WC' ) && WC()->query ? (string) WC()->query->get_current_endpoint() : '';
	return foodify_replace_heading_text( $html, foodify_account_heading( is_user_logged_in(), $endpoint ) );
}, 20, 2 );
