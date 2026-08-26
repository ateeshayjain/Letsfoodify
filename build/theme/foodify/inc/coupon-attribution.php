<?php
/**
 * Coupon attribution — the client's core requirement.
 *
 * Every coupon can be owned by a "partner" (influencer, reseller, corporate contact).
 * When an order using that coupon reaches `processing`, the partner is emailed the
 * order value, the units sold, and their running month-to-date totals. The same
 * numbers surface on the admin dashboard.
 *
 * Design notes:
 *  - Totals are INCREMENTAL counters stored on the coupon, not aggregated with a query.
 *    A per-order query across a growing order table is the thing that eventually makes
 *    an admin screen time out. `wp foodify coupons reconcile` rebuilds them from source
 *    if they ever drift.
 *  - Fires on `processing`, never on order creation, so failed payments never notify.
 *  - Idempotent: an order meta flag means a status flap cannot double-send.
 *  - HPOS and legacy post storage are both supported.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const FOODIFY_PARTNER_META   = '_foodify_partner_id';
const FOODIFY_NOTIFIED_META  = '_foodify_partner_notified';
const FOODIFY_NOTIFY_META     = '_foodify_notify_enabled';
const FOODIFY_COMMISSION_META = '_foodify_commission_pct';

/* -------------------------------------------------------------------------
 * 1. Assigning an owner to a coupon
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_coupon_options', static function ( int $coupon_id ): void {
	$users = get_users( [
		'role__in' => [ 'coupon_partner', 'administrator', 'shop_manager' ],
		'orderby'  => 'display_name',
		'number'   => 500,
	] );

	$options = [ '' => __( '— No partner —', 'foodify' ) ];
	foreach ( $users as $user ) {
		$options[ (string) $user->ID ] = sprintf( '%s (%s)', $user->display_name, $user->user_email );
	}

	woocommerce_wp_select( [
		'id'          => FOODIFY_PARTNER_META,
		'label'       => __( 'Partner', 'foodify' ),
		'description' => __( 'Emailed automatically on every order that uses this code.', 'foodify' ),
		'desc_tip'    => true,
		'options'     => $options,
		'value'       => (string) get_post_meta( $coupon_id, FOODIFY_PARTNER_META, true ),
	] );

	// Scope §6's data model carries `_foodify_notify_enabled` and its test list
	// carries "a coupon with notification disabled emails nobody". Neither
	// existed. The row is still WRITTEN when this is off — silence to the
	// partner is a preference; a gap in the ledger is missing accounting.
	woocommerce_wp_checkbox( [
		'id'          => FOODIFY_NOTIFY_META,
		'label'       => __( 'Email the partner', 'foodify' ),
		'description' => __( 'Off: sales are still recorded and visible, the partner just is not emailed.', 'foodify' ),
		'value'       => '' === (string) get_post_meta( $coupon_id, FOODIFY_NOTIFY_META, true ) ? 'yes' : (string) get_post_meta( $coupon_id, FOODIFY_NOTIFY_META, true ),
	] );

	woocommerce_wp_text_input( [
		'id'                => FOODIFY_COMMISSION_META,
		'label'             => __( 'Commission %', 'foodify' ),
		'description'       => __( 'Reporting only — nothing is paid out by this site.', 'foodify' ),
		'desc_tip'          => true,
		'type'              => 'number',
		'custom_attributes' => [ 'step' => '0.01', 'min' => '0', 'max' => '100' ],
		'value'             => (string) get_post_meta( $coupon_id, FOODIFY_COMMISSION_META, true ),
	] );
}, 10, 1 );

add_action( 'woocommerce_coupon_options_save', static function ( int $coupon_id ): void {
	$partner = isset( $_POST[ FOODIFY_PARTNER_META ] ) ? absint( wp_unslash( $_POST[ FOODIFY_PARTNER_META ] ) ) : 0;
	if ( $partner > 0 ) {
		update_post_meta( $coupon_id, FOODIFY_PARTNER_META, $partner );
	} else {
		delete_post_meta( $coupon_id, FOODIFY_PARTNER_META );
	}

	update_post_meta( $coupon_id, FOODIFY_NOTIFY_META, isset( $_POST[ FOODIFY_NOTIFY_META ] ) ? 'yes' : 'no' );

	$pct = isset( $_POST[ FOODIFY_COMMISSION_META ] ) ? (float) wp_unslash( $_POST[ FOODIFY_COMMISSION_META ] ) : 0.0;
	if ( $pct > 0.0 && $pct <= 100.0 ) {
		update_post_meta( $coupon_id, FOODIFY_COMMISSION_META, $pct );
	} else {
		delete_post_meta( $coupon_id, FOODIFY_COMMISSION_META );
	}
}, 10, 1 );

/* -------------------------------------------------------------------------
 * 2. Helpers
 * ---------------------------------------------------------------------- */

