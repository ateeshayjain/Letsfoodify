#!/usr/bin/env bash
#
# WP-01 gate — the week-1 live-site fixes, and NOTHING else.
#
#   ./wp01-verify.sh https://letsfoodify.com
#
# WHY THIS EXISTS AND smoke-test.sh DOES NOT DO IT
# ------------------------------------------------
# smoke-test.sh is the CUTOVER gate (WP-14). It asserts the finished store:
# nine checkout fields (WP-06, week 11), COD (WP-07, week 12), twelve JS files
# and six CSS files (WP-04, week 10). In week 1 the live site has 25 fields, no
# COD, 73 JS and 60 CSS — so smoke-test.sh returns four blocking failures on a
# perfectly correct WP-01.
#
# A gate that cannot pass is worse than no gate. It either stalls the work or
# teaches you to run it and ignore the result, and on a solo build the gate is
# what stands in for code review. So WP-01 gets a gate scoped to WP-01.
#
# Every check asserts the HTTP status BEFORE grepping. An absence check over a
# body that never arrived reports absence — "no leaked comment" is what a dead
# site looks like.
set -uo pipefail

BASE="${1:-}"; [[ -z "$BASE" ]] && { echo "Usage: $0 <base-url>"; exit 2; }
BASE="${BASE%/}"

