<?php
/**
 * Block patterns. Every page is assembled from these — no page builder, no per-page CSS.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function (): void {
	if ( ! function_exists( 'register_block_pattern_category' ) ) {
		return;
	}

	register_block_pattern_category( 'foodify', [
		'label'       => __( 'Foodify', 'foodify' ),
		'description' => __( 'Storefront sections built on the Foodify design system.', 'foodify' ),
	] );
} );

/** Unregister core and Woo pattern noise so the inserter shows only what we support. */
add_action( 'init', static function (): void {
	remove_theme_support( 'core-block-patterns' );
}, 9 );
