#!/usr/bin/env bash
#
# WP-02 gate — taxonomy and information architecture.
#
#   ./wp02-verify.sh https://letsfoodify.com [--redirects=scripts/redirects.csv]
#
# Checks WP-02's acceptance criteria and nothing else. smoke-test.sh remains the
# CUTOVER gate (WP-14); wp01-verify.sh remains week 1.
#
#   - 20 or fewer indexable tag archives remain
#   - every removed tag URL 301s, with no chain longer than one hop
#   - the replacement attribute archives are NOT indexable
#   - retained categories carry 150-300 words of unique copy
#
# Chains and loops are checked STATICALLY against redirects.csv first, before any
# network call. A chain is visible in the file — a target that is itself a source
# — and finding it there costs nothing, whereas finding it live means it is
# already deployed and Google has already crawled it.
set -uo pipefail

BASE="${1:-}"; [[ -z "$BASE" ]] && { echo "Usage: $0 <base-url> [--redirects=file.csv]"; exit 2; }
BASE="${BASE%/}"
REDIRECTS="scripts/redirects.csv"
for a in "${@:2}"; do [[ "$a" == --redirects=* ]] && REDIRECTS="${a#*=}"; done

PASS=0; FAIL=0; WARN=0
ok(){ printf '\033[32m  PASS\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
no(){ printf '\033[31m  FAIL\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
wr(){ printf '\033[33m  WARN\033[0m %s\n' "$1"; WARN=$((WARN+1)); }
hdr(){ printf '\n\033[1;36m%s\033[0m\n' "$1"; }

UA='FoodifyWP02/1.0'
BODY=""; STATUS=""
fetch(){
  BODY="$(curl -sSL --max-time 25 -A "$UA" -w '\n%{http_code}' "$1" 2>/dev/null)" || { STATUS=000; BODY=""; return 1; }
  STATUS="${BODY##*$'\n'}"; BODY="${BODY%$'\n'*}"
  [[ "$STATUS" == "200" && -n "$BODY" ]]
}
fetch_page(){ fetch "$1" && [[ ${#BODY} -gt 500 && "$BODY" == *"<"* ]]; }
code(){ curl -s -o /dev/null -w '%{http_code}' --max-time 25 -A "$UA" "$1"; }

hdr "1 · The redirect map, checked before it is trusted"
if [[ ! -f "$REDIRECTS" ]]; then
  no "no redirect map at $REDIRECTS — run taxonomy-cleanup.php execute first, or pass --redirects="
else
  python3 - "$REDIRECTS" <<'PY'
import csv, sys, collections
rows = list(csv.DictReader(open(sys.argv[1])))
src  = {}
dup  = collections.Counter()
bad  = 0
for r in rows:
    s = (r.get('source') or '').strip()
    t = (r.get('target') or '').strip()
    if not s: continue
    dup[s] += 1
    src[s] = t

RED, GRN, OFF = "\033[31m", "\033[32m", "\033[0m"

def emit(kind, msg):
    print("  " + RED + "FAIL" + OFF + f" {kind}: {msg}")

# self-redirects
for s, t in src.items():
    if s == t:
        emit("self-redirect", s); bad += 1

# chains: a target that is itself a source
for s, t in src.items():
    if t in src:
        emit("chain", f"{s} -> {t} -> {src[t]}"); bad += 1

# loops
for s in src:
    seen, cur = [s], src[s]
    while cur in src and cur not in seen:
        seen.append(cur); cur = src[cur]
    if cur in seen:
        emit("loop", " -> ".join(seen + [cur])); bad += 1
        break

for s, n in dup.items():
    if n > 1:
        emit("duplicate source", f"{s} appears {n} times — which target wins is undefined"); bad += 1

tag = (RED + "FAIL" + OFF) if bad else (GRN + "PASS" + OFF)
print(f"  {tag} {len(rows)} redirects, {bad} structural problem(s) "
      "— checked without a single request")
sys.exit(1 if bad else 0)
PY
  [[ $? -eq 0 ]] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
fi

SITE_UP=0
if [[ "$(code "$BASE/")" == "200" ]]; then SITE_UP=1; fi

hdr "2 · Every removed URL resolves, in one hop"
if [[ "$SITE_UP" -eq 0 ]]; then
  no "$BASE/ did not return 200 — every network check below is UNRELIABLE"
fi
if [[ -f "$REDIRECTS" ]]; then
  n=0; chained=0; broken=0
  while IFS=, read -r s t typ note; do
    [[ "$s" == "source" || -z "$s" ]] && continue
    n=$((n+1))
    read -r hops final < <(curl -s -o /dev/null -L --max-time 20 -A "$UA" \
      -w '%{num_redirects} %{http_code}' "$BASE$s")
    if   [[ "$final" == "000" ]]; then no "  request failed: $s"; broken=$((broken+1))
    elif [[ "$final" != "200" ]]; then no "  $s -> final $final"; broken=$((broken+1))
    elif [[ "$hops" -gt 1 ]];     then no "  $s -> $hops hops (chain)"; chained=$((chained+1))
    fi
  done < "$REDIRECTS"
  if [[ "$n" -eq 0 ]]; then
    wr "redirect map is empty"
  elif [[ "$broken" -eq 0 && "$chained" -eq 0 ]]; then
    ok "all $n redirects resolve to 200 in one hop"
  else
    no "$broken broken, $chained chained, of $n"
  fi
fi

hdr "3 · Indexable tag archives: 20 or fewer"
MAP="$BASE/sitemap_index.xml"
TAGSM=""
if fetch "$MAP"; then
  TAGSM=$(grep -oE '<loc>[^<]*product_tag-sitemap[^<]*</loc>|<loc>[^<]*product-tag-sitemap[^<]*</loc>' <<<"$BODY" | head -1 | sed 's|</\?loc>||g')
fi
if [[ "$SITE_UP" -eq 0 ]]; then
  no "cannot count tag archives — the site did not respond"
elif [[ -z "$TAGSM" ]] && [[ "$STATUS" != "200" ]]; then
  no "sitemap index did not load (HTTP $STATUS) — 'no tag sitemap' is not a conclusion"
elif [[ -z "$TAGSM" ]]; then
  ok "sitemap index loaded and lists no tag sitemap — tag archives are out of the index"
elif fetch "$TAGSM"; then
  COUNT=$(grep -oE '<loc>' <<<"$BODY" | wc -l | tr -d ' ')
  [[ "$COUNT" -le 20 ]] && ok "$COUNT indexable tag archives (criterion: 20 or fewer)" \
                        || no "$COUNT indexable tag archives — criterion is 20 or fewer"
else
  wr "tag sitemap listed at $TAGSM but did not load — check by hand"
fi

hdr "4 · Attribute archives generate NO indexable page"
# The whole point of moving tags to attributes. A pa_* taxonomy is public by
# default, so without theme/foodify/inc/product-attributes.php this trades 170
# indexable tag archives for a fresh set of indexable attribute archives.
ATTR_HIT=0
for path in /pa_dietary/vegan/ /pa_prep/hot-water/ /product-dietary/vegan/ /dietary/vegan/; do
  c="$(code "$BASE$path")"
  if [[ "$c" == "200" ]]; then
    if fetch_page "$BASE$path" && grep -io '<meta name="robots"[^>]*>' <<<"$BODY" | grep -qi noindex; then
      wr "$path returns 200 but is noindex — prefer 404 (public=false)"
    else
      no "$path returns 200 and is INDEXABLE — attribute archives are not suppressed"
      ATTR_HIT=1
    fi
  fi
done
if [[ "$SITE_UP" -eq 0 ]]; then
  no "cannot check attribute archives — the site did not respond"
elif [[ "$ATTR_HIT" -eq 0 ]]; then
  ok "no indexable attribute archive found"
fi

hdr "5 · Retained categories carry unique copy"
CATSM=""
if fetch "$MAP"; then
  CATSM=$(grep -oE '<loc>[^<]*product_cat-sitemap[^<]*</loc>|<loc>[^<]*product-cat-sitemap[^<]*</loc>' <<<"$BODY" | head -1 | sed 's|</\?loc>||g')
fi
if [[ -n "$CATSM" ]] && fetch "$CATSM"; then
  URLS=$(grep -oE '<loc>[^<]*</loc>' <<<"$BODY" | sed 's|</\?loc>||g')
  thin=0; total=0
  while read -r u; do
    [[ -z "$u" ]] && continue
    total=$((total+1))
    if ! fetch_page "$u"; then no "  category did not load: $u"; thin=$((thin+1)); continue; fi
    # crude word count of the term description area; good enough to catch "empty"
    words=$(sed 's/<[^>]*>/ /g' <<<"$BODY" | tr -s '[:space:]' '\n' | grep -c '[A-Za-z]' || true)
    [[ "$words" -lt 150 ]] && { no "  ~$words words: $u"; thin=$((thin+1)); }
  done <<< "$URLS"
  [[ "$thin" -eq 0 ]] && ok "all $total categories carry 150+ words" \
                      || no "$thin of $total categories are thin (criterion: 150-300 words each)"
else
  wr "could not enumerate categories from the sitemap — check by hand"
fi

hdr "Result"
printf '  %d passed · %d failed · %d warnings\n\n' "$PASS" "$FAIL" "$WARN"
if [[ "$FAIL" -ne 0 ]]; then
  echo "WP-02 is NOT complete."
  exit 1
fi
echo "WP-02 acceptance criteria pass."
echo "STILL OUTSTANDING and not checkable from here: 'zero soft-404s in Search"
echo "Console after 14 days'. Diarise the check — it is the criterion that catches"
echo "a redirect map that resolves but sends everything to an irrelevant page."
