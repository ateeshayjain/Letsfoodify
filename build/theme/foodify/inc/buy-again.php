<?php
/**
 * 7: buy again on the home page.
 *
 * A signed-in customer with past orders sees up to four things they have bought
 * before, most recent first, each with one-tap Add to cart. Guests and
 * customers with no orders see nothing — not an empty shelf, not a prompt.
 *
 * THE CACHE IS THE DANGEROUS PART
 * -------------------------------
 * The home page is page-cached for guests (WP-04). A section that lists ONE
 * customer's purchases, rendered into a page the cache then stores, would be
 * served to every visitor after them: a privacy leak that looks like a working
 * feature. Three guards, any one of which is enough on its own:
 *
 *   1. It renders only for a logged-in user — `is_user_logged_in()` inside the
 *      shortcode — so a cache primed by a guest never contains it.
 *   2. A logged-in front-page response is marked private before anything is
 *      printed: `Cache-Control: no-store, private`, DONOTCACHEPAGE, and the
 *      same `X-Foodify-Private: 1` marker checkout-flow.php sends, so the
 *      smoke test can assert it. Most hosts already bypass the page cache on
 *      the wordpress_logged_in_ cookie; this does not depend on it.
 *   3. Nothing about the customer is put into JavaScript or any shared store.
 *
 * WHAT COUNTS AS "BOUGHT"
 * -----------------------
 * Completed (it has left the kitchen — see WP-10's order emails) and
 * processing (paid, being packed). Not pending, not failed, not cancelled or
 * refunded: those are things the customer did not end up receiving. A product
 * is skipped if it is no longer purchasable — unpublished, sold out, or not a
 * simple product a single tap can add — rather than shown with a dead button.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/account-actions-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/** Order statuses whose items count as bought. Without the wc- prefix. */
function foodify_buy_again_statuses(): array {
	return [ 'completed', 'processing' ];
}

/**
 * Which products to offer, in order.
 *
 * @param array<int,array{id?:int,status:string,date:int,product_ids:array<int,int>}> $orders
 *        Plain arrays. `date` is a unix time; `product_ids` in line-item order.
 * @param array<int,int> $purchasable Product ids that can be added to the cart right now.
 * @return array<int,int>
 */
function foodify_buy_again_ids( array $orders, array $purchasable, int $limit = 4 ): array {
	if ( $limit < 1 ) {
		return [];
	}
	$ok      = array_flip( array_map( 'intval', $purchasable ) );
	$allowed = foodify_buy_again_statuses();

	$orders = array_values( array_filter( $orders, static function ( $o ) use ( $allowed ): bool {
		return is_array( $o ) && in_array( preg_replace( '/^wc-/', '', (string) ( $o['status'] ?? '' ) ), $allowed, true );
	} ) );
	// Most recent first; two orders in the same second fall back to the higher id.
	usort( $orders, static fn( array $a, array $b ): int =>
		[ (int) ( $b['date'] ?? 0 ), (int) ( $b['id'] ?? 0 ) ] <=> [ (int) ( $a['date'] ?? 0 ), (int) ( $a['id'] ?? 0 ) ] );

	$out = [];
	foreach ( $orders as $o ) {
		foreach ( (array) ( $o['product_ids'] ?? [] ) as $pid ) {
			$pid = (int) $pid;
			if ( $pid < 1 || isset( $out[ $pid ] ) || ! isset( $ok[ $pid ] ) ) {
				continue;
			}
			$out[ $pid ] = true;
			if ( count( $out ) >= $limit ) {
				return array_keys( $out );
			}
		}
	}
	return array_keys( $out );
}

/**
 * Must this front-page response be kept out of every shared cache?
 * Any logged-in view — the decision is made before the page knows whether the
 * shelf will have anything on it, and a wrong "no" here is a privacy leak.
 */
function foodify_buy_again_private_response( bool $front_page, bool $logged_in ): bool {
	return $front_page && $logged_in;
}

/**
 * The shelf.
 *
 * Each card carries what the grid cards carry (review BROWSE-7 / ACCOUNT-20):
 * the veg mark before the name and the prep line ("Hot water · 6 min · Serves
 * 2") — principle 1, six minutes is the product — built by the SAME helpers
 * the grid uses (product-spec.php), so the two can never disagree. Its button
 * is the grid's too: Add, then − 1 + once the product is in the cart
 * (cart-stepper.php, which reads the cart and announces every change).
 *
 * @param array<int,array{id:int,name:string,url:string,price:string,img?:string,add_url:string,
 *              chip?:?array{label:string,modifier:string},servings?:int,veg?:bool}> $products
 *        `chip` is foodify_prep_chip_parts(); `veg` is foodify_diet_is_veg() of the declared diet.
 */
