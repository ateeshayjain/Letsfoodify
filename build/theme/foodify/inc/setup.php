<?php
/**
 * Appearance → Foodify setup: the shop set up from wp-admin, by button.
 *
 * WHY THIS EXISTS (13 Nov 2026). The kit assumed the theme goes onto a copy of
 * the live shop and is configured by scripts/bootstrap.sh over SSH. The
 * client's developer uploaded the zip to an empty WordPress through wp-admin
 * instead — no SSH, no WP-CLI — and got a bare page: no WooCommerce, no pages
 * behind the menu, plain permalinks (so /shop/ showed the home page), no
 * settings, no products. This screen is what that person needs.
 *
 * WHAT IT DOES, each step by its own button and nothing by itself:
 *   1. Plugins      — WooCommerce (inc/requirements.php's Install/Activate links).
 *   2. Pages        — readable permalinks if the site has none, and every page
 *                     the theme links to, created only where ABSENT.
 *   3. Shop settings— the SAME values scripts/bootstrap.sh Phase 4 writes
 *                     (tests/setup-test.php compares the two lists, so they
 *                     cannot drift), the prep/dietary filter attributes and
 *                     the four range categories. Shown as a before → after
 *                     list first; applied on click.
 *   4. Business     — tagline, phone and FSSAI licence, typed by a person.
 *   5. Products     — how to bring the real catalogue (WooCommerce's own
 *                     Export/Import), and, on an EMPTY store only, a dozen
 *                     SAMPLE products so the design can be seen.
 *
 * WHAT IT DOES NOT DO. Shipping zones and rates, delivery zones, the returns
 * window, box sizes and the public offer are client policy (bootstrap.sh
 * reports each); the screen lists them and writes none. It never deletes or
 * rewrites a page, and it never touches a product it did not add.
 *
 * THE SAMPLE PRODUCTS ARE NOT THE CATALOGUE. Real SKU names, fixture prices,
 * pack sizes and ingredient lists (assets/data/sample-products.json, exported
 * from the prototype). They carry no ratings or reviews. Each is marked
 * `_foodify_sample`, a notice stays on every admin screen while one exists,
 * and one button removes exactly those.
 *
 * Pure functions first; WordPress glue below the function_exists guard.
 */
declare( strict_types = 1 );

/**
 * Pages the theme's own links need. `wc` is the WooCommerce option that must
 * point at the page; `content` is written only when the page is created.
 *
 * @return array<string,array{title:string,content:string,wc:string,note:string}>
 */
function foodify_setup_pages(): array {
	$p = static fn( string $title, string $note = '', string $content = '', string $wc = '' ): array => [ 'title' => $title, 'content' => $content, 'wc' => $wc, 'note' => $note ];
	return [
		// The shop's own pages. The theme's templates carry the forms, so the pages are empty.
		'shop'           => $p( 'Shop', '', '', 'woocommerce_shop_page_id' ),
		'cart'           => $p( 'Cart', '', '', 'woocommerce_cart_page_id' ),
		'checkout'       => $p( 'Checkout', '', '', 'woocommerce_checkout_page_id' ),
		'my-account'     => $p( 'My account', '', '', 'woocommerce_myaccount_page_id' ),
		'build-a-box'    => $p( 'Build your own box' ),
		'track-order'    => $p( 'Track your order', '', "<!-- wp:shortcode -->\n[woocommerce_order_tracking]\n<!-- /wp:shortcode -->" ),
		// Linked from the footer. Their words are the business's, so they are created empty.
		'shipping'       => $p( 'Delivery', 'write it' ),
		'returns'        => $p( 'Returns', 'write it' ),
		'contact-us'     => $p( 'Contact us', 'write it' ),
		'our-story'      => $p( 'Our story', 'write it' ),
		'terms'          => $p( 'Terms', 'write it' ),
		'privacy-policy' => $p( 'Privacy policy', 'write it' ),
	];
}

/**
 * The shop settings. THE SAME LIST AS scripts/bootstrap.sh "Phase 4 · Commerce
 * configuration" and "Cash on delivery" — tests/setup-test.php reads both and
 * fails on any difference. Change one, change the other (rule 2).
 *
 * @return array<string,mixed>
 */
function foodify_setup_options(): array {
	return [
		'woocommerce_currency'                           => 'INR',
		'woocommerce_currency_pos'                       => 'left',
		'woocommerce_price_thousand_sep'                 => ',',
		'woocommerce_price_decimal_sep'                  => '.',
		'woocommerce_price_num_decimals'                 => '2',
		'woocommerce_default_catalog_orderby'            => 'popularity',
		'woocommerce_default_country'                    => 'IN:UP',
		'woocommerce_allowed_countries'                  => 'specific',
		'woocommerce_specific_allowed_countries'         => [ 'IN' ],
		'woocommerce_ship_to_countries'                  => 'specific',
		'woocommerce_specific_ship_to_countries'         => [ 'IN' ],
		'woocommerce_enable_guest_checkout'              => 'yes',
		'woocommerce_enable_checkout_login_reminder'     => 'yes',
		'woocommerce_enable_signup_and_login_from_checkout' => 'no',
		'woocommerce_enable_myaccount_registration'      => 'yes',
		'woocommerce_ship_to_destination'                => 'billing_only',
		'woocommerce_enable_shipping_calc'               => 'yes',
		'woocommerce_shipping_cost_requires_address'     => 'no',
		'woocommerce_cart_redirect_after_add'            => 'no',
		'woocommerce_enable_ajax_add_to_cart'            => 'yes',
		'woocommerce_cod_settings'                       => [
			'enabled'            => 'yes',
			'title'              => 'Cash on delivery',
			'description'        => 'Pay in cash when your order arrives.',
			'instructions'       => 'Please keep exact change ready.',
			'enable_for_methods' => [],
			'enable_for_virtual' => 'no',
		],
	];
}

