<?php
/**
 * WP-08 — product reviews, and the flow that collects them.
 *
 * Scope §5 separates three things clients call "connected to Google reviews":
 *
 *   1. Show the Business Profile's reviews on the site — DONE, inc/shortcodes.php.
 *   2. Star ratings in Google's own search results, from PRODUCT reviews marked
 *      up with AggregateRating. "The SEO-valuable one… Recommend this gets
 *      built." This file.
 *   3. Google Customer Reviews / seller ratings — parked until Merchant Center
 *      has volume.
 *
 * (2) is not a widget. Schema without reviews emits nothing, so the deliverable
 * is the COLLECTION FLOW: a post-delivery ask that actually produces reviews.
 *
 * THE HONESTY CONSTRAINT THIS INHERITS
 * ------------------------------------
 * The audit found a fabricated "70 people are viewing this right now" counter on
 * the product page. Reviews are the same surface and a worse place to invent:
 * a rating that overstates what customers actually said is the kind of thing
 * Google issues manual actions for, and it is the whole reason the schema is
 * worth anything. So nothing here manufactures, pads, or rounds up — and the
 * star rating in listings now carries its COUNT, because "★★★★★" from one
 * review reads as established and is not.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/reviews-test.php without WordPress.
 * ---------------------------------------------------------------------- */

function foodify_review_request_defaults(): array {
	return [
		'enabled'           => true,
		'delay_days'        => 5,    // parcel has arrived and been eaten
		'max_age_days'      => 45,   // never ask about an order this old
		'customer_cooldown' => 30,   // days between asks to one person
	];
}

/**
 * Should this order be asked for a review, right now?
 *
 * SCHEDULING IS NOT DECIDING. The event is queued when the order completes and
 * fires five days later, and things change in five days — a refund lands, the
 * customer opts out, cron backs up and fires a month late. So this runs BOTH
 * times, and the firing is the one that counts.
 *
 * @param array{completed_at:int,asked_at:?int,email:string,refunded:bool,
 *              cancelled:bool,opted_out:bool,has_reviewable_items:bool,
 *              customer_last_asked:?int} $o
 * @return array{send:bool,reason:string,due_at:int}
 */
function foodify_review_request_state( array $o, int $now, array $cfg = [] ): array {
	$cfg    = array_merge( foodify_review_request_defaults(), $cfg );
	$done   = (int) ( $o['completed_at'] ?? 0 );
	$due_at = $done > 0 ? $done + ( (int) $cfg['delay_days'] * DAY_IN_SECONDS ) : 0;

	$no = static fn( string $why ): array => [ 'send' => false, 'reason' => $why, 'due_at' => 0 ];

	if ( empty( $cfg['enabled'] ) ) {
		return $no( 'disabled' );
	}
	if ( $done <= 0 ) {
		return $no( 'not_completed' );
	}

	// Permanent reasons first. A refunded order that is also "not due yet"
	// should report the refund — reporting the timing implies it will be asked
	// later, and it never will.
	if ( ! empty( $o['cancelled'] ) ) {
		return $no( 'cancelled' );
	}
	if ( ! empty( $o['refunded'] ) ) {
		return $no( 'refunded' );
	}
	if ( '' === trim( (string) ( $o['email'] ?? '' ) ) ) {
		return $no( 'no_email' );
	}
	if ( ! empty( $o['opted_out'] ) ) {
		return $no( 'opted_out' );
	}
	if ( empty( $o['has_reviewable_items'] ) ) {
		return $no( 'nothing_to_review' );
	}
	if ( null !== ( $o['asked_at'] ?? null ) ) {
		return $no( 'already_asked' );
	}

	// Cron can fire very late — a site down for a fortnight queues everything and
	// releases it at once. Asking about a meal someone ate two months ago reads
	// as a broken system, not a nudge.
	if ( $now > $done + ( (int) $cfg['max_age_days'] * DAY_IN_SECONDS ) ) {
		return $no( 'too_old' );
	}

	$last = $o['customer_last_asked'] ?? null;
	if ( null !== $last && $now < (int) $last + ( (int) $cfg['customer_cooldown'] * DAY_IN_SECONDS ) ) {
		return $no( 'customer_cooldown' );
	}

	if ( $now < $due_at ) {
		return [ 'send' => false, 'reason' => 'not_due_yet', 'due_at' => $due_at ];
	}

	return [ 'send' => true, 'reason' => 'ok', 'due_at' => $due_at ];
}

/**
 * How a rating may be described in words.
 *
 * WooCommerce renders the average and nothing else, so a single five-star review
 * shows as "★★★★★" — indistinguishable from two hundred of them. That is not a
 * lie exactly, but it is what the fake viewer counter was: a signal engineered to
 * read stronger than the evidence behind it.
 *
 * @return array{show:bool,stars:float,count:int,label:string}
 */
function foodify_rating_display( float $average, int $count ): array {
	if ( $count < 1 || $average <= 0.0 ) {
		return [ 'show' => false, 'stars' => 0.0, 'count' => 0, 'label' => '' ];
	}
	return [
		'show'  => true,
		'stars' => round( $average, 1 ),
		'count' => $count,
		'label' => 1 === $count
			? '1 review'
			: sprintf( '%d reviews', $count ),
	];
}

/**
 * A card's rating in one short line: "★ 4.7 (84)" (client, 9 Nov 2026 — the
 * card was five stars, the average, and "84 reviews" on a line of its own).
 * One star is a mark, not a score; the average beside it is the score, and
 * the count is never dropped (WP-08: a 5.0 from one review must say so).
 * A screen reader hears the whole sentence instead of the symbols.
 * Nothing at all for a product with no reviews.
 */
function foodify_card_rating_html( float $average, int $count ): string {
	$d = foodify_rating_display( $average, $count );
	if ( ! $d['show'] ) {
		return '';
	}
	$avg = number_format( (float) $d['stars'], 1 );
	return '<div class="wc-block-components-product-rating fd-card-rating">'
		. '<span aria-hidden="true"><span class="fd-card-rating__star">★</span> ' . $avg
		. ' <span class="fd-card-rating__count">(' . (int) $d['count'] . ')</span></span>'
		. '<span class="fd-sr">Rated ' . $avg . ' out of 5 from ' . htmlspecialchars( $d['label'], ENT_QUOTES ) . '</span></div>';
}

/**
 * Five glyphs for an average — ONE rounding rule for the whole product page
 * (UX review 8 Nov 2026, PRODUCT-15). The title said ★★★★☆ for 4.7 and the
 * summary above the reviews said ★★★★★. The rule is FLOOR, the same stance as
 * every other number here: a 4.7 is shown as four full stars and "4.7", never
 * rounded up into a fifth star it did not earn.
 */
function foodify_star_glyphs( float $average ): string {
	$full = (int) floor( max( 0.0, min( 5.0, $average ) ) + 1e-9 );
	return str_repeat( '★', $full ) . str_repeat( '☆', 5 - $full );
}

/**
 * The glyphs for the eye, the number for a reader: the stars are aria-hidden
 * and the text says "Rated 4.7 out of 5".
 */
function foodify_stars_html( float $average ): string {
	return '<span class="fd-stars" aria-hidden="true">' . foodify_star_glyphs( $average ) . '</span>'
		. '<span class="fd-sr">Rated ' . number_format( round( $average, 1 ), 1 ) . ' out of 5</span>';
}

/**
 * The rating row under the product page's title: stars, the average, and the
 * count as a link to the reviews. Nothing at zero reviews (product-display.php
 * hides the row then too — no empty stars).
 */