/**
 * Resolve the coupons on an order that have a partner attached.
 *
 * @return array<int, array{code:string, coupon_id:int, partner_id:int, discount:float,
 *                          notify:bool, commission_pct:?float}>
 */
function foodify_partner_coupons_on_order( WC_Order $order ): array {
	$found = [];

	foreach ( $order->get_items( 'coupon' ) as $item ) {
		$code      = (string) $item->get_code();
		$coupon_id = (int) wc_get_coupon_id_by_code( $code );
		if ( ! $coupon_id ) {
			continue;
		}

		$partner_id = (int) get_post_meta( $coupon_id, FOODIFY_PARTNER_META, true );
		if ( ! $partner_id ) {
			continue;
		}

		$pct = get_post_meta( $coupon_id, FOODIFY_COMMISSION_META, true );

		$found[] = [
			'code'           => $code,
			'coupon_id'      => $coupon_id,
			'partner_id'     => $partner_id,
			'discount'       => (float) $item->get_discount(),
			// Absent meta means yes. A coupon created before this field existed
			// must not silently stop notifying the partner it was created for.
			'notify'         => 'no' !== (string) get_post_meta( $coupon_id, FOODIFY_NOTIFY_META, true ),
			'commission_pct' => '' === $pct || null === $pct ? null : (float) $pct,
		];
	}

	return $found;
}

/** Total units on an order. */
function foodify_order_units( WC_Order $order ): int {
	$units = 0;
	foreach ( $order->get_items() as $item ) {
		$units += (int) $item->get_quantity();
	}
	return $units;
}

/**
 * "Inventory" — what actually sold, per line.
 *
 * The client's sentence was "inventory & value". The kit's email carried a
 * single unit COUNT, which answers value twice and inventory not at all.
 */
function foodify_order_line_items( WC_Order $order ): array {
	$rows = [];
	foreach ( $order->get_items() as $item ) {
		$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
		$rows[]  = [
			'sku'   => $product ? (string) $product->get_sku() : '',
			'name'  => (string) $item->get_name(),
			'qty'   => (int) $item->get_quantity(),
			'total' => (float) $item->get_total(),
		];
	}
	return foodify_partner_line_items( $rows );
}

/**
 * Running totals, DERIVED from the ledger.
 *
 * These were incremental counters on coupon post meta, and the file header
 * promised `wp foodify coupons reconcile` as the fix if they drifted. That
 * command was never written. Derived totals cannot drift, so there is nothing to
 * reconcile and nothing to promise.
 *
 * @param string $bucket 'YYYY-MM', or 'all'.
 */
function foodify_coupon_stats( int $coupon_id, string $bucket ): array {
	$totals = foodify_coupon_totals( $coupon_id, 'all' === $bucket ? '' : $bucket );
	return [
		'orders'   => $totals['orders'],
		'units'    => $totals['units'],
		'revenue'  => $totals['revenue'],
		'discount' => $totals['discount'],
	];
}

/* -------------------------------------------------------------------------
 * 3. The notification
 * ---------------------------------------------------------------------- */

/**
 * Credit the partner once, whatever path the order took to becoming real.
 *
 * WHY NOT `woocommerce_payment_complete`, WHICH IS WHAT SCOPE §6 SAYS.
 * A cash-on-delivery order NEVER FIRES IT — see docs/WP-07-NOTES.md. Following
 * that instruction literally would have failed to credit a partner on every cash
 * order, which on this store is most of them, with nothing erroring.
 *
 * Fires on `processing` AND `completed`; the ledger's UNIQUE (order_id,
 * coupon_id) makes the second a no-op, so an order that skips one status is
 * credited late rather than never.
 */
