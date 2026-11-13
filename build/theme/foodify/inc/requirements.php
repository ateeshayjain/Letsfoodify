<?php
/**
 * The plugins this theme needs, said in wp-admin (client, 12 Nov 2026: "if the
 * theme has dependencies, WordPress tells you which plugins to install").
 *
 * WordPress's own "Requires Plugins:" header (6.5+) is read from PLUGIN headers
 * only — a theme's style.css cannot use it. So the theme says it itself: one
 * notice on every admin screen while WooCommerce is missing (the shop, cart,
 * checkout and account do not exist without it), and a quieter one, on the
 * Themes, Plugins and Foodify setup screens only, for the free plugins scripts/bootstrap.sh
 * installs. Each line is a real button — Install or Activate — for a user who
 * may install plugins; anyone else is told to ask an administrator.
 *
 * Nothing here installs anything by itself, and nothing is paid: Rank Math Pro
 * and Digits stay manual (rule 5 — paid plugins are asked about, not assumed).
 *
 * Pure functions first; WordPress glue below the function_exists guard.
 */
declare( strict_types = 1 );

/**
 * What the theme asks for, by wordpress.org slug — which is also the plugin's
 * folder. A plugin is matched by its folder, never a guessed main-file name, so
 * a renamed main file cannot make an installed plugin read as missing.
 *
 * @return list<array{slug:string,name:string,why:string,required:bool}>
 */
function foodify_plugin_requirements(): array {
	return [
		[ 'slug' => 'woocommerce', 'name' => 'WooCommerce', 'why' => 'the shop, cart, checkout and accounts', 'required' => true ],
		[ 'slug' => 'seo-by-rank-math', 'name' => 'Rank Math SEO', 'why' => 'search listings and the sitemap', 'required' => false ],
		[ 'slug' => 'judgeme-product-reviews-woocommerce', 'name' => 'Judge.me Product Reviews', 'why' => 'product reviews', 'required' => false ],
		[ 'slug' => 'wp-mail-smtp', 'name' => 'WP Mail SMTP', 'why' => 'order emails that reach the inbox', 'required' => false ],
		[ 'slug' => 'woocommerce-pdf-invoices-packing-slips', 'name' => 'PDF Invoices & Packing Slips', 'why' => 'the GST invoice customers download', 'required' => false ],
	];
}

/**
 * Which requirements are not active, and what each needs: 'install', or
 * 'activate' with the installed main file (`file`) the Activate link names.
 *
 * @param list<array{slug:string,name:string,why:string,required:bool}> $reqs
 * @param list<string> $installed Plugin files present ("folder/main.php", keys of get_plugins()).
 * @param list<string> $active    Plugin files active.
 * @return list<array{slug:string,name:string,why:string,required:bool,need:string,file:string}>
 */
function foodify_missing_plugins( array $reqs, array $installed, array $active ): array {
	$in  = static fn( string $slug, array $files ): string => (string) ( array_values( array_filter( $files, static fn( string $f ): bool => str_starts_with( $f, $slug . '/' ) ) )[0] ?? '' );
	$out = [];
	foreach ( $reqs as $r ) {
		if ( '' !== $in( $r['slug'], $active ) ) {
			continue;
		}
		$r['file'] = $in( $r['slug'], $installed );
		$r['need'] = '' !== $r['file'] ? 'activate' : 'install';
		$out[]     = $r;
	}
	return $out;
}

/**
 * One notice. $links maps a slug to its Install/Activate URL; an empty map means
 * the viewer cannot install plugins, so they are told who can.
 *
 * @param list<array{slug:string,name:string,why:string,need:string}> $missing
 * @param array<string,string> $links
 */
function foodify_requirements_notice_html( array $missing, array $links, bool $required ): string {
	if ( ! $missing ) {
		return '';
	}
	$e     = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	$items = '';
	foreach ( $missing as $m ) {
		$verb   = 'activate' === $m['need'] ? 'Activate' : 'Install';
		$action = isset( $links[ $m['slug'] ] )
			? ' <a class="button button-small" href="' . $e( $links[ $m['slug'] ] ) . '">' . $verb . ' ' . $e( $m['name'] ) . '</a>'
			: '';
		$items .= '<li><strong>' . $e( $m['name'] ) . '</strong> — ' . $e( $m['why'] ) . $action . '</li>';
	}
	$lead = $required
		? 'The Foodify theme needs this plugin. Until it is active the shop, cart and checkout pages are empty:'
		: 'The Foodify theme is built to work with these free plugins:';
	$foot = $links ? '' : '<p>' . ( $required ? 'Ask a site administrator to install it.' : 'A site administrator can install them.' ) . '</p>';
	return '<div class="notice ' . ( $required ? 'notice-error' : 'notice-info' ) . ' fd-requirements"><p>' . $lead . '</p><ul>' . $items . '</ul>' . $foot . '</div>';
}

if ( ! function_exists( 'add_action' ) ) {
	return;
}

add_action( 'admin_notices', static function (): void {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$installed = array_keys( get_plugins() );
	$active    = (array) get_option( 'active_plugins', [] );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
	}
	$missing = foodify_missing_plugins( foodify_plugin_requirements(), $installed, array_values( $active ) );
	if ( ! $missing ) {
		return;
	}

	$links = [];
	foreach ( $missing as $m ) {
		if ( 'install' === $m['need'] && current_user_can( 'install_plugins' ) ) {
			// RAW urls: foodify_requirements_notice_html() escapes them. wp_nonce_url()
			// returns "&amp;" already, so the buttons went out as "&amp;amp;_wpnonce"
			// and WordPress answered "The link you followed has expired" — the
			// Install WooCommerce button did not install (found 13 Nov 2026, by
			// reading the href a real wp-admin printed).
			$links[ $m['slug'] ] = add_query_arg( '_wpnonce', wp_create_nonce( 'install-plugin_' . $m['slug'] ), self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $m['slug'] ) ) );
		} elseif ( 'activate' === $m['need'] && current_user_can( 'activate_plugins' ) ) {
			$links[ $m['slug'] ] = add_query_arg( '_wpnonce', wp_create_nonce( 'activate-plugin_' . $m['file'] ), self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $m['file'] ) ) );
		}
	}

	$required = array_values( array_filter( $missing, static fn( array $m ): bool => $m['required'] ) );
	$optional = array_values( array_filter( $missing, static fn( array $m ): bool => ! $m['required'] ) );

	// WooCommerce: every admin screen, for anyone who can change the site.
	if ( $required && current_user_can( 'switch_themes' ) ) {
		echo foodify_requirements_notice_html( $required, $links, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}
	// The rest: only where someone is choosing themes or plugins, so it never nags.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $optional && $screen && in_array( $screen->id, [ 'themes', 'plugins', 'appearance_page_foodify-setup' ], true ) && current_user_can( 'install_plugins' ) ) {
		echo foodify_requirements_notice_html( $optional, $links, false ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}
} );
