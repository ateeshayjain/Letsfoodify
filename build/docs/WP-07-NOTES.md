# WP-07 — payments and COD

The approved design offers **"Pay now — save ₹25"** against **"Cash on
delivery"**. That is the standard Indian D2C trade: COD carries real cost —
courier COD fee, cash handling, and return-to-origin on refused deliveries — so
prepaid gets an incentive.

Two things in this package are money rather than decoration, and everything else
follows from them.

---

## 1. The label and the fee must be one calculation

If the payment radio says "Save ₹25" and the fee line applies ₹20, the store has
lied on the payment screen, and it is the kind of lie that gets screenshotted.

`foodify_gateway_saving_label()` and the `woocommerce_cart_calculate_fees`
callback both call `foodify_prepaid_discount()`. Not "both use ₹25" — *both call
the same function*, including its rounding. The test asserts the two agree across
four carts, in flat and percentage mode:

```
cart ₹620:  label says ₹25,  fee applies ₹25
cart ₹20:   label says ₹20,  fee applies ₹20     ← the clamp
cart ₹617:  label says ₹31,  fee applies ₹31     ← 5% of 617 = 30.85
cart ₹8000: label says ₹100, fee applies ₹100    ← the ceiling
```

The ₹20 row is the clamp: without it a ₹20 cart pays **−₹5**, and WooCommerce
renders a negative total quite happily.

---

## 2. The discount must not survive a switch to COD

Pick "Pay now". Let the total update. Switch to COD. Place the order.

If the discount is read from the session — which holds the choice made a moment
ago — the store ships ₹25 under and nothing anywhere reports an error. It is not
even an attack; it is what happens when someone changes their mind.

`foodify_chosen_payment_method()` resolves the gateway in order of authority:

1. the **posted** `payment_method` — what the order will actually be created with;
2. the same field inside the `post_data` query string that `update_order_review`
   sends;
3. the session, which is a cache of an earlier choice and can be stale by exactly
   one switch — the switch that matters;
4. nothing, which earns no discount.

It is a pure function with its own tests rather than an inline `$_POST` read,
because "the submitted method beats a stale session" is an assertion someone
should be able to see.

An **unchosen** method is also not prepaid. Treating `''` as prepaid would show
the discount before anyone picked anything and then take it away.

```
php tests/payments-test.php     # 34 passed
```

---

## GST on the discount — FLAGGED, NOT DECIDED

**This needs the client's CA before launch.** It is the one thing in WP-07 I am
not willing to settle quietly.

The fee ships **non-taxable**, so GST on the order is computed on the
pre-discount subtotal. That is deliberately the *conservative* error: the store
over-declares GST by roughly ₹1.25 per prepaid order rather than under-declaring
it. Over-declaring costs a little money; under-declaring is a compliance
exposure.

It is still an error. The correct treatment for a GST-inclusive store is that the
₹25 is itself gross and contains tax at the **blended rate of the basket** — and
a single WooCommerce fee line cannot express that when a basket mixes 5% and 12%
items.

Two alternatives were considered and rejected:

| Option | Why not |
|---|---|
| Taxable fee at the standard tax class | Wrong in the other direction on a mixed basket, and it can **under**-declare |
| Auto-applied hidden coupon (handles inclusive tax correctly) | Inflates `get_total_discount()`, which is the revenue basis WP-09 pays partners on — so every prepaid order would quietly shrink a partner's commission. And it **cannot coexist with an individual-use partner code**: WooCommerce removes all other coupons, so either the partner's code or the prepaid discount silently disappears |

The coupon route is the arithmetically correct one and it is unusable here for
reasons that have nothing to do with tax. That is worth knowing before someone
reads this and "fixes" it.

Settling it is a one-line change, and the filter exists so it stays one line:

```php
add_filter( 'foodify_payment_config', fn( $c ) => $c + [ 'fee_taxable' => true ] );
```

**Owner: client's CA. Deadline: before launch. Home: WP-11 (GST invoicing).**

---

## The COD seam, which is the real find in this package

Scope §6 says, in as many words, to write partner attribution **on payment
complete**:

> "Write the attribution row on payment complete, not order creation — an unpaid
> or abandoned order must not credit a partner."

