<?php
/**
 * Lane A: delivery date by PIN, veg mark, unit price, the back-of-pack shot,
 * back-in-stock alerts, and the Offers list — the pure halves.
 *
 * The rules worth pinning by name:
 *  - A delivery date is only printed from configured rules, and the PHP
 *    reference and the page's JS agree on every shared vector
 *    (tests/fixtures/delivery-vectors.json; the JS side is
 *    tests/delivery-vectors.js).
 *  - "Non-vegetarian" never earns the green dot, and a blank diet earns
 *    nothing either: the mark is a claim.
 *  - A unit price is never computed from the wrong quantity: "3 packs of 80 g"
 *    is 240 g, and two quantities that disagree print nothing.
 *  - A stock alert is never stored twice, never past the cap, and its cancel
 *    token is stored hashed.
 *  - No offer line carries a number checkout would not charge, and nothing
 *    configured means nothing shown.
 *
 *   php tests/lane-a-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/product-spec.php';
require __DIR__ . '/../theme/foodify/inc/payments.php';
require __DIR__ . '/../theme/foodify/inc/delivery-estimate.php';
require __DIR__ . '/../theme/foodify/inc/stock-alerts.php';
require __DIR__ . '/../theme/foodify/inc/offers.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

/* ======================================================================== */
echo "── delivery: the configuration is the client's, and checked ──\n";
$vec  = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/delivery-vectors.json' ), true );
$main = $vec['configs']['main'];
check( 'the shared vectors load (' . count( $vec['cases'] ?? [] ) . ' cases) — an empty file is not a pass', count( $vec['cases'] ?? [] ) >= 15 );
check( 'a valid config normalises',                  null !== foodify_delivery_normalise_config( $main ) );
check( 'normalising is idempotent — the page JSON and the PHP read the same rules',
	foodify_delivery_normalise_config( $main ) === foodify_delivery_normalise_config( foodify_delivery_normalise_config( $main ) ) );
check( 'unset option → no config',                   null === foodify_delivery_normalise_config( null ) && null === foodify_delivery_normalise_config( false ) );
$no_cut = $main; unset( $no_cut['cutoff_hour'] );
check( 'no cut-off hour → no config (never assumed)', null === foodify_delivery_normalise_config( $no_cut ) );
$no_days = $main; unset( $no_days['no_dispatch_weekdays'] );
check( 'no dispatch-day rule → no config ("ships every day" is a claim)', null === foodify_delivery_normalise_config( $no_days ) );
check( 'cut-off 24 is refused',                      null === foodify_delivery_normalise_config( [ 'cutoff_hour' => 24 ] + $main ) );
check( 'cut-off 13.5 is refused',                    null === foodify_delivery_normalise_config( [ 'cutoff_hour' => 13.5 ] + $main ) );
check( 'no dispatch on all seven days is refused',   null === foodify_delivery_normalise_config( [ 'no_dispatch_weekdays' => [ 1, 2, 3, 4, 5, 6, 7 ] ] + $main ) );
check( 'a malformed holiday is refused, not skipped', null === foodify_delivery_normalise_config( [ 'holidays' => [ '2026-02-30' ] ] + $main ) );
$z = $main; $z['zones'][0]['max_days'] = 0;
check( 'max below min is refused',                   null === foodify_delivery_normalise_config( $z ) );
$z = $main; $z['zones'][0]['states'] = []; $z['zones'][0]['pin_prefixes'] = [];
check( 'a zone matching nothing is refused',         null === foodify_delivery_normalise_config( $z ) );
$z = $main; $z['zones'][1]['states'] = [ 'delhi' ];
check( 'a state name instead of a WC code is refused', null === foodify_delivery_normalise_config( $z ) );
$z = $main; $z['zones'][1]['states'] = [ 'dl' ];
check( 'a lower-case state code is accepted as upper', [ 'DL' ] === foodify_delivery_normalise_config( $z )['zones'][1]['states'] );

