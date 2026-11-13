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
	// On the shop and category pages WooCommerce replays the classic loop hooks
	// around the blocks of a block-built grid (its "compatibility layer", on the
	// render_block filter). The block card already carries its prep line, so
	// printing here as well put the method on every card twice — seen on the
	// first real WooCommerce render, 13 Nov 2026. Classic loops are not inside
	// that filter and still get their chip.
	if ( doing_filter( 'render_block' ) ) {
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

	// UX review 8 Nov 2026, BROWSE-1 — principle 1 again: the card names the
	// prep method and time FIRST ("Hot water · 6 min · Serves 2"), above the
	// name. That one line replaces the grey "Serves 2 · 6 min" line that used to
	// sit under the name, so the card does not grow. No prep method recorded:
	// the old yield line, under the name, as before.
	$chip  = foodify_prep_chip_parts( $product );
	$combo = function_exists( 'foodify_combo_data' ) && null !== foodify_combo_data( $product );
	// "Too cluttered" (client, 9 Nov 2026): one line under the name — how it is
	// made. Servings and the diet tags live on the product page, in the
	// filters and in search; the pack size moved down beside the unit price.
	$lead  = $chip ? foodify_card_prep_html( $chip ) : '';
	$yield = '' !== $lead ? '' : foodify_card_yield( [
		'servings'     => (string) $product->get_meta( '_foodify_servings' ),
		'prep_minutes' => (string) $product->get_meta( '_foodify_prep_minutes' ),
	] );
	// A combo answers a different question under its name — what is in the
	// pack, not how many one sachet serves — so product-combo.php swaps the line.
	$yield = (string) apply_filters( 'foodify_card_yield_line', $yield, $product );
	return $lead . $html . ( '' !== $yield ? '<p class="fd-yield">' . esc_html( $yield ) . '</p>' : '' ); // $lead escaped in its builder.
}, 10, 2 );

/** The declared diet, the one field the veg mark reads. */
function foodify_product_diet( WC_Product $product ): string {
	return (string) $product->get_meta( '_foodify_diet' );
}

/**
 * FSSAI's vegetarian mark beside the name — on every card, and on the product
 * page's H1. Read from the declared diet (foodify_diet_is_veg), never assumed:
 * every Foodify product is vegetarian today, and the day one is not, the mark
 * must not follow it there. Inside the heading, before the name, where Indian
 * packs and menus put it.
 */
add_filter( 'render_block_core/post-title', static function ( string $html, array $block ): string {
	if ( '' === $html || ! function_exists( 'wc_get_product' ) ) {
		return $html;
	}
	$card = ! empty( $block['attrs']['__woocommerceNamespace'] );
	$pdp  = ! $card && 1 === (int) ( $block['attrs']['level'] ?? 2 ) && function_exists( 'is_product' ) && is_product();
	if ( ! $card && ! $pdp ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}
	$mark = foodify_veg_mark_html( foodify_product_diet( $product ) );
	// The FIRST heading tag, wherever it starts: on the shop and category pages
	// WooCommerce's compatibility layer has already put classic-hook output in
	// front of the heading, and anchored to the start of the string the mark
	// was on the home page's cards and on none of the shop's.
	return '' === $mark ? $html : (string) preg_replace( '/(<h[1-6]\b[^>]*>)/', '$1' . $mark, $html, 1 );
}, 5, 2 );

/**
 * The product page's badges, directly under the H1 and its veg mark: the same
 * Bestseller / New / Sold out the card shows, and every dietary tag (Jain only
 * when the ingredients allow it). UX review 8 Nov 2026, PRODUCT-5: the card
 * said "Bestseller · Jain" and the page it opened said neither.
 */
add_filter( 'render_block_core/post-title', static function ( string $html, array $block ): string {
	if ( '' === $html || ! empty( $block['attrs']['__woocommerceNamespace'] ) || 1 !== (int) ( $block['attrs']['level'] ?? 2 )
		|| ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}
	$state = foodify_badge_state( $product );
	$tags  = foodify_pdp_tags_html(
		foodify_card_badges( $state )['commercial'],
		foodify_diet_tags( $state['dietary'], $state['ingredients'] )
	);
	return $html . $tags; // Escaped in the builder.
}, 6, 2 );

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
		// The Jain badge is checked against this, not trusted from the tag.
		'ingredients' => (string) $product->get_meta( '_foodify_ingredients' ),
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
	// Commercial only (Sold out / Bestseller / New): the dietary tag is text
	// under the name since BROWSE-18 (foodify_card_diet_html()).
	$badges = foodify_render_badges( [ 'dietary' => '' ] + foodify_card_badges( foodify_badge_state( $product ) ) );
	if ( '' === $badges ) {
		return $html;
	}
	// Inside the wrapper, after the image, so the wrapper's position anchors them.
	$pos = strrpos( $html, '</div>' );
	return false === $pos ? $html . $badges : substr( $html, 0, $pos ) . $badges . substr( $html, $pos );
}, 10, 2 );

