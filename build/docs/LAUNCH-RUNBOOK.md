# Launch runbook — cutover, verification, rollback, hypercare

The document for launch day. Written to be followed under pressure, so: every
step has a command or a click-path, every check has a pass condition, and the
rollback is written **before** it is needed — a rollback invented during an
incident is improvisation with the client's order history.

The five hard rules apply throughout, two of them constantly:
**Rule 1** — nothing runs against production without the exact command shown
and a yes. **Rule 2** — the staging database NEVER moves to production; prod
has orders staging has never seen. Configuration moves via
`scripts/bootstrap.sh` only.

---

## T-7 — the week before

- [ ] **Design sign-off recorded** (Nalin, in writing — the artifact links).
- [ ] Client inputs all present, verified by the gates, not by eye:
  - [ ] FSSAI licence — `smoke-test.sh` fails on `NOT CONFIGURED`
  - [ ] GSTIN + per-product HSN/rates — the Today screen stops warning
  - [ ] GA4 measurement ID — gate reports "gtag loader present", not "analytics OFF"
  - [ ] Photography loaded — feed exclusion notice reads zero products
  - [ ] Product weights — no order shows "Not dispatchable"
- [ ] `build/run-all-tests.sh` — **ALL GREEN, 23 suites**. Anything red stops the clock.
- [ ] **URL inventory → `redirects.csv`** finalised from the WP-01 crawl.
      `wp02-verify.sh` static analysis clean: no chains, no loops, no duplicates.
- [ ] **Baseline captured and filed**: GSC queries/pages/impressions export, GA4
      revenue and conversion, CWV. Without this the 30-day report proves nothing
      (scope §7.6: "how these projects avoid accountability").

## T-1 — staging is the dress rehearsal, run as if it were the show

```bash
# Staging must be INVISIBLE and the build must be green ON staging:
build/scripts/smoke-test.sh https://staging.letsfoodify.com --staging --redirects=redirects.csv
```

- [ ] `--staging` passes — **staging is noindexed** (an indexable staging fails the gate).
- [ ] Every redirect first-hops **301** and lands 200, no chains.
- [ ] Full checkout regression **on live payment rails** (Razorpay test mode +
      one real COD order, then cancel it): the WP-13 DebugView runbook is the
      script — view_item → add_to_cart → begin_checkout → purchase, purchase
      exactly once on refresh.
- [ ] Cross-browser/device pass: real iPhone Safari, one Android Chrome, desktop
      Chrome + Firefox. The browser sweep covers layout; **font rendering, the
      WP nav hamburger overlay, and the payment modal are the three things only
      real devices show.**
- [ ] Load check: `hey`/`ab` at ~2× expected peak against staging `/shop/` and
      one PDP — TTFB stays under the 0.6s budget.
- [ ] **Fresh backup of PRODUCTION taken and restore-TESTED on a scratch site.**
      A backup that has never been restored is a hope, not a backup.

## Cutover — the sequence

Low-traffic window (their orders are daytime; 05:00 IST). Announce nothing;
maintenance mode ≤ 30 min.

1. **Maintenance mode on** (production, with consent — Rule 1):
   `wp maintenance-mode activate`
2. **Final prod backup** (DB + uploads), timestamped, copied OFF the server.
3. **Deploy code from git** — theme + drop-ins. No database import (Rule 2).
4. **Configuration**: `bash scripts/bootstrap.sh --phase=2` — show the dry-run
   first, then live. It asserts the role, the endpoint, the shipping methods,
   and reports the email settings.
5. **Apply the 301 map** at the server/redirection layer; spot-check 3 by hand.
6. **THE FLIP**: Settings → Reading → "Discourage search engines" **UNTICKED**.
   This is the classic catastrophe and it is now also a gate assertion, but the
   gate runs after — untick it consciously, first, as its own step.
7. **Maintenance mode off.**
8. **The gate, against production, immediately:**
   ```bash
   build/scripts/smoke-test.sh https://letsfoodify.com --redirects=redirects.csv
   ```
   **Exit non-zero = go to Rollback. No arguing with the gate at 5am** — that
   is the entire point of having written it when calm.
9. Post-flip only: submit `sitemap_index.xml` in GSC; point Merchant Center at
   `/?foodify-feed=1`; place one real COD test order end-to-end and confirm the
   partner email, the GA4 purchase (once), and the Today screen all saw it.

## Rollback

**Triggers** — any one of these within the first 2 hours:
- The smoke test fails and the failure is not fixable in ≤ 15 minutes.
- Checkout cannot take an order (either payment path).
- The site is noindexed/serving 5xx and the cause is not identified.

**Path** (pre-agreed, no invention):
1. Maintenance mode on.
2. Restore the **code** state: `git checkout <pre-launch-tag>` / redeploy old
   theme directory. **The database is NOT rolled back** — orders taken during
   the window are real orders (Rule 2 cuts both ways).
3. Re-tick nothing: the old site was indexable; leave it indexable.
4. Maintenance off; smoke-test the OLD site; tell the client what happened,
   what was learned, and the new window — same day, in writing.

Cost of rollback: one lost window. Cost of debugging live on production: the
order history. The choice is pre-made.

## Hypercare — 30 days

| When | Check | Against |
|---|---|---|
| Daily, week 1 | GSC coverage + crawl errors; smoke-test cron | zero new errors |
| Daily, week 1 | Orders/day, conversion | WP-01 baseline |
| Weekly | Rankings for the top-20 GSC queries | baseline export |
| Weekly | Feed status in Merchant Center | zero disapprovals |
| Day 30 | The hypercare report: rankings, revenue, conversion vs baseline | the WP-01 numbers, or it proves nothing |

SEO loss from a migration **shows up 3–6 weeks after launch** (scope §7),
"long after everyone has agreed the project went well" — which is why hypercare
is 30 days and the report is against the baseline, not against a feeling.