echo "── delivery: the dates, worked by hand ──\n";
$noida = [ 'city' => 'Gautam Buddha Nagar', 'state' => 'UP' ];
$at    = static fn( string $t ): DateTimeImmutable => new DateTimeImmutable( $t );
$a = foodify_delivery_answer( '201304', $noida, $main, $at( '2026-11-10T13:59:00+05:30' ) );
check( 'Tue 13:59, NCR 1–2 days: Wed 11 – Thu 12',   'Delivers Wed 11 – Thu 12 Nov' === $a['message'] );
check( '… and it says why: ships today',             'To Gautam Buddha Nagar · Order by 2 pm to ship today.' === $a['detail'] );
check( '… the longest prefix (2013, NCR) beat the earlier "20"', 'Delhi NCR' === $a['estimate']['zone'] );
$a = foodify_delivery_answer( '201304', $noida, $main, $at( '2026-11-10T14:00:00+05:30' ) );
check( 'at 14:00 exactly the cut-off has passed',    'Delivers Thu 12 – Fri 13 Nov' === $a['message'] && 'To Gautam Buddha Nagar · Ships Wed 11 Nov.' === $a['detail'] );
$a = foodify_delivery_answer( '201304', $noida, $main, $at( '2026-11-10T08:30:00Z' ) );
check( 'the server clock in UTC is read as IST (08:30Z = 14:00 IST)', '2026-11-11' === $a['estimate']['dispatch'] );
$a = foodify_delivery_answer( '110001', [ 'city' => 'Central Delhi', 'state' => 'DL' ], $main, $at( '2026-11-14T15:00:00+05:30' ) );
check( 'Sat after cut-off → Sun closed → Mon holiday → ships Tue 17', '2026-11-17' === $a['estimate']['dispatch'] && 'Delivers Wed 18 – Thu 19 Nov' === $a['message'] );
$a = foodify_delivery_answer( '560001', [ 'city' => 'Bangalore', 'state' => 'KA' ], $main, $at( '2026-11-13T10:00:00+05:30' ) );
check( 'Fri, 3–6 days skipping Sunday: Tue 17 – Fri 20', 'Delivers Tue 17 – Fri 20 Nov' === $a['message'] );
$a = foodify_delivery_answer( '400001', [ 'city' => 'Mumbai', 'state' => 'MH' ], $main, $at( '2026-11-26T10:00:00+05:30' ) );
check( 'across a month end both months are named',  'Delivers Mon 30 Nov – Thu 3 Dec' === $a['message'] );
check( 'a single-day range is one date',            'Delivers Tue 10 Nov' === foodify_delivery_answer( '201304', $noida, $vec['configs']['sameday'], $at( '2026-11-10T11:00:00+05:30' ) )['message'] );

echo "── delivery: every way of not having an answer is said plainly ──\n";
check( 'an unserved state: "we don\'t deliver", with the city',
	"Sorry, we don't deliver to 795001 (Imphal West) yet." === foodify_delivery_answer( '795001', [ 'city' => 'Imphal West', 'state' => 'MN' ], $main, $at( '2026-11-11T09:00:00+05:30' ) )['message'] );
check( 'a PIN not in the table is "check the number", not "unserviceable"',
	'unknown' === foodify_delivery_answer( '999999', null, $main, $at( '2026-11-11T09:00:00+05:30' ) )['status'] );
check( 'a two-state PIN is unconfirmed, never guessed',
	'unconfirmed' === foodify_delivery_answer( '140119', [ 'city' => 'Rupnagar', 'state' => '' ], $main, $at( '2026-11-11T09:00:00+05:30' ) )['status'] );
check( 'five digits is invalid',                     'invalid' === foodify_delivery_answer( '20130', null, $main, $at( '2026-11-11T09:00:00+05:30' ) )['status'] );
check( 'no config: nothing to say, and the widget prints nothing',
	'unconfigured' === foodify_delivery_answer( '201304', $noida, [], $at( '2026-11-11T09:00:00+05:30' ) )['status']
	&& '' === foodify_delivery_widget_html( [], '/wp-json/foodify/v1/pin/' ) );