function foodify_pdp_rating_html( float $average, int $count ): string {
	$d = foodify_rating_display( $average, $count );
	if ( ! $d['show'] ) {
		return '';
	}
	$word = 1 === $count ? 'review' : 'reviews';
	return '<div class="fd-rating">' . foodify_stars_html( $d['stars'] )
		. ' <span class="fd-rating__score" aria-hidden="true">' . number_format( $d['stars'], 1 ) . '</span>'
		. ' <a href="#reviews" class="woocommerce-review-link" rel="nofollow">(' . $count . ' ' . $word . ')</a></div>';
}

/* -------------------------------------------------------------------------
 * 11 · The star histogram and the filter — pure.
 *
 * Same honesty rule as foodify_rating_display(): the average is printed with
 * the distribution behind it, so "4.4" from forty-six reviews and "4.4" from
 * five read differently, and the one-star reviews are a tap away rather than
 * buried under the praise. Nothing is hidden by default; the filter only ever
 * narrows what the customer asked to narrow, and says how many it hid.
 * ---------------------------------------------------------------------- */

/**
 * Counts per star, the average to one decimal, and the total.
 *
 * Only whole ratings 1–5 count: comment meta arrives as strings, and a "0"
 * (a review left before ratings were required) or a "4.5" (typed by hand into
 * the database) is not a star rating and must not drag the average.
 *
 * @param array<int,mixed> $ratings
 * @return array{counts:array<int,int>,total:int,average:float,percents:array<int,int>}
 *         counts and percents keyed 5,4,3,2,1 in that order.
 */
function foodify_review_histogram( array $ratings ): array {
	$counts = [ 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 ];
	$sum    = 0;
	foreach ( $ratings as $r ) {
		if ( is_int( $r ) ) {
			$n = $r;
		} elseif ( is_string( $r ) && preg_match( '/^\s*[1-5]\s*$/', $r ) ) {
			$n = (int) $r;
		} elseif ( is_float( $r ) && floor( $r ) === $r ) {
			$n = (int) $r;
		} else {
			continue;
		}
		if ( $n < 1 || $n > 5 ) {
			continue;
		}
		$counts[ $n ]++;
		$sum += $n;
	}
	$total    = array_sum( $counts );
	$percents = [];
	foreach ( $counts as $star => $c ) {
		$percents[ $star ] = $total > 0 ? (int) round( $c / $total * 100 ) : 0;
	}
	return [
		'counts'   => $counts,
		'total'    => $total,
		'average'  => $total > 0 ? round( $sum / $total, 1 ) : 0.0,
		'percents' => $percents,
	];
}

/**
 * The filter chips: one per star that has reviews, then "With photos".
 * A chip that would filter to nothing is left out — it is a dead control.
 *
 * @return array<int,array{key:string,label:string,count:int,aria:string}>
 */
function foodify_review_filter_chips( array $hist, int $with_photos ): array {
	$chips = [];
	foreach ( (array) ( $hist['counts'] ?? [] ) as $star => $n ) {
		$n = (int) $n;
		if ( $n < 1 ) {
			continue;
		}
		$chips[] = [
			'key'   => (string) $star,
			'label' => sprintf( '%d★ (%d)', $star, $n ),
			'count' => $n,
			'aria'  => sprintf( '%d %s, %d %s', $star, 1 === (int) $star ? 'star' : 'stars', $n, 1 === $n ? 'review' : 'reviews' ),
		];
	}
	if ( $with_photos > 0 ) {
		$chips[] = [
			'key'   => 'photos',
			'label' => sprintf( 'With photos (%d)', $with_photos ),
			'count' => $with_photos,
			'aria'  => sprintf( 'With photos, %d %s', $with_photos, 1 === $with_photos ? 'review' : 'reviews' ),
		];
	}
	return $chips;
}

/**
 * The line under the chips. The script mirrors it word for word.
 *
 * WooCommerce may page reviews. When only some are on the page the filter can
 * only filter those, and saying "Showing 3 of 46" would claim three matches in
 * all forty-six — so the line says what it actually looked at.
 *
 * @param int $shown  Reviews visible after the filter.
 * @param int $loaded Reviews on this page.
 * @param int $total  Reviews in all (the histogram's total).
 */
function foodify_review_count_line( int $shown, int $loaded, int $total ): string {
	$total = max( $total, $loaded );
	if ( $total < 1 ) {
		return '';
	}
	$word = static fn( int $n ): string => 1 === $n ? 'review' : 'reviews';
	if ( $loaded >= $total ) {
		if ( $shown >= $total ) {
			return 1 === $total ? 'Showing 1 review' : sprintf( 'Showing all %d reviews', $total );
		}
		return sprintf( 'Showing %d of %d %s', $shown, $total, $word( $total ) );
	}
	if ( $shown >= $loaded ) {
		return sprintf( 'Showing %d of %d %s', $loaded, $total, $word( $total ) );
	}
	return sprintf( 'Showing %d of the %d reviews on this page (%d in all)', $shown, $loaded, $total );
}

/**
 * The filter's group label (PRODUCT-6). When WooCommerce pages the reviews the
 * chips can only filter the ones on this page, so they say so — "Filter the 5
 * reviews shown" — and the script recounts each chip among those five. The
 * histogram above keeps the totals. The script mirrors this word for word.
 */
function foodify_review_filter_label( int $loaded, int $total ): string {
	return $loaded > 0 && $loaded < $total
		? sprintf( 'Filter the %d %s shown', $loaded, 1 === $loaded ? 'review' : 'reviews' )
		: 'Filter reviews';
}

/**
 * The block above the review list: the average, the 5→1 histogram, and the
 * filter. The filter ships `hidden` — without the script its buttons would do
 * nothing, so without the script they do not exist, and every review shows.
 */
function foodify_review_summary_html( array $hist, int $with_photos ): string {
	$total = (int) ( $hist['total'] ?? 0 );
	if ( $total < 1 ) {
		return '';
	}
	$avg   = (float) ( $hist['average'] ?? 0 );
	$stars = foodify_star_glyphs( $avg );   // the title's rule, not round()
	$word  = 1 === $total ? 'review' : 'reviews';

	$rows = '';
	foreach ( (array) $hist['counts'] as $star => $n ) {
		$pct   = (int) ( $hist['percents'][ $star ] ?? 0 );
		$rows .= sprintf(
			'<li class="fd-rsum__row"><span class="fd-rsum__star" aria-hidden="true">%1$d★</span>'
			. '<span class="fd-rsum__bar" aria-hidden="true"><i style="width:%2$d%%"></i></span>'
			. '<span class="fd-rsum__n" aria-hidden="true">%3$d</span>'
			. '<span class="fd-sr">%1$d %4$s: %3$d %5$s</span></li>',
			(int) $star, $pct, (int) $n, 1 === (int) $star ? 'star' : 'stars', 1 === (int) $n ? 'review' : 'reviews'
		);
	}

	$chips = '';
	foreach ( foodify_review_filter_chips( $hist, $with_photos ) as $c ) {
		$chips .= sprintf(
			'<button type="button" class="fd-rsum__chip" data-fd-filter="%1$s" aria-pressed="false" aria-label="%2$s">%3$s</button>',
			htmlspecialchars( $c['key'], ENT_QUOTES ),
			htmlspecialchars( $c['aria'], ENT_QUOTES ),
			htmlspecialchars( $c['label'], ENT_QUOTES )
		);
	}

	return '<div class="fd-rsum" data-fd-review-summary data-total="' . $total . '">'
		. '<p class="fd-rsum__avg"><span class="fd-rsum__score">' . number_format( $avg, 1 ) . '</span>'
		. ' <span class="fd-stars" aria-hidden="true">' . $stars . '</span>'
		. ' <span class="fd-rsum__basis">out of 5 · ' . $total . ' ' . $word . '</span></p>'
		. '<ul class="fd-rsum__bars" aria-label="How the ' . $total . ' ' . $word . ' are spread">' . $rows . '</ul>'
		. '<div class="fd-rsum__filter" data-fd-review-filter hidden>'
		. '<p class="fd-rsum__label" id="fd-rsum-label" data-fd-filter-label>' . htmlspecialchars( foodify_review_filter_label( $total, $total ), ENT_QUOTES ) . '</p>'
		. '<div class="fd-rsum__chips" role="group" aria-labelledby="fd-rsum-label">' . $chips . '</div>'
		. '<p class="fd-rsum__count" data-fd-review-count aria-live="polite">' . htmlspecialchars( foodify_review_count_line( $total, $total, $total ), ENT_QUOTES ) . '</p>'
		. '<button type="button" class="fd-rsum__clear" data-fd-filter-clear hidden>Clear</button>'
		. '</div></div>';
}

