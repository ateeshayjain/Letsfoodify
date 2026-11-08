<?php
/**
 * 13: abandoned-cart reminder — consent first.
 *
 * DPDP Act 2023: a marketing email needs an affirmative, unticked opt-in that is
 * as easy to withdraw as to give. So the reminder exists only for someone who
 * ticked "Email me my cart if I don't finish checking out" — at checkout, under
 * the email field, or (signed in) in Your details, where it defaults to off.
 * There is no "we noticed you left" email to anyone who did not ask for one.
 *
 * SHIPS OFF until option foodify_cart_reminder_delay_minutes is set (15–1440;
 * suggested 60, client to confirm).
 *
 * WHAT IS KEPT, AND FOR HOW LONG. A snapshot holds the email, the cart lines
 * (product, variation, quantity — no prices, no address, no name) and three
 * timestamps. It is deleted when an order is placed with that email, seven days
 * after the reminder went out, or the moment the customer unticks the box or
 * taps "stop" in the email. A daily sweep deletes anything the per-snapshot
 * clean-up missed.
 *
 * WHERE IT IS KEPT: a non-public custom post type (foodify_cart_snap), not an
 * option. Options are one row each and the autoloaded ones are read on every
 * request; a store of personal data that grows with traffic does not belong in
 * that table. A CPT row with meta gets indexed lookups by email hash, deletion
 * by WordPress's own APIs, Action Scheduler jobs that reference its id, and the
 * core personal-data eraser hook — with no new table to migrate. It is
 * registered with public, UI, REST, query var and export all OFF.
 *
 * ONE REMINDER, NEVER TO A BUYER. The decision is re-made at send time: any
 * order with that email since the snapshot was taken cancels it. The restore
 * link works until an order is placed with that email or the snapshot is gone
 * (seven days after sending) — opening it twice only fills a cart twice, which
 * is why mail scanners pre-opening links cannot break it.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const FOODIFY_CART_OPTIN_META = '_foodify_cart_reminder_optin';  // user meta: 'yes' | 'no'
const FOODIFY_CART_KEEP_DAYS  = 7;                                // from the approved brief

/* -------------------------------------------------------------------------
 * Pure — tested in tests/retention-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * A rolling-window limit for something a stranger can trigger.
 *
 * @param int[] $timestamps
 * @return array{allowed:bool,kept:int[]} kept = the window to store, including now when allowed.
 */
function foodify_rate_gate( array $timestamps, int $now, int $max, int $window ): array {
	$kept = array_values( array_filter( $timestamps, static fn( $t ): bool => is_int( $t ) && $t > $now - $window && $t <= $now ) );
	if ( count( $kept ) >= $max ) {
		return [ 'allowed' => false, 'kept' => $kept ];
	}
	$kept[] = $now;
	return [ 'allowed' => true, 'kept' => $kept ];
}

/** "about an hour", "about 2 hours", "about 30 minutes". */
function foodify_cart_delay_label( int $minutes ): string {
	if ( 60 === $minutes ) {
		return 'about an hour';
	}
	if ( $minutes > 60 && 0 === $minutes % 60 ) {
		return 'about ' . ( $minutes / 60 ) . ' hours';
	}
	return 'about ' . $minutes . ' minutes';
}

/** @return array{active:bool,half:bool,missing:string[],delay:int} */
function foodify_cart_config_state( string $delay_raw, bool $has_scheduler ): array {
	$delay   = ctype_digit( trim( $delay_raw ) ) ? (int) $delay_raw : 0;
	$missing = [];
	if ( $delay < 15 || $delay > 1440 ) {
		$missing[] = 'foodify_cart_reminder_delay_minutes (15–1440)';
	}
	if ( ! $has_scheduler ) {
		$missing[] = 'Action Scheduler (bundled with WooCommerce)';
	}
	return [
		'active'  => [] === $missing,
		'half'    => [] !== $missing && '' !== trim( $delay_raw ),
		'missing' => $missing,
		'delay'   => $delay,
	];
}

