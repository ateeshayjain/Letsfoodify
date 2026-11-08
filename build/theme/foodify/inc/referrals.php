<?php
/**
 * 14: referrals — give ₹X, get ₹X.
 *
 * A customer with at least one COMPLETED order gets a personal code and share
 * link in My Account → Refer a friend. The friend gets ₹X off a FIRST order
 * above a minimum. The referrer gets a single-use ₹X code only when the
 * friend's order is COMPLETED (in this store: "on its way", per WP-10) — not
 * when it is placed — and that code is withdrawn if the friend's order is
 * refunded before the code is used.
 *
 * SHIPS OFF. All four options must be set or the feature does not exist:
 * foodify_referral_give, foodify_referral_get, foodify_referral_min_order and
 * foodify_referral_monthly_cap ("0" = no cap — an explicit choice, not a
 * default). Partly set = an admin notice, nothing customer-facing.
 *
 * ABUSE CHECKS, all in foodify_referral_friend_check():
 *  - no self-referral: same account, same email (gmail dots and +tags folded),
 *    same mobile, or a delivery address the referrer has used;
 *  - first order only, by email AND by mobile: either one having ordered
 *    before is enough to refuse;
 *  - one reward per friend: a friend's email/mobile can earn a referrer a
 *    reward once, ever, across all referrers;
 *  - a monthly cap on rewards per referrer.
 * Checked when the code is applied (as far as the cart knows the customer) and
 * again, with the posted details, before the order is created.
 *
 * NOT PARTNER SALES. Partner attribution (inc/coupon-attribution.php) counts a
 * coupon only when it carries _foodify_partner_id. Referral coupons are created
 * without it, tagged _foodify_coupon_kind, and the meta is stripped again if
 * someone sets a partner on one in wp-admin. tests/retention-test.php runs the
 * real foodify_partner_coupons_on_order() against an order holding both kinds.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const FOODIFY_REFERRAL_CODE_META  = '_foodify_referral_code';   // user meta: the referrer's code
const FOODIFY_REFERRAL_LOG_META   = '_foodify_referral_log';    // user meta: rewards issued
const FOODIFY_REFERRAL_ORDER_META = '_foodify_referral';        // order meta: what the friend's order promised

/* -------------------------------------------------------------------------
 * Pure — tested in tests/retention-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * @param array{give?:string,get?:string,min?:string,cap?:string} $raw Option values, as stored.
 * @return array{active:bool,half:bool,missing:string[],give:float,get:float,min:float,cap:int}
 */
function foodify_referral_config_state( array $raw ): array {
	$num     = static fn( $v ): ?float => is_numeric( $v ) ? (float) $v : null;
	$give    = $num( $raw['give'] ?? '' );
	$get     = $num( $raw['get'] ?? '' );
	$min     = $num( $raw['min'] ?? '' );
	$cap     = isset( $raw['cap'] ) && ctype_digit( trim( (string) $raw['cap'] ) ) ? (int) $raw['cap'] : null;
	$missing = [];
	if ( null === $give || $give <= 0 ) {
		$missing[] = 'foodify_referral_give';
	}
	if ( null === $get || $get <= 0 ) {
		$missing[] = 'foodify_referral_get';
	}
	if ( null === $min || $min < 0 ) {
		$missing[] = 'foodify_referral_min_order';
	}
	if ( null === $cap ) {
		$missing[] = 'foodify_referral_monthly_cap (0 = no cap)';
	}
	if ( null !== $give && null !== $min && $min > 0 && $give >= $min ) {
		$missing[] = 'foodify_referral_give must be less than foodify_referral_min_order';
	}
	$touched = false;
	foreach ( [ 'give', 'get', 'min', 'cap' ] as $k ) {
		$touched = $touched || '' !== trim( (string) ( $raw[ $k ] ?? '' ) );
	}
	return [
		'active'  => [] === $missing,
		'half'    => [] !== $missing && $touched,
		'missing' => $missing,
		'give'    => (float) $give,
		'get'     => (float) $get,
		'min'     => (float) $min,
		'cap'     => (int) $cap,
	];
}

/** A code from random bytes: "REF" + 6 characters with no 0/O/1/I/L to misread. */
function foodify_referral_code_from( string $bytes, string $prefix = 'REF' ): string {
	$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	$out      = '';
	for ( $i = 0; $i < 6; $i++ ) {
		$out .= $alphabet[ ord( $bytes[ $i % max( 1, strlen( $bytes ) ) ] ?? "\0" ) % strlen( $alphabet ) ];
	}
	return $prefix . $out;
}

function foodify_referral_is_code( string $code ): bool {
	return 1 === preg_match( '/^REF[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{6}$/i', trim( $code ) );
}

/**
 * The email as a person, not a string: lowercase, "+tag" dropped, and for
 * Gmail the dots too — p.riya+x@gmail.com and priya@googlemail.com are one
 * inbox, and the obvious way to refer yourself.
 */
function foodify_referral_email_key( string $email ): string {
	$email = strtolower( trim( $email ) );
	if ( false === strpos( $email, '@' ) ) {
		return '';
	}
	[ $local, $domain ] = explode( '@', $email, 2 );
	$local = (string) preg_replace( '/\+.*$/', '', $local );
	if ( in_array( $domain, [ 'gmail.com', 'googlemail.com' ], true ) ) {
		$local  = str_replace( '.', '', $local );
		$domain = 'gmail.com';
	}
	return '' === $local ? '' : $local . '@' . $domain;
}

