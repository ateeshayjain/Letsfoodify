#!/usr/bin/env bash
# Foodify — THE CUTOVER GATE (WP-14). Run before every deploy of the rebuilt
# store and immediately after cutover.
#
# NOT the week-1 gate. This asserts the finished store: nine checkout fields
# (WP-06, week 11), COD (WP-07, week 12), and the WP-04 asset budget (week 10).
# Run against the live site during WP-01 it returns four blocking failures on a
# perfectly correct week-1 result. Use scripts/wp01-verify.sh for WP-01.
#   ./smoke-test.sh https://staging.letsfoodify.com
#   ./smoke-test.sh https://letsfoodify.com --redirects=redirects.csv
set -uo pipefail

BASE="${1:-}"; [[ -z "$BASE" ]] && { echo "Usage: $0 <base-url> [--redirects=file.csv]"; exit 2; }
BASE="${BASE%/}"; REDIRECTS=""
for a in "${@:2}"; do [[ "$a" == --redirects=* ]] && REDIRECTS="${a#*=}"; done

PASS=0; FAIL=0; WARN=0
ok(){ printf '\033[32m  PASS\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
no(){ printf '\033[31m  FAIL\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
wr(){ printf '\033[33m  WARN\033[0m %s\n' "$1"; WARN=$((WARN+1)); }
hdr(){ printf '\n\033[1;36m%s\033[0m\n' "$1"; }

# One fetch per page, reused across assertions.
#
# THE FAILURE MODE THIS GUARDS AGAINST: every "must be gone" assertion below is an
# ABSENCE check, and grep over an EMPTY string reports absence. A site that is
# down therefore "passes" the honesty section — it would print PASS for "no
# leaked source comment" having never loaded the page. A positive check fails
# loudly when the fetch dies; an absence check fails silently. So every body is
# checked for content before anything is asserted about it.
FETCH(){ curl -fsSL --max-time 25 -A 'FoodifySmokeTest/1.0' "$1" 2>/dev/null; }
CODE(){ curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$1"; }
# Response headers only. Empty means the request failed — checked before use,
# because "no HIT header" and "could not ask" are otherwise the same string.
HEADERS(){ curl -sS -o /dev/null -D - --max-time 25 -A 'FoodifySmokeTest/1.0' "$1" 2>/dev/null; }

# Guard: returns 0 only if $1 looks like a real HTML document.
GOT(){ [[ ${#1} -gt 500 && "$1" == *"<"* ]]; }
REQUIRE_BODY(){ # body label -> fails the run if the body never arrived
  if ! GOT "$2"; then no "$1: page did not load — assertions below are UNRELIABLE"; return 1; fi
  return 0
}

# A cart session. WooCommerce renders no payment methods and no checkout form for
# an empty cart, so checking /checkout/ cookie-free reports "COD missing" on a
# perfectly good store while the field count passes vacuously at zero.
JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
SESSION(){ curl -fsSL --max-time 25 -b "$JAR" -c "$JAR" -A 'FoodifySmokeTest/1.0' "$1" 2>/dev/null; }

hdr "1 · Reachability"
for p in "/" "/shop/" "/cart/" "/checkout/" "/my-account/" "/robots.txt" "/sitemap_index.xml"; do
  c=$(CODE "$BASE$p")
  [[ "$c" == "200" ]] && ok "$p → 200" || no "$p → $c"
done

hdr "2 · SEO — the audit's critical findings must not come back"
HOME=$(FETCH "$BASE/")
PDP_URL=$(printf '%s' "$HOME" | grep -oE 'href="[^"]*/product/[^"]+/"' | head -1 | sed 's/href="//;s/"$//')
# Themes emit product links both absolute and root-relative. Resolve either.
case "$PDP_URL" in
  http://*|https://*) ;;
  /*)                 PDP_URL="$BASE$PDP_URL" ;;
  *)                  PDP_URL="$BASE/shop/" ;;
esac
PDP=$(FETCH "$PDP_URL")

REQUIRE_BODY "homepage" "$HOME" || true
REQUIRE_BODY "product page" "$PDP" || true

grep -qi '<h1' <<<"$HOME"                     && ok "homepage has an H1"            || no "homepage has NO H1 (regression)"
grep -qi 'name="description"' <<<"$HOME"      && ok "homepage meta description"     || no "homepage meta description MISSING"
grep -qi 'property="og:' <<<"$HOME"           && ok "homepage Open Graph tags"      || no "homepage Open Graph MISSING"
grep -qi 'name="description"' <<<"$PDP"       && ok "product meta description"      || no "product meta description MISSING"
# WP-08. Exactly ONE Product node. Both WooCommerce and Rank Math emit Product
# structured data, and which yields to the other is something I could not verify
# by reading plugin source from this environment. So assert the OUTCOME rather
# than the mechanism: two Product nodes on one page is a real conflict whichever
# plugin produced them.
#
# `grep -o | wc -l`, not `grep -c`: grep -c counts LINES, so minified schema
# would report 1 for any number of nodes. That mistake is already in this
# project's history.
if GOT "$PDP"; then
  NODES=$(grep -oE '"@type" *: *"Product"' <<<"$PDP" | wc -l | tr -d ' ')
  case "$NODES" in
    0) no "Product schema MISSING" ;;
    1) ok "exactly one Product schema node" ;;
    *) no "$NODES Product schema nodes on one page — WooCommerce and Rank Math are both emitting; disable one" ;;
  esac

  # The product page must carry the Legal Metrology declarations (scope §8) and
  # the thing this brand sells on — how you make it. Positive checks: each looks
  # for a marker the theme emits, so a page that failed to load cannot report
  # them as present.
  grep -qi 'fd-prep__steps' <<<"$PDP" \
    && ok "product page says how you make it" \
    || no "product page has no preparation steps — the one question this brand exists to answer"
  grep -qi 'fd-spec__group' <<<"$PDP" \
    && ok "product page carries its structured declarations" \
    || no "product page has no declarations block — Legal Metrology fields are missing"
  # "Not provided" is deliberate and correct on a page whose data is incomplete —
  # it is how a gap is made visible rather than hidden. It is a WARN so it names
  # the work without blocking a deploy over data the client still owes.
  if grep -qi 'fd-spec' <<<"$PDP" && grep -q 'Not provided' <<<"$PDP"; then
    wr "product page shows 'Not provided' — a required declaration is missing on this product"
  fi

  # The schema and the page must tell the same story about reviews. A rating in
  # structured data that the page cannot show is fabricated social proof in the
  # one format built to be trusted, and it is what manual actions are for. The
  # reverse — visible reviews, no schema — is the SEO value silently not landing.
  PAGE_RATED=0;   grep -qiE 'woocommerce-product-rating|star-rating' <<<"$PDP" && PAGE_RATED=1
  SCHEMA_RATED=0; grep -qi  'aggregateRating' <<<"$PDP" && SCHEMA_RATED=1
  if   [[ "$PAGE_RATED" == 1 && "$SCHEMA_RATED" == 1 ]]; then ok "reviews on the page and in the schema agree"
  elif [[ "$PAGE_RATED" == 0 && "$SCHEMA_RATED" == 0 ]]; then ok "no reviews yet, and the schema does not claim any"
  elif [[ "$PAGE_RATED" == 0 && "$SCHEMA_RATED" == 1 ]]; then no "schema claims an aggregateRating the page cannot show — fabricated social proof"
  else                                                        wr "reviews are visible but absent from the schema — the SEO value is not landing"
  fi
else
  no "schema checks could not run — product page did not load"
fi
# 000 means the request failed, which is not evidence the sitemap is retired.
WPSM="$(CODE "$BASE/wp-sitemap.xml")"
if   [[ "$WPSM" == "000" ]]; then no "core sitemap check could not run (network) — not a pass"
elif [[ "$WPSM" != "200" ]]; then ok "core sitemap retired ($WPSM)"
else                              no "core wp-sitemap.xml still served — two sitemaps compete"; fi

hdr "3 · Honesty — things that must be gone"
# WP-08. `FSSAI 10012345678901` was hardcoded into the site header, both footers
# and the trust strip. It is the number people type when they need a number, and
# a food business displaying a fabricated licence is a compliance problem rather
# than a typo. It now comes from configuration, and an unset licence renders the
# words NOT CONFIGURED — deliberately not a number, so it cannot be mistaken for
# one. Either string reaching production is a blocking failure.
if GOT "$HOME"; then
  grep -q '10012345678901' <<<"$HOME" \
    && no "the placeholder FSSAI licence 10012345678901 is live on the site" \
    || ok "no placeholder FSSAI licence number"
  grep -q 'NOT CONFIGURED' <<<"$HOME" \
    && no "FSSAI licence renders NOT CONFIGURED — set it via the foodify_business_profile filter" \
    || ok "FSSAI licence number is configured"
else
  no "cannot check the FSSAI licence — homepage did not load"
fi
if GOT "$PDP"; then
  grep -qi 'people are viewing' <<<"$PDP" && no "fake viewer counter still present" || ok "no fake viewer counter"
else
  no "cannot check viewer counter — product page did not load"
fi

# WP-05's address book is a rewrite endpoint. Rewrite rules live in an option,
# so a deploy that copies theme files without re-activating leaves the account
# menu pointing at a 404. Logged out, WooCommerce answers every account endpoint
# with the login form and a 200 — so 404 here means the RULE is missing, which
# is precisely the failure that is invisible until a customer taps it.
AB="$(CODE "$BASE/my-account/address-book/")"
if   [[ "$AB" == "200" ]]; then ok "/my-account/address-book/ → 200 (endpoint registered)"
elif [[ "$AB" == "404" ]]; then no "/my-account/address-book/ → 404 — rewrite rules not flushed after deploy"
else                            no "/my-account/address-book/ → $AB (could not verify the endpoint)"; fi

ACC=$(FETCH "$BASE/my-account/")
if GOT "$ACC"; then
  grep -q 'Inject JS step-switching' <<<"$ACC" && no "developer comment still leaking on /my-account/" || ok "no leaked source comment"
else
  no "cannot check /my-account/ for the leaked comment — page did not load"
fi

# Compare against the actual current year rather than a hardcoded cutoff — the
# original treated <=2024 as stale, so it silently starts passing "© 2026" in 2027.
if GOT "$HOME"; then
  YEAR="$(date +%Y)"
  FOUND="$(grep -oiE '(©|&copy;|&#169;)[[:space:]]*[0-9]{4}' <<<"$HOME" | grep -oE '[0-9]{4}' | sort -u | tail -1)"
  if   [[ -z "$FOUND" ]];          then no "no copyright year in the footer — the FOODIFY_YEAR substitution is broken"
  elif [[ "$FOUND" == "$YEAR" ]];  then ok "footer year current ($FOUND)"
  else                                  no "footer year is $FOUND, current year is $YEAR"; fi
fi

hdr "4 · Commerce"
# Put something in the cart first, or none of this measures anything.
SHOP=$(SESSION "$BASE/shop/")
ADD_ID=$(grep -oE 'add-to-cart=[0-9]+' <<<"$SHOP" | head -1 | grep -oE '[0-9]+')
if [[ -n "$ADD_ID" ]]; then
  SESSION "$BASE/?add-to-cart=$ADD_ID" >/dev/null
  CART=$(SESSION "$BASE/cart/")
  grep -qi 'cart is currently empty' <<<"$CART" \
    && wr "add-to-cart did not stick — commerce assertions may be unreliable" \
    || ok "cart seeded with product #$ADD_ID"
else
  wr "could not find an add-to-cart id on /shop/ — commerce assertions run without a cart"
fi
CHK=$(SESSION "$BASE/checkout/")

if REQUIRE_BODY "checkout" "$CHK"; then
  if grep -qi 'cart is currently empty\|no payment methods' <<<"$CHK"; then
    no "checkout rendered an empty cart — the assertions below cannot run"
  else
    # Budget is on what a NEW GUEST sees: nine billing-side fields. The shipping
    # mirror only appears behind the "deliver elsewhere" toggle, and counting it
    # against the same budget fails a correct build.
    BFIELDS=$(grep -oE 'name="billing_[a-z0-9_]+"' <<<"$CHK" | sort -u | wc -l | tr -d ' ')
    SFIELDS=$(grep -oE 'name="shipping_[a-z0-9_]+"' <<<"$CHK" | sort -u | wc -l | tr -d ' ')
    [[ "$BFIELDS" -ge 1 && "$BFIELDS" -le 9 ]] \
      && ok "billing fields: $BFIELDS (budget ≤9, audit baseline 25)" \
      || no "billing fields: $BFIELDS — budget is 9, audit baseline was 25"
    [[ "$SFIELDS" -le 9 ]] && ok "shipping mirror: $SFIELDS fields" \
                           || wr "shipping mirror: $SFIELDS fields — larger than billing"

    grep -qE '<select[^>]*billing_state' <<<"$CHK" \
      && ok "state is a select" || no "state is not a select — free text corrupts GST and shipping"
    grep -qi 'cod\|cash on delivery' <<<"$CHK" && ok "COD offered" || no "COD not offered at checkout"
    grep -qi 'razorpay' <<<"$CHK" && ok "Razorpay present" || no "Razorpay missing"

    # WP-07: the prepaid saving is named on the payment option itself, from the
    # same function that applies the fee.
    #
    # WARN, not FAIL, and deliberately so. A missing saving is a legitimate
    # CONFIGURATION — the client can set prepaid_flat to 0 and mean it — unlike
    # the footer year, where the theme always emits one and absence proved the
    # substitution had broken. Do not "tighten" this to a failure: it would
    # block a deploy over a pricing decision.
    if grep -qi 'razorpay' <<<"$CHK"; then
      grep -qi 'fd-pay-saving' <<<"$CHK" \
        && ok "prepaid saving shown on the payment option" \
        || wr "no prepaid saving on the payment options — intended, or is inc/payments.php not loading?"
    fi
    grep -qE 'name="billing_email"[^>]*required|billing_email.*validate-required' <<<"$CHK" \
      && ok "email is required" || wr "could not confirm email is required — check manually"

    # WP-06: checkout runs a stripped header. Every nav link on this page is an
    # exit, and the audited site ran the full site chrome here. Positive check
    # first — the marker must be present — so a body that arrived empty cannot
    # report "no navigation" as a pass.
    if grep -qi 'fd-checkout-back' <<<"$CHK"; then
      ok "checkout uses the stripped header"
      grep -qi 'wp-block-navigation' <<<"$CHK" \
        && no "checkout still renders site navigation — every link there is an exit" \
        || ok "no site navigation on checkout"
    else
      no "checkout is NOT using the stripped header (parts/header-checkout.html)"
    fi
  fi
fi

hdr "5 · Privacy — pages that must never be cached"
# A page cache that serves /checkout/ or /my-account/ to an anonymous visitor
# serves ONE CUSTOMER'S name, address and phone TO ANOTHER. It is a data breach,
# not a performance bug, and it is silent: everything renders, orders go through,
# and nobody finds out until a customer says they saw somebody else's address.
#
# REVIEW-NOTES item 1 records that the only cache measurement anyone has taken of
# this site was almost certainly polluted by the tester's own cart cookie. So
# this asserts rather than assumes — and asserts POSITIVELY first, because a
# missing header and an unanswered request look identical to grep.
for p in "/cart/" "/checkout/" "/my-account/"; do
  H="$(HEADERS "$BASE$p")"
  if ! grep -qi '^HTTP/' <<<"$H"; then
    no "$p — could not read response headers; cache policy NOT verified"
    continue
  fi

  grep -qiE '^cache-control:.*no-store' <<<"$H" \
    && ok "$p sends Cache-Control: no-store" \
    || no "$p does NOT send no-store — a shared cache may serve one customer's details to another"

  # Proves inc/checkout-flow.php is loaded and running on this page, which is a
  # different question from whether the header policy is right.
  grep -qi '^x-foodify-private:' <<<"$H" \
    && ok "$p carries the theme's private-page marker" \
    || no "$p missing X-Foodify-Private — the WP-06 module is not running here"

  # Only now that headers demonstrably arrived is an absence check meaningful.
  if grep -qiE '^(x-cache|cf-cache-status|hcdn-cache|x-hcdn-cache|x-litespeed-cache|x-proxy-cache|x-fastcgi-cache):' <<<"$H"; then
    if grep -qiE '^(x-cache|cf-cache-status|hcdn-cache|x-hcdn-cache|x-litespeed-cache|x-proxy-cache|x-fastcgi-cache):[^\r]*hit' <<<"$H"; then
      no "$p was served FROM AN EDGE CACHE (HIT) — private data is being shared between visitors"
    else
      ok "$p reported by the edge as not cached"
    fi
  else
    wr "$p — no edge cache header at all; the CDN's behaviour here is unconfirmed"
  fi
done

hdr "6 · The Merchant Center feed"
# WP-12. The feed is the revenue surface, and Merchant Center fetches it on a
# schedule — a feed that breaks after a deploy fails SILENTLY until listings
# start expiring. Fetched and parsed, not just status-checked: HTTP 200 with a
# half-written document is exactly the failure an unescaped ampersand causes,
# and every item after that byte vanishes.
FEED_BODY="$(FETCH "$BASE/?foodify-feed=1")"
if [[ -z "$FEED_BODY" ]]; then
  no "feed did not answer at /?foodify-feed=1 — Merchant Center's fetch will fail"
elif ! grep -q 'xmlns:g="http://base.google.com/ns/1.0"' <<<"$FEED_BODY"; then
  no "feed answered but is not a Merchant Center document (no g: namespace)"
elif command -v xmllint >/dev/null 2>&1; then
  if xmllint --noout - <<<"$FEED_BODY" 2>/dev/null; then
    FEED_N="$(grep -o '<item>' <<<"$FEED_BODY" | wc -l | tr -d ' ')"
    ok "feed parses as XML with $FEED_N items"
    [[ "$FEED_N" -eq 0 ]] && wr "feed is VALID BUT EMPTY — every product is excluded (photos/descriptions missing?)"
  else
    no "feed does NOT parse as XML — one bad character is truncating the catalogue"
  fi
else
  # xmllint may be absent on the host. Say the check is weaker, never that it passed.
  grep -q '</rss>' <<<"$FEED_BODY" \
    && ok "feed reaches its closing tag (xmllint absent — parse not fully verified)" \
    || no "feed is TRUNCATED — it never reaches </rss>"
fi

hdr "7 · Analytics — installed fully, or not at all"
# WP-13. Half-installed analytics is the worst state because it LOOKS
# installed: the gtag loader without events measures nothing, events without
# the loader throw on every page. Coherence is asserted both ways. Fully OFF
# is legitimate pre-launch (the measurement ID is client-supplied, like the
# FSSAI number) — reported as a WARN naming the work, never silently passed.
GTAG_HOME=0; grep -q 'googletagmanager.com/gtag/js' <<<"$HOME" && GTAG_HOME=1
if [[ "$GTAG_HOME" == 1 ]]; then
  ok "gtag loader present"
  grep -q "view_item" <<<"$PDP" \
    && ok "view_item fires on the product page" \
    || no "gtag is loaded but view_item never fires — HALF-INSTALLED analytics"
  if GOT "$CHK"; then
    grep -q "begin_checkout" <<<"$CHK" \
      && ok "begin_checkout fires on checkout" \
      || no "gtag is loaded but begin_checkout never fires — HALF-INSTALLED analytics"
  fi
else
  grep -q "gtag(" <<<"$PDP" \
    && no "events are rendered but the gtag loader is absent — every page throws" \
    || wr "analytics OFF (no measurement ID) — supply foodify_ga4_measurement_id before launch; then verify in DebugView per WP-13-NOTES"
fi

hdr "8 · Performance budget (uncached HTML)"
read -r TTFB TOTAL SIZE HTTPC < <(curl -s -o /dev/null \
  -w '%{time_starttransfer} %{time_total} %{size_download} %{http_code}' --max-time 30 "$PDP_URL")
# 0 bytes in 0 seconds is not a fast page, it is a failed request.
if [[ "$HTTPC" != "200" || "$SIZE" -lt 500 ]]; then
  no "performance not measured — $PDP_URL returned $HTTPC, ${SIZE}B"
else
  awk -v t="$TTFB" 'BEGIN{exit !(t<0.6)}'  && ok "TTFB ${TTFB}s (<0.6s)"        || wr "TTFB ${TTFB}s — budget 0.6s"
  awk -v s="$SIZE" 'BEGIN{exit !(s<300000)}' && ok "HTML $((SIZE/1024))KB (<300KB)" || wr "HTML $((SIZE/1024))KB — audit baseline 288KB"
fi
# Counting assets in a body that never arrived yields 0 and 0, which would sail
# under both budgets. Same absence-check trap as section 3.
if GOT "$PDP"; then
  JS=$(grep -oE '<script[^>]+src=' <<<"$PDP" | wc -l | tr -d ' ')
  CSS=$(grep -oE '<link[^>]+stylesheet' <<<"$PDP" | wc -l | tr -d ' ')
  [[ "$JS"  -le 12 ]] && ok "JS files: $JS (budget 12, baseline 73)"   || no "JS files: $JS — budget is 12"
  [[ "$CSS" -le 6  ]] && ok "CSS files: $CSS (budget 6, baseline 60)"  || no "CSS files: $CSS — budget is 6"
else
  no "asset budget could not be measured — product page did not load"
fi

hdr "9 · Redirects"
if [[ -n "$REDIRECTS" && -f "$REDIRECTS" ]]; then
  n=0; bad=0
  while IFS=, read -r src tgt typ note; do
    [[ "$src" == "source" || -z "$src" ]] && continue
    n=$((n+1))
    hops=$(curl -s -o /dev/null -w '%{num_redirects}' -L --max-time 20 "$BASE$src")
    final=$(curl -s -o /dev/null -w '%{http_code}' -L --max-time 20 "$BASE$src")
    if   [[ "$final" != "200" ]]; then no "$src → final $final"; bad=$((bad+1))
    elif [[ "$hops" -gt 1 ]];    then wr "$src → $hops hops (chain)"
    fi
  done < "$REDIRECTS"
  [[ "$bad" -eq 0 ]] && ok "all $n redirects resolve to 200" || no "$bad of $n redirects broken"
else
  wr "no redirect map supplied — pass --redirects=redirects.csv before cutover"
fi

hdr "Result"
printf '  %d passed · %d failed · %d warnings\n\n' "$PASS" "$FAIL" "$WARN"
[[ "$FAIL" -eq 0 ]] || { echo "BLOCKING failures. Do not cut over."; exit 1; }
echo "Clear to proceed."
