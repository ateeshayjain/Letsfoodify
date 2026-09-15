# Design review round 2 — 15 Sep 2026

Client feedback, verbatim, on the published preview:

> 1. Cart design is not clearly visible — add it
> 2. Filter and Sort design not visible — add it

Both were true, and both were partly the preview's fault rather than the
theme's. The rule this project keeps relearning applied again: **the preview
must render what the theme ships, or the client signs off on something that
does not exist.** This round found three places where it did not.

## What the client saw, and why

| Screen | What the preview showed | Cause |
|---|---|---|
| Shop | One half-rendered card and "No products match those filters" | The renderer never looped `post-template`; it rendered the inner blocks once and printed the `query-no-results` paragraph unconditionally |
| Shop | Filters as a bare checkbox list, "Sort: Bestselling" as grey text | The fixtures invented their own markup and classes; the theme had no rules for either, on any markup |
| Header | "Bag 3" pill | A fixture button. The real block is WooCommerce's Mini-Cart, which the theme did not style |
| Header | No navigation, on any screen, ever | The Navigation block's links are self-closing children and rendered nothing |
| Header / shop toolbar | Groups stacked vertically | `layout: {type: flex}` was never emulated |
| Cart | A `− 1 +` stepper | Fixture. WooCommerce's classic cart renders a number input |
| Home hero | A wide title | The preview let a 55% column grow; core does not (`flex-grow: 0` on a sized column). At content width the real title column was 418px |

Every product card style — the kraft tile, the rhythm work from 26 Aug — lived
in the preview's fixture CSS. The site had none of it.

## What changed in the theme

**Cart.** The Mini-Cart block now uses the cart icon, always shows the count,
and is styled on WooCommerce's own classes as a charcoal pill: icon · count
badge · amount. The cart page styles the classic `shop_table` markup: name
takes the free width, money is tabular and right-aligned, the remove control is
44px, the totals are a card, and below 768px each line is a three-fact row
(thumbnail, name, quantity / total) instead of WooCommerce's labelled blocks.
The cart page is `alignwide`; at content width a six-column table clips.

**Filter and sort.** Filters sit in a core `details` block: `open`, summary
hidden on desktop, so the sidebar is simply there; on a phone the summary is a
44px "Filter" control and one line of `wp_footer` script closes the element on
load (CSS can hide a summary but cannot close a details). Sort is a labelled
`select` — "Sort by" beside WooCommerce's own ordering form, with the option
labels shortened (`woocommerce_catalog_orderby`) so it does not read "Sort by
Sort by popularity". Default catalogue order is popularity, set in
`bootstrap.sh` per rule 2.

**Attribute ids.** The filter blocks carried `attributeId: 0` with a note to
"set them in the Site Editor". A numeric id is install-specific; a template
exported from staging filters nothing on production. The blocks now carry the
slug (`foodifyAttribute`) and `product-attributes.php` resolves it on
`render_block_data`. Pinned by `tests/shop-test.php`.

**Product grid.** Home best-sellers, the shop, categories and "Complete the
meal" are all the same Product Query loop with `className: fd-products`, and
`style.css` styles the loop's real classes. Two-up on phones by matching core's
`(0,3,0)` one-column rule and winning by order.

**The prep chip.** It was class `fd-prep` — the same class as the product
page's "How you make it" band, so the band's margin and padding cascaded onto
every card chip. Renamed `fd-chip`. And it was hooked to
`woocommerce_before_shop_loop_item_title`, a classic-loop hook that a block
query never fires: on the three screens where "six minutes is the product"
matters most, the chip could never render. Now a `render_block_core/post-title`
filter, keyed on the namespace attribute WooCommerce puts on product titles.

**Header and hero** are `alignwide`. At 760px the header's three items could
not fit on one row and the hero's title column was 418px wide. Only visible
once the preview stopped lying about both.

## What changed in the preview

The renderer now loops `post-template` (per-product context for title, image,
price, rating, button), drops `query-no-results`, renders `query-pagination`,
builds the Navigation from its link children, emulates flex layouts (row and
column, with core's alignment mapping), applies core's `flex-grow: 0` to sized
columns, handles `alignfull` and the cover's inner container, and carries
each screen's WooCommerce body classes so scoped rules apply. Every fixture
for a WooCommerce block emits that block's real classes; the only fixture CSS
left is the bowl standing in for photography.

## Gates

- `contrast-test.py` caught the one new violation before a screenshot did:
  the remove-line hover paired flame-ink with the flame wash at 4.27:1 — the
  exact pairing the project's recorded lesson names. Flame-deep, 5.10:1.
- `mobile-sweep.js` caught the phone cart scrolling sideways by 79px: a table
  keeps its column-derived width under grid rows. Out of table layout at
  ≤768px.
- Reading the screenshots caught what neither could: the wrapped uppercase
  chip, the vertically stacked header, the filter column at half the page, the
  × and the line total pushed out of the phone cart by the desktop 1% columns.
- `wp-boot-test.sh`: clean. `run-all-tests.sh`: all green, 24 suites.

## Still only staging can confirm

- The Mini-Cart drawer, the attribute filters and the price slider hydrate
  client-side. Their server markup is styled; their behaviour is not previewed.
- `woocommerce/related-products` was rewritten from the legacy self-closing
  form to the query-loop form. Like every WooCommerce block in this theme it
  has not run under WooCommerce here (the monorepo needs a JS build).
- The chip on the product page itself still hangs off
  `woocommerce_single_product_summary`. The PDP is block-built too; if the chip
  is absent there on staging, the same `render_block` route is the fix.
