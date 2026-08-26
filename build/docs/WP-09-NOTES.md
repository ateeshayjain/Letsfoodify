# WP-09 — the coupon attribution engine

Scope §6 calls this *"the one genuinely custom build"*. It was already in the
kit, and the first job was to check it against its own spec.

---

## The fatal

```php
$coupons = foodify_attributed_coupons( $order, $coupons, true );   // line 186
$coupons = foodify_attributed_coupons( $order, $coupons );          // line 226
```

**`foodify_attributed_coupons()` was called twice and defined nowhere.**

An earlier verification pass — mine — extracted a duplicated single-winner rule
"into one function", updated both call sites, and never wrote the function.
`docs/VERIFICATION-2026-08-25.md` reports it as fixed:

> "The rule now lives in one function, `foodify_attributed_coupons()`, called by
> both paths; the inline copy is gone."

The inline copy was indeed gone. So was the replacement.

**Every `php -l` in this repository passed for weeks afterwards**, because an
undefined function is a *runtime* error, not a syntax error — and nothing here
has ever run against PHP with WooCommerce loaded. The blast radius is the money
path: any order using a partner coupon reaching `processing` would fatal
mid-status-transition, on a live store.

Two things to take from it. `php -l` proves a file parses, not that it works. And
a document saying something was fixed is not evidence — this one was written by
the same pass that broke it.

### The check that would have caught it

`tests/undefined-functions.php` walks every PHP file in the theme, collects every
`foodify_*` function *defined* and every one *called*, and fails naming
`file:line` for any gap. Proven against the real bug by removing the function and
watching it fail with both call sites listed.

It found a hole in itself within minutes. The first version treated the first
argument of `add_action`/`apply_filters` as a callback — but this theme names its
own hooks `foodify_*` too, so it reported four of the theme's own filters as
fatal bugs. **Crying wolf is the failure a gate has to avoid above all others**:
do it once and the gate gets ignored, and the real finding goes with it.

Then, minutes after being declared working, it **passed a genuinely undefined
function I had just written** — `array_map( 'foodify_csv_cell', … )`, a callable
handed to a higher-order function, which fails at runtime exactly as loudly. Now
covered, and proven the same way. A gate with a hole in it is worse than no gate,
because it is trusted.

---

## The rule that was missing, and what it should be

The removed rule was *"largest discount wins, the rest are audit-only"*. Scope
§6's own test case says something different:

> "an order with two coupons attributes to **both** without double-counting
> revenue."

Single-winner means the second partner is told nothing at all. Their code was
used and they hear silence — worse for trust than the double-count it was
avoiding, and partner trust is the entire product here.

So revenue is **apportioned by discount share**. Every partner gets a row and a
notification; the order value is stated in full because that is a fact about the
order; and `attributed_revenue` is each partner's share.

`foodify_apportion()` works in integer paise and settles the rounding remainder
by largest fractional part, so **the shares sum to the order exactly**.
Apportioning money with floats and a `round()` per share leaks or invents a
paisa, and a ledger whose rows do not add up is a ledger nobody can reconcile —
which is the whole reason for having one. Edge cases pinned: a free-shipping
coupon (zero discount) splits evenly rather than dividing by nil; a zero-revenue
order does not divide by zero; a negative total (a reversal) apportions the same
way; and the split is deterministic, so two runs credit the same partner the same
paisa.

---

## The ledger the spec asked for

Scope §6 specifies a custom table and says why: *"it keeps reporting queries cheap
and survives order deletion for accounting."* The kit built **running counters on
coupon post meta** instead.

That is not a storage preference. A counter cannot be drilled into, exported, or
checked against anything — and when it drifts, nothing says so. The file header
promised `wp foodify coupons reconcile` as the fix for exactly that drift. **That
command was never written either.**

`{prefix}foodify_attribution` now holds one row per (order, coupon) with the
line items, both revenue figures, the discount, the commission and the status.

- **`UNIQUE (order_id, coupon_id)` is the idempotency guarantee**, and it lives in
  the storage rather than in an order-meta flag the calling code has to remember
  to check. A status flap, a double-fired hook or two racing requests cannot
  produce two rows, whatever the caller believes about itself. The insert *is*
  the check — no read-then-write, which is a race however you write it.
- **Totals are derived**, so there is nothing to reconcile and nothing to promise.
- **Reversals mark, never delete.** A reversed sale is accounting history.

### A refund bug this fixes on the way

The kit credited a counter with `subtotal − discount` and debited it with the
**refund amount**. Different bases — so a full refund did not return the counter
to zero, it left a residue nobody could explain. Reversing the *row* makes the
two sides symmetric by construction.