/**
 * May this friend use this referrer's code?
 *
 * @param array{user_id?:int,email?:string,phone?:string,address_fp?:string,subtotal?:float} $friend
 * @param array{user_id?:int,email?:string,phone?:string,address_fps?:string[]} $referrer
 * @param array{prior_orders?:int,already_referred?:bool} $history
 * @param array{active?:bool,give?:float,min?:float} $cfg
 * @return array{ok:bool,reason:string,message:string}
 */
function foodify_referral_friend_check( array $friend, array $referrer, array $history, array $cfg ): array {
	$no = static fn( string $reason, string $message ): array => [ 'ok' => false, 'reason' => $reason, 'message' => $message ];
	if ( empty( $cfg['active'] ) ) {
		return $no( 'inactive', 'This referral code is not active right now.' );
	}
	$f_uid   = (int) ( $friend['user_id'] ?? 0 );
	$f_email = foodify_referral_email_key( (string) ( $friend['email'] ?? '' ) );
	$r_email = foodify_referral_email_key( (string) ( $referrer['email'] ?? '' ) );
	$f_phone = foodify_wa_phone( (string) ( $friend['phone'] ?? '' ) );
	$r_phone = foodify_wa_phone( (string) ( $referrer['phone'] ?? '' ) );
	$f_addr  = (string) ( $friend['address_fp'] ?? '' );
	$r_addrs = array_filter( array_map( 'strval', (array) ( $referrer['address_fps'] ?? [] ) ) );

	$self = ( $f_uid > 0 && $f_uid === (int) ( $referrer['user_id'] ?? 0 ) )
		|| ( '' !== $f_email && $f_email === $r_email )
		|| ( null !== $f_phone && $f_phone === $r_phone )
		|| ( '' !== $f_addr && in_array( $f_addr, $r_addrs, true ) );
	if ( $self ) {
		return $no( 'self', 'This is your own referral code — share it with a friend instead.' );
	}
	if ( (int) ( $history['prior_orders'] ?? 0 ) > 0 || ! empty( $history['already_referred'] ) ) {
		return $no( 'not_first', 'Referral codes are for a first Foodify order, and this email or mobile number has ordered before.' );
	}
	$min = (float) ( $cfg['min'] ?? 0 );
	if ( isset( $friend['subtotal'] ) && $min > 0 && (float) $friend['subtotal'] < $min ) {
		return $no( 'min', 'This code works on a first order above ' . foodify_rupees_whole( $min ) . '. Add ' . foodify_rupees_whole( (float) ceil( $min - (float) $friend['subtotal'] ) ) . ' more to use it.' );
	}
	return [ 'ok' => true, 'reason' => '', 'message' => '' ];
}

/**
 * Does the referrer earn a reward for this (completed) order?
 *
 * @param array{has_referral?:bool,get?:float,decided?:bool,referrer_exists?:bool,friend_rewarded?:bool,total?:float,refunded?:float,min?:float,issued_this_month?:int,cap?:int} $s
 * @return array{issue:bool,reason:string}
 */
function foodify_referral_reward_decision( array $s ): array {
	$no = static fn( string $r ): array => [ 'issue' => false, 'reason' => $r ];
	if ( empty( $s['has_referral'] ) ) {
		return $no( 'none' );
	}
	if ( ! empty( $s['decided'] ) ) {
		return $no( 'decided' );   // a status flap must not issue twice
	}
	if ( (float) ( $s['get'] ?? 0 ) <= 0 ) {
		return $no( 'no_amount' );
	}
	if ( empty( $s['referrer_exists'] ) ) {
		return $no( 'no_referrer' );
	}
	if ( ! empty( $s['friend_rewarded'] ) ) {
		return $no( 'friend_rewarded' );
	}
	$total    = (float) ( $s['total'] ?? 0 );
	$refunded = (float) ( $s['refunded'] ?? 0 );
	if ( $refunded > 0 && ( $refunded >= $total || $total - $refunded < (float) ( $s['min'] ?? 0 ) ) ) {
		return $no( 'refunded' );
	}
	$cap = (int) ( $s['cap'] ?? 0 );
	if ( $cap > 0 && (int) ( $s['issued_this_month'] ?? 0 ) >= $cap ) {
		return $no( 'capped' );
	}
	return [ 'issue' => true, 'reason' => '' ];
}

/**
 * The friend's order was refunded (wholly or partly). What happens to the reward?
 *
 * @return string revoke | keep_used | keep
 */
function foodify_referral_revoke_decision( float $total, float $refunded, float $min, int $reward_usage ): string {
	if ( $refunded <= 0 ) {
		return 'keep';
	}
	$still_qualifies = $refunded < $total && ( $total - $refunded ) >= $min;
	if ( $still_qualifies ) {
		return 'keep';
	}
	return $reward_usage > 0 ? 'keep_used' : 'revoke';
}

/** Rewards issued (not withdrawn) in a month. @param array<int,array{at:string,status:string}> $log */
function foodify_referral_month_count( array $log, string $ym ): int {
	$n = 0;
	foreach ( $log as $row ) {
		if ( 0 === strpos( (string) ( $row['at'] ?? '' ), $ym ) && 'revoked' !== ( $row['status'] ?? '' ) ) {
			$n++;
		}
	}
	return $n;
}

