<?php
/**
 * Product display — prep method, per-serving price, and the honest-social-proof cleanup.
 *
 * Principle 01 of the Design Playbook: prep effort is the deciding attribute for an
 * instant-food catalogue, so it leads the card, ahead of price.
 *
 * Expects per SKU:
 *   pa_prep (attribute)    hot-water | drinking-water | cooking  — CANONICAL
 *   _foodify_prep_method   legacy meta, fallback only until migration completes
 *   _foodify_prep_minutes  integer
 *   _foodify_servings      integer, drives the per-serving price
 *
 * Prep method is resolved via foodify_prep_method() in product-attributes.php.
 * Do not read the meta directly anywhere else — two readers of the same fact
 * drift, and then the chip and the shop filter disagree about the same product.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Below this, and only when stock is genuinely managed per SKU, the count is shown.
 * If the client does not manage stock per item this never fires — which is correct,
 * but they may expect otherwise (REVIEW-NOTES item 9).
 */
const FOODIFY_LOW_STOCK_AT = 5;

/** @return array{label:string, modifier:string}|null */
function foodify_prep_chip_parts( WC_Product $product ): ?array {
	// Resolved through ONE function: the pa_prep attribute is canonical, the
	// legacy meta is the fallback for products not yet migrated. Reading the meta
	// directly here is how the chip and the filter come to disagree.
	$method  = function_exists( 'foodify_prep_method' )
		? foodify_prep_method( $product )
		: (string) $product->get_meta( '_foodify_prep_method' );
	$minutes = (int) $product->get_meta( '_foodify_prep_minutes' );

	// The class is fd-chip, not fd-prep. `.fd-prep` is the product page's
	// "How you make it" band (inc/product-spec.php), and the two shared a name
	// for a month: the band's margin and padding rules cascaded onto every
	// card's chip, which only a real render would have shown.
	$map = [
		'hot_water'      => [ __( 'Hot water', 'foodify' ), 'fd-chip--hot' ],
		'drinking_water' => [ __( 'Drinking water', 'foodify' ), '' ],
		'cooking'        => [ __( 'Cooking', 'foodify' ), 'fd-chip--cook' ],   // "Requires cooking · 5 min" wraps a two-up card
	];

	if ( ! isset( $map[ $method ] ) ) {
		return null;
	}

	[ $label, $modifier ] = $map[ $method ];

	return [
		'label'    => $minutes > 0
			? sprintf( '%s · %d min', $label, $minutes )
			: $label,
		'modifier' => $modifier,
	];
}

/** The chip's markup, or '' when the product has no prep method recorded. */
function foodify_prep_chip_html( WC_Product $product ): string {
	$chip = foodify_prep_chip_parts( $product );
	if ( ! $chip ) {
		return '';
	}

	return sprintf(
		'<span class="fd-chip %s">%s</span>',
		esc_attr( $chip['modifier'] ),
		esc_html( $chip['label'] )
	);
}

/** Chip above the product title, on cards and on the single product page. */
function foodify_render_prep_chip(): void {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	echo foodify_prep_chip_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the builder.
}

add_action( 'woocommerce_before_shop_loop_item_title', 'foodify_render_prep_chip', 9 );
add_action( 'woocommerce_single_product_summary', 'foodify_render_prep_chip', 4 );

/**
 * The same chip on the block-built grids.
 *
 * `woocommerce_before_shop_loop_item_title` is a CLASSIC-loop hook. The shop,
 * category and home grids are Product Query loops, which never fire it — so
 * on the three screens where "six minutes is the product" matters most, the
 * chip was hooked to a template that never runs. The product title block
 * inside those loops carries WooCommerce's namespace attribute, which is the
 * one reliable signal that this title belongs to a product card.
 */
add_filter( 'render_block_core/post-title', static function ( string $html, array $block ): string {
	if ( '' === $html || empty( $block['attrs']['__woocommerceNamespace'] ) ) {
		return $html;
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}

	return foodify_prep_chip_html( $product ) . $html;
}, 10, 2 );

