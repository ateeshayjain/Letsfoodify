<?php
/**
 * Tests the pure half of inc/address-book.php — no WordPress, no database.
 *
 * The invariant under test is "a non-empty book has exactly one default". It
 * sounds trivial and it is not: the operations that break it are deleting the
 * default and editing an address to untick it, neither of which anyone hits
 * while clicking through a happy path. A book with no default does not error —
 * checkout simply stops prefilling, and the acceptance criterion ("zero address
 * fields typed") fails quietly, months later, for one customer at a time.
 *
 * Loads the REAL file. It returns early before its WordPress half when
 * add_action() is absent, so there is nothing to stub and nothing to drift.
 *
 *   php tests/address-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../theme/foodify/inc/address-book.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

/** The invariant, asserted after every mutation in this file. */
function one_default( array $book ): bool {
	$n = 0;
	foreach ( $book as $a ) {
		$n += ! empty( $a['is_default'] ) ? 1 : 0;
	}
	return $book ? 1 === $n : 0 === $n;
}

function addr( array $over = [] ): array {
	return array_merge( [
		'label'      => 'Home',
		'first_name' => 'Priya Sharma',
		'phone'      => '9876543210',
		'address_1'  => 'B-402, Sunrise Apartments',
		'address_2'  => 'Sector 62',
		'city'       => 'Noida',
		'state'      => 'UP',
		'postcode'   => '201309',
	], $over );
}

$T = 1_700_000_000;

echo "── validation refuses what checkout would refuse ──\n";

check( 'a complete address validates', [] === foodify_address_validate( foodify_address_normalise( addr() ) ) );

$bad = foodify_address_validate( foodify_address_normalise( addr( [ 'phone' => '1234567890' ] ) ) );
check( 'a landline-shaped number is refused', isset( $bad['phone'] ) );
$bad = foodify_address_validate( foodify_address_normalise( addr( [ 'phone' => '98765 43210' ] ) ) );
check( 'a spaced mobile number is accepted (digits extracted)', ! isset( $bad['phone'] ) );
$bad = foodify_address_validate( foodify_address_normalise( addr( [ 'phone' => '+91 98765 43210' ] ) ) );
check( 'a +91 prefix is refused rather than silently truncated', isset( $bad['phone'] ) );

$bad = foodify_address_validate( foodify_address_normalise( addr( [ 'postcode' => '20130' ] ) ) );
check( 'a 5-digit PIN is refused', isset( $bad['postcode'] ) );
$bad = foodify_address_validate( foodify_address_normalise( addr( [ 'postcode' => '012345' ] ) ) );
check( 'a PIN starting 0 is refused', isset( $bad['postcode'] ) );

$bad = foodify_address_validate( foodify_address_normalise( addr( [ 'address_2' => '' ] ) ) );
check( 'address line 2 is optional', [] === $bad );
$bad = foodify_address_validate( foodify_address_normalise( addr( [ 'address_1' => '   ' ] ) ) );
check( 'whitespace is not an address', isset( $bad['address_1'] ) );

echo "── the first address is always the default ──\n";

$r = foodify_address_upsert( [], addr(), $T );
check( 'first save succeeds', [] === $r['errors'] && 1 === count( $r['book'] ) );
check( 'first address is default even though it was not ticked', true === $r['book'][0]['is_default'] );
check( 'invariant holds', one_default( $r['book'] ) );
$book = $r['book'];
$home = $r['book'][0]['id'];

echo "── a second address does not steal the default ──\n";

$r = foodify_address_upsert( $book, addr( [
	'label' => 'Office', 'address_1' => 'Tower C, 9th floor', 'address_2' => 'Sector 16',
	'city' => 'Noida', 'postcode' => '201301',
] ), $T + 100 );
$book = $r['book'];
check( 'two addresses saved', 2 === count( $book ) );
check( 'invariant holds', one_default( $book ) );
check( 'home is still the default', foodify_address_default( $book )['id'] === $home );
$office = foodify_address_find( $book, $home )['id'] === $book[0]['id'] ? $book[1]['id'] : $book[0]['id'];
check( 'default sorts first', $book[0]['id'] === $home );

echo "── saving the same place twice is one address ──\n";

$again = foodify_address_upsert( $book, addr( [ 'label' => 'Home (new phone)', 'phone' => '9811111111' ] ), $T + 200 );
check( 'no third row appears', 2 === count( $again['book'] ) );
check( 'the existing row was updated', foodify_address_find( $again['book'], $home )['phone'] === '9811111111' );
check( 'invariant holds', one_default( $again['book'] ) );

// Same street written differently is still the same place.
$messy = foodify_address_upsert( $book, addr( [ 'address_1' => 'B-402,  Sunrise   Apartments' ] ), $T + 200 );
check( 'punctuation and spacing do not create a duplicate', 2 === count( $messy['book'] ) );

// A genuinely different flat in the same building is a different address.
$other = foodify_address_upsert( $book, addr( [ 'address_1' => 'B-403, Sunrise Apartments' ] ), $T + 200 );
check( 'a different flat number IS a new address', 3 === count( $other['book'] ) );

echo "── changing the default ──\n";

