<?php
/**
 * WP-09 — the attribution ledger: the arithmetic, the record, and the email body.
 *
 * Scope §6 calls the coupon engine "the one genuinely custom build". It also
 * specifies a custom TABLE, one row per redeeming order, because "it keeps
 * reporting queries cheap and survives order deletion for accounting". The kit
 * built running counters on coupon post meta instead, and the difference is not
 * cosmetic: counters cannot be drilled through, cannot be exported, and cannot
 * be checked against anything. When they drift, nothing says so.
 *
 * THE FATAL THIS FILE EXISTS TO FIX
 * ---------------------------------
 * `foodify_attributed_coupons()` was CALLED TWICE and DEFINED NOWHERE.
 *
 * An earlier verification pass — mine — extracted a duplicated single-winner
 * rule "into one function", updated both call sites, and never wrote the
 * function. `docs/VERIFICATION-2026-08-25.md` reports it as fixed. Every
 * `php -l` in this repository has passed since, because an undefined function
 * is a RUNTIME error, not a syntax error, and nothing here has ever run against
 * PHP with WooCommerce loaded.
 *
 * The blast radius is the money path: any order using a partner coupon reaching
 * `processing` would fatal mid-status-transition. The lesson is the same one
 * this project keeps relearning in new clothes — `php -l` proves a file parses,
 * not that it works, and a document saying something was fixed is not evidence.
 * A repo-wide "called but never defined" scan is now part of the gate.
 *
 * WHAT THE RULE SHOULD BE, WHICH IS NOT WHAT WAS THERE
 * ---------------------------------------------------
 * The removed rule was "largest discount wins, the rest are audit-only". Scope
 * §6's test case says something different: "an order with two coupons attributes
 * to BOTH without double-counting revenue." Single-winner means the second
 * partner is told nothing at all — their code was used and they hear silence,
 * which is worse for trust than the double-count it was avoiding.
 *
 * So revenue is APPORTIONED by discount share. Every partner gets a row and a
 * notification; the order value is stated in full because that is a fact about
 * the order; and `attributed_revenue` is each partner's share, summing to the
 * order's net revenue EXACTLY — to the paisa, by construction.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/partner-test.php without WordPress or a database.
 * ---------------------------------------------------------------------- */

/**
 * Split a total across weighted shares so the parts sum to the whole EXACTLY.
 *
 * Works in integer paise and settles the rounding remainder by largest
 * fractional part. Apportioning money with floats and `round()` per share leaks
 * or invents a paisa, and a ledger whose rows do not add up to the order is a
 * ledger nobody can reconcile — which is the entire reason for having one.
 *
 * @param array<string,float> $weights Keyed weights. Zero or negative weights share equally.
 * @param int                 $total   Amount in paise.
 * @return array<string,int>  Same keys, paise, summing to $total.
 */
function foodify_apportion( array $weights, int $total ): array {
	if ( ! $weights ) {
		return [];
	}
	$sum = 0.0;
	foreach ( $weights as $w ) {
		$sum += max( 0.0, (float) $w );
	}
	// No usable weights — an order where every coupon gave zero discount is a
	// real case (a free-shipping coupon). Split evenly rather than dividing by nil.
	if ( $sum <= 0.0 ) {
		$weights = array_fill_keys( array_keys( $weights ), 1.0 );
		$sum     = (float) count( $weights );
	}

	$out  = [];
	$frac = [];
	$used = 0;
	foreach ( $weights as $key => $w ) {
		$exact      = ( max( 0.0, (float) $w ) / $sum ) * $total;
		$floor      = (int) floor( $exact );
		$out[ $key ] = $floor;
		$frac[ $key ] = $exact - $floor;
		$used       += $floor;
	}

	// Largest remainder takes the leftover paise, one each, deterministically.
	$remainder = $total - $used;
	if ( 0 !== $remainder ) {
		$keys = array_keys( $frac );
		usort( $keys, static function ( $a, $b ) use ( $frac ): int {
			$cmp = $frac[ $b ] <=> $frac[ $a ];
			return 0 !== $cmp ? $cmp : strcmp( (string) $a, (string) $b );   // stable
		} );
		$step = $remainder > 0 ? 1 : -1;
		for ( $i = 0; $i !== $remainder; $i += $step ) {
			$out[ $keys[ abs( $i ) % count( $keys ) ] ] += $step;
		}
	}
	return $out;
}

