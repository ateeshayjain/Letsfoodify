<?php
/**
 * 10: cancel before dispatch, request a return — in My Account.
 *
 * WHAT "DISPATCHED" MEANS IN THIS THEME
 * -------------------------------------
 * There is no shipped flag. The theme's own definition is the order status:
 *
 *   - processing  "Awaiting dispatch" on the Today screen (admin-dashboard.php
 *                 counts exactly this status), "we are packing it now" in the
 *                 WP-10 processing email.
 *   - completed   "It has left us" — the WP-10 completed email is the
 *                 on-its-way note. Completed IS dispatch here.
 *
 * So a customer may cancel while the order is pending, on hold (COD awaiting
 * its confirmation call) or processing, and not once it is completed. A future
 * courier integration that hands a parcel over while the order is still
 * `processing` must say so through the `foodify_order_dispatched` filter, or
 * customers will be able to cancel parcels already in a van.
 *
 * CANCELLING IS MONEY, SO THE ORDER OF OPERATIONS IS FIXED
 * --------------------------------------------------------
 *   1. The status becomes cancelled. WooCommerce restocks on that transition
 *      (it reverses only stock it actually reduced) and emails the shop its
 *      own "Cancelled order" note.
 *   2. Only a PAID, PREPAID order is refunded — through the gateway, with
 *      wc_create_refund( refund_payment ). A COD order has taken no money.
 *      "Processing" alone is not proof of payment: WooCommerce's COD gateway
 *      puts unpaid orders in processing too. Paid means a date_paid exists.
 *   3. If the gateway refund fails, the order STAYS cancelled, the failure is
 *      written on the order, the shop is emailed to refund by hand, and the
 *      customer is told exactly that — not "refunded", and not an error that
 *      makes them think the cancel did not happen.
 *
 * RETURNS ARE OFF UNTIL THE SHOP SAYS HOW LONG THEY ARE OPEN
 * ----------------------------------------------------------
 * `foodify_return_window_days` is a policy number, and policy is the client's.
 * Unset, a delivered order says "Contact us about a problem with your order"
 * with the care address, and wp-admin names the option. The window counts from
 * the delivered date when one is recorded (the "Mark as delivered" order
 * action, or a courier integration writing `_foodify_delivered_at`), otherwise
 * from dispatch — the earlier date, so the window can only be stricter than
 * the policy, never longer than the shop agreed to.
 *
 * Every write: a logged-in customer, a nonce tied to the order and the action,
 * and the order's customer id equal to theirs. An order id that is somebody
 * else's resolves to "not on your account", the same answer as one that does
 * not exist.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/account-actions-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/** "wc-processing" → "processing". */
function foodify_order_status_key( string $status ): string {
	return (string) preg_replace( '/^wc-/', '', strtolower( trim( $status ) ) );
}

/** Statuses a customer may still cancel from. Everything else has left, or never needed cancelling. */
function foodify_cancellable_statuses(): array {
	return [ 'pending', 'on-hold', 'processing' ];
}

/**
 * May this order still be cancelled by its customer?
 *
 * @param array{status:string,dispatched?:bool} $f
 */
function foodify_can_cancel( array $f ): bool {
	if ( ! empty( $f['dispatched'] ) ) {
		return false;   // handed to the courier: a cancel now cannot stop the parcel
	}
	return in_array( foodify_order_status_key( (string) ( $f['status'] ?? '' ) ), foodify_cancellable_statuses(), true );
}

/** Only the order's own customer, and never a guest (customer id 0 is every guest order). */
function foodify_order_owner_ok( int $order_customer_id, int $user_id ): bool {
	return $user_id > 0 && $order_customer_id === $user_id;
}

/**
 * Does cancelling this order mean sending money back?
 *
 * @param array{is_cod?:bool,paid?:bool,remaining?:float} $f
 * @return array{refund:bool,amount:float}
 */
function foodify_cancel_refund_plan( array $f ): array {
	$amount = round( max( 0.0, (float) ( $f['remaining'] ?? 0 ) ), 2 );
	$refund = empty( $f['is_cod'] ) && ! empty( $f['paid'] ) && $amount > 0.0;
	return [ 'refund' => $refund, 'amount' => $refund ? $amount : 0.0 ];
}

