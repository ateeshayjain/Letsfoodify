<?php
/**
 * 12: repeat orders — "subscribe & save" WITHOUT auto-debit.
 *
 * A customer chooses "Repeat this order every 2 / 4 / 6 weeks" on an order. The
 * store never charges anyone. A few days before each date it sends ONE email
 * (and a WhatsApp, only if feature 9 is on and that order opted in) with a
 * signed one-tap link that puts the same items back in the cart — leaving out
 * anything sold out and saying which — and opens checkout. The customer pays
 * like any other order.
 *
 * SHIPS OFF. The feature exists only when option foodify_repeat_reminder_days
 * is set (1–7; suggested 2, client to confirm). A discount exists only when
 * foodify_repeat_discount is set; without one the copy says "Repeat this
 * order", never "Subscribe & save", because a "save" with nothing saved is the
 * fabricated-trust pattern this rebuild removed.
 *
 * THE DISCOUNT IS A COUPON, NOT A CART FEE — decided, because:
 *  - a coupon is a record: it appears on the order, in WooCommerce reports and
 *    on the GST invoice as a discount, where a negative fee is a line nobody
 *    audits;
 *  - single use is WooCommerce's own usage counter, not session state that a
 *    second tab or a cleared cookie can lose;
 *  - it is restricted to the customer's email and to the products of THAT
 *    schedule, so a forwarded link or a padded cart gets nothing extra.
 * One coupon per reminder cycle: unique code, usage limit 1, expiring with the
 * link, individual use (no stacking with a partner or referral code), and a
 * cart may carry at most one repeat coupon. Skipping, pausing, cancelling or
 * missing a cycle deletes that cycle's unused coupon.
 *
 * "SINGLE USE" MEANS ONE ORDER, NOT ONE CLICK. Mail scanners (Outlook Safe
 * Links, corporate gateways) open every link in an email before the person
 * does. A token burned on first open would arrive dead. So opening the link is
 * harmless and repeatable — it only fills a cart — and the token dies when an
 * order is placed through it (its nonce rotates), when the cycle is skipped,
 * paused or missed, or when it expires.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const FOODIFY_REPEAT_META      = '_foodify_repeat_orders';  // user meta: list of schedules
const FOODIFY_REPEAT_MAX       = 10;                        // schedules per customer
const FOODIFY_COUPON_KIND_META = '_foodify_coupon_kind';    // repeat | referral_code | referral_reward

/* -------------------------------------------------------------------------
 * Pure — tested in tests/retention-test.php without WordPress.
 * ---------------------------------------------------------------------- */

function foodify_b64url( string $bin ): string {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

function foodify_b64url_decode( string $s ): ?string {
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]*$/', $s ) ) {
		return null;
	}
	$bin = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
	return false === $bin ? null : $bin;
}

/**
 * A signed link token: base64url(claims JSON) . "." . base64url(HMAC-SHA256).
 *
 * Claims used by this lane: p (purpose), u (subject id), s (schedule id),
 * n (nonce — what makes it single-use), e (expiry, unix seconds).
 * The key is per-purpose (foodify_link_key()), so a cart-recovery token can
 * never be replayed as a repeat-order token even with identical claims.
 */
