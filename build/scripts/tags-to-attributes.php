<?php
/**
 * WP-02 — migrate the useful product tags into filter attributes.
 *
 *   wp eval-file tags-to-attributes.php report
 *   wp eval-file tags-to-attributes.php execute --confirm
 *
 * ORDER IS NOT NEGOTIABLE: this runs BEFORE taxonomy-cleanup.php execute.
 * Once a tag is deleted, the fact that a product was vegan is gone — the term
 * relationship goes with the term. Migrate first, delete second. The script
 * refuses to run if the tags it needs have already been removed.
 *
 * What it does NOT do: delete anything. Tags keep their term relationships
 * after migration; taxonomy-cleanup.php removes them later, after the 30-day
 * noindex has done its work.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run through WP-CLI: wp eval-file tags-to-attributes.php report\n" );
}
if ( ! function_exists( 'wc_create_attribute' ) ) {
	WP_CLI::error( 'WooCommerce is not loaded.' );
}
if ( ! function_exists( 'foodify_attribute_map' ) ) {
	WP_CLI::error(
		"foodify_attribute_map() not found — the Foodify theme is not active.\n" .
		"The map lives in theme/foodify/inc/product-attributes.php, which also forces\n" .
		"these taxonomies non-public. Running the migration without it would create\n" .
		"INDEXABLE attribute archives, which is the problem WP-02 is solving."
	);
}

$mode      = $args[0] ?? 'report';
$confirmed = in_array( '--confirm', $args, true );
$MAP       = foodify_attribute_map();

/** Ensure the attribute exists; return its taxonomy name. */
function foodify_ensure_attribute( string $slug, string $label, bool $apply ): string {
	$existing = wc_get_attribute_taxonomies();
	foreach ( $existing as $tax ) {
		if ( $tax->attribute_name === $slug ) {
			return wc_attribute_taxonomy_name( $slug );
		}
	}
	if ( ! $apply ) {
		WP_CLI::log( sprintf( '  would CREATE attribute "%s" (%s)', $label, $slug ) );
		return wc_attribute_taxonomy_name( $slug );
	}
	$id = wc_create_attribute( [
		'name'         => $label,
		'slug'         => $slug,
		'type'         => 'select',
		'order_by'     => 'menu_order',
		'has_archives' => false,   // no archive; product-attributes.php also forces public=false
	] );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( sprintf( 'Could not create attribute %s: %s', $slug, $id->get_error_message() ) );
	}
	WP_CLI::log( sprintf( '  created attribute "%s"', $label ) );
	return wc_attribute_taxonomy_name( $slug );
}

/** Products carrying a tag whose name matches (case-insensitively) any of $names. */
function foodify_products_for_tag_names( array $names ): array {
	$ids = [];
	foreach ( $names as $name ) {
		$term = get_term_by( 'name', $name, 'product_tag' );
		if ( ! $term instanceof WP_Term ) {
			continue;
		}
		$found = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [ [ 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $term->term_id ] ],
		] );
		$ids = array_merge( $ids, array_map( 'intval', $found ) );
	}
	return array_values( array_unique( $ids ) );
}

$apply = ( 'execute' === $mode );
if ( $apply && ! $confirmed ) {
	WP_CLI::error( 'This writes product terms. Re-run with --confirm once you have a backup.' );
}
if ( ! in_array( $mode, [ 'report', 'execute' ], true ) ) {
	WP_CLI::error( "Unknown mode '$mode'. Use: report | execute --confirm" );
}

// Refuse if the source tags are already gone — migrating after deletion is a no-op
// that looks like success.
$tag_total = (int) wp_count_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false ] );
if ( 0 === $tag_total ) {
	WP_CLI::error(
		"No product tags remain. If taxonomy-cleanup.php execute has already run, the\n" .
		"term relationships this migration reads are gone and it cannot recover them.\n" .
		"Restore from the pre-cleanup backup and run this FIRST."
	);
}
WP_CLI::log( sprintf( '%d product tags present.', $tag_total ) );

$rows = []; $assigned = 0; $unmatched = [];

foreach ( $MAP as $attr_slug => $attr ) {
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( 'Attribute: %s', $attr['label'] ) );
	$taxonomy = foodify_ensure_attribute( $attr_slug, $attr['label'], $apply );

	foreach ( $attr['terms'] as $term_slug => $term ) {
		$product_ids = foodify_products_for_tag_names( $term['tags'] );

		if ( ! $product_ids ) {
			$unmatched[] = sprintf( '%s / %s (tags: %s)', $attr['label'], $term['label'], implode( ', ', $term['tags'] ) );
			continue;
		}

		if ( $apply ) {
			if ( ! term_exists( $term_slug, $taxonomy ) ) {
				wp_insert_term( $term['label'], $taxonomy, [ 'slug' => $term_slug ] );
			}
			foreach ( $product_ids as $pid ) {
				// append = true: never clobber an attribute term already set by hand.
				wp_set_object_terms( $pid, $term_slug, $taxonomy, true );
				$assigned++;
			}
		}

		$rows[] = [
			'attribute' => $attr['label'],
			'value'     => $term['label'],
			'from tags' => implode( ', ', $term['tags'] ),
			'products'  => count( $product_ids ),
		];
	}
}

WP_CLI::log( '' );
WP_CLI\Utils\format_items( 'table', $rows, [ 'attribute', 'value', 'from tags', 'products' ] );

if ( $unmatched ) {
	WP_CLI::log( '' );
	WP_CLI::warning( 'No products found for these — the tag names in foodify_attribute_map() may not match the live ones:' );
	foreach ( $unmatched as $u ) {
		WP_CLI::log( '  ' . $u );
	}
	WP_CLI::log( '' );
	WP_CLI::log( 'List the real tag names with:  wp term list product_tag --fields=name,count --format=table' );
}

if ( ! $apply ) {
	WP_CLI::log( '' );
	WP_CLI::warning( 'Report only. Nothing was written. Re-run with: execute --confirm' );
	exit;
}

// Attribute taxonomies are registered on init from the DB table; flush so the
// new ones are queryable in this request and the rewrite rules are clean.
delete_transient( 'wc_attribute_taxonomies' );
WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
flush_rewrite_rules( false );

WP_CLI::success( sprintf( '%d product-attribute assignments written.', $assigned ) );
WP_CLI::log( '' );
WP_CLI::log( 'Next:' );
WP_CLI::log( '  1. Confirm no attribute archive is reachable:' );
WP_CLI::log( '       curl -s -o /dev/null -w "%{http_code}\\n" https://letsfoodify.com/pa_dietary/vegan/   # want 404' );
WP_CLI::log( '  2. Add the Filter by Attribute blocks to the shop sidebar (prep, dietary).' );
WP_CLI::log( '  3. ONLY THEN: wp eval-file taxonomy-cleanup.php report' );
