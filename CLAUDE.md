# CLAUDE.md — letsfoodify.com rebuild

Written for a future session that has **not** read
`Foodify-Findings-Design-Handover.pdf`. Everything needed to work is here.

---

## 0. Read this first

This repo IS the Foodify project root. `build/` is the kit; `planning/` holds the
pre-engagement scope and the week-1 runbook.

**The build kit has been audited.** Read `build/docs/VERIFICATION-2026-08-25.md`
before `build/docs/REVIEW-NOTES.md` — it records what was checked against primary
sources, what was wrong, and what changed. Items 3, 4 and 6 of REVIEW-NOTES are
closed; the rest are still open.

**Nothing has run against a real WordPress.** Syntax and logic only, except
`build/scripts/smoke-test.sh`, which is behaviour-tested against fixture sites via
`build/tests/selftest.py`. Budget a full day to shake the kit out on staging
before the schedule depends on it.

**letsfoodify.com was unreachable from the workspace this was built in** (egress
proxy, 403 on CONNECT). Every check against the live site has to be run by the
developer on their own machine — including the week-2 cache test. See §7.

### History note

Sessions 1–3 ran inside an unrelated iOS repository, because that was the
workspace available. Everything was moved here in one commit, which kept every
file and none of the reasoning — so the eight original commit messages are
reproduced verbatim in **`docs/HISTORY.md`**. That is now the only copy; the
source branch on `ateeshayjain/TheMoneyApp` is being deleted.

Read `docs/HISTORY.md` when you want to know *why* a call went the way it did.
Read `build/docs/VERIFICATION-2026-08-25.md` when you want to know *what* is
wrong in the kit and what changed.

One thing that cost a session and is worth carrying forward: that repo ignored
`build/` for Xcode artefacts, and the rule matched at any depth, so the entire
kit was silently untracked. `git status` was clean, `git add` said nothing, and
work reported as pushed existed only on disk. **After any commit that should
include kit files, confirm with `git show --stat HEAD` rather than trusting a
clean `git status`.** The absence looked exactly like success — which is the
failure shape this whole audit keeps finding.

---

## 1. Stack

| | |
|---|---|
| Platform | WordPress 7.1 · WooCommerce 11.0.1 |
| Catalogue | 44 products · 12 categories · ~170 tags |
| Payments | Razorpay (`woo-razorpay`). COD does not exist yet — WP-07 |
| Theme today | Elessi + child, Elementor, Elementor Pro, Slider Revolution, Essential Addons, nasa-core. **All six are deleted in WP-03.** |
| Theme target | **Standalone block theme** (no parent — see `build/docs/WP-03-DECISIONS.md`). All tokens in `theme.json` |
| SEO | None installed today. Rank Math (`seo-by-rank-math`) lands in WP-01 |
| Fonts | Fraunces (display), Instrument Sans (UI). Self-hosted — see `assets/fonts/MANIFEST.md` |
| Team | One developer. Fifteen weeks to launch, seventeen to handover |

Baseline to beat (measured 24 Aug 2026): 179 requests, 2.5 MB, 73 JS, 60 CSS,
140 products on the homepage, 25 checkout fields, 0 meta descriptions.

---

## 2. Hard rules — breaking these causes irreversible damage

1. **Production holds the live order history.** Never run anything against prod
   without showing the exact command and getting an explicit yes.
2. **Never push a staging database to production.** Prod will have weeks of
   orders staging has never seen. Configuration moves via
   `scripts/bootstrap.sh`, never via the database. **If you change a setting in
   wp-admin, add it to that script in the same session or it is lost at
   cutover.**
3. **`scripts/smoke-test.sh` is blocking, not advisory.** Non-zero exit means no
   deploy. No overrides.
4. **No colour, font size or spacing value is ever hardcoded.** Everything is
   `var(--wp--preset--*)` from `theme.json`. If a value is not in the token set,
   add it there first.
5. **Ask before installing any paid plugin.** Recurring cost is already at the
   top of the client's tolerance (₹50–93k/yr projected).

### One rule the handover implies but does not state

