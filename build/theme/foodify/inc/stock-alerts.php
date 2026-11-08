<?php
/**
 * Notify me when it is back in stock — one email, then the address is deleted.
 *
 * WHY THIS EXISTS. A sold-out card used to say "Sold out" and nothing else
 * (product-display.php explains why: a NOTIFY ME button with no mechanism
 * behind it is a promise the store would not keep). The demand is real and the
 * buyer is gone the moment the page says no.
 *
 * THE RULES — data minimisation (DPDP Act 2023) is the design, not a footnote.
 *  1. ONE email per subscription, about that product only. Never marketing:
 *     the address is not added to any list, and the email says so.
 *  2. The subscription is DELETED once the email is sent. Nothing is kept "for
 *     later". A failed send is retried at most three times, then dropped.
 *  3. Withdrawable at once: the confirmation shows "Cancel this alert", and the
 *     WordPress privacy eraser/exporter know about these addresses.
 *  4. Strangers can trigger it, so it is rate-limited per IP and per address
 *     (the otp-throttle.php shape), capped per product, nonce-checked, and
 *     carries a honeypot.
 *  5. The cancel token is stored HASHED, and never travels in a URL: the
 *     confirmation reaches the page through a one-time flash key, because the
 *     page's URL goes to analytics (inc/analytics.php) and server logs.
 *
 * Storage: product meta `_foodify_stock_alerts`, a list of
 * {email, token (sha256), created, tries}. Per product, so deleting a product
 * deletes its alerts, and a restock reads one row.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/lane-a-test.php without WordPress.
 * ---------------------------------------------------------------------- */

const FOODIFY_STOCK_ALERT_CAP        = 500;    // subscriptions per product — abuse bound, not a business number
const FOODIFY_STOCK_ALERT_IP_MAX     = 5;      // sign-ups per IP per window
const FOODIFY_STOCK_ALERT_EMAIL_MAX  = 3;      // sign-ups per address per window, across products
const FOODIFY_STOCK_ALERT_WINDOW     = 3600;   // seconds
const FOODIFY_STOCK_ALERT_BATCH      = 50;     // emails per scheduled run
const FOODIFY_STOCK_ALERT_MAX_TRIES  = 3;

/** A normalised address, or null. Lower-cased: one person, one subscription. */
function foodify_stock_alert_email_ok( string $raw ): ?string {
	$email = strtolower( trim( $raw ) );
	if ( '' === $email || strlen( $email ) > 254 || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		return null;
	}
	// A dot in the domain: "a@localhost" passes filter_var and reaches no one.
	return str_contains( (string) substr( $email, (int) strrpos( $email, '@' ) + 1 ), '.' ) ? $email : null;
}

/** sha256 of the cancel token. Only the hash is stored. */
function foodify_stock_alert_hash( string $token ): string {
	return hash( 'sha256', $token );
}

/**
 * Rolling-window rate limit, the otp-throttle shape with the numbers as
 * arguments so one function serves the IP and the address limits.
 *
 * @param int[] $timestamps
 * @return array{allowed:bool,retry_after:int,recent:int[]}
 */
function foodify_stock_alert_gate( array $timestamps, int $now, int $max, int $window ): array {
	$recent = array_values( array_filter( $timestamps, static fn( $t ): bool => is_int( $t ) && $t > $now - $window && $t <= $now ) );
	sort( $recent );
	if ( count( $recent ) >= $max ) {
		return [ 'allowed' => false, 'retry_after' => max( 1, $recent[0] + $window - $now ), 'recent' => $recent ];
	}
	return [ 'allowed' => true, 'retry_after' => 0, 'recent' => $recent ];
}

/**
 * Add a subscription. An address already waiting is NOT duplicated: its token
 * is replaced (so the person who just asked gets a working cancel button) and
 * its date kept. A full list refuses rather than growing.
 *
 * @param array<int,array{email:string,token:string,created:int,tries?:int}> $subs
 * @return array{subs:array,result:string} result: added | exists | full | invalid
 */
