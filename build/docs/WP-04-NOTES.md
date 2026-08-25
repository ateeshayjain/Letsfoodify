# WP-04 — performance budget

Baseline, measured 24 Aug 2026: **179 requests · 2.5 MB · 73 JS · 60 CSS**.
Budget: **≤55 requests · ≤900 KB · ≤12 JS · ≤6 CSS**, plus mobile
LCP ≤2.5s, INP ≤200ms, CLS ≤0.1 on **field data**, 48h after launch.

## Most of this budget was won by WP-03, not by tuning

The 73 JS and 60 CSS files came from Elessi, Elementor, Elementor Pro, Slider
Revolution, Essential Addons and nasa-core loading together. Deleting that stack
and building a block theme is what moves those numbers; nothing in a performance
pass would have got there while the builders were still installed. The audit says
so directly — *"a speed fix cannot be bolted on, the weight is structural."*

So this package is the remainder: what the theme itself can do, and what only
staging can prove.

## Done

**The stylesheet is inlined.** `style.css` is small, so inlining removes a
render-blocking request from the critical path — which is what LCP measures. The
trade goes the other way on repeat visits, where an external file would be
cached, so it is filterable rather than assumed:
`add_filter( 'foodify_inline_stylesheet', '__return_false' )`.

**Both fonts are preloaded.** WP-04 requires fonts "self-hosted and preloaded".
`theme.json` self-hosts them but WordPress does **not** preload — it emits the
`@font-face` rules and the browser only discovers the files after parsing CSS.
On a page whose largest element is a heading, that discovery delay *is* the LCP.
Only the two faces actually used are preloaded; preloading everything is the
common mistake, because it competes with the image the browser also needs. The
preload is skipped if the file is missing, so it can never point at a 404.

**jQuery Migrate is dropped.** ~10KB of parse-and-execute on every page, shimming
jQuery APIs removed years ago. If something breaks it is a plugin calling a dead
API, and that plugin is the fix.

**Scripts are deferred — with two exceptions that matter.** An inline script
attached to a handle prints immediately after that handle's tag and is **not**
deferred. Defer its dependency and the inline code runs before the library
exists. **This theme would have broken itself:** `inc/checkout-fields.php`
attaches the PIN-code lookup inline to a jQuery-dependent handle, on the checkout
page. So jQuery is never deferred, and no handle carrying inline data is either.
`tests/perf-test.php` pins that rule with 11 assertions.

## Removed, deliberately

**The hand-rolled `fetchpriority` filter is gone.** It set
`fetchpriority="high"` on the first attachment image of the request. WordPress
already does this in `wp_get_loading_optimization_attributes()`, behind
`wp_high_priority_element_flag()` — a static that guarantees **exactly one**
element per request gets high priority, chosen with viewport and content-media
heuristics.

Worse than redundant, it picked the wrong image. "First attachment image
rendered" is not "largest contentful paint". On the front page the hero is a
photography placeholder with no image at all, so the first attachment was a
best-seller thumbnail in a grid below the fold — marked high priority *and*
eager, competing for bandwidth with whatever the browser actually needed first.
Two high-priority images is worse than none.

## Two things this file deliberately does not do

**Cart fragments stay.** Disabling `wc-cart-fragments` is the most-repeated
WooCommerce performance tip there is, and it is wrong for this store. The
mini-cart count in `parts/header.html` depends on it, and so does the
free-shipping progress bar, which registers itself as a fragment precisely so it
cannot show a stale figure after a quantity change. Turn fragments off and both
silently stop updating — the bar goes back to promising something the cart no
longer says, which is the defect it exists to prevent.

**Image conversion is ShortPixel's job.** ShortPixel is already budgeted
(₹10,000/yr) for modern formats plus CDN delivery. WordPress can be filtered to
write WebP on upload, but two systems converting the same images gives you
double-processed files and no single answer to "which variant is being served?".
Configure it there.

## What is measurable, and what is not

| Criterion | Checked by | Honest status |
|---|---|---|
| JS files ≤ 12 | `smoke-test.sh` §5 | automated |
| CSS files ≤ 6 | `smoke-test.sh` §5 | automated |
| HTML weight | `smoke-test.sh` §5 | automated (HTML only) |
| TTFB | `smoke-test.sh` §5 | automated |
| **Requests ≤ 55** | *nothing* | **not automatable with curl** |
| **Page weight ≤ 900 KB** | *nothing* | **not automatable with curl** |
| LCP / INP / CLS | — | **field data, 48h after launch** |

`smoke-test.sh` counts `<script src>` and `<link stylesheet>` in the HTML. It
cannot count total requests or total transferred weight, because those include
images, fonts, and anything script-injected — you need a real browser.

**Do not read a passing `smoke-test.sh` as a passing performance budget.** Two of
the four countable criteria are not covered by it at all. Run Lighthouse against
staging for those, and read the field numbers from CrUX or Search Console's Core
Web Vitals report 48 hours after launch — which is what the criterion actually
says, and the reason it cannot be signed off at cutover.

## Left for the hosting layer

Page and object caching and a CDN are WP-04 line items that live in the
infrastructure, not the theme. They depend on the week-2 hosting decision, which
is still waiting on the clean-session cache test.
