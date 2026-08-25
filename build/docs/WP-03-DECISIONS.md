# WP-03 — two decisions made while building, both reversible

## 1. Foodify is now a standalone block theme, not a Blocksy child

`style.css` no longer carries `Template: blocksy`.

**Why the middle ground was not available.** WordPress decides a theme is a block
theme by looking for `templates/index.html` in the *stylesheet* directory. WP-03
requires block templates. So the moment `templates/index.html` exists, the theme
becomes a block theme whether or not it declares a parent — and Blocksy's classic
PHP templates, header, footer and layout stop being used. Keeping
`Template: blocksy` would have meant paying **₹6,000/yr for Blocksy Pro** and
using none of it, while carrying a parent theme's update surface for nothing.

Everything the handover asks for points the same way: the builder stack is
deleted, all tokens live in `theme.json`, pages are assembled from patterns, and
recurring cost is at the top of the client's tolerance.

**Consequences.**
- Blocksy Pro drops out of the recurring cost — **₹6,000/yr saved**, and
  `bootstrap.sh` phase 3 should stop installing Blocksy.
- Header, footer, and every template are ours: `parts/`, `templates/`.
- No parent theme means no parent-theme updates and no parent-theme surprises.

**To reverse:** put `Template: blocksy` back in `style.css`, delete
`templates/` and `parts/`, and rebuild the front end as PHP overrides. That is
the whole of WP-03 again, so decide now rather than in week 8.

## 2. Cart and Checkout use the CLASSIC shortcode blocks

`templates/page-cart.html` and `page-checkout.html` render
`wp:woocommerce/classic-shortcode`, not the Cart and Checkout **blocks**.

**Why.** `inc/checkout-fields.php` — the 25-fields-to-9 work, WP-06 — is built on
the `woocommerce_checkout_fields` filter. **That filter is not read by the block
Checkout**, which registers and renders its fields through its own API. Ship the
block Checkout and the entire field reduction silently does nothing: the code
runs, no error appears, and checkout still asks for everything.

That is the exact failure shape this project keeps finding, so the templates use
the surface the audited code actually controls.

**Verify on staging** — this is asserted from how the two checkouts are built,
not tested here. Put a product in the cart, load `/checkout/`, and count the
fields. If it shows nine, the classic path is active and `checkout-fields.php`
is working.

**The trade.** The block Checkout is faster and has better validation and a
better mobile flow. Migrating to it is a real improvement, but it means
rewriting `checkout-fields.php` against the block API and re-testing the whole
of WP-06. Worth scheduling deliberately after launch — not worth discovering by
accident in week 11.

---

## What WP-03 has produced

```
parts/          header.html   footer.html
templates/      index.html  front-page.html  single-product.html
                archive-product.html  taxonomy-product_cat.html
                page-cart.html  page-checkout.html  page.html  404.html
patterns/       hero  how-it-works  trust-strip  shop-by-effort
                reviews  free-shipping-progress
tools/          render-preview.py
preview/        storefront.html   (generated — do not hand-edit)
```

### The preview is generated, not maintained

`tools/render-preview.py` reads `theme.json`, `templates/`, `parts/` and
`patterns/` and produces `preview/storefront.html`. The obvious alternative — a
hand-written mockup — drifts from the theme the moment either changes, leaving
two descriptions of one design to keep in sync. There is nothing to sync here:
change the theme, re-run the script, the preview follows.

```bash
python3 tools/render-preview.py
```

**It is not WordPress.** Dynamic blocks are replaced with fixtures and
WordPress's layout CSS is reimplemented, not copied. Judge layout, type, colour,
hierarchy and responsive behaviour on it. Judge behaviour on staging.

### Still to do inside WP-03

- ~~Two shortcodes referenced and not written~~ — **done**, see below.
- **The account template** (`page-my-account.html`) waits on WP-05, since its
  content is the OTP login and address book.
- **Photography.** Every food image is a CSS placeholder. This is the week-3
  shoot, and it is the single biggest visual difference between the preview and
  the real thing.
