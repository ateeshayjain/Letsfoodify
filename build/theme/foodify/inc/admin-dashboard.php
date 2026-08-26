<?php
/**
 * WP-10 — the admin landing screen.
 *
 * Scope §W6: "A curated WooCommerce landing screen: today's orders, revenue,
 * low stock, pending shipments, coupon performance."
 *
 * The point is a screen someone opens at 9am and immediately knows what to do
 * from. WordPress's own dashboard is a wall of widgets about WordPress; the
 * WooCommerce one is analytics, which answers a different question than "what is
 * waiting for me".
 *
 * TWO RULES CARRIED OVER FROM EVERY OTHER PACKAGE IN THIS BUILD
 * ------------------------------------------------------------
 * 1. A NUMBER NOBODY MEASURED IS NOT ZERO. "No orders today" and "the query did
 *    not run" render as the same `0` unless they are kept apart, and a dashboard
 *    that quietly reports nothing wrong when it cannot see is worse than one
 *    that is honestly blank.
 *
 * 2. NULL STOCK IS NOT ZERO STOCK. WooCommerce returns null for
 *    `stock_quantity` when stock management is OFF for a product — which is a
 *    perfectly normal setting and means "unlimited", not "none". Read it as 0
 *    and the low-stock panel fills with every unmanaged product, screaming about
 *    items that are fine, until people stop reading it.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const FOODIFY_LOW_STOCK_DEFAULT = 6;
const FOODIFY_DASH_CACHE        = 'foodify_dashboard_snapshot';

/* -------------------------------------------------------------------------
 * Pure — tested in tests/dashboard-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * The tiles, from raw counts.
 *
 * A value of `null` means NOT MEASURED and renders as "—", never as zero.
 *
 * @param array{orders_today:?int,revenue_today:?float,awaiting:?int,low_stock:?int,partner_orders:?int} $raw
 * @param array<string,bool> $can Capability map for the viewer.
 * @return array<int,array{key:string,label:string,value:string,measured:bool,note:string}>
 */
function foodify_dashboard_tiles( array $raw, array $can ): array {
	$num = static fn( $v ): string => null === $v ? '—' : number_format( (int) $v );
	$rup = static fn( $v ): string => null === $v ? '—' : '₹' . number_format( (float) $v, 0, '.', ',' );

	$tiles = [
		[
			'key'      => 'orders_today',
			'label'    => 'Orders today',
			'value'    => $num( $raw['orders_today'] ?? null ),
			'measured' => null !== ( $raw['orders_today'] ?? null ),
			'note'     => '',
		],
		[
			'key'      => 'awaiting',
			'label'    => 'Awaiting dispatch',
			'value'    => $num( $raw['awaiting'] ?? null ),
			'measured' => null !== ( $raw['awaiting'] ?? null ),
			// The only tile that is a to-do rather than a fact.
			'note'     => ( (int) ( $raw['awaiting'] ?? 0 ) ) > 0 ? 'Needs packing' : '',
		],
		[
			'key'      => 'low_stock',
			'label'    => 'Low or out of stock',
			'value'    => $num( $raw['low_stock'] ?? null ),
			'measured' => null !== ( $raw['low_stock'] ?? null ),
			'note'     => '',
		],
	];

	// Revenue is business-sensitive and Shop Staff do not need it to pack a box.
	// Gated here rather than at render, so the tile cannot leak through a
	// template someone copies.
	if ( ! empty( $can['view_woocommerce_reports'] ) ) {
		array_splice( $tiles, 1, 0, [ [
			'key'      => 'revenue_today',
			'label'    => 'Revenue today',
			'value'    => $rup( $raw['revenue_today'] ?? null ),
			'measured' => null !== ( $raw['revenue_today'] ?? null ),
			'note'     => '',
		] ] );
	}

	if ( ! empty( $can['manage_woocommerce'] ) ) {
		$tiles[] = [
			'key'      => 'partner_orders',
			'label'    => 'Partner-code orders this month',
			'value'    => $num( $raw['partner_orders'] ?? null ),
			'measured' => null !== ( $raw['partner_orders'] ?? null ),
			'note'     => '',
		];
	}

	return $tiles;
}

/**
 * Low-stock rows, most urgent first.
 *
 * @param array<int,array{id:int,name:string,stock:?int,managed:bool}> $products
 * @return array<int,array{id:int,name:string,stock:int,state:string}>
 */
function foodify_low_stock_rows( array $products, int $threshold ): array {
	$rows = [];
	foreach ( $products as $p ) {
		// THE ONE THAT MATTERS. Stock management off means "we do not count this
		// one", not "there are none". Reading null as 0 fills the panel with
		// products that are fine, and a panel full of false alarms gets ignored —
		// taking the true one with it.
		if ( empty( $p['managed'] ) || null === ( $p['stock'] ?? null ) ) {
			continue;
		}
		$stock = (int) $p['stock'];
		if ( $stock > $threshold ) {
			continue;
		}
		$rows[] = [
			'id'    => (int) $p['id'],
			'name'  => (string) $p['name'],
			'stock' => $stock,
			'state' => $stock <= 0 ? 'out' : 'low',
		];
	}

	usort( $rows, static function ( array $a, array $b ): int {
		$cmp = $a['stock'] <=> $b['stock'];        // fewest first; out of stock leads
		return 0 !== $cmp ? $cmp : strcmp( $a['name'], $b['name'] );   // stable
	} );
	return $rows;
}

