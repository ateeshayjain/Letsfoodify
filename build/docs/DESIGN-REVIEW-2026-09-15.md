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

## Round 3, same day — bands, borders, columns, and the claim

Three more client screenshots and a competitor teardown (spiceupfood.in).

**Bands.** The reviews band and the trust strip had top and bottom padding
only; content sat flush against the edge. Core 7.1 no longer pads a group
with a background on its own. Both now carry spacing-50 on all sides.

**Borders.** Every border in the theme was a width without a style. The style
engine maps `border.style` to `border-style` and nothing else — a width alone
draws NOTHING in WordPress. The preview had invented `border-style: solid` on
the colour class, which is why the borders showed there and why the trust
strip grew 3px sides where only top and bottom had a width. Twelve borders now
declare `"style":"solid"`; the preview emits `border-color` only, as
theme.json's preset classes do. Without the client's tablet screenshot this
would have shipped as an invisible header rule, invisible trust-strip rules and
invisible card borders across three patterns.

**Columns.** The preview let columns wrap at every width; core forces one row
above 782px. A 55% + 45% pair plus its gap wrapped, so the hero image sat under
the title on every desktop render — and I read that as the intended layout in
my own screenshots twice. Now nowrap above 782px, as core.

**The claim.** The teardown's real lesson: Spice Up's site is one number
restated everywhere. Ours is now "6 minute mein ghar ka khana ready!" and it
lives in exactly one place — the `blogdescription` line in `bootstrap.sh`
(rule 2). The hero H1 renders it through `<!--FOODIFY_TAGLINE-->`; the preview
reads the same line so it cannot drift. `foodify_claim_minutes()` is the only
place the number is a number; the product editor flags any prep time slower
than it — not refused, because the pack may be right and the claim wrong, but
never quietly untrue. `tests/claim-test.py` fails the build if any static copy
or preview fixture claims a longer time. The one fixture that did (a 7-minute
idli) was on the page the client was signing off.

Not adopted from the teardown without a client decision: pack-of-3/5 variants
(needs their pack pricing), occasion categories (needs the SKU list), OTPless
and subscriptions (paid — rule 5), a PDP pincode checker (sends every browser's
PIN to a third party pre-purchase, and a delivery date needs the WP-11 courier
API). "Fresh" was declined as a word: for a dehydrated product it is a claim the
site would have to argue; "ghar ka khana" is one it makes good on.

## Round 4, same day — the card and the product page (wireframes 01–03)

The client's own wireframes, built. What landed: two badges at most on a card
(commercial left — Sold out beats Bestseller beats New; dietary right), a
second line that says "Serves 2 · 6 min", a SAVE badge that only appears at
15% or more, stars hidden entirely at zero reviews, and "Sold out" on the
button instead of "Add to cart". On the product page: a delivery-promise pill
under the title, a yield strip under the price (Net · Makes · servings · Ready
in), five assurances directly under the button, a tabbed body on desktop that
is the SAME markup a phone reads as accordions, Pack & label always open after
the tabs, and a sticky add-to-cart bar on the phone that appears only once the
real button has scrolled away.

Three of these were wrong in the first build and were caught by looking at the
rendered page, which is the only reason they are not in the repo:

**The tab strip drew every title on top of every other.**
`grid-template-columns: repeat(auto-fit, minmax(0, max-content))` is invalid —
auto-repeat needs a definite track size — so the whole declaration was dropped
and four 12px boxes tiled while their labels painted through each other. It is
now a wrapping flex row: titles size to their text, the open panel is 100% wide
and wraps below them, with no column count to keep in sync.

**The sticky bar never hid.** The script sets `bar.hidden = true`, and
`.fd-sticky-atc { display: flex }` outranks the browser's own bare
`[hidden] { display: none }`. The attribute flipped and nothing moved, so the
bar sat over the page permanently — including while the real button was on
screen.

**The Jain badge was pushed off the card.** A four-up card's image is ~140px,
narrower than "Bestseller" and "Jain" side by side; `space-between` does not
shrink a nowrap badge, it pushes the second one out. Badges now wrap.

