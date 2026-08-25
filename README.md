# letsfoodify.com — rebuild

WordPress + WooCommerce storefront rebuild for **The Foodify Company** (Noida).
44 products, 12 categories. One developer, 15 weeks to cutover, 17 to handover.

```
CLAUDE.md              Project context. Read this first — it assumes you have not
                       read the handover PDF.
build/                 The build kit
  theme/foodify/       Standalone block theme. theme.json is the only token source.
  scripts/             bootstrap.sh (config as code), taxonomy-cleanup.php,
                       smoke-test.sh (blocking gate), clean-elementor-meta.php
  tests/               150 assertions. selftest.py proves the blocking gate works;
                       address-test.php and otp-test.php pin the WP-05 rules
  tools/               render-preview.py — generates preview/storefront.html
                       FROM the theme, so the mockup cannot drift from the code
  docs/                VERIFICATION (read first), REVIEW-NOTES, WP-03/04/05 notes,
                       SOLO-PLAN, MIGRATION
planning/              Pre-engagement scope, the WP-01 runbook, and two design pages
docs/HISTORY.md        The eight original commit messages — why each call was made
```

## Start here

```bash
cat CLAUDE.md                                  # rules, tokens, verified plugin facts
cat build/docs/VERIFICATION-2026-08-25.md      # what in the kit was wrong, and why
cat docs/HISTORY.md                            # how it was found, decision by decision
python3 build/tests/selftest.py                # prove the blocking gate works
```

## The five rules

1. **Never run anything against production without showing the command first.**
   Prod holds the live order history.
2. **Never push a staging database to production.** Config moves via
   `build/scripts/bootstrap.sh`. Change something in wp-admin, add it to the
   script the same session or it is lost at cutover.
3. **`smoke-test.sh` is blocking, not advisory.** Non-zero exit, no deploy.
4. **No colour, size, spacing or radius is hardcoded.** Presets are
   `var(--wp--preset--*)`, everything else `var(--wp--custom--*)`. Not in the
   token set? Add it to `theme.json` first.
5. **Ask before installing any paid plugin.** Recurring cost is already at the
   top of the client's tolerance.

## Open, and owned by the client

The clean-session cache test (decides whether the week-2 host migration happens
at all), DLT registration for SMS, the GBP verifiable address, the coupon partner
list, design sign-off, and the shipping/COD decisions. `CLAUDE.md` §7 and
`build/docs/SOLO-PLAN.md` have the dates.