function foodify_link_token_sign( array $claims, string $key ): string {
	$body = foodify_b64url( (string) json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
	return $body . '.' . foodify_b64url( hash_hmac( 'sha256', $body, $key, true ) );
}

/**
 * The claims of a valid token, or null. Valid = well formed, signature matches
 * (constant-time), purpose matches, not expired, carries a nonce. Whether the
 * nonce is still the CURRENT one is the caller's check against storage.
 */
function foodify_link_token_verify( string $token, string $key, string $purpose, int $now ): ?array {
	if ( '' === $key || strlen( $token ) > 1024 || 1 !== substr_count( $token, '.' ) ) {
		return null;
	}
	[ $body, $sig ] = explode( '.', $token );
	$expect = foodify_b64url( hash_hmac( 'sha256', $body, $key, true ) );
	if ( ! hash_equals( $expect, $sig ) ) {
		return null;
	}
	$json   = foodify_b64url_decode( $body );
	$claims = null === $json ? null : json_decode( $json, true );
	if ( ! is_array( $claims ) ) {
		return null;
	}
	if ( ( $claims['p'] ?? null ) !== $purpose || ! is_int( $claims['e'] ?? null ) || $claims['e'] < $now ) {
		return null;
	}
	if ( ! is_string( $claims['n'] ?? null ) || '' === $claims['n'] ) {
		return null;
	}
	return $claims;
}

/** The intervals the customer may choose, in weeks. From the approved brief. */
function foodify_repeat_intervals(): array {
	return [ 2, 4, 6 ];
}

/** Calendar arithmetic on Y-m-d strings. No clock, no timezone drift. */
function foodify_date_add_days( string $ymd, int $days ): string {
	$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
	if ( ! $d ) {
		return '';
	}
	return $d->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
}

function foodify_repeat_next_date( string $from, int $weeks ): string {
	return foodify_date_add_days( $from, 7 * $weeks );
}

/** "Thu 12 Nov" — how a date reads to a customer. */
function foodify_date_label( string $ymd ): string {
	$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
	return $d ? $d->format( 'D j M' ) : '';
}

/** "10%", "7.5%". */
function foodify_pct_label( float $pct ): string {
	return rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' ) . '%';
}

/** The discount option as a percentage, or 0 when unset / not a sane number. */
function foodify_repeat_discount_pct( $raw ): float {
	if ( ! is_numeric( $raw ) ) {
		return 0.0;
	}
	$pct = round( (float) $raw, 2 );
	return ( $pct > 0 && $pct < 100 ) ? $pct : 0.0;
}

/**
 * @return array{active:bool,half:bool,missing:string[],lead:int,pct:float}
 */
function foodify_repeat_config_state( string $lead_raw, string $pct_raw, bool $has_scheduler ): array {
	$lead    = ctype_digit( trim( $lead_raw ) ) ? (int) $lead_raw : 0;
	$pct     = foodify_repeat_discount_pct( $pct_raw );
	$missing = [];
	if ( $lead < 1 || $lead > 7 ) {
		$missing[] = 'foodify_repeat_reminder_days (1–7)';
	}
	if ( '' !== trim( $pct_raw ) && 0.0 === $pct ) {
		$missing[] = 'foodify_repeat_discount (a number between 0 and 100, or unset for no discount)';
	}
	if ( ! $has_scheduler ) {
		$missing[] = 'Action Scheduler (bundled with WooCommerce)';
	}
	$touched = '' !== trim( $lead_raw ) || '' !== trim( $pct_raw );
	return [
		'active'  => [] === $missing,
		'half'    => [] !== $missing && $touched,
		'missing' => $missing,
		'lead'    => $lead,
		'pct'     => $pct,
	];
}

/** Every customer-facing phrase that depends on whether there is a discount. */
function foodify_repeat_labels( float $pct, int $lead ): array {
	$when = 1 === $lead ? 'the day before' : $lead . ' days before';
	$base = 'We email you ' . $when . ' each date with a one-tap link to check out. Nothing is charged automatically — you confirm and pay each time, and you can skip, pause or cancel whenever you like.';
	if ( $pct > 0 ) {
		return [
			'title' => 'Subscribe & save ' . foodify_pct_label( $pct ),
			'lead'  => 'Get ' . foodify_pct_label( $pct ) . ' off every repeat of this order. ' . $base,
			'cta'   => 'Set up repeat',
			'menu'  => 'Repeat orders',
		];
	}
	return [
		'title' => 'Repeat this order',
		'lead'  => $base,
		'cta'   => 'Set up repeat',
		'menu'  => 'Repeat orders',
	];
}

/**
 * A new schedule, or null when the input cannot make one.
 *
 * @param array<int,array{product_id:int,variation_id?:int,qty:int}> $items
 */
function foodify_repeat_new( array $items, int $weeks, string $today, string $address_id, int $source_order, string $id, string $nonce ): ?array {
	if ( ! in_array( $weeks, foodify_repeat_intervals(), true ) || '' === foodify_date_add_days( $today, 0 ) ) {
		return null;
	}
	$clean = [];
	foreach ( $items as $it ) {
		$pid = (int) ( $it['product_id'] ?? 0 );
		$qty = (int) ( $it['qty'] ?? 0 );
		if ( $pid > 0 && $qty > 0 ) {
			$clean[] = [ 'product_id' => $pid, 'variation_id' => max( 0, (int) ( $it['variation_id'] ?? 0 ) ), 'qty' => min( $qty, 99 ) ];
		}
	}
	if ( ! $clean ) {
		return null;
	}
	return [
		'id'           => $id,
		'items'        => $clean,
		'weeks'        => $weeks,
		'next'         => foodify_repeat_next_date( $today, $weeks ),
		'address_id'   => $address_id,
		'status'       => 'active',
		'source_order' => $source_order,
		'nonce'        => $nonce,
		'reminded_for' => '',
		'coupon'       => '',
	];
}

/**
 * The last day a cycle's link works: the day before the NEXT cycle's reminder
 * would go out. No invented grace period — the cycle simply ends where the
 * next one begins.
 */
function foodify_repeat_link_last_day( array $s, int $lead ): string {
	return foodify_date_add_days( (string) $s['next'], 7 * (int) $s['weeks'] - $lead - 1 );
}

/** Is a reminder due today? At most one per cycle. */
function foodify_repeat_reminder_due( array $s, string $today, int $lead ): bool {
	if ( 'active' !== ( $s['status'] ?? '' ) || $lead < 1 ) {
		return false;
	}
	if ( ( $s['reminded_for'] ?? '' ) === $s['next'] ) {
		return false;
	}
	return $today >= foodify_date_add_days( (string) $s['next'], -$lead ) && $today <= foodify_repeat_link_last_day( $s, $lead );
}

/**
 * A cycle nobody ordered from rolls forward when its link stops working.
 *
 * @return array{schedule:array,rolled:bool,dropped_coupon:string}
 */
function foodify_repeat_roll_missed( array $s, string $today, int $lead, string $new_nonce ): array {
	$rolled  = false;
	$dropped = '';
	$guard   = 0;
	while ( 'active' === ( $s['status'] ?? '' ) && $today > foodify_repeat_link_last_day( $s, $lead ) && $guard++ < 60 ) {
		$dropped           = '' !== $s['coupon'] ? $s['coupon'] : $dropped;
		$s['next']         = foodify_repeat_next_date( (string) $s['next'], (int) $s['weeks'] );
		$s['reminded_for'] = '';
		$s['coupon']       = '';
		$s['nonce']        = $new_nonce;
		$rolled            = true;
	}
	return [ 'schedule' => $s, 'rolled' => $rolled, 'dropped_coupon' => $dropped ];
}

/**
 * An order was placed through the link. Early keeps the rhythm (next is
 * counted from the scheduled date); late restarts it from today.
 */
function foodify_repeat_advance( array $s, string $today, string $new_nonce ): array {
	$base              = max( (string) $s['next'], $today );
	$s['next']         = foodify_repeat_next_date( $base, (int) $s['weeks'] );
	$s['reminded_for'] = '';
	$s['coupon']       = '';
	$s['nonce']        = $new_nonce;
	return $s;
}

/**
 * Pause / resume / skip / interval / cancel, from My Account.
 *
 * Every verb that ends the current cycle rotates the nonce, so the link
 * already sitting in the inbox stops working, and reports the cycle's coupon
 * so the caller can delete it.
 *
 * @return array{schedules:array,ok:bool,message:string,drop_coupon:string}
 */
function foodify_repeat_apply( array $schedules, string $id, string $action, array $args, string $today, string $new_nonce ): array {
	foreach ( $schedules as $i => $s ) {
		if ( ( $s['id'] ?? '' ) !== $id ) {
			continue;
		}
		$drop = (string) ( $s['coupon'] ?? '' );
		switch ( $action ) {
			case 'pause':
				$s['status'] = 'paused';
				$s['coupon'] = '';
				$s['nonce']  = $new_nonce;
				$schedules[ $i ] = $s;
				return [ 'schedules' => $schedules, 'ok' => true, 'message' => 'Paused. No reminders until you resume.', 'drop_coupon' => $drop ];

			case 'resume':
				$guard = 0;
				while ( $s['next'] < $today && $guard++ < 60 ) {
					$s['next'] = foodify_repeat_next_date( (string) $s['next'], (int) $s['weeks'] );
				}
				$s['status']       = 'active';
				$s['reminded_for'] = '';
				$s['nonce']        = $new_nonce;
				$schedules[ $i ]   = $s;
				return [ 'schedules' => $schedules, 'ok' => true, 'message' => 'Resumed. Next order: ' . foodify_date_label( $s['next'] ) . '.', 'drop_coupon' => '' ];

			case 'skip':
				$s['next']         = foodify_repeat_next_date( max( (string) $s['next'], $today ), (int) $s['weeks'] );
				$s['reminded_for'] = '';
				$s['coupon']       = '';
				$s['nonce']        = $new_nonce;
				$schedules[ $i ]   = $s;
				return [ 'schedules' => $schedules, 'ok' => true, 'message' => 'Skipped. Next order: ' . foodify_date_label( $s['next'] ) . '.', 'drop_coupon' => $drop ];

			case 'interval':
				$weeks = (int) ( $args['weeks'] ?? 0 );
				if ( ! in_array( $weeks, foodify_repeat_intervals(), true ) ) {
					return [ 'schedules' => $schedules, 'ok' => false, 'message' => 'Choose 2, 4 or 6 weeks.', 'drop_coupon' => '' ];
				}
				$s['weeks']      = $weeks;
				$schedules[ $i ] = $s;
				return [ 'schedules' => $schedules, 'ok' => true, 'message' => 'Now every ' . $weeks . ' weeks. Next order: ' . foodify_date_label( $s['next'] ) . '.', 'drop_coupon' => '' ];

			case 'cancel':
				unset( $schedules[ $i ] );
				return [ 'schedules' => array_values( $schedules ), 'ok' => true, 'message' => 'Cancelled. No more reminders for this order.', 'drop_coupon' => $drop ];
		}
		return [ 'schedules' => $schedules, 'ok' => false, 'message' => 'That is not something a repeat order can do.', 'drop_coupon' => '' ];
	}
	return [ 'schedules' => $schedules, 'ok' => false, 'message' => 'That repeat order no longer exists.', 'drop_coupon' => '' ];
}

/**
 * Plan a cart rebuild from saved lines and the current catalogue. Shared by
 * repeat orders and cart recovery (13).
 *
 * @param array<int,array{product_id:int,variation_id?:int,qty:int}> $lines
 * @param array<string,array{name:string,purchasable:bool,in_stock:bool,max?:?int}> $catalog
 *        keyed "product_id:variation_id"; a missing key means the product is gone.
 * @return array{add:array<int,array{product_id:int,variation_id:int,qty:int}>,skipped:string[],reduced:array<int,array{name:string,from:int,to:int}>,gone:int,message:string}
 */
function foodify_cart_rebuild_plan( array $lines, array $catalog ): array {
	$add = $skipped = $reduced = [];
	$gone = 0;
	foreach ( $lines as $l ) {
		$pid = (int) ( $l['product_id'] ?? 0 );
		$vid = (int) ( $l['variation_id'] ?? 0 );
		$qty = (int) ( $l['qty'] ?? 0 );
		if ( $pid < 1 || $qty < 1 ) {
			continue;
		}
		$p = $catalog[ $pid . ':' . $vid ] ?? null;
		if ( ! is_array( $p ) ) {
			$gone++;
			continue;
		}
		if ( empty( $p['purchasable'] ) || empty( $p['in_stock'] ) ) {
			$skipped[] = (string) $p['name'];
			continue;
		}
		$max = $p['max'] ?? null;
		if ( is_int( $max ) && $max < 1 ) {
			$skipped[] = (string) $p['name'];
			continue;
		}
		if ( is_int( $max ) && $qty > $max ) {
			$reduced[] = [ 'name' => (string) $p['name'], 'from' => $qty, 'to' => $max ];
			$qty       = $max;
		}
		$add[] = [ 'product_id' => $pid, 'variation_id' => $vid, 'qty' => $qty ];
	}

	$join = static function ( array $names ): string {
		if ( count( $names ) < 2 ) {
			return (string) ( $names[0] ?? '' );
		}
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' and ' . $last;
	};
	$parts = [];
	if ( ! $add ) {
		$parts[] = 'Nothing from this order is available right now, so your cart is unchanged.';
	} else {
		$n       = count( $add );
		$parts[] = 'We added ' . ( 1 === $n ? '1 item' : $n . ' items' ) . ' to your cart.';
	}
	if ( $skipped ) {
		$parts[] = $join( $skipped ) . ( 1 === count( $skipped ) ? ' is' : ' are' ) . ' sold out, so we left ' . ( 1 === count( $skipped ) ? 'it' : 'them' ) . ' out.';
	}
	foreach ( $reduced as $r ) {
		$parts[] = 'Only ' . $r['to'] . ' ' . $r['name'] . ' left, so your cart has ' . $r['to'] . ' instead of ' . $r['from'] . '.';
	}
	if ( $gone ) {
		$parts[] = ( 1 === $gone ? '1 item is' : $gone . ' items are' ) . ' no longer on the menu.';
	}
	return [ 'add' => $add, 'skipped' => $skipped, 'reduced' => $reduced, 'gone' => $gone, 'message' => implode( ' ', $parts ) ];
}

/**
 * May this coupon join the cart? A cart carries at most one repeat coupon —
 * the same schedule's code twice is already refused by WooCommerce, two
 * cycles' codes are refused here.
 *
 * @param array<string,string> $applied code => kind, for coupons already on the cart.
 */
function foodify_repeat_coupon_allowed( string $code, string $kind, array $applied ): bool {
	if ( 'repeat' !== $kind ) {
		return true;
	}
	foreach ( $applied as $other => $other_kind ) {
		if ( 'repeat' === $other_kind && strtolower( (string) $other ) !== strtolower( $code ) ) {
			return false;
		}
	}
	return true;
}

/** "Dal Fry × 2, Poha" from items with names resolved. @param array<int,array{name:string,qty:int}> $named */
function foodify_repeat_summary( array $named ): string {
	return implode( ', ', array_map( static fn( $i ): string => (string) $i['name'] . ( (int) $i['qty'] > 1 ? ' × ' . (int) $i['qty'] : '' ), $named ) );
}

/**
 * The offer on an order page.
 *
 * @param array{order_id:int,labels:array,nonce_field:string,existing?:?array{weeks:int,next:string},manage_url?:string} $d
 */
function foodify_repeat_offer_html( array $d ): string {
	$e   = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$oid = (int) $d['order_id'];
	$l   = $d['labels'];
	if ( ! empty( $d['existing'] ) ) {
		return '<section class="fd-repeat is-set" aria-labelledby="fd-repeat-title-' . $oid . '">'
			. '<h2 class="fd-repeat__title" id="fd-repeat-title-' . $oid . '">' . $e( (string) $l['title'] ) . '</h2>'
			. '<p class="fd-repeat__lead">This order repeats every ' . (int) $d['existing']['weeks'] . ' weeks. Next order: ' . $e( foodify_date_label( (string) $d['existing']['next'] ) ) . '.</p>'
			. '<a class="fd-secondary" href="' . $e( (string) ( $d['manage_url'] ?? '' ) ) . '">Manage repeat orders</a></section>';
	}
	$opts = '';
	foreach ( foodify_repeat_intervals() as $w ) {
		$opts .= '<option value="' . $w . '">' . $w . ' weeks</option>';
	}
	return '<section class="fd-repeat" aria-labelledby="fd-repeat-title-' . $oid . '">'
		. '<h2 class="fd-repeat__title" id="fd-repeat-title-' . $oid . '">' . $e( (string) $l['title'] ) . '</h2>'
		. '<p class="fd-repeat__lead">' . $e( (string) $l['lead'] ) . '</p>'
		. '<form method="post" class="fd-repeat__form">' . $d['nonce_field']
		. '<input type="hidden" name="foodify_repeat_action" value="create">'
		. '<input type="hidden" name="order_id" value="' . $oid . '">'
		. '<label class="fd-repeat__label" for="fd-repeat-weeks-' . $oid . '">Repeat this order every</label>'
		. '<select class="fd-repeat__select" id="fd-repeat-weeks-' . $oid . '" name="weeks">' . $opts . '</select>'
		. '<button type="submit" class="wp-element-button">' . $e( (string) $l['cta'] ) . '</button>'
		. '</form></section>';
}

/**
 * My Account → Repeat orders.
 *
 * @param array<int,array{id:string,weeks:int,next:string,status:string,summary:string}> $rows
 * @param array{lead:int,active:bool,nonces:array<string,string>} $d
 */
function foodify_repeat_list_html( array $rows, array $d ): string {
	$e     = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$lead  = (int) $d['lead'];
	$nonce = static fn( string $a ): string => (string) ( $d['nonces'][ $a ] ?? '' );
	$verb  = static function ( string $action, string $id, string $label, string $class = 'fd-secondary', string $extra = '' ) use ( $e, $nonce ): string {
		return '<form method="post" class="fd-repeat-item__verb">' . $nonce( $action )
			. '<input type="hidden" name="foodify_repeat_action" value="' . $e( $action ) . '">'
			. '<input type="hidden" name="schedule_id" value="' . $e( $id ) . '">' . $extra
			. '<button type="submit" class="' . $e( $class ) . '">' . $e( $label ) . '</button></form>';
	};

	$out = '';
	if ( empty( $d['active'] ) ) {
		$out .= '<p class="fd-account-lead">Repeat-order reminders are switched off at the moment, so none will be sent.</p>';
	}
	if ( ! $rows ) {
		return $out . '<p class="fd-account-lead">No repeat orders yet. Open any order and choose “Repeat this order”.</p>';
	}
	$out .= '<ul class="fd-repeat-list">';
	foreach ( $rows as $r ) {
		$id     = (string) $r['id'];
		$paused = 'paused' === $r['status'];
		$opts   = '';
		foreach ( foodify_repeat_intervals() as $w ) {
			$opts .= '<option value="' . $w . '"' . ( $w === (int) $r['weeks'] ? ' selected' : '' ) . '>' . $w . ' weeks</option>';
		}
		$next = $paused
			? 'Paused — no reminders until you resume.'
			: 'Next order: ' . foodify_date_label( (string) $r['next'] ) . ' · reminder ' . foodify_date_label( foodify_date_add_days( (string) $r['next'], -$lead ) );
		$out .= '<li class="fd-repeat-item' . ( $paused ? ' is-paused' : '' ) . '" id="repeat-' . $e( $id ) . '">'
			. '<div class="fd-repeat-item__head"><span class="fd-repeat-item__title">Every ' . (int) $r['weeks'] . ' weeks</span>'
			. '<span class="fd-repeat-item__status">' . ( $paused ? 'Paused' : 'Active' ) . '</span></div>'
			. '<p class="fd-repeat-item__items">' . $e( (string) $r['summary'] ) . '</p>'
			. '<p class="fd-repeat-item__next">' . $e( $next ) . '</p>'
			. '<div class="fd-repeat-item__actions">'
			. ( $paused ? $verb( 'resume', $id, 'Resume' ) : $verb( 'pause', $id, 'Pause' ) . $verb( 'skip', $id, 'Skip next' ) )
			. '<form method="post" class="fd-repeat-item__verb fd-repeat-item__interval">' . $nonce( 'interval' )
			. '<input type="hidden" name="foodify_repeat_action" value="interval">'
			. '<input type="hidden" name="schedule_id" value="' . $e( $id ) . '">'
			. '<label class="screen-reader-text" for="fd-repeat-weeks-' . $e( $id ) . '">How often</label>'
			. '<select class="fd-repeat__select" id="fd-repeat-weeks-' . $e( $id ) . '" name="weeks">' . $opts . '</select>'
			. '<button type="submit" class="fd-secondary">Change</button></form>'
			. $verb( 'cancel', $id, 'Cancel', 'fd-secondary fd-danger' )
			. '</div></li>';
	}
	return $out . '</ul>';
}

/**
 * The reminder email.
 *
 * @param array{first_name:string,date:string,summary:string,link:string,manage:string,pct:float} $d
 * @return array{subject:string,heading:string,body:string}
 */
function foodify_repeat_email( array $d ): array {
	$e     = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$date  = foodify_date_label( (string) $d['date'] );
	$name  = '' !== trim( (string) $d['first_name'] ) ? trim( (string) $d['first_name'] ) : 'there';
	$saving = (float) $d['pct'] > 0
		? '<p>Your ' . $e( foodify_pct_label( (float) $d['pct'] ) ) . ' repeat-order discount is applied when you check out through this link.</p>'
		: '';
	return [
		'subject' => 'Your Foodify repeat order is due ' . $date,
		'heading' => 'Time for your next order',
		'body'    => '<p>Hi ' . $e( $name ) . ',</p>'
			. '<p>Your repeat order is due on ' . $e( $date ) . ': ' . $e( (string) $d['summary'] ) . '.</p>'
			. '<p><a href="' . $e( (string) $d['link'] ) . '">Check out this order</a></p>'
			. $saving
			. '<p>The link puts these items in your cart and opens checkout. Anything sold out is left out, and we say which. Nothing is charged until you pay.</p>'
			. '<p>Not this time? <a href="' . $e( (string) $d['manage'] ) . '">Skip, pause or change it</a> in your account.</p>',
	];
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;
}

/**
 * A nonce input WITHOUT an id. wp_nonce_field() prints id="foodify_repeat_nonce"
 * on every form, and the account list holds several forms per schedule —
 * duplicate ids on one page.
 */
function foodify_repeat_nonce_input( string $action ): string {
	return '<input type="hidden" name="foodify_repeat_nonce" value="' . esc_attr( wp_create_nonce( 'foodify_repeat_' . $action ) ) . '">';
}

/** Per-purpose HMAC key derived from the site's auth salt. */
function foodify_link_key( string $purpose ): string {
	return hash_hmac( 'sha256', 'foodify-link|' . $purpose, wp_salt( 'auth' ) );
}

function foodify_link_nonce(): string {
	return bin2hex( random_bytes( 8 ) );
}

/** Today in the store's timezone. */
function foodify_site_today(): string {
	return wp_date( 'Y-m-d' );
}

/** 23:59:59 on a Y-m-d in the store's timezone, as unix seconds. */
function foodify_end_of_day_ts( string $ymd ): int {
	$d = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $ymd . ' 23:59:59', wp_timezone() );
	return $d ? $d->getTimestamp() : 0;
}