/** ₹1,250 · ₹1,25,000 · ₹99.50 — Indian grouping, paise only when there are any. */
function foodify_order_rupees( float $amount ): string {
	$neg    = $amount < 0;
	$amount = round( abs( $amount ), 2 );
	$whole  = (string) (int) floor( $amount );
	$paise  = (int) round( ( $amount - floor( $amount ) ) * 100 );
	if ( strlen( $whole ) > 3 ) {
		$head  = substr( $whole, 0, -3 );
		$whole = ltrim( (string) preg_replace( '/(\d)(?=(\d\d)+$)/', '$1,', $head ), ',' ) . ',' . substr( $whole, -3 );
	}
	return ( $neg ? '−' : '' ) . '₹' . $whole . ( $paise > 0 ? '.' . str_pad( (string) $paise, 2, '0', STR_PAD_LEFT ) : '' );
}

/** What happens to the money, said before the customer confirms. */
function foodify_cancel_money_line( array $plan, bool $is_cod ): string {
	if ( ! empty( $plan['refund'] ) ) {
		return 'You paid ' . foodify_order_rupees( (float) $plan['amount'] ) . '. We send it back to the way you paid as soon as you cancel.';
	}
	return $is_cod
		? 'Nothing has been paid — this order is cash on delivery.'
		: 'Nothing has been charged for this order.';
}

/**
 * What the customer is told after cancelling.
 *
 * @param bool|null $refunded null = no refund was due; true = the gateway took it; false = it failed.
 */
function foodify_cancel_message( string $number, array $plan, ?bool $refunded ): string {
	$head = 'Order #' . $number . ' is cancelled.';
	if ( empty( $plan['refund'] ) || null === $refunded ) {
		return $head . ' Nothing was charged, so there is nothing to refund.';
	}
	$amount = foodify_order_rupees( (float) $plan['amount'] );
	if ( $refunded ) {
		return $head . ' Your refund of ' . $amount . ' has been sent to the way you paid. Banks usually take a few working days to show it.';
	}
	return $head . ' We could not send your ' . $amount . ' refund automatically, so our team has been asked to send it by hand. You will get an email from us when it is on its way.';
}

/** The option, read strictly: a whole number of days, 1–365. Anything else is OFF, never a guess. */
function foodify_return_window_days( $raw ): int {
	if ( is_int( $raw ) ) {
		$days = $raw;
	} elseif ( is_string( $raw ) && preg_match( '/^\s*(\d{1,3})\s*$/', $raw, $m ) ) {
		$days = (int) $m[1];
	} else {
		return 0;
	}
	return $days >= 1 && $days <= 365 ? $days : 0;
}

/**
 * May a return be requested?
 *
 * @param array{status:string,delivered_at?:int,completed_at?:int} $f
 * @return array{open:bool,state:string,closes:int}
 *         state: off | requested | not_yet | unknown | open | closed
 */
function foodify_return_open( array $f, int $window_days, int $now ): array {
	$status = foodify_order_status_key( (string) ( $f['status'] ?? '' ) );
	$out    = static fn( string $state, int $closes = 0 ): array => [ 'open' => 'open' === $state, 'state' => $state, 'closes' => $closes ];

	if ( 'return-requested' === $status ) {
		return $out( 'requested' );
	}
	if ( $window_days < 1 ) {
		return $out( 'off' );
	}
	if ( 'completed' !== $status ) {
		return $out( 'not_yet' );
	}
	$start = (int) ( $f['delivered_at'] ?? 0 );
	if ( $start < 1 ) {
		$start = (int) ( $f['completed_at'] ?? 0 );
	}
	if ( $start < 1 ) {
		return $out( 'unknown' );
	}
	$closes = $start + $window_days * 86400;
	return $out( $now <= $closes ? 'open' : 'closed', $closes );
}