echo "── delivery: PHP agrees with every shared vector ──\n";
foreach ( $vec['cases'] as $c ) {
	$got = foodify_delivery_answer( $c['pin'], $c['lookup'], $vec['configs'][ $c['config'] ], $at( $c['now'] ) );
	check( $c['name'], $got === $c['expect'] );
}

echo "── delivery: the JS twin agrees too (node tests/delivery-vectors.js) ──\n";
// Run here as well, so the gate that runs this file runs the JS check. A
// missing node is a FAIL, not a skip: an unchecked twin looks exactly like a
// checked one in a summary line.
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
check( 'node is available to check the browser half', '' !== $node );
if ( '' !== $node ) {
	exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/delivery-vectors.js' ) . ' 2>&1', $js_out, $js_rc );
	$js_last = (string) end( $js_out );
	check( 'the JS answers every shared vector as PHP does — ' . $js_last, 0 === $js_rc && preg_match( '/^\d+ passed, 0 failed$/', preg_replace( '/\e\[[\d;]*m/', '', $js_last ) ) );
}

echo "── delivery: the widget and its script ──\n";
$w = foodify_delivery_widget_html( $main, '/wp-json/foodify/v1/pin/' );
check( 'the widget is printed hidden — no script, no dead field', str_contains( $w, 'data-fd-deliver hidden' ) );
check( 'it carries the normalised config as JSON',
	json_decode( html_entity_decode( (string) preg_replace( '/^.*data-config="([^"]*)".*$/s', '$1', $w ), ENT_QUOTES ), true ) === foodify_delivery_normalise_config( $main ) );
check( 'the field is labelled "Check delivery"',     str_contains( $w, '<label class="fd-deliver__label" for="fd-deliver-pin">Check delivery</label>' ) );
check( 'the answer is announced (aria-live)',        str_contains( $w, 'role="status" aria-live="polite"' ) );
$js = foodify_delivery_script();
check( 'the script exposes the pure answer for the prototype', str_contains( $js, 'w.foodifyDelivery={answer:answer' ) );
check( 'the script remembers the PIN under foodify_pin', str_contains( $js, "KEY='foodify_pin'" ) && str_contains( foodify_pin_prefill_script(), "localStorage.getItem('foodify_pin')" ) );
check( 'checkout prefill only fills an EMPTY field', str_contains( foodify_pin_prefill_script(), '&&!f.value' ) );
check( 'the script talks to no other domain',        ! preg_match( '#https?://#', $js . foodify_pin_prefill_script() ) );

/* ======================================================================== */
echo "── the veg mark is a claim, read from the declared diet ──\n";
check( 'Vegetarian → mark',                          foodify_diet_is_veg( 'Vegetarian' ) );
check( 'Vegan → mark (vegan food is vegetarian)',    foodify_diet_is_veg( 'Vegan' ) );
check( '"Non-vegetarian" never matches "vegetarian"', ! foodify_diet_is_veg( 'Non-vegetarian' ) );
check( 'egg is not vegetarian',                      ! foodify_diet_is_veg( 'Contains egg' ) && ! foodify_diet_is_veg( 'Eggetarian' ) );
check( 'blank → no mark, never a default',           ! foodify_diet_is_veg( '' ) && '' === foodify_veg_mark_html( '' ) );
check( 'the mark is labelled for a screen reader',   str_contains( foodify_veg_mark_html( 'Vegetarian' ), 'role="img" aria-label="Vegetarian"' ) );

