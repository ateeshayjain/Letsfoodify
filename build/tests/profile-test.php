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

echo "── the header's account button, from WooCommerce's real markup (13 Nov 2026) ──\n";
$out_html = '<div data-block-name="woocommerce/customer-account" class="wp-block-woocommerce-customer-account " style=""><a class="wc-block-customer-account__link" href="/my-account/"><div class="wc-block-customer-account__visual"><svg class="wc-block-customer-account__account-icon"></svg></div><span class="label">Login</span></a></div>';
$b = foodify_account_button_html( $out_html );
check( 'the wrapper gets the class style.css styles', str_contains( $b, 'class="wc-block-customer-account wp-block-woocommerce-customer-account' ) );
check( 'so does the link', str_contains( $b, 'wc-block-customer-account__link wc-block-customer-account__account-link' ) );
check( '"Login" reads "Sign in"', str_contains( $b, '<span class="label">Sign in</span>' ) );
$in_html = str_replace( [ '<svg class="wc-block-customer-account__account-icon"></svg>', 'Login' ], [ '<img decoding="async" alt="" src="https://secure.gravatar.com/avatar/abc?s=48" class="avatar avatar-48 photo wc-block-customer-account__avatar" height="48" width="48">', 'My Account' ], $out_html );
$b = foodify_account_button_html( $in_html );
check( 'a signed-in customer gets the icon, and NO request to gravatar.com', ! str_contains( $b, 'gravatar' ) && ! str_contains( $b, '<img' ) && str_contains( $b, '<svg class="wc-block-customer-account__account-icon"' ) );
check( '"My Account" reads "My account"', str_contains( $b, '>My account<' ) );
check( 'run twice, nothing is added twice', $b === foodify_account_button_html( $b ) );
check( 'markup that is not the account block is returned untouched', '<p>Login</p>' === foodify_account_button_html( '<p>Login</p>' ) );

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
check( 'on an order\'s page the way back is "‹ Orders"', '<p class="fd-profile-back is-always"><a href="/my-account/orders/"><span aria-hidden="true">‹</span> Orders</a></p>' === foodify_profile_back_html( '/my-account/orders/', 'Orders' ) && str_contains( (string) file_get_contents( $T . 'inc/profile.php' ), "is_wc_endpoint_url( 'view-order' )" ) );
check( '"‹ Profile" back to the account page', '<p class="fd-profile-back"><a href="/my-account/"><span aria-hidden="true">‹</span> Profile</a></p>' === foodify_profile_back_html( '/my-account/' ) );

echo "── wiring ──\n";
$src = (string) file_get_contents( $T . 'inc/profile.php' );
check( 'the theme loads it', str_contains( (string) file_get_contents( $T . 'functions.php' ), "require_once FOODIFY_DIR . '/inc/profile.php';" ) );
check( 'the list is the live account menu, so a plugin\'s section appears and a missing one never does', str_contains( $src, 'foodify_profile_menu( wc_get_account_menu_items()' ) );
check( 'on the dashboard; "‹ Profile" on every other account page', str_contains( $src, "add_action( 'woocommerce_account_dashboard'" ) && str_contains( $src, "is_wc_endpoint_url() ) {" ) );
check( 'the old dashboard line and button are gone from account.php', ! str_contains( (string) file_get_contents( $T . 'inc/account.php' ), "esc_html__( 'Orders &amp; reorder', 'foodify' )\n" ) && ! str_contains( (string) file_get_contents( $T . 'inc/account.php' ), "add_action( 'woocommerce_account_dashboard'" ) );
$css = (string) file_get_contents( $T . 'style.css' );
check( 'on a phone the side menu is hidden: the profile is the menu', str_contains( $css, ".woocommerce-account.logged-in .woocommerce-MyAccount-navigation { display: none; }\n  .fd-profile-back { display: block; }" ) );

echo "── the page heading (client, 9 Nov: \"Rename it to Profile\") ──\n";
check( 'signed in, the first screen is headed "Profile"', 'Profile' === foodify_account_heading( true, '' ) );
check( 'an order\'s page is headed "Order summary"', 'Order summary' === foodify_account_heading( true, 'view-order' ) );
check( 'signed out, or another section: no change', '' === foodify_account_heading( false, '' ) && '' === foodify_account_heading( true, 'orders' ) );
check( 'the heading\'s words are replaced, its tag and classes kept', '<h1 class="wp-block-post-title has-3-xl-font-size">Profile</h1>' === foodify_replace_heading_text( '<h1 class="wp-block-post-title has-3-xl-font-size">My account</h1>', 'Profile' ) );
check( 'no words: the block as it was', '<h1>My account</h1>' === foodify_replace_heading_text( '<h1>My account</h1>', '' ) );
$psrc = (string) file_get_contents( $T . 'inc/profile.php' );
check( 'only the account page\'s own title block — never a product card\'s', str_contains( $psrc, "! empty( \$block['attrs']['__woocommerceNamespace'] )" ) && str_contains( $psrc, '! is_account_page()' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
