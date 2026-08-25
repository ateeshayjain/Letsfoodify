# WP-01 — Emergency fixes to the LIVE site

Week 1. Six changes, all landing on production while it takes orders. There is
no staging copy of the outcome to admire first — that is what makes this the
riskiest package in the project despite being the smallest.

Ordered so that the reversible, self-contained fixes land first and the one
change that can damage the whole site lands last, behind its own gate.

> **Updated 25 Aug 2026, after the kit audit.** Two things changed since this was
> written and both matter:
>
> **The gate for WP-01 is `wp01-verify.sh`, not `smoke-test.sh`.** smoke-test.sh
> is the *cutover* gate (WP-14): it asserts nine checkout fields (WP-06, week 11),
> COD (WP-07, week 12) and the WP-04 asset budget (week 10). In week 1 the live
> site has 25 fields, no COD and 73 JS files, so smoke-test.sh returns four
> blocking failures on a perfectly correct WP-01. A gate that cannot pass either
> stalls the work or teaches you to ignore it, and on a solo build the gate is
> what stands in for code review.
>
> **Step 5's Rank Math commands are now in `bootstrap.sh --phase=1`**, with
> assertions this document did not have — every patch is checked, the portfolio
> CPT is detected rather than guessed, and the catalogue is verified still
> indexable before the script exits. Run the script; use the commands below only
> to *inspect* what it did. Steps 1-4 stay manual: they are theme options and
> page content that differ per install.
>
> Start with `scripts/wp01-preflight.sh` — it asserts access, takes and
> size-checks the backup, records every value WP-01 overwrites, captures the
> baseline and URL inventory, and dry-runs phase 1.

---

## The one thing that can go badly wrong

Step 5 sets `noindex` on ~170 tag archives. The same option blob controls
`index` on 44 products and 12 categories. **A wrong key noindexes the entire
catalogue, and nothing in wp-admin will look wrong** — Rank Math will show the
setting you intended, because you set it; the damage is in what the pages
actually render.

Google can drop a noindexed store within days and takes weeks to restore it.
So step 5 has a mandatory post-check that asserts products are *still indexable*,
and it is the last thing done, after everything else is verified.

---

## Pre-flight — do not skip

**Run `bash scripts/wp01-preflight.sh https://letsfoodify.com` first** — it does
everything in this section and refuses rather than warns. The commands are kept
below so you can see what it is doing and run any of them on their own.

```bash
# 1. Full backup, restore-tested. WP-00 requires this; WP-01 depends on it.
wp db export "prod-preWP01-$(date +%Y%m%d-%H%M%S).sql"
tar -czf "prod-preWP01-uploads-$(date +%Y%m%d).tgz" wp-content/

# 2. Prove the restore, on a staging copy — a dump file is not a backup
#    until it has been restored once.
wp db import prod-preWP01-<stamp>.sql   # on STAGING only

# 3. Record the baseline so "we broke it" is answerable later.
curl -sS -o baseline-home.html  -w '%{http_code}\n' https://letsfoodify.com/
curl -sS -o baseline-acct.html  -w '%{http_code}\n' https://letsfoodify.com/my-account/
curl -sS -o baseline-sitemap.xml -w '%{http_code}\n' https://letsfoodify.com/wp-sitemap.xml
wp option get woocommerce_enable_reviews > baseline-reviews.txt
wp plugin list --format=csv > baseline-plugins.csv
wp theme  list --format=csv > baseline-themes.csv

# 4. Dry-run the whole phase on STAGING first.
bash scripts/bootstrap.sh --env=staging --dry-run
bash scripts/bootstrap.sh --env=staging --phase=1
bash scripts/smoke-test.sh; echo "exit=$?"
```

**Do not proceed to production until `wp01-verify.sh` exits 0 on staging.**
(`smoke-test.sh` will still fail there — see the note at the top. That is
expected in week 1, not a regression.)

---

## Step 1 — Remove the leaked developer comment

`// 3. Inject JS step-switching script to show only one box at a time` renders
as visible body text above the login box on `/my-account/`.

### Find it first — it is not necessarily in a template

```bash
# Most likely: page content, a widget, a theme option, or Elementor data.
wp post list --post_type=page --field=ID | \
  xargs -n1 -I{} sh -c 'wp post get {} --field=content | grep -l "Inject JS" >/dev/null 2>&1 && echo "page {}"'

wp db query "SELECT option_name FROM $(wp db prefix --allow-root)options
             WHERE option_value LIKE '%Inject JS step-switching%';"
wp db query "SELECT post_id, meta_key FROM $(wp db prefix --allow-root)postmeta
             WHERE meta_value LIKE '%Inject JS step-switching%';"
grep -rn "Inject JS step-switching" wp-content/themes/ wp-content/plugins/ 2>/dev/null
```