/**
 * What a referral coupon is made of. Deliberately carries NO partner id.
 *
 * @param string $kind referral_code | referral_reward
 * @param array{code:string,give?:float,get?:float,min?:float,referrer_id:int,email?:string,order_id?:int} $a
 * @return array{props:array<string,mixed>,meta:array<string,mixed>}
 */
function foodify_referral_coupon_spec( string $kind, array $a ): array {
	if ( 'referral_code' === $kind ) {
		return [
			'props' => [
				'code'                 => $a['code'],
				'discount_type'        => 'fixed_cart',
				'amount'               => (float) ( $a['give'] ?? 0 ),
				'minimum_amount'       => (float) ( $a['min'] ?? 0 ) > 0 ? (float) $a['min'] : '',
				'individual_use'       => true,
				'usage_limit_per_user' => 1,
				'description'          => 'Referral code. Created automatically; first orders only.',
			],
			'meta'  => [ FOODIFY_COUPON_KIND_META => 'referral_code', '_foodify_referrer_id' => (int) $a['referrer_id'] ],
		];
	}
	return [
		'props' => [
			'code'                 => $a['code'],
			'discount_type'        => 'fixed_cart',
			'amount'               => (float) ( $a['get'] ?? 0 ),
			'individual_use'       => true,
			'usage_limit'          => 1,
			'usage_limit_per_user' => 1,
			'email_restrictions'   => [ (string) ( $a['email'] ?? '' ) ],
			'description'          => 'Referral reward. Issued automatically; single use.',
		],
		'meta'  => [
			FOODIFY_COUPON_KIND_META      => 'referral_reward',
			'_foodify_referrer_id'        => (int) $a['referrer_id'],
			'_foodify_referral_for_order' => (int) ( $a['order_id'] ?? 0 ),
		],
	];
}

/**
 * Would partner attribution count a coupon with this meta? Only a partner
 * coupon that is NOT one of this lane's kinds. Mirrors the test in
 * foodify_partner_coupons_on_order(), plus the kind guard.
 */
function foodify_coupon_counts_as_partner( array $meta ): bool {
	return (int) ( $meta['_foodify_partner_id'] ?? 0 ) > 0 && '' === (string) ( $meta[ FOODIFY_COUPON_KIND_META ] ?? '' );
}

/** Should the saved code go on the cart now? @param array{has_code?:bool,applied?:bool,dismissed?:bool,subtotal?:float,min?:float,self?:bool} $s */
function foodify_referral_should_autoapply( array $s ): bool {
	return ! empty( $s['has_code'] ) && empty( $s['applied'] ) && empty( $s['dismissed'] ) && empty( $s['self'] )
		&& (float) ( $s['subtotal'] ?? 0 ) > 0 && (float) ( $s['subtotal'] ?? 0 ) >= (float) ( $s['min'] ?? 0 );
}

/** @param array{give:float,get:float,min:float,cap:int} $cfg */
function foodify_referral_labels( array $cfg ): array {
	$give = foodify_rupees_whole( (float) $cfg['give'] );
	$get  = foodify_rupees_whole( (float) $cfg['get'] );
	$min  = (float) $cfg['min'] > 0 ? ' above ' . foodify_rupees_whole( (float) $cfg['min'] ) : '';
	$cap  = (int) $cfg['cap'];
	return [
		'title'  => 'Give ' . $give . ', get ' . $get,
		'lead'   => 'Your friend gets ' . $give . ' off their first Foodify order' . $min . '. When their order ships, you get a ' . $get . ' code for your next one.',
		'rules'  => 'One reward per friend, on their first order. Not for your own orders, number or addresses.' . ( $cap > 0 ? ' Up to ' . $cap . ' reward' . ( 1 === $cap ? '' : 's' ) . ' a month.' : '' ),
		'locked' => 'Your referral code appears here once your first order has shipped.',
		'menu'   => 'Refer a friend',
		'notice' => 'Your friend\'s code is saved: ' . $give . ' comes off a first order' . $min . ' at checkout.',
	];
}

function foodify_referral_share_text( array $cfg, string $code, string $link ): string {
	$min = (float) $cfg['min'] > 0 ? ' above ' . foodify_rupees_whole( (float) $cfg['min'] ) : '';
	return 'Get ' . foodify_rupees_whole( (float) $cfg['give'] ) . ' off your first Foodify order' . $min . ' with my code ' . $code . ': ' . $link;
}

/**
 * My Account → Refer a friend.
 *
 * @param array{labels:array,eligible:bool,code?:string,link?:string,share_text?:string,rewards?:array<int,array{code:string,amount:float,status:string}>} $d
 */
