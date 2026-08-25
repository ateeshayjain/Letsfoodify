# WP-02 — Taxonomy and information architecture

170 indexable tag archives serving 44 products, plus page-builder templates, a
portfolio CPT and an author list. Google's crawl budget is spent on pages that
will never rank, competing with the product pages that should.

**Spans weeks 1–14.** Not because the work is long, but because the safe path has
a 30-day wait in the middle. Everything below is ordered, and the order is the
whole package.

---

## The order, and why each step cannot move

```
week 1-2   migrate tags -> attributes      LAST CHANCE to read the relationships
week 1-2   noindex the doomed tags          starts the 30-day clock
           ...30 days...                    Google drops them cleanly
week 14    execute: redirect, then delete   redirect FIRST, always
week 14    verify                           chains and loops before soft-404s
```

**1 · Migration is irreversible-by-omission.** `tags-to-attributes.php` reads
*which products carry which tag*. Delete the tag and that relationship goes with
it — nothing left on the site records that a product was vegan. The migration
has exactly one chance. `taxonomy-cleanup.php execute` now **refuses to run**
until it finds populated attribute terms, so this cannot be got wrong by
accident.

**2 · The 30-day clock has to start in week 1.** WP-02 says "weeks 1–2, executed
week 14", and the wait is what makes week 14 safe. `SOLO-PLAN.md` week 1 does not
list it — it lists access, backups and the phase-1 hotfixes. **Put the noindex
pass in week 1 or week 14 either slips or executes with no cooling period.**

**3 · Redirect before delete.** Rank Math fires a redirect before the archive
renders, so installing it while the term still exists is harmless. Deleting
first leaves ~150 URLs at 404 until a manual import that can be forgotten —
against this package's own "zero soft-404s" criterion.

---

## Week 1–2

### Step 1 — See what the live data says

```bash
wp eval-file scripts/taxonomy-cleanup.php report
```

Derives the mapping from the products that actually carry each tag: a tag on one
product redirects to that product, otherwise to the category most of them share.
It warns if more than 20 tags would survive — `KEEP_MIN` is the rule, 20 is the
criterion, and nothing reconciled them before.

Check the real tag names against the migration map:

```bash
wp term list product_tag --fields=name,count --format=table
```

`foodify_attribute_map()` in `theme/foodify/inc/product-attributes.php` matches
tags by name. If the live names differ from the guesses in there — and they
will — **edit the map before migrating**. `tags-to-attributes.php report` lists
every mapping that matched nothing.

### Step 2 — Migrate the useful tags into attributes

```bash
wp eval-file scripts/tags-to-attributes.php report          # nothing is written
wp eval-file scripts/tags-to-attributes.php execute --confirm
```

Vegan, gluten-free, Jain, millet, high-protein and prep method become
WooCommerce attributes driving layered navigation on `/shop/`.

**The trap this closes:** `pa_*` taxonomies are **public by default**. Migrating
without `inc/product-attributes.php` deletes 170 indexable tag archives and
creates a fresh set of indexable attribute archives — the same crawl-budget
problem wearing a different URL. That file forces `public => false`, so
`/pa_dietary/vegan/` 404s while `?filter_dietary=vegan` keeps working.

Confirm it:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://letsfoodify.com/pa_dietary/vegan/   # want 404
```

Then add the **Filter by Attribute** blocks (prep, dietary) to the shop sidebar,
or the filtering exists in the database and nowhere the customer can reach.

### Step 3 — Noindex, and start the clock

```bash
wp eval-file scripts/taxonomy-cleanup.php noindex
```

Records every prior `rank_math_robots` value to the option
`foodify_taxonomy_noindex_prior` first. To reverse before week 14:

```bash
wp eval-file scripts/taxonomy-cleanup.php undo-noindex
```

**Diarise week 14 now.** The wait is the mechanism.

### Step 4 — Category copy (150–300 words each)

Twelve categories, unique copy each. Content work, not a script — and it is what
makes the retained archives worth keeping rather than thin pages of a different
kind. Start it in week 1; it does not block anything and it always slips.

---

## Week 14

### Step 5 — Execute

```bash
wp db export "../backups/pre-taxonomy-$(date +%Y%m%d-%H%M%S).sql"
wp eval-file scripts/taxonomy-cleanup.php execute --confirm
```

Per doomed tag: install the 301 through Rank Math's Redirections API, confirm it
landed, **then** delete the term. A term whose redirect did not install is left
in place and reported. Without the Redirections module the script refuses rather
than deleting into a void.

### Step 6 — Gate

```bash
bash scripts/wp02-verify.sh https://letsfoodify.com --redirects=scripts/redirects.csv
```

- The redirect map is analysed **statically first** — chains, loops,
  self-redirects and duplicate sources are visible in the CSV and cost nothing
  to find there. Finding a chain live means it is already deployed and crawled.
- Then live: every source resolves 200 in one hop.
- Then: 20 or fewer indexable tag archives, no indexable attribute archive,
  categories carrying their copy.

Prove the gate first:

```bash
bash tests/wp02-map-selftest.sh    # 6 assertions over clean/chain/loop/self/duplicate maps
```

---

## What no script can check

**"Zero soft-404s in Search Console after 14 days."** A redirect map can resolve
perfectly and still be wrong — every tag 301ing to `/shop/` passes every check
here and tells Google the pages were worthless. Diarise the Search Console check
for 14 days after cutover. It is the criterion that catches a technically valid,
editorially useless map.

Watch the `reason` column in `redirects.csv`. A row reading `no category` or
`no products` is one of those.
