<?php
/**
 * WP-11 — GST: the split, and the invoice's mandatory fields.
 *
 * WHAT THIS FILE DOES NOT DO, DELIBERATELY
 * ----------------------------------------
 * It does not decide a GST RATE or an HSN CODE for anything. Scope §12 excludes
 * "FSSAI/GST filing or consulting — display duties only", and a wrong rate on a
 * food product is the client's tax liability, not a styling bug. Rates and HSN
 * codes are per-product data their CA supplies; everything here is rate-agnostic
 * arithmetic and structure.
 *
 * WHAT IT DOES DO
 * ---------------
 * Two things the code genuinely owns:
 *
 * 1. THE SPLIT. An intra-state supply is CGST + SGST, each half the rate. An
 *    inter-state one is IGST at the full rate. Which applies depends on the
 *    PLACE OF SUPPLY, and for goods sold to an unregistered person that is the
 *    delivery address — not the billing address, which is the one most carts
 *    reach for first.
 *
 * 2. THE ROUNDING. Halving a rate and rounding each half independently makes
 *    CGST + SGST disagree with the total tax by a paisa, on some orders and not
 *    others. An invoice whose parts do not sum to its total is not a compliant
 *    invoice, and it is the kind of defect that surfaces during an assessment
 *    rather than during testing. So the split works in integer paise and the
 *    second half is the remainder, never a second rounding.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/** Where the goods ship FROM. Everything else is inter-state relative to this. */
const FOODIFY_SELLER_STATE = 'UP';

/* -------------------------------------------------------------------------
 * Pure — tested in tests/gst-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * Is this an intra-state supply?
 *
 * Goods to an unregistered buyer: place of supply is where they are DELIVERED.
 * Falling back to the billing state is how a Delhi-billed, Noida-delivered order
 * gets charged IGST when it owes CGST+SGST — and the customer never notices,
 * because the total is identical either way. Only the return is wrong.
 */
function foodify_is_intra_state( string $delivery_state, string $billing_state = '', string $seller = FOODIFY_SELLER_STATE ): bool {
	$state = strtoupper( trim( $delivery_state ) );
	if ( '' === $state ) {
		$state = strtoupper( trim( $billing_state ) );   // no delivery address known yet
	}
	if ( '' === $state ) {
		return false;   // unknown place of supply: never ASSERT intra-state
	}
	return $state === strtoupper( trim( $seller ) );
}

/**
 * Split a tax-inclusive amount into its taxable value and its GST components.
 *
 * All figures in rupees; the arithmetic runs in paise so the parts reconcile.
 *
 * @param float $gross Amount the customer pays, tax included.
 * @param float $rate  Total GST rate as a percentage, e.g. 5.0.
 * @return array{gross:float,taxable:float,tax:float,cgst:float,sgst:float,igst:float,rate:float}
 */
function foodify_gst_split( float $gross, float $rate, bool $intra ): array {
	$gross_p = (int) round( $gross * 100 );

	if ( $rate <= 0.0 || 0 === $gross_p ) {
		return [
			'gross' => $gross_p / 100, 'taxable' => $gross_p / 100, 'tax' => 0.0,
			'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'rate' => max( 0.0, $rate ),
		];
	}

	// Tax-INCLUSIVE: the tax is the part of the gross above the taxable value.
	// Deriving the taxable value first and subtracting guarantees the two always
	// add back up to the gross, whatever the rate does to the decimals.
	$taxable_p = (int) round( $gross_p * 100 / ( 100 + $rate ) );
	$tax_p     = $gross_p - $taxable_p;

	if ( $intra ) {
		// THE ROUNDING THAT MATTERS. Half the rate, rounded twice, drifts. Round
		// CGST once and give SGST the remainder, so the two always sum to the tax.
		$cgst_p = intdiv( $tax_p, 2 );
		$sgst_p = $tax_p - $cgst_p;
		$igst_p = 0;
	} else {
		$cgst_p = $sgst_p = 0;
		$igst_p = $tax_p;
	}

	return [
		'gross'   => $gross_p / 100,
		'taxable' => $taxable_p / 100,
		'tax'     => $tax_p / 100,
		'cgst'    => $cgst_p / 100,
		'sgst'    => $sgst_p / 100,
		'igst'    => $igst_p / 100,
		'rate'    => $rate,
	];
}

/**
 * Sum line splits into an invoice summary, grouped by rate.
 *
 * Grouped because a compliant invoice states tax per RATE, and a basket mixing
 * 5% and 12% items has two lines, not one blended one.
 *
 * @param array<int,array> $splits
 * @return array{by_rate:array<string,array>,gross:float,taxable:float,tax:float,cgst:float,sgst:float,igst:float}
 */
function foodify_gst_summary( array $splits ): array {
	$by_rate = [];
	$total   = [ 'gross' => 0, 'taxable' => 0, 'tax' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0 ];

	foreach ( $splits as $s ) {
		$key = number_format( (float) $s['rate'], 2, '.', '' );
		if ( ! isset( $by_rate[ $key ] ) ) {
			$by_rate[ $key ] = [ 'rate' => (float) $s['rate'], 'gross' => 0, 'taxable' => 0, 'tax' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0 ];
		}
		foreach ( [ 'gross', 'taxable', 'tax', 'cgst', 'sgst', 'igst' ] as $f ) {
			// Accumulate in paise. Adding rupee floats is how a hundred-line
			// invoice ends up a paisa out with nothing to point at.
			$by_rate[ $key ][ $f ] += (int) round( (float) $s[ $f ] * 100 );
			$total[ $f ]           += (int) round( (float) $s[ $f ] * 100 );
		}
	}

	foreach ( $by_rate as $key => $row ) {
		foreach ( [ 'gross', 'taxable', 'tax', 'cgst', 'sgst', 'igst' ] as $f ) {
			$by_rate[ $key ][ $f ] = $row[ $f ] / 100;
		}
	}
	ksort( $by_rate, SORT_NUMERIC );

	$out = [ 'by_rate' => $by_rate ];
	foreach ( $total as $f => $paise ) {
		$out[ $f ] = $paise / 100;
	}
	return $out;
}

