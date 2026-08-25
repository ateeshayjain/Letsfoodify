<?php
/**
 * The two shortcodes the block patterns reference.
 *
 *   [foodify_free_shipping_progress]   cart progress toward free shipping
 *   [foodify_google_reviews limit=3]   reviews from the Google Business Profile
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* =============================================================================
 * 1 · Free-shipping progress
 *
 * Principle 03: no surprises after the cart. A progress bar that disagrees with
 * what checkout actually charges is worse than no progress bar — it makes a
 * promise the last screen breaks, which is the exact failure the audit found.
 *
 * So the threshold is READ FROM the free-shipping method that would actually
 * apply, never from a constant. Two details decide whether it tells the truth:
 *
 *   - The customer's shipping ZONE decides which method applies. A single
 *     hardcoded number is wrong the moment there is more than one zone.
 *   - WooCommerce's free-shipping `min_amount` is compared against the subtotal
 *     BEFORE discounts unless `ignore_discounts` is 'no'. Get that backwards and
 *     a coupon either falsely qualifies the customer or falsely un-qualifies
 *     them, and they find out at the payment step.
 * ========================================================================== */

/**
 * Pure progress arithmetic. No WordPress, no WooCommerce — so it can be tested.
 *
 * @param float      $subtotal  Cart amount to compare, already discount-adjusted per settings.
 * @param float|null $threshold Free-shipping minimum, or null when none applies.
 * @return array{applicable:bool,qualified:bool,remaining:float,percent:float}
 */
function foodify_shipping_progress_state( float $subtotal, ?float $threshold ): array {
	if ( null === $threshold || $threshold <= 0 ) {
		// No free-shipping method, or one with no minimum — nothing to progress toward.
		return [ 'applicable' => false, 'qualified' => false, 'remaining' => 0.0, 'percent' => 0.0 ];
	}

	$subtotal = max( 0.0, $subtotal );

	if ( $subtotal >= $threshold ) {
		return [ 'applicable' => true, 'qualified' => true, 'remaining' => 0.0, 'percent' => 100.0 ];
	}

	return [
		'applicable' => true,
		'qualified'  => false,
		'remaining'  => round( $threshold - $subtotal, 2 ),
		'percent'    => round( ( $subtotal / $threshold ) * 100, 2 ),
	];
}

/**
 * The free-shipping minimum that would actually apply to this cart, or null.
 *
 * Walks the customer's own shipping zone rather than assuming zone 0, and
 * returns the LOWEST qualifying minimum when a zone offers several — that is the
 * one the customer will hit first, so it is the honest one to show.
 */
function foodify_free_shipping_threshold(): ?float {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return null;
	}

	$package = [
		'destination' => [
			'country'  => WC()->customer ? WC()->customer->get_shipping_country() : 'IN',
			'state'    => WC()->customer ? WC()->customer->get_shipping_state() : '',
			'postcode' => WC()->customer ? WC()->customer->get_shipping_postcode() : '',
		],
	];

	$zone    = function_exists( 'wc_get_shipping_zone' ) ? wc_get_shipping_zone( $package ) : null;
	$methods = $zone ? $zone->get_shipping_methods( true ) : [];

	$lowest = null;
	foreach ( $methods as $method ) {
		if ( 'free_shipping' !== $method->id ) {
			continue;
		}
		// 'min_amount' only means anything for these two requirement types.
		$requires = $method->get_option( 'requires' );
		if ( ! in_array( $requires, [ 'min_amount', 'either', 'both' ], true ) ) {
			continue;
		}
		$min = (float) $method->get_option( 'min_amount', 0 );
		if ( $min > 0 && ( null === $lowest || $min < $lowest ) ) {
			$lowest = $min;
		}
	}

	return $lowest;
}

/**
 * The cart figure WooCommerce will actually compare against `min_amount`.
 *
 * Mirrors WC_Shipping_Free_Shipping::is_available(): the subtotal, minus
 * discounts unless the method is set to ignore them. Reimplementing this
 * incorrectly is how the bar and the checkout come to disagree.
 */