/** The four ranges the header links to, as product categories. */
function foodify_setup_categories(): array {
	return [ 'express' => 'Express', 'hot-and-fresh' => 'Hot & Fresh', 'flavors' => 'Flavors', 'combos' => 'Combos' ];
}

/** A setting's value, as a person reads it. */
function foodify_setup_display( $value ): string {
	if ( is_array( $value ) ) {
		$flat = static fn( $v ): string => is_array( $v ) ? '[' . implode( ', ', array_map( 'strval', $v ) ) . ']' : (string) $v;
		return array_is_list( $value )
			? implode( ', ', array_map( $flat, $value ) )
			: implode( ' · ', array_map( static fn( $k, $v ): string => $k . ': ' . $flat( $v ), array_keys( $value ), $value ) );
	}
	return false === $value || null === $value || '' === $value ? '(not set)' : (string) $value;
}

/** Two setting values are the same setting (WordPress stores 2 and "2" alike). */
function foodify_setup_same( $a, $b ): bool {
	if ( is_array( $a ) || is_array( $b ) ) {
		if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( $a as $k => $v ) {
			if ( ! array_key_exists( $k, $b ) || ! foodify_setup_same( $v, $b[ $k ] ) ) {
				return false;
			}
		}
		return true;
	}
	return false !== $a && null !== $a && false !== $b && null !== $b && (string) $a === (string) $b;
}

/**
 * The settings that would change, each with what it is now.
 *
 * @param array<string,mixed> $want
 * @param callable(string):mixed $get  Reads an option; false when unset.
 * @return list<array{option:string,now:string,want:string}>
 */
function foodify_setup_option_changes( array $want, callable $get ): array {
	$out = [];
	foreach ( $want as $option => $value ) {
		$now = $get( $option );
		if ( ! foodify_setup_same( $now, $value ) ) {
			$out[] = [ 'option' => $option, 'now' => foodify_setup_display( $now ), 'want' => foodify_setup_display( $value ) ];
		}
	}
	return $out;
}

/** FSSAI licence numbers are 14 digits. (inc/business-profile.php holds the same rule; this file is tested alone.) */
function foodify_setup_valid_fssai( string $fssai ): bool {
	return 1 === preg_match( '/^[1-9]\d{13}$/', $fssai );
}

/**
 * The business details form, cleaned. Nothing is invented: a blank stays blank,
 * and a licence that is not 14 digits is refused, not stored.
 *
 * @return array{values:array{tagline:string,phone:string,fssai:string},errors:list<string>}
 */
function foodify_setup_clean_details( array $in ): array {
	$one     = static fn( $v ): string => trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $v ) ) );
	$tagline = mb_substr( $one( $in['tagline'] ?? '' ), 0, 120 );
	$phone   = $one( $in['phone'] ?? '' );
	$fssai   = (string) preg_replace( '/[\s-]+/', '', $one( $in['fssai'] ?? '' ) );
	$errors  = [];
	if ( '' !== $phone && ! preg_match( '/^\+?[0-9][0-9 ]{8,16}$/', $phone ) ) {
		$errors[] = 'The phone number should be digits (with +91 if you show it that way). It was not saved.';
		$phone    = '';
	}
	if ( '' !== $fssai && ! foodify_setup_valid_fssai( $fssai ) ) {
		$errors[] = 'An FSSAI licence number is 14 digits. It was not saved.';
		$fssai    = '';
	}
	return [ 'values' => [ 'tagline' => $tagline, 'phone' => $phone, 'fssai' => $fssai ], 'errors' => $errors ];
}

/**
 * Phone and licence typed on the setup screen, laid over the profile's defaults.
 * Only those two keys, only non-empty strings: a stored blank never erases a
 * default, and nothing else in the profile can be set from a form.
 */
function foodify_business_details_merge( array $profile, $stored ): array {
	foreach ( [ 'phone', 'fssai' ] as $k ) {
		if ( is_array( $stored ) && isset( $stored[ $k ] ) && is_string( $stored[ $k ] ) && '' !== trim( $stored[ $k ] ) ) {
			$profile[ $k ] = trim( $stored[ $k ] );
		}
	}
	return $profile;
}

/**
 * The sample products file, checked: a row missing a name, slug, range or price
 * is dropped rather than half-created.
 *
 * @return list<array<string,mixed>>
 */
