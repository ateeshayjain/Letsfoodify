#!/usr/bin/env bash
#
# Foodify — configuration as code.
#
# WHY THIS EXISTS
# ---------------
# The rebuild happens on staging while prod keeps taking orders. At cutover you cannot
# push the staging database to prod, because prod has weeks of orders staging has never
# seen. Only two things can safely move: files, and *reproducible configuration*.
#
# So every setting lives here rather than in someone's memory of an admin screen.
# Run it on staging to build the environment; run the same script on prod at cutover.
#
# USAGE
#   ./bootstrap.sh --env=staging        # full build
#   ./bootstrap.sh --env=prod --phase=1 # week-1 hotfixes only, safe on the live site
#   ./bootstrap.sh --env=prod --dry-run
#
set -Eeuo pipefail

ENV="staging"; PHASE="all"; DRY=0
for arg in "$@"; do
  case "$arg" in
    --env=*)   ENV="${arg#*=}" ;;
    --phase=*) PHASE="${arg#*=}" ;;
    --dry-run) DRY=1 ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done

log()  { printf '\033[1;36m▸ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m  ✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

wp() { if [[ $DRY -eq 1 ]]; then echo "    [dry-run] wp $*"; else command wp "$@"; fi; }

command -v wp >/dev/null || die "WP-CLI not found. Install it first: https://wp-cli.org"
command wp core is-installed >/dev/null 2>&1 || die "No WordPress install found in $(pwd)"

if [[ "$ENV" == "prod" && "$PHASE" == "all" ]]; then
  read -rp "Running the FULL bootstrap against PRODUCTION. Type 'yes' to continue: " confirm
  [[ "$confirm" == "yes" ]] || die "Aborted."
fi

log "Pre-flight backup"
if [[ $DRY -eq 0 ]]; then
  mkdir -p ../backups
  command wp db export "../backups/pre-bootstrap-$(date +%Y%m%d-%H%M%S).sql" --quiet
  ok "Database exported to ../backups/"
fi

# ---------------------------------------------------------------------------
# PHASE 1 — safe on the live site. Fixes the audit's critical findings.
# ---------------------------------------------------------------------------
if [[ "$PHASE" == "1" || "$PHASE" == "all" ]]; then

  # Phase 1 installs a plugin, enables reviews, rewrites the tagline and touches
  # rewrite rules on a store that is taking orders. That earns a prompt of its own.
  if [[ "$ENV" == "prod" && $DRY -eq 0 ]]; then
    read -rp "Running PHASE 1 against PRODUCTION. Type 'yes' to continue: " confirm
    [[ "$confirm" == "yes" ]] || die "Aborted."
  fi

  log "Phase 1 · SEO foundation"
  wp plugin install seo-by-rank-math --activate
  ok "Rank Math installed"

  wp option update rank_math_modules '["seo-analysis","sitemap","rich-snippet","woocommerce","404-monitor","redirections"]' --format=json

  # -------------------------------------------------------------------------
  # De-indexing thin archives.
  #
  # Rank Math stores these in a SERIALISED ARRAY under a HYPHENATED option name
  # (rank-math-options-titles). Its state flags use underscores
  # (rank_math_modules) — the inconsistency is the plugin's, not ours.
  #
  # Two failure modes this block is written to avoid:
  #   1. `wp option patch` on a key Rank Math never reads SUCCEEDS. It writes a
  #      key nothing consults. There is no error to catch, so a wrong key is
  #      invisible. Every key below is taken from the plugin's own installer.
  #   2. `_robots` is INERT unless `_custom_robots` is 'on'. Both, in that order.
  #
  # Rank Math's own installer already defaults post_tag / product_tag /
  # post_format to noindex — but via add_option(), which no-ops when the option
  # already exists. On a site that once had Rank Math, the defaults do NOT
  # reapply. So we set them explicitly and then verify.
  # -------------------------------------------------------------------------
  log "Phase 1 · De-indexing thin archives"

  rm_patch() {  # blob key value [--format=json]
    local blob="$1" key="$2" val="$3"; shift 3
    wp option patch update "$blob" "$key" "$val" "$@" \
      || die "Rank Math patch failed: $blob.$key — investigate before continuing."
  }

  for tax in product_tag post_tag; do
    rm_patch rank-math-options-titles "tax_${tax}_custom_robots" on
    rm_patch rank-math-options-titles "tax_${tax}_robots" '["noindex"]' --format=json
    rm_patch rank-math-options-sitemap "tax_${tax}_sitemap" off
  done

  # Verified against the plugin source: this key is real and lives in the titles blob.
  rm_patch rank-math-options-titles disable_author_archives on

  # Page-builder templates and the portfolio CPT. The portfolio slug is DETECTED,
  # never guessed — the audit says "an empty portfolio section", not which CPT.
  for pt in elementor_library; do
    if command wp post-type list --field=name 2>/dev/null | grep -qx "$pt"; then
      rm_patch rank-math-options-titles "pt_${pt}_custom_robots" on
      rm_patch rank-math-options-titles "pt_${pt}_robots" '["noindex"]' --format=json
      rm_patch rank-math-options-sitemap "pt_${pt}_sitemap" off
      ok "de-indexed CPT $pt"
    fi
  done

  PORTFOLIO_CPT="$(command wp post-type list --public=1 --field=name 2>/dev/null \
                   | grep -iE 'portfolio|project' | head -1 || true)"
  if [[ -n "$PORTFOLIO_CPT" ]]; then
    rm_patch rank-math-options-titles "pt_${PORTFOLIO_CPT}_custom_robots" on
    rm_patch rank-math-options-titles "pt_${PORTFOLIO_CPT}_robots" '["noindex"]' --format=json
    rm_patch rank-math-options-sitemap "pt_${PORTFOLIO_CPT}_sitemap" off
    ok "de-indexed portfolio CPT: $PORTFOLIO_CPT"
  else
    warn "No portfolio-like CPT found. If the audit's 'empty portfolio section' exists,"
    warn "find its slug with: wp post-type list --field=name   and add it here."
  fi
  ok "Thin archives de-indexed"

  # -------------------------------------------------------------------------
  # THE CATALOGUE MUST STILL BE INDEXABLE.
  # The same blob that noindexes 170 tags controls index on 44 products and 12
  # categories. A wrong key here does not look wrong in wp-admin — the damage is
  # only visible in what the pages render. Assert it, do not assume it.
  # -------------------------------------------------------------------------
  if [[ $DRY -eq 0 ]]; then
    for k in pt_product_custom_robots tax_product_cat_custom_robots; do
      v="$(command wp option pluck rank-math-options-titles "$k" 2>/dev/null || echo '')"
      if [[ "$v" == "on" ]]; then
        r="$(command wp option pluck rank-math-options-titles "${k%_custom_robots}_robots" --format=json 2>/dev/null || echo '')"
        [[ "$r" == *noindex* ]] && die "CATALOGUE NOINDEXED: ${k%_custom_robots} is set to noindex. Roll back now."
      fi
    done
    ok "Verified: products and product categories remain indexable"
  fi

  log "Phase 1 · Reviews on"
  wp option update woocommerce_enable_reviews yes
  wp option update woocommerce_enable_review_rating yes
  wp option update woocommerce_review_rating_required yes
  wp option update woocommerce_review_rating_verification_label yes

  # The global switch does NOT open comments on products created with
  # comment_status='closed' — which is the default for CSV-imported catalogues.
  # Without this the reviews tab never renders and WP-01's acceptance criterion
  # fails while every setting reads "on".
  if [[ $DRY -eq 0 ]]; then
    mkdir -p ../backups
    command wp post list --post_type=product --post_status=any \
      --fields=ID,comment_status --format=csv > "../backups/comment-status-$(date +%Y%m%d-%H%M%S).csv"
    CLOSED="$(command wp post list --post_type=product --post_status=any \
              --fields=ID,comment_status --format=csv | grep -c ',closed' || true)"
    if [[ "$CLOSED" -gt 0 ]]; then
      command wp post list --post_type=product --post_status=any --field=ID \
        | xargs -r -n1 -I{} command wp post update {} --comment_status=open --quiet
      ok "Opened comments on $CLOSED products (previous state saved to ../backups/)"
    else
      ok "All products already accept comments"
    fi
  else
    echo "    [dry-run] would open comment_status on products with comments closed"
  fi
  ok "Product reviews enabled — required for aggregateRating and star results"

  log "Phase 1 · Housekeeping"
  if [[ $DRY -eq 0 ]]; then
    command wp option get blogdescription > "../backups/blogdescription-$(date +%Y%m%d-%H%M%S).txt" || true
  fi
  wp option update blogdescription "Instant home-style Indian meals, ready in 6 minutes"

  # Deliberately NOT changing permalink structure here. Phase 1 runs against the live
  # store, and altering the structure rewrites every URL on the site at once.
  CURRENT_PERMALINKS="$(command wp option get permalink_structure 2>/dev/null || echo '')"
  if [[ "$CURRENT_PERMALINKS" != "/%postname%/" ]]; then
    warn "Permalink structure is '${CURRENT_PERMALINKS:-plain}', not /%postname%/."
    warn "Do NOT change it on prod. Change it on staging and generate redirects first."
  else
    ok "Permalink structure already /%postname%/"
  fi

  # Soft flush only. --hard rewrites .htaccess, which on a live managed host can
  # discard hand-added cache, security or redirect rules that nothing here knows about.
  wp rewrite flush
  ok "Rewrite rules flushed (soft — .htaccess untouched, structure unchanged)"

fi

# ---------------------------------------------------------------------------
# PHASE 2+ — staging only. This is the rebuild.
# ---------------------------------------------------------------------------
if [[ "$PHASE" == "all" ]]; then

  log "Phase 2 · Removing the builder stack"
  # 73 JS and 60 CSS files per page came from here. Delete, don't deactivate —
  # deactivated plugins still carry DB rows and an update surface.
  for p in elementor elementor-pro header-footer-elementor essential-addons-for-elementor-lite \
           revslider nasa-core tabs-responsive woocommerce-payments contact-form-7 \
           woo-conditional-product-fees-for-checkout checkout-field-editor-and-manager-for-woocommerce \
           login-with-phone-number; do
    if command wp plugin is-installed "$p" >/dev/null 2>&1; then
      wp plugin deactivate "$p" --quiet || true
      wp plugin delete "$p" --quiet || true
      ok "removed $p"
    fi
  done

  log "Phase 2 · Orphaned metadata"
  # Prefix is resolved through $wpdb inside the script, never guessed here.
  wp eval-file "$(dirname "$0")/clean-elementor-meta.php"
  if [[ $DRY -eq 0 ]]; then
    command wp eval-file "$(dirname "$0")/clean-elementor-meta.php" --apply || warn "postmeta cleanup skipped"
  fi
  ok "Elementor postmeta cleared"

  log "Phase 3 · Theme"
  # Blocksy is NOT installed here. WP-03 made foodify a STANDALONE block theme
  # (templates/index.html is what makes WordPress treat it as one), so there is
  # no parent to install and no Blocksy Pro licence to renew — see
  # docs/WP-03-DECISIONS.md §1.
  if command wp theme is-installed foodify >/dev/null 2>&1; then
    wp theme activate foodify
    ok "Foodify theme active"
    # The address book (WP-05) is a rewrite endpoint registered on init. Rules
    # are cached in an option, so activation must be followed by a flush.
    wp rewrite flush
  elif [[ $DRY -eq 1 ]]; then
    echo "    [dry-run] wp theme activate foodify"
  else
    # Deliberately fatal. The old fallback activated Blocksy and carried on with
    # a warning — which would leave the store rendering a completely different
    # theme, without any of the WP-01/03 fixes, while every later phase reported
    # success. A missing theme is a broken deploy, not a warning.
    die "foodify theme not found in wp-content/themes. Deploy it from git and re-run. Nothing else in this phase has been applied."
  fi

  log "Phase 3 · Plugin set"
  for p in judgeme-product-reviews-woocommerce wp-mail-smtp; do
    wp plugin install "$p" --activate
  done
  # Blocksy Pro is no longer on this list — the standalone theme removed the need
  # for it, and rule 5 says paid plugins are asked about, not assumed.
  warn "Premium plugins install manually: Rank Math Pro, Digits (OTP — needs DLT registration first)"

  log "Phase 4 · Commerce configuration"
  wp option update woocommerce_currency INR
  wp option update woocommerce_currency_pos left
  wp option update woocommerce_price_thousand_sep ','
  wp option update woocommerce_price_decimal_sep '.'
  wp option update woocommerce_price_num_decimals 2
  # Store base address drives the GST intra/inter-state split. The client is at
  # Parx Laureate, Noida 201304 — Uttar Pradesh. 'IN:HR' (Haryana) was in the kit and
  # would invert the split on every order. CONFIRM against the GST registration
  # certificate, not the office address, if they differ.
  wp option update woocommerce_default_country 'IN:UP'
  wp option update woocommerce_allowed_countries specific
  wp option update woocommerce_specific_allowed_countries '["IN"]' --format=json
  wp option update woocommerce_ship_to_countries specific
  wp option update woocommerce_specific_ship_to_countries '["IN"]' --format=json

  # Guest checkout is the default path — no interstitial, no forced account.
  wp option update woocommerce_enable_guest_checkout yes
  wp option update woocommerce_enable_checkout_login_reminder yes
  wp option update woocommerce_enable_signup_and_login_from_checkout no
  wp option update woocommerce_enable_myaccount_registration yes

  # Every charge is disclosed in the cart. Nothing new may appear at checkout.
  wp option update woocommerce_enable_shipping_calc yes
  wp option update woocommerce_shipping_cost_requires_address no
  wp option update woocommerce_cart_redirect_after_add no
  wp option update woocommerce_enable_ajax_add_to_cart yes
  ok "Commerce configured"

  log "Phase 4 · Cash on delivery"
  wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery","description":"Pay in cash when your order arrives.","instructions":"Please keep exact change ready.","enable_for_methods":[],"enable_for_virtual":"no"}' --format=json
  ok "COD enabled — cap and PIN allowlist are set in the Shiprocket step"

  log "Phase 5 · Roles"
  wp role create coupon_partner "Coupon Partner" 2>/dev/null || true
  wp cap add coupon_partner read
  ok "coupon_partner role ready — sees only their own dashboard"

  log "Phase 5 · Pages"
  # Titles are explicit — "${slug^}" turns my-account into "My-account".
  # And a page WooCommerce does not know about is just a page: each one is wired
  # to its woocommerce_*_page_id or the store silently keeps using the old ones.
  declare -A WC_PAGES=( [shop]="Shop" [cart]="Cart" [checkout]="Checkout" [my-account]="My account" )
  declare -A WC_OPTION=( [shop]=woocommerce_shop_page_id [cart]=woocommerce_cart_page_id \
                         [checkout]=woocommerce_checkout_page_id [my-account]=woocommerce_myaccount_page_id )
  for slug in shop cart checkout my-account; do
    PID="$(command wp post list --post_type=page --name="$slug" --field=ID --posts_per_page=1 2>/dev/null | head -1)"
    if [[ -z "$PID" ]]; then
      if [[ $DRY -eq 1 ]]; then
        echo "    [dry-run] wp post create --post_type=page --post_title=\"${WC_PAGES[$slug]}\" --post_name=$slug"
      else
        PID="$(command wp post create --post_type=page --post_status=publish \
               --post_title="${WC_PAGES[$slug]}" --post_name="$slug" --porcelain)"
        ok "created page ${WC_PAGES[$slug]} (#$PID)"
      fi
    fi
    [[ -n "$PID" ]] && wp option update "${WC_OPTION[$slug]}" "$PID"
  done
  ok "Core pages present and wired to WooCommerce"

  # The address book (WP-05) lives on a rewrite endpoint the theme registers on
  # init. Rewrite rules are cached in an option, so a freshly deployed theme has
  # a menu item pointing at a URL that 404s until they are rebuilt. The theme
  # flushes on activation too; this covers a deploy that overwrites theme files
  # without re-activating, which is what a git-based deploy actually does.
  wp rewrite flush
  ok "Rewrite rules flushed (address-book endpoint registered)"

  # ASSERT it, do not assume it. A dead account link is invisible to everyone
  # except the customer who taps it.
  if [[ $DRY -eq 0 ]]; then
    if command wp rewrite list --format=csv 2>/dev/null | grep -q 'address-book'; then
      ok "address-book endpoint present in the rewrite table"
    else
      warn "address-book endpoint NOT in the rewrite table — /my-account/address-book/ will 404."
      warn "Check the foodify theme is the active theme, then re-run: wp rewrite flush"
    fi
  fi

fi

log "Verifying"
if [[ $DRY -eq 0 ]]; then
  command wp option get woocommerce_enable_reviews | grep -q yes && ok "reviews: on" || warn "reviews: OFF"
  command wp option get woocommerce_enable_guest_checkout | grep -q yes && ok "guest checkout: on" || warn "guest checkout: OFF"
  command wp plugin is-active seo-by-rank-math >/dev/null 2>&1 && ok "Rank Math: active" || warn "Rank Math: INACTIVE"
fi

log "Done — env=$ENV phase=$PHASE"
echo "Next: ./taxonomy-cleanup.sh --dry-run   then   ./smoke-test.sh https://letsfoodify.com"
