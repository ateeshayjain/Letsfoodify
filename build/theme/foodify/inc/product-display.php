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

	// Wireframe 01, rule 3: the card is two lines — name, then "Serves 2 · 6 min".
	// The prep chip moved to the product page; on a two-up phone card the yield
	// line answers the two questions a card gets asked in the space a chip took.
	$yield = foodify_card_yield( [
		'servings'     => (string) $product->get_meta( '_foodify_servings' ),
		'prep_minutes' => (string) $product->get_meta( '_foodify_prep_minutes' ),
	] );
	// A combo answers a different question here — what is in the pack, not how
	// many one sachet serves — so product-combo.php swaps the line.
	$yield = (string) apply_filters( 'foodify_card_yield_line', $yield, $product );
	return $html . ( '' !== $yield ? '<p class="fd-yield">' . esc_html( $yield ) . '</p>' : '' );
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Wireframes 01–03 (design playbook §4). Pure rules live in product-spec.php;
 * these are the hooks that read the product and call them.
 * ---------------------------------------------------------------------- */

/** Product ids in the top eight by sales. Cached; the badge is not worth a query per card. */
function foodify_bestseller_ids(): array {
	$ids = get_transient( 'foodify_bestsellers' );
	if ( ! is_array( $ids ) ) {
		$ids = function_exists( 'wc_get_products' )
			? array_map( 'intval', (array) wc_get_products( [ 'limit' => 8, 'orderby' => 'popularity', 'status' => 'publish', 'return' => 'ids' ] ) )
			: [];
		set_transient( 'foodify_bestsellers', $ids, 12 * HOUR_IN_SECONDS );
	}
	return $ids;
}

/** The badge inputs for one product, as foodify_card_badges() wants them. */
function foodify_badge_state( WC_Product $product ): array {
	$created = $product->get_date_created();
	return [
		'in_stock'   => $product->is_in_stock(),
		'bestseller' => in_array( $product->get_id(), foodify_bestseller_ids(), true ),
		'new'        => $created instanceof WC_DateTime && $created->getTimestamp() > time() - 30 * DAY_IN_SECONDS,
		'dietary'    => (array) wc_get_product_terms( $product->get_id(), foodify_attribute_taxonomy( 'dietary' ), [ 'fields' => 'slugs' ] ),
	];
}

/** Wireframe 01, rule 2: badges over the card image. Max two, fixed positions. */
add_filter( 'render_block_woocommerce/product-image', static function ( string $html, array $block ): string {
	if ( '' === $html || empty( $block['attrs']['isDescendentOfQueryLoop'] ) || ! function_exists( 'wc_get_product' ) ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}
	$badges = foodify_render_badges( foodify_card_badges( foodify_badge_state( $product ) ) );
	if ( '' === $badges ) {
		return $html;
	}
	// Inside the wrapper, after the image, so the wrapper's position anchors them.
	$pos = strrpos( $html, '</div>' );
	return false === $pos ? $html . $badges : substr( $html, 0, $pos ) . $badges . substr( $html, $pos );
}, 10, 2 );

/** Wireframe 02, rule 5: the yield strip directly under the price, product page only. */
add_filter( 'render_block_woocommerce/product-price', static function ( string $html, array $block ): string {
	if ( '' === $html || ! empty( $block['attrs']['isDescendentOfQueryLoop'] ) || ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}
	$meta  = static fn( string $k ): string => (string) $product->get_meta( '_foodify_' . $k );
	$parts = foodify_yield_parts( [
		'net_quantity'  => $meta( 'net_quantity' ),
		'cooked_weight' => $meta( 'cooked_weight' ),
		'servings'      => $meta( 'servings' ),
		'prep_minutes'  => $meta( 'prep_minutes' ),
	] );
	// A combo answers this line in meals rather than grams — product-combo.php.
	$parts = (array) apply_filters( 'foodify_pdp_yield_parts', $parts, $product );
	if ( ! $parts ) {
		return $html;
	}
	return $html . '<p class="fd-yield-strip">' . implode( '', array_map( static fn( $p ): string => '<span>' . esc_html( $p ) . '</span>', $parts ) ) . '</p>';
}, 10, 2 );

/** Wireframe 01/02, rule 5: SAVE badge only at or above the floor; below it, the price alone. */
add_filter( 'woocommerce_get_price_html', static function ( string $html, WC_Product $product ): string {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $html;
	}
	if ( ! $product->is_on_sale() || $product->is_type( 'variable' ) ) {
		return $html;
	}
	$badge = foodify_save_badge( (float) $product->get_regular_price(), (float) $product->get_sale_price() );
	return '' === $badge ? $html : $html . ' <span class="fd-save">' . esc_html( $badge ) . '</span>';
}, 20, 2 );

/**
 * Sold out is sold out. WooCommerce's loop button for an out-of-stock product
 * says "Read more" and links to the page, which is a door to a wall. The
 * wireframe asks for NOTIFY ME; that needs a back-in-stock mechanism the
 * store does not have yet, and a button that promises a notification it will
 * never send is the fake-viewer-counter class. So: the plain truth, until the
 * mechanism exists.
 */
add_filter( 'woocommerce_product_add_to_cart_text', static function ( string $text, WC_Product $product ): string {
	return $product->is_in_stock() ? $text : __( 'Sold out', 'foodify' );
}, 10, 2 );

/**
 * Wireframe 03: the sticky add-to-cart bar, plus the page's one script.
 * The bar is hidden until the real button leaves the viewport, carries the
 * live total for the chosen quantity, and its button presses the real one.
 */
add_action( 'wp_footer', static function (): void {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_the_ID() );
	if ( $product instanceof WC_Product && $product->is_in_stock() && $product->is_purchasable() ) {
		printf(
			'<div class="fd-sticky-atc" data-unit="%1$s" hidden><div class="fd-sticky-atc__price"><strong class="fd-sticky-atc__total">%2$s</strong><span class="fd-sticky-atc__qty">1 pack</span></div><button type="button" class="wp-element-button">%3$s</button></div>',
			esc_attr( (string) wc_get_price_to_display( $product ) ),
			wp_kses_post( wc_price( wc_get_price_to_display( $product ) ) ),
			esc_html__( 'Add to cart', 'foodify' )
		);
	}
	echo '<script>' . foodify_pdp_script() . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- static script, no data.
} );

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
	// Wireframe 01, rule 4: at zero reviews the row is HIDDEN — no empty grey
	// stars, and no "No reviews yet" either, which read as a verdict. The
	// count is what makes stars honest, and a row with no count says nothing.
	return $count > 0 ? $html : '';
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
