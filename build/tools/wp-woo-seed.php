<?php
/**
 * What a shop owner does in wp-admin AFTER Appearance → Foodify setup, for the
 * local WooCommerce test site only (scripts/wp-woo-test.sh): one shipping zone
 * with the two methods bootstrap.sh writes (flat rate + free delivery above a
 * minimum), a customer, and the Privacy Policy page published. Safe to re-run.
 *
 *   php tools/wp-woo-seed.php /path/to/wordpress
 *
 * The amounts are bootstrap.sh's own defaults (₹59, free from ₹599). They are
 * test data for a throwaway site — client policy is still the client's.
 */
$fd_dir = $argv[1] ?? ''; // not $wp: that is WordPress's own global.
if ( ! is_file( "$fd_dir/wp-load.php" ) ) { fwrite( STDERR, "no WordPress at '$fd_dir' — the seed did NOT run\n" ); exit( 2 ); }
require "$fd_dir/wp-load.php";
if ( ! class_exists( 'WooCommerce' ) ) { fwrite( STDERR, "WooCommerce is not active — the seed did NOT run\n" ); exit( 2 ); }

$zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $z ) { if ( 'India' === $z['zone_name'] ) { $zone = new WC_Shipping_Zone( $z['id'] ); } }
if ( ! $zone ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'India' );
	$zone->add_location( 'IN', 'country' );
	$zone->save();
	foreach ( [
		'free_shipping' => [ 'title' => 'Free delivery', 'requires' => 'min_amount', 'min_amount' => '599', 'ignore_discounts' => 'no' ],
		'flat_rate'     => [ 'title' => 'Delivery', 'cost' => '59', 'tax_status' => 'none' ],
	] as $method => $settings ) {
		$instance = $zone->add_shipping_method( $method );
		update_option( "woocommerce_{$method}_{$instance}_settings", $settings );
	}
	WC_Cache_Helper::get_transient_version( 'shipping', true );
}

$customer = get_user_by( 'login', 'asha' );
if ( ! $customer ) {
	$id = wc_create_new_customer( 'asha@example.org', 'asha', 'local-test-only' );
	if ( is_wp_error( $id ) ) { fwrite( STDERR, $id->get_error_message() . "\n" ); exit( 1 ); }
	$c = new WC_Customer( $id );
	$c->set_first_name( 'Asha' ); $c->set_last_name( 'Verma' ); $c->set_billing_first_name( 'Asha' ); $c->set_billing_last_name( 'Verma' );
	$c->set_billing_phone( '9876543210' ); $c->set_billing_address_1( '14 Civil Lines' ); $c->set_billing_city( 'Kanpur' );
	$c->set_billing_state( 'UP' ); $c->set_billing_postcode( '208001' ); $c->set_billing_country( 'IN' );
	$c->save();
	$customer = get_user_by( 'id', $id );
}

$pp = get_page_by_path( 'privacy-policy' );
if ( $pp && 'publish' !== $pp->post_status ) { wp_publish_post( $pp->ID ); }

echo json_encode( [ 'zone' => $zone->get_id(), 'customer' => (int) $customer->ID, 'threshold' => function_exists( 'foodify_free_shipping_threshold' ) ? foodify_free_shipping_threshold() : null ] ), "\n";
