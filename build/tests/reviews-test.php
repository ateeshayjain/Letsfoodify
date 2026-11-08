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

echo "── 11 · the histogram: the average with its evidence ──\n";

$ratings = array_merge( array_fill( 0, 32, 5 ), array_fill( 0, 9, '4' ), [ 3, 3, 2, 1, 1 ] );
$h = foodify_review_histogram( $ratings );
check( 'counts per star, 5 down to 1', [ 5 => 32, 4 => 9, 3 => 2, 2 => 1, 1 => 2 ] === $h['counts'] );
check( 'the order is 5 -> 1, as the bars are drawn', [ 5, 4, 3, 2, 1 ] === array_keys( $h['counts'] ) );
check( 'the total is every rating counted', 46 === $h['total'] );
// (32×5 + 9×4 + 2×3 + 2 + 2) / 46 = 206 / 46 = 4.478… -> 4.5
check( 'the average is to one decimal (206 / 46 = 4.478 -> 4.5)', 4.5 === $h['average'] );
check( 'bar widths are shares of the total', 70 === $h['percents'][5] && 20 === $h['percents'][4] );
$h = foodify_review_histogram( [ 5, '0', '4.5', 6, null, 'five', '', 4 ] );
check( 'a "0", a "4.5", a 6 or junk is not a star rating and does not drag the average', 2 === $h['total'] && 4.5 === $h['average'] );
$h = foodify_review_histogram( [] );
check( 'no reviews -> zeroes, not a division by zero', 0 === $h['total'] && 0.0 === $h['average'] && 0 === $h['percents'][5] );

echo "── the filter chips ──\n";

$h     = foodify_review_histogram( $ratings );
$chips = foodify_review_filter_chips( $h, 4 );
check( 'chips read like the brief: "5★ (32)"', '5★ (32)' === $chips[0]['label'] );
check( 'photos last: "With photos (4)"', 'With photos (4)' === end( $chips )['label'] && 'photos' === end( $chips )['key'] );
check( 'a chip has a name a screen reader can say', '5 stars, 32 reviews' === $chips[0]['aria'] );
$chips = foodify_review_filter_chips( foodify_review_histogram( [ 5, 5, 4 ] ), 0 );
check( 'a star with no reviews gets no chip (it would filter to nothing)', [ '5', '4' ] === array_column( $chips, 'key' ) );
check( 'no photos -> no photo chip', ! in_array( 'photos', array_column( $chips, 'key' ), true ) );

echo "── the count line says what it looked at ──\n";

check( 'filtered: "Showing 9 of 46 reviews"', 'Showing 9 of 46 reviews' === foodify_review_count_line( 9, 46, 46 ) );
check( 'unfiltered: "Showing all 46 reviews"', 'Showing all 46 reviews' === foodify_review_count_line( 46, 46, 46 ) );
check( 'one review is one review', 'Showing 1 review' === foodify_review_count_line( 1, 1, 1 ) );
// WooCommerce may page reviews. Three matches among the ten on this page is
// not three matches among forty-six, and the line must not imply it.
check( 'paged: it names the page, not the whole set', 'Showing 3 of the 10 reviews on this page (46 in all)' === foodify_review_count_line( 3, 10, 46 ) );
check( 'paged, unfiltered', 'Showing 10 of 46 reviews' === foodify_review_count_line( 10, 10, 46 ) );
check( 'an unrated review on the page is still counted, never "11 of 10"', 'Showing all 11 reviews' === foodify_review_count_line( 11, 11, 10 ) );
$js = foodify_review_filter_script();
check( 'the script carries the same phrases', str_contains( $js, "'Showing all '+total+' reviews'" ) && str_contains( $js, "' reviews on this page ('" ) );

echo "── the summary block ──\n";

