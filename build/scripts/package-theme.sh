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
unzip -p "$OUT" foodify/style.css | grep -q '^Theme Name:[[:space:]]*Foodify' || fail "style.css has no Theme Name header"
unzip -p "$OUT" foodify/theme.json | python3 -c 'import json,sys; json.load(sys.stdin)' || fail "theme.json does not parse"
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

SUM="$(sha256sum "$OUT" | cut -d' ' -f1)"
echo "$SUM  $(basename "$OUT")" > "$OUT.sha256"
printf "\n${G}ready${N}  %s  (%s)\n        sha256 %s\n" "dist/$NAME.zip" "$(du -h "$OUT" | cut -f1)" "$SUM"
[[ -z "$DIRTY" ]] || printf "        note: built from UNCOMMITTED theme changes — commit before handing this over\n"
