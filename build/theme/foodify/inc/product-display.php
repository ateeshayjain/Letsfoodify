<?php
/**
 * Product display — prep method, per-serving price, and the honest-social-proof cleanup.
 *
 * Principle 01 of the Design Playbook: prep effort is the deciding attribute for an
 * instant-food catalogue, so it leads the card, ahead of price.
 *
 * Expects two product fields, set per SKU:
 *   _foodify_prep_method   hot_water | drinking_water | cooking
 *   _foodify_prep_minutes  integer
 *   _foodify_servings      integer, drives the per-serving price
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
	$method  = (string) $product->get_meta( '_foodify_prep_method' );
	$minutes = (int) $product->get_meta( '_foodify_prep_minutes' );

	$map = [
		'hot_water'      => [ __( 'Hot water', 'foodify' ), 'fd-prep--hot' ],
		'drinking_water' => [ __( 'Drinking water', 'foodify' ), '' ],
		'cooking'        => [ __( 'Requires cooking', 'foodify' ), 'fd-prep--cook' ],
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

/** Chip above the product title, on cards and on the single product page. */
function foodify_render_prep_chip(): void {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$chip = foodify_prep_chip_parts( $product );
	if ( ! $chip ) {
		return;
	}

	printf(
		'<span class="fd-prep %s">%s</span>',
		esc_attr( $chip['modifier'] ),
		esc_html( $chip['label'] )
	);
}

add_action( 'woocommerce_before_shop_loop_item_title', 'foodify_render_prep_chip', 9 );
add_action( 'woocommerce_single_product_summary', 'foodify_render_prep_chip', 4 );

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