echo "── unit price ──\n";
check( '₹185 for 80 g → ₹231.25/100 g',              '₹231.25/100 g' === foodify_unit_price( 185, '80 g' ) );
check( '1.2 kg is priced per kg',                    '₹154.17/kg' === foodify_unit_price( 185, '1.2 kg' ) );
check( 'exactly 1 kg is per kg',                     '₹450/kg' === foodify_unit_price( 450, '1000 g' ) );
check( '250 ml is per 100 ml',                       '₹74/100 ml' === foodify_unit_price( 185, '250 ml' ) );
check( '1 l is per litre',                           '₹99/l' === foodify_unit_price( 99, '1 l' ) );
check( '"3 packs · 240 g": the stated figure is the total', '₹77.08/100 g' === foodify_unit_price( 185, '3 packs · 240 g' ) );
check( '"3 × 80 g" is 240 g',                        '₹77.08/100 g' === foodify_unit_price( 185, '3 × 80 g' ) );
check( '"3 packs of 80 g" is 240 g — never the per-pack weight', '₹77.08/100 g' === foodify_unit_price( 185, '3 packs of 80 g' ) );
check( '"3 packs, 80 g each" is 240 g',              '₹77.08/100 g' === foodify_unit_price( 185, '3 packs, 80 g each' ) );
check( 'a breakdown that agrees is fine: "80 g (2 × 40 g)"', '₹231.25/100 g' === foodify_unit_price( 185, '80 g (2 × 40 g)' ) );
check( 'two quantities that disagree print nothing', null === foodify_unit_price( 185, '80 g / 90 g' ) );
check( 'mass and volume together print nothing',     null === foodify_unit_price( 185, '80 g + 100 ml' ) );
check( 'unparseable prints nothing',                 null === foodify_unit_price( 185, 'one pouch' ) && null === foodify_unit_price( 185, '' ) );
check( 'no price prints nothing',                    null === foodify_unit_price( 0, '80 g' ) );
check( '"80gms" reads',                              '₹231.25/100 g' === foodify_unit_price( 185, '80gms' ) );
check( 'Indian grouping in large figures',           '₹1,23,456.50' === foodify_inr( 123456.5 ) && '₹1,250' === foodify_inr( 1250 ) );

echo "── the gallery: back of pack is a required shot ──\n";
$shots = foodify_gallery_shots();
$back  = array_values( array_filter( $shots, static fn( $s ) => 'back' === $s['key'] ) )[0] ?? null;
check( 'there is a back-of-pack shot',               null !== $back );
check( '… required',                                 true === ( $back['required'] ?? false ) );
check( '… titled as the brief names it',             'Back of pack — the printed label, legible' === ( $back['title'] ?? '' ) );
check( 'shot 1 is still the pack front (Merchant Center)', 'front' === $shots[0]['key'] && 1 === $shots[0]['n'] );
check( 'shots are numbered in order',                range( 1, count( $shots ) ) === array_column( $shots, 'n' ) );
check( 'four untagged images leave the label as the gap', [ 'back' ] === foodify_gallery_gaps( foodify_shot_keys_from_tags( [ '', '', '', '' ] ) ) );
check( 'five untagged images complete the list',     [] === foodify_gallery_gaps( foodify_shot_keys_from_tags( [ '', '', '', '', '' ] ) ) );
check( 'a tagged label counts wherever it sits',     ! in_array( 'back', foodify_gallery_gaps( foodify_shot_keys_from_tags( [ 'back', '' ] ) ), true ) );
check( 'no images: every shot is a gap',             count( $shots ) === count( foodify_gallery_gaps( [] ) ) );

/* ======================================================================== */
echo "── stock alerts: who may sign up, and how often ──\n";
check( 'an address is normalised to lower case',     'asha@example.in' === foodify_stock_alert_email_ok( '  Asha@Example.IN ' ) );
check( 'not an address → refused',                   null === foodify_stock_alert_email_ok( 'asha' ) && null === foodify_stock_alert_email_ok( '' ) );
check( 'a domain with no dot is refused',            null === foodify_stock_alert_email_ok( 'a@localhost' ) );
check( 'over 254 characters is refused',             null === foodify_stock_alert_email_ok( str_repeat( 'a', 250 ) . '@x.in' ) );
$t0 = 1_800_000_000;
check( 'under the limit: allowed',                   foodify_stock_alert_gate( [ $t0 - 10, $t0 - 20 ], $t0, 3, 3600 )['allowed'] );
$g = foodify_stock_alert_gate( [ $t0 - 100, $t0 - 50, $t0 - 10 ], $t0, 3, 3600 );
check( 'at the limit: refused, retry when the oldest ages out', ! $g['allowed'] && 3500 === $g['retry_after'] );
check( 'requests older than the window do not count', foodify_stock_alert_gate( [ $t0 - 3600, $t0 - 4000, $t0 - 10 ], $t0, 2, 3600 )['allowed'] );