function foodify_stock_alert_add( array $subs, string $email, string $token_hash, int $now, int $cap = FOODIFY_STOCK_ALERT_CAP ): array {
	$email = foodify_stock_alert_email_ok( $email );
	if ( null === $email || ! preg_match( '/^[a-f0-9]{64}$/', $token_hash ) ) {
		return [ 'subs' => $subs, 'result' => 'invalid' ];
	}
	foreach ( $subs as $i => $s ) {
		if ( ( $s['email'] ?? '' ) === $email ) {
			$subs[ $i ]['token'] = $token_hash;
			return [ 'subs' => array_values( $subs ), 'result' => 'exists' ];
		}
	}
	if ( count( $subs ) >= $cap ) {
		return [ 'subs' => $subs, 'result' => 'full' ];
	}
	$subs[] = [ 'email' => $email, 'token' => $token_hash, 'created' => $now, 'tries' => 0 ];
	return [ 'subs' => array_values( $subs ), 'result' => 'added' ];
}

/** Remove by cancel token (constant-time compare). */
function foodify_stock_alert_remove( array $subs, string $token_hash ): array {
	$kept    = [];
	$removed = false;
	foreach ( $subs as $s ) {
		if ( '' !== $token_hash && hash_equals( (string) ( $s['token'] ?? '' ), $token_hash ) ) {
			$removed = true;
			continue;
		}
		$kept[] = $s;
	}
	return [ 'subs' => $kept, 'removed' => $removed ];
}

/** Remove every subscription for an address — the privacy eraser. */
function foodify_stock_alert_forget( array $subs, string $email ): array {
	$email = strtolower( trim( $email ) );
	$kept  = array_values( array_filter( $subs, static fn( $s ): bool => ( $s['email'] ?? '' ) !== $email ) );
	return [ 'subs' => $kept, 'removed' => count( $subs ) - count( $kept ) ];
}

/** Drop subscriptions older than the retention period. 0 days = no expiry configured. */
function foodify_stock_alert_expire( array $subs, int $now, int $days ): array {
	if ( $days <= 0 ) {
		return $subs;
	}
	return array_values( array_filter( $subs, static fn( $s ): bool => (int) ( $s['created'] ?? 0 ) > $now - $days * 86400 ) );
}

/**
 * The one email. Plain text: it is a notice, not a campaign, and a plain-text
 * mail is the hardest to mistake for marketing.
 *
 * @param array{product:string,url:string,site:string,asked:string} $d asked = "8 Nov 2026"
 * @return array{subject:string,body:string}
 */
function foodify_stock_alert_message( array $d ): array {
	$product = trim( (string) $d['product'] );
	$site    = trim( (string) $d['site'] );
	return [
		'subject' => "Back in stock: {$product}",
		'body'    => "{$product} is back in stock at {$site}.\n\n"
			. trim( (string) $d['url'] ) . "\n\n"
			. 'You asked us on ' . trim( (string) $d['asked'] ) . " to email you once when it returned. This is that email, and the only one: we have now deleted your address from this alert. We have not added it to any mailing list.\n\n"
			. "— {$site}\n",
	];
}

/** What the customer is told after each outcome. */
function foodify_stock_alert_notice( string $status, string $product = '', string $email = '' ): string {
	switch ( $status ) {
		case 'added':
		case 'exists':
			return "Done. We'll email {$email} once when {$product} is back, then delete your address.";
		case 'cancelled':
			return "Alert cancelled. We've deleted your address.";
		case 'invalid':
			return 'Please enter a valid email address.';
		case 'limited':
			return 'Too many requests from here. Please try again in an hour.';
		case 'full':
			return "We can't take more alerts for this product right now. Please check back.";
		case 'instock':
			return "Good news — it's back in stock. You can order it now.";
		default:
			return 'Something went wrong. Please try again.';
	}
}

/**
 * The form, or the confirmation in its place.
 *
 * @param array{product_id:int,product:string,action:string,nonce:string,privacy_url?:string,status?:string,email?:string,token?:string} $d
 */
