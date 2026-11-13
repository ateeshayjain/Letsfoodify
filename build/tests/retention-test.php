<?php
/**
 * Lane D — retention: WhatsApp updates (9), repeat orders (12), cart reminder
 * (13), referrals (14). The pure halves, no WordPress.
 *
 * The rules worth defending by name:
 *  - nobody is messaged without consent, and nothing is sent twice for one event;
 *  - a signed link is refused when tampered with, expired, aimed at another
 *    purpose, or superseded (its nonce rotated);
 *  - a reminder is never sent to someone who has already ordered;
 *  - a referral is never self-referral, never a second order, and its coupons
 *    are never counted as partner sales — proved against the REAL
 *    foodify_partner_coupons_on_order() at the end.
 *
 *   php tests/retention-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

$inc = __DIR__ . '/../theme/foodify/inc/';
require $inc . 'address-book.php';
require $inc . 'whatsapp.php';
require $inc . 'repeat-orders.php';
require $inc . 'cart-recovery.php';
require $inc . 'referrals.php';
require $inc . 'partner-ledger.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

/* ========================================================================= */
echo "── 9 · WhatsApp: the number ──\n";
check( '+91 with spaces',                 '919876543210' === foodify_wa_phone( '+91 98765 43210' ) );
// 13 Nov 2026: checkout stripped a leading "91" from every number and refused these customers.
check( 'a ten-digit mobile that BEGINS 91 is a mobile, not a country code and eight digits', '919123456780' === foodify_wa_phone( '9123456780' ) && '919123456780' === foodify_wa_phone( '+91 91234 56780' ) );
$cf = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/checkout-fields.php' );
check( 'checkout validates the mobile by that same rule, and never strips a bare leading 91', str_contains( $cf, 'foodify_wa_phone( $raw )' ) && ! str_contains( $cf, "'/^(0|91)/'" ) );
check( 'leading 0 and a dash',            '919876543210' === foodify_wa_phone( '098765-43210' ) );
check( 'ten bare digits',                 '919876543210' === foodify_wa_phone( '9876543210' ) );
check( '91 without plus',                 '919876543210' === foodify_wa_phone( '919876543210' ) );
check( 'a landline is not a mobile',      null === foodify_wa_phone( '0120 4567890' ) );
check( 'a mobile must start 6–9',         null === foodify_wa_phone( '5876543210' ) );
check( 'too short is null',               null === foodify_wa_phone( '98765' ) );
check( 'a foreign number is null',        null === foodify_wa_phone( '+1 415 555 0100' ) );
check( 'empty is null',                   null === foodify_wa_phone( '' ) );

echo "── 9 · WhatsApp: which status sends which update ──\n";
check( 'processing = confirmed',          'order_confirmed' === foodify_wa_event_for_status( 'processing' ) );
check( 'completed = shipped (WP-10: "On its way"), not delivered', 'order_shipped' === foodify_wa_event_for_status( 'wc-completed' ) );
check( 'on-hold (COD awaiting a call) sends nothing', null === foodify_wa_event_for_status( 'on-hold' ) );
check( 'refunded sends nothing',          null === foodify_wa_event_for_status( 'refunded' ) );
check( 'delivered is not promised when no plugin registers it', null === foodify_wa_event_for_status( 'delivered', [ 'wc-processing', 'wc-completed' ] ) );
check( 'delivered maps once it is registered', 'delivered' === foodify_wa_event_for_status( 'delivered', [ 'wc-delivered' ] ) );
check( 'out for delivery likewise',       'out_for_delivery' === foodify_wa_event_for_status( 'wc-out-for-delivery', [ 'out-for-delivery' ] ) );

$tpls = [ 'order_confirmed' => 'foodify_order_confirmed', 'order_shipped' => 'foodify_order_shipped', 'order_shipped_tracking' => 'foodify_order_shipped_tracking' ];
check( 'a template needs a language',     null === foodify_wa_template_for( 'order_confirmed', $tpls, '' ) );
check( 'confirmed resolves',              [ 'name' => 'foodify_order_confirmed', 'language' => 'en', 'event' => 'order_confirmed' ] === foodify_wa_template_for( 'order_confirmed', $tpls, 'en' ) );
check( 'a tracking link picks the tracking template', 'order_shipped_tracking' === foodify_wa_template_for( 'order_shipped', $tpls, 'en', true )['event'] );
check( 'no tracking link, the plain one',  'order_shipped' === foodify_wa_template_for( 'order_shipped', $tpls, 'en', false )['event'] );
check( 'tracking without its template falls back, not silent', 'order_shipped' === foodify_wa_template_for( 'order_shipped', [ 'order_shipped' => 'x_shipped' ], 'en', true )['event'] );
check( 'an unconfigured event has no template', null === foodify_wa_template_for( 'delivered', $tpls, 'en' ) );
check( 'a malformed template name is refused', null === foodify_wa_template_for( 'order_confirmed', [ 'order_confirmed' => 'Order Confirmed!' ], 'en' ) );

echo "── 9 · WhatsApp: the request body ──\n";
check( 'rupees, Indian grouping',         '₹1,250' === foodify_rupees_whole( 1250 ) && '₹1,25,000' === foodify_rupees_whole( 125000 ) && '₹999' === foodify_rupees_whole( 999 ) && '₹1,00,00,000' === foodify_rupees_whole( 10000000 ) );
check( 'confirmed: name, number, total',  [ 'Priya', '1042', '₹1,250' ] === foodify_wa_body_params( 'order_confirmed', [ 'first_name' => 'Priya', 'number' => '1042', 'total' => 1250.0 ] ) );
check( 'no name is "there", not blank',   'there' === foodify_wa_body_params( 'order_shipped', [ 'number' => '1' ] )[0] );
$p = foodify_wa_payload( '919876543210', [ 'name' => 'foodify_order_shipped', 'language' => 'en' ], [ 'Priya', '1042' ] );
check( 'the Cloud API template shape', [
	'messaging_product' => 'whatsapp',
	'recipient_type'    => 'individual',
	'to'                => '919876543210',
	'type'              => 'template',
	'template'          => [
		'name'       => 'foodify_order_shipped',
		'language'   => [ 'code' => 'en' ],
		'components' => [ [ 'type' => 'body', 'parameters' => [ [ 'type' => 'text', 'text' => 'Priya' ], [ 'type' => 'text', 'text' => '1042' ] ] ] ],
	],
] === $p );
$p = foodify_wa_payload( '919876543210', [ 'name' => 'r', 'language' => 'en' ], [], 'TOKEN' );
check( 'a URL button carries the link suffix', 'button' === $p['template']['components'][0]['type'] && 'TOKEN' === $p['template']['components'][0]['parameters'][0]['text'] && '0' === $p['template']['components'][0]['index'] );

