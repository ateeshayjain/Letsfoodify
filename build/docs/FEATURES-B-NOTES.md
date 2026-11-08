# Lane B — search, buy again, cancel & returns (Nov 2026)

Files: `inc/search.php`, `inc/buy-again.php`, `inc/order-actions.php`,
`parts/header.html` (search block), `templates/front-page.html` (shortcode),
`templates/product-search-results.html` (new), style.css LANE B block,
bootstrap.sh LANE B block. Tests: `tests/search-test.php` (68),
`tests/account-actions-test.php` (110).

## Decisions

**Search index is a REST endpoint, not JSON in the page.** `GET
/wp-json/foodify/v1/search-index`, `Cache-Control: public, max-age=600` + ETag,
fetched on first focus of the field and kept 10 minutes in sessionStorage.
Printing it into every page would cost ~8 KB per page view for every visitor and
go stale for the life of the page cache. Not on the load path, so outside the
WP-04 request budget.

**One ranking, two languages.** `foodify_search_rank()` (PHP) ranks the results
page through `pre_get_posts`; `foodify_search_engine_js()` ranks suggestions.
The test runs 25 queries through both in node and fails on any difference.
Tiers: name prefix > word prefix > keyword/ingredient prefix > one typo (query
words ≥5 letters, insert/delete/substitute/adjacent swap, on code points so
Devanagari works); sold out after every in-stock match.

**The header form is static HTML.** `parts/header.html` carries
`foodify_search_form_html()` verbatim in a core/html block (pinned by test), so
search works on a cached page with no PHP and no JavaScript. It ships closed; the
icon opens it natively (`<details>`). The script opens it from 1200px.

**Phone header width.** At 360px the row (logo, menu, search, account, cart) did
not fit — it already overflowed by ~4px in WordPress before search was added.
Below 600px the row gap tightens and the cart pill drops its amount *visually*
(still in the DOM and the drawer). Change this if the client wants the amount
back on phones; something else must then give.

**"Dispatched" = status Completed.** WP-10's completed email is "it has left
us"; the Today screen's "Awaiting dispatch" is processing. Cancel is allowed in
pending / on-hold / processing. A courier integration that ships while still
processing must set the `foodify_order_dispatched` filter.

**Cancel confirm is a two-step disclosure, not `confirm()`.** The house uses
`confirm()` for deleting an address; an order cancel without JavaScript would
then be one tap. The `<details>` explains what happens to the money and holds
the "Yes, cancel" button, and works without JavaScript.

**Refunds.** Status → cancelled first (WooCommerce restocks), then, only for a
paid (date_paid set) non-COD order, `wc_create_refund( refund_payment )`. The
`woocommerce_order_fully_refunded_status` filter keeps it Cancelled. On failure:
order note, email to the New Order recipient, honest message to the customer.

**Returns** are off until `foodify_return_window_days` is set (1–365). The window
counts from `_foodify_delivered_at` (new "Mark as delivered" order action) or,
if absent, the completed date. Custom status `wc-return-requested`.

**Buy again** renders only for logged-in users, and a logged-in front page is
sent `no-store, private` + `X-Foodify-Private: 1` + DONOTCACHEPAGE.

## Requests to other owners
- product-editor.php: add `'search_keywords' => [ 'label' => 'Search keywords',
  'type' => 'text', 'hint' => 'Other names people type: Hindi, regional, e.g. राजमा, rajma chawal' ]`
  to `foodify_editor_fields()` so the shop can type them (meta `_foodify_search_keywords`, already read).
- smoke-test.sh: assert `X-Foodify-Private: 1` on a logged-in `/` request.
