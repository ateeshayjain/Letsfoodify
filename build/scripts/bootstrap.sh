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
  # THE claim. The hero H1 renders this option through <!--FOODIFY_TAGLINE-->,
  # so the number the whole site restates lives here and nowhere else.
  # tests/claim-test.py reads this exact line.
  wp option update blogdescription "6 minute mein ghar ka khana ready!"

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
  # woocommerce-pdf-invoices-packing-slips (WP Overnight, free, GPL) gives the
  # customer a downloadable GST invoice from My account and attaches it to the
  # order email. Slug checked against the plugin's own repository, 7 Nov 2026.
  for p in judgeme-product-reviews-woocommerce wp-mail-smtp woocommerce-pdf-invoices-packing-slips; do
    wp plugin install "$p" --activate
  done
  # Its settings are NOT set here: the option keys have not been checked against
  # the installed plugin, and a wrong key fails silently (REVIEW-NOTES item 3).
  # Set them once in wp-admin, then copy them into this script (rule 2).
  warn "PDF invoices: set GSTIN, registered address, invoice numbering and 'attach to Processing + Completed emails' in WooCommerce → PDF Invoices, then add those options here"
  # Blocksy Pro is no longer on this list — the standalone theme removed the need
  # for it, and rule 5 says paid plugins are asked about, not assumed.
  warn "Premium plugins install manually: Rank Math Pro, Digits (OTP — needs DLT registration first)"

  log "Phase 4 · Commerce configuration"
  wp option update woocommerce_currency INR
  wp option update woocommerce_currency_pos left
  wp option update woocommerce_price_thousand_sep ','
  wp option update woocommerce_price_decimal_sep '.'
  wp option update woocommerce_price_num_decimals 2
  # The shop's default order is what a first-time visitor sees before touching
  # "Sort by". Popularity puts the reorder favourites first; menu_order is
  # whatever the importer happened to number.
  wp option update woocommerce_default_catalog_orderby popularity
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

  log "Phase 4 · Shipping zones"
  # The free-shipping BAR on the cart reads its threshold from the method that
  # would actually apply (inc/shortcodes.php) rather than from a constant. Until
  # now no method was ever created, so the bar has been reading nothing.
  #
  # SAFE TO WRITE, unlike `wp option update`: a `wp wc` subcommand that does not
  # exist FAILS with a non-zero exit. That is a positive failure surface, so a
  # wrong command name is loud here in a way a wrong OPTION name never is.
  if [[ $DRY -eq 0 ]]; then
    ZID="$(command wp wc shipping_zone list --format=ids --user=1 2>/dev/null | head -1)"
    if [[ -z "$ZID" ]]; then
      ZID="$(command wp wc shipping_zone create --name="India" --order=1 --porcelain --user=1 2>/dev/null || echo '')"
      [[ -n "$ZID" ]] && ok "created shipping zone India (#$ZID)" || warn "could not create the shipping zone — create it in wp-admin and re-run"
    else
      ok "shipping zone present (#$ZID)"
    fi

    if [[ -n "$ZID" ]]; then
      # THE TRAP inc/shortcodes.php already documents and nothing ever set.
      # WooCommerce compares free shipping's min_amount against the subtotal
      # BEFORE discounts unless ignore_discounts is 'no'. Get it backwards and a
      # coupon either falsely qualifies the customer for free shipping or falsely
      # un-qualifies them — and they find out at the payment step, which is the
      # exact "no surprises after the cart" promise this build is built on.
      # Customer titles (decision 6, UX fix wave 8 Nov 2026 — review BUY-15):
      # one name per thing, the customer's word. WooCommerce's defaults are
      # "Free shipping" and "Flat rate", and "Flat rate" is a term of art the
      # customer then reads next to a ₹59 charge.
      #
      # IDEMPOTENT: a re-run used to create a second free-shipping and a second
      # flat-rate method in the same zone. Now an existing method is updated in
      # place (titles and settings) and only a missing one is created.
      ship_method() {   # ship_method <method_id> <settings-json> <ok-message>
        local mid="$1" settings="$2" msg="$3" iid
        iid="$(command wp wc shipping_zone_method list "$ZID" --fields=instance_id,method_id --format=csv --user=1 2>/dev/null \
          | awk -F, -v m="$mid" '$2==m {print $1; exit}')"
        if [[ -n "$iid" ]]; then
          command wp wc shipping_zone_method update "$ZID" "$iid" --settings="$settings" --user=1 >/dev/null 2>&1 \
            && ok "$msg (updated #$iid)" || warn "$mid method #$iid not updated — check it by hand"
        else
          command wp wc shipping_zone_method create "$ZID" --method_id="$mid" --settings="$settings" --user=1 >/dev/null 2>&1 \
            && ok "$msg" || warn "$mid method not created — check it by hand"
        fi
      }
      ship_method free_shipping '{"title":"Free delivery","requires":"min_amount","min_amount":"599","ignore_discounts":"no"}' \
        'Free delivery over ₹599 (compared AFTER coupons, before the box discount)'
      ship_method flat_rate '{"title":"Delivery","cost":"59"}' \
        'Delivery ₹59 below the threshold'
      # Flat rate is HIDDEN once free delivery applies — a woocommerce_package_rates
      # filter in inc/checkout-flow.php (review BUY-3). Without it the zone offers
      # both, WooCommerce keeps a previously chosen method, and a customer told
      # "Free delivery unlocked" can still be charged ₹59.

      # ASSERT, do not assume. A zone with no methods silently offers no shipping
      # at all, and an empty checkout shipping section reads as a theme bug.
      METHODS="$(command wp wc shipping_zone_method list "$ZID" --format=count --user=1 2>/dev/null || echo 0)"
      if [[ "${METHODS:-0}" -ge 2 ]]; then
        ok "zone has $METHODS shipping methods"
      else
        warn "zone has ${METHODS:-0} shipping methods — checkout will offer no delivery option"
      fi
    fi
  else
    echo "    [dry-run] wp wc shipping_zone create --name=India"
    echo "    [dry-run] wp wc shipping_zone_method create|update <id> free_shipping title='Free delivery' (ignore_discounts=no)"
    echo "    [dry-run] wp wc shipping_zone_method create|update <id> flat_rate title='Delivery'"
  fi

  log "Phase 4 · Cash on delivery"
  wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery","description":"Pay in cash when your order arrives.","instructions":"Please keep exact change ready.","enable_for_methods":[],"enable_for_virtual":"no"}' --format=json
  # WAS: "cap and PIN allowlist are set in the Shiprocket step". THERE WAS NO
  # SHIPROCKET STEP — the same shape as the `wp foodify coupons reconcile`
  # command WP-09 found promised and never written. Both now live in code:
  # the COD cap in inc/payments.php (shipping uncapped by default — a commercial
  # decision the client has not taken), and PIN serviceability in
  # inc/fulfilment.php, where an EMPTY allowlist means everywhere rather than
  # nowhere.
  ok "COD enabled — cap: inc/payments.php · PIN serviceability: inc/fulfilment.php"

  log "Phase 5 · Roles"
  wp role create coupon_partner "Coupon Partner" 2>/dev/null || true
  wp cap add coupon_partner read
  ok "coupon_partner role ready — sees only their own dashboard"

  # WP-10's Shop Staff role is created and RECONCILED by the theme (inc/roles.php),
  # not here. Deliberately: capabilities live in the wp_user_roles option, so
  # `wp role create` is a no-op on a site where the role already exists — which
  # means a tightened capability set would never reach a live site. The theme
  # versions the set and syncs it. This step only VERIFIES the result.
  if [[ $DRY -eq 0 ]]; then
    if command wp role exists foodify_shop_staff >/dev/null 2>&1; then
      ok "Shop Staff role present"
      # A positive check for each capability that must be absent. Enumerated,
      # because "does not have admin" is an absence claim and absence claims
      # pass without being checked.
      LEAKED=""
      for CAP in manage_woocommerce install_plugins activate_plugins edit_users \
                 promote_users export delete_shop_orders edit_products unfiltered_html; do
        if command wp cap list foodify_shop_staff 2>/dev/null | grep -qx "$CAP"; then
          LEAKED="$LEAKED $CAP"
        fi
      done
      if [[ -n "$LEAKED" ]]; then
        warn "Shop Staff holds capabilities it must never have:$LEAKED"
        warn "Bump FOODIFY_ROLES_VERSION in inc/roles.php and reload any admin page to resync."
      else
        ok "Shop Staff holds no forbidden capability"
      fi
    else
      warn "Shop Staff role missing — activate the foodify theme, then load any admin page once."
    fi
  fi

  # Order-status email copy (WP-10). READ ONLY, on purpose.
  #
  # `wp option update` on a name nothing reads SUCCEEDS — that is how this project
  # shipped invented Rank Math sub-keys. So rather than writing WooCommerce email
  # settings from a name I could not verify offline, this REPORTS what is actually
  # stored. If a line comes back empty, the option name is wrong or the email has
  # never been customised, and either way you now know before pasting anything.
  # The copy to paste is in build/docs/WP-10-NOTES.md.
  if [[ $DRY -eq 0 ]]; then
    log "Order-status email settings (read-only report)"
    for E in customer_processing_order customer_completed_order customer_on_hold_order; do
      SUBJ="$(command wp option get "woocommerce_${E}_settings" --format=json 2>/dev/null || echo '')"
      if [[ -z "$SUBJ" ]]; then
        warn "  woocommerce_${E}_settings — not stored (never customised, or the name is wrong)"
      else
        ok   "  woocommerce_${E}_settings — $SUBJ"
      fi
    done
  fi

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

