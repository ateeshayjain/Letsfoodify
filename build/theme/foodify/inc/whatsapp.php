<?php
/**
 * 9: WhatsApp order updates — through Meta's WhatsApp Cloud API directly, no
 * BSP subscription in between.
 *
 * SHIPS OFF. Nothing customer-facing exists until ALL of these are true:
 *   - wp-config.php defines FOODIFY_WA_TOKEN and FOODIFY_WA_PHONE_ID. The
 *     credentials live ONLY there — never in an option, never in this repo;
 *   - option foodify_whatsapp_enabled = yes;
 *   - options foodify_whatsapp_language and foodify_whatsapp_graph_version are set;
 *   - option foodify_whatsapp_templates names at least one approved template.
 * A half-configured site gets an admin notice naming what is missing.
 *
 * THE RULES
 *  1. CONSENT IS PER ORDER AND UNTICKED. "Send order updates on WhatsApp to this
 *     number" is a checkbox the customer ticks (inc/checkout-fields.php adds it);
 *     nobody is opted in by default, and the order page carries a one-tap
 *     "Stop WhatsApp updates".
 *  2. CHECKOUT NEVER WAITS ON META. A status change only enqueues an Action
 *     Scheduler job. The HTTP call happens later, in the background; a slow or
 *     failing API cannot delay or fail an order.
 *  3. ONE MESSAGE PER EVENT PER ORDER. A status flap (completed → processing →
 *     completed) must not send "on its way" twice; sent events are stored on the
 *     order and re-checked at SEND time, as are consent and refunds.
 *  4. LOGS CARRY NO PII. A failure becomes an order note with the event, the
 *     HTTP status and Meta's numeric error code — never the phone number, the
 *     message body, or Meta's free-text error (which can quote the recipient).
 *
 * COST: Meta bills per template message (order updates are the "utility"
 * category), at a per-country rate Meta sets and changes. No rate is quoted
 * here because none could be verified. The current rate is on Meta's
 * "WhatsApp Business Platform pricing" page and in WhatsApp Manager → Billing.
 * It is a running cost the client approves BEFORE foodify_whatsapp_enabled = yes.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const FOODIFY_WA_OPTIN_META = '_foodify_wa_optin';     // 'yes' | 'no'
const FOODIFY_WA_SENT_META  = '_foodify_wa_sent';      // [ event => unix time ]

/* -------------------------------------------------------------------------
 * Pure — tested in tests/retention-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * An Indian mobile number in the form the Cloud API wants: 91XXXXXXXXXX.
 *
 * Accepts what people type — "+91 98765 43210", "098765-43210", "9876543210".
 * Returns null for anything that is not a 10-digit mobile starting 6–9: a
 * landline or a typo must not become a message to a stranger.
 */
function foodify_wa_phone( string $raw ): ?string {
	$digits = preg_replace( '/\D/', '', $raw ) ?? '';
	if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '91' ) ) {
		$digits = substr( $digits, 2 );
	} elseif ( 11 === strlen( $digits ) && '0' === $digits[0] ) {
		$digits = substr( $digits, 1 );
	}
	if ( 1 !== preg_match( '/^[6-9][0-9]{9}$/', $digits ) ) {
		return null;
	}
	return '91' . $digits;
}

/**
 * Which update an order status change means, or null for none.
 *
 * In this store `completed` is "it has left us" (WP-10's email copy: "On its
 * way"), so it maps to SHIPPED, not delivered. Out-for-delivery and delivered
 * exist only if a courier plugin registers those statuses; they are mapped only
 * when present in $registered, so nothing is promised for a status the store
 * cannot reach.
 *
 * @param string   $status     New status, with or without the wc- prefix.
 * @param string[] $registered Registered status slugs, with or without wc-.
 */
