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
