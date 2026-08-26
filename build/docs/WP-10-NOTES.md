# WP-10 — the admin dashboard and the Shop Staff role

Scope §W6: *"A curated WooCommerce landing screen: today's orders, revenue, low
stock, pending shipments, coupon performance. A **Shop Staff role** that can
process orders without holding full admin. Bulk price/stock editing.
Order-status email templates in the brand's voice."*

---

## The trap the role is built around

**`add_role()` is a no-op when the role already exists.**

Capabilities live in the `wp_user_roles` option, not in code. So tightening a
capability here, testing it on a fresh install, and deploying changes **nothing**
on a site where the role was created by an earlier release. The looser set stays
in the database forever.

That is a security defect with a very quiet failure: the code says the staff
account cannot install plugins, the database says it can, and every review of the
code agrees with the code. It is the same shape as an absence check that cannot
run — *the thing that proves the claim is not the thing being read.*

So the role carries `FOODIFY_ROLES_VERSION`. Change the capability set, bump the
version, and the next request reconciles the database to it — adding **and
removing** capabilities in place. Removal is the half that matters: adding is
what everyone remembers, and taking one away is exactly what `add_role()` will
never do.

It reconciles in place rather than `remove_role()` + `add_role()`, which would
drop the role from every user holding it for the length of a request — and on a
site where two requests overlap, from one of them for real.

### The forbidden list is asserted positively

`foodify_granted_forbidden_caps()` returns *which* dangerous capabilities a role
actually holds. So the test can **poison a role and watch the detector fire**,
rather than proving only that today's array looks right. Without that, the
clean-role assertion passes just as happily against a detector that always
returns nothing.

There is also an admin notice if the database ever disagrees with the code. The
sync should make that impossible — and "should be impossible" is precisely what
everyone believed about the capability set that had already drifted.

### What Shop Staff can and cannot do

| Can | Cannot | Because |
|---|---|---|
| Read and edit all shop orders | `delete_shop_orders` | Deleting an order destroys the record the WP-09 ledger, the GST invoice and any refund depend on. Cancelling is a status change, and they can do that. |
| Adjust stock via `foodify_manage_stock` | `edit_products` | `edit_products` also grants **price** editing. Least privilege costs one custom capability; the alternative costs the ability to change what things sell for. |
| See orders awaiting dispatch | `view_woocommerce_reports` | Revenue is not needed to pack a box. |
| — | `manage_woocommerce` | It reaches store settings *and* the WP-09 Coupon Performance screen, which lists partner email addresses. |
| — | `install_plugins`, `activate_plugins`, `edit_themes` | Code execution on the server. |
| — | `edit_users`, `promote_users`, `list_users` | Other people's accounts, self-promotion, and enumerating the customer list. |
| — | `export`, `import`, `unfiltered_html` | The whole database out, and markup in. |

Each of those is a named assertion in `tests/admin-test.php`, with the reason in
the assertion text — because "the role does not have admin" is an absence claim,
and absence claims pass without being checked.

`bootstrap.sh` **verifies** rather than creates: it checks the role exists and
greps each forbidden capability out of `wp cap list`, warning by name if any is
present. Creating it there would reintroduce the exact drift the version solves.

---

## A number nobody measured is not zero

Every dashboard value starts as `null` and is only set when its query actually
returned. A failed query renders **"—"** and the words *not measured*, never a
confident `0`.

"No orders today" and "the query did not run" are the same `0` otherwise, and a
screen that quietly says nothing is wrong when it cannot see is worse than a
blank one.

Tiles are gated **by capability in the model**, not at render — so the revenue
tile cannot leak through a template someone copies.

---

## Null stock is not zero stock

WooCommerce returns `null` for `stock_quantity` when stock management is **off**
for a product. That is a normal setting and it means *unlimited*, not *none*.

Read it as `0` and the low-stock panel fills with every unmanaged product,
screaming about items that are fine — until people stop reading it, and the true
one goes with the false ones. Unmanaged products are skipped, and the empty state
says so rather than implying the shelves are full.

Sorting: out of stock first, then fewest, then alphabetical. The last tiebreak is
not fussiness — without it two products at the same level reorder between renders
and the panel appears to shuffle itself.

---

## Bulk stock editing, and what I did instead of bulk price editing

