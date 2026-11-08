<?php
/**
 * Checkout — 25 fields down to 9.
 *
 * Audited state: 25 visible fields, `billing_email` NOT required, `billing_state_1`
 * rendered as free text, a separate country select defaulted to India anyway, and a
 * split first/last name. Four downstream features depend on email being captured:
 * order confirmation, cart recovery, review requests, and partner coupon notifications.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/** Single source of truth for what checkout asks. Nine fields. */
add_filter( 'woocommerce_checkout_fields', static function ( array $fields ): array {

	$billing = $fields['billing'] ?? [];

	// 1. Fields that go entirely.
	foreach ( [ 'billing_company', 'billing_last_name', 'billing_country' ] as $gone ) {
		unset( $billing[ $gone ] );
	}

	// 2. Full name replaces the first/last split.
	if ( isset( $billing['billing_first_name'] ) ) {
		$billing['billing_first_name']['label']       = __( 'Full name', 'foodify' );
		$billing['billing_first_name']['placeholder'] = __( 'Priya Sharma', 'foodify' );
		$billing['billing_first_name']['autocomplete']= 'name';
		$billing['billing_first_name']['class']       = [ 'form-row-wide' ];
		$billing['billing_first_name']['priority']    = 30;
	}

	// 3. Contact first — mobile is the identity, email is the receipt.
	if ( isset( $billing['billing_phone'] ) ) {
		$billing['billing_phone']['label']        = __( 'Mobile number', 'foodify' );
		$billing['billing_phone']['required']     = true;
		$billing['billing_phone']['priority']     = 10;
		$billing['billing_phone']['class']        = [ 'form-row-wide' ];
		$billing['billing_phone']['autocomplete'] = 'tel';
		$billing['billing_phone']['custom_attributes'] = [
			'inputmode'  => 'numeric',
			'pattern'    => '[0-9]{10}',
			'maxlength'  => '10',
		];
	}

	if ( isset( $billing['billing_email'] ) ) {
		$billing['billing_email']['label']        = __( 'Email', 'foodify' );
		$billing['billing_email']['description']  = __( 'For your order confirmation and GST invoice.', 'foodify' );
		$billing['billing_email']['required']     = true;   // was optional — four features depend on this
		$billing['billing_email']['priority']     = 20;
		$billing['billing_email']['class']        = [ 'form-row-wide' ];
		$billing['billing_email']['autocomplete'] = 'email';
	}

	// 4. PIN code leads the address block; it fills city and state.
	if ( isset( $billing['billing_postcode'] ) ) {
		$billing['billing_postcode']['label']    = __( 'PIN code', 'foodify' );
		$billing['billing_postcode']['priority'] = 40;
		$billing['billing_postcode']['class']    = [ 'form-row-first' ];
		$billing['billing_postcode']['custom_attributes'] = [
			'inputmode' => 'numeric',
			'pattern'   => '[1-9][0-9]{5}',
			'maxlength' => '6',
		];
	}

	if ( isset( $billing['billing_city'] ) ) {
		$billing['billing_city']['priority'] = 50;
		$billing['billing_city']['class']    = [ 'form-row-last' ];
	}

	// 5. State becomes a real select. Woo supplies IN states once country is locked to IN.
	if ( isset( $billing['billing_state'] ) ) {
		$billing['billing_state']['type']     = 'state';
		$billing['billing_state']['priority'] = 60;
		$billing['billing_state']['class']    = [ 'form-row-wide' ];
		$billing['billing_state']['required'] = true;
	}

	if ( isset( $billing['billing_address_1'] ) ) {
		$billing['billing_address_1']['label']       = __( 'Address', 'foodify' );
		$billing['billing_address_1']['placeholder'] = __( 'Flat, house number, building', 'foodify' );
		$billing['billing_address_1']['priority']    = 70;
	}

	if ( isset( $billing['billing_address_2'] ) ) {
		$billing['billing_address_2']['placeholder'] = __( 'Area, street, landmark (optional)', 'foodify' );
		$billing['billing_address_2']['priority']    = 80;
		$billing['billing_address_2']['required']    = false;
	}

	// 6. Consent, never assumed (DPDP: affirmative, unticked). Each box exists
	//    only while its feature is fully configured, and sits under the field it
	//    is about: WhatsApp under the mobile number (inc/whatsapp.php), the cart
	//    reminder under email (inc/cart-recovery.php). Keys are NOT billing_*,
	//    so the smoke test's nine-field budget still counts only what is typed.
	if ( function_exists( 'foodify_wa_active' ) && foodify_wa_active() ) {
		$billing['foodify_wa_optin'] = foodify_wa_optin_field();
	}
	if ( function_exists( 'foodify_cart_recovery_active' ) && foodify_cart_recovery_active() ) {
		$billing['foodify_cart_consent'] = foodify_cart_consent_field(
			foodify_cart_state()['delay'],
			foodify_cart_user_optin( get_current_user_id() )   // pre-ticked only by the customer's own earlier "yes"
		);
	}

	$fields['billing'] = $billing;

	// Shipping mirrors billing; the "deliver elsewhere" toggle handles the rare case.
	unset(
		$fields['shipping']['shipping_company'],
		$fields['shipping']['shipping_country'],
		$fields['shipping']['shipping_last_name']
	);
	if ( isset( $fields['shipping']['shipping_first_name'] ) ) {
		$fields['shipping']['shipping_first_name']['label']        = __( 'Full name', 'foodify' );
		$fields['shipping']['shipping_first_name']['class']        = [ 'form-row-wide' ];
		$fields['shipping']['shipping_first_name']['autocomplete'] = 'name';
	}

	return $fields;
}, 20 );

