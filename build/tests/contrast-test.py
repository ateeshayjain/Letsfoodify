#!/usr/bin/env python3
"""The HIG audit's contrast table, turned into a permanent gate.

WHY THIS EXISTS. The 2026-08-26 audit found flame-ink on flame at 1.68:1 —
unreadable step numbers on the product page's signature band — in a project
whose own recorded lesson already said "badge text on the flame wash needs the
deeper ink". The class was known and an instance shipped anyway, because the
lesson lived in prose. This turns it into arithmetic that runs in the gate.

Two layers, because static CSS cannot resolve the cascade:

  1. SAME-RULE SCAN: any style.css rule that sets BOTH a palette text colour
     and a palette background is measured. This is exactly the shape the P0
     bug had (.fd-prep__n set both, in one block).
  2. PINNED CONTEXTUAL PAIRS: pairings the cascade creates across rules —
     mute text inside the kraft-pale band, footer text on char — listed
     explicitly with WHERE they occur. A new band/colour combination must be
     added here when it is introduced; the list header says so.

It also asserts the audit's FIXES hold, so a revert fails by name.

    python3 tests/contrast-test.py
"""
import json, re, sys, os

KIT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
pal = {c['slug']: c['color'] for c in json.load(open(f"{KIT}/theme/foodify/theme.json"))['settings']['color']['palette']}
css = open(f"{KIT}/theme/foodify/style.css").read()

def lum(h):
    r, g, b = (int(h[i:i+2], 16) / 255 for i in (1, 3, 5))
    f = lambda c: c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = f(r), f(g), f(b)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b

def ratio(fg, bg):
    la, lb = lum(pal[fg]), lum(pal[bg])
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)

G, R, N = "\033[32m", "\033[31m", "\033[0m"
passed = failed = 0
def check(label, ok):
    global passed, failed
    print(f"  {G}PASS{N} {label}" if ok else f"  {R}FAIL{N} {label}")
    passed, failed = passed + (1 if ok else 0), failed + (0 if ok else 1)

TOKEN = r"var\(--wp--preset--color--([a-z-]+)\)"

print("── layer 1: every same-rule colour+background pairing in style.css ──")
n = 0
for m in re.finditer(r"([^{}]+)\{([^{}]*)\}", css):
    sel, body = m.group(1).strip().splitlines()[-1].strip(), m.group(2)
    fg = re.search(r"(?<![-a-z])color:\s*" + TOKEN, body)
    bg = re.search(r"background(?:-color)?:\s*" + TOKEN, body)
    if not (fg and bg):
        continue
    n += 1
    r = ratio(fg.group(1), bg.group(1))
    # 4.5 for text. If a rule ever needs the 3:1 large-text bar instead, it gets
    # an entry in the pinned list below with its justification — not a loosened
    # scan, which would loosen it for everything.
    check(f"{sel}: {fg.group(1)} on {bg.group(1)} = {r:.2f}", r >= 4.5)
check(f"the scan found rules to measure ({n}) — an empty scan is a broken scan, not a clean sheet", n >= 4)

print("── layer 2: contextual pairs the cascade creates (add new bands HERE) ──")
CONTEXT = [
    # (fg, bg, where, minimum)
    ("char",       "paper",      "body text on the page",                       4.5),
    ("mute",       "paper",      "secondary text on the page",                  4.5),
    ("mute",       "surface",    "secondary text on cards",                     4.5),
    ("kraft-deep", "kraft-pale", "prep detail + claim note (the P1-3 fix)",     4.5),
    ("leaf-ink",   "kraft-pale", "default badge, pay-saving chip",              4.5),
    ("flame-ink",  "paper",      "links, errors, is-missing values",            4.5),
    ("flame-deep", "flame-wash", "the flame badge (the recorded WP-03 lesson)", 4.5),
    ("paper",      "char",       "header strip and footer text",                4.5),
    ("paper",      "flame-deep", "prep step numerals (the P0-1 fix)",           4.5),
    ("line-strong","surface",    "input borders (non-text)",                    3.0),
    ("kraft",      "char",       "footer separator (the P2-8 fix)",             3.0),
    ("flame-deep", "surface",    "review stars (non-text)",                     3.0),
]
for fg, bg, where, need in CONTEXT:
    r = ratio(fg, bg)
    check(f"{fg} on {bg} — {where}: {r:.2f} (needs {need})", r >= need)

print("── the audit's specific regressions, pinned by name ──")
check("the prep chip does NOT pair flame-ink with flame any more",
      not re.search(r"\.fd-prep__n\s*\{[^}]*color--flame-ink", css))
check("prep detail text is not mute on the kraft band",
      "kraft-deep" in re.search(r"\.fd-prep__body span[^}]*\}", css).group(0))
check("no rule anywhere pairs flame-ink text with a flame background",
      not any(re.search(r"background(?:-color)?:\s*var\(--wp--preset--color--flame\)", b) and
              re.search(r"(?<![-a-z])color:\s*var\(--wp--preset--color--flame-ink\)", b)
              for b in re.findall(r"\{([^{}]*)\}", css)))

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
