# WP-08 — reviews, and the business's own identity

Scope §5 separates three things clients call *"connected to Google reviews"*:

| | | State |
|---|---|---|
| 1 | Show the Business Profile's reviews on the site | Already built — `inc/shortcodes.php`, WP-03 |
| 2 | **Star ratings in Google's own search results**, from product reviews marked up with AggregateRating. *"The SEO-valuable one… Recommend this gets built."* | **This package** |
| 3 | Google Customer Reviews / seller ratings | Parked until Merchant Center has volume |

(2) is not a widget. Schema with no reviews emits nothing, so the deliverable is
the **collection flow** — an ask that actually produces reviews — plus the
guarantee that what gets published is true.

---

## The thing I found on the way in, which turned out to be the bigger item

Google Business Profile ranking rests on **NAP consistency**: the name, address
and phone on the site matching the profile byte for byte. So I went to read what
the theme publishes, and found this in the site header, both footers and the
trust strip:

```
FSSAI 10012345678901
```

That is a placeholder. A real FSSAI licence is fourteen digits encoding licence
type, state and year; this is `1-00-1234567890-1` — the number people type when
they need a number. **A food business displaying a fabricated licence number is a
compliance problem, not a typo**, and it was on every page of the build, repeated
four times, reading exactly like a real one.

It is the fake-viewer-counter failure again in a much more expensive place: a
plausible value nobody would question. And publishing `LocalBusiness` structured
data built from it would have handed the same fabricated licence to Google **in
the one format designed to be trusted**.

### What changed

- The number is no longer a literal anywhere in the theme. It comes from
  `foodify_business_profile()`, which ships with the licence **empty**.
- An unset or placeholder licence renders the words **`NOT CONFIGURED`** in the
  place a licence belongs. Deliberately not a number and deliberately shouty: a
  blank would read as a layout bug and get ignored, and a plausible number is
  what got us here.
- `foodify_local_business_schema()` returns **null** when any profile field is
  missing or still placeholder text. Returning null is the feature — structured
  data is a machine-readable claim, and half a claim is a false one.
- `smoke-test.sh` **fails the deploy** if either `10012345678901` or
  `NOT CONFIGURED` reaches production.
- An admin notice names exactly which fields are outstanding.

`foodify_is_valid_fssai()` rejects the shipped dummy, ascending runs, all-same
digits, anything that is not exactly fourteen digits, and anything not starting
with a licence-type digit. It tolerates the spacing people actually paste.

**Client dependency:** the real FSSAI licence number and the phone number shown
on GBP. Until both arrive, the site says NOT CONFIGURED and the gate blocks
launch. That is the intended behaviour, not a bug to work around.

---

## The review collection flow

An ask five days after the order completes, one per order, with per-product
links and one way out.

### Scheduling is not deciding

The event is queued when the order completes and fires five days later. Five days
is plenty of time for a refund to land — and **an email asking someone to rate a
meal you just refunded them for is worse than sending nothing at all.**

So `foodify_review_request_state()` runs **both times**, and the firing is the one
that counts. It returns a decision and a *reason*, and the reasons are ordered:
permanent ones beat timing ones. Reporting "not due yet" on a refunded order
implies it will be asked later, and it never will.

| Reason | Why |
|---|---|
| `cancelled` / `refunded` | Permanent. Checked first. |
| `no_email`, `opted_out`, `nothing_to_review`, `already_asked` | Permanent. |
| `too_old` | Cron fires very late after an outage — a site down a fortnight releases everything at once. Asking about a meal eaten two months ago reads as a broken system. 45-day window. |
| `customer_cooldown` | A regular customer is not asked every week. 30 days. |
| `not_due_yet` | The only one that carries a `due_at`. |

```
php tests/reviews-test.php     # 43 passed
```

### Consent

The opt-out list stores **SHA-256 hashes of email addresses**, never addresses —
DPDP §8, and a WordPress option is not a safe place to keep a mailing list. The
unsubscribe link carries an HMAC so it cannot be forged or enumerated, and opting
out never needs the address back, because the list is hashes on both sides.

---

## Reviews have to be real, and the page and the schema have to agree