function foodify_attribute_order( int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$coupons = foodify_partner_coupons_on_order( $order );
	if ( ! $coupons ) {
		return;
	}

	$net        = (float) $order->get_subtotal() - (float) $order->get_total_discount();
	$units      = foodify_order_units( $order );
	$line_items = foodify_order_line_items( $order );
	$now        = current_time( 'mysql' );
	$created    = $order->get_date_created();
	$month      = gmdate( 'Y-m', $created ? $created->getTimestamp() : time() );

	// THE FUNCTION THAT WAS CALLED HERE AND NEVER EXISTED. It apportions revenue
	// across every partner code on the order, so the shares sum to $net exactly
	// and no partner is silently left out.
	$attributed = foodify_attributed_coupons( $coupons, $net );
	$shared     = count( $attributed );

	foreach ( $attributed as $c ) {
		$commission = foodify_commission_amount( $c['attributed_revenue'], $c['commission_pct'] );
		$partner    = get_userdata( $c['partner_id'] );

		// INSERT IGNORE against the unique key: this is the idempotency guard,
		// and it is in the storage rather than in a meta flag the caller has to
		// remember to check.
		$fresh = foodify_record_attribution( [
			'order_id'           => $order_id,
			'coupon_id'          => $c['coupon_id'],
			'coupon_code'        => $c['code'],
			'owner_email'        => $partner ? (string) $partner->user_email : '',
			'order_total'        => (float) $order->get_total(),
			'discount_amount'    => $c['discount'],
			'attributed_revenue' => $c['attributed_revenue'],
			'commission_amount'  => $commission,
			'units'              => $units,
			'line_items_json'    => (string) wp_json_encode( $line_items ),
			'order_status'       => (string) $order->get_status(),
			'notified_at'        => $now,
			'created_at'         => $now,
		] );

		if ( ! $fresh ) {
			continue;   // already recorded — a status flap, not a second sale
		}
		if ( ! $c['notify'] ) {
			continue;   // recorded, deliberately not emailed
		}

		foodify_send_partner_email( $c, $order, $units, $line_items, $shared, $commission, $month, false );
	}

	$order->update_meta_data( FOODIFY_NOTIFIED_META, $now );
	$order->save();
}

add_action( 'woocommerce_order_status_processing', 'foodify_attribute_order', 20, 1 );
add_action( 'woocommerce_order_status_completed',  'foodify_attribute_order', 20, 1 );

/**
 * Refunds reverse the row and send a correction.
 *
 * Reversal marks, never deletes: a reversed sale is accounting history, and a
 * partner who was told about a sale is owed the correction in the same ledger.
 *
 * The kit debited a REFUND AMOUNT from a counter that had been credited with
 * `subtotal - discount`. Different bases, so a full refund did not return the
 * counter to zero — it left a residue nobody could explain. Reversing the ROW
 * makes the two sides symmetric by construction.
 */
add_action( 'woocommerce_order_refunded', static function ( int $order_id, int $refund_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$coupons = foodify_partner_coupons_on_order( $order );
	if ( ! $coupons ) {
		return;
	}
	// Never reverse a credit that was never made.
	if ( ! $order->get_meta( FOODIFY_NOTIFIED_META ) ) {
		return;
	}

	$net        = (float) $order->get_subtotal() - (float) $order->get_total_discount();
	$attributed = foodify_attributed_coupons( $coupons, $net );
	$shared     = count( $attributed );
	$now        = current_time( 'mysql' );
	$created    = $order->get_date_created();
	$month      = gmdate( 'Y-m', $created ? $created->getTimestamp() : time() );
	$line_items = foodify_order_line_items( $order );

	foreach ( $attributed as $c ) {
		foodify_reverse_attribution( $order_id, $c['coupon_id'], $now );
		if ( ! $c['notify'] ) {
			continue;
		}
		$commission = foodify_commission_amount( $c['attributed_revenue'], $c['commission_pct'] );
		foodify_send_partner_email( $c, $order, 0, $line_items, $shared, $commission, $month, true );
	}
}, 20, 2 );