/** A lowercase, valid-looking email, or null. */
function foodify_cart_email( string $raw ): ?string {
	$e = strtolower( trim( $raw ) );
	return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : null;
}

/**
 * Cart lines as stored: product, variation, quantity. Nothing else — a price
 * stored here would be stale by the time anyone read it.
 *
 * @param array<int|string,array{product_id?:int,variation_id?:int,quantity?:int}> $cart_items WooCommerce cart contents.
 * @return array<int,array{product_id:int,variation_id:int,qty:int}>
 */
function foodify_cart_snapshot_lines( array $cart_items ): array {
	$out = [];
	foreach ( $cart_items as $item ) {
		$pid = (int) ( $item['product_id'] ?? 0 );
		$qty = (int) ( $item['quantity'] ?? 0 );
		if ( $pid > 0 && $qty > 0 ) {
			$out[] = [ 'product_id' => $pid, 'variation_id' => (int) ( $item['variation_id'] ?? 0 ), 'qty' => min( $qty, 99 ) ];
		}
	}
	return $out;
}

/**
 * Is the one reminder due?
 *
 * @param array{lines?:array,activity?:int,sent?:int} $snap
 * @return array{send:bool,reason:string,at:int} at = when it becomes due (for a reschedule).
 */
function foodify_cart_reminder_due( array $snap, int $now, int $delay_minutes, bool $ordered_since ): array {
	$at = (int) ( $snap['activity'] ?? 0 ) + 60 * $delay_minutes;
	if ( $delay_minutes < 1 ) {
		return [ 'send' => false, 'reason' => 'inactive', 'at' => 0 ];
	}
	if ( ! empty( $snap['sent'] ) ) {
		return [ 'send' => false, 'reason' => 'already_sent', 'at' => 0 ];
	}
	if ( $ordered_since ) {
		return [ 'send' => false, 'reason' => 'ordered', 'at' => 0 ];
	}
	if ( empty( $snap['lines'] ) ) {
		return [ 'send' => false, 'reason' => 'empty', 'at' => 0 ];
	}
	if ( $now < $at ) {
		return [ 'send' => false, 'reason' => 'not_yet', 'at' => $at ];
	}
	return [ 'send' => true, 'reason' => '', 'at' => $at ];
}

/** Has a snapshot outlived its purpose? */
function foodify_cart_snapshot_expired( array $snap, int $now, int $delay_minutes ): bool {
	$keep = FOODIFY_CART_KEEP_DAYS * 86400;
	if ( ! empty( $snap['sent'] ) ) {
		return $now >= (int) $snap['sent'] + $keep;
	}
	// Never sent (consent given, then something failed): same retention, counted from when it would have gone.
	return $now >= (int) ( $snap['activity'] ?? 0 ) + 60 * max( 0, $delay_minutes ) + $keep;
}

/** The checkout field definition. Unticked unless a signed-in customer already said yes. */
function foodify_cart_consent_field( int $delay_minutes, bool $checked = false ): array {
	return [
		'type'        => 'checkbox',
		'label'       => 'Email me my cart if I don\'t finish checking out',
		'description' => 'One email, ' . foodify_cart_delay_label( $delay_minutes ) . ' later, with a link back to this cart. Untick to cancel it.',
		'required'    => false,
		'default'     => $checked ? 1 : 0,
		'class'       => [ 'form-row-wide', 'fd-optin' ],
		'priority'    => 21,   // directly under email (priority 20)
	];
}

/** The same choice in My Account → Your details. */
function foodify_cart_account_field_html( bool $checked, int $delay_minutes ): string {
	return '<fieldset class="fd-account-optin"><legend>Reminders</legend>'
		. '<p class="woocommerce-form-row form-row form-row-wide"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox fd-optin" for="foodify_cart_optin">'
		. '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="foodify_cart_optin" id="foodify_cart_optin" value="1"' . ( $checked ? ' checked' : '' ) . '> '
		. '<span>Email me my cart if I don\'t finish checking out</span></label></p>'
		. '<p class="fd-optin__note">One email, ' . foodify_cart_delay_label( $delay_minutes ) . ' later, with a link back to your cart. Off unless you tick it.</p>'
		. '</fieldset>';
}

