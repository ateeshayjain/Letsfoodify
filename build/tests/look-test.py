#!/usr/bin/env python3
"""The storefront's LOOK rules, pinned: focus, the 16px floor, 44px targets,
and the copy the UX review (8 Nov 2026) found contradicting itself.

WHY THIS EXISTS. The review found focus switched off for every form field
(`outline: none` with a 1.13:1 halo as the "replacement"), 13px text on the
cards, the footer and the product facts, 16px-tall footer links, a filter
panel printed open on phones, and a home page promising "12-month shelf life"
and "no cooking" above a trust strip saying 9–12 months and a range that needs
a stove. Each was a one-line regression waiting to happen again, so each is a
check here.

What a static scan can and cannot do: it reads style.css and the template
markup this theme owns. It cannot resolve the cascade, so the 16px rule is
"no rule below base unless it is an uppercase label" — the shape decision 1
allows — plus an explicit allowlist, each entry with its reason.

    python3 tests/look-test.py
"""
import json, os, re, sys

KIT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = os.path.join(KIT, "theme", "foodify")
css = open(os.path.join(T, "style.css")).read()
read = lambda *p: open(os.path.join(T, *p)).read()

G, R, N = "\033[32m", "\033[31m", "\033[0m"
passed = failed = 0
def check(label, ok):
    global passed, failed
    print(f"  {G}PASS{N} {label}" if ok else f"  {R}FAIL{N} {label}")
    passed, failed = passed + (1 if ok else 0), failed + (0 if ok else 1)

# Rules as (selector, body), comments stripped, at-rule wrappers flattened.
nocomment = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
rules = [(m.group(1).strip().split("{")[-1].strip(), m.group(2))
         for m in re.finditer(r"([^{}]+)\{([^{}]*)\}", nocomment)]
def rule(sel_regex):
    return [b for s, b in rules if re.search(sel_regex, s)]

print("── focus is visible everywhere (decision 3) ──")
check("no rule sets outline:none / outline:0 except on a details marker or a hidden element",
      not [s for s, b in rules if re.search(r"outline\s*:\s*(none|0)\b", b)])
check("the global ring is 2px solid flame-ink, offset 2px",
      bool(re.search(r":focus-visible\s*\{[^}]*outline:\s*2px solid var\(--wp--preset--color--flame-ink\);\s*outline-offset:\s*2px", nocomment)))
check("a :root :is(...) ring covers inputs, selects, textareas and summaries (beats input:focus-shaped plugin rules)",
      bool(re.search(r":root :is\(a, button, input, select, textarea, summary[^)]*\):focus-visible\s*\{[^}]*outline:\s*2px solid", nocomment)))
focus_rules = rule(r"input:not\(\[type=\"checkbox\"\]\):not\(\[type=\"radio\"\]\):focus")
check("the field :focus rule has the same :not() chain as the border rule (it lost 0,1,1 vs 0,2,1)", bool(focus_rules))
check("the field :focus rule no longer leans on the 1.13:1 flame-wash halo",
      all("flame-wash" not in b and "box-shadow" not in b for b in focus_rules))
check("on charcoal grounds the ring turns paper (flame-ink on char is under 3:1)",
      bool(re.search(r"has-char-background-color[^{]*:focus-visible\s*\{[^}]*outline-color:\s*var\(--wp--preset--color--paper\)", nocomment)))

print("── buttons carry their own type and shape ──")
check("button { font: inherit } — no 13.3px system font on a bare <button>", bool(re.search(r"(^|\})\s*button\s*\{\s*font:\s*inherit", nocomment)))
sec = rule(r"^\.fd-secondary,")
sec = sec[0] if sec else ""
for prop, val in [("border-radius", "radius--pill"), ("font-size", "font-size--base"), ("font-family", "font-family--ui"),
                  ("min-height", "tap-target"), ("border", "1px solid var(--wp--preset--color--line-strong)")]:
    check(f".fd-secondary sets {prop} ({val})", bool(re.search(prop + r"\s*:[^;]*" + re.escape(val), sec)))
check("the orders table's Cancel / Report a problem share the .fd-secondary pill",
      bool(re.search(r"\.fd-secondary,\s*\.woocommerce-orders-table \.foodify-cancel,\s*\.woocommerce-orders-table \.foodify-return\s*\{", nocomment)))
check("destructive verbs use the danger token, never flame-ink",
      not any("flame-ink" in b for s, b in rules if ".fd-danger" in s) and bool(rule(r"\.fd-danger")))
check(".fd-address__verb buttons are 16px", any("font-size--base" in b for b in rule(r"\.fd-address__verb button$")))

print("── the 16px floor (decision 1) ──")
# Below base is allowed only for uppercase labels/badges. Each exception that
# is not uppercase in CSS is named, with why.
ALLOW = {
    ".wc-block-mini-cart .wc-block-mini-cart__badge": "the cart count — a one- or two-digit numeral in a pill",
}
small = []
for s, b in rules:
    if re.search(r"font-size:\s*var\(--wp--preset--font-size--(xs|sm)\)", b):
        if "text-transform: uppercase" in b or s in ALLOW:
            continue
        small.append(s)