function foodify_wa_event_for_status( string $status, array $registered = [] ): ?string {
	$status = (string) preg_replace( '/^wc-/', '', $status );
	$known  = array_map( static fn( $s ): string => (string) preg_replace( '/^wc-/', '', (string) $s ), $registered );
	$map    = [
		'processing'       => 'order_confirmed',
		'completed'        => 'order_shipped',
		'out-for-delivery' => 'out_for_delivery',
		'delivered'        => 'delivered',
	];
	if ( ! isset( $map[ $status ] ) ) {
		return null;
	}
	if ( in_array( $status, [ 'out-for-delivery', 'delivered' ], true ) && ! in_array( $status, $known, true ) ) {
		return null;
	}
	return $map[ $status ];
}

/**
 * The approved template for an event, or null when none is configured.
 *
 * A shipped order with a tracking link prefers order_shipped_tracking (whose
 * body carries the link as its third variable) and falls back to the plain
 * order_shipped template rather than sending nothing.
 *
 * @param array<string,string> $templates event => approved template name.
 * @return array{name:string,language:string,event:string}|null
 */
function foodify_wa_template_for( string $event, array $templates, string $language, bool $has_tracking = false ): ?array {
	if ( '' === trim( $language ) ) {
		return null;
	}
	$pick = $event;
	if ( 'order_shipped' === $event && $has_tracking && '' !== trim( (string) ( $templates['order_shipped_tracking'] ?? '' ) ) ) {
		$pick = 'order_shipped_tracking';
	}
	$name = trim( (string) ( $templates[ $pick ] ?? '' ) );
	// Meta template names are lowercase letters, digits and underscores.
	if ( '' === $name || 1 !== preg_match( '/^[a-z0-9_]{1,512}$/', $name ) ) {
		return null;
	}
	return [ 'name' => $name, 'language' => trim( $language ), 'event' => $pick ];
}

/** Indian rupee formatting, whole rupees: ₹1,250 / ₹1,25,000. Shared by this lane's copy. */
function foodify_rupees_whole( float $amount ): string {
	$n = (string) (int) round( abs( $amount ) );
	if ( strlen( $n ) > 3 ) {
		$rest = (string) preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', substr( $n, 0, -3 ) );
		$n    = $rest . ',' . substr( $n, -3 );
	}
	return '₹' . $n;
}

/**
 * The template's body variables, in order. The approved template text must use
 * {{1}}, {{2}}… in exactly this order — the handoff carries the text to submit.
 *
 * @param array{first_name?:string,number?:string,total?:float,tracking?:string,date?:string} $o
 * @return string[]
 */
function foodify_wa_body_params( string $template_event, array $o ): array {
	$name = trim( (string) ( $o['first_name'] ?? '' ) );
	$name = '' !== $name ? $name : 'there';
	$num  = (string) ( $o['number'] ?? '' );
	switch ( $template_event ) {
		case 'order_confirmed':
			return [ $name, $num, foodify_rupees_whole( (float) ( $o['total'] ?? 0 ) ) ];
		case 'order_shipped':
		case 'out_for_delivery':
		case 'delivered':
			return [ $name, $num ];
		case 'order_shipped_tracking':
			return [ $name, $num, (string) ( $o['tracking'] ?? '' ) ];
		case 'repeat_reminder':
			return [ $name, (string) ( $o['date'] ?? '' ) ];
	}
	return [];
}

/**
 * The Cloud API request body for a template message.
 *
 * @param array{name:string,language:string} $tpl
 * @param string[] $params        Body variables, in order.
 * @param string   $button_suffix Dynamic suffix of the template's URL button (index 0), or ''.
 */
