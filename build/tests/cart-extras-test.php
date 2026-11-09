<?php
/**
 * inc/cart-extras.php and inc/home-rows.php — the cart as a bill (Bill
 * details, You saved, Available offers, You might also like) and the home
 * page's shelves (client direction, 9 Nov 2026).
 *
 *   php tests/cart-extras-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/offers.php';
require __DIR__ . '/../theme/foodify/inc/cart-extras.php';
require __DIR__ . '/../theme/foodify/inc/home-rows.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
$T = __DIR__ . '/../theme/foodify/';

echo "── You saved ──\n";
check( 'sale prices × quantity: 2 × (225 − 185) = ₹80', 80 === foodify_cart_savings( [ [ 225.0, 185.0, 2 ] ], 0.0, 0.0 ) );
check( '… plus codes plus the box discount', 80 + 28 + 45 === foodify_cart_savings( [ [ 225.0, 185.0, 2 ], [ 185.0, 185.0, 1 ] ], 28.0, 45.0 ) );
check( 'a line at full price saves nothing; a price ABOVE regular never subtracts', 0 === foodify_cart_savings( [ [ 185.0, 185.0, 3 ], [ 100.0, 120.0, 1 ] ], 0.0, 0.0 ) );
check( 'negative inputs are not savings', 10 === foodify_cart_savings( [ [ 110.0, 100.0, 1 ] ], -5.0, -9.0 ) );
check( 'whole rupees, rounded DOWN — never claim a paisa the shopper did not save', 12 === foodify_cart_savings( [ [ 12.99, 0.5, 1 ] ], 0.0, 0.0 ) );
check( '"You saved ₹98 on this order"', '<p class="fd-saved">You saved <strong>₹98</strong> on this order</p>' === foodify_cart_saved_html( 98 ) );
check( 'nothing saved, no line', '' === foodify_cart_saved_html( 0 ) );

echo "── Available offers ──\n";
$coupon = [ 'code' => 'welcome5', 'type' => 'percent', 'amount' => 5.0, 'min_spend' => 499.0, 'expires' => '', 'free_shipping' => false ];
$lines  = foodify_offer_lines( [ 'coupon' => $coupon, 'payment' => [ 'prepaid_discount' => 25 ], 'prepaid_available' => true, 'free_shipping_min' => 599.0 ] );
$offers = foodify_cart_offers( $lines, 'welcome5' );
check( 'only the coupon line becomes an offer to apply (delivery and paying online are not codes)', 1 === count( $offers ) && 'WELCOME5' === $offers[0]['code'] );
check( 'its words are the product page\'s own (foodify_offer_lines), not typed by hand', 'Use code WELCOME5: 5% off your order' === $offers[0]['title'] && 'On orders of ₹499 or more.' === $offers[0]['detail'] );
check( 'no code, no offers', [] === foodify_cart_offers( $lines, '' ) && [] === foodify_cart_offers( [], 'X' ) );
$h = foodify_public_offers_html( $offers );
check( 'a labelled section: "Available offers"', str_contains( $h, '<section class="fd-offers-list" aria-labelledby="fd-offers-list-title">' ) && str_contains( $h, '>Available offers</h2>' ) );
check( 'Apply is a button carrying the code, named for the offer', str_contains( $h, '<button type="button" class="fd-secondary fd-cart-offer__apply" data-fd-offer="WELCOME5" aria-label="Apply WELCOME5: Use code WELCOME5: 5% off your order">Apply</button>' ) );
$ha = foodify_public_offers_html( $offers, [ 'welcome5' ] );
check( 'already on the cart (any case): "Applied", no button', str_contains( $ha, 'fd-cart-offer is-applied' ) && str_contains( $ha, '>Applied</span>' ) && ! str_contains( $ha, 'data-fd-offer=' ) );
check( 'escaped', str_contains( foodify_public_offers_html( [ [ 'code' => 'A"B', 'title' => '<x>', 'detail' => '' ] ] ), '&lt;x&gt;' ) && ! str_contains( foodify_public_offers_html( [ [ 'code' => 'A"B', 'title' => 't', 'detail' => '' ] ] ), 'A"B' ) );
check( 'no offers, no section', '' === foodify_public_offers_html( [] ) );
check( 'its class is its own — the product page\'s .fd-offer (with its dot) is a different thing', ! preg_match( '/class="fd-offer[ "_]/', $h ) );
$js = foodify_public_offers_script();
check( 'Apply fills WooCommerce\'s own coupon field and presses its button', str_contains( $js, "getElementById('coupon_code')" ) && str_contains( $js, '[name="apply_coupon"]' ) && str_contains( $js, 'go.click()' ) );

echo "── wiring ──\n";
$src = (string) file_get_contents( $T . 'inc/cart-extras.php' );
$off = (string) file_get_contents( $T . 'inc/offers.php' );
check( 'the theme loads it', str_contains( (string) file_get_contents( $T . 'functions.php' ), "require_once FOODIFY_DIR . '/inc/cart-extras.php';" ) );
check( '"Cart totals" becomes "Bill details"', str_contains( $src, "'Cart totals' === \$text ? __( 'Bill details', 'foodify' )" ) );
check( 'the offer is the ONE public coupon, read live — never a list of WooCommerce\'s coupons (partner codes are coupons too)',
	str_contains( $src, 'foodify_offer_coupon()' ) && ! str_contains( $src, 'get_posts' ) && ! str_contains( $src, "'shop_coupon'" ) );
check( 'the cart asks for the coupon with no product; a product page still checks its restrictions',
	str_contains( $off, 'function foodify_offer_coupon( ?WC_Product $product = null ): ?array' ) && str_contains( $off, 'if ( null === $product ) {' ) );
check( 'You saved counts codes with their tax and the box discount', str_contains( $src, 'get_discount_total() + (float) WC()->cart->get_discount_tax()' ) && str_contains( $src, 'foodify_box_cart_discount_total()' ) );
check( 'You saved sits above Proceed to checkout', str_contains( $src, "add_action( 'woocommerce_proceed_to_checkout'" ) && str_contains( $src, '}, 1 );' ) );
$cs = (string) file_get_contents( $T . 'inc/cart-stepper.php' );
check( 'an Add on the cart page refreshes the classic cart table', str_contains( $cs, "window.jQuery(document.body).trigger('wc_update_cart')" ) );
$bs = (string) file_get_contents( __DIR__ . '/../scripts/bootstrap.sh' );
check( 'bootstrap.sh reports where the public offer now shows', str_contains( $bs, 'under Available offers in the cart' ) );

echo "── home rows ──\n";
$rows = foodify_home_rows();
check( 'four shelves, in order', [ 'bestsellers', 'hot-water', 'under-200', 'jain' ] === array_keys( $rows ) );
check( 'rows query by SLUG, never a term id', 'slug' === foodify_home_row_query_args( 'jain' )['tax_query'][0]['field'] && [ 'jain' ] === foodify_home_row_query_args( 'jain' )['tax_query'][0]['terms'] && 'pa_prep' === foodify_home_row_query_args( 'hot-water' )['tax_query'][0]['taxonomy'] );
check( 'Under ₹200 is the price, inclusive', [ 'key' => '_price', 'value' => 200, 'compare' => '<=', 'type' => 'NUMERIC' ] === foodify_home_row_query_args( 'under-200' )['meta_query'][0] );
check( 'bestsellers and unknown keys add nothing', [] === foodify_home_row_query_args( 'bestsellers' ) && [] === foodify_home_row_query_args( 'nope' ) );
$m = foodify_home_row_merge( [ 'tax_query' => [ [ 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => [ 'outofstock' ], 'operator' => 'NOT IN' ] ] ], foodify_home_row_query_args( 'jain' ) );
check( 'a row keeps the loop\'s own clauses (stock) and ANDs its own', 2 === count( array_filter( array_keys( $m['tax_query'] ), 'is_int' ) ) && 'AND' === $m['tax_query']['relation'] );
$fp = (string) file_get_contents( $T . 'templates/front-page.html' );
foreach ( $rows as $k => $r ) {
	check( "the template carries \"$k\" with its title and See all", str_contains( $fp, '"fdRow":"' . $k . '"' ) && str_contains( $fp, '<a href="' . $r['see_all'] . '">See all<span class="fd-sr"> ' . str_replace( '&', '&amp;', $r['title'] ) . '</span></a>' ) );
}
check( 'in stock only', 4 === substr_count( $fp, '"__woocommerceStockStatus":["instock"],"fdRow"' ) );

echo "── You might also like ──\n";
check( 'what is in the cart is left out', [ 3, 7 ] === foodify_cart_suggest_args( [], [ 3, 0, 7, 3 ] )['post__not_in'] );
check( '… on top of anything the loop already excluded', [ 1, 3 ] === foodify_cart_suggest_args( [ 'post__not_in' => [ 1 ] ], [ 3 ] )['post__not_in'] );
check( 'an empty cart changes nothing', [ 'x' => 1 ] === foodify_cart_suggest_args( [ 'x' => 1 ], [ 0 ] ) );
$pc = (string) file_get_contents( $T . 'templates/page-cart.html' );
check( 'the cart page carries the row, after the cart', str_contains( $pc, '"fdRow":"cart-suggest"' ) && strpos( $pc, 'classic-shortcode' ) < strpos( $pc, 'You might also like' ) );
$hr = (string) file_get_contents( $T . 'inc/home-rows.php' );
check( 'the filter reads the live cart for that row', str_contains( $hr, "'cart-suggest' === \$row" ) && str_contains( $hr, 'WC()->cart->get_cart()' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