function foodify_referral_panel_html( array $d ): string {
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$l = $d['labels'];
	if ( empty( $d['eligible'] ) ) {
		return '<section class="fd-refer is-locked"><h2 class="fd-refer__title">' . $e( (string) $l['title'] ) . '</h2>'
			. '<p class="fd-refer__lead">' . $e( (string) $l['lead'] ) . '</p>'
			. '<p class="fd-refer__lead">' . $e( (string) $l['locked'] ) . '</p></section>';
	}
	$link = (string) $d['link'];
	$text = (string) $d['share_text'];
	$out  = '<section class="fd-refer"><h2 class="fd-refer__title">' . $e( (string) $l['title'] ) . '</h2>'
		. '<p class="fd-refer__lead">' . $e( (string) $l['lead'] ) . '</p>'
		. '<p class="fd-refer__code"><span class="fd-refer__code-label">Your code</span> <strong class="fd-refer__code-value">' . $e( (string) $d['code'] ) . '</strong></p>'
		. '<p class="fd-refer__link"><label class="fd-refer__code-label" for="fd-refer-link">Your link</label>'
		. '<input id="fd-refer-link" class="fd-refer__input" type="text" readonly value="' . $e( $link ) . '"></p>'
		// WhatsApp is how this is actually shared in India, so it is the main
		// button; Copy link and the browser's Share are the alternatives (ACCOUNT-13).
		. '<div class="fd-refer__actions">'
		. '<a class="wp-element-button fd-refer__wa" href="https://wa.me/?text=' . $e( rawurlencode( $text ) ) . '">Send on WhatsApp</a>'
		. '<button type="button" class="fd-secondary" data-fd-copy data-url="' . $e( $link ) . '">Copy link</button>'
		. '<button type="button" class="fd-secondary" data-fd-share data-text="' . $e( $text ) . '" data-url="' . $e( $link ) . '" hidden>Share</button>'
		. '</div>'
		. '<p class="fd-refer__status" role="status" aria-live="polite"></p>'
		. '<p class="fd-refer__rules">' . $e( (string) $l['rules'] ) . '</p>';
	$rewards = (array) ( $d['rewards'] ?? [] );
	if ( $rewards ) {
		$words = [ 'ready' => 'Ready to use', 'used' => 'Used', 'revoked' => 'Withdrawn — the order was refunded' ];
		$out  .= '<h3 class="fd-refer__subtitle">Your rewards</h3><ul class="fd-refer__rewards">';
		foreach ( $rewards as $r ) {
			$st   = isset( $words[ $r['status'] ] ) ? (string) $r['status'] : 'ready';
			$out .= '<li class="fd-refer__reward is-' . $e( $st ) . '"><span class="fd-refer__reward-code">' . $e( (string) $r['code'] ) . '</span>'
				. '<span>' . $e( foodify_rupees_whole( (float) $r['amount'] ) . ' · ' . $words[ $st ] ) . '</span></li>';
		}
		$out .= '</ul>';
	}
	return $out . '</section>';
}

/**
 * The referrer's reward email. Names no friend — the referrer learns that a
 * referral converted, not who bought what.
 *
 * @param array{first_name:string,code:string,amount:float} $d
 * @return array{subject:string,heading:string,body:string}
 */
function foodify_referral_reward_email( array $d ): array {
	$e    = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$amt  = foodify_rupees_whole( (float) $d['amount'] );
	$name = '' !== trim( (string) $d['first_name'] ) ? trim( (string) $d['first_name'] ) : 'there';
	return [
		'subject' => 'Your referral worked — here is ' . $amt . ' off',
		'heading' => 'Thanks for the referral',
		'body'    => '<p>Hi ' . $e( $name ) . ',</p>'
			. '<p>Someone you referred just had their first Foodify order shipped, so here is ' . $e( $amt ) . ' off your next order.</p>'
			. '<p>Your code: <strong>' . $e( (string) $d['code'] ) . '</strong></p>'
			. '<p>Enter it at checkout. It works once, on an order placed with this email address.</p>',
	];
}

/**
 * Share + copy on the Refer a friend panel. Expects [data-fd-share] (hidden
 * until the browser can share), [data-fd-copy], and .fd-refer__status.
 */
function foodify_referral_script(): string {
	return <<<'JS'
(function(){
  var status=document.querySelector('.fd-refer__status');
  function say(t){if(status){status.textContent=t;}}
  var share=document.querySelector('[data-fd-share]');
  if(share&&navigator.share){
    share.hidden=false;
    share.addEventListener('click',function(){navigator.share({text:share.getAttribute('data-text')||'',url:share.getAttribute('data-url')||''}).catch(function(){});});
  }
  var copy=document.querySelector('[data-fd-copy]');
  if(copy){copy.addEventListener('click',function(){
    var url=copy.getAttribute('data-url')||'';
    var done=function(){say('Link copied.');};
    if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done,function(){say('Copy the link above.');});}
    else{var f=document.getElementById('fd-refer-link');if(f){f.select();try{document.execCommand('copy');done();}catch(e){say('Copy the link above.');}}}
  });}
})();
JS;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;
}

function foodify_referral_state(): array {
	static $state = null;
	if ( null === $state ) {
		$state = foodify_referral_config_state( [
			'give' => (string) get_option( 'foodify_referral_give', '' ),
			'get'  => (string) get_option( 'foodify_referral_get', '' ),
			'min'  => (string) get_option( 'foodify_referral_min_order', '' ),
			'cap'  => (string) get_option( 'foodify_referral_monthly_cap', '' ),
		] );
	}
	return $state;
}

function foodify_referral_partner_meta_key(): string {
	return defined( 'FOODIFY_PARTNER_META' ) ? (string) constant( 'FOODIFY_PARTNER_META' ) : '_foodify_partner_id';
}

