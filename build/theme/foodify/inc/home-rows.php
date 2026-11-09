<?php
/**
 * The home page's rows (client, 9 Nov 2026 — Blinkit's stacked shelves):
 * Bestsellers · Just add hot water · Under ₹200 · Jain meals.
 *
 * Each row is a core Query Loop in templates/front-page.html carrying one
 * extra key in its query, `fdRow`, and nothing else that names a term. The
 * filter below turns that key into the query — by SLUG and by price, never by
 * a term id, because ids differ between staging and production and a template
 * that hard-codes one shows the wrong row (or none) after cutover.
 *
 * On a phone a row is a strip that swipes sideways; from 782px it is one row
 * of four with "See all" (style.css, "Home rows"). In-stock meals only: a
 * shelf is for buying, and "Sold out" four times in a strip reads as a shop
 * running down.
 *
 * @package Foodify
 */
declare( strict_types = 1 );
defined( 'ABSPATH' ) || exit;

/**
 * The rows, in order. One place: the template's headings and links, the query
 * args, the prototype and the tests all read this.
 *
 * @return array<string,array{title:string,see_all:string}>
 */
function foodify_home_rows(): array {
	return [
		'bestsellers' => [ 'title' => 'Bestsellers',        'see_all' => '/shop/?orderby=popularity' ],
		'hot-water'   => [ 'title' => 'Just add hot water', 'see_all' => '/shop/?filter_prep=hot-water' ],
		'under-200'   => [ 'title' => 'Under ₹200',         'see_all' => '/shop/?max_price=200' ],
		'jain'        => [ 'title' => 'Jain meals',         'see_all' => '/shop/?filter_dietary=jain' ],
	];
}

/**
 * The WP_Query args a row adds to the loop's own (which already ask for
 * products, by popularity, in stock). Unknown key → no change.
 *
 * @return array{tax_query?:array,meta_query?:array}
 */
function foodify_home_row_query_args( string $row ): array {
	$tax = static fn( string $taxonomy, string $slug ): array => [ 'tax_query' => [ [ 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => [ $slug ] ] ] ];
	switch ( $row ) {
		case 'hot-water':
			return $tax( 'pa_prep', 'hot-water' );
		case 'jain':
			return $tax( 'pa_dietary', 'jain' );
		case 'under-200':
			return [ 'meta_query' => [ [ 'key' => '_price', 'value' => 200, 'compare' => '<=', 'type' => 'NUMERIC' ] ] ];
		default:
			return [];
	}
}

/** Merge a row's args into a loop's, keeping whatever tax/meta clauses the loop already had. */
function foodify_home_row_merge( array $query, array $add ): array {
	foreach ( [ 'tax_query', 'meta_query' ] as $k ) {
		if ( empty( $add[ $k ] ) ) {
			continue;
		}
		$have        = isset( $query[ $k ] ) && is_array( $query[ $k ] ) ? $query[ $k ] : [];
		$query[ $k ] = array_merge( $have, $add[ $k ] );
		if ( count( $query[ $k ] ) > 1 && ! isset( $query[ $k ]['relation'] ) ) {
			$query[ $k ]['relation'] = 'AND';
		}
	}
	return $query;
}

/**
 * The cart page's "You might also like": best sellers minus what is already in
 * the cart, so it never offers a meal the shopper has. Returned as the loop
 * args to merge; no ids, no change.
 *
 * @param array<int,int> $in_cart product (and parent) ids on the cart's lines
 */
function foodify_cart_suggest_args( array $query, array $in_cart ): array {
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $in_cart ) ) ) );
	if ( ! $ids ) {
		return $query;
	}
	$have                   = isset( $query['post__not_in'] ) && is_array( $query['post__not_in'] ) ? array_map( 'intval', $query['post__not_in'] ) : [];
	$query['post__not_in'] = array_values( array_unique( array_merge( $have, $ids ) ) );
	return $query;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

add_filter( 'query_loop_block_query_vars', static function ( array $query, $block ) {
	$row = is_object( $block ) && isset( $block->context['query']['fdRow'] ) ? (string) $block->context['query']['fdRow'] : '';
	if ( 'cart-suggest' === $row ) {
		$ids = [];
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$ids[] = (int) ( $item['product_id'] ?? 0 );
				$ids[] = (int) ( $item['variation_id'] ?? 0 );
			}
		}
		return foodify_cart_suggest_args( $query, $ids );
	}
	return '' === $row ? $query : foodify_home_row_merge( $query, foodify_home_row_query_args( $row ) );
}, 20, 2 );