echo "── 9 · WhatsApp: configured, or not at all ──\n";
$on = foodify_wa_config_state( true, true, 'yes', $tpls, 'en', 'v23.0' );
check( 'everything set = active',         $on['active'] && ! $on['half'] );
$c = foodify_wa_config_state( false, false, 'yes', $tpls, 'en', 'v23.0' );
check( 'enabled without wp-config credentials is OFF and says why', ! $c['active'] && $c['half'] && in_array( 'FOODIFY_WA_TOKEN (wp-config.php)', $c['missing'], true ) );
check( 'credentials but the option not "yes" is OFF', ! foodify_wa_config_state( true, true, 'no', $tpls, 'en', 'v23.0' )['active'] );
check( 'no order template is OFF',        ! foodify_wa_config_state( true, true, 'yes', [ 'repeat_reminder' => 'x' ], 'en', 'v23.0' )['active'] );
check( 'a version that is not vNN.N is OFF', ! foodify_wa_config_state( true, true, 'yes', $tpls, 'en', 'latest' )['active'] );
$none = foodify_wa_config_state( false, false, '', [], '', '' );
check( 'nothing set = off, and no nagging notice', ! $none['active'] && ! $none['half'] );

echo "── 9 · WhatsApp: should we send ──\n";
$ok = [ 'active' => true, 'consent' => true, 'phone' => '919876543210', 'template' => [ 'name' => 'x' ], 'already_sent' => false, 'order_ok' => true ];
check( 'all clear sends',                 true === foodify_wa_should_send( $ok )['send'] );
check( 'no consent, no message',          'no_consent' === foodify_wa_should_send( array_merge( $ok, [ 'consent' => false ] ) )['reason'] );
check( 'switched off, no message',        'inactive' === foodify_wa_should_send( array_merge( $ok, [ 'active' => false ] ) )['reason'] );
check( 'an unusable number, no message',  'no_phone' === foodify_wa_should_send( array_merge( $ok, [ 'phone' => null ] ) )['reason'] );
check( 'no template, no message',         'no_template' === foodify_wa_should_send( array_merge( $ok, [ 'template' => null ] ) )['reason'] );
check( 'never twice for one event',       'already_sent' === foodify_wa_should_send( array_merge( $ok, [ 'already_sent' => true ] ) )['reason'] );
check( 'a refunded order gets no "shipped"', 'order_state' === foodify_wa_should_send( array_merge( $ok, [ 'order_ok' => false ] ) )['reason'] );
check( 'missing consent key is not consent', false === foodify_wa_should_send( [ 'active' => true, 'phone' => '91', 'template' => [] ] )['send'] );

echo "── 9 · WhatsApp: retry, and what is logged ──\n";
check( 'a 500 retries in a minute',       60 === foodify_wa_retry_after( 500, 1 ) );
check( 'a network failure retries',       60 === foodify_wa_retry_after( 0, 1 ) );
check( 'rate-limited backs off further',  7200 === foodify_wa_retry_after( 429, 4 ) );
check( 'and gives up after four',         null === foodify_wa_retry_after( 503, 5 ) );
check( 'a 400 (bad template / not on WhatsApp) is not retried', null === foodify_wa_retry_after( 400, 1 ) );
check( 'a 401 (bad token) is not retried', null === foodify_wa_retry_after( 401, 1 ) );
$note = foodify_wa_failure_note( 'order_shipped', 400, 132001, null );
check( 'the note names event, status and code', str_contains( $note, 'order_shipped' ) && str_contains( $note, '400' ) && str_contains( $note, '132001' ) );
check( 'the note can carry no phone and no body', ! preg_match( '/\d{10}/', $note ) && ! str_contains( $note, 'Priya' ) );
check( 'the retry is stated plainly',     str_contains( foodify_wa_failure_note( 'x', 500, 0, 300 ), 'Retrying in 5 min' ) );
$f = foodify_wa_optin_field();
check( 'the checkout box is unticked and optional', 0 === $f['default'] && false === $f['required'] && 'checkbox' === $f['type'] );
check( 'it says exactly what it does',    'Send order updates on WhatsApp to this number' === $f['label'] );

/* ========================================================================= */
echo "── signed links ──\n";
$key    = 'k-repeat';
$claims = [ 'p' => 'repeat', 'u' => 7, 's' => 'rabc', 'n' => 'n1', 'e' => 2000 ];
$t      = foodify_link_token_sign( $claims, $key );
check( 'a good token verifies to its claims', $claims === foodify_link_token_verify( $t, $key, 'repeat', 1000 ) );
check( 'URL-safe: no + / = characters',   1 === preg_match( '/^[A-Za-z0-9_.-]+$/', $t ) );
[ $b, $s ] = explode( '.', $t );
$forged = foodify_b64url( (string) json_encode( [ 'p' => 'repeat', 'u' => 8, 's' => 'rabc', 'n' => 'n1', 'e' => 2000 ] ) ) . '.' . $s;
check( 'a different user under the old signature is refused', null === foodify_link_token_verify( $forged, $key, 'repeat', 1000 ) );
check( 'another key is refused',          null === foodify_link_token_verify( $t, 'other', 'repeat', 1000 ) );
check( 'an empty key is refused',         null === foodify_link_token_verify( $t, '', 'repeat', 1000 ) );
check( 'another purpose is refused',      null === foodify_link_token_verify( $t, $key, 'cart', 1000 ) );
check( 'an expired token is refused',     null === foodify_link_token_verify( $t, $key, 'repeat', 2001 ) );
check( 'a token without a nonce is refused', null === foodify_link_token_verify( foodify_link_token_sign( [ 'p' => 'repeat', 'e' => 2000 ], $key ), $key, 'repeat', 1000 ) );
check( 'garbage is refused, not a warning', null === foodify_link_token_verify( 'a.b.c', $key, 'repeat', 1 ) && null === foodify_link_token_verify( '%%.%%', $key, 'repeat', 1 ) );

