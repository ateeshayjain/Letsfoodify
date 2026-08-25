# WP-05 — accounts: the address book, the reorder path, the OTP rule

WP-05's own framing: *"the address book and reorder button are the point — not
the login itself."* Acceptance: **a returning customer completes checkout with
zero address fields typed.**

The login half is blocked on the client — an SMS gateway needs DLT registration.
Three of the four deliverables are not blocked on it, and one of them is the
stated point of the package. Those three are built here.

| | State |
|---|---|
| Multi-address book, one default | **Built** — `inc/address-book.php`, 51 tests |
| One-tap address at checkout | **Built** — chooser + server-side prefill |
| Post-purchase account claim | **Built** — `inc/account.php` |
| OTP rate limit + resend cooldown | **Built as a pure rule** — `inc/otp-throttle.php`, 24 tests |
| The OTP gateway itself | **Blocked on the client** — DLT registration |

---

## The address book

### Why WooCommerce's own fields are not enough

WooCommerce stores exactly **one** billing address and **one** shipping address
per customer. A household that orders to home and to an office retypes one of
them every time — which is most of the 25-field checkout the audit complained
about, arriving again on every repeat order. That is the opposite of the
acceptance criterion.

### The model is a superset, not a substitute

The book lives in one user-meta key (`_foodify_address_book`). The address
flagged default is **also mirrored into WooCommerce's own `shipping_*` /
`billing_*` meta on every save**.

That mirroring is the whole safety argument, and it is the decision worth
challenging me on if you disagree. Everything downstream — checkout prefill,
order creation, the admin customer screen, the WP-11 courier payload, Razorpay,
any plugin that calls `get_user_meta()` — keeps reading the fields it has always
read, and sees the truth. If `inc/address-book.php` were deleted tomorrow the
store would still take orders; customers would simply be back to one address.

A model that *replaced* WooCommerce's fields would look identical in testing and
break silently in every integration that never heard of it. That failure would
surface as wrong addresses on courier labels, weeks later, one order at a time.

### The invariant

**A non-empty book has exactly one default.** Zero defaults means checkout
prefills nothing and the acceptance criterion fails; two means the one it picks
is arbitrary.

It sounds trivial. The operations that break it are:

- **deleting the default** — the book keeps working and checkout quietly stops
  prefilling;
- **editing an address to untick "default"** when it is the only one that has it.

Neither is a case anyone hits while clicking a happy path, and neither throws an
error. So the list arithmetic is pure functions over plain arrays and the
invariant is asserted after *every* mutation in `tests/address-test.php`:

```
php tests/address-test.php     # 51 passed
```

Also pinned there: a first address is default whether or not it was ticked;
saving the same place twice updates one row rather than appending a near
duplicate (fingerprint on address + city + PIN, deliberately **not** on name or
phone — the same flat ordered for two different people is one address); editing
an id that is not in the book is refused rather than silently created; a 10-address
cap that blocks a new address but never blocks editing an existing one.

### Existing customers

`foodify_get_address_book()` seeds the book from the customer's existing
WooCommerce meta the first time it is read. Without that, every existing
customer opens a brand-new "address book", finds it empty, and concludes the
site lost their address. Incomplete legacy data seeds **nothing** rather than a
half-filled card that fails validation the moment they touch it.

### Deletion

The default address has no Delete button. To remove it you make another the
default first, or edit it. This is a deliberate constraint rather than an
oversight: it is the cheapest way to make "the book always has a default" true
in the UI as well as in the data, and a customer with one saved address who
deletes it has gained nothing.

### Security

Every mutation is a **POST with a nonce** — including "make default" and
"delete", which is why they are one-button forms rather than links. A delete
*link* is a CSRF hole: a URL in an email that quietly removes somebody's saved
address. Address ids are only ever resolved inside the current user's own book,
so an id belonging to somebody else does not resolve at all. POST-redirect-GET
after each action, so a refresh cannot repeat it.

---

## Checkout

Two mechanisms, and the order matters:

1. **Server-side prefill.** The default address is already in WooCommerce's
   meta (see the mirror above), so WooCommerce's own prefill fills the fields
   before any of this theme's code runs. This is what makes "zero fields typed"
   true with JavaScript blocked, on a slow connection, and on the first paint.
2. **The chooser**, above the checkout form, for picking a *different* saved
   address. It stores the choice in the WooCommerce session and
   `woocommerce_checkout_get_value` reads from it.

The chooser renders **only when there are two or more saved addresses**. A
"choose your address" control with one option is noise on the screen that most
needs none.

It submits on `change` via a one-line inline handler, with a `<noscript>`
button behind it. The JavaScript is the convenience; the form is the mechanism.

### A completed order teaches the book

`woocommerce_checkout_order_processed` upserts whatever address the order used.
Someone who typed a new address at checkout should not have to save it again by
hand. It does **not** promote that address to the account default — a gift sent
to somebody else is not where the next order goes.

---

## Post-purchase account claim

Offered on the order-received page, not as a checkbox at checkout. After the
order is placed the customer has already typed everything an account needs, and
the offer is a favour rather than one more decision in front of the money.

