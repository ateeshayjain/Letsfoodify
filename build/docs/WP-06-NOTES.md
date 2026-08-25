# WP-06 — the checkout rebuild

The field trim (25 → 9) shipped with the kit as `inc/checkout-fields.php`. The
saved-address chooser is WP-05. What was left is the flow itself: what the page
around the form does, what it promises, and what happens when something goes
wrong.

| | State |
|---|---|
| 25 → 9 fields | Already built (kit), verified in WP-03 |
| One-tap saved address | Already built (WP-05) |
| Distraction-free checkout shell | **Built** — `parts/header-checkout.html`, `parts/footer-checkout.html` |
| Cart/checkout/account never cached | **Built** — theme headers + a blocking gate assertion |
| Errors move focus, not just scroll | **Built** |
| Cart quantity applies itself | **Built** |
| Honest total promise on the cart | **Built** — derived, not asserted |
| Coupon field demoted, not moved | **Built** — and the reason it is not moved matters |
| Payment methods, COD | WP-07 |

---

## What the audited site did, and why this does not

The developer comment leaking above the login box was:

```
// 3. Inject JS step-switching script to show only one box at a time
```

That is a multi-step checkout built by hiding boxes with JavaScript, and it
breaks in ways invisible to whoever built it. Browser Back moves through history,
not steps. A validation error fires on a hidden step and the customer sees a form
with no visible problem. A screen reader walks straight through the hidden boxes.

**This is one page with a sticky summary.** Nine fields fit on a phone without
steps, which is the actual fix — steps were compensation for a 25-field form.

---

## The privacy control, which is the most important thing here

**A page cache that serves `/checkout/` or `/my-account/` to an anonymous
visitor serves one customer's name, address and phone number to another.** It is
a data breach, not a performance bug, and it is completely silent: the pages
render, orders go through, and nobody finds out until a customer says they saw
somebody else's address.

`REVIEW-NOTES.md` item 1 records that the only cache measurement anyone has ever
taken of this site was almost certainly polluted by the tester's own cart cookie.
So nobody has actually confirmed how the host behaves.

Three layers, and the third is the one that matters:

1. **The theme sends the headers.** `Cache-Control: no-cache, no-store,
   must-revalidate, private` on cart, checkout (including order-received) and
   account, plus `DONOTCACHEPAGE` for plugin-level caches. `no-store` is the one
   that counts — `no-cache` still permits a shared cache to *store* the response
   and revalidate, and a CDN that gets revalidation wrong is precisely the
   failure being defended against.
   WooCommerce sets `DONOTCACHEPAGE` itself; this asserts it anyway, because
   wordpress.org is unreachable from this environment and I could not verify
   current behaviour. Asserting costs one function call.
2. **The predicate is deliberately narrow.** `foodify_is_private_page()` covers
   exactly cart, checkout and account. Widening it to the shop or a product page
   would disable page caching for the whole storefront and undo WP-04. Pinned by
   test in both directions.
3. **`smoke-test.sh` section 5 asserts it against the real site**, because the
   origin sending a header does not prove the CDN obeys it.

### How that assertion is shaped, and why

This project keeps meeting the same failure: *an absence check that cannot run
looks exactly like an absence check that passed.* "No cache-HIT header" and
"could not read the headers" are the same result to `grep`.

So section 5 goes in this order, per page:

1. **Did headers arrive at all?** No → `FAIL — cache policy NOT verified`. Stop.
2. **Positive:** `Cache-Control` must contain `no-store`.
3. **Positive:** `X-Foodify-Private: 1` must be present. This is a marker the
   theme emits, and it answers a *different* question — whether
   `inc/checkout-flow.php` is loaded and running on that page at all. Right
   headers from the wrong source is still a broken deploy.
4. **Only now**, the absence check: no `x-cache` / `cf-cache-status` /
   `hcdn-cache` / `x-litespeed-cache` header may say HIT. No edge header at all
   is reported as a WARN — "unconfirmed", not "fine".

`selftest.py` proves all three directions: the healthy fixture sends the headers
and passes; the defective fixture omits them **and reports `X-Cache: HIT`**, and
the gate must catch each; the unreachable case must not print
`sends Cache-Control: no-store` and must say the policy could not be verified.

---

## The cart's promise, derived instead of asserted

`templates/page-cart.html` carried this line unconditionally:

> "Shipping and handling are calculated here, before you go to checkout. Nothing
> new is added at the payment step."

**That is a promise the code cannot keep.** WooCommerce resolves shipping from a
shipping *address*, and a first-time visitor has not given one — so the cart
shows whatever the default zone produces, and the number can change at checkout
once the real PIN arrives. The customer was told it would not. Same shape as the
gate that reported PASS without running: copy that reads as verified, with
nothing verifying it.

