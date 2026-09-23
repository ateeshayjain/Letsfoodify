# Putting the new design on a real WordPress site

**Who this is for:** the developer (or Hostinger support) turning this kit into a
working website. About an afternoon. Nothing here touches the live store.

**What you are installing:** a WordPress block theme — `foodify` — plus its
configuration. It sits on the SAME WooCommerce back office the store already
runs: same products, orders, customers and web addresses. Only the layer the
customer sees changes.

---

## Before you start — two rules that are not negotiable

1. **Never do this on the live site.** Everything below happens on a *staging copy*.
   The live store holds the order history; it is the client's business.
2. **Never push the staging database to live.** At launch, only files and
   `bootstrap.sh` move across. See `LAUNCH-RUNBOOK.md`.

## What you need

- A **staging copy of the live store.** On Hostinger: hPanel → Websites → *your
  site* → WordPress → Staging → Create. It copies files and database.
- **SSH with WP-CLI** on that staging site (Hostinger includes both).
- The theme zip: `dist/foodify-<version>-<commit>.zip` — build it with
  `scripts/package-theme.sh`, which refuses to produce a zip that does not boot.
- An admin login for staging.

## Steps

**1. Make staging invisible to Google.** Settings → Reading → tick *Discourage
search engines*. The smoke test fails an indexable staging site on purpose.

**2. Upload the theme — do not activate it yet.**
Appearance → Themes → Add New → Upload Theme → choose the zip → Install.
Or over SSH: `wp theme install foodify-*.zip`.

**3. Rehearse the configuration.**

```bash
cd ~/public_html          # the staging site's WordPress folder
./bootstrap.sh --env=staging --dry-run
```

Read what it would do. It backs up the database first, installs the free plugins
it needs, activates `foodify`, and sets currency, shipping, checkout fields and
the tagline. Nothing paid is installed — rule 5.

**4. Run it for real.**

```bash
./bootstrap.sh --env=staging
```

**5. Check it with the gate, not by eye alone.**

```bash
scripts/smoke-test.sh https://<your-staging-url> --staging
```

Non-zero means it is not ready. It is blocking, not advisory.

**6. Look at it.** Home, one category, one product, a combo box, the cart,
checkout, the account page — on a laptop and on a phone. It should match
`docs/Foodify-Storefront-Design.pdf`, with the client's real products in it.

## What will look unfinished — and why that is correct

These are waiting on the client, and the site shows them honestly rather than
inventing them:

| You will see | Because | Fixed by |
|---|---|---|
| Cream "photography placeholder" boxes | No prepared-dish photos yet | The week-3 shoot, uploaded per product |
| **FSSAI NOT CONFIGURED** | The licence number has not been supplied | The number, set in `business-profile.php` |
| Empty "What is inside" on combo boxes | Each box needs its contents entered | Product editor → *Combo — what's inside* |
| No saving shown on a combo | The separate-purchase total is not entered | Product editor → *Combo — cost bought separately* |
| "Not provided" in Pack & label | A declaration has not been entered | Product editor, per SKU |

The dummy values in the design PDF (the FSSAI number `10012345000001`, the
same-day NCR promise, the sample boxes) are **not in the theme and cannot reach
it** — `tests/fixture-leak-test.py` fails the build if they do.

## If anything is wrong

Appearance → Themes → activate the old theme. That is the whole rollback on
staging — the products and orders were never touched. `bootstrap.sh` also took a
database export into `../backups/` before it changed anything.

## Going live

Not from this document. `LAUNCH-RUNBOOK.md` is the cutover: the T-1 dress
rehearsal on staging, the sequence, the rollback and the 30 days after.
