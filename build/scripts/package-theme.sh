#!/usr/bin/env bash
# Package the theme as the zip WordPress installs: Appearance → Themes → Add New
# → Upload Theme, or `wp theme install foodify-*.zip` over SSH.
#
# WHY THIS EXISTS. bootstrap.sh activates a theme that must already be in
# wp-content/themes — "deploy it from git" — and nothing in the kit said how the
# files get there. A zip is the one form every WordPress host accepts, and the
# one a developer can hand to a host's support desk.
#
# THE ZIP IS WHAT GETS TESTED. After building it, this script unzips it into a
# scratch folder and boots THAT in a real WordPress (scripts/wp-boot-test.sh).
# A missing file, a stray absolute path or a folder named wrongly inside the
# archive shows up here, not on the client's staging site. If the boot cannot
# run, the package is not declared good.
#
#   scripts/package-theme.sh            # -> dist/foodify-<version>-<commit>.zip
set -euo pipefail
# PIPED CHECKS NEVER USE `grep -q`. Under pipefail, -q stops reading at the first
# match; a long producer (`wp rewrite list`, a 180 KB style.css) is then killed by
# SIGPIPE, exits 141, and a check that FOUND its line reports it missing. Every
# piped grep sends its output to /dev/null instead, which reads to the end.
KIT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="$KIT/theme/foodify"
G="\033[32m"; R="\033[31m"; N="\033[0m"
fail() { printf "${R}  FAIL${N} %s\n" "$*"; exit 1; }
pass() { printf "${G}  PASS${N} %s\n" "$*"; }

VERSION="$(sed -n 's/^Version:[[:space:]]*//p' "$THEME/style.css" | head -1 | tr -d '[:space:]')"
[[ -n "$VERSION" ]] || fail "no Version: line in style.css"
COMMIT="$(git -C "$KIT" rev-parse --short HEAD 2>/dev/null || echo nogit)"
DIRTY=""; git -C "$KIT" diff --quiet -- theme 2>/dev/null || DIRTY="-dirty"
NAME="foodify-${VERSION}-${COMMIT}${DIRTY}"
mkdir -p "$KIT/dist"
OUT="$KIT/dist/$NAME.zip"
rm -f "$OUT"

# Build from a staged copy so the archive's top folder is exactly `foodify/` —
# WordPress names the installed theme after it, and bootstrap.sh activates
# `foodify` by that name.
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
cp -r "$THEME" "$STAGE/foodify"
find "$STAGE/foodify" \( -name '.DS_Store' -o -name '*.orig' -o -name '*.bak' -o -name '*~' \) -delete
( cd "$STAGE" && TZ=UTC find foodify -exec touch -t 202601010000 {} + && zip -qrX "$OUT" foodify )

echo "── checking the archive ──"
LIST="$(unzip -Z1 "$OUT")"
grep -qx 'foodify/style.css'           <<<"$LIST" || fail "foodify/style.css is not at the top of the archive"
grep -qx 'foodify/templates/index.html' <<<"$LIST" || fail "templates/index.html missing — WordPress would not treat it as a block theme"
grep -qx 'foodify/theme.json'          <<<"$LIST" || fail "theme.json missing"
[[ "$(grep -c '^foodify/' <<<"$LIST")" -eq "$(wc -l <<<"$LIST")" ]] || fail "files outside foodify/ in the archive"
unzip -p "$OUT" foodify/style.css | grep >/dev/null '^Theme Name:[[:space:]]*Foodify' || fail "style.css has no Theme Name header"
unzip -p "$OUT" foodify/theme.json | python3 -c 'import json,sys; json.load(sys.stdin)' || fail "theme.json does not parse"
grep -qx 'foodify/screenshot.png'      <<<"$LIST" || fail "screenshot.png missing — the theme's card in Appearance → Themes would be a blank square"
for f in Fraunces-Variable.woff2 InstrumentSans-Variable.woff2; do
  grep -qx "foodify/assets/fonts/$f" <<<"$LIST" || fail "font $f is not in the archive — the site would fall back to system fonts"
done
pass "archive layout: $(wc -l <<<"$LIST") files under foodify/, block theme, fonts included"

echo "── booting the ARCHIVE in a real WordPress ──"
UNZ="$(mktemp -d)"; trap 'rm -rf "$STAGE" "$UNZ"' EXIT
unzip -q "$OUT" -d "$UNZ"
if FOODIFY_THEME_SRC="$UNZ/foodify" "$KIT/scripts/wp-boot-test.sh" >"$UNZ/boot.log" 2>&1; then
  pass "the zip installs and boots clean in WordPress"
else
  sed 's/^/    /' "$UNZ/boot.log" | tail -20
  fail "the zip did not boot clean — not shipping it"
fi
# Booting clean is PHP's verdict. What a person sees after uploading the zip is a
# page — so the archive's home page is opened in a browser too.
if FOODIFY_THEME_SRC="$UNZ/foodify" "$KIT/scripts/wp-render-test.sh" >"$UNZ/render.log" 2>&1; then
  pass "the zip's home page renders clean in a browser (real WordPress, both widths)"
else
  sed 's/^/    /' "$UNZ/render.log" | tail -24
  fail "the zip's home page does not render clean — not shipping it"
fi

# The shop pages need WooCommerce, which only some machines can download. Where a
# WooCommerce test site exists the ZIP is set up and bought from, and a failure
# stops the hand-over; where none does, that is SAID — the zip is not called
# checked for something that did not run.
WOO_NOTE=""
if [[ -f "${FOODIFY_WOO_DIR:-/home/user/wpsite-woo}/wp-load.php" ]]; then
  if FOODIFY_THEME_SRC="$UNZ/foodify" "$KIT/scripts/wp-woo-test.sh" >"$UNZ/woo.log" 2>&1; then
    pass "the zip's shop works in a real WooCommerce: set up, crawled and bought from ($(grep -o '[0-9]* checks' "$UNZ/woo.log" | tail -1))"
  else
    grep -E 'FAIL|did NOT' "$UNZ/woo.log" | sed 's/^/    /' | head -24
    fail "the zip's shop does not work clean in WooCommerce — not shipping it"
  fi
else
  WOO_NOTE="        note: the WooCommerce check did NOT run on this zip (no site at ${FOODIFY_WOO_DIR:-/home/user/wpsite-woo}) — scripts/wp-woo-test.sh, docs/WP-BOOT.md"
fi

SUM="$(sha256sum "$OUT" | cut -d' ' -f1)"
echo "$SUM  $(basename "$OUT")" > "$OUT.sha256"
printf "\n${G}ready${N}  %s  (%s)\n        sha256 %s\n" "dist/$NAME.zip" "$(du -h "$OUT" | cut -f1)" "$SUM"
[[ -z "$WOO_NOTE" ]] || printf "%s\n" "$WOO_NOTE"
[[ -z "$DIRTY" ]] || printf "        note: built from UNCOMMITTED theme changes — commit before handing this over\n"