Stock is editable **inline on the low-stock panel** — the items that are actually
low, which is the workflow, rather than a generic bulk editor bolted next to
WooCommerce's own. That is also the only door `foodify_manage_stock` opens, which
is what lets staff fix stock without being able to touch prices.

Guards: capability checked **before** the nonce (a valid nonce from a user who may
not do this is still a user who may not do this), a nonce scoped per product id,
and the field **refuses rather than guesses** — blank, negative, decimal and
"twelve" are all rejected. A blank field read as zero would take a product off
sale because somebody tabbed past it. Every change stamps who set it and to what.

**I did not build bulk price editing.** WooCommerce's product list has Quick Edit
and Bulk Edit with percentage price changes, and I could not verify from this
environment whether it covers what is wanted — wordpress.org is unreachable.
Building a second one from memory risks duplicating core badly. It needs five
minutes on the real admin to confirm, and then either nothing or a small gap to
fill.

---

## Order-status emails — copy, not code

The scope asks for these "in the brand's voice". **I have not written them into
`bootstrap.sh`**, and the reason is the one from WP-07: `wp option update` on a
name nothing reads *succeeds*, so a wrong option name produces a green line in
the deploy log for a setting that does not exist. That is how this project shipped
invented Rank Math sub-keys.

Instead `bootstrap.sh` now **reports** what is actually stored under each email's
settings option. If a line comes back empty, the name is wrong or the email has
never been customised — and either way you know before pasting anything.

Paste these in **WooCommerce → Settings → Emails**. Voice: plain, unhurried,
concrete, no exclamation marks — the same register as the site.

### Processing — "we have your order"

- **Subject:** `Your Foodify order #{order_number} is confirmed`
- **Heading:** `Thanks — we have your order`
- **Additional content:**
  > We are packing it now. You will get another note the moment it leaves our
  > kitchen in Noida, with a tracking link.
  >
  > Everything you ordered is made without preservatives and keeps for 9–12
  > months, so there is no rush to eat it — though most people do.

### Completed — "it has left us"

- **Subject:** `Your Foodify order #{order_number} is on its way`
- **Heading:** `On its way`
- **Additional content:**
  > It usually takes 3–5 days to reach most of India.
  >
  > When it arrives: six minutes, hot water, done. Cooking instructions are on
  > every pack.
  >
  > If anything is not right, reply to this email — it reaches a person.

### On hold — the COD confirmation

- **Subject:** `We need to confirm your order #{order_number}`
- **Heading:** `One thing before we pack`
- **Additional content:**
  > This order is set to pay on delivery, so we confirm it by phone before it
  > goes out. We will call the number on the order today.
  >
  > If you would rather pay now and skip the call, reply and we will send a link.

### Cancelled

- **Subject:** `Your Foodify order #{order_number} has been cancelled`
- **Heading:** `That order is cancelled`
- **Additional content:**
  > Nothing has been charged. If this was not what you wanted, reply and we will
  > sort it out.

### Refunded

- **Subject:** `Refund sent for order #{order_number}`
- **Heading:** `Refund on its way`
- **Additional content:**
  > It usually reaches your account in 5–7 working days, depending on your bank.
  >
  > If you want to tell us what went wrong, reply — we read all of it.

### New order (to the shop)

- **Subject:** `New order #{order_number} — {order_total}`
- **Heading:** `New order`

**Not decided by me:** the sender name and address, and the footer. Those must
match the business identity WP-08 is waiting on — and a from-address that fails
SPF/DKIM lands the lot in spam, which is a WP-12 job.

---

## Not done, and why

- **Bulk price editing** — see above. Needs five minutes against the real admin
  before anything is built.
- **Pending shipments as a distinct thing from `processing`.** They are the same
  status until WP-11 wires a courier, at which point "handed to Shiprocket" is a
  real state worth its own tile.
- **A packing-slip / picking-list view.** The obvious next thing for the people
  this screen is aimed at, and it wants the WP-11 courier payload to be useful.
- **Any of it run against a real WordPress install.** WP-00 is still open, and
  the capability sets in particular deserve one pass with a real staff account
  before anyone is given the login.

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
php     tests/admin-test.php          # 58
php     tests/undefined-functions.php #  1
python3 tests/selftest.py             # 38
python3 tests/wp01-selftest.py        # 25
bash    tests/wp02-map-selftest.sh    #  6
                                      # 374 assertions
```