# ==== LANE A settings (delivery-estimate, stock-alerts, offers) ====
# Three options, all CLIENT POLICY, none written here until the client confirms
# a value (docs/FEATURES-A-NOTES.md has the suggested values to confirm). Each
# feature is simply absent while its option is unset; this block only reports.
#
# When confirmed, set them like this (shape only — the numbers are the client's):
#   wp option update foodify_delivery_zones --format=json '{"zones":[{"label":"<zone>",
#     "states":["<WC state code>"],"pin_prefixes":["<digits>"],"min_days":<n>,"max_days":<n>}],
#     "cutoff_hour":<0-23 IST>,"no_dispatch_weekdays":[<1=Mon..7=Sun>],"no_delivery_weekdays":[],
#     "holidays":["YYYY-MM-DD"]}'
#   wp option update foodify_stock_alert_retention_days <days>
#   wp option update foodify_public_offer <COUPON CODE>     # must be a live WooCommerce coupon
if [[ "$PHASE" == "all" ]]; then
  log "Lane A · Product page: delivery dates, stock alerts, offers"
  if [[ $DRY -eq 0 ]]; then
    if command wp option get foodify_delivery_zones >/dev/null 2>&1; then
      ok "foodify_delivery_zones is set — product pages show the PIN delivery check, and checkout REFUSES any PIN outside these zones (check every state you deliver to is listed)"
    else
      warn "foodify_delivery_zones NOT set — no delivery check on product pages, and checkout refuses no PIN (client to confirm zones, transit days, cut-off, dispatch days)"
    fi
    if command wp option get foodify_stock_alert_retention_days >/dev/null 2>&1; then
      ok "foodify_stock_alert_retention_days is set — unsent stock alerts expire"
    else
      warn "foodify_stock_alert_retention_days NOT set — unsent stock alerts are kept until cancelled (client to confirm a period)"
    fi
    if command wp option get foodify_public_offer >/dev/null 2>&1; then
      ok "foodify_public_offer is set — its coupon is listed under Offers while it is live"
    else
      ok "foodify_public_offer unset — no coupon is advertised on product pages"
    fi
  else
    echo "    [dry-run] wp option get foodify_delivery_zones / foodify_stock_alert_retention_days / foodify_public_offer"
  fi
