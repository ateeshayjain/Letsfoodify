#!/usr/bin/env bash
# Build a throwaway LOCAL WordPress on SQLite for the real-renderer gates.
#
#   scripts/wp-site-build.sh /home/user/wpsite            # WordPress only  (wp-boot-test, wp-render-test)
#   scripts/wp-site-build.sh /home/user/wpsite-woo --woo  # + WooCommerce   (wp-woo-test)
#
# Everything comes from downloads.wordpress.org (free, wordpress.org-hosted).
# Nothing here touches, or knows the address of, any live or staging site.
# An existing site is left alone: delete the directory to rebuild.
#
# FOODIFY_WP_VERSION pins WordPress core (default: what api.wordpress.org offers).
# FOODIFY_WP_CACHE   is where the zips are kept (default: ~/.cache/foodify-wp).
set -euo pipefail
WP="${1:?usage: wp-site-build.sh <dir> [--woo]}"; WOO="${2:-}"
CACHE="${FOODIFY_WP_CACHE:-$HOME/.cache/foodify-wp}"
R="\033[31m"; N="\033[0m"
die() { printf "${R}  FAIL${N} %s — the site was NOT built\n" "$1" >&2; exit 2; }

if [[ -f "$WP/wp-load.php" ]]; then echo "  exists: $WP (delete it to rebuild)"; exit 0; fi
mkdir -p "$CACHE"

fetch() { # name url — a failed or empty download is an error, never an empty zip
  local out="$CACHE/$1"
  [[ -s "$out" ]] && return 0
  local code; code="$(curl -sSL -o "$out.part" -w '%{http_code}' "$2" || true)"
  [[ "$code" == 200 && -s "$out.part" ]] || { rm -f "$out.part"; die "could not download $2 (HTTP $code)"; }
  mv "$out.part" "$out"
}

VER="${FOODIFY_WP_VERSION:-}"
if [[ -z "$VER" ]]; then
  VER="$(curl -sS https://api.wordpress.org/core/version-check/1.7/ | python3 -c 'import json,sys; print(json.load(sys.stdin)["offers"][0]["version"])' 2>/dev/null || true)"
  [[ -n "$VER" ]] || die "api.wordpress.org did not say which WordPress is current (set FOODIFY_WP_VERSION)"
fi
fetch "wordpress-$VER.zip" "https://downloads.wordpress.org/release/wordpress-$VER.zip"
fetch "sqlite-database-integration.zip" "https://downloads.wordpress.org/plugin/sqlite-database-integration.zip"
[[ "$WOO" == --woo ]] && fetch "woocommerce.zip" "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
unzip -q "$CACHE/wordpress-$VER.zip" -d "$TMP"
mkdir -p "$(dirname "$WP")"; mv "$TMP/wordpress" "$WP"
unzip -q "$CACHE/sqlite-database-integration.zip" -d "$WP/wp-content/plugins"
[[ "$WOO" == --woo ]] && unzip -q "$CACHE/woocommerce.zip" -d "$WP/wp-content/plugins"

# The SQLite drop-in: WordPress's own plugin, wired in the way its activation does.
P="$WP/wp-content/plugins/sqlite-database-integration"
sed -e "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$P/db.copy" > "$WP/wp-content/db.php"

cat > "$WP/wp-config.php" <<'PHP'
<?php
// A LOCAL TEST SITE. SQLite ignores the DB_* constants; they only have to exist.
define( 'DB_NAME', 'local' ); define( 'DB_USER', 'local' ); define( 'DB_PASSWORD', 'local' ); define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as $k ) { define( $k, 'local-test-site-' . $k ); }
$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );
define( 'AUTOMATIC_UPDATER_DISABLED', true ); define( 'WP_AUTO_UPDATE_CORE', false );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

php -d display_errors=0 -r 'define( "WP_INSTALLING", true ); require "'"$WP"'/wp-load.php";
  require ABSPATH . "wp-admin/includes/upgrade.php";
  wp_install( "Foodify Staging", "admin", "admin@example.org", true, "", "pass" );' >/dev/null \
  || die "wp_install failed"

if [[ "$WOO" == --woo ]]; then
  # Activated as wp-admin's Plugins screen does it, so WooCommerce's installer
  # runs (its tables, roles and pages), then its queued install work is drained.
  php -d display_errors=0 -r 'define( "WP_ADMIN", true ); require "'"$WP"'/wp-load.php";
    require_once ABSPATH . "wp-admin/includes/plugin.php";
    $r = activate_plugin( "woocommerce/woocommerce.php" );
    if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() ); exit( 1 ); }' \
    || die "WooCommerce would not activate"
  php -d display_errors=0 -r 'require "'"$WP"'/wp-load.php";
    if ( ! function_exists( "WC" ) ) { exit( 1 ); }
    WC_Install::install();
    exit( wc_get_page_id( "cart" ) > 0 ? 0 : 1 );' \
    || die "WooCommerce activated but did not install its tables and pages"
fi
echo "  built: $WP (WordPress $VER${WOO:+ + WooCommerce})"