All three are invisible to a page-overflow check, which is what the browser
sweep was. It now also fails on a box narrower than the text inside it (what a
dropped grid track actually looks like) and on an element escaping the box it
is overlaid on. Both were proven by running them against the broken build
before the fix.

Two duplications went with the wave: the contents table and the Pack & label
table are one renderer and one set of CSS rules (they were two copies, and the
copy the label table used had lost its layout when the old side-by-side wrapper
was deleted), and the quantity box is one rule for the cart and the product
page rather than a cart-scoped copy.

Still client-gated, unchanged from round 3: pack-of-3/5 pricing, bundle
pairings, a same-day NCR promise, the FSSAI number, and the per-SKU taste,
cooked-weight and FAQ copy the new fields are waiting for.

## Round 5, 16 Sep — one left edge, and the gaps filled with named dummies

**"Is it aligned to screen?"** Not everywhere, and the wider the screen the
worse it read. Core caps the content rail at 760px and the wide rail at
1200px, so a page whose title is a constrained block above an alignwide grid
has two left edges: 20px apart at 1280, **220px apart at 1920**. That was the
shop ("Foodify Express" indented from its own products), the cart title, the
product breadcrumb, and the home best-sellers section — where the wrapper
group was constrained, so the four-up grid inside it rendered into a 760px box
while the shop grid used the full 1200.

Every page now has ONE left edge. The mechanism matters, because the obvious
fix does not work: capping an alignwide paragraph's width just re-centres it
(the rail is made of auto margins), and zeroing its left margin drops it to the
container edge rather than the rail. The title and intro therefore sit in a
wide **default-layout** group — a constrained one would re-centre each child —
and `.fd-lede` caps the line length only.

The preview was lying about part of this: `cls_for()` never emitted `align` or
`className`, so a dynamic block carrying `{"align":"wide"}` rendered at content
width here and wide in WordPress. It now emits both. The browser sweep gained
the check that would have caught the whole class — a heading indented from the
widest thing it introduces — and a third width, **1920**, because every rail
mistake is ten times larger there than at 1280. Both were proven against the
broken build before the fix.

**The gaps are filled with dummies, and the dummies cannot escape.** The
preview now shows an FSSAI licence number, a same-day-NCR dispatch promise, a
curated "Complete the meal" pairing (which no longer offers you the product you
are already looking at), and per-product taste notes, cooked weight and FAQ.
All of it is invented, declared in one block at the top of
`tools/render-preview.py`, and named in the preview's own banner.
`tests/fixture-leak-test.py` fails the build if any of those strings appears in
the theme, a pattern, a template or `bootstrap.sh`. That is not ceremony: an
invented licence number on a live food site is a false statement to a regulator,
and a plausible-looking one reached four templates in this project once already.
The theme still prints NOT CONFIGURED for a licence it has not been given.

**Still Nalin's call** — the dummies are the questions, in visible form: the
real FSSAI number, whether same-day NCR dispatch can be promised, which
products actually pair, and the per-SKU copy.

## Round 6, 16 Sep — the header row

**"The cart and profile should move to right."** They were, above ~900px and
below ~620px. In between — every tablet and small laptop — the header row
wrapped and dropped the account and cart onto a second line, centred. Three
separate causes, all now fixed and all now pinned:

1. The header group wrapped. It is `flexWrap: nowrap` now, so the row holds
   and the nav wraps inside itself instead. `.fd-header__actions` also takes
   `margin-inline-start: auto`, so even a wrap elsewhere would push them right
   rather than centre them.
2. The preview centred them by 23px of its own accord: its constrained-layout
   rule applied auto margins to `.wp-block-group > .wp-block-group`, which in
   WordPress happens for CONSTRAINED containers only, never inside a flex row.
   Scoped with `:not(.is-layout-flex)`.
3. The nav then broke to two lines between 782 and 800px, because the preview
   hardcoded a 22px nav gap and ignored a block's own `blockGap` entirely. The
   renderer honours `blockGap` now, the header sets spacing-30, and the nav
   tightens its own gap below 1024 — it gives up gap, never links: hiding a
   category behind a hamburger on a 900px screen loses a navigation level to
   save 40px.

