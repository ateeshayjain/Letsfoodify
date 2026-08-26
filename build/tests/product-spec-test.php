<?php
/**
 * Tests the pure half of inc/product-spec.php — no WordPress.
 *
 * The design pass moved three things onto the product page: how you make it,
 * what is in it, and what the pack declares. Two of those carry a duty of care
 * rather than a layout opinion, and those are what these tests defend:
 *
 *  1. A MISSING DECLARATION IS SHOWN, NOT HIDDEN. Dropping an empty required row
 *     makes an incomplete page look complete. For allergens it is a safety
 *     argument: the absence of a declaration must never read as "contains none".
 *
 *  2. AN UNKNOWN PREP METHOD INVENTS NOTHING. Guessing cooking instructions for
 *     food is not a graceful fallback.
 *
 *   php tests/product-spec-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../theme/foodify/inc/product-spec.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

function complete(): array {
	return [
		'ingredients'  => 'Split yellow lentils, onion, tomato, ghee, cumin.',
		'allergens'    => 'Milk (ghee)',
		'net_quantity' => '80 g',
		'servings'     => '2',
		'diet'         => 'Vegetarian',
		'storage'      => 'Cool, dry place.',
		'mrp'          => '₹210.00 (incl. all taxes)',
		'best_before'  => '14 Aug 2027',
		'shelf_life'   => '12 months',
		'origin'       => 'India',
		'fssai'        => '12419064000123',
		'marketed_by'  => 'AVAC Ventures, Noida 201304',
		'care'         => 'care@letsfoodify.com',
	];
}
function rows_of( array $groups, string $group ): array {
	return array_column( $groups[ $group ]['rows'], null, 'key' );
}

echo "── the declarations Legal Metrology requires ──\n";

check( 'a complete product is missing nothing', [] === foodify_spec_missing( complete() ) );

// Scope §8 lists these by name. Each is asserted individually rather than as a
// count, so a field quietly dropped from the required list fails here by name.
foreach ( [ 'ingredients', 'allergens', 'net_quantity', 'diet', 'mrp', 'best_before', 'origin', 'fssai', 'marketed_by', 'care' ] as $key ) {
	$without = complete();
	$without[ $key ] = '';
	check( "'$key' is required", in_array( $key, foodify_spec_missing( $without ), true ) );
}
$opt = complete();
$opt['servings'] = '';
$opt['storage']  = '';
check( 'servings and storage are optional', [] === foodify_spec_missing( $opt ) );
check( 'whitespace is not a declaration',
	in_array( 'allergens', foodify_spec_missing( array_merge( complete(), [ 'allergens' => "  \n " ] ) ), true ) );

echo "── a missing declaration is SHOWN ──\n";

$groups = foodify_spec_model( array_merge( complete(), [ 'allergens' => '' ] ) );
$c      = rows_of( $groups, 'contents' );

// THE ONE THAT MATTERS. Someone deciding whether their child can eat this needs
// to know the data is absent, not be shown a table that quietly omits the row.
check( 'a missing allergen declaration is STILL A ROW', isset( $c['allergens'] ) );
check( 'it says Not provided',      'Not provided' === $c['allergens']['value'] );
check( 'and is flagged as a gap',   false === $c['allergens']['provided'] );

// An empty OPTIONAL field is simply not a row — nothing is being hidden.
$groups = foodify_spec_model( array_merge( complete(), [ 'storage' => '' ] ) );
check( 'an empty optional field is dropped, not shown as a gap',
	! isset( rows_of( $groups, 'contents' )['storage'] ) );

$groups = foodify_spec_model( array_merge( complete(), [ 'fssai' => '' ] ) );
check( 'an unconfigured FSSAI licence surfaces here too, not just in the footer',
	'Not provided' === rows_of( $groups, 'label' )['fssai']['value'] );

echo "── the two audiences are separated ──\n";

$groups = foodify_spec_model( complete() );
$c = array_keys( rows_of( $groups, 'contents' ) );
$l = array_keys( rows_of( $groups, 'label' ) );

check( "the buyer's group leads with ingredients", 'ingredients' === $c[0] );
check( 'allergens are second, not buried',         'allergens'   === $c[1] );
check( 'net quantity is a buyer fact',             in_array( 'net_quantity', $c, true ) );
check( 'the FSSAI number is NOT in the buyer group', ! in_array( 'fssai', $c, true ) );
check( 'it is in the label group',                 in_array( 'fssai', $l, true ) );
check( 'so is marketed-by',                        in_array( 'marketed_by', $l, true ) );
check( 'no field appears in both groups',          [] === array_intersect( $c, $l ) );
check( 'every declared field lands somewhere',
	count( $c ) + count( $l ) === count( foodify_spec_fields() ) );

echo "── nutrition is a panel or it is nothing ──\n";

$n = foodify_nutrition_rows( [ 'energy' => '312 kcal', 'protein' => '14 g', 'carbs' => '44 g', 'fat' => '8 g' ] );
check( 'four values make a panel', 4 === count( $n ) );
check( 'energy leads, as on a pack', 'Energy' === $n[0]['label'] );
check( 'the order is the label order, not the input order',
	'Protein' === $n[1]['label'] && 'Carbohydrate' === $n[2]['label'] );

// Two stray numbers is not a nutrition panel; it is a fragment that LOOKS like
// one, which is worse than an honest absence.
check( 'two values render nothing rather than half a panel',
	[] === foodify_nutrition_rows( [ 'energy' => '312 kcal', 'fat' => '8 g' ] ) );
check( 'exactly three is enough', 3 === count( foodify_nutrition_rows( [ 'energy' => '1', 'protein' => '2', 'fat' => '3' ] ) ) );
check( 'nothing at all renders nothing', [] === foodify_nutrition_rows( [] ) );
check( 'blank values are not counted toward the three',
	[] === foodify_nutrition_rows( [ 'energy' => '312', 'protein' => '', 'carbs' => '  ', 'fat' => '8' ] ) );

echo "── how you make it, per product ──\n";

$s = foodify_prep_steps( 'Just add hot water', '6 minutes' );
check( 'hot water gives three steps', 3 === count( $s ) );
check( 'and the wait is the product\'s own time', false !== strpos( $s[2]['title'], '6 minutes' ) );
check( 'steps are numbered from one', 1 === $s[0]['n'] && 3 === $s[2]['n'] );

$s = foodify_prep_steps( 'Stir with drinking water' );
check( 'cold water never says boiling', false === stripos( implode( ' ', array_column( $s, 'detail' ) ), 'boiling' ) );
check( 'and never says wait',           false === stripos( implode( ' ', array_column( $s, 'title' ) ), 'wait' ) );

$s = foodify_prep_steps( 'Requires cooking', '8 minutes' );
check( 'cooking says simmer, with the product\'s time',
	false !== stripos( $s[1]['title'], 'simmer' ) && false !== strpos( $s[1]['title'], '8 minutes' ) );
check( 'cooking never claims no pan is needed',
	false === stripos( implode( ' ', array_column( $s, 'detail' ) ), 'no pan' ) );

// Guessing cooking instructions for food is not a graceful fallback.
check( 'an UNKNOWN method invents nothing', [] === foodify_prep_steps( 'Sous vide for 40 minutes' ) );
check( 'an empty method invents nothing',   [] === foodify_prep_steps( '' ) );

check( 'the method match is case-insensitive', 3 === count( foodify_prep_steps( 'JUST ADD HOT WATER' ) ) );
check( 'a default time is used when the product states none',
	false !== strpos( foodify_prep_steps( 'hot water' )[2]['title'], '6 minutes' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