/* ========================================================================= */
echo "── 12 · repeat orders: dates ──\n";
check( 'two weeks on',                    '2026-11-22' === foodify_repeat_next_date( '2026-11-08', 2 ) );
check( 'across a month and a year',       '2027-01-08' === foodify_repeat_next_date( '2026-12-11', 4 ) );
check( 'a leap day is a real day',        '2028-02-29' === foodify_repeat_next_date( '2028-02-15', 2 ) );
check( 'a date reads like a person says it', 'Thu 12 Nov' === foodify_date_label( '2026-11-12' ) );
check( 'only 2, 4 or 6 weeks',            [ 2, 4, 6 ] === foodify_repeat_intervals() );

echo "── 12 · repeat orders: a schedule ──\n";
$items = [ [ 'product_id' => 11, 'qty' => 2 ], [ 'product_id' => 12, 'variation_id' => 13, 'qty' => 1 ], [ 'product_id' => 0, 'qty' => 1 ] ];
$s = foodify_repeat_new( $items, 2, '2026-11-08', 'a1', 1042, 'r1', 'n1' );
check( 'a schedule starts one interval out', '2026-11-22' === $s['next'] && 'active' === $s['status'] );
check( 'junk lines are dropped',          2 === count( $s['items'] ) && 13 === $s['items'][1]['variation_id'] );
check( '3 weeks is not on offer',         null === foodify_repeat_new( $items, 3, '2026-11-08', '', 1, 'r', 'n' ) );
check( 'no items, no schedule',           null === foodify_repeat_new( [], 2, '2026-11-08', '', 1, 'r', 'n' ) );

echo "── 12 · repeat orders: when the one reminder goes ──\n";
check( 'its link lasts until the next cycle\'s reminder', '2026-12-03' === foodify_repeat_link_last_day( $s, 2 ) );
check( 'not three days early',            false === foodify_repeat_reminder_due( $s, '2026-11-19', 2 ) );
check( 'two days early: due',             true === foodify_repeat_reminder_due( $s, '2026-11-20', 2 ) );
check( 'once per cycle',                  false === foodify_repeat_reminder_due( array_merge( $s, [ 'reminded_for' => '2026-11-22' ] ), '2026-11-21', 2 ) );
check( 'paused: never',                   false === foodify_repeat_reminder_due( array_merge( $s, [ 'status' => 'paused' ] ), '2026-11-21', 2 ) );
check( 'after the cycle ends: not due',   false === foodify_repeat_reminder_due( $s, '2026-12-04', 2 ) );
$r = foodify_repeat_roll_missed( array_merge( $s, [ 'coupon' => 'repeat-x' ] ), '2026-12-04', 2, 'n2' );
check( 'a missed cycle rolls forward',    $r['rolled'] && '2026-12-06' === $r['schedule']['next'] );
check( 'and its link and coupon die with it', 'n2' === $r['schedule']['nonce'] && 'repeat-x' === $r['dropped_coupon'] && '' === $r['schedule']['coupon'] );
check( 'a live cycle does not roll',      false === foodify_repeat_roll_missed( $s, '2026-11-25', 2, 'n2' )['rolled'] );
$a = foodify_repeat_advance( $s, '2026-11-20', 'n3' );
check( 'ordering early keeps the rhythm', '2026-12-06' === $a['next'] && 'n3' === $a['nonce'] );
check( 'ordering late restarts from today', '2026-12-10' === foodify_repeat_advance( $s, '2026-11-26', 'n3' )['next'] );

echo "── 12 · repeat orders: pause, skip, change, cancel ──\n";
$list = [ array_merge( $s, [ 'coupon' => 'repeat-c' ] ) ];
$x = foodify_repeat_apply( $list, 'r1', 'pause', [], '2026-11-20', 'n9' );
check( 'pause stops reminders and kills the outstanding link', 'paused' === $x['schedules'][0]['status'] && 'n9' === $x['schedules'][0]['nonce'] && 'repeat-c' === $x['drop_coupon'] );
$y = foodify_repeat_apply( $x['schedules'], 'r1', 'resume', [], '2026-12-30', 'n10' );
check( 'resume after a long pause lands on a future date', 'active' === $y['schedules'][0]['status'] && $y['schedules'][0]['next'] >= '2026-12-30' );
$z = foodify_repeat_apply( $list, 'r1', 'skip', [], '2026-11-20', 'n11' );
check( 'skip moves one interval and drops the coupon', '2026-12-06' === $z['schedules'][0]['next'] && 'repeat-c' === $z['drop_coupon'] );
check( 'interval to 6 weeks',             6 === foodify_repeat_apply( $list, 'r1', 'interval', [ 'weeks' => 6 ], '2026-11-20', 'n' )['schedules'][0]['weeks'] );
check( 'interval to 5 weeks is refused',  false === foodify_repeat_apply( $list, 'r1', 'interval', [ 'weeks' => 5 ], '2026-11-20', 'n' )['ok'] );
$c = foodify_repeat_apply( $list, 'r1', 'cancel', [], '2026-11-20', 'n' );
check( 'cancel removes it',               [] === $c['schedules'] && 'repeat-c' === $c['drop_coupon'] );
check( 'someone else\'s id resolves to nothing', false === foodify_repeat_apply( $list, 'rX', 'cancel', [], '2026-11-20', 'n' )['ok'] );