/** The only reasons a return may be asked for. Keys are stored; labels are shown. */
function foodify_return_reasons(): array {
	return [
		'damaged'      => 'Arrived damaged',
		'wrong_item'   => 'Wrong item sent',
		'missing_item' => 'Something is missing',
		'quality'      => 'Expired, or a quality problem',
	];
}

/** A posted reason, or null. Never coerced to a default. */
function foodify_return_reason( string $raw ): ?string {
	$key = strtolower( trim( $raw ) );
	return array_key_exists( $key, foodify_return_reasons() ) ? $key : null;
}

/** The optional note: plain text, one paragraph of whitespace, 500 characters at most. */
function foodify_return_note( string $raw ): string {
	$text = (string) preg_replace( '/<[^>]*>/', '', $raw );
	$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	return mb_substr( $text, 0, 500 );
}

/**
 * The shop's emails. Order number and a link — no address, no phone: the shop
 * already has those on the order, and an inbox is not where they should be copied.
 *
 * @return array{subject:string,body:string}
 */
function foodify_manual_refund_email( array $d ): array {
	$number = (string) ( $d['number'] ?? '' );
	return [
		'subject' => 'Refund needed by hand: order #' . $number,
		'body'    => implode( "\n\n", [
			'Order #' . $number . ' was cancelled by the customer before dispatch, but the automatic refund of '
				. foodify_order_rupees( (float) ( $d['amount'] ?? 0 ) ) . ' through the payment gateway failed.',
			'Gateway said: ' . (string) ( $d['error'] ?? 'no reason given' ),
			'The customer has been told the refund will be sent by hand. Please refund it from the gateway dashboard or the order screen:',
			(string) ( $d['admin_url'] ?? '' ),
		] ),
	];
}

/** @return array{subject:string,body:string} */
function foodify_return_email( array $d ): array {
	$number = (string) ( $d['number'] ?? '' );
	$label  = foodify_return_reasons()[ (string) ( $d['reason'] ?? '' ) ] ?? 'Not given';
	$note   = trim( (string) ( $d['note'] ?? '' ) );
	return [
		'subject' => 'Return requested: order #' . $number . ' — ' . $label,
		'body'    => implode( "\n\n", array_filter( [
			'The customer has asked for a return on order #' . $number . '.',
			'Reason: ' . $label,
			'' !== $note ? 'Their note: ' . $note : '',
			'Reply to them from the order, and change its status once it is resolved:',
			(string) ( $d['admin_url'] ?? '' ),
		] ) ),
	];
}

/**
 * What happens after "Send report", in one sentence (review ACCOUNT-12). One
 * name through the whole flow — "Report a problem" → "Send report" →
 * "Problem reported" — and never "return" in the customer's copy: most of
 * these are a split pouch or a missing pack, not something sent back. The
 * reply goes to the email on the order, and the sentence says which.
 */
function foodify_report_next_step( string $email ): string {
	$email = trim( $email );
	return '' !== $email
		? 'We will look into it and reply to ' . $email . ', the email on this order.'
		: 'We will look into it and reply by email.';
}

/**
 * The block under an order on the view-order page.
 *
 * @param array{order_id:int,number:string,cancel:bool,money?:string,return:array{state:string,closes:int},
 *              closes_label?:string,care_email?:string,customer_email?:string,nonce_cancel?:string,nonce_return?:string} $s
 */
