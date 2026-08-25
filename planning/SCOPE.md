# letsfoodify.com — revamp & SEO scope

**Client:** The Foodify Company (AVAC Ventures, Parx Laureate, Noida 201304)
**Site:** https://letsfoodify.com — WordPress + WooCommerce, live
**Introduced by:** Nalin Agarwal (personal connection)
**Status:** PRE-QUOTE. Written from the WhatsApp brief (20–21 Aug) and public pages
only. Nothing below is confirmed with the client — §11 lists what must be answered
before this becomes a proposal.

> **Evidence limit, stated up front.** letsfoodify.com is blocked by this
> environment's network egress policy, so the site was never loaded directly.
> Everything in §2 comes from search-engine snapshots of the site's own pages.
> Plugin stack, theme, hosting, payment gateway, traffic and current Core Web
> Vitals are therefore **unknown** — they are the first item of Phase 0, and the
> effort in §10 carries a contingency because of it.

---

## 1. The brief, decoded

Nalin's list, verbatim, mapped to build items:

| # | What he said | What it means to build | Workstream |
|---|---|---|---|
| 1 | "e-commerce website" | Revamp the existing WooCommerce store, not a new stack | W2, W3 |
| 2 | "SEO friendly" | Technical SEO + on-page + a migration that doesn't lose rankings | W7 |
| 3 | "connected to google reviews" | Three separate things clients conflate — see §5 | W8 |
| 4 | "create a google profile (GBN I think)" | Google **Business** Profile. But see §5: for a D2C catalogue, Merchant Center is the higher-ROI surface | W8 |
| 5 | "login using mobile number… or continue as guest" | OTP auth + saved address book; guest checkout preserved | W4 |
| 6 | "admin friendly dashboard" | Curated ops dashboard + a staff role that isn't full admin | W6 |
| 7 | "coupon code… email to the person whose code was used (inventory & value)… visible on backend" | Influencer/partner coupon attribution. **The one genuinely custom build.** See §6 | W5 |
| 8 | "user friendly UI/UX, aesthetically appealing" | Design system + rebuilt PLP/PDP/cart/checkout | W2 |
| 9 | "mobile responsive" | Mobile-first, not a desktop design squeezed down | W2, W3 |

**Not in the brief but non-negotiable for an Indian online food seller** —
payments, shipping, GST invoicing, and FSSAI/Legal Metrology display duties (§8).
These are in scope by necessity; if the client already has them solved, they drop
out and the estimate falls.

---

## 2. What the business actually is

D2C packaged food. Instant, preservative-free, home-style Indian food — mostly
dehydrated product that reconstitutes with hot water. Three ranges:

- **Foodify Express** — add hot water, ready in minutes. Positioned at travellers,
  students, the elderly, families.
- **Foodify Hot & Fresh** — minimal stove cooking.
- **Foodify Flavors** — chutneys, stir with cold water.

Observed SKUs: Dal Fry (Jain), Dal Makhni, Idli Sambhar, Pav Bhaji, Palak Gravy,
Dal Khichdi, Aloo ka Mazaa, Super Millet Idli, Sprouted Millets & Beetroot Cheela,
Masala Chai, Lemon Masala Tea, Coconut Red Chutney + chutney combos.

Prices ₹100 – ₹1,800+, singles and combos, several showing sale prices. Shelf life
is 9–12 months and is already published per product — good, that is exactly the
field the e-commerce shelf-life rule turns on (§8).

Existing URL shapes (these are the SEO asset — see §7):

```
/                         /shop/                  /express/
/contact-us/              /product-category/meals/   /product-category/chutney/
/product/<slug>/          e.g. /product/pav-bhaji/, /product/super-millet-idli/
```

**Read on the business:** a Jain variant, millet SKUs, and an elderly/traveller
framing say the buyer is health-and-convenience led, skews 35+, and a meaningful
slice is diaspora or travel-driven. That should steer both the design and the
keyword set, and it makes the gifting/combo path worth merchandising properly.
Confirm against their real order data in Phase 0 rather than taking my word for it.

