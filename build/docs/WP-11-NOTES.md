# WP-11 — shipping, GST, fulfilment

## What I refused to decide

**No GST rate and no HSN code is set by this build.** Scope §12 excludes
"FSSAI/GST filing or consulting — display duties only", and a wrong rate on a
food product is the client's tax liability, not a styling bug. Rates and HSN
codes are per-product data their CA supplies.

Everything in `inc/gst.php` is **rate-agnostic arithmetic and structure**. A
product with no rate set contributes zero tax and is reported as rate-less,
rather than being quietly taxed at someone's guess.

## The two things the code does own

### Place of supply decides the split — and it is the DELIVERY address

Intra-state is CGST + SGST, each half the rate. Inter-state is IGST at the full
rate. For goods sold to an unregistered buyer, the place of supply is **where
they are delivered** — not the billing address, which is the one most carts reach
for first.

Bill to Delhi, deliver to Noida, and the order owes CGST+SGST. Charge IGST and
**the customer never notices, because the total is identical either way.** Only
the return is wrong. Pinned both directions.

With no state known at all, the code never *asserts* intra-state.

### The parts must sum to the whole, to the paisa

Halving a rate and rounding each half independently makes CGST + SGST disagree
with the total tax by a paisa — on some orders and not others. An invoice whose
parts do not sum to its total is not compliant, and it surfaces during an
assessment rather than during testing.

So the split works in integer paise: the taxable value is derived first and the
tax is the remainder, then CGST is rounded **once** and SGST takes what is left.
The test runs 1,200 awkward prices across three rates and asserts the identity
holds every time.

A mixed basket produces one line **per rate**, because that is what a compliant
invoice states — not one blended figure.

### A document is only called a Tax Invoice if it is one

`foodify_invoice_title()` returns *"Order summary — not a tax invoice"* when any
mandatory particular is missing. Printing the words over a document without a
GSTIN or a place of supply does not make it one; it makes the shop's own records
claim something the document cannot support. The store GSTIN ships **empty**,
so today every document is an order summary — which is accurate.

---

## The Shiprocket step that did not exist

`bootstrap.sh` carried this line from the day the kit was written:

> `ok "COD enabled — cap and PIN allowlist are set in the Shiprocket step"`

**There was no Shiprocket step.** Same shape as the `wp foodify coupons
reconcile` command WP-09 found promised and never written: a comment naming a
thing that does not exist, which reads as reassurance to anyone who does not go
looking. Both now live in code, and the line says where.

### The one number that must never be wrong

The COD amount on a manifest is **what a delivery agent collects at the door**.

- Non-zero on a **prepaid** order → the courier takes money the customer already
  paid. They are charged twice, they complain, and they are right.
- Wrong on a **COD** order → the shop is short, per parcel, with no record of why.

Neither shows up in testing, because both need a real delivery to surface. So
`cod_amount` is **derived from the payment method**, never read from a field — a
`cod_amount` carried alongside a payment method is two sources for one fact, and
they drift the first time somebody marks a COD order paid by hand. Asserted in
both directions, including a second cash gateway that must be *declared* before
it can collect.

### Weight is refused, not guessed

Couriers bill on the higher of actual and volumetric weight. A default of 0.5 kg
on a parcel that is really 1.2 kg is a per-parcel loss nobody reconciles. **One
line with an unknown weight makes the whole parcel weight unknown**, and the
order screen says "Not dispatchable" with the reason — cheap to fix while the
order is on a screen, expensive once it is in a van.

An uncallable phone number blocks dispatch too: a driver who cannot ring ahead is
most of what a failed delivery is.

### An empty allowlist means everywhere

`foodify_pin_serviceable()` treats an empty allowlist as *serve everywhere*, not
*serve nowhere*. A courier integration that ships with an empty list and reads it
as deny-all refuses every order on the store the day it goes live.

---

## Shipping zones, and the trap that was already documented

`inc/shortcodes.php` has always read the free-shipping threshold from the method
that would actually apply, rather than from a constant — and **no method was ever
created**, so the cart bar has been reading nothing this whole time.

`bootstrap.sh` now creates the India zone with free shipping over ₹599 and a ₹59
flat rate below, and asserts the zone ends up with methods: a zone with none
silently offers no delivery at all, and an empty shipping section at checkout
reads as a theme bug.

The setting that matters is **`ignore_discounts: "no"`**. WooCommerce compares
`min_amount` against the subtotal *before* discounts unless it is set. Get it
backwards and a coupon either falsely qualifies the customer for free shipping or
falsely un-qualifies them — and they find out at the payment step, which is
precisely the "no surprises after the cart" promise this build rests on.

**Why writing this config is safe when writing an option is not:** a `wp wc`
subcommand that does not exist **fails with a non-zero exit**. That is a positive
failure surface. `wp option update` on a name nothing reads *succeeds* — which is
how this project shipped invented Rank Math sub-keys.

---

## Not done

- **Rates, HSN codes, the GSTIN.** Client and their CA. Until they arrive, the
  admin says so and documents are titled honestly.
- **The WP-07 GST question on the prepaid discount** is still open, and now has a
  home: the split arithmetic here is what it needs to be coherent with. Still the
  CA's call.
- **A Shiprocket API integration.** The payload is built and validated; pushing it
  to a courier needs their account and API keys.
- **An invoice PDF.** The field model and the title rule exist; rendering wants
  the real GSTIN and a signed-off layout.
- **Product weights.** Every parcel is currently unweighable, because no product
  has a weight set. That is per-product data, same load as the Legal Metrology
  fields, and it belongs with WP-12's content pass.

## Tests

```
php tests/wp11-test.php              # 51
build/scripts/wp-boot-test.sh        # 28 — against a real WordPress
```