function foodify_order_actions_html( array $s ): string {
	$e      = static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
	$id     = (int) ( $s['order_id'] ?? 0 );
	$number = (string) ( $s['number'] ?? (string) $id );
	$state  = (string) ( $s['return']['state'] ?? 'not_yet' );
	$email  = trim( (string) ( $s['care_email'] ?? '' ) );
	$mail   = '' !== $email
		? '<a href="mailto:' . $e( $email ) . '?subject=' . rawurlencode( 'Order #' . $number ) . '">' . $e( $email ) . '</a>'
		: '';
	$hidden = static fn( string $action, string $nonce ): string =>
		'<input type="hidden" name="foodify_order_action" value="' . $e( $action ) . '">'
		. '<input type="hidden" name="order_id" value="' . $id . '">'
		. '<input type="hidden" name="foodify_order_nonce" value="' . $e( $nonce ) . '">';

	$parts = [];

	if ( ! empty( $s['cancel'] ) ) {
		$parts[] = '<details class="fd-order-cancel" id="fd-cancel">'
			. '<summary class="fd-order-actions__toggle">Cancel this order</summary>'
			. '<form method="post" class="fd-order-actions__form">'
			. '<p>It has not left our kitchen yet, so it can still be stopped. ' . $e( (string) ( $s['money'] ?? '' ) ) . '</p>'
			. $hidden( 'cancel', (string) ( $s['nonce_cancel'] ?? '' ) )
			. '<button type="submit" class="wp-element-button fd-order-actions__confirm is-danger">Yes, cancel order #' . $e( $number ) . '</button>'
			. '</form></details>';
	}

	if ( 'open' === $state ) {
		$radios = '';
		foreach ( foodify_return_reasons() as $key => $label ) {
			$radios .= '<label class="fd-return__reason"><input type="radio" name="reason" value="' . $e( $key ) . '" required> ' . $e( $label ) . '</label>';
		}
		$parts[] = '<details class="fd-order-return" id="fd-return">'
			. '<summary class="fd-order-actions__toggle">Report a problem</summary>'
			. '<form method="post" class="fd-order-actions__form">'
			. '<fieldset class="fd-return__reasons"><legend>What went wrong?</legend>' . $radios . '</fieldset>'
			. '<label class="fd-return__note-label" for="fd-return-note">Anything else we should know? (optional)</label>'
			. '<textarea class="fd-return__note" id="fd-return-note" name="note" rows="3" maxlength="500"></textarea>'
			. '<p class="fd-order-actions__note">' . $e( foodify_report_next_step( (string) ( $s['customer_email'] ?? '' ) ) )
			. ( ! empty( $s['closes_label'] ) ? ' You can report it until ' . $e( (string) $s['closes_label'] ) . '.' : '' ) . '</p>'
			. $hidden( 'return', (string) ( $s['nonce_return'] ?? '' ) )
			. '<button type="submit" class="wp-element-button fd-order-actions__confirm">Send report</button>'
			. '</form></details>';
	} elseif ( 'requested' === $state ) {
		$parts[] = '<p class="fd-order-actions__note"><strong>Problem reported.</strong> ' . $e( foodify_report_next_step( (string) ( $s['customer_email'] ?? '' ) ) ) . '</p>';
	} elseif ( 'closed' === $state && '' !== $mail ) {
		$parts[] = '<p class="fd-order-actions__note">Online reports for this order closed on ' . $e( (string) ( $s['closes_label'] ?? '' ) )
			. '. If something is wrong, contact us: ' . $mail . '</p>';
	} elseif ( in_array( $state, [ 'off', 'unknown' ], true ) && ! empty( $s['delivered'] ) && '' !== $mail ) {
		$parts[] = '<p class="fd-order-actions__note">Contact us about a problem with your order: ' . $mail . '</p>';
	}

	if ( ! $parts ) {
		return '';
	}
	return '<section class="fd-order-actions" aria-labelledby="fd-order-actions-title">'
		. '<h2 class="fd-order-actions__title" id="fd-order-actions-title">Need to change something?</h2>'
		. implode( '', $parts )
		. '</section>';
}

