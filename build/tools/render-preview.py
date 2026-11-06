#!/usr/bin/env python3
"""
Render the Foodify block theme to static HTML for review.

WHY IT WORKS THIS WAY
---------------------
The obvious way to show a client the new storefront is to hand-write a mockup.
That mockup then drifts from the theme the moment either changes, and you end up
maintaining two descriptions of one design — the failure this project keeps
finding in other forms.

So this renders the REAL artefacts: theme.json for tokens, templates/*.html and
parts/*.html for structure, patterns/*.php for content. Change the theme and the
preview changes with it; there is nothing to keep in sync.

WHAT IT IS NOT
--------------
It approximates WordPress's block renderer. Good enough to judge layout, type,
colour, hierarchy and responsive behaviour. It is NOT WordPress: dynamic blocks
are replaced with fixtures, and WordPress's own layout CSS is reimplemented
here, not copied. Sign off structure and design on this; sign off behaviour on
staging.

Usage: python3 tools/render-preview.py [outfile]
"""
import json, os, re, sys, html, datetime

KIT   = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
THEME = os.path.join(KIT, "theme", "foodify")
OUT   = sys.argv[1] if len(sys.argv) > 1 else os.path.join(KIT, "preview", "storefront.html")

# ── tokens ────────────────────────────────────────────────────────────────────
tj = json.load(open(os.path.join(THEME, "theme.json")))
S  = tj["settings"]


def kebab(s):
    return re.sub(r"(?<=[a-z0-9])(?=[A-Z])", "-", s).lower()


def token_css():
    out = [":root{"]
    for c in S["color"]["palette"]:
        out.append(f"--wp--preset--color--{c['slug']}:{c['color']};")
    for f in S["typography"]["fontSizes"]:
        out.append(f"--wp--preset--font-size--{f['slug']}:{f['size']};")
    for fam in S["typography"]["fontFamilies"]:
        out.append(f"--wp--preset--font-family--{fam['slug']}:{fam['fontFamily']};")
    for sp in S["spacing"]["spacingSizes"]:
        out.append(f"--wp--preset--spacing--{sp['slug']}:{sp['size']};")

    def custom(node, prefix="--wp--custom"):
        for k, v in node.items():
            key = f"{prefix}--{kebab(k)}"
            if isinstance(v, dict):
                custom(v, key)
            else:
                out.append(f"{key}:{v};")
    custom(S.get("custom", {}))
    out.append("}")

    # WordPress emits a utility class per preset. Reimplemented, not copied.
    for c in S["color"]["palette"]:
        out.append(f".has-{c['slug']}-color{{color:var(--wp--preset--color--{c['slug']})}}")
        out.append(f".has-{c['slug']}-background-color{{background-color:var(--wp--preset--color--{c['slug']})}}")
        # border-color ONLY, as theme.json's preset classes emit it. The style
        # comes from the block's own inline border-*-style — a width with no
        # style draws nothing in WordPress, and the preview used to invent a
        # solid style here, which also drew 3px sides where only top and
        # bottom had a width.
        out.append(f".has-{c['slug']}-border-color{{border-color:var(--wp--preset--color--{c['slug']})}}")
    for f in S["typography"]["fontSizes"]:
        cls = re.sub(r"(\d)([a-z])", r"\1-\2", f["slug"])   # 4xl -> 4-xl, as WP does
        out.append(f".has-{cls}-font-size{{font-size:var(--wp--preset--font-size--{f['slug']})}}")
    for fam in S["typography"]["fontFamilies"]:
        out.append(f".has-{fam['slug']}-font-family{{font-family:var(--wp--preset--font-family--{fam['slug']})}}")
    return "\n".join(out)


def styles_css():
    """theme.json `styles` -> CSS, the subset the preview needs."""
    st = tj.get("styles", {})
    out = []
    body = []
    if "color" in st:
        if "background" in st["color"]: body.append(f"background:{st['color']['background']}")
        if "text" in st["color"]:       body.append(f"color:{st['color']['text']}")
    ty = st.get("typography", {})
    for k, prop in (("fontFamily", "font-family"), ("fontSize", "font-size"), ("lineHeight", "line-height")):
        if k in ty: body.append(f"{prop}:{ty[k]}")
    out.append("body{" + ";".join(body) + "}")
    # styles.spacing.blockGap -> the variable WordPress's flex layouts read for gap.
    gap = st.get("spacing", {}).get("blockGap", "1.5rem")
    out.append(f":root{{--wp--style--block-gap:{gap}}}")

    el = st.get("elements", {})
    for name, sel in (("heading", "h1,h2,h3,h4"), ("h1", "h1"), ("h2", "h2"), ("h3", "h3"), ("link", "a")):
        node = el.get(name, {})
        decl = []
        t = node.get("typography", {})
        for k, prop in (("fontFamily", "font-family"), ("fontSize", "font-size"), ("fontWeight", "font-weight"),
                        ("lineHeight", "line-height"), ("letterSpacing", "letter-spacing"),
                        ("textDecoration", "text-decoration"), ("textTransform", "text-transform")):
            if k in t: decl.append(f"{prop}:{t[k]}")
        if "color" in node and "text" in node["color"]:
            decl.append(f"color:{node['color']['text']}")
        if decl:
            out.append(sel + "{" + ";".join(decl) + "}")
    if "button" in el:
        b = el["button"]; decl = []
        if "color" in b:
            if "background" in b["color"]: decl.append(f"background:{b['color']['background']}")
            if "text" in b["color"]:       decl.append(f"color:{b['color']['text']}")
        if decl:
            out.append(".wp-element-button,.wp-block-button__link{" + ";".join(decl) + "}")
    return "\n".join(out)


# ── fixtures for dynamic blocks ───────────────────────────────────────────────
# (name, range, time, price, was, hue, rating, review count, servings, flags)
# Flags exercise every card state the wireframe names: bestseller, new (with no
# reviews yet — stars row hidden), sold out, Jain. The sale pair shows the SAVE
# floor: 225→185 is 17% and gets a badge; 225→195 is 13% and does not.
# ── FIXTURE-ONLY PLACEHOLDERS ────────────────────────────────────────────────
# Values the client has not supplied yet, invented HERE so the preview shows a
# finished page instead of four gaps. They exist in this file and nowhere else:
# tests/fixture-leak-test.py fails the build if any of them appears in the
# theme, the patterns, the templates or bootstrap.sh. That is not paranoia — a
# plausible-looking FSSAI number got into four templates once already, and an
# invented licence number on a live food site is a legal claim, not a mock-up.
#
# Each one is a QUESTION FOR NALIN, listed in docs/DESIGN-REVIEW-2026-09-15.md.
FIXTURE_FSSAI    = "10012345000001"   # licence: 14 digits, real format, invented number
FIXTURE_DELIVERY = "Same-day dispatch in Delhi NCR · 3–5 days pan-India"   # NOT yet promised
# "Complete the meal" is a CURATED pairing in WP-03, not "four more products".
# These indices into PRODUCTS stand in for the pairing Nalin has to choose:
# a chutney, a chai and two meals — never the product being viewed.
FIXTURE_PAIRINGS = [6, 7, 1, 5]

PRODUCTS = [
    ("Express Dal Fry",        "Express",       "6 MIN", 185, 225, "#D9822B", "4.7", 84, 2, {"bestseller", "jain"}),
    ("Idli Sambhar",           "Express",       "6 MIN", 195, 225, "#E0A03C", "4.8", 62, 2, {"bestseller"}),
    ("Express Dal Khichdi",    "Express",       "6 MIN", 185, 0,   "#CE9126", "4.5", 41, 2, set()),
    ("Aloo ka Mazaa",          "Express",       "5 MIN", 100, 0,   "#C98A34", "4.4", 28, 1, {"soldout"}),
    ("Super Millet Idli",      "Express",       "6 MIN", 210, 0,   "#B98D3E", "0",   0,  2, {"new"}),   # nothing on the review page may outrun the claim
    ("Pav Bhaji",              "Express",       "6 MIN", 185, 0,   "#C2571F", "4.7", 57, 2, set()),
    ("Coconut Red Chutney",    "Flavors",       "1 MIN", 240, 0,   "#4E8B66", "4.5", 19, 4, {"jain"}),
    ("Masala Chai",            "Hot & Fresh",   "3 MIN", 375, 0,   "#A6603A", "4.8", 71, 1, set()),
    # Appended, never inserted: FIXTURE_PAIRINGS indexes this list. These four
    # are SKUs observed on the live site (planning/SCOPE.md §2); their range,
    # price and rating are fixture values like every other row here.
    ("Dal Makhni",             "Express",       "6 MIN", 210, 0,   "#8E3B22", "4.6", 33, 2, set()),
    ("Palak Gravy",            "Express",       "6 MIN", 195, 0,   "#5E7D3A", "4.3", 12, 2, set()),
    ("Sprouted Millet & Beetroot Cheela", "Hot & Fresh", "6 MIN", 240, 0, "#9C3550", "4.5", 9, 2, {"new"}),
    ("Lemon Masala Tea",       "Hot & Fresh",   "3 MIN", 325, 0,   "#C9A23A", "4.6", 24, 1, set()),
]

# Per-product detail the click-through needs and the tuple does not carry: the
# URL slug, the Dietary filter terms, and the product page's own copy. FIXTURE
# like the rows above — Nalin's per-SKU content (DESIGN-REVIEW §questions)
# replaces every line. A product with no nutrition panel here shows none, which
# is what the theme does for a product without one: it never invents a panel.
PRODUCT_INFO = {
    "Express Dal Fry": dict(
        slug="express-dal-fry", diet=["Jain", "High protein"], net="80 g", cooked="260 g",
        taste="Savoury, Mildly spicy, Tangy",
        # The live SKU is "Dal Fry (Jain)", and this page's own FAQ says so —
        # so the ingredient list may not contain onion, garlic or ginger. It
        # did, in every earlier render: a contradiction a client reads in
        # seconds and an allergen-adjacent claim besides.
        ingredients="Split yellow lentils (moong, toor), tomato, ghee, cumin, turmeric, coriander, asafoetida, salt, red chilli.",
        excerpt="Yellow moong and toor, tempered with cumin, tomato and a little ghee. Dried slowly so the tempering survives.",
        about=None),   # None = PDP_ABOUT, the copy already reviewed
    "Idli Sambhar": dict(
        slug="idli-sambhar", diet=["Gluten free"], net="90 g", cooked="300 g", taste="Savoury, Tangy",
        ingredients="Rice, black gram, toor dal, tamarind, drumstick, tomato, sambhar spices, curry leaves, salt.",
        excerpt="Soft rice idli and a tamarind sambhar, both from one pack and one kettle.",
        about="<p>Breakfast in Chennai, wherever you are: steamed rice-and-urad idli, dried whole, with a tamarind sambhar that brings back its own drumstick and curry leaf.</p>"),
    "Express Dal Khichdi": dict(
        slug="express-dal-khichdi", diet=["Gluten free"], net="80 g", cooked="270 g", taste="Mild, Comforting",
        ingredients="Rice, split moong dal, ghee, cumin, turmeric, asafoetida, salt.",
        excerpt="Rice and moong, soft and lightly spiced. The meal for a long day or a tired stomach.",
        about="<p>Rice and yellow moong cooked soft with a little ghee and cumin — the thing you are made when you are unwell, and the thing you want when you are not.</p>"),
    "Aloo ka Mazaa": dict(
        slug="aloo-ka-mazaa", diet=[], net="60 g", cooked="200 g", taste="Spicy, Savoury",
        ingredients="Potato, tomato, onion, oil, cumin, coriander, red chilli, salt.",
        excerpt="A dry, spiced potato sabzi for parathas, puris or a slice of bread.",
        about="<p>A dry potato sabzi with tomato and cumin. Good with parathas, better on a train.</p>"),
    "Super Millet Idli": dict(
        slug="super-millet-idli", diet=["Millet based", "Gluten free"], net="90 g", cooked="290 g", taste="Savoury, Earthy",
        ingredients="Foxtail millet, little millet, black gram, salt.",
        excerpt="Idli made from foxtail and little millet — the same kettle, a slower-burning breakfast.",
        about="<p>The idli, made with foxtail and little millet in place of rice. Earthier, more filling, and the same six minutes.</p>"),
    "Pav Bhaji": dict(
        slug="pav-bhaji", diet=[], net="85 g", cooked="280 g", taste="Spicy, Buttery, Tangy",
        ingredients="Potato, tomato, peas, cauliflower, capsicum, onion, butter, pav bhaji masala, salt.",
        excerpt="Mumbai's street bhaji, mashed, buttered and spiced. Bring your own pav.",
        about="<p>The bhaji from a Juhu handcart: potato, peas, cauliflower and capsicum, mashed into butter and pav bhaji masala.</p>"),
    "Coconut Red Chutney": dict(
        slug="coconut-red-chutney", diet=["Vegan", "Jain", "Gluten free"], net="50 g", cooked="", taste="Tangy, Mildly spicy",
        ingredients="Coconut, roasted chana dal, dry red chilli, tamarind, salt, curry leaves.",
        excerpt="Coconut, roasted chana and red chilli. Stir with cold water — no grinding stone.",
        about="<p>Fresh coconut chutney without the mixer: coconut, roasted chana and red chilli, ground and dried. Stir with drinking water and it is ready.</p>"),
    "Masala Chai": dict(
        slug="masala-chai", diet=["Gluten free"], net="250 g", cooked="", taste="Spiced, Sweet",
        ingredients="Assam tea, cardamom, ginger, cinnamon, clove, black pepper.",
        excerpt="Assam tea with cardamom, ginger and clove. Boil, simmer, strain.",
        about="<p>Strong Assam leaf with whole cardamom, dried ginger and clove — the chai a dhaba makes, for the stove at home.</p>"),
    "Dal Makhni": dict(
        slug="dal-makhni", diet=["High protein", "Gluten free"], net="85 g", cooked="280 g", taste="Rich, Creamy, Smoky",
        ingredients="Whole black gram, kidney beans, butter, cream, tomato, garam masala, salt.",
        excerpt="Black dal slow-cooked with butter and cream. Six minutes for something that took a night.",
        about="<p>Whole urad and rajma, slow-cooked overnight with butter, cream and tomato, then dried. It takes six minutes to bring back what took a night.</p>"),
    "Palak Gravy": dict(
        slug="palak-gravy", diet=["Gluten free"], net="70 g", cooked="240 g", taste="Savoury, Earthy",
        ingredients="Spinach, onion, tomato, garlic, ginger, cumin, garam masala, salt.",
        excerpt="A spinach gravy to pour over paneer, potatoes or rice.",
        about="<p>A smooth spinach gravy with garlic and cumin. Add paneer, potato or corn, or eat it with rice as it is.</p>"),
    "Sprouted Millet & Beetroot Cheela": dict(
        slug="sprouted-millet-beetroot-cheela", diet=["Millet based", "Vegan"], net="150 g", cooked="", taste="Savoury, Earthy",
        ingredients="Sprouted finger millet, beetroot, green gram, coriander, cumin, salt.",
        excerpt="A batter mix of sprouted ragi and beetroot. Whisk with water, pour on a hot pan.",
        about="<p>Sprouted ragi, green gram and beetroot, milled into a batter mix. Whisk with water and make thin cheelas on a hot pan, four at a time.</p>"),
    "Lemon Masala Tea": dict(
        slug="lemon-masala-tea", diet=["Vegan", "Gluten free"], net="200 g", cooked="", taste="Citrus, Spiced",
        ingredients="Assam tea, dried lemon peel, black salt, black pepper, mint.",
        excerpt="Black tea with lemon peel, black salt and mint. Brewed, not boiled with milk.",
        about="<p>The roadside lemon chai: black tea, lemon peel, black salt and a little mint. Made with water, no milk.</p>"),
}

# The four ranges, as product categories. (screen id, the nav/href slug, the
# range name in the PRODUCTS tuple, title, intro). The intros are fixture copy.
CATEGORIES = [
    ("cat-express", "express", "Express", "Foodify Express",
     "Add hot water, wait six minutes, eat. The Express range is built for trains, hostels and hotel "
     "kettles — home-style meals that need no cooking at all, with a nine to twelve month shelf life "
     "and no preservatives."),
    ("cat-hot-and-fresh", "hot-and-fresh", "Hot & Fresh", "Foodify Hot & Fresh",
     "A pan, a few minutes, and food that tastes cooked because it was. Batters, teas and mixes for a "
     "stove at home."),
    ("cat-flavors", "flavors", "Flavors", "Foodify Flavors",
     "Chutneys that stir up with cold drinking water. No grinding stone, no mixer, no stove."),
]


# COMBOS — packs of several meals. Same tuple shape as PRODUCTS plus the two
# fields a combo has: what is inside, and what those items cost bought one by
# one. Both are FIXTURE values (see the placeholder block above): the packs,
# their contents and their prices are Nalin's to set, and the saving the page
# prints is computed from the separate total rather than invented.
COMBOS = [
    ("Trial Box · 3 meals",     "Combos", "6 MIN", 525,  615,  "#D9822B", "4.8", 46, 3,  set(),
     "Express Dal Fry\nIdli Sambhar\nExpress Dal Khichdi", 615),
    ("Week Box · 6 meals",      "Combos", "6 MIN", 999,  1170, "#CE9126", "4.7", 38, 6,  {"bestseller"},
     "Express Dal Fry × 2\nIdli Sambhar × 2\nExpress Dal Khichdi\nPav Bhaji", 1170),
    ("Hostel Box · 9 meals",    "Combos", "6 MIN", 1499, 1755, "#C2571F", "4.6", 21, 9,  set(),
     "Express Dal Fry × 3\nIdli Sambhar × 2\nExpress Dal Khichdi × 2\nPav Bhaji\nAloo ka Mazaa", 1755),
    ("Chai & Chutney Box",      "Combos", "3 MIN", 540,  615,  "#A6603A", "4.9", 17, 4,  {"jain"},
     "Masala Chai × 2\nCoconut Red Chutney × 2", 615),
]

def combo_of(p):
    """The two combo fields of a fixture row, or None for a single pack."""
    return (p[10], float(p[11])) if len(p) > 11 else None


def badge_state(p):
    f = p[9]
    return {"in_stock": "soldout" not in f, "bestseller": "bestseller" in f,
            "new": "new" in f, "dietary": ["jain"] if "jain" in f else []}
BOWL = ('<div class="fx-bowl" style="--h:{hue}"><span class="fx-time">{time}</span></div>')


def stars(r):
    full = int(float(r))
    return '<span class="fx-stars">' + "★" * full + "☆" * (5 - full) + f'</span> <span class="fx-rc">{r}</span>'


# The product the loop is currently rendering, or None outside a loop. The
# inner blocks of a Product Query (title, image, price, rating, button) read
# it, exactly as WordPress sets up post data per item.
CUR = None
LOOP_N = 8
LOOP_PICK = None
LOOP_ROWS = None
SCREEN_ID = ""

# What inc/product-display.php prepends to a product title inside a loop.
CHIP = {
    "Express":     ("fd-chip--hot",  "Hot water"),
    "Hot & Fresh": ("fd-chip--cook", "Cooking"),
    "Flavors":     ("",              "Drinking water"),
}


def prep_chip(p):
    mod, label = CHIP[p[1]]
    return f'<span class="fd-chip {mod}">{label} · {p[2].split()[0]} min</span>'


def price_html(p, size_cls=""):
    """WooCommerce's price markup: <del> old, <ins> current, when on sale — plus
    the theme's SAVE badge, decided by the theme's own floor."""
    _n, _r, _t, price, was, *_ = p
    amt = lambda v: f'<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">₹</span>{v}</bdi></span>'
    inner = f'<del aria-hidden="true">{amt(was)}</del> <ins>{amt(price)}</ins>' if was else amt(price)
    combo = combo_of(p)
    if combo:
        save = php("echo foodify_combo_saving_badge((float)$a[0], (float)$a[1])", combo[1], price)
    else:
        save = php("echo foodify_save_badge((float)$a[0], (float)$a[1])", was, price) if was else ""
    if save:
        inner += f' <span class="fd-save">{html.escape(save)}</span>'
    return f'<div class="wc-block-components-product-price wp-block-woocommerce-product-price {size_cls}">{inner}</div>'


def card_yield(p):
    combo = combo_of(p)
    if combo:
        # The theme swaps this line for a combo through foodify_card_yield_line;
        # the preview calls the same function the filter calls.
        line = php("echo foodify_combo_card_line(foodify_combo_items($a[0]))", combo[0])
    else:
        line = php("echo foodify_card_yield($a[0])", {"servings": str(p[8]), "prep_minutes": p[2].split()[0]})
    return f'<p class="fd-yield">{html.escape(line)}</p>' if line else ""


# The product page's fixture data, in the shape the theme's own functions take.
# Allergens and the FSSAI licence are deliberately EMPTY: the page must show
# "Not provided" for both, and the preview showing a plausible value is how
# the placeholder licence got into four templates in the first place.
COMBO_ABOUT = ("<p>Three of the meals people reorder most, in one box: dal fry, idli sambhar and dal khichdi. "
               "Each pack inside is the one sold on its own — the same 80 g, the same six minutes, the same shelf "
               "life. The box is the convenience.</p>"
               "<p>Bought one by one these three come to ₹615. The box is ₹525.</p>")

