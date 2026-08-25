#!/usr/bin/env bash
#
# WP-01 pre-flight. READ-ONLY against the site, except for taking a backup.
#
#   ./wp01-preflight.sh https://letsfoodify.com
#
# Run this from the WordPress root, over SSH, before touching anything. It
# refuses rather than warns: WP-01 edits a store that is taking orders, and the
# whole point of week 1 is that every change is reversible.
#
# It does three things:
#   1. asserts the tools and access WP-01 needs actually exist
#   2. captures the BEFORE state — both the rollback values and the baseline
#      the 30-day report is measured against
#   3. dry-runs bootstrap.sh phase 1 so you read the diff before it runs
set -Eeuo pipefail

BASE="${1:-}"; [[ -z "$BASE" ]] && { echo "Usage: $0 <live-site-url>"; exit 2; }
BASE="${BASE%/}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="../wp01-baseline-$STAMP"

log(){ printf '\033[1;36m▸ %s\033[0m\n' "$*"; }
ok(){  printf '\033[32m  ✓ %s\033[0m\n' "$*"; }
die(){ printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

log "Tooling"
for t in wp curl php; do command -v "$t" >/dev/null || die "$t not found. WP-01 needs it."; done
wp core is-installed >/dev/null 2>&1 || die "No WordPress install in $(pwd). Run this from the WP root."
wp plugin is-active woocommerce >/dev/null 2>&1 || die "WooCommerce is not active — wrong install?"
ok "wp-cli, curl, php, WordPress and WooCommerce all present"

log "The site answers"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE/")"
[[ "$CODE" == "200" ]] || die "$BASE/ returned $CODE. Fix access before changing anything."
ok "$BASE/ returns 200"

mkdir -p "$OUT"

log "Backup — and it is not a backup until it restores"
wp db export "$OUT/db-before.sql" --quiet
SIZE=$(wc -c < "$OUT/db-before.sql")
[[ "$SIZE" -gt 100000 ]] || die "Dump is only ${SIZE}B. That is not a real database."
ok "database exported ($(( SIZE / 1024 / 1024 )) MB)"
echo
echo "  RESTORE-TEST THIS ON STAGING BEFORE CONTINUING:"
echo "    wp db import $OUT/db-before.sql     # on STAGING, never here"
echo "  WP-00 requires a verified restore, not a dump file. Nothing below replaces it."
echo

log "Rollback record — every value WP-01 overwrites"
{
  echo "# Captured $(date -Iseconds) from $BASE"
  for o in blogdescription permalink_structure woocommerce_enable_reviews \
           woocommerce_enable_review_rating woocommerce_default_country \
           woocommerce_shop_page_id woocommerce_cart_page_id \
           woocommerce_checkout_page_id woocommerce_myaccount_page_id; do
    printf '%-45s %s\n' "$o" "$(wp option get "$o" 2>/dev/null || echo '<unset>')"
  done
} > "$OUT/options-before.txt"
ok "options recorded -> $OUT/options-before.txt"

wp post list --post_type=product --post_status=any --fields=ID,post_title,comment_status \
  --format=csv > "$OUT/product-comment-status.csv" 2>/dev/null || true
CLOSED=$(grep -c ',closed' "$OUT/product-comment-status.csv" || true)
ok "products with comments closed: $CLOSED (reviews tab will not render on these until phase 1 opens them)"

# Rank Math may already be present from an earlier attempt; record it if so.
if wp plugin is-installed seo-by-rank-math >/dev/null 2>&1; then
  wp option get rank-math-options-titles  --format=json > "$OUT/rank-math-titles-before.json"  2>/dev/null || true
  wp option get rank-math-options-sitemap --format=json > "$OUT/rank-math-sitemap-before.json" 2>/dev/null || true
  ok "Rank Math already installed — settings recorded. Its install defaults will NOT reapply."
else
  ok "Rank Math not yet installed (its noindex defaults for tags WILL apply on a fresh install)"
fi

log "Baseline — what the 30-day report is measured against"
{
  echo "captured: $(date -Iseconds)"
  echo "products: $(wp post list --post_type=product --post_status=publish --format=count)"
  echo "product_cat: $(wp term list product_cat --format=count)"
  echo "product_tag: $(wp term list product_tag --format=count)"
  echo "orders_total: $(wp post list --post_type=shop_order --post_status=any --format=count 2>/dev/null || echo 'n/a — HPOS?')"
  echo "active_plugins: $(wp plugin list --status=active --format=count)"
  echo "theme: $(wp theme list --status=active --field=name)"
  echo "php: $(php -r 'echo PHP_VERSION;')"
  echo "wp: $(wp core version)"
} > "$OUT/baseline.txt"
cat "$OUT/baseline.txt" | sed 's/^/    /'

log "URL inventory — the migration contract for WP-02 and WP-14"
wp post list --post_type=product --post_status=publish --field=url > "$OUT/urls-products.txt" 2>/dev/null || true
wp term list product_tag --field=url  > "$OUT/urls-tags.txt" 2>/dev/null || true
wp term list product_cat --field=url  > "$OUT/urls-categories.txt" 2>/dev/null || true
ok "$(wc -l < "$OUT/urls-products.txt") product / $(wc -l < "$OUT/urls-tags.txt") tag / $(wc -l < "$OUT/urls-categories.txt") category URLs recorded"

log "Dry-run of phase 1 — read every line before you approve it"
echo
bash "$(dirname "$0")/bootstrap.sh" --env=prod --phase=1 --dry-run || die "Dry run failed. Do not proceed."

echo
ok "Pre-flight complete. Everything above is in $OUT/"
cat <<NEXT

  Next, in order:
    1. Restore-test $OUT/db-before.sql on STAGING. Not optional.
    2. Run phase 1 on STAGING first:  bootstrap.sh --env=staging --phase=1
    3. Gate staging:                  wp01-verify.sh https://staging.letsfoodify.com
    4. Only then, production:         bootstrap.sh --env=prod --phase=1
    5. Gate production:               wp01-verify.sh $BASE

  Steps 1-4 of the runbook (the leaked comment, the fake counter, the
  testimonials, the shipping label, the footer year) are NOT in bootstrap.sh —
  they are edits to theme options and page content that differ per install.
  Do those by hand, per planning/WP-01-RUNBOOK.md, and record what you changed.

NEXT