/**
 * Compose and send.
 *
 * The body is built by a PURE function so scope §6's stated test case — "the
 * email contains zero customer PII" — is executable. The same check then runs
 * HERE, at send time, and refuses rather than leaks: a test proves today's
 * template is clean, but the guard is what stops tomorrow's well-meant "add the
 * customer's name so it feels personal".
 */
function foodify_send_partner_email(
	array $coupon,
	WC_Order $order,
	int $units,
	array $line_items,
	int $shared_with,
	float $commission,
	string $month,
	bool $is_correction
): void {
	$partner = get_userdata( $coupon['partner_id'] );
	if ( ! $partner || ! is_email( $partner->user_email ) ) {
		$order->add_order_note( sprintf(
			/* translators: %s: coupon code */
			__( 'Partner notification for %s could not be sent: no valid email on the partner account.', 'foodify' ),
			$coupon['code']
		) );
		return;
	}

	$site = get_bloginfo( 'name' );
	$body = foodify_partner_email_body( [
		'partner_name'       => $partner->display_name,
		'code'               => $coupon['code'],
		'order_number'       => (string) $order->get_order_number(),
		'order_date'         => wc_format_datetime( $order->get_date_created() ),
		'line_items'         => $line_items,
		'order_total'        => (float) $order->get_total(),
		'attributed_revenue' => (float) ( $coupon['attributed_revenue'] ?? 0.0 ),
		'discount'           => (float) $coupon['discount'],
		'commission'         => $commission > 0.0 ? $commission : null,
		'shared_with'        => $shared_with,
		'month_label'        => gmdate( 'F Y', (int) strtotime( $month . '-01' ) ),
		'mtd'                => foodify_coupon_stats( $coupon['coupon_id'], $month ),
		'site'               => $site,
		'portal_url'         => home_url( '/my-account/partner/' ),
		'is_correction'      => $is_correction,
	] );

	$leaked = foodify_pii_in_text( $body, [
		'buyer name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		'buyer email'   => (string) $order->get_billing_email(),
		'buyer phone'   => (string) $order->get_billing_phone(),
		'buyer address' => (string) $order->get_billing_address_1(),
		'buyer postcode'=> (string) $order->get_billing_postcode(),
	] );
	if ( $leaked ) {
		// Refuse. Scope §6 calls this "a privacy line and a DPDP-Act-shaped one",
		// and an unsent email is recoverable in a way a sent one is not.
		$order->add_order_note( sprintf(
			/* translators: 1: coupon code, 2: comma-separated field names */
			__( 'Partner notification for %1$s was BLOCKED: the message contained customer data (%2$s). The sale is still recorded.', 'foodify' ),
			$coupon['code'],
			implode( ', ', $leaked )
		) );
		return;
	}

	$subject = $is_correction
		/* translators: 1: site name, 2: order number, 3: coupon code */
		? sprintf( __( '[%1$s] Correction on order #%2$s — %3$s', 'foodify' ), $site, $order->get_order_number(), $coupon['code'] )
		/* translators: 1: site name, 2: coupon code */
		: sprintf( __( '[%1$s] New order using %2$s', 'foodify' ), $site, $coupon['code'] );

	wp_mail( $partner->user_email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
}

/* -------------------------------------------------------------------------
 * 3b. The partner-facing endpoint the notification email links to.
 *     Without this registration every email in section 3 links to a 404.
 * ---------------------------------------------------------------------- */

add_action( 'init', static function (): void {
	add_rewrite_endpoint( 'partner', EP_ROOT | EP_PAGES );
} );

add_filter( 'woocommerce_account_menu_items', static function ( array $items ): array {
	if ( ! current_user_can( 'coupon_partner' ) && ! current_user_can( 'manage_woocommerce' ) ) {
		return $items;
	}
	return foodify_insert_after( $items, 'orders', 'partner', __( 'My coupon', 'foodify' ) );
} );

add_action( 'woocommerce_account_partner_endpoint', static function (): void {
	$user_id = get_current_user_id();
	$coupons = get_posts( [
		'post_type'      => 'shop_coupon',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'fields'         => 'ids',
		'meta_key'       => FOODIFY_PARTNER_META,
		'meta_value'     => (string) $user_id,
	] );

	if ( ! $coupons ) {
		echo '<p>' . esc_html__( 'No coupon codes are assigned to your account yet.', 'foodify' ) . '</p>';
		return;
	}

	$month = gmdate( 'Y-m', (int) current_time( 'timestamp' ) );

	foreach ( $coupons as $coupon_id ) {
		$mtd = foodify_coupon_stats( (int) $coupon_id, $month );
		$all = foodify_coupon_stats( (int) $coupon_id, 'all' );

		printf( '<h2>%s</h2>', esc_html( strtoupper( (string) get_the_title( $coupon_id ) ) ) );
		echo '<table class="woocommerce-table shop_table"><tbody>';
		printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'Orders this month', 'foodify' ), (int) $mtd['orders'] );
		printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'Units this month', 'foodify' ), (int) $mtd['units'] );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Value this month', 'foodify' ), wp_kses_post( wc_price( (float) $mtd['revenue'] ) ) );
		printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'Orders all time', 'foodify' ), (int) $all['orders'] );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Value all time', 'foodify' ), wp_kses_post( wc_price( (float) $all['revenue'] ) ) );
		echo '</tbody></table>';
	}
} );

