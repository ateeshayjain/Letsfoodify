#!/usr/bin/env bash
# Serve the real WordPress with the theme active, run the setup screen's page
# step as a person would, and LOOK at the pages in a browser
# (tools/wp-render-check.js says why).
#
#   scripts/wp-render-test.sh
set -uo pipefail
KIT="$(cd "$(dirname "$0")/.." && pwd)"
WP="${FOODIFY_WP_DIR:-/home/user/wpsite}"
PORT="${FOODIFY_WP_PORT:-8791}"
R="\033[31m"; N="\033[0m"
# A check that could not run must not look like a pass.
[[ -f "$WP/wp-load.php" ]] || { printf "${R}  SKIP-FAIL${N} no WordPress at %s — this gate did NOT run\n" "$WP"; exit 2; }
[[ -x /opt/pw-browsers/chromium && -d /tmp/node_modules/playwright-core ]] || { printf "${R}  SKIP-FAIL${N} browser or playwright-core missing — this gate did NOT run\n"; exit 2; }

SRC="${FOODIFY_THEME_SRC:-$KIT/theme/foodify}"
rm -rf "$WP/wp-content/themes/foodify" && cp -r "$SRC" "$WP/wp-content/themes/foodify" || { printf "${R}  FAIL${N} could not stage the theme\n"; exit 2; }
php -r 'require "'"$WP"'/wp-load.php"; switch_theme("foodify");' >/dev/null 2>&1 || { printf "${R}  FAIL${N} could not activate the theme\n"; exit 2; }

# What "Create the missing pages" on Appearance → Foodify setup does — run for
# real, twice: the second run must create nothing.
php -d display_errors=0 -r 'require "'"$WP"'/wp-load.php";
  $a = foodify_setup_do_pages(); $b = foodify_setup_do_pages();
  // WordPress ships its Privacy Policy page as a DRAFT. The screen says so and
  // leaves publishing to a person; here that person publishes it.
  $draft = in_array( "Privacy policy", foodify_setup_facts()["pages_draft"], true );
  $pp = get_page_by_path( "privacy-policy" );
  if ( $pp && "publish" !== $pp->post_status ) { wp_publish_post( $pp->ID ); } else { $draft = true; }
  $missing = array_merge( foodify_setup_facts()["pages_missing"], foodify_setup_facts()["pages_draft"], $draft ? [] : [ "the screen did not report the draft" ] );
  printf( "  %s setup: every page the theme links to exists, links are readable, and a second run creates nothing (%d then %d)\n",
    ( ! $missing && 0 === $b && "" !== get_option( "permalink_structure" ) ) ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m", $a, $b );
  exit( ( ! $missing && 0 === $b ) ? 0 : 1 );' || SETUP_FAIL=1

LOGIN="fd-gate-login-$$.php"
TMP="$(mktemp -d)"; trap 'kill "$SRV" 2>/dev/null; rm -rf "$TMP" "$WP/$LOGIN"' EXIT
# A one-use door for the browser, removed on exit: signs in as the first
# administrator and lands on the setup screen.
cat > "$WP/$LOGIN" <<'PHP'
<?php
require __DIR__ . '/wp-load.php';
wp_set_auth_cookie( 1, false, false );
wp_safe_redirect( admin_url( 'themes.php?page=foodify-setup' ) );
exit;
PHP
# The address the browser uses, and no waiting on wordpress.org (unreachable
# from a sandbox; a blocked update check holds the single-threaded server).
cat > "$TMP/prepend.php" <<PHP
<?php
define( 'WP_HOME', 'http://localhost:$PORT' );
define( 'WP_SITEURL', 'http://localhost:$PORT' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );
PHP
( cd "$WP" && PHP_CLI_SERVER_WORKERS=4 exec php -d auto_prepend_file="$TMP/prepend.php" -d display_errors=0 -S "localhost:$PORT" >"$TMP/server.log" 2>&1 ) &
SRV=$!
for _ in $(seq 1 40); do curl -s -o /dev/null "http://localhost:$PORT/" && break; sleep 0.25; done
curl -s -o /dev/null "http://localhost:$PORT/" || { printf "${R}  FAIL${N} WordPress did not start on port %s\n" "$PORT"; tail -5 "$TMP/server.log"; exit 2; }

node "$KIT/tools/wp-render-check.js" "http://localhost:$PORT" "/$LOGIN"; RC=$?
[[ "${SETUP_FAIL:-0}" -eq 0 ]] || RC=1
exit "$RC"