function foodify_wa_payload( string $to, array $tpl, array $params, string $button_suffix = '' ): array {
	$components = [];
	if ( $params ) {
		$components[] = [
			'type'       => 'body',
			'parameters' => array_map( static fn( $p ): array => [ 'type' => 'text', 'text' => (string) $p ], array_values( $params ) ),
		];
	}
	if ( '' !== $button_suffix ) {
		$components[] = [
			'type'       => 'button',
			'sub_type'   => 'url',
			'index'      => '0',
			'parameters' => [ [ 'type' => 'text', 'text' => $button_suffix ] ],
		];
	}
	$template = [ 'name' => (string) $tpl['name'], 'language' => [ 'code' => (string) $tpl['language'] ] ];
	if ( $components ) {
		$template['components'] = $components;
	}
	return [
		'messaging_product' => 'whatsapp',
		'recipient_type'    => 'individual',
		'to'                => $to,
		'type'              => 'template',
		'template'          => $template,
	];
}

/**
 * Is the feature configured, and if not, what is missing?
 *
 * @param array<string,string> $templates
 * @return array{active:bool,half:bool,missing:string[]}
 */
function foodify_wa_config_state( bool $has_token, bool $has_phone_id, string $enabled, array $templates, string $language, string $version ): array {
	$has_template = false;
	foreach ( [ 'order_confirmed', 'order_shipped', 'order_shipped_tracking', 'out_for_delivery', 'delivered' ] as $e ) {
		if ( '' !== trim( (string) ( $templates[ $e ] ?? '' ) ) ) {
			$has_template = true;
		}
	}
	$checks  = [
		'FOODIFY_WA_TOKEN (wp-config.php)'    => $has_token,
		'FOODIFY_WA_PHONE_ID (wp-config.php)' => $has_phone_id,
		'foodify_whatsapp_enabled = yes'      => 'yes' === $enabled,
		'foodify_whatsapp_templates'          => $has_template,
		'foodify_whatsapp_language'           => '' !== trim( $language ),
		'foodify_whatsapp_graph_version'      => 1 === preg_match( '/^v\d+\.\d+$/', trim( $version ) ),
	];
	$missing = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
	return [
		'active'  => [] === $missing,
		'half'    => [] !== $missing && count( $missing ) < count( $checks ),
		'missing' => $missing,
	];
}

/**
 * Should this update go out right now? Re-run at SEND time, not only at
 * enqueue time: a refund or a withdrawn consent in between must win.
 *
 * @param array{active?:bool,consent?:bool,phone?:?string,template?:?array,already_sent?:bool,order_ok?:bool} $s
 * @return array{send:bool,reason:string}
 */
function foodify_wa_should_send( array $s ): array {
	$checks = [
		'inactive'     => ! empty( $s['active'] ),
		'no_consent'   => ! empty( $s['consent'] ),
		'no_phone'     => is_string( $s['phone'] ?? null ) && '' !== $s['phone'],
		'no_template'  => is_array( $s['template'] ?? null ),
		'already_sent' => empty( $s['already_sent'] ),
		'order_state'  => ! array_key_exists( 'order_ok', $s ) || ! empty( $s['order_ok'] ),
	];
	foreach ( $checks as $reason => $ok ) {
		if ( ! $ok ) {
			return [ 'send' => false, 'reason' => $reason ];
		}
	}
	return [ 'send' => true, 'reason' => '' ];
}

/**
 * Seconds to wait before attempt $attempt + 1, or null to stop.
 *
 * Retried: a network failure (0), 429 and 5xx — the API was busy or down.
 * Not retried: any other 4xx — a bad template, a number not on WhatsApp, an
 * expired token. Repeating those only repeats the failure.
 */
function foodify_wa_retry_after( int $http_code, int $attempt ): ?int {
	if ( ! ( 0 === $http_code || 429 === $http_code || $http_code >= 500 ) ) {
		return null;
	}
	$steps = [ 1 => 60, 2 => 300, 3 => 1800, 4 => 7200 ];
	return $steps[ $attempt ] ?? null;
}

