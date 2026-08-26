<?php
/**
 * WP-12 — the Google Merchant Center product feed. Scope calls it "the revenue
 * item (R5)": free product listings are the surface that actually moves revenue
 * for a packaged-goods catalogue, and the client asked for the other one (GBP).
 *
 * AN ITEM MISSING A REQUIRED ATTRIBUTE IS EXCLUDED, NOT SUBMITTED BROKEN
 * ----------------------------------------------------------------------
 * Google disapproves items missing required attributes, and repeated
 * disapprovals damage the whole account's standing — the feed is graded as a
 * feed, not item by item. So an incomplete product is left OUT and REPORTED,
 * which also makes the feed the enforcement arm of the content pass: no
 * photography, no listing. That is the true dependency stated in code instead
 * of in a schedule.
 *
 * THE ESCAPING IS NOT OPTIONAL POLISH. Product names contain ampersands
 * ("Chai & Snacks combo") and an unescaped & is not "slightly wrong XML", it is
 * a feed that stops PARSING at that byte — every item after the ampersand
 * vanishes and Merchant Center reports a fetch error on the whole file. One
 * product named carelessly takes the catalogue down with it.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/wp12-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/** XML text content. The five metacharacters, plus control chars XML forbids. */
function foodify_xml( string $s ): string {
	$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s ) ?? '';
	return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/** Money the way the feed spec wants it: "185.00 INR". Never a ₹ sign. */
function foodify_feed_price( float $amount ): string {
	return number_format( $amount, 2, '.', '' ) . ' INR';
}

/**
 * Build one feed item, or the list of reasons it cannot be one.
 *
 * @param array{id:string,title:string,description:string,link:string,image:string,
 *              price:float,in_stock:bool,brand:string,gtin:string} $p
 * @return array{item:?array<string,string>,missing:array<int,string>}
 */
function foodify_feed_item( array $p ): array {
	$missing = [];
	foreach ( [ 'id', 'title', 'description', 'link', 'image', 'brand' ] as $f ) {
		if ( '' === trim( (string) ( $p[ $f ] ?? '' ) ) ) {
			$missing[] = $f;
		}
	}
	if ( (float) ( $p['price'] ?? 0 ) <= 0.0 ) {
		$missing[] = 'price';
	}
	// "Not provided" is the PDP being honest with a human. Inside a feed it
	// would be a description asserting garbage to a machine — a product whose
	// description IS the gap marker is a product without a description.
	if ( str_contains( (string) ( $p['description'] ?? '' ), 'Not provided' ) ) {
		$missing[] = 'description';
	}
	if ( $missing ) {
		return [ 'item' => null, 'missing' => array_values( array_unique( $missing ) ) ];
	}

	$item = [
		'g:id'           => (string) $p['id'],
		'g:title'        => mb_substr( (string) $p['title'], 0, 150 ),
		'g:description'  => mb_substr( (string) $p['description'], 0, 5000 ),
		'g:link'         => (string) $p['link'],
		'g:image_link'   => (string) $p['image'],
		'g:availability' => ! empty( $p['in_stock'] ) ? 'in_stock' : 'out_of_stock',
		'g:price'        => foodify_feed_price( (float) $p['price'] ),
		'g:brand'        => (string) $p['brand'],
		'g:condition'    => 'new',
	];

	// Own-brand food has no GTIN. Omitting the field entirely gets the item
	// flagged for a missing identifier; the honest declaration is
	// identifier_exists=false, which Google accepts for own-brand goods.
	$gtin = preg_replace( '/\D/', '', (string) ( $p['gtin'] ?? '' ) ) ?? '';
	if ( in_array( strlen( $gtin ), [ 8, 12, 13, 14 ], true ) ) {
		$item['g:gtin'] = $gtin;
	} else {
		$item['g:identifier_exists'] = 'no';
	}

	return [ 'item' => $item, 'missing' => [] ];
}

/**
 * The whole feed document from built items. RSS 2.0 with the g: namespace —
 * the shape Merchant Center's scheduled fetch expects.
 *
 * @param array<int,array<string,string>> $items
 */