function foodify_repeat_state(): array {
	static $state = null;
	if ( null === $state ) {
		$state = foodify_repeat_config_state(
			(string) get_option( 'foodify_repeat_reminder_days', '' ),
			(string) get_option( 'foodify_repeat_discount', '' ),
			function_exists( 'as_schedule_recurring_action' )
		);
	}
	return $state;
}

function foodify_repeat_get( int $user_id ): array {
	$s = get_user_meta( $user_id, FOODIFY_REPEAT_META, true );
	return is_array( $s ) ? array_values( $s ) : [];
}

function foodify_repeat_save( int $user_id, array $schedules ): void {
	if ( $schedules ) {
		update_user_meta( $user_id, FOODIFY_REPEAT_META, array_values( $schedules ) );
	} else {
		delete_user_meta( $user_id, FOODIFY_REPEAT_META );
	}
}

/**
 * The current catalogue for some saved lines, in the shape the plan reads.
 * Shared with cart recovery.
 */
function foodify_cart_catalog( array $lines ): array {
	$out = [];
	foreach ( $lines as $l ) {
		$pid = (int) ( $l['product_id'] ?? 0 );
		$vid = (int) ( $l['variation_id'] ?? 0 );
		$p   = wc_get_product( $vid ?: $pid );
		if ( ! $p instanceof WC_Product || 'publish' !== get_post_status( $pid ) ) {
			continue;
		}
		$out[ $pid . ':' . $vid ] = [
			'name'        => $p->get_name(),
			'purchasable' => $p->is_purchasable(),
			'in_stock'    => $p->is_in_stock(),
			'max'         => $p->managing_stock() && ! $p->backorders_allowed() ? (int) $p->get_stock_quantity() : null,
		];
	}
	return $out;
}

