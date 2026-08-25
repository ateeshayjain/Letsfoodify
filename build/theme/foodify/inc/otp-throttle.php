<?php
/**
 * WP-05 — OTP request throttling.
 *
 * Acceptance: "Five OTP requests in an hour blocks the sixth; 30-second resend
 * cooldown." Both are about abuse and cost, not about which gateway sends the
 * message — every SMS is billed, and an unthrottled endpoint is a way to spend
 * the client's money from a script.
 *
 * So the decision is a PURE function with no gateway, no WordPress, and no
 * clock of its own. The OTP plugin arrives in week 11 once DLT registration
 * clears; this rule does not have to wait for it, and it can be tested now
 * rather than on a live endpoint with real per-message costs.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const FOODIFY_OTP_MAX_PER_HOUR = 5;
const FOODIFY_OTP_RESEND_WAIT  = 30;   // seconds
const FOODIFY_OTP_WINDOW       = 3600; // seconds

/**
 * May this number request an OTP right now?
 *
 * @param int[] $timestamps Unix times of previous requests, any order.
 * @param int   $now        Current unix time — passed in, never read here, so
 *                          the boundaries can be tested exactly.
 * @return array{allowed:bool,reason:string,retry_after:int,used:int}
 */
function foodify_otp_gate( array $timestamps, int $now ): array {
	// Only what falls inside the window counts. Anything older is irrelevant and
	// pruning it here is what stops the list growing without bound.
	$recent = array_values( array_filter(
		$timestamps,
		static fn( $t ): bool => is_int( $t ) && $t > $now - FOODIFY_OTP_WINDOW && $t <= $now
	) );
	sort( $recent );

	$used = count( $recent );
	$last = $used ? (int) end( $recent ) : null;

	// Cooldown first: it is the more specific answer, and telling someone they
	// are rate-limited for an hour when they are actually 5 seconds into a
	// 30-second wait is a support ticket.
	if ( null !== $last && ( $now - $last ) < FOODIFY_OTP_RESEND_WAIT ) {
		return [
			'allowed'     => false,
			'reason'      => 'cooldown',
			'retry_after' => FOODIFY_OTP_RESEND_WAIT - ( $now - $last ),
			'used'        => $used,
		];
	}

	if ( $used >= FOODIFY_OTP_MAX_PER_HOUR ) {
		// The window is rolling, so the wait is until the OLDEST request ages out.
		$retry = ( (int) $recent[0] + FOODIFY_OTP_WINDOW ) - $now;
		return [
			'allowed'     => false,
			'reason'      => 'hourly_limit',
			'retry_after' => max( 1, $retry ),
			'used'        => $used,
		];
	}

	return [ 'allowed' => true, 'reason' => '', 'retry_after' => 0, 'used' => $used ];
}

/** Storage key. Hashed so a raw phone number is never an option name. */
function foodify_otp_key( string $phone ): string {
	$digits = preg_replace( '/\D/', '', $phone );
	return 'foodify_otp_' . hash( 'sha256', (string) $digits . wp_salt( 'auth' ) );
}

/**
 * Wrapper the OTP plugin calls before sending. Returns the same shape.
 *
 * Keyed on the PHONE NUMBER, not the session or IP: the cost and the abuse both
 * follow the number. A per-session limit is defeated by clearing cookies.
 */
function foodify_otp_check( string $phone, ?int $now = null ): array {
	$now   = $now ?? time();
	$key   = foodify_otp_key( $phone );
	$times = get_transient( $key );
	$times = is_array( $times ) ? $times : [];

	return foodify_otp_gate( $times, $now );
}

/** Record a send. Call only after the gateway accepted the message. */
function foodify_otp_record( string $phone, ?int $now = null ): void {
	$now   = $now ?? time();
	$key   = foodify_otp_key( $phone );
	$times = get_transient( $key );
	$times = is_array( $times ) ? $times : [];

	$times   = array_values( array_filter( $times, static fn( $t ): bool => is_int( $t ) && $t > $now - FOODIFY_OTP_WINDOW ) );
	$times[] = $now;

	set_transient( $key, $times, FOODIFY_OTP_WINDOW );
}