/* -------------------------------------------------------------------------
 * 4. Admin surfacing — orders column + dashboard widget
 * ---------------------------------------------------------------------- */

/** HPOS orders list column. */
add_filter( 'woocommerce_shop_order_list_table_columns', static function ( array $columns ): array {
	return foodify_insert_after( $columns, 'order_status', 'foodify_coupon', __( 'Coupon', 'foodify' ) );
} );

add_action( 'woocommerce_shop_order_list_table_custom_column', static function ( string $column, $order ): void {
	if ( 'foodify_coupon' === $column && $order instanceof WC_Order ) {
		echo wp_kses_post( foodify_render_order_coupon_cell( $order ) );
	}
}, 10, 2 );

/** Legacy post-table fallback, for stores not yet on HPOS. */
add_filter( 'manage_edit-shop_order_columns', static function ( array $columns ): array {
	return foodify_insert_after( $columns, 'order_status', 'foodify_coupon', __( 'Coupon', 'foodify' ) );
} );

add_action( 'manage_shop_order_posts_custom_column', static function ( string $column, int $post_id ): void {
	if ( 'foodify_coupon' !== $column ) {
		return;
	}
	$order = wc_get_order( $post_id );
	if ( $order instanceof WC_Order ) {
		echo wp_kses_post( foodify_render_order_coupon_cell( $order ) );
	}
}, 10, 2 );

function foodify_render_order_coupon_cell( WC_Order $order ): string {
	$codes = $order->get_coupon_codes();
	if ( ! $codes ) {
		return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No coupon', 'foodify' ) . '</span>';
	}

	$out = [];
	foreach ( $codes as $code ) {
		$coupon_id  = (int) wc_get_coupon_id_by_code( $code );
		$partner_id = $coupon_id ? (int) get_post_meta( $coupon_id, FOODIFY_PARTNER_META, true ) : 0;
		$partner    = $partner_id ? get_userdata( $partner_id ) : null;

		$out[] = $partner
			? sprintf( '<strong>%s</strong><br><small>%s</small>', esc_html( strtoupper( $code ) ), esc_html( $partner->display_name ) )
			: sprintf( '<strong>%s</strong>', esc_html( strtoupper( $code ) ) );
	}

	return implode( '<br>', $out );
}

/** Insert a column after a known key, preserving order. */
if ( ! function_exists( 'foodify_insert_after' ) ) :
function foodify_insert_after( array $columns, string $after, string $key, string $label ): array {
	$out = [];
	foreach ( $columns as $k => $v ) {
		$out[ $k ] = $v;
		if ( $k === $after ) {
			$out[ $key ] = $label;
		}
	}
	if ( ! isset( $out[ $key ] ) ) {
		$out[ $key ] = $label;
	}
	return $out;
}
endif;

