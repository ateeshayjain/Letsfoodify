#!/usr/bin/env python3
"""The one number.

The site's claim is "6 minute mein ghar ka khana ready!" and the discipline
that makes a claim like that work is that NOTHING on the site outruns it.
Spice Up restates "7 minutes" on every surface; the day one of their packs
says 9, the claim is a slogan. So:

  1. The claim lives in ONE place — the blogdescription line in bootstrap.sh
     — and the hero renders it through a token, never as literal text.
  2. No static copy in the theme (patterns, templates, parts) may state a
     preparation time LONGER than the claim. Shorter is fine: "Five minutes on
     the stove" is a range that beats the claim, not one that breaks it.
  3. The preview's fixture products may not outrun it either — the review page
     is what the client signs off.
  4. foodify_prep_exceeds_claim() (business-profile.php) is the editor's
     warning; its verdicts are pinned here via a tiny PHP shim.

    python3 tests/claim-test.py
"""
import os, re, subprocess, sys

KIT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
THEME = os.path.join(KIT, "theme", "foodify")
G, R, N = "\033[32m", "\033[31m", "\033[0m"
passed = failed = 0
def check(label, ok):
    global passed, failed
    print(f"  {G}PASS{N} {label}" if ok else f"  {R}FAIL{N} {label}")
    passed, failed = passed + (1 if ok else 0), failed + (0 if ok else 1)

WORDS = {"one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7,
         "eight": 8, "nine": 9, "ten": 10, "twelve": 12, "fifteen": 15, "twenty": 20,
         "ek": 1, "do": 2, "teen": 3, "char": 4, "paanch": 5, "chhe": 6, "saat": 7, "aath": 8, "dus": 10}
MINUTE = re.compile(r"\b(\d+|" + "|".join(WORDS) + r")[ -]?(min|mins|minute|minutes)\b", re.I)

def minutes_in(text):
    for m in MINUTE.finditer(text):
        tok = m.group(1).lower()
        yield (int(tok) if tok.isdigit() else WORDS[tok]), m.group(0)

print("── the claim, and where it lives ──")
boot = open(os.path.join(KIT, "scripts", "bootstrap.sh")).read()
m = re.search(r'wp option update blogdescription "([^"]+)"', boot)
check("bootstrap.sh sets the site tagline", bool(m))
tagline = m.group(1) if m else ""
claim = [n for n, _ in minutes_in(tagline)]
check(f"the tagline states one time: {tagline!r}", len(claim) == 1)
CLAIM = claim[0] if claim else 0

php = open(os.path.join(THEME, "inc", "business-profile.php")).read()
php_claim = re.search(r"function foodify_claim_minutes\(\): int \{\s*return (\d+);", php)
check("foodify_claim_minutes() agrees with the tagline", bool(php_claim) and int(php_claim.group(1)) == CLAIM)

hero = open(os.path.join(THEME, "patterns", "hero.php")).read()
check("the hero H1 renders the tagline token, not literal copy", "<h1" in hero and "<!--FOODIFY_TAGLINE-->" in hero)
check("the hero carries no second time claim of its own", not list(minutes_in(hero)))

print(f"── no static copy outruns {CLAIM} minutes ──")
n = 0
for sub in ("patterns", "templates", "parts"):
    d = os.path.join(THEME, sub)
    for fn in sorted(os.listdir(d)):
        text = open(os.path.join(d, fn)).read()
        for mins, phrase in minutes_in(text):
            n += 1
            check(f"{sub}/{fn}: {phrase!r} ≤ claim", mins <= CLAIM)
check(f"the sweep found time claims to check ({n}) — an empty sweep is a broken sweep", n >= 3)

print("── the preview's fixtures obey it too ──")
prev = open(os.path.join(KIT, "tools", "render-preview.py")).read()
fixture_times = [int(x) for x in re.findall(r'"(\d+) MIN"', prev)]
check(f"no fixture product outruns the claim ({sorted(set(fixture_times))})", fixture_times and max(fixture_times) <= CLAIM)
check("the preview reads the tagline from bootstrap.sh, not a copy", "tagline_from_bootstrap" in prev and 'blogdescription "' in prev)

print("── the editor's warning ──")
shim = r"""
define('ABSPATH', __DIR__);
require $argv[1];
foreach (['6 minutes','5','6','7','7 minutes','10 min','no idea',''] as $v) {
    echo $v, '=', foodify_prep_exceeds_claim($v) ? 'over' : 'ok', "\n";
}
"""
out = subprocess.run(["php", "-r", shim, os.path.join(THEME, "inc", "business-profile.php")],
                     capture_output=True, text=True).stdout
check("6 minutes is within the claim",     "6 minutes=ok" in out)
check("5 is within the claim",             "5=ok" in out)
check("7 exceeds it — the editor warns",   "7=over" in out and "7 minutes=over" in out)
check("10 min exceeds it",                 "10 min=over" in out)
check("no number, no contradiction",       "no idea=ok" in out and "=ok" in out.splitlines()[-1])

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