echo "── stock alerts: stored once, capped, withdrawable ──\n";
$h1 = foodify_stock_alert_hash( 'a1b2c3' );
$h2 = foodify_stock_alert_hash( 'd4e5f6' );
$r  = foodify_stock_alert_add( [], 'Asha@Example.in', $h1, $t0 );
check( 'first sign-up is added',                     'added' === $r['result'] && 1 === count( $r['subs'] ) );
check( 'what is stored: address, HASHED token, date, tries — nothing else',
	[ 'email' => 'asha@example.in', 'token' => $h1, 'created' => $t0, 'tries' => 0 ] === $r['subs'][0] );
check( 'the raw token is never stored',              ! str_contains( (string) json_encode( $r['subs'] ), 'a1b2c3' ) );
$r2 = foodify_stock_alert_add( $r['subs'], 'asha@example.IN', $h2, $t0 + 60 );
check( 'the same address again is not a second row', 'exists' === $r2['result'] && 1 === count( $r2['subs'] ) );
check( '… its token is replaced, so the new cancel button works', $h2 === $r2['subs'][0]['token'] );
check( '… and its original date is kept',            $t0 === $r2['subs'][0]['created'] );
$full = foodify_stock_alert_add( [], 'b@example.in', $h1, $t0, 2 );
$full = foodify_stock_alert_add( $full['subs'], 'c@example.in', $h1, $t0, 2 );
check( 'a full list refuses rather than grows',      'full' === foodify_stock_alert_add( $full['subs'], 'd@example.in', $h1, $t0, 2 )['result'] );
check( 'an invalid address is not stored',           'invalid' === foodify_stock_alert_add( [], 'nope', $h1, $t0 )['result'] );
check( 'a token that is not a sha256 is not stored', 'invalid' === foodify_stock_alert_add( [], 'a@b.in', 'abc', $t0 )['result'] );
$rm = foodify_stock_alert_remove( $r2['subs'], $h2 );
check( 'cancel by token removes it',                 $rm['removed'] && [] === $rm['subs'] );
check( 'a wrong token removes nothing',              ! foodify_stock_alert_remove( $r2['subs'], $h1 )['removed'] );
check( 'an empty token removes nothing',             ! foodify_stock_alert_remove( $r2['subs'], '' )['removed'] );
check( 'the privacy eraser forgets an address',      1 === foodify_stock_alert_forget( $r2['subs'], 'ASHA@example.in' )['removed'] );
check( 'retention drops what is older than the period', [] === foodify_stock_alert_expire( $r2['subs'], $t0 + 31 * 86400, 30 ) );
check( 'no retention configured keeps everything',  $r2['subs'] === foodify_stock_alert_expire( $r2['subs'], $t0 + 999 * 86400, 0 ) );

echo "── stock alerts: the one email, and the form ──\n";
$m = foodify_stock_alert_message( [ 'product' => 'Express Dal Fry', 'url' => 'https://letsfoodify.com/product/express-dal-fry/', 'site' => 'Foodify', 'asked' => '8 Nov 2026' ] );
check( 'subject names the product',                  'Back in stock: Express Dal Fry' === $m['subject'] );
check( 'the body links the product',                 str_contains( $m['body'], "https://letsfoodify.com/product/express-dal-fry/\n" ) );
check( 'it says it is the only email and the address is deleted',
	str_contains( $m['body'], 'This is that email, and the only one' ) && str_contains( $m['body'], 'deleted your address' ) );