/* -------------------------------------------------------------------------
 * 11 · Review photos — pure.
 *
 * Who: verified buyers only, the same wc_customer_bought_product() test the
 * review itself passes. What: up to three JPEG/PNG/WebP images, 5 MB each,
 * judged by their BYTES (finfo + getimagesize), never by the filename or the
 * browser's claimed type. Then the WordPress half re-encodes every photo,
 * which is what strips EXIF — including GPS, i.e. the customer's home — and
 * a review with photos always waits for a human to approve it.
 * ---------------------------------------------------------------------- */

/** The limits. Engineering bounds, not business values. */
function foodify_review_photo_limits(): array {
	return [
		'max_files'  => 3,
		'max_bytes'  => 5 * 1024 * 1024,
		'mimes'      => [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' ],
		'max_pixels' => 40_000_000,   // a 5 MB PNG can declare a 30,000² canvas; decoding that is the attack
		'max_edge'   => 1600,         // stored size; a phone photo has no business being 4,000px on a product page
		'per_email'  => [ 'max' => 3, 'window' => 86400 ],   // photo reviews per buyer per day
		'per_ip'     => [ 'max' => 6, 'window' => 3600 ],    // and per address per hour
	];
}

/**
 * $_FILES for a multiple file input arrives column-wise
 * (name[0], name[1] … type[0] …). Turn it into one array per file, and drop
 * the empty slot a form sends when nothing was chosen.
 *
 * @param mixed $field
 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function foodify_review_photo_files( $field ): array {
	if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
		return [];
	}
	$cols = [ 'name', 'type', 'tmp_name', 'error', 'size' ];
	if ( ! is_array( $field['name'] ) ) {
		foreach ( $cols as $c ) {
			$field[ $c ] = [ $field[ $c ] ?? '' ];
		}
	}
	$files = [];
	foreach ( array_keys( (array) $field['name'] ) as $i ) {
		$f = [
			'name'     => (string) ( $field['name'][ $i ] ?? '' ),
			'type'     => (string) ( $field['type'][ $i ] ?? '' ),
			'tmp_name' => (string) ( $field['tmp_name'][ $i ] ?? '' ),
			'error'    => (int) ( $field['error'][ $i ] ?? 4 ),
			'size'     => (int) ( $field['size'][ $i ] ?? 0 ),
		];
		if ( 4 === $f['error'] ) {   // UPLOAD_ERR_NO_FILE: the input was left empty
			continue;
		}
		$files[] = $f;
	}
	return $files;
}

/**
 * What the file IS, from its bytes. The browser's `type` and the extension
 * are claims made by whoever sent it; neither is consulted.
 *
 * @return array{mime:string,width:int,height:int}
 */
function foodify_review_photo_sniff( string $path ): array {
	$none = [ 'mime' => '', 'width' => 0, 'height' => 0 ];
	if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
		return $none;
	}
	$mime = '';
	if ( function_exists( 'finfo_open' ) ) {
		$fi   = finfo_open( FILEINFO_MIME_TYPE );
		$mime = $fi ? (string) finfo_file( $fi, $path ) : '';
	}
	$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a non-image is an expected answer, not an error.
	if ( ! is_array( $info ) ) {
		return $none;
	}
	// Both readings must agree. A file that finfo calls one thing and the image
	// parser another is exactly what this check exists to refuse.
	if ( '' !== $mime && $mime !== ( $info['mime'] ?? '' ) ) {
		return $none;
	}
	return [ 'mime' => (string) $info['mime'], 'width' => (int) $info[0], 'height' => (int) $info[1] ];
}

/**
 * One photo: acceptable, or the reason in words the customer can act on.
 *
 * @param array{error?:int,size?:int,mime?:string,width?:int,height?:int} $f
 *        `mime`, `width`, `height` come from foodify_review_photo_sniff(), never from the request.
 * @return array{ok:bool,error:string}
 */
function foodify_review_photo_verdict( array $f, int $index, ?array $limits = null ): array {
	$limits = $limits ?? foodify_review_photo_limits();
	$label  = sprintf( 'Photo %d', $index );
	$no     = static fn( string $why ): array => [ 'ok' => false, 'error' => $label . ' ' . $why ];
	$mb     = (int) round( $limits['max_bytes'] / 1048576 );
	$err    = (int) ( $f['error'] ?? 0 );

	if ( 1 === $err || 2 === $err ) {            // UPLOAD_ERR_INI_SIZE / FORM_SIZE
		return $no( sprintf( 'is larger than %d MB. Please choose a smaller one.', $mb ) );
	}
	if ( 0 !== $err ) {
		return $no( 'did not finish uploading. Please try again.' );
	}
	$size = (int) ( $f['size'] ?? 0 );
	if ( $size < 1 ) {
		return $no( 'is empty.' );
	}
	if ( $size > (int) $limits['max_bytes'] ) {
		return $no( sprintf( 'is %s MB; the limit is %d MB. Please choose a smaller one.', number_format( $size / 1048576, 1 ), $mb ) );
	}
	if ( ! isset( $limits['mimes'][ (string) ( $f['mime'] ?? '' ) ] ) ) {
		return $no( 'is not a JPEG, PNG or WebP photo.' );
	}
	$w = (int) ( $f['width'] ?? 0 );
	$h = (int) ( $f['height'] ?? 0 );
	if ( $w < 1 || $h < 1 ) {
		return $no( 'could not be read as a picture.' );
	}
	if ( $w * $h > (int) $limits['max_pixels'] ) {
		return $no( 'is too large to process. Please choose a smaller one.' );
	}
	return [ 'ok' => true, 'error' => '' ];
}

/**
 * A rolling-window counter, the house shape (inc/otp-throttle.php).
 *
 * @param int[] $timestamps Earlier events, any order.
 * @return array{allowed:bool,retry_after:int,used:int}
 */
function foodify_review_photo_gate( array $timestamps, int $now, int $max, int $window ): array {
	$recent = array_values( array_filter( $timestamps, static fn( $t ): bool => is_int( $t ) && $t > $now - $window && $t <= $now ) );
	sort( $recent );
	$used = count( $recent );
	if ( $used >= $max ) {
		return [ 'allowed' => false, 'retry_after' => max( 1, ( (int) $recent[0] + $window ) - $now ), 'used' => $used ];
	}
	return [ 'allowed' => true, 'retry_after' => 0, 'used' => $used ];
}

/**
 * May this submission carry photos?
 *
 * @param array{count:int,is_product:bool,is_reply:bool,bought:bool,gate_ok:bool} $c
 * @return array{allowed:bool,reason:string}
 */
function foodify_review_photo_allowed( array $c, ?array $limits = null ): array {
	$limits = $limits ?? foodify_review_photo_limits();
	$no     = static fn( string $why ): array => [ 'allowed' => false, 'reason' => $why ];
	if ( (int) ( $c['count'] ?? 0 ) < 1 ) {
		return $no( 'none' );
	}
	if ( empty( $c['is_product'] ) ) {
		return $no( 'not_product' );
	}
	if ( ! empty( $c['is_reply'] ) ) {
		return $no( 'reply' );            // photos belong to a review, not to an answer
	}
	if ( empty( $c['bought'] ) ) {
		return $no( 'not_verified' );
	}
	if ( (int) $c['count'] > (int) $limits['max_files'] ) {
		return $no( 'too_many' );
	}
	if ( empty( $c['gate_ok'] ) ) {
		return $no( 'rate_limited' );
	}
	return [ 'allowed' => true, 'reason' => 'ok' ];
}

