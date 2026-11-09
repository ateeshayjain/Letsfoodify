<?php
/**
 * inc/tab-bar.php — the phone's bottom tab bar (client direction, 9 Nov 2026).
 *
 *   php tests/tab-bar-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/tab-bar.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
$T    = __DIR__ . '/../theme/foodify/';
$urls = [ 'home' => '/', 'cats' => '/shop/', 'again' => '/my-account/orders/', 'acct' => '/my-account/' ];

echo "── the tabs ──\n";
check( 'Home · Categories · Order again · Account, in that order', [ 'Home', 'Categories', 'Order again', 'Account' ] === array_values( array_map( static fn( $t ) => $t['label'], foodify_tab_bar_tabs() ) ) );
$h = foodify_tab_bar_html( $urls, 'cats' );
check( 'a named nav holding a list of links', str_starts_with( $h, '<nav class="fd-tabbar" aria-label="Shortcuts"><ul class="fd-tabbar__tabs">' ) && 4 === substr_count( $h, '<li><a class="fd-tabbar__tab' ) );
check( 'the current tab says so with aria-current="page", and only that one', 1 === substr_count( $h, 'aria-current="page"' ) && str_contains( $h, 'is-current" href="/shop/" aria-current="page">' ) );
check( 'icons are decoration; every tab has its words visible', 4 === substr_count( $h, 'aria-hidden="true" focusable="false"' ) && str_contains( $h, '<span class="fd-tabbar__label">Order again</span>' ) );
check( 'Order again goes to the orders list (each order there has Order again)', str_contains( $h, 'href="/my-account/orders/"' ) );
check( 'a tab with no link is left out; none at all, no bar', 3 === substr_count( foodify_tab_bar_html( [ 'home' => '/', 'cats' => '/s/', 'acct' => '/a/' ] ), '<li>' ) && '' === foodify_tab_bar_html( [] ) );
check( 'escaped', str_contains( foodify_tab_bar_html( [ 'home' => '/?a="b"' ] ), 'href="/?a=&quot;b&quot;"' ) );

echo "── which tab is current ──\n";
check( 'the home page', 'home' === foodify_tab_bar_current( [ 'front' => true ] ) );
check( 'the shop and every category', 'cats' === foodify_tab_bar_current( [ 'shop' => true ] ) && 'cats' === foodify_tab_bar_current( [ 'category' => true ] ) );
check( 'the orders list and an order are Order again, the rest of the account is Account', 'again' === foodify_tab_bar_current( [ 'account' => true, 'orders' => true ] ) && 'acct' === foodify_tab_bar_current( [ 'account' => true ] ) );
check( 'any other page: none', '' === foodify_tab_bar_current( [] ) && '' === foodify_tab_bar_current( [ 'search' => true ] ) );

echo "── where it is not ──\n";
$src = (string) file_get_contents( $T . 'inc/tab-bar.php' );
check( 'not printed on a product page, the cart or checkout — their own bars own the bottom',
	1 === preg_match( "/'wp_footer'.*?is_product\\(\\).*?is_cart\\(\\).*?is_checkout\\(\\)/s", $src ) );
check( 'decided by the URL, never by who is signed in (pages are edge-cached)', ! str_contains( $src, 'is_user_logged_in' ) && ! str_contains( $src, 'get_current_user_id' ) );
check( 'the theme loads it', str_contains( (string) file_get_contents( $T . 'functions.php' ), "require_once FOODIFY_DIR . '/inc/tab-bar.php';" ) );

echo "── the stacking ──\n";
$css = (string) file_get_contents( $T . 'style.css' );
check( 'phones only', str_contains( $css, ".fd-tabbar, .fd-tabbar[hidden] { display: none; }\n@media (max-width: 781px) {\n  .fd-tabbar:not([hidden]) {" ) );
check( 'one height token for the tab, so everything stacks on it exactly', str_contains( $css, '.fd-tabbar__tab {' ) && str_contains( $css, 'box-sizing: border-box; height: var(--wp--preset--spacing--80);' ) );
check( 'the View cart bar floats just above it', str_contains( $css, "body:has(.fd-tabbar:not([hidden])) .fd-viewcart:not([hidden]) {\n    bottom: calc(var(--wp--preset--spacing--80) + var(--wp--preset--spacing--20) + env(safe-area-inset-bottom, 0px));" ) );
check( 'the page pads for both, so nothing hides behind them', str_contains( $css, 'body:has(.fd-tabbar:not([hidden])):has(.fd-viewcart:not([hidden])) {' ) );
check( 'labels at the 16px floor, never smaller', str_contains( $css, "color: var(--wp--preset--color--mute); text-decoration: none;\n    font-size: var(--wp--preset--font-size--base);" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