check( 'no marketing: no offer, no discount, no "shop now"', ! preg_match( '/discount|% off|coupon|shop now|newsletter|offer/i', $m['subject'] . $m['body'] ) );
$f = foodify_stock_alert_form_html( [ 'product_id' => 7, 'product' => 'Dal <b>', 'action' => '/wp-admin/admin-post.php', 'nonce' => 'n0nce', 'privacy_url' => '/privacy-policy/' ] );
check( 'the form posts to admin-post with the action', str_contains( $f, 'action="/wp-admin/admin-post.php"' ) && str_contains( $f, 'name="action" value="foodify_stock_alert"' ) );
check( 'it carries a nonce',                         str_contains( $f, 'name="_fdnonce" value="n0nce"' ) );
check( 'it carries a honeypot',                      str_contains( $f, 'name="fd_website"' ) );
check( 'the privacy line is under the field',        str_contains( $f, 'We delete your address once it’s sent.' ) && str_contains( $f, 'href="/privacy-policy/"' ) );
check( 'no pre-ticked consent box, no marketing opt-in', ! str_contains( $f, 'checkbox' ) );
check( 'the anchor the card links to exists',        str_contains( $f, 'id="fd-notify"' ) );
$done = foodify_stock_alert_form_html( [ 'product_id' => 7, 'product' => 'Dal <b>', 'action' => '/a', 'nonce' => 'n', 'status' => 'added', 'email' => 'a@b.in', 'token' => str_repeat( 'f', 32 ) ] );
check( 'after signing up: the confirmation names the address', str_contains( $done, 'We&#039;ll email a@b.in once when Dal &lt;b&gt; is back' ) );
check( '… and offers a cancel button (POST, token)',  str_contains( $done, 'value="foodify_stock_alert_cancel"' ) && str_contains( $done, 'name="token" value="' . str_repeat( 'f', 32 ) . '"' ) );
check( 'an error re-shows the form with the reason', str_contains( foodify_stock_alert_form_html( [ 'product_id' => 7, 'product' => 'x', 'action' => '/a', 'nonce' => 'n', 'status' => 'invalid' ] ), 'role="alert">Please enter a valid email address.' ) );
// PRODUCT-19
$err = foodify_stock_alert_form_html( [ 'product_id' => 7, 'product' => 'x', 'action' => '/a', 'nonce' => 'n', 'status' => 'limited', 'email' => 'a"b@c.in' ] );
check( 'after a server error the address comes back, escaped', str_contains( $err, 'value="a&quot;b@c.in"' ) );
check( '… and the error is in the field\'s description', str_contains( $err, 'id="fd-notify-error"' ) && str_contains( $err, 'aria-describedby="fd-notify-error fd-notify-privacy"' ) );
check( 'no error, no stale address and no error id',  ! preg_match( '/id="fd-notify-email"[^>]*\bvalue=/', $f ) && str_contains( $f, 'aria-describedby="fd-notify-privacy"' ) && ! str_contains( $f, 'fd-notify-error' ) );
check( 'the form says "Sold out", the one word for it', str_contains( $f, '<p class="fd-notify__title">Sold out</p>' ) && ! str_contains( $f, 'Sold out for now' ) );
check( 'the form and the confirmation can take focus (tabindex=-1)', str_contains( $f, 'id="fd-notify" tabindex="-1"' ) && str_contains( $done, 'id="fd-notify" tabindex="-1"' ) );
check( 'the product script focuses #fd-notify on arrival', str_contains( foodify_pdp_script(), "location.hash==='#fd-notify'" ) && str_contains( foodify_pdp_script(), 'nt.focus()' ) );

