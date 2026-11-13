<?php
/**
 * What a blank WordPress showed when the zip was uploaded (13 Nov 2026): an
 * invisible headline, a shortcode printed as text, a blog post on the
 * Bestsellers shelf, three headings over nothing, and a blank theme card.
 * The browser check (scripts/wp-render-test.sh) looks at the page; these pin
 * the causes, so each can fail by name.
 *
 *   php tests/blank-install-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/home-rows.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
$T = __DIR__ . '/../theme/foodify/';

echo "── the hero can be read ──\n";
$hero = (string) file_get_contents( $T . 'patterns/hero.php' );
check( 'the cover says its overlay is light, in the block AND in its markup (else WordPress paints its text white)',
	str_contains( $hero, '"overlayColor":"kraft-pale","isDark":false' ) && str_contains( $hero, 'class="wp-block-cover alignfull is-light"' ) );
foreach ( array_merge( glob( $T . 'patterns/*.php' ), glob( $T . 'templates/*.html' ), glob( $T . 'parts/*.html' ) ) as $f ) {
	$src = (string) file_get_contents( $f );
	if ( ! preg_match_all( '/<!-- wp:cover (\{.*?\}) -->/', $src, $m ) ) {
		continue;
	}
	foreach ( $m[1] as $json ) {
		$a     = (array) json_decode( $json, true );
		$light = in_array( (string) ( $a['overlayColor'] ?? '' ), [ 'kraft-pale', 'paper', 'base', 'white', 'leaf-wash' ], true );
		check( basename( $f ) . ': a cover over a light colour declares isDark false', ! $light || false === ( $a['isDark'] ?? null ) );
	}
}

echo "── shortcodes inside patterns run ──\n";
$sc = (string) file_get_contents( $T . 'inc/shortcodes.php' );
check( 'the shortcode block\'s own output is run (WordPress runs shortcodes before patterns are expanded)',
	str_contains( $sc, "add_filter( 'render_block_core/shortcode', static fn( \$html ): string => foodify_drop_unrun_shortcodes( do_shortcode( shortcode_unautop( (string) \$html ) ) ), 20 );" ) );
$in_patterns = 0;
foreach ( glob( $T . 'patterns/*.php' ) as $f ) {
	$in_patterns += substr_count( (string) file_get_contents( $f ), '<!-- wp:shortcode -->' );
}
check( 'patterns do carry shortcode blocks, so the filter is load-bearing (' . $in_patterns . ')', $in_patterns > 0 );

echo "── a site without WooCommerce does not crash ──\n";
$bp = (string) file_get_contents( $T . 'inc/business-profile.php' );
check( 'is_shop() is asked only if it exists (unguarded: a fatal, and a blank page, on every page but home)', str_contains( $bp, "! ( function_exists( 'is_shop' ) && is_shop() )" ) && ! preg_match( '/!\s*is_shop\(\)/', $bp ) );
// WooCommerce's page tests, called on a line that does not first ask whether
// they exist. Lines inside a woocommerce_* hook only run with WooCommerce, so
// each allowed file is named here with the reason; a new one fails by name.
$allowed = [
	'inc/cart-extras.php' => 'woocommerce_cart_item_* filters',
	'inc/profile.php'     => 'woocommerce_account_content action',
	'functions.php'       => 'returns early when is_woocommerce is undefined',
];
$bare = [];
foreach ( array_merge( glob( $T . 'inc/*.php' ), [ $T . 'functions.php' ] ) as $f ) {
	$rel = str_replace( $T, '', $f );
	foreach ( file( $f ) as $i => $line ) {
		if ( preg_match( '/\b(is_shop|is_product|is_product_category|is_product_taxonomy|is_cart|is_checkout|is_account_page|is_woocommerce|is_wc_endpoint_url|is_order_received_page)\(/', $line )
			&& ! str_contains( $line, 'function_exists' ) && ! preg_match( '~^\s*(//|\*|/\*)~', $line ) && ! isset( $allowed[ $rel ] ) ) {
			$bare[] = $rel . ':' . ( $i + 1 );
		}
	}
}
check( 'no WooCommerce page test is called without asking it exists' . ( $bare ? ' — ' . implode( ', ', $bare ) : '' ), ! $bare );
$scsrc = (string) file_get_contents( $T . 'inc/shortcodes.php' );
check( 'a shop shortcode nothing ran is dropped, not printed ([woocommerce_order_tracking] with WooCommerce off)',
	str_contains( $scsrc, "foodify_drop_unrun_shortcodes( do_shortcode( shortcode_unautop( (string) \$html ) ) )" )
	&& 1 === preg_match( '/function foodify_drop_unrun_shortcodes.*?preg_replace\( \'(.*?)\', \'\'/s', $scsrc, $rx )
	&& '<p></p> [gallery]' === preg_replace( $rx[1], '', '<p>[woocommerce_order_tracking]</p>[foodify_buy_again limit="3"] [gallery]' ) );

echo "── empty shelves and empty reviews step aside ──\n";
check( 'no product post type (WooCommerce off): a shelf matches nothing — never blog posts', [ 0 ] === foodify_home_row_guard( [ 'post_type' => 'post' ], false )['post__in'] );
check( 'with products the query is left alone', [ 'orderby' => 'popularity' ] === foodify_home_row_guard( [ 'orderby' => 'popularity' ], true ) );
$rows = (string) file_get_contents( $T . 'inc/home-rows.php' );
check( 'the guard is asked of every row, by WordPress\'s own answer', str_contains( $rows, "foodify_home_row_guard( \$query, post_type_exists( 'product' ) )" ) );
$css = (string) file_get_contents( $T . 'style.css' );
check( 'a shelf with no products is not shown', str_contains( $css, '.fd-row:not(:has(.wp-block-post)) { display: none; }' ) );
check( 'a reviews section with no reviews is not shown', str_contains( $css, '.fd-reviews-section:not(:has(.fd-review)) { display: none; }' )
	&& str_contains( (string) file_get_contents( $T . 'patterns/reviews.php' ), 'class="wp-block-group alignwide fd-reviews-section ' )
	&& str_contains( $sc, '<figure class="fd-review">' ) );

echo "── the theme's card ──\n";
$shot = $T . 'screenshot.png';
$size = is_file( $shot ) ? (array) getimagesize( $shot ) : [ 0, 0 ];
check( 'screenshot.png is there, at WordPress\'s size (1200×900)', 1200 === (int) $size[0] && 900 === (int) $size[1] );
check( 'and light enough to ship in every zip (under 400 KB)', is_file( $shot ) && filesize( $shot ) < 400 * 1024 );

echo "── the gate looks at the real page ──\n";
$gate = (string) file_get_contents( __DIR__ . '/../run-all-tests.sh' );
$pack = (string) file_get_contents( __DIR__ . '/../scripts/package-theme.sh' );
check( 'run-all-tests renders the theme in real WordPress, in a browser', str_contains( $gate, 'run "wordpress-render" ./scripts/wp-render-test.sh' ) );
check( 'the packager renders THE ZIP the same way before calling it ready', str_contains( $pack, 'FOODIFY_THEME_SRC="$UNZ/foodify" "$KIT/scripts/wp-render-test.sh"' ) );
check( 'a render check that cannot run fails, it does not pass', 2 === substr_count( (string) file_get_contents( __DIR__ . '/../scripts/wp-render-test.sh' ), 'this gate did NOT run' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
