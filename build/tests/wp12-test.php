<?php
/**
 * Tests the pure halves of inc/product-editor.php and inc/product-feed.php.
 *
 * Two duties of care, one per module:
 *
 *  1. THE SANITISERS REFUSE RATHER THAN COERCE. "abc" cast to (float) is 0.0,
 *     and 0% is a REAL GST rate — so a typo silently becomes a tax position.
 *     Null means the field stays empty, and empty is visible everywhere else
 *     in this build.
 *
 *  2. AN UNESCAPED AMPERSAND STOPS THE FEED PARSING AT THAT BYTE. One product
 *     named "Chai & Snacks" takes every item after it out of Merchant Center
 *     and reports a fetch error on the whole file.
 *
 *   php tests/wp12-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
}

require __DIR__ . '/../theme/foodify/inc/product-editor.php';
require __DIR__ . '/../theme/foodify/inc/product-feed.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

echo "── the GST rate field cannot be typo'd into a tax position ──\n";

check( 'a real slab is accepted',           5.0  === foodify_sanitize_gst_rate( '5' ) );
check( 'with a stray percent sign',         12.0 === foodify_sanitize_gst_rate( '12%' ) );
check( 'ZERO is a real rate (unbranded staples)', 0.0 === foodify_sanitize_gst_rate( '0' ) );
check( 'the 0.25 bullion slab exists',      0.25 === foodify_sanitize_gst_rate( '0.25' ) );

// THE ONE THAT MATTERS. (float)'abc' is 0.0 and 0.0 is a legal rate. Coercion
// here turns a slipped key into a filed tax figure.
check( 'garbage is REFUSED, never coerced to 0', null === foodify_sanitize_gst_rate( 'abc' ) );
check( 'an off-schedule rate is a typo, refused (50 for 5)', null === foodify_sanitize_gst_rate( '50' ) );
check( 'so is 1.8 for 18',                  null === foodify_sanitize_gst_rate( '1.8' ) );
check( 'empty is null — "not set", which is not the same as 0%', null === foodify_sanitize_gst_rate( '' ) );

echo "── HSN codes are 4, 6 or 8 digits ──\n";

check( 'a 4-digit chapter heading is accepted', '2106' === foodify_sanitize_hsn( '2106' ) );
check( 'an 8-digit tariff item is accepted',    '21069099' === foodify_sanitize_hsn( '2106 90 99' ) );
check( 'five digits is a typo, refused',        null === foodify_sanitize_hsn( '21069' ) );
check( 'words are refused',                     null === foodify_sanitize_hsn( 'food' ) );

echo "── nutrition values are a number with a unit ──\n";

check( '"312 kcal" is stored',        '312 kcal' === foodify_sanitize_nutrition( '312 kcal' ) );
check( '"14g" without a space works', '14g' === foodify_sanitize_nutrition( '14g' ) );
check( 'prose is refused — half a panel of words must never render as data',
	null === foodify_sanitize_nutrition( 'lots of protein' ) );
check( 'a bare number with no unit is refused', null === foodify_sanitize_nutrition( '312' ) );

echo "── best-before is a date or nothing ──\n";

check( 'a parseable date is normalised to the pack format',
	'14 Aug 2027' === foodify_sanitize_best_before( '2027-08-14' ) );
check( 'not-a-date is refused', null === foodify_sanitize_best_before( 'next year sometime' ) );
// Old stock exists. Refusing a past date would HIDE it from the shelf-life
// check, which is the thing that must see it.
check( 'a PAST date is storable — the shelf-life rule flags it, not the sanitiser',
	null !== foodify_sanitize_best_before( '2024-01-01' ) );

echo "── the shelf-life-at-delivery rule ──\n";

$NOW = (int) gmmktime( 12, 0, 0, 8, 26, 2026 );

// 12-month shelf life: required = min(30% of 365 = 110, 45) = 45 days.
$s = foodify_shelf_life_state( '14 Aug 2027', 365, $NOW );
check( 'a fresh batch is sellable', true === $s['sellable'] );
$s = foodify_shelf_life_state( '20 Sep 2026', 365, $NOW );
check( '25 days of life left is BELOW the 45-day bar — not sellable',
	false === $s['sellable'] && 'too_little_life' === $s['reason'] );
$s = foodify_shelf_life_state( '1 Aug 2026', 365, $NOW );
check( 'expired stock is named expired, not just unsellable', 'expired' === $s['reason'] );
// "Whichever is less": a 60-day shelf life needs 30% = 18 days, NOT 45.
$s = foodify_shelf_life_state( gmdate( 'j M Y', $NOW + 20 * DAY_IN_SECONDS ), 60, $NOW );
check( 'a short-life product uses the 30% bar, not the 45-day cap', true === $s['sellable'] );
$s = foodify_shelf_life_state( '14 Aug 2027', 0, $NOW );
check( 'no shelf life on record -> unknown, never assumed sellable', 'unknown' === $s['reason'] );

echo "── the editor's refusal path ──\n";

check( 'a refused rate stores NOTHING rather than something wrong',
	null === foodify_editor_sanitise( 'gst_rate', '50' ) );
check( 'a good rate round-trips without float dressing', '5' === foodify_editor_sanitise( 'gst_rate', '5' ) );
check( '0.25 keeps its decimals', '0.25' === foodify_editor_sanitise( 'gst_rate', '0.25' ) );
check( 'nutrition routes through its own rule', null === foodify_editor_sanitise( 'nutrition_energy', 'a lot' ) );
check( 'free text fields still pass', 'Cool, dry place.' === foodify_editor_sanitise( 'storage', 'Cool, dry place.' ) );
check( 'every editor field has a sanitiser path that does not throw',
	(bool) array_map( static fn( $k ) => foodify_editor_sanitise( $k, 'x' ), array_keys( foodify_editor_fields() ) ) );

echo "── one bad ampersand must not take down the catalogue ──\n";

check( 'ampersands are escaped',    'Chai &amp; Snacks' === foodify_xml( 'Chai & Snacks' ) );
check( 'angle brackets are escaped', '&lt;b&gt;' === foodify_xml( '<b>' ) );
check( 'control characters XML forbids are stripped, not escaped',
	'ab' === foodify_xml( "a\x08b" ) );
// Deliberately NO smart handling of already-escaped input. Feed text is TEXT:
// a title someone typed as "Chai &amp; Snacks" literally contains those seven
// characters, and "detecting" prior escaping is how a title containing the
// substring "&amp;" gets mangled while a title containing "&" slips through raw.
check( 'input that LOOKS pre-escaped is still escaped as text',
	'&amp;amp;' === foodify_xml( '&amp;' ) );

echo "── the feed price format ──\n";

check( 'the spec format, never a rupee sign', '185.00 INR' === foodify_feed_price( 185.0 ) );
check( 'paise survive',                        '199.50 INR' === foodify_feed_price( 199.5 ) );

echo "── an incomplete item is excluded, not submitted broken ──\n";

function feed_product( array $over = [] ): array {
	return array_merge( [
		'id' => 'FDY-42', 'title' => 'Express Dal Fry', 'description' => 'Home-style dal, gently dried. Six minutes with hot water.',
		'link' => 'https://letsfoodify.com/product/express-dal-fry/',
		'image' => 'https://letsfoodify.com/wp-content/uploads/dal.jpg',
		'price' => 185.0, 'in_stock' => true, 'brand' => 'The Foodify Company', 'gtin' => '',
	], $over );
}

$b = foodify_feed_item( feed_product() );
check( 'a complete product becomes an item', null !== $b['item'] && [] === $b['missing'] );
check( 'availability maps from stock',       'in_stock' === $b['item']['g:availability'] );
check( 'own-brand food without a GTIN declares identifier_exists=no',
	'no' === ( $b['item']['g:identifier_exists'] ?? '' ) && ! isset( $b['item']['g:gtin'] ) );

$b = foodify_feed_item( feed_product( [ 'gtin' => '8901234567890' ] ) );
check( 'a real 13-digit GTIN is carried instead', '8901234567890' === ( $b['item']['g:gtin'] ?? '' ) );

// THE ENFORCEMENT ARM OF THE CONTENT PASS. No photo, no listing.
$b = foodify_feed_item( feed_product( [ 'image' => '' ] ) );
check( 'no photograph -> EXCLUDED, and the reason is named',
	null === $b['item'] && in_array( 'image', $b['missing'], true ) );
$b = foodify_feed_item( feed_product( [ 'price' => 0.0 ] ) );
check( 'a zero price -> excluded, not listed free', in_array( 'price', $b['missing'], true ) );
$b = foodify_feed_item( feed_product( [ 'description' => 'Allergens: Not provided' ] ) );
check( 'the PDP\'s honest gap marker never reaches Google as a description',
	null === $b['item'] && in_array( 'description', $b['missing'], true ) );
$b = foodify_feed_item( feed_product( [ 'in_stock' => false ] ) );
check( 'out of stock is a VALUE, not an exclusion', 'out_of_stock' === $b['item']['g:availability'] );

echo "── the document survives its own content ──\n";

$xml = foodify_feed_xml(
	[ foodify_feed_item( feed_product( [ 'title' => 'Chai & Snacks <combo>' ] ) )['item'] ],
	'The Foodify Company', 'https://letsfoodify.com/'
);
check( 'the document declares the g: namespace', str_contains( $xml, 'xmlns:g="http://base.google.com/ns/1.0"' ) );
check( 'the poisoned title is escaped in place', str_contains( $xml, 'Chai &amp; Snacks &lt;combo&gt;' ) );
$parsed = @simplexml_load_string( $xml );
check( 'AND the whole document still parses as XML', false !== $parsed );
check( 'with exactly one item in it', $parsed && 1 === count( $parsed->channel->item ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
