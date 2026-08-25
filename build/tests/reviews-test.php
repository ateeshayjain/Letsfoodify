<?php
/**
 * Tests the pure halves of inc/reviews.php and inc/business-profile.php.
 *
 * Two things are being defended, and they are the same thing twice:
 *
 *  1. NEVER ASK FOR A REVIEW OF AN ORDER YOU JUST REFUNDED. Scheduling is not
 *     deciding — the event is queued when the order completes and fires five
 *     days later, and five days is plenty of time for a refund to land.
 *
 *  2. NEVER PUBLISH A PLACEHOLDER AS FACT. The build had FSSAI 10012345678901
 *     hardcoded into the header, both footers and the trust strip. Structured
 *     data would have handed the same fabricated licence to Google in a format
 *     designed to be trusted.
 *
 *   php tests/reviews-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

require __DIR__ . '/../theme/foodify/inc/reviews.php';
require __DIR__ . '/../theme/foodify/inc/business-profile.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

$NOW  = 1_700_000_000;
$DONE = $NOW - ( 6 * DAY_IN_SECONDS );   // completed six days ago; ask is due at five

function order( array $over = [] ): array {
	global $DONE;
	return array_merge( [
		'completed_at'         => $DONE,
		'asked_at'             => null,
		'email'                => 'priya@example.org',
		'refunded'             => false,
		'cancelled'            => false,
		'opted_out'            => false,
		'has_reviewable_items' => true,
		'customer_last_asked'  => null,
	], $over );
}

echo "── when to ask ──\n";

$s = foodify_review_request_state( order(), $NOW );
check( 'a delivered order past the delay is asked', true === $s['send'] && 'ok' === $s['reason'] );

$s = foodify_review_request_state( order(), $DONE + ( 4 * DAY_IN_SECONDS ) );
check( 'a day early -> not yet, and it says when', false === $s['send'] && 'not_due_yet' === $s['reason'] );
check( 'the due time is completion + 5 days', $DONE + ( 5 * DAY_IN_SECONDS ) === $s['due_at'] );

$s = foodify_review_request_state( order(), $DONE + ( 5 * DAY_IN_SECONDS ) );
check( 'exactly on the boundary -> asked', true === $s['send'] );

echo "── the reason it is re-checked at firing time ──\n";

// THE ONE THAT MATTERS. Queued five days ago when everything was fine; a refund
// landed since. An email asking someone to rate a meal you just refunded them
// for is worse than sending nothing.
$s = foodify_review_request_state( order( [ 'refunded' => true ] ), $NOW );
check( 'a refund since the order completed stops the ask', false === $s['send'] && 'refunded' === $s['reason'] );
check( 'and it does not reschedule', 0 === $s['due_at'] );

$s = foodify_review_request_state( order( [ 'cancelled' => true ] ), $NOW );
check( 'a cancelled order is never asked', false === $s['send'] && 'cancelled' === $s['reason'] );

// A permanent reason must beat a timing one. Reporting "not due yet" on a
// refunded order implies it will be asked later, and it never will.
$s = foodify_review_request_state( order( [ 'refunded' => true ] ), $DONE + DAY_IN_SECONDS );
check( 'refunded outranks not-due-yet', 'refunded' === $s['reason'] );

echo "── asking twice, and asking too often ──\n";

$s = foodify_review_request_state( order( [ 'asked_at' => $NOW - 100 ] ), $NOW );
check( 'never asked twice about one order', false === $s['send'] && 'already_asked' === $s['reason'] );

$s = foodify_review_request_state( order( [ 'customer_last_asked' => $NOW - ( 10 * DAY_IN_SECONDS ) ] ), $NOW );
check( 'a regular customer is not asked every week', false === $s['send'] && 'customer_cooldown' === $s['reason'] );

$s = foodify_review_request_state( order( [ 'customer_last_asked' => $NOW - ( 31 * DAY_IN_SECONDS ) ] ), $NOW );
check( 'after the cooldown they can be asked again', true === $s['send'] );

echo "── cron that fires very late ──\n";

// A site down for a fortnight queues everything and releases it at once. Asking
// about a meal someone ate two months ago reads as a broken system.
$s = foodify_review_request_state( order( [ 'completed_at' => $NOW - ( 60 * DAY_IN_SECONDS ) ] ), $NOW );
check( 'an order older than the window is dropped, not asked', false === $s['send'] && 'too_old' === $s['reason'] );
$s = foodify_review_request_state( order( [ 'completed_at' => $NOW - ( 44 * DAY_IN_SECONDS ) ] ), $NOW );
check( 'just inside the window is still asked', true === $s['send'] );

echo "── consent and the obvious blanks ──\n";

$s = foodify_review_request_state( order( [ 'opted_out' => true ] ), $NOW );
check( 'an opted-out customer is never asked', false === $s['send'] && 'opted_out' === $s['reason'] );
$s = foodify_review_request_state( order( [ 'email' => '   ' ] ), $NOW );
check( 'whitespace is not an email address', false === $s['send'] && 'no_email' === $s['reason'] );
$s = foodify_review_request_state( order( [ 'has_reviewable_items' => false ] ), $NOW );
check( 'nothing reviewable -> no ask', false === $s['send'] && 'nothing_to_review' === $s['reason'] );
$s = foodify_review_request_state( order(), $NOW, [ 'enabled' => false ] );
check( 'the whole flow can be switched off', false === $s['send'] && 'disabled' === $s['reason'] );
$s = foodify_review_request_state( order( [ 'completed_at' => 0 ] ), $NOW );
check( 'an order that never completed is not asked', false === $s['send'] && 'not_completed' === $s['reason'] );

echo "── a star rating carries its evidence ──\n";

$d = foodify_rating_display( 5.0, 1 );
check( 'one review says "1 review", not just five stars', $d['show'] && '1 review' === $d['label'] );
$d = foodify_rating_display( 4.6, 23 );
check( 'many reviews say how many', '23 reviews' === $d['label'] && 4.6 === $d['stars'] );
$d = foodify_rating_display( 0.0, 0 );
check( 'no reviews -> render nothing rather than zero stars', false === $d['show'] );
$d = foodify_rating_display( 4.666, 3 );
check( 'the average is not dressed up beyond one decimal', 4.7 === $d['stars'] );

echo "── the licence number that was hardcoded on every page ──\n";

check( 'the dummy that shipped in four templates is refused',
	false === foodify_is_valid_fssai( '10012345678901' ) );
check( 'an ascending run is refused',       false === foodify_is_valid_fssai( '12345678901234' ) );
check( 'all-same digits are refused',       false === foodify_is_valid_fssai( '11111111111111' ) );
check( 'thirteen digits are refused',       false === foodify_is_valid_fssai( '1001234567890' ) );
check( 'fifteen digits are refused',        false === foodify_is_valid_fssai( '100123456789012' ) );
check( 'empty is refused',                  false === foodify_is_valid_fssai( '' ) );
check( 'a licence not starting 1 or 2 is refused', false === foodify_is_valid_fssai( '90012345678901' ) );
check( 'a plausible real licence is accepted', true === foodify_is_valid_fssai( '12419064000123' ) );
check( 'spacing in a real licence is tolerated', true === foodify_is_valid_fssai( '1241 9064 0001 23' ) );

echo "── the profile refuses to publish itself half-built ──\n";

$profile = [
	'legal_name' => 'AVAC Ventures', 'brand' => 'The Foodify Company',
	'street' => 'N-7011 Parx Laureate, Sector 108', 'locality' => 'Noida',
	'region' => 'UP', 'postal' => '201304', 'country' => 'IN',
	'phone' => '+911204567890', 'email' => 'care@letsfoodify.com',
	'fssai' => '12419064000123',
];
check( 'a complete profile has nothing outstanding', [] === foodify_business_placeholders( $profile ) );
check( 'and it produces a schema node', null !== foodify_local_business_schema( $profile ) );

// THE POINT OF THE WHOLE FILE. Structured data is a machine-readable claim.
// Building one out of placeholders publishes a false licence to Google in the
// one format designed to be trusted.
$bad = array_merge( $profile, [ 'fssai' => '10012345678901' ] );
check( 'the dummy licence is caught', in_array( 'fssai', foodify_business_placeholders( $bad ), true ) );
check( 'and NO schema is published at all', null === foodify_local_business_schema( $bad ) );

check( 'a missing phone is caught',
	in_array( 'phone', foodify_business_placeholders( array_merge( $profile, [ 'phone' => '' ] ) ), true ) );
// Display masks get copied out of mockups into config more often than you would think.
check( 'a masked phone number is not a phone number',
	in_array( 'phone', foodify_business_placeholders( array_merge( $profile, [ 'phone' => '+91 98••• ••210' ] ) ), true ) );
check( 'an example.com address is caught',
	in_array( 'email', foodify_business_placeholders( array_merge( $profile, [ 'email' => 'hi@example.com' ] ) ), true ) );
check( 'every missing field is named, not just the first',
	3 === count( foodify_business_placeholders( array_merge( $profile, [ 'phone' => '', 'street' => '', 'fssai' => '' ] ) ) ) );

echo "── template tokens ──\n";

$t = foodify_content_tokens( $profile, '2026' );
check( 'the year token resolves',    '2026' === $t['<!--FOODIFY_YEAR-->'] );
check( 'a real licence resolves',    '12419064000123' === $t['<!--FOODIFY_FSSAI-->'] );

// A blank would read as a layout bug and get ignored. A plausible number is
// what got us here. It has to be loud and it has to not be a number.
$t = foodify_content_tokens( array_merge( $profile, [ 'fssai' => '' ] ), '2026' );
check( 'an unset licence renders NOT CONFIGURED', 'NOT CONFIGURED' === $t['<!--FOODIFY_FSSAI-->'] );
$t = foodify_content_tokens( array_merge( $profile, [ 'fssai' => '10012345678901' ] ), '2026' );
check( 'and so does the dummy, rather than passing through',
	'NOT CONFIGURED' === $t['<!--FOODIFY_FSSAI-->'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
