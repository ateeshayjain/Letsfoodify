# Features lane C — review photos, star filter, build your own box (Nov 2026)

Code: `theme/foodify/inc/reviews.php` (feature 11, appended to WP-08's file),
`theme/foodify/inc/box-builder.php` (feature 8), `templates/page-build-a-box.html`.
Tests: `tests/reviews-test.php` (43 → 121), `tests/box-test.php` (98).
CSS: style.css between the LANE C markers. bootstrap.sh: LANE C block.

## 11 · Review photos

- **Who.** Verified buyers only — `wc_customer_bought_product()`, the same test
  the review itself passes. Checked again for photos even if the
  `foodify_require_verified_reviews` filter is switched off for text. The upload
  field is not shown to a signed-in customer who has not bought.
- **What.** Up to 3 files, 5 MB each, JPEG / PNG / WebP, decided from the BYTES:
  `finfo` and `getimagesize()` must agree, then `wp_check_filetype_and_ext()` is
  asked again with a filename built from the sniffed type (the customer's
  filename is never consulted). Canvas over 40 MP refused (decompression bomb).
- **EXIF/GPS.** Every photo is re-encoded through WordPress's **GD** editor
  (forced with `wp_image_editors` for the duration; Imagick can carry EXIF
  through a save), orientation applied first (`maybe_exif_rotate()`), long edge
  capped at 1600px. The upload itself is never copied. `tests/reviews-test.php`
  proves the mechanism: a JPEG with an EXIF block loses it, byte for byte, on a
  GD re-encode.
- **Moderation.** A review with photos is ALWAYS held for a human
  (`pre_comment_approved` → 0), whatever the site's setting for text. Photos
  render only when the comment is approved (moderators see them in wp-admin).
  Files get random names and no parent post, so a pending photo cannot be found
  by guessing and never joins a product's media.
- **Refusals stop the submission** (`wp_die`, Back link, text kept by the
  browser) rather than publishing the text and silently dropping the photos.
- **Rate limit.** 3 photo reviews per buyer email per day, 6 per IP per hour
  (transients, hashed keys, the otp-throttle shape).
- **DPDP.** Deleting a review deletes its files; a personal-data eraser
  ("Review photos") deletes them on an erasure request. Alt text uses the first
  name only, never an email.
- **Form encoding.** `comment_form()` has no enctype argument; without
  `multipart/form-data` the browser sends the filename and no bytes. The product
  review form is output-buffered and given the attribute server side.

## 11 · Star histogram and filter

Above the review list (`render_block` on `woocommerce/product-reviews`): the
average to 1 dp with its count, a 5→1 bar per star with counts, and chips
("5★ (32)", … "With photos (4)"). Chips for stars with no reviews are omitted.
The filter ships `hidden` and the script reveals it, so with no JavaScript there
are no dead buttons and every review shows. Each review card gets classes
`fd-rv fd-rv--N [fd-rv--photos]` via `comment_class`. If WooCommerce pages the
reviews, the count line says so ("Showing 3 of the 10 reviews on this page (46
in all)") instead of implying it searched all of them.

## 8 · Build your own box

- **Cart model.** Each pack is its own cart line with cart item data
  `foodify_box = {id, size}`. A negative, non-taxable fee "Your box (6 packs) —
  10% off" exists only while exactly `size` packs share the id. Boxes of one size
  share ONE fee line ("Your 2 boxes (6 packs each) — 10% off"), because
  WooCommerce keys fees by name and a second identically named fee is dropped.
  Changing a quantity or removing a line breaks the box: no fee, and a cart
  notice naming the box and the way back ("Add 1 more to any pack marked
  "6-pack box""). Order lines keep `_foodify_box_id`, `_foodify_box_size`,
  `_foodify_box_complete` and a visible "Box: 6-pack box A1B2C3D4" for the packer.
- **Eligible packs.** Published, simple, purchasable, in stock, priced,
  catalogue-visible, not a combo (`foodify_combo_data()`); capped per pack by
  managed stock and sold-individually. The submission is re-validated against
  the live catalogue and added all-or-nothing.
- **Order of discounts.** Coupons (on lines) → box (% of the box lines' gross
  AFTER coupons) → prepaid saving (flat, payments.php). Free shipping is judged
  after the box discount (`woocommerce_shipping_free_shipping_is_available`),
  matching `ignore_discounts = no`.
- **Rounding.** Down to the whole rupee, in integer paise, identically in PHP and
  in the script — the page's promise and the cart's fee are the same number.
- **GST.** Fee non-taxable, same conservative choice as the prepaid saving
  (over-declares by a few rupees). Same open question for the CA.
- **Off by default.** `foodify_box_sizes` unset → page says boxes are coming
  soon, no form, nothing can be added; wp-admin notice names the option.
- **Per-meal figure** reuses `foodify_combo_per_meal()` (rounded down).
- **Suggested value — CLIENT TO CONFIRM, not set anywhere:**
  `wp option update foodify_box_sizes --format=json '{"3":5,"6":10,"9":12}'`
  (3 packs 5% off, 6 packs 10%, 9 packs 12% — the figures in the feature brief).
  Accepted shape: sizes 2–24, whole percent 0–90; anything else is dropped.

## Not done here, for the integrator

- `payments.php` reads `get_cart_contents_total()` for the prepaid saving. With a
  flat amount the box fee is irrelevant; if `prepaid_rate` is ever used, subtract
  `foodify_box_cart_discount_total()` from `$eligible`.
- `shortcodes.php` free-shipping progress bar compares
  `foodify_shipping_comparison_subtotal()`; subtract
  `foodify_box_cart_discount_total()` there too, or the bar can say "unlocked"
  while checkout charges shipping.
- The page `/build-a-box/` is created by bootstrap.sh; nothing links to it yet
  (header nav is not this lane's file).
