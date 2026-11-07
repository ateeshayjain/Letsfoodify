<?php
/**
 * PIN code → city and state, answered by the site itself.
 *
 * Checkout used to send the customer's PIN code from their browser to
 * api.postalpincode.in — an unofficial free service with no SLA, on the most
 * important page of the shop, and a third party receiving customer data the
 * privacy policy never mentioned (REVIEW-NOTES item 5). The theme now carries
 * India Post's directory (assets/data/pincodes.json, built by
 * tools/build-pincodes.py) and answers at /wp-json/foodify/v1/pin/<pin>.
 *
 * The answer is in the old service's shape, so the checkout script did not have
 * to learn a second format: [{Status, PostOffice:[{District, State}]}], with
 * State a WooCommerce state CODE. A PIN the table does not know is not an
 * error — checkout leaves city and state for the customer to type.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/checkout-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/** The table, read once per request. [] when the file is missing or damaged. */
function foodify_pin_data( string $path ): array {
	static $cache = [];
	if ( ! isset( $cache[ $path ] ) ) {
		$raw  = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		$data = '' === $raw ? null : json_decode( $raw, true );
		$cache[ $path ] = is_array( $data ) && isset( $data['pins'], $data['states'], $data['districts'] ) ? $data : [];
	}
	return $cache[ $path ];
}

/**
 * One PIN code. Returns ['city' => …, 'state' => WooCommerce code or ''] or
 * null. A PIN filed under two states comes back with an empty state: the
 * customer picks it, because a wrong state is a wrong GST split.
 *
 * @return array{city:string,state:string}|null
 */
function foodify_pin_lookup( string $pin, array $data ): ?array {
	$pin = preg_replace( '/\D/', '', $pin );
	if ( 6 !== strlen( (string) $pin ) || empty( $data['pins'][ $pin ] ) ) {
		return null;
	}
	[ $d, $s ] = $data['pins'][ $pin ];
	return [
		'city'  => $d >= 0 ? (string) ( $data['districts'][ $d ] ?? '' ) : '',
		'state' => $s >= 0 ? (string) ( $data['states'][ $s ] ?? '' ) : '',
	];
}

/** The old service's response shape, which the checkout script reads. */
function foodify_pin_response( ?array $hit ): array {
	if ( null === $hit ) {
		return [ [ 'Status' => 'Error', 'PostOffice' => null ] ];
	}
	return [ [ 'Status' => 'Success', 'PostOffice' => [ [ 'District' => $hit['city'], 'State' => $hit['state'] ] ] ] ];
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'foodify/v1', '/pin/(?P<pin>\d{6})', [
		'methods'             => 'GET',
		// Public reference data — the same table India Post publishes.
		'permission_callback' => '__return_true',
		'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
			$data = foodify_pin_data( FOODIFY_DIR . '/assets/data/pincodes.json' );
			$res  = new WP_REST_Response( foodify_pin_response( foodify_pin_lookup( (string) $request['pin'], $data ) ) );
			// The answer for a PIN never changes between deploys.
			$res->header( 'Cache-Control', 'public, max-age=604800' );
			return $res;
		},
	] );
} );
