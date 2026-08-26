<?php
/**
 * Tests the pure halves of inc/roles.php and inc/admin-dashboard.php.
 *
 * The two failure modes worth naming up front:
 *
 *  1. A ROLE THAT IS TIGHT IN CODE AND LOOSE IN THE DATABASE. `add_role()` is a
 *     no-op when the role already exists, so tightening a capability, testing it
 *     on a fresh install and deploying changes NOTHING on a site where the role
 *     was created earlier. Every code review agrees with the code; the database
 *     disagrees silently.
 *
 *  2. A DASHBOARD THAT REPORTS A NUMBER NOBODY MEASURED. "No orders today" and
 *     "the query did not run" both render as 0 unless kept apart — and a screen
 *     that quietly says nothing is wrong when it cannot see is worse than a
 *     blank one.
 *
 *   php tests/admin-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../theme/foodify/inc/roles.php';
require __DIR__ . '/../theme/foodify/inc/admin-dashboard.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

echo "── what Shop Staff may do ──\n";

$caps = foodify_shop_staff_caps();
foreach ( [ 'read', 'read_shop_orders', 'edit_shop_orders', 'edit_others_shop_orders' ] as $need ) {
	check( "can $need — they have to work orders", ! empty( $caps[ $need ] ) );
}
check( 'can adjust stock through a capability of its own', ! empty( $caps[ FOODIFY_CAP_STOCK ] ) );

echo "── what Shop Staff must NEVER do ──\n";

// Enumerated one by one, not asserted as "no admin caps". "The role does not
// have admin" is an absence claim, and absence claims pass without being checked.
$must_not = [
	'manage_woocommerce'       => 'reach store settings — and the Coupon Performance screen, which lists partner email addresses',
	'view_woocommerce_reports' => 'see revenue; packing a box does not need it',
	'install_plugins'          => 'execute code on the server',
	'activate_plugins'         => 'execute code on the server',
	'edit_themes'              => 'execute code on the server',
	'edit_users'               => "touch other people's accounts",
	'promote_users'            => 'promote themselves',
	'list_users'               => 'enumerate the customer list',
	'export'                   => 'take the whole database out',
	'delete_shop_orders'       => 'destroy the record the ledger and the GST invoice depend on',
	'edit_products'            => 'change what things sell for',
	'unfiltered_html'          => 'inject markup',
];
foreach ( $must_not as $cap => $why ) {
	check( "cannot $cap — would let them $why", empty( $caps[ $cap ] ) );
}

check( 'the granted set holds NO forbidden capability at all',
	[] === foodify_granted_forbidden_caps( $caps ) );

// Proving the detector works, not merely that today's array looks right. Without
// this, the assertion above passes just as happily against a detector that
// always returns nothing.
$poisoned = $caps + [ 'install_plugins' => true, 'edit_users' => true ];
$found    = foodify_granted_forbidden_caps( $poisoned );
check( 'a poisoned role IS caught', in_array( 'install_plugins', $found, true ) && in_array( 'edit_users', $found, true ) );
check( 'and only the offending capabilities are named', 2 === count( $found ) );
check( 'a capability set to false is not "granted"',
	[] === foodify_granted_forbidden_caps( $caps + [ 'install_plugins' => false ] ) );

echo "── the drift the version exists to fix ──\n";

// The scenario: an earlier release gave staff edit_products, this one does not.
$stored = [ 'read' => true, 'edit_shop_orders' => true, 'edit_products' => true, 'manage_woocommerce' => true ];
$diff   = foodify_role_caps_diff( $stored, $caps );

// REMOVAL IS THE HALF THAT MATTERS. Adding is what everyone remembers; taking a
// capability away is what a tightened role needs, and what add_role() will never
// do on a site where the role already exists.
check( 'the stale dangerous capabilities are REMOVED',
	in_array( 'edit_products', $diff['remove'], true ) && in_array( 'manage_woocommerce', $diff['remove'], true ) );
check( 'the newly needed ones are ADDED', in_array( FOODIFY_CAP_STOCK, $diff['add'], true ) );
check( 'a capability already correct is left alone',
	! in_array( 'read', $diff['add'], true ) && ! in_array( 'read', $diff['remove'], true ) );

$applied = array_diff_key( $stored, array_flip( $diff['remove'] ) )
	+ array_fill_keys( $diff['add'], true );
check( 'applying the diff leaves NOTHING forbidden', [] === foodify_granted_forbidden_caps( $applied ) );

check( 'an unchanged role needs no diff',
	[ 'add' => [], 'remove' => [] ] === foodify_role_caps_diff( $caps, $caps ) );

check( 'a version bump triggers a resync', true  === foodify_roles_need_sync( '1', '2' ) );
check( 'the same version does not',        false === foodify_roles_need_sync( '1', '1' ) );
// A site that has never seen this code has no stored version at all.
check( 'a never-installed role syncs',     true  === foodify_roles_need_sync( null, '1' ) );

echo "── a number nobody measured is not zero ──\n";

$all = [ 'view_woocommerce_reports' => true, 'manage_woocommerce' => true ];

$t = foodify_dashboard_tiles( [ 'orders_today' => 0, 'awaiting' => 0, 'low_stock' => 0 ], $all );
$by = array_column( $t, null, 'key' );
check( 'a genuine zero shows as 0 and is measured',
	'0' === $by['orders_today']['value'] && true === $by['orders_today']['measured'] );

$t  = foodify_dashboard_tiles( [], $all );
$by = array_column( $t, null, 'key' );
check( 'an unmeasured value shows an em dash, not 0', '—' === $by['orders_today']['value'] );
check( 'and is flagged unmeasured',                   false === $by['orders_today']['measured'] );
check( 'revenue too',                                 '—' === $by['revenue_today']['value'] );

$t  = foodify_dashboard_tiles( [ 'orders_today' => 14, 'revenue_today' => 8420.0, 'awaiting' => 3, 'low_stock' => 2 ], $all );
$by = array_column( $t, null, 'key' );
check( 'orders render with a thousands separator when large',
	'14' === $by['orders_today']['value'] );
check( 'revenue renders as rupees',   '₹8,420' === $by['revenue_today']['value'] );
check( 'work waiting says so',        'Needs packing' === $by['awaiting']['note'] );
check( 'no work waiting says nothing',
	'' === array_column( foodify_dashboard_tiles( [ 'awaiting' => 0 ], $all ), null, 'key' )['awaiting']['note'] );

echo "── tiles are gated by capability, not by template ──\n";

$staff = foodify_dashboard_tiles( [ 'orders_today' => 14, 'revenue_today' => 8420.0, 'awaiting' => 3, 'low_stock' => 2, 'partner_orders' => 5 ], [] );
$keys  = array_column( $staff, 'key' );
check( 'Shop Staff see the orders they must pack',   in_array( 'awaiting', $keys, true ) );
check( 'Shop Staff do NOT see revenue',              ! in_array( 'revenue_today', $keys, true ) );
check( 'Shop Staff do NOT see partner performance',  ! in_array( 'partner_orders', $keys, true ) );
check( 'a manager sees both',
	in_array( 'revenue_today', array_column( $staff = foodify_dashboard_tiles( [ 'revenue_today' => 1.0, 'partner_orders' => 1 ], $all ), 'key' ), true )
	&& in_array( 'partner_orders', array_column( $staff, 'key' ), true ) );

echo "── null stock is not zero stock ──\n";

$products = [
	[ 'id' => 1, 'name' => 'Express Dal Fry',  'stock' => 2,    'managed' => true  ],
	[ 'id' => 2, 'name' => 'Masala Chai',      'stock' => 0,    'managed' => true  ],
	[ 'id' => 3, 'name' => 'Poha',             'stock' => 40,   'managed' => true  ],
	// Stock management OFF. This means "we do not count this one", not "there
	// are none". Read as 0 and the panel fills with products that are fine.
	[ 'id' => 4, 'name' => 'Gift card',        'stock' => null, 'managed' => false ],
	[ 'id' => 5, 'name' => 'Combo box',        'stock' => null, 'managed' => true  ],
];
$rows = foodify_low_stock_rows( $products, 6 );

check( 'an unmanaged product is NOT reported as out of stock',
	! in_array( 'Gift card', array_column( $rows, 'name' ), true ) );
check( 'nor is a managed product with a null quantity',
	! in_array( 'Combo box', array_column( $rows, 'name' ), true ) );
check( 'a well-stocked product is not listed',
	! in_array( 'Poha', array_column( $rows, 'name' ), true ) );
check( 'exactly the two that are genuinely low', 2 === count( $rows ) );
check( 'out of stock leads',      'Masala Chai' === $rows[0]['name'] && 'out' === $rows[0]['state'] );
check( 'then the next lowest',    'Express Dal Fry' === $rows[1]['name'] && 'low' === $rows[1]['state'] );

check( 'a product exactly ON the threshold is included',
	1 === count( foodify_low_stock_rows( [ [ 'id' => 9, 'name' => 'Edge', 'stock' => 6, 'managed' => true ] ], 6 ) ) );
check( 'one above it is not',
	0 === count( foodify_low_stock_rows( [ [ 'id' => 9, 'name' => 'Edge', 'stock' => 7, 'managed' => true ] ], 6 ) ) );
check( 'nothing to report -> an empty list, not a crash', [] === foodify_low_stock_rows( [], 6 ) );

// Two products at the same level must order the same way every render, or the
// panel appears to shuffle itself.
$tie = foodify_low_stock_rows( [
	[ 'id' => 1, 'name' => 'Zebra', 'stock' => 1, 'managed' => true ],
	[ 'id' => 2, 'name' => 'Apple', 'stock' => 1, 'managed' => true ],
], 6 );
check( 'ties break alphabetically, so the panel does not shuffle', 'Apple' === $tie[0]['name'] );

echo "── the stock field refuses rather than guesses ──\n";

check( 'a whole number is accepted',        12   === foodify_parse_stock_input( '12' ) );
check( 'zero is a real answer',             0    === foodify_parse_stock_input( '0' ) );
check( 'surrounding whitespace is fine',    7    === foodify_parse_stock_input( '  7 ' ) );
// A blank field read as zero would take a product off sale because somebody
// tabbed past it.
check( 'BLANK is refused, not read as zero', null === foodify_parse_stock_input( '' ) );
check( 'a negative number is refused',       null === foodify_parse_stock_input( '-3' ) );
check( 'a decimal is refused',               null === foodify_parse_stock_input( '2.5' ) );
check( 'words are refused',                  null === foodify_parse_stock_input( 'twelve' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