check("every sub-16px rule in style.css is an uppercase label or a named exception"
      + (f" — offenders: {small}" if small else ""), not small)
check("the scan saw the allowed uppercase labels (an empty scan is a broken scan)",
      sum(1 for s, b in rules if re.search(r"font-size--(xs|sm)", b) and "uppercase" in b) >= 5)
for sel in [r"\.fd-products \.fd-unit-price", r"\.fd-products \.fd-yield", r"^\.fd-rating-count", r"\.fd-shop__toolbar \.woocommerce-result-count",
            r"^\.fd-ship$", r"^\.fd-cart-promise$", r"^\.fd-pay-saving$", r"td\.product-name \.variation", r"^\.fd-yield-strip$",
            r"^\.fd-assure__item$", r"^\.fd-review figcaption$", r"^\.fd-prep__body span$", r"^\.fd-spec__group\.is-label dd$",
            r"^\.woocommerce-form-login label$", r"\.wc-block-attribute-filter__title"]:
    bodies = rule(sel)
    check(f"{sel.strip('^$')} is 16px", bool(bodies) and all("font-size--base" in b for b in bodies if "font-size" in b) and any("font-size--base" in b for b in bodies))
check("WooCommerce notices are 16px", bool(re.search(r"\.woocommerce-message, \.woocommerce-info, \.woocommerce-error[^{]*\{[^}]*font-size:\s*var\(--wp--preset--font-size--base\)", nocomment)))
check("theme.json captions are 16px", json.load(open(os.path.join(T, "theme.json")))["styles"]["elements"]["caption"]["typography"]["fontSize"].endswith("font-size--base)"))

# Owned markup: a preset class below base only on an uppercase element.
OWNED = ["parts/header.html", "parts/footer.html", "templates/front-page.html", "templates/archive-product.html",
         "templates/taxonomy-product_cat.html", "templates/product-search-results.html", "templates/404.html",
         "templates/page-cart.html", "templates/page-my-account.html", "templates/page-checkout.html", "templates/page.html"]
OWNED += ["patterns/" + f for f in sorted(os.listdir(os.path.join(T, "patterns")))]
bad = []
for f in OWNED:
    text = read(f)
    for m in re.finditer(r"<(\w+)[^>]*class=\"[^\"]*has-(xs|sm)-font-size[^\"]*\"[^>]*>([^<]*)", text):
        tag = m.group(0)
        if "uppercase" in tag or (m.group(3).strip() and m.group(3).strip() == m.group(3).strip().upper()):
            continue
        bad.append(f"{f}: {m.group(3).strip()[:30]!r}")
    for m in re.finditer(r'"fontSize":"(xs|sm)"', text):
        pass
    if re.search(r'wp:woocommerce/product-button \{[^}]*"fontSize":"sm"', text):
        bad.append(f"{f}: card Add to cart at sm")
check("owned templates, parts and patterns print no sub-16px text that is not an uppercase label"
      + (f" — {bad}" if bad else ""), not bad)

print("── 44px (decision 2) ──")
def has_tap(sel):
    return any("tap-target" in b for s, b in rules if sel in s)
for sel in [".woocommerce-breadcrumb a", ".woocommerce-review-link", ".woocommerce-orders-table__cell-order-number a",
            ".wc-block-cart-item__remove-link", ".restore-item", ".woocommerce-remove-coupon", ".fd-cart-back a",
            ".fd-more a", ".wp-block-list.is-style-plain a", ".fd-rsum__clear"]:
    check(f"{sel} has a tap-target min-height", has_tap(sel))
check("footer lists are two columns on a phone", bool(re.search(r"\.fd-footer \.wp-block-list\.is-style-plain\s*\{[^}]*grid-template-columns:\s*repeat\(2", nocomment)))
check("is-style-plain is defined by the theme (no bullets, links inherit the footer's paper)",
      any("list-style: none" in b for b in rule(r"^\.wp-block-list\.is-style-plain$")) and any("color: inherit" in b for b in rule(r"^\.wp-block-list\.is-style-plain a$")))
footer = read("parts", "footer.html")
check("the footer carries .fd-footer", 'className":"fd-footer"' in footer and 'class="wp-block-group fd-footer' in footer)
check("the footer's Shop column links Combos", "/product-category/combos/" in footer)
check("account nav is a scrolling chip row under 900px, the current chip filled",
      bool(re.search(r"MyAccount-navigation ul\s*\{[^}]*display:\s*flex;[^}]*overflow-x:\s*auto", nocomment))
      and bool(re.search(r"li\.is-active a\s*\{[^}]*background:\s*var\(--wp--preset--color--char\)", nocomment)))

