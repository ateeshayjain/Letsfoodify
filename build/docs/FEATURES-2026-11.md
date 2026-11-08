# The Nov 2026 features: what was built, where, and what Foodify must decide

The client compared the site with Amazon, Blinkit and other ready-meal stores and
asked for all fourteen gaps (8 Nov 2026). They were built in four lanes. Each
lane's decisions, legal notes and staging checklist are in its own notes file:

| # | Feature | Code | Notes | Tests |
|---|---|---|---|---|
| 1 | Delivery date by PIN on the product page | `inc/delivery-estimate.php` | A | `lane-a-test.php` + `delivery-vectors.js` (PHP and JS agree) |
| 2 | Search with instant suggestions + results page | `inc/search.php`, `parts/header.html`, `templates/product-search-results.html` | B | `search-test.php` (PHP and JS agree) |
| 3 | FSSAI veg mark + price per 100 g | `inc/product-spec.php`, `inc/product-display.php` | A | `lane-a-test.php` |
| 4 | Notify me when back in stock | `inc/stock-alerts.php` | A | `lane-a-test.php` |
| 5 | Back-of-pack label in the photo brief | `inc/product-spec.php` (`foodify_gallery_shots`) | A | `lane-a-test.php` |
| 6 | Offers on the product page | `inc/offers.php` | A | `lane-a-test.php` |
| 7 | Buy again on the home page | `inc/buy-again.php`, `templates/front-page.html` | B | `account-actions-test.php` |
| 8 | Build your own box | `inc/box-builder.php`, `templates/page-build-a-box.html` | C | `box-test.php` |
| 9 | WhatsApp order updates (ships OFF) | `inc/whatsapp.php` | D | `retention-test.php` |
| 10 | Cancel before dispatch, report a problem | `inc/order-actions.php` | B | `account-actions-test.php` |
| 11 | Review photos + star filter | `inc/reviews.php` | C | `reviews-test.php` |
| 12 | Repeat orders (no auto-debit) | `inc/repeat-orders.php` | D | `retention-test.php` |
| 13 | Abandoned-cart reminder, consent first | `inc/cart-recovery.php` | D | `retention-test.php` |
| 14 | Referrals: give ₹X, get ₹X | `inc/referrals.php` | D | `retention-test.php` |

Notes: `FEATURES-A-NOTES.md` … `FEATURES-D-NOTES.md`. All suites are in
`run-all-tests.sh` (36 suites, all green on 8 Nov 2026, including the WordPress
boot test and the click-through, which walks every feature).

## Rules every feature keeps

- **No paid plugin (hard rule 5).** Subscribe & save is a reminder and a one-tap
  reorder that the customer pays for each time. True auto-debit needs
  WooCommerce Subscriptions (paid) or a Razorpay e-mandate, which is the client's
  call.
- **No invented business value in the theme.** Each feature reads an option. While
  that option is unset, customers see nothing and wp-admin shows a notice naming
  the option. bootstrap.sh reports the options and writes none of them.
  `fixture-leak-test.py` still passes.
- **Anything that sends a message ships OFF**: WhatsApp needs wp-config constants
  plus `foodify_whatsapp_enabled`. Repeat and cart reminders need their options.
- **Consent is an unticked box (DPDP Act).** The WhatsApp updates and the cart
  reminder are opt-in, and both can be withdrawn.

## Integration done after the lanes (outside any one lane)

- `inc/shortcodes.php`: the free-delivery bar judges the same amount checkout
  does — after coupons, BEFORE the box discount (decision 4, 8 Nov 2026). The
  earlier version subtracted the box discount, and so did checkout, which let a
  complete box cost the customer free delivery (₹615 loose, ₹644 as a box —
  review BUY-1). Neither subtracts it now.
- `inc/product-editor.php`: a **Search keywords** field (meta
  `_foodify_search_keywords`), where the shop enters Hindi and regional names.
- `parts/footer.html`: "Build a box" link in the Shop column. The header nav is at
  its width limit.
- `run-all-tests.sh`: the five new suites, plus a **duplicate-functions** check.
  `php -l` and `undefined-functions` cannot see a function defined twice, which is
  a fatal at boot. Two lanes nearly shipped one (`foodify_inr`).
- `smoke-test.sh` section 5b: signs in as a test customer (`FOODIFY_SMOKE_COOKIE`)
  and asserts that the home page is private, no-store, carries `X-Foodify-Private`
  and is not an edge HIT. It then asserts that a guest asking next sees no Buy
  again. A missing cookie FAILS rather than skips. `selftest.py` proves that the
  section catches a leaking cache. Fixed alongside it: the gate's edge-cache HIT
  regex used `[^\r]`, which `grep -E` reads as "not r". Any header value with an
  "r" before HIT (e.g. "served from origin shield, HIT") was reported as not
  cached.
- Prototype: every feature can be clicked, rendered from the theme's own functions
  and scripts. Demo settings live only in `tools/render-preview.py`, and its banner
  names them.

## Still open

- Two design changes need the design owner's sign-off:
  - The product page loses the two hard-coded assurances ("Cash on delivery
    available", "Free shipping over ₹599"). They now come from settings, in Offers.
  - On phones, the cart pill shows the count only (the amount is in the drawer),
    so that search fits.
- **No online payment gateway is configured anywhere.** "Pay online and save ₹25"
  cannot apply on the live build until one is, so Offers leaves it out there.

## Questions for Foodify, in one list

**Delivery and orders**
1. Courier zones and transit days, the dispatch cut-off time, no-dispatch days and
   holidays. The PIN check shows nothing until these exist.
2. Does the courier restrict cash on delivery by PIN?
3. The site-wide "Ships across India in 3–5 days": keep it, or let the PIN check
   replace it on product pages?
4. When is an order marked Completed (= dispatched)? Cancelling is allowed until
   then. Should it close earlier, e.g. once packing starts?
5. How many days after delivery may a customer report a problem online
   (suggested: 7)? Does the courier send a "delivered" event?

**Products and pricing**
6. Unit price per 100 g (what Amazon and Blinkit show), or per gram (our reading
   of the 2022 Legal Metrology amendment, for a compliance adviser to confirm)?
7. Hindi and regional names for each product, for search. Who supplies them?
8. Box sizes and discounts (suggested 3 / 6 / 9 packs at 5 / 10 / 12% off). Should
   a box stack with coupons? Which packs are excluded? Is a special box needed
   for packing?
9. The GST treatment of the box discount and the prepaid saving (for the CA).
10. Is there a public coupon to advertise on product pages?

**Messages and marketing**
11. Approve Meta's per-message charge for WhatsApp updates. Which number and
    WhatsApp Business account, and who submits the templates? Should
    repeat-order reminders go on WhatsApp, where Meta may class them as
    marketing?
12. Repeat orders: how many days' notice (suggested 2)? Any discount, and does it
    stack with the prepaid saving?
13. Cart reminder delay (suggested 60 minutes).
14. Referrals: the give and get amounts, the minimum first order, and the monthly
    cap. Is the reward issued on dispatch or on delivery? Do reward codes expire?
15. Who approves photo reviews, and how fast?
16. How long may an unsent back-in-stock request be kept (suggested: 180 days)?