### Change
Delete the comment line from wherever it is found. If it sits inside a PHP
template it is a genuine stray `//` inside HTML output — delete the line. If it
is in page content or postmeta, edit that value only.

**Record the exact before-value first:**
```bash
wp post get <ID> --field=content > rollback/step1-page<ID>.html
# or
wp option get <name> --format=json > rollback/step1-<name>.json
```

### Verify
```bash
curl -sS -o /tmp/ma.html -w '%{http_code}\n' https://letsfoodify.com/my-account/ \
  && grep -c 'Inject JS' /tmp/ma.html
```
Must print `200` then `0`. **If the status line is not 200, the `0` means
nothing** — the acceptance criterion as written in the handover
(`curl -s … | grep -c`) cannot tell a fixed page from an unreachable one.

### Rollback
```bash
wp post update <ID> --post_content="$(cat rollback/step1-page<ID>.html)"
# or restore the option from the saved JSON
```

---

## Step 2 — Remove the fake urgency counter and duplicate testimonials

"70 people are viewing this right now" on product pages; three testimonials all
attributed to SUJATA on the homepage.

### Change
Find the source before disabling anything:
```bash
grep -rn "people are viewing\|are viewing this" wp-content/themes/ wp-content/plugins/ 2>/dev/null
wp db query "SELECT option_name FROM $(wp db prefix --allow-root)options
             WHERE option_value LIKE '%people are viewing%';"
```

Most likely a theme option in Elessi/nasa-core rather than a separate plugin.
**Turn off the specific option — do not deactivate nasa-core in week 1.** That
theme still renders the live store until WP-03 replaces it in weeks 3–9.

Testimonials: edit the homepage section to keep one real testimonial or none.
Inventing two more is not an option — WP-08 brings real reviews in week 16.

### ⚠ This may not be server-side
The handover's Part 5 records *"an unexplained string injected into the rendered
product page that is not present in the server's HTML — something client-side is
writing to live product pages."* The viewer counter is a strong candidate for
being exactly that.

**Test:** compare raw HTML against rendered DOM.
```bash
curl -sS https://letsfoodify.com/product/<slug>/ | grep -c 'are viewing'
```
If this returns `0` but the browser shows the counter, the source is a
client-side script — a tag-manager container, an injected third-party script, or
a compromised asset. **Stop and tell me.** That is a WP-13 tag-audit finding and
possibly a security incident, not a week-1 widget toggle.

### Verify
```bash
curl -sS -o /tmp/p.html -w '%{http_code}\n' https://letsfoodify.com/product/<slug>/ \
  && grep -c 'are viewing' /tmp/p.html          # expect 200 then 0
```
Plus one real browser load of a product page and the homepage — this fix is
partly visual and curl cannot see client-side rendering.

### Rollback
Re-enable the theme option. Restore the homepage section from the page revision
(`wp post list --post_type=revision --parent=<home_id>`).

---

## Step 3 — Enable product reviews

### Change
```bash
wp option update woocommerce_enable_reviews yes
wp option update woocommerce_enable_review_rating yes
wp option update woocommerce_review_rating_verification_label yes
```

**The gotcha that makes this look broken:** the global setting does not open
comments on products that were created with `comment_status = closed` — which is
the default for most CSV/importer-created catalogues. The reviews tab will still
not render.

```bash
# How many are actually closed?
wp post list --post_type=product --post_status=publish \
  --fields=ID,comment_status --format=csv | grep -c closed

# Open them (records the before-state first)
wp post list --post_type=product --post_status=publish \
  --fields=ID,comment_status --format=csv > rollback/step3-comment-status.csv
wp post list --post_type=product --post_status=publish --field=ID | \
  xargs -n1 -I{} wp post update {} --comment_status=open
```

### Verify
```bash
curl -sS -o /tmp/p.html -w '%{http_code}\n' https://letsfoodify.com/product/<slug>/ \
  && grep -ci 'reviews' /tmp/p.html
```
Then load a product page and confirm a **Reviews tab renders**. That is the
handover's actual acceptance criterion, and only a browser can confirm it.

### Rollback
```bash
wp option update woocommerce_enable_reviews no
awk -F, 'NR>1 && $2=="closed"{print $1}' rollback/step3-comment-status.csv | \
  xargs -n1 -I{} wp post update {} --comment_status=closed
```

---

## Step 4 — Broken shipping label and the footer year

### Change
The shipping line renders as `.: ₹55.00` — a shipping-zone method whose title is
`.` or carries a stray character.

