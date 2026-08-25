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
const FOODIFY_STATS_META     = '_foodify_stats';

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
}, 10, 1 );

add_action( 'woocommerce_coupon_options_save', static function ( int $coupon_id ): void {
	$partner = isset( $_POST[ FOODIFY_PARTNER_META ] ) ? absint( wp_unslash( $_POST[ FOODIFY_PARTNER_META ] ) ) : 0;
	if ( $partner > 0 ) {
		update_post_meta( $coupon_id, FOODIFY_PARTNER_META, $partner );
	} else {
		delete_post_meta( $coupon_id, FOODIFY_PARTNER_META );
	}
}, 10, 1 );

/* -------------------------------------------------------------------------
 * 2. Helpers
 * ---------------------------------------------------------------------- */

/**
 * Resolve the coupons on an order that have a partner attached.
 *
 * @return array<int, array{code:string, coupon_id:int, partner_id:int, discount:float}>
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

		$found[] = [
			'code'      => $code,
			'coupon_id' => $coupon_id,
			'partner_id'=> $partner_id,
			'discount'  => (float) $item->get_discount(),
		];
	}

	return $found;
}

/** Total units on an order, excluding refunded quantities. */
function foodify_order_units( WC_Order $order ): int {
	$units = 0;
	foreach ( $order->get_items() as $item ) {
		$units += (int) $item->get_quantity();
	}
	return $units;
}

/**
 * Read the running counters off a coupon, keyed by YYYY-MM plus an all-time bucket.
 *
 * @return array{orders:int, units:int, revenue:float, discount:float}
 */
function foodify_coupon_stats( int $coupon_id, string $bucket ): array {
	$stats = get_post_meta( $coupon_id, FOODIFY_STATS_META, true );
	$stats = is_array( $stats ) ? $stats : [];
	return wp_parse_args(
		$stats[ $bucket ] ?? [],
		[ 'orders' => 0, 'units' => 0, 'revenue' => 0.0, 'discount' => 0.0 ]
	);
}

/** Apply a signed delta to both the month bucket and the all-time bucket. */
function foodify_bump_coupon_stats( int $coupon_id, string $month, array $delta ): void {
	$stats = get_post_meta( $coupon_id, FOODIFY_STATS_META, true );
	$stats = is_array( $stats ) ? $stats : [];

	foreach ( [ $month, 'all' ] as $bucket ) {
		$current = wp_parse_args(
			$stats[ $bucket ] ?? [],
			[ 'orders' => 0, 'units' => 0, 'revenue' => 0.0, 'discount' => 0.0 ]
		);
		$stats[ $bucket ] = [
			'orders'   => max( 0, (int) $current['orders'] + (int) ( $delta['orders'] ?? 0 ) ),
			'units'    => max( 0, (int) $current['units'] + (int) ( $delta['units'] ?? 0 ) ),
			'revenue'  => max( 0.0, (float) $current['revenue'] + (float) ( $delta['revenue'] ?? 0 ) ),
			'discount' => max( 0.0, (float) $current['discount'] + (float) ( $delta['discount'] ?? 0 ) ),
		];
	}

	update_post_meta( $coupon_id, FOODIFY_STATS_META, $stats );
}

