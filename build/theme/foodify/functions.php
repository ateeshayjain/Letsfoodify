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
	$path = FOODIFY_DIR . '/style.css';

	/**
	 * WP-04 budget: 6 CSS files against a baseline of 60. The theme's own
	 * stylesheet is small enough that inlining it is worth more than caching it —
	 * it removes a render-blocking request from the critical path, which is what
	 * LCP is actually measuring.
	 *
	 * The trade is real and goes the other way on repeat visits, where an
	 * external file would already be cached. With page caching in front and a
	 * file this size, first paint wins. Filterable so it stays a decision:
	 *
	 *     add_filter( 'foodify_inline_stylesheet', '__return_false' );
	 */
	$inline = (bool) apply_filters( 'foodify_inline_stylesheet', filesize( $path ) < 30000 );

	if ( $inline && is_readable( $path ) ) {
		wp_register_style( 'foodify', false, [], FOODIFY_VERSION );
		wp_enqueue_style( 'foodify' );
		wp_add_inline_style( 'foodify', (string) file_get_contents( $path ) );
		return;
	}

	wp_enqueue_style( 'foodify', get_stylesheet_uri(), [], FOODIFY_VERSION );
}, 20 );

/**
 * Preload the two font files. WP-04 requires fonts "self-hosted and preloaded";
 * theme.json self-hosts them but WordPress does not preload — it emits the
 * @font-face rules, and the browser only discovers the files after parsing CSS.
 * On a page whose largest element is a heading, that discovery delay IS the LCP.
 *
 * Only the two actually used are preloaded. Preloading everything is the common
 * mistake: it competes with the image the browser also needs.
 */
add_action( 'wp_head', static function (): void {
	$dir = get_stylesheet_directory_uri() . '/assets/fonts/';
	foreach ( [ 'Fraunces-Variable.woff2', 'InstrumentSans-Variable.woff2' ] as $file ) {
		if ( ! is_readable( FOODIFY_DIR . '/assets/fonts/' . $file ) ) {
			continue;   // never preload a 404
		}
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( $dir . $file )
		);
	}
}, 1 );

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
 * jQuery Migrate exists to shim deprecated jQuery calls. Nothing in this theme
 * needs it and modern WooCommerce does not either — it is roughly 10KB of
 * parse-and-execute on every page for compatibility with code from 2016.
 *
 * If something breaks, it is a plugin calling a removed jQuery API, and the fix
 * is that plugin, not carrying the shim site-wide.
 */
add_action( 'wp_default_scripts', static function ( WP_Scripts $scripts ): void {
	if ( is_admin() || empty( $scripts->registered['jquery'] ) ) {
		return;
	}
	$scripts->registered['jquery']->deps = array_diff(
		$scripts->registered['jquery']->deps,
		[ 'jquery-migrate' ]
	);
} );

/**
 * Defer what can be deferred.
 *
 * THE INTERACTION THAT MAKES A BLANKET `defer` WRONG: an inline script attached
 * to a handle prints immediately after that handle's tag and is NOT deferred. So
 * deferring a dependency while its inline script runs synchronously means the
 * inline code executes before the library it needs exists. This theme would
 * break itself doing that — inc/checkout-fields.php attaches the PIN-code lookup
 * to a jQuery-dependent handle.
 *
 * So: never defer jQuery, and never defer a handle that carries inline data.
 */
/**
 * The decision, as a pure function so it can be tested. Every argument is passed
 * in; nothing is looked up. See tests/perf-test.php.
 *
 * @param string $tag        The full <script> tag WordPress built.
 * @param string $handle     Script handle.
 * @param bool   $has_inline Whether the handle carries before/after inline data.
 * @param array  $never      Handles that must never be deferred.
 */
function foodify_defer_script_tag( string $tag, string $handle, bool $has_inline, array $never ): string {
	if ( in_array( $handle, $never, true ) ) {
		return $tag;
	}
	if ( $has_inline ) {
		return $tag;
	}
	if ( str_contains( $tag, ' defer' ) || str_contains( $tag, ' async' ) || str_contains( $tag, 'type="module"' ) ) {
		return $tag;
	}
	if ( ! str_contains( $tag, ' src=' ) ) {
		return $tag;   // inline-only tag: nothing to defer
	}
	return str_replace( ' src=', ' defer src=', $tag );
}