fi
# ==== END LANE A ====
# ==== LANE B settings (search, buy-again, order-actions) ====
log "Lane B · search, buy again, cancel and returns"
# Search needs no setting: the index is built from the catalogue and rebuilt on
# any product change. Hindi/regional names go in each product's
# _foodify_search_keywords meta (see docs/FEATURES-B-NOTES.md).
# Returns are a POLICY number and the client has not given one. Unset, the
# account says "Contact us about a problem with your order". Never default it.
if [[ $DRY -eq 0 ]] && command wp option get foodify_return_window_days >/dev/null 2>&1; then
  ok "returns window: $(command wp option get foodify_return_window_days) days"
else
  warn "foodify_return_window_days NOT SET — online return requests are off (client to confirm the window, then: wp option update foodify_return_window_days <days>)"
fi
# ==== END LANE B ====
# ==== LANE C settings (box-builder) ====
# Build-your-own box (inc/box-builder.php) and review photos (inc/reviews.php).
#
# foodify_box_sizes is CLIENT POLICY — box sizes and their percent off — and is
# NOT written here. Unset, /build-a-box/ says boxes are coming soon and sells
# nothing. When the client confirms, set it like this (shape only):
#   wp option update foodify_box_sizes --format=json '{"<packs>":<percent off>, ...}'
# (docs/FEATURES-C-NOTES.md has the suggestion to confirm.)
if [[ "$PHASE" == "all" ]]; then
  log "Lane C · Build-a-box page, review photos"

  # The page. Its template is templates/page-build-a-box.html, chosen by slug,
  # so the page needs no content of its own.
  BOX_PID="$(command wp post list --post_type=page --name=build-a-box --field=ID --posts_per_page=1 2>/dev/null | head -1 || true)"
  if [[ -n "$BOX_PID" ]]; then
    ok "page /build-a-box/ present (#$BOX_PID)"
  elif [[ $DRY -eq 1 ]]; then
    echo "    [dry-run] wp post create --post_type=page --post_title=\"Build your own box\" --post_name=build-a-box"
  else
    BOX_PID="$(command wp post create --post_type=page --post_status=publish \
               --post_title="Build your own box" --post_name=build-a-box --porcelain)"
    ok "created page Build your own box (#$BOX_PID)"
  fi

  if [[ $DRY -eq 0 ]]; then
    BOX_SIZES="$(command wp option get foodify_box_sizes --format=json 2>/dev/null || true)"
    if [[ -n "$BOX_SIZES" && "$BOX_SIZES" != "[]" && "$BOX_SIZES" != '""' ]]; then
      ok "foodify_box_sizes = $BOX_SIZES — /build-a-box/ sells boxes"
    else
      warn "foodify_box_sizes not set — /build-a-box/ says 'coming soon'. Pending client: box sizes and % off."
    fi

    # Review photos are up to 5 MB each, three per review. PHP's own limits
    # refuse a larger upload before the theme sees it; the theme then reports
    # "larger than 5 MB", which is wrong if the real cap is PHP's 2 MB default.
    UPL="$(command wp eval 'echo ini_get("upload_max_filesize")."|".ini_get("post_max_size")."|".(function_exists("imagewebp")?"webp":"nowebp");' 2>/dev/null || true)"
    if [[ -n "$UPL" ]]; then
      ok "PHP upload_max_filesize|post_max_size|GD WebP = $UPL (need >= 5M | >= 16M | webp)"
    else
      warn "could not read PHP upload limits — check upload_max_filesize >= 5M and post_max_size >= 16M by hand"
    fi
  fi
