# Kit verification — 25 Aug 2026

The build kit arrived syntax-checked only. This records what was checked against
primary sources, what was wrong, and what changed. `REVIEW-NOTES.md` items 3, 4
and 6 are closed; 1, 2, 5, 7, 8, 9 remain open and are restated at the end.

Nothing here has been run against a real WordPress. Syntax, logic and — for
`smoke-test.sh` — behaviour against fixture sites. Not the same as working.

---

## A1 · Plugin slugs and Rank Math option keys — **3 of 4 keys were wrong**

Verified by reading the plugin's own source, not documentation:
`includes/class-installer.php` (write path) and
`includes/settings/class-settings.php` (read path).

The **blob names in the kit were right** — `rank-math-options-titles` with
hyphens, which is the form most tutorials get wrong. The **sub-keys were
invented**:

| `bootstrap.sh` wrote | Real? | Correct form |
|---|---|---|
| `noindex_product_tag` | ✗ no such key | `tax_product_tag_custom_robots` = `on` **and** `tax_product_tag_robots` = `["noindex"]` |
| `noindex_portfolio` | ✗ no such key | `pt_<cpt>_custom_robots` + `pt_<cpt>_robots` |
| `disable_author_archives` | ✓ **real** | unchanged |
| `exclude_post_types` (sitemap) | ✗ no such key | `pt_<cpt>_sitemap` = `off`, `tax_<tax>_sitemap` = `off` |

Rank Math's only real `noindex_*` keys are `noindex_empty_taxonomies`,
`noindex_search`, `noindex_archive_subpages`, `noindex_password_protected`.

**Why this could never have been caught by `--dry-run`.** `wp option patch
update` on a key the plugin never reads **succeeds**. It writes a key nothing
consults. There is no error, no warning, and wp-admin shows the setting you
intended because you set it. The kit then compounded it with
`2>/dev/null || true`, so even a genuine failure printed "✓ Thin archives
de-indexed".

Two further traps now documented in the script:

1. **`_robots` is inert unless `_custom_robots` is `on`.** Setting the robots
   array alone does nothing.
2. **Rank Math already noindexes `post_tag` / `product_tag` / `post_format` on a
   fresh install** — but via `add_option()`, which no-ops when the option
   already exists. On a site that once had Rank Math, the defaults do **not**
   reapply. Verify the live value; never assume either way.

**Also fixed in phase 1:**
- Portfolio CPT is now **detected** (`wp post-type list`), not guessed. The
  audit says "an empty portfolio section", never which CPT.
- Added an assertion that **products and product categories are still
  indexable** after the tag work. The same blob controls both; a wrong key
  noindexes the catalogue and nothing in wp-admin looks wrong.
- `--env=prod --phase=1` now prompts. Only `--phase=all` did, so the live-site
  phase — which installs a plugin and touches rewrite rules — ran unattended.
- `wp rewrite flush --hard` → soft flush. `--hard` rewrites `.htaccess`, which
  on a managed host can discard hand-added cache, security or redirect rules.
- Reviews: the global switch does **not** open comments on products created with
  `comment_status='closed'`, the default for CSV-imported catalogues. Without
  this the reviews tab never renders and WP-01's acceptance criterion fails
  while every setting reads "on". Now opens them, saving prior state to
  `../backups/`.
- `woocommerce_default_country` was `IN:HR` (Haryana). The client is in Noida —
  **Uttar Pradesh**. Store base state drives the GST intra/inter-state split, so
  this inverted it on every order. Now `IN:UP`. **Confirm against the GST
  registration certificate**, not the office address, if they differ.
- Core pages: `${slug^}` produced "My-account", and a created page WooCommerce
  is not pointed at does nothing. Now titled properly and wired to
  `woocommerce_*_page_id`.

## A2 · `billing_last_name` — **Razorpay is safe; keep the field anyway**

`woo-razorpay` reads it in exactly one place, building `prefill.name` for the
payment modal:

```php
'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
```

Display only. Not in signature verification, not in capture, not a required API
field. An empty surname yields `"Priya "` — accepted. **Payment does not break.**

