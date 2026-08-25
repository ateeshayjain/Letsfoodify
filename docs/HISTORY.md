# Commit history, ported

These eight commits are where this project's decisions were actually argued out.
They were made on the branch `claude/letsfoodify-revamp-b6cf4i` of
`ateeshayjain/TheMoneyApp`, because that was the only workspace available at the
time. The project was later extracted into this repository as a single commit,
which preserved every file and none of the reasoning — so the reasoning is
reproduced here, verbatim.

**Why this file exists.** `build/docs/VERIFICATION-2026-08-25.md` records *what*
was wrong in the build kit and what changed. This records *how it was found* and
*why each call went the way it did* — including the ones that were mistakes.
Once the source branch is deleted this is the only copy.

Messages are unedited apart from stripped `Co-Authored-By` and `Claude-Session`
trailers. Times are UTC. Oldest first.

| | Commit | Date | Subject |
|---|---|---|---|
| 1 | `534cfa7` | 2026-08-24 | [Scope the letsfoodify.com revamp + SEO engagement](#scope-the-letsfoodifycom-revamp--seo-engagement) |
| 2 | `cb4ce50` | 2026-08-24 | [Publish the letsfoodify scope as a readable page](#publish-the-letsfoodify-scope-as-a-readable-page) |
| 3 | `0029b03` | 2026-08-24 | [Design the letsfoodify storefront: W2 UI/UX concept](#design-the-letsfoodify-storefront-w2-uiux-concept) |
| 4 | `3043a1c` | 2026-08-25 | [Verify the handover's unverified four; add CLAUDE.md and the WP-01 runbook](#verify-the-handovers-unverified-four-add-claudemd-and-the-wp-01-runbook) |
| 5 | `4688f9b` | 2026-08-25 | [Audit the build kit against primary sources and fix what was wrong](#audit-the-build-kit-against-primary-sources-and-fix-what-was-wrong) |
| 6 | `0831132` | 2026-08-25 | [Commit the build kit itself — .gitignore had been swallowing it](#commit-the-build-kit-itself--gitignore-had-been-swallowing-it) |
| 7 | `4474a6f` | 2026-08-25 | [Remove the Foodify client project — it belongs in its own repo](#remove-the-foodify-client-project--it-belongs-in-its-own-repo) |
| 8 | `9adaf3c` | 2026-08-25 | [Revert the .gitignore negation added for the Foodify kit](#revert-the-gitignore-negation-added-for-the-foodify-kit) |

---

## Scope the letsfoodify.com revamp + SEO engagement

`534cfa7d5e1ca0ed62f403d0f970f26d31f4d5d1` · 2026-08-24 07:18 UTC

Turns the WhatsApp brief from Nalin (20-21 Aug) into a quotable scope: the
nine asks decoded into eleven workstreams with effort, six platform
recommendations, and the fifteen open questions that block a quote.

The three calls worth arguing about:

- Revamp on WooCommerce rather than rebuild headless. Every requirement has a
  mature WooCommerce path, the admin is non-technical, and a rebuild risks the
  indexed catalogue for no commercial gain at this size.
- The coupon-attribution ask is the only genuinely custom build. Specced to a
  data model, a payment-complete trigger, the refund reversal path, and a
  no-buyer-PII rule on the partner email — but blocked on how many partners
  there are and whether they earn commission, because that decides custom vs
  AffiliateWP.
- "Create a Google profile" is likely the wrong Google surface to lead with.
  Merchant Center moves revenue for a packaged-goods catalogue; Business
  Profile helps brand and local search. Do both, lead with the former.

Also flags what these projects usually get wrong: a revamp and an SEO
improvement pull against each other, so the URL freeze, the 301 map and a
pre-launch baseline are first-class deliverables rather than a post-launch
pass. And FSSAI licence display plus Legal Metrology declarations are launch
blockers for an Indian online food seller, built into the PDP template where
they also feed the Merchant Center product feed.

The live site is blocked by this environment's egress policy, so the business
read is from search snapshots and the plugin/theme/hosting stack is unknown.
Stated in the document and carried as a 15% contingency on effort.

---

## Publish the letsfoodify scope as a readable page

`cb4ce50d583ec45aced2a09c8a7fd69c52cb1a95` · 2026-08-24 07:22 UTC

Same content as SCOPE.md, set as a build docket: workstreams carry their
day counts in a right-aligned column, the phase overlap is a real 13-week
grid rather than prose, and the recommendations and risks are ruled apart
so the six calls and the migration warning can be found without reading
the whole thing.

---

## Design the letsfoodify storefront: W2 UI/UX concept

`0029b0384381081c6dedcf183268da2ad96277a4` · 2026-08-24 07:31 UTC

Five responsive screens rather than artboards — home, shop, product, bag and
checkout, plus the design system behind them. Deliberately a working page, so
"mobile responsive" can be checked by resizing instead of taken on trust.

The direction comes from the product's own mechanic rather than the category's
visual habits. What is actually distinctive about Foodify is a transformation:
dry powder plus hot water is a real meal in six minutes. So prep time and water
temperature are treated as instrument readings in mono type, and the three
ranges are colour-coded by how you make them — Express marigold for hot water,
Hot & Fresh ember for the stove, Flavors green for cold. The chip on a card
tells you the prep method before you read a word, which is how people actually
choose here. Explicitly not cream-and-terracotta with a serif: that is where
every instant-food brand lands, and it cannot carry the shelf-life and
prep-time story this product is built on.

Two decisions from the scope are visible in the design rather than described:

- Checkout leads with guest as the default path and offers the mobile-OTP
  account beside it, per R4. Forcing registration is the standard Indian D2C
  conversion killer.
- The label panel — FSSAI licence, net quantity, veg mark, allergens, country
  of origin, consumer care — is a component of structured fields, not free
  text in a description. That makes the §08 duties enforceable and renders
  them consistently, and the same fields feed the Merchant Center product
  feed, so the compliance work pays for itself twice.

The coupon flow shows its own privacy boundary: the applied-coupon state says
the partner is emailed the items and value and never the buyer's details,
which is the no-PII rule from §06 stated where the customer can see it.

Placeholders are labelled as placeholders. Every food image is CSS, since
photography is Q13 and unpriced, and the layouts are built to take real shots
directly. Prices and SKUs are read from search snapshots of the live site —
the site itself was never reachable from here — and need confirming.

---

## Verify the handover's unverified four; add CLAUDE.md and the WP-01 runbook

`3043a1cd6217fa3448f6fb8db87cca423b2e3a5a` · 2026-08-25 08:18 UTC

Session 1 of the rebuild. No feature code, as asked. Two of the four
verifications came back wrong, both in the silent-failure class the handover
warns about.

Rank Math's three settings blobs are named with HYPHENS —
rank-math-options-titles, -general, -sitemap — while its state flags use
underscores (rank_math_modules, rank_math_version). Confirmed on both the write
path (includes/class-installer.php) and the read path
(includes/settings/class-settings.php). The underscore form that nearly every
tutorial uses creates a new, ignored option and applies nothing. The values are
serialized arrays, so bootstrap must use `wp option patch update`, not
`wp option update`, which would replace the whole blob. Two further traps
recorded: `_robots` is inert unless `_custom_robots` is `on`, and Rank Math
already noindexes post_tag/product_tag/post_format on a FRESH install — via
add_option(), which no-ops if the option exists, so a site that once had Rank
Math will not get those defaults back.

Razorpay does not need billing_last_name. woo-razorpay reads it in exactly one
place, building prefill.name for the payment modal; it is display-only, absent
from signature verification and capture, and an empty surname yields a harmless
trailing space. Recommendation is still to keep the field hidden and populated
from a name split: the customer sees nine fields either way, so removing the
data field buys nothing the acceptance criterion measures, while the WP-11
courier API and the unchosen GST invoice plugin both commonly read it.

Fonts are sourced and verified rather than assumed — a default curl UA gets
served TTF, not woff2. fontTools confirms Fraunces carries opsz 9..144 and
wght 100..900 but NOT the SOFT or WONK axes the handover's "soft optical serif"
description implies; those exist only upstream. Instrument Sans ships without
its wdth axis. Flagged as a WP-03 decision.

The WP-01 runbook orders the six fixes so the reversible ones land first and
the de-index lands last behind its own gate, because the option blob that
noindexes 170 tags also controls index on 44 products and a wrong key would
noindex the catalogue with nothing looking wrong in wp-admin. Every check
asserts HTTP status before grepping: the handover's own criterion
(curl -s ... | grep -c 'Inject JS' returns 0) cannot distinguish a fixed page
from an unreachable one.

Two blockers are recorded rather than worked around: ./build/ does not exist in
this workspace, and letsfoodify.com is unreachable from it, so the cache-header
test and every kit-contents claim are outstanding.

---

## Audit the build kit against primary sources and fix what was wrong

`4688f9b64ce0903837344371ef9079a3754a3d49` · 2026-08-25 09:45 UTC

The kit arrived syntax-checked only. Verified the four items Part 4 flags as
unverified, plus two defects found while looking. Full record in
build/docs/VERIFICATION-2026-08-25.md.

Rank Math: the blob names were right (hyphenated, which most tutorials get
wrong) but three of four sub-keys were invented. noindex_product_tag,
noindex_portfolio and the sitemap's exclude_post_types do not exist; the real
form is tax_<tax>_custom_robots plus tax_<tax>_robots, and _robots is inert
unless _custom_robots is on. --dry-run could never have caught this: wp option
patch on a key the plugin never reads SUCCEEDS, writing a key nothing consults,
and the kit wrapped each call in 2>/dev/null || true so a genuine failure still
printed a tick. Phase 1 now asserts every patch, detects the portfolio CPT
instead of guessing it, and — because the same blob controls index on 44
products — verifies the catalogue is still indexable before it exits. Also:
phase 1 on prod now prompts, the hard rewrite flush is soft so .htaccess
survives, product comment_status is opened so the reviews tab actually renders,
the store base state is UP rather than HR so the GST split is not inverted, and
core pages are wired to their woocommerce_*_page_id.

Razorpay does not need billing_last_name — it reads it in one place, building
prefill.name for the modal. But the kit removed the field without the
full-name split REVIEW-NOTES prescribes, so the stored surname was simply gone
for the courier payload and every invoice plugin. Split added on both the
checkout and the address-book paths; the customer still sees nine fields.

The PIN lookup could not be reached from here, so availability is unverified,
but three real bugs were: it only filled empty fields, so correcting a mistyped
PIN left the old city and state and therefore the wrong shipping zone and GST;
it matched states by display text, which silently fails for Odisha, Puducherry
and Uttarakhand; and it fired per keystroke. The endpoint is now filterable so
an offline dataset is a one-line swap. The dataset itself is deliberately not
built — no authoritative source was reachable, and a PIN-to-state table written
from memory is wrong at exactly the split-state boundaries where a wrong state
breaks GST.

Fonts added from the upstream repository rather than the CSS API, which serves
per-subset files under other names and hands TTF to a default curl UA. Both
carry their full axes. Two decisions attached: Instrument Sans has no rupee
glyph in any cmap subtable, so every price renders its symbol from a fallback
font, and Fraunces at 162KB is 18% of the page budget against 90KB with SOFT
and WONK pinned.

smoke-test.sh had the bug it exists to catch, in both directions. Absence
checks over an empty body report absence, so a site that was completely down
printed PASS for "no leaked source comment" and "no fake viewer counter". The
product URL was never resolved when relative, so on such a theme the counter
was reported absent on a site that still had it. Checkout was fetched with no
cart, so COD and Razorpay read as missing on a healthy store while the field
count passed vacuously at zero — and the field regex could not match
billing_address_1 anyway. tests/selftest.py now serves healthy, defective and
unreachable fixture sites and asserts what the gate reports: 13 pass against
the fixed gate, and GATE=scripts/smoke-test.sh.orig still reproduces the
original fail-open, so the bug stays demonstrable rather than becoming a claim.

coupon-attribution.php applied its single-winner rule on the credit path only.
The refund path looped every partner coupon and debited each the full refund,
so on a two-coupon order the losing partner — never credited — went negative
and was emailed a correction for a sale they were never told about. The rule
now lives in one function called by both paths, and a refund returns early
unless the order actually carries the notified flag.

---

## Commit the build kit itself — .gitignore had been swallowing it

`083113225792633bff15751ea405cc4a6c7194df` · 2026-08-25 10:08 UTC

The repo ignores build/ for Xcode artefacts, and that rule matches at any depth,
so clients/letsfoodify/build/ was never tracked. The previous commit recorded
only the surrounding docs while reporting success: git status was clean, git add
said nothing, and every kit fix from that session — the Rank Math keys, the
surname split, the PIN lookup, the fonts, the smoke-gate rewrite, the coupon
refund asymmetry — existed only on disk. An ignored path and a committed path
produce identical output, which is the same failure shape as the rest of this
audit.

Added a !clients/letsfoodify/build/ negation rather than narrowing the Xcode
rule, since git will not descend into an ignored directory and the negation has
to name the directory itself. Recorded in CLAUDE.md, with the instruction to
verify kit commits with git show --stat rather than a clean git status.

This commit therefore carries both sessions: the first-pass fixes reported as
pushed but never tracked, and the second pass over the rest of the kit.

Second pass, all detailed in docs/VERIFICATION-2026-08-25.md:

taxonomy-cleanup.php deleted every doomed term before its redirect existed
anywhere, leaving ~150 URLs at 404 until a manual CSV import that can simply be
forgotten — against WP-02's own zero-soft-404s criterion. Redirects now install
through Rank Math's API before each delete, and the script refuses to run rather
than delete into a void when that module is absent. The noindex pass was
irreversible across ~150 terms and now records prior state behind undo-noindex.
report warns when survivors exceed the twenty-archive target KEEP_MIN was never
reconciled against.

style.css forced min-height 44px onto every anchor, inflating navs, footers and
breadcrumbs where WCAG exempts inline links, and set display:block on the table
element so cart columns collapse on desktop. It also hardcoded a colour, two font
sizes and several radii in the file whose own header forbids exactly that —
because theme.json has no radius preset system and settings.custom was null.
Tokens added there; CSS and both patterns now reference them.

hero.php shipped an empty img src on the homepage's largest element, which makes
the browser re-request the whole document, and hardcoded the kraft-pale hex so
the hero had stopped tracking the palette.

product-display.php overwrote WooCommerce's "Available on backorder" with a flat
"In stock", telling the customer something untrue in the module whose purpose is
removing dishonest social proof.

theme.json advertised a customTemplates entry with no template behind it, in a
classic child theme where the feature does nothing at all.

---

## Remove the Foodify client project — it belongs in its own repo

`4474a6f7e2aec73ea030f07f6712933baccccb41` · 2026-08-25 10:12 UTC

letsfoodify.com is a separate client engagement and nothing in it relates to The
Money App. It only ever lived here because this was the workspace the sessions
ran in, and it should not have accumulated four commits' worth of an unrelated
WordPress project in an iOS repository.

Extracted whole, with its own history, and handed over as a git bundle. Nothing
is lost: the per-session commits remain reachable on this branch if the
reasoning behind a specific change is ever wanted.

Also reverts the .gitignore negation added for it. The `build/` rule goes back
to covering only Xcode artefacts, which is all it was ever meant to do — and the
fact that it silently swallowed an entire directory tree is recorded in the new
repo's CLAUDE.md rather than worked around here.

---

## Revert the .gitignore negation added for the Foodify kit

`9adaf3ce202ad7e9e3aff4ab23ea49fff5f1a107` · 2026-08-25 10:12 UTC

Missed in the previous commit: `git add -A .gitignore clients` failed on the
`clients` pathspec, which no longer existed on disk, and took the .gitignore
staging with it. The error went to /dev/null.

The `build/` rule is back to covering only Xcode artefacts, which is all it was
ever for.

---

## What the last two are about

Commits 7 and 8 are the move out of `TheMoneyApp`, not work on the store. They
are kept because commit 6 is only intelligible beside them: the `build/` rule in
that repo's `.gitignore` — meant for Xcode artefacts, and matching at any depth —
silently swallowed the whole kit, so a commit reported as successful contained
only documentation. `git status` was clean and `git add` said nothing. That is
the same failure shape as the Rank Math option keys, the fail-open smoke gate and
the absence checks in the WP-01 runbook: **the absence looked exactly like
success.** It is worth carrying forward as a habit, not just a footnote.