function foodify_buy_again_html( array $products ): string {
	if ( ! $products ) {
		return '';
	}
	$e     = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	$items = '';
	foreach ( $products as $p ) {
		$name = (string) ( $p['name'] ?? '' );
		$img  = (string) ( $p['img'] ?? '' );
		$pic  = '' !== $img
			? '<img class="fd-again__img" src="' . $e( $img ) . '" alt="" width="96" height="96" loading="lazy" decoding="async">'
			: '<span class="fd-again__img is-empty" aria-hidden="true"></span>';
		$veg  = ! empty( $p['veg'] ) && function_exists( 'foodify_veg_mark_html' ) ? foodify_veg_mark_html( 'vegetarian' ) : '';
		$prep = ! empty( $p['chip'] ) && is_array( $p['chip'] ) && function_exists( 'foodify_card_prep_html' )
			? foodify_card_prep_html( $p['chip'], 0, (string) ( $p['pack'] ?? '' ) )
			: '';
		$id   = (int) ( $p['id'] ?? 0 );
		$add  = (string) ( $p['add_url'] ?? '' );
		$ctl  = function_exists( 'foodify_cart_control_html' ) && $id > 0
			? foodify_cart_control_html( $id, $name, $add, 0, 'fd-again__ctl' )
			: '<a class="wp-element-button fd-again__add" href="' . $e( $add ) . '" rel="nofollow" aria-label="' . $e( 'Add ' . $name . ' to cart' ) . '">Add to cart</a>';
		$items .= '<li class="fd-again__item">'
			. '<a class="fd-again__link" href="' . $e( (string) ( $p['url'] ?? '' ) ) . '">' . $pic
			. '<span class="fd-again__name">' . $veg . $e( $name ) . '</span></a>'
			. $prep
			. '<span class="fd-again__price">' . $e( (string) ( $p['price'] ?? '' ) ) . '</span>'
			. $ctl
			. '</li>';
	}
	return '<section class="fd-again alignwide" aria-labelledby="fd-again-title" data-fd-again>'
		. '<h2 class="fd-again__title" id="fd-again-title">Buy again</h2>'
		. '<p class="fd-again__lede">From your recent orders.</p>'
		. '<ul class="fd-again__list">' . $items . '</ul>'
		. '</section>';
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** The shelf's products for one customer, ready for foodify_buy_again_html(). */
function foodify_buy_again_products( int $user_id ): array {
	if ( $user_id < 1 || ! function_exists( 'wc_get_orders' ) ) {
		return [];
	}
	$orders = (array) wc_get_orders( [
		'customer_id' => $user_id,
		'status'      => array_map( static fn( string $s ): string => 'wc-' . $s, foodify_buy_again_statuses() ),
		'limit'       => 10,   // enough to fill four slots from people who order the same thing
		'orderby'     => 'date',
		'order'       => 'DESC',
		'return'      => 'objects',
	] );

	$plain = [];
	$seen  = [];
	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		$ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && $item->get_product_id() ) {
				$ids[]                              = (int) $item->get_product_id();
				$seen[ (int) $item->get_product_id() ] = true;
			}
		}
		$created = $order->get_date_created();
		$plain[] = [
			'id'          => $order->get_id(),
			'status'      => $order->get_status(),
			'date'        => $created ? $created->getTimestamp() : 0,
			'product_ids' => $ids,
		];
	}
	if ( ! $seen ) {
		return [];
	}

	$by_id       = [];
	$purchasable = [];
	foreach ( (array) wc_get_products( [ 'include' => array_keys( $seen ), 'limit' => count( $seen ), 'status' => 'publish', 'return' => 'objects' ] ) as $p ) {
		if ( $p instanceof WC_Product && $p->is_type( 'simple' ) && $p->is_purchasable() && $p->is_in_stock() && 'hidden' !== $p->get_catalog_visibility() ) {
			$by_id[ $p->get_id() ] = $p;
			$purchasable[]         = $p->get_id();
		}
	}


	$out = [];
	foreach ( foodify_buy_again_ids( $plain, $purchasable, 4 ) as $id ) {
		$p     = $by_id[ $id ];
		$image = $p->get_image_id() ? wp_get_attachment_image_url( (int) $p->get_image_id(), 'woocommerce_thumbnail' ) : '';
		$out[] = [
			'id'       => $id,
			'name'     => wp_strip_all_tags( $p->get_name() ),
			'url'      => (string) get_permalink( $id ),
			'price'    => html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $p ) ) ), ENT_QUOTES, 'UTF-8' ),
			'img'      => is_string( $image ) ? $image : '',
			'add_url'  => home_url( '/?add-to-cart=' . $id ),
			// What the grid card shows, from the grid's own helpers (BROWSE-7).
			'chip'     => function_exists( 'foodify_prep_chip_parts' ) ? foodify_prep_chip_parts( $p ) : null,
			'servings' => function_exists( 'foodify_combo_data' ) && null !== foodify_combo_data( $p ) ? 0 : (int) $p->get_meta( '_foodify_servings' ),
			'pack'     => (string) $p->get_meta( '_foodify_net_quantity' ),
			'veg'      => function_exists( 'foodify_product_diet' ) && function_exists( 'foodify_diet_is_veg' ) && foodify_diet_is_veg( foodify_product_diet( $p ) ),
		];
	}
	return $out;
}

/** Guard 2: a logged-in front page is private, decided before any byte is sent. */
add_action( 'template_redirect', static function (): void {
	if ( ! foodify_buy_again_private_response( is_front_page(), is_user_logged_in() ) ) {
		return;
	}
	foreach ( [ 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB' ] as $flag ) {
		if ( ! defined( $flag ) ) {
			define( $flag, true );
		}
	}
	if ( headers_sent() ) {
		return;
	}
	nocache_headers();
	header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, private', true );
	header( 'X-Foodify-Private: 1', true );
}, 0 );

/** [foodify_buy_again] — templates/front-page.html. Guard 1: logged-in only. */
add_shortcode( 'foodify_buy_again', static function (): string {
	if ( ! is_user_logged_in() || ! is_front_page() ) {
		return '';
	}
	$products = foodify_buy_again_products( get_current_user_id() );
	if ( ! $products ) {
		return '';
	}
	return foodify_buy_again_html( $products );
} );