/**
 * The reminder.
 *
 * @param array{summary:string,link:string,stop:string} $d
 * @return array{subject:string,heading:string,body:string}
 */
function foodify_cart_email_parts( array $d ): array {
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	return [
		'subject' => 'Your Foodify cart is saved',
		'heading' => 'Your cart, as you left it',
		'body'    => '<p>You asked us to email you your cart. Here it is: ' . $e( (string) $d['summary'] ) . '.</p>'
			. '<p><a href="' . $e( (string) $d['link'] ) . '">Open my cart</a></p>'
			. '<p>Prices and stock are checked again when you open it. Anything sold out is left out, and we say which.</p>'
			. '<p>This is the only reminder we send about this cart, and we delete it in ' . FOODIFY_CART_KEEP_DAYS . ' days. '
			. '<a href="' . $e( (string) $d['stop'] ) . '">Delete it now</a>.</p>',
	];
}

/**
 * Checkout script: tells the site about the tick as it happens, because the
 * whole point is the customer who never presses Place order.
 *
 * Expects input#foodify_cart_consent and input#billing_email, and
 * window.FOODIFY_CART = { endpoint, nonce }. Posts form-encoded
 * nonce / consent (1|0) / email to the endpoint; the server reads the cart
 * from the session, never from the request.
 */
function foodify_cart_consent_script(): string {
	return <<<'JS'
(function(){
  var cfg=window.FOODIFY_CART||{};if(!cfg.endpoint){return;}
  var timer;
  function box(){return document.getElementById('foodify_cart_consent');}
  function email(){var f=document.getElementById('billing_email');return f?f.value.trim():'';}
  function send(){
    var b=box();if(!b){return;}
    var e=email();
    if(b.checked&&!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(e)){return;}
    var body=new URLSearchParams();body.set('nonce',cfg.nonce||'');body.set('consent',b.checked?'1':'0');body.set('email',b.checked?e:'');
    fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).catch(function(){});
  }
  document.addEventListener('change',function(ev){
    var t=ev.target;if(!t){return;}
    if(t.id==='foodify_cart_consent'){send();}
    else if(t.id==='billing_email'){var b=box();if(b&&b.checked){clearTimeout(timer);timer=setTimeout(send,400);}}
  });
})();
JS;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;
}

function foodify_cart_state(): array {
	static $state = null;
	if ( null === $state ) {
		$state = foodify_cart_config_state( (string) get_option( 'foodify_cart_reminder_delay_minutes', '' ), function_exists( 'as_schedule_single_action' ) );
	}
	return $state;
}

function foodify_cart_recovery_active(): bool {
	return foodify_cart_state()['active'];
}

/** Has this signed-in customer said yes in their account? */
function foodify_cart_user_optin( int $user_id ): bool {
	return $user_id > 0 && 'yes' === (string) get_user_meta( $user_id, FOODIFY_CART_OPTIN_META, true );
}

function foodify_cart_email_hash( string $email ): string {
	return hash_hmac( 'sha256', $email, wp_salt( 'auth' ) );
}

add_action( 'init', static function (): void {
	register_post_type( 'foodify_cart_snap', [
		'label'               => 'Saved carts',
		'public'              => false,
		'publicly_queryable'  => false,
		'show_ui'             => false,
		'show_in_rest'        => false,
		'show_in_nav_menus'   => false,
		'exclude_from_search' => true,
		'query_var'           => false,
		'rewrite'             => false,
		'can_export'          => false,
		'supports'            => [],
	] );
} );