/** On a card, the pack size leads the unit price line: "80 g · ₹231.25/100 g". */
add_filter( 'render_block_woocommerce/product-price', static function ( string $html, array $block ): string {
	if ( '' === $html || empty( $block['attrs']['isDescendentOfQueryLoop'] ) || ! function_exists( 'wc_get_product' ) ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	return $product instanceof WC_Product ? foodify_card_pack_unit( $html, (string) $product->get_meta( '_foodify_net_quantity' ) ) : $html;
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
	// "Contains: milk, gluten" under the strip, when allergens are declared
	// (PRODUCT-20). The full declaration stays in the Ingredients tab.
	$allergens = foodify_allergen_line_html( $meta( 'allergens' ) );
	if ( ! $parts ) {
		return $html . $allergens;
	}
	return $html . '<p class="fd-yield-strip">' . implode( '', array_map( static fn( $p ): string => '<span>' . esc_html( $p ) . '</span>', $parts ) ) . '</p>' . $allergens;
}, 10, 2 );

/**
 * The assurances, under the add-to-cart form and after the delivery check and
 * Offers (priorities 10 and 11) and the sold-out alert form (20). They were
 * typed into the template, the same on every product; now they read the
 * product (foodify_assurance_lines()).
 */
add_filter( 'render_block_woocommerce/add-to-cart-form', static function ( string $html ): string {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}
	$in_stock = $product->is_in_stock();
	$cod      = $in_stock && function_exists( 'foodify_offer_config' ) && function_exists( 'foodify_cod_line' )
		? foodify_cod_line( foodify_offer_config( $product ) )
		: '';
	$lines = foodify_assurance_lines( [
		'shelf_life' => (string) $product->get_meta( '_foodify_shelf_life' ),
		'cod'        => $cod,
		'in_stock'   => $in_stock,
	] );
	return $html . foodify_assurance_html( $lines, foodify_assurance_note( $in_stock ) ); // Escaped in the builder.
}, 25 );

/**
 * A screen reader hears the MRP before the sale price (PRODUCT-16) — see
 * foodify_sale_price_html(). WooCommerce's own version hides the MRP from
 * readers and explains it in .screen-reader-text spans this theme does not style.
 */
add_filter( 'woocommerce_format_sale_price', static function ( $price, $regular, $sale ) {
	// wp-admin does not load the theme's .fd-sr, so there the text would show.
	if ( ( is_admin() && ! wp_doing_ajax() ) || ! is_numeric( $regular ) || ! is_numeric( $sale ) ) {
		return $price;
	}
	$reg = wc_price( (float) $regular );
	// Decoded: wc_price() writes the rupee sign as an entity, and the builder
	// escapes its text — together a reader heard "MRP &#8377;225".
	return foodify_sale_price_html( $reg, wc_price( (float) $sale ), html_entity_decode( wp_strip_all_tags( $reg ), ENT_QUOTES, 'UTF-8' ) );
}, 10, 3 );

/** "Add another · 1 in cart" on the card button once the product is in the cart (BROWSE-7). */
add_filter( 'gettext_woocommerce', static function ( $translation, $text ) {
	return '%s in cart' === $text ? foodify_in_cart_text() : $translation;
}, 10, 2 );

/**
 * Sold-out products last in every listing sort (BROWSE-8) — the shop, the
 * category pages, search results, and every Product Collection block (home and
 * related grids), whatever sort is chosen. foodify_sold_out_last_clauses().
 */