/** Create a coupon from a spec. */
function foodify_referral_make_coupon( array $spec ): int {
	$c = new WC_Coupon();
	foreach ( $spec['props'] as $prop => $value ) {
		$setter = 'set_' . $prop;
		if ( '' !== $value && is_callable( [ $c, $setter ] ) ) {
			$c->{$setter}( $value );
		}
	}
	foreach ( $spec['meta'] as $k => $v ) {
		$c->update_meta_data( $k, $v );
	}
	return (int) $c->save();
}

function foodify_referral_unique_code( string $prefix ): string {
	for ( $i = 0; $i < 10; $i++ ) {
		$code = foodify_referral_code_from( random_bytes( 6 ), $prefix );
		if ( ! wc_get_coupon_id_by_code( $code ) ) {
			return $code;
		}
	}
	return $prefix . strtoupper( bin2hex( random_bytes( 4 ) ) );
}

function foodify_referral_eligible( int $uid ): bool {
	return $uid > 0 && (bool) wc_get_orders( [ 'customer_id' => $uid, 'status' => [ 'completed' ], 'limit' => 1, 'return' => 'ids' ] );
}

/** The referrer's code, created on first view and kept in step with the options. */
function foodify_referral_code_for( int $uid ): string {
	$cfg  = foodify_referral_state();
	$code = (string) get_user_meta( $uid, FOODIFY_REFERRAL_CODE_META, true );
	$id   = '' !== $code ? (int) wc_get_coupon_id_by_code( $code ) : 0;
	if ( ! $id ) {
		$code = foodify_referral_unique_code( 'REF' );
		$id   = foodify_referral_make_coupon( foodify_referral_coupon_spec( 'referral_code', [ 'code' => $code, 'give' => $cfg['give'], 'min' => $cfg['min'], 'referrer_id' => $uid ] ) );
		if ( ! $id ) {
			return '';
		}
		update_user_meta( $uid, FOODIFY_REFERRAL_CODE_META, $code );
		return $code;
	}
	$c = new WC_Coupon( $id );
	if ( (float) $c->get_amount() !== $cfg['give'] || (float) $c->get_minimum_amount() !== $cfg['min'] ) {
		$c->set_amount( $cfg['give'] );
		$c->set_minimum_amount( $cfg['min'] > 0 ? $cfg['min'] : '' );
		$c->save();
	}
	return $code;
}

/** Everything known about the referrer behind a code coupon. */
function foodify_referral_referrer( WC_Coupon $coupon ): ?array {
	$uid  = (int) $coupon->get_meta( '_foodify_referrer_id' );
	$user = $uid ? get_userdata( $uid ) : false;
	if ( ! $user ) {
		return null;
	}
	$fps = [];
	if ( function_exists( 'foodify_get_address_book' ) ) {
		foreach ( foodify_get_address_book( $uid ) as $a ) {
			$fps[] = foodify_address_fingerprint( $a );
		}
	}
	return [
		'user_id'     => $uid,
		'email'       => (string) $user->user_email,
		'phone'       => (string) get_user_meta( $uid, 'billing_phone', true ),
		'address_fps' => $fps,
	];
}

function foodify_referral_hash( string $s ): string {
	return '' === $s ? '' : hash_hmac( 'sha256', $s, wp_salt( 'auth' ) );
}

/** Has this email or mobile ordered before? Orders that never happened (failed, cancelled, unpaid) do not count. */
function foodify_referral_history( string $email, string $phone ): array {
	static $memo = [];
	$memo_key = strtolower( trim( $email ) ) . '|' . (string) foodify_wa_phone( $phone );
	if ( isset( $memo[ $memo_key ] ) ) {
		return $memo[ $memo_key ];   // coupon validation runs on every cart calculation
	}
	$statuses = [ 'processing', 'completed', 'on-hold', 'refunded' ];
	$prior    = 0;
	foreach ( array_unique( array_filter( [ trim( $email ), strtolower( trim( $email ) ) ] ) ) as $e ) {
		$prior += count( wc_get_orders( [ 'billing_email' => $e, 'status' => $statuses, 'limit' => 1, 'return' => 'ids' ] ) );
	}
	$e164 = foodify_wa_phone( $phone );
	if ( $e164 ) {
		$ten = substr( $e164, 2 );
		foreach ( [ $ten, '0' . $ten, '91' . $ten, '+91' . $ten, '+91 ' . $ten ] as $p ) {
			$prior += count( wc_get_orders( [ 'billing_phone' => $p, 'status' => $statuses, 'limit' => 1, 'return' => 'ids' ] ) );
		}
	}
	$keys     = array_filter( [ foodify_referral_hash( foodify_referral_email_key( $email ) ), foodify_referral_hash( (string) $e164 ) ] );
	$referred = false;
	foreach ( $keys as $k ) {
		$referred = $referred || (bool) wc_get_orders( [
			'status'     => $statuses,
			'limit'      => 1,
			'return'     => 'ids',
			'meta_query' => [ 'relation' => 'OR', // phpcs:ignore WordPress.DB.SlowDBQuery
				[ 'key' => '_foodify_referral_friend', 'value' => $k ],
				[ 'key' => '_foodify_referral_phone', 'value' => $k ],
			],
		] );
	}
	return $memo[ $memo_key ] = [ 'prior_orders' => $prior, 'already_referred' => $referred ];
}

/** The referrer's reward log, as a list of rows. */
function foodify_referral_log( int $uid ): array {
	$log = $uid ? get_user_meta( $uid, FOODIFY_REFERRAL_LOG_META, true ) : [];
	return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : [];
}

