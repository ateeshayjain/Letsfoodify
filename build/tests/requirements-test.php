<?php
/**
 * inc/requirements.php — the plugins the theme needs, said in wp-admin (client, 12 Nov 2026).
 *
 *   php tests/requirements-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/requirements.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
$T    = __DIR__ . '/../theme/foodify/';
$reqs = foodify_plugin_requirements();

echo "── the list ──\n";
check( 'WooCommerce is the one required plugin', [ 'woocommerce' ] === array_column( array_filter( $reqs, static fn( $r ) => $r['required'] ), 'slug' ) );
$boot = (string) file_get_contents( __DIR__ . '/../scripts/bootstrap.sh' );
$free = array_column( array_filter( $reqs, static fn( $r ) => ! $r['required'] ), 'slug' );
check( 'every recommended plugin is one bootstrap.sh installs (one list, not two)', [] === array_filter( $free, static fn( $s ) => ! str_contains( $boot, $s ) ) );
check( 'nothing paid is asked for (rule 5): no Pro, no Digits, no Elementor', ! preg_match( '/pro\b|digits|elementor/i', implode( ' ', array_column( $reqs, 'slug' ) ) ) );

echo "── what is missing ──\n";
$m = foodify_missing_plugins( $reqs, [ 'woocommerce/woocommerce.php', 'wp-mail-smtp/wp_mail_smtp.php', 'seo-by-rank-math/rank-math.php' ], [ 'seo-by-rank-math/rank-math.php' ] );
$by = array_column( $m, null, 'slug' );
check( 'an active plugin is not mentioned', ! isset( $by['seo-by-rank-math'] ) );
check( 'installed but off: "activate", naming the file actually installed', 'activate' === $by['woocommerce']['need'] && 'woocommerce/woocommerce.php' === $by['woocommerce']['file'] );
check( 'not there at all: "install"', 'install' === $by['judgeme-product-reviews-woocommerce']['need'] && '' === $by['judgeme-product-reviews-woocommerce']['file'] );
check( 'matched by folder, so a main file named otherwise still counts as active', [] === array_filter( foodify_missing_plugins( $reqs, [ 'woocommerce/wc.php' ], [ 'woocommerce/wc.php' ] ), static fn( $x ) => 'woocommerce' === $x['slug'] ) );
check( 'a folder that merely starts the same is not the plugin ("woocommerce-pdf…" is not WooCommerce)', isset( array_column( foodify_missing_plugins( $reqs, [], [ 'woocommerce-pdf-invoices-packing-slips/x.php' ] ), null, 'slug' )['woocommerce'] ) );
check( 'everything active: nothing to say', [] === foodify_missing_plugins( $reqs, [], array_map( static fn( $r ) => $r['slug'] . '/x.php', $reqs ) ) );

echo "── the notice ──\n";
$wc = array_values( array_filter( $m, static fn( $x ) => $x['required'] ) );
$h  = foodify_requirements_notice_html( $wc, [ 'woocommerce' => '/wp-admin/plugins.php?action=activate&plugin=woocommerce%2Fwoocommerce.php&_wpnonce=a"b' ], true );
check( 'required: an error notice that says what breaks without it', str_contains( $h, 'class="notice notice-error fd-requirements"' ) && str_contains( $h, 'shop, cart and checkout pages are empty' ) );
check( 'a real button with the right verb, its URL escaped', str_contains( $h, '<a class="button button-small" href="/wp-admin/plugins.php?action=activate&amp;plugin=woocommerce%2Fwoocommerce.php&amp;_wpnonce=a&quot;b">Activate WooCommerce</a>' ) );
$no = foodify_requirements_notice_html( $wc, [], true );
check( 'someone who cannot install: no button, told to ask an administrator', ! str_contains( $no, '<a ' ) && str_contains( $no, 'Ask a site administrator to install it.' ) );
$opt = foodify_requirements_notice_html( array_values( array_filter( $m, static fn( $x ) => ! $x['required'] ) ), [ 'judgeme-product-reviews-woocommerce' => '/i' ], false );
check( 'recommended: an info notice, "Install" for one not there', str_contains( $opt, 'notice-info' ) && str_contains( $opt, '>Install Judge.me Product Reviews</a>' ) );
check( 'nothing missing: nothing printed', '' === foodify_requirements_notice_html( [], [], true ) );

echo "── wiring ──\n";
$src = (string) file_get_contents( $T . 'inc/requirements.php' );
check( 'the theme loads it', str_contains( (string) file_get_contents( $T . 'functions.php' ), "require_once FOODIFY_DIR . '/inc/requirements.php';" ) );
check( 'install and activate links carry WordPress\'s own nonces', str_contains( $src, "'install-plugin_' . \$m['slug']" ) && str_contains( $src, "'activate-plugin_' . \$m['file']" ) );
check( 'buttons only for users allowed to press them', str_contains( $src, "current_user_can( 'install_plugins' )" ) && str_contains( $src, "current_user_can( 'activate_plugins' )" ) );
check( 'the recommended list only on the Themes, Plugins and Foodify setup screens — it never nags', str_contains( $src, "[ 'themes', 'plugins', 'appearance_page_foodify-setup' ]" ) );
check( 'nothing installs by itself', ! preg_match( '/Plugin_Upgrader|activate_plugin\s*\(/', $src ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