---

## 3. Recommendation in one page

**R1 — Revamp on WooCommerce. Do not rebuild headless.**
Every requirement in the brief has a mature WooCommerce path. The client's admin is
non-technical and already knows this back office. A headless/Next.js rebuild would
roughly double build cost, add a permanent maintenance burden they cannot service
in-house, and put the existing indexed catalogue at risk for no commercial gain at
this size. Revisit only if they outgrow it — realistically past ~₹50L/mo GMV or a
multi-warehouse operation.

**R2 — Treat this as a replatform of the presentation layer, with the URLs frozen.**
New theme, rebuilt templates, new design system; product/category URLs preserved
byte-for-byte wherever possible. This is the single decision that protects the "SEO"
half of the brief (§7).

**R3 — Coupon attribution: build the thin custom version now, buy the platform later.**
If coupon owners are a handful of known partners, a ~4–6 day custom add-on does
exactly what Nalin described and carries no licence fee. Move to AffiliateWP when
they need self-service signup, payouts and 50+ partners. Trigger and rationale in §6.

**R4 — OTP login with guest checkout kept as the default path.**
Mobile-OTP accounts are right for address reuse, but forcing registration is the
classic Indian D2C conversion killer. Guest stays the primary flow; the account is
offered post-purchase ("save this address for next time"). **Hard dependency: TRAI
DLT registration for transactional SMS — 1–2 week lead time, client-owned, must
start in week 1** (§4/W4).

**R5 — "Google profile" is probably the wrong Google surface to prioritise.**
Google Business Profile helps brand and local search, and they have a Noida address
so they can hold one. But for a packaged-goods catalogue the surface that actually
moves revenue is **Google Merchant Center** — free product listings and a Shopping
feed. Do both; lead with Merchant Center. Say this to the client plainly, because
they asked for the other one.

**R6 — Compliance display is a launch blocker, not a nice-to-have.**
FSSAI licence display, packaged-commodity declarations, and the e-commerce shelf-life
rule are legal duties for an Indian online food seller (§8). Build them into the PDP
template rather than bolting them on. Client should confirm specifics with their own
FSSAI consultant — I am scoping the build, not giving legal advice.

---

## 4. Workstreams

Effort is in **dev-days** for a small team. Ranges are honest, not padded; the spread
is mostly the Phase 0 unknowns.

### W1 — Discovery & audit — 3 d
Access handover (hosting, WP admin, domain/DNS, GA4, Search Console, payment gateway,
current SMS/email senders). Full plugin and theme inventory. Baseline capture: GA4
traffic and revenue, GSC queries/pages/impressions, Core Web Vitals, current
conversion rate. **Complete URL inventory export** — this becomes the migration
contract in §7. Current order volume and AOV, to size everything else.
*Output: audit note + baseline metrics sheet + URL inventory.*

### W2 — UI/UX design — 8–10 d
Design system (type, colour, spacing, buttons, product card) then screens: home, shop
/ PLP with filters, PDP, cart, checkout, account + address book, order history, the
three range landing pages (Express / Hot & Fresh / Flavors), contact, and content page
template. Mobile-first artboards; desktop derived. Food photography direction —
existing shots get audited and a shot-list issued for gaps.
*Output: Figma file, mobile + desktop, with states (empty, loading, error, OOS).*

### W3 — Front-end build — 10–12 d
Theme build against the design system. Templates for PLP/PDP/cart/checkout/account.
Performance is a build-time constraint, not a later pass: responsive images and modern
formats, lazy loading, critical CSS, font strategy, minimal plugin JS. Target
Core Web Vitals in the green on 4G mobile.
*Output: theme in staging, all templates, passing CWV.*

### W4 — Accounts & checkout — 3–4 d
Mobile-OTP registration and login. Saved address book, default address, reuse at
checkout. Guest checkout preserved and primary. Order history. Checkout field trim —
every removed field is conversion.
Dependencies: SMS provider (MSG91 / Fast2SMS / Gupshup) **and DLT template registration**.
WhatsApp OTP via a WABA provider is worth costing as an alternative — delivery in
India is materially better and it opens order-status notifications later.
*Output: working OTP auth, address book, guest path, on staging.*

