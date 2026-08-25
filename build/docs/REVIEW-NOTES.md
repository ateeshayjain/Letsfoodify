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

**2 — The two published client documents still say ten weeks.** They were written assuming
a pod of four or five. You now have one developer plus a host migration. `docs/SOLO-PLAN.md`
says 15 weeks. **Do not send the Rebuild Scope to the client until those numbers agree** —
it is a commitment to a date you cannot hit.

**3 — Plugin slugs and Rank Math option keys are asserted from memory, not verified.**
`seo-by-rank-math`, `judgeme-product-reviews-woocommerce`, and every
`rank-math-options-titles` sub-key in `bootstrap.sh`. Run `--dry-run` first and check each
one resolves. A wrong option key fails silently — the setting simply never applies.

**4 — Removing `billing_last_name` may break the payment gateway.** Razorpay and some GST
invoicing plugins expect a surname field. Test a real transaction before assuming the
nine-field checkout is safe. If it breaks, keep the field and hide it, populating it from
the full-name split.

**5 — Checkout now calls a third-party API from the customer's browser.** The PIN lookup
hits `api.postalpincode.in`, an unofficial free service, on your most critical page. It
degrades silently if unavailable, but it sends customer PIN codes to a third party — that
needs a privacy-policy line, and the client should agree to it. A bundled offline PIN
dataset avoids both problems.

**6 — The fonts referenced in `theme.json` do not exist in this repo.** `Fraunces-Variable.woff2`
and `InstrumentSans-Variable.woff2` must be downloaded into `assets/fonts/`. Until then the
theme silently falls back to Georgia and system sans, and nothing will warn you.

**7 — Recurring cost was never totalled.** Blocksy Pro, Rank Math Pro, Digits, Judge.me,
Metorik, ShortPixel, UpdraftPlus, plus SMS per-message. Roughly ₹60–95k in year one
depending on the Metorik tier. Put it in front of the client before you commit them.

**8 — None of this has run against a real WordPress install.** Everything here is
syntax-checked only. `theme.json` parses, every PHP file lints clean, both shell scripts
pass `bash -n`. That is not the same as working. Budget a full day to shake it out on
staging before the schedule depends on it.

**9 — The stock-count feature contradicts nothing, but check the data.** `product-display.php`
shows "Only N left" when stock is 5 or fewer. If the client does not actually manage stock
per SKU, it will never fire — which is correct behaviour, but they may expect otherwise.
