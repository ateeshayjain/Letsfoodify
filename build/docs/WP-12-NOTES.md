# WP-12 — content and the Merchant Center feed

Scope calls the feed **"the revenue item (R5)"**: for a packaged-goods catalogue,
free product listings on Google are the surface that actually moves revenue —
and the client asked for the other one (GBP), which is why the scope says to
lead with this and say so plainly.

Three earlier packages parked debts here, deliberately, so the client enters
data **once**:

| Debt | From | Now |
|---|---|---|
| Editor UI for the Legal Metrology declarations | PDP design pass | `inc/product-editor.php` |
| Product weights (every parcel unweighable) | WP-11 | pointed at WooCommerce's own Shipping field; the completeness column counts it |
| The feed itself | WP-08 | `inc/product-feed.php` |

---

## The editor: refuse, never coerce

One metabox on the product screen carries everything: declarations, nutrition,
best-before, prep time, HSN and GST rate. The design decision is in the
sanitisers.

**`(float)'abc'` is `0.0`, and 0% is a real GST rate** (unbranded staples are
0-rated). Coerce and a slipped key silently becomes a filed tax position. So:

- GST rate accepts only the schedule's actual slabs — 0, 0.25, 3, 5, 12, 18, 28.
  `50` (for 5) and `1.8` (for 18) are refused as the typos they are.
- HSN accepts only 4, 6 or 8 digits — the lengths the schedule uses. Five digits
  is a typo, not a code.
- Nutrition accepts a number with a unit ("312 kcal"), never prose — half a
  panel of words must never render as data.
- Best-before must parse as a date, and a **past** date is *storable*: old stock
  exists, and refusing it would hide it from the shelf-life rule, which is the
  thing that must see it.

Refused input stores **nothing**, and empty is visible everywhere else in this
build ("Not provided" on the PDP, "not invoiceable" in WP-11). A refusal is
announced in an admin notice naming the fields — refusing silently would look
like data loss.

Saving requires `edit_product`, which Shop Staff deliberately do not hold
(WP-10): the person who sets a GST rate is the person who may set a price.

A **completeness column** on the products list shows which of the 44 still need
data — a glance, not a spreadsheet. Weight and GST rate count toward it.

### The shelf-life rule, parameterised

Scope §8: food delivered online must carry meaningful remaining life — commonly
cited as *30% remaining or 45 days, whichever is less*, **and the scope itself
says to verify the current threshold with the client's consultant because the
rule has been revised**. Both numbers are therefore parameters, not constants.
"Whichever is less" is honoured: a 60-day-life product needs 18 days, not 45.

---

## The feed: excluded, never submitted broken

Google grades a feed as a feed. Items missing required attributes are
disapproved, and repeated disapprovals damage the whole account's standing. So
**an incomplete product is left out and reported**, per product, with reasons —
which makes the feed the enforcement arm of the content pass: *no photography,
no listing*. The dependency the schedule stated ("needs the new photography
settled first") is now stated in code.

- The PDP's honest gap marker is caught: a description containing "Not provided"
  never reaches Google — that text is the page being honest with a *human*;
  inside a feed it is garbage asserted to a *machine*.
- A zero price is excluded, not listed free.
- **Out of stock is a value, not an exclusion** — `availability: out_of_stock`.
- Own-brand food without a GTIN declares `identifier_exists: no`, which Google
  accepts; omitting the field gets the item flagged instead.

### The escaping is the load-bearing part

An unescaped `&` is not slightly-wrong XML — the document **stops parsing at
that byte**. Every item after it vanishes and Merchant Center reports a fetch
error on the whole file. One product named "Chai & Snacks" takes the catalogue
down with it. The test poisons a title with `& <combo>` and asserts the built
document still parses with `simplexml`.

No smart handling of already-escaped input, on purpose: feed text is text, and
"detecting" prior escaping is how a title containing the literal substring
`&amp;` gets mangled while a raw `&` slips through.

### A query var, not a rewrite endpoint

The feed lives at `/?foodify-feed=1`. The address-book endpoint already carries
the "rewrite rules are cached and go stale on git deploys" failure mode — and a
feed URL that 404s after a deploy **silently stops the scheduled Merchant Center
fetch** until listings start expiring. A query var cannot go stale.

### The gate fetches and parses

`smoke-test.sh` section 6 fetches the feed and runs it through `xmllint` —
**parsed, not status-checked**, because HTTP 200 with a half-written document is
exactly what the ampersand failure looks like. A valid-but-empty feed (every
product excluded) is a WARN naming the likely cause. If `xmllint` is absent the
check degrades to "reaches `</rss>`" and *says it is weaker* — never that it
passed. `selftest.py` proves both directions with a fixture truncated at an
unescaped ampersand.

---

## Not done, and why

- **Creating the Merchant Center account and scheduling the fetch** — client
  work, needs their Google account, and the feed URL to hand it is
  `https://letsfoodify.com/?foodify-feed=1`.
- **Product descriptions and photography** — the content itself. The exclusion
  report is the work queue.
- **GTINs** — only if the client ever barcodes; `identifier_exists: no` is
  correct until then.
- **`g:shipping` in the feed** — needs the WP-11 zone rates confirmed on staging
  first; wrong shipping in a feed is a disapproval magnet.
- **WooCommerce still not booted** — the feed's WordPress half (product mapping,
  the transient cache) runs behind `function_exists('wc_get_products')` and is
  exercised only by the pure tests. Same standing limit as everything else;
  WP-00.

## Tests

```
php tests/wp12-test.php     # 48
python3 tests/selftest.py   # 46  (feed gate proven both directions)
```