/** The refusal a customer reads, by reason. */
function foodify_review_photo_refusal( string $reason, ?array $limits = null ): string {
	$limits = $limits ?? foodify_review_photo_limits();
	return [
		'not_verified' => 'Photos can be added by customers who ordered this product. If you did, use the same email address as your order.',
		'too_many'     => sprintf( 'You can add up to %d photos to a review. Please remove some and send it again.', (int) $limits['max_files'] ),
		'rate_limited' => 'You have added photos to several reviews recently. Please try again later, or send this review without photos.',
		'reply'        => 'Photos can be added to a review, not to a reply.',
		'not_product'  => 'Photos can only be added to product reviews.',
	][ $reason ] ?? '';
}

/**
 * "Photo from Priya's review" — first name only; never a surname, never an
 * email. With several photos each is numbered — "Photo 1 of 2 from Priya's
 * review" — so a reader moving through them hears three different things
 * rather than the same sentence three times (PRODUCT-23).
 */
function foodify_review_photo_alt( string $author, int $n = 0, int $of = 1 ): string {
	$author = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $author ) ) );
	$first  = '' !== $author ? explode( ' ', $author )[0] : '';
	$first  = rtrim( $first, '.,' );
	$photo  = $of > 1 && $n >= 1 && $n <= $of ? "Photo {$n} of {$of}" : 'Photo';
	if ( '' === $first || str_contains( $first, '@' ) || ! preg_match( '/\p{L}/u', $first ) ) {
		return "{$photo} from a customer's review";
	}
	return sprintf( "%s from %s's review", $photo, $first );
}

/**
 * The thumbnails in a review card. Each is a link to the full image, so with
 * no script it still opens; the script turns the link into a <dialog>.
 *
 * @param array<int,array{thumb:string,full:string,width?:int,height?:int}> $photos
 */
function foodify_review_photos_html( array $photos, string $author ): string {
	$ok     = static fn( string $u ): bool => (bool) preg_match( '#^(https?://|/)[^\s"\'<>]*$#', $u );
	$photos = array_values( array_filter( $photos, static fn( $p ): bool => $ok( (string) ( $p['thumb'] ?? '' ) ) && $ok( (string) ( $p['full'] ?? '' ) ) ) );
	$of     = count( $photos );
	$li     = '';
	foreach ( $photos as $i => $p ) {
		$thumb = (string) $p['thumb'];
		$full  = (string) $p['full'];
		$alt   = htmlspecialchars( foodify_review_photo_alt( $author, $i + 1, $of ), ENT_QUOTES );
		$dims = ( ! empty( $p['width'] ) && ! empty( $p['height'] ) )
			? sprintf( ' width="%d" height="%d"', (int) $p['width'], (int) $p['height'] )
			: '';
		$li .= sprintf(
			'<li><a class="fd-rv-photo" href="%1$s" data-fd-photo><img src="%2$s"%3$s alt="%4$s" loading="lazy" decoding="async"></a></li>',
			htmlspecialchars( $full, ENT_QUOTES ),
			htmlspecialchars( $thumb, ENT_QUOTES ),
			$dims,
			$alt
		);
	}
	return '' === $li ? '' : '<ul class="fd-rv-photos">' . $li . '</ul>';
}

/**
 * The review section's script: the star filter, and photos opening in a
 * <dialog>. Either half is inert without its markup. Without the script every
 * review shows and every photo is a plain link to the full image.
 *
 * Expects: [data-fd-review-summary][data-total] holding [data-fd-review-filter]
 * (hidden), buttons [data-fd-filter="5".."1"|"photos"], [data-fd-review-count],
 * [data-fd-filter-clear]; review items anywhere on the page with class `fd-rv`,
 * `fd-rv--N` for their rating and `fd-rv--photos` when they carry approved
 * photos; photo links a[data-fd-photo] with an <img> inside.
 *
 * Returned as a string so the clickable prototype embeds the SAME source.
 */
function foodify_review_filter_script(): string {
	return <<<'JS'
(function(){
  var root=document.querySelector('[data-fd-review-summary]');
  var items=[].slice.call(document.querySelectorAll('.fd-rv'));
  var bar=root&&root.querySelector('[data-fd-review-filter]');
  if(bar&&items.length){
    var total=Math.max(parseInt(root.getAttribute('data-total'),10)||0,items.length);
    var chips=[].slice.call(bar.querySelectorAll('[data-fd-filter]'));
    var count=bar.querySelector('[data-fd-review-count]'),clear=bar.querySelector('[data-fd-filter-clear]'),current='';
    function rating(el){var m=/(?:^|\s)fd-rv--([1-5])(?:\s|$)/.exec(el.className);return m?m[1]:'';}
    function word(n){return n===1?'review':'reviews';}
    // Paged reviews: the chips can only filter what is on this page, so they
    // count only that, and the label says so. The histogram keeps the totals.
    if(items.length<total){
      var lab=bar.querySelector('[data-fd-filter-label]');
      if(lab)lab.textContent='Filter the '+items.length+' '+word(items.length)+' shown';
      chips.forEach(function(c){
        var k=c.getAttribute('data-fd-filter'),n=items.filter(function(el){return k==='photos'?el.classList.contains('fd-rv--photos'):rating(el)===k;}).length;
        c.hidden=n<1;
        if(k==='photos'){c.textContent='With photos ('+n+')';c.setAttribute('aria-label','With photos, '+n+' '+word(n));}
        else{c.textContent=k+'★ ('+n+')';c.setAttribute('aria-label',k+' '+(k==='1'?'star':'stars')+', '+n+' '+word(n));}
      });
      chips=chips.filter(function(c){return !c.hidden;});
    }
    function line(shown,loaded){
      if(total<1)return '';
      if(loaded>=total){if(shown>=total)return total===1?'Showing 1 review':'Showing all '+total+' reviews';return 'Showing '+shown+' of '+total+' '+word(total);}
      if(shown>=loaded)return 'Showing '+loaded+' of '+total+' '+word(total);
      return 'Showing '+shown+' of the '+loaded+' reviews on this page ('+total+' in all)';
    }
    function apply(key){
      current=key;var shown=0;
      items.forEach(function(el){var ok=!key||(key==='photos'?el.classList.contains('fd-rv--photos'):rating(el)===key);el.hidden=!ok;if(ok)shown++;});
      chips.forEach(function(c){c.setAttribute('aria-pressed',String(c.getAttribute('data-fd-filter')===key));});
      clear.hidden=!key;count.textContent=line(shown,items.length);
    }
    chips.forEach(function(c){c.addEventListener('click',function(){var k=c.getAttribute('data-fd-filter');apply(current===k?'':k);});});
    clear.addEventListener('click',function(){apply('');if(chips[0])chips[0].focus();});
    bar.hidden=false;apply('');
  }
  var links=[].slice.call(document.querySelectorAll('a[data-fd-photo]'));
  if(!links.length||typeof HTMLDialogElement!=='function')return;
  var dlg=document.createElement('dialog');dlg.className='fd-rv-dialog';dlg.setAttribute('aria-label','Review photo');
  dlg.innerHTML='<img alt=""><form method="dialog"><button type="submit" class="fd-rv-dialog__close">Close</button></form>';
  document.body.appendChild(dlg);
  var big=dlg.querySelector('img'),opener=null;
  dlg.addEventListener('click',function(e){if(e.target===dlg)dlg.close();});
  dlg.addEventListener('close',function(){big.removeAttribute('src');if(opener)opener.focus();});
  links.forEach(function(a){a.addEventListener('click',function(e){
    e.preventDefault();opener=a;var t=a.querySelector('img');
    big.src=a.getAttribute('href');big.alt=t?t.getAttribute('alt')||'':'';dlg.showModal();
  });});
})();
JS;
}