function foodify_sample_products( string $json ): array {
	$rows = json_decode( $json, true );
	$out  = [];
	foreach ( is_array( $rows ) ? $rows : [] as $r ) {
		if ( ! is_array( $r ) || '' === trim( (string) ( $r['name'] ?? '' ) ) || ! preg_match( '/^[a-z0-9-]+$/', (string) ( $r['slug'] ?? '' ) )
			|| ! isset( foodify_setup_categories()[ (string) ( $r['category'] ?? '' ) ] ) || (float) ( $r['regular_price'] ?? 0 ) <= 0 ) {
			continue;
		}
		$out[] = $r;
	}
	return $out;
}

/**
 * Dietary labels ("Millet based") to their attribute term slugs ("millet"),
 * through the theme's own attribute map; a label the map does not know is dropped.
 *
 * @param list<string> $labels
 * @param array<string,array{label:string}> $terms foodify_attribute_map()['dietary']['terms']
 * @return list<string>
 */
function foodify_sample_dietary_slugs( array $labels, array $terms ): array {
	$by = [];
	foreach ( $terms as $slug => $t ) {
		$by[ strtolower( (string) ( $t['label'] ?? '' ) ) ] = (string) $slug;
	}
	$out = [];
	foreach ( $labels as $l ) {
		$k = strtolower( trim( (string) $l ) );
		if ( isset( $by[ $k ] ) ) {
			$out[] = $by[ $k ];
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * The screen's steps, from facts about the site. Each: key, title, state
 * ('done' | 'todo' | 'blocked' — blocked means WooCommerce is needed first),
 * and lines saying what is there and what is not.
 *
 * @param array<string,mixed> $f
 * @return list<array{key:string,title:string,state:string,lines:list<string>}>
 */
function foodify_setup_steps( array $f ): array {
	$wc    = ! empty( $f['wc_active'] );
	$n     = static fn( int $count, string $one, string $many ): string => $count . ' ' . ( 1 === $count ? $one : $many );
	$steps = [];

	$steps[] = [
		'key'   => 'plugins',
		'title' => 'WooCommerce',
		'state' => $wc ? 'done' : 'todo',
		'lines' => $wc ? [ 'WooCommerce is active.' ] : [ 'The shop, cart, checkout and accounts are WooCommerce. Nothing below about the shop can be set up until it is active.' ],
	];

	$missing = array_values( (array) ( $f['pages_missing'] ?? [] ) );
	$plain   = ! empty( $f['permalinks_plain'] );
	$unwired = array_values( (array) ( $f['pages_unwired'] ?? [] ) );
	$lines   = [];
	if ( $plain ) {
		$lines[] = 'This site uses plain links (?p=123), so /shop/ and every menu link show the wrong page. Setup switches it to readable links (/sample-page/).';
	}
	if ( $missing ) {
		$lines[] = $n( count( $missing ), 'page the theme links to does not exist', 'pages the theme links to do not exist' ) . ': ' . implode( ', ', $missing ) . '.';
	}
	if ( $unwired && $wc ) {
		$lines[] = 'WooCommerce is not pointed at: ' . implode( ', ', $unwired ) . '.';
	}
	$drafts = array_values( (array) ( $f['pages_draft'] ?? [] ) );
	if ( $drafts ) {
		$lines[] = 'Not published yet, so visitors get "page not found": ' . implode( ', ', $drafts ) . '. Publish ' . ( 1 === count( $drafts ) ? 'it' : 'them' ) . ' under Pages when the words are ready.';
	}
	$empty = array_values( (array) ( $f['pages_empty'] ?? [] ) );
	if ( $empty ) {
		$lines[] = 'Created, and still empty — their words are yours to write: ' . implode( ', ', $empty ) . '.';
	}
	$pages_done = ! $plain && ! $missing && ! $drafts && ( ! $wc || ! $unwired );
	$steps[]    = [
		'key'   => 'pages',
		'title' => 'Pages and links',
		'state' => $pages_done ? 'done' : 'todo',
		'lines' => $lines ?: [ 'Every page the theme links to exists.' ],
	];

	$changes  = (array) ( $f['option_changes'] ?? [] );
	$attrs    = array_values( (array) ( $f['attributes_missing'] ?? [] ) );
	$cats     = array_values( (array) ( $f['categories_missing'] ?? [] ) );
	$lines    = [];
	if ( $changes ) {
		$lines[] = $n( count( $changes ), 'shop setting differs', 'shop settings differ' ) . ' from what the theme is built for (listed below).';
	}
	if ( $attrs ) {
		$lines[] = 'The shop filters need these product attributes: ' . implode( ', ', $attrs ) . '.';
	}
	if ( $cats ) {
		$lines[] = 'The menu links to these product categories, which do not exist: ' . implode( ', ', $cats ) . '.';
	}
	if ( $wc && ! empty( $f['has_orders'] ) && ( $changes || $attrs || $cats ) ) {
		$lines[] = 'THIS STORE HAS ORDERS. Read the list before applying: these are the settings of a shop that is trading.';
	}
	$steps[] = [
		'key'   => 'settings',
		'title' => 'Shop settings',
		'state' => ! $wc ? 'blocked' : ( $changes || $attrs || $cats ? 'todo' : 'done' ),
		'lines' => ! $wc ? [ 'Currency, India-only selling, guest checkout, cash on delivery, the filters and the four ranges.' ] : ( $lines ?: [ 'The shop is set the way the theme expects.' ] ),
	];

	$d     = (array) ( $f['details'] ?? [] );
	$blank = array_keys( array_filter( [ 'the headline' => '' === (string) ( $d['tagline'] ?? '' ), 'the phone number' => '' === (string) ( $d['phone'] ?? '' ), 'the FSSAI licence' => '' === (string) ( $d['fssai'] ?? '' ) ] ) );
	$steps[] = [
		'key'   => 'details',
		'title' => 'Business details',
		'state' => $blank ? 'todo' : 'done',
		'lines' => $blank
			? [ 'Not given yet: ' . implode( ', ', $blank ) . '. Until they are, the site prints NOT CONFIGURED where each belongs — on purpose, so a made-up licence number can never ship.' ]
			: [ 'Headline, phone and FSSAI licence are set.' ],
	];

	$count   = (int) ( $f['product_count'] ?? 0 );
	$samples = (int) ( $f['sample_count'] ?? 0 );
	$lines   = [];
	if ( ! $wc ) {
		$lines[] = 'Products need WooCommerce.';
	} elseif ( 0 === $count ) {
		$lines[] = 'This store has no products, so every shelf and the shop page are empty.';
	} else {
		$lines[] = 'This store has ' . $n( $count, 'product', 'products' ) . ( $samples ? ', of which ' . $n( $samples, 'is a sample', 'are samples' ) . ' added by this screen' : '' ) . '.';
	}
	if ( $samples ) {
		$lines[] = 'SAMPLES ARE NOT THE CATALOGUE: real names, made-up prices, pack sizes and ingredients. Remove them before the shop takes an order.';
	}
	$steps[] = [
		'key'   => 'products',
		'title' => 'Products',
		'state' => ! $wc ? 'blocked' : ( 0 === $count || $samples ? 'todo' : 'done' ),
		'lines' => $lines,
	];

	return $steps;
}

/** How many steps still need someone. */
function foodify_setup_open_count( array $steps ): int {
	return count( array_filter( $steps, static fn( array $s ): bool => 'done' !== $s['state'] ) );
}

/**
 * The screen. $ctx carries what only WordPress can make: `forms` (step key →
 * ready HTML for its buttons and fields), `changes` (the settings list), and
 * `notice` (the result of the last action).
 *
 * @param list<array{key:string,title:string,state:string,lines:list<string>}> $steps
 * @param array{forms?:array<string,string>,changes?:list<array{option:string,now:string,want:string}>,notice?:string,errors?:list<string>} $ctx
 */
function foodify_setup_screen_html( array $steps, array $ctx = [] ): string {
	$e     = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	$label = [ 'done' => 'Done', 'todo' => 'To do', 'blocked' => 'Needs WooCommerce first' ];
	$icon  = [ 'done' => 'dashicons-yes-alt', 'todo' => 'dashicons-marker', 'blocked' => 'dashicons-lock' ];
	$open  = foodify_setup_open_count( $steps );

	$out  = '<div class="wrap fd-setup"><h1>Foodify setup</h1>';
	$out .= '<p>' . ( 0 === $open
		? 'Everything this screen can set up is done.'
		: $e( $open . ( 1 === $open ? ' step is' : ' steps are' ) . ' left. Each button below does one thing, and says what, before it does it. Nothing happens by itself.' ) ) . '</p>';
	if ( ! empty( $ctx['notice'] ) ) {
		$out .= '<div class="notice notice-success inline"><p>' . $e( (string) $ctx['notice'] ) . '</p></div>';
	}
	foreach ( (array) ( $ctx['errors'] ?? [] ) as $err ) {
		$out .= '<div class="notice notice-error inline"><p>' . $e( (string) $err ) . '</p></div>';
	}

	$i = 0;
	foreach ( $steps as $s ) {
		$i++;
		$state = isset( $label[ $s['state'] ] ) ? $s['state'] : 'todo';
		$out  .= '<div class="postbox fd-setup__step fd-setup__step--' . $e( $state ) . '" id="fd-setup-' . $e( $s['key'] ) . '">'
			. '<div class="postbox-header"><h2 class="hndle">' . $i . '. ' . $e( $s['title'] ) . '</h2>'
			. '<p class="fd-setup__state"><span class="dashicons ' . $icon[ $state ] . '" aria-hidden="true"></span> ' . $label[ $state ] . '</p></div>'
			. '<div class="inside">';
		foreach ( $s['lines'] as $line ) {
			$out .= '<p>' . $e( $line ) . '</p>';
		}
		if ( 'settings' === $s['key'] && 'blocked' !== $state && ! empty( $ctx['changes'] ) ) {
			$out .= '<table class="widefat striped fd-setup__changes"><thead><tr><th scope="col">Setting</th><th scope="col">Now</th><th scope="col">After</th></tr></thead><tbody>';
			foreach ( $ctx['changes'] as $c ) {
				$out .= '<tr><td><code>' . $e( $c['option'] ) . '</code></td><td>' . $e( $c['now'] ) . '</td><td>' . $e( $c['want'] ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		$out .= (string) ( $ctx['forms'][ $s['key'] ] ?? '' );
		$out .= '</div></div>';
	}

	$out .= '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Not set here</h2></div><div class="inside">'
		. '<p>These are the business\'s decisions, so this screen sets none of them. Each part of the site simply stays off, or says so, until it is given:</p>'
		. '<ul class="ul-disc"><li><strong>Delivery charge and free-delivery amount</strong> — WooCommerce → Settings → Shipping. Until a zone has a rate, the cart shows no delivery charge and no free-delivery bar.</li>'
		. '<li><strong>Where you deliver and how long it takes</strong> (<code>foodify_delivery_zones</code>), <strong>the returns window</strong> (<code>foodify_return_window_days</code>), <strong>box sizes</strong> (<code>foodify_box_sizes</code>) and <strong>the advertised coupon</strong> (<code>foodify_public_offer</code>).</li>'
		. '<li><strong>The logo and the home page photograph</strong> — Appearance → Editor.</li>'
		. '<li><strong>Payments</strong> — your payment gateway\'s own plugin and keys.</li></ul></div></div>';

	return $out . '</div>';
}

if ( ! function_exists( 'add_action' ) ) {
	return;
}

const FOODIFY_SETUP_SLUG    = 'foodify-setup';
const FOODIFY_SAMPLE_META   = '_foodify_sample';
const FOODIFY_DETAILS_OPTION = 'foodify_business_details';

function foodify_setup_url( array $args = [] ): string {
	return add_query_arg( array_merge( [ 'page' => FOODIFY_SETUP_SLUG ], $args ), admin_url( 'themes.php' ) );
}

/** Product ids, any status but trash; $samples_only narrows to this screen's own. */
function foodify_setup_product_ids( bool $samples_only = false ): array {
	if ( ! post_type_exists( 'product' ) ) {
		return [];
	}
	$args = [ 'post_type' => 'product', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ];
	if ( $samples_only ) {
		$args['meta_key']   = FOODIFY_SAMPLE_META; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_value'] = '1';                 // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	return array_map( 'intval', get_posts( $args ) );
}

/** Everything the steps are decided from. Reads only. */
function foodify_setup_facts(): array {
	$wc    = class_exists( 'WooCommerce' );
	$pages = foodify_setup_pages();
	$missing = []; $unwired = []; $empty = []; $drafts = [];
	foreach ( $pages as $slug => $p ) {
		$page = get_page_by_path( $slug );
		if ( ! $page || 'trash' === $page->post_status ) {
			$missing[] = '/' . $slug . '/';
			continue;
		}
		if ( '' !== $p['wc'] && (int) get_option( $p['wc'] ) !== (int) $page->ID ) {
			$unwired[] = $p['title'];
		}
		if ( 'publish' !== $page->post_status ) {
			$drafts[] = $p['title'];
		} elseif ( 'write it' === $p['note'] && '' === trim( (string) $page->post_content ) ) {
			$empty[] = $p['title'];
		}
	}
	$attrs = [];
	if ( function_exists( 'foodify_attribute_map' ) ) {
		foreach ( foodify_attribute_map() as $slug => $a ) {
			if ( ! taxonomy_exists( 'pa_' . $slug ) ) {
				$attrs[] = (string) $a['label'];
			}
		}
	}
	$cats = [];
	if ( taxonomy_exists( 'product_cat' ) ) {
		foreach ( foodify_setup_categories() as $slug => $name ) {
			if ( ! term_exists( $slug, 'product_cat' ) ) {
				$cats[] = $name;
			}
		}
	}
	$stored  = (array) get_option( FOODIFY_DETAILS_OPTION, [] );
	$profile = function_exists( 'foodify_business_profile' ) ? foodify_business_profile() : $stored;
	return [
		'wc_active'          => $wc,
		'permalinks_plain'   => '' === (string) get_option( 'permalink_structure' ),
		'pages_missing'      => $missing,
		'pages_unwired'      => $unwired,
		'pages_empty'        => $empty,
		'pages_draft'        => $drafts,
		'option_changes'     => $wc ? foodify_setup_option_changes( foodify_setup_options(), static fn( string $o ) => get_option( $o ) ) : [],
		'attributes_missing' => $wc ? $attrs : [],
		'categories_missing' => $wc ? $cats : [],
		'has_orders'         => $wc && function_exists( 'wc_get_orders' ) && [] !== wc_get_orders( [ 'limit' => 1, 'return' => 'ids' ] ),
		'details'            => [
			'tagline' => (string) get_option( 'blogdescription' ),
			'phone'   => (string) ( $profile['phone'] ?? '' ),
			'fssai'   => foodify_setup_valid_fssai( (string) ( $profile['fssai'] ?? '' ) ) ? (string) $profile['fssai'] : '',
		],
		'product_count'      => count( foodify_setup_product_ids() ),
		'sample_count'       => count( foodify_setup_product_ids( true ) ),
	];
}

/** One step's form: a nonce'd POST to admin-post.php with one button. */
function foodify_setup_form( string $do, string $button, string $fields = '', string $class = 'button button-primary' ): string {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fd-setup__form">'
		. '<input type="hidden" name="action" value="foodify_setup"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
		. wp_nonce_field( 'foodify_setup_' . $do, '_wpnonce', true, false )
		. $fields . '<p><button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $button ) . '</button></p></form>';
}

add_action( 'admin_menu', static function (): void {
	add_theme_page( 'Foodify setup', 'Foodify setup', 'manage_options', FOODIFY_SETUP_SLUG, static function (): void {
		$facts = foodify_setup_facts();
		$steps = foodify_setup_steps( $facts );
		$forms = [];

		if ( ! $facts['wc_active'] ) {
			// inc/requirements.php prints the Install / Activate button on this screen too.
			$forms['plugins'] = '<p>Use the <strong>Install WooCommerce</strong> button in the notice at the top of this page, then come back here.</p>';
		}

		if ( $facts['pages_missing'] || $facts['permalinks_plain'] || ( $facts['wc_active'] && $facts['pages_unwired'] ) ) {
			$n = count( $facts['pages_missing'] );
			$forms['pages'] = foodify_setup_form( 'pages', $n ? sprintf( 'Create %d missing %s', $n, 1 === $n ? 'page' : 'pages' ) . ( $facts['permalinks_plain'] ? ' and switch to readable links' : '' ) : ( $facts['permalinks_plain'] ? 'Switch to readable links' : 'Point WooCommerce at its pages' ) );
		}

		if ( 'todo' === $steps[2]['state'] ) {
			$forms['settings'] = foodify_setup_form( 'settings', 'Apply these shop settings' );
		}

		$d = $facts['details'];
		$forms['details'] = foodify_setup_form(
			'details',
			'Save business details',
			'<table class="form-table" role="presentation"><tbody>'
			. '<tr><th scope="row"><label for="fd-setup-tagline">Headline</label></th><td><input type="text" class="regular-text" id="fd-setup-tagline" name="tagline" maxlength="120" value="' . esc_attr( $d['tagline'] ) . '"><p class="description">The big line on the home page (this is the site\'s Tagline, Settings → General). The brand\'s own line is “6 minute mein ghar ka khana ready!”.</p></td></tr>'
			. '<tr><th scope="row"><label for="fd-setup-phone">Phone</label></th><td><input type="tel" class="regular-text" id="fd-setup-phone" name="phone" autocomplete="off" value="' . esc_attr( $d['phone'] ) . '"><p class="description">Exactly as it is written on your Google Business Profile.</p></td></tr>'
			. '<tr><th scope="row"><label for="fd-setup-fssai">FSSAI licence number</label></th><td><input type="text" class="regular-text" id="fd-setup-fssai" name="fssai" inputmode="numeric" autocomplete="off" value="' . esc_attr( $d['fssai'] ) . '"><p class="description">14 digits, from the licence. It is printed in the header, the footer, on product pages and on invoices.</p></td></tr>'
			. '</tbody></table>',
			'done' === $steps[3]['state'] ? 'button' : 'button button-primary'
		);

		if ( $facts['wc_active'] ) {
			$import = '<p><strong>The real catalogue</strong> is in the live shop. On the live site: Products → Export, tick “Export custom meta”. Here: <a href="' . esc_url( admin_url( 'edit.php?post_type=product&page=product_importer' ) ) . '">Products → Import</a>. Run “Apply these shop settings” first, so the filters exist for the import to fill.</p>';
			if ( $facts['sample_count'] > 0 ) {
				$forms['products'] = $import . foodify_setup_form( 'remove_samples', sprintf( 'Remove the %d sample products', $facts['sample_count'] ), '', 'button button-primary' );
			} elseif ( 0 === $facts['product_count'] ) {
				$count = count( foodify_sample_products( (string) file_get_contents( FOODIFY_DIR . '/assets/data/sample-products.json' ) ) );
				$forms['products'] = $import . '<p><strong>Only to see the design:</strong> ' . sprintf( 'add %d sample products. Real product names; made-up prices, pack sizes and ingredients; no photographs, ratings or reviews. One button removes them again.', $count ) . '</p>'
					. ( 'todo' === $steps[2]['state'] ? '<p><em>Apply the shop settings first — the samples use the filters and ranges that step creates.</em></p>' : foodify_setup_form( 'samples', sprintf( 'Add %d sample products', $count ), '', 'button' ) );
			} else {
				$forms['products'] = $import;
			}
		}

		$done    = isset( $_GET['fd_done'] ) ? sanitize_key( (string) wp_unslash( $_GET['fd_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$n       = isset( $_GET['fd_n'] ) ? (int) $_GET['fd_n'] : 0;                                         // phpcs:ignore WordPress.Security.NonceVerification
		$notices = [
			'pages'          => sprintf( 'Pages and links are set up (%d created).', $n ),
			'settings'       => sprintf( 'Shop settings applied (%d changed).', $n ),
			'details'        => 'Business details saved.',
			'samples'        => sprintf( '%d sample products added. They are samples — remove them before the shop takes an order.', $n ),
			'remove_samples' => sprintf( '%d sample products removed.', $n ),
		];
		$errors = get_transient( 'foodify_setup_errors_' . get_current_user_id() );
		delete_transient( 'foodify_setup_errors_' . get_current_user_id() );

		echo '<style>.fd-setup .postbox{max-width:60rem}.fd-setup .postbox-header{align-items:center}.fd-setup .hndle{cursor:default;padding:8px 12px}.fd-setup__state{margin:0 1em;white-space:nowrap}.fd-setup__changes{margin:1em 0}.fd-setup__changes td{word-break:break-word}</style>';
		echo foodify_setup_screen_html( $steps, [ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside; forms are built with esc_* above.
			'forms'   => $forms,
			'changes' => $facts['option_changes'],
			'notice'  => $notices[ $done ] ?? '',
			'errors'  => is_array( $errors ) ? $errors : [],
		] );
	} );
} );

/** Step 2: readable links, the missing pages, WooCommerce pointed at its four. Creates; never rewrites. */
function foodify_setup_do_pages(): int {
	$created = 0;
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}
	foreach ( foodify_setup_pages() as $slug => $p ) {
		$page = get_page_by_path( $slug );
		$id   = $page && 'trash' !== $page->post_status ? (int) $page->ID : 0;
		if ( ! $id ) {
			$id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $p['title'], 'post_name' => $slug, 'post_content' => $p['content'] ] );
			$created += $id ? 1 : 0;
		}
		if ( $id && '' !== $p['wc'] && class_exists( 'WooCommerce' ) ) {
			update_option( $p['wc'], $id );
		}
	}
	flush_rewrite_rules();
	return $created;
}

/** Step 3: the settings list, the filter attributes with their terms, the four ranges. */
function foodify_setup_do_settings(): int {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return 0;
	}
	$changed = 0;
	foreach ( foodify_setup_options() as $option => $value ) {
		if ( ! foodify_setup_same( get_option( $option ), $value ) ) {
			update_option( $option, $value );
			$changed++;
		}
	}
	if ( function_exists( 'foodify_attribute_map' ) && function_exists( 'wc_create_attribute' ) ) {
		foreach ( foodify_attribute_map() as $slug => $a ) {
			$tax = wc_attribute_taxonomy_name( $slug );
			if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
				// The same call scripts/tags-to-attributes.php makes.
				$made = wc_create_attribute( [ 'name' => (string) $a['label'], 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ] );
				if ( is_wp_error( $made ) ) {
					continue;
				}
				$changed++;
			}
			// WooCommerce registers pa_* on init from its own table, so a taxonomy
			// created in this request is not registered yet. Register it for the
			// rest of this request so its terms can be written now.
			if ( ! taxonomy_exists( $tax ) ) {
				register_taxonomy( $tax, [ 'product' ], [ 'hierarchical' => false, 'public' => false ] );
			}
			foreach ( (array) $a['terms'] as $term_slug => $t ) {
				if ( ! term_exists( (string) $term_slug, $tax ) ) {
					wp_insert_term( (string) $t['label'], $tax, [ 'slug' => (string) $term_slug ] );
				}
			}
		}
		delete_transient( 'wc_attribute_taxonomies' );
	}
	foreach ( foodify_setup_categories() as $slug => $name ) {
		if ( taxonomy_exists( 'product_cat' ) && ! term_exists( $slug, 'product_cat' ) ) {
			$made     = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
			$changed += is_wp_error( $made ) ? 0 : 1;
		}
	}
	return $changed;
}

/** Step 5: the samples — on an EMPTY store only, each marked as this screen's own. */
function foodify_setup_do_samples(): int {
	if ( ! class_exists( 'WC_Product_Simple' ) || [] !== foodify_setup_product_ids() ) {
		return 0;
	}
	$dietary = function_exists( 'foodify_attribute_map' ) ? (array) ( foodify_attribute_map()['dietary']['terms'] ?? [] ) : [];
	$added   = 0;
	foreach ( foodify_sample_products( (string) file_get_contents( FOODIFY_DIR . '/assets/data/sample-products.json' ) ) as $r ) {
		$product = new WC_Product_Simple();
		$product->set_name( (string) $r['name'] );
		$product->set_slug( (string) $r['slug'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_regular_price( (string) $r['regular_price'] );
		if ( (float) ( $r['sale_price'] ?? 0 ) > 0 ) {
			$product->set_sale_price( (string) $r['sale_price'] );
		}
		$product->set_short_description( (string) ( $r['excerpt'] ?? '' ) );
		$product->set_stock_status( empty( $r['in_stock'] ) ? 'outofstock' : 'instock' );
		$cat = get_term_by( 'slug', (string) $r['category'], 'product_cat' );
		if ( $cat ) {
			$product->set_category_ids( [ (int) $cat->term_id ] );
		}

		// Prep method and dietary labels are GLOBAL attributes: the shop's filters read them.
		$attributes = [];
		foreach ( [ 'prep' => [ (string) ( $r['prep'] ?? '' ) ], 'dietary' => foodify_sample_dietary_slugs( (array) ( $r['dietary'] ?? [] ), $dietary ) ] as $slug => $term_slugs ) {
			$tax = wc_attribute_taxonomy_name( $slug );
			$ids = [];
			foreach ( array_filter( $term_slugs, 'strlen' ) as $term_slug ) {
				$term = taxonomy_exists( $tax ) ? get_term_by( 'slug', $term_slug, $tax ) : false;
				if ( $term ) {
					$ids[] = (int) $term->term_id;
				}
			}
			if ( $ids ) {
				$attribute = new WC_Product_Attribute();
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
				$attribute->set_name( $tax );
				$attribute->set_options( $ids );
				$attribute->set_visible( true );
				$attributes[] = $attribute;
			}
		}
		$product->set_attributes( $attributes );

		foreach ( [
			'_foodify_prep_minutes'  => (string) (int) ( $r['prep_minutes'] ?? 0 ),
			'_foodify_servings'      => (string) (int) ( $r['servings'] ?? 0 ),
			'_foodify_net_quantity'  => (string) ( $r['net_quantity'] ?? '' ),
			'_foodify_cooked_weight' => (string) ( $r['cooked_weight'] ?? '' ),
			'_foodify_ingredients'   => (string) ( $r['ingredients'] ?? '' ),
			'_foodify_diet'          => 'Vegetarian',
			FOODIFY_SAMPLE_META      => '1',
		] as $key => $value ) {
			if ( '' !== $value && '0' !== $value ) {
				$product->update_meta_data( $key, $value );
			}
		}
		$added += $product->save() ? 1 : 0;
	}
	return $added;
}

/** Removes exactly the products this screen added — found by its own mark, nothing else. */
function foodify_setup_do_remove_samples(): int {
	$removed = 0;
	foreach ( foodify_setup_product_ids( true ) as $id ) {
		if ( '1' === (string) get_post_meta( $id, FOODIFY_SAMPLE_META, true ) && wp_delete_post( $id, true ) ) {
			$removed++;
		}
	}
	return $removed;
}

add_action( 'admin_post_foodify_setup', static function (): void {
	$do = isset( $_POST['do'] ) ? sanitize_key( (string) wp_unslash( $_POST['do'] ) ) : '';
	if ( ! current_user_can( 'manage_options' ) || ! in_array( $do, [ 'pages', 'settings', 'details', 'samples', 'remove_samples' ], true ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'foodify' ), 403 );
	}
	check_admin_referer( 'foodify_setup_' . $do );

	$n = 0;
	switch ( $do ) {
		case 'pages':
			$n = foodify_setup_do_pages();
			break;
		case 'settings':
			$n = foodify_setup_do_settings();
			break;
		case 'details':
			$clean = foodify_setup_clean_details( wp_unslash( $_POST ) );
			update_option( 'blogdescription', $clean['values']['tagline'] );
			$stored = (array) get_option( FOODIFY_DETAILS_OPTION, [] );
			foreach ( [ 'phone', 'fssai' ] as $k ) {
				// A refused or blank field leaves what was stored: a typo cannot erase a licence.
				if ( '' !== $clean['values'][ $k ] ) {
					$stored[ $k ] = $clean['values'][ $k ];
				}
			}
			update_option( FOODIFY_DETAILS_OPTION, $stored, false );
			if ( $clean['errors'] ) {
				set_transient( 'foodify_setup_errors_' . get_current_user_id(), $clean['errors'], 60 );
			}
			break;
		case 'samples':
			$n = foodify_setup_do_samples();
			break;
		case 'remove_samples':
			$n = foodify_setup_do_remove_samples();
			break;
	}
	wp_safe_redirect( foodify_setup_url( [ 'fd_done' => $do, 'fd_n' => $n ] ) . '#fd-setup-' . ( 'remove_samples' === $do || 'samples' === $do ? 'products' : $do ) );
	exit;
} );

/* Once, after the theme is switched on in wp-admin: land on this screen. */
add_action( 'after_switch_theme', static function (): void {
	update_option( 'foodify_setup_redirect', '1', false );
} );
add_action( 'admin_init', static function (): void {
	if ( '1' !== get_option( 'foodify_setup_redirect' ) || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	delete_option( 'foodify_setup_redirect' );
	if ( isset( $_GET['page'] ) && FOODIFY_SETUP_SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	wp_safe_redirect( foodify_setup_url() );
	exit;
} );

/* While a sample product exists, every admin screen says so. */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_options' ) || ! post_type_exists( 'product' ) ) {
		return;
	}
	$n = count( foodify_setup_product_ids( true ) );
	if ( $n > 0 ) {
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html( sprintf( '%d sample products are in this store.', $n ) ),
			esc_html__( 'Their prices, pack sizes and ingredients are made up. Remove them before the shop takes an order.', 'foodify' ),
			esc_url( foodify_setup_url() . '#fd-setup-products' ),
			esc_html__( 'Foodify setup', 'foodify' )
		);
	}
} );