function foodify_stock_alert_form_html( array $d ): string {
	$e      = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$id     = (int) $d['product_id'];
	$status = (string) ( $d['status'] ?? '' );
	$hidden = '<input type="hidden" name="product_id" value="' . $id . '">'
		. '<input type="hidden" name="_fdnonce" value="' . $e( (string) $d['nonce'] ) . '">';

	if ( in_array( $status, [ 'added', 'exists' ], true ) ) {
		$cancel = '' !== (string) ( $d['token'] ?? '' )
			? '<form class="fd-notify__cancel" method="post" action="' . $e( (string) $d['action'] ) . '">'
				. '<input type="hidden" name="action" value="foodify_stock_alert_cancel">' . $hidden
				. '<input type="hidden" name="token" value="' . $e( (string) $d['token'] ) . '">'
				. '<button type="submit" class="fd-notify__undo">Cancel this alert</button></form>'
			: '';
		return '<div class="fd-notify is-done" id="fd-notify" role="status">'
			. '<p class="fd-notify__msg">' . $e( foodify_stock_alert_notice( $status, (string) $d['product'], (string) ( $d['email'] ?? '' ) ) ) . '</p>'
			. $cancel . '</div>';
	}

	$note = '';
	if ( '' !== $status ) {
		$ok   = 'cancelled' === $status;
		$note = '<p class="fd-notify__' . ( $ok ? 'msg' : 'error' ) . '" role="' . ( $ok ? 'status' : 'alert' ) . '">'
			. $e( foodify_stock_alert_notice( $status ) ) . '</p>';
	}
	$privacy = '' !== (string) ( $d['privacy_url'] ?? '' )
		? ' <a href="' . $e( (string) $d['privacy_url'] ) . '">Privacy policy</a>'
		: '';
	$invalid = 'invalid' === $status ? ' aria-invalid="true"' : '';

	return '<form class="fd-notify" id="fd-notify" method="post" action="' . $e( (string) $d['action'] ) . '">'
		. '<p class="fd-notify__title">Sold out for now</p>'
		. $note
		. '<label class="fd-notify__label" for="fd-notify-email">Email me once when it’s back</label>'
		. '<span class="fd-notify__row">'
		. '<input class="fd-notify__input" id="fd-notify-email" type="email" name="email" autocomplete="email" inputmode="email" maxlength="254" required' . $invalid . ' aria-describedby="fd-notify-privacy">'
		. '<button class="fd-notify__go wp-element-button" type="submit">Notify me</button>'
		. '</span>'
		. '<input type="hidden" name="action" value="foodify_stock_alert">' . $hidden
		. '<span hidden><label>Leave this empty <input type="text" name="fd_website" tabindex="-1" autocomplete="off"></label></span>'
		. '<p class="fd-notify__privacy" id="fd-notify-privacy">One email, only about this product. We delete your address once it’s sent.' . $privacy . '</p>'
		. '</form>';
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

const FOODIFY_STOCK_ALERT_META = '_foodify_stock_alerts';

function foodify_stock_alerts_get( int $product_id ): array {
	$subs = get_post_meta( $product_id, FOODIFY_STOCK_ALERT_META, true );
	return is_array( $subs ) ? array_values( $subs ) : [];
}

function foodify_stock_alerts_put( int $product_id, array $subs ): void {
	if ( $subs ) {
		update_post_meta( $product_id, FOODIFY_STOCK_ALERT_META, array_values( $subs ) );
	} else {
		delete_post_meta( $product_id, FOODIFY_STOCK_ALERT_META );   // nothing kept that is not needed
	}
}

/** Retention in days from `foodify_stock_alert_retention_days`; 0 = not configured. */
function foodify_stock_alert_retention(): int {
	return max( 0, (int) get_option( 'foodify_stock_alert_retention_days', 0 ) );
}

/** Can this product take an alert right now? Sold out, simple, published, list not full. */
function foodify_stock_alerts_accepting( WC_Product $product ): bool {
	if ( ! apply_filters( 'foodify_stock_alerts_enabled', true, $product ) ) {
		return false;
	}
	if ( $product->is_in_stock() || $product->is_type( 'variable' ) || 'publish' !== $product->get_status() ) {
		return false;
	}
	return count( foodify_stock_alerts_get( $product->get_id() ) ) < FOODIFY_STOCK_ALERT_CAP;
}

/** Rate-limit bucket keys. Hashed, so a raw IP or address is never an option name. */
function foodify_stock_alert_bucket( string $kind, string $value ): string {
	return 'foodify_sa_' . $kind . '_' . substr( hash( 'sha256', $value . wp_salt( 'nonce' ) ), 0, 32 );
}

function foodify_stock_alert_limited( string $key, int $max, int $now ): bool {
	$times = get_transient( $key );
	$gate  = foodify_stock_alert_gate( is_array( $times ) ? $times : [], $now, $max, FOODIFY_STOCK_ALERT_WINDOW );
	if ( ! $gate['allowed'] ) {
		return true;
	}
	$gate['recent'][] = $now;
	set_transient( $key, $gate['recent'], FOODIFY_STOCK_ALERT_WINDOW );
	return false;
}

/** Back to the product page, with a one-time flash key in place of any token. */
function foodify_stock_alert_redirect( int $product_id, array $flash ): void {
	$key = bin2hex( random_bytes( 8 ) );
	set_transient( 'foodify_sa_flash_' . $key, $flash + [ 'product_id' => $product_id ], 10 * MINUTE_IN_SECONDS );
	$url = get_permalink( $product_id );
	wp_safe_redirect( add_query_arg( 'fd_alert', $key, $url ? $url : home_url( '/' ) ) . '#fd-notify', 303 );
	exit;
}

/** Shared request checks. Returns the product, or redirects with the reason. */
function foodify_stock_alert_request_product(): WC_Product {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below.
	$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	$nonce      = isset( $_POST['_fdnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_fdnonce'] ) ) : '';
	// phpcs:enable
	$product = $product_id ? wc_get_product( $product_id ) : null;
	if ( ! $product instanceof WC_Product ) {
		wp_safe_redirect( home_url( '/' ), 303 );
		exit;
	}
	if ( ! wp_verify_nonce( $nonce, 'foodify_stock_alert_' . $product_id ) ) {
		foodify_stock_alert_redirect( $product_id, [ 'status' => 'error' ] );
	}
	return $product;
}