echo "── 12 · repeat orders: rebuilding the cart ──\n";
$cat = [
	'11:0'  => [ 'name' => 'Express Dal Fry', 'purchasable' => true, 'in_stock' => true, 'max' => null ],
	'12:13' => [ 'name' => 'Masala Oats', 'purchasable' => true, 'in_stock' => false ],
	'14:0'  => [ 'name' => 'Poha', 'purchasable' => true, 'in_stock' => true, 'max' => 1 ],
];
$plan = foodify_cart_rebuild_plan( [ [ 'product_id' => 11, 'qty' => 2 ], [ 'product_id' => 12, 'variation_id' => 13, 'qty' => 1 ], [ 'product_id' => 14, 'qty' => 3 ], [ 'product_id' => 99, 'qty' => 1 ] ], $cat );
check( 'what is in stock is added',       [ [ 'product_id' => 11, 'variation_id' => 0, 'qty' => 2 ], [ 'product_id' => 14, 'variation_id' => 0, 'qty' => 1 ] ] === $plan['add'] );
check( 'a sold-out item is left out BY NAME', [ 'Masala Oats' ] === $plan['skipped'] && str_contains( $plan['message'], 'Masala Oats is sold out, so we left it out.' ) );
check( 'short stock is reduced and said', str_contains( $plan['message'], 'Only 1 Poha left' ) );
check( 'a deleted product is counted, not invented', 1 === $plan['gone'] && str_contains( $plan['message'], 'no longer on the menu' ) );
$none = foodify_cart_rebuild_plan( [ [ 'product_id' => 12, 'variation_id' => 13, 'qty' => 1 ] ], $cat );
check( 'all sold out: nothing added, and it says so', [] === $none['add'] && str_contains( $none['message'], 'cart is unchanged' ) );
$two = foodify_cart_rebuild_plan( [ [ 'product_id' => 1, 'qty' => 1 ], [ 'product_id' => 2, 'qty' => 1 ] ], [ '1:0' => [ 'name' => 'A', 'purchasable' => true, 'in_stock' => false ], '2:0' => [ 'name' => 'B', 'purchasable' => false, 'in_stock' => true ] ] );
check( 'two sold out read as a sentence', str_contains( $two['message'], 'A and B are sold out, so we left them out.' ) );

echo "── 12 · repeat orders: the discount ──\n";
check( 'unset = no discount',             0.0 === foodify_repeat_discount_pct( '' ) );
check( 'not a number = no discount',      0.0 === foodify_repeat_discount_pct( 'ten' ) );
check( '10 = 10%',                        10.0 === foodify_repeat_discount_pct( '10' ) );
check( '100% is not a discount, it is a mistake', 0.0 === foodify_repeat_discount_pct( '100' ) );
check( 'negative is nothing',             0.0 === foodify_repeat_discount_pct( '-5' ) );
$plain = foodify_repeat_labels( 0.0, 2 );
check( 'no discount: "Repeat this order", never "save"', 'Repeat this order' === $plain['title'] && false === stripos( $plain['title'] . $plain['lead'], 'save' ) );
check( 'and never "subscribe"',           false === stripos( $plain['title'] . $plain['lead'], 'subscribe' ) );
check( 'the copy says no auto-debit',     str_contains( $plain['lead'], 'Nothing is charged automatically' ) );
check( 'with a discount it may say save', 'Subscribe & save 7.5%' === foodify_repeat_labels( 7.5, 2 )['title'] );
check( 'one day before reads naturally',  str_contains( foodify_repeat_labels( 0, 1 )['lead'], 'the day before' ) );
check( 'a second repeat coupon on one cart is refused', false === foodify_repeat_coupon_allowed( 'repeat-b', 'repeat', [ 'repeat-a' => 'repeat' ] ) );
check( 'the same code is WooCommerce\'s own business', true === foodify_repeat_coupon_allowed( 'repeat-a', 'repeat', [ 'REPEAT-A' => 'repeat' ] ) );
check( 'other coupons are not this rule\'s concern', true === foodify_repeat_coupon_allowed( 'INFLU10', '', [ 'repeat-a' => 'repeat' ] ) );

echo "── 12 · repeat orders: configured, or not at all ──\n";
check( 'no reminder days = off, quietly', ! foodify_repeat_config_state( '', '', true )['active'] && ! foodify_repeat_config_state( '', '', true )['half'] );
check( 'reminder days set = on',          foodify_repeat_config_state( '2', '', true )['active'] );
check( 'a discount without reminder days is half-configured', foodify_repeat_config_state( '', '10', true )['half'] );
check( 'nine days ahead of a two-week cycle is refused', ! foodify_repeat_config_state( '9', '', true )['active'] );
check( 'a garbled discount is refused, not read as 0', foodify_repeat_config_state( '2', 'ten', true )['half'] );
check( 'no Action Scheduler, no feature', ! foodify_repeat_config_state( '2', '', false )['active'] );

echo "── 12 · repeat orders: what the customer sees ──\n";
$html = foodify_repeat_offer_html( [ 'order_id' => 1042, 'labels' => $plain, 'nonce_field' => '<input type="hidden" name="foodify_repeat_nonce" value="N">' ] );
check( 'the offer is a form with the three intervals', 3 === substr_count( $html, '<option' ) && str_contains( $html, 'name="foodify_repeat_action" value="create"' ) && str_contains( $html, 'value="1042"' ) );
check( 'the select has a label',          str_contains( $html, 'for="fd-repeat-weeks-1042"' ) && str_contains( $html, 'id="fd-repeat-weeks-1042"' ) );
check( 'the nonce is in the form',        str_contains( $html, 'foodify_repeat_nonce' ) );
$set = foodify_repeat_offer_html( [ 'order_id' => 1, 'labels' => $plain, 'nonce_field' => '', 'existing' => [ 'weeks' => 4, 'next' => '2026-12-06' ], 'manage_url' => '/my-account/repeat-orders/' ] );
check( 'an order already repeating says so instead', str_contains( $set, 'every 4 weeks' ) && str_contains( $set, 'Sun 6 Dec' ) && ! str_contains( $set, '<form' ) );
$rows = [ [ 'id' => 'r1', 'weeks' => 2, 'next' => '2026-11-22', 'status' => 'active', 'summary' => 'Dal <Fry> × 2' ], [ 'id' => 'r2', 'weeks' => 4, 'next' => '2026-12-01', 'status' => 'paused', 'summary' => 'Poha' ] ];
$lh = foodify_repeat_list_html( $rows, [ 'lead' => 2, 'active' => true, 'nonces' => [ 'pause' => '<i>NP</i>' ] ] );
check( 'active rows offer pause and skip', str_contains( $lh, '>Pause<' ) && str_contains( $lh, '>Skip next<' ) && str_contains( $lh, '<i>NP</i>' ) );
check( 'paused rows offer resume',        str_contains( $lh, '>Resume<' ) && str_contains( $lh, 'Paused — no reminders' ) );
check( 'the next date and the reminder date are both shown', str_contains( $lh, 'Next order: Sun 22 Nov · reminder Fri 20 Nov' ) );
check( 'item names are escaped',          str_contains( $lh, 'Dal &lt;Fry&gt;' ) );
check( 'no schedules: an honest empty state', str_contains( foodify_repeat_list_html( [], [ 'lead' => 2, 'active' => true, 'nonces' => [] ] ), 'No repeat orders yet' ) );
$m = foodify_repeat_email( [ 'first_name' => '', 'date' => '2026-11-22', 'summary' => 'Poha', 'link' => 'https://x/?a=1&b=2', 'manage' => 'https://x/m', 'pct' => 0.0 ] );
check( 'the email names the date',        'Your Foodify repeat order is due Sun 22 Nov' === $m['subject'] );
check( 'its link is escaped',             str_contains( $m['body'], 'href="https://x/?a=1&amp;b=2"' ) );
check( 'no discount, no discount line',   ! str_contains( $m['body'], 'discount' ) );
check( 'with one, it is named',           str_contains( foodify_repeat_email( array_merge( [ 'first_name' => 'A', 'date' => '2026-11-22', 'summary' => '', 'link' => '', 'manage' => '' ], [ 'pct' => 10.0 ] ) )['body'], '10% repeat-order discount' ) );