/**
 * Everything a tax invoice must carry, and whether this order carries it.
 *
 * @param array<string,string> $v
 * @return array<int,string> Missing field keys. Empty means it may be called a
 *                           tax invoice.
 */
function foodify_invoice_missing( array $v ): array {
	$required = [
		'supplier_name', 'supplier_address', 'supplier_gstin',
		'invoice_number', 'invoice_date',
		'buyer_name', 'place_of_supply',
		'hsn', 'description', 'quantity', 'taxable_value', 'tax_rate', 'tax_amount', 'total',
	];
	$missing = [];
	foreach ( $required as $key ) {
		if ( '' === trim( (string) ( $v[ $key ] ?? '' ) ) ) {
			$missing[] = $key;
		}
	}
	return $missing;
}

/**
 * What this document may honestly be called.
 *
 * A "Tax Invoice" is a specific thing with a specific set of mandatory
 * particulars. A document missing the supplier's GSTIN or the place of supply is
 * NOT one, and printing the words over it does not make it one — it just makes
 * the store's own records claim something the document cannot support.
 *
 * @return array{title:string,valid:bool,missing:array<int,string>}
 */
function foodify_invoice_title( array $values ): array {
	$missing = foodify_invoice_missing( $values );
	return [
		'title'   => $missing ? 'Order summary — not a tax invoice' : 'Tax Invoice',
		'valid'   => ! $missing,
		'missing' => $missing,
	];
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** The store's own GST identity. Ships EMPTY — the client supplies the GSTIN. */
function foodify_gst_profile(): array {
	$business = function_exists( 'foodify_business_profile' ) ? foodify_business_profile() : [];
	return (array) apply_filters( 'foodify_gst_profile', [
		'gstin'        => '',   // client to supply. Until then no document says "Tax Invoice".
		'seller_state' => FOODIFY_SELLER_STATE,
		'legal_name'   => (string) ( $business['legal_name'] ?? '' ),
		'address'      => trim( implode( ', ', array_filter( [
			(string) ( $business['street'] ?? '' ),
			(string) ( $business['locality'] ?? '' ),
			(string) ( $business['postal'] ?? '' ),
		] ) ) ),
	] );
}

/** Per-product HSN and rate. No defaults — an unset rate is unset, not 0%. */
function foodify_product_hsn( WC_Product $product ): string {
	return (string) $product->get_meta( '_foodify_hsn' );
}

function foodify_product_gst_rate( WC_Product $product ): ?float {
	$raw = $product->get_meta( '_foodify_gst_rate' );
	return ( '' === $raw || null === $raw ) ? null : (float) $raw;
}

/** Line-by-line split for an order. */
function foodify_order_gst_lines( WC_Order $order ): array {
	$intra = foodify_is_intra_state(
		(string) $order->get_shipping_state(),
		(string) $order->get_billing_state(),
		(string) foodify_gst_profile()['seller_state']
	);

	$lines = [];
	foreach ( $order->get_items() as $item ) {
		$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
		$rate    = $product ? foodify_product_gst_rate( $product ) : null;

		$lines[] = [
			'name'  => (string) $item->get_name(),
			'hsn'   => $product ? foodify_product_hsn( $product ) : '',
			'qty'   => (int) $item->get_quantity(),
			// A product with no rate set contributes ZERO tax and is reported as
			// rate-less, rather than being quietly taxed at someone's guess.
			'rate_known' => null !== $rate,
			'split' => foodify_gst_split( (float) $item->get_total() + (float) $item->get_total_tax(), (float) ( $rate ?? 0.0 ), $intra ),
		];
	}
	return [ 'intra' => $intra, 'lines' => $lines ];
}

/**
 * Say so in the admin when the store cannot issue a tax invoice at all.
 *
 * A missing GSTIN or a product with no HSN is not something to discover from a
 * customer asking for an invoice they are entitled to.
 */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'toplevel_page_foodify-today' !== $screen->id ) {
		return;
	}

	$gaps = [];
	if ( '' === trim( (string) foodify_gst_profile()['gstin'] ) ) {
		$gaps[] = __( 'the store GSTIN is not set', 'foodify' );
	}
	if ( function_exists( 'wc_get_products' ) ) {
		$no_rate = 0;
		foreach ( (array) wc_get_products( [ 'limit' => 100, 'status' => 'publish', 'return' => 'objects' ] ) as $p ) {
			if ( null === foodify_product_gst_rate( $p ) || '' === foodify_product_hsn( $p ) ) {
				$no_rate++;
			}
		}
		if ( $no_rate ) {
			/* translators: %d: number of products */
			$gaps[] = sprintf( _n( '%d product has no HSN code or GST rate', '%d products have no HSN code or GST rate', $no_rate, 'foodify' ), $no_rate );
		}
	}
	if ( ! $gaps ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s. %3$s</p></div>',
		esc_html__( 'Foodify GST:', 'foodify' ),
		esc_html( implode( ', and ', $gaps ) ),
		esc_html__( 'Until these are filled in, order documents are titled "Order summary — not a tax invoice", which is what they are. Rates and HSN codes come from your accountant, not from this site.', 'foodify' )
	);
} );
