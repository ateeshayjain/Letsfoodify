<?php
/**
 * inc/setup.php — Appearance → Foodify setup (13 Nov 2026).
 *
 *   php tests/setup-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/setup.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
$B = __DIR__ . '/../';
$T = $B . 'theme/foodify/';

echo "── one list of shop settings, not two (rule 2) ──\n";
$boot = (string) file_get_contents( $B . 'scripts/bootstrap.sh' );
$from = strpos( $boot, 'log "Phase 4 · Commerce configuration"' );
$to   = strpos( $boot, 'log "Phase 5 · Roles"' );
$in_script = [];
if ( false !== $from && false !== $to ) {
	foreach ( explode( "\n", substr( $boot, $from, $to - $from ) ) as $line ) {
		if ( preg_match( '/^\s*wp option update (woocommerce_\w+) (.+?)( --format=json)?\s*$/', $line, $m ) ) {
			$raw = trim( $m[2] );
			$raw = ( str_starts_with( $raw, "'" ) && str_ends_with( $raw, "'" ) ) ? substr( $raw, 1, -1 ) : $raw;
			$in_script[ $m[1] ] = empty( $m[3] ) ? $raw : json_decode( $raw, true );
		}
	}
}
$here = foodify_setup_options();
check( 'bootstrap.sh Phase 4 was found and read (' . count( $in_script ) . ' settings)', count( $in_script ) >= 15 );
$only_script = array_diff( array_keys( $in_script ), array_keys( $here ) );
$only_here   = array_diff( array_keys( $here ), array_keys( $in_script ) );
check( 'every setting bootstrap.sh writes, the screen writes' . ( $only_script ? ' — missing here: ' . implode( ', ', $only_script ) : '' ), ! $only_script );
check( 'the screen writes nothing bootstrap.sh does not' . ( $only_here ? ' — only here: ' . implode( ', ', $only_here ) : '' ), ! $only_here );
$differ = array_keys( array_filter( $here, static fn( $v, $k ) => isset( $in_script[ $k ] ) && ! foodify_setup_same( $in_script[ $k ], $v ), ARRAY_FILTER_USE_BOTH ) );
check( 'and to the same values' . ( $differ ? ' — differ: ' . implode( ', ', $differ ) : '' ), ! $differ );
check( 'the store is in Uttar Pradesh (the GST split depends on it)', 'IN:UP' === $here['woocommerce_default_country'] );
check( 'bootstrap.sh reports the business details option, so a value typed on staging is not lost at cutover', str_contains( $boot, 'wp option update foodify_business_details --format=json' ) && str_contains( $boot, 'foodify_business_details NOT SET' ) );

echo "── what would change ──\n";
$now = [ 'woocommerce_currency' => 'USD', 'woocommerce_price_num_decimals' => 2, 'woocommerce_specific_allowed_countries' => [ 'IN' ] ];
$ch  = foodify_setup_option_changes( [ 'woocommerce_currency' => 'INR', 'woocommerce_price_num_decimals' => '2', 'woocommerce_specific_allowed_countries' => [ 'IN' ], 'woocommerce_enable_guest_checkout' => 'yes' ], static fn( string $o ) => $now[ $o ] ?? false );
check( 'a different value is listed with what it is now', [ 'option' => 'woocommerce_currency', 'now' => 'USD', 'want' => 'INR' ] === $ch[0] );
check( '2 and "2" are the same setting; an equal list is the same', 2 === count( $ch ) );
check( 'an unset setting reads "(not set)"', '(not set)' === $ch[1]['now'] && 'yes' === $ch[1]['want'] );
check( 'unset is never "the same" as anything', ! foodify_setup_same( false, '' ) && ! foodify_setup_same( false, false ) );
check( 'a setting with parts is shown part by part', 'enabled: yes · enable_for_methods: []' === foodify_setup_display( [ 'enabled' => 'yes', 'enable_for_methods' => [] ] ) );

echo "── pages ──\n";
$pages = foodify_setup_pages();
$links = [];
foreach ( array_merge( glob( $T . 'parts/*.html' ), glob( $T . 'templates/*.html' ), glob( $T . 'patterns/*.php' ) ) as $f ) {
	preg_match_all( '~href="/([a-z0-9-]+)/"~', (string) file_get_contents( $f ), $m );
	$links = array_merge( $links, $m[1] );
}
$links   = array_values( array_unique( $links ) );
$uncovered = array_diff( $links, array_keys( $pages ), [ 'partner' ] );   // /partner/ is a rewrite endpoint (inc/coupon-attribution.php)
check( 'every page the theme\'s own links point at is one setup creates' . ( $uncovered ? ' — not created: ' . implode( ', ', $uncovered ) : '' ), ! $uncovered && count( $links ) >= 8 );
check( 'WooCommerce is pointed at its four pages', [ 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ] === array_values( array_filter( array_column( $pages, 'wc' ) ) ) );
check( 'the tracking page carries WooCommerce\'s form, as a shortcode BLOCK', str_contains( $pages['track-order']['content'], "<!-- wp:shortcode -->\n[woocommerce_order_tracking]" ) );
check( 'pages whose words are the business\'s are created empty — nothing is written for them', [] === array_filter( $pages, static fn( $p ) => 'write it' === $p['note'] && '' !== $p['content'] ) );
$src = (string) file_get_contents( $T . 'inc/setup.php' );
check( 'a page is created only where absent; none is ever updated or deleted', ! preg_match( '/wp_update_post|wp_trash_post/', $src ) && 1 === substr_count( $src, 'wp_insert_post(' ) );
check( 'plain permalinks are switched; an existing structure is left alone', str_contains( $src, "if ( '' === (string) get_option( 'permalink_structure' ) ) {" ) );

echo "── business details ──\n";
$c = foodify_setup_clean_details( [ 'tagline' => "  6 minute <b>mein</b>\n ghar ka khana ready! ", 'phone' => '+91 98765 43210', 'fssai' => '1001 2345 0000 01' ] );
check( 'tags and line breaks leave the headline', '6 minute mein ghar ka khana ready!' === $c['values']['tagline'] );
check( 'a 14-digit licence is kept, spaces removed', '10012345000001' === $c['values']['fssai'] && ! $c['errors'] );
$bad = foodify_setup_clean_details( [ 'phone' => 'call us', 'fssai' => '1234' ] );
check( 'a licence that is not 14 digits is REFUSED, not stored', '' === $bad['values']['fssai'] && '' === $bad['values']['phone'] && 2 === count( $bad['errors'] ) );
check( 'the form lays only phone and licence over the profile', [ 'phone' => '99', 'fssai' => '10012345000001', 'legal_name' => 'AVAC' ] === foodify_business_details_merge( [ 'phone' => '', 'fssai' => '', 'legal_name' => 'AVAC' ], [ 'phone' => ' 99 ', 'fssai' => '10012345000001', 'legal_name' => 'Evil' ] ) );
check( 'a stored blank never erases a default; rubbish is ignored', [ 'phone' => '1' ] === foodify_business_details_merge( [ 'phone' => '1' ], [ 'phone' => '' ] ) && [ 'phone' => '1' ] === foodify_business_details_merge( [ 'phone' => '1' ], 'x' ) );
check( 'a refused field leaves what was stored (a typo cannot erase a licence)', str_contains( $src, "if ( '' !== \$clean['values'][ \$k ] ) {" ) );
$bp = (string) file_get_contents( $T . 'inc/business-profile.php' );
check( 'the profile reads the form, and a filter in code still has the last word', strpos( $bp, 'foodify_business_details_merge( $profile' ) < strpos( $bp, "apply_filters( 'foodify_business_profile', \$profile )" ) );

echo "── the steps ──\n";
$blank = foodify_setup_steps( [ 'wc_active' => false, 'permalinks_plain' => true, 'pages_missing' => [ '/shop/', '/cart/' ], 'details' => [] ] );
$st    = array_column( $blank, 'state', 'key' );
check( 'a blank WordPress: WooCommerce, pages and details to do; the shop steps wait for WooCommerce', [ 'plugins' => 'todo', 'pages' => 'todo', 'settings' => 'blocked', 'details' => 'todo', 'products' => 'blocked' ] === $st );
check( 'plain links are named as the reason /shop/ shows the wrong page', str_contains( implode( ' ', $blank[1]['lines'] ), 'plain links' ) && str_contains( implode( ' ', $blank[1]['lines'] ), '2 pages the theme links to do not exist: /shop/, /cart/.' ) );
$ready = [ 'wc_active' => true, 'details' => [ 'tagline' => 'x', 'phone' => '1', 'fssai' => '10012345000001' ], 'product_count' => 44 ];
check( 'a set-up shop: every step done', [ 'done' ] === array_values( array_unique( array_column( foodify_setup_steps( $ready ), 'state' ) ) ) && 0 === foodify_setup_open_count( foodify_setup_steps( $ready ) ) );
$draft = foodify_setup_steps( $ready + [ 'pages_draft' => [ 'Privacy policy' ] ] );
check( 'a page that is only a draft is not "done" — visitors get page-not-found', 'todo' === $draft[1]['state'] && str_contains( implode( ' ', $draft[1]['lines'] ), 'Not published yet' ) );
$live = foodify_setup_steps( [ 'wc_active' => true, 'has_orders' => true, 'option_changes' => [ [ 'option' => 'a', 'now' => 'b', 'want' => 'c' ] ] ] );
check( 'a store with orders is told so before its settings are touched', str_contains( implode( ' ', $live[2]['lines'] ), 'THIS STORE HAS ORDERS' ) );
$sam = foodify_setup_steps( $ready + [ 'sample_count' => 12 ] );
check( 'samples in the store keep the products step open, and say they are not the catalogue', 'todo' === $sam[4]['state'] && str_contains( implode( ' ', $sam[4]['lines'] ), 'SAMPLES ARE NOT THE CATALOGUE' ) );

echo "── the screen ──\n";
$h = foodify_setup_screen_html( $blank, [ 'forms' => [ 'pages' => '<form id="x"></form>' ], 'notice' => 'Saved <ok>', 'errors' => [ 'Bad <thing>' ] ] );
check( 'five numbered steps, each with its state in words', 5 === substr_count( $h, 'class="postbox fd-setup__step ' ) && str_contains( $h, '<h2 class="hndle">3. Shop settings</h2>' ) && str_contains( $h, 'Needs WooCommerce first' ) );
check( 'a step\'s form is printed inside its box', str_contains( $h, '<form id="x"></form></div></div>' ) );
check( 'results and refusals are escaped', str_contains( $h, 'Saved &lt;ok&gt;' ) && str_contains( $h, 'Bad &lt;thing&gt;' ) );
check( 'it says what it does NOT set (delivery charge, zones, returns, payments)', str_contains( $h, 'Not set here' ) && str_contains( $h, 'foodify_delivery_zones' ) && str_contains( $h, 'Payments' ) );
$t = foodify_setup_screen_html( $live, [ 'changes' => [ [ 'option' => 'woocommerce_currency', 'now' => 'USD', 'want' => 'INR' ] ] ] );
check( 'settings are shown before → after, before anything is applied', str_contains( $t, '<td><code>woocommerce_currency</code></td><td>USD</td><td>INR</td>' ) );

echo "── sample products ──\n";
$json = (string) file_get_contents( $T . 'assets/data/sample-products.json' );
$rows = foodify_sample_products( $json );
check( 'the file reads, every row whole (' . count( $rows ) . ')', count( $rows ) >= 10 && count( $rows ) === count( (array) json_decode( $json, true ) ) );
exec( 'python3 ' . escapeshellarg( $B . 'tools/export-sample-products.py' ) . ' --check 2>&1', $o, $rc );
check( 'it is the prototype\'s fixture set, exported — not retyped (' . trim( implode( ' ', $o ) ) . ')', 0 === $rc );
check( 'NO ratings or review counts travel with a sample', ! preg_match( '/rating|review/i', $json ) );
check( 'a row without a price, or outside the four ranges, is dropped', [] === foodify_sample_products( '[{"name":"X","slug":"x","category":"express","regular_price":0},{"name":"Y","slug":"y","category":"snacks","regular_price":10}]' ) );
check( 'dietary labels become the theme\'s own term slugs ("Millet based" → millet); unknown labels are dropped', [ 'millet', 'jain' ] === foodify_sample_dietary_slugs( [ 'Millet based', 'Jain', 'Keto' ], [ 'millet' => [ 'label' => 'Millet based' ], 'jain' => [ 'label' => 'Jain' ] ] ) );
check( 'samples are added on an EMPTY store only (checked on the server, not just by hiding the button)', str_contains( $src, "if ( ! class_exists( 'WC_Product_Simple' ) || [] !== foodify_setup_product_ids() ) {" ) );
check( 'each is marked, and removal deletes by that mark alone', str_contains( $src, "FOODIFY_SAMPLE_META      => '1'," ) && str_contains( $src, "if ( '1' === (string) get_post_meta( \$id, FOODIFY_SAMPLE_META, true ) && wp_delete_post( \$id, true ) ) {" ) );
check( 'while one exists every admin screen says so', str_contains( $src, 'sample products are in this store.' ) );

echo "── wiring ──\n";
check( 'the theme loads it', str_contains( (string) file_get_contents( $T . 'functions.php' ), "require_once FOODIFY_DIR . '/inc/setup.php';" ) );
check( 'administrators only, and every action carries its own nonce', str_contains( $src, "! current_user_can( 'manage_options' )" ) && str_contains( $src, "check_admin_referer( 'foodify_setup_' . \$do );" ) );
check( 'only the five actions it knows', str_contains( $src, "[ 'pages', 'settings', 'details', 'samples', 'remove_samples' ]" ) );
check( 'after activation in wp-admin the first screen is this one — once, never over WP-CLI', str_contains( $src, "add_action( 'after_switch_theme'" ) && str_contains( $src, "defined( 'WP_CLI' ) && WP_CLI" ) && str_contains( $src, "delete_option( 'foodify_setup_redirect' );" ) );
check( 'nothing is done on page load: every write is behind admin-post', 1 === substr_count( $src, "add_action( 'admin_post_foodify_setup'" ) && ! preg_match( "/add_action\( 'admin_menu'.*?update_option/s", substr( $src, 0, (int) strpos( $src, 'function foodify_setup_do_pages' ) ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