The reasoning is right and the hook is wrong. **A cash-on-delivery order never
fires `woocommerce_payment_complete`.** WooCommerce's COD gateway moves the order
to a status directly without going through it.

Follow that instruction literally and **every COD order fails to credit its
partner**. On an Indian D2C food store that is most orders. Nothing errors,
nothing logs, and the first anyone knows is an awkward conversation about missing
commission.

`inc/coupon-attribution.php` already hooked `woocommerce_order_status_processing`
rather than payment-complete, which is correct — but binding to one status makes
attribution depend on COD landing there, and **how WooCommerce's COD gateway
chooses its status is something I could not verify from this environment**
(wordpress.org is unreachable).

So the fix does not depend on knowing. Attribution now fires on **`processing`
and `completed`**, sharing the existing idempotency meta, so the second is a
no-op when the first already ran. Whatever path a COD order takes to becoming
real, the partner is credited exactly once — late at worst, rather than never.

---

## COD availability ships as a no-op, on purpose

`foodify_cod_availability()` can refuse COD above a cart value — the usual
defence against refused high-value deliveries. It ships with `cod_max_value = 0`,
meaning **no cap**.

Capping COD is a commercial decision about return-to-origin risk that the client
has not taken. Switching it on silently would start refusing orders they expect
to receive. The arithmetic is built and tested (boundary included: exactly at the
cap is allowed, one rupee over is refused, and the refusal names the number and
says what to do instead) so turning it on is a config line, not a build.

The same applies to a **second cash gateway** — a courier's own cash collection,
say. `cod_methods` is a list, and a gateway not in it earns the prepaid discount.
The test asserts both directions, including the bug: with the default config, a
`cod_delhivery` gateway *would* be paid the prepaid discount.

---

## A filter I nearly invented

I wrote `add_filter( 'wc_order_status_label', … )` to show customers "Confirmed"
instead of "Processing", which is warehouse language.

**`wc_order_status_label` is not a real WooCommerce filter.** I could not check —
wordpress.org is unreachable here — and this project has already shipped invented
Rank Math option sub-keys that `--dry-run` could not catch, because *writing to a
key nothing reads succeeds*. A filter nobody fires is the same failure: the code
looks like it does something and does nothing at all.

Removed rather than guessed. The real filter, `wc_order_statuses`, renames the
status **everywhere** including wp-admin, which would confuse the people packing
orders and the WP-10 dashboard — so it is not a drop-in either. Left undone.

---

## The gate

`smoke-test.sh` now checks that the prepaid saving is actually rendered on the
payment options — and reports it as a **WARN, not a FAIL**.

That is a deliberate difference from the `FOODIFY_YEAR` check tightened in WP-06.
There, the theme always emits a year, so absence proved the substitution had
broken. Here, absence is a legitimate configuration: the client can set
`prepaid_flat` to 0 and mean it. Turning this into a failure would block a deploy
over a pricing decision. The comment in the script says so, so nobody "tightens"
it later.

---

## Not done, and why

- **Razorpay account configuration** — keys, webhook URL, capture mode. Needs the
  client's Razorpay dashboard, and it is WP-00 access territory. The plugin
  itself is free; nothing here needs rule 5.
- **Refund handling on the payment side.** `coupon-attribution.php` already
  reverses partner counters on `woocommerce_order_refunded`. A partial refund's
  effect on the prepaid saving is untouched, and it should be looked at once
  there is a real refund to look at.
- **GST invoice generation** — WP-11.
- **A COD confirmation call / IVR step**, which some Indian stores use to cut RTO.
  Out of scope and a per-order cost.
- **None of this has run against a real WooCommerce install.** Every claim rests
  on source reading and the pure tests; WP-00 is still open.

## Running everything

```
php     tests/perf-test.php        # 11
php     tests/shortcode-test.php   # 17
php     tests/otp-test.php         # 24
php     tests/address-test.php     # 51
php     tests/checkout-test.php    # 15
php     tests/payments-test.php    # 34
python3 tests/selftest.py          # 29
python3 tests/wp01-selftest.py     # 25
bash    tests/wp02-map-selftest.sh #  6
                                   # 212 assertions
```