/** A stock figure a human typed. Refuses rather than guessing. */
function foodify_parse_stock_input( string $raw ): ?int {
	$raw = trim( $raw );
	if ( '' === $raw || ! preg_match( '/^\d+$/', $raw ) ) {
		return null;   // negative, blank, or "twelve" — all refused
	}
	return (int) $raw;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

add_action( 'admin_menu', static function (): void {
	// Top level, above everything, because it is the landing screen. Shop Staff
	// are redirected here and this is the only WooCommerce page most of them
	// need; `edit_shop_orders` is the capability that says "you work orders".
	add_menu_page(
		__( 'Today', 'foodify' ),
		__( 'Today', 'foodify' ),
		'edit_shop_orders',
		'foodify-today',
		'foodify_render_today',
		'dashicons-food',
		1
	);
}, 9 );

/**
 * Gather the numbers. Cached, because this runs on a screen people leave open.
 *
 * Every value starts as NULL and is only set when its query actually returned.
 * A failed query therefore renders "—", not a confident zero.
 */
function foodify_dashboard_snapshot(): array {
	$cached = get_transient( FOODIFY_DASH_CACHE );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$raw = [
		'orders_today'   => null,
		'revenue_today'  => null,
		'awaiting'       => null,
		'low_stock'      => null,
		'partner_orders' => null,
	];

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return $raw;   // WooCommerce absent: everything stays unmeasured
	}

	$today = wp_date( 'Y-m-d' );
	$orders = wc_get_orders( [
		'limit'        => -1,
		'date_created' => '>=' . $today . ' 00:00:00',
		'status'       => [ 'processing', 'completed', 'on-hold' ],
		'return'       => 'objects',
	] );
	if ( is_array( $orders ) ) {
		$raw['orders_today']  = count( $orders );
		$raw['revenue_today'] = array_sum( array_map( static fn( $o ): float => (float) $o->get_total(), $orders ) );
	}

	$awaiting = wc_get_orders( [ 'limit' => -1, 'status' => [ 'processing' ], 'return' => 'ids' ] );
	if ( is_array( $awaiting ) ) {
		$raw['awaiting'] = count( $awaiting );
	}

	$low = foodify_low_stock_rows( foodify_stock_candidates(), foodify_low_stock_threshold() );
	$raw['low_stock'] = count( $low );

	if ( function_exists( 'foodify_attribution_table' ) ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WP-09 custom table
		$n = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM `' . foodify_attribution_table() . '` WHERE reversed_at IS NULL AND created_at >= %s',
			wp_date( 'Y-m-01 00:00:00' )
		) );
		$raw['partner_orders'] = null === $n ? null : (int) $n;
	}

	set_transient( FOODIFY_DASH_CACHE, $raw, 5 * MINUTE_IN_SECONDS );
	return $raw;
}

/** A number changing is what makes the cache wrong, so that is what clears it. */
foreach ( [ 'woocommerce_order_status_changed', 'woocommerce_new_order', 'woocommerce_product_set_stock' ] as $hook ) {
	add_action( $hook, static function (): void {
		delete_transient( FOODIFY_DASH_CACHE );
	} );
}

function foodify_low_stock_threshold(): int {
	$wc = get_option( 'woocommerce_notify_low_stock_amount' );
	$n  = is_numeric( $wc ) ? (int) $wc : FOODIFY_LOW_STOCK_DEFAULT;
	return (int) apply_filters( 'foodify_low_stock_threshold', max( 0, $n ) );
}

/** Products worth asking about, mapped to the shape the pure function takes. */
function foodify_stock_candidates(): array {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return [];
	}
	$products = wc_get_products( [ 'limit' => 200, 'status' => 'publish', 'return' => 'objects' ] );
	$out      = [];
	foreach ( (array) $products as $p ) {
		$out[] = [
			'id'      => $p->get_id(),
			'name'    => $p->get_name(),
			'stock'   => $p->get_stock_quantity(),          // null when unmanaged
			'managed' => (bool) $p->get_manage_stock(),
		];
	}
	return $out;
}