But the kit `unset()` the field without doing what `REVIEW-NOTES` item 4
prescribes, so the stored surname was simply gone. The customer sees nine fields
either way — the acceptance criterion counts *visible* fields — so removing the
stored surname buys nothing and costs a long tail: WooCommerce's own
`get_formatted_billing_full_name()`, the WP-11 courier payload and every GST
invoice plugin read it, and each degrades quietly.

`foodify_split_name()` now splits on `woocommerce_checkout_create_order` and on
`woocommerce_customer_save_address`. First word is the given name, remainder the
surname; a single-word name leaves the surname empty, which is correct — many
Indian customers have exactly one name and inventing a second is worse than
storing none. Shipping now mirrors billing (`shipping_last_name` was still split
while billing showed one "Full name" field).

## A3 · PIN lookup — **works as written, but had three real bugs**

`api.postalpincode.in` could not be reached from this environment (egress
policy), so **availability is unverified**. Behaviour was reviewed and fixed:

1. **Stale city and state.** The original filled only *empty* fields, so
   correcting a mistyped PIN left the previous city and state in place — wrong
   shipping zone, wrong GST, nothing visibly broken. Now overwrites whenever the
   PIN itself changes.
2. **State matching by display text.** WooCommerce and the API disagree on
   Odisha/Orissa, Puducherry/Pondicherry, Uttarakhand/Uttaranchal and several
   union territories, so those silently left the state unset. Now matches on
   WooCommerce state **codes** via an alias table, falling back to text.
3. **One request per keystroke.** `input` and `change` both fired. Now debounced
   250 ms.

The endpoint is now filterable, so swapping to a bundled dataset or a
same-origin proxy is one line and touches no other file:

```php
add_filter( 'foodify_pincode_endpoint', fn() => home_url( '/wp-json/foodify/v1/pin/' ) );
```

Returning `''` disables the lookup and leaves manual entry.

**Offline dataset — not built, deliberately.** No authoritative PIN dataset was
reachable from this environment. A PIN→state table written from memory would be
wrong at exactly the boundaries that matter (UP/Uttarakhand, MP/Chhattisgarh,
AP/Telangana, Bihar/Jharkhand all split states sharing prefixes), and a wrong
state breaks GST — the same failure class this audit exists to catch. Effort
once a source is chosen: **half a day** to ingest ~19,300 PINs, ship state as a
~10 KB prefix table plus district as a lazy-loaded chunk, and a REST route
behind the filter above. Sources worth using: India Post's own PIN directory on
data.gov.in, or the GeoNames `IN.zip` postal export.

## A4 · Fonts — **added, and the design system needs a decision**

`theme.json` referenced `Fraunces-Variable.woff2` and
`InstrumentSans-Variable.woff2`; neither existed, and a missing file falls back
to Georgia and system sans with no warning.

Both are now in `theme/foodify/assets/fonts/`, sourced from the upstream Google
Fonts repository (not the CSS API, which serves per-subset files under different
names and — with a default curl UA — serves TTF rather than woff2). Subset to
Latin + Latin-1 + Latin Ext-A + punctuation + currency, variable axes preserved,
SHA-256 pinned in `SHA256SUMS`.

| File | Axes | Size |
|---|---|---|
| `Fraunces-Variable.woff2` | `opsz 9..144`, `wght 100..900`, `SOFT 0..100`, `WONK 0..1` | 162 KB |
| `InstrumentSans-Variable.woff2` | `wdth 75..100`, `wght 400..700` | 82 KB |

**Two decisions before WP-03 signs off:**

1. **Instrument Sans has no rupee glyph.** U+20B9 is absent from every cmap
   subtable — it carries `$` and `€` but not `₹`. The UI font is specified for
   "tabular numerals for all prices", so **every price on the store renders its
   ₹ from a fallback font**. It degrades to the platform sans (Roboto, SF, Segoe
   — all have it), so it is legible rather than broken, but the symbol will not
   match the digits beside it. Fraunces *does* have ₹. Options: accept the
   platform fallback, serve ₹ from Fraunces via a `unicode-range` face, or
   render prices in Fraunces. **This needs a visual check on a real device
   before the type system is signed off.**