/* ======================================================================== */
echo "── offers: only what checkout charges ──\n";
$pay = foodify_payment_defaults();
check( 'nothing configured → no lines, no block',    [] === foodify_offer_lines( [] ) && '' === foodify_offers_html( [] ) );
$lines = foodify_offer_lines( [
	'payment' => $pay, 'prepaid_available' => true, 'cod_available' => true,
	'free_shipping_min' => 599.0, 'shipping_flat' => 59.0, 'free_shipping_after_discounts' => true,
] );
$by = array_column( $lines, null, 'key' );
check( 'the prepaid line is the payments.php figure', 'Pay online and save ' . foodify_offer_inr( (float) $pay['prepaid_flat'] ) === $by['prepaid']['text'] );
check( '… and equals what the fee takes on a ₹599 cart',
	'Pay online and save ₹' . (int) foodify_prepaid_discount( 599, 'razorpay', $pay ) === $by['prepaid']['text'] );
check( 'free delivery names the zone\'s threshold',  'Free delivery on orders of ₹599 or more' === $by['shipping']['text'] );
check( '… the charge below it, and the coupon rule', '₹59 delivery below that, counted after any coupon.' === $by['shipping']['detail'] );
// PRODUCT-12: cash on delivery is a way to pay, not an offer — an assurance line.
check( 'COD is never an Offers line, even when the gateway is on', ! isset( $by['cod'] ) );
check( 'order: prepaid, shipping',                   [ 'prepaid', 'shipping' ] === array_column( $lines, 'key' ) );
check( 'COD with no cap is just "available" (assurance)', 'Cash on delivery available' === foodify_cod_line( [ 'payment' => $pay, 'cod_available' => true ] ) );
check( 'no COD gateway → no COD assurance',          '' === foodify_cod_line( [ 'payment' => $pay, 'prepaid_available' => true ] ) );
check( 'no online gateway → no prepaid line',        ! in_array( 'prepaid', array_column( foodify_offer_lines( [ 'payment' => $pay, 'cod_available' => true ] ), 'key' ), true ) );
check( 'unknown threshold → no shipping line',       ! in_array( 'shipping', array_column( foodify_offer_lines( [ 'free_shipping_min' => null ] ), 'key' ), true ) );
check( 'a formula flat rate is not printed',         'Counted after any coupon.' === foodify_offer_lines( [ 'free_shipping_min' => 599.0, 'shipping_flat' => null, 'free_shipping_after_discounts' => true ] )[0]['detail'] );
$rate = [ 'prepaid_flat' => 0.0, 'prepaid_rate' => 0.05, 'prepaid_max' => 40.0, 'prepaid_min_cart' => 300.0 ] + $pay;
$pl   = foodify_offer_lines( [ 'payment' => $rate, 'prepaid_available' => true ] )[0];
check( 'a rate reads as the rate and its cap',       'Pay online and save 5% (up to ₹40)' === $pl['text'] );
check( '… with its minimum cart',                    str_contains( $pl['detail'], 'On orders of ₹300 or more.' ) );
check( '… and the cap is the one the fee applies',   40.0 === foodify_prepaid_discount( 5000, 'razorpay', $rate ) && 0.0 === foodify_prepaid_discount( 299, 'razorpay', $rate ) );
check( 'a COD cap is stated',                        'Cash on delivery on orders up to ₹2,000' === foodify_cod_line( [ 'payment' => [ 'cod_max_value' => 2000.0 ] + $pay, 'cod_available' => true ] ) );
check( 'a zero discount is not an offer',            [] === foodify_offer_lines( [ 'payment' => [ 'prepaid_flat' => 0.0, 'prepaid_rate' => 0.0 ] + $pay, 'prepaid_available' => true ] ) );