add_filter( 'posts_clauses', static function ( array $clauses, WP_Query $q ): array {
	if ( is_admin() || $q->is_singular() ) {
		return $clauses;
	}
	$type     = $q->get( 'post_type' );
	$products = 'product' === $type || [ 'product' ] === $type;
	$listing  = ! empty( $q->query_vars['isProductCollection'] )
		|| ( $q->is_main_query() && ( $products || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) );
	if ( ! $listing ) {
		return $clauses;
	}
	global $wpdb;
	return foodify_sold_out_last_clauses( $clauses, $wpdb->posts, $wpdb->prefix . 'wc_product_meta_lookup' );
}, 20, 2 );

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
 *
 * Nov 2026: the mechanism exists (inc/stock-alerts.php). Where it can take a
 * subscription for this product, the button says "Notify me" and opens the
 * product page at the form (#fd-notify); where it cannot, still "Sold out".
 */
add_filter( 'woocommerce_product_add_to_cart_text', static function ( string $text, WC_Product $product ): string {
	if ( $product->is_in_stock() ) {
		return $text;
	}
	return function_exists( 'foodify_stock_alerts_accepting' ) && foodify_stock_alerts_accepting( $product )
		? __( 'Notify me', 'foodify' )
		: __( 'Sold out', 'foodify' );
}, 10, 2 );

/**
 * The sold-out card button's accessible name says what it does (BROWSE-21):
 * "Notify me when Masala Oats is back", not WooCommerce's "Read more about".
 * ProductButton uses this description as the button's aria-label.
 */
add_filter( 'woocommerce_product_add_to_cart_description', static function ( $text, $product ) {
	if ( ! $product instanceof WC_Product || $product->is_in_stock() ) {
		return $text;
	}
	$accepting = function_exists( 'foodify_stock_alerts_accepting' ) && foodify_stock_alerts_accepting( $product );
	return foodify_sold_out_button_label( $product->get_name(), $accepting );
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
		// A combo is bought by the box; "1 pack" on the bar of a three-pack box
		// reads as one meal.
		$box = function_exists( 'foodify_combo_data' ) && null !== foodify_combo_data( $product );
		$one = $box ? __( 'box', 'foodify' ) : __( 'pack', 'foodify' );
		$ctl = function_exists( 'foodify_cart_control_html' ) && $product->is_type( 'simple' )
			? foodify_cart_control_html( $product->get_id(), $product->get_name(), $product->add_to_cart_url(), max( 0, (int) $product->get_max_purchase_quantity() ), 'fd-sticky-atc__ctl' )
			: '';
		echo foodify_sticky_atc_html( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the builder; the price is wc_price().
			(string) wc_get_price_to_display( $product ),
			wp_kses_post( wc_price( wc_get_price_to_display( $product ) ) ),
			__( 'Add to cart', 'foodify' ), $one, $box ? __( 'boxes', 'foodify' ) : __( 'packs', 'foodify' ), $ctl
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
	// The template prints the filters closed and opens them from 782px itself
	// (UX review BROWSE-2). This only writes how many filters are on into the
	// closed panel's summary — "Filter · 2" — so a phone shows that it is filtered.
	echo "<script>(function(){var c=document.querySelector('.fd-filters__count');if(!c)return;var n=document.querySelectorAll('.fd-filters input[type=checkbox]:checked').length+(/[?&](min|max)_price=/.test(location.search)?1:0);c.textContent=n?String(n):'';})();</script>\n";
} );

/* The visible "Sort by" label names the select (BROWSE-20); the result count is
 * announced when filters change it (BROWSE-2). WooCommerce's loop/orderby.php
 * prints aria-label="Shop order", which the label replaces. */
add_filter( 'render_block_woocommerce/catalog-sorting', static function ( string $html ): string {
	return preg_replace( '/<select name="orderby" class="orderby"[^>]*?aria-label="[^"]*"/', '<select name="orderby" class="orderby" id="fd-orderby"', $html, 1 ) ?? $html;
} );
add_filter( 'render_block_woocommerce/product-results-count', static function ( string $html ): string {
	return preg_replace( '/<p class="woocommerce-result-count/', '<p role="status" class="woocommerce-result-count', $html, 1 ) ?? $html;
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
 * Unit price — "₹231.25/100 g" — under the price on the card and the product
 * page, from the price the customer is actually shown and the declared net
 * quantity. Nothing when the quantity cannot be read with certainty
 * (foodify_net_quantity), and nothing on a variable product, whose variations
 * have different quantities.
 */
add_filter( 'woocommerce_get_price_html', static function ( string $html, WC_Product $product ): string {
	if ( ( is_admin() && ! wp_doing_ajax() ) || '' === $html || $product->is_type( 'variable' ) ) {
		return $html;
	}
	$unit = foodify_unit_price( (float) wc_get_price_to_display( $product ), (string) $product->get_meta( '_foodify_net_quantity' ) );
	return null === $unit ? $html : $html . '<span class="fd-unit-price">' . esc_html( $unit ) . '</span>';
}, 11, 2 );

/**
 * Honest scarcity only. The audited site displayed a hardcoded "70 people are viewing
 * this right now" on every product. This shows a stock count only when the number is
 * real and genuinely low.
 */
add_filter( 'woocommerce_get_availability_text', static function ( string $text, WC_Product $product ): string {
	if ( ! $product->is_in_stock() ) {
		return __( 'Sold out', 'foodify' );   // one word for it, everywhere (decision 6)
	}
	if ( ! $product->managing_stock() ) {
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

/**
 * Whole rupees are printed whole: ₹185, not ₹185.00. Every screen the client
 * signed off shows them that way, and the first real WooCommerce render
 * (13 Nov 2026) showed ".00" on every card, line and total — the prototype
 * formats prices itself and never ran wc_price(). The store still keeps two
 * decimals (bootstrap.sh), so ₹92.50 stays ₹92.50; only a zero fraction goes.
 */
add_filter( 'woocommerce_price_trim_zeros', '__return_true' );

/**
 * WooCommerce's round "Sale!" flash over the product photograph is not this
 * design's: the saving is the SAVE badge beside the price, shown only from 15%
 * (foodify_save_badge). The prototype never drew the flash because it does not
 * run WooCommerce's gallery; the real page did.
 */
add_filter( 'woocommerce_sale_flash', '__return_empty_string' );