function foodify_shipping_comparison_subtotal(): float {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0.0;
	}
	$cart     = WC()->cart;
	$subtotal = (float) $cart->get_displayed_subtotal();

	if ( $cart->display_prices_including_tax() ) {
		$subtotal = round( $subtotal - ( (float) $cart->get_discount_total() + (float) $cart->get_discount_tax() ), wc_get_price_decimals() );
	} else {
		$subtotal = round( $subtotal - (float) $cart->get_discount_total(), wc_get_price_decimals() );
	}

	return (float) $subtotal;
}

/** Render. Returns '' when there is nothing honest to say. */
function foodify_render_shipping_progress(): string {
	$threshold = foodify_free_shipping_threshold();
	$state     = foodify_shipping_progress_state( foodify_shipping_comparison_subtotal(), $threshold );

	if ( ! $state['applicable'] ) {
		return '';
	}

	if ( $state['qualified'] ) {
		$message = '<strong>' . esc_html__( 'Free shipping unlocked.', 'foodify' ) . '</strong> '
			. esc_html__( 'Delivery is on us.', 'foodify' );
	} else {
		$message = sprintf(
			/* translators: %s: formatted rupee amount still needed */
			esc_html__( '%s away from free shipping.', 'foodify' ),
			'<strong>' . wp_kses_post( wc_price( $state['remaining'] ) ) . '</strong>'
		);
	}

	return sprintf(
		'<div class="fd-shipping-progress" data-foodify-shipping-progress>'
			. '<p class="fd-ship">%1$s</p>'
			. '<div class="fd-progress" role="progressbar" aria-valuenow="%2$d" aria-valuemin="0" aria-valuemax="100" aria-label="%3$s">'
			. '<i style="width:%2$s%%"></i></div></div>',
		$message,
		esc_attr( (string) $state['percent'] ),
		esc_attr__( 'Progress toward free shipping', 'foodify' )
	);
}

add_shortcode( 'foodify_free_shipping_progress', static function (): string {
	return foodify_render_shipping_progress();
} );

/**
 * Keep it truthful after an AJAX cart update.
 *
 * The cart page replaces `.woocommerce-cart-form` and `.cart_totals` on quantity
 * change. This block sits outside both, so without a fragment it would keep
 * showing the pre-update figure — a stale promise, which is the failure mode
 * this whole component exists to avoid.
 */
add_filter( 'woocommerce_add_to_cart_fragments', static function ( array $fragments ): array {
	$html = foodify_render_shipping_progress();
	if ( '' !== $html ) {
		$fragments['[data-foodify-shipping-progress]'] = $html;
	}
	return $fragments;
} );

/* =============================================================================
 * 2 · Google Business Profile reviews
 *
 * WP-08: "Google review widget on homepage and product pages." This replaces
 * three testimonials attributed to the same name, so the whole point is that it
 * shows real reviews or nothing at all. It never falls back to sample content.
 *
 * Fetched SERVER-SIDE and cached, deliberately:
 *   - Places API is billed per request. An uncached widget bills once per
 *     pageview, which is a bill that grows with the traffic the SEO work is
 *     meant to create.
 *   - No third-party script runs on the customer's browser, so no customer data
 *     reaches Google from this component and there is nothing to disclose.
 *
 * The API key is read from a constant first so it can live in wp-config.php and
 * stay out of database backups, which get copied between environments.
 * ========================================================================== */

const FOODIFY_REVIEWS_TRANSIENT = 'foodify_google_reviews';
const FOODIFY_REVIEWS_TTL       = 12 * HOUR_IN_SECONDS;

function foodify_google_places_key(): string {
	if ( defined( 'FOODIFY_GOOGLE_PLACES_KEY' ) && FOODIFY_GOOGLE_PLACES_KEY ) {
		return (string) FOODIFY_GOOGLE_PLACES_KEY;
	}
	return (string) get_option( 'foodify_google_places_key', '' );
}

function foodify_google_place_id(): string {
	if ( defined( 'FOODIFY_GOOGLE_PLACE_ID' ) && FOODIFY_GOOGLE_PLACE_ID ) {
		return (string) FOODIFY_GOOGLE_PLACE_ID;
	}
	return (string) get_option( 'foodify_google_place_id', '' );
}

/**
 * Normalise one Places review into the fields the template needs.
 *
 * Pure, so the shape can be tested without the network.
 *
 * @return array{author:string,rating:int,text:string,relative:string,url:string}|null
 */