/** Sign up. */
$foodify_stock_alert_subscribe = static function (): void {
	$product = foodify_stock_alert_request_product();
	$id      = $product->get_id();
	$now     = time();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in foodify_stock_alert_request_product().
	$honeypot = isset( $_POST['fd_website'] ) ? (string) wp_unslash( $_POST['fd_website'] ) : '';
	$raw      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	// phpcs:enable

	if ( '' !== $honeypot ) {
		foodify_stock_alert_redirect( $id, [ 'status' => 'added', 'email' => '' ] );   // tell a bot nothing
	}
	if ( $product->is_in_stock() ) {
		foodify_stock_alert_redirect( $id, [ 'status' => 'instock' ] );
	}
	if ( ! foodify_stock_alerts_accepting( $product ) ) {
		foodify_stock_alert_redirect( $id, [ 'status' => 'full' ] );
	}
	$email = foodify_stock_alert_email_ok( $raw );
	if ( null === $email ) {
		foodify_stock_alert_redirect( $id, [ 'status' => 'invalid' ] );
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( foodify_stock_alert_limited( foodify_stock_alert_bucket( 'ip', $ip ), FOODIFY_STOCK_ALERT_IP_MAX, $now )
		|| foodify_stock_alert_limited( foodify_stock_alert_bucket( 'em', $email ), FOODIFY_STOCK_ALERT_EMAIL_MAX, $now ) ) {
		foodify_stock_alert_redirect( $id, [ 'status' => 'limited' ] );
	}

	$token = bin2hex( random_bytes( 16 ) );
	$subs  = foodify_stock_alert_expire( foodify_stock_alerts_get( $id ), $now, foodify_stock_alert_retention() );
	$res   = foodify_stock_alert_add( $subs, $email, foodify_stock_alert_hash( $token ), $now );
	if ( in_array( $res['result'], [ 'added', 'exists' ], true ) ) {
		foodify_stock_alerts_put( $id, $res['subs'] );
		foodify_stock_alert_redirect( $id, [ 'status' => $res['result'], 'email' => $email, 'token' => $token ] );
	}
	foodify_stock_alert_redirect( $id, [ 'status' => $res['result'] ] );
};
add_action( 'admin_post_nopriv_foodify_stock_alert', $foodify_stock_alert_subscribe );
add_action( 'admin_post_foodify_stock_alert', $foodify_stock_alert_subscribe );

/** Withdraw. The token is the authority; the nonce stops a cross-site POST. */
$foodify_stock_alert_cancel = static function (): void {
	$product = foodify_stock_alert_request_product();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in foodify_stock_alert_request_product().
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	if ( preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		$res = foodify_stock_alert_remove( foodify_stock_alerts_get( $product->get_id() ), foodify_stock_alert_hash( $token ) );
		foodify_stock_alerts_put( $product->get_id(), $res['subs'] );
	}
	// Said whether or not a row matched: an already-sent or already-cancelled
	// alert is, from the customer's side, cancelled.
	foodify_stock_alert_redirect( $product->get_id(), [ 'status' => 'cancelled' ] );
};
add_action( 'admin_post_nopriv_foodify_stock_alert_cancel', $foodify_stock_alert_cancel );
add_action( 'admin_post_foodify_stock_alert_cancel', $foodify_stock_alert_cancel );

/** The form under the (absent) add-to-cart on a sold-out product page. */
add_filter( 'render_block_woocommerce/add-to-cart-form', static function ( string $html ): string {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product instanceof WC_Product || $product->is_in_stock() || $product->is_type( 'variable' ) ) {
		return $html;
	}
	$flash = [];
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a one-time display key, not a write.
	$key = isset( $_GET['fd_alert'] ) ? sanitize_key( wp_unslash( $_GET['fd_alert'] ) ) : '';
	if ( preg_match( '/^[a-f0-9]{16}$/', $key ) ) {
		$got = get_transient( 'foodify_sa_flash_' . $key );
		delete_transient( 'foodify_sa_flash_' . $key );   // once
		if ( is_array( $got ) && (int) ( $got['product_id'] ?? 0 ) === $product->get_id() ) {
			$flash = $got;
		}
	}
	$status = (string) ( $flash['status'] ?? '' );
	if ( '' === $status && ! foodify_stock_alerts_accepting( $product ) ) {
		return $html;
	}
	return $html . foodify_stock_alert_form_html( [
		'product_id'  => $product->get_id(),
		'product'     => $product->get_name(),
		'action'      => admin_url( 'admin-post.php' ),
		'nonce'       => wp_create_nonce( 'foodify_stock_alert_' . $product->get_id() ),
		'privacy_url' => (string) get_privacy_policy_url(),
		'status'      => $status,
		'email'       => (string) ( $flash['email'] ?? '' ),
		'token'       => (string) ( $flash['token'] ?? '' ),
	] ); // Escaped in the builder.
}, 20 );