$html = foodify_review_summary_html( foodify_review_histogram( $ratings ), 4 );
check( 'the average, out of 5, with its count', str_contains( $html, '<span class="fd-rsum__score">4.5</span>' ) && str_contains( $html, 'out of 5 · 46 reviews' ) );
check( 'five bars, 5 to 1', 5 === substr_count( $html, 'class="fd-rsum__row"' ) && strpos( $html, '>5★<' ) < strpos( $html, '>1★<' ) );
check( 'each bar is spoken, not only drawn', str_contains( $html, '5 stars: 32 reviews' ) && str_contains( $html, '1 star: 2 reviews' ) );
check( 'the filter ships hidden: without the script there is no dead button', (bool) preg_match( '/data-fd-review-filter hidden>/', $html ) );
check( 'Clear ships hidden until something is filtered', str_contains( $html, 'data-fd-filter-clear hidden>Clear<' ) );
check( 'chips start un-pressed', ! str_contains( $html, 'aria-pressed="true"' ) && str_contains( $html, 'data-fd-filter="photos"' ) );
check( 'no reviews -> no summary at all (never "0 out of 5")', '' === foodify_review_summary_html( foodify_review_histogram( [] ), 0 ) );

echo "── photos: who may attach them ──\n";

$ok = [ 'count' => 2, 'is_product' => true, 'is_reply' => false, 'bought' => true, 'gate_ok' => true ];
check( 'a verified buyer may attach photos', foodify_review_photo_allowed( $ok )['allowed'] );
check( 'someone who did not buy may not', 'not_verified' === foodify_review_photo_allowed( [ 'bought' => false ] + $ok )['reason'] );
check( 'four photos is one too many', 'too_many' === foodify_review_photo_allowed( [ 'count' => 4 ] + $ok )['reason'] );
check( 'three is the limit, and allowed', foodify_review_photo_allowed( [ 'count' => 3 ] + $ok )['allowed'] );
check( 'a reply cannot carry photos', 'reply' === foodify_review_photo_allowed( [ 'is_reply' => true ] + $ok )['reason'] );
check( 'over the rate limit -> refused', 'rate_limited' === foodify_review_photo_allowed( [ 'gate_ok' => false ] + $ok )['reason'] );
check( 'not-verified outranks the rate limit (no hint the limit exists)', 'not_verified' === foodify_review_photo_allowed( [ 'bought' => false, 'gate_ok' => false ] + $ok )['reason'] );
check( 'every refusal has words a customer can act on',
	'' !== foodify_review_photo_refusal( 'not_verified' ) && str_contains( foodify_review_photo_refusal( 'too_many' ), 'up to 3' ) );

echo "── photos: judged by their bytes ──\n";