/**
 * THE FUNCTION THAT WAS MISSING.
 *
 * Attributes an order's net revenue across the partner coupons on it.
 *
 * @param array<int,array{code:string,coupon_id:int,partner_id:int,discount:float}> $coupons
 * @param float $net_revenue Order revenue to attribute, in rupees.
 * @return array<int,array{code:string,coupon_id:int,partner_id:int,discount:float,attributed_revenue:float,share:float}>
 */
function foodify_attributed_coupons( array $coupons, float $net_revenue ): array {
	if ( ! $coupons ) {
		return [];
	}
	$weights = [];
	foreach ( $coupons as $i => $c ) {
		$weights[ (string) $i ] = (float) ( $c['discount'] ?? 0.0 );
	}
	$paise = foodify_apportion( $weights, (int) round( $net_revenue * 100 ) );

	$out = [];
	foreach ( $coupons as $i => $c ) {
		$amount              = (float) ( $paise[ (string) $i ] / 100 );
		$c['attributed_revenue'] = $amount;
		$c['share']              = 0.0 !== $net_revenue ? round( $amount / $net_revenue, 6 ) : 0.0;
		$out[]                   = $c;
	}
	return $out;
}

/** Commission, reporting-only in v1. Null percentage means none configured. */
function foodify_commission_amount( float $attributed, ?float $pct ): float {
	if ( null === $pct || $pct <= 0.0 ) {
		return 0.0;
	}
	return round( $attributed * ( $pct / 100 ), 2 );
}

/**
 * "Inventory": what sold and how much of it.
 *
 * This is the half of the client's actual sentence — "inventory & value" — that
 * the kit's email did not carry. It sent a single unit COUNT, which answers
 * "value" twice and "inventory" not at all.
 *
 * @param array<int,array{sku?:string,name?:string,qty?:int,total?:float}> $items
 * @return array<int,array{sku:string,name:string,qty:int,total:float}>
 */
function foodify_partner_line_items( array $items ): array {
	$out = [];
	foreach ( $items as $item ) {
		$qty = (int) ( $item['qty'] ?? 0 );
		if ( $qty <= 0 ) {
			continue;
		}
		$out[] = [
			'sku'   => trim( (string) ( $item['sku'] ?? '' ) ),
			'name'  => trim( (string) ( $item['name'] ?? '' ) ),
			'qty'   => $qty,
			'total' => round( (float) ( $item['total'] ?? 0.0 ), 2 ),
		];
	}
	return $out;
}

/**
 * Neutralise a CSV cell before it reaches a spreadsheet.
 *
 * A coupon code is text somebody typed. A code beginning `=`, `+`, `-`, `@` or a
 * control character is executed as a FORMULA when the file is opened in Excel or
 * Sheets — which is how a CSV export becomes a way to run something on the
 * finance team's laptop. Prefixing an apostrophe makes the cell literal text and
 * is invisible on screen.
 */
function foodify_csv_cell( $value ): string {
	$s = (string) $value;
	if ( '' === $s ) {
		return $s;
	}
	// The tab/CR/LF cases matter because they let a payload hide behind
	// whitespace that a reviewer eyeballing the file will not see.
	if ( preg_match( '/^[=+\-@\t\r\n]/', $s ) ) {
		return "'" . $s;
	}
	return $s;
}

/** Money, formatted the same way everywhere in this module. */
function foodify_partner_money( float $amount ): string {
	$sign = $amount < 0 ? '-' : '';
	return $sign . '₹' . number_format( abs( $amount ), 2, '.', ',' );
}

/**
 * The partner email body. Pure, so the PII rule below is testable.
 *
 * @param array{partner_name:string,code:string,order_number:string,order_date:string,
 *              line_items:array,order_total:float,attributed_revenue:float,discount:float,
 *              commission:?float,shared_with:int,month_label:string,mtd:array,
 *              site:string,portal_url:string,is_correction:bool} $c
 */