function foodify_feed_xml( array $items, string $shop_title, string $shop_url ): string {
	$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$out .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n";
	$out .= '<title>' . foodify_xml( $shop_title ) . "</title>\n";
	$out .= '<link>' . foodify_xml( $shop_url ) . "</link>\n";
	$out .= '<description>' . foodify_xml( $shop_title . ' product feed' ) . "</description>\n";
	foreach ( $items as $item ) {
		$out .= "<item>\n";
		foreach ( $item as $tag => $value ) {
			$out .= '<' . $tag . '>' . foodify_xml( $value ) . '</' . $tag . ">\n";
		}
		$out .= "</item>\n";
	}
	$out .= "</channel>\n</rss>\n";
	return $out;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

const FOODIFY_FEED_CACHE = 'foodify_merchant_feed';

/** Map a WC product onto the pure builder's shape. */
function foodify_feed_source( WC_Product $product ): array {
	$image_id = (int) $product->get_image_id();
	$profile  = function_exists( 'foodify_business_profile' ) ? foodify_business_profile() : [];

	// Short description first — it is written for a listing. The excerpt-less
	// fallback is the long description stripped of markup; NEVER the spec table.
	$description = trim( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ) );

	return [
		'id'          => 'FDY-' . $product->get_id(),
		'title'       => (string) $product->get_name(),
		'description' => $description,
		'link'        => (string) get_permalink( $product->get_id() ),
		'image'       => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'full' ) : '',
		'price'       => (float) $product->get_price(),
		'in_stock'    => $product->is_in_stock(),
		'brand'       => (string) ( $profile['brand'] ?? '' ),
		'gtin'        => (string) $product->get_meta( '_foodify_gtin' ),
	];
}

/**
 * Serve the feed at /?foodify-feed=1.
 *
 * A query var, not a rewrite endpoint, deliberately: the address-book endpoint
 * already carries the "rewrite rules are cached and go stale on git deploys"
 * failure mode, and a feed URL that 404s after a deploy silently stops the
 * Merchant Center fetch. A query var cannot go stale.
 */
add_action( 'template_redirect', static function (): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only feed
	if ( ! isset( $_GET['foodify-feed'] ) ) {
		return;
	}
	if ( ! function_exists( 'wc_get_products' ) ) {
		status_header( 503 );
		exit;
	}

	$cached = get_transient( FOODIFY_FEED_CACHE );
	if ( ! is_string( $cached ) ) {
		$items    = [];
		$excluded = [];
		foreach ( (array) wc_get_products( [ 'limit' => 500, 'status' => 'publish', 'return' => 'objects' ] ) as $product ) {
			$built = foodify_feed_item( foodify_feed_source( $product ) );
			if ( $built['item'] ) {
				$items[] = $built['item'];
			} else {
				$excluded[ $product->get_id() ] = $built['missing'];
			}
		}
		$cached = foodify_feed_xml( $items, get_bloginfo( 'name' ), home_url( '/' ) );
		set_transient( FOODIFY_FEED_CACHE, $cached, HOUR_IN_SECONDS );
		// The exclusion list is the content team's work queue. Persisted where
		// the admin notice below reads it, never printed into the feed itself.
		update_option( 'foodify_feed_excluded', $excluded, false );
	}

	header( 'Content-Type: application/xml; charset=UTF-8' );
	echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped element-by-element at build time
	exit;
} );

foreach ( [ 'save_post_product', 'woocommerce_product_set_stock' ] as $hook ) {
	add_action( $hook, static function (): void {
		delete_transient( FOODIFY_FEED_CACHE );
	} );
}

/** The exclusion report — "no photography, no listing" made visible. */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'edit-product', 'toplevel_page_foodify-today' ], true ) ) {
		return;
	}
	$excluded = get_option( 'foodify_feed_excluded', [] );
	if ( ! is_array( $excluded ) || ! $excluded ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'Foodify feed:', 'foodify' ),
		esc_html( sprintf(
			/* translators: %d: number of products */
			_n(
				'%d product is EXCLUDED from the Google Shopping feed — most often a missing photo or description. It is invisible on Google until fixed.',
				'%d products are EXCLUDED from the Google Shopping feed — most often missing photos or descriptions. They are invisible on Google until fixed.',
				count( $excluded ),
				'foodify'
			),
			count( $excluded )
		) )
	);
} );