```bash
wp wc shipping_zone list --user=1 --format=table
wp wc shipping_zone_method list <zone_id> --user=1 --format=json  # read title
```
Fix the method title in WooCommerce → Settings → Shipping → the zone.
**Then add it to `scripts/bootstrap.sh` in this same session** — hard rule 2. A
shipping title set only in wp-admin is lost at cutover, and it reappears as the
same bug on the new site.

Footer `© 2025` → dynamic year. This is a throwaway fix (WP-03 deletes the
theme) but the site is live for another fourteen weeks.

### Verify
```bash
curl -sS -o /tmp/c.html -w '%{http_code}\n' https://letsfoodify.com/cart/ \
  && grep -o '\.\s*:\s*₹' /tmp/c.html | wc -l        # expect 0
date +%Y                                              # compare to footer
curl -sS https://letsfoodify.com/ | grep -o '© *[0-9]\{4\}'
```
Cart needs an item — do this in a browser session, or with a cart cookie.

### Rollback
Restore the original method title. Revert the footer template change.

---

## Step 5 — Rank Math, meta, and de-indexing ⚠ HIGHEST RISK

Do this **last**, after steps 1–4 are verified.

### 5a. Install
```bash
wp plugin install seo-by-rank-math --activate
wp option get rank_math_modules --format=json      # note: UNDERSCORES here
```

### 5b. Check what the install already did — before changing anything

Rank Math's installer sets `post_tag`, `product_tag` and `post_format` to
`custom_robots => 'on'`, `robots => ['noindex']` **by default**. If this is a
first install, the tag de-indexing may already be done.

```bash
wp option pluck rank-math-options-titles tax_product_tag_custom_robots   # want: on
wp option pluck rank-math-options-titles tax_product_tag_robots          # want: [noindex]
wp option pluck rank-math-options-titles tax_post_tag_custom_robots
wp option pluck rank-math-options-titles tax_post_tag_robots
```

**Note the hyphens.** `rank_math_options_titles` with underscores is a
different, ignored option — that is the silent failure the handover warns about,
and it is the form nearly every tutorial online uses.

Defaults are written with `add_option()`, which is a **no-op if the option
already exists**. If Rank Math was ever installed and removed on this site, the
defaults will not reapply and you must set them explicitly.

### 5c. Let the script set them

`bootstrap.sh --phase=1` now applies all of the below, asserting each patch and
dying on the first failure — `wp option patch` on a key Rank Math never reads
*succeeds*, so an unasserted run cannot tell you it did nothing. It also verifies
the catalogue is still indexable before exiting.

Run the script. The commands here are the equivalent by hand, for inspection or
for a one-off repair:

```bash
# Only for keys 5b showed as wrong or absent:
wp option patch update rank-math-options-titles tax_product_tag_custom_robots on
wp option patch update rank-math-options-titles tax_product_tag_robots --format=json '["noindex"]'

# Author archives, page-builder templates, portfolio CPT:
wp option patch update rank-math-options-titles disable_author_archives on
wp option patch update rank-math-options-titles pt_elementor_library_custom_robots on
wp option patch update rank-math-options-titles pt_elementor_library_robots --format=json '["noindex"]'
# Get the real portfolio CPT slug first — do not guess:
wp post-type list --public=1 --fields=name,label --format=table
```

**`_robots` is inert unless `_custom_robots` is `on`.** Always set both, in that
order.

### 5d. Title and description templates
```bash
wp option patch update rank-math-options-titles pt_product_title       '%title% %sep% %sitename%'
wp option patch update rank-math-options-titles pt_product_description '%excerpt%'
```

**`%excerpt%` renders empty for a product with no short description.** The
acceptance criterion is *"every product URL returns a non-empty meta
description"* — a template alone does not guarantee it.

```bash
# Find products that will render an empty description:
wp post list --post_type=product --post_status=publish --field=ID | while read id; do
  [ -z "$(wp post get $id --field=excerpt)" ] && echo "EMPTY EXCERPT: $id $(wp post get $id --field=post_title)"
done
```
Every ID printed needs a hand-written short description or a per-post
`rank_math_description`. That is content work — budget for it; it is the real
cost of this step, not the plugin install.

### 5e. MANDATORY post-check — the catalogue is still indexable

```bash
# Products and categories MUST NOT be noindex.
for u in "https://letsfoodify.com/product/<slug>/" \
         "https://letsfoodify.com/product-category/<cat>/" \
         "https://letsfoodify.com/"; do
  echo "--- $u"
  curl -sS -o /tmp/x.html -w 'HTTP %{http_code}\n' "$u" \
    && grep -io '<meta name="robots"[^>]*>' /tmp/x.html
done
```
**Any `noindex` on a product, a category or the homepage: stop and roll back
immediately.** Do not "fix it forward" — restore the option blob.