PASS=0; FAIL=0; WARN=0
ok(){ printf '\033[32m  PASS\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
no(){ printf '\033[31m  FAIL\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
wr(){ printf '\033[33m  WARN\033[0m %s\n' "$1"; WARN=$((WARN+1)); }
hdr(){ printf '\n\033[1;36m%s\033[0m\n' "$1"; }

UA='FoodifyWP01/1.0'
BODY=""; STATUS=""

# fetch: 200 with a non-empty body. Used for XML too, so there is no length
# floor here — a sitemap index is legitimately ~100 bytes, and an earlier
# version's 500-byte minimum (borrowed from the page-oriented smoke test)
# silently rejected every sitemap, so the per-product sweep could never run.
fetch(){
  BODY="$(curl -sSL --max-time 25 -A "$UA" -w '\n%{http_code}' "$1" 2>/dev/null)" || { STATUS=000; BODY=""; return 1; }
  STATUS="${BODY##*$'\n'}"; BODY="${BODY%$'\n'*}"
  [[ "$STATUS" == "200" && -n "$BODY" ]]
}

# fetch_page: additionally insists the body is a real HTML document. Every
# absence check below must use this — "the comment is gone" and "the page never
# rendered" are the same grep result otherwise.
fetch_page(){
  fetch "$1" && [[ ${#BODY} -gt 500 && "$BODY" == *"<"* ]]
}
code(){ curl -s -o /dev/null -w '%{http_code}' --max-time 25 -A "$UA" "$1"; }

hdr "1 · The leaked developer comment  (criterion 1)"
if fetch_page "$BASE/my-account/"; then
  n=$(grep -c 'Inject JS step-switching' <<<"$BODY" || true)
  [[ "$n" -eq 0 ]] && ok "/my-account/ carries no leaked source comment" \
                   || no "/my-account/ still leaks the developer comment ($n occurrences)"
  grep -qiE '<\?php|Warning:|Notice:|Fatal error' <<<"$BODY" \
    && no "PHP output or notices rendering on /my-account/" || ok "no PHP notices on /my-account/"
else
  no "/my-account/ did not load (HTTP $STATUS) — cannot verify criterion 1"
fi

hdr "2 · Sitemaps  (criterion 3)"
CORE="$(code "$BASE/wp-sitemap.xml")"
RM="$(code "$BASE/sitemap_index.xml")"
if [[ "$CORE" == "000" || "$RM" == "000" ]]; then
  no "sitemap checks could not run (network) — 000 is not evidence of anything"
else
  [[ "$CORE" != "200" ]] && ok "core wp-sitemap.xml retired ($CORE)" \
                         || no "core wp-sitemap.xml still served — two sitemaps compete"
  [[ "$RM"  == "200" ]] && ok "Rank Math sitemap_index.xml serving" \
                        || no "Rank Math sitemap not found at /sitemap_index.xml ($RM)"
fi

hdr "3 · Reviews are on  (criterion 4)"
PDP_URL=""
if fetch_page "$BASE/"; then
  PDP_URL=$(grep -oE 'href="[^"]*/product/[^"]+/"' <<<"$BODY" | head -1 | sed 's/href="//;s/"$//')
fi
if [[ -z "$PDP_URL" ]] && fetch_page "$BASE/shop/"; then
  PDP_URL=$(grep -oE 'href="[^"]*/product/[^"]+/"' <<<"$BODY" | head -1 | sed 's/href="//;s/"$//')
fi
case "$PDP_URL" in http*) ;; /*) PDP_URL="$BASE$PDP_URL" ;; *) PDP_URL="" ;; esac

if [[ -n "$PDP_URL" ]] && fetch_page "$PDP_URL"; then
  grep -qiE 'id="reviews"|tab-title-reviews|woocommerce-Reviews|comment-form-rating' <<<"$BODY" \
    && ok "product page renders a reviews tab" \
    || no "no reviews tab on $PDP_URL — check comment_status on products, not just the global setting"
  # WP-01 also removes the invented social proof.
  grep -qi 'people are viewing' <<<"$BODY" \
    && no "fake viewer counter still present" || ok "no fake viewer counter"
else
  no "could not load a product page (tried: ${PDP_URL:-none found}) — criterion 4 unverified"
fi

hdr "4 · THE CATALOGUE IS STILL INDEXABLE"
# The same Rank Math option blob that noindexes 170 tags controls index on 44
# products. A wrong key noindexes the store and nothing in wp-admin looks wrong.
# This is the check that matters most in WP-01.
if [[ -n "$PDP_URL" ]] && fetch_page "$PDP_URL"; then
  if grep -io '<meta name="robots"[^>]*>' <<<"$BODY" | grep -qi noindex; then
    no "PRODUCT PAGE IS NOINDEX — roll back now, do not fix forward"
  else
    ok "product page is indexable"
  fi
fi
if fetch_page "$BASE/"; then
  grep -io '<meta name="robots"[^>]*>' <<<"$BODY" | grep -qi noindex \
    && no "HOMEPAGE IS NOINDEX — roll back now" || ok "homepage is indexable"
fi

hdr "5 · Thin archives are de-indexed"
TAG_URL=""
if [[ -n "$PDP_URL" ]] && fetch "$PDP_URL"; then
  TAG_URL=$(grep -oE 'href="[^"]*/product-tag/[^"]+/"' <<<"$BODY" | head -1 | sed 's/href="//;s/"$//')
  case "$TAG_URL" in http*) ;; /*) TAG_URL="$BASE$TAG_URL" ;; *) TAG_URL="" ;; esac
fi
if [[ -n "$TAG_URL" ]] && fetch_page "$TAG_URL"; then
  grep -io '<meta name="robots"[^>]*>' <<<"$BODY" | grep -qi noindex \
    && ok "tag archive is noindex ($TAG_URL)" \
    || no "tag archive is still indexable — the de-index did not apply"
else
  wr "no product-tag link found to sample; check one by hand"
fi

hdr "6 · Meta descriptions and social tags on EVERY product  (criterion 2)"
# The criterion says every product URL. Sampling one is how this passes while
# products with an empty short description quietly render an empty description.
MAP="$BASE/sitemap_index.xml"
PRODUCT_SITEMAP=""
if fetch "$MAP"; then
  PRODUCT_SITEMAP=$(grep -oE '<loc>[^<]*product-sitemap[^<]*</loc>' <<<"$BODY" | head -1 | sed 's|</\?loc>||g')
fi
URLS=""
if [[ -n "$PRODUCT_SITEMAP" ]] && fetch "$PRODUCT_SITEMAP"; then
  URLS=$(grep -oE '<loc>[^<]*/product/[^<]*</loc>' <<<"$BODY" | sed 's|</\?loc>||g')
fi
if [[ -z "$URLS" ]]; then
  wr "could not enumerate products from the sitemap — run the wp-cli sweep in the runbook instead"
else
  total=0; bad=0
  while read -r u; do
    [[ -z "$u" ]] && continue
    total=$((total+1))
    if ! fetch_page "$u"; then no "  fetch failed ($STATUS): $u"; bad=$((bad+1)); continue; fi
    d=$(grep -ioE '<meta name="description" content="[^"]+"' <<<"$BODY" | wc -l | tr -d ' ')
    # -o | wc -l, never grep -c: grep -c counts matching LINES. A site that
    # emits its <head> on one line — which is what minification produces, and
    # WP-04 adds minification — would report 1 social tag and fail this check
    # while being entirely correct.
    s=$(grep -oiE '<meta (property="og:|name="twitter:)' <<<"$BODY" | wc -l | tr -d ' ')
    if [[ "$d" -lt 1 || "$s" -lt 4 ]]; then
      no "  desc=$d social=$s  $u"; bad=$((bad+1))
    fi
  done <<< "$URLS"
  [[ "$bad" -eq 0 ]] && ok "all $total products carry a description and 4+ social tags" \
                     || no "$bad of $total products fail criterion 2"
fi

hdr "7 · Housekeeping"
if fetch_page "$BASE/"; then
  YEAR="$(date +%Y)"
  FOUND="$(grep -oiE '(©|&copy;|&#169;)[[:space:]]*[0-9]{4}' <<<"$BODY" | grep -oE '[0-9]{4}' | sort -u | tail -1)"
  if   [[ -z "$FOUND" ]];         then wr "no copyright year found in the footer"
  elif [[ "$FOUND" == "$YEAR" ]]; then ok "footer year current ($FOUND)"
  else                                 no "footer year is $FOUND, current year is $YEAR"; fi
fi

hdr "Result"
printf '  %d passed · %d failed · %d warnings\n\n' "$PASS" "$FAIL" "$WARN"
if [[ "$FAIL" -ne 0 ]]; then
  echo "WP-01 is NOT complete. Fix or roll back before moving on."
  exit 1
fi
echo "WP-01 acceptance criteria pass."
echo "NOTE: smoke-test.sh is the CUTOVER gate and will still fail here — it asserts"
echo "      COD, nine checkout fields and the WP-04 asset budget, none of which exist"
echo "      until weeks 10-12. That is expected, not a regression."