/**
 * The section's heading and the line under it (PRODUCT-24). With reviews:
 * "What people said". With none, "What people said" over an empty list read
 * as a verdict, and the line under it ("Only people who ordered this can
 * review it") told a buyer what they could not do rather than how they can.
 *
 * @return array{title:string,sub:string}
 */
function foodify_review_section_copy( int $count ): array {
	return $count > 0
		? [ 'title' => 'What people said', 'sub' => 'Only people who ordered this can review it.' ]
		: [ 'title' => 'Reviews', 'sub' => 'Bought it? You can review it from My account → Orders.' ];
}

/**
 * With no reviews, WooCommerce's own block adds a second "Reviews" heading and
 * "There are no reviews yet." under the section's heading and line. Both go;
 * the review form (for a verified buyer) stays.
 */
function foodify_review_strip_empty_state( string $html ): string {
	$html = (string) preg_replace( '~<h2\b[^>]*\bclass="[^"]*\bwoocommerce-Reviews-title\b[^"]*"[^>]*>.*?</h2>\s*~s', '', $html );
	return (string) preg_replace( '~<p\b[^>]*\bclass="[^"]*\bwoocommerce-noreviews\b[^"]*"[^>]*>.*?</p>\s*~s', '', $html );
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

const FOODIFY_REVIEW_ASKED_META = '_foodify_review_asked';
const FOODIFY_REVIEW_OPTOUT     = 'foodify_review_optout';
const FOODIFY_REVIEW_LAST_ASKED = 'foodify_review_last_asked';

function foodify_review_config(): array {
	return (array) apply_filters( 'foodify_review_config', foodify_review_request_defaults() );
}

/** Opt-out list holds HASHES, never addresses — DPDP §8, and an option is not a safe place for a mailing list. */
function foodify_review_email_hash( string $email ): string {
	return hash( 'sha256', strtolower( trim( $email ) ) . wp_salt( 'auth' ) );
}

function foodify_review_opted_out( string $email ): bool {
	$list = get_option( FOODIFY_REVIEW_OPTOUT, [] );
	return is_array( $list ) && in_array( foodify_review_email_hash( $email ), $list, true );
}

function foodify_review_opt_out( string $email ): void {
	$list = get_option( FOODIFY_REVIEW_OPTOUT, [] );
	$list = is_array( $list ) ? $list : [];
	$hash = foodify_review_email_hash( $email );
	if ( ! in_array( $hash, $list, true ) ) {
		$list[] = $hash;
		update_option( FOODIFY_REVIEW_OPTOUT, $list, false );
	}
}

/** Build the live state for one order. */
function foodify_review_order_state( WC_Order $order ): array {
	$completed = $order->get_date_completed();
	$email     = (string) $order->get_billing_email();

	$reviewable = false;
	foreach ( $order->get_items() as $item ) {
		$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
		if ( $product && $product->get_id() && comments_open( $product->get_id() ) ) {
			$reviewable = true;
			break;
		}
	}

	$last = '' !== $email
		? get_option( FOODIFY_REVIEW_LAST_ASKED . '_' . foodify_review_email_hash( $email ), null )
		: null;

	return [
		'completed_at'         => $completed ? $completed->getTimestamp() : 0,
		'asked_at'             => $order->get_meta( FOODIFY_REVIEW_ASKED_META ) ? (int) $order->get_meta( FOODIFY_REVIEW_ASKED_META ) : null,
		'email'                => $email,
		'refunded'             => (float) $order->get_total_refunded() > 0.0,
		'cancelled'            => in_array( $order->get_status(), [ 'cancelled', 'failed', 'refunded' ], true ),
		'opted_out'            => '' !== $email && foodify_review_opted_out( $email ),
		'has_reviewable_items' => $reviewable,
		'customer_last_asked'  => null !== $last ? (int) $last : null,
	];
}

/** Queue the ask when the order completes. */
add_action( 'woocommerce_order_status_completed', static function ( int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$state = foodify_review_request_state( foodify_review_order_state( $order ), time(), foodify_review_config() );
	if ( ! $state['due_at'] && ! $state['send'] ) {
		return;   // permanently ineligible — nothing to schedule
	}
	if ( wp_next_scheduled( 'foodify_send_review_request', [ $order_id ] ) ) {
		return;
	}
	wp_schedule_single_event( max( time() + 60, $state['due_at'] ), 'foodify_send_review_request', [ $order_id ] );
}, 30, 1 );

/**
 * Fire the ask — after re-deciding.
 *
 * The re-check is the point. Five days is long enough for a refund to land, and
 * an email asking someone to rate a meal the store just refunded them for is
 * worse than sending nothing at all.
 */
add_action( 'foodify_send_review_request', static function ( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$state = foodify_review_request_state( foodify_review_order_state( $order ), time(), foodify_review_config() );
	if ( ! $state['send'] ) {
		return;
	}

	$email = (string) $order->get_billing_email();
	$sent  = foodify_send_review_request_email( $order, $email );
	if ( ! $sent ) {
		return;   // do not stamp a failure as asked; the next completed order re-queues
	}

	$order->update_meta_data( FOODIFY_REVIEW_ASKED_META, (string) time() );
	$order->save();
	update_option( FOODIFY_REVIEW_LAST_ASKED . '_' . foodify_review_email_hash( $email ), time(), false );
} );

/** An opt-out link that cannot be forged or enumerated. */
function foodify_review_optout_url( string $email ): string {
	$hash  = foodify_review_email_hash( $email );
	$token = hash_hmac( 'sha256', $hash, wp_salt( 'nonce' ) );
	return add_query_arg(
		[ 'foodify_no_reviews' => $hash, 'token' => $token ],
		home_url( '/' )
	);
}

add_action( 'template_redirect', static function (): void {
	if ( empty( $_GET['foodify_no_reviews'] ) || empty( $_GET['token'] ) ) {
		return;
	}
	$hash  = sanitize_text_field( wp_unslash( $_GET['foodify_no_reviews'] ) );
	$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
	if ( ! hash_equals( hash_hmac( 'sha256', $hash, wp_salt( 'nonce' ) ), $token ) ) {
		return;
	}
	// The list already holds hashes, so opting out never needs the address back.
	$list = get_option( FOODIFY_REVIEW_OPTOUT, [] );
	$list = is_array( $list ) ? $list : [];
	if ( ! in_array( $hash, $list, true ) ) {
		$list[] = $hash;
		update_option( FOODIFY_REVIEW_OPTOUT, $list, false );
	}
	wp_die(
		esc_html__( 'Done — we will not ask you to review anything again. Your orders are unaffected.', 'foodify' ),
		esc_html__( 'Unsubscribed', 'foodify' ),
		[ 'response' => 200, 'back_link' => true ]
	);
}, 1 );

/** The email. Plain, one ask, one link per product, one way out. */
function foodify_send_review_request_email( WC_Order $order, string $email ): bool {
	$lines = [];
	foreach ( $order->get_items() as $item ) {
		$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
		if ( ! $product || ! $product->get_id() || ! comments_open( $product->get_id() ) ) {
			continue;
		}
		$lines[] = sprintf(
			'<li><a href="%1$s">%2$s</a></li>',
			esc_url( get_permalink( $product->get_id() ) . '#reviews' ),
			esc_html( $product->get_name() )
		);
	}
	if ( ! $lines ) {
		return false;
	}

	$body = sprintf(
		'<p>%1$s</p><p>%2$s</p><ul>%3$s</ul><p style="font-size:13px;color:#6b6257">%4$s <a href="%5$s">%6$s</a></p>',
		esc_html( sprintf(
			/* translators: %s: customer first name */
			__( 'Hi %s,', 'foodify' ),
			$order->get_billing_first_name() ?: __( 'there', 'foodify' )
		) ),
		esc_html__( 'You ordered from us a few days ago. If you have eaten it by now, a line about what you thought helps the next person decide — and it helps us more than you would guess.', 'foodify' ),
		implode( '', $lines ),
		esc_html__( 'Would rather not be asked?', 'foodify' ),
		esc_url( foodify_review_optout_url( $email ) ),
		esc_html__( 'Unsubscribe from review requests', 'foodify' )
	);

	$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
	$html   = $mailer ? $mailer->wrap_message( __( 'How was it?', 'foodify' ), $body ) : $body;

	return (bool) wp_mail(
		$email,
		__( 'How was your order?', 'foodify' ),
		$html,
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}

/**
 * A star rating in a listing carries its count.
 *
 * Hooked as an ACTION rather than filtering the rating markup, deliberately: I
 * could not verify the exact rating-html filter name from this environment, and
 * this project has already shipped an invented option key and nearly shipped an
 * invented filter. An action that appends an element is additive — if the
 * priority is wrong the count lands somewhere slightly different, rather than
 * silently never running.
 */
add_action( 'woocommerce_after_shop_loop_item_title', static function (): void {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$d = foodify_rating_display( (float) $product->get_average_rating(), (int) $product->get_review_count() );
	if ( ! $d['show'] ) {
		return;
	}
	printf(
		'<span class="fd-rating-count">%s</span>',
		esc_html( sprintf( '%s · %s', number_format( $d['stars'], 1 ), $d['label'] ) )
	);
}, 6 );

/**
 * A product review must come from someone who bought the product.
 *
 * WooCommerce has a setting for this — "Reviews can only be left by verified
 * owners" — and I have not set it in scripts/bootstrap.sh, deliberately. I could
 * not verify its option name from this environment, and `wp option update` on a
 * name nothing reads SUCCEEDS. That is exactly how this project shipped invented
 * Rank Math sub-keys: a green line in the deploy log for a setting that does not
 * exist. A gate cannot catch it either, because writing and reading back a dead
 * key both work.
 *
 * So the rule is enforced here instead, using `wc_customer_bought_product()` —
 * a public WooCommerce function, not a settings name. It is stronger than the
 * checkbox in two ways: it lives in the repository where it can be reviewed, and
 * it survives somebody unticking the box in wp-admin.
 *
 * Replies are not reviews. Anyone answering a review — including the shop — has
 * a parent comment and is left alone.
 */
add_filter( 'preprocess_comment', static function ( array $comment ) {
	if ( ! (bool) apply_filters( 'foodify_require_verified_reviews', true ) ) {
		return $comment;
	}
	$post_id = (int) ( $comment['comment_post_ID'] ?? 0 );
	if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
		return $comment;
	}
	if ( ! empty( $comment['comment_parent'] ) ) {
		return $comment;   // a reply, not a review
	}
	if ( current_user_can( 'moderate_comments' ) ) {
		return $comment;   // the shop answering on its own product
	}

	$email   = (string) ( $comment['comment_author_email'] ?? '' );
	$user_id = (int) ( $comment['user_id'] ?? 0 );
	if ( ! function_exists( 'wc_customer_bought_product' ) ) {
		return $comment;
	}
	if ( wc_customer_bought_product( $email, $user_id, $post_id ) ) {
		return $comment;
	}

	wp_die(
		esc_html__( 'Reviews here come from people who actually ordered the product, so they are worth reading. If you bought this, use the same email address as your order.', 'foodify' ),
		esc_html__( 'Review not published', 'foodify' ),
		[ 'response' => 403, 'back_link' => true ]
	);
} );

/* =========================================================================
 * 11 · Review photos and the star filter — WordPress glue.
 * ====================================================================== */

const FOODIFY_REVIEW_PHOTOS_META = '_foodify_review_photos';   // comment meta: attachment ids
const FOODIFY_REVIEW_PHOTO_OF    = '_foodify_review_comment';  // attachment meta: comment id
const FOODIFY_REVIEW_PHOTO_FIELD = 'fd_review_photos';

/** Is this a top-level review on a product (not a reply, not a page comment)? */
function foodify_review_is_product_review( $comment ): bool {
	return $comment instanceof WP_Comment
		&& 0 === (int) $comment->comment_parent
		&& 'product' === get_post_type( (int) $comment->comment_post_ID );
}

/** Attachment ids stored on a review. */
function foodify_review_photo_ids( int $comment_id ): array {
	$ids = get_comment_meta( $comment_id, FOODIFY_REVIEW_PHOTOS_META, true );
	return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : [];
}

/** May the people looking at this page see this review's photos? Only once a human approved it. */
function foodify_review_photos_visible( WP_Comment $comment ): bool {
	if ( '1' === (string) $comment->comment_approved ) {
		return true;
	}
	// The moderator has to see what they are approving.
	return is_admin() && current_user_can( 'moderate_comments' );
}

function foodify_review_gate_key( string $kind, string $value ): string {
	return 'foodify_rvp_' . hash( 'sha256', $kind . ':' . strtolower( trim( $value ) ) . wp_salt( 'auth' ) );
}

function foodify_review_client_ip(): string {
	// REMOTE_ADDR only: a forwarded-for header is whatever the sender typed.
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/** Both rate limits at once: per buyer email and per address. */
function foodify_review_photo_gate_ok( string $email, int $now ): bool {
	$l = foodify_review_photo_limits();
	foreach ( [ [ 'e', $email, $l['per_email'] ], [ 'i', foodify_review_client_ip(), $l['per_ip'] ] ] as [ $kind, $value, $rule ] ) {
		if ( '' === $value ) {
			continue;
		}
		$times = get_transient( foodify_review_gate_key( $kind, $value ) );
		$gate  = foodify_review_photo_gate( is_array( $times ) ? $times : [], $now, (int) $rule['max'], (int) $rule['window'] );
		if ( ! $gate['allowed'] ) {
			return false;
		}
	}
	return true;
}

function foodify_review_photo_gate_record( string $email, int $now ): void {
	$l = foodify_review_photo_limits();
	foreach ( [ [ 'e', $email, $l['per_email'] ], [ 'i', foodify_review_client_ip(), $l['per_ip'] ] ] as [ $kind, $value, $rule ] ) {
		if ( '' === $value ) {
			continue;
		}
		$key   = foodify_review_gate_key( $kind, $value );
		$times = get_transient( $key );
		$times = array_values( array_filter( is_array( $times ) ? $times : [], static fn( $t ): bool => is_int( $t ) && $t > $now - (int) $rule['window'] ) );
		$times[] = $now;
		set_transient( $key, $times, (int) $rule['window'] );
	}
}

/** GD only, for the re-encode. GD decodes to pixels and writes no metadata; Imagick can carry EXIF through a save. */
function foodify_review_force_gd(): array {
	return [ 'WP_Image_Editor_GD' ];
}

/**
 * Re-encode one validated upload into the media library and return its id.
 *
 * THE RE-ENCODE IS THE EXIF STRIP. A phone photo carries the GPS position it
 * was taken at, which for a meal is the customer's kitchen. Copying the upload
 * would publish that; decoding to pixels and writing a fresh file does not.
 * The orientation EXIF would carry is applied to the pixels first, so a photo
 * taken upright still shows upright once the tag is gone.
 *
 * The file gets a random name — a photo waiting for approval must not be
 * findable by guessing — and no parent post, so it never joins a product's
 * media.
 */
function foodify_review_photo_store( string $tmp, string $mime, int $comment_id, string $alt ): int {
	$limits = foodify_review_photo_limits();
	$ext    = $limits['mimes'][ $mime ] ?? '';
	if ( '' === $ext ) {
		return 0;
	}
	add_filter( 'wp_image_editors', 'foodify_review_force_gd', 999 );
	$editor = wp_get_image_editor( $tmp, [ 'mime_type' => $mime ] );
	remove_filter( 'wp_image_editors', 'foodify_review_force_gd', 999 );
	if ( is_wp_error( $editor ) ) {
		return 0;
	}
	if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
		$editor->maybe_exif_rotate();
	}
	$size = $editor->get_size();
	$edge = (int) $limits['max_edge'];
	if ( max( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) ) > $edge ) {
		$editor->resize( $edge, $edge, false );
	}
	$editor->set_quality( 82 );

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return 0;
	}
	$name  = wp_unique_filename( $uploads['path'], 'review-' . bin2hex( random_bytes( 8 ) ) . '.' . $ext );
	$saved = $editor->save( trailingslashit( $uploads['path'] ) . $name, $mime );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
		return 0;
	}

	$id = wp_insert_attachment( [
		'post_mime_type' => $mime,
		'post_title'     => __( 'Review photo', 'foodify' ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	], $saved['path'], 0, true );
	if ( is_wp_error( $id ) || ! $id ) {
		wp_delete_file( $saved['path'] );
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $saved['path'] ) );
	update_post_meta( (int) $id, '_wp_attachment_image_alt', $alt );
	update_post_meta( (int) $id, FOODIFY_REVIEW_PHOTO_OF, $comment_id );
	return (int) $id;
}