add_filter( 'script_loader_tag', static function ( string $tag, string $handle ): string {
	if ( is_admin() ) {
		return $tag;
	}

	$never = (array) apply_filters( 'foodify_never_defer', [
		'jquery', 'jquery-core', 'jquery-migrate', 'wp-polyfill',
	] );

	$scripts    = wp_scripts();
	$has_inline = false;
	foreach ( [ 'before', 'after' ] as $position ) {
		if ( ! empty( $scripts->get_data( $handle, $position ) ) ) {
			$has_inline = true;
			break;
		}
	}

	return foodify_defer_script_tag( $tag, $handle, $has_inline, $never );
}, 10, 2 );

/*
 * CART FRAGMENTS STAY. Disabling wc-cart-fragments is the most-repeated
 * WooCommerce performance tip on the internet and it is wrong for this store.
 * Two things here depend on it: the mini-cart count in parts/header.html, and
 * the free-shipping progress bar, which registers itself as a fragment so it
 * cannot show a stale figure after a quantity change.
 *
 * Turn it off and both silently stop updating — the bar goes back to promising
 * something the cart no longer says, which is the exact defect it was built to
 * prevent. If fragments ever need to go, those two components need replacing
 * first.
 */

/*
 * IMAGE FORMATS ARE THE PLUGIN'S JOB, NOT THIS FILE'S.
 * WP-04 wants modern formats across the library. WordPress can be filtered to
 * write WebP on upload, but ShortPixel is already budgeted (₹10,000/yr) for
 * exactly this plus CDN delivery, and two systems converting the same images is
 * worse than one — you get double-processed files and no single answer to "which
 * variant is being served?". Configure it there; do not add a competing filter
 * here.
 */

/*
 * LCP PRIORITY IS CORE'S JOB. There used to be a filter here that set
 * fetchpriority="high" on the first attachment image of the request. It is gone,
 * deliberately, and should not come back.
 *
 * WordPress does this itself in wp_get_loading_optimization_attributes(), behind
 * wp_high_priority_element_flag() — a static that guarantees EXACTLY ONE element
 * per request gets high priority, decided with viewport and content-media
 * heuristics. Two high-priority images is worse than none: they compete for the
 * same bandwidth and the real LCP element arrives later.
 *
 * The filter also picked the wrong image. "First attachment image rendered" is
 * not "largest contentful paint". On the front page the hero is a photography
 * placeholder with no image at all, so the first attachment was a best-seller
 * thumbnail in a grid below the fold — marked high priority AND eager, competing
 * with whatever the browser actually needed first.
 *
 * If a specific template ever needs to override core's choice, do it on that
 * template's own image, not on a request-wide static.
 */

/*
 * The FOODIFY_YEAR substitution moved to inc/business-profile.php.
 *
 * It now shares one token table with the FSSAI licence number, because two
 * render_block filters doing the same job is how the second one gets forgotten —
 * and forgetting the first is precisely what shipped `<!--FOODIFY_YEAR-->` to
 * the live footer as an invisible HTML comment while the preview rendered a
 * year the site could never show.
 */

/** Feature modules. Each is independently removable. */
require_once FOODIFY_DIR . '/inc/checkout-fields.php';
require_once FOODIFY_DIR . '/inc/checkout-flow.php';    // WP-06: the page around the form
require_once FOODIFY_DIR . '/inc/payments.php';          // WP-07: prepaid saving, COD rules
require_once FOODIFY_DIR . '/inc/business-profile.php';  // WP-08: NAP, licence, token table
require_once FOODIFY_DIR . '/inc/reviews.php';           // WP-08: product reviews + the ask
require_once FOODIFY_DIR . '/inc/partner-ledger.php';     // WP-09: must load BEFORE coupon-attribution
require_once FOODIFY_DIR . '/inc/roles.php';             // WP-10: Shop Staff, least privilege
require_once FOODIFY_DIR . '/inc/admin-dashboard.php';   // WP-10: the landing screen
require_once FOODIFY_DIR . '/inc/coupon-attribution.php';
require_once FOODIFY_DIR . '/inc/product-attributes.php';   // must load BEFORE product-display
require_once FOODIFY_DIR . '/inc/product-display.php';
require_once FOODIFY_DIR . '/inc/product-spec.php';      // design pass: prep steps + declarations
require_once FOODIFY_DIR . '/inc/patterns.php';
require_once FOODIFY_DIR . '/inc/shortcodes.php';
require_once FOODIFY_DIR . '/inc/account.php';
require_once FOODIFY_DIR . '/inc/address-book.php';   // WP-05: several addresses, one default
require_once FOODIFY_DIR . '/inc/otp-throttle.php';   // WP-05: the rule, ahead of the gateway