**An absence check that cannot run looks exactly like an absence check that
passed.** `curl … | grep -c 'Inject JS'` returns `0` both when the leaked
comment is gone and when the request never reached the server. Every
verification in §7 therefore asserts the HTTP status *first*. Apply the same
shape to anything added to `smoke-test.sh`.

---

## 3. Design tokens

Sampled from the packaging artwork, not invented. Contrast ratios computed to
WCAG 2.x relative luminance.

| Token | Hex | Use | Contrast |
|---|---|---|---|
| Flame | `#F07040` | Decorative only | **Fails as text** |
| Flame Ink | `#C4451A` | Every primary button, link, price | 5.0:1 on white · AA |
| Flame Deep | `#B03D14` | Badge/alert text on flame wash | 5.1:1 · AA |
| Leaf | `#4A7818` | Health, veg, progress | 6.2:1 on leaf wash · AA |
| Kraft | `#C6884A` | Section grounds | — |
| Kraft Pale | `#F4EBDF` | Product-image ground (replaces white) | — |
| Charcoal | `#2B2A27` | All body copy | 13.9:1 on Paper · AAA |
| Paper | `#FDFBF7` | Page ground — warm, not clinical white | — |
| Line Strong | `#9C8A6E` | **All form borders** | 3.2:1 · clears the control floor |
| Line | `#E6DCCB` | Dividers only | 1.3:1 · **never a form border** |

White on Charcoal is 14.4:1 (AAA). Muted text on Paper is 4.5:1 — AA at 16px+
only.

**Type scale — nothing off it:** 12 · 13 · 16 · 18 · 20 · 24 · 32 · 40 · 56.
**16px is the floor for anything a customer reads.** The current site runs
11.6px across 437 elements. Prices use tabular numerals.

Fraunces = anything read as a *voice*. Instrument Sans = anything read as an
*interface*.

---

## 4. The five design principles

1. **Six minutes is the product.** Prep method and time are the most prominent
   attribute on every card — ahead of price.
2. **Show the food, not the pouch.** Every product needs a prepared-dish image.
   This is a photography problem, not a design one.
3. **No surprises after the cart.** Shipping, handling and delivery date shown
   before checkout. ₹210 becoming ₹285 at the last screen is the costliest
   current flaw.
4. **Earn trust honestly.** Real reviews, real names, real counts.
5. **Designed at 360px first.** 44px touch targets, 16px body text, no
   exceptions.

---

## 5. Where each work package's code lives

Verified against the unpacked kit, 25 Aug 2026.

| Path | Work packages |
|---|---|
| `theme/foodify/theme.json` | WP-03. All tokens. Single source of truth |
| `theme/foodify/assets/fonts/` | WP-03, WP-04. See `MANIFEST.md` |
| `inc/coupon-attribution.php` | WP-09 (ownership, notification, refund correction, admin column) and WP-10 (dashboard widget, partner endpoint) |
| `inc/checkout-fields.php` | WP-06. 25→9 fields, email required, state select, PIN auto-fill, mobile validation |
| `inc/product-display.php` | WP-03. Prep chip, per-serving price, honest stock, curated cross-sells |
| `scripts/bootstrap.sh` | All config. `--dry-run`, `--phase=1` for live fixes |
| `scripts/taxonomy-cleanup.php` | WP-02. Three passes: report → noindex → execute |
| `scripts/smoke-test.sh` | WP-14. Blocking assertions |
| `docs/REVIEW-NOTES.md` | The kit author's own known gaps |
| `docs/VERIFICATION-2026-08-25.md` | **Read first.** What was verified, what was wrong, what changed |
| `tests/selftest.py` | Proves `smoke-test.sh` catches what it claims. Run before trusting the gate |
| `scripts/clean-elementor-meta.php` | WP-03. Orphaned postmeta, dry-run by default |
| `docs/WP-05-NOTES.md` | Address book, checkout chooser, account claim, OTP rule |
| `docs/WP-06-NOTES.md` | Checkout shell, the no-cache privacy control, honest totals |
| `tests/checkout-test.php` | 15 assertions. The cart may only promise what it knows |
| `docs/WP-07-NOTES.md` | Prepaid saving, COD rules, and the GST question left open |
| `tests/payments-test.php` | 34 assertions. The label and the fee are one calculation |
| `tests/address-test.php` | 51 assertions. The "exactly one default" invariant |
| `tests/otp-test.php` | 24 assertions. OTP limits, tested before the gateway exists |
| `docs/SOLO-PLAN.md`, `docs/MIGRATION.md` | Schedule and cutover notes |