/** The card's "Notify me" opens the product page at the form. */
add_filter( 'woocommerce_product_add_to_cart_url', static function ( string $url, WC_Product $product ): string {
	if ( $product->is_in_stock() || ! foodify_stock_alerts_accepting( $product ) ) {
		return $url;
	}
	return get_permalink( $product->get_id() ) . '#fd-notify';
}, 10, 2 );

/** Back in stock → queue the sends. Never inline: a restock is an admin save. */
$foodify_stock_alert_restocked = static function ( $product_id, $status ): void {
	$product_id = (int) $product_id;
	if ( 'instock' !== $status || ! foodify_stock_alerts_get( $product_id ) ) {
		return;
	}
	$args = [ 'product_id' => $product_id ];
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		if ( ! as_has_scheduled_action( 'foodify_stock_alert_send', $args, 'foodify' ) ) {
			as_enqueue_async_action( 'foodify_stock_alert_send', $args, 'foodify' );
		}
	} elseif ( ! wp_next_scheduled( 'foodify_stock_alert_send', $args ) ) {
		wp_schedule_single_event( time(), 'foodify_stock_alert_send', $args );
	}
};
add_action( 'woocommerce_product_set_stock_status', $foodify_stock_alert_restocked, 10, 2 );
add_action( 'woocommerce_variation_set_stock_status', $foodify_stock_alert_restocked, 10, 2 );

