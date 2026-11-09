<?php
/**
 * inc/profile.php — the account's first screen as a profile (client, 9 Nov 2026).
 *
 *   php tests/profile-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/profile.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
$T = __DIR__ . '/../theme/foodify/';

echo "── the menu, the profile's way ──\n";
$menu = [ 'dashboard' => 'Dashboard', 'orders' => 'Orders &amp; reorder', 'address-book' => 'Saved addresses', 'partner-earnings' => '<b>Partner</b>', 'customer-logout' => 'Log out' ];
$m    = foodify_profile_menu( $menu, static fn( string $k ): string => '/a/' . $k . '/' );
check( 'every account door but the dashboard and Log out, in the menu\'s order', [ 'orders', 'address-book', 'partner-earnings' ] === array_column( $m['items'], 'key' ) );
check( 'labels are plain text, decoded once ("Orders & reorder")', 'Orders & reorder' === $m['items'][0]['label'] && 'Partner' === $m['items'][2]['label'] );
check( 'Log out is kept apart, for the foot of the screen', '/a/customer-logout/' === $m['logout'] );

echo "── the screen ──\n";
$h = foodify_profile_html( [
	'name' => 'Asha <R>', 'phone' => '9876543210', 'email' => 'a@b.in',
	'tiles' => [ [ 'key' => 'orders', 'label' => 'Orders', 'url' => '/o/' ], [ 'key' => 'address-book', 'label' => 'Addresses', 'url' => '/ad/' ], [ 'key' => 'help', 'label' => 'Help', 'url' => '/contact-us/' ] ],
	'groups' => [ [ 'title' => 'Your information', 'items' => $m['items'] ], [ 'title' => 'Empty', 'items' => [] ] ],
	'logout' => $m['logout'],
] );
check( 'a labelled section headed by the name (escaped)', str_contains( $h, '<section class="fd-profile" aria-labelledby="fd-profile-name"><h2 class="fd-profile__name" id="fd-profile-name">Asha &lt;R&gt;</h2>' ) );
check( 'mobile and email, each said in words to a screen reader', str_contains( $h, '<span class="fd-sr">Mobile </span>9876543210</li>' ) && str_contains( $h, '<span class="fd-sr">Email </span>a@b.in</li>' ) );
check( 'three tiles, each a link with its words', 3 === substr_count( $h, 'class="fd-profile__tile"' ) && str_contains( $h, '<span>Help</span></a>' ) );
check( 'each group is a list labelled by its heading', str_contains( $h, '<h3 class="fd-profile__group" id="fd-profile-g0">Your information</h3><ul class="fd-profile__list" aria-labelledby="fd-profile-g0">' ) );
check( 'a row: round icon, words, chevron — the whole row is the link', str_contains( $h, '<a class="fd-profile__row" href="/a/orders/"><span class="fd-profile__icon"><svg' ) && str_contains( $h, '<span class="fd-profile__label">Orders &amp; reorder</span><svg class="fd-profile__chev"' ) );
check( 'a group with nothing in it is not printed', ! str_contains( $h, '>Empty<' ) );
check( 'Log out last, on its own', str_ends_with( $h, '<span class="fd-profile__label">Log out</span></a></li></ul></section>' ) );
check( 'icons are decoration', 0 === preg_match( '/<svg(?![^>]*aria-hidden="true")/', $h ) );
check( 'no name: "Your account"', str_contains( foodify_profile_html( [ 'name' => ' ', 'tiles' => [], 'groups' => [] ] ), '>Your account</h2>' ) );
check( 'an unknown door still gets an icon', str_contains( foodify_profile_icon( 'partner-earnings' ), '<path d="M6 3h9l4 4v14H6z' ) );
check( 'nothing borrowed the shop does not have (wallet, wishlist, prescriptions, gift cards)', ! preg_match( '/wallet|wishlist|prescription|gift card/i', $h ) );
check( '"‹ Profile" back to the account page', '<p class="fd-profile-back"><a href="/my-account/"><span aria-hidden="true">‹</span> Profile</a></p>' === foodify_profile_back_html( '/my-account/' ) );

echo "── wiring ──\n";
$src = (string) file_get_contents( $T . 'inc/profile.php' );
check( 'the theme loads it', str_contains( (string) file_get_contents( $T . 'functions.php' ), "require_once FOODIFY_DIR . '/inc/profile.php';" ) );
check( 'the list is the live account menu, so a plugin\'s section appears and a missing one never does', str_contains( $src, 'foodify_profile_menu( wc_get_account_menu_items()' ) );
check( 'on the dashboard; "‹ Profile" on every other account page', str_contains( $src, "add_action( 'woocommerce_account_dashboard'" ) && str_contains( $src, "is_wc_endpoint_url() ) {" ) );
check( 'the old dashboard line and button are gone from account.php', ! str_contains( (string) file_get_contents( $T . 'inc/account.php' ), "esc_html__( 'Orders &amp; reorder', 'foodify' )\n" ) && ! str_contains( (string) file_get_contents( $T . 'inc/account.php' ), "add_action( 'woocommerce_account_dashboard'" ) );
$css = (string) file_get_contents( $T . 'style.css' );
check( 'on a phone the side menu is hidden: the profile is the menu', str_contains( $css, ".woocommerce-account.logged-in .woocommerce-MyAccount-navigation { display: none; }\n  .fd-profile-back { display: block; }" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