function foodify_partner_email_body( array $c ): string {
	$l   = [];
	$l[] = sprintf( 'Hi %s,', $c['partner_name'] );
	$l[] = '';
	$l[] = $c['is_correction']
		? sprintf( 'An order using your code %s has been refunded. Your totals below are updated.', $c['code'] )
		: sprintf( 'Someone just ordered using your code %s.', $c['code'] );
	$l[] = '';
	$l[] = sprintf( 'Order:   #%s', $c['order_number'] );
	$l[] = sprintf( 'Date:    %s', $c['order_date'] );
	$l[] = '';

	if ( $c['line_items'] ) {
		$l[] = '── What sold ──';
		foreach ( $c['line_items'] as $item ) {
			$label = '' !== $item['sku'] ? sprintf( '%s (%s)', $item['name'], $item['sku'] ) : $item['name'];
			$l[]   = sprintf( '  %d × %s — %s', $item['qty'], $label, foodify_partner_money( $item['total'] ) );
		}
		$l[] = '';
	}

	$l[] = sprintf( 'Order value:  %s', foodify_partner_money( $c['order_total'] ) );
	$l[] = sprintf( 'Discount:     %s', foodify_partner_money( $c['discount'] ) );

	// Only mention the split when there IS one. Telling a partner their share is
	// 100% of an order invites the question of why that needed saying.
	if ( $c['shared_with'] > 1 ) {
		$l[] = sprintf(
			'Attributed to your code:  %s  (this order also used %d other partner code%s)',
			foodify_partner_money( $c['attributed_revenue'] ),
			$c['shared_with'] - 1,
			2 === $c['shared_with'] ? '' : 's'
		);
	}
	if ( null !== $c['commission'] ) {
		$l[] = sprintf( 'Commission:   %s', foodify_partner_money( $c['commission'] ) );
	}

	$l[] = '';
	$l[] = sprintf( '── Your totals for %s ──', $c['month_label'] );
	$l[] = sprintf( 'Orders:       %d', (int) ( $c['mtd']['orders'] ?? 0 ) );
	$l[] = sprintf( 'Units:        %d', (int) ( $c['mtd']['units'] ?? 0 ) );
	$l[] = sprintf( 'Total value:  %s', foodify_partner_money( (float) ( $c['mtd']['revenue'] ?? 0.0 ) ) );
	$l[] = '';
	$l[] = sprintf( 'Full history: %s', $c['portal_url'] );
	$l[] = '';
	$l[] = sprintf( '— %s', $c['site'] );

	return implode( "\n", $l );
}

/**
 * Which pieces of buyer data leaked into a message.
 *
 * Scope §6: "No customer PII — no name, address, phone or email of the buyer.
 * That is a privacy line and a DPDP-Act-shaped one." It also lists "the email
 * contains zero customer PII" as a test case worth writing.
 *
 * This is that test, and it is ALSO a runtime guard. A test proves today's
 * template is clean; the guard is what stops tomorrow's well-meant "add the
 * customer's name so it feels personal" from shipping. Short values are ignored
 * — a two-letter name would match half the alphabet and make the guard useless
 * by crying wolf.
 *
 * @param array<string,string> $pii label => value
 * @return array<int,string>   Labels that appear in the body.
 */
function foodify_pii_in_text( string $body, array $pii ): array {
	$found = [];
	$hay   = strtolower( $body );
	foreach ( $pii as $label => $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( strlen( $value ) < 4 ) {
			continue;
		}
		if ( false !== strpos( $hay, $value ) ) {
			$found[] = (string) $label;
		}
	}
	return $found;
}

/* -------------------------------------------------------------------------
 * The attribution table. WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

const FOODIFY_ATTRIBUTION_DB_VERSION = '1';

function foodify_attribution_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'foodify_attribution';
}

/**
 * Create the table.
 *
 * UNIQUE (order_id, coupon_id) is the idempotency guarantee, and it lives in the
 * STORAGE rather than in an order-meta flag. A status flap, a double-fired hook
 * or two requests racing cannot produce two rows, whatever the calling code
 * believes about itself.
 */