**It links this order and no others.** Linking every past order with the same
email address would be more generous and it is a hole: the only thing proving
who is standing here is the order key in the URL, which proves they placed
*this* order and nothing about the rest. Someone forwarded a confirmation email
would inherit a stranger's order history. Older orders come back when they sign
in properly — WooCommerce links guest orders by email on login, which is a
different trust decision, made by WooCommerce, behind an actual credential.

Three gates, all required: a nonce tied to this order id, an order key matching
this order (`hash_equals`), and an order that is still unclaimed.

---

## The OTP rule, ahead of the OTP gateway

`inc/otp-throttle.php`. Acceptance: *"five OTP requests in an hour blocks the
sixth; 30-second resend cooldown."*

Both rules are about abuse and cost, not about which gateway sends the message.
Every OTP is a billed SMS, and an unthrottled endpoint is a way to spend the
client's money from a script. So the rule is a **pure function** — no gateway,
no WordPress, no clock of its own — and it is tested now rather than discovered
on a live endpoint at one paid message per assertion.

```
php tests/otp-test.php     # 24 passed
```

Decisions worth stating:

- **Keyed on the phone number**, not the session or IP. The cost and the abuse
  both follow the number; a per-session limit is defeated by clearing cookies.
  The number is hashed with `wp_salt()` so it never appears as an option name.
- **Cooldown answers before the hourly limit** when both apply. Telling someone
  they are rate-limited for an hour when they are five seconds into a 30-second
  wait is a support ticket.
- **The window rolls.** `retry_after` on the hourly limit counts from the
  *oldest* request ageing out, not from a fixed reset.
- Future-dated and non-integer timestamps are discarded rather than trusted.

When the OTP plugin lands in week 11 it calls `foodify_otp_check()` before
sending and `foodify_otp_record()` after the gateway accepts. Nothing here has
to change.

---

## The rewrite endpoint — and a check that it exists

The address book lives on `/my-account/address-book/`, a rewrite endpoint the
theme registers on `init`. **Rewrite rules are cached in an option.** A deploy
that copies theme files without re-activating the theme — which is what a
git-based deploy does — leaves the account menu pointing at a URL that 404s.

Three things now cover it:

1. the theme flushes on `after_switch_theme`;
2. `bootstrap.sh` runs `wp rewrite flush` after wiring the WooCommerce pages,
   then **asserts** the endpoint is in `wp rewrite list` rather than assuming it;
3. `smoke-test.sh` fetches the URL. Logged out, WooCommerce answers every
   account endpoint with the login form and a 200 — so a **404 here means the
   rule is missing**, which is precisely the failure that is invisible until a
   customer taps it. A non-200, non-404 answer is reported as *could not
   verify*, never as a pass.

`selftest.py` now proves that check works both ways: the healthy fixture serves
the endpoint and the gate passes it; the defective fixture omits it and the gate
must report "rewrite rules not flushed". The unreachable-site case asserts the
gate does **not** print "endpoint registered".

---

## One fixture set, not two

`tests/fixture-server.py` carried its own copy of the smoke-test fixtures,
duplicating `selftest.py`'s. It had already fallen a route behind. It now
imports them, so there is one definition. Two fixture sets that must agree is
the same drift risk as two merchant normalizers or two net-worth definitions.

---

## Not done, and why

- **The OTP gateway.** Blocked on DLT registration — client-owned, weeks out.
  The theme still renders WooCommerce's own login form so the plugin can take it
  over without a template change.
- **A default *billing* address distinct from shipping.** The book models one
  address per entry. Indian D2C food orders are overwhelmingly ship-to-billing;
  splitting them doubles the form for a case that has not been shown to exist.
  Raise it if the GST-invoice work in WP-11 needs it.
- **Address autocomplete from PIN code.** `api.postalpincode.in` is already
  wired at checkout (WP-06 territory). The address-book form does not use it
  yet.
- **The claim block is not in the preview.** There is no order-received screen
  in `preview/storefront.html` to put it on.

## Running everything

```
php   tests/perf-test.php        # 11
php   tests/shortcode-test.php   # 17
php   tests/otp-test.php         # 24
php   tests/address-test.php     # 51
python3 tests/selftest.py        # 16
python3 tests/wp01-selftest.py   # 25
bash  tests/wp02-map-selftest.sh #  6
                                 # 150 assertions
```

---

## One thing in the kit that contradicted WP-03 — flagged and fixed

`bootstrap.sh` Phase 3 still installed Blocksy and, if the foodify theme was
missing from `wp-content/themes`, **activated Blocksy and carried on with a
warning**.

That is worse than it looks. WP-03 made foodify a standalone block theme — there
is no parent to install and no Blocksy Pro licence to renew. And the fallback
would leave the store rendering an entirely different theme, with none of the
WP-01 or WP-03 fixes, while every later phase of the script reported success
against it. The same failure shape this project keeps meeting: the run looks
green and the thing it was checking never happened.

Now: Blocksy is not installed, a missing foodify theme is `die`, not `warn`, and
activation is followed by a rewrite flush. Blocksy Pro also comes off the
manual-premium-plugin list, which takes ~₹6,000/yr off the recurring cost that
`REVIEW-NOTES.md` §7 said was never totalled.
