<?php
/**
 * Tests the pure half of inc/analytics.php — no WordPress, no Google.
 *
 * Three correctness rules, each of which fails silently in production:
 *
 *  1. PURCHASE FIRES ONCE PER ORDER. Refreshing the thank-you page or reopening
 *     it from the confirmation email must not double-count revenue — inflated
 *     revenue is worse than none, because decisions get made on it.
 *
 *  2. ITEM IDS ARE THE FEED'S IDS. GA4 joins Shopping and analytics on item_id;
 *     two id schemes are two disconnected catalogues, noticed only when the
 *     join is finally needed.
 *
 *  3. NOTHING IN A PAYLOAD IS PII, and no payload can break out of its script
 *     block — a product named "</script>" ends the block at that byte, the
 *     same one-bad-byte failure as the feed's ampersand.
 *
 *   php tests/wp13-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../theme/foodify/inc/analytics.php';
require __DIR__ . '/../theme/foodify/inc/partner-ledger.php';   // foodify_pii_in_text

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

echo "── purchase fires once, and only for a real order ──\n";

check( 'a never-sent, paid order is due',            true  === foodify_purchase_event_due( null, true ) );
check( 'an empty meta string is also never-sent',    true  === foodify_purchase_event_due( '', true ) );
// THE REFRESH. The flag was written on the first render; the second render —
// tomorrow, from the email link — must be silent.
check( 'a sent order is NEVER due again',            false === foodify_purchase_event_due( '2026-08-26 12:00:00', true ) );
check( 'a failed/pending payment is not a purchase', false === foodify_purchase_event_due( null, false ) );

echo "── item ids join the Merchant Center catalogue ──\n";

$item = foodify_ga4_item( 42, 'Express Dal Fry', 185.0, 2 );
check( 'item_id carries the feed prefix — FDY-42, same key as g:id', 'FDY-42' === $item['item_id'] );
check( 'price rounds to paise',      185.0 === $item['price'] );
check( 'a zero quantity becomes 1, never 0', 1 === foodify_ga4_item( 1, 'x', 1.0, 0 )['quantity'] );

echo "── the purchase payload ──\n";

$p = foodify_ga4_purchase( '1194', 595.0, 28.33, 0.0, [ $item ], [ 'NALIN10' ] );
check( 'currency is INR',                    'INR' === $p['currency'] );
check( 'transaction_id is the order number', '1194' === $p['transaction_id'] );
check( 'the partner code rides along — WP-09 campaigns become visible in acquisition',
	'NALIN10' === $p['coupon'] );
check( 'no coupons -> no coupon key, not an empty one',
	! isset( foodify_ga4_purchase( '1', 1.0, 0.0, 0.0, [] )['coupon'] ) );
check( 'two coupons join with a comma',
	'NALIN10,PRIYA50' === foodify_ga4_purchase( '1', 1.0, 0.0, 0.0, [], [ 'nalin10', 'priya50' ] )['coupon'] );

echo "── nothing leaves the site that identifies a buyer ──\n";

$rendered = foodify_ga4_event_js( 'purchase', $p );
$buyer = [
	'buyer name'  => 'Priya Sharma',
	'buyer email' => 'priya@example.org',
	'buyer phone' => '9876543210',
	'buyer address' => 'B-402, Sunrise Apartments',
];
check( 'THE RULE: a rendered purchase event contains zero customer PII',
	[] === foodify_pii_in_text( $rendered, $buyer ) );
// Prove the detector against a poisoned payload, or the line above proves nothing.
$leaky = foodify_ga4_event_js( 'purchase', $p + [ 'customer' => 'Priya Sharma, 9876543210' ] );
check( 'a leaked name IS caught by the same detector',
	[] !== foodify_pii_in_text( $leaky, $buyer ) );

echo "── one bad product name cannot break the script block ──\n";

$js = foodify_ga4_event_js( 'view_item', [
	'items' => [ foodify_ga4_item( 7, 'Chai </script><script>alert(1)</script> combo', 99.0, 1 ) ],
] );
check( 'no literal </script> survives rendering', false === stripos( $js, '</script>' ) );
check( 'no raw < or > at all — JSON_HEX_TAG', ! preg_match( '/[<>]/', $js ) );
check( 'and the payload still decodes to the original name',
	false !== strpos( json_decode( substr( $js, strpos( $js, '{' ), -2 ), true )['items'][0]['item_name'] ?? '', 'alert(1)' ) );
check( 'ampersands and apostrophes in DATA are hexed',
	! preg_match( "/[&']/", $js ) );

echo "── the measurement id gate ──\n";

check( 'a real id loads',              true  === foodify_valid_measurement_id( 'G-ABC12DE34F' ) );
check( 'lowercase is tolerated',       true  === foodify_valid_measurement_id( 'g-abc12de34f' ) );
// Half-installed analytics looks installed. Garbage must mean OFF, not "try".
check( 'a UA- id is refused (GA4 only)',     false === foodify_valid_measurement_id( 'UA-1234567-1' ) );
check( 'a GTM container id is refused',      false === foodify_valid_measurement_id( 'GTM-ABCDEF' ) );
check( 'empty is off',                       false === foodify_valid_measurement_id( '' ) );
check( 'an injected value cannot smuggle markup',
	false === foodify_valid_measurement_id( 'G-ABC"><script>' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