/** Send one batch; delete each subscription once its email is accepted. */
add_action( 'foodify_stock_alert_send', static function ( $product_id ): void {
	$product_id = (int) ( is_array( $product_id ) ? ( $product_id['product_id'] ?? 0 ) : $product_id );
	$product    = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product || ! $product->is_in_stock() || 'publish' !== $product->get_status() ) {
		return;   // sold out again before the queue ran: keep the alerts for the real restock
	}
	$now  = time();
	$subs = foodify_stock_alert_expire( foodify_stock_alerts_get( $product_id ), $now, foodify_stock_alert_retention() );
	$keep    = [];
	$sent    = 0;
	$waiting = 0;   // not attempted this run (beyond the batch)
	foreach ( $subs as $s ) {
		if ( $sent >= FOODIFY_STOCK_ALERT_BATCH ) {
			$keep[] = $s;
			$waiting++;
			continue;
		}
		$msg = foodify_stock_alert_message( [
			'product' => $product->get_name(),
			'url'     => (string) get_permalink( $product_id ),
			'site'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'asked'   => wp_date( 'j M Y', (int) ( $s['created'] ?? $now ) ),
		] );
		$sent++;
		if ( wp_mail( (string) $s['email'], $msg['subject'], $msg['body'], [ 'Content-Type: text/plain; charset=UTF-8' ] ) ) {
			continue;   // sent → gone
		}
		$s['tries'] = (int) ( $s['tries'] ?? 0 ) + 1;
		if ( $s['tries'] < FOODIFY_STOCK_ALERT_MAX_TRIES ) {
			$keep[] = $s;
		}
	}
	foodify_stock_alerts_put( $product_id, $keep );

	if ( $keep && function_exists( 'as_schedule_single_action' ) ) {
		// More than one batch: carry on now. Only failures left: try again in an hour.
		as_schedule_single_action( $waiting > 0 ? time() : time() + HOUR_IN_SECONDS, 'foodify_stock_alert_send', [ 'product_id' => $product_id ], 'foodify' );
	}
} );

/** Expire old subscriptions daily — only once a retention period is configured. */
add_action( 'init', static function (): void {
	if ( foodify_stock_alert_retention() > 0 && ! wp_next_scheduled( 'foodify_stock_alert_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'foodify_stock_alert_purge' );
	}
} );
add_action( 'foodify_stock_alert_purge', static function (): void {
	$days = foodify_stock_alert_retention();
	if ( $days <= 0 ) {
		return;
	}
	$ids = get_posts( [ 'post_type' => [ 'product', 'product_variation' ], 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => FOODIFY_STOCK_ALERT_META ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
	foreach ( $ids as $id ) {
		foodify_stock_alerts_put( (int) $id, foodify_stock_alert_expire( foodify_stock_alerts_get( (int) $id ), time(), $days ) );
	}
} );

/** WordPress's privacy tools: Tools → Export / Erase Personal Data. */
function foodify_stock_alert_products_for( string $email ): array {
	$ids = get_posts( [ 'post_type' => [ 'product', 'product_variation' ], 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => FOODIFY_STOCK_ALERT_META ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$email = strtolower( trim( $email ) );
	return array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool =>
		in_array( $email, array_column( foodify_stock_alerts_get( $id ), 'email' ), true ) ) );
}

add_filter( 'wp_privacy_personal_data_exporters', static function ( array $exporters ): array {
	$exporters['foodify-stock-alerts'] = [
		'exporter_friendly_name' => __( 'Back-in-stock alerts', 'foodify' ),
		'callback'               => static function ( string $email ): array {
			$data = [];
			foreach ( foodify_stock_alert_products_for( $email ) as $id ) {
				$data[] = [
					'group_id'    => 'foodify-stock-alerts',
					'group_label' => __( 'Back-in-stock alerts', 'foodify' ),
					'item_id'     => 'foodify-stock-alert-' . $id,
					'data'        => [ [ 'name' => __( 'Product', 'foodify' ), 'value' => get_the_title( $id ) ] ],
				];
			}
			return [ 'data' => $data, 'done' => true ];
		},
	];
	return $exporters;
} );

add_filter( 'wp_privacy_personal_data_erasers', static function ( array $erasers ): array {
	$erasers['foodify-stock-alerts'] = [
		'eraser_friendly_name' => __( 'Back-in-stock alerts', 'foodify' ),
		'callback'             => static function ( string $email ): array {
			$removed = 0;
			foreach ( foodify_stock_alert_products_for( $email ) as $id ) {
				$res = foodify_stock_alert_forget( foodify_stock_alerts_get( $id ), $email );
				foodify_stock_alerts_put( $id, $res['subs'] );
				$removed += $res['removed'];
			}
			return [ 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
		},
	];
	return $erasers;
} );

/** Name the unset policy option where the person who sets it will see it. */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) || foodify_stock_alert_retention() > 0 ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'edit-product', 'toplevel_page_foodify-today' ], true ) ) {
		return;
	}
	printf(
		'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'Foodify back-in-stock alerts:', 'foodify' ),
		esc_html__( 'the option foodify_stock_alert_retention_days is not set, so an alert for a product that never returns is kept until it is cancelled or erased. Set how many days an unsent alert may be kept (DPDP: no longer than needed).', 'foodify' )
	);
} );
