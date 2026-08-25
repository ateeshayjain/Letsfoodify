<?php
/**
 * Foodify child theme bootstrap.
 *
 * Design tokens live in theme.json. Behaviour lives in inc/.
 * Anything that must survive a cutover belongs in scripts/bootstrap.sh, NOT here.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'FOODIFY_VERSION', '1.0.0' );
define( 'FOODIFY_DIR', get_stylesheet_directory() );

/**
 * Styles. One stylesheet, versioned off the theme constant so a deploy busts the
 * CDN without a manual purge. There is no parent theme — see
 * docs/WP-03-DECISIONS.md for why this is standalone rather than a Blocksy child.
 */
add_action( 'wp_enqueue_scripts', static function (): void {
	wp_enqueue_style( 'foodify', get_stylesheet_uri(), [], FOODIFY_VERSION );
}, 20 );

/**
 * Performance. The audited site shipped 73 JS and 60 CSS files per page; the budget is 12 and 6.
 * These are the cuts that are safe on every template.
 */
add_action( 'init', static function (): void {
	// Emoji detection script — ~10KB, used by nothing on this store.
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );

	// oEmbed host JS — this store embeds nothing.
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );

	// Author enumeration surface; the author sitemap is disabled in bootstrap.sh too.
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
} );

/**
 * Woo block styles load on every page by default, including the blog. Drop them where
 * there is no cart, product or checkout in play.
 */
add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! function_exists( 'is_woocommerce' ) ) {
		return;
	}
	if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
		return;
	}
	foreach ( [ 'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'wc-blocks-style' ] as $handle ) {
		wp_dequeue_style( $handle );
	}
}, 99 );

/**
 * LCP image gets fetchpriority; everything else lazy-loads. Applied to the product
 * gallery's first image and the hero pattern image.
 */
add_filter( 'wp_get_attachment_image_attributes', static function ( array $attr, $attachment, $size ): array {
	static $first = true;
	if ( $first && ! is_admin() && ( is_front_page() || is_product() ) ) {
		$attr['fetchpriority'] = 'high';
		$attr['loading']       = 'eager';
		$first                 = false;
	}
	return $attr;
}, 10, 3 );

/** Feature modules. Each is independently removable. */
require_once FOODIFY_DIR . '/inc/checkout-fields.php';
require_once FOODIFY_DIR . '/inc/coupon-attribution.php';
require_once FOODIFY_DIR . '/inc/product-attributes.php';   // must load BEFORE product-display
require_once FOODIFY_DIR . '/inc/product-display.php';
require_once FOODIFY_DIR . '/inc/patterns.php';