/** Opens the matching panel when the orders list links to #fd-cancel or #fd-return. */
function foodify_order_actions_script(): string {
	return "(function(){var h=location.hash.slice(1),d=h&&document.getElementById(h);if(d&&d.tagName==='DETAILS'){d.open=true;d.scrollIntoView({block:'start'});}})();";
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** Everything the pure rules need, from one order. */
function foodify_order_facts( WC_Order $order ): array {
	$status    = foodify_order_status_key( $order->get_status() );
	$completed = $order->get_date_completed();
	$cod       = function_exists( 'foodify_is_cod' ) && function_exists( 'foodify_payment_config' )
		? foodify_is_cod( (string) $order->get_payment_method(), (array) foodify_payment_config() )
		: 'cod' === $order->get_payment_method();
	return [
		'status'       => $status,
		// Completed is dispatch in this theme (see the header). A courier
		// integration that ships while processing says so here.
		'dispatched'   => (bool) apply_filters( 'foodify_order_dispatched', in_array( $status, [ 'completed', 'return-requested', 'refunded' ], true ), $order ),
		'completed_at' => $completed ? $completed->getTimestamp() : 0,
		'delivered_at' => (int) $order->get_meta( '_foodify_delivered_at' ),
		'is_cod'       => $cod,
		'paid'         => null !== $order->get_date_paid(),
		'remaining'    => (float) $order->get_remaining_refund_amount(),
	];
}

function foodify_order_return_window(): int {
	return foodify_return_window_days( get_option( 'foodify_return_window_days', '' ) );
}

/** Where the shop's own mail goes: the New Order recipient, else the site admin. */
function foodify_order_shop_address(): string {
	if ( function_exists( 'WC' ) && WC()->mailer() ) {
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['WC_Email_New_Order'] ) ) {
			$to = (string) $emails['WC_Email_New_Order']->get_recipient();
			if ( '' !== trim( $to ) ) {
				return $to;
			}
		}
	}
	return (string) get_option( 'admin_email' );
}

function foodify_order_care_email(): string {
	$profile = function_exists( 'foodify_business_profile' ) ? foodify_business_profile() : [];
	return sanitize_email( (string) ( $profile['email'] ?? '' ) );
}

/* ---- the status ---------------------------------------------------------- */

add_action( 'init', static function (): void {
	register_post_status( 'wc-return-requested', [
		'label'                     => _x( 'Problem reported', 'Order status', 'foodify' ),
		'public'                    => false,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		/* translators: %s: number of orders */
		'label_count'               => _n_noop( 'Problem reported <span class="count">(%s)</span>', 'Problem reported <span class="count">(%s)</span>', 'foodify' ),
	] );
} );

add_filter( 'wc_order_statuses', static function ( array $statuses ): array {
	$out = [];
	foreach ( $statuses as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'wc-completed' === $key ) {
			$out['wc-return-requested'] = _x( 'Problem reported', 'Order status', 'foodify' );
		}
	}
	if ( ! isset( $out['wc-return-requested'] ) ) {
		$out['wc-return-requested'] = _x( 'Problem reported', 'Order status', 'foodify' );
	}
	return $out;
} );

// It was paid and delivered; asking for a return does not make it unpaid.
add_filter( 'woocommerce_order_is_paid_statuses', static function ( array $statuses ): array {
	$statuses[] = 'return-requested';
	return array_values( array_unique( $statuses ) );
} );

/* ---- refund through the gateway ------------------------------------------ */

/**
 * A full refund makes WooCommerce move the order to "Refunded". For a customer
 * cancel the true status is Cancelled — the refund is its consequence — so for
 * the one order being refunded here, the status stays put.
 */
add_filter( 'woocommerce_order_fully_refunded_status', static function ( $status, $order_id ) {
	return (int) ( $GLOBALS['foodify_cancel_refunding'] ?? 0 ) === (int) $order_id ? 'cancelled' : $status;
}, 10, 2 );

/** Ask the gateway for the money back. True on success; false after recording and reporting the failure. */
function foodify_cancel_refund( WC_Order $order, float $amount ): bool {
	$GLOBALS['foodify_cancel_refunding'] = $order->get_id();
	try {
		$refund = wc_create_refund( [
			'amount'         => $amount,
			'reason'         => __( 'Cancelled by the customer before dispatch', 'foodify' ),
			'order_id'       => $order->get_id(),
			'refund_payment' => true,
			'restock_items'  => false,   // the cancel already restocked
		] );
	} catch ( Throwable $t ) {
		$refund = new WP_Error( 'foodify_refund_exception', $t->getMessage() );
	} finally {
		unset( $GLOBALS['foodify_cancel_refunding'] );
	}
	if ( ! is_wp_error( $refund ) ) {
		return true;
	}

	$order->add_order_note( sprintf(
		/* translators: 1: amount, 2: gateway error */
		__( 'Automatic refund of %1$s FAILED: %2$s. The order is cancelled; refund it by hand. The customer has been told it will be sent by hand.', 'foodify' ),
		foodify_order_rupees( $amount ),
		$refund->get_error_message()
	) );
	$mail = foodify_manual_refund_email( [
		'number'    => $order->get_order_number(),
		'amount'    => $amount,
		'error'     => $refund->get_error_message(),
		'admin_url' => $order->get_edit_order_url(),
	] );
	wp_mail( foodify_order_shop_address(), $mail['subject'], $mail['body'] );
	return false;
}