/** The field, on the product review form. Not offered to a signed-in customer who has not bought. */
function foodify_review_photo_field(): void {
	if ( 'product' !== get_post_type() ) {
		return;
	}
	if ( is_user_logged_in() && function_exists( 'wc_customer_bought_product' ) ) {
		$user = wp_get_current_user();
		if ( ! wc_customer_bought_product( (string) $user->user_email, (int) $user->ID, (int) get_the_ID() ) ) {
			return;
		}
	}
	$l = foodify_review_photo_limits();
	printf(
		'<p class="comment-form-fd-photos fd-rv-upload"><label for="fd-review-photos">%1$s <span class="fd-rv-upload__hint">%2$s</span></label>'
		. '<input type="file" id="fd-review-photos" name="%3$s[]" accept="image/jpeg,image/png,image/webp" multiple>'
		. '<span class="fd-rv-upload__note">%4$s</span></p>',
		esc_html__( 'Add photos', 'foodify' ),
		esc_html( sprintf(
			/* translators: 1: max files, 2: max megabytes */
			__( '(optional — up to %1$d, JPEG, PNG or WebP, %2$d MB each)', 'foodify' ),
			(int) $l['max_files'],
			(int) round( $l['max_bytes'] / 1048576 )
		) ),
		esc_attr( FOODIFY_REVIEW_PHOTO_FIELD ),
		esc_html__( 'For customers who ordered this. Photos appear once we have checked the review, and we remove location and camera details from every photo.', 'foodify' )
	);
}
add_action( 'comment_form_logged_in_after', 'foodify_review_photo_field' );
add_action( 'comment_form_after_fields', 'foodify_review_photo_field' );

