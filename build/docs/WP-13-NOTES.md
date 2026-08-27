# WP-13 — analytics: the instrumentation, and the verification only a live site can do

Scope §W8: *"GA4 e-commerce events verified end to end (view_item → add_to_cart
→ begin_checkout → purchase), GSC property confirmed on the new build."* The
verification half needs the live site; the instrumentation half is code with
real correctness rules, and it is built and tested.

## The three rules the code is built around

**1. Purchase fires exactly once per order.** People refresh the thank-you page
and reopen it from the confirmation email for days; every naive implementation
double-counts revenue that way, and inflated revenue is worse than none because
decisions get made on it. The once-flag lives in **order meta, server-side** —
and it can, reliably, because WP-06 made the order-received page `no-store`, so
that page is never served from a cache with a stale flag. A failed or pending
payment renders no purchase event at all.

**2. Item IDs are the feed's IDs** (`FDY-<id>`). GA4 joins Shopping and
analytics data on `item_id`; two id schemes are two disconnected catalogues,
noticed only when the join is finally needed. One catalogue, one key —
same rule as WP-12's feed.

**3. No PII, and no script breakout.** No email, phone, name or address in any
payload — GA4's terms forbid it and DPDP makes it a liability; pinned with the
same detector the partner emails use, proven against a poisoned payload. And
every string in a rendered event line goes through `JSON_HEX_TAG`-family
encoding, so a product named `</script>` cannot end the script block — the same
one-bad-byte failure as the feed's ampersand, third appearance, same medicine.

Partner **coupon codes ride on the purchase event** — that is how WP-09's codes
become visible in acquisition reports. Codes name a partner's campaign, not a
buyer; they are not PII.

## Ships OFF, and the gate knows the difference

With no measurement ID configured, **nothing loads** — no gtag, no dataLayer,
no request to Google. The ID is client-supplied, same contract as the FSSAI
number and the GSTIN:

```php
add_filter( 'foodify_ga4_measurement_id', fn() => 'G-XXXXXXXXXX' );
```

A malformed ID (a `UA-`, a `GTM-`, anything injected) is treated as **absent**,
never half-loaded against garbage.

`smoke-test.sh` §7 asserts coherence both ways: loader present → the events
must fire on their pages (**half-installed analytics is a FAIL** — it looks
installed and measures nothing); loader absent → no event may render (or every
page throws), and the OFF state is a **WARN naming the work**, never a silent
pass. `selftest.py` proves both directions plus the unreachable case.

## What only the live site can verify — the launch runbook

Do this on staging with the real ID, then once more on production after cutover:

1. **DebugView.** GA4 Admin → DebugView; browse with the `?debug_mode=1` or the
   GA Debugger extension. Walk the funnel in one session: product page
   (`view_item`) → add to bag (`add_to_cart`, both the AJAX loop button and the
   PDP form) → checkout (`begin_checkout`) → place a **test COD order**
   (`purchase`).
2. **The refresh test.** Reload the order-received page and reopen it from the
   confirmation email. `purchase` must appear **once** in DebugView, ever.
3. **The join test.** In the purchase event, confirm `items[].item_id` reads
   `FDY-…` and matches the same product's `g:id` in `/?foodify-feed=1`.
4. **The PII test.** In DebugView, open every event's parameters and confirm no
   name, phone, email or address appears anywhere.
5. **GSC.** Search Console → add/confirm the property (domain property via DNS
   is the durable choice), submit `sitemap_index.xml`, and confirm the WP-01
   baseline capture can be compared against it in the 30-day report.
6. Record the run in `tasks/`-style notes with screenshots — the hypercare
   report compares against the WP-01 baseline, and an unverified funnel is not
   a baseline.

## Not done, and why

- **`add_to_cart` on the AJAX path carries no item detail** — WooCommerce's
  `added_to_cart` browser event does not expose the product cleanly without
  fragile DOM-scraping; the event fires with currency only. The server-side
  (non-AJAX) path carries full items. Enrich later if list-level analysis is
  wanted; a fragile scrape that breaks silently is worse than a thin event.
- **`view_item_list` / `select_item`** (impression tracking) — real volume
  first; impression events at 44 products add noise before they add signal.
- **Consent banner / Consent Mode** — DPDP does not mandate a GDPR-style
  banner today; the `foodify_analytics_enabled`-shaped decision belongs with
  the privacy-policy refresh in the compliance pass, not silently in code.
- **GA4 property creation and the measurement ID** — client's Google account.

## Tests

```
php tests/wp13-test.php     # 24 — purchase-once, feed-id join, PII, breakout
python3 tests/selftest.py   # 49 — half-installed analytics caught both ways
```