/** Put a plan's lines in the cart WITHOUT emptying what is already there. Returns lines touched. */
function foodify_cart_apply_plan( array $plan ): int {
	if ( ! WC()->cart ) {
		return 0;
	}
	$present = [];
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$present[ (int) $item['product_id'] . ':' . (int) $item['variation_id'] ] = [ $key, (int) $item['quantity'] ];
	}
	$n = 0;
	foreach ( $plan['add'] as $l ) {
		$k = $l['product_id'] . ':' . $l['variation_id'];
		if ( isset( $present[ $k ] ) ) {
			if ( $present[ $k ][1] < $l['qty'] ) {
				WC()->cart->set_quantity( $present[ $k ][0], $l['qty'] );
			}
			$n++;
			continue;
		}
		$attrs = $l['variation_id'] ? wc_get_product_variation_attributes( $l['variation_id'] ) : [];
		if ( WC()->cart->add_to_cart( $l['product_id'], $l['qty'], $l['variation_id'], $attrs ) ) {
			$n++;
		}
	}
	return $n;
}

/** Items with names, for the account list and the email. */
function foodify_repeat_named_items( array $items ): array {
	$named = [];
	foreach ( $items as $it ) {
		$p       = wc_get_product( ( (int) $it['variation_id'] ) ?: (int) $it['product_id'] );
		$named[] = [ 'name' => $p instanceof WC_Product ? $p->get_name() : __( 'An item no longer sold', 'foodify' ), 'qty' => (int) $it['qty'] ];
	}
	return $named;
}