- **`archive-product.html` filter blocks carry `attributeId: 0`** — a placeholder.
  WooCommerce needs the real attribute IDs, which only exist once
  `tags-to-attributes.php` has created them on a live install. Set them in the
  Site Editor after the WP-02 migration, then export the template back to the
  theme.


---

## The two shortcodes (added after the first WP-03 pass)

`inc/shortcodes.php`. Both render nothing rather than something untrue.

### `[foodify_free_shipping_progress]`

**The threshold is read from the shipping method that would actually apply** —
never a constant. A bar promising free shipping while checkout charges for
delivery is worse than no bar: it is principle 03 broken by the component meant
to demonstrate it.

Two details decide whether it tells the truth, and both are easy to get wrong:

- **Zone.** The customer's shipping zone decides which free-shipping method
  applies. One hardcoded number is wrong the moment a second zone exists, so
  `foodify_free_shipping_threshold()` resolves the zone from the customer's own
  destination and takes the **lowest** qualifying minimum — the one they hit
  first, so the honest one to show.
- **Discounts.** WooCommerce compares `min_amount` against the subtotal *after*
  discounts unless the method ignores them.
  `foodify_shipping_comparison_subtotal()` mirrors
  `WC_Shipping_Free_Shipping::is_available()`. Reimplement it wrong and a coupon
  either falsely qualifies the customer or falsely un-qualifies them — and they
  find out at the payment step.

It also registers a **cart fragment**. The cart page replaces
`.woocommerce-cart-form` and `.cart_totals` on a quantity change; this block sits
outside both, so without the fragment it would keep showing the pre-update figure.
A stale promise is the failure this component exists to prevent.

### `[foodify_google_reviews limit="3"]`

WP-08's review widget, replacing three testimonials attributed to the same name.
**If there are no real reviews it renders nothing** — there is no sample state,
because inventing filler is the thing being removed.

**Fetched server-side and cached for 12 hours**, deliberately:

- Places API bills per request. An uncached widget bills once per pageview — a
  cost that grows with exactly the traffic the SEO work is meant to create.
- No third-party script runs in the customer's browser, so no customer data
  reaches Google from this component and there is nothing to disclose.

A failed fetch caches an empty result for 15 minutes, so a wrong key does not
retry on every view. Star-only reviews (a rating with no words) are dropped —
they prove nothing on a page whose job is showing what people said.

**Configuration.** Key and place ID are read from constants first so they can
live in `wp-config.php` and stay out of database backups, which get copied
between environments:

```php
define( 'FOODIFY_GOOGLE_PLACES_KEY', '...' );
define( 'FOODIFY_GOOGLE_PLACE_ID',  '...' );
```

Options `foodify_google_places_key` / `foodify_google_place_id` are the fallback.
Flush the cache after replying to a review: `do_action( 'foodify_flush_google_reviews' )`.

### Tested

`tests/shortcode-test.php` — 17 assertions, plain PHP, no WordPress. It loads the
**real file** behind a few no-op stubs rather than a copied-out helper, so the
tests cannot drift from the shipping code.

```bash
php tests/shortcode-test.php
```

Covers: no method, zero threshold, empty cart, part way, exactly on the
threshold, over it, a negative subtotal, and one paisa short. Plus review
normalisation — star-only, no author, rating 0, rating 6, missing optionals.

### A drift this introduced, and closed

The shortcodes first emitted `fx-` classes, which is the **preview's fixture
prefix** — they only existed in the renderer's CSS. The preview would have looked
right and the live site would have rendered reviews unstyled: the preview
flattering the real thing, which is precisely what generating it from the theme
is supposed to prevent.

Fixed at the source. The shortcodes emit `fd-` (theme) classes, the styles live
in `style.css`, and the renderer's fixture was pointed at the same classes with
its duplicate rules deleted. `fx-` now means fixture and `fd-` means shipped, with
no overlap.
