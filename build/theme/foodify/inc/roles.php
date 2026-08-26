<?php
/**
 * WP-10 — the Shop Staff role.
 *
 * Scope §W6: "A Shop Staff role that can process orders without holding full
 * admin."
 *
 * THE TRAP THIS FILE IS BUILT AROUND
 * ----------------------------------
 * `add_role()` IS A NO-OP WHEN THE ROLE ALREADY EXISTS. Capabilities live in the
 * `wp_user_roles` option, not in code — so tightening a capability here, testing
 * it locally on a fresh install, and deploying changes NOTHING on a site where
 * the role was already created. The looser set stays in the database forever.
 *
 * That is a security defect with a very quiet failure: the code says the staff
 * account cannot install plugins, the database says it can, and every review of
 * the code agrees with the code. It is the same shape as an absence check that
 * cannot run — the thing that proves the claim is not the thing being read.
 *
 * So the role carries a VERSION. Change the capability set, bump the version,
 * and the next request reconciles the database to it. And the forbidden list is
 * asserted POSITIVELY: `foodify_granted_forbidden_caps()` returns which
 * dangerous capabilities a role actually holds, so the test can prove the
 * detector works by poisoning a role and watching it fire — rather than proving
 * only that today's array looks right.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/** Bump this whenever the capability sets below change, or nothing happens. */
const FOODIFY_ROLES_VERSION = '1';

const FOODIFY_STAFF_ROLE   = 'foodify_shop_staff';
const FOODIFY_CAP_STOCK    = 'foodify_manage_stock';

/* -------------------------------------------------------------------------
 * Pure — tested in tests/roles-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * What Shop Staff may do.
 *
 * The list is deliberately short and every line earns itself.
 *
 * NOT `edit_products`. Stock is the thing staff need to change, and
 * `edit_products` also grants price editing — a capability that only ever needs
 * to be held by the person who sets prices. So stock adjustment runs behind a
 * capability of its own, `foodify_manage_stock`, and the dashboard's low-stock
 * panel is the door. Least privilege costs one custom capability here; the
 * alternative costs the ability to change what things sell for.
 *
 * NOT `delete_shop_orders`. Deleting an order destroys the accounting record
 * that the WP-09 ledger, the GST invoice and any refund depend on. Cancelling is
 * a status change and staff can do that.
 *
 * NOT `view_woocommerce_reports`. Revenue is not needed to pack a box.
 */
function foodify_shop_staff_caps(): array {
	return [
		'read'                       => true,
		// Orders: see them, work them, note them. Others' too — a shop floor is
		// not per-user.
		'read_shop_order'            => true,
		'read_shop_orders'           => true,
		'read_private_shop_orders'   => true,
		'edit_shop_order'            => true,
		'edit_shop_orders'           => true,
		'edit_others_shop_orders'    => true,
		'edit_published_shop_orders' => true,
		// Products: LOOK, do not touch. Needed so the order screen can resolve
		// product names and the low-stock panel can list them.
		'read_product'               => true,
		'read_private_products'      => true,
		// The one write they get, scoped to exactly one field.
		FOODIFY_CAP_STOCK            => true,
	];
}

/**
 * Capabilities that must never be granted to Shop Staff.
 *
 * Enumerated rather than assumed, because "the role does not have admin" is an
 * absence claim, and absence claims are the ones that pass without being checked.
 */
function foodify_forbidden_staff_caps(): array {
	return [
		// Store-wide control
		'manage_woocommerce', 'manage_options', 'view_woocommerce_reports',
		// Code execution
		'install_plugins', 'activate_plugins', 'edit_plugins', 'update_plugins',
		'install_themes', 'switch_themes', 'edit_themes', 'edit_files', 'update_core',
		// Other people's accounts — including promoting themselves
		'edit_users', 'create_users', 'delete_users', 'promote_users', 'list_users', 'remove_users',
		// Bulk data out, and bulk data in
		'export', 'import',
		// Destroying records the ledger and the GST invoice depend on
		'delete_shop_orders', 'delete_others_shop_orders', 'delete_published_shop_orders',
		// Pricing and the catalogue itself
		'edit_products', 'edit_others_products', 'publish_products', 'delete_products',
		'manage_product_terms', 'edit_product_terms', 'delete_product_terms',
		// Markup injection
		'unfiltered_html',
	];
}

/**
 * Which forbidden capabilities a role actually holds. Empty is the pass.
 *
 * @param array<string,bool> $caps
 * @return array<int,string>
 */
function foodify_granted_forbidden_caps( array $caps ): array {
	$bad = [];
	foreach ( foodify_forbidden_staff_caps() as $cap ) {
		if ( ! empty( $caps[ $cap ] ) ) {
			$bad[] = $cap;
		}
	}
	return $bad;
}