/**
 * Keep a surname in the DATA even though the FORM asks for one full name.
 *
 * The customer sees nine fields either way — the acceptance criterion counts
 * *visible* fields — so removing the stored surname buys nothing and costs a
 * long tail. Verified against source: Razorpay reads billing_last_name in
 * exactly one place, building prefill.name for the payment modal, so an empty
 * surname does NOT break payment. But WooCommerce's own
 * get_formatted_billing_full_name(), the WP-11 courier payload and every GST
 * invoice plugin read it, and each degrades quietly rather than loudly.
 *
 * First word is the given name, the remainder is the surname. A single-word
 * name leaves the surname empty, which is correct — many Indian customers have
 * exactly one name and inventing a second is worse than storing none.
 */
function foodify_split_name( string $full ): array {
	$full  = trim( preg_replace( '/\s+/', ' ', $full ) );
	if ( '' === $full ) {
		return [ '', '' ];
	}
	$parts = explode( ' ', $full );
	$first = array_shift( $parts );
	return [ $first, implode( ' ', $parts ) ];
}

add_action( 'woocommerce_checkout_create_order', static function ( WC_Order $order ): void {
	foreach ( [ 'billing', 'shipping' ] as $ctx ) {
		$getter = "get_{$ctx}_first_name";
		$setter_first = "set_{$ctx}_first_name";
		$setter_last  = "set_{$ctx}_last_name";
		[ $first, $last ] = foodify_split_name( (string) $order->{$getter}() );
		if ( '' !== $first ) {
			$order->{$setter_first}( $first );
			$order->{$setter_last}( $last );
		}
	}
}, 5 );

/** Same split when a customer edits an address in their account (WP-05 address book). */
add_action( 'woocommerce_customer_save_address', static function ( int $customer_id, string $load_address ): void {
	$full = (string) get_user_meta( $customer_id, $load_address . '_first_name', true );
	[ $first, $last ] = foodify_split_name( $full );
	if ( '' !== $first ) {
		update_user_meta( $customer_id, $load_address . '_first_name', $first );
		update_user_meta( $customer_id, $load_address . '_last_name', $last );
	}
}, 20, 2 );

/** Country is India, always. Hidden, not asked. */
add_filter( 'woocommerce_countries_allowed_countries', static fn(): array => [ 'IN' => __( 'India', 'foodify' ) ] );
add_filter( 'default_checkout_billing_country', static fn(): string => 'IN' );
add_filter( 'default_checkout_shipping_country', static fn(): string => 'IN' );

