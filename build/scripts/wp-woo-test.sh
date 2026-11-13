#!/usr/bin/env bash
# The theme in a REAL WordPress WITH WooCommerce: set up by the setup screen's
# buttons, crawled at two widths, and bought from — tools/wp-woo-check.js says
# what is asserted and why. LOCAL ONLY: it builds and serves a throwaway site
# on this machine and knows no other address.
#
#   scripts/wp-woo-test.sh
#   FOODIFY_WOO_DIR=/path/to/site scripts/wp-woo-test.sh     # default /home/user/wpsite-woo
#   FOODIFY_WOO_SHOTS=/tmp/shots scripts/wp-woo-test.sh      # also save a screenshot of each page
#   FOODIFY_THEME_SRC=/path/to/unzipped/foodify …            # test that copy of the theme
#
# NOT in run-all-tests.sh: building the site needs downloads.wordpress.org and
# api.wordpress.org, which the gate's other sandbox cannot reach, and that gate
# has to stay runnable there. Run this one wherever WooCommerce can be had,
# before a hand-over and after any change to a template, style.css or inc/.
#
# A CHECK THAT CANNOT RUN IS NOT A PASS. No WooCommerce, no browser, no server:
# each says "did NOT run" and exits 2.
set -uo pipefail
KIT="$(cd "$(dirname "$0")/.." && pwd)"
WP="${FOODIFY_WOO_DIR:-/home/user/wpsite-woo}"
PORT="${FOODIFY_WOO_PORT:-8793}"
G="\033[32m"; R="\033[31m"; N="\033[0m"
norun() { printf "${R}  SKIP-FAIL${N} %s — the WooCommerce check did NOT run\n" "$1"; [[ -n "${2:-}" ]] && printf "            %s\n" "$2"; exit 2; }

[[ -x /opt/pw-browsers/chromium && -d /tmp/node_modules/playwright-core ]] || norun "browser or playwright-core missing" "see docs/WP-BOOT.md"
if [[ ! -f "$WP/wp-load.php" ]]; then
  "$KIT/scripts/wp-site-build.sh" "$WP" --woo || norun "no WordPress at $WP and one could not be built" "WooCommerce comes from downloads.wordpress.org — is it reachable from here?"
fi
[[ -f "$WP/wp-content/plugins/woocommerce/woocommerce.php" ]] || norun "WooCommerce is not installed in $WP" "delete $WP and re-run to build a site with it"

# Every run starts from a WooCommerce nobody has set up: the database is put
# back to the copy wp-site-build.sh took. A run must not depend on the last one.
DB="$(php -r 'echo (string) @include $argv[1];' "$WP/wp-content/database/db-path.php" 2>/dev/null || true)"
[[ -n "$DB" && -f "$DB.pristine" ]] || norun "no pristine database copy beside the site's database" "delete $WP and re-run: wp-site-build.sh takes the copy when it builds"
cp "$DB.pristine" "$DB" && rm -f "$DB-wal" "$DB-shm" "$DB-journal"
rm -rf "$WP/wp-content/uploads/wc-logs" 2>/dev/null; : > "$WP/wp-content/debug.log"

SRC="${FOODIFY_THEME_SRC:-$KIT/theme/foodify}"
rm -rf "$WP/wp-content/themes/foodify" && cp -r "$SRC" "$WP/wp-content/themes/foodify" || norun "could not stage the theme"
# Activate in one load, use in the next — as clicking Activate does (wp-boot-test.sh says why).
php -d display_errors=0 -r 'require $argv[1] . "/wp-load.php"; switch_theme( "foodify" );' "$WP" >/dev/null 2>&1 || norun "could not activate the theme"
php -d display_errors=0 -r 'require $argv[1] . "/wp-load.php"; exit( class_exists( "WooCommerce" ) && function_exists( "foodify_setup_options" ) ? 0 : 1 );' "$WP" >/dev/null 2>&1 \
  || norun "WooCommerce or the theme did not load in $WP"

LOGIN="fd-woo-door-$$.php"
TMP="$(mktemp -d)"; trap 'kill "$SRV" 2>/dev/null; rm -rf "$TMP" "$WP/$LOGIN"' EXIT
# A one-use door for the browser, removed on exit: signs in as the user asked
# for (the administrator, or the test customer) and goes where it is sent.
cat > "$WP/$LOGIN" <<'PHP'
<?php
require __DIR__ . '/wp-load.php';
wp_set_auth_cookie( isset( $_GET['u'] ) ? (int) $_GET['u'] : 1, false, false );
wp_safe_redirect( isset( $_GET['to'] ) ? home_url( (string) wp_unslash( $_GET['to'] ) ) : admin_url( 'themes.php?page=foodify-setup' ) );
exit;
PHP
# The address the browser uses; no waiting on wordpress.org; no cron in page loads.
cat > "$TMP/prepend.php" <<PHP
<?php
define( 'WP_HOME', 'http://localhost:$PORT' );
define( 'WP_SITEURL', 'http://localhost:$PORT' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );
PHP
( cd "$WP" && PHP_CLI_SERVER_WORKERS=6 exec php -d auto_prepend_file="$TMP/prepend.php" -d display_errors=0 -S "localhost:$PORT" >"$TMP/server.log" 2>&1 ) &
SRV=$!
for _ in $(seq 1 60); do curl -s -o /dev/null "http://localhost:$PORT/" && break; sleep 0.25; done
curl -s -o /dev/null "http://localhost:$PORT/" || { tail -5 "$TMP/server.log"; norun "WordPress did not start on port $PORT"; }

node "$KIT/tools/wp-woo-check.js" "http://localhost:$PORT" "/$LOGIN" "$WP"
