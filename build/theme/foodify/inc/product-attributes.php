<?php
/**
 * WP-02 — the filter attributes that replace 170 tag archives.
 *
 * The audit found 170 indexable tag pages serving 44 products. Deleting them is
 * only half the job: tags like "Vegan" and "Gluten Free" were doing something
 * useful — letting a customer narrow the catalogue. Remove them without a
 * replacement and the store loses a real feature to gain an SEO number.
 *
 * So the useful ones become WooCommerce product attributes, which drive layered
 * navigation on /shop/ and generate no indexable archive of their own.
 *
 * THE TRAP THIS FILE EXISTS TO CLOSE
 * ----------------------------------
 * A WooCommerce attribute registers a `pa_*` taxonomy, and those are PUBLIC by
 * default. Migrate tags to attributes without this and you delete 170 indexable
 * tag archives and create a fresh set of indexable attribute archives —
 * /product-tag/vegan/ becomes /pa_dietary/vegan/ and the crawl budget problem
 * is exactly where it started. WP-02's criterion is explicit: the attributes
 * "generate no indexable archive".
 *
 * `public => false` makes the term archive 404. Layered navigation is
 * unaffected: it filters via `filter_pa_*` query vars on the shop page, which
 * do not need a public taxonomy.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * The attributes, and the tag names that migrate into each.
 *
 * Tag names are matched case-insensitively against the live tag list by
 * scripts/tags-to-attributes.php. Anything not listed here is handled by
 * taxonomy-cleanup.php — deleted with a redirect, not silently dropped.
 */
function foodify_attribute_map(): array {
	return [
		'prep' => [
			'label' => __( 'Prep method', 'foodify' ),
			'terms' => [
				'hot-water'      => [ 'label' => __( 'Just add hot water', 'foodify' ),
				                      'tags'  => [ 'instant', 'no cooking', 'ready to eat', 'just add water' ] ],
				'drinking-water' => [ 'label' => __( 'Stir with drinking water', 'foodify' ),
				                      'tags'  => [ 'chutney', 'no cook' ] ],
				'cooking'        => [ 'label' => __( 'Requires cooking', 'foodify' ),
				                      'tags'  => [ 'ready to cook', 'cook at home' ] ],
			],
		],
		'dietary' => [
			'label' => __( 'Dietary', 'foodify' ),
			'terms' => [
				'vegan'        => [ 'label' => __( 'Vegan', 'foodify' ),        'tags' => [ 'vegan' ] ],
				'gluten-free'  => [ 'label' => __( 'Gluten free', 'foodify' ),  'tags' => [ 'gluten free', 'gluten-free' ] ],
				'jain'         => [ 'label' => __( 'Jain', 'foodify' ),         'tags' => [ 'jain', 'no onion no garlic' ] ],
				'millet'       => [ 'label' => __( 'Millet based', 'foodify' ), 'tags' => [ 'millet', 'millets' ] ],
				'high-protein' => [ 'label' => __( 'High protein', 'foodify' ), 'tags' => [ 'high protein', 'protein' ] ],
			],
		],
	];
}

/** Attribute slug -> the `pa_` taxonomy WooCommerce registers for it. */
function foodify_attribute_taxonomy( string $slug ): string {
	return 'pa_' . $slug;
}

/**
 * Force every Foodify attribute taxonomy non-public.
 *
 * WooCommerce applies `woocommerce_taxonomy_args_pa_{slug}` when registering.
 * Setting public/publicly_queryable false means /pa_dietary/vegan/ 404s while
 * `?filter_dietary=vegan` on the shop page keeps working.
 */
foreach ( array_keys( foodify_attribute_map() ) as $foodify_attr_slug ) {
	add_filter(
		'woocommerce_taxonomy_args_' . foodify_attribute_taxonomy( $foodify_attr_slug ),
		static function ( array $args ): array {
			$args['public']             = false;
			$args['publicly_queryable'] = false;
			$args['has_archive']        = false;
			$args['rewrite']            = false;
			$args['show_in_nav_menus']  = false;
			return $args;
		}
	);
}
unset( $foodify_attr_slug );

/**
 * Belt and braces: if anything re-registers these as public, the archive still
 * must not be indexed. Rank Math reads this filter for term-level robots.
 */
add_filter( 'rank_math/frontend/robots', static function ( array $robots ): array {
	if ( ! is_tax() ) {
		return $robots;
	}
	$tax = get_queried_object();
	if ( $tax instanceof WP_Term && str_starts_with( $tax->taxonomy, 'pa_' ) ) {
		$robots['index'] = 'noindex';
	}
	return $robots;
} );

/**
 * ONE source of truth for prep method.
 *
 * `product-display.php` reads `_foodify_prep_method` post meta to render the
 * prep chip. Once prep method is also an attribute there are two places that
 * answer the same question, and they will drift — the chip will say one thing
 * while the filter returns another. The attribute is canonical because that is
 * what layered navigation queries; the meta stays only as a fallback for
 * products not yet migrated.
 *
 * @return string '' | hot_water | drinking_water | cooking
 */
function foodify_prep_method( WC_Product $product ): string {
	$terms = wc_get_product_terms( $product->get_id(), foodify_attribute_taxonomy( 'prep' ), [ 'fields' => 'slugs' ] );
	if ( ! empty( $terms[0] ) ) {
		return str_replace( '-', '_', (string) $terms[0] );   // hot-water -> hot_water
	}
	return (string) $product->get_meta( '_foodify_prep_method' );
}