# A combo declares for the BOX: the individual packs carry their own labels, and
# the buyer is told so rather than shown one pack's ingredients for three.
COMBO_VALUES = {
    "ingredients": "Each pack carries its own ingredient list, printed on the pack and shown on that product page.",
    "allergens": "", "net_quantity": "3 packs · 240 g", "servings": "6", "cooked_weight": "780 g",
    "diet": "Vegetarian", "storage": "Cool, dry place.", "mrp": "₹615.00 (incl. all taxes)",
    "best_before": "14 Aug 2027", "shelf_life": "12 months", "origin": "India", "fssai": "",
    "marketed_by": "AVAC Ventures, Noida 201304", "care": "care@letsfoodify.com",
}
COMBO_FAQ = ("Q: Can I choose what goes in the box?\n"
             "A: Not yet. The three boxes are fixed; a build-your-own box is planned.\n"
             "Q: Do the packs expire together?\n"
             "A: Each pack has its own best-before date, printed on it. The earliest is shown above.\n"
             "Q: Is it cheaper than buying them separately?\n"
             "A: Yes — ₹90 cheaper on this box. The comparison is printed under the contents.")

PDP_VALUES = {
    "ingredients": "Split yellow lentils, onion, tomato, ghee, cumin, turmeric, coriander, ginger, garlic, salt, red chilli.",
    "allergens": "", "net_quantity": "80 g", "servings": "2", "cooked_weight": "260 g", "diet": "Vegetarian",
    "storage": "Cool, dry place. Use within 3 days of opening.", "prep_minutes": "6",
    "mrp": "₹225.00 (incl. all taxes)", "best_before": "14 Aug 2027", "shelf_life": "12 months",
    "origin": "India", "fssai": "", "marketed_by": "AVAC Ventures, Noida 201304", "care": "care@letsfoodify.com",
}
PDP_NUTRITION = {"energy": "312 kcal", "protein": "14 g", "carbs": "44 g", "sugars": "3 g", "fat": "8 g", "sodium": "620 mg"}
PDP_ABOUT = ("<p>The dal you would make on a Tuesday if you had the hour: yellow moong and toor, tempered with "
             "cumin, tomato and a little ghee, then dried slowly so the tempering survives.</p>"
             "<p>Built for the places a stove is not — a train berth, a hostel drawer, a hotel kettle, a desk at "
             "nine at night. One pack is dinner for two, or a very good lunch for one.</p>")
PDP_FAQ = ("Q: Does it need a stove?\nA: No. Boiling water from a kettle is enough.\n"
           "Q: How long does an opened pack keep?\nA: Three days, sealed, in a cool dry place.\n"
           "Q: Is it Jain?\nA: This one is — no onion, no garlic. Look for the Jain badge on other packs.")


# ── the catalogue, as the click-through sees it ──────────────────────────────
# What the screen being rendered is about. main() sets these per screen, the
# way WordPress sets up the queried object before a template runs.
PDP = None        # the product row a product screen shows
LISTING = None    # the rows a shop / category screen lists
CAT = None        # (title, intro, crumb label, screen id) of a listing screen
PAGE = None       # (title, content html) of an information page

PREP_TERM = {"Express": "Just add hot water", "Flavors": "Stir with drinking water",
             "Hot & Fresh": "Requires cooking"}


def slugify(s):
    return re.sub(r"[^a-z0-9]+", "-", s.lower().replace("&", "and")).strip("-")


def slug_of(p):
    return PRODUCT_INFO.get(p[0], {}).get("slug") or slugify(p[0])


def screen_of(p):
    return "p-" + slug_of(p)


def info_of(p):
    return PRODUCT_INFO.get(p[0], {})


def prep_terms(p):
    """The Prep method filter terms a product carries. A combo carries those of
    what is inside it, so filtering for 'hot water' finds the hot-water boxes."""
    combo = combo_of(p)
    if not combo:
        return [PREP_TERM[p[1]]]
    terms = []
    for line in combo[0].split("\n"):
        name = re.sub(r"\s*×\s*\d+\s*$", "", line).strip()
        row = next((r for r in PRODUCTS if r[0] == name), None)
        if row and PREP_TERM[row[1]] not in terms:
            terms.append(PREP_TERM[row[1]])
    return terms


def diet_terms(p):
    if combo_of(p):
        return ["Jain"] if "jain" in p[9] else []
    return list(info_of(p).get("diet", []))


def by_popularity(rows):
    # WooCommerce's popularity is total sales; review count stands in for it.
    return sorted(rows, key=lambda r: -r[7])


def catalog_json():
    """What the page script needs to build a cart line: one entry per product."""
    out = {}
    for p in PRODUCTS + COMBOS:
        out[slug_of(p)] = {"name": p[0], "price": p[3], "was": p[4], "hue": p[5],
                           "stock": "soldout" not in p[9], "screen": screen_of(p),
                           "combo": bool(combo_of(p))}
    return out


def loop_card(inner_markup, p, i):
    """One <li> of a Product Query loop — WordPress's post-template item."""
    global CUR
    CUR = p
    body = render(inner_markup, 1)
    CUR = None
    # WooCommerce adds the stock status to the loop item's classes; the theme
    # greys a sold-out card on it, so the fixture has to carry it too.
    stock = "outofstock" if "soldout" in p[9] else "instock"
    # The data-* attributes are the MOCK's: they let the page script filter and
    # sort the grid the way WooCommerce does with a fresh query on the site.
    data = (f' data-slug="{slug_of(p)}" data-price="{p[3]}" data-rating="{p[6]}" data-pop="{p[7]}"'
            f' data-new="{1 if "new" in p[9] else 0}" data-order="{i}"'
            f' data-prep="{html.escape("|".join(prep_terms(p)))}" data-diet="{html.escape("|".join(diet_terms(p)))}"')
    return f'<li class="wp-block-post post-{100 + i} product type-product status-publish {stock}"{data}>{body}</li>'


REVIEWS = [
    ("Carried six Express packs to Leh. Hotel kettle, six minutes, actual dal chawal at 11,000 feet.", "Rohit M.", 5),
    ("My mother is 78 and cooks less now. The Jain dal fry means she eats a proper lunch without standing at the stove.", "Anjali S.", 5),
    ("Sceptical about dried chutney. The coconut one is genuinely close to what my grandmother grinds.", "Deepa R.", 4),
]

# Product-page reviews, by how the product is made — so the chai page is not
# praised for being "actually dal". Fixture text, like everything on this list.
PDP_REVIEWS = {
    "Express Dal Fry": [("Took it to site for a week. Six minutes and it is actually dal, not soup.", "Rakesh M."),
                        ("My mother approved, which I did not expect.", "Sneha T.")],
    "Just add hot water": [("A kettle and six minutes, and it tastes like somebody cooked.", "Karan V."),
                           ("Packed four for a hostel week. Gone in three days.", "Ishita B.")],
    "Stir with drinking water": [("Stirred it up at my desk and it tasted fresh.", "Priya K."),
                                 ("Goes with every idli and dosa in the house.", "Lakshmi N.")],
    "Requires cooking": [("Easy on a weekday morning, and the kids eat it.", "Meera J."),
                         ("Proper flavour. Worth the few minutes at the stove.", "Vikram S.")],
    "combo": [("The box saved me ordering four times a month.", "Arjun P."),
              ("Good way to try the range before picking favourites.", "Nidhi G.")],
}


def shortcode(code):
    if "google_reviews" in code:
        cards = "".join(
            f'<figure class="fd-review"><blockquote>{html.escape(q)}</blockquote>'
            f'<figcaption><span class="fd-stars">{"★"*s}{"☆"*(5-s)}</span> · {html.escape(who)} · <span class="fd-verified">Google review</span></figcaption></figure>'
            for q, who, s in REVIEWS)
        return f'<div class="fd-reviews">{cards}</div>'
    if "free_shipping_progress" in code:
        # inc/shortcodes.php's markup. The page script fills the message and
        # the bar from the cart, with the theme's own two sentences.
        return ('<div class="fd-shipping-progress" data-foodify-shipping-progress>'
                '<p class="fd-ship"></p>'
                '<div class="fd-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" '
                'aria-valuemax="100" aria-label="Progress toward free shipping">'
                '<i style="width:0%"></i></div></div>')
    return f'<div class="fx-note">shortcode: {html.escape(code)}</div>'


def pdp_values(p):
    """The product page's declarations, in the shape foodify_spec_model() takes."""
    combo = combo_of(p)
    if combo:
        items = [l for l in combo[0].split("\n") if l.strip()]
        packs = sum(int(m.group(1)) if (m := re.search(r"×\s*(\d+)\s*$", l)) else 1 for l in items)
        return dict(COMBO_VALUES, net_quantity=f"{packs} packs", servings=str(p[8]),
                    cooked_weight="", mrp=f"₹{int(combo[1]):,}.00 (incl. all taxes)")
    info = info_of(p)
    v = dict(PDP_VALUES)
    v.update(ingredients=info["ingredients"], net_quantity=info["net"], servings=str(p[8]),
             cooked_weight=info["cooked"], prep_minutes=p[2].split()[0],
             mrp=f"₹{p[4] or p[3]}.00 (incl. all taxes)")
    if p[0] != "Express Dal Fry":
        v["storage"] = "Cool, dry place. Keep the pouch sealed."
    return v


def pdp_faq(p):
    if p[0] == "Express Dal Fry":
        return PDP_FAQ
    combo = combo_of(p)
    if combo:
        saving = int(combo[1] - p[3])
        return ("Q: Can I choose what goes in the box?\n"
                "A: Not yet. The boxes are fixed; a build-your-own box is planned.\n"
                "Q: Do the packs expire together?\n"
                "A: Each pack has its own best-before date, printed on it. The earliest is shown above.\n"
                "Q: Is it cheaper than buying them separately?\n"
                f"A: Yes — ₹{saving} cheaper on this box. The comparison is printed under the contents.")
    term = PREP_TERM[p[1]]
    faq = {
        "Just add hot water": "Q: Does it need a stove?\nA: No. Boiling water from a kettle is enough.\n"
                              "Q: How long does an opened pack keep?\nA: Three days, sealed, in a cool dry place.",
        "Stir with drinking water": "Q: Does it need a mixer?\nA: No. Stir it with drinking water and it is ready.\n"
                                    "Q: How long does it keep once made?\nA: A day in the fridge, like any fresh chutney.",
        "Requires cooking": "Q: What do I need?\nA: A pan and a stove. The steps and the time are above.\n"
                            "Q: How long does an opened pack keep?\nA: Sealed, until the date printed on the pack.",
    }[term]
    if "jain" in p[9]:
        faq += "\nQ: Is it Jain?\nA: Yes — no onion, no garlic, no root vegetables."
    return faq


def pdp_about(p):
    combo = combo_of(p)
    if combo:
        lines = [re.sub(r"\s*×\s*(\d+)\s*$", r" (×\1)", l.strip()) for l in combo[0].split("\n") if l.strip()]
        return (f"<p>{', '.join(lines[:-1])} and {lines[-1]}, in one box. Each pack inside is the one sold on its "
                "own — the same weight, the same time, the same shelf life. The box is the convenience.</p>"
                f"<p>Bought one by one these come to ₹{int(combo[1]):,}. The box is ₹{p[3]:,}.</p>")
    return info_of(p).get("about") or PDP_ABOUT


def pdp_excerpt(p):
    combo = combo_of(p)
    if combo:
        n = php("echo foodify_combo_meal_count(foodify_combo_items($a[0]))", combo[0])
        return f"{n} packs from the range in one box, for less than buying them one at a time."
    return info_of(p).get("excerpt", "")


def crumb_for(p):
    if combo_of(p):
        return ("combos", "Combos")
    cat = next(c for c in CATEGORIES if c[2] == p[1])
    return (cat[0], cat[2])