function foodify_create_attribution_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = foodify_attribution_table();
	$collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		order_id BIGINT UNSIGNED NOT NULL,
		coupon_id BIGINT UNSIGNED NOT NULL,
		coupon_code VARCHAR(191) NOT NULL DEFAULT '',
		owner_email VARCHAR(191) NOT NULL DEFAULT '',
		order_total DECIMAL(12,2) NOT NULL DEFAULT 0,
		discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
		attributed_revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
		commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
		units INT NOT NULL DEFAULT 0,
		line_items_json LONGTEXT NULL,
		order_status VARCHAR(32) NOT NULL DEFAULT '',
		notified_at DATETIME NULL,
		reversed_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY order_coupon (order_id, coupon_id),
		KEY coupon_created (coupon_id, created_at),
		KEY owner_email (owner_email)
	) {$collate};" );

	update_option( 'foodify_attribution_db_version', FOODIFY_ATTRIBUTION_DB_VERSION, false );
}

add_action( 'after_switch_theme', 'foodify_create_attribution_table' );
add_action( 'init', static function (): void {
	if ( get_option( 'foodify_attribution_db_version' ) !== FOODIFY_ATTRIBUTION_DB_VERSION ) {
		foodify_create_attribution_table();
	}
}, 5 );

/**
 * Insert one attribution row. Returns false when the row already existed.
 *
 * The insert IS the idempotency check — no read-then-write, which is a race
 * whichever way you write it.
 */
function foodify_record_attribution( array $row ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table, by design (scope §6)
	$ok = $wpdb->query( $wpdb->prepare(
		'INSERT IGNORE INTO `' . foodify_attribution_table() . '`
		 (order_id, coupon_id, coupon_code, owner_email, order_total, discount_amount,
		  attributed_revenue, commission_amount, units, line_items_json, order_status,
		  notified_at, created_at)
		 VALUES (%d, %d, %s, %s, %f, %f, %f, %f, %d, %s, %s, %s, %s)',
		$row['order_id'], $row['coupon_id'], $row['coupon_code'], $row['owner_email'],
		$row['order_total'], $row['discount_amount'], $row['attributed_revenue'],
		$row['commission_amount'], $row['units'], $row['line_items_json'],
		$row['order_status'], $row['notified_at'], $row['created_at']
	) );
	return (bool) $ok;
}

/** Mark a row reversed. Never deleted — a reversal is accounting history. */
function foodify_reverse_attribution( int $order_id, int $coupon_id, string $when ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( $wpdb->prepare(
		'UPDATE `' . foodify_attribution_table() . '` SET reversed_at = %s WHERE order_id = %d AND coupon_id = %d AND reversed_at IS NULL',
		$when, $order_id, $coupon_id
	) );
}

/**
 * Per-coupon totals, derived from the ledger rather than from a counter.
 *
 * This is what the custom table buys. The counters it replaces could drift with
 * nothing to compare against — and the file header promised
 * `wp foodify coupons reconcile` as the fix for that drift. THAT COMMAND WAS
 * NEVER WRITTEN either. Derived totals cannot drift, so there is nothing to
 * reconcile and nothing to promise.
 *
 * @return array{orders:int,units:int,revenue:float,discount:float,commission:float,last_used:?string}
 */
function foodify_coupon_totals( int $coupon_id, string $month = '' ): array {
	global $wpdb;
	$where  = 'coupon_id = %d AND reversed_at IS NULL';
	$params = [ $coupon_id ];
	if ( '' !== $month ) {
		$where   .= ' AND created_at >= %s AND created_at < %s';
		$params[] = $month . '-01 00:00:00';
		$params[] = gmdate( 'Y-m-01 00:00:00', strtotime( $month . '-01 +1 month' ) );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT COUNT(*) AS orders, COALESCE(SUM(units),0) AS units,
		        COALESCE(SUM(attributed_revenue),0) AS revenue,
		        COALESCE(SUM(discount_amount),0) AS discount,
		        COALESCE(SUM(commission_amount),0) AS commission,
		        MAX(created_at) AS last_used
		   FROM `' . foodify_attribution_table() . '` WHERE ' . $where,
		...$params
	), ARRAY_A );

	return [
		'orders'     => (int) ( $row['orders'] ?? 0 ),
		'units'      => (int) ( $row['units'] ?? 0 ),
		'revenue'    => (float) ( $row['revenue'] ?? 0 ),
		'discount'   => (float) ( $row['discount'] ?? 0 ),
		'commission' => (float) ( $row['commission'] ?? 0 ),
		'last_used'  => $row['last_used'] ?? null,
	];
}
