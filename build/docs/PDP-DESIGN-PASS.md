# Product page — design pass, 26 Aug 2026

The product page was the weakest screen in the build. Not because it looked
wrong, but because it was a generic e-commerce PDP wearing this brand's colours,
and it left out the two things a first-time buyer of instant food actually needs.

## What was wrong

**1. It never said how you make it.** The whole proposition is *"a real meal in
six minutes"*, and the question every first-time buyer has — *what do I actually
do with this?* — had no answer anywhere on the page. The homepage has a
How-it-works pattern. The product page, where the decision is made, had a small
chip above the title.

**2. The declarations block was furniture.** Ten rows of equal weight titled
"Pack & label", mixing things a buyer wants (net quantity, servings, allergens)
with things a regulator wants (FSSAI number, marketed-by). Everything at one
weight means nothing is findable, so the useful three were buried under the
mandatory seven.

**3. Ingredients and nutrition were missing entirely.** Scope §8 lists both as
required by the Legal Metrology e-commerce rules. Neither was in the template.
A compliance gap wearing a design gap's clothes.

**4. Four competing price signals** — struck-through MRP, sale price, "12% off",
a per-serving figure injected by a filter, and a caption pointing at it. The
per-serving number is the persuasive one on a ₹185 pack and it was the one
getting crowded out.

**5. Reviews were not on the page.** WP-08 built the collection flow and the
AggregateRating schema depends on them, but the template had no reviews block —
so the rating at the top linked nowhere and the SEO value had nowhere to land.

**6. A grey box of prose doing three jobs** — delivery estimate, COD
availability, and the no-surprises promise, in one paragraph. Prose boxes get
skipped.

## What the page is now

```
breadcrumb
┌── 50% ───────────────┬── 50% ──────────────────┐
│ gallery / shot brief │ title                   │
│                      │ rating → reviews        │
│                      │ short excerpt           │
│                      │ PRICE                   │
│                      │ stock                   │
│                      │ ADD TO BAG              │
│                      │ ─── three assurances    │
└──────────────────────┴─────────────────────────┘
HOW YOU MAKE IT          three numbered steps, from this product's prep method
┌── what's in it ──────┬── pack & label ─────────┐
│ ingredients          │ MRP, best before,       │
│ allergens            │ shelf life, origin,     │
│ net quantity         │ FSSAI, marketed by,     │
│ servings, diet       │ consumer care           │
│ storage              │                         │
│ NUTRITION per serving│ feeds the Google feed   │
└──────────────────────┴─────────────────────────┘
WHAT PEOPLE SAID         reviews — verified purchases only
COMPLETE THE MEAL        related products
```

The buy column is a **ladder**, not a stack: each rung a little quieter than the
one above, so the eye lands on the price and then the button. Block editor
defaults space a title and a stock line identically, which is why the gaps are
set explicitly.

The three assurances replace the grey box. Each answers a separate objection, so
each is its own line with a tick, not a sentence in a paragraph.

## The decision worth arguing about

**A missing required declaration is SHOWN, not hidden.** An empty required field
renders *"Not provided"* rather than dropping out of the table.

Dropping it makes an incomplete page look complete, and the person who would
notice is the one who cannot see the gap.

For allergens this is a safety argument rather than a tidiness one: **the absence
of an allergen declaration must never read as "contains no allergens"**. Someone
deciding whether their child can eat this needs to know the data is missing. The
row is marked by a **word** as well as a colour — a colour alone says nothing to
a screen reader or a colour-blind reader — and it carries *"ask us before ordering
if this matters to you"*, which is the only honest thing the page can say.

Optional fields with no value are simply not rows. Nothing is being hidden there.

An admin notice counts how many published products are legally incomplete, on
the products screen and the WP-10 dashboard, because per-product data is exactly
the kind that gets filled in for the first six and forgotten for the other
thirty-eight.

## Prep steps are per product, and refuse to guess

`foodify_prep_steps()` reads the product's own prep-method attribute. "Just add
hot water" and "requires cooking" are different promises and the page has to keep
the one it makes — the cold-water variant never says *boiling*, the cooking
variant never says *no pan needed*.

**An unrecognised method returns nothing.** Guessing cooking instructions for
food is not a graceful fallback.

## Nutrition is a panel or it is nothing

Fewer than three values render nothing. Two stray numbers is not a nutrition
panel; it is a fragment that *looks* like one, which is worse than an honest
absence.

## The gallery placeholder is now a brief

There is still no photography — `assets/` holds two font files and nothing else.
Rather than a grey box, the placeholder names the four shots, in order, with what
each has to prove:

1. **Pack, front** — straight on, label legible. *This is the Merchant Center
   image, so it is the one that cannot wait.*
2. **Prepared, in a bowl** — the actual portion, natural light, no props.
3. **Ingredients, laid out** — proves it is food, not powder. Answers the real
   objection.
4. **In a hand or beside a mug** — scale. *80 g means nothing without something
   next to it.*

A client can act on that. "Image goes here" is not a deliverable.

## Gate

`smoke-test.sh` now checks the product page positively for both markers — the
preparation steps and the declarations block — so a page that failed to load
cannot report them present. `selftest.py` proves both directions: the healthy
fixture carries them, the defective one carries neither, and the unreachable case
must not clear either.

"Not provided" appearing on a live page is a **WARN**, not a failure: it names
the work without blocking a deploy over data the client still owes.

## Still open

- **Photography.** Client. Nothing else on this page is blocked by it, but shot 1
  gates the Merchant Center feed in WP-08.
- **Per-product declaration data.** Ingredients, allergens, nutrition and
  best-before are per-product meta with no editor UI yet — they can be set by
  filter or by importer today. A metabox is an hour's work and belongs with
  WP-12's content load, so the data entry happens once rather than twice.
- **Never rendered by WordPress.** The preview is generated from the theme, which
  proves the templates and tokens are coherent, not that WordPress agrees.

## Tests

```
php tests/product-spec-test.php   # 44
python3 tests/selftest.py         # 43  (was 38 — five new PDP assertions)
```