function foodify_render_today(): void {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this.', 'foodify' ) );
	}

	$raw   = foodify_dashboard_snapshot();
	$tiles = foodify_dashboard_tiles( $raw, [
		'view_woocommerce_reports' => current_user_can( 'view_woocommerce_reports' ),
		'manage_woocommerce'       => current_user_can( 'manage_woocommerce' ),
	] );

	echo '<div class="wrap"><h1>' . esc_html__( 'Today', 'foodify' ) . '</h1>';

	echo '<div class="foodify-tiles" style="display:flex;gap:16px;flex-wrap:wrap;margin:1.5em 0">';
	foreach ( $tiles as $tile ) {
		printf(
			'<div style="flex:1 1 180px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px">'
			. '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970">%1$s</div>'
			. '<div style="font-size:28px;font-weight:600;margin-top:4px;color:%2$s">%3$s</div>'
			. '<div style="font-size:12px;color:#646970;min-height:1.2em">%4$s</div></div>',
			esc_html( $tile['label'] ),
			$tile['measured'] ? '#1d2327' : '#8c8f94',
			esc_html( $tile['value'] ),
			esc_html( $tile['measured'] ? $tile['note'] : __( 'not measured', 'foodify' ) )
		);
	}
	echo '</div>';

	foodify_render_low_stock_panel();

	printf(
		'<p><a class="button button-primary" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p></div>',
		esc_url( admin_url( 'admin.php?page=wc-orders&status=wc-processing' ) ),
		esc_html__( 'Orders awaiting dispatch', 'foodify' ),
		esc_url( admin_url( 'admin.php?page=foodify-coupon-performance' ) ),
		esc_html__( 'Coupon performance', 'foodify' )
	);
}

/**
 * Low stock, with the one write Shop Staff are trusted with.
 *
 * Setting stock here rather than sending them to the product editor is the whole
 * reason `foodify_manage_stock` exists as a capability of its own: the product
 * editor would also let them change the price.
 */
function foodify_render_low_stock_panel(): void {
	$rows = foodify_low_stock_rows( foodify_stock_candidates(), foodify_low_stock_threshold() );

	printf( '<h2>%s</h2>', esc_html__( 'Low or out of stock', 'foodify' ) );
	if ( ! $rows ) {
		printf( '<p>%s</p>', esc_html__( 'Nothing is running low. Products with stock management switched off are not counted here.', 'foodify' ) );
		return;
	}

	$can_edit = current_user_can( FOODIFY_CAP_STOCK );

	echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
	printf( '<th>%s</th><th>%s</th><th>%s</th>', esc_html__( 'Product', 'foodify' ), esc_html__( 'In stock', 'foodify' ), esc_html__( 'Set to', 'foodify' ) );
    echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		printf(
			'<tr><td><strong>%1$s</strong></td><td><span style="color:%2$s;font-weight:600">%3$d</span> %4$s</td><td>%5$s</td></tr>',
			esc_html( $row['name'] ),
			'out' === $row['state'] ? '#b32d2e' : '#996800',
			$row['stock'],
			'out' === $row['state'] ? esc_html__( '(out of stock)', 'foodify' ) : '',
			$can_edit ? foodify_stock_form( $row['id'] ) : '—'
		);
	}
	echo '</tbody></table>';
}

/** One-field form per row. A nonce per product id, so a link cannot do this. */
function foodify_stock_form( int $product_id ): string {
	return sprintf(
		'<form method="post" action="%1$s" style="display:flex;gap:6px">%2$s'
		. '<input type="hidden" name="action" value="foodify_set_stock">'
		. '<input type="hidden" name="product_id" value="%3$d">'
		. '<input type="number" name="stock" min="0" step="1" required style="width:90px">'
		. '<button class="button">%4$s</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( 'foodify_set_stock_' . $product_id, '_wpnonce', true, false ),
		$product_id,
		esc_html__( 'Save', 'foodify' )
	);
}

add_action( 'admin_post_foodify_set_stock', static function (): void {
	$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

	// Capability BEFORE nonce: a valid nonce from a user who may not do this is
	// still a user who may not do this.
	if ( ! current_user_can( FOODIFY_CAP_STOCK ) ) {
		wp_die( esc_html__( 'You do not have permission to change stock.', 'foodify' ) );
	}
	check_admin_referer( 'foodify_set_stock_' . $product_id );

	$stock = foodify_parse_stock_input(
		isset( $_POST['stock'] ) ? sanitize_text_field( wp_unslash( $_POST['stock'] ) ) : ''
	);
	$product = $product_id ? wc_get_product( $product_id ) : null;

	// Refuse rather than guess. A blank field meaning "zero" would take a product
	// off sale because somebody tabbed past it.
	if ( null === $stock || ! $product ) {
		wp_safe_redirect( add_query_arg( 'foodify_stock', 'invalid', admin_url( 'admin.php?page=foodify-today' ) ) );
		exit;
	}

	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
	$product->save();

	// An order note's equivalent for stock: who changed it, and to what.
	$user = wp_get_current_user();
	$product->add_meta_data( '_foodify_stock_last_set_by', $user->user_login . ' → ' . $stock . ' @ ' . current_time( 'mysql' ), true );
	$product->save_meta_data();

	delete_transient( FOODIFY_DASH_CACHE );
	wp_safe_redirect( add_query_arg( 'foodify_stock', 'saved', admin_url( 'admin.php?page=foodify-today' ) ) );
	exit;
} );

add_action( 'admin_notices', static function (): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$state = isset( $_GET['foodify_stock'] ) ? sanitize_key( wp_unslash( $_GET['foodify_stock'] ) ) : '';
	if ( 'saved' === $state ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Stock updated.', 'foodify' ) );
	} elseif ( 'invalid' === $state ) {
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'Stock must be a whole number, zero or more. Nothing was changed.', 'foodify' ) );
	}
} );