/* ---- the handler ---------------------------------------------------------- */

add_action( 'template_redirect', static function (): void {
	if ( empty( $_POST['foodify_order_action'] ) || ! is_user_logged_in() || ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$action   = sanitize_key( wp_unslash( $_POST['foodify_order_action'] ) );
	$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
	$nonce    = isset( $_POST['foodify_order_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['foodify_order_nonce'] ) ) : '';
	if ( ! in_array( $action, [ 'cancel', 'return' ], true ) ) {
		return;
	}
	$back = wc_get_account_endpoint_url( 'orders' );
	if ( ! wp_verify_nonce( $nonce, 'foodify_order_' . $action . '_' . $order_id ) ) {
		wc_add_notice( __( 'That request expired. Please try again.', 'foodify' ), 'error' );
		wp_safe_redirect( $back );
		exit;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || ! foodify_order_owner_ok( (int) $order->get_customer_id(), get_current_user_id() ) ) {
		wc_add_notice( __( 'We could not find that order on your account.', 'foodify' ), 'error' );
		wp_safe_redirect( $back );
		exit;
	}
	$back  = $order->get_view_order_url();
	$facts = foodify_order_facts( $order );
	$care  = foodify_order_care_email();

	if ( 'cancel' === $action ) {
		if ( ! foodify_can_cancel( $facts ) ) {
			wc_add_notice( sprintf(
				/* translators: %s: care email address */
				__( 'This order has already left our kitchen, so it can no longer be cancelled here. Write to %s and we will help.', 'foodify' ),
				$care ?: __( 'us', 'foodify' )
			), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$plan = foodify_cancel_refund_plan( $facts );
		$order->update_status( 'cancelled', __( 'Cancelled by the customer from My Account, before dispatch.', 'foodify' ) );
		$refunded = $plan['refund'] ? foodify_cancel_refund( $order, $plan['amount'] ) : null;
		wc_add_notice(
			esc_html( foodify_cancel_message( (string) $order->get_order_number(), $plan, $refunded ) ),
			false === $refunded ? 'notice' : 'success'
		);
		wp_safe_redirect( $back );
		exit;
	}

	// Return request.
	$window = foodify_return_open( $facts, foodify_order_return_window(), time() );
	if ( ! $window['open'] ) {
		wc_add_notice( __( 'A problem can no longer be reported online for this order. Please contact us instead.', 'foodify' ), 'error' );
		wp_safe_redirect( $back );
		exit;
	}
	$reason = foodify_return_reason( isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '' );
	if ( null === $reason ) {
		wc_add_notice( __( 'Please choose what went wrong.', 'foodify' ), 'error' );
		wp_safe_redirect( $back . '#fd-return' );
		exit;
	}
	$note = foodify_return_note( isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '' );

	$order->update_meta_data( '_foodify_return_reason', $reason );
	$order->update_status( 'return-requested', sprintf(
		/* translators: 1: reason, 2: customer's note */
		__( 'Return requested by the customer: %1$s.%2$s', 'foodify' ),
		foodify_return_reasons()[ $reason ],
		'' !== $note ? ' ' . sprintf( __( 'Note: %s', 'foodify' ), $note ) : ''
	) );
	$mail = foodify_return_email( [
		'number'    => $order->get_order_number(),
		'reason'    => $reason,
		'note'      => $note,
		'admin_url' => $order->get_edit_order_url(),
	] );
	wp_mail( foodify_order_shop_address(), $mail['subject'], $mail['body'] );

	wc_add_notice( esc_html( 'Problem reported. ' . foodify_report_next_step( (string) $order->get_billing_email() ) ), 'success' );
	wp_safe_redirect( $back );
	exit;
}, 5 );

/* ---- the screens ---------------------------------------------------------- */

/**
 * Orders list: Cancel and Report a problem beside Reorder and Details, each a
 * link to the order's own page where the explanation and the confirm live.
 * WooCommerce's own "Cancel" (a bare GET link on unpaid orders) is replaced on
 * the statuses this file handles; on others (failed) it is left alone.
 */
add_filter( 'woocommerce_my_account_my_orders_actions', static function ( array $actions, $order ): array {
	if ( ! $order instanceof WC_Order ) {
		return $actions;
	}
	$facts = foodify_order_facts( $order );
	if ( in_array( $facts['status'], foodify_cancellable_statuses(), true ) ) {
		unset( $actions['cancel'] );
	}
	if ( foodify_can_cancel( $facts ) ) {
		$actions['foodify-cancel'] = [
			'url'  => $order->get_view_order_url() . '#fd-cancel',
			'name' => __( 'Cancel', 'foodify' ),
		];
	}
	if ( foodify_return_open( $facts, foodify_order_return_window(), time() )['open'] ) {
		$actions['foodify-return'] = [
			'url'  => $order->get_view_order_url() . '#fd-return',
			'name' => __( 'Report a problem', 'foodify' ),
		];
	}
	return $actions;
}, 20, 2 );

/** The block under the order on its own page. */
add_action( 'woocommerce_view_order', static function ( $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || ! foodify_order_owner_ok( (int) $order->get_customer_id(), get_current_user_id() ) ) {
		return;
	}
	$facts  = foodify_order_facts( $order );
	$return = foodify_return_open( $facts, foodify_order_return_window(), time() );
	$cancel = foodify_can_cancel( $facts );
	$html   = foodify_order_actions_html( [
		'order_id'     => $order->get_id(),
		'number'       => (string) $order->get_order_number(),
		'cancel'       => $cancel,
		'money'        => foodify_cancel_money_line( foodify_cancel_refund_plan( $facts ), (bool) $facts['is_cod'] ),
		'return'       => $return,
		'delivered'    => 'completed' === $facts['status'],
		'closes_label' => $return['closes'] ? wp_date( 'D j M', $return['closes'] ) : '',
		'care_email'   => foodify_order_care_email(),
		'customer_email' => (string) $order->get_billing_email(),
		'nonce_cancel' => $cancel ? wp_create_nonce( 'foodify_order_cancel_' . $order->get_id() ) : '',
		'nonce_return' => $return['open'] ? wp_create_nonce( 'foodify_order_return_' . $order->get_id() ) : '',
	] );
	if ( '' === $html ) {
		return;
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the builder.
	echo '<script>' . foodify_order_actions_script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static script.
}, 20 );

/* ---- the shop's side ------------------------------------------------------ */

/** "Mark as delivered" in the order actions box: starts the return window from the real date. */
add_filter( 'woocommerce_order_actions', static function ( array $actions ): array {
	$actions['foodify_mark_delivered'] = __( 'Mark as delivered (starts the return window)', 'foodify' );
	return $actions;
} );

add_action( 'woocommerce_order_action_foodify_mark_delivered', static function ( $order ): void {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$order->update_meta_data( '_foodify_delivered_at', time() );
	$order->add_order_note( __( 'Marked as delivered. The return window counts from now.', 'foodify' ) );
	$order->save();
} );

/** Returns are off until the policy exists — say so where someone can set it. */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) || foodify_order_return_window() > 0 ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order', 'toplevel_page_foodify-today' ], true ) ) {
		return;
	}
	printf(
		'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <code>foodify_return_window_days</code></p></div>',
		esc_html__( 'Foodify returns are off.', 'foodify' ),
		esc_html__( 'Customers see "Contact us about a problem with your order" instead of a return form. To open online return requests, set the number of days after delivery:', 'foodify' )
	);
} );