A tag archive, by contrast, *should* now show `noindex`:
```bash
curl -sS https://letsfoodify.com/product-tag/<some-tag>/ | grep -io '<meta name="robots"[^>]*>'
```

### 5f. Sitemap swap
```bash
curl -sS -o /dev/null -w 'core sitemap: %{http_code}\n' https://letsfoodify.com/wp-sitemap.xml
curl -sS -o /dev/null -w 'rank math:   %{http_code}\n' https://letsfoodify.com/sitemap_index.xml
```
Want core `404`/`301` and Rank Math `200`.

### 5g. Social tags
```bash
curl -sS https://letsfoodify.com/product/<slug>/ | \
  grep -cioE '<meta (property="og:|name="twitter:)'      # want >= 4
curl -sS https://letsfoodify.com/product/<slug>/ | \
  grep -io '<meta name="description"[^>]*>'              # want non-empty
```

### Rollback for the whole of step 5
```bash
wp option get rank-math-options-titles --format=json > rollback/step5-titles.json   # BEFORE editing
# restore:
wp option update rank-math-options-titles --format=json < rollback/step5-titles.json
# nuclear:
wp plugin deactivate seo-by-rank-math      # core sitemap returns, meta reverts to none
```
Deactivating restores the pre-WP-01 state exactly — the site had no SEO plugin
to begin with, so there is nothing to lose by backing out.

---

## Step 6 — Run bootstrap on production

**Hard rule 1.** Show me the command and get a yes before this runs.

```bash
# 1. Dry-run against prod and read every line of the diff:
bash scripts/bootstrap.sh --env=prod --phase=1 --dry-run

# 2. Only after I have said yes:
bash scripts/bootstrap.sh --env=prod --phase=1

# 3. Blocking gate — the WP-01 one:
bash scripts/wp01-verify.sh https://letsfoodify.com; echo "exit=$?"
```

If `wp01-verify.sh` exits non-zero, the deploy is not done — it is failed. Roll
back to the pre-flight snapshot rather than patching forward under time pressure.

Its own self-test proves it catches what it claims, including a catalogue
accidentally set to noindex:

```bash
python3 tests/wp01-selftest.py     # 25 assertions across 4 fixture sites
```

Anything changed in wp-admin during steps 1–5 that is **not** yet in
`bootstrap.sh` must be added now, before the session ends. At cutover only files
and this script survive.

---

## Acceptance criteria — the handover's four, restated as runnable checks

All four are checked by `wp01-verify.sh`, which walks **every** product for
criterion 2 rather than sampling one — a product with an empty short description
renders an empty meta description, and sampling is exactly how that passes.

| # | Criterion | Check |
|---|---|---|
| 1 | No leaked comment on `/my-account/` | `curl -sS -o /tmp/a -w '%{http_code}\n' …/my-account/ && grep -c 'Inject JS' /tmp/a` → `200`, `0` |
| 2 | Every product URL has a non-empty meta description and ≥4 social tags | loop all 44 slugs; assert status 200 first |
| 3 | `wp-sitemap.xml` no longer 200; SEO plugin's sitemap does | step 5f |
| 4 | A product page renders a reviews tab | browser check, step 3 |

Criterion 2 across all 44:
```bash
wp post list --post_type=product --post_status=publish --field=url | while read u; do
  code=$(curl -sS -o /tmp/pp.html -w '%{http_code}' "$u")
  [ "$code" != "200" ] && { echo "FETCH FAIL $code $u"; continue; }
  d=$(grep -io '<meta name="description" content="[^"]\+"' /tmp/pp.html | wc -l)
  s=$(grep -cioE '<meta (property="og:|name="twitter:)' /tmp/pp.html)
  [ "$d" -lt 1 ] || [ "$s" -lt 4 ] && echo "FAIL desc=$d social=$s $u"
done; echo "sweep complete"
```

---

## Decisions carried out of WP-01

**Keep `billing_last_name` when WP-06 rebuilds checkout.** Razorpay reads it in
exactly one place — building `prefill.name` for the payment modal — and an empty
surname yields a harmless trailing space. Payment does not break. But the
customer-visible field count is nine either way, so removing the *data* field
buys nothing the acceptance criterion measures, while WP-11's courier API and
the not-yet-chosen GST invoice plugin both commonly read it. Register it,
hide it, populate it from a split of the full-name field. Ten lines now against
a long tail discovered in week 12.

**Do not delete a single tag in week 1.** WP-02 is explicit: noindex, wait 30
days, *then* execute and emit redirects. Deleting early forfeits the redirect
map and creates the soft-404s the criteria forbid.