/**
 * Sort options that read as options. The toolbar puts a visible "Sort by"
 * beside the select, so WooCommerce's own labels would render as
 * "Sort by  Sort by popularity". These are the same keys, shortened.
 */
add_filter( 'woocommerce_catalog_orderby', static function ( array $options ): array {
	$short = [
		'menu_order' => __( 'Featured', 'foodify' ),
		'popularity' => __( 'Most popular', 'foodify' ),
		'rating'     => __( 'Best rated', 'foodify' ),
		'date'       => __( 'Newest', 'foodify' ),
		'price'      => __( 'Price: low to high', 'foodify' ),
		'price-desc' => __( 'Price: high to low', 'foodify' ),
	];
	foreach ( $short as $key => $label ) {
		if ( isset( $options[ $key ] ) ) {
			$options[ $key ] = $label;
		}
	}
	return $options;
} );

/**
 * On a phone the filters start CLOSED. The details block ships `open` so that
 * on desktop, where its summary is hidden, the sidebar is simply there — CSS
 * can hide a summary but cannot close a details element, so the one line of
 * script does that, and only below the breakpoint core stacks columns at.
 * Without JavaScript the filters are open, which is the safe failure.
 */
add_action( 'wp_footer', static function (): void {
	if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_taxonomy() ) ) {
		return;
	}
	echo "<script>if(matchMedia('(max-width:781px)').matches){document.querySelectorAll('details.fd-filters[open]').forEach(function(d){d.removeAttribute('open')})}</script>\n";
} );

/** Per-serving maths under the price. ₹210 reads differently as ₹105 a head. */
add_filter( 'woocommerce_get_price_html', static function ( string $html, WC_Product $product ): string {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $html;
	}

	$servings = (int) $product->get_meta( '_foodify_servings' );
	$price    = (float) wc_get_price_to_display( $product );

	if ( $servings < 2 || $price <= 0 ) {
		return $html;
	}

	return $html . sprintf(
		'<span class="fd-per-serving">%s</span>',
		esc_html( sprintf(
			/* translators: %s: formatted per-serving price */
			__( '%s per serving', 'foodify' ),
			wp_strip_all_tags( wc_price( $price / $servings ) )
		) )
	);
}, 10, 2 );

/**
 * Honest scarcity only. The audited site displayed a hardcoded "70 people are viewing
 * this right now" on every product. This shows a stock count only when the number is
 * real and genuinely low.
 */
add_filter( 'woocommerce_get_availability_text', static function ( string $text, WC_Product $product ): string {
	if ( ! $product->managing_stock() || ! $product->is_in_stock() ) {
		return $text;
	}

	// Never overwrite a backorder message with "In stock". WooCommerce says
	// "Available on backorder" for a product that is purchasable but not on the
	// shelf; replacing that with "In stock" tells the customer something untrue,
	// which is the exact failing this module exists to correct.
	if ( $product->is_on_backorder( 1 ) ) {
		return $text;
	}

	$stock = (int) $product->get_stock_quantity();

	if ( $stock > 0 && $stock <= FOODIFY_LOW_STOCK_AT ) {
		/* translators: %d: units remaining */
		return sprintf( _n( 'Only %d left', 'Only %d left', $stock, 'foodify' ), $stock );
	}

	// Anything else keeps WooCommerce's own wording rather than being flattened.
	return $text;
}, 10, 2 );

/**
 * Reviews on, and the rating visible even at zero — an absent star row reads as
 * "nobody bought this" rather than "this is new".
 */
add_filter( 'woocommerce_product_get_rating_html', static function ( string $html, $rating, int $count ): string {
	if ( $html || ( ! is_shop() && ! is_product_category() ) ) {
		return $html;
	}

	return '<span class="fd-rating fd-rating--empty">' . esc_html__( 'No reviews yet', 'foodify' ) . '</span>';
}, 10, 3 );

/** Curated cross-sells beat tag-matched "related products" — a gravy should suggest rice. */
add_filter( 'woocommerce_related_products', static function ( array $related, int $product_id ): array {
	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product ) {
		return $related;
	}

	$curated = $product->get_cross_sell_ids();

	return $curated ? array_map( 'intval', $curated ) : $related;
}, 10, 2 );