/** The order note for a failure. Status and code only. */
function foodify_wa_failure_note( string $event, int $http_code, int $meta_code, ?int $retry_in ): string {
	$what = sprintf( 'WhatsApp "%s" update not sent (HTTP %d%s).', $event, $http_code, $meta_code > 0 ? ', Meta error ' . $meta_code : '' );
	if ( null === $retry_in ) {
		return $what . ' Not retrying.';
	}
	$wait = $retry_in >= 3600 ? (int) round( $retry_in / 3600 ) . ' h' : (int) round( $retry_in / 60 ) . ' min';
	return $what . ' Retrying in ' . $wait . '.';
}

/** The checkout field definition. Unticked. inc/checkout-fields.php adds it when the feature is active. */
function foodify_wa_optin_field(): array {
	return [
		'type'     => 'checkbox',
		'label'    => 'Send order updates on WhatsApp to this number',
		'required' => false,
		'default'  => 0,
		'class'    => [ 'form-row-wide', 'fd-optin' ],
		'priority' => 11,   // directly under the mobile number (priority 10)
	];
}

/**
 * The "stop" control on an order page.
 *
 * @param string $nonce_field Pre-rendered nonce input (WordPress supplies it).
 */
function foodify_wa_stop_form_html( int $order_id, string $nonce_field ): string {
	return '<form method="post" class="fd-optin-stop">' . $nonce_field
		. '<input type="hidden" name="foodify_wa_stop" value="' . $order_id . '">'
		. '<p class="fd-optin-stop__text">You get WhatsApp updates for this order.</p>'
		. '<button type="submit" class="fd-secondary">Stop WhatsApp updates</button></form>';
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;
}

/** @return array<string,string> event => approved template name */
function foodify_wa_templates(): array {
	$raw = get_option( 'foodify_whatsapp_templates', '' );
	if ( is_string( $raw ) ) {
		$raw = json_decode( $raw, true );
	}
	return is_array( $raw ) ? array_map( 'strval', $raw ) : [];
}

function foodify_wa_state(): array {
	static $state = null;
	if ( null === $state ) {
		$state = foodify_wa_config_state(
			defined( 'FOODIFY_WA_TOKEN' ) && '' !== (string) constant( 'FOODIFY_WA_TOKEN' ),
			defined( 'FOODIFY_WA_PHONE_ID' ) && '' !== (string) constant( 'FOODIFY_WA_PHONE_ID' ),
			(string) get_option( 'foodify_whatsapp_enabled', '' ),
			foodify_wa_templates(),
			(string) get_option( 'foodify_whatsapp_language', '' ),
			(string) get_option( 'foodify_whatsapp_graph_version', '' )
		);
		if ( $state['active'] && ! function_exists( 'as_enqueue_async_action' ) ) {
			$state = [ 'active' => false, 'half' => true, 'missing' => [ 'Action Scheduler (bundled with WooCommerce)' ] ];
		}
	}
	return $state;
}

function foodify_wa_active(): bool {
	return foodify_wa_state()['active'];
}

/** Has this order's customer consented, and not withdrawn? */
function foodify_wa_order_consent( WC_Order $order ): bool {
	return 'yes' === (string) $order->get_meta( FOODIFY_WA_OPTIN_META );
}

/**
 * POST one message. Returns the HTTP status (0 on a network failure), Meta's
 * numeric error code, and the message id. Nothing here is logged.
 *
 * @return array{http:int,code:int,id:string}
 */
function foodify_wa_post( array $payload ): array {
	$url      = sprintf(
		'https://graph.facebook.com/%s/%s/messages',
		rawurlencode( trim( (string) get_option( 'foodify_whatsapp_graph_version', '' ) ) ),
		rawurlencode( (string) constant( 'FOODIFY_WA_PHONE_ID' ) )
	);
	$response = wp_remote_post( $url, [
		'timeout' => 10,
		'headers' => [
			'Authorization' => 'Bearer ' . (string) constant( 'FOODIFY_WA_TOKEN' ),
			'Content-Type'  => 'application/json',
		],
		'body'    => (string) wp_json_encode( $payload ),
	] );
	if ( is_wp_error( $response ) ) {
		return [ 'http' => 0, 'code' => 0, 'id' => '' ];
	}
	$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return [
		'http' => (int) wp_remote_retrieve_response_code( $response ),
		'code' => (int) ( $json['error']['code'] ?? 0 ),
		'id'   => (string) ( $json['messages'][0]['id'] ?? '' ),
	];
}