`foodify_cart_promise()` now derives the line from three states, and each says
something true:

| State | Line |
|---|---|
| No address, or no rate resolved | "Shipping is calculated from your PIN code at the next step. Nothing else is added." |
| Rate resolved at ₹0 | "Free shipping applied. The total below is what you pay…" |
| Rate resolved above ₹0 | "Shipping is already included in the total below…" |

The sharpest test is the middle case: **an address is known but no rate
resolved** — a PIN outside every shipping zone, or a method not yet chosen.
Reading that as free is exactly how a total silently grows between the cart and
the payment page, so `shipping_cost` is `?float` and `null` is never treated as
zero. A *missing* key is likewise unknown, not free.

```
php tests/checkout-test.php     # 15 passed
```

---

## The coupon field: reworded, not relocated

The received wisdom is to move the coupon field into the order summary so it
stops sending people off to hunt for codes. **Do not do that here.**

The order summary is rendered *inside* `form.checkout`, and WooCommerce's coupon
form is a real `<form>`. Nested forms are invalid HTML and every browser drops
the inner one — so the coupon inputs become part of the checkout form, and
**pressing Enter in the coupon field submits the order**. It would look correct
in a screenshot and place wrong orders in production.

So it stays where WooCommerce puts it, collapsed as WooCommerce already collapses
it, and only the copy and the styling change: "Have a code from a partner or a
creator?" WP-09's partner codes are why this field exists on this store, and
naming that is more useful than a generic prompt that reads as *you are paying
too much*.

---

## Errors move focus

WooCommerce scrolls the notice box into view and stops. On a phone that leaves a
red box at the top and the cursor nowhere, so the customer scrolls back down
hunting for the bad field; with a screen reader the error is announced and then
abandoned. Both are the same defect — the page said something went wrong and did
not say where.

The notice region gets `role="alert"` and focus, then the first `.woocommerce-invalid`
field is focused, so the next keystroke lands where it is needed. Handles both
the AJAX `checkout_error` event and a server-side failure that re-renders the page.

---

## Cart quantity applies itself

WooCommerce needs a separate "Update cart" click, and the button only enables
once something changes. People change the number, watch the line total stay put,
and conclude it did not work — or proceed with the old quantity. Debounced 700ms
so typing "12" does not submit "1" first. The button stays in the markup for
anyone without JavaScript; this only removes the need to find it.

---

## Bug found on the way: `<!--FOODIFY_YEAR-->` was never replaced

`parts/footer.html` carried `<!--FOODIFY_YEAR-->` and **nothing in the theme
replaced it.** Only `tools/render-preview.py` did.

So the preview showed a current year the live site could never render, and the
live footer read "© The Foodify Company" with the year sitting there as an
invisible HTML comment. The preview exists to stop the mockup drifting from the
theme — and here it was the preview doing the drifting.

It also survived the blocking gate, because the footer-year check **warned** when
no year was found instead of failing. A warning is how this shipped.

Fixed with a `render_block` filter in `functions.php` (a shortcode would not
work — shortcodes are not expanded inside block template parts, so `[year]` would
have printed itself), guarded by a `strpos` so it is cheap on every block, and
untyped because this file declares `strict_types` and a plugin returning null
into `render_block` would turn a cosmetic footer into a fatal. **The gate's warn
is now a failure.**

---

## Also fixed

- **A missing template part rendered as nothing** in `render-preview.py`. It now
  says `missing template part: <slug>` on the page. A preview that silently
  disagrees with the theme is the one thing generating it from the theme is meant
  to prevent.
- **`theme.json` now declares its four `templateParts`** with names and areas, so
  the checkout header and footer are findable in the Site Editor rather than
  being anonymous files.

---

## Not done, and why

- **Payment methods, COD, prepaid discount** — WP-07. The preview's payment
  block is a placeholder.
- **A delivery-date estimate.** The arithmetic is easy; the inputs are not. Order
  cutoff time, whether they dispatch on Saturdays, and transit days by zone are
  client facts, and inventing a dispatch promise on a checkout page is how you
  manufacture complaints. Needs three answers, then it is an hour's work.
- **PIN-code autocomplete in the address-book form** (it is wired at checkout).
- **A cart-abandonment path.** Needs email infrastructure (WP-12 territory).
- **None of this has run against a real WordPress install.** WP-00 access is
  still open, so every claim here rests on source reading and the pure tests.

## Running everything

```
php     tests/perf-test.php        # 11
php     tests/shortcode-test.php   # 17
php     tests/otp-test.php         # 24
php     tests/address-test.php     # 51
php     tests/checkout-test.php    # 15
python3 tests/selftest.py          # 28
python3 tests/wp01-selftest.py     # 25
bash    tests/wp02-map-selftest.sh #  6
                                   # 177 assertions
```