2. **Fraunces at 162 KB is 18% of the 900 KB page budget.** Pinning `SOFT` and
   `WONK` to chosen values drops it to **90 KB — a 45% saving** — at the cost of
   runtime control over the two axes that make Fraunces "soft". If the design
   uses a single SOFT value, pin it.

Total installed font weight: **245 KB of the 900 KB budget.**

---

## Found while verifying — not in the four

### `smoke-test.sh` had the bug it exists to catch — **now proven fixed**

The gate is blocking, so its own correctness is load-bearing. It was wrong in
both directions.

**Fail-open.** `FETCH()` returns an empty string on any failure, and every
"must be gone" assertion is an absence check. `grep` over an empty body reports
absence, so a site that was **completely down** printed:

```
  PASS no fake viewer counter
  PASS no leaked source comment
```

**False negative on a real defect.** The product URL was taken from the homepage
without resolving relative hrefs, so on a theme emitting relative product links
the product page was never fetched — and the fake viewer counter was reported
absent **on a site that still had it**.

**False failures on a correct build.** `/checkout/` was fetched with no cart, and
WooCommerce renders no payment methods and no form for an empty cart. So COD and
Razorpay were reported missing on a healthy store, while the field count passed
vacuously at zero. Separately the field regex `name="billing_[a-z_]+"` never
matched `billing_address_1` or `_2` — a field-counting gate that could not count
two of the fields. And the footer-year check hardcoded "stale" as ≤2024, so it
silently starts passing in 2027.

All fixed, plus a cart-seeding session, a `©`/`&copy;` footer match, and guards
so an unmeasured page fails rather than passes.

**`tests/selftest.py` proves it.** Three fixture sites — healthy, defective,
unreachable — asserting what the gate reports. Run it before trusting the gate:

```bash
python3 tests/selftest.py                              # 13 passed · 0 failed
GATE=scripts/smoke-test.sh.orig python3 tests/selftest.py   # 7 passed · 6 failed
```

The second command is kept deliberately: it reproduces the original fail-open
against the as-shipped gate, so the bug stays demonstrable rather than becoming
a claim in a document.

### `coupon-attribution.php` — refund path debited partners who were never credited

The credit path applies a single-winner rule: with two partner coupons on one
order, the largest discount wins and the rest are audit-only. `REVIEW-NOTES`
records fixing the double-count there.

**The refund path did not apply it.** It looped every partner coupon on the
order and debited each the full refund amount. On a two-coupon order, the losing
partner — never credited — went **negative**, and was emailed a correction for a
sale they were never told about.

The rule now lives in one function, `foodify_attributed_coupons()`, called by
both paths; the inline copy is gone. The refund path also returns early unless
the order actually carries the notified flag, so a refund on an order that never
reached `processing` cannot reverse a credit that was never made.

**Left alone, flagged for a decision:** a refund reverses `revenue` but not
`orders` or `units`. A partial refund arguably should not; a full refund
probably should. The handover says only "refund reverses the totals". Decide
before WP-09 ships.

### The two handover PDFs are content-identical

`FoodifyFindingsDesignHandover.pdf` and `…_1.pdf` differ only in pagination
(19 pages vs 18). No content drift. Either is safe to work from.

---

## Still open from REVIEW-NOTES

| # | Item | State |
|---|---|---|
| 1 | Hosting rests on a cart-cookie-polluted cache reading | **Open.** letsfoodify.com is unreachable from this environment; the developer must run the clean-session test. See `CLAUDE.md` §7 — and note the published one-liner cannot tell "no cache header" from "request failed". |
| 2 | Client documents still say ten weeks | **Open.** Client-side, not code. |
| 3 | Plugin slugs and option keys unverified | **Closed** — above. |
| 4 | `billing_last_name` may break payment | **Closed** — above. |
| 5 | Third-party PIN API needs a privacy line | **Open.** Endpoint is now swappable and documented; the privacy-policy line and client agreement are still owed. |
| 6 | Fonts missing from the repo | **Closed** — above, with two decisions attached. |
| 7 | Recurring cost never totalled | **Open.** Client-side. |
| 8 | Nothing has run against a real WordPress | **Open, and still the biggest risk.** Budget the full day on staging. |
| 9 | Stock counts only fire if stock is managed per SKU | **Open.** Data question for the client. |