// comment_form() has no enctype argument, and a file input in a form without
// it sends the file NAME and no bytes — the photo is silently lost. So the
// product review form is buffered and given one, server side, rather than by a
// script that a customer without JavaScript would never run.
add_action( 'comment_form_before', static function (): void {
	if ( 'product' === get_post_type() ) {
		$GLOBALS['foodify_review_form_buffer'] = ob_start();
	}
} );
add_action( 'comment_form_after', static function (): void {
	if ( empty( $GLOBALS['foodify_review_form_buffer'] ) ) {
		return;
	}
	$GLOBALS['foodify_review_form_buffer'] = false;
	$html = (string) ob_get_clean();
	if ( ! preg_match( '/<form\b[^>]*\benctype=/i', $html ) ) {
		$html = (string) preg_replace( '/<form\b/i', '<form enctype="multipart/form-data"', $html, 1 );
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- WordPress's own comment form, re-emitted.
} );

/**
 * Decide the photos BEFORE the review exists. A refusal stops the submission
 * with the reason and a way back, rather than publishing the text and quietly
 * dropping the photos the customer thinks they attached.
 * Runs after the verified-buyer filter above (priority 10).
 */
add_filter( 'preprocess_comment', static function ( array $comment ) {
	$GLOBALS['foodify_review_pending_photos'] = [];
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- comment submissions carry WordPress's own checks; files are validated below.
	$files = foodify_review_photo_files( $_FILES[ FOODIFY_REVIEW_PHOTO_FIELD ] ?? null );
	if ( ! $files ) {
		return $comment;
	}
	$post_id = (int) ( $comment['comment_post_ID'] ?? 0 );
	$email   = (string) ( $comment['comment_author_email'] ?? '' );
	$user_id = (int) ( $comment['user_id'] ?? 0 );
	$bought  = function_exists( 'wc_customer_bought_product' ) && '' !== $email && wc_customer_bought_product( $email, $user_id, $post_id );

	$decision = foodify_review_photo_allowed( [
		'count'      => count( $files ),
		'is_product' => 'product' === get_post_type( $post_id ),
		'is_reply'   => ! empty( $comment['comment_parent'] ),
		'bought'     => $bought,
		'gate_ok'    => $bought && foodify_review_photo_gate_ok( $email, time() ),
	] );
	$stop = static function ( string $why, int $status ): void {
		wp_die(
			esc_html( $why . ' ' . __( 'Use Back to return to your review — the text you wrote is still there.', 'foodify' ) ),
			esc_html__( 'Review not sent', 'foodify' ),
			[ 'response' => $status, 'back_link' => true ]
		);
	};
	if ( ! $decision['allowed'] ) {
		$stop( foodify_review_photo_refusal( $decision['reason'] ), 'rate_limited' === $decision['reason'] ? 429 : 403 );
	}

	$limits  = foodify_review_photo_limits();
	$pending = [];
	foreach ( $files as $i => $f ) {
		$tmp   = (string) $f['tmp_name'];
		$sniff = ( 0 === (int) $f['error'] && is_uploaded_file( $tmp ) ) ? foodify_review_photo_sniff( $tmp ) : [ 'mime' => '', 'width' => 0, 'height' => 0 ];
		$v     = foodify_review_photo_verdict( $f + $sniff, $i + 1, $limits );
		if ( ! $v['ok'] ) {
			$stop( $v['error'], 400 );
		}
		// WordPress's own content check as a second opinion on the BYTES. It is
		// handed a name built from what the bytes are, not the customer's
		// filename: a real JPEG called "IMG_1234" (no extension) is still a
		// photo, and "photo.jpg.php" is never consulted at all.
		$wp = wp_check_filetype_and_ext(
			$tmp,
			'photo.' . $limits['mimes'][ $sniff['mime'] ],
			[ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ]
		);
		if ( empty( $wp['type'] ) || $wp['type'] !== $sniff['mime'] ) {
			/* translators: %d: photo number */
			$stop( sprintf( __( 'Photo %d is not a JPEG, PNG or WebP photo.', 'foodify' ), $i + 1 ), 400 );
		}
		add_filter( 'wp_image_editors', 'foodify_review_force_gd', 999 );
		$can = wp_image_editor_supports( [ 'mime_type' => $sniff['mime'], 'methods' => [ 'resize', 'save' ] ] );
		remove_filter( 'wp_image_editors', 'foodify_review_force_gd', 999 );
		if ( ! $can ) {
			/* translators: %d: photo number */
			$stop( sprintf( __( 'Photo %d is a type this site cannot process yet. Please send a JPEG instead.', 'foodify' ), $i + 1 ), 400 );
		}
		$pending[] = [ 'tmp' => $tmp, 'mime' => $sniff['mime'] ];
	}
	$GLOBALS['foodify_review_pending_photos'] = $pending;
	return $comment;
}, 20 );

// A review with photos always waits for a person. Whatever the site's
// moderation setting does for text, an image is checked by a human first.
add_filter( 'pre_comment_approved', static function ( $approved ) {
	if ( empty( $GLOBALS['foodify_review_pending_photos'] ) ) {
		return $approved;
	}
	return ( 1 === $approved || '1' === $approved ) ? 0 : $approved;
}, 99 );

add_action( 'comment_post', static function ( $comment_id, $approved ): void {
	$pending = $GLOBALS['foodify_review_pending_photos'] ?? [];
	$GLOBALS['foodify_review_pending_photos'] = [];
	if ( ! $pending || 'spam' === $approved ) {
		return;
	}
	$comment = get_comment( (int) $comment_id );
	if ( ! $comment instanceof WP_Comment ) {
		return;
	}
	$alt = foodify_review_photo_alt( (string) $comment->comment_author );
	$ids = [];
	foreach ( $pending as $p ) {
		$id = foodify_review_photo_store( (string) $p['tmp'], (string) $p['mime'], (int) $comment_id, $alt );
		if ( $id ) {
			$ids[] = $id;
		}
	}
	if ( $ids ) {
		update_comment_meta( (int) $comment_id, FOODIFY_REVIEW_PHOTOS_META, $ids );
		foodify_review_photo_gate_record( (string) $comment->comment_author_email, time() );
	}
}, 10, 2 );

// The photos go with the review. Deleting a review (or emptying spam) deletes
// its files — they are the customer's personal data, not the shop's stock.
add_action( 'delete_comment', static function ( $comment_id ): void {
	foreach ( foodify_review_photo_ids( (int) $comment_id ) as $id ) {
		wp_delete_attachment( $id, true );
	}
} );

// Tools → Erase Personal Data: WordPress anonymises a comment; it does not
// delete the files hanging off it. This eraser does.
add_filter( 'wp_privacy_personal_data_erasers', static function ( array $erasers ): array {
	$erasers['foodify-review-photos'] = [
		'eraser_friendly_name' => __( 'Review photos', 'foodify' ),
		'callback'             => static function ( string $email ): array {
			$removed = false;
			foreach ( get_comments( [ 'author_email' => $email, 'post_type' => 'product', 'status' => 'all', 'number' => 200 ] ) as $c ) {
				foreach ( foodify_review_photo_ids( (int) $c->comment_ID ) as $id ) {
					$removed = (bool) wp_delete_attachment( $id, true ) || $removed;
				}
				delete_comment_meta( (int) $c->comment_ID, FOODIFY_REVIEW_PHOTOS_META );
			}
			return [ 'items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true ];
		},
	];
	return $erasers;
} );

/** Thumbnails in the review card — after wpautop (30), so it cannot wrap them. */
add_filter( 'comment_text', static function ( $text, $comment = null ) {
	if ( ! foodify_review_is_product_review( $comment ) || ! foodify_review_photos_visible( $comment ) ) {
		return $text;
	}
	$photos = [];
	foreach ( foodify_review_photo_ids( (int) $comment->comment_ID ) as $id ) {
		$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
		$full  = wp_get_attachment_image_url( $id, 'large' ) ?: wp_get_attachment_url( $id );
		if ( $thumb && $full ) {
			$photos[] = [ 'thumb' => esc_url_raw( (string) $thumb[0] ), 'full' => esc_url_raw( (string) $full ), 'width' => (int) $thumb[1], 'height' => (int) $thumb[2] ];
		}
	}
	return $text . foodify_review_photos_html( $photos, (string) $comment->comment_author );
}, 35, 2 );

/** Each review card says its rating and whether it has photos, for the filter. */
add_filter( 'comment_class', static function ( $classes, $css_class = '', $comment_id = 0, $comment = null ) {
	if ( ! foodify_review_is_product_review( $comment ) ) {
		return $classes;
	}
	$classes   = (array) $classes;
	$classes[] = 'fd-rv';
	$rating    = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
	if ( $rating >= 1 && $rating <= 5 ) {
		$classes[] = 'fd-rv--' . $rating;
	}
	if ( foodify_review_photo_ids( (int) $comment->comment_ID ) && foodify_review_photos_visible( $comment ) ) {
		$classes[] = 'fd-rv--photos';
	}
	return $classes;
}, 10, 4 );

/** Approved ratings and the photo count for one product. */
function foodify_review_product_stats( int $product_id ): array {
	$ratings = [];
	$photos  = 0;
	foreach ( get_comments( [ 'post_id' => $product_id, 'status' => 'approve', 'parent' => 0, 'number' => 2000 ] ) as $c ) {
		$ratings[] = (string) get_comment_meta( (int) $c->comment_ID, 'rating', true );
		if ( foodify_review_photo_ids( (int) $c->comment_ID ) ) {
			$photos++;
		}
	}
	return [ 'ratings' => $ratings, 'with_photos' => $photos ];
}

/** The summary and filter, above the review list, with its script. */
add_filter( 'render_block', static function ( string $html, array $block ): string {
	if ( 'woocommerce/product-reviews' !== ( $block['blockName'] ?? '' ) || ! empty( $GLOBALS['foodify_review_summary_done'] ) ) {
		return $html;
	}
	$id = (int) get_the_ID();
	if ( ! $id || 'product' !== get_post_type( $id ) ) {
		return $html;
	}
	$GLOBALS['foodify_review_summary_done'] = true;
	$stats   = foodify_review_product_stats( $id );
	$summary = foodify_review_summary_html( foodify_review_histogram( $stats['ratings'] ), (int) $stats['with_photos'] );
	if ( '' === $summary ) {
		return foodify_review_strip_empty_state( $html );   // no reviews: the section's own heading and line say it
	}
	return $summary . $html . '<script>' . foodify_review_filter_script() . '</script>'; // static script, no data
}, 10, 2 );

/** The section heading and its line, by whether the product has reviews (PRODUCT-24). */
$foodify_review_copy = static function ( string $html, array $block ): string {
	$cls = (string) ( $block['attrs']['className'] ?? '' );
	$key = str_contains( $cls, 'fd-reviews__title' ) ? 'title' : ( str_contains( $cls, 'fd-reviews__sub' ) ? 'sub' : '' );
	if ( '' === $key || ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}
	$copy = foodify_review_section_copy( (int) $product->get_review_count() );
	return (string) preg_replace( '~^(\s*<(h2|p)\b[^>]*>).*?(</\2>)~s', '$1' . esc_html( $copy[ $key ] ) . '$3', $html, 1 );
};
add_filter( 'render_block_core/heading', $foodify_review_copy, 10, 2 );
add_filter( 'render_block_core/paragraph', $foodify_review_copy, 10, 2 );

/**
 * The rating row under the product page's title, in the same stars and the
 * same rounding rule as the summary above the reviews (PRODUCT-15). On a card,
 * the one-line "★ 4.7 (84)" (foodify_card_rating_html).
 */
add_filter( 'render_block_woocommerce/product-rating', static function ( string $html, array $block, $instance = null ): string {
	if ( ! empty( $block['attrs']['isDescendentOfQueryLoop'] ) ) {
		$id      = is_object( $instance ) && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : (int) get_the_ID();
		$product = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		return $product instanceof WC_Product
			? foodify_card_rating_html( (float) $product->get_average_rating(), (int) $product->get_review_count() )
			: $html;
	}
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}
	return foodify_pdp_rating_html( (float) $product->get_average_rating(), (int) $product->get_review_count() ); // Escaped in the builder.
}, 10, 3 );
