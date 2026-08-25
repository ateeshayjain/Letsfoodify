<?php
/**
 * WP-05 — the multi-address book.
 *
 * WP-05's own framing: "the address book and reorder button are the point — not
 * the login itself." Acceptance: "A returning customer completes checkout with
 * zero address fields typed."
 *
 * WooCommerce stores exactly ONE billing address and ONE shipping address per
 * customer. A household that orders to home and to an office has to retype one
 * of them every time, which is most of the 25-field checkout the audit
 * complained about, arriving again on every repeat order.
 *
 * THE MODEL IS A SUPERSET, NOT A SUBSTITUTE
 * -----------------------------------------
 * The book lives in one user-meta key. The address flagged default is ALSO
 * mirrored into WooCommerce's own `shipping_*` / `billing_*` meta on every save.
 *
 * That mirroring is the whole safety argument. Everything downstream —
 * checkout prefill, order creation, the admin customer screen, the WP-11
 * courier payload, Razorpay, any plugin that calls get_user_meta() — keeps
 * reading the fields it has always read and sees the truth. If this file were
 * removed tomorrow the store would still work; customers would simply be back
 * to one address. A model that REPLACED WooCommerce's fields would look
 * identical in testing and break silently in every integration that never heard
 * of it.
 *
 * WHY THE PURE HALF EXISTS
 * ------------------------
 * The invariant that matters is "a non-empty book has exactly one default".
 * Zero defaults means checkout prefills nothing and the acceptance criterion
 * fails; two means the one it picks is arbitrary. Deleting the default is the
 * case that breaks it, and it is not a case anyone hits by hand while testing
 * happy paths. So the list arithmetic is pure functions over plain arrays,
 * tested in tests/address-test.php without WordPress, a database or a browser.
 *
 * Email is deliberately NOT part of an address. It belongs to the account, not
 * to a doorstep, and duplicating it per address creates two answers to "where do
 * we send the invoice".
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const FOODIFY_ADDRESS_META = '_foodify_address_book';
const FOODIFY_ADDRESS_MAX  = 10;

/** The fields an address holds. Mirrors checkout, minus email and country. */
function foodify_address_fields(): array {
	return [ 'label', 'first_name', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode' ];
}

/* -------------------------------------------------------------------------
 * Pure list arithmetic — no WordPress below this line until it says otherwise.
 * ---------------------------------------------------------------------- */

/**
 * Coerce arbitrary input into the record shape. Unknown keys are dropped.
 */
function foodify_address_normalise( array $raw ): array {
	$out = [];
	foreach ( foodify_address_fields() as $f ) {
		$v = $raw[ $f ] ?? '';
		$v = is_scalar( $v ) ? (string) $v : '';
		$out[ $f ] = trim( preg_replace( '/\s+/u', ' ', $v ) ?? '' );
	}
	$out['phone']    = preg_replace( '/\D/', '', $out['phone'] ) ?? '';
	$out['postcode'] = preg_replace( '/\D/', '', $out['postcode'] ) ?? '';
	$out['state']    = strtoupper( $out['state'] );
	$out['id']       = isset( $raw['id'] ) && is_string( $raw['id'] ) ? $raw['id'] : '';
	$out['is_default'] = ! empty( $raw['is_default'] );
	$out['updated']  = isset( $raw['updated'] ) && is_int( $raw['updated'] ) ? $raw['updated'] : 0;
	return $out;
}

/**
 * Field-name => message for everything wrong with it. Empty means valid.
 *
 * Same rules as checkout-fields.php enforces at checkout, applied here too:
 * an address that saves cleanly and then fails validation at the till is a
 * worse experience than one that refuses to save.
 */
function foodify_address_validate( array $a ): array {
	$e = [];
	if ( '' === $a['first_name'] ) {
		$e['first_name'] = 'Enter the name this delivery is for.';
	}
	if ( ! preg_match( '/^[6-9]\d{9}$/', $a['phone'] ) ) {
		$e['phone'] = 'Enter a valid 10-digit Indian mobile number.';
	}
	if ( '' === $a['address_1'] ) {
		$e['address_1'] = 'Enter the flat, house number or building.';
	}
	if ( '' === $a['city'] ) {
		$e['city'] = 'Enter the town or city.';
	}
	if ( '' === $a['state'] ) {
		$e['state'] = 'Choose a state.';
	}
	if ( ! preg_match( '/^[1-9]\d{5}$/', $a['postcode'] ) ) {
		$e['postcode'] = 'Enter a valid 6-digit PIN code.';
	}
	return $e;
}

/**
 * What makes two saved addresses "the same place".
 *
 * Without this, editing an address in the checkout chooser and saving it back
 * appends a near-identical second row, and the book fills with the same house
 * written slightly differently. Name and phone are excluded on purpose: the
 * same flat ordered for two different people is one address, not two.
 */
function foodify_address_fingerprint( array $a ): string {
	$parts = [ $a['address_1'], $a['address_2'], $a['city'], $a['postcode'] ];
	$flat  = strtolower( implode( '|', $parts ) );
	return preg_replace( '/[^a-z0-9|]/', '', $flat ) ?? '';
}

/** Stable per-record id. Random, not derived — an address may be edited freely. */
function foodify_address_new_id(): string {
	return 'a' . bin2hex( random_bytes( 6 ) );
}

/**
 * The one-line summary the chooser shows. Never the whole record.
 */
function foodify_address_summary( array $a ): string {
	$bits = array_filter( [ $a['address_1'], $a['address_2'], $a['city'], $a['postcode'] ], static fn( $s ): bool => '' !== $s );
	return implode( ', ', $bits );
}

/**
 * Enforce the invariant: a non-empty book has EXACTLY one default.
 *
 * Called after every mutation rather than trusted to each one. $prefer names an
 * id that should win if it is present and flagged.
 */
function foodify_address_enforce_default( array $book, string $prefer = '' ): array {
	if ( ! $book ) {
		return $book;
	}
	$winner = '';
	if ( '' !== $prefer ) {
		foreach ( $book as $a ) {
			if ( $a['id'] === $prefer ) {
				$winner = $prefer;
				break;
			}
		}
	}
	if ( '' === $winner ) {
		foreach ( $book as $a ) {
			if ( ! empty( $a['is_default'] ) ) {
				$winner = $a['id'];
				break;
			}
		}
	}
	if ( '' === $winner ) {
		// Nothing claims it. Promote the most recently touched, which is the
		// closest thing to "the one they actually use".
		$best = null;
		foreach ( $book as $a ) {
			if ( null === $best || $a['updated'] > $best['updated'] ) {
				$best = $a;
			}
		}
		$winner = $best['id'];
	}
	foreach ( $book as $i => $a ) {
		$book[ $i ]['is_default'] = ( $a['id'] === $winner );
	}
	return array_values( $book );
}

/** Default first, then most recently updated. The order the chooser renders. */
function foodify_address_sort( array $book ): array {
	usort( $book, static function ( array $x, array $y ): int {
		if ( ! empty( $x['is_default'] ) !== ! empty( $y['is_default'] ) ) {
			return ! empty( $x['is_default'] ) ? -1 : 1;
		}
		return $y['updated'] <=> $x['updated'];
	} );
	return array_values( $book );
}

/**
 * Insert or update one address.
 *
 * @return array{book:array,errors:array,id:string}
 */
function foodify_address_upsert( array $book, array $raw, int $now, string $edit_id = '' ): array {
	$a = foodify_address_normalise( $raw );
	$errors = foodify_address_validate( $a );
	if ( $errors ) {
		return [ 'book' => $book, 'errors' => $errors, 'id' => '' ];
	}
	$a['updated'] = $now;

	$target = -1;
	if ( '' !== $edit_id ) {
		foreach ( $book as $i => $existing ) {
			if ( $existing['id'] === $edit_id ) {
				$target = $i;
				break;
			}
		}
		if ( -1 === $target ) {
			// Editing something that is not in this book. Refuse rather than
			// silently creating it — an unknown id is a bug or a tampered form.
			return [ 'book' => $book, 'errors' => [ 'id' => 'That address is no longer saved.' ], 'id' => '' ];
		}
	} else {
		$fp = foodify_address_fingerprint( $a );
		foreach ( $book as $i => $existing ) {
			if ( foodify_address_fingerprint( $existing ) === $fp ) {
				$target = $i;   // same place, written again — update, do not duplicate
				break;
			}
		}
	}

	if ( -1 === $target && count( $book ) >= FOODIFY_ADDRESS_MAX ) {
		return [ 'book' => $book, 'errors' => [ 'id' => 'You have reached the maximum of ' . FOODIFY_ADDRESS_MAX . ' saved addresses. Delete one first.' ], 'id' => '' ];
	}

	if ( -1 === $target ) {
		$a['id'] = foodify_address_new_id();
		// The first address saved is the default whether or not it was ticked;
		// otherwise a book of one has no default and checkout prefills nothing.
		if ( ! $book ) {
			$a['is_default'] = true;
		}
		$book[] = $a;
	} else {
		$a['id'] = $book[ $target ]['id'];
		// Never let an edit silently REMOVE the default flag from the only
		// address that has it — unticking is done by defaulting another.
		if ( ! $a['is_default'] && ! empty( $book[ $target ]['is_default'] ) ) {
			$a['is_default'] = true;
		}
		$book[ $target ] = $a;
	}

	$book = foodify_address_enforce_default( $book, $a['is_default'] ? $a['id'] : '' );
	return [ 'book' => foodify_address_sort( $book ), 'errors' => [], 'id' => $a['id'] ];
}

/**
 * Remove one address.
 *
 * Deleting the default is the case the invariant exists for: without the
 * re-enforce below, the book keeps working and checkout quietly stops
 * prefilling, which nobody notices until a customer types an address again.
 */
function foodify_address_delete( array $book, string $id ): array {
	$book = array_values( array_filter( $book, static fn( array $a ): bool => $a['id'] !== $id ) );
	return foodify_address_sort( foodify_address_enforce_default( $book ) );
}

/** Make one address the default. Unknown id leaves the book untouched. */
function foodify_address_set_default( array $book, string $id ): array {
	$known = false;
	foreach ( $book as $a ) {
		if ( $a['id'] === $id ) {
			$known = true;
			break;
		}
	}
	if ( ! $known ) {
		return foodify_address_sort( $book );
	}
	return foodify_address_sort( foodify_address_enforce_default( $book, $id ) );
}

/** The address checkout should prefill, or null. */
function foodify_address_default( array $book ): ?array {
	foreach ( $book as $a ) {
		if ( ! empty( $a['is_default'] ) ) {
			return $a;
		}
	}
	return null;
}

/** Find by id, or null. */
function foodify_address_find( array $book, string $id ): ?array {
	foreach ( $book as $a ) {
		if ( $a['id'] === $id ) {
			return $a;
		}
	}
	return null;
}

/**
 * Seed a book from WooCommerce's single stored address.
 *
 * Every existing customer already has one. Without this they open a brand-new
 * "address book" that is empty and conclude the site lost their address.
 */
function foodify_address_seed_from_wc( array $wc, int $now ): array {
	$a = foodify_address_normalise( $wc );
	if ( foodify_address_validate( $a ) ) {
		return [];   // incomplete legacy data: show an empty book, not a broken row
	}
	$a['id']         = foodify_address_new_id();
	$a['is_default'] = true;
	$a['updated']    = $now;
	$a['label']      = '' !== $a['label'] ? $a['label'] : 'Saved address';
	return [ $a ];
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness; the pure half is all it needs
}

/** Read the book, seeding it from WooCommerce meta the first time. */
function foodify_get_address_book( int $user_id ): array {
	$book = get_user_meta( $user_id, FOODIFY_ADDRESS_META, true );
	if ( is_array( $book ) && $book ) {
		return foodify_address_sort( array_map( static function ( $a ): array {
			$n = foodify_address_normalise( is_array( $a ) ? $a : [] );
			return $n;
		}, $book ) );
	}
	if ( is_array( $book ) ) {
		return [];   // an explicitly emptied book stays empty; do not re-seed it
	}

	$seed = foodify_address_seed_from_wc( [
		'first_name' => get_user_meta( $user_id, 'shipping_first_name', true ) ?: get_user_meta( $user_id, 'billing_first_name', true ),
		'phone'      => get_user_meta( $user_id, 'billing_phone', true ),
		'address_1'  => get_user_meta( $user_id, 'shipping_address_1', true ) ?: get_user_meta( $user_id, 'billing_address_1', true ),
		'address_2'  => get_user_meta( $user_id, 'shipping_address_2', true ) ?: get_user_meta( $user_id, 'billing_address_2', true ),
		'city'       => get_user_meta( $user_id, 'shipping_city', true ) ?: get_user_meta( $user_id, 'billing_city', true ),
		'state'      => get_user_meta( $user_id, 'shipping_state', true ) ?: get_user_meta( $user_id, 'billing_state', true ),
		'postcode'   => get_user_meta( $user_id, 'shipping_postcode', true ) ?: get_user_meta( $user_id, 'billing_postcode', true ),
	], time() );

	if ( $seed ) {
		update_user_meta( $user_id, FOODIFY_ADDRESS_META, $seed );
	}
	return $seed;
}

/**
 * Persist the book AND mirror the default into WooCommerce's own fields.
 *
 * The mirror is not a convenience. It is what keeps every integration that
 * reads billing_/shipping_ meta correct without knowing this module exists.
 */
function foodify_save_address_book( int $user_id, array $book ): void {
	update_user_meta( $user_id, FOODIFY_ADDRESS_META, $book );

	$default = foodify_address_default( $book );
	if ( ! $default ) {
		return;
	}
	$map = [
		'first_name' => $default['first_name'],
		'address_1'  => $default['address_1'],
		'address_2'  => $default['address_2'],
		'city'       => $default['city'],
		'state'      => $default['state'],
		'postcode'   => $default['postcode'],
		'country'    => 'IN',
	];
	foreach ( $map as $field => $value ) {
		update_user_meta( $user_id, 'shipping_' . $field, $value );
		update_user_meta( $user_id, 'billing_' . $field, $value );
	}
	update_user_meta( $user_id, 'billing_phone', $default['phone'] );
}

/**
 * The account endpoint.
 *
 * A new endpoint needs rewrite rules, which is exactly the kind of thing that
 * works on the machine it was written on and 404s on the client's site. Flushed
 * on theme switch, and scripts/bootstrap.sh runs `wp rewrite flush` as well —
 * belt and braces, because the failure is a dead link in the account menu.
 */
add_action( 'init', static function (): void {
	add_rewrite_endpoint( 'address-book', EP_ROOT | EP_PAGES );
} );

add_action( 'after_switch_theme', static function (): void {
	add_rewrite_endpoint( 'address-book', EP_ROOT | EP_PAGES );
	flush_rewrite_rules();
} );

add_filter( 'woocommerce_get_query_vars', static function ( array $vars ): array {
	$vars['address-book'] = 'address-book';
	return $vars;
} );

/** Replace WooCommerce's single-address tab with the book. */
add_filter( 'woocommerce_account_menu_items', static function ( array $items ): array {
	$out = [];
	foreach ( $items as $key => $label ) {
		if ( 'edit-address' === $key ) {
			$out['address-book'] = __( 'Saved addresses', 'foodify' );
			continue;
		}
		$out[ $key ] = $label;
	}
	if ( ! isset( $out['address-book'] ) ) {
		$out['address-book'] = __( 'Saved addresses', 'foodify' );
	}
	return $out;
}, 20 );

/* ---- mutations ---------------------------------------------------------- */

/**
 * One handler for all three verbs.
 *
 * Every branch requires a logged-in user AND a valid nonce before it touches
 * anything. A delete link without a nonce is a CSRF hole: a link in an email
 * that quietly removes somebody's saved address.
 *
 * POST-redirect-GET at the end so a refresh cannot repeat the action.
 */
add_action( 'template_redirect', static function (): void {
	if ( empty( $_POST['foodify_address_action'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}
	$action = sanitize_key( wp_unslash( $_POST['foodify_address_action'] ) );
	$nonce  = isset( $_POST['foodify_address_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['foodify_address_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'foodify_address_' . $action ) ) {
		wc_add_notice( __( 'That request expired. Please try again.', 'foodify' ), 'error' );
		return;
	}

	$user_id = get_current_user_id();
	$book    = foodify_get_address_book( $user_id );
	$id      = isset( $_POST['address_id'] ) ? sanitize_text_field( wp_unslash( $_POST['address_id'] ) ) : '';

	// An id is only ever looked up inside THIS user's book, so an id belonging
	// to somebody else simply does not resolve.
	if ( '' !== $id && ! foodify_address_find( $book, $id ) && 'save' !== $action ) {
		wc_add_notice( __( 'That address is no longer saved.', 'foodify' ), 'error' );
		return;
	}

	switch ( $action ) {
		case 'save':
			$raw = [];
			foreach ( foodify_address_fields() as $f ) {
				$raw[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
			}
			$raw['is_default'] = ! empty( $_POST['is_default'] );
			$result = foodify_address_upsert( $book, $raw, time(), $id );
			if ( $result['errors'] ) {
				foreach ( $result['errors'] as $message ) {
					wc_add_notice( esc_html( $message ), 'error' );
				}
				return;   // stay on the form so the typing is not lost
			}
			foodify_save_address_book( $user_id, $result['book'] );
			wc_add_notice( __( 'Address saved.', 'foodify' ), 'success' );
			break;

		case 'delete':
			foodify_save_address_book( $user_id, foodify_address_delete( $book, $id ) );
			wc_add_notice( __( 'Address removed.', 'foodify' ), 'success' );
			break;

		case 'default':
			foodify_save_address_book( $user_id, foodify_address_set_default( $book, $id ) );
			wc_add_notice( __( 'Default address updated.', 'foodify' ), 'success' );
			break;
	}

	wp_safe_redirect( remove_query_arg( 'edit', wp_get_referer() ?: wc_get_account_endpoint_url( 'address-book' ) ) );
	exit;
} );

/* ---- the account screen ------------------------------------------------- */

add_action( 'woocommerce_account_address-book_endpoint', static function (): void {
	$user_id = get_current_user_id();
	$book    = foodify_get_address_book( $user_id );
	$edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
	$editing = '' !== $edit_id ? foodify_address_find( $book, $edit_id ) : null;

	echo '<p class="fd-account-lead">';
	esc_html_e( 'Save the places you order to. Checkout fills in your default address on its own — you only choose when it is going somewhere else.', 'foodify' );
	echo '</p>';

	if ( $book ) {
		echo '<ul class="fd-address-list">';
		foreach ( $book as $a ) {
			$is_default = ! empty( $a['is_default'] );
			printf(
				'<li class="fd-address%1$s"><div class="fd-address__head"><span class="fd-address__label">%2$s</span>%3$s</div>'
				. '<p class="fd-address__body">%4$s<br>%5$s</p><div class="fd-address__actions">',
				$is_default ? ' is-default' : '',
				esc_html( '' !== $a['label'] ? $a['label'] : __( 'Address', 'foodify' ) ),
				$is_default ? '<span class="fd-address__default">' . esc_html__( 'Default', 'foodify' ) . '</span>' : '',
				esc_html( $a['first_name'] . ' · ' . $a['phone'] ),
				esc_html( foodify_address_summary( $a ) . ' ' . $a['state'] )
			);

			printf(
				'<a class="fd-secondary" href="%s">%s</a>',
				esc_url( add_query_arg( 'edit', $a['id'], wc_get_account_endpoint_url( 'address-book' ) ) ),
				esc_html__( 'Edit', 'foodify' )
			);

			if ( ! $is_default ) {
				foodify_address_verb_form( 'default', $a['id'], __( 'Make default', 'foodify' ), 'fd-secondary' );
				foodify_address_verb_form( 'delete', $a['id'], __( 'Delete', 'foodify' ), 'fd-danger', __( 'Delete this saved address?', 'foodify' ) );
			}
			echo '</div></li>';
		}
		echo '</ul>';
		if ( 1 === count( $book ) ) {
			echo '<p class="fd-address-note">' . esc_html__( 'Your only saved address cannot be deleted — add another first, or edit this one.', 'foodify' ) . '</p>';
		}
	}

	foodify_address_form( $editing );
} );

/** A one-button POST form. Used for verbs so they carry a nonce; a link cannot. */
function foodify_address_verb_form( string $action, string $id, string $label, string $class = '', string $confirm = '' ): void {
	printf(
		'<form method="post" class="fd-address__verb">%1$s<input type="hidden" name="foodify_address_action" value="%2$s">'
		. '<input type="hidden" name="address_id" value="%3$s">'
		. '<button type="submit" class="%4$s"%5$s>%6$s</button></form>',
		wp_nonce_field( 'foodify_address_' . $action, 'foodify_address_nonce', true, false ),
		esc_attr( $action ),
		esc_attr( $id ),
		esc_attr( $class ),
		'' !== $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '',
		esc_html( $label )
	);
}

/** Add / edit form. */
function foodify_address_form( ?array $a ): void {
	$a = $a ?? foodify_address_normalise( [] );
	$editing = '' !== $a['id'];

	echo '<h3 class="fd-address-form__title">' . esc_html( $editing ? __( 'Edit address', 'foodify' ) : __( 'Add an address', 'foodify' ) ) . '</h3>';
	echo '<form method="post" class="fd-address-form woocommerce-address-fields">';
	wp_nonce_field( 'foodify_address_save', 'foodify_address_nonce' );
	echo '<input type="hidden" name="foodify_address_action" value="save">';
	printf( '<input type="hidden" name="address_id" value="%s">', esc_attr( $a['id'] ) );

	$text = static function ( string $name, string $label, string $value, bool $required, string $placeholder = '', string $autocomplete = '' ): void {
		printf(
			'<p class="form-row form-row-wide"><label for="fd-%1$s">%2$s%3$s</label>'
			. '<input type="text" class="input-text" name="%1$s" id="fd-%1$s" value="%4$s" placeholder="%5$s" autocomplete="%6$s"%7$s></p>',
			esc_attr( $name ),
			esc_html( $label ),
			$required ? ' <abbr class="required" title="required">*</abbr>' : '',
			esc_attr( $value ),
			esc_attr( $placeholder ),
			esc_attr( $autocomplete ),
			$required ? ' required' : ''
		);
	};

	$text( 'label', __( 'Name this address', 'foodify' ), $a['label'], false, __( 'Home, Office…', 'foodify' ) );
	$text( 'first_name', __( 'Full name', 'foodify' ), $a['first_name'], true, __( 'Priya Sharma', 'foodify' ), 'name' );
	$text( 'phone', __( 'Mobile number', 'foodify' ), $a['phone'], true, '', 'tel' );
	$text( 'address_1', __( 'Address', 'foodify' ), $a['address_1'], true, __( 'Flat, house number, building', 'foodify' ), 'address-line1' );
	$text( 'address_2', __( 'Area, street, landmark', 'foodify' ), $a['address_2'], false, __( 'Optional', 'foodify' ), 'address-line2' );
	$text( 'postcode', __( 'PIN code', 'foodify' ), $a['postcode'], true, '', 'postal-code' );
	$text( 'city', __( 'Town / City', 'foodify' ), $a['city'], true, '', 'address-level2' );

	echo '<p class="form-row form-row-wide"><label for="fd-state">' . esc_html__( 'State', 'foodify' ) . ' <abbr class="required" title="required">*</abbr></label>';
	echo '<select name="state" id="fd-state" class="input-text" required>';
	$states = function_exists( 'WC' ) ? WC()->countries->get_states( 'IN' ) : [];
	echo '<option value="">' . esc_html__( 'Choose a state', 'foodify' ) . '</option>';
	foreach ( (array) $states as $code => $name ) {
		printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $code ), selected( $code, $a['state'], false ), esc_html( $name ) );
	}
	echo '</select></p>';

	printf(
		'<p class="form-row form-row-wide"><label><input type="checkbox" name="is_default" value="1"%s> %s</label></p>',
		checked( true, (bool) $a['is_default'], false ),
		esc_html__( 'Use this address by default at checkout', 'foodify' )
	);

	printf( '<p><button type="submit" class="wp-element-button">%s</button></p>', esc_html( $editing ? __( 'Save changes', 'foodify' ) : __( 'Save address', 'foodify' ) ) );
	echo '</form>';
}

/* ---- checkout ----------------------------------------------------------- */

/**
 * Prefill from the default address SERVER-SIDE.
 *
 * This is the acceptance criterion — "zero address fields typed" — and doing it
 * here rather than in JavaScript means it holds with scripts blocked, on a slow
 * connection, and before any of the chooser's code has run.
 *
 * WooCommerce's own prefill reads billing_/shipping_ meta, which
 * foodify_save_address_book() mirrors, so this filter is mostly belt and
 * braces; it matters for the chooser's "deliver somewhere else" selection.
 */
add_filter( 'woocommerce_checkout_get_value', static function ( $value, string $input ) {
	if ( ! is_user_logged_in() ) {
		return $value;
	}
	$chosen = WC()->session ? WC()->session->get( 'foodify_chosen_address' ) : '';
	if ( ! is_string( $chosen ) || '' === $chosen ) {
		return $value;
	}
	$a = foodify_address_find( foodify_get_address_book( get_current_user_id() ), $chosen );
	if ( ! $a ) {
		return $value;
	}
	$field = preg_replace( '/^(billing|shipping)_/', '', $input );
	if ( 'phone' === $field ) {
		return $a['phone'];
	}
	if ( in_array( $field, [ 'first_name', 'address_1', 'address_2', 'city', 'state', 'postcode' ], true ) ) {
		return $a[ $field ];
	}
	return $value;
}, 10, 2 );

/** Remember the chosen address for this cart. */
add_action( 'template_redirect', static function (): void {
	if ( empty( $_POST['foodify_choose_address'] ) || ! is_user_logged_in() ) {
		return;
	}
	$nonce = isset( $_POST['foodify_address_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['foodify_address_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'foodify_address_choose' ) ) {
		return;
	}
	$id = sanitize_text_field( wp_unslash( $_POST['foodify_choose_address'] ) );
	if ( foodify_address_find( foodify_get_address_book( get_current_user_id() ), $id ) && WC()->session ) {
		WC()->session->set( 'foodify_chosen_address', $id );
	}
	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}, 5 );

/**
 * The chooser, above the checkout form.
 *
 * Only rendered when there is a real choice to make. One saved address is
 * already in the fields; showing a "choose your address" control with a single
 * option is noise on the screen that most needs none.
 */
add_action( 'woocommerce_before_checkout_form', static function (): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$book = foodify_get_address_book( get_current_user_id() );
	if ( count( $book ) < 2 ) {
		return;
	}
	$chosen = WC()->session ? (string) WC()->session->get( 'foodify_chosen_address' ) : '';
	if ( '' === $chosen ) {
		$default = foodify_address_default( $book );
		$chosen  = $default ? $default['id'] : '';
	}

	echo '<form method="post" class="fd-address-choose"><fieldset>';
	echo '<legend>' . esc_html__( 'Deliver to', 'foodify' ) . '</legend>';
	wp_nonce_field( 'foodify_address_choose', 'foodify_address_nonce' );
	foreach ( $book as $a ) {
		printf(
			'<label class="fd-address-choose__option"><input type="radio" name="foodify_choose_address" value="%1$s"%2$s onchange="this.form.submit()">'
			. '<span class="fd-address-choose__label">%3$s</span><span class="fd-address-choose__body">%4$s</span></label>',
			esc_attr( $a['id'] ),
			checked( $a['id'], $chosen, false ),
			esc_html( '' !== $a['label'] ? $a['label'] : $a['first_name'] ),
			esc_html( foodify_address_summary( $a ) )
		);
	}
	// Works without JavaScript too — the onchange above is the convenience, not
	// the mechanism.
	printf( '<noscript><button type="submit" class="wp-element-button">%s</button></noscript>', esc_html__( 'Use this address', 'foodify' ) );
	printf(
		'<a class="fd-address-choose__manage" href="%s">%s</a>',
		esc_url( wc_get_account_endpoint_url( 'address-book' ) ),
		esc_html__( 'Manage saved addresses', 'foodify' )
	);
	echo '</fieldset></form>';
} );

/**
 * A completed order teaches the book.
 *
 * Someone who checked out and typed a new address should not have to save it
 * again by hand — and the next order is the one that proves the address book
 * works. Upsert dedups by fingerprint, so an order to an address already saved
 * changes nothing but its timestamp.
 */
add_action( 'woocommerce_checkout_order_processed', static function ( int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! $order->get_customer_id() ) {
		return;
	}
	$user_id = $order->get_customer_id();
	$book    = foodify_get_address_book( $user_id );
	$raw     = [
		'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
		'phone'      => $order->get_billing_phone(),
		'address_1'  => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
		'address_2'  => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
		'city'       => $order->get_shipping_city() ?: $order->get_billing_city(),
		'state'      => $order->get_shipping_state() ?: $order->get_billing_state(),
		'postcode'   => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
		'label'      => '',
	];
	$result = foodify_address_upsert( $book, $raw, time() );
	if ( ! $result['errors'] ) {
		// Persist the book, but do NOT re-mirror: the order already carries the
		// address, and promoting a one-off delivery to the account default is
		// the wrong answer for a gift sent to somebody else.
		update_user_meta( $user_id, FOODIFY_ADDRESS_META, $result['book'] );
	}
	if ( WC()->session ) {
		WC()->session->set( 'foodify_chosen_address', '' );
	}
} );
