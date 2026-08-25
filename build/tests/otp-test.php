<?php
/**
 * Tests inc/otp-throttle.php with plain PHP — no WordPress, no gateway.
 *
 * The reason this test exists BEFORE the gateway does: every OTP is a billed
 * SMS. The alternative to testing the rule here is discovering its boundaries
 * on a live endpoint, one paid message per assertion, on the client's account.
 *
 * Boundaries are the whole point. "Five per hour" is only meaningful if the
 * fifth is allowed and the sixth is not, and "30-second cooldown" is only
 * meaningful if 29s is refused and 30s is not. An off-by-one here is either a
 * customer who cannot log in or a script that sends six.
 *
 *   php tests/otp-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

// Enough WordPress to let the file load. The pure gate touches none of it.
function wp_salt( $s = '' ) { return 'test-salt'; }
function get_transient( $k ) { return $GLOBALS['fx_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['fx_transients'][ $k ] = $v; return true; }
$GLOBALS['fx_transients'] = [];

require __DIR__ . '/../theme/foodify/inc/otp-throttle.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

$NOW = 1_700_000_000;

echo "── the two rules in isolation ──\n";

$g = foodify_otp_gate( [], $NOW );
check( 'first request is allowed', true === $g['allowed'] && 0 === $g['used'] );

// Four earlier requests, all well outside the cooldown, inside the window.
$four = [ $NOW - 3000, $NOW - 2000, $NOW - 1000, $NOW - 100 ];
$g = foodify_otp_gate( $four, $NOW );
check( 'fifth request is allowed (4 used)', true === $g['allowed'] && 4 === $g['used'] );

$five = array_merge( $four, [ $NOW - 60 ] );
$g = foodify_otp_gate( $five, $NOW );
check( 'sixth request is blocked', false === $g['allowed'] );
check( 'sixth request blocked for the hourly limit, not cooldown', 'hourly_limit' === $g['reason'] );

echo "── cooldown boundary ──\n";

$g = foodify_otp_gate( [ $NOW - 29 ], $NOW );
check( '29s after a send -> refused', false === $g['allowed'] && 'cooldown' === $g['reason'] );
check( '29s -> retry_after is the 1s that remains', 1 === $g['retry_after'] );

$g = foodify_otp_gate( [ $NOW - 30 ], $NOW );
check( '30s after a send -> allowed', true === $g['allowed'] );

$g = foodify_otp_gate( [ $NOW ], $NOW );
check( 'same second -> refused, full 30s wait', false === $g['allowed'] && 30 === $g['retry_after'] );

echo "── which rule answers when both apply ──\n";

// At the cap AND inside the cooldown. Answering "wait an hour" here would be
// true but useless: the shorter, more specific wait is the actionable one.
$g = foodify_otp_gate( array_merge( $four, [ $NOW - 5 ] ), $NOW );
check( 'cap + cooldown -> cooldown wins', false === $g['allowed'] && 'cooldown' === $g['reason'] );
check( 'cap + cooldown -> reports the 25s, not 3600', 25 === $g['retry_after'] );

echo "── the window rolls, it does not reset ──\n";

// Five sends an hour ago; the oldest is one second from ageing out.
$old = [ $NOW - 3599, $NOW - 3000, $NOW - 2000, $NOW - 1000, $NOW - 500 ];
$g = foodify_otp_gate( $old, $NOW );
check( 'still capped while the oldest is inside the window', false === $g['allowed'] && 'hourly_limit' === $g['reason'] );
check( 'retry_after counts from the OLDEST request (1s)', 1 === $g['retry_after'] );

// One second later that oldest send falls out of the window.
$g = foodify_otp_gate( $old, $NOW + 1 );
check( 'one second later the oldest ages out -> allowed', true === $g['allowed'] && 4 === $g['used'] );

$stale = [ $NOW - 7200, $NOW - 5000, $NOW - 4000, $NOW - 3601 ];
$g = foodify_otp_gate( array_merge( $stale, [ $NOW - 100 ] ), $NOW );
check( 'timestamps older than the window are pruned, not counted', true === $g['allowed'] && 1 === $g['used'] );

echo "── input the gate must not trust ──\n";

$g = foodify_otp_gate( [ $NOW + 600 ], $NOW );
check( 'a future timestamp is ignored, not treated as a send', true === $g['allowed'] && 0 === $g['used'] );

$g = foodify_otp_gate( [ 'nonsense', null, 3.5, $NOW - 100 ], $NOW );
check( 'non-integer entries are discarded', true === $g['allowed'] && 1 === $g['used'] );

$g = foodify_otp_gate( [ $NOW - 100, $NOW - 3000, $NOW - 50 ], $NOW );
check( 'unsorted input still finds the newest for cooldown', true === $g['allowed'] );
$g = foodify_otp_gate( [ $NOW - 3000, $NOW - 10, $NOW - 100 ], $NOW );
check( 'unsorted input still finds the newest (cooldown case)', 'cooldown' === $g['reason'] && 20 === $g['retry_after'] );

echo "── keying ──\n";

// The number is what gets billed, so the number is what gets limited. These
// four are the same subscriber written four ways.
$k = foodify_otp_key( '9876543210' );
check( 'spaces do not create a second bucket',   foodify_otp_key( '98765 43210' ) === $k );
check( 'dashes do not create a second bucket',   foodify_otp_key( '98765-43210' ) === $k );
check( 'a different number is a different key',  foodify_otp_key( '9876543211' ) !== $k );
check( 'the raw number never appears in the key', false === strpos( $k, '9876543210' ) );

echo "── transient wrapper round-trip ──\n";

$phone = '+91 98765 43210';
for ( $i = 0; $i < 5; $i++ ) {
	foodify_otp_record( $phone, $NOW - 1000 + ( $i * 60 ) );
}
$g = foodify_otp_check( $phone, $NOW );
check( 'five recorded sends block the sixth', false === $g['allowed'] && 5 === $g['used'] );
check( 'a different number is unaffected', true === foodify_otp_check( '9000000000', $NOW )['allowed'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
