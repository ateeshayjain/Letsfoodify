<?php
/**
 * Combos: what is inside, what it costs per meal, and when a saving may be shown.
 *
 * The rule worth defending by name is the saving. A combo's discount is the
 * one number on the page a shop is tempted to invent, and an invented saving is
 * the same fabricated-trust pattern as the "70 people are viewing this" counter
 * the rebuild removed. So it is computed from a figure the shop enters — what
 * the items cost bought separately — and shown only when it clears a floor.
 *
 *   php tests/combo-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/product-combo.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

echo "── what is inside ──\n";
$items = foodify_combo_items( "Express Dal Fry × 2\nIdli Sambhar\n2 x Masala Chai\n\n   \n" );
check( 'a trailing count is read',        [ 'name' => 'Express Dal Fry', 'qty' => 2 ] === $items[0] );
check( 'no count means one',              [ 'name' => 'Idli Sambhar', 'qty' => 1 ] === $items[1] );
check( 'a leading count is read too',     [ 'name' => 'Masala Chai', 'qty' => 2 ] === $items[2] );
check( 'blank lines are not items',       3 === count( $items ) );
check( 'meals are counted, not lines',    5 === foodify_combo_meal_count( $items ) );
check( 'an empty field is not a combo',   [] === foodify_combo_items( "  \n\n " ) );
check( 'a bare count with no name is dropped', [] === foodify_combo_items( "3 ×" ) );

echo "── per-meal price ──\n";
check( '₹525 over 3 meals is ₹175',       175 === foodify_combo_per_meal( 525, 3 ) );
check( 'it rounds DOWN, never flattering the pack', 174 === foodify_combo_per_meal( 524, 3 ) );
check( 'no meals, no figure',             0 === foodify_combo_per_meal( 525, 0 ) );

echo "── the saving, which may not be invented ──\n";
check( '₹615 separately vs ₹525 saves ₹90', [ 'amount' => 90, 'percent' => 14 ] === foodify_combo_saving( 615, 525 ) );
check( 'no stated separate price, no saving', [ 'amount' => 0, 'percent' => 0 ] === foodify_combo_saving( 0, 525 ) );
check( 'a pack that is not cheaper claims nothing', [ 'amount' => 0, 'percent' => 0 ] === foodify_combo_saving( 500, 525 ) );
check( 'the badge reads "Save ₹90"',      'Save ₹90' === foodify_combo_saving_badge( 615, 525 ) );
check( 'a saving under the floor is not a badge', '' === foodify_combo_saving_badge( 545, 525 ) );
check( 'no separate price means no badge', '' === foodify_combo_saving_badge( 0, 525 ) );

echo "── the card line ──\n";
check( 'meals first, then the names',     '5 meals · Express Dal Fry, Idli Sambhar, Masala Chai' === foodify_combo_card_line( $items ) );
$long = foodify_combo_items( "A\nB\nC\nD\nE" );
check( 'a long list counts the rest rather than truncating a word',
	'5 meals · A, B, C +2 more' === foodify_combo_card_line( $long ) );
check( 'one meal is singular',            '1 meal · A' === foodify_combo_card_line( foodify_combo_items( "A" ) ) );
check( 'nothing inside renders nothing',  '' === foodify_combo_card_line( [] ) );

echo "── the panel ──\n";
$html = foodify_combo_panel_html( $items, [ 'price' => 525, 'separate' => 615 ] );
check( 'every item is listed',            3 === substr_count( $html, 'fd-combo__item' ) );
check( 'the per-meal price is printed',   str_contains( $html, '₹105 per meal' ) );
check( 'the saving names what it is against', str_contains( $html, 'Save ₹90 against ₹615 bought separately' ) );
$plain = foodify_combo_panel_html( $items, [ 'price' => 525 ] );
check( 'with no separate price the panel still renders, minus the saving',
	str_contains( $plain, 'fd-combo__list' ) && ! str_contains( $plain, 'Save ₹' ) );
check( 'a name is escaped',               str_contains( foodify_combo_panel_html( [ [ 'name' => '<b>x</b>', 'qty' => 1 ] ] ), '&lt;b&gt;' ) );
check( 'not a combo, no panel',           '' === foodify_combo_panel_html( [] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