function foodify_cart_snap_load( int $id ): ?array {
	if ( $id < 1 || 'foodify_cart_snap' !== get_post_type( $id ) ) {
		return null;
	}
	$m = static fn( string $k ) => get_post_meta( $id, '_fcs_' . $k, true );
	return [
		'id'       => $id,
		'email'    => (string) $m( 'email' ),
		'lines'    => is_array( $m( 'lines' ) ) ? $m( 'lines' ) : [],
		'created'  => (int) $m( 'created' ),
		'activity' => (int) $m( 'activity' ),
		'sent'     => (int) $m( 'sent' ),
		'nonce'    => (string) $m( 'nonce' ),
		'user_id'  => (int) $m( 'user' ),
	];
}

function foodify_cart_snap_delete( int $id ): void {
	if ( 'foodify_cart_snap' !== get_post_type( $id ) ) {
		return;
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'foodify_cart_reminder', [ $id ], 'foodify' );
		as_unschedule_all_actions( 'foodify_cart_snap_cleanup', [ $id ], 'foodify' );
	}
	wp_delete_post( $id, true );
}

/** @return int[] */
function foodify_cart_snaps_for_email( string $email ): array {
	return array_map( 'intval', get_posts( [
		'post_type'      => 'foodify_cart_snap',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => 20,
		'meta_key'       => '_fcs_email_hash', // phpcs:ignore WordPress.DB.SlowDBQuery — indexed meta_key, tiny table.
		'meta_value'     => foodify_cart_email_hash( $email ), // phpcs:ignore WordPress.DB.SlowDBQuery
	] ) );
}

/** Create or refresh the snapshot for this session, and (re)schedule its one reminder. */
function foodify_cart_snap_upsert( int $id, string $email, array $lines, int $user_id ): int {
	$now = time();
	if ( ! foodify_cart_snap_load( $id ) ) {
		foreach ( foodify_cart_snaps_for_email( $email ) as $old ) {   // one saved cart per person
			foodify_cart_snap_delete( $old );
		}
		$id = (int) wp_insert_post( [ 'post_type' => 'foodify_cart_snap', 'post_status' => 'private', 'post_title' => '' ] );
		if ( $id < 1 ) {
			return 0;
		}
		update_post_meta( $id, '_fcs_created', $now );
		update_post_meta( $id, '_fcs_nonce', bin2hex( random_bytes( 8 ) ) );
	}
	update_post_meta( $id, '_fcs_email', $email );
	update_post_meta( $id, '_fcs_email_hash', foodify_cart_email_hash( $email ) );
	update_post_meta( $id, '_fcs_lines', $lines );
	update_post_meta( $id, '_fcs_activity', $now );
	update_post_meta( $id, '_fcs_user', $user_id );
	$snap = foodify_cart_snap_load( $id );
	if ( $snap && ! $snap['sent'] ) {
		as_unschedule_all_actions( 'foodify_cart_reminder', [ $id ], 'foodify' );
		as_schedule_single_action( $now + 60 * foodify_cart_state()['delay'], 'foodify_cart_reminder', [ $id ], 'foodify' );
	}
	return $id;
}

