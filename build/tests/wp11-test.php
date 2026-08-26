<?php
/**
 * Tests the pure halves of inc/gst.php and inc/fulfilment.php.
 *
 * Two numbers here are money in a way nothing else in this build is:
 *
 *  1. CGST + SGST MUST SUM TO THE TAX. Halving a rate and rounding each half
 *     independently drifts by a paisa on some orders and not others. An invoice
 *     whose parts do not sum to its total is not compliant, and it surfaces
 *     during an assessment rather than during testing.
 *
 *  2. THE COD AMOUNT IS WHAT A DELIVERY AGENT COLLECTS AT THE DOOR. Non-zero on
 *     a prepaid order and the customer is charged twice. Neither shows up in
 *     testing, because both need a real delivery to surface.
 *
 *   php tests/wp11-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../theme/foodify/inc/gst.php';
require __DIR__ . '/../theme/foodify/inc/fulfilment.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
function near( float $a, float $b ): bool { return abs( $a - $b ) < 0.0001; }

echo "── place of supply ──\n";

check( 'UP delivery from a UP seller is intra-state',  true  === foodify_is_intra_state( 'UP', 'UP' ) );
check( 'Maharashtra delivery is inter-state',          false === foodify_is_intra_state( 'MH', 'MH' ) );
check( 'case does not decide the tax',                 true  === foodify_is_intra_state( 'up' ) );

// For goods to an unregistered buyer the place of supply is where they are
// DELIVERED. Billing in Delhi and delivering to Noida owes CGST+SGST; charging
// IGST is invisible to the customer, because the total is identical either way.
// Only the return is wrong.
check( 'DELIVERY state decides, not billing',
	true === foodify_is_intra_state( 'UP', 'DL' ) && false === foodify_is_intra_state( 'DL', 'UP' ) );
check( 'with no delivery state, billing is the fallback', true === foodify_is_intra_state( '', 'UP' ) );
check( 'with neither, intra-state is never ASSERTED',     false === foodify_is_intra_state( '', '' ) );

echo "── the split reconciles, to the paisa ──\n";

$s = foodify_gst_split( 210.00, 5.0, true );
check( 'taxable + tax = gross',        near( $s['taxable'] + $s['tax'], 210.00 ) );
check( 'CGST + SGST = tax',            near( $s['cgst'] + $s['sgst'], $s['tax'] ) );
check( 'intra-state charges no IGST',  near( $s['igst'], 0.0 ) );
check( '5% of a ₹210 inclusive price is ₹10.00', near( $s['tax'], 10.00 ) );

$s = foodify_gst_split( 210.00, 5.0, false );
check( 'inter-state is all IGST',      near( $s['igst'], $s['tax'] ) && near( $s['cgst'], 0.0 ) );
check( 'and the tax is the same amount', near( $s['tax'], 10.00 ) );

// THE ROUNDING CASE. An odd number of paise of tax cannot be halved evenly, and
// rounding each half independently makes them sum to a paisa more than the tax.
$odd = 0;
for ( $p = 1; $p <= 400; $p++ ) {
	$g = $p * 1.37;                       // prices that land on awkward paise
	foreach ( [ 5.0, 12.0, 18.0 ] as $rate ) {
		$x = foodify_gst_split( $g, $rate, true );
		if ( ! near( $x['cgst'] + $x['sgst'], $x['tax'] ) ) { $odd++; }
		if ( ! near( $x['taxable'] + $x['tax'], $x['gross'] ) ) { $odd++; }
	}
}
check( 'across 1,200 awkward prices the parts ALWAYS sum to the whole', 0 === $odd );

check( 'a zero rate leaves the whole amount taxable',
	near( foodify_gst_split( 100.0, 0.0, true )['taxable'], 100.0 ) );
check( 'a zero amount does not divide by anything', near( foodify_gst_split( 0.0, 5.0, true )['tax'], 0.0 ) );

echo "── an invoice states tax per RATE ──\n";

$sum = foodify_gst_summary( [
	foodify_gst_split( 210.00, 5.0, true ),
	foodify_gst_split( 185.00, 5.0, true ),
	foodify_gst_split( 375.00, 12.0, true ),
] );
check( 'a mixed basket produces two rate lines, not one blend', 2 === count( $sum['by_rate'] ) );
check( 'rate lines are ordered', [ '5.00', '12.00' ] === array_keys( $sum['by_rate'] ) );
check( 'the 5% line carries both ₹210 and ₹185', near( $sum['by_rate']['5.00']['gross'], 395.00 ) );
check( 'the totals still reconcile', near( $sum['taxable'] + $sum['tax'], $sum['gross'] ) );
check( 'and CGST + SGST still equals the tax', near( $sum['cgst'] + $sum['sgst'], $sum['tax'] ) );

echo "── what a document may be called ──\n";

$full = [
	'supplier_name' => 'AVAC Ventures', 'supplier_address' => 'Noida 201304', 'supplier_gstin' => '09AAAAA0000A1Z5',
	'invoice_number' => 'FDY-1194', 'invoice_date' => '22 Aug 2026',
	'buyer_name' => 'Priya S', 'place_of_supply' => 'Uttar Pradesh (09)',
	'hsn' => '2106', 'description' => 'Express Dal Fry', 'quantity' => '2',
	'taxable_value' => '400.00', 'tax_rate' => '5', 'tax_amount' => '20.00', 'total' => '420.00',
];
check( 'a complete document is a Tax Invoice', 'Tax Invoice' === foodify_invoice_title( $full )['title'] );

// Printing "Tax Invoice" over a document missing its mandatory particulars does
// not make it one — it makes the shop's own records claim something the document
// cannot support.
foreach ( [ 'supplier_gstin', 'place_of_supply', 'hsn', 'invoice_number' ] as $key ) {
	$t = foodify_invoice_title( array_merge( $full, [ $key => '' ] ) );
	check( "without $key it is NOT called a tax invoice", 'Order summary — not a tax invoice' === $t['title'] );
	check( "  and $key is named as the reason", in_array( $key, $t['missing'], true ) );
}

echo "── the COD amount ──\n";

// THE ONE THAT MATTERS. A non-zero COD amount on a prepaid order means the agent
// collects money the customer has already paid.
check( 'a PREPAID order collects NOTHING at the door', 0.0 === foodify_cod_amount( 'razorpay', 558.00 ) );
check( 'a COD order collects the order total',         558.00 === foodify_cod_amount( 'cod', 558.00 ) );
check( 'an unknown method is treated as prepaid, not COD', 0.0 === foodify_cod_amount( '', 558.00 ) );
check( 'a second cash gateway must be declared to collect',
	0.0 === foodify_cod_amount( 'cod_delhivery', 558.00 )
	&& 558.00 === foodify_cod_amount( 'cod_delhivery', 558.00, [ 'cod', 'cod_delhivery' ] ) );
check( 'a negative total never becomes a negative collection', 0.0 === foodify_cod_amount( 'cod', -50.0 ) );

echo "── weight is refused, not guessed ──\n";

check( 'weights sum with quantity, plus packaging',
	near( (float) foodify_parcel_weight( [ [ 'qty' => 2, 'weight' => 0.08 ], [ 'qty' => 1, 'weight' => 0.2 ] ] ), 0.41 ) );
// Couriers bill on the higher of actual and volumetric weight. A default of
// 0.5 kg on a parcel that is really 1.2 kg is a per-parcel loss nobody reconciles.
check( 'ONE unknown line makes the parcel weight unknown',
	null === foodify_parcel_weight( [ [ 'qty' => 1, 'weight' => 0.08 ], [ 'qty' => 1, 'weight' => null ] ] ) );
check( 'a zero weight is unknown, not weightless',
	null === foodify_parcel_weight( [ [ 'qty' => 1, 'weight' => 0.0 ] ] ) );
check( 'no items at all is unknown', null === foodify_parcel_weight( [] ) );

echo "── the manifest ──\n";

function order( array $over = [] ): array {
	return array_merge( [
		'order_number' => '1194', 'name' => 'Priya Sharma', 'phone' => '9876543210',
		'address_1' => 'B-402, Sunrise Apartments', 'address_2' => 'Sector 62',
		'city' => 'Noida', 'state' => 'UP', 'postcode' => '201309',
		'pickup_postcode' => '201304', 'payment_method' => 'cod', 'order_total' => 558.00,
		'items' => [ [ 'sku' => 'FDY-DAL-01', 'name' => 'Express Dal Fry', 'qty' => 2, 'weight' => 0.08 ] ],
	], $over );
}

$m = foodify_courier_payload( order() );
check( 'a complete COD order is dispatchable', $m['ready'] && [] === $m['missing'] );
check( 'and marked COD with the amount', 'COD' === $m['payload']['payment_mode'] && 558.00 === $m['payload']['cod_amount'] );

$m = foodify_courier_payload( order( [ 'payment_method' => 'razorpay' ] ) );
check( 'a PREPAID order is marked Prepaid with ZERO to collect',
	'Prepaid' === $m['payload']['payment_mode'] && 0.0 === $m['payload']['cod_amount'] );
check( 'and still carries the order total for the courier\'s records', 558.00 === $m['payload']['order_total'] );

$m = foodify_courier_payload( order( [ 'phone' => '1234567890' ] ) );
check( 'an uncallable number blocks dispatch', ! $m['ready'] && in_array( 'phone', $m['missing'], true ) );
$m = foodify_courier_payload( order( [ 'items' => [ [ 'sku' => 'X', 'name' => 'Y', 'qty' => 1, 'weight' => null ] ] ] ) );
check( 'an unknown weight blocks dispatch', in_array( 'weight_kg', $m['missing'], true ) );
$m = foodify_courier_payload( order( [ 'pickup_postcode' => '' ] ) );
check( 'no pickup PIN blocks dispatch', in_array( 'pickup_postcode', $m['missing'], true ) );
$m = foodify_courier_payload( order( [ 'postcode' => '20130' ] ) );
check( 'a five-digit PIN blocks dispatch', in_array( 'postcode', $m['missing'], true ) );
check( 'the payload never carries a partial address silently',
	'B-402, Sunrise Apartments' === foodify_courier_payload( order() )['payload']['address_1'] );

echo "── serviceability ──\n";

// An EMPTY allowlist means EVERYWHERE. Reading it as a deny-all refuses every
// order on the store the day it goes live.
check( 'an empty allowlist serves everywhere', true === foodify_pin_serviceable( '201309' ) );
check( 'an allowlist restricts to itself',
	true === foodify_pin_serviceable( '201309', [ '201309' ] ) && false === foodify_pin_serviceable( '400001', [ '201309' ] ) );
check( 'a blocklist wins over an allowlist',
	false === foodify_pin_serviceable( '201309', [ '201309' ], [ '201309' ] ) );
check( 'a malformed PIN is never serviceable', false === foodify_pin_serviceable( '2013' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
