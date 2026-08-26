<?php
/**
 * Tests the pure half of inc/partner-ledger.php — no WordPress, no database.
 *
 * Scope §6 lists the test cases it wants written. The two that carry real
 * consequence are here, and both were untestable before this package because the
 * code they describe either did not exist or lived inside a WooCommerce call:
 *
 *  1. "AN ORDER WITH TWO COUPONS ATTRIBUTES TO BOTH WITHOUT DOUBLE-COUNTING
 *     REVENUE." The function that was supposed to do this — foodify_attributed_
 *     coupons() — was called twice and DEFINED NOWHERE, so this is the first
 *     time the rule has existed at all.
 *
 *  2. "THE EMAIL CONTAINS ZERO CUSTOMER PII." Scope calls it "a privacy line and
 *     a DPDP-Act-shaped one". Asserting it needs the body to be a pure value, so
 *     it is.
 *
 *   php tests/partner-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../theme/foodify/inc/partner-ledger.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

echo "── apportionment: the parts must equal the whole ──\n";

$a = foodify_apportion( [ 'x' => 1.0 ], 62000 );
check( 'one share takes everything', [ 'x' => 62000 ] === $a );

// The classic: a total that does not divide. Rounding each share independently
// leaks or invents a paisa, and a ledger whose rows do not add up to the order
// is a ledger nobody can reconcile — which is the whole point of having one.
$a = foodify_apportion( [ 'x' => 1.0, 'y' => 1.0, 'z' => 1.0 ], 100 );
check( '₹1.00 across three sums to exactly 100p', 100 === array_sum( $a ) );
check( 'and the remainder goes to one of them, not nowhere', [ 34, 33, 33 ] === array_values( $a ) );

$a = foodify_apportion( [ 'big' => 90.0, 'small' => 10.0 ], 62000 );
check( 'weights are respected', 55800 === $a['big'] && 6200 === $a['small'] );
check( 'and still sum exactly', 62000 === array_sum( $a ) );

// A free-shipping coupon gives no discount. Dividing by a zero weight sum would
// be a fatal; treating it as "gets nothing" would silently drop a real partner.
$a = foodify_apportion( [ 'x' => 0.0, 'y' => 0.0 ], 50000 );
check( 'all-zero weights split evenly rather than dividing by nil', [ 25000, 25000 ] === array_values( $a ) );
check( 'and still sum exactly', 50000 === array_sum( $a ) );

$a = foodify_apportion( [ 'x' => 1.0, 'y' => 2.0 ], -30000 );
check( 'a negative total (a reversal) apportions too', -30000 === array_sum( $a ) );

check( 'no coupons -> nothing', [] === foodify_apportion( [], 1000 ) );

// Determinism matters: two runs of the same order must credit the same partner
// with the same paisa, or a reconciliation looks like a discrepancy.
$one = foodify_apportion( [ 'a' => 1.0, 'b' => 1.0, 'c' => 1.0 ], 1001 );
$two = foodify_apportion( [ 'a' => 1.0, 'b' => 1.0, 'c' => 1.0 ], 1001 );
check( 'the split is deterministic', $one === $two );

echo "── the function that was called and never existed ──\n";

$coupons = [
	[ 'code' => 'NALIN10',  'coupon_id' => 11, 'partner_id' => 3, 'discount' => 62.00 ],
	[ 'code' => 'PRIYA50',  'coupon_id' => 12, 'partner_id' => 4, 'discount' => 38.00 ],
];
$r = foodify_attributed_coupons( $coupons, 558.00 );

check( 'BOTH partners are attributed, not just the largest', 2 === count( $r ) );
check( 'nobody is silently dropped',
	'NALIN10' === $r[0]['code'] && 'PRIYA50' === $r[1]['code'] );

// The double-counting the single-winner rule was avoiding: if each partner were
// credited the full ₹558, the month's "revenue" would read ₹1,116 on a ₹558 order.
$total = $r[0]['attributed_revenue'] + $r[1]['attributed_revenue'];
check( 'revenue is NOT double-counted — the shares sum to the order', abs( $total - 558.00 ) < 0.005 );
check( 'the larger discount gets the larger share',
	$r[0]['attributed_revenue'] > $r[1]['attributed_revenue'] );

$solo = foodify_attributed_coupons( [ $coupons[0] ], 558.00 );
check( 'a single coupon is attributed the whole order', abs( $solo[0]['attributed_revenue'] - 558.00 ) < 0.005 );
check( 'and its share is 1.0', abs( $solo[0]['share'] - 1.0 ) < 0.000001 );

check( 'no partner coupons -> nothing to attribute', [] === foodify_attributed_coupons( [], 558.00 ) );

// Zero-revenue orders exist — a 100% coupon, or a full-value gift code.
$free = foodify_attributed_coupons( $coupons, 0.0 );
check( 'a zero-revenue order does not divide by zero', 2 === count( $free ) && 0.0 === $free[0]['attributed_revenue'] );

echo "── commission ──\n";

check( '10% of ₹558 is ₹55.80', 55.80 === foodify_commission_amount( 558.00, 10.0 ) );
check( 'no percentage configured -> nothing owed', 0.0 === foodify_commission_amount( 558.00, null ) );
check( 'a zero percentage -> nothing owed',        0.0 === foodify_commission_amount( 558.00, 0.0 ) );
check( 'commission rounds to paise',               18.60 === foodify_commission_amount( 558.00, 3.3333 ) );

echo "── inventory: what sold, not just how much ──\n";

$items = foodify_partner_line_items( [
	[ 'sku' => 'FDY-DAL-01', 'name' => 'Express Dal Fry', 'qty' => 2, 'total' => 420.0 ],
	[ 'sku' => '',           'name' => 'Masala Chai',     'qty' => 1, 'total' => 200.0 ],
	[ 'sku' => 'FDY-GHOST',  'name' => 'Removed line',    'qty' => 0, 'total' => 0.0 ],
] );
check( 'zero-quantity lines are dropped', 2 === count( $items ) );
check( 'quantity and line value survive', 2 === $items[0]['qty'] && 420.0 === $items[0]['total'] );
check( 'a product with no SKU is still listed', 'Masala Chai' === $items[1]['name'] );

echo "── the partner email ──\n";

function ctx( array $over = [] ): array {
	return array_merge( [
		'partner_name' => 'Nalin Agarwal',
		'code' => 'NALIN10',
		'order_number' => '1194',
		'order_date' => '22 Aug 2026',
		'line_items' => foodify_partner_line_items( [
			[ 'sku' => 'FDY-DAL-01', 'name' => 'Express Dal Fry', 'qty' => 2, 'total' => 420.0 ],
			[ 'sku' => 'FDY-CHAI-1', 'name' => 'Masala Chai',     'qty' => 1, 'total' => 200.0 ],
		] ),
		'order_total' => 558.0,
		'attributed_revenue' => 558.0,
		'discount' => 62.0,
		'commission' => null,
		'shared_with' => 1,
		'month_label' => 'August 2026',
		'mtd' => [ 'orders' => 4, 'units' => 11, 'revenue' => 2240.0 ],
		'site' => 'The Foodify Company',
		'portal_url' => 'https://letsfoodify.com/my-account/partner/',
		'is_correction' => false,
	], $over );
}

$body = foodify_partner_email_body( ctx() );
check( 'the email names what sold',      false !== strpos( $body, 'Express Dal Fry' ) );
check( 'with the SKU',                   false !== strpos( $body, 'FDY-DAL-01' ) );
check( 'and the quantity',               false !== strpos( $body, '2 ×' ) );
check( 'and the line value',             false !== strpos( $body, '420.00' ) );
check( 'and the order value',            false !== strpos( $body, '558.00' ) );
check( 'and the month-to-date totals',   false !== strpos( $body, '2,240.00' ) );

// Telling a partner their share is 100% of an order invites the question of why
// that needed saying.
check( 'a sole partner is not told about a "split"', false === strpos( $body, 'Attributed to your code' ) );
$shared = foodify_partner_email_body( ctx( [ 'shared_with' => 2, 'attributed_revenue' => 346.0 ] ) );
check( 'a shared order says so, and by how much',
	false !== strpos( $shared, 'Attributed to your code' ) && false !== strpos( $shared, '346.00' ) );
check( 'and says how many other codes, in the singular',
	false !== strpos( $shared, '1 other partner code' ) );

check( 'commission appears only when configured',
	false === strpos( $body, 'Commission' )
	&& false !== strpos( foodify_partner_email_body( ctx( [ 'commission' => 55.80 ] ) ), '55.80' ) );

$refund = foodify_partner_email_body( ctx( [ 'is_correction' => true ] ) );
check( 'a correction says it is a refund, not a sale', false !== stripos( $refund, 'refunded' ) );

echo "── the privacy line ──\n";

// Scope §6: "No customer PII — no name, address, phone or email of the buyer."
$buyer = [
	'buyer name'     => 'Priya Sharma',
	'buyer email'    => 'priya@example.org',
	'buyer phone'    => '9876543210',
	'buyer address'  => 'B-402, Sunrise Apartments',
	'buyer postcode' => '201309',
];
check( 'THE STATED TEST CASE: the email contains zero customer PII',
	[] === foodify_pii_in_text( $body, $buyer ) );
check( 'a correction email carries none either',
	[] === foodify_pii_in_text( $refund, $buyer ) );

// Proving the check works, not merely that today's template is clean. Without
// this the assertion above passes just as happily against a broken detector.
$leaky = $body . "\nDeliver to: Priya Sharma, B-402, Sunrise Apartments, 9876543210";
$found = foodify_pii_in_text( $leaky, $buyer );
check( 'a leaked name IS caught',    in_array( 'buyer name', $found, true ) );
check( 'a leaked address IS caught', in_array( 'buyer address', $found, true ) );
check( 'a leaked phone IS caught',   in_array( 'buyer phone', $found, true ) );

// A two-letter name would match half the alphabet and make the guard useless by
// crying wolf, which is how a guard gets switched off.
check( 'a very short value is not matched blindly',
	[] === foodify_pii_in_text( 'Order value: ₹558.00', [ 'buyer name' => 'Al' ] ) );
check( 'matching ignores case', [ 'buyer name' ] === foodify_pii_in_text( 'From PRIYA SHARMA', [ 'buyer name' => 'Priya Sharma' ] ) );

echo "── the CSV export cannot execute ──\n";

// A coupon code is text somebody typed. A cell beginning `=` runs as a formula
// when the finance team opens the export in Excel.
check( 'a formula is neutralised',      "'=1+1" === foodify_csv_cell( '=1+1' ) );
check( 'so is a leading plus',          "'+CMD" === foodify_csv_cell( '+CMD' ) );
check( 'so is a leading minus',         "'-2+3" === foodify_csv_cell( '-2+3' ) );
check( 'so is a leading at-sign',       "'@SUM" === foodify_csv_cell( '@SUM' ) );
check( 'and a payload hiding behind a tab', "'\t=1+1" === foodify_csv_cell( "\t=1+1" ) );
check( 'an ordinary code is untouched', 'NALIN10' === foodify_csv_cell( 'NALIN10' ) );
check( 'an empty cell stays empty',     '' === foodify_csv_cell( '' ) );
check( 'a negative NUMBER is quoted too — correctness beats tidiness here',
	"'-62.00" === foodify_csv_cell( '-62.00' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