/* ========================================================================= */
echo "── 13 · cart reminder: configured, or not at all ──\n";
check( 'unset = off, quietly',            ! foodify_cart_config_state( '', true )['active'] && ! foodify_cart_config_state( '', true )['half'] );
check( '60 minutes = on',                 foodify_cart_config_state( '60', true )['active'] );
check( '5 minutes is too pushy to ship',  foodify_cart_config_state( '5', true )['half'] );
check( '"about an hour"',                 'about an hour' === foodify_cart_delay_label( 60 ) );
check( '"about 2 hours"',                 'about 2 hours' === foodify_cart_delay_label( 120 ) );
check( '"about 90 minutes"',              'about 90 minutes' === foodify_cart_delay_label( 90 ) );

echo "── 13 · cart reminder: consent ──\n";
$cf = foodify_cart_consent_field( 60 );
check( 'the box is unticked by default',  0 === $cf['default'] && false === $cf['required'] );
check( 'its words are the brief\'s',      'Email me my cart if I don\'t finish checking out' === $cf['label'] );
check( 'it says how many emails and when', str_contains( $cf['description'], 'One email, about an hour later' ) );
check( 'it sits under email (20)',        21 === $cf['priority'] );
check( 'pre-ticked only by the customer\'s own earlier yes', 1 === foodify_cart_consent_field( 60, true )['default'] );
check( 'the account setting defaults off', ! str_contains( foodify_cart_account_field_html( false, 60 ), 'checked' ) );
check( 'and remembers a yes',             str_contains( foodify_cart_account_field_html( true, 60 ), ' checked' ) );
check( 'emails are checked and lowercased', 'priya@x.in' === foodify_cart_email( ' Priya@X.in ' ) && null === foodify_cart_email( 'priya@' ) );

echo "── 13 · cart reminder: what is kept ──\n";
$lines = foodify_cart_snapshot_lines( [ 'k1' => [ 'product_id' => 11, 'variation_id' => 0, 'quantity' => 2, 'line_total' => 350, 'data' => 'obj' ], 'k2' => [ 'product_id' => 12, 'quantity' => 0 ] ] );
check( 'product, variation, quantity — and no price', [ [ 'product_id' => 11, 'variation_id' => 0, 'qty' => 2 ] ] === $lines );

echo "── 13 · cart reminder: one reminder, never to a buyer ──\n";
$snap = [ 'lines' => $lines, 'activity' => 10000, 'sent' => 0 ];
$d = foodify_cart_reminder_due( $snap, 10000 + 59 * 60, 60, false );
check( 'not before the delay, and says when', false === $d['send'] && 'not_yet' === $d['reason'] && 13600 === $d['at'] );
check( 'due after the delay',             true === foodify_cart_reminder_due( $snap, 13600, 60, false )['send'] );
check( 'never to someone who placed the order', 'ordered' === foodify_cart_reminder_due( $snap, 99999, 60, true )['reason'] );
check( 'never a second one',              'already_sent' === foodify_cart_reminder_due( array_merge( $snap, [ 'sent' => 13700 ] ), 99999, 60, false )['reason'] );
check( 'an empty cart is not a reminder', 'empty' === foodify_cart_reminder_due( array_merge( $snap, [ 'lines' => [] ] ), 99999, 60, false )['reason'] );
check( 'kept 7 days after sending, then gone', ! foodify_cart_snapshot_expired( [ 'sent' => 100 ], 100 + 7 * 86400 - 1, 60 ) && foodify_cart_snapshot_expired( [ 'sent' => 100 ], 100 + 7 * 86400, 60 ) );
check( 'never sent is not kept forever',  foodify_cart_snapshot_expired( [ 'activity' => 100, 'sent' => 0 ], 100 + 3600 + 7 * 86400, 60 ) );
$em = foodify_cart_email_parts( [ 'summary' => 'Poha', 'link' => 'https://s/?foodify_cart=T', 'stop' => 'https://s/?foodify_cart_stop=S' ] );
check( 'the email links back to the cart and offers deletion', str_contains( $em['body'], 'foodify_cart=T' ) && str_contains( $em['body'], 'foodify_cart_stop=S' ) && str_contains( $em['body'], 'delete it in 7 days' ) );
check( 'no fake urgency in it',           false === stripos( $em['subject'] . $em['body'], 'hurry' ) && false === stripos( $em['body'], 'only a few' ) );
$js = foodify_cart_consent_script();
check( 'the script reads the two fields it claims', str_contains( $js, 'foodify_cart_consent' ) && str_contains( $js, 'billing_email' ) );
check( 'and talks to nobody but the site', ! preg_match( '#https?://#', $js ) );

echo "── 13 · rate limit ──\n";
$g = foodify_rate_gate( [ 1, 2, 3 ], 10, 3, 600 );
check( 'the fourth inside the window is refused', false === $g['allowed'] );
$g = foodify_rate_gate( [ 1, 2, 3 ], 700, 3, 600 );
check( 'and allowed once the window has rolled', true === $g['allowed'] && [ 700 ] === $g['kept'] );