fi
# ==== END LANE C ====
# ==== LANE D settings (whatsapp, repeat-orders, cart-recovery, referrals) ====
# Every value here is CLIENT POLICY or a running cost, so this block WRITES
# NOTHING — it reports. Each feature is absent from the storefront until its
# options are complete (docs/FEATURES-D-NOTES.md has the suggested values).
#
# When the client confirms, set them like this (shape only — values are theirs):
#   # 9 WhatsApp — credentials go in wp-config.php ONLY, never an option:
#   #   define( 'FOODIFY_WA_TOKEN', '…' ); define( 'FOODIFY_WA_PHONE_ID', '…' );
#   wp option update foodify_whatsapp_templates --format=json \
#     '{"order_confirmed":"<approved name>","order_shipped":"<approved name>","repeat_reminder":"<approved name>"}'
#   wp option update foodify_whatsapp_language <code, e.g. en>
#   wp option update foodify_whatsapp_graph_version <vNN.N from the Meta app dashboard>
#   wp option update foodify_whatsapp_enabled yes          # only after the per-message cost is approved
#   # 12 repeat orders
#   wp option update foodify_repeat_reminder_days <1-7>
#   wp option update foodify_repeat_discount <percent>     # leave unset for no discount
#   # 13 cart reminder
#   wp option update foodify_cart_reminder_delay_minutes <15-1440>
#   # 14 referrals — all four, or the feature does not exist
#   wp option update foodify_referral_give <rupees>; wp option update foodify_referral_get <rupees>
#   wp option update foodify_referral_min_order <rupees>; wp option update foodify_referral_monthly_cap <n, 0 = no cap>
if [[ "$PHASE" == "all" ]]; then
  log "Lane D · Retention: WhatsApp, repeat orders, cart reminder, referrals"
  if [[ $DRY -eq 0 ]]; then
    lane_d_opt() {   # $1 option, $2 what it switches on — reports set/unset, never the value of a secret
      if command wp option get "$1" >/dev/null 2>&1; then ok "$1 is set — $2"
      else warn "$1 NOT set — $2 stays OFF (client to confirm)"; fi
    }
    for C in FOODIFY_WA_TOKEN FOODIFY_WA_PHONE_ID; do
      if command wp config has "$C" --type=constant >/dev/null 2>&1; then ok "$C defined in wp-config.php"
      else warn "$C not in wp-config.php — WhatsApp updates stay OFF"; fi
    done
    lane_d_opt foodify_whatsapp_templates        "WhatsApp template names"
    lane_d_opt foodify_whatsapp_language         "WhatsApp template language"
    lane_d_opt foodify_whatsapp_graph_version    "WhatsApp Cloud API version"
    if [[ "$(command wp option get foodify_whatsapp_enabled 2>/dev/null || true)" == "yes" ]]; then
      ok "foodify_whatsapp_enabled = yes — Meta bills per message; confirm the client approved the cost"
    else
      warn "foodify_whatsapp_enabled is not 'yes' — WhatsApp updates OFF (needs the client's OK on Meta's per-message charge)"
    fi
    lane_d_opt foodify_repeat_reminder_days      "repeat orders"
    if command wp option get foodify_repeat_discount >/dev/null 2>&1; then ok "foodify_repeat_discount is set — copy reads 'Subscribe & save'"
    else warn "foodify_repeat_discount NOT set — repeat orders carry no discount and say 'Repeat this order' (client to confirm)"; fi
    lane_d_opt foodify_cart_reminder_delay_minutes "the abandoned-cart reminder"
    for O in foodify_referral_give foodify_referral_get foodify_referral_min_order foodify_referral_monthly_cap; do
      lane_d_opt "$O" "referrals (all four needed)"
    done
    # The two account screens are rewrite endpoints; assert them, a dead menu link is invisible to everyone but the customer.
    for EP in repeat-orders refer-a-friend; do
      if command wp rewrite list --format=csv 2>/dev/null | grep -q "$EP"; then ok "$EP endpoint present in the rewrite table"
      else warn "$EP endpoint NOT in the rewrite table — run: wp rewrite flush (with the foodify theme active)"; fi
    done
  fi
fi
# ==== END LANE D ====

log "Verifying"
if [[ $DRY -eq 0 ]]; then
  command wp option get woocommerce_enable_reviews | grep -q yes && ok "reviews: on" || warn "reviews: OFF"
  command wp option get woocommerce_enable_guest_checkout | grep -q yes && ok "guest checkout: on" || warn "guest checkout: OFF"
  command wp plugin is-active seo-by-rank-math >/dev/null 2>&1 && ok "Rank Math: active" || warn "Rank Math: INACTIVE"
  command wp plugin is-active woocommerce-pdf-invoices-packing-slips >/dev/null 2>&1 && ok "PDF invoices: active" || warn "PDF invoices: INACTIVE — customers cannot download a GST invoice"
fi

log "Done — env=$ENV phase=$PHASE"
echo "Next: ./taxonomy-cleanup.sh --dry-run   then   ./smoke-test.sh https://letsfoodify.com"
