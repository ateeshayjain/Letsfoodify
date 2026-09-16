#!/usr/bin/env python3
"""Invented data stays in the renderer that invents it.

WHY THIS EXISTS. The preview fills the client's gaps with dummy values so the
mock-up reads as a finished page: an FSSAI licence number, a same-day NCR
dispatch promise, a curated "Complete the meal" pairing. Every one of those is
a CLAIM. On a live food site an invented licence number is not a placeholder,
it is a false statement to a regulator and a customer — and this project has
already had a plausible-looking FSSAI number reach four templates once.

So the rule is mechanical: a fixture value may appear in tools/render-preview.py
and nowhere else. Not in the theme, not in a pattern, not in a template, not in
bootstrap.sh — the last one being the file that configures the real site.

The list is READ FROM the renderer, not retyped here: a dummy renamed there but
copied here would pass while the real string leaked. A missing or empty list is
a FAILURE, because a scan with nothing to look for passes every time.

    python3 tests/fixture-leak-test.py
"""
import os, re, sys

KIT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(KIT, "tools", "render-preview.py")
G, R, N = "\033[32m", "\033[31m", "\033[0m"
passed = failed = 0

def check(label, ok):
    global passed, failed
    print(f"  {G}PASS{N} {label}" if ok else f"  {R}FAIL{N} {label}")
    if ok: passed += 1
    else:  failed += 1

src = open(SRC).read()
values = dict(re.findall(r'^(FIXTURE_[A-Z_]+)\s*=\s*"([^"]+)"', src, re.M))
check(f"the renderer declares its fixture values ({len(values)} found)", len(values) >= 2)

# Where a fixture value must never appear. bootstrap.sh is the sharp one: it is
# the file whose contents become the live site's configuration.
SEARCH = [("theme", ".html"), ("theme", ".php"), ("theme", ".css"),
          ("scripts", ".sh"), ("scripts", ".php")]
files = []
for sub, ext in SEARCH:
    root = os.path.join(KIT, sub)
    for dirpath, _dirs, names in os.walk(root):
        files += [os.path.join(dirpath, n) for n in names if n.endswith(ext)]
check(f"there are files to scan ({len(files)}) — an empty scan is not a clean sheet", len(files) > 20)

for name, value in sorted(values.items()):
    hits = []
    for f in files:
        try:
            if value in open(f, encoding="utf-8", errors="ignore").read():
                hits.append(os.path.relpath(f, KIT))
        except OSError:
            hits.append(f"UNREADABLE {f}")
    check(f"{name} ({value[:34]}) is fixture-only" + (" — found in " + ", ".join(hits[:3]) if hits else ""),
          not hits)

# And the theme still refuses to invent one itself.
profile = open(os.path.join(KIT, "theme/foodify/inc/business-profile.php")).read()
check("the theme still prints NOT CONFIGURED for an unset licence", "NOT CONFIGURED" in profile)
check("the theme ships no licence number of its own",
      re.search(r"'fssai'\s*=>\s*''", profile) is not None)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