/* ========================================================================= */
echo "── 14 · referrals: configured, or not at all ──\n";
$cfgRaw = [ 'give' => '100', 'get' => '100', 'min' => '499', 'cap' => '5' ];
$cfg = foodify_referral_config_state( $cfgRaw );
check( 'all four set = on',               $cfg['active'] && 100.0 === $cfg['give'] && 5 === $cfg['cap'] );
foreach ( [ 'give', 'get', 'min', 'cap' ] as $k ) {
	check( "no $k = off, and named",      ! foodify_referral_config_state( array_merge( $cfgRaw, [ $k => '' ] ) )['active'] && foodify_referral_config_state( array_merge( $cfgRaw, [ $k => '' ] ) )['half'] );
}
check( 'cap "0" is a choice: no cap',     foodify_referral_config_state( array_merge( $cfgRaw, [ 'cap' => '0' ] ) )['active'] );
check( 'giving more than the minimum is refused', ! foodify_referral_config_state( array_merge( $cfgRaw, [ 'give' => '500' ] ) )['active'] );
check( 'nothing set = off, quietly',      ! foodify_referral_config_state( [] )['half'] );

echo "── 14 · referrals: the code ──\n";
$code = foodify_referral_code_from( "\x00\x01\x02\x03\x04\x05" );
check( 'REF + six unambiguous characters', 1 === preg_match( '/^REF[A-Z2-9]{6}$/', $code ) && ! preg_match( '/[01OIL]/', substr( $code, 3 ) ) );
check( 'is_code recognises its own format', foodify_referral_is_code( $code ) && foodify_referral_is_code( strtolower( $code ) ) && ! foodify_referral_is_code( 'INFLU10' ) );

echo "── 14 · referrals: one person is one person ──\n";
check( 'gmail dots and +tags fold',       'priya@gmail.com' === foodify_referral_email_key( 'P.riya+food@Gmail.com' ) );
check( 'googlemail is gmail',             'priya@gmail.com' === foodify_referral_email_key( 'priya@googlemail.com' ) );
check( 'other domains keep their dots',   'p.riya@x.in' === foodify_referral_email_key( 'p.riya+a@x.in' ) );

echo "── 14 · referrals: the friend ──\n";
$ref  = [ 'user_id' => 7, 'email' => 'priya@gmail.com', 'phone' => '9876543210', 'address_fps' => [ 'flat12|sector62|noida|201301' ] ];
$cfgA = [ 'active' => true, 'give' => 100.0, 'min' => 499.0 ];
$new  = [ 'prior_orders' => 0, 'already_referred' => false ];
$fr   = [ 'user_id' => 0, 'email' => 'amit@x.in', 'phone' => '9811111111', 'address_fp' => 'h4|dlf|gurgaon|122002', 'subtotal' => 600.0 ];
check( 'a new friend over the minimum: yes', true === foodify_referral_friend_check( $fr, $ref, $new, $cfgA )['ok'] );
check( 'self: same account',              'self' === foodify_referral_friend_check( array_merge( $fr, [ 'user_id' => 7 ] ), $ref, $new, $cfgA )['reason'] );
check( 'self: same inbox, dotted',        'self' === foodify_referral_friend_check( array_merge( $fr, [ 'email' => 'pri.ya+2@gmail.com' ] ), $ref, $new, $cfgA )['reason'] );
check( 'self: same mobile, typed differently', 'self' === foodify_referral_friend_check( array_merge( $fr, [ 'phone' => '+91 98765-43210' ] ), $ref, $new, $cfgA )['reason'] );
$fpSame = foodify_address_fingerprint( foodify_address_normalise( [ 'address_1' => 'Flat 12', 'address_2' => 'Sector 62', 'city' => 'Noida', 'postcode' => '201301' ] ) );
check( 'self: the referrer\'s own address (address-book fingerprint)', 'self' === foodify_referral_friend_check( array_merge( $fr, [ 'address_fp' => $fpSame ] ), $ref, $new, $cfgA )['reason'] );
check( 'not a first order by email or mobile', 'not_first' === foodify_referral_friend_check( $fr, $ref, [ 'prior_orders' => 1 ], $cfgA )['reason'] );
check( 'one reward per friend, across referrers', 'not_first' === foodify_referral_friend_check( $fr, $ref, [ 'prior_orders' => 0, 'already_referred' => true ], $cfgA )['reason'] );
$short = foodify_referral_friend_check( array_merge( $fr, [ 'subtotal' => 449.0 ] ), $ref, $new, $cfgA );
check( 'under the minimum says how much more', 'min' === $short['reason'] && str_contains( $short['message'], 'Add ₹50 more' ) && str_contains( $short['message'], 'above ₹499' ) );
check( 'switched off says so',            'inactive' === foodify_referral_friend_check( $fr, $ref, $new, [ 'active' => false ] )['reason'] );
check( 'an unknown subtotal is not judged yet', true === foodify_referral_friend_check( array_diff_key( $fr, [ 'subtotal' => 1 ] ), $ref, $new, $cfgA )['ok'] );

echo "── 14 · referrals: the referrer's reward ──\n";
$rs = [ 'has_referral' => true, 'get' => 100.0, 'decided' => false, 'referrer_exists' => true, 'friend_rewarded' => false, 'total' => 650.0, 'refunded' => 0.0, 'min' => 499.0, 'issued_this_month' => 0, 'cap' => 5 ];
check( 'a completed first order earns it', true === foodify_referral_reward_decision( $rs )['issue'] );
check( 'never twice for one order',       'decided' === foodify_referral_reward_decision( array_merge( $rs, [ 'decided' => true ] ) )['reason'] );
check( 'never twice for one friend',      'friend_rewarded' === foodify_referral_reward_decision( array_merge( $rs, [ 'friend_rewarded' => true ] ) )['reason'] );
check( 'the monthly cap holds',           'capped' === foodify_referral_reward_decision( array_merge( $rs, [ 'issued_this_month' => 5 ] ) )['reason'] );
check( 'cap 0 means none',                true === foodify_referral_reward_decision( array_merge( $rs, [ 'issued_this_month' => 50, 'cap' => 0 ] ) )['issue'] );
check( 'refunded before completion: nothing', 'refunded' === foodify_referral_reward_decision( array_merge( $rs, [ 'refunded' => 650.0 ] ) )['reason'] );
check( 'a partial refund that leaves it above the minimum still earns', true === foodify_referral_reward_decision( array_merge( $rs, [ 'refunded' => 100.0 ] ) )['issue'] );
check( 'a partial refund that drops below it does not', 'refunded' === foodify_referral_reward_decision( array_merge( $rs, [ 'refunded' => 200.0 ] ) )['reason'] );
check( 'no promised amount, no reward',   'no_amount' === foodify_referral_reward_decision( array_merge( $rs, [ 'get' => 0.0 ] ) )['reason'] );
check( 'refunded before use: withdrawn',  'revoke' === foodify_referral_revoke_decision( 650, 650, 499, 0 ) );
check( 'refunded after use: kept, noted', 'keep_used' === foodify_referral_revoke_decision( 650, 650, 499, 1 ) );
check( 'a small partial refund: kept',    'keep' === foodify_referral_revoke_decision( 650, 50, 499, 0 ) );
check( 'no refund: kept',                 'keep' === foodify_referral_revoke_decision( 650, 0, 499, 0 ) );
check( 'this month counts, withdrawn ones do not', 2 === foodify_referral_month_count( [ [ 'at' => '2026-11-02', 'status' => 'issued' ], [ 'at' => '2026-11-09', 'status' => 'issued' ], [ 'at' => '2026-11-10', 'status' => 'revoked' ], [ 'at' => '2026-10-30', 'status' => 'issued' ] ], '2026-11' ) );

