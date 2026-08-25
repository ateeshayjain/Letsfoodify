#!/usr/bin/env bash
# Tests the static redirect-map analyser inside wp02-verify.sh.
# Pure logic, no network — a chain is visible in the CSV, and catching it there
# is the difference between a bad map and a deployed bad map.
cd "$(dirname "$0")/.."
T=0; F=0
run(){ bash scripts/wp02-verify.sh "http://127.0.0.1:9" --redirects="tests/fixtures/$1" 2>&1 | sed -n '/1 · The redirect map/,/^$/p'; }
expect(){ # fixture  pattern  label
  if grep -q "$2" <<<"$(run "$1")"; then printf '  \033[32mPASS\033[0m %s\n' "$3"; T=$((T+1));
  else printf '  \033[31mFAIL\033[0m %s\n' "$3"; F=$((F+1)); fi; }
refute(){
  if grep -q "$2" <<<"$(run "$1")"; then printf '  \033[31mFAIL\033[0m %s\n' "$3"; F=$((F+1));
  else printf '  \033[32mPASS\033[0m %s\n' "$3"; T=$((T+1)); fi; }

echo "── redirect-map analyser ──"
expect redirects-clean.csv '0 structural problem'      "clean map reports no problems"
refute redirects-clean.csv 'chain:'                     "clean map is not flagged as chained"
expect redirects-chain.csv 'chain: /product-tag/a/'     "chain caught"
expect redirects-loop.csv  'loop:'                      "loop caught"
expect redirects-self.csv  'self-redirect'              "self-redirect caught"
expect redirects-dup.csv   'duplicate source'           "duplicate source caught"
printf '\n  %d passed · %d failed\n' "$T" "$F"
[[ $F -eq 0 ]] || exit 1