/** The applied referral code on the cart, if any. */
function foodify_referral_code_on_cart(): ?WC_Coupon {
	if ( ! WC()->cart ) {
		return null;
	}
	foreach ( WC()->cart->get_applied_coupons() as $code ) {
		$c = new WC_Coupon( $code );
		if ( 'referral_code' === (string) $c->get_meta( FOODIFY_COUPON_KIND_META ) ) {
			return $c;
		}
	}
	return null;
}

/* ---- validity: at apply time, as far as the cart knows -------------------- */

add_filter( 'woocommerce_coupon_is_valid', static function ( bool $valid, WC_Coupon $coupon, $discounts = null ): bool {
	if ( ! $valid || 'referral_code' !== (string) $coupon->get_meta( FOODIFY_COUPON_KIND_META ) ) {
		return $valid;
	}
	// Only a customer's cart. An admin applying a code to an existing order is staff discretion.
	if ( ! $discounts instanceof WC_Discounts || ! $discounts->get_object() instanceof WC_Cart ) {
		return $valid;
	}
	$cfg      = foodify_referral_state();
	$referrer = foodify_referral_referrer( $coupon );
	if ( ! $referrer ) {
		throw new Exception( esc_html__( 'This referral code is not active right now.', 'foodify' ), 100 );
	}
	$uid      = get_current_user_id();
	$customer = WC()->customer;
	$email    = $uid ? (string) wp_get_current_user()->user_email : ( $customer ? (string) $customer->get_billing_email() : '' );
	$phone    = $customer ? (string) $customer->get_billing_phone() : '';
	$check    = foodify_referral_friend_check(
		[ 'user_id' => $uid, 'email' => $email, 'phone' => $phone ],
		$referrer,
		'' !== $email || '' !== $phone ? foodify_referral_history( $email, $phone ) : [],
		$cfg
	);
	if ( ! $check['ok'] ) {
		throw new Exception( esc_html( $check['message'] ), 100 );
	}
	return true;
}, 20, 3 );

/* ---- validity: before the order exists, with what was actually typed ------ */

add_action( 'woocommerce_after_checkout_validation', static function ( array $data, WP_Error $errors ): void {
	$coupon = foodify_referral_code_on_cart();
	if ( ! $coupon ) {
		return;
	}
	$referrer = foodify_referral_referrer( $coupon );
	$email    = (string) ( $data['billing_email'] ?? '' );
	$phone    = (string) ( $data['billing_phone'] ?? '' );
	$fp       = function_exists( 'foodify_address_fingerprint' ) ? foodify_address_fingerprint( foodify_address_normalise( [
		'address_1' => $data['shipping_address_1'] ?? ( $data['billing_address_1'] ?? '' ),
		'address_2' => $data['shipping_address_2'] ?? ( $data['billing_address_2'] ?? '' ),
		'city'      => $data['shipping_city'] ?? ( $data['billing_city'] ?? '' ),
		'postcode'  => $data['shipping_postcode'] ?? ( $data['billing_postcode'] ?? '' ),
	] ) ) : '';
	$check = $referrer ? foodify_referral_friend_check(
		[ 'user_id' => get_current_user_id(), 'email' => $email, 'phone' => $phone, 'address_fp' => $fp ],
		$referrer,
		foodify_referral_history( $email, $phone ),
		foodify_referral_state()
	) : [ 'ok' => false, 'message' => __( 'This referral code is not active right now.', 'foodify' ) ];
	if ( ! $check['ok'] ) {
		WC()->cart->remove_coupon( $coupon->get_code() );
		if ( WC()->session ) {
			WC()->session->set( 'foodify_referral_dismissed', 1 );
			WC()->session->set( 'refresh_totals', true );   // the failure response tells checkout.js to re-render the total
		}
		$errors->add( 'foodify_referral', esc_html( $check['message'] . ' ' . __( 'We removed the code — check your total and place the order again.', 'foodify' ) ) );
	}
}, 20, 2 );

/** Record what the friend's order promised, and who the friend is (hashed). */
add_action( 'woocommerce_checkout_order_processed', static function ( $order_id ): void {
	$coupon = foodify_referral_code_on_cart();
	$order  = wc_get_order( (int) $order_id );
	if ( ! $coupon || ! $order instanceof WC_Order ) {
		return;
	}
	$cfg = foodify_referral_state();
	$order->update_meta_data( FOODIFY_REFERRAL_ORDER_META, [
		'code'     => $coupon->get_code(),
		'referrer' => (int) $coupon->get_meta( '_foodify_referrer_id' ),
		'get'      => $cfg['get'],
		'min'      => $cfg['min'],
		'cap'      => $cfg['cap'],
	] );
	$order->update_meta_data( '_foodify_referral_friend', foodify_referral_hash( foodify_referral_email_key( (string) $order->get_billing_email() ) ) );
	$order->update_meta_data( '_foodify_referral_phone', foodify_referral_hash( (string) foodify_wa_phone( (string) $order->get_billing_phone() ) ) );
	$order->save();
	if ( WC()->session ) {
		WC()->session->set( 'foodify_referral_code', '' );
	}
}, 20 );

/* ---- the referrer's reward ------------------------------------------------- */