# WooCommerce and core dynamic blocks -> fixture markup.
def dynamic(name, attrs, inner):
    a = attrs or {}
    if name == "site-title":
        return ('<p class="fx-logo has-display-font-family"><a href="/" data-s="home">'
                'lets<span>foodify</span></a></p>')
    if name == "post-title":
        lvl = a.get("level", 2)
        if CUR and a.get("__woocommerceNamespace"):
            return (f'<h{lvl} class="wp-block-post-title {cls_for(a)}"><a href="/product/{slug_of(CUR)}/" '
                    f'data-s="{screen_of(CUR)}">{html.escape(CUR[0])}</a></h{lvl}>' + card_yield(CUR))
        # The page's own title. It always said "Express Dal Fry", so the cart
        # screen was headed with a product name — a fixture artefact that reads
        # as a bug in a client review.
        titles = {"cart": "Cart", "checkout": "Checkout", "order": "Order received", "account": "My account",
                  "signin": "My account", "notfound": "Page not found"}
        if PDP:
            title = PDP[0]
        elif PAGE:
            title = PAGE[0]
        else:
            title = titles.get(SCREEN_ID, "")
        return f'<h{lvl} class="fx-posttitle {cls_for(a)}">{html.escape(title)}</h{lvl}>'
    if name == "query-title":
        return f'<h1 class="{cls_for(a)}">{html.escape(CAT[0])}</h1>'
    if name == "term-description":
        return '<p class="' + cls_for(a) + f'">{html.escape(CAT[1])}</p>'
    if name == "post-excerpt":
        return '<p class="' + cls_for(a) + f'">{html.escape(pdp_excerpt(PDP))}</p>'
    if name == "post-content":
        return PAGE[1] if PAGE else '<p>Page content renders here.</p>'
    if name == "woocommerce/mini-cart":
        # WooCommerce's Mini-Cart button markup: amount, then icon + count badge.
        # The theme reorders them with CSS; the classes are Woo's, not fixtures.
        # The figures are filled from the cart by the page script.
        return ('<div class="wc-block-mini-cart wp-block-woocommerce-mini-cart">'
                '<button class="wc-block-mini-cart__button" data-drawer aria-label="Cart">'
                '<span class="wc-block-mini-cart__amount">₹0</span>'
                '<span class="wc-block-mini-cart__quantity-badge">'
                '<svg class="wc-block-mini-cart__icon" viewBox="0 0 24 24" width="24" height="24" fill="none" '
                'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                '<path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 8H6.2"/>'
                '<circle cx="9.5" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/></svg>'
                '<span class="wc-block-mini-cart__badge">0</span></span></button></div>')
    if name == "woocommerce/customer-account":
        return ('<div class="wc-block-customer-account wp-block-woocommerce-customer-account">'
                '<a class="wc-block-customer-account__account-link" href="/my-account/" data-s="account" aria-label="Account">'
                '<svg class="wc-block-customer-account__account-icon" viewBox="0 0 24 24" fill="none" '
                'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">'
                '<circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/></svg></a></div>')
    if name == "woocommerce/product-image" and CUR:
        badges = php("echo foodify_render_badges(foodify_card_badges($a[0]))", badge_state(CUR))
        return (f'<div class="wc-block-components-product-image"><a href="/product/{slug_of(CUR)}/" data-s="{screen_of(CUR)}" tabindex="-1">'
                f'{BOWL.format(hue=CUR[5], time=CUR[2])}</a>{badges}</div>')
    if name == "woocommerce/product-button" and CUR:
        if "soldout" in CUR[9]:
            # Out of stock: WooCommerce links the button to the product page
            # with "Read more"; the theme relabels it and greys the card.
            return ('<div class="wp-block-button wc-block-components-product-button">'
                    f'<a href="/product/{slug_of(CUR)}/" data-s="{screen_of(CUR)}" class="wp-block-button__link wp-element-button">Sold out</a></div>')
        return ('<div class="wp-block-button wc-block-components-product-button">'
                f'<a href="?add-to-cart={slug_of(CUR)}" data-add="{slug_of(CUR)}" class="wp-block-button__link wp-element-button '
                'add_to_cart_button ajax_add_to_cart">Add to cart</a></div>')
    if name == "woocommerce/breadcrumbs":
        sid, label = crumb_for(PDP)
        short = PDP[0].split(" · ")[0]
        return ('<p class="fx-crumb ' + cls_for(a) + '"><a href="/" data-s="home">Home</a> / '
                f'<a href="#" data-s="{sid}">{html.escape(label)}</a> / {html.escape(short)}</p>')
    if name == "woocommerce/product-image-gallery":
        # There is no photography. Rather than a grey box, the placeholder states
        # the brief — four shots, in order, with what each one has to prove. That
        # is a deliverable the client can act on; "image goes here" is not.
        shots = [
            ("1", "Pack, front", "Straight on, label legible. This is the Merchant Center image."),
            ("2", "Prepared, in a bowl", "The actual portion, natural light, no styling props."),
            ("3", "Ingredients, laid out", "Proves it is food, not powder. Answers the real objection."),
            ("4", "In a hand or beside a mug", "Scale. 80 g means nothing without something next to it."),
        ]
        hue = PDP[5]
        thumbs = "".join(
            f'<div class="fx-shot">{BOWL.format(hue=hue, time="")}'
            f'<span class="fx-shot__n">{n}</span><strong>{t}</strong><em>{d}</em></div>'
            for n, t, d in shots)
        return (
            '<div class="fx-gallery">'
            + BOWL.format(hue=hue, time=PDP[2])
            + '<div class="fx-shotlist">' + thumbs + '</div>'
            + '<p class="fx-ph">No photography supplied yet. Four shots per product, in this '
              'order — shot 1 is what Google Shopping uses, so it is the one that cannot wait.</p>'
            + '</div>'
        )
    if name == "woocommerce/product-rating":
        row = CUR or PDP
        if row[7] == 0:
            return ""   # the theme hides the row at zero reviews (product-display.php)
        if CUR:
            return (f'<div class="wc-block-components-product-rating">{stars(CUR[6])} '
                    f'<span class="fd-rating-count">{CUR[7]} reviews</span></div>')
        return f'<div class="fx-rating">{stars(row[6])} <span class="fx-rc">{row[7]} reviews</span></div>'
    if name == "woocommerce/product-price":
        if CUR:
            return price_html(CUR, cls_for(a))
        # The product page: the price, then the yield strip the theme appends.
        # A combo answers that strip in meals and per-meal price — the theme
        # swaps it through foodify_pdp_yield_parts, so the preview calls the
        # same function rather than writing a second version of the rule.
        combo = combo_of(PDP)
        if combo:
            parts = json.loads(php(
                "echo json_encode(foodify_combo_yield_parts(foodify_combo_items($a[0]), (float)$a[1], $a[2]))",
                combo[0], PDP[3], f"Ready in {PDP[2].split()[0]} min"))
        else:
            parts = json.loads(php("echo json_encode(foodify_yield_parts($a[0]))", pdp_values(PDP)))
        strip = "".join(f"<span>{html.escape(x)}</span>" for x in parts)
        return price_html(PDP, "fx-price--lg " + cls_for(a)) + f'<p class="fd-yield-strip">{strip}</p>'
    if name == "woocommerce/product-stock-indicator":
        if "soldout" in PDP[9]:
            return '<p class="fx-stock stock out-of-stock">Out of stock</p>'
        return '<p class="fx-stock stock in-stock">In stock</p>'
    if name == "woocommerce/add-to-cart-form":
        # WooCommerce prints no form at all for an out-of-stock simple product.
        if "soldout" in PDP[9]:
            return ''
        # WooCommerce's form: a number input for quantity and the real button.
        # A <form class="cart"> so the theme's sticky bar can watch it here too.
        qid = "qty-" + slug_of(PDP)
        return ('<form class="cart fx-atc"><div class="quantity">'
                f'<label class="screen-reader-text" for="{qid}">Quantity</label>'
                f'<input type="number" id="{qid}" class="input-text qty text" value="1" min="1" step="1" inputmode="numeric"></div>'
                f'<button type="submit" data-add="{slug_of(PDP)}" class="single_add_to_cart_button wp-element-button fx-add fx-add--lg">'
                'Add to cart</button></form>')
    if name == "woocommerce/product-details":
        combo = combo_of(PDP)
        if combo:
            # The theme prints this panel on woocommerce_after_single_product_summary
            # at priority 5 — before the tabbed body at 6. Same order here.
            panel = php("echo foodify_combo_panel_html(foodify_combo_items($a[0]),"
                        "['price'=>(float)$a[1],'separate'=>(float)$a[2]])", combo[0], PDP[3], combo[1])
            method = prep_terms(PDP)
            body = php(
                "echo foodify_render_pdp_body(['about'=>$a[0],'taste'=>[],"
                "'groups'=>foodify_spec_model($a[1]),'nutrition'=>[],"
                "'steps'=>foodify_prep_steps($a[3],$a[4]),'faq'=>foodify_faq_pairs($a[2])])",
                pdp_about(PDP), pdp_values(PDP), pdp_faq(PDP),
                method[0] if len(method) == 1 else "", PDP[2].split()[0] + " minutes")
            return panel + body
        # The theme renders the tabbed body from inc/product-spec.php on
        # woocommerce_after_single_product_summary — BEFORE this block in the
        # page body. The preview calls the SAME renderer with fixture data.
        nutrition = PDP_NUTRITION if PDP[0] == "Express Dal Fry" else {}
        return php(
            "echo foodify_render_pdp_body(['about'=>$a[0],'taste'=>foodify_taste_chips($a[1]),"
            "'groups'=>foodify_spec_model($a[2]),'nutrition'=>foodify_nutrition_rows($a[3]),"
            "'steps'=>foodify_prep_steps($a[5],$a[6]),'faq'=>foodify_faq_pairs($a[4])])",
            pdp_about(PDP), info_of(PDP)["taste"], pdp_values(PDP), nutrition, pdp_faq(PDP),
            PREP_TERM[PDP[1]], PDP[2].split()[0] + " minutes")

    if name == "woocommerce/product-reviews":
        if PDP[7] == 0:
            return ('<div class="fd-reviews"><p class="woocommerce-noreviews">There are no reviews yet. '
                    'Reviews open to customers who have bought it.</p></div>')
        key = "combo" if combo_of(PDP) else (PDP[0] if PDP[0] in PDP_REVIEWS else PREP_TERM[PDP[1]])
        cards = "".join(
            f'<figure class="fd-review"><blockquote>{html.escape(q)}</blockquote>'
            f'<figcaption><span class="fd-stars">{"★"*5}</span> · {who} · '
            f'<span class="fd-verified">Verified purchase</span></figcaption></figure>'
            for q, who in PDP_REVIEWS[key])
        return f'<div class="fd-reviews">{cards}</div>'
    if name == "woocommerce/catalog-sorting":
        # WooCommerce's ordering form; option labels are the theme's shortened
        # set, and popularity is the default bootstrap.sh sets.
        opts = "".join(f'<option value="{v}"{" selected" if v == "popularity" else ""}>{o}</option>' for v, o in [
            ("menu_order", "Featured"), ("popularity", "Most popular"), ("rating", "Best rated"),
            ("date", "Newest"), ("price", "Price: low to high"), ("price-desc", "Price: high to low")])
        return ('<div class="wp-block-woocommerce-catalog-sorting"><form class="woocommerce-ordering" method="get">'
                f'<select name="orderby" class="orderby" aria-label="Shop order">{opts}</select></form></div>')
    if name == "woocommerce/product-results-count":
        n = len(LISTING)
        return ('<p class="woocommerce-result-count wp-block-woocommerce-product-results-count">'
                + ("Showing the single result" if n == 1 else f"Showing all {n} results") + '</p>')
    if name == "woocommerce/price-filter":
        lo = min(r[3] for r in LISTING)
        hi = max(r[3] for r in LISTING)
        lo, hi = (lo // 50) * 50, -(-hi // 50) * 50
        return ('<div class="wp-block-woocommerce-price-filter"><h3 class="wc-block-price-filter__title">Price</h3>'
                '<div class="wc-block-price-filter__range-input-wrapper"><div class="fx-range"><i></i></div></div>'
                '<div class="wc-block-price-filter__controls">'
                f'<label><span class="screen-reader-text">Lowest price</span>₹<input type="number" class="wc-block-price-filter__amount wc-block-price-filter__amount--min" value="{lo}" min="{lo}" max="{hi}" step="50" data-floor="{lo}" inputmode="numeric"></label>'
                f'<label><span class="screen-reader-text">Highest price</span>₹<input type="number" class="wc-block-price-filter__amount wc-block-price-filter__amount--max" value="{hi}" min="{lo}" max="{hi}" step="50" data-ceil="{hi}" inputmode="numeric"></label>'
                '</div></div>')
    if name == "woocommerce/attribute-filter":
        # Woo's checkbox-list markup. In WordPress this block hydrates client-side;
        # the server sends the same classes with the list rendered from the
        # attribute the theme resolves from `foodifyAttribute` at render time.
        # Counts are for THIS listing, and a term with nothing in it is left
        # out, as WooCommerce leaves it out.
        attr = a.get("foodifyAttribute", "")
        terms = {"prep": ["Just add hot water", "Stir with drinking water", "Requires cooking"],
                 "dietary": ["Vegan", "Gluten free", "Jain", "Millet based", "High protein"]}.get(attr, [])
        of = prep_terms if attr == "prep" else diet_terms
        h = a.get("heading", "Filter")
        lis = "".join(
            '<li class="wc-block-checkbox-list__item"><div class="wc-block-components-checkbox"><label>'
            f'<input type="checkbox" class="wc-block-components-checkbox__input" data-attr="{attr}" value="{html.escape(t)}">'
            f'<span class="wc-block-components-checkbox__label">{t} '
            f'<span class="wc-filter-element-label-list-count">({n})</span></span></label></div></li>'
            for t in terms if (n := sum(1 for r in LISTING if t in of(r))))
        if not lis:
            return ""
        return (f'<div class="wp-block-woocommerce-attribute-filter"><h3 class="wc-block-attribute-filter__title">{h}</h3>'
                f'<ul class="wc-block-checkbox-list">{lis}</ul></div>')
    if name == "woocommerce/classic-shortcode":
        sc = a.get("shortcode", "cart")
        if sc == "my_account":
            return my_account(SIGNED_IN)
        if sc == "checkout" and SCREEN_ID == "order":
            return order_received()
        return cart_or_checkout(sc)
    return inner or ""


def cls_for(a):
    """The classes WordPress puts on a dynamic block, from its attributes.

    align and className were missing, so a block that carries {"align":"wide"}
    in the template rendered at content width in the preview and on the wide
    rail in WordPress — the preview showing a misalignment the theme did not
    have, and (worse) hiding the one it did.
    """
    out = []
    if a.get("align"):
        out.append("align" + a["align"])
    if a.get("className"):
        out.append(a["className"])
    if "fontSize" in a:
        out.append("has-" + re.sub(r"(\d)([a-z])", r"\1-\2", a["fontSize"]) + "-font-size")
    if "textColor" in a:
        out.append(f"has-{a['textColor']}-color has-text-color")
    if "fontFamily" in a:
        out.append(f"has-{a['fontFamily']}-font-family")
    return " ".join(out)


INDIAN_STATES = ["Andhra Pradesh", "Bihar", "Delhi", "Goa", "Gujarat", "Haryana", "Karnataka", "Kerala",
                 "Madhya Pradesh", "Maharashtra", "Punjab", "Rajasthan", "Tamil Nadu", "Telangana",
                 "Uttar Pradesh", "West Bengal"]


def state_select(id_, label="State"):
    opts = '<option value="">Choose a state</option>' + "".join(f"<option>{s}</option>" for s in INDIAN_STATES)
    return (f'<p class="form-row form-row-wide"><label for="{id_}">{label} <abbr class="required" title="required">*</abbr></label>'
            f'<select id="{id_}" name="state" class="input-text" required>{opts}</select></p>')


def cart_or_checkout(which):
    if which == "cart":
        # [woocommerce_cart]'s own markup — shop_table_responsive with data-title
        # cells, the quantity as WooCommerce's number input, the totals table.
        # The LINES are drawn by the page script from the cart, with this same
        # markup, so adding a product anywhere in the mock lands here.
        return '''<div class="woocommerce">
<div class="woocommerce-notices-wrapper"></div>
<div class="wc-empty-cart-message" hidden><div class="cart-empty woocommerce-info">Your cart is currently empty.</div>
<p class="return-to-shop"><a class="button wc-backward wp-element-button" href="/shop/" data-s="shop">Return to shop</a></p></div>
<form class="woocommerce-cart-form">
<table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents">
<thead><tr><th class="product-remove"><span class="screen-reader-text">Remove item</span></th>
<th class="product-thumbnail"><span class="screen-reader-text">Thumbnail image</span></th>
<th class="product-name">Product</th><th class="product-price">Price</th>
<th class="product-quantity">Quantity</th><th class="product-subtotal">Subtotal</th></tr></thead>
<tbody class="fx-lines"></tbody>
<tbody><tr><td class="actions" colspan="6"><div class="coupon"><label for="coupon_code" class="screen-reader-text">Coupon:</label>
<input type="text" id="coupon_code" class="input-text" placeholder="Partner or creator code" autocomplete="off">
<button type="submit" class="button wp-element-button" name="apply_coupon">Apply</button></div>
<button type="submit" class="button wp-element-button" name="update_cart" disabled>Update cart</button></td></tr></tbody>
</table></form>
<div class="cart-collaterals"><div class="cart_totals"><h2>Cart totals</h2>
<table class="shop_table shop_table_responsive"><tbody>
<tr class="cart-subtotal"><th>Subtotal</th><td data-title="Subtotal"></td></tr>
<tr class="cart-discount" hidden><th></th><td data-title="Coupon"></td></tr>
<tr class="woocommerce-shipping-totals shipping"><th>Shipping</th><td data-title="Shipping"></td></tr>
<tr class="order-total"><th>Total</th><td data-title="Total"></td></tr>
</tbody></table>
<p class="fd-cart-promise is-estimate">GST is included. Shipping is confirmed from your PIN code at the next step. Nothing else is added.</p>
<div class="wc-proceed-to-checkout"><a href="/checkout/" data-s="checkout" class="checkout-button button alt wc-forward wp-element-button">Proceed to checkout</a></div>
</div></div></div>'''
    # Checkout. A RETURNING customer sees the chooser and every field already
    # carrying the default address — WP-05's acceptance ("zero address fields
    # typed") made visible. A GUEST sees the same nine fields empty and no
    # chooser. The page script switches between them on the sign-in state.
    fields = [("fx-co-mobile", "Mobile number", "tel", "numeric", True),
              ("fx-co-email", "Email", "email", "", True),
              ("fx-co-name", "Full name", "text", "", True),
              ("fx-co-pin", "PIN code", "text", "numeric", True),
              ("fx-co-city", "City", "text", "", True),
              ("fx-co-state", "State", "", "", True),
              ("fx-co-addr1", "Address", "text", "", True),
              ("fx-co-addr2", "Address line 2 (optional)", "text", "", False),
              ("fx-co-notes", "Order notes (optional)", "text", "", False)]
    inputs = []
    for fid, label, typ, mode, req in fields:
        if fid == "fx-co-state":
            ctl = (f'<select id="{fid}"><option value="">Choose a state</option>'
                   + "".join(f"<option>{s}</option>" for s in INDIAN_STATES) + '</select>')
        else:
            im = f' inputmode="{mode}"' if mode else ""
            ctl = f'<input id="{fid}" type="{typ}"{im}{" required" if req else ""}>'
        inputs.append(f'<label class="fx-field" for="{fid}"><span>{html.escape(label)}</span>{ctl}</label>')
    return f'''<div class="fx-checkout">
<div><div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout" hidden></div>
<div class="woocommerce-form-coupon-toggle"><div class="woocommerce-info">Have a code from a
partner or a creator? <a href="#" class="showcoupon">Enter it here</a></div></div>
<form class="checkout_coupon woocommerce-form-coupon fx-co-coupon" hidden>
<p class="form-row"><label for="fx-co-code" class="screen-reader-text">Coupon</label>
<input type="text" id="fx-co-code" class="input-text" placeholder="Partner or creator code" autocomplete="off"></p>
<p class="form-row"><button type="submit" class="button wp-element-button">Apply</button></p></form>
<div class="woocommerce-form-login-toggle fx-co-signin"><div class="woocommerce-info">Already ordered from us? Sign in to fill this in automatically — or just carry on below as a guest. <a href="/my-account/" data-s="signin" class="showlogin">Click here to login</a></div></div>
<div class="fx-co-chooser"></div>
<p class="fx-fieldcount">Nine fields for a first order — the audited site asked twenty-five.
A returning customer types none of them.</p>
<form class="fx-co-fields" novalidate>{"".join(inputs)}</form></div>
<aside class="fx-summary"><h2>Your order</h2>
<div class="fx-co-lines"></div>
<div class="fx-row"><span>Subtotal</span><span class="fx-num fx-co-sub"></span></div>
<div class="fx-row fx-disc fx-co-coupon-row" hidden><span></span><span class="fx-num"></span></div>
<div class="fx-row fx-disc fx-co-prepaid-row"><span>Prepaid saving</span><span class="fx-num">−₹25</span></div>
<div class="fx-row"><span>Shipping</span><span class="fx-num fx-co-ship"></span></div>
<div class="fx-row fx-total"><span>Total</span><span class="fx-num fx-co-total"></span></div>
<p class="fd-cart-promise is-promise">GST is included. This is the final amount — no handling,
convenience or platform fee is added.</p>
<div class="fx-pay"><label><input type="radio" name="fx-pay" value="razorpay" checked> Pay now · UPI, card, netbanking
<span class="fd-pay-saving">Save ₹25</span></label>
<label><input type="radio" name="fx-pay" value="cod"> Cash on delivery</label></div>
<button type="button" class="wp-element-button fx-add fx-add--lg fx-place">Place order</button></aside></div>'''


def order_received():
    """WooCommerce's thank-you page (checkout/thankyou.php), same classes. The
    page script fills it from the order just placed, or the last one."""
    return '''<div class="woocommerce"><div class="woocommerce-order">
<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">Thank you. Your order has been received.</p>
<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
<li class="woocommerce-order-overview__order order">Order number: <strong class="fx-o-no"></strong></li>
<li class="woocommerce-order-overview__date date">Date: <strong class="fx-o-date"></strong></li>
<li class="woocommerce-order-overview__total total">Total: <strong class="fx-o-total"></strong></li>
<li class="woocommerce-order-overview__payment-method method">Payment method: <strong class="fx-o-pay"></strong></li>
</ul>
<p class="fx-o-next"></p>
<section class="woocommerce-order-details"><h2 class="woocommerce-order-details__title">Order details</h2>
<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
<thead><tr><th class="woocommerce-table__product-name product-name">Product</th><th class="woocommerce-table__product-table product-total">Total</th></tr></thead>
<tbody class="fx-o-lines"></tbody><tfoot class="fx-o-totals"></tfoot></table></section>
<section class="woocommerce-customer-details"><h2 class="woocommerce-column__title">Delivery address</h2>
<address class="fx-o-address"></address></section>
<p class="fx-o-actions"><a href="/shop/" data-s="shop" class="wp-element-button">Continue shopping</a>
<a href="/my-account/" data-s="account" class="fd-secondary">See your orders</a></p>
</div></div>'''


SIGNED_IN = True   # flipped per screen by main()


def my_account(signed_in):
    """WooCommerce's my-account markup, as the classic shortcode emits it. The
    panels are filled and switched by the page script; the classes are Woo's
    and the theme's (inc/address-book.php), so the theme's CSS styles them."""
    if not signed_in:
        # WooCommerce's own login form. WP-05's OTP plugin replaces exactly this,
        # which is why the theme does not render a form of its own. The second
        # step is the plugin's, drawn here so the flow can be walked end to end.
        return '''<div class="woocommerce"><div class="woocommerce-notices-wrapper"></div>
<form class="woocommerce-form woocommerce-form-login login fx-otp-1">
<h2>Sign in</h2>
<p class="fd-account-lead">Your saved addresses come back automatically, so checkout is four taps.</p>
<p class="woocommerce-form-row form-row"><label for="fx-mobile">Mobile number</label>
<input type="tel" id="fx-mobile" class="woocommerce-Input input-text" placeholder="10-digit mobile number" inputmode="numeric" maxlength="10" autocomplete="tel-national"></p>
<button type="submit" class="woocommerce-button button woocommerce-form-login__submit wp-element-button">Send code</button>
<p class="fd-account-lead" style="margin-top:1rem">No password. We send a six-digit code to your phone.</p>
</form>
<form class="woocommerce-form woocommerce-form-login login fx-otp-2" hidden>
<h2>Enter the code</h2>
<p class="fd-account-lead">Sent to <strong class="fx-otp-to"></strong>. <a href="#" class="fx-otp-change">Change number</a></p>
<p class="woocommerce-form-row form-row"><label for="fx-otp">Six-digit code</label>
<input type="text" id="fx-otp" class="woocommerce-Input input-text" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></p>
<button type="submit" class="woocommerce-button button wp-element-button">Verify and sign in</button>
<p class="fd-account-lead" style="margin-top:1rem">Any six digits work in this prototype. <a href="#" class="fx-otp-resend">Resend code</a></p>
</form>
<div class="fx-note">WP-05 · mobile-OTP login replaces WooCommerce's form once the SMS gateway is
DLT-registered. Guest checkout stays the default path either way.</div>
</div>'''

    nav = "".join(
        f'<li class="woocommerce-MyAccount-navigation-link woocommerce-MyAccount-navigation-link--{key}">'
        f'<a href="#" data-panel="{key}">{label}</a></li>'
        for key, label in [("orders", "Orders &amp; reorder"), ("address-book", "Saved addresses"),
                           ("edit-account", "Your details"), ("customer-logout", "Log out")])
    states = state_select("fd-state")
    return f'''<div class="woocommerce">
<nav class="woocommerce-MyAccount-navigation" aria-label="Account pages"><ul>{nav}</ul></nav>
<div class="woocommerce-MyAccount-content">
<div class="woocommerce-notices-wrapper"></div>
<section data-panel-body="orders">
<p class="fd-account-lead">Your past orders are one tap from being your next one.</p>
<table class="woocommerce-orders-table"><thead><tr>
<th>Order</th><th>Date</th><th>Status</th><th>Total</th><th><span class="screen-reader-text">Actions</span></th>
</tr></thead><tbody class="fx-orders"></tbody></table>
</section>
<section data-panel-body="view-order" hidden>
<p><a href="#" data-panel="orders" class="fd-secondary">← All orders</a></p>
<p class="fx-vo-head"></p>
<section class="woocommerce-order-details"><h2 class="woocommerce-order-details__title">Order details</h2>
<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
<thead><tr><th class="product-name">Product</th><th class="product-total">Total</th></tr></thead>
<tbody class="fx-vo-lines"></tbody><tfoot class="fx-vo-totals"></tfoot></table></section>
<section class="woocommerce-customer-details"><h2 class="woocommerce-column__title">Delivery address</h2>
<address class="fx-vo-address"></address></section>
<p><button type="button" class="wp-element-button fd-reorder fx-vo-reorder">Reorder these items</button></p>
</section>
<section data-panel-body="address-book" hidden>
<p class="fd-account-lead">Save the places you order to. Checkout fills in your default address on its
own — you only choose when it is going somewhere else.</p>
<ul class="fd-address-list"></ul>
<h3 class="fd-address-form__title">Add an address</h3>
<form class="fd-address-form woocommerce-address-fields">
<input type="hidden" name="address_id" value="">
<p class="form-row form-row-wide"><label for="fd-label">Name this address</label><input type="text" class="input-text" id="fd-label" placeholder="Home, Office…"></p>
<p class="form-row form-row-wide"><label for="fd-first_name">Full name <abbr class="required" title="required">*</abbr></label><input type="text" class="input-text" id="fd-first_name" placeholder="Priya Sharma" autocomplete="name" required></p>
<p class="form-row form-row-wide"><label for="fd-phone">Mobile number <abbr class="required" title="required">*</abbr></label><input type="text" class="input-text" id="fd-phone" inputmode="numeric" autocomplete="tel" required></p>
<p class="form-row form-row-wide"><label for="fd-address_1">Address <abbr class="required" title="required">*</abbr></label><input type="text" class="input-text" id="fd-address_1" placeholder="Flat, house number, building" autocomplete="address-line1" required></p>
<p class="form-row form-row-wide"><label for="fd-address_2">Area, street, landmark</label><input type="text" class="input-text" id="fd-address_2" placeholder="Optional" autocomplete="address-line2"></p>
<p class="form-row form-row-wide"><label for="fd-postcode">PIN code <abbr class="required" title="required">*</abbr></label><input type="text" class="input-text" id="fd-postcode" inputmode="numeric" maxlength="6" autocomplete="postal-code" required></p>
<p class="form-row form-row-wide"><label for="fd-city">Town / City <abbr class="required" title="required">*</abbr></label><input type="text" class="input-text" id="fd-city" autocomplete="address-level2" required></p>
{states}
<p class="form-row form-row-wide"><label><input type="checkbox" id="fd-is_default"> Use this address by default at checkout</label></p>
<p><button type="submit" class="wp-element-button">Save address</button>
<button type="button" class="fd-secondary fx-addr-cancel" hidden>Cancel</button></p>
</form>
</section>
<section data-panel-body="edit-account" hidden>
<p class="fd-account-lead">What we use to reach you about an order. Sign-in is by mobile number, so there is no password to change.</p>
<form class="woocommerce-EditAccountForm edit-account fx-details">
<p class="form-row form-row-wide"><label for="fx-d-name">Full name</label><input type="text" class="input-text" id="fx-d-name" autocomplete="name"></p>
<p class="form-row form-row-wide"><label for="fx-d-mobile">Mobile number</label><input type="text" class="input-text" id="fx-d-mobile" inputmode="numeric" disabled></p>
<p class="form-row form-row-wide"><label for="fx-d-email">Email for receipts</label><input type="email" class="input-text" id="fx-d-email" autocomplete="email"></p>
<p><button type="submit" class="wp-element-button">Save changes</button></p>
</form>
</section>
</div></div>'''


# ── block parser ──────────────────────────────────────────────────────────────
BLOCK = re.compile(r"<!--\s*(/)?wp:([a-z0-9-]+(?:/[a-z0-9-]+)?)\s*(\{.*?\})?\s*(/)?-->", re.S)


def load_pattern(slug):
    fn = slug.split("/")[-1] + ".php"
    path = os.path.join(THEME, "patterns", fn)
    if not os.path.exists(path):
        return f'<div class="fx-note">missing pattern: {slug}</div>'
    src = open(path).read()
    return src.split("?>", 1)[1] if "?>" in src else src


def find_close(markup, name, start):
    """(open_of_close, end_of_close) for the closing comment matching a block
    opened just before `start`, honouring nested blocks of the same name."""
    depth = 1
    for m in BLOCK.finditer(markup, start):
        if m.group(2) != name or m.group(4):
            continue
        depth += -1 if m.group(1) else 1
        if depth == 0:
            return m.start(), m.end()
    return len(markup), len(markup)


MOCK_SCRIPT = r'''<script>
(function () {
  'use strict';
  var SCREENS = __SCREENS__;
  var CATALOG = __CATALOG__;
  var PDP_SRC = __PDP_SCRIPT__;
  var THRESHOLD = 599, FLAT = 59, PREPAID = 25;
  var COUPONS = { NALIN10: { rate: 0.10, label: 'partner code' } };
  var KEY = 'foodify-prototype-v2';

  // ── state ────────────────────────────────────────────────────────────────
  // A returning customer, signed in, with two saved addresses and three past
  // orders — the person WP-05 was built for. Log out to walk the guest path.
  function seed() {
    return {
      cart: [{ s: 'express-dal-fry', q: 1 }, { s: 'idli-sambhar', q: 1 }, { s: 'express-dal-khichdi', q: 1 }],
      coupon: null, signedIn: true, mobile: '9876543210', name: 'Ateeshay Jain', email: 'you@example.com',
      addresses: [
        { id: 'a1', label: 'Home', name: 'Ateeshay Jain', phone: '9876543210', l1: 'N-7011 Parx Laureate', l2: 'Sector 108', city: 'Noida', state: 'Uttar Pradesh', pin: '201304', def: true },
        { id: 'a2', label: 'Office', name: 'Ateeshay Jain', phone: '9876543210', l1: 'Tower C, 9th floor', l2: 'Sector 16', city: 'Noida', state: 'Uttar Pradesh', pin: '201301', def: false }
      ],
      chosen: 'a1',
      orders: [
        order(1194, '22 Aug 2026', 'Delivered', [['express-dal-fry', 2], ['masala-chai', 1]], null, 'razorpay', 'a1'),
        order(1121, '31 Jul 2026', 'Delivered', [['idli-sambhar', 1], ['coconut-red-chutney', 1]], null, 'cod', 'a1'),
        order(1088, '09 Jul 2026', 'Delivered', [['week-box-6-meals', 1], ['coconut-red-chutney', 1]], null, 'razorpay', 'a2')
      ],
      nextNo: 1195, last: null
    };
    function order(no, date, status, lines, coupon, pay, addrId) {
      var L = lines.map(function (l) { return { s: l[0], q: l[1], price: CATALOG[l[0]].price }; });
      var t = totals(L, coupon, pay);
      var a = { a1: ['Ateeshay Jain', '9876543210', 'N-7011 Parx Laureate', 'Sector 108', 'Noida', 'Uttar Pradesh', '201304'],
                a2: ['Ateeshay Jain', '9876543210', 'Tower C, 9th floor', 'Sector 16', 'Noida', 'Uttar Pradesh', '201301'] }[addrId];
      return { no: no, date: date, status: status, lines: L, coupon: coupon, pay: pay, t: t, email: 'you@example.com',
               addr: { name: a[0], phone: a[1], l1: a[2], l2: a[3], city: a[4], state: a[5], pin: a[6] } };
    }
  }
  var S;
  try { S = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { S = null; }
  if (!S || !S.cart) S = seed();
  function save() { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {} }

  // ── arithmetic: the rules bootstrap.sh and inc/payments.php configure ─────
  // Free shipping at ₹599 compared AFTER discounts, ₹59 below it, ₹25 off for
  // paying now. One function, so the cart, the drawer, checkout and the order
  // all agree — the way WooCommerce computes them once on the site.
  function totals(lines, coupon, pay) {
    var sub = 0, count = 0;
    lines.forEach(function (l) { sub += l.price * l.q; count += l.q; });
    var disc = coupon ? Math.round(sub * COUPONS[coupon].rate) : 0;
    var after = sub - disc;
    var ship = !lines.length ? 0 : (after >= THRESHOLD ? 0 : FLAT);
    var pre = pay === 'razorpay' && lines.length ? Math.min(PREPAID, after) : 0;
    return { sub: sub, count: count, disc: disc, after: after, ship: ship, pre: pre, total: after + ship - pre };
  }
  function cartLines() {
    return S.cart.filter(function (l) { return CATALOG[l.s]; })
      .map(function (l) { return { s: l.s, q: l.q, price: CATALOG[l.s].price }; });
  }
  function payMethod() { var r = document.querySelector('#s-checkout input[name=fx-pay]:checked'); return r ? r.value : 'razorpay'; }
  function rs(n) { try { return '₹' + new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(n); } catch (e) { return '₹' + Math.round(n); } }
  function amt(n) { return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">₹</span>' + new Intl.NumberFormat('en-IN').format(n) + '</bdi></span>'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function bowl(hue) { return '<div class="fx-bowl" style="--h:' + hue + '"></div>'; }
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
  function screen(id) { return document.getElementById('s-' + id); }

  // ── screens ──────────────────────────────────────────────────────────────
  var TABS = $$('.proto .tab');
  var current = null, afterSignIn = null;
  function show(id, push, anchor) {
    if (SCREENS.indexOf(id) < 0) return;
    // What WordPress does before it renders: the account page signs you in
    // first, and checkout with an empty cart sends you back to the cart.
    if (id === 'account' && !S.signedIn) id = 'signin';
    if (id === 'signin' && S.signedIn) id = 'account';
    if (id === 'checkout' && !S.cart.length) { id = 'cart'; }
    if (id === 'account' && push !== false) panel = 'orders';
    SCREENS.forEach(function (s) { screen(s).hidden = (s !== id); });
    TABS.forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.s === id)); });
    current = id;
    var R = RENDER[id] || (id.indexOf('p-') === 0 ? RENDER.product : null);
    if (R) R(screen(id));
    closeDrawer(); closeMenu();
    if (anchor) { var t = screen(id).querySelector('#' + anchor); if (t) { t.scrollIntoView(); } else window.scrollTo(0, 0); }
    else window.scrollTo(0, 0);
    if (push !== false) history.pushState({ s: id }, '', '#' + id);
  }
  window.addEventListener('popstate', function (e) {
    show((e.state && e.state.s) || location.hash.slice(1) || 'home', false);
  });

  // ── notices: WooCommerce's own three kinds ───────────────────────────────
  function notice(root, kind, html) {
    var w = root.querySelector('.woocommerce-notices-wrapper, .woocommerce-NoticeGroup');
    if (!w) return;
    w.hidden = false;
    w.innerHTML = kind === 'error'
      ? '<ul class="woocommerce-error" role="alert" tabindex="-1">' + [].concat(html).map(function (h) { return '<li>' + h + '</li>'; }).join('') + '</ul>'
      : '<div class="woocommerce-' + (kind === 'info' ? 'info' : 'message') + '" role="status" tabindex="-1">' + html + '</div>';
    var n = w.firstChild; if (n && n.focus) n.focus({ preventScroll: true });
    w.scrollIntoView({ block: 'center' });
  }
  function clearNotice(root) { $$('.woocommerce-notices-wrapper, .woocommerce-NoticeGroup', root).forEach(function (w) { w.innerHTML = ''; }); }

  // ── the header, on every screen ──────────────────────────────────────────
  function paintHeader() {
    var t = totals(cartLines(), S.coupon, null);
    $$('.wc-block-mini-cart__amount').forEach(function (a) { a.textContent = rs(t.sub); });
    $$('.wc-block-mini-cart__badge').forEach(function (b) { b.textContent = String(t.count); });
    $$('.wc-block-mini-cart__button').forEach(function (b) {
      b.setAttribute('aria-label', t.count + (t.count === 1 ? ' item' : ' items') + ' in cart, total price of ' + rs(t.sub));
    });
    $$('.wc-block-customer-account__account-link').forEach(function (a) {
      a.setAttribute('aria-label', S.signedIn ? 'My account' : 'Sign in');
    });
  }

  // ── the cart ─────────────────────────────────────────────────────────────
  function add(slug, q) {
    var p = CATALOG[slug]; if (!p || !p.stock) return;
    var line = S.cart.filter(function (l) { return l.s === slug; })[0];
    if (line) line.q += q; else S.cart.push({ s: slug, q: q });
    save(); paintHeader(); openDrawer(p.name + (q > 1 ? ' × ' + q : '') + ' added to your cart.');
  }
  function setQty(slug, q) {
    S.cart = S.cart.map(function (l) { if (l.s === slug) l.q = q; return l; }).filter(function (l) { return l.q > 0; });
    if (!S.cart.length) S.coupon = null;
    save(); paintHeader();
  }
  function shipMessage(t) {
    if (!t.count) return '';
    return t.after >= THRESHOLD ? '<strong>Free shipping unlocked.</strong> Delivery is on us.'
      : '<strong>' + rs(THRESHOLD - t.after) + '</strong> away from free shipping.';
  }
  function paintShipBar(root, t) {
    $$('.fd-shipping-progress', root).forEach(function (w) {
      w.hidden = !t.count;
      var pct = Math.min(100, Math.round(t.after / THRESHOLD * 100));
      $('.fd-ship', w).innerHTML = shipMessage(t);
      $('.fd-progress', w).setAttribute('aria-valuenow', String(pct));
      $('.fd-progress > i', w).style.width = pct + '%';
    });
  }
  var RENDER = {}, qtyTimer;
  RENDER.cart = function (root) {
    var L = cartLines(), t = totals(L, S.coupon, null);
    var empty = !L.length;
    $('.wc-empty-cart-message', root).hidden = !empty;
    $('.woocommerce-cart-form', root).hidden = empty;
    $('.cart-collaterals', root).hidden = empty;
    paintShipBar(root, t);
    $('.fx-lines', root).innerHTML = L.map(function (l, i) {
      var p = CATALOG[l.s];
      return '<tr class="woocommerce-cart-form__cart-item cart_item" data-s-line="' + l.s + '">' +
        '<td class="product-remove"><a href="#" class="remove" aria-label="Remove ' + esc(p.name) + ' from cart">×</a></td>' +
        '<td class="product-thumbnail"><a href="#" data-s="' + p.screen + '">' + bowl(p.hue) + '</a></td>' +
        '<td class="product-name" data-title="Product"><a href="#" data-s="' + p.screen + '">' + esc(p.name) + '</a></td>' +
        '<td class="product-price" data-title="Price">' + amt(p.price) + '</td>' +
        '<td class="product-quantity" data-title="Quantity"><div class="quantity">' +
        '<label class="screen-reader-text" for="cq-' + i + '">Quantity</label>' +
        '<input type="number" id="cq-' + i + '" class="input-text qty text" value="' + l.q + '" min="0" step="1" inputmode="numeric"></div></td>' +
        '<td class="product-subtotal" data-title="Subtotal">' + amt(p.price * l.q) + '</td></tr>';
    }).join('');
    paintCartTotals(root);
  };
  function paintCartTotals(root) {
    var L = cartLines(), t = totals(L, S.coupon, null);
    paintShipBar(root, t);
    $('.cart-subtotal td', root).innerHTML = amt(t.sub);
    var dr = $('.cart-discount', root);
    dr.hidden = !S.coupon;
    if (S.coupon) {
      $('th', dr).textContent = 'Coupon: ' + S.coupon.toLowerCase();
      $('td', dr).innerHTML = '−' + amt(t.disc) + ' <a href="#" class="woocommerce-remove-coupon">[Remove]</a>';
    }
    $('.shipping td', root).innerHTML = t.ship ? 'Flat rate: ' + amt(t.ship) : 'Free shipping';
    $('.order-total td', root).innerHTML = '<strong>' + amt(t.total) + '</strong>';
  }
  function applyCoupon(code, root) {
    code = (code || '').trim().toUpperCase();
    if (!code) return notice(root, 'error', 'Please enter a coupon code.');
    if (!COUPONS[code]) return notice(root, 'error', 'Coupon "' + esc(code.toLowerCase()) + '" does not exist!');
    if (S.coupon === code) return notice(root, 'error', 'Coupon code already applied!');
    S.coupon = code; save();
    notice(root, 'message', 'Coupon code applied successfully.');
  }

  // ── the mini-cart drawer (addToCartBehaviour: open_drawer in header.html) ─
  var drawer = document.createElement('div');
  drawer.className = 'wc-block-components-drawer__screen-overlay fx-drawer-overlay';
  drawer.hidden = true;
  drawer.innerHTML =
    '<div class="wc-block-mini-cart__drawer wc-block-components-drawer" role="dialog" aria-modal="true" aria-label="Your cart" tabindex="-1">' +
    '<div class="wc-block-mini-cart__title-row"><h2 class="wc-block-mini-cart__title">Your cart <span class="fx-dr-count"></span></h2>' +
    '<button type="button" class="wc-block-components-drawer__close" aria-label="Close">×</button></div>' +
    '<p class="fx-dr-added" role="status"></p>' +
    '<div class="fx-dr-body"></div></div>';
  document.body.appendChild(drawer);
  var lastFocus = null;
  function openDrawer(msg) {
    lastFocus = document.activeElement;
    paintDrawer(msg || '');
    drawer.hidden = false;
    document.documentElement.classList.add('fx-locked');
    $('.wc-block-components-drawer__close', drawer).focus();
  }
  function closeDrawer() {
    if (drawer.hidden) return;
    drawer.hidden = true; document.documentElement.classList.remove('fx-locked');
    if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
  }
  function paintDrawer(msg) {
    var L = cartLines(), t = totals(L, S.coupon, null);
    $('.fx-dr-count', drawer).textContent = '(' + t.count + (t.count === 1 ? ' item)' : ' items)');
    var added = $('.fx-dr-added', drawer);
    if (msg !== undefined) { added.textContent = msg; added.hidden = !msg; }
    if (!L.length) {
      $('.fx-dr-body', drawer).innerHTML = '<div class="wc-block-mini-cart__empty-cart-wrapper"><p class="wc-block-mini-cart__empty-cart">Your cart is currently empty!</p>' +
        '<a href="#" data-s="shop" class="wp-element-button">Start shopping</a></div>';
      return;
    }
    $('.fx-dr-body', drawer).innerHTML =
      '<ul class="wc-block-mini-cart__items">' + L.map(function (l) {
        var p = CATALOG[l.s];
        return '<li class="wc-block-cart-items__row" data-s-line="' + l.s + '">' +
          '<a href="#" data-s="' + p.screen + '" class="fx-dr-img" tabindex="-1">' + bowl(p.hue) + '</a>' +
          '<div class="fx-dr-main"><a href="#" data-s="' + p.screen + '" class="wc-block-components-product-name">' + esc(p.name) + '</a>' +
          '<div class="wc-block-components-quantity-selector">' +
          '<button type="button" class="wc-block-components-quantity-selector__button--minus" aria-label="Reduce quantity of ' + esc(p.name) + '">−</button>' +
          '<span class="fx-dr-q" aria-live="polite">' + l.q + '</span>' +
          '<button type="button" class="wc-block-components-quantity-selector__button--plus" aria-label="Increase quantity of ' + esc(p.name) + '">+</button></div>' +
          '<a href="#" class="wc-block-cart-item__remove-link">Remove item</a></div>' +
          '<span class="fx-num">' + rs(p.price * l.q) + '</span></li>';
      }).join('') + '</ul>' +
      '<div class="wc-block-mini-cart__footer">' +
      '<div class="wc-block-mini-cart__footer-subtotal"><span>Subtotal</span><span class="fx-num">' + rs(t.sub) + '</span></div>' +
      '<p class="fx-dr-ship">' + shipMessage(t) + '</p>' +
      '<div class="wc-block-mini-cart__footer-actions">' +
      '<a href="/cart/" data-s="cart" class="wc-block-mini-cart__footer-cart wp-element-button fx-outline">View my cart</a>' +
      '<a href="/checkout/" data-s="checkout" class="wc-block-mini-cart__footer-checkout wp-element-button">Go to checkout</a></div></div>';
  }
  drawer.addEventListener('click', function (e) {
    if (e.target === drawer || e.target.closest('.wc-block-components-drawer__close')) { e.preventDefault(); e.stopPropagation(); closeDrawer(); return; }
    var row = e.target.closest('[data-s-line]');
    if (!row) return;
    var slug = row.getAttribute('data-s-line'), line = S.cart.filter(function (l) { return l.s === slug; })[0];
    if (!line) return;
    if (e.target.closest('.wc-block-components-quantity-selector__button--minus')) { e.stopPropagation(); setQty(slug, line.q - 1); paintDrawer(); }
    else if (e.target.closest('.wc-block-components-quantity-selector__button--plus')) { e.stopPropagation(); setQty(slug, line.q + 1); paintDrawer(); }
    else if (e.target.closest('.wc-block-cart-item__remove-link')) { e.preventDefault(); e.stopPropagation(); setQty(slug, 0); paintDrawer(CATALOG[slug].name + ' removed.'); }
  });

  // ── the phone menu (overlayMenu: "mobile" in header.html) ────────────────
  var menu = document.createElement('div');
  menu.className = 'wp-block-navigation__responsive-container fx-menu has-paper-background-color has-char-color';
  menu.hidden = true;
  menu.setAttribute('role', 'dialog'); menu.setAttribute('aria-modal', 'true'); menu.setAttribute('aria-label', 'Menu');
  document.body.appendChild(menu);
  function openMenu() {
    var nav = $('#s-' + current + ' .fx-nav') || $('.fx-nav');
    // Core's overlay holds the menu's own items and nothing else; the account
    // and cart stay in the header row beside the button.
    menu.innerHTML = '<button type="button" class="wp-block-navigation__responsive-container-close" aria-label="Close menu">×</button>' +
      '<nav class="fx-menu__links">' + (nav ? nav.innerHTML : '') + '</nav>';
    menu.hidden = false; document.documentElement.classList.add('fx-locked');
    $('button', menu).focus();
  }
  function closeMenu() { if (!menu.hidden) { menu.hidden = true; document.documentElement.classList.remove('fx-locked'); } }

  // ── the Razorpay window, which is Razorpay's, not the theme's ────────────
  var rzp = document.createElement('div');
  rzp.className = 'fx-rzp'; rzp.hidden = true;
  rzp.innerHTML = '<div class="fx-rzp__box" role="dialog" aria-modal="true" aria-labelledby="fx-rzp-t">' +
    '<p class="fx-rzp__brand">Razorpay · Secure payment</p><h2 id="fx-rzp-t">Foodify</h2><p class="fx-rzp__amt"></p>' +
    '<p class="fx-rzp__note">On the site, Razorpay’s own window opens here for UPI, cards and netbanking. It is Razorpay’s screen, not part of the theme, so this prototype only stands in for it.</p>' +
    '<button type="button" class="wp-element-button fx-rzp-ok">Simulate a successful payment</button>' +
    '<button type="button" class="fd-secondary fx-rzp-cancel">Cancel payment</button></div>';
  document.body.appendChild(rzp);

  // ── checkout ─────────────────────────────────────────────────────────────
  var FIELDS = { mobile: 'fx-co-mobile', email: 'fx-co-email', name: 'fx-co-name', pin: 'fx-co-pin', city: 'fx-co-city', state: 'fx-co-state', l1: 'fx-co-addr1', l2: 'fx-co-addr2' };
  function field(k) { return document.getElementById(FIELDS[k]); }
  function chosenAddress() { return S.addresses.filter(function (a) { return a.id === S.chosen; })[0] || S.addresses.filter(function (a) { return a.def; })[0] || S.addresses[0]; }
  function fillFrom(a) {
    field('mobile').value = S.mobile; field('email').value = S.email; field('name').value = a ? a.name : S.name;
    ['pin', 'city', 'state', 'l1', 'l2'].forEach(function (k) { field(k).value = a ? a[k] : ''; });
  }
  var checkoutMode = null;
  RENDER.checkout = function (root) {
    var L = cartLines(), pay = payMethod(), t = totals(L, S.coupon, pay);
    $('.fx-co-signin', root).hidden = S.signedIn;
    var ch = $('.fx-co-chooser', root);
    if (S.signedIn && S.addresses.length >= 2) {
      var sel = chosenAddress();
      ch.innerHTML = '<form class="fd-address-choose"><fieldset><legend>Deliver to</legend>' +
        S.addresses.map(function (a) {
          return '<label class="fd-address-choose__option"><input type="radio" name="fx-addr" value="' + a.id + '"' + (sel && a.id === sel.id ? ' checked' : '') + '>' +
            '<span class="fd-address-choose__label">' + esc(a.label || a.name) + '</span>' +
            '<span class="fd-address-choose__body">' + esc(a.l1 + ', ' + a.l2 + ', ' + a.city + ', ' + a.pin) + '</span></label>';
        }).join('') +
        '<button type="submit" class="wp-element-button">Use this address</button>' +
        '<a class="fd-address-choose__manage" href="#" data-s="account" data-panel-go="address-book">Manage saved addresses</a></fieldset></form>';
    } else ch.innerHTML = '';
    var mode = S.signedIn ? 'in' : 'guest';
    if (mode !== checkoutMode) {
      checkoutMode = mode;
      if (S.signedIn) fillFrom(chosenAddress());
      else ['mobile', 'email', 'name', 'pin', 'city', 'state', 'l1', 'l2'].forEach(function (k) { field(k).value = ''; });
    }
    $('.fx-co-lines', root).innerHTML = L.map(function (l) {
      var p = CATALOG[l.s];
      return '<div class="fx-row"><span>' + esc(p.name) + ' <span class="fx-meta-inline">× ' + l.q + '</span></span><span class="fx-num">' + rs(p.price * l.q) + '</span></div>';
    }).join('');
    $('.fx-co-sub', root).textContent = rs(t.sub);
    var cr = $('.fx-co-coupon-row', root); cr.hidden = !S.coupon;
    if (S.coupon) { cr.firstChild.textContent = 'Coupon ' + S.coupon; cr.lastChild.textContent = '−' + rs(t.disc); }
    $('.fx-co-prepaid-row', root).hidden = !t.pre;
    $('.fx-co-ship', root).textContent = t.ship ? rs(t.ship) : 'Free';
    $('.fx-co-total', root).textContent = rs(t.total);
    $('.fx-place', root).textContent = pay === 'cod' ? 'Place order · pay ' + rs(t.total) + ' on delivery' : 'Pay ' + rs(t.total) + ' and place order';
  };
  function validateCheckout() {
    var errs = [], v = function (k) { return (field(k).value || '').trim(); };
    if (!/^[6-9]\d{9}$/.test(v('mobile').replace(/\D/g, ''))) errs.push(['mobile', '<strong>Mobile number</strong> must be a 10-digit Indian mobile number.']);
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v('email'))) errs.push(['email', '<strong>Email</strong> is not a valid email address.']);
    if (!v('name')) errs.push(['name', '<strong>Full name</strong> is a required field.']);
    if (!/^\d{6}$/.test(v('pin'))) errs.push(['pin', '<strong>PIN code</strong> is not a valid PIN code.']);
    if (!v('city')) errs.push(['city', '<strong>City</strong> is a required field.']);
    if (!v('state')) errs.push(['state', '<strong>State</strong> is a required field.']);
    if (!v('l1')) errs.push(['l1', '<strong>Address</strong> is a required field.']);
    Object.keys(FIELDS).forEach(function (k) { field(k).removeAttribute('aria-invalid'); });
    errs.forEach(function (e) { field(e[0]).setAttribute('aria-invalid', 'true'); });
    return errs;
  }
  function placeOrder() {
    var L = cartLines(), pay = payMethod(), t = totals(L, S.coupon, pay);
    var v = function (k) { return (field(k).value || '').trim(); };
    var addr = { name: v('name'), phone: v('mobile').replace(/\D/g, ''), l1: v('l1'), l2: v('l2'), city: v('city'), state: v('state'), pin: v('pin') };
    var d = new Date(), months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var o = { no: S.nextNo++, date: ('0' + d.getDate()).slice(-2) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear(),
              status: 'Processing', lines: L, coupon: S.coupon, pay: pay, t: t, email: v('email'), addr: addr, fresh: true };
    // A completed order teaches the book (inc/address-book.php): a signed-in
    // customer who typed a new address finds it saved next time.
    if (S.signedIn) {
      var known = S.addresses.some(function (a) { return a.l1 === addr.l1 && a.pin === addr.pin; });
      if (!known && addr.l1) S.addresses.push({ id: 'a' + Date.now(), label: '', name: addr.name, phone: addr.phone, l1: addr.l1, l2: addr.l2, city: addr.city, state: addr.state, pin: addr.pin, def: !S.addresses.length });
      S.orders.unshift(o);
    }
    S.last = o; S.cart = []; S.coupon = null; save(); paintHeader();
    show('order');
  }

  // ── order received ───────────────────────────────────────────────────────
  function orderTable(o, lines, totalsEl) {
    lines.innerHTML = o.lines.map(function (l) {
      var p = CATALOG[l.s] || { name: l.s, screen: 'home' };
      return '<tr class="woocommerce-table__line-item order_item"><td class="woocommerce-table__product-name product-name">' +
        '<a href="#" data-s="' + p.screen + '">' + esc(p.name) + '</a> <strong class="product-quantity">×&nbsp;' + l.q + '</strong></td>' +
        '<td class="woocommerce-table__product-total product-total">' + amt(l.price * l.q) + '</td></tr>';
    }).join('');
    var rows = [['Subtotal:', amt(o.t.sub)]];
    if (o.coupon) rows.push(['Coupon ' + o.coupon + ':', '−' + amt(o.t.disc)]);
    if (o.t.pre) rows.push(['Prepaid saving:', '−' + amt(o.t.pre)]);
    rows.push(['Shipping:', o.t.ship ? amt(o.t.ship) + ' <small class="shipped_via">via Flat rate</small>' : 'Free shipping']);
    rows.push(['Payment method:', o.pay === 'cod' ? 'Cash on delivery' : 'Paid online (Razorpay)']);
    rows.push(['Total:', '<strong>' + amt(o.t.total) + '</strong> <small class="includes_tax">(includes GST)</small>']);
    totalsEl.innerHTML = rows.map(function (r) { return '<tr><th scope="row">' + r[0] + '</th><td>' + r[1] + '</td></tr>'; }).join('');
  }
  function addressHTML(a) {
    return esc(a.name) + '<br>' + esc(a.l1) + (a.l2 ? '<br>' + esc(a.l2) : '') + '<br>' + esc(a.city) + ' ' + esc(a.pin) + '<br>' + esc(a.state) +
      '<p class="woocommerce-customer-details--phone">' + esc(a.phone) + '</p>';
  }
  RENDER.order = function (root) {
    var o = S.last || S.orders[0];
    if (!o) return;
    $('.fx-o-no', root).textContent = o.no;
    $('.fx-o-date', root).textContent = o.date;
    $('.fx-o-total', root).innerHTML = amt(o.t.total);
    $('.fx-o-pay', root).textContent = o.pay === 'cod' ? 'Cash on delivery' : 'Paid online (Razorpay)';
    $('.fx-o-next', root).innerHTML = (o.pay === 'cod' ? 'Keep <strong>' + rs(o.t.total) + '</strong> ready in cash for the delivery. ' : 'Your payment is complete. ') +
      'A receipt has been emailed to <strong>' + esc(o.email) + '</strong>.';
    orderTable(o, $('.fx-o-lines', root), $('.fx-o-totals', root));
    $('.fx-o-address', root).innerHTML = addressHTML(o.addr);
    $('.fx-o-actions a[data-s=account]', root).hidden = !S.signedIn;
  };

  // ── my account ───────────────────────────────────────────────────────────
  var panel = 'orders', viewing = null;
  function goPanel(p) { panel = p; RENDER.account(screen('account'), true); }
  RENDER.account = function (root, keepNotice) {
    if (!keepNotice) clearNotice(root);
    $$('[data-panel-body]', root).forEach(function (s) { s.hidden = s.getAttribute('data-panel-body') !== panel; });
    $$('.woocommerce-MyAccount-navigation-link', root).forEach(function (li) {
      var k = li.querySelector('a').getAttribute('data-panel');
      li.classList.toggle('is-active', k === panel || (panel === 'view-order' && k === 'orders'));
    });
    $('.fx-orders', root).innerHTML = S.orders.length ? S.orders.map(function (o) {
      return '<tr><td><a href="#" data-view="' + o.no + '"><strong>#' + o.no + '</strong></a></td><td>' + o.date + '</td><td>' + o.status + '</td>' +
        '<td class="woocommerce-orders-table__cell-order-total">' + rs(o.t.total) + '<span class="fx-meta">' + o.t.count + (o.t.count === 1 ? ' item' : ' items') + '</span></td>' +
        '<td class="woocommerce-orders-table__cell-order-actions">' +
        '<button type="button" class="wp-element-button fd-reorder" data-reorder="' + o.no + '">Reorder</button>' +
        '<button type="button" class="wp-element-button fd-secondary" data-view="' + o.no + '">Details</button></td></tr>';
    }).join('') : '<tr><td colspan="5">No order has been made yet. <a href="#" data-s="shop">Browse products</a></td></tr>';
    var o = S.orders.filter(function (x) { return x.no === viewing; })[0];
    if (o) {
      $('.fx-vo-head', root).innerHTML = 'Order <mark class="order-number">#' + o.no + '</mark> was placed on <mark class="order-date">' + o.date + '</mark> and is currently <mark class="order-status">' + o.status + '</mark>.';
      orderTable(o, $('.fx-vo-lines', root), $('.fx-vo-totals', root));
      $('.fx-vo-address', root).innerHTML = addressHTML(o.addr);
      $('.fx-vo-reorder', root).setAttribute('data-reorder', o.no);
    }
    $('.fd-address-list', root).innerHTML = S.addresses.length ? S.addresses.map(function (a) {
      return '<li class="fd-address' + (a.def ? ' is-default' : '') + '" data-addr="' + a.id + '">' +
        '<div class="fd-address__head"><span class="fd-address__label">' + esc(a.label || a.name) + '</span>' + (a.def ? '<span class="fd-address__default">Default</span>' : '') + '</div>' +
        '<p class="fd-address__body">' + esc(a.name) + ' · ' + esc(a.phone) + '<br>' + esc(a.l1 + (a.l2 ? ', ' + a.l2 : '') + ', ' + a.city + ', ' + a.pin + ' ' + a.state) + '</p>' +
        '<div class="fd-address__actions"><a class="fd-secondary" href="#" data-addr-edit>Edit</a>' +
        (a.def ? '' : '<form class="fd-address__verb"><button type="button" data-addr-default>Make default</button></form>' +
          '<form class="fd-address__verb"><button type="button" class="fd-danger" data-addr-delete>Delete</button></form>') +
        '</div></li>';
    }).join('') : '<li class="fd-address-note">No saved addresses yet. Add one below, or it is saved from your first order.</li>';
    $('#fx-d-name', root).value = S.name; $('#fx-d-mobile', root).value = S.mobile; $('#fx-d-email', root).value = S.email;
  };
  function addrForm(a) {
    var f = $('#s-account .fd-address-form');
    f.querySelector('[name=address_id]').value = a ? a.id : '';
    var m = { label: 'label', first_name: 'name', phone: 'phone', address_1: 'l1', address_2: 'l2', postcode: 'pin', city: 'city', state: 'state' };
    Object.keys(m).forEach(function (k) { $('#fd-' + k).value = a ? a[m[k]] : ''; });
    $('#fd-is_default').checked = !!(a && a.def);
    $('.fd-address-form__title').textContent = a ? 'Edit address' : 'Add an address';
    $('button[type=submit]', f).textContent = a ? 'Save changes' : 'Save address';
    $('.fx-addr-cancel', f).hidden = !a;
    if (a) f.scrollIntoView({ block: 'start' });
  }
  function reorder(no) {
    var o = S.orders.filter(function (x) { return x.no === no; })[0]; if (!o) return;
    var skipped = [];
    o.lines.forEach(function (l) {
      var p = CATALOG[l.s];
      if (!p || !p.stock) { skipped.push(p ? p.name : l.s); return; }
      var line = S.cart.filter(function (c) { return c.s === l.s; })[0];
      if (line) line.q += l.q; else S.cart.push({ s: l.s, q: l.q });
    });
    save(); paintHeader(); show('cart');
    notice(screen('cart'), 'message', 'The items from order #' + no + ' are in your cart.' +
      (skipped.length ? ' ' + esc(skipped.join(', ')) + (skipped.length === 1 ? ' is' : ' are') + ' out of stock and was left out.' : ''));
  }

  // ── sign in (WP-05's OTP flow) ───────────────────────────────────────────
  RENDER.signin = function (root) {
    $('.fx-otp-1', root).hidden = false; $('.fx-otp-2', root).hidden = true;
  };

  // ── product pages ────────────────────────────────────────────────────────
  RENDER.product = function () {};
  $$('[id^=s-p-]').forEach(function (el) {
    try { (new Function('document', PDP_SRC))(el); } catch (e) {}
  });

  // ── listings: WooCommerce's filter and sort blocks, done in the page ──────
  function applyFilters(root) {
    var grid = $('.fd-shop__results .wp-block-post-template', root);
    if (!grid) return;
    var want = { prep: [], dietary: [] };
    $$('.wc-block-components-checkbox__input:checked', root).forEach(function (c) { want[c.getAttribute('data-attr')].push(c.value); });
    var lo = parseFloat(($('.wc-block-price-filter__amount--min', root) || {}).value) || 0;
    var hi = parseFloat(($('.wc-block-price-filter__amount--max', root) || {}).value) || 1e9;
    var shown = 0;
    $$('li.wp-block-post', grid).forEach(function (li) {
      var prep = li.getAttribute('data-prep').split('|'), diet = li.getAttribute('data-diet').split('|');
      var price = parseFloat(li.getAttribute('data-price'));
      var ok = (!want.prep.length || want.prep.some(function (w) { return prep.indexOf(w) >= 0; })) &&
               (!want.dietary.length || want.dietary.every(function (w) { return diet.indexOf(w) >= 0; })) &&
               price >= lo && price <= hi;
      li.hidden = !ok; if (ok) shown++;
    });
    var rc = $('.woocommerce-result-count', root);
    if (rc) rc.textContent = shown === 0 ? 'No products found' : shown === 1 ? 'Showing the single result' : 'Showing all ' + shown + ' results';
    var nr = $('.fx-noresults', root); if (nr) nr.hidden = shown > 0;
    var mn = $('.wc-block-price-filter__amount--min', root), mx = $('.wc-block-price-filter__amount--max', root), bar = $('.fx-range i', root);
    if (mn && mx && bar) {
      var f = parseFloat(mn.getAttribute('data-floor')), c = parseFloat(mx.getAttribute('data-ceil')), span = Math.max(1, c - f);
      bar.style.left = Math.max(0, Math.min(100, (lo - f) / span * 100)) + '%';
      bar.style.right = Math.max(0, Math.min(100, (c - Math.min(hi, c)) / span * 100)) + '%';
    }
  }
  function applySort(root, how) {
    var grid = $('.fd-shop__results .wp-block-post-template', root); if (!grid) return;
    var key = {
      menu_order: function (li) { return +li.getAttribute('data-order'); },
      popularity: function (li) { return -li.getAttribute('data-pop'); },
      rating: function (li) { return -parseFloat(li.getAttribute('data-rating')); },
      date: function (li) { return -li.getAttribute('data-new'); },
      price: function (li) { return +li.getAttribute('data-price'); },
      'price-desc': function (li) { return -li.getAttribute('data-price'); }
    }[how] || function () { return 0; };
    $$('li.wp-block-post', grid).map(function (li, i) { return [key(li), i, li]; })
      .sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; })
      .forEach(function (x) { grid.appendChild(x[2]); });
  }
  function resetFilters(root) {
    $$('.wc-block-components-checkbox__input', root).forEach(function (c) { c.checked = false; });
    var mn = $('.wc-block-price-filter__amount--min', root), mx = $('.wc-block-price-filter__amount--max', root);
    if (mn) mn.value = mn.getAttribute('data-floor'); if (mx) mx.value = mx.getAttribute('data-ceil');
    applyFilters(root);
  }

  // ── one click handler for the whole document ─────────────────────────────
  var toast = document.createElement('div'); toast.className = 'fx-toast'; toast.hidden = true; toast.setAttribute('role', 'status');
  document.body.appendChild(toast); var tt;
  function say(msg) { toast.textContent = msg; toast.hidden = false; clearTimeout(tt); tt = setTimeout(function () { toast.hidden = true; }, 3600); }

  document.addEventListener('click', function (e) {
    var t = e.target, root = t.closest('.fx-shell');
    var el;
    if ((el = t.closest('.proto-reset'))) { e.preventDefault(); S = seed(); save(); checkoutMode = null; viewing = null; paintHeader(); show('home'); say('Prototype reset: three items in the cart, signed in as a returning customer.'); return; }
    if ((el = t.closest('[data-add]'))) {
      e.preventDefault();
      var form = el.closest('form.cart'), q = form ? Math.max(1, parseInt((form.querySelector('input.qty') || {}).value, 10) || 1) : 1;
      add(el.getAttribute('data-add'), q); return;
    }
    if (t.closest('[data-drawer]')) { e.preventDefault(); openDrawer(''); return; }
    if (t.closest('.fx-burger')) { e.preventDefault(); openMenu(); return; }
    if (t.closest('.wp-block-navigation__responsive-container-close')) { e.preventDefault(); closeMenu(); return; }
    if ((el = t.closest('[data-reorder]'))) { e.preventDefault(); reorder(+el.getAttribute('data-reorder')); return; }
    if ((el = t.closest('[data-view]'))) { e.preventDefault(); viewing = +el.getAttribute('data-view'); clearNotice(screen('account')); goPanel('view-order'); window.scrollTo(0, 0); return; }
    if ((el = t.closest('[data-panel]'))) {
      e.preventDefault();
      var p = el.getAttribute('data-panel');
      if (p === 'customer-logout') { S.signedIn = false; checkoutMode = null; save(); paintHeader(); show('signin'); notice(screen('signin'), 'message', 'You are signed out. Sign in again with your mobile number, or carry on shopping as a guest.'); return; }
      clearNotice(screen('account')); goPanel(p); return;
    }
    if ((el = t.closest('[data-s]')) && el.getAttribute('data-s')) {
      // Theme links carry real hrefs (/shop/, /cart/ …); following one leaves
      // this single file for a page that does not exist here.
      e.preventDefault();
      var go = el.getAttribute('data-panel-go');
      if (el.closest('.fx-noresults')) { resetFilters(root); return; }
      if (el.closest('.fx-co-signin')) afterSignIn = 'checkout';
      show(el.getAttribute('data-s'), true, el.getAttribute('data-anchor'));
      if (go) goPanel(go);
      return;
    }
    if (root && (el = t.closest('a[href^="#"]')) && el.getAttribute('href').length > 1) {
      var target = root.querySelector(el.getAttribute('href'));
      if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); return; }
    }
    // cart
    if ((el = t.closest('.woocommerce-cart-form a.remove'))) {
      e.preventDefault();
      var slug = el.closest('tr').getAttribute('data-s-line'), name = CATALOG[slug].name;
      setQty(slug, 0); RENDER.cart(screen('cart'));
      notice(screen('cart'), 'message', '“' + esc(name) + '” removed. <a href="#" class="restore-item" data-restore="' + slug + '">Undo?</a>');
      return;
    }
    if ((el = t.closest('[data-restore]'))) { e.preventDefault(); var rsl = el.getAttribute('data-restore'); S.cart.push({ s: rsl, q: 1 }); save(); paintHeader(); clearNotice(screen('cart')); RENDER.cart(screen('cart')); return; }
    if (t.closest('.woocommerce-remove-coupon')) { e.preventDefault(); S.coupon = null; save(); RENDER.cart(screen('cart')); notice(screen('cart'), 'message', 'Coupon has been removed.'); return; }
    // checkout
    if (t.closest('.showcoupon')) { e.preventDefault(); var cf = $('#s-checkout .fx-co-coupon'); cf.hidden = !cf.hidden; if (!cf.hidden) $('#fx-co-code').focus(); return; }
    if (t.closest('.fx-place')) {
      e.preventDefault();
      var errs = validateCheckout();
      if (errs.length) { notice(screen('checkout'), 'error', errs.map(function (x) { return x[1]; })); return; }
      clearNotice(screen('checkout')); $('.woocommerce-NoticeGroup', screen('checkout')).hidden = true;
      if (payMethod() === 'razorpay') {
        $('.fx-rzp__amt', rzp).textContent = rs(totals(cartLines(), S.coupon, 'razorpay').total);
        rzp.hidden = false; $('.fx-rzp-ok', rzp).focus();
      } else placeOrder();
      return;
    }
    if (t.closest('.fx-rzp-ok')) { rzp.hidden = true; placeOrder(); return; }
    if (t.closest('.fx-rzp-cancel') || t === rzp) {
      rzp.hidden = true;
      notice(screen('checkout'), 'error', 'Payment cancelled. Your order has not been placed — try again, or choose cash on delivery.');
      return;
    }
    // account
    if ((el = t.closest('[data-addr-edit]'))) { e.preventDefault(); addrForm(S.addresses.filter(function (a) { return a.id === el.closest('[data-addr]').getAttribute('data-addr'); })[0]); return; }
    if ((el = t.closest('[data-addr-default]'))) {
      var id = el.closest('[data-addr]').getAttribute('data-addr');
      S.addresses.forEach(function (a) { a.def = a.id === id; }); S.chosen = id; checkoutMode = null; save();
      RENDER.account(screen('account'), true); notice(screen('account'), 'message', 'Default address changed. Checkout will fill it in.'); return;
    }
    if ((el = t.closest('[data-addr-delete]'))) {
      var did = el.closest('[data-addr]').getAttribute('data-addr');
      S.addresses = S.addresses.filter(function (a) { return a.id !== did; }); if (S.chosen === did) S.chosen = (S.addresses[0] || {}).id; checkoutMode = null; save();
      RENDER.account(screen('account'), true); notice(screen('account'), 'message', 'Address deleted.'); return;
    }
    if (t.closest('.fx-addr-cancel')) { e.preventDefault(); addrForm(null); return; }
    // sign in
    if (t.closest('.fx-otp-change')) { e.preventDefault(); RENDER.signin(screen('signin')); $('#fx-mobile').focus(); return; }
    if (t.closest('.fx-otp-resend')) { e.preventDefault(); notice(screen('signin'), 'message', 'A new code has been sent.'); return; }
    if (t.closest('.fx-mail')) { e.preventDefault(); say('This opens your email app, addressed to care@letsfoodify.com.'); return; }
    // Anything left that LOOKS clickable says so. Silence reads as broken.
    el = t.closest('a, button, input[type=submit]');
    if (!el || el.closest('.proto') || el.closest('.fx-menu') || el.closest('.fx-drawer-overlay')) return;
    if (el.matches('.fd-tab__button, .fd-sticky-atc button, .tab, summary') || el.type === 'submit' || el.closest('form.cart')) return;
    if (el.hasAttribute('data-note')) { e.preventDefault(); say(el.getAttribute('data-note')); return; }
    e.preventDefault();
    say('Not built into this prototype — on the live site this works.');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeDrawer(); closeMenu(); if (!rzp.hidden) { rzp.hidden = true; } }
  });
  // The tab strip is the reviewer's jump list.
  TABS.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.s); }); });

  // ── forms ────────────────────────────────────────────────────────────────
  document.addEventListener('submit', function (e) {
    var f = e.target, root = f.closest('.fx-shell');
    e.preventDefault();
    if (f.matches('.woocommerce-cart-form')) {
      if (e.submitter && e.submitter.name === 'update_cart') return;
      var code = $('#coupon_code', f); applyCoupon(code.value, root); code.value = ''; RENDER.cart(root); return;
    }
    if (f.matches('.fx-co-coupon')) { applyCoupon($('#fx-co-code').value, root); $('#fx-co-code').value = ''; f.hidden = true; RENDER.checkout(root); return; }
    if (f.matches('.fd-address-choose')) {
      var r = f.querySelector('input[name=fx-addr]:checked'); if (!r) return;
      S.chosen = r.value; save(); fillFrom(chosenAddress());
      notice(root, 'message', 'Delivering to ' + esc(chosenAddress().label || chosenAddress().name) + '. The fields below are filled in.'); return;
    }
    if (f.matches('.fd-address-form')) {
      var g = function (k) { return ($('#fd-' + k).value || '').trim(); };
      var errs = [];
      if (!g('first_name')) errs.push('<strong>Full name</strong> is a required field.');
      if (!/^[6-9]\d{9}$/.test(g('phone').replace(/\D/g, ''))) errs.push('<strong>Mobile number</strong> must be a 10-digit Indian mobile number.');
      if (!g('address_1')) errs.push('<strong>Address</strong> is a required field.');
      if (!/^\d{6}$/.test(g('postcode'))) errs.push('<strong>PIN code</strong> is not a valid PIN code.');
      if (!g('city')) errs.push('<strong>Town / City</strong> is a required field.');
      if (!g('state')) errs.push('<strong>State</strong> is a required field.');
      if (errs.length) return notice(root, 'error', errs);
      var id = f.querySelector('[name=address_id]').value, rec = { id: id || 'a' + Date.now(), label: g('label'), name: g('first_name'), phone: g('phone').replace(/\D/g, ''),
        l1: g('address_1'), l2: g('address_2'), city: g('city'), state: g('state'), pin: g('postcode'), def: $('#fd-is_default').checked || !S.addresses.length };
      if (rec.def) S.addresses.forEach(function (a) { a.def = false; });
      if (id) S.addresses = S.addresses.map(function (a) { return a.id === id ? rec : a; }); else S.addresses.push(rec);
      if (!S.addresses.some(function (a) { return a.def; })) S.addresses[0].def = true;
      checkoutMode = null; save(); addrForm(null); RENDER.account(root, true);
      notice(root, 'message', id ? 'Address updated.' : 'Address saved.'); return;
    }
    if (f.matches('.fx-details')) {
      S.name = $('#fx-d-name').value.trim() || S.name; S.email = $('#fx-d-email').value.trim() || S.email; checkoutMode = null; save();
      notice(root, 'message', 'Account details changed successfully.'); return;
    }
    if (f.matches('.fx-otp-1')) {
      var m = $('#fx-mobile').value.replace(/\D/g, '');
      if (!/^[6-9]\d{9}$/.test(m)) return notice(root, 'error', 'Enter your 10-digit mobile number.');
      clearNotice(root);
      $('.fx-otp-to', root).textContent = m.slice(0, 5) + ' ' + m.slice(5);
      f.hidden = true; $('.fx-otp-2', root).hidden = false; $('#fx-otp').value = ''; $('#fx-otp').focus(); return;
    }
    if (f.matches('.fx-otp-2')) {
      if (!/^\d{6}$/.test($('#fx-otp').value.trim())) return notice(root, 'error', 'Enter the six-digit code from the message.');
      S.signedIn = true; S.mobile = $('#fx-mobile').value.replace(/\D/g, ''); checkoutMode = null; save(); paintHeader();
      var back = afterSignIn; afterSignIn = null;
      show(back || 'account');
      if (!back) notice(screen('account'), 'message', 'Signed in. Your saved addresses and past orders are below.');
      return;
    }
    if (f.matches('.fx-track')) {
      var no = parseInt(($('#fx-t-order').value || '').replace(/\D/g, ''), 10);
      var all = S.orders.slice(); if (S.last && !all.some(function (o) { return o.no === S.last.no; })) all.unshift(S.last);
      var o = all.filter(function (x) { return x.no === no; })[0], out = $('.fx-track-result', root);
      if (!o) { out.innerHTML = '<ul class="woocommerce-error" role="alert"><li>Sorry, the order could not be found. Please contact us if you are having difficulty finding your order details.</li></ul>'; return; }
      out.innerHTML = '<div class="woocommerce-message" role="status">Order <mark>#' + o.no + '</mark> was placed on <mark>' + o.date + '</mark> and is currently <mark>' + o.status + '</mark>.</div>' +
        '<table class="woocommerce-table shop_table order_details"><tbody class="fx-t-lines"></tbody><tfoot class="fx-t-totals"></tfoot></table>';
      orderTable(o, $('.fx-t-lines', out), $('.fx-t-totals', out)); return;
    }
    if (f.matches('.fx-contact') || f.matches('.fx-partner')) {
      f.hidden = true; root.querySelector(f.matches('.fx-contact') ? '.fx-contact-done' : '.fx-partner-done').hidden = false; return;
    }
  });
  document.addEventListener('input', function (e) {
    var t = e.target, root = t.closest('.fx-shell');
    if (t.matches('#s-cart input.qty')) {
      // inc/checkout-flow.php: the quantity applies itself after a 700 ms
      // pause, so typing "12" does not submit "1" first.
      clearTimeout(qtyTimer);
      qtyTimer = setTimeout(function () {
        var q = parseInt(t.value, 10); if (isNaN(q) || q < 0) return;
        var tr = t.closest('tr'); if (!tr) return;
        var slug = tr.getAttribute('data-s-line');
        setQty(slug, q);
        if (q === 0) { RENDER.cart(screen('cart')); notice(screen('cart'), 'message', '“' + esc(CATALOG[slug].name) + '” removed.'); }
        else { $('.product-subtotal', tr).innerHTML = amt(CATALOG[slug].price * q); paintCartTotals(screen('cart')); notice(screen('cart'), 'message', 'Cart updated.'); }
      }, 700);
      return;
    }
    if (t.matches('.wc-block-price-filter__amount')) applyFilters(root);
  });
  document.addEventListener('change', function (e) {
    var t = e.target, root = t.closest('.fx-shell');
    if (t.matches('.wc-block-components-checkbox__input, .wc-block-price-filter__amount')) { applyFilters(root); return; }
    if (t.matches('select.orderby')) { applySort(root, t.value); return; }
    if (t.matches('#s-checkout input[name=fx-pay]')) { RENDER.checkout(screen('checkout')); return; }
  });

  // What inc/product-display.php prints in wp_footer on the shop: filters start
  // closed on a phone. Same line, so the preview closes what the site closes.
  if (matchMedia('(max-width:781px)').matches) { $$('details.fd-filters[open]').forEach(function (d) { d.removeAttribute('open'); }); }

  window.fxShow = show;
  window.fxScreens = SCREENS;
  // For tests/preview-doors.js: put a known cart in place, so the shipping
  // threshold can be tested AT its boundary rather than wherever a click-through
  // happened to leave the cart.
  window.fxSetCart = function (lines, coupon) {
    S.cart = lines.map(function (l) { return { s: l[0], q: l[1] }; }); S.coupon = coupon || null;
    save(); paintHeader(); if (current === 'cart') RENDER.cart(screen('cart'));
  };
  paintHeader();
  var first = location.hash.slice(1);
  show(SCREENS.indexOf(first) >= 0 ? first : 'home', false);
  history.replaceState({ s: current }, '', location.hash || '');
})();
</script>'''


def wire_links(body):
    """Make the mock click-through.

    Every anchor the THEME writes carries a real href — /shop/, /cart/,
    /product-category/express/ — and in a static file they all went nowhere,
    so a reviewer clicking the cart got silence and reasonably read it as
    broken. This maps those hrefs onto the screens this preview has. It changes
    nothing about the theme: it is the mock's own wiring, and the banner says so.
    """
    def screen_for(href):
        if href.startswith("/cart"):        return "cart"
        if href.startswith("/checkout"):    return "checkout"
        if href.startswith("/my-account"):  return "account"
        m = re.match(r"/product/([^/?#]+)", href)
        if m:                               return "p-" + m.group(1)
        m = re.match(r"/product-category/([^/?#]+)", href)
        if m:                               return "combos" if m.group(1) == "combos" else "cat-" + m.group(1)
        if href.startswith(("/shop", "/?orderby")): return "shop"
        m = re.match(r"/([a-z-]+)/?$", href)
        if m and m.group(1) in PAGES:       return "page-" + m.group(1)
        if href in ("/", "") or href.startswith("/#"): return "home"
        return None

    def sub(m):
        head, href = m.group(1), m.group(2)
        if "data-s=" in head:
            return m.group(0)
        s = screen_for(href)
        if not s:
            return m.group(0)
        anchor = f' data-anchor="{href[2:]}"' if href.startswith("/#") else ""
        return f'<a{head}href="{href}" data-s="{s}"{anchor}'

    return re.sub(r'<a([^>]*?)href="([^"]*)"', sub, body)


def loop_rows(related, query):
    """The rows a Product Query loop shows on the screen being rendered."""
    if related:
        # "Complete the meal" is the CURATED pairing (FIXTURE_PAIRINGS), never
        # the product being viewed, topped up by popularity if it was in it.
        picks = [PRODUCTS[i] for i in FIXTURE_PAIRINGS if PRODUCTS[i] is not PDP]
        rest = [r for r in by_popularity(PRODUCTS) if r is not PDP and r not in picks and "soldout" not in r[9]]
        return (picks + rest)[:4]
    if LISTING is not None:
        return LISTING
    # The home page's best-sellers row: orderBy popularity.
    return by_popularity(PRODUCTS)


def render(markup, depth=0):
    global LOOP_N
    if depth > 8:
        return markup
    out, pos = [], 0
    skip_to = 0
    for m in BLOCK.finditer(markup):
        if m.start() < skip_to:
            continue
        out.append(markup[pos:m.start()])
        pos = m.end()
        closing, name, attrs, selfclose = m.group(1), m.group(2), m.group(3), m.group(4)
        a = json.loads(attrs) if attrs else {}
        if closing:
            continue
        # Flex layouts. WordPress turns layout:{type:flex} into a generated
        # container class; the preview never did, so every flex group (the
        # header, the shop toolbar) rendered as a stack — which is how the
        # account icon and the cart came to sit one under the other in the
        # client's review. The style is injected onto the block's own wrapper.
        lay = a.get("layout", {})
        if name == "group" and not selfclose and lay.get("type") == "flex":
            pos_map = {"left": "flex-start", "center": "center", "right": "flex-end",
                       "space-between": "space-between", "stretch": "stretch"}
            jc = pos_map.get(lay.get("justifyContent", "left"), "flex-start")
            wrap = "nowrap" if lay.get("flexWrap") == "nowrap" else "wrap"
            if lay.get("orientation") == "vertical":
                # Core maps justifyContent to the cross axis for a column.
                style = f'display:flex;flex-direction:column;align-items:{jc};'
            else:
                va = {"top": "flex-start", "center": "center", "bottom": "flex-end",
                      "stretch": "stretch"}.get(lay.get("verticalAlignment", "center"), "center")
                style = f'display:flex;flex-direction:row;flex-wrap:{wrap};align-items:{va};justify-content:{jc};'
            # A block's own blockGap, if it sets one. WordPress honours it;
            # this always wrote the global gap, so a header that asked for a
            # tighter row got the default and wrapped a width earlier than the
            # theme said it would.
            bg = (a.get("style", {}).get("spacing", {}) or {}).get("blockGap")
            if isinstance(bg, str) and bg.startswith("var:preset|"):
                bg = "var(--wp--preset--" + bg[len("var:preset|"):].replace("|", "--") + ")"
            style += f'gap:{bg or "var(--wp--style--block-gap)"}'
            tail = markup[pos:]
            tail = re.sub(r'<div\s+class="', f'<div style="{style}" class="is-layout-flex ', tail, count=1)
            markup = markup[:pos] + tail
            # finditer holds the old string; re-scan from here on the patched one.
            return "".join(out) + render(markup[pos:], depth)
        if name == "navigation" and not selfclose:
            # The Navigation block's HTML is generated from its link children,
            # which are self-closing and rendered nothing — so no preview ever
            # showed the menu. On a phone core renders a hamburger; the preview
            # hides the row below 560px and does not draw the overlay.
            cs, ce = find_close(markup, name, pos)
            labels = re.findall(r'wp:navigation-link\s*(\{.*?\})', markup[pos:cs])
            # The link's real url too, not just its label: wire_links needs it
            # to know which screen a nav item belongs to, and WordPress renders
            # the href regardless.
            items = "".join(
                '<a href="{url}" class="wp-block-navigation-item__content">{label}</a>'.format(
                    url=html.escape(json.loads(l).get("url", "#")),
                    label=json.loads(l).get("label", ""))
                for l in labels)
            out.append('<button type="button" class="wp-block-navigation__responsive-container-open fx-burger" '
                       'aria-label="Open menu"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">'
                       '<path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" '
                       'stroke-linecap="round"/></svg></button>')
            out.append(f'<nav class="wp-block-navigation fx-nav">{items}</nav>')
            pos = skip_to = ce
            continue
        # A Product Query loop. WordPress renders post-template's inner blocks
        # once per result; this did not, so the shop screen showed one half-card
        # and the no-results paragraph — which is what the client reviewed.
        if name in ("query", "woocommerce/related-products") and not selfclose:
            LOOP_N = int(a.get("query", {}).get("perPage", 8))
            globals()["LOOP_ROWS"] = loop_rows(name.endswith("related-products"), a.get("query", {}))
            continue
        if name == "post-template" and not selfclose:
            cs, ce = find_close(markup, name, pos)
            inner = markup[pos:cs]
            cols = a.get("layout", {}).get("columnCount", 3)
            cls = a.get("className", "")
            rows = LOOP_ROWS[:min(LOOP_N, 12)]
            items = "".join(loop_card(inner, r, i) for i, r in enumerate(rows))
            out.append(f'<ul class="wp-block-post-template {cls} is-layout-grid columns-{cols} '
                       f'wp-block-post-template-is-layout-grid">{items}</ul>')
            pos = skip_to = ce
            continue
        if name == "query-no-results" and not selfclose:
            # WordPress renders this only for an empty loop. The fixture loop is
            # never empty on load, but filtering can empty it, so the theme's own
            # sentence is kept, hidden, for the page script to show.
            cs, ce = find_close(markup, name, pos)
            out.append('<div class="fx-noresults" hidden>' + render(markup[pos:cs], depth + 1) + '</div>')
            pos = skip_to = ce
            continue
        if name == "query-pagination" and not selfclose:
            # WordPress prints pagination only when there is a second page. No
            # listing here has more than one page of twelve, so there is none —
            # the old fixture drew "1 2 Next" with nothing behind it.
            _cs, ce = find_close(markup, name, pos)
            pos = skip_to = ce
            continue
        if name == "template-part":
            p = os.path.join(THEME, "parts", a.get("slug", "") + ".html")
            tag = a.get("tagName", "div")
            if os.path.exists(p):
                out.append(f"<{tag}>" + render(open(p).read(), depth + 1) + f"</{tag}>")
            else:
                # Say so. A missing part rendering as nothing is a preview that
                # quietly disagrees with the theme, which is the one thing
                # generating it from the theme is meant to prevent.
                out.append(f'<div class="fx-note">missing template part: {a.get("slug","")}</div>')
            continue
        if name == "pattern":
            out.append(render(load_pattern(a.get("slug", "")), depth + 1))
            continue
        if name == "shortcode":
            nxt = markup.find("<!-- /wp:shortcode -->", pos)
            out.append(shortcode(markup[pos:nxt].strip() if nxt > 0 else ""))
            continue
        if selfclose:
            out.append(dynamic(name, a, ""))
            continue
        # container block: WordPress's own HTML follows, keep it
    out.append(markup[pos:])
    txt = "".join(out)
    # strip any comment residue and drop empty shortcode text
    txt = re.sub(r"<!--\s*/?wp:.*?-->", "", txt, flags=re.S)
    txt = re.sub(r"\[foodify_[a-z_]+[^\]]*\]", "", txt)
    return txt


LAYOUT_CSS = """
*{box-sizing:border-box}
body{margin:0;-webkit-font-smoothing:antialiased}
img{max-width:100%}
.wp-block-group,.wp-block-columns{width:100%}
.wp-site-blocks>*{margin:0}
main>.wp-block-group,main>section,main>div{margin:0}
.wp-block-group.has-background,.wp-block-group[style*=background]{width:100%}
/* Flex layouts (injected inline by render()); children are not block-wide. */
.is-layout-flex>*{width:auto;margin:0}
/* Cover: full-bleed, centred inner container that is itself a constrained layout. */
.fx-shell main>.alignfull{max-width:none;margin-left:calc(-1 * var(--wp--preset--spacing--40));margin-right:calc(-1 * var(--wp--preset--spacing--40))}
.wp-block-cover{display:flex;align-items:center;justify-content:center;position:relative;padding-left:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40)}
.wp-block-cover__background{position:absolute;inset:0;z-index:0}
.wp-block-cover__background.has-background-dim-0{opacity:0}
.wp-block-cover__inner-container{position:relative;width:100%}
.wp-block-cover__inner-container>*{max-width:__CONTENT__;margin-left:auto;margin-right:auto}
.wp-block-cover__inner-container>.alignwide{max-width:__WIDE__}
.wp-block-columns{display:flex;gap:var(--wp--preset--spacing--50);flex-wrap:wrap;align-items:flex-start}
/* core columns/style.css: one row above 782px, whatever the bases add up to.
   Without this a 55% + 45% pair plus its gap wrapped, and the hero image sat
   under the title on every desktop render. */
@media (min-width:782px){.wp-block-columns{flex-wrap:nowrap}}
.wp-block-columns.are-vertically-aligned-center{align-items:center}
.wp-block-column{flex:1 1 0;min-width:0}
/* core columns/style.css: a column given a width keeps it (flex-grow:0) —
   without this the 232px filter column grew to half the shop. */
.wp-block-columns>.wp-block-column[style*=flex-basis]{flex-grow:0}
.wp-block-columns.is-not-stacked-on-mobile{flex-wrap:nowrap}
.wp-block-buttons{display:flex;gap:var(--wp--preset--spacing--30);flex-wrap:wrap}
.wp-block-button__link,.wp-element-button{display:inline-flex;align-items:center;justify-content:center;
  background:var(--wp--preset--color--flame-ink);color:var(--wp--preset--color--paper);
  border:1px solid var(--wp--preset--color--flame-ink);border-radius:var(--wp--custom--radius--pill);
  padding:12px 24px;font-weight:600;text-decoration:none;font-size:var(--wp--preset--font-size--base);
  min-height:var(--wp--custom--tap-target);cursor:pointer}
.wp-block-button.is-style-outline .wp-block-button__link{background:transparent;color:var(--wp--preset--color--char);
  border-color:var(--wp--preset--color--line-strong)}
.wp-block-separator{border:0;border-top:1px solid currentColor;opacity:.25}
.wp-block-list.is-style-plain{list-style:none;padding:0;margin:0}
.wp-block-list.is-style-plain li{padding:4px 0}
.wp-block-list.is-style-plain a{color:inherit;text-decoration:none;opacity:.85}
.wp-block-list.is-style-plain a:hover{opacity:1;text-decoration:underline}
/* Post-template grid, as core's post-template/style.css + layout support emit it:
   one column by default, columns-N above 600px, and the max-width:600px rule at
   (0,3,0) that the theme's two-up phone rule has to tie and beat by order. */
.wp-block-post-template{list-style:none;padding:0;margin:0}
.wp-block-post-template.is-layout-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:1.25em}
@media (min-width:600px){
  .wp-block-post-template.is-layout-grid.columns-2{grid-template-columns:repeat(2,minmax(0,1fr))}
  .wp-block-post-template.is-layout-grid.columns-3{grid-template-columns:repeat(3,minmax(0,1fr))}
  .wp-block-post-template.is-layout-grid.columns-4{grid-template-columns:repeat(4,minmax(0,1fr))}
}
@media (max-width:600px){
  .wp-block-post-template-is-layout-grid[class*=columns-]:not(.has-native-responsive-grid){grid-template-columns:1fr}
}
.wp-block-details summary{cursor:pointer}
h1,h2,h3{margin:0 0 .4em}
p{margin:0 0 1em}
/* Constrained layout, as theme.json declares it. :not(.is-layout-flex) is
   load-bearing: core applies these auto margins for CONSTRAINED containers
   only, and a flex container's children get no such thing. Without it the
   header's action group was centred by its own auto margins and stopped 23px
   short of the right edge — a misalignment that existed only in the preview. */
main,header>div,footer>div,
.wp-block-group:not(.is-layout-flex)>.wp-block-group,
.wp-block-group[class*=has-background]:not(.is-layout-flex)>*{margin-left:auto;margin-right:auto}
.fx-shell main>*,header>div>*,footer>div>*{max-width:__CONTENT__;margin-left:auto;margin-right:auto}
.is-layout-flex>*{margin-left:0;margin-right:0}
.fx-shell .alignwide,.fx-shell main>.alignwide{max-width:__WIDE__}
header>div,footer>div{padding-left:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40)}
main{padding-left:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40)}
"""

FIXTURE_CSS = """
.fx-logo{font-size:var(--wp--preset--font-size--xl);font-weight:700;margin:0;letter-spacing:-.02em}
.fx-logo span{color:var(--wp--preset--color--flame-ink)}
.fx-nav{display:flex;gap:var(--wp--preset--spacing--50);flex-wrap:wrap}
.fx-nav a{color:var(--wp--preset--color--char);text-decoration:none;font-size:var(--wp--preset--font-size--base)}
.fx-nav a:hover{color:var(--wp--preset--color--flame-ink)}
.fx-bowl{position:relative;aspect-ratio:1;border-radius:50%;
  background:radial-gradient(circle at 38% 32%,color-mix(in srgb,var(--h) 22%,#fff) 0,transparent 42%),
             radial-gradient(circle at 50% 50%,var(--h) 0,color-mix(in srgb,var(--h) 70%,#2A1B08) 76%);
  box-shadow:inset 0 0 0 6px color-mix(in srgb,var(--h) 28%,#FFFDF8)}
.fx-time{position:absolute;bottom:2%;right:-2%;background:var(--wp--preset--color--char);
  color:var(--wp--preset--color--paper);font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px}
/* Card rhythm (design review, 26 Aug) moved into the THEME on 15 Sep — it was
   fixture CSS here, so the preview showed cards the site would never render.
   style.css now styles the loop's own classes; the bowl below merely stands in
   for the product image inside Woo's image wrapper. */
.wc-block-components-product-image .fx-bowl{width:70%;margin:0 auto}
.product-thumbnail .fx-bowl{width:4rem}
.fx-rating{font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute);line-height:1.5}
.fx-stars{color:var(--wp--preset--color--flame);letter-spacing:-1px}
.fx-price{font-weight:700;margin:auto 0 0;padding-top:var(--wp--preset--spacing--30);
  font-size:var(--wp--preset--font-size--lg);font-variant-numeric:tabular-nums}
.fx-price s{color:var(--wp--preset--color--mute);font-weight:400;margin-right:6px}
.fx-price--lg{font-size:var(--wp--preset--font-size--2xl)}
.fx-off{font-size:12px;background:var(--wp--preset--color--flame-wash);color:var(--wp--preset--color--flame-deep);
  padding:3px 8px;border-radius:3px;vertical-align:middle}
.fx-add{width:100%;margin-top:var(--wp--preset--spacing--30)}
.fx-add--lg{width:auto;flex:1;min-height:52px}
.fx-crumb{font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute)}
.fx-crumb a{color:inherit}
/* The mock's own toast: what a click with no door SAYS instead of doing nothing. */
.fx-toast{position:fixed;left:50%;bottom:calc(var(--wp--preset--spacing--50) + env(safe-area-inset-bottom,0px));transform:translateX(-50%);z-index:120;
  max-width:min(34rem,calc(100vw - 2rem));padding:var(--wp--preset--spacing--30) var(--wp--preset--spacing--50);
  background:var(--wp--preset--color--char);color:var(--wp--preset--color--paper);border-radius:var(--wp--custom--radius--pill);
  font-size:var(--wp--preset--font-size--sm);line-height:1.4;text-align:center;box-shadow:0 8px 24px rgba(0,0,0,.25)}
.fx-toast[hidden]{display:none}
.fx-empty{padding:var(--wp--preset--spacing--60) 0;font-size:var(--wp--preset--font-size--md)}
.fd-cart-back a{color:var(--wp--preset--color--mute)}
.fx-gallery .fx-bowl{max-width:440px;margin:0 auto}
.fx-shotlist{display:grid;grid-template-columns:repeat(2,1fr);gap:var(--wp--preset--spacing--30);margin-top:var(--wp--preset--spacing--40)}
.fx-shot{position:relative;background:var(--wp--preset--color--surface);border:1px dashed var(--wp--preset--color--line-strong);border-radius:var(--wp--custom--radius--card);padding:var(--wp--preset--spacing--30)}
.fx-shot .fx-bowl{opacity:.28;max-width:100%;margin-bottom:var(--wp--preset--spacing--20)}
.fx-shot__n{position:absolute;top:var(--wp--preset--spacing--30);left:var(--wp--preset--spacing--30);width:1.5rem;height:1.5rem;display:grid;place-items:center;border-radius:var(--wp--custom--radius--pill);background:var(--wp--preset--color--char);color:var(--wp--preset--color--paper);font-size:var(--wp--preset--font-size--xs);font-weight:700}
.fx-shot strong{display:block;font-size:var(--wp--preset--font-size--sm)}
.fx-shot em{display:block;font-style:normal;color:var(--wp--preset--color--mute);font-size:var(--wp--preset--font-size--xs);line-height:1.5;margin-top:var(--wp--preset--spacing--10)}
.fx-thumbs{display:flex;gap:10px;justify-content:center;margin-top:18px}
.fx-thumbs .fx-bowl{width:60px}
.fx-ph{text-align:center;font-size:11px;letter-spacing:.06em;text-transform:uppercase;
  color:var(--wp--preset--color--mute);margin-top:12px}
.fx-stock{color:var(--wp--preset--color--leaf-ink);font-weight:600;font-size:var(--wp--preset--font-size--sm)}
.fx-atc{display:flex;gap:12px;margin:var(--wp--preset--spacing--40) 0 0}
.fx-qty{display:flex;align-items:center;border:1px solid var(--wp--preset--color--line-strong);
  border-radius:var(--wp--custom--radius--pill);overflow:hidden}
.fx-qty button{background:none;border:0;padding:0 16px;height:52px;font-size:17px;cursor:pointer;color:inherit}
.fx-spec{border:1px solid var(--wp--preset--color--line);border-radius:var(--wp--custom--radius--card);
  overflow:hidden;margin:var(--wp--preset--spacing--60) 0;background:var(--wp--preset--color--surface)}
.fx-spec h2{font-size:var(--wp--preset--font-size--lg);margin:0;padding:16px 22px 4px}
.fx-spec-note{font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute);padding:0 22px 14px;margin:0}
.fx-spec dl{margin:0;display:grid;grid-template-columns:1fr 1fr}
.fx-spec dl>div{padding:13px 22px;border-top:1px solid var(--wp--preset--color--line)}
.fx-spec dl>div:nth-child(odd){border-right:1px solid var(--wp--preset--color--line)}
.fx-spec dt{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:var(--wp--preset--color--mute);margin-bottom:3px}
.fx-spec dd{margin:0;font-size:var(--wp--preset--font-size--base)}
.fx-veg{color:var(--wp--preset--color--leaf-ink);font-weight:600}
.fx-range{height:4px;background:var(--wp--preset--color--line);border-radius:999px;position:relative;margin-top:8px}
.fx-range::after{content:"";position:absolute;left:10%;right:25%;top:0;bottom:0;
  background:var(--wp--preset--color--flame-ink);border-radius:999px}
.fx-pag{display:flex;gap:10px;justify-content:center;margin-top:var(--wp--preset--spacing--60)}
.fx-pag a{padding:8px 14px;border:1px solid var(--wp--preset--color--line);border-radius:6px;
  font-size:var(--wp--preset--font-size--sm);cursor:pointer}
.fx-pag a.on{background:var(--wp--preset--color--char);color:var(--wp--preset--color--paper);border-color:var(--wp--preset--color--char)}
.fx-checkout{display:grid;grid-template-columns:1.6fr 1fr;gap:var(--wp--preset--spacing--60);align-items:start}
.fx-meta{display:block;font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute)}
.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
.fx-num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap}
.fx-summary{background:var(--wp--preset--color--surface);border:1px solid var(--wp--preset--color--line);
  border-radius:var(--wp--custom--radius--card);padding:var(--wp--preset--spacing--50)}
.fx-summary h2{font-size:var(--wp--preset--font-size--lg);margin:0 0 16px}
.fx-row{display:flex;justify-content:space-between;gap:14px;padding:7px 0;font-size:var(--wp--preset--font-size--base)}
.fx-disc{color:var(--wp--preset--color--leaf-ink)}
.fx-total{border-top:1px solid var(--wp--preset--color--line-strong);margin-top:8px;padding-top:14px;
  font-family:var(--wp--preset--font-family--display);font-size:var(--wp--preset--font-size--lg);font-weight:700}
.fx-field{display:block;margin-bottom:var(--wp--preset--spacing--30)}
.fx-field span{display:block;font-size:var(--wp--preset--font-size--sm);margin-bottom:5px;font-weight:600}
.fx-field input,.fx-field select{width:100%;padding:12px 14px;font-size:16px;
  border:1.5px solid var(--wp--preset--color--line-strong);border-radius:var(--wp--custom--radius--control);
  min-height:var(--wp--custom--tap-target);background:var(--wp--preset--color--surface);color:inherit;font-family:inherit}
.fx-fieldcount{font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--leaf-ink);
  font-weight:600;margin-bottom:var(--wp--preset--spacing--40)}
.fx-pay{margin:18px 0}
.fx-pay label{display:flex;gap:9px;align-items:center;padding:9px 0;font-size:var(--wp--preset--font-size--sm)}
.fx-note{padding:10px 14px;background:var(--wp--preset--color--flame-wash);
  color:var(--wp--preset--color--flame-deep);border-radius:6px;font-size:13px}
@media (max-width:781px){
  /* Core's exact stacking rule and breakpoint — verified against
     wp-includes/blocks/columns/style.css in the local WordPress clone. */
  .wp-block-columns:not(.is-not-stacked-on-mobile)>.wp-block-column{flex-basis:100% !important}
  .wp-block-columns.is-not-stacked-on-mobile>.wp-block-column{flex:1 1 45%}
}
@media (max-width:900px){
  .fx-checkout{grid-template-columns:1fr}
  .fx-spec dl{grid-template-columns:1fr}
  .fx-spec dl>div:nth-child(odd){border-right:0}
}
@media (max-width:560px){
  .wc-block-components-product-image .fx-bowl{width:78%}
  .fx-nav{display:none}
}

/* ── click-through additions ─────────────────────────────────────────────────
   Everything below stands in for WooCommerce's own stylesheet (woocommerce-
   general, wc-blocks-style), which loads on shop pages on the live site and is
   not part of the theme. Tokens only, so it follows the theme's palette. */
/* The page script hides and shows mock-only pieces with the hidden attribute.
   Theme classes that set display would beat it, so it is enforced for the
   mock's own toggles — but NOT for the two the theme hides itself (the sticky
   bar and the tab panels), whose own [hidden] rules the pdp test guards. */
.fx-shell [hidden]:not(.fd-sticky-atc):not(.fd-tab__panel),.fx-drawer-overlay [hidden],.fx-menu[hidden],
.fx-drawer-overlay[hidden],.fx-rzp[hidden]{display:none!important}
html.fx-locked{overflow:hidden}
.woocommerce-message,.woocommerce-info,.woocommerce-error{list-style:none;margin:0 0 var(--wp--preset--spacing--40);
  padding:var(--wp--preset--spacing--30) var(--wp--preset--spacing--40);border-radius:var(--wp--custom--radius--control);
  background:var(--wp--preset--color--surface);border:1px solid var(--wp--preset--color--line);
  border-left:4px solid var(--wp--preset--color--leaf);font-size:var(--wp--preset--font-size--sm);line-height:1.5}
.woocommerce-info{border-left-color:var(--wp--preset--color--kraft)}
.woocommerce-error{border-left-color:var(--wp--preset--color--danger)}
.woocommerce-error li{margin:2px 0}
.woocommerce-notices-wrapper:empty,.woocommerce-NoticeGroup:empty{display:none}
.woocommerce-cart .woocommerce>.wc-empty-cart-message{grid-column:1/-1}
.return-to-shop{margin-top:var(--wp--preset--spacing--40)}
.woocommerce .input-text,.woocommerce select,.fx-shell .form-row .input-text,.fx-shell .form-row select{
  width:100%;min-height:var(--wp--custom--tap-target);padding:0 var(--wp--preset--spacing--30);font:inherit;font-size:16px;
  border:1.5px solid var(--wp--preset--color--line-strong);border-radius:var(--wp--custom--radius--control);
  background:var(--wp--preset--color--surface);color:inherit}
.fx-shell textarea.input-text{padding:var(--wp--preset--spacing--20) var(--wp--preset--spacing--30)}
.fx-shell .form-row{margin:0 0 var(--wp--preset--spacing--30)}
.fx-shell .form-row label{display:block;font-weight:600;font-size:var(--wp--preset--font-size--sm);margin-bottom:var(--wp--preset--spacing--10)}
.fx-shell .form-row label input[type=checkbox]{margin-right:var(--wp--preset--spacing--10)}
.fx-shell abbr.required{color:var(--wp--preset--color--danger);text-decoration:none}
.fx-field input[aria-invalid=true],.fx-field select[aria-invalid=true]{border-color:var(--wp--preset--color--danger)}
.woocommerce-cart td.actions>[name=update_cart]{display:none}
.fx-meta-inline{color:var(--wp--preset--color--mute);font-weight:400}
.fx-co-chooser:empty{display:none}
.fx-co-chooser{margin-bottom:var(--wp--preset--spacing--40)}
.fx-co-signin .woocommerce-info{font-size:var(--wp--preset--font-size--sm)}
/* WooCommerce's thank-you page and order tables. */
.woocommerce-order-overview{list-style:none;padding:0;margin:var(--wp--preset--spacing--40) 0;display:flex;flex-wrap:wrap;
  gap:var(--wp--preset--spacing--40) var(--wp--preset--spacing--60);font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute)}
.woocommerce-order-overview strong{display:block;color:var(--wp--preset--color--char);font-size:var(--wp--preset--font-size--md);font-variant-numeric:tabular-nums}
.woocommerce-notice--success{font-family:var(--wp--preset--font-family--display);font-size:var(--wp--preset--font-size--xl);
  color:var(--wp--preset--color--leaf-ink);margin:0}
.woocommerce-order-details,.woocommerce-customer-details{margin-top:var(--wp--preset--spacing--60)}
.woocommerce-order-details__title,.woocommerce-column__title{font-size:var(--wp--preset--font-size--lg)}
.fx-shell table.order_details{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
.fx-shell table.order_details th,.fx-shell table.order_details td{text-align:left;padding:var(--wp--preset--spacing--20) var(--wp--preset--spacing--30) var(--wp--preset--spacing--20) 0;
  border-bottom:1px solid var(--wp--preset--color--line);vertical-align:top}
.fx-shell table.order_details td:last-child,.fx-shell table.order_details th:last-child{text-align:right;padding-right:0}
.fx-shell table.order_details thead th{font-size:var(--wp--preset--font-size--xs);letter-spacing:.1em;text-transform:uppercase;color:var(--wp--preset--color--mute)}
.fx-shell table.order_details tfoot th{font-weight:400;color:var(--wp--preset--color--mute)}
.fx-shell table.order_details a{color:inherit}
.fx-shell address{font-style:normal;line-height:1.6;padding:var(--wp--preset--spacing--40);border:1px solid var(--wp--preset--color--line);
  border-radius:var(--wp--custom--radius--card);background:var(--wp--preset--color--surface);max-width:24rem}
.woocommerce-customer-details--phone{margin:var(--wp--preset--spacing--20) 0 0}
.fx-o-actions{display:flex;flex-wrap:wrap;align-items:center;gap:var(--wp--preset--spacing--40);margin-top:var(--wp--preset--spacing--60)}
.fx-shell mark{background:var(--wp--preset--color--flame-wash);color:inherit;padding:0 .2em;border-radius:3px}
.woocommerce-MyAccount-navigation-link.is-active a{color:var(--wp--preset--color--flame-ink);font-weight:700}
.woocommerce-EditAccountForm,.fd-address-form{max-width:32rem}
/* The listing's price filter: WooCommerce's two amount inputs and its bar. */
.wc-block-price-filter__controls{display:flex;gap:var(--wp--preset--spacing--20)}
.wc-block-price-filter__controls label{display:flex;align-items:center;gap:4px;font-size:var(--wp--preset--font-size--sm)}
.wc-block-price-filter__amount{width:5.5rem;min-height:var(--wp--custom--tap-target);padding:0 var(--wp--preset--spacing--20);font:inherit;
  border:1px solid var(--wp--preset--color--line-strong);border-radius:var(--wp--custom--radius--control);background:var(--wp--preset--color--surface);color:inherit}
.fx-range::after{content:none}
.fx-range i{position:absolute;left:0;right:0;top:0;bottom:0;background:var(--wp--preset--color--flame-ink);border-radius:999px}
.fx-noresults{padding:var(--wp--preset--spacing--60) 0}
.wc-block-components-checkbox label{display:flex;align-items:center;gap:var(--wp--preset--spacing--20);min-height:var(--wp--custom--tap-target);cursor:pointer}
.wc-block-components-checkbox__input{width:18px;height:18px;accent-color:var(--wp--preset--color--flame-ink)}
.woocommerce-noreviews{color:var(--wp--preset--color--mute)}
.fx-stock.out-of-stock{color:var(--wp--preset--color--danger)}
.fx-pending{margin:var(--wp--preset--spacing--50) 0;line-height:1.55}
.fx-shell .wp-block-post-content ul{padding-left:1.2em;line-height:1.7}
.fx-track{display:grid;grid-template-columns:1fr 1fr;gap:0 var(--wp--preset--spacing--40);max-width:36rem;margin:var(--wp--preset--spacing--40) 0}
.fx-track .form-row:last-child{grid-column:1/-1}
.fx-track-result{max-width:36rem}
/* The mini-cart drawer (addToCartBehaviour: open_drawer). */
.fx-drawer-overlay{position:fixed;inset:0;z-index:130;background:rgba(20,32,28,.45);display:flex;justify-content:flex-end}
.wc-block-mini-cart__drawer{width:min(420px,100vw);height:100%;background:var(--wp--preset--color--paper);color:var(--wp--preset--color--char);
  display:flex;flex-direction:column;box-shadow:-8px 0 32px rgba(0,0,0,.18);font-family:var(--wp--preset--font-family--ui);overflow:auto;
  padding:0 var(--wp--preset--spacing--40) env(safe-area-inset-bottom,0px)}
.wc-block-mini-cart__title-row{display:flex;align-items:center;justify-content:space-between;padding:var(--wp--preset--spacing--40) 0;border-bottom:1px solid var(--wp--preset--color--line)}
.wc-block-mini-cart__title{font-family:var(--wp--preset--font-family--display);font-size:var(--wp--preset--font-size--xl);margin:0}
.fx-dr-count{font-family:var(--wp--preset--font-family--ui);font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute);font-weight:400}
.wc-block-components-drawer__close,.wp-block-navigation__responsive-container-close{background:none;border:0;font-size:28px;line-height:1;cursor:pointer;color:inherit;
  min-width:var(--wp--custom--tap-target);min-height:var(--wp--custom--tap-target)}
.fx-dr-added{margin:var(--wp--preset--spacing--30) 0 0;padding:var(--wp--preset--spacing--20) var(--wp--preset--spacing--30);border-radius:var(--wp--custom--radius--control);
  background:var(--wp--preset--color--leaf-wash);color:var(--wp--preset--color--leaf-ink);font-size:var(--wp--preset--font-size--sm);font-weight:600}
.fx-dr-added:empty{display:none}
.wc-block-mini-cart__items{list-style:none;margin:0;padding:0;flex:1}
.wc-block-cart-items__row{display:grid;grid-template-columns:3.5rem 1fr auto;gap:var(--wp--preset--spacing--30);align-items:start;
  padding:var(--wp--preset--spacing--40) 0;border-bottom:1px solid var(--wp--preset--color--line)}
.wc-block-cart-items__row .fx-bowl{width:3.5rem}
.fx-dr-main{display:flex;flex-direction:column;align-items:flex-start;gap:var(--wp--preset--spacing--20)}
.wc-block-components-product-name{color:inherit;font-weight:600;text-decoration:none}
.wc-block-components-quantity-selector{display:inline-flex;align-items:center;border:1px solid var(--wp--preset--color--line-strong);border-radius:var(--wp--custom--radius--pill)}
.wc-block-components-quantity-selector button{background:none;border:0;cursor:pointer;color:inherit;font-size:18px;min-width:var(--wp--custom--tap-target);min-height:36px}
.fx-dr-q{min-width:1.5em;text-align:center;font-variant-numeric:tabular-nums}
.wc-block-cart-item__remove-link{font-size:var(--wp--preset--font-size--xs);color:var(--wp--preset--color--mute)}
.wc-block-mini-cart__footer{padding:var(--wp--preset--spacing--40) 0}
.wc-block-mini-cart__footer-subtotal{display:flex;justify-content:space-between;font-weight:700;font-size:var(--wp--preset--font-size--md)}
.fx-dr-ship{font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute);margin:var(--wp--preset--spacing--20) 0 var(--wp--preset--spacing--40)}
.wc-block-mini-cart__footer-actions{display:grid;grid-template-columns:1fr 1fr;gap:var(--wp--preset--spacing--20)}
.wc-block-mini-cart__footer-actions .wp-element-button{padding-left:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30)}
.fx-outline{background:transparent;color:var(--wp--preset--color--char);border-color:var(--wp--preset--color--line-strong)}
.wc-block-mini-cart__empty-cart-wrapper{padding:var(--wp--preset--spacing--70) 0;text-align:center}
/* The phone menu: core's overlay. */
.fx-burger{display:none;background:none;border:0;color:inherit;cursor:pointer;padding:0;
  min-width:var(--wp--custom--tap-target);min-height:var(--wp--custom--tap-target);align-items:center;justify-content:center}
.fx-menu{position:fixed;inset:0;z-index:130;background:var(--wp--preset--color--paper);color:var(--wp--preset--color--char);
  padding:calc(var(--wp--preset--spacing--40) + env(safe-area-inset-top,0px)) var(--wp--preset--spacing--40) var(--wp--preset--spacing--40);overflow:auto}
.fx-menu .wp-block-navigation__responsive-container-close{position:absolute;top:calc(var(--wp--preset--spacing--30) + env(safe-area-inset-top,0px));right:var(--wp--preset--spacing--30)}
.fx-menu__links{display:flex;flex-direction:column;margin-top:var(--wp--preset--spacing--70)}
.fx-menu__links a{display:flex;align-items:center;min-height:52px;border-bottom:1px solid var(--wp--preset--color--line);
  color:inherit;text-decoration:none;font-family:var(--wp--preset--font-family--display);font-size:var(--wp--preset--font-size--xl)}
/* Razorpay's window, stood in for. */
.fx-rzp{position:fixed;inset:0;z-index:140;background:rgba(20,32,28,.55);display:grid;place-items:center;padding:var(--wp--preset--spacing--40)}
.fx-rzp__box{width:min(380px,100%);background:var(--wp--preset--color--surface);color:var(--wp--preset--color--char);border-radius:var(--wp--custom--radius--card);
  padding:var(--wp--preset--spacing--50);display:flex;flex-direction:column;gap:var(--wp--preset--spacing--30);font-family:var(--wp--preset--font-family--ui)}
.fx-rzp__box h2{margin:0;font-size:var(--wp--preset--font-size--xl)}
.fx-rzp__brand{margin:0;font-size:var(--wp--preset--font-size--xs);letter-spacing:.08em;text-transform:uppercase;color:var(--wp--preset--color--mute)}
.fx-rzp__amt{margin:0;font-size:var(--wp--preset--font-size--2xl);font-weight:700;font-variant-numeric:tabular-nums}
.fx-rzp__note{margin:0;font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute)}
.fx-rzp .fd-secondary{align-self:center}
@media (max-width:599px){
  .fx-burger{display:inline-flex}
  .fx-nav{display:none}
  .fx-track{grid-template-columns:1fr}
}
"""

# ── the information pages ────────────────────────────────────────────────────
# The footer links to eight pages that are WordPress pages, not WooCommerce
# templates: they render through templates/page.html with their content. What
# the build already decides (the shipping rules in bootstrap.sh, COD in
# payments.php) is stated as built. What the CLIENT has to write — returns,
# privacy, terms, the founder story — is NOT invented: the page says what it
# will carry and who supplies it. A made-up returns policy on a food site is a
# promise, not a placeholder.
def pending(what):
    return f'<div class="fx-note fx-pending"><strong>To come from Foodify.</strong> {what}</div>'


PAGES = {
    "track-order": ("Track your order", '''
<p class="has-md-font-size">Enter the order number from your confirmation message and the mobile number you ordered with.</p>
<form class="woocommerce-form woocommerce-form-track-order track_order fx-track">
<p class="form-row form-row-first"><label for="fx-t-order">Order number</label><input class="input-text" type="text" id="fx-t-order" placeholder="For example 1194" inputmode="numeric"></p>
<p class="form-row form-row-last"><label for="fx-t-mobile">Mobile number</label><input class="input-text" type="text" id="fx-t-mobile" placeholder="Mobile number used at checkout" inputmode="numeric"></p>
<p class="form-row"><button type="submit" class="wp-element-button">Track</button></p>
</form>
<div class="fx-track-result" aria-live="polite"></div>'''),
    "shipping": ("Shipping", f'''
<h2>What delivery costs</h2>
<ul><li><strong>Free</strong> on orders of ₹599 or more, counted after any partner or creator code.</li>
<li><strong>₹59</strong> below that. The cart shows how far you are from free delivery as you shop.</li>
<li>GST is included in every price. No handling, packing or platform fee is added at checkout.</li></ul>
<h2>Paying</h2>
<ul><li><strong>Pay now</strong> by UPI, card or netbanking through Razorpay, and ₹25 comes off the order.</li>
<li><strong>Cash on delivery</strong> is available on every order, with no extra charge.</li></ul>
<h2>When it arrives</h2>
<p>{FIXTURE_DELIVERY}.</p>
{pending("The dispatch promise above is a placeholder until Foodify confirms it — same-day NCR dispatch is not yet agreed. The courier partner and the serviceable PIN codes are set in the shop's settings.")}'''),
    "returns": ("Returns &amp; refunds", '''
<p class="has-md-font-size">Food cannot be resold once it has left us, so this page has to say exactly what happens when something goes wrong.</p>
''' + pending("The returns and refund policy. It needs to cover: a pack that arrives damaged or leaking, a wrong item, a missing item, how to report it (with a photo, within how many days), and how long a refund takes for UPI and for cash-on-delivery orders.")),
    "contact-us": ("Contact us", '''
<p class="has-md-font-size">Questions about an order, a product or a bulk enquiry.</p>
<p><strong>Email</strong> · <a href="mailto:care@letsfoodify.com" class="fx-mail">care@letsfoodify.com</a><br>
<strong>Marketed by</strong> · AVAC Ventures, Noida 201304</p>
''' + pending("A phone or WhatsApp number, and the hours someone answers it.")),
    "our-story": ("Our story", '''
<p class="has-md-font-size">Instant, preservative-free, home-style Indian food — dal, idli, khichdi, chai and chutney — that comes back to life with hot water, a few minutes on the stove, or a stir of cold water.</p>
<p>It is made for the places a kitchen is not: a train berth, a hostel room, a hotel kettle, an office desk, a parent who cooks less than they used to. Every pack keeps for nine to twelve months without preservatives.</p>
''' + pending("The founder's story, in Nalin's words, and photographs of the kitchen and the team.")),
    "privacy-policy": ("Privacy policy", pending("The privacy policy, from Foodify's legal adviser. It has to describe the mobile-number sign-in, what Razorpay and the courier receive, and the analytics the site runs.")),
    "terms": ("Terms of service", pending("The terms of sale, from Foodify's legal adviser.")),
    "partner": ("Partner with us", '''
<p class="has-md-font-size">Gyms, cafés, colleges, travel desks and creators: give your people a Foodify code, and every order they place with it is credited to you.</p>
<ul><li>Your code takes money off your customer's order at checkout.</li>
<li>Every order placed with it is recorded against your name — once, even if a second code is entered.</li>
<li>You see what your code has sold.</li></ul>
<p>To ask for a code, write to <a href="mailto:care@letsfoodify.com" class="fx-mail">care@letsfoodify.com</a>.</p>
''' + pending("Whether partners earn a commission, and how much. That decides whether payouts are built or bought (SCOPE.md question 8).")),
}


COMBOS_INTRO = ("A week of dinners in one box. Every pack inside is the same meal sold on its own — the box is the "
                "convenience, and the saving is against what those same packs cost bought one by one.")


def screen_specs():
    """Every screen in the click-through: (id, tab label or None, template, setup).

    One screen per product and per category, rendered by the theme's own
    templates, so following a link lands on THAT page — not the one product
    page every card used to open."""
    specs = [("home", "Home", "front-page.html", {}),
             ("shop", "Shop", "archive-product.html",
              dict(listing=by_popularity(PRODUCTS),
                   cat=("Shop all", "Every meal, chutney and tea in the range. Filter by how much effort you have to give."))),
             ]
    for sid, _slug, rng, title, intro in CATEGORIES:
        specs.append((sid, None, "taxonomy-product_cat.html",
                      dict(listing=by_popularity([p for p in PRODUCTS if p[1] == rng]), cat=(title, intro))))
    specs.append(("combos", "Combos", "taxonomy-product_cat.html",
                  dict(listing=by_popularity(COMBOS), cat=("Combos", COMBOS_INTRO))))
    for p in PRODUCTS + COMBOS:
        label = "Product" if p is PRODUCTS[0] else ("Combo pack" if p is COMBOS[0] else None)
        specs.append((screen_of(p), label, "single-product.html", dict(pdp=p)))
    specs += [("cart", "Cart", "page-cart.html", {}),
              ("checkout", "Checkout", "page-checkout.html", {}),
              ("order", "Order placed", "page-checkout.html", {}),
              ("account", "Account", "page-my-account.html", {}),
              ("signin", "Sign in", "page-my-account.html", dict(signed_out=True))]
    for slug, (title, content) in PAGES.items():
        specs.append(("page-" + slug, "Info page" if slug == "shipping" else None, "page.html",
                      dict(page=(html.unescape(title), content))))
    specs.append(("notfound", "404", "404.html", {}))
    return specs


# The body classes WordPress/WooCommerce put on each kind of page. The theme
# scopes the cart and account rules by them, so the preview shell carries them.
def body_class(sid, setup):
    if setup.get("pdp"):
        return "single-product woocommerce"
    if sid == "shop":
        return "post-type-archive-product woocommerce"
    if setup.get("listing"):
        return "tax-product_cat woocommerce"
    return {"cart": "woocommerce-cart woocommerce-page",
            "checkout": "woocommerce-checkout woocommerce-page",
            "order": "woocommerce-checkout woocommerce-page woocommerce-order-received",
            # WordPress adds logged-in for a signed-in visitor
            "account": "woocommerce-account woocommerce-page logged-in",
            "signin": "woocommerce-account woocommerce-page"}.get(sid, "page" if sid.startswith("page-") else "")


def php(expr, *args):
    """Evaluate one PHP expression against the theme's PURE halves and return its
    output. The card badges, yield lines, SAVE badge and the whole tabbed PDP
    body are rendered by the theme's own functions with fixture data — the
    preview cannot drift from the theme because it does not reimplement it.
    `args` are passed as JSON in $argv and decoded into $a[0], $a[1], ..."""
    import subprocess
    inc = os.path.join(THEME, "inc")
    prelude = (
        "define('ABSPATH', __DIR__);"
        f"require '{inc}/product-spec.php'; require '{inc}/business-profile.php';"
        f"require '{inc}/product-combo.php';"
        "$a = array_map(fn($j) => json_decode($j, true), array_slice($argv, 1));"
    )
    r = subprocess.run(["php", "-r", prelude + expr + ";"] + [json.dumps(x) for x in args],
                       capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit(f"php bridge failed for {expr}:\n{r.stderr}")
    return r.stdout


def tagline_from_bootstrap():
    src = open(os.path.join(KIT, "scripts", "bootstrap.sh")).read()
    m = re.search(r'wp option update blogdescription "([^"]+)"', src)
    return m.group(1) if m else "TAGLINE NOT CONFIGURED"


TAGLINE = tagline_from_bootstrap()
DELIVERY = php("echo foodify_delivery_promise()")
CLAIM_MINUTES = php("echo foodify_claim_minutes()")
PDP_SCRIPT = php("echo foodify_pdp_script()")


def main():
    global PDP, LISTING, CAT, PAGE, SIGNED_IN, SCREEN_ID
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    layout = (LAYOUT_CSS
              .replace("__CONTENT__", S["layout"]["contentSize"])
              .replace("__WIDE__", S["layout"]["wideSize"]))
    theme_css = open(os.path.join(THEME, "style.css")).read()
    theme_css = theme_css.split("*/", 1)[1] if "*/" in theme_css else theme_css

    panels, tabs, ids = [], [], []
    for sid, label, fn, setup in screen_specs():
        path = os.path.join(THEME, "templates", fn)
        PDP, LISTING, PAGE = setup.get("pdp"), setup.get("listing"), setup.get("page")
        CAT = setup.get("cat")
        SIGNED_IN = not setup.get("signed_out")
        SCREEN_ID = sid
        body = wire_links(render(open(path).read()))
        # The theme substitutes these from foodify_content_tokens(). The preview
        # must resolve the SAME tokens or it drifts from the site — which is
        # exactly how <!--FOODIFY_YEAR--> reached the live footer as an invisible
        # comment while the preview showed a year the site could never render.
        #
        # FSSAI and the delivery promise are FIXTURE values (see the block by
        # PRODUCTS). The theme itself still prints NOT CONFIGURED for an unset
        # licence and still promises only what bootstrap.sh configures — the
        # dummies live in this renderer, are named in the banner above, and
        # fixture-leak-test.py fails the build if one reaches the theme.
        body = body.replace("<!--FOODIFY_YEAR-->", str(datetime.date.today().year))
        body = body.replace("<!--FOODIFY_FSSAI-->", FIXTURE_FSSAI)
        # The tagline is the WordPress site description, which bootstrap.sh sets.
        # Read from that line, so the preview's hero says what the site's will.
        body = body.replace("<!--FOODIFY_TAGLINE-->", TAGLINE)
        body = body.replace("<!--FOODIFY_DELIVERY-->", FIXTURE_DELIVERY)
        body = body.replace("<!--FOODIFY_CLAIM_MINUTES-->", CLAIM_MINUTES)
        if PDP and "soldout" not in PDP[9]:
            # What inc/product-display.php prints in wp_footer on a product page:
            # the sticky bar, hidden until the real button scrolls away. The
            # theme's one script runs once per product screen (see MOCK_SCRIPT).
            unit = "box" if combo_of(PDP) else "pack"
            body += (f'<div class="fd-sticky-atc" data-unit="{PDP[3]}" hidden><div class="fd-sticky-atc__price">'
                     f'<strong class="fd-sticky-atc__total">₹{PDP[3]}</strong><span class="fd-sticky-atc__qty">1 {unit}</span></div>'
                     '<button type="button" class="wp-element-button">Add to cart</button></div>')
        if label:
            tabs.append(f'<button class="tab" role="tab" aria-selected="{str(sid == "home").lower()}" data-s="{sid}">{label}</button>')
        ids.append(sid)
        panels.append(f'<div class="fx-shell {body_class(sid, setup)}" id="s-{sid}"{"" if sid == "home" else " hidden"}>{body}</div>')

    fonts = ('<link rel="preconnect" href="https://fonts.googleapis.com">'
             '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
             '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?'
             'family=Fraunces:opsz,wght@9..144,300..700&'
             'family=Instrument+Sans:ital,wght@0,400..700;1,400..700&display=swap">')

    script = (MOCK_SCRIPT.replace('__SCREENS__', json.dumps(ids))
              .replace('__CATALOG__', json.dumps(catalog_json(), ensure_ascii=False))
              .replace('__PDP_SCRIPT__', json.dumps(PDP_SCRIPT).replace("</", "<\\/")))

    doc = f"""<title>Foodify Storefront</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
{fonts}
<style>
{token_css()}
{styles_css()}
{layout}
{FIXTURE_CSS}
{theme_css}
.proto{{position:sticky;top:0;z-index:99;background:#14201C;color:#F5F2EA;border-bottom:1px solid #23332D}}
.proto-in{{max-width:1240px;margin:0 auto;padding:9px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}}
.proto-tag{{font-size:10px;letter-spacing:.1em;text-transform:uppercase;opacity:.6;
  font-family:var(--wp--preset--font-family--ui)}}
.tabs{{display:flex;gap:3px;flex-wrap:wrap}}
.proto-reset{{margin-left:auto;background:none;border:1px solid #3A4C45;border-radius:999px;color:#C9D6D0;
  font:600 12px/1 var(--wp--preset--font-family--ui);padding:7px 12px;cursor:pointer;min-height:32px}}
.proto-reset:hover{{color:#F5F2EA;border-color:#9DAEA6}}
@media (max-width:781px){{
  .proto-in{{flex-wrap:nowrap;padding:7px 12px;gap:10px}}
  .proto-tag{{display:none}}
  .tabs{{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}}
  .tabs::-webkit-scrollbar{{display:none}}
  .tab{{flex:none}}
  .proto-reset{{margin-left:0;flex:none}}
  .banner{{font-size:11px;padding:8px 12px}}
}}
.tab{{background:none;border:1px solid transparent;border-radius:999px;padding:5px 13px;
  font-size:12.5px;font-weight:600;color:#9DAEA6;cursor:pointer;font-family:var(--wp--preset--font-family--ui)}}
.tab[aria-selected=true]{{background:var(--wp--preset--color--flame);border-color:var(--wp--preset--color--flame);color:#241703}}
.tab:hover{{color:#F5F2EA}}
.banner{{background:var(--wp--preset--color--flame-wash);color:#6B4A0C;padding:10px 20px;
  font-size:13px;border-bottom:1px solid #EBD9B4;font-family:var(--wp--preset--font-family--ui)}}
.banner b{{font-family:var(--wp--preset--font-family--ui)}}
.banner summary{{cursor:pointer;font-weight:600;display:inline}}
.banner details p{{margin:.5em 0 0;max-width:72rem}}
</style>
<div class="proto"><div class="proto-in">
<span class="proto-tag">Foodify · clickable prototype · rendered from the theme</span>
<div class="tabs" role="tablist" aria-label="Jump to a page">{''.join(tabs)}</div>
<button type="button" class="proto-reset">Reset</button>
</div></div>
<div class="banner"><b>A working prototype of the new letsfoodify.com</b>, generated from the real WordPress theme.
Browse, filter, open any product, add to the cart, check out, and find the order in your account. In the cart, try the
partner code <b>NALIN10</b>.
<details><summary>What is real and what is not</summary>
<p><b>Real:</b> the layout, type, colours and every component come from the theme the developer installs. The cart
follows the shop's actual rules — free delivery from ₹599 after codes, ₹59 below it, ₹25 off for paying online,
cash on delivery on every order.</p>
<p><b>Stood in for:</b> Razorpay's payment window (a simulated payment), the SMS that carries the sign-in code (any
six digits work), and food photography (coloured bowls, pending the shoot). Nothing here is sent anywhere; the
prototype remembers your cart and orders in this browser only, and <b>Reset</b> clears them.</p>
<p><b>Invented for the prototype, awaiting your word:</b> the FSSAI licence number ({FIXTURE_FSSAI}), the
same-day NCR dispatch promise, the "Complete the meal" pairing, the combo boxes with their contents and
prices, each product's price, ingredients, taste notes, cooked weight, FAQ and reviews, and the partner code.
The live site prints <i>NOT CONFIGURED</i> for a licence it has not been given — nothing here is
live until you confirm it.</p></details></div>
{''.join(panels)}
{script}"""
    open(OUT, "w").write(doc)
    print(f"rendered {len(ids)} screens -> {OUT}  ({len(doc):,} bytes)")


if __name__ == "__main__":
    main()