---

# Second pass — the rest of the kit

`taxonomy-cleanup.php`, `product-display.php`, `patterns.php`, both block
patterns, `style.css`, the whole of `theme.json`, `clean-elementor-meta.php`,
`README.md`, `SOLO-PLAN.md`, `MIGRATION.md`. Everything in the kit has now been
read.

## `taxonomy-cleanup.php` — the delete/redirect order guaranteed a 404 window

**The worst finding of this pass.** `execute` wrote `redirects.csv`, deleted
every doomed term, and *then* told you to import the CSV into Rank Math by hand.
Between the delete and the import — however long that takes, and it is a manual
step that can simply be forgotten — **all ~150 tag URLs return 404**. WP-02's own
criterion is "zero soft-404s in Search Console after 14 days", and this was the
single most likely thing to break it.

A redirect installed while the term still exists is harmless: Rank Math fires it
before the archive renders. So the order is now inverted — the redirect goes in
**first**, via Rank Math's own `Redirections\DB::add()`, and a term is deleted
only once its redirect is confirmed present. If the Redirections module is not
active the script **refuses to run** rather than deleting into a void, and names
the two ways forward. `--redirects-already-installed` acknowledges a manual
import.

Also fixed:

- **`noindex` was irreversible.** It rewrote `rank_math_robots` term meta on ~150
  terms with no record of what was there. Prior values are now saved to an option
  and a new `undo-noindex` mode restores them.
- **Nothing reconciled `KEEP_MIN` with the acceptance criterion.** The rule is
  "five or more products"; the criterion is "20 or fewer archives remain". A
  catalogue where 40 tags clear the threshold would pass the script and fail the
  criterion silently. `report` now warns when the survivor count exceeds 20.

Left as-is, deliberately: the script handles `product_tag` only, while the audit
counts page-builder templates, a portfolio CPT and author archives among the thin
pages. Those are handled by the Rank Math settings in `bootstrap.sh` phase 1, not
here — but nothing says so, so it is easy to assume this script covers all 170.

## `style.css` — two layout bugs and the hard rule broken in the file that states it

**`min-height: 44px` was applied to every `<a>` on the site.** `min-height` does
nothing to a non-replaced inline element, so inline links in prose were unaffected
— but every anchor that is block or inline-block was forced to 44px, which is most
of a navigation, every footer link list, every breadcrumb. WCAG 2.5.8 explicitly
exempts links inline in a sentence; the target-size floor belongs on controls.
Now scoped to buttons, nav items, account links and product-card links, with
`inline-flex` centring so the height actually contains the label.

**`display: block` was applied to `<table>` itself** for horizontal scrolling. That
drops the element out of table layout, so column widths collapse and cells stop
aligning — most visibly in the cart on desktop. Scrolling now sits on the wrapper.

**The file's own header says "do not hardcode colours, type sizes or spacing" and
then hardcodes all three:** `#EFE8DC` for the progress track, `.8125rem` twice,
`1rem` twice, and radii of 8/12/999px plus off-scale 6px and 7px spacing.

The reason is structural, not carelessness: **there was nowhere to put them.**
`theme.json` has no border-radius preset system — `settings.border.radius: true`
only toggles the editor control — so a radius could not be a token. `settings.custom`
was `null`. That is the correct home, since it emits `--wp--custom--*`:

```
--wp--custom--tap-target      44px
--wp--custom--ease            cubic-bezier(.2,.8,.2,1)
--wp--custom--radius--control 8px      inputs, selects
--wp--custom--radius--card    12px     product tiles, step cards, imagery
--wp--custom--radius--pill    999px    chips, progress, buttons
--wp--custom--track           #EFE8DC  progress groove
```