/** Delete a cycle's coupon, but never one somebody already used. */
function foodify_repeat_drop_coupon( string $code ): void {
	if ( '' === $code ) {
		return;
	}
	$id = (int) wc_get_coupon_id_by_code( $code );
	if ( $id && 'repeat' === (string) get_post_meta( $id, FOODIFY_COUPON_KIND_META, true ) && 0 === ( new WC_Coupon( $id ) )->get_usage_count() ) {
		wp_delete_post( $id, true );
	}
}

/** One coupon per cycle, restricted to this customer and this schedule's products. */
function foodify_repeat_issue_coupon( WP_User $user, array $s, float $pct, string $last_day ): string {
	$code   = 'repeat-' . strtolower( wp_generate_password( 10, false, false ) );
	$coupon = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( $pct );
	$coupon->set_individual_use( true );
	$coupon->set_usage_limit( 1 );
	$coupon->set_usage_limit_per_user( 1 );
	$coupon->set_email_restrictions( [ $user->user_email ] );
	$ids = [];
	foreach ( $s['items'] as $it ) {
		$ids[] = (int) $it['product_id'];
		if ( (int) $it['variation_id'] ) {
			$ids[] = (int) $it['variation_id'];
		}
	}
	$coupon->set_product_ids( array_values( array_unique( $ids ) ) );
	$coupon->set_date_expires( foodify_end_of_day_ts( $last_day ) );
	$coupon->set_description( 'Repeat-order discount. Issued automatically for one reminder cycle; single use.' );
	$coupon->update_meta_data( FOODIFY_COUPON_KIND_META, 'repeat' );
	$coupon->update_meta_data( '_foodify_repeat_ref', $user->ID . ':' . $s['id'] );
	$coupon->save();
	return $code;
}