$book = foodify_address_set_default( $book, $office );
check( 'invariant holds after switching', one_default( $book ) );
check( 'office is now the default', foodify_address_default( $book )['id'] === $office );
check( 'home is no longer the default', false === foodify_address_find( $book, $home )['is_default'] );

$untouched = foodify_address_set_default( $book, 'a-does-not-exist' );
check( 'an unknown id changes nothing', foodify_address_default( $untouched )['id'] === $office );
check( 'invariant holds after an unknown id', one_default( $untouched ) );

echo "── deleting the default promotes another ──\n";

$after = foodify_address_delete( $book, $office );
check( 'the address is gone', 1 === count( $after ) && null === foodify_address_find( $after, $office ) );
check( 'THE INVARIANT: a default still exists', one_default( $after ) );
check( 'the survivor was promoted', foodify_address_default( $after )['id'] === $home );

$empty = foodify_address_delete( $after, $home );
check( 'deleting the last address leaves an empty book', [] === $empty );
check( 'an empty book has no default and does not crash', null === foodify_address_default( $empty ) && one_default( $empty ) );

$nochange = foodify_address_delete( $book, 'a-does-not-exist' );
check( 'deleting an unknown id removes nothing', 2 === count( $nochange ) && one_default( $nochange ) );

echo "── an edit cannot orphan the default ──\n";

// Untick "default" on the only address that has it. Accepting that literally
// would leave the book with none.
$two   = foodify_address_set_default( $book, $home );
$edit  = foodify_address_find( $two, $home );
$edit['is_default'] = false;
$edit['label']      = 'Home (renamed)';
$r = foodify_address_upsert( $two, $edit, $T + 300, $home );
check( 'the edit saved', [] === $r['errors'] && 'Home (renamed)' === foodify_address_find( $r['book'], $home )['label'] );
check( 'THE INVARIANT: unticking the only default does not remove it', one_default( $r['book'] ) );
check( 'it is still the default', foodify_address_default( $r['book'] )['id'] === $home );

// Ticking default on a non-default address moves it, which IS allowed.
$edit = foodify_address_find( $two, $office );
$edit['is_default'] = true;
$r = foodify_address_upsert( $two, $edit, $T + 400, $office );
check( 'ticking default on another address moves it', foodify_address_default( $r['book'] )['id'] === $office );
check( 'invariant holds', one_default( $r['book'] ) );

echo "── input the book must not trust ──\n";

$r = foodify_address_upsert( $book, addr(), $T, 'a-not-in-this-book' );
check( 'editing an id that is not in the book is refused', isset( $r['errors']['id'] ) );
check( 'and the book is unchanged', 2 === count( $r['book'] ) );

$r = foodify_address_upsert( [], addr( [ 'postcode' => '99' ] ), $T );
check( 'an invalid address is not saved at all', [] === $r['book'] && isset( $r['errors']['postcode'] ) );

$n = foodify_address_normalise( [ 'first_name' => 'Priya', 'wp_capabilities' => 'administrator', 'id' => 123 ] );
check( 'unknown keys are dropped', ! isset( $n['wp_capabilities'] ) );
check( 'a non-string id is discarded, not cast', '' === $n['id'] );

echo "── the cap ──\n";

$full = [];
for ( $i = 0; $i < FOODIFY_ADDRESS_MAX; $i++ ) {
	$full = foodify_address_upsert( $full, addr( [ 'address_1' => "Flat {$i}, Sunrise Apartments" ] ), $T + $i )['book'];
}
check( 'the book fills to the cap', FOODIFY_ADDRESS_MAX === count( $full ) );
check( 'invariant holds at the cap', one_default( $full ) );
$r = foodify_address_upsert( $full, addr( [ 'address_1' => 'One too many' ] ), $T + 999 );
check( 'one more is refused with a message that says what to do', isset( $r['errors']['id'] ) && false !== strpos( $r['errors']['id'], 'Delete one' ) );
check( 'the cap does not block EDITING an existing address', [] === foodify_address_upsert( $full, addr( [ 'address_1' => 'Flat 3, Sunrise Apartments' ] ), $T + 999 )['errors'] );

echo "── seeding an existing customer from WooCommerce ──\n";

$seed = foodify_address_seed_from_wc( addr( [ 'label' => '' ] ), $T );
check( 'a complete WooCommerce address becomes one saved address', 1 === count( $seed ) );
check( 'it is the default', true === $seed[0]['is_default'] && one_default( $seed ) );
check( 'it gets a name rather than a blank card', '' !== $seed[0]['label'] );

$seed = foodify_address_seed_from_wc( [ 'first_name' => 'Priya', 'city' => 'Noida' ], $T );
check( 'incomplete legacy data seeds nothing rather than a broken row', [] === $seed );

echo "── the summary line ──\n";

$s = foodify_address_summary( foodify_address_normalise( addr() ) );
check( 'summary joins the parts that identify a doorstep', 'B-402, Sunrise Apartments, Sector 62, Noida, 201309' === $s );
$s = foodify_address_summary( foodify_address_normalise( addr( [ 'address_2' => '' ] ) ) );
check( 'an empty line does not leave a dangling comma', 'B-402, Sunrise Apartments, Noida, 201309' === $s );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