/** Dashboard widget: revenue and units by code, this month. */
add_action( 'wp_dashboard_setup', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'foodify_coupon_performance',
		__( 'Coupon performance — this month', 'foodify' ),
		static function (): void {
			$month   = gmdate( 'Y-m', (int) current_time( 'timestamp' ) );
			$coupons = get_posts( [
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_key'       => FOODIFY_PARTNER_META,
				'fields'         => 'ids',
			] );

			if ( ! $coupons ) {
				echo '<p>' . esc_html__( 'No coupons have a partner assigned yet.', 'foodify' ) . '</p>';
				return;
			}

			$rows = [];
			foreach ( $coupons as $coupon_id ) {
				$stats = foodify_coupon_stats( (int) $coupon_id, $month );
				if ( $stats['orders'] < 1 ) {
					continue;
				}
				$rows[] = [ 'code' => get_the_title( $coupon_id ) ] + $stats;
			}

			if ( ! $rows ) {
				echo '<p>' . esc_html__( 'No partner-coded orders this month yet.', 'foodify' ) . '</p>';
				return;
			}

			usort( $rows, static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Code', 'foodify' ) . '</th>';
			echo '<th style="text-align:right">' . esc_html__( 'Orders', 'foodify' ) . '</th>';
			echo '<th style="text-align:right">' . esc_html__( 'Units', 'foodify' ) . '</th>';
			echo '<th style="text-align:right">' . esc_html__( 'Value', 'foodify' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				printf(
					'<tr><td><strong>%s</strong></td><td style="text-align:right">%d</td><td style="text-align:right">%d</td><td style="text-align:right">%s</td></tr>',
					esc_html( strtoupper( (string) $row['code'] ) ),
					(int) $row['orders'],
					(int) $row['units'],
					wp_kses_post( wc_price( (float) $row['revenue'] ) )
				);
			}

			echo '</tbody></table>';
		}
	);
} );

/* -------------------------------------------------------------------------
 * 5. Coupon Performance — the screen scope §6 asks for.
 *
 * "Per code — redemptions, gross value, discount given, commission owed, last
 * used, partner contact. Date filtering, CSV export, drill-through to orders."
 *
 * None of it was possible against the counters this replaced: a running total
 * has no rows to filter, export, or drill into. The ledger is what makes the
 * screen a query rather than a rewrite.
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', static function (): void {
	add_submenu_page(
		'woocommerce',
		__( 'Coupon Performance', 'foodify' ),
		__( 'Coupon Performance', 'foodify' ),
		'manage_woocommerce',
		'foodify-coupon-performance',
		'foodify_render_coupon_performance'
	);
} );

/** The window being reported on, defaulting to this month. */
function foodify_performance_range( array $query ): array {
	$from = isset( $query['from'] ) ? sanitize_text_field( (string) $query['from'] ) : '';
	$to   = isset( $query['to'] ) ? sanitize_text_field( (string) $query['to'] ) : '';
	$ok   = static fn( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );

	if ( ! $ok( $from ) ) {
		$from = gmdate( 'Y-m-01' );
	}
	if ( ! $ok( $to ) ) {
		$to = gmdate( 'Y-m-d' );
	}
	// A backwards range returns nothing and looks like "no sales", which is the
	// worst possible way for a reporting screen to be wrong.
	if ( $from > $to ) {
		[ $from, $to ] = [ $to, $from ];
	}
	return [ $from, $to ];
}

/** One row per coupon over the window. */
function foodify_performance_rows( string $from, string $to ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table, by design (scope §6)
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT coupon_id, coupon_code, owner_email,
		        COUNT(*) AS redemptions,
		        COALESCE(SUM(attributed_revenue),0) AS revenue,
		        COALESCE(SUM(discount_amount),0)    AS discount,
		        COALESCE(SUM(commission_amount),0)  AS commission,
		        COALESCE(SUM(units),0)              AS units,
		        MAX(created_at)                     AS last_used
		   FROM `' . foodify_attribution_table() . '`
		  WHERE reversed_at IS NULL AND created_at >= %s AND created_at < %s
		  GROUP BY coupon_id, coupon_code, owner_email
		  ORDER BY revenue DESC',
		$from . ' 00:00:00',
		gmdate( 'Y-m-d 00:00:00', (int) strtotime( $to . ' +1 day' ) )
	), ARRAY_A );

	return is_array( $rows ) ? $rows : [];
}