/**
 * The tracking URL for an order, if a courier integration provides one through
 * the foodify_order_tracking_url filter. inc/fulfilment.php has none today, so
 * "shipped" goes out without a link until one exists.
 */
function foodify_wa_tracking_url( WC_Order $order ): string {
	$url = (string) apply_filters( 'foodify_order_tracking_url', '', $order );
	return 0 === strpos( $url, 'https://' ) ? esc_url_raw( $url ) : '';
}

/** Store the consent on the order — the posted value, nothing inferred. */
add_action( 'woocommerce_checkout_create_order', static function ( WC_Order $order, array $data ): void {
	if ( ! foodify_wa_active() ) {
		return;
	}
	$yes = ! empty( $data['foodify_wa_optin'] ) && null !== foodify_wa_phone( (string) $order->get_billing_phone() );
	$order->update_meta_data( FOODIFY_WA_OPTIN_META, $yes ? 'yes' : 'no' );
	if ( $yes ) {
		$order->update_meta_data( '_foodify_wa_optin_at', time() );
	}
}, 10, 2 );

/** A status change only ENQUEUES. Checkout and admin saves never wait on Meta. */
add_action( 'woocommerce_order_status_changed', static function ( int $order_id, string $from, string $to ): void {
	if ( ! foodify_wa_active() ) {
		return;
	}
	$event = foodify_wa_event_for_status( $to, array_keys( wc_get_order_statuses() ) );
	if ( null === $event ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || ! foodify_wa_order_consent( $order ) ) {
		return;
	}
	$sent = $order->get_meta( FOODIFY_WA_SENT_META );
	if ( is_array( $sent ) && isset( $sent[ $event ] ) ) {
		return;
	}
	as_enqueue_async_action( 'foodify_wa_send', [ $order_id, $event, 1 ], 'foodify' );
}, 20, 3 );

/** The send, decided again at send time. */
add_action( 'foodify_wa_send', static function ( $order_id, $event, $attempt = 1 ): void {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$event    = (string) $event;
	$attempt  = (int) $attempt;
	$tracking = 'order_shipped' === $event ? foodify_wa_tracking_url( $order ) : '';
	$tpl      = foodify_wa_template_for( $event, foodify_wa_templates(), (string) get_option( 'foodify_whatsapp_language', '' ), '' !== $tracking );
	$phone    = foodify_wa_phone( (string) $order->get_billing_phone() );
	$sent     = $order->get_meta( FOODIFY_WA_SENT_META );
	$sent     = is_array( $sent ) ? $sent : [];

	$decision = foodify_wa_should_send( [
		'active'       => foodify_wa_active(),
		'consent'      => foodify_wa_order_consent( $order ),
		'phone'        => $phone,
		'template'     => $tpl,
		'already_sent' => isset( $sent[ $event ] ),
		'order_ok'     => ! in_array( $order->get_status(), [ 'cancelled', 'refunded', 'failed' ], true ),
	] );
	if ( ! $decision['send'] || null === $tpl || null === $phone ) {
		return;   // silent by design: no consent / no template is a choice, not an error
	}

	$params = foodify_wa_body_params( $tpl['event'], [
		'first_name' => (string) $order->get_billing_first_name(),
		'number'     => (string) $order->get_order_number(),
		'total'      => (float) $order->get_total(),
		'tracking'   => $tracking,
	] );
	$result = foodify_wa_post( foodify_wa_payload( $phone, $tpl, $params ) );

	if ( $result['http'] >= 200 && $result['http'] < 300 ) {
		$sent[ $event ] = time();
		$order->update_meta_data( FOODIFY_WA_SENT_META, $sent );
		$order->save();
		return;
	}
	$retry = foodify_wa_retry_after( $result['http'], $attempt );
	$order->add_order_note( foodify_wa_failure_note( $event, $result['http'], $result['code'], $retry ) );
	if ( null !== $retry ) {
		as_schedule_single_action( time() + $retry, 'foodify_wa_send', [ (int) $order_id, $event, $attempt + 1 ], 'foodify' );
	}
}, 10, 3 );