/** Send one reminder. Returns the schedule as it now stands. */
function foodify_repeat_send_reminder( WP_User $user, array $s, array $cfg ): array {
	$last_day = foodify_repeat_link_last_day( $s, $cfg['lead'] );
	if ( $cfg['pct'] > 0 && '' === $s['coupon'] ) {
		$s['coupon'] = foodify_repeat_issue_coupon( $user, $s, $cfg['pct'], $last_day );
	}
	$token = foodify_link_token_sign(
		[ 'p' => 'repeat', 'u' => $user->ID, 's' => $s['id'], 'n' => $s['nonce'], 'e' => foodify_end_of_day_ts( $last_day ) ],
		foodify_link_key( 'repeat' )
	);
	$source = wc_get_order( (int) $s['source_order'] );
	$first  = $source instanceof WC_Order ? (string) $source->get_billing_first_name() : (string) $user->first_name;
	$mail   = foodify_repeat_email( [
		'first_name' => $first,
		'date'       => $s['next'],
		'summary'    => foodify_repeat_summary( foodify_repeat_named_items( $s['items'] ) ),
		'link'       => add_query_arg( 'foodify_repeat', $token, wc_get_cart_url() ),
		'manage'     => wc_get_account_endpoint_url( 'repeat-orders' ),
		'pct'        => $cfg['pct'],
	] );
	$mailer = WC()->mailer();
	$mailer->send( $user->user_email, $mail['subject'], $mailer->wrap_message( $mail['heading'], $mail['body'] ) );

	if ( $source instanceof WC_Order && function_exists( 'foodify_wa_active' ) && foodify_wa_active() ) {
		foodify_wa_send_repeat_reminder( $source, foodify_date_label( $s['next'] ), $token );
	}
	$s['reminded_for'] = $s['next'];
	return $s;
}

/* ---- the daily pass ------------------------------------------------------ */

add_action( 'init', static function (): void {
	if ( ! foodify_repeat_state()['active'] || get_transient( 'foodify_repeat_daily_ok' ) ) {
		return;
	}
	if ( false === as_has_scheduled_action( 'foodify_repeat_daily' ) ) {
		$first = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', foodify_date_add_days( foodify_site_today(), 1 ) . ' 09:00', wp_timezone() );
		as_schedule_recurring_action( $first ? $first->getTimestamp() : time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'foodify_repeat_daily', [], 'foodify' );
	}
	set_transient( 'foodify_repeat_daily_ok', 1, DAY_IN_SECONDS );
} );

add_action( 'foodify_repeat_daily', static function (): void {
	$cfg = foodify_repeat_state();
	if ( ! $cfg['active'] ) {
		return;
	}
	$today = foodify_site_today();
	$users = get_users( [ 'meta_key' => FOODIFY_REPEAT_META, 'fields' => 'ID', 'number' => 2000 ] );
	foreach ( $users as $uid ) {
		$user = get_userdata( (int) $uid );
		if ( ! $user instanceof WP_User ) {
			continue;
		}
		$schedules = foodify_repeat_get( $user->ID );
		$changed   = false;
		foreach ( $schedules as $i => $s ) {
			$roll = foodify_repeat_roll_missed( $s, $today, $cfg['lead'], foodify_link_nonce() );
			if ( $roll['rolled'] ) {
				foodify_repeat_drop_coupon( $roll['dropped_coupon'] );
				$s       = $roll['schedule'];
				$changed = true;
			}
			if ( foodify_repeat_reminder_due( $s, $today, $cfg['lead'] ) ) {
				$s       = foodify_repeat_send_reminder( $user, $s, $cfg );
				$changed = true;
			}
			$schedules[ $i ] = $s;
		}
		if ( $changed ) {
			foodify_repeat_save( $user->ID, $schedules );
		}
	}
} );

/* ---- the one-tap link ---------------------------------------------------- */