### Work package index

`WP-00` access · `WP-01` live emergency fixes · `WP-02` taxonomy ·
`WP-03` design system + front-end · `WP-04` performance budget ·
`WP-05` OTP login + address book · `WP-06` checkout rebuild ·
`WP-07` payments + COD · `WP-08` reviews + Google Business Profile ·
`WP-09` coupon attribution · `WP-10` admin dashboard ·
`WP-11` shipping/GST/fulfilment · `WP-12` content + Merchant Center ·
`WP-13` analytics · `WP-14` QA, launch, rollback

---

## 6. Verified facts about third-party plugins

Confirmed by reading plugin source on 25 Aug 2026. Do not re-derive from memory
or blog posts — both are wrong on the first item.

### Rank Math option names use HYPHENS

```
rank-math-options-titles      ← settings blob (serialized array)
rank-math-options-general
rank-math-options-sitemap
rank-math-options-instant-indexing
```

but state flags use **underscores**: `rank_math_modules`,
`rank_math_version`, `rank_math_known_post_types`, `rank_math_redirections`.

Confirmed on the write path (`includes/class-installer.php`) and the read path
(`includes/settings/class-settings.php`). `rank_math_options_titles` with
underscores — the form nearly every tutorial uses — **creates a new, ignored
option and fails silently.**

Because the value is a serialized array, `wp option update` replaces the whole
blob. Use:

```bash
wp option patch update rank-math-options-titles pt_product_title '%title% %sep% %sitename%'
```

Setting keys inside the titles blob:
`pt_{post_type}_title`, `pt_{post_type}_description`,
`pt_{post_type}_robots` (array), `pt_{post_type}_custom_robots` (`on`/`off`),
`tax_{taxonomy}_title`, `tax_{taxonomy}_robots`, `tax_{taxonomy}_custom_robots`.
Sitemap blob: `pt_{post_type}_sitemap`, `tax_{taxonomy}_sitemap`.

**`_robots` is gated by `_custom_robots`.** Setting `tax_product_tag_robots`
to `['noindex']` while `tax_product_tag_custom_robots` is `off` does nothing.
Always set both.

### Rank Math already noindexes tags on fresh install

`get_taxonomy_defaults()` sets `is_custom => 'on'` and `robots => ['noindex']`
for `post_tag`, `post_format` and `product_tag`. So WP-01's "de-index 170 tag
archives" is largely satisfied by installing the plugin.

**Caveat that matters:** defaults are written with `add_option()`, which is a
no-op when the option already exists. If Rank Math was ever installed and
removed on this site, the defaults will **not** reapply. Always verify the live
value rather than assuming the install did it.

### Razorpay does not need `billing_last_name`

`woo-razorpay` reads it in exactly one place — building `prefill.name` for the
checkout modal:

```php
'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
```

It is display-only. Not used in signature verification, not used in capture,
not a required API field. An empty surname yields `"Ateeshay "` with a trailing
space, which Razorpay accepts. **Removing the field does not break payment.**

Keep it anyway, and the kit now does: `foodify_split_name()` in
`inc/checkout-fields.php` splits the full-name field on
`woocommerce_checkout_create_order` and `woocommerce_customer_save_address`.
The customer still sees nine fields.

### Instrument Sans has no rupee glyph

U+20B9 is absent from every cmap subtable. Prices render ₹ from the platform
fallback. Legible, but the symbol will not match the digits beside it, and the
type system is not signed off until someone looks at it on a real device.
Fraunces does have it. See `build/docs/VERIFICATION-2026-08-25.md` §A4.

---

## 7. Commands run repeatedly

Every check asserts status **before** grepping, per §2.