echo "── 14 · referrals: applying the saved code ──\n";
check( 'applied once the cart reaches the minimum', true === foodify_referral_should_autoapply( [ 'has_code' => true, 'subtotal' => 520.0, 'min' => 499.0 ] ) );
check( 'not to an empty or small cart (no "minimum spend" error)', false === foodify_referral_should_autoapply( [ 'has_code' => true, 'subtotal' => 200.0, 'min' => 499.0 ] ) );
check( 'not after the customer removed it', false === foodify_referral_should_autoapply( [ 'has_code' => true, 'subtotal' => 900.0, 'min' => 0.0, 'dismissed' => true ] ) );

echo "── 14 · referrals: what the customer sees ──\n";
$labels = foodify_referral_labels( $cfg );
check( '"Give ₹100, get ₹100"',           'Give ₹100, get ₹100' === $labels['title'] );
check( 'the rule names the minimum and the cap', str_contains( $labels['lead'], 'above ₹499' ) && str_contains( $labels['rules'], 'Up to 5 rewards a month' ) );
check( 'no cap, no cap sentence',         ! str_contains( foodify_referral_labels( array_merge( $cfg, [ 'cap' => 0 ] ) )['rules'], 'Up to' ) );
$locked = foodify_referral_panel_html( [ 'labels' => $labels, 'eligible' => false ] );
check( 'before a completed order: no code, and says when', str_contains( $locked, 'is-locked' ) && ! str_contains( $locked, 'fd-refer__code-value' ) && str_contains( $locked, 'once your first order has shipped' ) );
$link  = 'https://letsfoodify.com/cart/?ref=' . $code;
$panel = foodify_referral_panel_html( [ 'labels' => $labels, 'eligible' => true, 'code' => $code, 'link' => $link, 'share_text' => foodify_referral_share_text( $cfg, $code, $link ),
	'rewards' => [ [ 'code' => 'RWDABCDEF', 'amount' => 100.0, 'status' => 'ready' ], [ 'code' => 'RWDGHJKMN', 'amount' => 100.0, 'status' => 'revoked' ] ] ] );
check( 'the code and link are shown',     str_contains( $panel, $code ) && str_contains( $panel, 'value="' . $link . '"' ) );
check( 'Share waits for the browser to support it', 1 === preg_match( '/data-fd-share[^>]*hidden/', $panel ) );
check( 'WhatsApp share is a plain link with the text encoded', str_contains( $panel, 'href="https://wa.me/?text=Get%20%E2%82%B9100' ) );
check( 'a withdrawn reward says why',     str_contains( $panel, 'Withdrawn — the order was refunded' ) );
$re = foodify_referral_reward_email( [ 'first_name' => 'Priya', 'code' => 'RWDABCDEF', 'amount' => 100.0 ] );
check( 'the reward email names no friend', str_contains( $re['body'], 'Someone you referred' ) && str_contains( $re['body'], 'RWDABCDEF' ) );
check( 'the share script needs no other site', ! preg_match( '#https?://#', foodify_referral_script() ) );

echo "── 14 · referral coupons are NEVER partner sales ──\n";
$codeSpec   = foodify_referral_coupon_spec( 'referral_code', [ 'code' => $code, 'give' => 100.0, 'min' => 499.0, 'referrer_id' => 7 ] );
$rewardSpec = foodify_referral_coupon_spec( 'referral_reward', [ 'code' => 'RWDABCDEF', 'get' => 100.0, 'referrer_id' => 7, 'email' => 'priya@gmail.com', 'order_id' => 55 ] );
check( 'the code coupon carries no partner id', ! array_key_exists( '_foodify_partner_id', $codeSpec['meta'] ) && 'referral_code' === $codeSpec['meta']['_foodify_coupon_kind'] );
check( 'nor does the reward',             ! array_key_exists( '_foodify_partner_id', $rewardSpec['meta'] ) && 'referral_reward' === $rewardSpec['meta']['_foodify_coupon_kind'] );
check( 'the code: first order only, no stacking, the minimum', true === $codeSpec['props']['individual_use'] && 1 === $codeSpec['props']['usage_limit_per_user'] && 499.0 === $codeSpec['props']['minimum_amount'] );
check( 'the reward: once, for the referrer\'s email only', 1 === $rewardSpec['props']['usage_limit'] && [ 'priya@gmail.com' ] === $rewardSpec['props']['email_restrictions'] );
check( 'a tagged coupon never counts as a partner\'s, even with a partner set', false === foodify_coupon_counts_as_partner( [ '_foodify_partner_id' => 9, '_foodify_coupon_kind' => 'referral_code' ] ) );
check( 'a plain partner coupon still does', true === foodify_coupon_counts_as_partner( [ '_foodify_partner_id' => 9 ] ) );

// Against the REAL attribution code. coupon-attribution.php has no pure half,
// so it is loaded here behind minimal stubs — declared at RUNTIME, inside this
// block, so the lane files above loaded their pure halves only.
if ( ! function_exists( 'add_action' ) ) {
	$GLOBALS['fd_meta']  = [];
	$GLOBALS['fd_codes'] = [];
	function add_action( ...$a ): bool { return true; }
	function add_filter( ...$a ): bool { return true; }
	function get_post_meta( int $id, string $key = '', bool $single = false ) { return $GLOBALS['fd_meta'][ $id ][ $key ] ?? ''; }
	function wc_get_coupon_id_by_code( string $code ): int { return (int) ( $GLOBALS['fd_codes'][ strtolower( $code ) ] ?? 0 ); }
	class WC_Order {
		public array $c = [];
		public function get_items( string $type = '' ): array { return 'coupon' === $type ? $this->c : []; }
	}
	class Fd_Coupon_Item {
		public function __construct( private string $code, private float $discount ) {}
		public function get_code(): string { return $this->code; }
		public function get_discount(): float { return $this->discount; }
	}
}
require $inc . 'coupon-attribution.php';