`style.css` and both patterns now reference tokens throughout. The only remaining
literals are `1px`/`1.5px` hairlines, `2px`/`3px` focus rings and `50%` — border
and outline widths, which the token set does not model and the rule does not name.

## `hero.php` — an empty `src` on the LCP element

`<img src="" alt="Bowl of Dal Makhni…"/>`. An empty `src` makes the browser
**re-request the current document** — a second full page load, on the homepage's
largest element, on the page most advertising traffic lands on. It would have
quietly eaten a request and a chunk of LCP from the WP-04 budget the same kit
sets. Replaced with a labelled placeholder group until the week-3 shoot lands.

The cover block also hardcoded `#F4EBDF`, which *is* the `kraft-pale` token — so
the hero had silently stopped tracking the palette. Now `"overlayColor":"kraft-pale"`.

## `product-display.php` — "In stock" overwrote "Available on backorder"

The availability filter returned a flat `In stock` for anything managed and in
stock. WooCommerce reports **"Available on backorder"** for a product that is
purchasable but not on the shelf, and this replaced that with a claim that it is
in stock — telling the customer something untrue, in the module whose stated
purpose is removing dishonest social proof. It now returns WooCommerce's own text
unless it has something genuinely better to say, and the low-stock threshold is a
named constant rather than a bare `5`.

The rating-html guard `$html || ! is_shop() && ! is_product_category()` parses as
intended, but is one edit away from the classic precedence bug. Parenthesised.

**Flagged, not changed:** the per-serving line is appended to
`woocommerce_get_price_html`, which also renders in the cart and mini-cart. That
may be desirable or may read as clutter beside a line total — a design call, not
a defect.

## `theme.json` — `customTemplates` advertised a template that cannot exist

It declared `page-landing`, but `templates/` is empty **and** `customTemplates` is
a block-theme feature while this is a classic child of Blocksy — so it does nothing
at all, and would mislead anyone reading the file for what exists. Removed until
there is a template behind it.

Otherwise the file is the strongest thing in the kit: `appearanceTools`,
root-padding-aware alignments, a complete palette, a fluid type scale, a nine-step
spacing scale, and element styles that correctly use the preset variables. The
`has-4-xl-font-size` class in the patterns is right, too — WordPress kebab-cases
`4xl` to `4-xl` for the class while the CSS variable keeps the raw slug, and it is
an easy thing to get wrong.

## `clean-elementor-meta.php` — clean

Prefix through `$wpdb`, `$wpdb->prepare` on both queries, reports before it
deletes, dry-run by default. No changes.

One caller-side note: `bootstrap.sh` invokes it twice in a non-dry run — once
through the dry-run-aware wrapper, once with `--apply`. That is report-then-apply
and works, but it reads like a mistake.

## Documentation conflicts worth resolving

- **`README.md` claims `theme.json` holds "Colour, type, spacing, radius".** It
  held no radius until this pass. Now true.
- **The noindex pass has no home in the schedule.** WP-02 says "Weeks 1–2,
  executed week 14" and the 30-day wait is what makes that safe — but
  `SOLO-PLAN.md` week 1 lists only access, backups, phase-1 hotfixes and the host
  shortlist, and week 14 is where `execute` appears. **If `noindex` is not run in
  weeks 1–2, the 30-day clock never starts** and week 14 either slips or executes
  without the cooling period. Put it in week 1.
- **`SOLO-PLAN.md` week 2 assumes the host migration happens**; the handover and
  `REVIEW-NOTES` item 1 say it may not be needed at all. If the clean cache test
  returns `HIT`, week 2 empties and the schedule gains a week — which is the week
  the handover says is recoverable. Worth stating as a branch rather than a plan.
- **`MIGRATION.md` names the current host: Hostinger, PHP 8.2.30, their own hCDN
  edge.** That is where the `hcdn-cache` header in the cache test comes from, and
  it is the only place in the kit that says so.

## What is still not covered by anything in the kit

`templates/` and `parts/` are empty, so every template WP-03 lists — product,
category, cart, checkout, home, account — is still to be built. That matches the
schedule (weeks 5–9) and is not a defect, but the kit is a token system, three
feature modules and two patterns, not a theme yet.