/** Keep the snapshot in step with the cart — for a session that consented, or a signed-in customer who opted in. */
function foodify_cart_sync(): void {
	if ( ! foodify_cart_recovery_active() || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
		return;
	}
	$id    = (int) WC()->session->get( 'foodify_cart_snap' );
	$snap  = foodify_cart_snap_load( $id );
	$lines = foodify_cart_snapshot_lines( WC()->cart->get_cart() );
	if ( $snap && $snap['sent'] ) {
		return;   // one reminder only; nothing more to keep current
	}
	if ( ! $lines ) {
		if ( $snap ) {
			foodify_cart_snap_delete( $id );   // an emptied cart is not an abandoned one
			WC()->session->set( 'foodify_cart_snap', 0 );
		}
		return;
	}
	if ( $snap ) {
		foodify_cart_snap_upsert( $id, $snap['email'], $lines, $snap['user_id'] );
		return;
	}
	$uid = get_current_user_id();
	if ( foodify_cart_user_optin( $uid ) ) {
		$email = foodify_cart_email( (string) wp_get_current_user()->user_email );
		if ( $email ) {
			WC()->session->set( 'foodify_cart_snap', foodify_cart_snap_upsert( 0, $email, $lines, $uid ) );
		}
	}
}
foreach ( [ 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_emptied' ] as $foodify_cart_hook ) {
	add_action( $foodify_cart_hook, static function (): void {
		// After WooCommerce has finished mutating the cart, once per request.
		if ( ! has_action( 'shutdown', 'foodify_cart_sync' ) ) {
			add_action( 'shutdown', 'foodify_cart_sync', 5 );
		}
	}, 99 );
}
unset( $foodify_cart_hook );

/* ---- checkout: the tick, as it happens ----------------------------------- */

add_action( 'wc_ajax_foodify_cart_consent', static function (): void {
	if ( ! foodify_cart_recovery_active() || ! check_ajax_referer( 'foodify_cart_consent', 'nonce', false ) ) {
		wp_send_json_error( [ 'state' => 'refused' ], 403 );
	}
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key  = 'foodify_cc_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	$gate = foodify_rate_gate( (array) get_transient( $key ), time(), 20, 600 );
	if ( ! $gate['allowed'] ) {
		wp_send_json_error( [ 'state' => 'slow_down' ], 429 );
	}
	set_transient( $key, $gate['kept'], 600 );

	$yes = ! empty( $_POST['consent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['consent'] ) );
	$uid = get_current_user_id();
	$id  = WC()->session ? (int) WC()->session->get( 'foodify_cart_snap' ) : 0;

	if ( ! $yes ) {
		if ( $id ) {
			foodify_cart_snap_delete( $id );
		}
		if ( WC()->session ) {
			WC()->session->set( 'foodify_cart_snap', 0 );
		}
		if ( $uid ) {
			update_user_meta( $uid, FOODIFY_CART_OPTIN_META, 'no' );
		}
		wp_send_json_success( [ 'state' => 'removed' ] );
	}

	$email = foodify_cart_email( isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '' );
	$lines = WC()->cart ? foodify_cart_snapshot_lines( WC()->cart->get_cart() ) : [];
	if ( ! $email || ! $lines || ! WC()->session ) {
		wp_send_json_success( [ 'state' => 'empty' ] );
	}
	WC()->session->set( 'foodify_cart_snap', foodify_cart_snap_upsert( $id, $email, $lines, $uid ) );
	if ( $uid ) {
		update_user_meta( $uid, FOODIFY_CART_OPTIN_META, 'yes' );   // the same choice, one place
	}
	wp_send_json_success( [ 'state' => 'saved' ] );
} );

add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! foodify_cart_recovery_active() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}
	wp_register_script( 'foodify-cart-consent', false, [], FOODIFY_VERSION, true );
	wp_enqueue_script( 'foodify-cart-consent' );
	wp_add_inline_script( 'foodify-cart-consent', 'window.FOODIFY_CART=' . wp_json_encode( [
		'endpoint' => WC_AJAX::get_endpoint( 'foodify_cart_consent' ),
		'nonce'    => wp_create_nonce( 'foodify_cart_consent' ),
	] ) . ';', 'before' );
	wp_add_inline_script( 'foodify-cart-consent', foodify_cart_consent_script() );
} );

/* ---- an order is the end of it ------------------------------------------- */

add_action( 'woocommerce_new_order', static function ( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	$email = $order instanceof WC_Order ? foodify_cart_email( (string) $order->get_billing_email() ) : null;
	if ( $email ) {
		foreach ( foodify_cart_snaps_for_email( $email ) as $id ) {
			foodify_cart_snap_delete( $id );
		}
	}
	if ( function_exists( 'WC' ) && WC()->session ) {
		$id = (int) WC()->session->get( 'foodify_cart_snap' );
		if ( $id ) {
			foodify_cart_snap_delete( $id );
		}
		WC()->session->set( 'foodify_cart_snap', 0 );
	}
}, 5 );