The sweep now runs a **tablet width (820px)** — the band where this lived, and
which no width in the list had ever touched — and fails if the header actions
stop short of the right edge or leave the logo's row. Proven by putting the
wrap back and watching it go red.

Also fixed while in there: the preview headed the cart page "Express Dal Fry",
because its post-title fixture returned a product name on every screen.

## Round 7, 16 Sep — the mock is click-through

**"I am clicking on cart but nothing is happening."** Only the tab strip along
the top was ever wired. Every door the page itself offers — the cart pill, the
account icon, the nav, a product card, Add to cart, the logo, the footer links
— was dead, which reads as a broken build rather than as a static mock.

They all work now, and the wiring is derived from the theme's own hrefs
(`/shop/`, `/cart/`, `/product-category/…`) rather than hand-listed, so a link
the theme adds is wired by being there. Two traps, both recorded because both
look identical to the original complaint: an `href` the mock did not intercept
NAVIGATES away from the single file to a page that does not exist here (the
click handler calls preventDefault now), and an element carrying an empty
`data-s` hides every screen at once.

`tests/preview-doors.js` opens each door in a real browser and asserts the
screen it lands on by name, plus that nothing points at a screen that does not
exist. It is a blocking suite in `run-all-tests.sh` — 28 suites now.

Sold out stays inert on purpose. On the live site Add to cart opens the
mini-cart drawer rather than the cart page; the mock has no drawer, so it opens
the cart screen instead.

## Round 8, 16 Sep — "what did you even make?"

A fair question, and the honest answer first: **a preview of the theme, not a
store.** Everything in the portal is generated from the real theme files —
tokens, templates, patterns, the PHP that renders cards and product pages — so
what you see is what WordPress will draw. What it is *not* is WordPress: there
is no WooCommerce behind it yet, so nothing genuinely adds to a cart, takes an
order, or sends an OTP. Nothing in this kit has run against a live WordPress
except the boot test. Until staging exists, the portal is for judging layout,
type and hierarchy — and, from this round, for clicking through.

Because a reviewer clicks, the mock now behaves like a store as far as a static
file can: every door opens the screen it names; the browser's Back button
returns (screens push history); the cart re-adds itself when a line is removed
or a quantity changed — subtotal, the 10% partner coupon, total, the
free-shipping bar and the header pill all follow, and an emptied cart says so
with a way back; and any click that has no screen here says so in a toast
rather than doing nothing. `tests/preview-doors.js` asserts all of it in a real
browser, 15 checks, blocking.

Six things the click-through surfaced, all in the THEME, all fixed:

1. **No way back from the cart.** The classic cart offers none once it holds
   something. It has a "← Continue shopping" link now.
2. **A sold-out card looked buyable.** WooCommerce marks the loop item
   `outofstock`; the theme now greys the image, mutes the name and price and
   flattens the button on that class. The badge stays loud — it is the message.
3. **"Use this address" and "Manage saved addresses" were flush.** Siblings the
   PHP prints back to back, with no gap — and the link's tap height was asking
   for `--wp--custom--tapTarget`, a token that does not exist.
4. **That token was wrong in six places.** theme.json's custom keys reach CSS
   kebab-cased (`--wp--custom--tap-target`), so every camelCase use was a
   silently invalid declaration. `tests/token-test.py` now checks that every
   token the theme uses exists — rule 4, as arithmetic rather than a sentence.
5. **The sign-in page sat in the wrong column.** The signed-in account page's
   two-column grid also applied to the signed-out page and put the login form
   in the 15rem nav column, headed "Sign in" twice. The grid is scoped to
   `logged-in` (which WordPress sets on `<body>`), the form is a centred card
   with WooCommerce's own classes, and the page is headed "My account" once.
6. **Nav links rode nine pixels high.** The 44px tap floor made each link a
   44px box inside the nav's flex row, with its text at the top. They centre now.

**Invoices and receipts** (asked this round): not today. WooCommerce sends the
order email — that is the receipt — and the account's Details page shows the
order, but nothing generates a downloadable GST invoice. The free "PDF Invoices
& Packing Slips" plugin would, styled to the tokens and carrying GSTIN and the
FSSAI number; it costs nothing, so rule 5 does not bite, but it is a scope
addition and needs a yes.