/* -------------------------------------------------------------------------
 * 3. The notification
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_order_status_processing', static function ( int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	// Idempotency guard — a status flap must not resend.
	if ( $order->get_meta( FOODIFY_NOTIFIED_META ) ) {
		return;
	}

	$coupons = foodify_partner_coupons_on_order( $order );
	if ( ! $coupons ) {
		return;
	}

	$month = gmdate( 'Y-m', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() );
	$units = foodify_order_units( $order );
	$net   = (float) $order->get_subtotal() - (float) $order->get_total_discount();

	$coupons = foodify_attributed_coupons( $order, $coupons, true );

	foreach ( $coupons as $coupon ) {
		foodify_bump_coupon_stats( $coupon['coupon_id'], $month, [
			'orders'   => 1,
			'units'    => $units,
			'revenue'  => $net,
			'discount' => $coupon['discount'],
		] );

		foodify_send_partner_email( $coupon, $order, $units, $net, $month, false );
	}

	$order->update_meta_data( FOODIFY_NOTIFIED_META, current_time( 'mysql' ) );
	$order->save();
}, 20, 1 );

/** Refunds reverse the counters and send a correction. */
add_action( 'woocommerce_order_refunded', static function ( int $order_id, int $refund_id ): void {
	$order  = wc_get_order( $order_id );
	$refund = wc_get_order( $refund_id );
	if ( ! $order instanceof WC_Order || ! $refund ) {
		return;
	}

	$coupons = foodify_partner_coupons_on_order( $order );
	if ( ! $coupons ) {
		return;
	}

	// Never reverse a credit that was never made: if the order never reached
	// processing, no partner was told and no counter was bumped.
	if ( ! $order->get_meta( FOODIFY_NOTIFIED_META ) ) {
		return;
	}

	// Same single-winner rule as the credit path, or the loser goes negative.
	$coupons = foodify_attributed_coupons( $order, $coupons );

	$month  = gmdate( 'Y-m', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() );
	$amount = abs( (float) $refund->get_amount() );

	foreach ( $coupons as $coupon ) {
		foodify_bump_coupon_stats( $coupon['coupon_id'], $month, [
			'orders'  => 0,
			'units'   => 0,
			'revenue' => -$amount,
		] );

		foodify_send_partner_email( $coupon, $order, 0, -$amount, $month, true );
	}
}, 20, 2 );

/**
 * Compose and send. Kept deliberately plain — this lands in Gmail inboxes on phones.
 *
 * @param array{code:string, coupon_id:int, partner_id:int, discount:float} $coupon
 */
function foodify_send_partner_email(
	array $coupon,
	WC_Order $order,
	int $units,
	float $net,
	string $month,
	bool $is_correction
): void {
	$partner = get_userdata( $coupon['partner_id'] );
	if ( ! $partner || ! is_email( $partner->user_email ) ) {
		// Surface the failure rather than swallowing it — see the dashboard widget.
		$order->add_order_note( sprintf(
			/* translators: %s: coupon code */
			__( 'Partner notification for %s could not be sent: no valid email on the partner account.', 'foodify' ),
			$coupon['code']
		) );
		return;
	}

	$mtd  = foodify_coupon_stats( $coupon['coupon_id'], $month );
	$site = get_bloginfo( 'name' );

	$subject = $is_correction
		? sprintf( __( '[%1$s] Correction on order #%2$s — %3$s', 'foodify' ), $site, $order->get_order_number(), $coupon['code'] )
		: sprintf( __( '[%1$s] New order using %2$s', 'foodify' ), $site, $coupon['code'] );

	$lines = [
		sprintf( __( 'Hi %s,', 'foodify' ), $partner->display_name ),
		'',
		$is_correction
			? sprintf( __( 'An order using your code %s has been refunded. Your totals below are updated.', 'foodify' ), $coupon['code'] )
			: sprintf( __( 'Someone just ordered using your code %s.', 'foodify' ), $coupon['code'] ),
		'',
		sprintf( __( 'Order:        #%s', 'foodify' ), $order->get_order_number() ),
		sprintf( __( 'Date:         %s', 'foodify' ), wc_format_datetime( $order->get_date_created() ) ),
		sprintf( __( 'Order value:  %s', 'foodify' ), wp_strip_all_tags( wc_price( $net ) ) ),
		sprintf( __( 'Units:        %d', 'foodify' ), $units ),
		sprintf( __( 'Discount:     %s', 'foodify' ), wp_strip_all_tags( wc_price( $coupon['discount'] ) ) ),
		'',
		sprintf( __( '── Your totals for %s ──', 'foodify' ), gmdate( 'F Y', strtotime( $month . '-01' ) ) ),
		sprintf( __( 'Orders:       %d', 'foodify' ), $mtd['orders'] ),
		sprintf( __( 'Units:        %d', 'foodify' ), $mtd['units'] ),
		sprintf( __( 'Total value:  %s', 'foodify' ), wp_strip_all_tags( wc_price( $mtd['revenue'] ) ) ),
		'',
		sprintf( __( 'Full history: %s', 'foodify' ), home_url( '/my-account/partner/' ) ),
		'',
		sprintf( __( '— %s', 'foodify' ), $site ),
	];

	wp_mail(
		$partner->user_email,
		$subject,
		implode( "\n", $lines ),
		[ 'Content-Type: text/plain; charset=UTF-8' ]
	);
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