add_action( 'woocommerce_order_status_completed', static function ( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$ref = $order->get_meta( FOODIFY_REFERRAL_ORDER_META );
	if ( ! is_array( $ref ) ) {
		return;
	}
	$uid  = (int) ( $ref['referrer'] ?? 0 );
	$user = $uid ? get_userdata( $uid ) : false;
	$log  = foodify_referral_log( $uid );

	$friend_rewarded = false;
	$hash            = (string) $order->get_meta( '_foodify_referral_friend' );
	if ( '' !== $hash ) {
		foreach ( wc_get_orders( [ 'limit' => 5, 'return' => 'ids', 'meta_key' => '_foodify_referral_friend', 'meta_value' => $hash ] ) as $other ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$o               = wc_get_order( $other );
			$friend_rewarded = $friend_rewarded || ( $o && (int) $other !== $order->get_id() && '' !== (string) $o->get_meta( '_foodify_referral_reward' ) && 'capped' !== (string) $o->get_meta( '_foodify_referral_reward' ) );
		}
	}

	$decision = foodify_referral_reward_decision( [
		'has_referral'      => true,
		'get'               => (float) ( $ref['get'] ?? 0 ),
		'decided'           => '' !== (string) $order->get_meta( '_foodify_referral_reward' ),
		'referrer_exists'   => (bool) $user,
		'friend_rewarded'   => $friend_rewarded,
		'total'             => (float) $order->get_total(),
		'refunded'          => (float) $order->get_total_refunded(),
		'min'               => (float) ( $ref['min'] ?? 0 ),
		'issued_this_month' => foodify_referral_month_count( $log, wp_date( 'Y-m' ) ),
		'cap'               => (int) ( $ref['cap'] ?? 0 ),
	] );
	if ( ! $decision['issue'] ) {
		if ( in_array( $decision['reason'], [ 'capped', 'friend_rewarded', 'refunded', 'no_referrer' ], true ) ) {
			$order->update_meta_data( '_foodify_referral_reward', $decision['reason'] );
			$order->save();
			$order->add_order_note( sprintf( 'Referral reward not issued: %s.', str_replace( '_', ' ', $decision['reason'] ) ) );
		}
		return;
	}

	$code = foodify_referral_unique_code( 'RWD' );
	$id   = foodify_referral_make_coupon( foodify_referral_coupon_spec( 'referral_reward', [
		'code'        => $code,
		'get'         => (float) $ref['get'],
		'referrer_id' => $uid,
		'email'       => (string) $user->user_email,
		'order_id'    => $order->get_id(),
	] ) );
	if ( ! $id ) {
		return;
	}
	$log[] = [ 'coupon' => $code, 'order' => $order->get_id(), 'at' => wp_date( 'Y-m-d' ), 'status' => 'issued', 'amount' => (float) $ref['get'] ];
	update_user_meta( $uid, FOODIFY_REFERRAL_LOG_META, array_values( $log ) );
	$order->update_meta_data( '_foodify_referral_reward', $code );
	$order->save();
	$order->add_order_note( sprintf( 'Referral reward %s issued to the referrer.', $code ) );

	$mail   = foodify_referral_reward_email( [ 'first_name' => (string) $user->first_name, 'code' => $code, 'amount' => (float) $ref['get'] ] );
	$mailer = WC()->mailer();
	$mailer->send( $user->user_email, $mail['subject'], $mailer->wrap_message( $mail['heading'], $mail['body'] ) );
}, 40 );

/** Refunded before the reward was used: withdraw it. */
$foodify_referral_refund = static function ( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$code = (string) $order->get_meta( '_foodify_referral_reward' );
	$ref  = $order->get_meta( FOODIFY_REFERRAL_ORDER_META );
	$id   = 0 === strpos( $code, 'RWD' ) ? (int) wc_get_coupon_id_by_code( $code ) : 0;   // not 'capped' / 'refunded' markers
	if ( ! $id || ! is_array( $ref ) || 'publish' !== get_post_status( $id ) ) {
		return;
	}
	$coupon = new WC_Coupon( $id );
	$what   = foodify_referral_revoke_decision( (float) $order->get_total(), (float) $order->get_total_refunded(), (float) ( $ref['min'] ?? 0 ), (int) $coupon->get_usage_count() );
	if ( 'revoke' === $what ) {
		wp_trash_post( $id );
		$uid = (int) ( $ref['referrer'] ?? 0 );
		$log = foodify_referral_log( $uid );
		foreach ( $log as $i => $row ) {
			if ( ( $row['coupon'] ?? '' ) === $code ) {
				$log[ $i ]['status'] = 'revoked';
			}
		}
		update_user_meta( $uid, FOODIFY_REFERRAL_LOG_META, array_values( $log ) );
		$order->add_order_note( sprintf( 'Referral reward %s withdrawn: this order was refunded before it was used.', $code ) );
	} elseif ( 'keep_used' === $what ) {
		$order->add_order_note( sprintf( 'Referral reward %s was already used, so it could not be withdrawn.', $code ) );
	}
};
add_action( 'woocommerce_order_refunded', $foodify_referral_refund, 20 );
add_action( 'woocommerce_order_status_refunded', $foodify_referral_refund, 20 );
unset( $foodify_referral_refund );

/** Never a partner sale: strip a partner set on a referral or repeat coupon in wp-admin. */
add_action( 'woocommerce_coupon_options_save', static function ( int $coupon_id ): void {
	if ( '' !== (string) get_post_meta( $coupon_id, FOODIFY_COUPON_KIND_META, true ) ) {
		delete_post_meta( $coupon_id, foodify_referral_partner_meta_key() );
	}
}, 99, 1 );

