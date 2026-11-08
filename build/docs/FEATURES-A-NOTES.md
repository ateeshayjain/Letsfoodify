# Lane A — product page and listing features (Nov 2026)

Delivery date by PIN · veg mark · unit price · back-of-pack shot · notify me
when back in stock · offers. Tests: `php tests/lane-a-test.php` (148 checks,
which also runs `node tests/delivery-vectors.js`, the JS twin of the delivery
rule, against `tests/fixtures/delivery-vectors.json`).

## Decisions

**Delivery date is computed in the browser, from the same rules as the PHP.**
The zone rules travel to the page as JSON (`data-config`), and
`foodify_delivery_script()` does the date arithmetic in IST. The PHP
`foodify_delivery_answer()` is the tested reference; 20 shared vectors bind
the two. Why not a REST endpoint that computes the date:
1. the answer depends on the clock (cut-off hour); a server-rendered date on a
   page-cached product page is frozen when the cache fills, so "ships today"
   would be read at 9 pm;
2. the only round trip left is the PIN → state lookup checkout already uses
   (`foodify/v1/pin`, cached a week); a compute endpoint is a second,
   uncacheable request per check, against the WP-04 budget;
3. the prototype has no server: it passes `window.foodifyPinLookup` instead.
Cost: a phone with a wrong clock sees a wrong date. Checkout states shipping
before payment, so this is a preview, not a contract.

**No COD-by-PIN line.** payments.php does not restrict COD by area, so "COD
not available here" would be unenforced. If the courier restricts COD by PIN,
that belongs in payments.php first; the widget can then say it.

**Nothing is a default.** Unset `foodify_delivery_zones` → no widget. The
config also requires `cutoff_hour` and `no_dispatch_weekdays` (may be `[]`),
because "ships every day" is a claim and an absent key is not consent to make it.

**The two hard-coded assurances on the product page were removed**
(`templates/single-product.html`: "Cash on delivery available", "Free shipping
over ₹599"). They now appear in the Offers list, derived from the COD gateway
and the shipping zone. Typed into the template they were commercial numbers no
setting could change, and would have been printed twice. Wireframe 02 rule 8
said "exactly five"; it is now three. Design owner to confirm.

**Veg mark** reads the declared `_foodify_diet` (product editor select:
Vegetarian / Non-vegetarian / Vegan) by EXACT value. Blank = no mark (the
admin column already counts it as a missing declaration). Vegan carries the
vegetarian mark (vegan food is vegetarian). It is drawn in CSS (two 1px
rings so it reads at card size) in the `leaf` token.

**Unit price** sizing: 16px (`base`, mute) on the product page; `sm` on
cards in `char`, matching the existing card meta line `.fd-yield` (sm) — the
AUDIT-HIG fix P2-10 accepted `sm` as the floor for secondary label text. This
is below CLAUDE.md's 16px floor for read text, deliberately consistent with
the card's other meta line; if the design owner applies 16px to card meta,
change `.fd-products .fd-unit-price` and `.fd-products .fd-yield` together.

**Gallery.** There was no shot list in the theme — only in the preview
(`render-preview.py`). `foodify_gallery_shots()` in product-spec.php is now
the list (five shots, shot 5 "Back of pack — the printed label, legible",
required), with `foodify_gallery_gaps()` and `foodify_shot_keys_from_tags()`.
No existing editor logic treats missing shots as gaps, so nothing consumes
the gaps yet (the editor is not this lane's file).

**Stock alerts: one email, then deleted.** No confirmation email (that would
be a second email). The cancel control is the "Cancel this alert" button on the
confirmation the customer sees after signing up; after the restock email the
address is already gone, so the email says so instead of carrying an
unsubscribe link. The cancel token is stored as sha256 and reaches the page
through a one-time flash transient, never in a URL (GA4 records page URLs).
The WordPress privacy exporter/eraser know about the addresses.

## Legal notes — NEED VERIFICATION against the gazette / regulation text

- **Legal Metrology (Packaged Commodities) Amendment Rules, 2022** — our
  reading (not verified; the gazette was not reachable from here): Rule 6
  declarations gained a mandatory **unit sale price** beside MRP. For net
  quantity above 1 kg / 1 l it is per kg / per l; **below 1 kg / 1 l it is per
  gram / per ml** (not per 100 g); for goods sold by number, per unit. Rule
  6(10) requires the Rule 6 declarations on the e-commerce listing. So:
  (a) the brief's "per 100 g" is the Amazon/Blinkit comparison convention and
  may not be the declared basis; (b) the rule's figure is on **MRP**, while
  this line uses the selling price. If counsel confirms, add a
  "Unit sale price" row to Pack & label computed on MRP at the rule's basis,
  and keep this line as the shopping aid. `foodify_unit_price()` is one
  function to change.
- **FSSAI vegetarian symbol** — Food Safety and Standards (Labelling and
  Display) Regulations 2020: a green filled circle inside a square with a green
  outline, near the product name; non-veg is a brown filled triangle in a
  brown-outlined square. The regulation sets minimum sizes relative to the
  pack; on a screen we use 16px (card) / 24px (H1). Confirm the regulation has
  no colour specification the `leaf` token conflicts with. Vegan products
  also have FSSAI's separate vegan logo (Vegan Foods Regulations 2022) — not
  drawn here.
- **DPDP Act 2023** — the alert is a service the person asks for, not
  marketing: notice at collection (the privacy line), deletion once the
  purpose is served (§8(7)), withdrawal as easy as giving (cancel button,
  privacy eraser). A retention period for alerts that are never sent is a
  policy decision → `foodify_stock_alert_retention_days`.

## Options (all client to confirm — nothing is written by bootstrap.sh)

| Option | Shape | Suggested (client to confirm) |
|---|---|---|
| `foodify_delivery_zones` | `{zones:[{label, states[], pin_prefixes[], min_days, max_days}], cutoff_hour, no_dispatch_weekdays[], no_delivery_weekdays[], holidays[]}` | Delhi NCR (DL + PIN prefixes for Noida/Greater Noida/Ghaziabad/Gurugram/Faridabad) 1–2 days; North (UP, HR, PB, CH, HP, UK, RJ) 2–4; metros/rest 3–6; NE, J&K, LA, islands 5–8; cut-off 14 (2 pm IST); no dispatch on Sunday `[7]`; courier holidays. **All from the courier's actual SLA**, not these guesses. |
| `foodify_stock_alert_retention_days` | integer days | 180 |
| `foodify_public_offer` | a WooCommerce coupon code | unset (no coupon advertised) |

## Found on the way

- **No online payment gateway is configured anywhere** (bootstrap.sh enables
  COD only). The approved "Pay now — save ₹25" therefore cannot happen on a
  fresh build, and the Offers list correctly omits it until a gateway is
  enabled. payments.php's `prepaid_flat 25` is also a business value living in
  theme code (pre-existing, WP-07).
- `foodify_delivery_promise()` ("Ships across India in 3–5 days") is a single
  hard-coded promise in business-profile.php. Once zones are configured it
  can disagree with the widget on the same page. Owner of business-profile.php
  to decide: derive it from the zones, or drop it on product pages.