/* ---- the reminder --------------------------------------------------------- */

add_action( 'foodify_cart_reminder', static function ( $id ): void {
	$id   = (int) $id;
	$snap = foodify_cart_snap_load( $id );
	if ( ! $snap ) {
		return;
	}
	$cfg      = foodify_cart_state();
	$statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : 'any';
	$ordered  = (bool) wc_get_orders( [
		'billing_email' => $snap['email'],
		'date_created'  => '>=' . $snap['created'],
		'status'        => $statuses,
		'limit'         => 1,
		'return'        => 'ids',
	] );
	$due = foodify_cart_reminder_due( $snap, time(), $cfg['delay'], $ordered );
	if ( 'not_yet' === $due['reason'] ) {
		as_schedule_single_action( $due['at'], 'foodify_cart_reminder', [ $id ], 'foodify' );
		return;
	}
	if ( ! $due['send'] ) {
		if ( in_array( $due['reason'], [ 'ordered', 'empty', 'inactive' ], true ) ) {
			foodify_cart_snap_delete( $id );
		}
		return;
	}

	$exp     = time() + FOODIFY_CART_KEEP_DAYS * DAY_IN_SECONDS;
	$restore = foodify_link_token_sign( [ 'p' => 'cart', 'u' => $id, 'n' => $snap['nonce'], 'e' => $exp ], foodify_link_key( 'cart' ) );
	$stop    = foodify_link_token_sign( [ 'p' => 'cart-stop', 'u' => $id, 'n' => $snap['nonce'], 'e' => $exp ], foodify_link_key( 'cart-stop' ) );
	$mail    = foodify_cart_email_parts( [
		'summary' => foodify_repeat_summary( foodify_repeat_named_items( $snap['lines'] ) ),
		'link'    => add_query_arg( 'foodify_cart', $restore, wc_get_cart_url() ),
		'stop'    => add_query_arg( 'foodify_cart_stop', $stop, wc_get_cart_url() ),
	] );
	$mailer = WC()->mailer();
	$mailer->send( $snap['email'], $mail['subject'], $mailer->wrap_message( $mail['heading'], $mail['body'] ) );

	update_post_meta( $id, '_fcs_sent', time() );
	as_schedule_single_action( $exp, 'foodify_cart_snap_cleanup', [ $id ], 'foodify' );
} );

add_action( 'foodify_cart_snap_cleanup', static function ( $id ): void {
	foodify_cart_snap_delete( (int) $id );
} );

/** Belt and braces: anything past its retention goes, daily. */
add_action( 'init', static function (): void {
	if ( ! function_exists( 'as_has_scheduled_action' ) || get_transient( 'foodify_cart_sweep_ok' ) ) {
		return;
	}
	if ( false === as_has_scheduled_action( 'foodify_cart_sweep' ) ) {
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'foodify_cart_sweep', [], 'foodify' );
	}
	set_transient( 'foodify_cart_sweep_ok', 1, DAY_IN_SECONDS );
} );

add_action( 'foodify_cart_sweep', static function (): void {
	$ids = get_posts( [ 'post_type' => 'foodify_cart_snap', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 500 ] );
	foreach ( $ids as $id ) {
		$snap = foodify_cart_snap_load( (int) $id );
		// Retention runs even if the feature was switched off since: off must not mean "kept forever".
		if ( $snap && foodify_cart_snapshot_expired( $snap, time(), max( 15, foodify_cart_state()['delay'] ) ) ) {
			foodify_cart_snap_delete( (int) $id );
		}
	}
} );

/* ---- the two links in the email ------------------------------------------ */

