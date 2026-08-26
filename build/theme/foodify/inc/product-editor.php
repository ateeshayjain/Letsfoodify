<?php
/**
 * WP-12 — one screen where all the per-product data gets entered, once.
 *
 * Three packages accumulated per-product fields with no way to type them in:
 * the PDP design pass (ingredients, allergens, nutrition, best-before), WP-11
 * (HSN code, GST rate — and weight, which is WooCommerce's own field), and the
 * prep steps (minutes). Each deferred the editor HERE, to the content load, so
 * the client enters everything in one sitting instead of discovering a new
 * empty field per package.
 *
 * THE SANITISERS REFUSE RATHER THAN COERCE
 * ----------------------------------------
 * A GST rate is a tax figure. "5%" typed into a numeric field, coerced by
 * (float) to 5.0, happens to be right — but "abc" coerces to 0.0, and 0% is a
 * REAL GST RATE (unbranded staples are 0-rated), so a typo silently becomes a
 * tax position. Same for HSN: a five-digit code is a typo, not a code. Every
 * sanitiser here returns null for input it cannot vouch for, and null means
 * the field stays EMPTY — which the PDP renders as "Not provided" and the
 * invoice title logic treats as not-invoiceable. Wrong data is worse than
 * missing data everywhere in this build; this is where that rule meets the
 * keyboard.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/wp12-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/** GST rate: a real Indian rate or null. Never coerced. */
function foodify_sanitize_gst_rate( string $raw ): ?float {
	$raw = trim( str_replace( '%', '', $raw ) );
	if ( '' === $raw || ! is_numeric( $raw ) ) {
		return null;
	}
	$rate = (float) $raw;
	// The GST schedule's actual slabs. A rate outside them is a typo — 50 for 5,
	// 1.8 for 18 — and accepting it books a tax position from a slipped key.
	// 0 is REAL (unbranded staples), which is exactly why "" must not become 0.
	return in_array( $rate, [ 0.0, 0.25, 3.0, 5.0, 12.0, 18.0, 28.0 ], true ) ? $rate : null;
}

/** HSN: 4, 6 or 8 digits — the lengths the schedule actually uses. */
function foodify_sanitize_hsn( string $raw ): ?string {
	$digits = preg_replace( '/\D/', '', $raw ) ?? '';
	return in_array( strlen( $digits ), [ 4, 6, 8 ], true ) ? $digits : null;
}

/** A nutrition value: a number with its unit, or null. "312 kcal", "14 g". */
function foodify_sanitize_nutrition( string $raw ): ?string {
	$raw = trim( preg_replace( '/\s+/', ' ', $raw ) ?? '' );
	if ( '' === $raw ) {
		return null;
	}
	// Number first, unit after — the shape a pack prints. Anything else is
	// refused so half a panel of prose never renders as data.
	return preg_match( '/^\d+(?:\.\d+)?\s?(?:kcal|kj|g|mg|mcg|µg)$/i', $raw ) ? $raw : null;
}

/** A best-before that is a real date, returned as the pack prints it. */
function foodify_sanitize_best_before( string $raw ): ?string {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return null;
	}
	$t = strtotime( $raw );
	if ( false === $t ) {
		return null;
	}
	// A best-before in the past is not a typo to fix silently — but it IS
	// storable: old stock exists, and refusing it here would hide it from the
	// shelf-life report below, which is the thing that must see it.
	return gmdate( 'j M Y', $t );
}

/**
 * The shelf-life-at-delivery rule (scope §8): food delivered online must carry
 * meaningful remaining life — commonly cited as 30% remaining or 45 days,
 * whichever is less. VERIFY THE CURRENT THRESHOLD WITH THE CLIENT'S CONSULTANT;
 * the rule has been revised, which is why both numbers are parameters.
 *
 * @return array{sellable:bool,reason:string,days_left:int}
 */
function foodify_shelf_life_state( string $best_before, int $shelf_life_days, int $now, float $fraction = 0.30, int $cap_days = 45 ): array {
	$bb = strtotime( $best_before . ' 23:59:59 UTC' );
	if ( false === $bb || $shelf_life_days <= 0 ) {
		return [ 'sellable' => false, 'reason' => 'unknown', 'days_left' => 0 ];
	}
	$days_left = (int) floor( ( $bb - $now ) / DAY_IN_SECONDS );
	if ( $days_left <= 0 ) {
		return [ 'sellable' => false, 'reason' => 'expired', 'days_left' => $days_left ];
	}
	// "Whichever is less" — the LOWER bar, per the commonly cited reading.
	$required = (int) min( ceil( $shelf_life_days * $fraction ), $cap_days );
	if ( $days_left < $required ) {
		return [ 'sellable' => false, 'reason' => 'too_little_life', 'days_left' => $days_left ];
	}
	return [ 'sellable' => true, 'reason' => '', 'days_left' => $days_left ];
}