---

## "Inventory & value" — the half that was missing

The client's sentence was *"inventory & value"*. Scope reads "inventory" as
**which items and what quantity**, and specifies the email carry *"line items —
SKU, product, qty, line value"*.

The kit's email sent a single unit **count**. That answers "value" twice and
"inventory" not at all. The email now lists what sold.

---

## The privacy line, enforced rather than intended

Scope §6:

> "**No customer PII** — no name, address, phone or email of the buyer. That is a
> privacy line and a DPDP-Act-shaped one."

and lists *"the email contains zero customer PII"* as a test case worth writing.

The body is now built by a **pure function**, so that test is executable — and the
same check runs at send time and **refuses**, logging an order note, rather than
leaking. A test proves today's template is clean; the guard is what stops
tomorrow's well-meant *"add the customer's name so it feels personal"*.

The test also poisons a body deliberately and asserts the leak **is** caught.
Without that, the clean-body assertion passes just as happily against a broken
detector.

---

## Also closed against the spec

| Spec item | Was | Now |
|---|---|---|
| `_foodify_notify_enabled` + "a coupon with notification disabled emails nobody" | absent | present — and the row is still **written**, because silence to a partner is a preference while a gap in the ledger is missing accounting |
| `_foodify_commission_pct`, "commission if configured" in the email | absent | present, reporting-only, shown only when set |
| Coupon Performance screen: redemptions, gross, discount, commission, last used, partner contact | an admin **column** and a widget | the screen, under WooCommerce |
| Date filtering, CSV export, drill-through to orders | impossible against counters | all three |
| CSV injection | n/a | `foodify_csv_cell()` — a coupon code beginning `=` executes as a formula when finance opens the export |

A backwards date range is swapped rather than returning nothing: an empty report
that means "you typed the dates the wrong way round" reads as "no sales", which
is the worst way for a reporting screen to be wrong.

---

## Flagged, not decided

**Scope §6 says: "This decision is blocked on Q7–Q9 in §11. Do not start building
until answered."** It was built anyway, before I arrived. The questions are still
open, and they decide whether this code should exist at all:

- **Q7** — how many coupon owners? Ten known influencers, or an open affiliate
  programme? *Custom is the right call only under about twenty, known personally.*
- **Q8** — do owners earn a commission, and does the system need to calculate and
  track payouts? *If yes, buy AffiliateWP. Writing payouts, TDS handling and fraud
  controls from scratch is a bad trade.* What is built here is reporting-only.
- **Q9** — do owners need to log in and see their own numbers, or is the email
  enough? *There is a partner portal at `/my-account/partner/`, which means the
  answer was assumed.*

Also open: **Q10** — the spec's own note that "inventory" might mean the partner's
remaining **redemption allowance** rather than line items. This build implements
line items. If Q10 comes back the other way it is a small change, and knowing now
is cheaper than knowing later. **Q11** — refund correction emails — is implemented
as "yes, send one".

The partner model is a further deliberate divergence: the spec's data model has
`_foodify_owner_name` / `_email` / `_phone` on the coupon, and the kit requires
the partner to be a **WordPress user**. That is better for the portal and worse
for onboarding — an influencer who should never touch wp-admin now needs an
account there. Left as-is because the portal depends on it, but it is a real
trade and Q9 settles it.

---

## Not done

- **A reconciliation command.** Derived totals cannot drift, so there is nothing
  to reconcile. The promise has been removed from the file header rather than
  kept and left unimplemented.
- **Database-level tests.** The ledger's SQL has no MySQL here to run against, so
  the pure half carries the arithmetic and the SQL stays thin and readable.
  Everything money-shaped is in the tested half.
- **Payouts, TDS, self-referral fraud controls.** Out of scope by the build-vs-buy
  recommendation — that is what AffiliateWP is for, and Q8 decides it.
- **None of this has run against a real WooCommerce install.** Which is exactly
  how the fatal survived. WP-00 access is still open.

## Running everything

```
php     tests/perf-test.php           # 11
php     tests/shortcode-test.php      # 17
php     tests/otp-test.php            # 24
php     tests/address-test.php        # 51
php     tests/checkout-test.php       # 15
php     tests/payments-test.php       # 34
php     tests/reviews-test.php        # 43
php     tests/partner-test.php        # 51
php     tests/undefined-functions.php #  1   ← the one that would have caught it
python3 tests/selftest.py             # 38
python3 tests/wp01-selftest.py        # 25
bash    tests/wp02-map-selftest.sh    #  6
                                      # 316 assertions
```