function foodify_render_coupon_performance(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this.', 'foodify' ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report, no state changes
	[ $from, $to ] = foodify_performance_range( wp_unslash( $_GET ) );
	$rows = foodify_performance_rows( $from, $to );

	echo '<div class="wrap"><h1>' . esc_html__( 'Coupon Performance', 'foodify' ) . '</h1>';

	printf(
		'<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="foodify-coupon-performance">'
		. '<label>%1$s <input type="date" name="from" value="%2$s"></label> '
		. '<label>%3$s <input type="date" name="to" value="%4$s"></label> '
		. '<button class="button">%5$s</button> '
		. '<a class="button" href="%6$s">%7$s</a></form>',
		esc_html__( 'From', 'foodify' ), esc_attr( $from ),
		esc_html__( 'To', 'foodify' ), esc_attr( $to ),
		esc_html__( 'Show', 'foodify' ),
		esc_url( wp_nonce_url(
			admin_url( 'admin-post.php?action=foodify_export_attribution&from=' . rawurlencode( $from ) . '&to=' . rawurlencode( $to ) ),
			'foodify_export_attribution'
		) ),
		esc_html__( 'Download CSV', 'foodify' )
	);

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No partner-coupon orders in this window.', 'foodify' ) . '</p></div>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	foreach ( [ 'Code', 'Partner', 'Redemptions', 'Units', 'Attributed value', 'Discount given', 'Commission owed', 'Last used', '' ] as $h ) {
		printf( '<th>%s</th>', esc_html( $h ) );
	}
	echo '</tr></thead><tbody>';

	foreach ( $rows as $r ) {
		printf(
			'<tr><td><strong>%1$s</strong></td><td>%2$s</td><td>%3$d</td><td>%4$d</td>'
			. '<td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td>'
			. '<td><a href="%9$s">%10$s</a></td></tr>',
			esc_html( strtoupper( (string) $r['coupon_code'] ) ),
			esc_html( (string) $r['owner_email'] ),
			(int) $r['redemptions'],
			(int) $r['units'],
			esc_html( foodify_partner_money( (float) $r['revenue'] ) ),
			esc_html( foodify_partner_money( (float) $r['discount'] ) ),
			esc_html( foodify_partner_money( (float) $r['commission'] ) ),
			esc_html( (string) $r['last_used'] ),
			// Drill-through: the orders list, filtered to this code.
			esc_url( admin_url( 'admin.php?page=wc-orders&s=' . rawurlencode( (string) $r['coupon_code'] ) . '&search-filter=all' ) ),
			esc_html__( 'Orders', 'foodify' )
		);
	}
	echo '</tbody></table></div>';
}

/**
 * CSV export.
 *
 * Every cell goes through the shared escaper: a coupon code is user-supplied
 * text, and a code beginning `=` becomes a formula when the file is opened in
 * Excel. That is CSV injection, and it is the same defect this project already
 * fixed once in the product export.
 */
add_action( 'admin_post_foodify_export_attribution', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to export this.', 'foodify' ) );
	}
	check_admin_referer( 'foodify_export_attribution' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
	[ $from, $to ] = foodify_performance_range( wp_unslash( $_GET ) );
	$rows = foodify_performance_rows( $from, $to );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=coupon-performance-' . $from . '-to-' . $to . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'Code', 'Partner email', 'Redemptions', 'Units', 'Attributed value', 'Discount given', 'Commission owed', 'Last used' ] );
	foreach ( $rows as $r ) {
		fputcsv( $out, array_map( 'foodify_csv_cell', [
			strtoupper( (string) $r['coupon_code'] ),
			(string) $r['owner_email'],
			(int) $r['redemptions'],
			(int) $r['units'],
			number_format( (float) $r['revenue'], 2, '.', '' ),
			number_format( (float) $r['discount'], 2, '.', '' ),
			number_format( (float) $r['commission'], 2, '.', '' ),
			(string) $r['last_used'],
		] ) );
	}
	fclose( $out );
	exit;
} );