```bash
# --- Cache state (the week-2 hosting decision) -------------------------
# -S surfaces proxy/DNS errors; without it a blocked request looks like a miss.
curl -sSI -o /tmp/h.txt -w '%{http_code}\n' https://letsfoodify.com/ \
  && grep -i 'hcdn-cache\|x-cache\|cf-cache-status\|x-litespeed-cache' /tmp/h.txt \
  || echo 'REQUEST FAILED — not a cache miss'
# Must be a clean, cookie-free request. A cart cookie legitimately forces bypass.

# --- Leaked developer comment (WP-01) ---------------------------------
curl -sS -o /tmp/ma.html -w '%{http_code}\n' https://letsfoodify.com/my-account/ \
  && grep -c 'Inject JS' /tmp/ma.html

# --- Config as code ---------------------------------------------------
bash scripts/bootstrap.sh --env=staging --dry-run          # ALWAYS first
bash scripts/bootstrap.sh --env=staging --phase=1
bash scripts/bootstrap.sh --env=prod    --phase=1 --dry-run # show me before running

# --- WP-02 taxonomy — ORDER MATTERS, see planning/WP-02-RUNBOOK.md ----
# Weeks 1-2: migrate FIRST (deleting tags destroys the relationships it reads),
# then noindex to start the 30-day clock.
wp eval-file scripts/tags-to-attributes.php report
wp eval-file scripts/tags-to-attributes.php execute --confirm
wp eval-file scripts/taxonomy-cleanup.php report
wp eval-file scripts/taxonomy-cleanup.php noindex      # 30-day clock starts here
wp eval-file scripts/taxonomy-cleanup.php undo-noindex # reverses it
# Week 14: redirect-then-delete, then gate.
wp eval-file scripts/taxonomy-cleanup.php execute --confirm
bash scripts/wp02-verify.sh https://letsfoodify.com --redirects=scripts/redirects.csv
bash tests/wp02-map-selftest.sh

# --- WP-04 performance -----------------------------------------------
php tests/perf-test.php               # script-deferral rule (11 assertions)
# NOTE: smoke-test.sh covers JS/CSS counts and HTML weight only. Requests <=55
# and page weight <=900KB need Lighthouse; LCP/INP/CLS need field data 48h
# after launch. See build/docs/WP-04-NOTES.md.

# --- WP-03 front end --------------------------------------------------
python3 tools/render-preview.py       # regenerate preview/storefront.html from the theme

# --- WP-01, week 1 ----------------------------------------------------
bash scripts/wp01-preflight.sh https://letsfoodify.com   # read-only + backup + dry-run
bash scripts/wp01-verify.sh    https://letsfoodify.com   # the WP-01 gate
python3 tests/wp01-selftest.py                            # prove that gate works

# --- Gates (blocking) — TWO of them, for different weeks ---------------
# wp01-verify.sh  = week 1.  smoke-test.sh = CUTOVER (WP-14).
# smoke-test.sh asserts nine checkout fields, COD and the WP-04 asset budget —
# none of which exist before week 10, so it fails by design during WP-01.
bash scripts/smoke-test.sh https://staging.letsfoodify.com; echo "exit=$?"
python3 tests/selftest.py          # prove the gate works before trusting it
GATE=scripts/smoke-test.sh.orig python3 tests/selftest.py   # reproduces the old fail-open

# --- Rank Math inspection ---------------------------------------------
wp option get rank-math-options-titles --format=json | python3 -m json.tool
wp option get rank_math_modules --format=json
wp option pluck rank-math-options-titles tax_product_tag_custom_robots

# --- Backups ----------------------------------------------------------
wp db export "backup-$(date +%Y%m%d-%H%M%S).sql"
```

### Definition of done — the whole project

Every acceptance criterion passes · performance budget met on **field** data 48h
after launch · zero critical Search Console errors at 14 days · checkout
completion at or above the pre-launch baseline over 14 days · a partner coupon
order triggers a correct email within 5 minutes, verified live · the client can
independently add a product, issue a coupon, assign a partner, read yesterday's
revenue and fulfil an order · runbook delivered, one training session done ·
30-day monitoring closed with a final report.

### Rollback triggers (WP-14)

Checkout error rate above 2%, **or** LCP above 4s, sustained 30 minutes.
Old environment stays live and paid for 30 days. Cutover Tuesday–Thursday
02:00–06:00 IST. **Never Friday.**