/**
 * What has to change to bring a stored role to the declared set.
 *
 * @param array<string,bool> $stored
 * @param array<string,bool> $wanted
 * @return array{add:array<int,string>,remove:array<int,string>}
 */
function foodify_role_caps_diff( array $stored, array $wanted ): array {
	$add = $remove = [];
	foreach ( $wanted as $cap => $on ) {
		if ( $on && empty( $stored[ $cap ] ) ) {
			$add[] = (string) $cap;
		}
	}
	// REMOVAL IS THE HALF THAT MATTERS. Adding a capability is what everyone
	// remembers; taking one away is what a tightened role actually needs, and
	// what add_role() will never do on a site where the role already exists.
	foreach ( $stored as $cap => $on ) {
		if ( $on && empty( $wanted[ $cap ] ) ) {
			$remove[] = (string) $cap;
		}
	}
	sort( $add );
	sort( $remove );
	return [ 'add' => $add, 'remove' => $remove ];
}

/** Does the stored version differ from what the code declares? */
function foodify_roles_need_sync( ?string $stored, string $current ): bool {
	return $stored !== $current;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/**
 * Reconcile the stored role to the declared one.
 *
 * Not `remove_role()` then `add_role()`: that would drop the role from every
 * user holding it for the length of one request, and on a site where two
 * requests overlap, from one of them for real. Capabilities are added and
 * removed in place instead.
 */
function foodify_sync_roles(): void {
	$wanted = foodify_shop_staff_caps();
	$role   = get_role( FOODIFY_STAFF_ROLE );

	if ( ! $role ) {
		add_role( FOODIFY_STAFF_ROLE, __( 'Shop Staff', 'foodify' ), $wanted );
		$role = get_role( FOODIFY_STAFF_ROLE );
	}
	if ( ! $role ) {
		return;   // the option write failed; try again next request rather than half-applying
	}

	$diff = foodify_role_caps_diff( (array) $role->capabilities, $wanted );
	foreach ( $diff['add'] as $cap ) {
		$role->add_cap( $cap );
	}
	foreach ( $diff['remove'] as $cap ) {
		$role->remove_cap( $cap );
	}

	// The stock capability is useful to a shop manager too, and to an admin, or
	// the low-stock panel is invisible to the people most likely to use it.
	foreach ( [ 'administrator', 'shop_manager' ] as $slug ) {
		$other = get_role( $slug );
		if ( $other && ! $other->has_cap( FOODIFY_CAP_STOCK ) ) {
			$other->add_cap( FOODIFY_CAP_STOCK );
		}
	}

	update_option( 'foodify_roles_version', FOODIFY_ROLES_VERSION, false );
}

add_action( 'after_switch_theme', 'foodify_sync_roles' );

add_action( 'init', static function (): void {
	$stored = get_option( 'foodify_roles_version', null );
	if ( foodify_roles_need_sync( is_string( $stored ) ? $stored : null, FOODIFY_ROLES_VERSION ) ) {
		foodify_sync_roles();
	}
}, 5 );

/**
 * Say so, loudly, if the database disagrees with the code.
 *
 * The sync above should make this impossible. It is here because "should be
 * impossible" is what everyone believed about the capability set that had
 * already drifted — and a role holding `install_plugins` is not something to
 * find out about from an incident.
 */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$role = get_role( FOODIFY_STAFF_ROLE );
	if ( ! $role ) {
		return;
	}
	$bad = foodify_granted_forbidden_caps( (array) $role->capabilities );
	if ( ! $bad ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <code>%3$s</code></p></div>',
		esc_html__( 'Foodify security:', 'foodify' ),
		esc_html__( 'the Shop Staff role holds capabilities it must never have. Bump FOODIFY_ROLES_VERSION in inc/roles.php to force a resync. Granted:', 'foodify' ),
		esc_html( implode( ', ', $bad ) )
	);
} );

/**
 * Shop Staff have no business in the WordPress dashboard proper.
 *
 * Sent to the orders screen, which is the job. Not a security control — the
 * capabilities above are — but a screen full of things they cannot use invites
 * clicking on them.
 */
add_action( 'admin_init', static function (): void {
	if ( wp_doing_ajax() || ! is_user_logged_in() ) {
		return;
	}
	$user = wp_get_current_user();
	if ( ! in_array( FOODIFY_STAFF_ROLE, (array) $user->roles, true ) ) {
		return;
	}
	global $pagenow;
	if ( 'index.php' === $pagenow ) {
		wp_safe_redirect( admin_url( 'admin.php?page=foodify-today' ) );
		exit;
	}
} );

/** No admin bar clutter for staff either. */
add_filter( 'show_admin_bar', static function ( $show ) {
	$user = wp_get_current_user();
	return in_array( FOODIFY_STAFF_ROLE, (array) $user->roles, true ) ? false : $show;
} );