/** Reject obviously invalid Indian mobile numbers before payment is attempted. */
add_action( 'woocommerce_after_checkout_validation', static function ( array $data, WP_Error $errors ): void {
	$phone = preg_replace( '/\D/', '', (string) ( $data['billing_phone'] ?? '' ) );
	$phone = preg_replace( '/^(0|91)/', '', (string) $phone );

	if ( strlen( (string) $phone ) !== 10 || ! preg_match( '/^[6-9]/', (string) $phone ) ) {
		$errors->add( 'billing_phone', __( 'Enter a valid 10-digit Indian mobile number.', 'foodify' ) );
	}

	$pin = preg_replace( '/\D/', '', (string) ( $data['billing_postcode'] ?? '' ) );
	if ( strlen( (string) $pin ) !== 6 ) {
		$errors->add( 'billing_postcode', __( 'Enter a valid 6-digit PIN code.', 'foodify' ) );
	}
}, 10, 2 );

/**
 * PIN-code lookup. Fills city and state so the customer types two fewer fields.
 *
 * Answered by the site itself (inc/pincodes.php, India Post's directory bundled
 * in assets/data). It used to call api.postalpincode.in from the customer's
 * browser — a third party receiving customer PIN codes, with no SLA, on the
 * checkout page. The endpoint stays filterable; returning an empty string
 * disables the lookup and leaves manual entry.
 */
add_action( 'wp_enqueue_scripts', static function (): void {
	if ( ! function_exists( 'is_checkout' ) || ! ( is_checkout() || is_account_page() ) ) {
		return;
	}

	$endpoint = (string) apply_filters( 'foodify_pincode_endpoint', rest_url( 'foodify/v1/pin/' ) );
	if ( '' === $endpoint ) {
		return;
	}

	/**
	 * WooCommerce's India state names diverge from the API's on several states.
	 * Matching on display text alone silently fails for exactly these, leaving
	 * the state unset — which corrupts the GST split and the shipping zone.
	 * Keys are normalised API values; values are WooCommerce state CODES.
	 */
	$aliases = [
		'odisha'                      => 'OR',
		'orissa'                      => 'OR',
		'puducherry'                  => 'PY',
		'pondicherry'                 => 'PY',
		'uttarakhand'                 => 'UK',
		'uttaranchal'                 => 'UK',
		'telangana'                   => 'TS',
		'delhi'                       => 'DL',
		'nct of delhi'                => 'DL',
		'jammu and kashmir'           => 'JK',
		'dadra and nagar haveli'      => 'DN',
		'daman and diu'               => 'DD',
		'andaman and nicobar islands' => 'AN',
	];

	// The delivery date under the PIN field (review BUY-11): the product page's
	// rules and the product page's function (delivery-estimate.php), so the two
	// can never promise different dates. Checkout only — not the address book,
	// not the order-received page — and only while foodify_delivery_zones is set.
	$on_checkout = is_checkout() && ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() );
	$delivery    = ( $on_checkout && function_exists( 'foodify_delivery_config' ) ) ? foodify_delivery_config() : null;

	wp_register_script( 'foodify-pincode', false, [ 'jquery' ], FOODIFY_VERSION, true );
	wp_enqueue_script( 'foodify-pincode' );
	wp_add_inline_script(
		'foodify-pincode',
		'window.FOODIFY_PIN = ' . wp_json_encode(
			[
				'endpoint' => $endpoint,
				'aliases'  => $aliases,
				'delivery' => $delivery,
			]
		) . ';'
		. ( null !== $delivery && function_exists( 'foodify_delivery_script' ) ? "\n" . foodify_delivery_script() : '' ),
		'before'
	);
	// City only when empty, state always, "PIN not found — type your city",
	// and the date. Source and rules: foodify_checkout_pin_script() (checkout-flow.php).
	wp_add_inline_script( 'foodify-pincode', foodify_checkout_pin_script() );
} );
