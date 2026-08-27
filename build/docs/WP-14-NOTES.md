# WP-14 — QA, launch, rollback

The last package. Most of WP-14 already existed, built incrementally by every
package before it — the blocking gate, its self-test, the WordPress boot, the
browser sweep. What was missing was the glue and one hole in the gate that the
scope itself had named.

## The hole: the classic launch catastrophe was a checklist item, not a gate

Scope §7, verbatim: *"A noindex shipped to production is the classic launch
catastrophe. Explicit checklist item at cutover."* It was on the checklist and
**absent from `smoke-test.sh`** — the gate would print "Clear to proceed" over
a site invisibly removed from Google. A checklist is prose, and this project
has watched prose lose four times.

Now §9 of the gate checks all three ways a site goes invisible: the robots
meta tag (WordPress's one "Discourage search engines" checkbox, easily left on
from staging), the `X-Robots-Tag` header, and a bare `Disallow: /` in
robots.txt. **`--staging` inverts the assertion** — staging *must* be
noindexed, or it enters the index as duplicate content before cutover.
`selftest.py` proves all four directions, including `--staging` refusing an
indexable site, and the unreachable case saying "could not verify" rather than
passing.

Also tightened: a redirect whose map row says **301** must first-hop 301/308.
The CSV's type column was read and ignored, so a 302 — which keeps the old URL
indexed and passes no equity — would have sailed through. That failure is
invisible at cutover and expensive at week six.

## `run-all-tests.sh` — the whole gate, one command

23 suites: lint, 15 pure PHP suites, the undefined-function scanner, contrast,
three gate self-tests, the WordPress boot, the browser sweep — and optionally
the blocking smoke test against a URL. **A suite that cannot run is a FAILURE,
not a skip**: a missing browser or WordPress turns the run red, because green
has to mean *verified*, not *unverifiable*.

## The runbook

`docs/LAUNCH-RUNBOOK.md` — T-7, T-1 (staging as dress rehearsal, run with
`--staging`), the cutover sequence with the noindex flip as its own conscious
step *before* the gate re-checks it, **a rollback written before it is needed**
with pre-agreed triggers ("no arguing with the gate at 5am"), and 30 days of
hypercare against the WP-01 baseline — because migration SEO loss shows up 3–6
weeks out, after everyone has agreed the project went well.

Rollback restores **code only**. The database never rolls back: orders taken
during the window are real orders. Rule 2 cuts both ways.

## What only launch week can do

Real-device QA (fonts, the nav hamburger, payment modals), checkout on live
rails, the load check, and everything in the runbook's checklists. The gate can
verify the state; it cannot place the test order. That is the one week where
the site, the client and this kit are finally in the same room — and every
tool it needs is now built.

## Tests

```
python3 tests/selftest.py   # 55 — noindex caught, --staging inversion proven
build/run-all-tests.sh      # 23 suites, ALL GREEN
```