Three defences, and they are the same principle three times.

**A star rating carries its count.** WooCommerce renders the average and nothing
else, so a single five-star review displays as `★★★★★` — indistinguishable from
two hundred of them. Not a lie exactly, but it is what the viewer counter was: a
signal engineered to read stronger than the evidence behind it.
`foodify_rating_display()` returns the count with the stars.

**A review must come from someone who bought the product.** WooCommerce has a
setting for this and **I did not put it in `bootstrap.sh`.** I could not verify
its option name from this environment, and `wp option update` on a name nothing
reads *succeeds* — a green line in the deploy log for a setting that does not
exist. That is precisely how this project shipped invented Rank Math sub-keys,
and no gate can catch it, because writing and reading back a dead key both work.

So it is enforced in `inc/reviews.php` with `wc_customer_bought_product()` — a
public WooCommerce **function**, not a settings name. Stronger than the checkbox
in two ways: it lives in the repository where it can be reviewed, and it survives
somebody unticking the box in wp-admin. Replies are not reviews and are left
alone; so is the shop answering on its own product.

**The schema and the page must tell the same story.** The gate now checks both
directions:

| Page | Schema | Verdict |
|---|---|---|
| shows a rating | has `aggregateRating` | PASS |
| no rating | no `aggregateRating` | PASS |
| no rating | **has** `aggregateRating` | **FAIL — fabricated social proof** |
| shows a rating | no `aggregateRating` | WARN — the SEO value is not landing |

---

## Two Product schema nodes

Both WooCommerce and Rank Math emit `Product` structured data. Which one yields
to the other is something I **could not verify** by reading plugin source from
here — wordpress.org and rankmath.com are both unreachable.

So the gate asserts the **outcome** rather than the mechanism: exactly one
`"@type":"Product"` node on a product page. Two is a real conflict whichever
plugin produced them, and the assertion is true regardless of which one I would
have guessed.

Counted with `grep -o … | wc -l`, **not `grep -c`** — `grep -c` counts *lines*,
so minified schema would report `1` for any number of nodes. That mistake is
already in this project's history, from the WP-01 gate.

---

## One token table, not two filters

WP-06 fixed `<!--FOODIFY_YEAR-->` reaching the live footer as an invisible HTML
comment, with a `render_block` filter in `functions.php`. Adding the FSSAI token
would have meant a second filter doing the same job — which is how the second one
gets forgotten, and forgetting the first is exactly what caused the original bug.

Both now come from one table, `foodify_content_tokens()`. A token in a template
with no entry in the table renders as a visible un-replaced comment rather than a
silent blank.

`render-preview.py` resolves the same two tokens, and shows the licence as
`NOT CONFIGURED` on purpose — a preview showing a plausible licence number is how
the dummy got into four templates in the first place.

---

## Not done, and why

- **Creating and verifying the Google Business Profile** — client work, and it
  does not exist yet. The Place ID is the input the review widget has been
  waiting on since WP-03.
- **Google Merchant Center feed** — scope §W8 calls it "the revenue item (R5)".
  It needs a live product catalogue with the Legal Metrology fields populated,
  which is WP-09/WP-12 territory, and a Merchant Center account. Not blocked on
  code; blocked on data and access.
- **GA4 e-commerce event verification** — WP-13, and it needs a live site to
  verify against rather than to assert about.
- **Review photos.** Worth having for food; needs a plugin or a media-upload
  flow on the comment form, and it is a real moderation load. Raise it once
  reviews are actually arriving.
- **Rank Math review-schema configuration.** Deliberately untouched: the invented
  sub-keys in this project's history all came from configuring Rank Math from
  memory. The gate now measures the result instead.

## Running everything

```
php     tests/perf-test.php        # 11
php     tests/shortcode-test.php   # 17
php     tests/otp-test.php         # 24
php     tests/address-test.php     # 51
php     tests/checkout-test.php    # 15
php     tests/payments-test.php    # 34
php     tests/reviews-test.php     # 43
python3 tests/selftest.py          # 38
python3 tests/wp01-selftest.py     # 25
bash    tests/wp02-map-selftest.sh #  6
                                   # 264 assertions
```