### W5 — Coupon attribution engine — 4–6 d
The custom piece. Full functional spec in §6.

### W6 — Admin dashboard — 2–3 d
A curated WooCommerce landing screen: today's orders, revenue, low stock, pending
shipments, coupon performance (from W5). A **Shop Staff role** that can process orders
without holding full admin. Bulk price/stock editing. Order-status email templates
in the brand's voice.
*Output: dashboard screen, role, documented in the runbook (W11).*

### W7 — SEO: technical, migration, on-page — 5 d technical + 5 d content
The migration risk plan is §7 and is the most important part of this workstream.
Technical: schema (Product, Offer, AggregateRating, Organization, BreadcrumbList),
XML sitemaps, robots, canonicals, internal linking, pagination, faceted-URL control.
On-page: rewritten title/meta across the catalogue, category copy, PDP copy that
answers real queries ("instant idli sambar", "ready to eat jain food", "travel food
india", "millet breakfast instant"), FAQ blocks with FAQPage schema.
*Output: 301 map, schema live and validating, rewritten catalogue metadata, keyword map.*

### W8 — Google surfaces — 3 d
Google Business Profile: create/claim, verify, categories, photos, hours, posts.
**Google Merchant Center: account, product feed from WooCommerce, feed validation,
free listings live** — this is the revenue item (R5). Reviews, all three senses
disambiguated in §5. GA4 e-commerce events verified end to end (view_item →
add_to_cart → begin_checkout → purchase), GSC property confirmed on the new build.
*Output: GBP live, Merchant Center feed approved, review surfaces live, analytics verified.*

### W9 — Compliance & trust — 2 d
FSSAI licence number displayed site-wide and on invoices. Per-product mandatory
declarations. Shelf-life-at-delivery rule enforced (§8). Refreshed policy pages —
shipping, returns, privacy, terms. GST-compliant invoicing. Trust surfaces: contact
route, real address, order-status comms.
*Output: compliance checklist signed off, policy pages live.*

### W10 — QA, launch, hypercare — 5 d + 3 d
Cross-browser and real-device QA. Full checkout regression on live payment rails.
Load check. Launch runbook with the 301 map applied at cutover and a rollback path.
Then 30 days of hypercare: GSC crawl errors and coverage, rankings vs the W1
baseline, conversion vs baseline, bug fixes.
*Output: QA sign-off, launch, 30-day post-launch report against baseline.*

### W11 — Handover — 2 d
Admin runbook for a non-technical operator: add a product, run a sale, issue a partner
coupon, read the dashboard, ship an order. Recorded walkthroughs. Maintenance plan
(backups, updates, who to call).

---

## 5. "Connected to Google reviews" — three different asks

Clients say this and mean any of three things. Price and build them separately:

1. **Show GBP reviews on the site.** A widget pulling the Business Profile's reviews
   onto home/PDP as social proof. Reads the Places API. Easy. *This is most likely
   what Nalin means.*
2. **Star ratings in Google search results.** Collect *product* reviews on-site and
   mark them up with AggregateRating schema so stars appear in organic listings.
   This is the SEO-valuable one and needs a review-collection flow (post-delivery
   email/WhatsApp ask), not just a widget. **Recommend this gets built.**
3. **Google Customer Reviews / seller ratings.** Opt-in survey via Merchant Center,
   produces a seller rating for Shopping ads. Only worth it once Merchant Center
   is running and volume supports it. *Phase 2.*

Confirm which he wants. Build (1) + (2); park (3).

---

## 6. The coupon attribution engine — functional spec

### What was asked

> "Coupon code is essential so that an email is sent to the person who gets an email
> mentioning that a sales has happened using their coupon code (inventory & value).
> Also this should be visible on the backend dashboard."

Read: **partner/influencer coupon attribution.** Each partner gets a code. When a
customer buys with it, the partner is emailed that a sale happened, with what sold and
for how much. Admin sees the same, aggregated.

"Inventory & value" reads as *which items and what quantity* (inventory) and *the order
value* (value). **Confirm this reading with the client** — it could also mean the
partner's remaining redemption allowance. The spec below covers the first reading and
notes where the second would change it.

### Build vs buy

| | Custom add-on | AffiliateWP + Affiliate Coupons |
|---|---|---|
| Cost | 4–6 dev days, no licence | ~$149–299/yr, ~2 d setup |
| Does what was asked | Exactly | Yes, plus a lot more |
| Partner self-service portal | No | Yes |
| Commission calc & payouts | No | Yes |
| Fraud/self-referral controls | Basic | Mature |
| Maintenance | Ours, forever | Vendor's |

**Recommendation:** custom **if** partners are few (<20), known personally, and not
paid a per-sale commission through the system. The moment they want partners logging
in to see their own numbers, or automated commission and payouts, buy AffiliateWP —
writing payouts, TDS handling and fraud controls from scratch is a bad trade.

**This decision is blocked on Q7–Q9 in §11.** Do not start building until answered.

### Data model (custom route)

Extend the native WooCommerce coupon — do not invent a parallel discount system.

```
Coupon meta (on wp_posts / shop_coupon):
  _foodify_owner_name
  _foodify_owner_email        ← notification target
  _foodify_owner_phone        ← optional, for WhatsApp later
  _foodify_notify_enabled     ← bool
  _foodify_commission_pct     ← optional, reporting only in v1

Attribution record (custom table, one row per redeeming order):
  id, order_id, coupon_id, coupon_code, owner_email,
  order_total, discount_amount, commission_amount,
  line_items_json           ← SKU, name, qty, line total  ("inventory")
  order_status, notified_at, created_at
```

A custom table, not order meta — it keeps reporting queries cheap and survives order
deletion for accounting.

### Trigger

Write the attribution row on **payment complete**, not order creation — an unpaid or
failed order must never notify a partner that they made a sale.
Handle the reversal path: on refund or cancellation, mark the row reversed and (per
Q9) optionally send a correction. Silent reversal is how partner trust dies.

### Partner email

Sent to `_foodify_owner_email` on payment complete. Contains: partner name, code used,
order date, **line items — SKU, product, qty, line value**, order value, discount given,
commission if configured, and month-to-date totals for that code. Plain, branded,
mobile-legible. **No customer PII** — no name, address, phone or email of the buyer.
That is a privacy line and a DPDP-Act-shaped one; state it to the client explicitly so
they don't ask for it later.

### Admin dashboard

A **Coupon Performance** screen: per code — redemptions, gross value, discount given,
commission owed, last used, partner contact. Date filtering, CSV export, drill-through
to orders. Surfaced as a summary widget on the W6 dashboard.

### Test cases worth writing

Payment-complete fires exactly one notification per order; a failed-then-retried
payment does not double-notify; refund reverses the row; a coupon with notification
disabled emails nobody; an order with two coupons attributes to both without
double-counting revenue; deleting a coupon does not orphan history; the email contains
zero customer PII.

---

## 7. The SEO migration risk — read this before quoting

**A WordPress "revamp" is the most common way a store loses its rankings.** The brief
asks for a revamp *and* better SEO in one sentence; those pull against each other
unless the migration is treated as a first-class deliverable. The catalogue at
`/product/<slug>/` and `/product-category/<slug>/` is an accumulated asset. A new theme
that changes permalink structure, drops the old category taxonomy, or renames slugs
"for tidiness" throws it away — and the loss shows up 3–6 weeks after launch, long
after everyone has agreed the project went well.

Non-negotiables:

1. **Freeze the URL structure.** Product and category URLs are preserved exactly.
   Any change must be justified individually and carry a 301.
2. **Complete URL inventory before design starts** (W1) — crawl the live site, export
   every indexed URL with its GSC impressions. That list is the migration contract.
3. **A 301 map for every URL that does change**, applied at cutover, tested on staging
   with a crawler before go-live. Not "we'll add redirects after."
4. **Preserve title/meta/schema through the theme change** — a new theme frequently
   drops the SEO plugin's output silently. Verify per template.
5. **Staging is noindexed; verify the live site is not.** A `noindex` shipped to
   production is the classic launch catastrophe. Explicit checklist item at cutover.
6. **Baseline before, measure after.** W1 captures the numbers; the 30-day hypercare
   report compares against them. Without a baseline nobody can tell whether the
   revamp helped or hurt, which is how these projects avoid accountability.

Tell the client this in the kickoff. It reframes "why is SEO a separate line item"
before it gets asked.

---

## 8. India compliance — food, sold online

Flagging what applies. **The client must confirm specifics with their own FSSAI
consultant / counsel — this is a build checklist, not legal advice.**

- **FSSAI licence number displayed** on the website and on invoices. Mandatory for a
  food business operator; e-commerce sellers must surface the licence of the entity
  selling.
- **Packaged-commodity declarations** (Legal Metrology, e-commerce rules) on each
  listing: MRP inclusive of taxes, net quantity, best-before/expiry, veg/non-veg mark,
  ingredients, allergens, nutritional info, country of origin, and consumer-care
  contact. These belong in the **PDP template as structured fields**, not free text in
  the description — that way they are enforceable, and they feed the Merchant Center
  feed (W8) at no extra cost.
- **Shelf-life-at-delivery rule.** FSSAI requires food delivered online to carry a
  meaningful remaining shelf life at delivery (commonly cited as 30% remaining, or
  45 days, whichever is less). Their 9–12 month shelf lives make this comfortable,
  but stock rotation should surface in the admin so old batches aren't shipped.
  *Verify the current threshold with their consultant — the rule has been revised.*
- **GST invoicing** — compliant tax invoice per order, correct HSN and rate for each
  food category.
- **DPDP Act** — the OTP flow and the coupon emails both touch personal data. Privacy
  policy must reflect what is actually collected; partner emails carry no buyer PII (§6).

---

## 9. Phasing

| Phase | Contents | Calendar |
|---|---|---|
| 0 — Discovery | W1. **Client starts DLT registration in parallel — week 1.** | Week 1 |
| 1 — Design | W2, signed off before any front-end build | Weeks 2–3 |
| 2 — Build | W3, W4, W5, W6 | Weeks 4–7 |
| 3 — SEO & Google | W7, W8, W9 — overlaps build | Weeks 5–8 |
| 4 — Launch | W10 QA, cutover with 301 map | Week 9 |
| 5 — Hypercare | W10 monitoring, W11 handover | Weeks 10–13 |

**~9 weeks to launch, 13 to close.** Design sign-off (end of week 3) is the critical
path — every week it slips, the whole schedule slips. Say that at kickoff.

---

## 10. Effort & indicative cost

| Workstream | Days |
|---|---|
| W1 Discovery & audit | 3 |
| W2 UI/UX design | 8–10 |
| W3 Front-end build | 10–12 |
| W4 Accounts & checkout | 3–4 |
| W5 Coupon engine | 4–6 |
| W6 Admin dashboard | 2–3 |
| W7 SEO technical + content | 10 |
| W8 Google surfaces | 3 |
| W9 Compliance & trust | 2 |
| W10 QA, launch, hypercare | 8 |
| W11 Handover | 2 |
| **Total** | **55–63 dev-days** |

Add **15% contingency** for the Phase 0 unknowns (unknown plugin stack, unknown
hosting, unknown data quality in the catalogue) → **63–72 days**.

**Cost: apply your own rate card — I have not assumed one.** At a blended
₹6,000–₹12,000/day typical of a small Indian shop, that lands ₹3.8L–₹8.6L. Treat that
as a sanity band, not a quote.

**Recurring, client-borne (annual):**

| Item | Indicative |
|---|---|
| Managed hosting (Cloudways / Rocket.net tier) | ₹15,000–35,000 |
| Premium plugins (SEO, OTP, backup, review) | ₹25,000–60,000 |
| SMS OTP | ~₹0.15–0.25 per OTP |
| AffiliateWP, *only if* R3 flips to buy | ~₹13,000–26,000 |
| Maintenance retainer (recommended) | quote separately |

---

## 11. Open questions — these block the quote

**Commercial**
1. Who is paying and what's my role — build it, or spec it and oversee a vendor?
2. Is there a budget band already in the client's head? It decides custom vs buy in
   several places.
3. Is the 30-day hypercare in scope, or does it end at launch? (Recommend in — see §7.)

**Product**
4. Does the current site convert at all today? Orders/month and AOV size every decision
   below.
5. Payment gateway and shipping partner — existing, or in scope?
6. Is there a mobile app or marketplace presence (Amazon/Flipkart/BigBasket) this must
   sit alongside?

**Coupon engine — blocks W5**
7. How many coupon owners? Ten known influencers, or an open affiliate programme?
8. Do owners earn a commission, and does the system need to calculate and track payouts?
9. Do owners need to log in and see their own numbers, or is the email enough?
10. Confirm "inventory & value" = line items + order value. Or does "inventory" mean the
    partner's remaining redemption allowance?
11. What happens on refund — does the partner get a correction email?

**Content & assets**
12. Who writes product copy — them or us? 30+ SKUs of rewritten copy is real effort and
    it is priced in W7's content half.
13. Do they have usable food photography, or is a shoot needed? Not currently priced.
14. Who owns the GBP once created, and is there a physical location that can pass
    verification? (Noida address exists — confirm it's staffed and receives post.)

**Access**
15. Full access to hosting, WP admin, DNS, GA4, Search Console, gateway. Without GSC
    history there is no SEO baseline and no way to prove the revamp helped.

---

## 12. Explicitly out of scope

Named so they don't arrive as assumptions later:

- Headless/custom-stack rebuild (see R1)
- Mobile apps
- Photography and videography production
- Paid media management — Google/Meta ad spend and campaign ops
- Marketplace listings (Amazon, Flipkart, BigBasket, Blinkit)
- ERP/WMS integration, multi-warehouse inventory
- Subscription/recurring-order commerce — *worth raising separately, it suits this
  product category unusually well*
- Multilingual
- Ongoing SEO retainer past the 30-day hypercare
- FSSAI/GST filing or consulting — display duties only (§8)

---

## 13. Open threads

- **"Feedback for Aangan"** — Ateeshay's 21 Aug message carried a second item that
  Nalin never answered. Unrelated to this scope and not actioned here. Chase it.
- **Never loaded the live site.** Everything in §2 is from search snapshots. First hour
  of Phase 0 is a real crawl; expect §2 to move.
- **The "GBN" in the brief** is almost certainly Google Business Profile, but confirm —
  and use the conversation to raise Merchant Center (R5).

---

## 14. Sources

- [The Foodify Company](https://letsfoodify.com/) · [Shop](https://letsfoodify.com/shop/) ·
  [Express](https://letsfoodify.com/express/) · [Contact](https://letsfoodify.com/contact-us/)
- [Meals category](https://letsfoodify.com/product-category/meals/) ·
  [Chutney category](https://letsfoodify.com/product-category/chutney/)
- Product pages: [Pav Bhaji](https://letsfoodify.com/product/pav-bhaji/) ·
  [Dal Fry (Jain)](https://letsfoodify.com/product/dal-fry-jain/) ·
  [Super Millet Idli](https://letsfoodify.com/product/super-millet-idli/) ·
  [Coconut Red Chutney](https://letsfoodify.com/product/coconut-red-chutney/) ·
  [Lemon Masala Tea](https://letsfoodify.com/product/lemon-masala-tea/)
- [FSSAI mandates for e-commerce FBOs](https://forum.openfoodfacts.org/t/fssai-mandates-for-e-comm-fbos/698)
