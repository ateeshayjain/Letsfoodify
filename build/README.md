# Foodify rebuild — build kit

Working files for the letsfoodify.com rebuild. Companion to the Rebuild Scope, the
internal build spec (WP-00…WP-14) and the Design Playbook.

**Context that shapes everything here: one developer, and a host migration inside the
project.** The original ten-week plan assumed a pod of four or five. See
`docs/SOLO-PLAN.md` for the re-sequenced version — it is 15 weeks, not 10.

```
theme/foodify/          Standalone block theme. Deploy from git; never edit on the server.
  theme.json            ALL design tokens. Colour, type, spacing, radius. Single source of truth.
  style.css             Only what theme.json cannot express. Keep it short.
  inc/
    coupon-attribution.php   Partner ownership, notification email, admin column, dashboard widget
    checkout-fields.php      25 fields → 9, email required, state as select, PIN auto-fill
    product-display.php      Prep chip, per-serving price, honest stock, curated cross-sells
    patterns.php             Pattern category registration
    product-attributes.php   Filter attributes, forced non-indexable (WP-02)
    shortcodes.php           Free-shipping progress + Google reviews
    account.php              Account menu, reorder-first order rows, post-purchase claim
    address-book.php         WP-05. Several saved addresses, exactly one default
    otp-throttle.php         WP-05. 5/hour + 30s cooldown, pure, gateway-independent
  patterns/             Block patterns. Pages are assembled from these — no page builder.
  templates/            Block templates: home, product, category, cart, checkout,
                        my-account, page, 404
  parts/                header.html, footer.html

tools/
  render-preview.py     Renders the theme to preview/storefront.html for review.
                        The preview is GENERATED — never hand-edit it.

scripts/
  bootstrap.sh              Configuration as code. The reason cutover is survivable.
  tags-to-attributes.php    Tags → filter attributes. MUST run before the cleanup.
  taxonomy-cleanup.php      170 tags → ~20, redirect-then-delete, reversible noindex
  wp02-verify.sh            WP-02 GATE: static chain/loop analysis, then live checks
  clean-elementor-meta.php  Orphaned postmeta, prefix resolved through $wpdb
  wp01-preflight.sh         Week 1: access, backup, rollback record, baseline, dry-run
  wp01-verify.sh            Week 1 GATE: the four WP-01 acceptance criteria
  smoke-test.sh             CUTOVER gate (WP-14). Fails during WP-01 by design —
                            it asserts COD and nine fields, which arrive in weeks 11-12.

docs/
  SOLO-PLAN.md          Re-sequenced schedule, what gets deferred, weekly cadence
  MIGRATION.md          Host move runbook
  REVIEW-NOTES.md       Known gaps and unverified assumptions — read before you ship
```

## Why configuration lives in a script

The rebuild happens on staging while prod keeps taking orders. At cutover you cannot push
the staging database to prod, because prod has weeks of orders staging has never seen.

Only two things move safely: **files**, and **reproducible configuration**. So every
setting lives in `bootstrap.sh` rather than in someone's memory of an admin screen. Run it
on staging to build the environment; run the same script on prod at cutover.

With one developer and no second pair of eyes, this matters more, not less.

## Order of operations

```bash
# Week 1 — safe against the live site
./scripts/bootstrap.sh --env=prod --phase=1 --dry-run
./scripts/bootstrap.sh --env=prod --phase=1

# Taxonomy, three passes a month apart
wp eval-file scripts/taxonomy-cleanup.php report
wp eval-file scripts/taxonomy-cleanup.php noindex      # wait 30 days
wp eval-file scripts/taxonomy-cleanup.php execute --confirm

# Staging rebuild
./scripts/bootstrap.sh --env=staging

# Before every deploy, and immediately after cutover
./scripts/smoke-test.sh https://letsfoodify.com --redirects=scripts/redirects.csv
```

## Rules

1. **No colour, font size or spacing value is ever hardcoded.** It comes from `theme.json`
   as `var(--wp--preset--*)`. If a value isn't in the token set, add it there first.
2. **The theme is deployed, never edited live.** No file editing in wp-admin.
3. **`bootstrap.sh` is the only place a setting is recorded.** Changed something in the
   admin? Add it to the script in the same sitting or it will not survive cutover.
4. **`smoke-test.sh` must exit 0 before cutover.** It is not advisory.