/** The fields the metabox renders, keyed by meta suffix. One list, one loop. */
function foodify_editor_fields(): array {
	return [
		'ingredients'  => [ 'label' => 'Ingredients',            'type' => 'textarea' ],
		'allergens'    => [ 'label' => 'Allergens',              'type' => 'text', 'hint' => 'e.g. Milk (ghee). Leave empty ONLY if genuinely none is declarable — the page will say "Not provided".' ],
		'net_quantity' => [ 'label' => 'Net quantity',           'type' => 'text', 'hint' => 'e.g. 80 g' ],
		'servings'     => [ 'label' => 'Servings per pack',      'type' => 'text' ],
		'diet'         => [ 'label' => 'Veg / non-veg',          'type' => 'select', 'options' => [ '' => '—', 'Vegetarian' => 'Vegetarian', 'Non-vegetarian' => 'Non-vegetarian', 'Vegan' => 'Vegan' ] ],
		'storage'      => [ 'label' => 'Storage',                'type' => 'text' ],
		'best_before'  => [ 'label' => 'Best before',            'type' => 'text', 'hint' => 'Any date format; stored as "14 Aug 2027". Refused if not a date.' ],
		'shelf_life'   => [ 'label' => 'Shelf life',             'type' => 'text', 'hint' => 'e.g. 12 months' ],
		'prep_minutes' => [ 'label' => 'Prep time',              'type' => 'text', 'hint' => 'e.g. 6 minutes — used by "How you make it"' ],
		'hsn'          => [ 'label' => 'HSN code',               'type' => 'text', 'hint' => '4, 6 or 8 digits, from your CA. Anything else is refused.' ],
		'gst_rate'     => [ 'label' => 'GST rate %',             'type' => 'text', 'hint' => '0, 0.25, 3, 5, 12, 18 or 28 — from your CA. Anything else is refused. 0 is a real rate; empty means "not set".' ],
		'nutrition_energy'  => [ 'label' => 'Energy / serving',  'type' => 'text', 'hint' => 'e.g. 312 kcal' ],
		'nutrition_protein' => [ 'label' => 'Protein / serving', 'type' => 'text', 'hint' => 'e.g. 14 g' ],
		'nutrition_carbs'   => [ 'label' => 'Carbohydrate',      'type' => 'text' ],
		'nutrition_sugars'  => [ 'label' => 'of which sugars',   'type' => 'text' ],
		'nutrition_fat'     => [ 'label' => 'Fat / serving',     'type' => 'text' ],
		'nutrition_sodium'  => [ 'label' => 'Sodium / serving',  'type' => 'text', 'hint' => 'e.g. 620 mg' ],
	];
}

/**
 * Sanitise one submitted field. Returns the value to store, or null to clear.
 * Split out so the refusal rules are testable without a $_POST.
 */
function foodify_editor_sanitise( string $key, string $raw ): ?string {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return null;
	}
	if ( 'gst_rate' === $key ) {
		$r = foodify_sanitize_gst_rate( $raw );
		return null === $r ? null : rtrim( rtrim( number_format( $r, 2, '.', '' ), '0' ), '.' );
	}
	if ( 'hsn' === $key ) {
		return foodify_sanitize_hsn( $raw );
	}
	if ( 'best_before' === $key ) {
		return foodify_sanitize_best_before( $raw );
	}
	if ( str_starts_with( $key, 'nutrition_' ) ) {
		return foodify_sanitize_nutrition( $raw );
	}
	return sanitize_textarea_field( $raw );
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

add_action( 'add_meta_boxes', static function (): void {
	add_meta_box(
		'foodify-product-data',
		__( 'Foodify — pack, tax & nutrition', 'foodify' ),
		'foodify_render_product_editor',
		'product',
		'normal',
		'high'
	);
} );