add_action( 'template_redirect', static function (): void {
	if ( empty( $_GET['foodify_repeat'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$back = remove_query_arg( 'foodify_repeat' );
	$fail = static function ( string $message ) use ( $back ): void {
		wc_add_notice( $message, 'error' );
		wp_safe_redirect( $back );
		exit;
	};
	$token  = sanitize_text_field( wp_unslash( $_GET['foodify_repeat'] ) );
	$claims = foodify_link_token_verify( $token, foodify_link_key( 'repeat' ), 'repeat', time() );
	if ( ! $claims || ! foodify_repeat_state()['active'] ) {
		$fail( __( 'This repeat-order link has expired. Your repeat orders are in your account.', 'foodify' ) );
	}
	$uid = (int) ( $claims['u'] ?? 0 );
	if ( is_user_logged_in() && get_current_user_id() !== $uid ) {
		$fail( __( 'This link belongs to a different account.', 'foodify' ) );
	}
	$schedule = null;
	foreach ( foodify_repeat_get( $uid ) as $s ) {
		if ( $s['id'] === ( $claims['s'] ?? '' ) ) {
			$schedule = $s;
		}
	}
	if ( ! $schedule || 'active' !== $schedule['status'] || ! hash_equals( (string) $schedule['nonce'], (string) $claims['n'] ) ) {
		$fail( __( 'This link has already been used, or a newer reminder replaced it.', 'foodify' ) );
	}

	$plan  = foodify_cart_rebuild_plan( $schedule['items'], foodify_cart_catalog( $schedule['items'] ) );
	$added = foodify_cart_apply_plan( $plan );
	if ( $added && '' !== $schedule['coupon'] && ! WC()->cart->has_discount( $schedule['coupon'] ) ) {
		WC()->cart->apply_coupon( $schedule['coupon'] );
	}
	WC()->session->set( 'foodify_repeat_redeem', [ 'u' => $uid, 's' => $schedule['id'], 'n' => $schedule['nonce'] ] );
	if ( is_user_logged_in() && function_exists( 'foodify_address_find' ) && foodify_address_find( foodify_get_address_book( $uid ), (string) $schedule['address_id'] ) ) {
		WC()->session->set( 'foodify_chosen_address', (string) $schedule['address_id'] );
	}
	wc_add_notice( esc_html( $plan['message'] ), $plan['skipped'] || $plan['gone'] || $plan['reduced'] ? 'notice' : 'success' );
	if ( $added && ! is_user_logged_in() ) {
		wc_add_notice( __( 'Sign in to use your saved address, or fill it in below.', 'foodify' ), 'notice' );
	}
	wp_safe_redirect( $added ? wc_get_checkout_url() : $back );
	exit;
}, 1 );

/** An order through the link ends the cycle: the next one starts, the link dies. */
add_action( 'woocommerce_checkout_order_processed', static function ( $order_id ): void {
	$r = WC()->session ? WC()->session->get( 'foodify_repeat_redeem' ) : null;
	if ( ! is_array( $r ) ) {
		return;
	}
	WC()->session->set( 'foodify_repeat_redeem', null );
	$uid       = (int) $r['u'];
	$schedules = foodify_repeat_get( $uid );
	foreach ( $schedules as $i => $s ) {
		if ( $s['id'] === $r['s'] && hash_equals( (string) $s['nonce'], (string) $r['n'] ) ) {
			$schedules[ $i ] = foodify_repeat_advance( $s, foodify_site_today(), foodify_link_nonce() );
			foodify_repeat_save( $uid, $schedules );
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof WC_Order ) {
				$order->update_meta_data( '_foodify_repeat_schedule', $s['id'] );
				$order->save();
			}
		}
	}
}, 20 );

/** At most one repeat coupon per cart. */
add_filter( 'woocommerce_coupon_is_valid', static function ( bool $valid, WC_Coupon $coupon, $discounts = null ): bool {
	if ( ! $valid || ! $discounts instanceof WC_Discounts || ! $discounts->get_object() instanceof WC_Cart || ! WC()->cart ) {
		return $valid;
	}
	$applied = [];
	foreach ( WC()->cart->get_applied_coupons() as $code ) {
		$id               = (int) wc_get_coupon_id_by_code( $code );
		$applied[ $code ] = $id ? (string) get_post_meta( $id, FOODIFY_COUPON_KIND_META, true ) : '';
	}
	if ( ! foodify_repeat_coupon_allowed( $coupon->get_code(), (string) $coupon->get_meta( FOODIFY_COUPON_KIND_META ), $applied ) ) {
		throw new Exception( esc_html__( 'Only one repeat-order discount can be used on an order.', 'foodify' ), 100 );
	}
	return $valid;
}, 10, 3 );

/* ---- the offer on an order page ------------------------------------------ */

add_action( 'woocommerce_order_details_after_order_table', static function ( $order ): void {
	$cfg = foodify_repeat_state();
	if ( ! $cfg['active'] || ! $order instanceof WC_Order || ! is_user_logged_in() ) {
		return;
	}
	if ( (int) $order->get_customer_id() !== get_current_user_id() || ! in_array( $order->get_status(), [ 'processing', 'completed', 'on-hold' ], true ) ) {
		return;
	}
	$existing = null;
	foreach ( foodify_repeat_get( get_current_user_id() ) as $s ) {
		if ( (int) $s['source_order'] === $order->get_id() ) {
			$existing = [ 'weeks' => (int) $s['weeks'], 'next' => (string) $s['next'] ];
		}
	}
	echo foodify_repeat_offer_html( [ // phpcs:ignore WordPress.Security.EscapeOutput — escaped in the builder.
		'order_id'    => $order->get_id(),
		'labels'      => foodify_repeat_labels( $cfg['pct'], $cfg['lead'] ),
		'nonce_field' => foodify_repeat_nonce_input( 'create' ),
		'existing'    => $existing,
		'manage_url'  => wc_get_account_endpoint_url( 'repeat-orders' ),
	] );
}, 20 );

/* ---- mutations ------------------------------------------------------------ */

add_action( 'template_redirect', static function (): void {
	if ( empty( $_POST['foodify_repeat_action'] ) || ! is_user_logged_in() ) {
		return;
	}
	$action = sanitize_key( wp_unslash( $_POST['foodify_repeat_action'] ) );
	$nonce  = isset( $_POST['foodify_repeat_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['foodify_repeat_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'foodify_repeat_' . $action ) ) {
		wc_add_notice( __( 'That request expired. Please try again.', 'foodify' ), 'error' );
		return;
	}
	$uid       = get_current_user_id();
	$schedules = foodify_repeat_get( $uid );
	$back      = wp_get_referer() ?: wc_get_account_endpoint_url( 'repeat-orders' );
	$weeks     = isset( $_POST['weeks'] ) ? absint( wp_unslash( $_POST['weeks'] ) ) : 0;

	if ( 'create' === $action ) {
		if ( ! foodify_repeat_state()['active'] ) {
			return;
		}
		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0 );
		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== $uid ) {
			wc_add_notice( __( 'That order is not on your account.', 'foodify' ), 'error' );
			return;
		}
		foreach ( $schedules as $s ) {
			if ( (int) $s['source_order'] === $order->get_id() ) {
				wc_add_notice( __( 'This order already repeats. Change it under Repeat orders.', 'foodify' ), 'notice' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'repeat-orders' ) );
				exit;
			}
		}
		if ( count( $schedules ) >= FOODIFY_REPEAT_MAX ) {
			wc_add_notice( __( 'You have the most repeat orders an account can hold. Cancel one first.', 'foodify' ), 'error' );
			return;
		}
		$items = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$items[] = [ 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'qty' => (int) $item->get_quantity() ];
			}
		}
		// The address this order went to, if it is in the book; otherwise checkout's default.
		$address_id = '';
		if ( function_exists( 'foodify_address_fingerprint' ) ) {
			$fp = foodify_address_fingerprint( foodify_address_normalise( [
				'address_1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
				'address_2' => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
				'city'      => $order->get_shipping_city() ?: $order->get_billing_city(),
				'postcode'  => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
			] ) );
			foreach ( foodify_get_address_book( $uid ) as $a ) {
				if ( foodify_address_fingerprint( $a ) === $fp ) {
					$address_id = (string) $a['id'];
				}
			}
		}
		$new = foodify_repeat_new( $items, $weeks, foodify_site_today(), $address_id, $order->get_id(), 'r' . bin2hex( random_bytes( 5 ) ), foodify_link_nonce() );
		if ( ! $new ) {
			wc_add_notice( __( 'Choose 2, 4 or 6 weeks.', 'foodify' ), 'error' );
			return;
		}
		$schedules[] = $new;
		foodify_repeat_save( $uid, $schedules );
		wc_add_notice( esc_html( sprintf(
			/* translators: 1: weeks, 2: date such as Thu 12 Nov */
			__( 'Done. This order repeats every %1$d weeks — next on %2$s. We will email you before each one.', 'foodify' ),
			$new['weeks'],
			foodify_date_label( $new['next'] )
		) ), 'success' );
		wp_safe_redirect( $back );
		exit;
	}

	$id     = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
	$result = foodify_repeat_apply( $schedules, $id, $action, [ 'weeks' => $weeks ], foodify_site_today(), foodify_link_nonce() );
	if ( $result['ok'] ) {
		foodify_repeat_save( $uid, $result['schedules'] );
		foodify_repeat_drop_coupon( $result['drop_coupon'] );
	}
	wc_add_notice( esc_html( $result['message'] ), $result['ok'] ? 'success' : 'error' );
	wp_safe_redirect( $back );
	exit;
} );