add_action( 'template_redirect', static function (): void {
	$restore = ! empty( $_GET['foodify_cart'] );
	$stop    = ! empty( $_GET['foodify_cart_stop'] );
	if ( ( ! $restore && ! $stop ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$param   = $restore ? 'foodify_cart' : 'foodify_cart_stop';
	$purpose = $restore ? 'cart' : 'cart-stop';
	$back    = remove_query_arg( [ 'foodify_cart', 'foodify_cart_stop' ] );
	$claims  = foodify_link_token_verify( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ), foodify_link_key( $purpose ), $purpose, time() );
	$snap    = $claims ? foodify_cart_snap_load( (int) $claims['u'] ) : null;

	if ( ! $snap || ! hash_equals( $snap['nonce'], (string) $claims['n'] ) ) {
		wc_add_notice(
			$stop ? __( 'That saved cart is already deleted.', 'foodify' ) : __( 'This saved cart has expired or an order was already placed with it.', 'foodify' ),
			$stop ? 'success' : 'notice'
		);
		wp_safe_redirect( $back );
		exit;
	}

	if ( $stop ) {
		foodify_cart_snap_delete( $snap['id'] );
		if ( $snap['user_id'] ) {
			update_user_meta( $snap['user_id'], FOODIFY_CART_OPTIN_META, 'no' );
		}
		wc_add_notice( __( 'Done — your saved cart is deleted and we will not email you about it.', 'foodify' ), 'success' );
		wp_safe_redirect( $back );
		exit;
	}

	$plan = foodify_cart_rebuild_plan( $snap['lines'], foodify_cart_catalog( $snap['lines'] ) );
	foodify_cart_apply_plan( $plan );
	if ( WC()->customer && '' === (string) WC()->customer->get_billing_email() ) {
		WC()->customer->set_billing_email( $snap['email'] );   // the address the link was sent to
	}
	wc_add_notice( esc_html( $plan['message'] ), $plan['skipped'] || $plan['gone'] || $plan['reduced'] ? 'notice' : 'success' );
	wp_safe_redirect( $back );
	exit;
}, 2 );

/* ---- My Account → Your details ------------------------------------------- */

add_action( 'woocommerce_edit_account_form', static function (): void {
	if ( ! foodify_cart_recovery_active() ) {
		return;
	}
	echo foodify_cart_account_field_html( foodify_cart_user_optin( get_current_user_id() ), foodify_cart_state()['delay'] ); // phpcs:ignore WordPress.Security.EscapeOutput — static markup.
} );

/** WooCommerce has verified its own nonce for this form before the action fires. */
add_action( 'woocommerce_save_account_details', static function ( $user_id ): void {
	if ( ! foodify_cart_recovery_active() ) {
		return;
	}
	$user_id = (int) $user_id;
	$yes     = ! empty( $_POST['foodify_cart_optin'] ); // phpcs:ignore WordPress.Security.NonceVerification — checked by WooCommerce.
	update_user_meta( $user_id, FOODIFY_CART_OPTIN_META, $yes ? 'yes' : 'no' );
	if ( ! $yes ) {
		$user  = get_userdata( $user_id );
		$email = $user ? foodify_cart_email( (string) $user->user_email ) : null;
		foreach ( $email ? foodify_cart_snaps_for_email( $email ) : [] as $id ) {
			foodify_cart_snap_delete( $id );
		}
	}
} );

/** Tools → Erase Personal Data reaches saved carts too. */
add_filter( 'wp_privacy_personal_data_erasers', static function ( array $erasers ): array {
	$erasers['foodify-saved-carts'] = [
		'eraser_friendly_name' => __( 'Foodify saved carts', 'foodify' ),
		'callback'             => static function ( string $email ): array {
			$ids = foodify_cart_snaps_for_email( strtolower( trim( $email ) ) );
			foreach ( $ids as $id ) {
				foodify_cart_snap_delete( $id );
			}
			return [ 'items_removed' => count( $ids ) > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
		},
	];
	return $erasers;
} );

add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$state = foodify_cart_state();
	if ( ! $state['half'] ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <code>%3$s</code></p></div>',
		esc_html__( 'Foodify cart reminders are OFF:', 'foodify' ),
		esc_html__( 'partly configured, so customers see nothing. Fix:', 'foodify' ),
		esc_html( implode( ', ', $state['missing'] ) )
	);
} );