print("── the shop's filters on a phone (BROWSE-2) ──")
shop = read("templates", "archive-product.html")
check("the category template IS still the shop template", shop == read("templates", "taxonomy-product_cat.html"))
check("the filters are printed closed", '<details class="wp-block-details fd-filters">' in shop and '"showContent":true' not in shop)
check("the summary has the count slot", '<summary>Filter<span class="fd-filters__count"></span></summary>' in shop)
check("an inline script opens them from 782px", "matchMedia('(min-width:782px)')" in shop and "d.open=true" in shop)
check("a closed filter shows its summary at every width (the no-script desktop)",
      bool(re.search(r"\.fd-filters:not\(\[open\]\) > summary\s*\{[^}]*display:\s*flex", nocomment)))
check("an empty count prints nothing", bool(rule(r"\.fd-filters__count:empty")))
check("pagination says Previous / Next, not Previous Page / Next Page",
      '"label":"Previous"' in shop and '"label":"Next"' in shop)
check("'Sort by' is a <label>", '<label class="fd-sort__label" for="fd-orderby">Sort by</label>' in shop)

print("── home copy agrees with itself (BROWSE-6, BROWSE-11) ──")
hero, trust = read("patterns", "hero.php"), read("patterns", "trust-strip.php")
how, effort = read("patterns", "how-it-works.php"), read("patterns", "shop-by-effort.php")
check("the hero says 9–12 months, like the trust strip and the footer", "9–12 month shelf life" in hero and "12-month" not in hero)
check("the hero does not promise 'no cooking' over a range that needs a stove", "no cooking" not in hero.lower() and "Most need only hot water" in hero)
check("the trust strip says the same words", "9–12 month shelf life" in trust)
check("how-it-works names the range it describes", "Express in three steps" in how and "Three steps, six minutes" not in how)
attrs = read("inc", "product-attributes.php")
for label in ("Just add hot water", "Requires cooking", "Stir with drinking water"):
    check(f"the effort kicker '{label}' is the filter's own term", f"'{label}'" in attrs and f">{label}</p>" in effort)
check("the stove kicker no longer claims a minute count", "Five minutes on the stove" not in effort and "A few minutes on the stove" in effort)

print("── header, cart, account, 404 ──")
header = read("parts", "header.html")
check("the strip is 16px and splits into a first fact and the rest", 'fd-strip has-base-font-size' in header and 'class="fd-strip__fact"' in header and 'class="fd-strip__more"' in header)
check("below 782px only the first fact shows", bool(re.search(r"@media \(max-width: 781px\)\s*\{\s*\.fd-strip__more\s*\{\s*display:\s*none", nocomment)))
for f in OWNED:
    check(f"{f}: 'Free delivery', never 'free shipping' (decision 6)", "free shipping" not in re.sub(r"<!--.*?-->", "", read(f), flags=re.S).lower() or f.startswith("patterns/free-shipping"))
check("the cart count hides at zero", '"productCountVisibility":"greater_than_zero"' in header)
check("the phone menu has All meals and Build a box (overlay-only)", '"label":"All meals","url":"/shop/","className":"fd-nav-extra"' in header and '"url":"/build-a-box/","className":"fd-nav-extra"' in header)
# Below 1024px there is no menu row and no menu button: the category row under
# the search replaces both (client, 9 Nov 2026); the row needs 1024px to fit.
check("below 1024px the menu is hidden whole and the category row takes its place",
      bool(re.search(r"@media \(max-width: 1023px\)\s*\{[^@]*?\.fd-header > \.wp-block-navigation\s*\{\s*display:\s*none", nocomment))
      and bool(re.search(r"@media \(min-width: 1024px\)\s*\{[^@]*?\.fd-catrow\s*\{\s*display:\s*none", nocomment)))
band = read("patterns", "free-shipping-progress.php")
check("the delivery band is drawn by the shortcode's wrapper, not an always-on group", "leaf-wash" not in band and "fd-ship-band" in band
      and bool(re.search(r"\.fd-shipping-progress\s*\{[^}]*background:\s*var\(--wp--preset--color--leaf-wash\)", nocomment)))
check("…and the wrapper honours [hidden]", bool(rule(r"^\.fd-shipping-progress\[hidden\]$")))
check("the order-total rule is drawn on the row", any("border-top" in b for b in rule(r"tr\.order-total$")))
acct = read("templates", "page-my-account.html")
check("the account sits on the wide rail", '"align":"wide","layout":{"type":"default"}' in acct and acct.index("alignwide") < acct.index("my_account"))
four = read("templates", "404.html")
check("the 404 says what happened and offers a product search", "We couldn’t find that page" in four and '"query":{"post_type":"product"}' in four)
check("a disabled Add box to cart is outlined, not half-transparent", not any("opacity: .5" in b for b in rule(r"fd-box__add"))
      and any("opacity: 1" in b for b in rule(r"\.fd-box__add:disabled")))
check("prices are not underlined", any("text-decoration: none" in b for s, b in rules if "ins" in s and "product-price" in s and ":is(" in s))
check("the sticky bar pads the page while it shows", bool(re.search(r"body:has\(\.fd-sticky-atc:not\(\[hidden\]\)\)\s*\{[^}]*padding-bottom", nocomment)))

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