$GLOBALS['fd_codes'] = [ 'influ10' => 101, strtolower( $code ) => 102, 'rwdabcdef' => 103, 'repeat-abc' => 104 ];
$GLOBALS['fd_meta']  = [
	101 => [ '_foodify_partner_id' => 9 ],
	102 => $codeSpec['meta'],
	103 => $rewardSpec['meta'],
	104 => [ '_foodify_coupon_kind' => 'repeat' ],
];
$order    = new WC_Order();
$order->c = [ new Fd_Coupon_Item( 'INFLU10', 50.0 ), new Fd_Coupon_Item( $code, 100.0 ), new Fd_Coupon_Item( 'RWDABCDEF', 100.0 ), new Fd_Coupon_Item( 'repeat-abc', 30.0 ) ];
$found    = foodify_partner_coupons_on_order( $order );
check( 'foodify_partner_coupons_on_order() keeps only the partner code', 1 === count( $found ) && 'INFLU10' === $found[0]['code'] && 9 === $found[0]['partner_id'] );
$attr = foodify_attributed_coupons( $found, 500.0 );
check( 'and the partner is attributed the whole net order — no share lost to a referral', 1 === count( $attr ) && 500.0 === $attr[0]['attributed_revenue'] );

echo "── UX fix wave: repeat orders and Refer a friend (ACCOUNT-2, -7, -11, -13) ──\n";
$lh = foodify_repeat_list_html( [ [ 'id' => 'r1', 'weeks' => 4, 'next' => '2026-12-06', 'status' => 'active', 'summary' => 'Poha' ] ], [ 'lead' => 2, 'active' => true, 'nonces' => [] ] );
check( 'ACCOUNT-2: "Stop repeating", not "Cancel" next to "Change"', str_contains( $lh, '>Stop repeating<' ) && ! str_contains( $lh, '>Cancel<' ) );
check( 'ACCOUNT-2: it is confirmed in place, like cancelling an order', 1 === preg_match( '#<details class="fd-repeat-item__stop"><summary[^>]*>Stop repeating</summary>.*?>Yes, stop reminders</button>.*?</details>#s', $lh ) );
check( 'ACCOUNT-2: the stop is styled as the dangerous one', str_contains( $lh, 'class="fd-secondary fd-danger">Stop repeating' ) && str_contains( $lh, 'is-danger">Yes, stop reminders' ) );
check( 'ACCOUNT-2: the confirm still posts the cancel verb', 1 === preg_match( '#<details class="fd-repeat-item__stop">.*?value="cancel".*?</details>#s', $lh ) );
check( 'ACCOUNT-7: the interval button says "Save"', str_contains( $lh, '<button type="submit" class="fd-secondary">Save</button>' ) && ! str_contains( $lh, '>Change<' ) );
check( 'ACCOUNT-7: nothing submits on change', ! str_contains( $lh, 'onchange' ) );
check( 'ACCOUNT-7: the confirmation names the interval and the first reminder', 'Repeat set up — every 4 weeks. First reminder: Fri 4 Dec.' === foodify_repeat_created_message( 4, '2026-12-06', 2 ) );
$set = foodify_repeat_offer_html( [ 'order_id' => 7, 'labels' => $plain, 'nonce_field' => '', 'existing' => [ 'weeks' => 4, 'next' => '2026-12-06' ], 'manage_url' => '/m/', 'created' => foodify_repeat_created_message( 4, '2026-12-06', 2 ) ] );
check( 'ACCOUNT-7: shown in place, where the button was, as a status', str_contains( $set, '<p class="fd-repeat__done" role="status" tabindex="-1">Repeat set up — every 4 weeks. First reminder: Fri 4 Dec.</p>' ) && str_contains( $set, 'id="fd-repeat-7"' ) );
check( 'ACCOUNT-7: and only right after setting it up', ! str_contains( foodify_repeat_offer_html( [ 'order_id' => 7, 'labels' => $plain, 'nonce_field' => '', 'existing' => [ 'weeks' => 4, 'next' => '2026-12-06' ] ] ), 'fd-repeat__done' ) );
$rsrc = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/repeat-orders.php' );
check( 'ACCOUNT-7: the create action redirects to that confirmation', str_contains( $rsrc, "add_query_arg( 'fd_repeat', 'set'" ) && str_contains( $rsrc, 'foodify_repeat_created_message( (int) $s[\'weeks\']' ) );
check( 'ACCOUNT-11: a delivered (completed) order offers "Repeat this order"', foodify_repeat_offer_allowed( 'completed' ) && foodify_repeat_offer_allowed( 'wc-completed' ) );
check( 'ACCOUNT-11: processing / on-hold / pending show nothing', ! foodify_repeat_offer_allowed( 'processing' ) && ! foodify_repeat_offer_allowed( 'on-hold' ) && ! foodify_repeat_offer_allowed( 'pending' ) && ! foodify_repeat_offer_allowed( 'return-requested' ) );
check( 'ACCOUNT-11: the order page asks that rule', str_contains( $rsrc, '! foodify_repeat_offer_allowed( (string) $order->get_status() )' ) );
check( 'the customer copy says "stop", not "cancel"', ! str_contains( foodify_repeat_labels( 0.0, 2 )['lead'], 'cancel' ) && 'Stopped repeating. No more reminders for this order.' === foodify_repeat_apply( [ [ 'id' => 'r1', 'coupon' => '' ] ], 'r1', 'cancel', [], '2026-11-20', 'n' )['message'] );
check( 'ACCOUNT-13: "Send on WhatsApp" is the main button, first', 1 === preg_match( '#<div class="fd-refer__actions"><a class="wp-element-button[^"]*" href="https://wa\.me/#', $panel ) );
check( 'ACCOUNT-13: Copy link and Share are secondary', str_contains( $panel, '<button type="button" class="fd-secondary" data-fd-copy' ) && 1 === preg_match( '#<button type="button" class="fd-secondary" data-fd-share[^>]*hidden>Share</button>#', $panel ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