/* ---- My Account → Repeat orders ------------------------------------------ */

add_action( 'init', static function (): void {
	add_rewrite_endpoint( 'repeat-orders', EP_ROOT | EP_PAGES );
} );

add_filter( 'woocommerce_get_query_vars', static function ( array $vars ): array {
	$vars['repeat-orders'] = 'repeat-orders';
	return $vars;
} );

add_filter( 'woocommerce_account_menu_items', static function ( array $items ): array {
	$cfg = foodify_repeat_state();
	if ( ! $cfg['active'] && ! ( is_user_logged_in() && foodify_repeat_get( get_current_user_id() ) ) ) {
		return $items;
	}
	$out = [];
	foreach ( $items as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'orders' === $key ) {
			$out['repeat-orders'] = foodify_repeat_labels( $cfg['pct'], max( 1, $cfg['lead'] ) )['menu'];
		}
	}
	if ( ! isset( $out['repeat-orders'] ) ) {
		$out = [ 'repeat-orders' => __( 'Repeat orders', 'foodify' ) ] + $out;
	}
	return $out;
}, 30 );

add_action( 'woocommerce_account_repeat-orders_endpoint', static function (): void {
	$cfg  = foodify_repeat_state();
	$rows = [];
	foreach ( foodify_repeat_get( get_current_user_id() ) as $s ) {
		$rows[] = [
			'id'      => (string) $s['id'],
			'weeks'   => (int) $s['weeks'],
			'next'    => (string) $s['next'],
			'status'  => (string) $s['status'],
			'summary' => foodify_repeat_summary( foodify_repeat_named_items( $s['items'] ) ),
		];
	}
	$nonces = [];
	foreach ( [ 'pause', 'resume', 'skip', 'interval', 'cancel' ] as $a ) {
		$nonces[ $a ] = foodify_repeat_nonce_input( $a );
	}
	if ( $cfg['active'] ) {
		echo '<p class="fd-account-lead">' . esc_html( foodify_repeat_labels( $cfg['pct'], $cfg['lead'] )['lead'] ) . '</p>';
	}
	echo foodify_repeat_list_html( $rows, [ 'lead' => max( 1, $cfg['lead'] ), 'active' => $cfg['active'], 'nonces' => $nonces ] ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in the builder.
} );

add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$state = foodify_repeat_state();
	if ( ! $state['half'] ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <code>%3$s</code></p></div>',
		esc_html__( 'Foodify repeat orders are OFF:', 'foodify' ),
		esc_html__( 'partly configured, so customers see nothing. Fix:', 'foodify' ),
		esc_html( implode( ', ', $state['missing'] ) )
	);
} );
