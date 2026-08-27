#!/usr/bin/env bash
# The whole gate, one command. WP-14's answer to "is this buildable state?"
#
#   build/run-all-tests.sh                # everything local
#   build/run-all-tests.sh https://site   # + the blocking smoke test against it
#
# EVERY SUITE THAT CANNOT RUN IS A FAILURE, NOT A SKIP. This project's recurring
# lesson is that an absence check that cannot run looks exactly like one that
# passed — a missing interpreter, a missing WordPress, a missing browser must
# turn the run red, because "green" has to mean "verified", not "unverifiable".
set -u
cd "$(dirname "$0")"
G="\033[32m"; R="\033[31m"; N="\033[0m"
FAILED=0; RAN=0

run() { # label command...
  local label="$1"; shift
  RAN=$((RAN+1))
  local out
  if out="$("$@" 2>&1)"; then
    printf "  ${G}PASS${N} %-28s %s\n" "$label" "$(tail -1 <<<"$out" | sed 's/\x1b\[[0-9;]*m//g')"
  else
    FAILED=$((FAILED+1))
    printf "  ${R}FAIL${N} %-28s\n" "$label"
    tail -12 <<<"$out" | sed 's/^/         /'
  fi
}

echo "── static ──"
run "php-lint"        bash -c 'find theme scripts -name "*.php" -exec php -l {} \; | grep -v "No syntax errors" && exit 1; echo "all files parse"'
run "bash-syntax"     bash -c 'for f in scripts/*.sh run-all-tests.sh; do bash -n "$f" || exit 1; done; echo "all scripts parse"'
run "theme.json"      python3 -c "import json;json.load(open('theme/foodify/theme.json'));print('valid')"

echo "── pure suites ──"
for t in perf shortcode otp address checkout payments reviews partner admin product-spec wp11 wp12 wp13; do
  run "$t" php "tests/$t-test.php"
done
run "undefined-functions" php tests/undefined-functions.php
run "contrast"            python3 tests/contrast-test.py
run "gate-selftest"       python3 tests/selftest.py
run "wp01-selftest"       python3 tests/wp01-selftest.py
run "wp02-map-selftest"   bash tests/wp02-map-selftest.sh

echo "── real renderers ──"
run "wordpress-boot"  ./scripts/wp-boot-test.sh
if [[ -x /opt/pw-browsers/chromium && -d /tmp/node_modules/playwright-core ]]; then
  run "browser-sweep" node tools/mobile-sweep.js
else
  RAN=$((RAN+1)); FAILED=$((FAILED+1))
  printf "  ${R}FAIL${N} %-28s browser or playwright-core missing — the sweep DID NOT RUN\n" "browser-sweep"
  printf "         npm i playwright-core (uses the preinstalled /opt/pw-browsers/chromium)\n"
fi

if [[ "${1:-}" == http* ]]; then
  echo "── the blocking gate, against $1 ──"
  run "smoke-test" ./scripts/smoke-test.sh "$1" "${@:2}"
fi

echo
if [[ "$FAILED" -eq 0 ]]; then
  printf "${G}ALL GREEN${N} — %d suites\n" "$RAN"
else
  printf "${R}%d of %d suites FAILED — do not ship this state${N}\n" "$FAILED" "$RAN"
fi
exit $(( FAILED > 0 ))
