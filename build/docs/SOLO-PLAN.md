# Solo plan — one developer, 15 weeks

The ten-week plan in the client Rebuild Scope assumes four or five people working in
parallel. With one developer the phases cannot overlap, and a host migration has been
added. **Realistic: 15 weeks to cutover, 17 to handover.**

The waiting periods do not shrink with team size, which is the one piece of good news:
DLT registration, GBP verification and photography retouching all run in the background
regardless. Start them in week 1 and they cost nothing on the critical path.

## What ships at launch, and what does not

Cutting scope is how a solo build stays on schedule. These are the calls I would make.

**Non-negotiable — ships at launch**

- SEO layer (Rank Math, meta, schema, sitemap, tag consolidation) — cheapest return in the project
- Theme rebuild and the builder-stack removal
- Checkout: 25 fields → 9, email required, state select
- COD with caps, and the prepaid discount
- OTP login and the address book
- Coupon attribution with partner emails
- Performance budget
- GST invoicing and Shiprocket

**Deferred to post-launch — say so in the contract**

| Deferred | To | Why it is safe to wait |
|---|---|---|
| Partner self-serve dashboard | Week 17 | The notification email satisfies the actual requirement. The dashboard is a support-load optimisation. |
| Merchant Center feed | Week 17 | Needs the new photography settled first anyway. |
| Abandoned-cart flows | Week 18 | Needs a few weeks of email capture before there is anything to recover. |
| Metorik | Drop for now | Native Woo Analytics covers the launch dashboard. Saves ~₹50k/yr until the volume justifies it. |
| 8 blog posts | Weeks 18–22 | One a week is sustainable solo. Eight in a fortnight is not. |
| Judge.me automation | Week 16 | Reviews on manually at launch; the delivery-triggered request follows. |

## Schedule

| Weeks | Focus | Runs in background |
|---|---|---|
| 1 | Access, backups, Phase-1 hotfixes on prod, host shortlist | **DLT registration**, **GBP verification** started day one |
| 2 | Host migration to staging on the new host; verify parity | Photography shot list, studio booked |
| 3 | Design tokens, component library, product + category screens | **The shoot happens this week** |
| 4 | Cart, checkout, account screens. **Client sign-off gate.** | Retouching |
| 5–6 | Theme build: product, category templates | Product copy rewrite, 44 SKUs |
| 7–8 | Cart, checkout, account templates | Alt text, image ingestion |
| 9 | Homepage, content pages, 404, search | |
| 10 | Performance pass to budget | |
| 11 | OTP login, address book, reorder | DLT must be approved by now |
| 12 | COD, prepaid discount, shipping zones, GST, Shiprocket | |
| 13 | Coupon attribution, admin dashboard | |
| 14 | Taxonomy execute, redirect map, full QA | |
| 15 | **Cutover.** Tue–Thu, 02:00–06:00 IST | |
| 16–17 | Reviews, monitoring, training, handover | |

## Weekly cadence

Solo work fails through drift, not through difficulty. Three fixed points a week:

- **Monday, 30 min** — pick the week's single deliverable. One, not three.
- **Thursday, 30 min** — client demo on staging, even when it is ugly. Weekly contact is
  what stops a week-4 sign-off gate becoming a week-7 one.
- **Friday, 30 min** — run `smoke-test.sh`, commit, update `bootstrap.sh` with anything
  changed in wp-admin this week. **This is the step that gets skipped and it is the one
  that makes cutover survivable.**

## The failure modes to watch

1. **Design sign-off slips.** Everything is behind it. Get one named decision-maker in the
   contract, not a committee.
2. **`bootstrap.sh` drifts from reality.** Someone flips a setting in wp-admin, never
   records it, and cutover silently loses it. The Friday ritual exists for this.
3. **Solo means no code review.** `smoke-test.sh` is the substitute. Do not skip it because
   the change looked small.
4. **Host migration in week 2 is a real project.** Do not treat it as an afternoon. Verify
   cron, email deliverability, SSL, and Razorpay webhooks after the move.
