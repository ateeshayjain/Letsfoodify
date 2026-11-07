# Review notes — read before shipping

Written against my own work. Ordered by what would hurt most if missed.

## Corrected already

| Was | Now |
|---|---|
| Published contrast table had invented figures — Leaf Ink on Leaf wash was claimed AAA at 7.4:1, actually **6.2:1 (AA)**; Charcoal on Paper claimed 14.9:1, actually 13.9:1 | All figures recomputed and the Playbook republished |
| `--fd-line` (#E6DCCB) used as the form-input border. **1.3:1** — fails WCAG 1.4.11's 3:1 floor for UI controls | New `--fd-line-strong` #9C8A6E at 3.2:1; inputs switched |
| Flame Ink on Flame wash used for badge and alert text at 4.3:1 — **fails AA** | New `--fd-flame-deep` #B03D14 at 5.1:1 |
| Partner email linked to `wc_get_account_endpoint_url('partner')`, an endpoint that was never registered — every email would 404 | Endpoint registered, with a real partner dashboard behind it |
| Two partner coupons on one order each credited the **full** order value — silent double-counting of revenue | Highest-discount code wins; the rest logged on the order for audit |
| `bootstrap.sh` ran a raw `DELETE` against a **guessed** table prefix (`|| echo wp_`) | Moved to `clean-elementor-meta.php`, prefix via `$wpdb`, dry-run by default |
| Phase 1 was labelled "safe on the live site" but ran `wp rewrite structure` — that rewrites **every URL on the store at once** | Structure change removed from the prod phase; now it warns instead |
| Child theme activated before it was deployed; `set -e` would abort the whole run | Guarded with `wp theme is-installed` |
| `get_term_link()` returns `WP_Error` on failure; cast straight to string | Wrapped in a `$safe_term_link` helper |

## Still open — decide before you ship

**1 — The hosting recommendation rests on a polluted measurement.** I reported every HTML
page returning `cache=BYPASS` and told you page caching was off. Earlier in the same session
I had added a product to the cart in that browser. WooCommerce sets cart cookies, and every
sane host-level cache correctly bypasses for a session with a cart. **My test almost
certainly measured my own cart cookie, not their configuration.** Re-run it clean — a
private window, no cart, `curl -sI https://letsfoodify.com/ | grep -i hcdn-cache` — before
committing to a migration. If it says HIT, the caching is fine and the move is optional.

**2 — ~~The two published client documents still say ten weeks.~~ RESOLVED 7 Nov.**
`planning/SCOPE.md` §9 and `planning/scope-artifact.html` now say ~15 weeks to launch and
17 to handover, one developer, host move included — the same as `docs/SOLO-PLAN.md`.

**3 — Plugin slugs and Rank Math option keys are asserted from memory, not verified.**
`seo-by-rank-math`, `judgeme-product-reviews-woocommerce`, and every
`rank-math-options-titles` sub-key in `bootstrap.sh`. Run `--dry-run` first and check each
one resolves. A wrong option key fails silently — the setting simply never applies.

**4 — ~~Removing `billing_last_name` may break the payment gateway.~~ RESOLVED in code.**
The form asks for one full name; `inc/checkout-fields.php` splits it back into first and
last name when the order is created and when an address is saved, so Razorpay, the
courier payload and any invoice plugin still find a surname. Still worth one real
test payment on staging, as with everything payment-side.

**5 — ~~Checkout calls a third-party API from the customer's browser.~~ RESOLVED 7 Nov.**
The PIN lookup is answered by the site itself (`inc/pincodes.php`, endpoint
`/wp-json/foodify/v1/pin/<pin>`) from India Post's directory bundled in
`assets/data/pincodes.json` (GODL-India, attribution in `assets/data/SOURCE.md`). No PIN
code leaves the site. The bundled copy is a 2015 snapshot with Telangana and Ladakh
corrected; **download the current directory from data.gov.in and rebuild with
`tools/build-pincodes.py` before launch.** Tested in checkout-test.php and in real
WordPress by the boot test.

**6 — ~~The fonts referenced in `theme.json` do not exist in this repo.~~ RESOLVED.**
Both files are in `theme/foodify/assets/fonts/` with checksums in `SHA256SUMS`, and
`scripts/package-theme.sh` refuses to build a zip that does not contain them.

**7 — Recurring cost was never totalled.** Blocksy Pro, Rank Math Pro, Digits, Judge.me,
Metorik, ShortPixel, UpdraftPlus, plus SMS per-message. Roughly ₹60–95k in year one
depending on the Metorik tier. Put it in front of the client before you commit them.

**8 — Half-resolved: it runs in real WordPress, NOT yet in WooCommerce.**
`scripts/wp-boot-test.sh` loads the theme into a real WordPress and fails on any
PHP diagnostic; `scripts/package-theme.sh` boots the shipped zip the same way.
WooCommerce could not be installed in the build environment (wordpress.org
blocked), so every WooCommerce block, the cart, checkout and payment paths are
first seen on staging. Budget a full day to shake that half out before the
schedule depends on it — `docs/INSTALL-ON-STAGING.md`.

**9 — The stock-count feature contradicts nothing, but check the data.** `product-display.php`
shows "Only N left" when stock is 5 or fewer. If the client does not actually manage stock
per SKU, it will never fire — which is correct behaviour, but they may expect otherwise.

**10 — GST invoices: plugin added, settings not.** `bootstrap.sh` now installs the free
PDF Invoices & Packing Slips plugin (slug checked against its own repository). Its
settings — GSTIN, registered address, numbering, which emails carry the PDF — are set
once in wp-admin and then copied into `bootstrap.sh`; the option keys have not been
checked against the installed plugin, and a wrong key fails silently (item 3). Needs
Foodify's GSTIN.
