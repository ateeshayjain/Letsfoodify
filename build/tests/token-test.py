#!/usr/bin/env python3
"""Every design token the theme uses must exist in theme.json.

WHY THIS EXISTS. style.css asked for var(--wp--custom--tapTarget). The key in
theme.json is `tapTarget`, but WordPress emits custom properties KEBAB-CASED —
--wp--custom--tap-target — so the variable never resolved, the declaration was
silently invalid, and a link lost its tap height and its spacing. The browser
does not warn. Rule 4 says every value comes from the token set; this checks
that the token set actually contains what is being asked for.

    python3 tests/token-test.py
"""
import json, os, re, sys

KIT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
THEME = os.path.join(KIT, "theme", "foodify")
tj = json.load(open(os.path.join(THEME, "theme.json")))

def kebab(s):
    return re.sub(r"(?<!^)(?=[A-Z])", "-", s).lower()

def custom_keys(node, prefix=""):
    for k, v in node.items():
        key = prefix + kebab(k)
        if isinstance(v, dict):
            yield from custom_keys(v, key + "--")
        else:
            yield key

known = set()
st = tj["settings"]
for c in st["color"]["palette"]:            known.add("color--" + c["slug"])
for f in st["typography"]["fontSizes"]:      known.add("font-size--" + f["slug"])
for f in st["typography"].get("fontFamilies", []): known.add("font-family--" + f["slug"])
for sp in st["spacing"]["spacingSizes"]:     known.add("spacing--" + sp["slug"])
for k in custom_keys(st.get("custom", {})):  known.add("custom--" + k)

G, R, N = "\033[32m", "\033[31m", "\033[0m"
passed = failed = 0
def check(label, ok):
    global passed, failed
    print(f"  {G}PASS{N} {label}" if ok else f"  {R}FAIL{N} {label}")
    if ok: passed += 1
    else:  failed += 1

check(f"theme.json declares tokens ({len(known)})", len(known) > 30)

files = []
for dirpath, _d, names in os.walk(THEME):
    files += [os.path.join(dirpath, n) for n in names if n.endswith((".css", ".php", ".html", ".json"))]
files.append(os.path.join(KIT, "tools", "render-preview.py"))
check(f"there are files to scan ({len(files)})", len(files) > 20)

USE = re.compile(r"var\(--wp--(preset--(?:color|font-size|font-family|spacing)--[a-z0-9-]+|custom--[A-Za-z0-9-]+)\)")
missing = {}
seen = 0
for f in files:
    text = open(f, encoding="utf-8", errors="ignore").read()
    for m in USE.finditer(text):
        seen += 1
        tok = m.group(1)
        tok = tok[len("preset--"):] if tok.startswith("preset--") else tok
        if tok not in known:
            missing.setdefault(tok, set()).add(os.path.relpath(f, KIT))
check(f"the scan found token uses ({seen}) — an empty scan is not a clean sheet", seen > 100)
for tok, where in sorted(missing.items()):
    check(f"--wp--{tok if tok.startswith('custom') else 'preset--' + tok} exists — used in {', '.join(sorted(where)[:3])}", False)
if not missing:
    check("every token used resolves to a theme.json entry", True)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