/* ---- the share link -------------------------------------------------------- */

add_action( 'template_redirect', static function (): void {
	if ( empty( $_GET['ref'] ) || ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}
	$code = strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) );
	$id   = foodify_referral_state()['active'] && foodify_referral_is_code( $code ) ? (int) wc_get_coupon_id_by_code( $code ) : 0;
	if ( ! $id || 'referral_code' !== (string) get_post_meta( $id, FOODIFY_COUPON_KIND_META, true ) ) {
		return;   // ?ref= is a common tracking parameter; anything that is not one of our codes is left alone
	}
	WC()->session->set_customer_session_cookie( true );
	WC()->session->set( 'foodify_referral_code', $code );
	WC()->session->set( 'foodify_referral_dismissed', 0 );
	wc_add_notice( esc_html( foodify_referral_labels( foodify_referral_state() )['notice'] ), 'success' );
	wp_safe_redirect( remove_query_arg( 'ref' ) );
	exit;
}, 3 );

/** Put the saved code on the cart once it can apply — not before, so nobody sees a "minimum spend" error for an empty cart. */
add_action( 'template_redirect', static function (): void {
	if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) || ! WC()->session || ! WC()->cart ) {
		return;
	}
	$code = (string) WC()->session->get( 'foodify_referral_code' );
	$cfg  = foodify_referral_state();
	if ( ! foodify_referral_should_autoapply( [
		'has_code'  => '' !== $code && $cfg['active'],
		'applied'   => '' !== $code && WC()->cart->has_discount( $code ),
		'dismissed' => (bool) WC()->session->get( 'foodify_referral_dismissed' ),
		'subtotal'  => (float) WC()->cart->get_subtotal(),
		'min'       => $cfg['min'],
	] ) ) {
		return;
	}
	WC()->cart->apply_coupon( $code );
	WC()->session->set( 'foodify_referral_dismissed', 1 );   // one try; a refusal is shown once, then the code is the customer's to type
}, 20 );

/* ---- My Account → Refer a friend ------------------------------------------- */

add_action( 'init', static function (): void {
	add_rewrite_endpoint( 'refer-a-friend', EP_ROOT | EP_PAGES );
} );

add_filter( 'woocommerce_get_query_vars', static function ( array $vars ): array {
	$vars['refer-a-friend'] = 'refer-a-friend';
	return $vars;
} );

add_filter( 'woocommerce_account_menu_items', static function ( array $items ): array {
	if ( ! foodify_referral_state()['active'] ) {
		return $items;
	}
	$label = foodify_referral_labels( foodify_referral_state() )['menu'];
	$out   = [];
	foreach ( $items as $key => $text ) {
		if ( 'customer-logout' === $key ) {
			$out['refer-a-friend'] = $label;
		}
		$out[ $key ] = $text;
	}
	if ( ! isset( $out['refer-a-friend'] ) ) {
		$out['refer-a-friend'] = $label;
	}
	return $out;
}, 30 );

add_action( 'woocommerce_account_refer-a-friend_endpoint', static function (): void {
	$cfg = foodify_referral_state();
	if ( ! $cfg['active'] ) {
		echo '<p class="fd-account-lead">' . esc_html__( 'Referrals are not available right now.', 'foodify' ) . '</p>';
		return;
	}
	$uid      = get_current_user_id();
	$labels   = foodify_referral_labels( $cfg );
	$eligible = foodify_referral_eligible( $uid );
	$d        = [ 'labels' => $labels, 'eligible' => $eligible ];
	if ( $eligible ) {
		$code = foodify_referral_code_for( $uid );
		$link = add_query_arg( 'ref', $code, wc_get_cart_url() );
		$rows = [];
		foreach ( foodify_referral_log( $uid ) as $row ) {
			$cid    = (int) wc_get_coupon_id_by_code( (string) $row['coupon'] );
			$status = 'revoked' === ( $row['status'] ?? '' ) ? 'revoked' : ( $cid && ( new WC_Coupon( $cid ) )->get_usage_count() > 0 ? 'used' : 'ready' );
			$rows[] = [ 'code' => (string) $row['coupon'], 'amount' => (float) ( $row['amount'] ?? 0 ), 'status' => $status ];
		}
		$d += [ 'code' => $code, 'link' => $link, 'share_text' => foodify_referral_share_text( $cfg, $code, $link ), 'rewards' => array_reverse( $rows ) ];
	}
	echo foodify_referral_panel_html( $d ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in the builder.
} );

add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! foodify_referral_state()['active'] || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'refer-a-friend' ) ) {
		return;
	}
	wp_register_script( 'foodify-refer', false, [], FOODIFY_VERSION, true );
	wp_enqueue_script( 'foodify-refer' );
	wp_add_inline_script( 'foodify-refer', foodify_referral_script() );
} );

add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$state = foodify_referral_state();
	if ( ! $state['half'] ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <code>%3$s</code></p></div>',
		esc_html__( 'Foodify referrals are OFF:', 'foodify' ),
		esc_html__( 'partly configured, so customers see nothing. Fix:', 'foodify' ),
		esc_html( implode( ', ', $state['missing'] ) )
	);
} );