echo "── offers: the public coupon ──\n";
$cl = foodify_offer_lines( [ 'coupon' => [ 'code' => 'diwali10', 'type' => 'percent', 'amount' => 10.0, 'min_spend' => 499.0, 'expires' => '2026-11-30' ] ] )[0];
check( 'percent coupon: code and amount',            'Use code DIWALI10: 10% off your order' === $cl['text'] );
check( '… with its minimum and expiry',              'On orders of ₹499 or more. Until 30 Nov.' === $cl['detail'] );
check( 'fixed cart coupon',                          'Use code SAVE100: ₹100 off your order' === foodify_offer_lines( [ 'coupon' => [ 'code' => 'SAVE100', 'type' => 'fixed_cart', 'amount' => 100.0 ] ] )[0]['text'] );
check( 'free-shipping-only coupon',                  'Use code SHIPFREE: free delivery' === foodify_offer_lines( [ 'coupon' => [ 'code' => 'SHIPFREE', 'type' => 'fixed_cart', 'amount' => 0.0, 'free_shipping' => true ] ] )[0]['text'] );
check( 'a coupon worth nothing is not shown',        [] === foodify_offer_lines( [ 'coupon' => [ 'code' => 'ZERO', 'type' => 'percent', 'amount' => 0.0 ] ] ) );
check( 'an unknown coupon type is not shown',        [] === foodify_offer_lines( [ 'coupon' => [ 'code' => 'X', 'type' => 'mystery', 'amount' => 10.0 ] ] ) );
check( 'the coupon comes first',                     'coupon' === foodify_offer_lines( [ 'payment' => $pay, 'prepaid_available' => true, 'coupon' => [ 'code' => 'A', 'type' => 'percent', 'amount' => 5.0 ] ] )[0]['key'] );
$html = foodify_offers_html( foodify_offer_lines( [ 'coupon' => [ 'code' => '<b>', 'type' => 'percent', 'amount' => 5.0 ] ] ) );
check( 'the block escapes what it prints',           str_contains( $html, 'Use code &lt;B&gt;' ) && str_contains( $html, 'class="fd-offers"' ) );

echo "── offers: one closed line under Add to cart (PRODUCT-3/10) ──\n";
$all  = foodify_offer_lines( [
	'coupon' => [ 'code' => 'diwali10', 'type' => 'percent', 'amount' => 10.0 ],
	'payment' => $pay, 'prepaid_available' => true, 'cod_available' => true,
	'free_shipping_min' => 599.0, 'shipping_flat' => 59.0, 'free_shipping_after_discounts' => true,
] );
$oh   = foodify_offers_html( $all );
check( 'the block is a <details>, closed by default',  str_starts_with( $oh, '<details class="fd-offers">' ) && ! str_contains( $oh, '<details class="fd-offers" open' ) && str_ends_with( $oh, '</details>' ) );
check( 'the summary counts and names every offer in a few words',
	'3 offers: Code DIWALI10: 10% off · Pay online, save ₹25 · Free delivery from ₹599' === foodify_offers_summary( $all ) );
check( '… and is the <summary>',                       str_contains( $oh, '<summary class="fd-offers__title">3 offers: Code DIWALI10: 10% off · Pay online, save ₹25 · Free delivery from ₹599</summary>' ) );
check( 'one offer is singular',                        '1 offer: Free delivery from ₹599' === foodify_offers_summary( foodify_offer_lines( [ 'free_shipping_min' => 599.0 ] ) ) );
check( 'the conditions are still behind it',           str_contains( $oh, '₹59 delivery below that, counted after any coupon.' ) );
// The order on the page is decided by filter priority on the add-to-cart form:
// delivery 10, combo panel 9, offers 11, alert form 20, assurances 25 — all
// APPENDED, so the button comes first (decision 5).
$src = static fn( string $f ): string => (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/' . $f );
check( 'Offers hang off the add-to-cart form at 11, no longer off the price block',
	(bool) preg_match( "/add_filter\\( 'render_block_woocommerce\\/add-to-cart-form'.*?foodify_offers_html.*?\\}, 11 \\);/s", $src( 'offers.php' ) )
	&& ! str_contains( $src( 'offers.php' ), "render_block_woocommerce/product-price" ) );
check( 'the delivery check is APPENDED after the form (it was prepended, above the button)',
	str_contains( $src( 'delivery-estimate.php' ), "return \$html . foodify_delivery_widget_html(" )
	&& ! str_contains( $src( 'delivery-estimate.php' ), ") . \$html; // Escaped" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