$tmp = sys_get_temp_dir() . '/fd-rv-' . getmypid();
@mkdir( $tmp );
$im = imagecreatetruecolor( 40, 30 );
imagejpeg( $im, "$tmp/real.jpg" );
imagepng( $im, "$tmp/real.png" );
imagewebp( $im, "$tmp/real.webp" );
imagegif( $im, "$tmp/real.gif" );
copy( "$tmp/real.png", "$tmp/png-named.jpg" );
file_put_contents( "$tmp/script.jpg", "<?php echo 'hi'; ?>" );
file_put_contents( "$tmp/torn.jpg", "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 64 ) );
check( 'a JPEG is a JPEG', [ 'mime' => 'image/jpeg', 'width' => 40, 'height' => 30 ] === foodify_review_photo_sniff( "$tmp/real.jpg" ) );
check( 'a PNG and a WebP are read too', 'image/png' === foodify_review_photo_sniff( "$tmp/real.png" )['mime'] && 'image/webp' === foodify_review_photo_sniff( "$tmp/real.webp" )['mime'] );
check( 'a PNG called .jpg is a PNG (the name is not consulted)', 'image/png' === foodify_review_photo_sniff( "$tmp/png-named.jpg" )['mime'] );
check( 'a PHP file called .jpg is nothing', '' === foodify_review_photo_sniff( "$tmp/script.jpg" )['mime'] );
check( 'a JPEG header on garbage is nothing', '' === foodify_review_photo_sniff( "$tmp/torn.jpg" )['mime'] );
check( 'a missing file is nothing', '' === foodify_review_photo_sniff( "$tmp/nope.jpg" )['mime'] );

$v = static fn( string $file, array $over = [] ): array => foodify_review_photo_verdict( $over + [ 'error' => 0, 'size' => (int) filesize( $file ) ] + foodify_review_photo_sniff( $file ), 1 );
check( 'a real JPEG passes', $v( "$tmp/real.jpg" )['ok'] );
check( 'a real WebP passes', $v( "$tmp/real.webp" )['ok'] );
check( 'a GIF is refused, by name of what is allowed', ! $v( "$tmp/real.gif" )['ok'] && str_contains( $v( "$tmp/real.gif" )['error'], 'not a JPEG, PNG or WebP' ) );
check( 'a script named .jpg is refused', ! $v( "$tmp/script.jpg" )['ok'] );
check( 'over 5 MB is refused, saying the size and the limit', str_contains( $v( "$tmp/real.jpg", [ 'size' => 6 * 1048576 ] )['error'], 'is 6.0 MB; the limit is 5 MB' ) );
check( 'exactly 5 MB is allowed', $v( "$tmp/real.jpg", [ 'size' => 5 * 1048576 ] )['ok'] );
check( 'PHP\'s own size limit is reported as too large, not as a failure', str_contains( $v( "$tmp/real.jpg", [ 'error' => 1 ] )['error'], 'larger than 5 MB' ) );
check( 'a broken upload says to try again', str_contains( $v( "$tmp/real.jpg", [ 'error' => 3 ] )['error'], 'try again' ) );
check( 'a decompression bomb (declared 10,000 × 10,000) is refused',
	! foodify_review_photo_verdict( [ 'error' => 0, 'size' => 1000, 'mime' => 'image/png', 'width' => 10000, 'height' => 10000 ], 1 )['ok'] );
check( 'the message names which photo', str_starts_with( foodify_review_photo_verdict( [ 'error' => 0, 'size' => 0 ], 2 )['error'], 'Photo 2 ' ) );
check( 'the glue never trusts the browser type: verdict reads the sniffed mime only',
	! foodify_review_photo_verdict( [ 'error' => 0, 'size' => 10, 'type' => 'image/jpeg' ], 1 )['ok'] );

echo "── photos: EXIF is gone after the re-encode ──\n";

// The mechanism foodify_review_photo_store() relies on, proved rather than
// assumed: GD decodes to pixels and writes a fresh file with no metadata. A
// JPEG is built here with an APP1/EXIF block carrying a camera Make, which
// stands in for the GPS block a phone writes.
$tiff = "II*\x00" . pack( 'V', 8 ) . pack( 'v', 1 )
	. pack( 'vvVV', 0x010F, 2, 5, 26 ) . pack( 'V', 0 ) . "LEAK\x00";
$app1 = "Exif\x00\x00" . $tiff;
$jpeg = (string) file_get_contents( "$tmp/real.jpg" );
file_put_contents( "$tmp/exif.jpg", substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1 . substr( $jpeg, 2 ) );
$before = function_exists( 'exif_read_data' ) ? @exif_read_data( "$tmp/exif.jpg" ) : false;
check( 'the fixture really carries EXIF (else this proves nothing)', is_array( $before ) && 'LEAK' === ( $before['Make'] ?? '' ) );
imagejpeg( imagecreatefromjpeg( "$tmp/exif.jpg" ), "$tmp/clean.jpg", 82 );
$after = @exif_read_data( "$tmp/clean.jpg" );
check( 'after a GD re-encode the camera data is gone', ! is_array( $after ) || ! isset( $after['Make'] ) );
check( 'and no byte of it survives', ! str_contains( (string) file_get_contents( "$tmp/clean.jpg" ), 'LEAK' ) );
$src = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/reviews.php' );
check( 'the store path forces the GD editor (Imagick can carry EXIF through a save)',
	str_contains( $src, "return [ 'WP_Image_Editor_GD' ];" ) && str_contains( $src, "add_filter( 'wp_image_editors', 'foodify_review_force_gd', 999 );" ) );
check( 'and saves a NEW file through the editor, never copying the upload',
	str_contains( $src, '$editor->save(' ) && ! preg_match( '/\b(copy|move_uploaded_file|rename)\s*\(\s*\$tmp/', $src ) );
check( 'a review with photos always waits for a human', str_contains( $src, "add_filter( 'pre_comment_approved'" ) );
check( 'and photos show only once it is approved', str_contains( $src, "'1' === (string) \$comment->comment_approved" ) );
array_map( 'unlink', glob( "$tmp/*" ) ?: [] );
@rmdir( $tmp );

echo "── photos: the rest ──\n";

$files = foodify_review_photo_files( [
	'name' => [ 'a.jpg', '', 'b.png' ], 'type' => [ 'image/jpeg', '', 'image/png' ],
	'tmp_name' => [ '/tmp/x', '', '/tmp/y' ], 'error' => [ 0, 4, 0 ], 'size' => [ 10, 0, 20 ],
] );
check( '$_FILES column-wise -> one array per file, empty slot dropped', 2 === count( $files ) && 'b.png' === $files[1]['name'] && 20 === $files[1]['size'] );
check( 'nothing chosen -> no files', [] === foodify_review_photo_files( [ 'name' => [ '' ], 'type' => [ '' ], 'tmp_name' => [ '' ], 'error' => [ 4 ], 'size' => [ 0 ] ] ) );
check( 'no field at all -> no files', [] === foodify_review_photo_files( null ) );

$g = foodify_review_photo_gate( [ $NOW - 100, $NOW - 200, $NOW - 300 ], $NOW, 3, 86400 );
check( 'three photo reviews in a day blocks the fourth', ! $g['allowed'] && 86400 - 300 === $g['retry_after'] );
check( 'a day later it is allowed again', foodify_review_photo_gate( [ $NOW - 86500, $NOW - 86600, $NOW - 86700 ], $NOW, 3, 86400 )['allowed'] );

check( 'alt text uses the first name only', "Photo from Priya's review" === foodify_review_photo_alt( 'Priya Sharma' ) );
check( 'an initial is dropped with its full stop', "Photo from Rakesh's review" === foodify_review_photo_alt( 'Rakesh M.' ) );
check( 'an email address is never put in alt text', "Photo from a customer's review" === foodify_review_photo_alt( 'priya@example.org' ) );
check( 'no name -> "a customer"', "Photo from a customer's review" === foodify_review_photo_alt( '  ' ) );

$p = foodify_review_photos_html( [
	[ 'thumb' => '/u/r-1-150x150.jpg', 'full' => '/u/r-1.jpg', 'width' => 150, 'height' => 150 ],
	[ 'thumb' => 'javascript:alert(1)', 'full' => '/u/x.jpg' ],
], 'Priya <b>S</b>' );
check( 'a thumbnail links to the full photo (works with no script)', str_contains( $p, '<a class="fd-rv-photo" href="/u/r-1.jpg" data-fd-photo><img src="/u/r-1-150x150.jpg" width="150" height="150"' ) );
check( 'with the alt text the brief asks for', str_contains( $p, 'alt="Photo from Priya&#039;s review"' ) );
check( 'a javascript: URL is never printed', ! str_contains( $p, 'javascript' ) && 1 === substr_count( $p, '<li>' ) );
check( 'no photos -> no markup', '' === foodify_review_photos_html( [], 'Priya' ) );

echo "── the script and its [hidden] escapes ──\n";
check( 'it cannot end the script block early', ! str_contains( $js, '</script' ) );
check( 'photos open in a <dialog>, not a lightbox library', str_contains( $js, "createElement('dialog')" ) && str_contains( $js, 'showModal()' ) );
check( 'the filter reveals itself only when it can work', str_contains( $js, 'bar.hidden=false' ) );
$css = (string) file_get_contents( __DIR__ . '/../theme/foodify/style.css' );
foreach ( [ 'fd-rsum__filter', 'fd-rsum__clear', 'fd-rv' ] as $cls ) {
	$sets_display = (bool) preg_match( '/\.' . preg_quote( $cls, '/' ) . '(?![\w-])[^{}]*\{[^{}]*display:\s*(?!none)/', $css );
	$has_escape   = (bool) preg_match( '/\.' . preg_quote( $cls, '/' ) . '\[hidden\][^{}]*\{[^{}]*display:\s*none/', $css );
	check( ".$cls: hidden by the script, and CSS lets it be", $has_escape || ! $sets_display );
}
check( 'filter chips are tap-target high', (bool) preg_match( '/\.fd-rsum__chip\s*\{[^}]*min-height:\s*var\(--wp--custom--tap-target\)/', $css ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
