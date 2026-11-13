#!/usr/bin/env bash
# Serve the real WordPress with the theme active and LOOK at the page in a
# browser (tools/wp-render-check.js says why). Run after wp-boot-test.sh, which
# stages and activates the theme.
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

TMP="$(mktemp -d)"; trap 'kill "$SRV" 2>/dev/null; rm -rf "$TMP"' EXIT
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

node "$KIT/tools/wp-render-check.js" "http://localhost:$PORT"
