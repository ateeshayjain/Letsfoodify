# The first real WooCommerce render — 13 Nov 2026

Until today every shop screen of this theme had been seen only in the prototype
(`preview/storefront.html`). The prototype is generated from the theme's files,
but it **draws WooCommerce's markup itself and loads none of WooCommerce's CSS
or scripts**. This document is what happened when a real WordPress with a real
WooCommerce rendered the theme, was set up through the setup screen's buttons,
and was bought from in a browser.

**34 defects found. 34 fixed and re-checked in the browser.** Seven things were
seen and deliberately left (§5). A longer list could not be tested here at all
(§6) — read it before calling any of this "verified on staging".

Everything was local. Nothing here contacted letsfoodify.com or any staging or
production server.

---

## 1. How the site was built

| | |
|---|---|
| WordPress | 7.1.4 (`downloads.wordpress.org/release/wordpress-7.1.4.zip`) |
| WooCommerce | 11.3.0 (`downloads.wordpress.org/plugin/woocommerce.latest-stable.zip`) |
| Database | **SQLite**, through WordPress's own `sqlite-database-integration` 3.1.3 |
| PHP | 8.3.6, `php -S` with 6 workers; `WP_HTTP_BLOCK_EXTERNAL`, `DISABLE_WP_CRON`, `WP_DEBUG_LOG` on |
| Browser | Chromium (Playwright), 1280×900 and 390×844 |
| Order storage | **HPOS** (WooCommerce's own order tables, its default for a new install) for every order the automated check places. The first three orders, placed by hand earlier in the day on the first build of the site, were in the legacy posts tables — that build reported HPOS off |
| Build script | `scripts/wp-site-build.sh /home/user/wpsite-woo --woo` |

**SQLite was enough.** WooCommerce's installer created all 36 of its tables;
creating products, the Store API cart, coupons, shipping zones, the classic
checkout, orders and the account all worked, and WooCommerce itself logged no
database error during a full run. MariaDB was therefore not installed. That is
a statement about this test, not about production: **MySQL is untested** (§6).

The site was then set up the way the client's developer would: the theme
activated, **Appearance → Foodify setup** clicked through in the browser, a
delivery zone added (flat ₹59, free from ₹599 — `bootstrap.sh`'s own figures),
WooCommerce switched from "Coming soon" to Live, and a customer created.

Two real orders were placed through the real checkout, cash on delivery: one as
a guest on a phone-width browser, one as a signed-in returning customer on a
laptop-width one.

---

## 2. The setup screen (`inc/setup.php`) against real WooCommerce

It had never run against WooCommerce. Each step was clicked, then checked in
the database.

| Step | What was checked | Result |
|---|---|---|
| Pages | 7 missing pages created, WooCommerce's four pointed at, readable links | **pass** |
| Shop settings | all 21 options equal to the list (now 21 — see defect 20); `pa_prep` and `pa_dietary` exist, registered, with all 8 terms; the 4 categories exist; step turns Done | **pass** |
| Business details | headline and phone saved; a licence that is not 14 digits refused, said, and not stored | **pass** |
| Sample products | 12 created — published, in the right category, with prep attribute, prep minutes, net quantity, price, stock state, each marked `_foodify_sample` | **pass** |
| A second run of every step | changes nothing (0 pages, 0 settings, 0 products) | **pass** |
| Remove samples | exactly the 12 go; a product added separately is untouched | **pass** |

The steps did what they said. What the screen got wrong was what it did **not**
say (defect 15) and a button on it that did not work (defect 28).

---

## 3. Every page type × width, after the fixes

`P` = finished document, exactly one H1, no raw shortcode / token / PHP notice,
no text under 3:1, no sideways scroll, no script error, no failed request, no
request to another host — **and** the page-specific checks in
`tools/wp-woo-check.js`. All from the final run of `scripts/wp-woo-test.sh`.

| Page | 1280 | 390 | Before the fixes |
|---|---|---|---|
| Home (4 shelves, 22 cards) | P | P | prices `.00`, WooCommerce SALE badge |
| Shop / all meals, filters, sort | P | P | **fail** — no filters, 10 script errors, cards wrong |
| Shop filtered by URL (`?filter_prep=cooking`) | P | P | fail (script errors) |
| Category (Express) | P | P | **fail** — as shop |
| Search results | P | P | prices `.00` |
| Search, no match | P | P | prices `.00` |
| Product page | P | P | **fail** — 8 defects |
| Product page, sold out | P | P | as above |
| Cart, empty | P | P | **fail** — "₹599 away from free delivery" over an empty cart |
| Cart with items (bill, steppers, coupon) | P¹ | P | **fail** — overflow, 34px sideways scroll at 390, stale bar |
| Checkout | P | P | **fail** — no layout |
| Order received | P¹ | P | **fail** — two addresses, wrong labels, no country |
| My account, signed out | P | P | **fail** — showed the cart |
| My account → Profile | P | P¹ | **fail** — showed the cart |
| My account → Orders | P | P¹ | **fail** — showed the cart; Order again dead |
| My account → Order summary (timeline) | P | P¹ | **fail** — showed the cart |
| My account → Addresses, Your details | P | P¹ | **fail** — showed the cart |
| Track order | P | P | form unstyled |
| Build a box | P² | P² | — |
| 404 | P | P | — |
| wp-admin → Appearance → Foodify setup | P | — | Install buttons dead; silent about Coming soon |

¹ Looked at in the browser at this width and screenshotted during the work,
but the automated check drives this page at the *other* width only (the guest
journey runs at 390, the signed-in journey at 1280). The generic assertions
are therefore automated at one width for these pages and by eye at the other.

² With no `foodify_box_sizes` set the page says boxes are coming and sells
nothing, which is the designed behaviour. **The builder itself was not
exercised** (§6).

Clicked by hand at 390 after the fixes, working, but **not** in the automated
check: **Apply** on the advertised offer in the cart (with `foodify_public_offer`
set — ₹244 → ₹225.50, the button turns "Applied"); **Notify me** on a sold-out
product (confirmation shown); **Create my account** on a guest's order-received
page (account created, signed in, the order attached); **Cancel this order**
from the order page (order status became `cancelled`, the page says nothing
was charged).

Buttons that were clicked and worked in the automated check: Add on a card (Store API), the card's
− / +, the View cart bar, Add to cart on the product page (in place), the
cart's + (twice, crossing the free-delivery line), Apply coupon, Place order
(guest), Place order (signed in), Order again (orders list and order page),
the filter checkboxes, and all five setup buttons.

---

## 4. Defects found — all fixed

Each was seen in the browser, fixed, re-rendered, and is now asserted by
`scripts/wp-woo-test.sh`. "Pure test" names the unit test that also pins it.

### Site-wide

| # | Seen | Cause | Fix |
|---|---|---|---|
| 1 | Ten script errors on shop and category ("wp is not defined"…); every script after them dead | The theme's defer rule kept a handle with inline data synchronous but deferred what it **depends on** (`wp-hooks` under `wp-i18n`; `wc-types` under `wc-settings`, whose data WooCommerce attaches in the footer) | `functions.php`: `foodify_defer_blocked()` — everything a synchronous script needs stays synchronous; worked out for head and footer separately. Pure test: `perf-test.php` (+7) |
| 3 | Headings and six components at the wrong size | WordPress emits `--wp--preset--font-size--2-xl`; `style.css` (6 rules) and `theme.json` (h1, h2) used `--2xl`, which does not exist. The prototype defined the wrong name, so it worked there. `token-test.py` checked against the raw slug and agreed | All uses corrected; the prototype generator and `token-test.py` now kebab-case as WordPress does |
| 12 | `₹185.00` everywhere | The prototype formats prices itself; `wc_price()` prints the store's two decimals | `woocommerce_price_trim_zeros` (only a zero fraction goes; ₹92.50 stays) — `inc/product-display.php` |
| 15 | After setup, every shop page showed visitors "Great things are on the horizon" | A new WooCommerce starts in **Coming soon** mode; the signed-in person setting it up never sees it | The setup screen now says so, with a link to Site visibility. It changes nothing by itself — going live is a person's decision. Pure test: `setup-test.php` (+2) |
| 26 | Header read "Login" as a text link; signed in, a broken avatar and a request to `gravatar.com` on every page | WooCommerce 11 prints `wp-block-woocommerce-customer-account` / `__link`, not the classes `style.css` targets; and swaps the icon for a Gravatar | `foodify_account_button_html()` (`inc/profile.php`): adds the classes, puts the icon back, says "Sign in". Pure test: `profile-test.php` (+7) |
| 30 | Notices in WooCommerce blue/green/red | In a block theme WooCommerce prints `.wc-block-components-notice-banner`, not `.woocommerce-info/-message/-error` | Restyled to the token set (`style.css`) |
| 28 | **Install WooCommerce / Activate buttons in wp-admin did nothing** ("The link you followed has expired") | `wp_nonce_url()` returns an escaped URL; the notice builder escaped it again → `&amp;amp;_wpnonce` | Raw URLs handed to the builder (`inc/requirements.php`). Pure test: `requirements-test.php` (+2). *Verified: WordPress now accepts the nonce. Not verified: an install completing — the test server blocks outside requests* |

### Shop, category, cards

| # | Seen | Cause | Fix |
|---|---|---|---|
| 2 | **The filter column was empty** | The templates used the old React filter blocks self-closing; those render nothing without saved markup (and loaded ~55 scripts trying) | WooCommerce's current server-rendered `product-filters` blocks; restyled; 59 → 23 script files. Attribute ids are resolved by walking the wrapper's tree (it renders its inner blocks itself — both lists showed the same attribute at first). Pure test: `shop-test.php` (+5) |
| 11 | Prep line printed twice; veg mark on home cards but on no shop card | On archives WooCommerce replays classic loop hooks around the blocks (its compatibility layer), ahead of the theme's own filters | Classic chip skipped inside `render_block`; veg mark no longer anchored to the start of the string — `inc/product-display.php` |
| 6 | WooCommerce's SALE badge beside the theme's; round "Sale!" over the product photo | Block default `showSaleBadge`; classic `woocommerce_sale_flash` | Off in all 10 card loops; flash filtered out |
| 13 | "Archives: Shop", "Category: Express" | `query-title` prefix | `"showPrefix":false` |
| 14 | "₹97.50 per serving" on cards | Printed by the price filter everywhere; the 9 Nov direction was pack + unit price only | Hidden on cards; stays on the product page (§5) |
| 33 | "Filter · n" ignored an active price filter | Counted checkboxes only | Counts a price range too |

### Product page

| # | Seen | Cause | Fix |
|---|---|---|---|
| 4 | Price at body size | WooCommerce writes `has-2xl-font-size`; WordPress generates `.has-2-xl-font-size` | Rule added |
| 5 | WooCommerce's "Additional information / Reviews (0)" tabs above the theme's body, with a second review form | `woocommerce_product_tabs` untouched | The three default tabs removed; a plugin's own tab survives — `inc/product-spec.php` |
| 7 | "MRP &amp;#8377;225" in the label table and to screen readers | `wp_strip_all_tags( wc_price() )` leaves an entity; the builders escape it again | Decoded first |
| 8 | **No product had a "How to make" tab**; then "Wait 6" | The steps matched the attribute's label ("hot water"); a real product gives its slug (`hot_water`). Minutes are a bare number | Slugs normalised; minutes worded. Pure test: `product-spec-test.php` (+3) |
| 9 | **"Complete the meal" listed "Hello world!"** | Written as a `woocommerce/related-products` wrapper around a bare post-template; it must be a `core/query` with that *namespace* | Template corrected; the heading sits inside the query so nothing related shows nothing |
| 10 | Tabs and label table in a narrow centred column | Constrained layout re-centres children; the prototype has no layout CSS | Default-layout wrappers |

### Cart

| # | Seen | Cause | Fix |
|---|---|---|---|
| 16 | Bill card half-width with totals hanging out; coupon row outside its card; checkout button 30px out of the card; **34px sideways scroll at 390** | WooCommerce's layout stylesheets (floats, 48% widths) fighting the theme's grid; content-box padding on the button | `woocommerce-layout` and `woocommerce-smallscreen` are no longer loaded (WooCommerce's own filter); button `border-box`; 1000px cap lifted to the theme's rail |
| 17 | "₹174 away from free delivery" over a ₹905 cart | The bar is refreshed by a cart fragment; WooCommerce no longer loads `wc-cart-fragments` by itself | Enqueued on the cart — `inc/checkout-flow.php` |
| 18 | "₹599 away from free delivery" over an empty cart | The renderer never asked whether the cart was empty | It does now; a removed last line also clears the bar — `inc/shortcodes.php` |

### Checkout and the order

| # | Seen | Cause | Fix |
|---|---|---|---|
| 19 | **One unstyled column in WooCommerce's colours** | The theme had **no checkout layout at all**: the approved screen is the prototype's own markup (`.fx-checkout`, `.fx-summary`) and CSS | Written for WooCommerce's real markup: fields left, "Your order" card right with totals, payment and button; one column under 900px. Includes the State field (selectWoo) |
| 20 | "Ship to a different address?" and a second fieldset | WooCommerce default | `woocommerce_ship_to_destination = billing_only`, added to **both** `bootstrap.sh` Phase 4 and `inc/setup.php` (`setup-test.php` still holds them equal) |
| 21 | **Orders saved with no country**; state printed "UP"; no shipping address | The country field is hidden, so none was posted | `foodify_checkout_country_data()` defaults both to `IN` — `inc/checkout-fields.php` |
| 22 | A returning customer's "Full name" was their first name; the order and its label lost the surname | The field is `billing_first_name` relabelled; existing customers are stored split | First + last joined on prefill, split again on save |
| 23 | Order received showed "Shipping address" and "Billing address" (the same address twice), "Shipping:" in the totals | In a block theme this page is WooCommerce's **order-confirmation template**, not the checkout page's classic thank-you the theme assumed | The theme's own `templates/order-confirmation.html`: one "Delivery address"; "Delivery:"; next-step buttons |
| 34 | **A customer whose mobile number begins 91 could not check out** ("Enter a valid 10-digit Indian mobile number" for 91234 56780) | The validator stripped a leading `0` or `91` from *any* number, turning a valid ten digits into eight | A country code is stripped only when there is one (12 digits, or 11 with a leading 0) — the rule `foodify_wa_phone()` already had. Pure test: `retention-test.php` (+2); the check's guest now uses such a number |
| 32 | Checkout errors and the two lines above the fields as blue alert boxes | Notice banners (see 30) | Sentences, not alerts |

### Account

| # | Seen | Cause | Fix |
|---|---|---|---|
| 24 | **Every My account page — signed in or out — showed the cart** | `woocommerce/classic-shortcode` knows only `cart` and `checkout` and renders the cart for anything else | The template uses `[woocommerce_my_account]` |
| 25 | Menu full-width, page content squeezed into 240px | WooCommerce's clearfix `::before` on `.woocommerce` became the first grid item | Pseudo-elements removed in the grid |
| 27 | **Order again on the orders list opened the cart and added nothing** | Same double escaping as 28: the nonce arrived as `amp;_wpnonce` | Raw URL. Pure test: `order-progress-test.php` (+1) |
| 29 | Order page: Order again twice, a bare Cancel that skipped the reasons, "₹59 via Delivery" | WooCommerce 8+ prints its own "Actions:" row | Row emptied on that page; "via" removed |
| 31 | Signed-out account and Track order forms unstyled; the password toggle a grey box | WooCommerce styles them only in its layout sheet | Styled in the theme |

Screenshots, before and after side by side at 1280 (`docs/woo-render/`):
`shop-before-after.jpg`, `product-before-after.jpg`,
`checkout-before-after.jpg`, `account-before-after.jpg`. "Before" is the theme
as it stood on `main` that morning, rendered by the same check.

---

## 5. Seen and NOT changed — each needs a decision, not a fix

1. **The shop's title and intro.** The prototype says "Shop all" and "Every
   meal, chutney and tea in the range…". Both are fixture copy. The real page
   prints the Shop page's own title ("Shop") and content (empty). Type them on
   that page in wp-admin; the theme invents no copy.
2. **Per-serving price on the product page.** The theme prints it; the
   prototype never showed it. Hidden on cards (the 9 Nov direction), left on
   the product page. Client's call.
3. **The review form is open to anyone**, directly under "You can review it
   from My account → Orders". WooCommerce's "verified owners only" setting is
   not in `bootstrap.sh`, and WP-08 expects Judge.me to replace this form.
   Decide which, then set it in both settings lists.
4. **Checkout error wording** is WooCommerce's: "Billing Mobile number is a
   required field." An empty mobile or PIN also gets the theme's own
   "Enter a valid…" line — two messages for one empty field.
5. **Scripts on the shop page: 23.** Down from 59, but WP-04's budget is 12.
   Not attempted here.
6. **The prototype still draws checkout, order received and the account with
   its own markup.** The theme's CSS now exists and is tested against the real
   thing, but those three prototype screens are not generated from
   WooCommerce's markup, so they can still drift. Rebuilding them is its own job.
7. A screen reader hears "Sprouted Millet & Beetroot Cheela150 g quantity" on
   the cart's quantity field (no space before the pack size).

---

## 6. NOT verified — and why

| Not verified | Why |
|---|---|
| **MySQL / MariaDB** | Ran on SQLite. The theme's own SQL (coupon ledger, stock alerts, cart recovery tables) was not exercised beyond boot |
| **An existing shop's order storage** | The check's orders are in HPOS tables; three hand-placed orders were in legacy storage. The live shop's mode is unknown, and no order was *migrated* between the two |
| **Razorpay, the prepaid saving, the payment label** | No gateway installed; only cash on delivery was used. `payments-test.php` carries the rule, not the page |
| **OTP sign-in** | Needs an SMS plugin and a DLT-registered sender. The real sign-in page is WooCommerce's username/password form, which is *not* the prototype's mobile-number screen |
| **Any email** (order, review request, partner, cart recovery) | The container has no mail transport |
| **PIN delivery estimate, the header delivery line** | `foodify_delivery_zones` is unset, so both stay off, as designed |
| **Build a box** | `foodify_box_sizes` unset — only the "coming soon" state was rendered |
| **Returning an order, address book add/edit, a review with photos, referrals, repeat orders, partner pages, WhatsApp opt-in, search suggestions, the product-page offers list with a real offer** | Rendered where they appear; none was clicked through |
| **Real catalogue** — photos, galleries, combos, variable products, 44 products, pagination | 12 text-only samples |
| **Rank Math, Judge.me, WP Mail SMTP, PDF invoices** | Not installed |
| **The Install WooCommerce button completing an install** | Nonce accepted; the download is blocked by the test server |
| **Widths other than 1280 and 390** (820, 1920) | Not crawled against real WooCommerce; the prototype sweep covers them |
| **Performance, real phones, screen readers** | Out of reach of a headless desktop browser |
| **wp-admin order screens, Shop Staff dashboard, the product editor fields** | Not opened |

---

## 7. The repeatable check

```bash
build/scripts/wp-woo-test.sh          # builds /home/user/wpsite-woo if absent; ~3 minutes; 118 checks
```

`docs/WP-BOOT.md` has the detail. It restores a pristine database first, so
every run starts from a WooCommerce nobody has set up. It exits 2 and says
**"did NOT run"** when WooCommerce, the browser or the server is missing. Run
against the theme as it was on `main` this morning it fails about sixty checks.
It is deliberately **not** in `run-all-tests.sh`, which must stay runnable
where WooCommerce cannot be downloaded.

`run-all-tests.sh`: ALL GREEN, 47 suites, after every commit of this work.
