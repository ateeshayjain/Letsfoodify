<?php
/**
 * What the local WooCommerce test site's DATABASE says, as JSON — so
 * tools/wp-woo-check.js can check that a button did what it said, not only
 * that the screen changed.
 *
 *   php tools/wp-woo-facts.php /path/to/wordpress            the facts
 *   php tools/wp-woo-facts.php /path/to/wordpress rerun      run each setup step AGAIN and report what it changed (must be nothing)
 */
$fd_dir = $argv[1] ?? ''; // not $wp: that is WordPress's own global.
if ( ! is_file( "$fd_dir/wp-load.php" ) ) { fwrite( STDERR, "no WordPress at '$fd_dir' — did NOT run\n" ); exit( 2 ); }
require "$fd_dir/wp-load.php";
if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'foodify_setup_options' ) ) { fwrite( STDERR, "WooCommerce or the theme is not active — did NOT run\n" ); exit( 2 ); }

if ( 'rerun' === ( $argv[2] ?? '' ) ) {
	echo wp_json_encode( [ 'pages' => foodify_setup_do_pages(), 'settings' => foodify_setup_do_settings(), 'samples' => foodify_setup_do_samples() ] ), "\n";
	exit;
}

$options_wrong = [];
foreach ( foodify_setup_options() as $option => $want ) {
	if ( ! foodify_setup_same( get_option( $option ), $want ) ) { $options_wrong[] = $option; }
}
$attributes = [];
foreach ( foodify_attribute_map() as $slug => $a ) {
	$tax                 = 'pa_' . $slug;
	$attributes[ $slug ] = [
		'id'         => (int) wc_attribute_taxonomy_id_by_name( $slug ),
		'registered' => taxonomy_exists( $tax ),
		'terms'      => taxonomy_exists( $tax ) ? array_values( (array) get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'slugs' ] ) ) : [],
		'want_terms' => array_map( 'strval', array_keys( (array) $a['terms'] ) ),
	];
}
$sample_rows = foodify_sample_products( (string) file_get_contents( FOODIFY_DIR . '/assets/data/sample-products.json' ) );
$by_slug     = [];
foreach ( $sample_rows as $r ) { $by_slug[ $r['slug'] ] = $r; }
$products = []; $product_faults = [];
foreach ( wc_get_products( [ 'limit' => -1, 'status' => [ 'publish', 'draft', 'pending', 'private' ] ] ) as $p ) {
	$slug       = $p->get_slug();
	$products[] = $slug;
	$r          = $by_slug[ $slug ] ?? null;
	if ( ! $r ) { continue; }
	$cats = wp_list_pluck( get_the_terms( $p->get_id(), 'product_cat' ) ?: [], 'slug' );
	$prep = wc_get_product_terms( $p->get_id(), 'pa_prep', [ 'fields' => 'slugs' ] );
	if ( 'publish' !== $p->get_status() ) { $product_faults[] = "$slug is not published"; }
	if ( [ (string) $r['category'] ] !== array_values( $cats ) ) { $product_faults[] = "$slug category " . implode( ',', $cats ); }
	if ( (float) $p->get_regular_price() !== (float) $r['regular_price'] ) { $product_faults[] = "$slug price"; }
	if ( '' !== (string) ( $r['prep'] ?? '' ) && [ (string) $r['prep'] ] !== array_values( $prep ) ) { $product_faults[] = "$slug prep " . implode( ',', $prep ); }
	if ( (string) (int) ( $r['prep_minutes'] ?? 0 ) !== (string) $p->get_meta( '_foodify_prep_minutes' ) ) { $product_faults[] = "$slug prep minutes"; }
	if ( (string) ( $r['net_quantity'] ?? '' ) !== (string) $p->get_meta( '_foodify_net_quantity' ) ) { $product_faults[] = "$slug net quantity"; }
	if ( '1' !== (string) $p->get_meta( '_foodify_sample' ) ) { $product_faults[] = "$slug is not marked as a sample"; }
	if ( empty( $r['in_stock'] ) === $p->is_in_stock() ) { $product_faults[] = "$slug stock"; }
}
$orders = [];
foreach ( wc_get_orders( [ 'limit' => 10, 'orderby' => 'ID', 'order' => 'ASC' ] ) as $o ) {
	$orders[] = [
		'id' => $o->get_id(), 'customer' => $o->get_customer_id(), 'status' => $o->get_status(), 'total' => (float) $o->get_total(),
		'payment' => $o->get_payment_method(), 'billing_country' => $o->get_billing_country(), 'shipping_country' => $o->get_shipping_country(),
		'shipping_address_1' => $o->get_shipping_address_1(), 'name' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
		'state' => $o->get_billing_state(), 'key' => $o->get_order_key(),
	];
}
$customer = get_user_by( 'login', 'asha' );
echo wp_json_encode( [
	'woocommerce'     => WC()->version,
	'wordpress'       => get_bloginfo( 'version' ),
	'coming_soon'     => get_option( 'woocommerce_coming_soon' ),
	'permalinks'      => (string) get_option( 'permalink_structure' ),
	'options_wrong'   => $options_wrong,
	'attributes'      => $attributes,
	'categories'      => array_values( array_intersect( array_keys( foodify_setup_categories() ), (array) get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'slugs' ] ) ) ),
	'want_categories' => array_keys( foodify_setup_categories() ),
	'pages_missing'   => foodify_setup_facts()['pages_missing'],
	'pages_unwired'   => foodify_setup_facts()['pages_unwired'],
	'products'        => $products,
	'sample_slugs'    => array_keys( $by_slug ),
	'product_faults'  => $product_faults,
	'tagline'         => (string) get_option( 'blogdescription' ),
	'details'         => (array) get_option( 'foodify_business_details', [] ),
	'orders'          => $orders,
	'customer_id'     => $customer ? (int) $customer->ID : 0,
	'threshold'       => function_exists( 'foodify_free_shipping_threshold' ) ? foodify_free_shipping_threshold() : null,
] ), "\n";