function foodify_render_product_editor( WP_Post $post ): void {
	wp_nonce_field( 'foodify_product_data_' . $post->ID, 'foodify_product_nonce' );

	echo '<p style="color:#646970">' . esc_html__( 'These fields render on the product page, feed the Google Shopping listing, and decide the GST split. A required field left empty shows "Not provided" on the page — visible, on purpose. Weight lives in Shipping on the right; the courier manifest refuses to dispatch without it.', 'foodify' ) . '</p>';
	echo '<table class="form-table"><tbody>';

	foreach ( foodify_editor_fields() as $key => $field ) {
		$value = (string) get_post_meta( $post->ID, '_foodify_' . $key, true );
		$id    = 'foodify_' . $key;

		printf( '<tr><th scope="row"><label for="%s">%s</label></th><td>', esc_attr( $id ), esc_html( $field['label'] ) );
		if ( 'textarea' === $field['type'] ) {
			printf( '<textarea name="%1$s" id="%1$s" rows="3" class="large-text">%2$s</textarea>', esc_attr( $id ), esc_textarea( $value ) );
		} elseif ( 'select' === $field['type'] ) {
			printf( '<select name="%1$s" id="%1$s">', esc_attr( $id ) );
			foreach ( $field['options'] as $opt => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
			}
			echo '</select>';
		} else {
			printf( '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text">', esc_attr( $id ), esc_attr( $value ) );
		}
		if ( ! empty( $field['hint'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['hint'] ) );
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

add_action( 'save_post_product', static function ( int $post_id ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	$nonce = isset( $_POST['foodify_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['foodify_product_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'foodify_product_data_' . $post_id ) ) {
		return;
	}
	// edit_products, which Shop Staff deliberately do not hold (WP-10) — the
	// person who sets a GST rate is the person who may set a price.
	if ( ! current_user_can( 'edit_product', $post_id ) ) {
		return;
	}

	$refused = [];
	foreach ( array_keys( foodify_editor_fields() ) as $key ) {
		$id = 'foodify_' . $key;
		if ( ! isset( $_POST[ $id ] ) ) {
			continue;
		}
		$raw   = (string) wp_unslash( $_POST[ $id ] );
		$clean = foodify_editor_sanitise( $key, $raw );
		if ( null === $clean ) {
			delete_post_meta( $post_id, '_foodify_' . $key );
			if ( '' !== trim( $raw ) ) {
				$refused[] = $key;   // typed something, stored nothing — say so
			}
		} else {
			update_post_meta( $post_id, '_foodify_' . $key, $clean );
		}
	}

	if ( $refused ) {
		// Refusing silently would look like data loss. The transient rides to the
		// next screen the editor sees.
		set_transient( 'foodify_refused_' . get_current_user_id(), $refused, 60 );
	}
} );

add_action( 'admin_notices', static function (): void {
	$refused = get_transient( 'foodify_refused_' . get_current_user_id() );
	if ( ! is_array( $refused ) || ! $refused ) {
		return;
	}
	delete_transient( 'foodify_refused_' . get_current_user_id() );
	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <code>%3$s</code>. %4$s</p></div>',
		esc_html__( 'Foodify:', 'foodify' ),
		esc_html__( 'these fields were REFUSED and stored as empty:', 'foodify' ),
		esc_html( implode( ', ', $refused ) ),
		esc_html__( 'A value that cannot be vouched for is not stored — a typo\'d GST rate is a tax position, not a display bug. Check the field hints and re-enter.', 'foodify' )
	);
} );

/**
 * A completeness column on the products list, so "which of the 44 still need
 * data" is a glance, not a spreadsheet.
 */
add_filter( 'manage_edit-product_columns', static function ( array $cols ): array {
	$cols['foodify_complete'] = __( 'Foodify data', 'foodify' );
	return $cols;
} );

add_action( 'manage_product_posts_custom_column', static function ( string $col, int $post_id ): void {
	if ( 'foodify_complete' !== $col || ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	$product = wc_get_product( $post_id );
	if ( ! $product ) {
		return;
	}
	$gaps = function_exists( 'foodify_spec_missing' ) && function_exists( 'foodify_product_spec_values' )
		? foodify_spec_missing( foodify_product_spec_values( $product ) )
		: [];
	if ( '' === (string) $product->get_weight() ) {
		$gaps[] = 'weight';
	}
	if ( null === foodify_product_gst_rate( $product ) ) {
		$gaps[] = 'gst_rate';
	}
	if ( $gaps ) {
		printf( '<span style="color:#996800">%s</span>', esc_html( sprintf(
			/* translators: %d: number of missing fields */
			_n( '%d field missing', '%d fields missing', count( $gaps ), 'foodify' ),
			count( $gaps )
		) ) );
	} else {
		printf( '<span style="color:#00753e">%s</span>', esc_html__( 'Complete', 'foodify' ) );
	}
}, 10, 2 );