/**
 * A repeat-order reminder (feature 12). Consent is the SOURCE order's, read
 * live, so "Stop WhatsApp updates" on that order stops these too. The signed
 * link token is the template's URL-button suffix.
 */
function foodify_wa_send_repeat_reminder( WC_Order $source, string $date_label, string $token ): void {
	$tpl      = foodify_wa_template_for( 'repeat_reminder', foodify_wa_templates(), (string) get_option( 'foodify_whatsapp_language', '' ) );
	$phone    = foodify_wa_phone( (string) $source->get_billing_phone() );
	$decision = foodify_wa_should_send( [
		'active'   => foodify_wa_active(),
		'consent'  => foodify_wa_order_consent( $source ),
		'phone'    => $phone,
		'template' => $tpl,
	] );
	if ( ! $decision['send'] || null === $tpl || null === $phone ) {
		return;
	}
	$params = foodify_wa_body_params( 'repeat_reminder', [ 'first_name' => (string) $source->get_billing_first_name(), 'date' => $date_label ] );
	$result = foodify_wa_post( foodify_wa_payload( $phone, $tpl, $params, $token ) );
	if ( $result['http'] < 200 || $result['http'] >= 300 ) {
		// The email went out regardless; a reminder is not worth retrying for hours.
		$source->add_order_note( foodify_wa_failure_note( 'repeat_reminder', $result['http'], $result['code'], null ) );
	}
}

/** Withdrawal, on the order page (order-received, and My Account → view order). */
add_action( 'woocommerce_order_details_after_order_table', static function ( $order ): void {
	if ( ! $order instanceof WC_Order || ! foodify_wa_order_consent( $order ) ) {
		return;
	}
	$owner = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
	if ( ! $owner && ! isset( $_GET['key'] ) ) {   // a guest reaches this page by order key, checked on submit
		return;
	}
	echo foodify_wa_stop_form_html( $order->get_id(), wp_nonce_field( 'foodify_wa_stop_' . $order->get_id(), 'foodify_wa_nonce', true, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput — built from an int and a core nonce field.
}, 30 );

add_action( 'template_redirect', static function (): void {
	if ( empty( $_POST['foodify_wa_stop'] ) ) {
		return;
	}
	$order_id = absint( wp_unslash( $_POST['foodify_wa_stop'] ) );
	$nonce    = isset( $_POST['foodify_wa_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['foodify_wa_nonce'] ) ) : '';
	$order    = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || ! wp_verify_nonce( $nonce, 'foodify_wa_stop_' . $order_id ) ) {
		return;
	}
	$owner = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
	$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( ! $owner && ! ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) ) {
		return;
	}
	$order->update_meta_data( FOODIFY_WA_OPTIN_META, 'no' );
	$order->save();
	$order->add_order_note( 'Customer stopped WhatsApp updates for this order.' );
	wc_add_notice( __( 'Done — no more WhatsApp messages about this order.', 'foodify' ), 'success' );
	wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
	exit;
} );

/** Half-configured: say what is missing. Entirely off: say nothing. */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$state = foodify_wa_state();
	if ( ! $state['half'] ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <code>%3$s</code></p></div>',
		esc_html__( 'Foodify WhatsApp updates are OFF:', 'foodify' ),
		esc_html__( 'partly configured, so customers see nothing. Missing:', 'foodify' ),
		esc_html( implode( ', ', $state['missing'] ) )
	);
} );