function foodify_normalise_review( array $r ): ?array {
	$text   = trim( (string) ( $r['text'] ?? '' ) );
	$author = trim( (string) ( $r['author_name'] ?? '' ) );
	$rating = (int) ( $r['rating'] ?? 0 );

	// A review with no words is a star click. It proves nothing on a page whose
	// job is showing what people said, so it is dropped rather than padded out.
	if ( '' === $text || '' === $author || $rating < 1 || $rating > 5 ) {
		return null;
	}

	return [
		'author'   => $author,
		'rating'   => $rating,
		'text'     => $text,
		'relative' => (string) ( $r['relative_time_description'] ?? '' ),
		'url'      => (string) ( $r['author_url'] ?? '' ),
	];
}

/**
 * Fetch and cache. Returns [] on any failure — never an error, never a fallback.
 *
 * @return array<int, array{author:string,rating:int,text:string,relative:string,url:string}>
 */
function foodify_fetch_google_reviews(): array {
	$cached = get_transient( FOODIFY_REVIEWS_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$key      = foodify_google_places_key();
	$place_id = foodify_google_place_id();
	if ( '' === $key || '' === $place_id ) {
		return [];
	}

	$response = wp_remote_get(
		add_query_arg(
			[
				'place_id' => $place_id,
				'fields'   => 'review,rating,user_ratings_total',
				'reviews_sort' => 'newest',
				'key'      => $key,
			],
			'https://maps.googleapis.com/maps/api/place/details/json'
		),
		[ 'timeout' => 8 ]
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		// Cache the failure briefly so a broken key does not retry on every view.
		set_transient( FOODIFY_REVIEWS_TRANSIENT, [], 15 * MINUTE_IN_SECONDS );
		return [];
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || 'OK' !== ( $body['status'] ?? '' ) ) {
		set_transient( FOODIFY_REVIEWS_TRANSIENT, [], 15 * MINUTE_IN_SECONDS );
		return [];
	}

	$reviews = [];
	foreach ( (array) ( $body['result']['reviews'] ?? [] ) as $raw ) {
		$clean = is_array( $raw ) ? foodify_normalise_review( $raw ) : null;
		if ( $clean ) {
			$reviews[] = $clean;
		}
	}

	set_transient( FOODIFY_REVIEWS_TRANSIENT, $reviews, FOODIFY_REVIEWS_TTL );
	return $reviews;
}

/** Clear the cache by hand after replying to a review, or on demand. */
add_action( 'foodify_flush_google_reviews', static function (): void {
	delete_transient( FOODIFY_REVIEWS_TRANSIENT );
} );

add_shortcode( 'foodify_google_reviews', static function ( $atts ): string {
	$atts  = shortcode_atts( [ 'limit' => 3 ], (array) $atts, 'foodify_google_reviews' );
	$limit = max( 1, min( 5, (int) $atts['limit'] ) );   // Places returns at most five

	$reviews = array_slice( foodify_fetch_google_reviews(), 0, $limit );

	// Nothing real to show: render nothing. The pattern's heading stands alone,
	// which is honest. Inventing filler here is the thing being removed.
	if ( ! $reviews ) {
		return '';
	}

	$cards = '';
	foreach ( $reviews as $r ) {
		$stars = str_repeat( '★', $r['rating'] ) . str_repeat( '☆', 5 - $r['rating'] );
		$who   = esc_html( $r['author'] );
		if ( '' !== $r['url'] ) {
			$who = sprintf( '<a href="%s" rel="nofollow noopener" target="_blank">%s</a>', esc_url( $r['url'] ), $who );
		}
		$cards .= sprintf(
			'<figure class="fd-review"><blockquote>%1$s</blockquote>'
				. '<figcaption><span class="fd-stars" aria-label="%2$s">%3$s</span> · %4$s%5$s · '
				. '<span class="fd-verified">%6$s</span></figcaption></figure>',
			esc_html( $r['text'] ),
			esc_attr( sprintf( /* translators: %d: star rating out of five */ __( '%d out of 5', 'foodify' ), $r['rating'] ) ),
			esc_html( $stars ),
			$who,
			$r['relative'] ? ' · ' . esc_html( $r['relative'] ) : '',
			esc_html__( 'Google review', 'foodify' )
		);
	}

	return '<div class="fd-reviews">' . $cards . '</div>';
} );
