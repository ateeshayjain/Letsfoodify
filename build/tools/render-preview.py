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
PRODUCTS = [
    ("Express Dal Fry",        "Express",       "6 MIN", 185, 225, "#D9822B", "4.7", 84, 2, {"bestseller", "jain"}),
    ("Idli Sambhar",           "Express",       "6 MIN", 195, 225, "#E0A03C", "4.8", 62, 2, {"bestseller"}),
    ("Express Dal Khichdi",    "Express",       "6 MIN", 185, 0,   "#CE9126", "4.5", 41, 2, set()),
    ("Aloo ka Mazaa",          "Express",       "5 MIN", 100, 0,   "#C98A34", "4.4", 28, 1, {"soldout"}),
    ("Super Millet Idli",      "Express",       "6 MIN", 210, 0,   "#B98D3E", "0",   0,  2, {"new"}),   # nothing on the review page may outrun the claim
    ("Pav Bhaji",              "Express",       "6 MIN", 185, 0,   "#C2571F", "4.7", 57, 2, set()),
    ("Coconut Red Chutney",    "Flavors",       "1 MIN", 240, 0,   "#4E8B66", "4.5", 19, 4, {"jain"}),
    ("Masala Chai",            "Hot & Fresh",   "3 MIN", 375, 0,   "#A6603A", "4.8", 71, 1, set()),
]


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
    save = php("echo foodify_save_badge((float)$a[0], (float)$a[1])", was, price) if was else ""
    if save:
        inner += f' <span class="fd-save">{html.escape(save)}</span>'
    return f'<div class="wc-block-components-product-price wp-block-woocommerce-product-price {size_cls}">{inner}</div>'


def card_yield(p):
    line = php("echo foodify_card_yield($a[0])", {"servings": str(p[8]), "prep_minutes": p[2].split()[0]})
    return f'<p class="fd-yield">{html.escape(line)}</p>' if line else ""


# The product page's fixture data, in the shape the theme's own functions take.
# Allergens and the FSSAI licence are deliberately EMPTY: the page must show
# "Not provided" for both, and the preview showing a plausible value is how
# the placeholder licence got into four templates in the first place.
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


def loop_card(inner_markup, p, i):
    """One <li> of a Product Query loop — WordPress's post-template item."""
    global CUR
    CUR = p
    body = render(inner_markup, 1)
    CUR = None
    return f'<li class="wp-block-post post-{100 + i} product type-product status-publish">{body}</li>'


REVIEWS = [
    ("Carried six Express packs to Leh. Hotel kettle, six minutes, actual dal chawal at 11,000 feet.", "Rohit M.", 5),
    ("My mother is 78 and cooks less now. The Jain dal fry means she eats a proper lunch without standing at the stove.", "Anjali S.", 5),
    ("Sceptical about dried chutney. The coconut one is genuinely close to what my grandmother grinds.", "Deepa R.", 4),
]


def shortcode(code):
    if "google_reviews" in code:
        cards = "".join(
            f'<figure class="fd-review"><blockquote>{html.escape(q)}</blockquote>'
            f'<figcaption><span class="fd-stars">{"★"*s}{"☆"*(5-s)}</span> · {html.escape(who)} · <span class="fd-verified">Google review</span></figcaption></figure>'
            for q, who, s in REVIEWS)
        return f'<div class="fd-reviews">{cards}</div>'
    if "free_shipping_progress" in code:
        return ('<div class="fd-shipping-progress">'
                '<p class="fd-ship"><strong>₹106</strong> away from free shipping.</p>'
                '<div class="fd-progress" role="progressbar" aria-valuenow="82" aria-valuemin="0" '
                'aria-valuemax="100" aria-label="Progress toward free shipping">'
                '<i style="width:82%"></i></div></div>')
    return f'<div class="fx-note">shortcode: {html.escape(code)}</div>'


# WooCommerce and core dynamic blocks -> fixture markup.
def dynamic(name, attrs, inner):
    a = attrs or {}
    if name == "site-title":
        return '<p class="fx-logo has-display-font-family">lets<span>foodify</span></p>'
    if name == "post-title":
        lvl = a.get("level", 2)
        if CUR and a.get("__woocommerceNamespace"):
            return (f'<h{lvl} class="wp-block-post-title {cls_for(a)}"><a href="#">{html.escape(CUR[0])}</a></h{lvl}>'
                    + card_yield(CUR))
        return f'<h{lvl} class="fx-posttitle {cls_for(a)}">Express Dal Fry</h{lvl}>'
    if name == "query-title":
        return f'<h1 class="{cls_for(a)}">Foodify Express</h1>'
    if name == "term-description":
        return ('<p class="' + cls_for(a) + '">Add hot water, wait six minutes, eat. The Express range is '
                'built for trains, hostels and hotel kettles — fourteen home-style meals that need no '
                'cooking at all, with a nine to twelve month shelf life and no preservatives.</p>')
    if name == "post-excerpt":
        return ('<p class="' + cls_for(a) + '">Yellow moong and toor, tempered with cumin, tomato and a '
                'little ghee. Dried slowly so the tempering survives.</p>')
    if name == "post-content":
        return '<p>Page content renders here.</p>'
    if name == "navigation":
        items = ["Express", "Hot &amp; Fresh", "Flavors", "Combos", "How it works"]
        return '<nav class="fx-nav">' + "".join(f'<a href="#">{i}</a>' for i in items) + "</nav>"
    if name == "woocommerce/mini-cart":
        # WooCommerce's Mini-Cart button markup: amount, then icon + count badge.
        # The theme reorders them with CSS; the classes are Woo's, not fixtures.
        return ('<div class="wc-block-mini-cart wp-block-woocommerce-mini-cart">'
                '<button class="wc-block-mini-cart__button" aria-label="3 items in cart, total price of ₹620">'
                '<span class="wc-block-mini-cart__amount">₹620</span>'
                '<span class="wc-block-mini-cart__quantity-badge">'
                '<svg class="wc-block-mini-cart__icon" viewBox="0 0 24 24" width="24" height="24" fill="none" '
                'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                '<path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 8H6.2"/>'
                '<circle cx="9.5" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/></svg>'
                '<span class="wc-block-mini-cart__badge">3</span></span></button></div>')
    if name == "woocommerce/customer-account":
        return ('<div class="wc-block-customer-account wp-block-woocommerce-customer-account">'
                '<a class="wc-block-customer-account__account-link" href="#" aria-label="Account">'
                '<svg class="wc-block-customer-account__account-icon" viewBox="0 0 24 24" fill="none" '
                'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">'
                '<circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/></svg></a></div>')
    if name == "woocommerce/product-image" and CUR:
        badges = php("echo foodify_render_badges(foodify_card_badges($a[0]))", badge_state(CUR))
        return f'<div class="wc-block-components-product-image"><a href="#">{BOWL.format(hue=CUR[5], time=CUR[2])}</a>{badges}</div>'
    if name == "woocommerce/product-button" and CUR:
        label = "Sold out" if "soldout" in CUR[9] else "Add to cart"
        return ('<div class="wp-block-button wc-block-components-product-button">'
                f'<a href="#" class="wp-block-button__link wp-element-button add_to_cart_button">{label}</a></div>')
    if name == "woocommerce/breadcrumbs":
        return '<p class="fx-crumb"><a href="#">Home</a> / <a href="#">Express</a> / Dal Fry</p>'
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
        thumbs = "".join(
            f'<div class="fx-shot">{BOWL.format(hue=h, time="")}'
            f'<span class="fx-shot__n">{n}</span><strong>{t}</strong><em>{d}</em></div>'
            for (n, t, d), h in zip(shots, ("#D9822B", "#C67A2A", "#B9702B", "#E0A03C")))
        return (
            '<div class="fx-gallery">'
            + BOWL.format(hue="#D9822B", time="6 MIN")
            + '<div class="fx-shotlist">' + thumbs + '</div>'
            + '<p class="fx-ph">No photography supplied yet. Four shots per product, in this '
              'order — shot 1 is what Google Shopping uses, so it is the one that cannot wait.</p>'
            + '</div>'
        )
    if name == "woocommerce/product-rating":
        if CUR:
            if CUR[7] == 0:
                return ""   # the theme hides the row at zero reviews (product-display.php)
            return (f'<div class="wc-block-components-product-rating">{stars(CUR[6])} '
                    f'<span class="fd-rating-count">{CUR[7]} reviews</span></div>')
        return f'<div class="fx-rating">{stars("4.7")} <span class="fx-rc">84 reviews</span></div>'
    if name == "woocommerce/product-price":
        if CUR:
            return price_html(CUR, cls_for(a))
        # The product page: the price, then the yield strip the theme appends.
        parts = json.loads(php("echo json_encode(foodify_yield_parts($a[0]))", PDP_VALUES))
        strip = "".join(f"<span>{html.escape(x)}</span>" for x in parts)
        return price_html(PRODUCTS[0], "fx-price--lg " + cls_for(a)) + f'<p class="fd-yield-strip">{strip}</p>'
    if name == "woocommerce/product-stock-indicator":
        return '<p class="fx-stock">In stock</p>'
    if name == "woocommerce/add-to-cart-form":
        # WooCommerce's form: a number input for quantity and the real button.
        # A <form class="cart"> so the theme's sticky bar can watch it here too.
        return ('<form class="cart fx-atc"><div class="quantity"><label class="screen-reader-text" for="fx-qty">Quantity</label>'
                '<input type="number" id="fx-qty" class="input-text qty text" value="1" min="1" step="1" inputmode="numeric"></div>'
                '<button type="button" class="single_add_to_cart_button wp-element-button fx-add fx-add--lg">Add to cart</button></form>')
    if name == "woocommerce/product-details":
        # The theme renders the tabbed body from inc/product-spec.php on
        # woocommerce_after_single_product_summary — BEFORE this block in the
        # page body. The preview calls the SAME renderer with fixture data.
        return php(
            "echo foodify_render_pdp_body(['about'=>$a[0],'taste'=>foodify_taste_chips($a[1]),"
            "'groups'=>foodify_spec_model($a[2]),'nutrition'=>foodify_nutrition_rows($a[3]),"
            "'steps'=>foodify_prep_steps('hot water','6'),'faq'=>foodify_faq_pairs($a[4])])",
            PDP_ABOUT, "Savoury, Mildly spicy, Tangy", PDP_VALUES, PDP_NUTRITION, PDP_FAQ)

    if name == "woocommerce/product-reviews":
        cards = "".join(
            f'<figure class="fd-review"><blockquote>{q}</blockquote>'
            f'<figcaption><span class="fd-stars">{"★"*5}</span> · {who} · '
            f'<span class="fd-verified">Verified purchase</span></figcaption></figure>'
            for q, who in [
                ("Took it to site for a week. Six minutes and it is actually dal, not soup.", "Rakesh M."),
                ("My mother approved, which I did not expect.", "Sneha T."),
            ])
        return f'<div class="fd-reviews">{cards}</div>' 
    if name == "woocommerce/catalog-sorting":
        # WooCommerce's ordering form; option labels are the theme's shortened set.
        opts = "".join(f'<option{" selected" if i == 1 else ""}>{o}</option>' for i, o in enumerate(
            ["Featured", "Most popular", "Best rated", "Newest", "Price: low to high", "Price: high to low"]))
        return ('<div class="wp-block-woocommerce-catalog-sorting"><form class="woocommerce-ordering" method="get">'
                f'<select name="orderby" class="orderby" aria-label="Shop order">{opts}</select></form></div>')
    if name == "woocommerce/product-results-count":
        return '<p class="woocommerce-result-count wp-block-woocommerce-product-results-count">Showing 1–12 of 14 results</p>'
    if name == "woocommerce/price-filter":
        return ('<div class="wp-block-woocommerce-price-filter"><h3 class="wc-block-price-filter__title">Price</h3>'
                '<div class="wc-block-price-filter__range-input-wrapper"><div class="fx-range"></div></div>'
                '<div class="wc-block-price-filter__controls"><span>₹100</span><span>₹1,800</span></div></div>')
    if name == "woocommerce/attribute-filter":
        # Woo's checkbox-list markup. In WordPress this block hydrates client-side;
        # the server sends the same classes with the list rendered from the
        # attribute the theme resolves from `foodifyAttribute` at render time.
        opts = {"prep":    [("Just add hot water", 14), ("Stir with drinking water", 7), ("Requires cooking", 11)],
                "dietary": [("Vegan", 9), ("Gluten free", 12), ("Jain", 6), ("Millet based", 5), ("High protein", 7)]}
        h = a.get("heading", "Filter")
        lis = "".join(
            '<li class="wc-block-checkbox-list__item"><div class="wc-block-components-checkbox"><label>'
            '<input type="checkbox" class="wc-block-components-checkbox__input">'
            f'<span class="wc-block-components-checkbox__label">{o} '
            f'<span class="wc-filter-element-label-list-count">({n})</span></span></label></div></li>'
            for o, n in opts.get(a.get("foodifyAttribute", ""), []))
        return (f'<div class="wp-block-woocommerce-attribute-filter"><h3 class="wc-block-attribute-filter__title">{h}</h3>'
                f'<ul class="wc-block-checkbox-list">{lis}</ul></div>')
    if name == "woocommerce/classic-shortcode":
        sc = a.get("shortcode", "cart")
        if sc == "my_account":
            return my_account(SIGNED_IN)
        return cart_or_checkout(sc)
    return inner or ""


def cls_for(a):
    out = []
    if "fontSize" in a:
        out.append("has-" + re.sub(r"(\d)([a-z])", r"\1-\2", a["fontSize"]) + "-font-size")
    if "textColor" in a:
        out.append(f"has-{a['textColor']}-color has-text-color")
    if "fontFamily" in a:
        out.append(f"has-{a['fontFamily']}-font-family")
    return " ".join(out)


def cart_or_checkout(which):
    if which == "cart":
        # [woocommerce_cart]'s own markup — shop_table_responsive with data-title
        # cells, the quantity as WooCommerce's number input, the totals table.
        # The theme styles these classes; nothing here is a fixture class except
        # the bowl standing in for the thumbnail image.
        amt = lambda v: f'<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">₹</span>{v}</bdi></span>'
        lines = "".join(
            '<tr class="woocommerce-cart-form__cart-item cart_item">'
            f'<td class="product-remove"><a href="#" class="remove" aria-label="Remove {html.escape(p[0])} from cart">×</a></td>'
            f'<td class="product-thumbnail"><a href="#">{BOWL.format(hue=p[5], time="")}</a></td>'
            f'<td class="product-name" data-title="Product"><a href="#">{html.escape(p[0])}</a></td>'
            f'<td class="product-price" data-title="Price">{amt(p[3])}</td>'
            '<td class="product-quantity" data-title="Quantity"><div class="quantity">'
            f'<label class="screen-reader-text" for="qty-{i}">Quantity</label>'
            f'<input type="number" id="qty-{i}" class="input-text qty text" value="1" min="0" step="1" inputmode="numeric"></div></td>'
            f'<td class="product-subtotal" data-title="Subtotal">{amt(p[3])}</td></tr>'
            for i, p in enumerate(PRODUCTS[:3]))
        return f'''<div class="woocommerce">
<form class="woocommerce-cart-form">
<table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents">
<thead><tr><th class="product-remove"><span class="screen-reader-text">Remove item</span></th>
<th class="product-thumbnail"><span class="screen-reader-text">Thumbnail image</span></th>
<th class="product-name">Product</th><th class="product-price">Price</th>
<th class="product-quantity">Quantity</th><th class="product-subtotal">Subtotal</th></tr></thead>
<tbody>{lines}
<tr><td class="actions" colspan="6"><div class="coupon"><label for="coupon_code" class="screen-reader-text">Coupon:</label>
<input type="text" id="coupon_code" class="input-text" placeholder="Partner or creator code">
<button type="button" class="button wp-element-button">Apply</button></div>
<button type="button" class="button wp-element-button" disabled>Update cart</button></td></tr>
</tbody></table></form>
<div class="cart-collaterals"><div class="cart_totals"><h2>Cart totals</h2>
<table class="shop_table shop_table_responsive"><tbody>
<tr class="cart-subtotal"><th>Subtotal</th><td data-title="Subtotal">{amt(620)}</td></tr>
<tr class="cart-discount coupon-nalin10"><th>Coupon NALIN10</th><td data-title="Coupon">−{amt(62)}</td></tr>
<tr class="woocommerce-shipping-totals shipping"><th>Shipping</th><td data-title="Shipping">Free shipping</td></tr>
<tr class="order-total"><th>Total</th><td data-title="Total">{amt(558)}</td></tr>
</tbody></table>
<p class="fd-cart-promise is-estimate">GST is included. Shipping is confirmed from your PIN code at the next step. Nothing else is added.</p>
<div class="wc-proceed-to-checkout"><a href="#" class="checkout-button button alt wc-forward wp-element-button">Proceed to checkout</a></div>
</div></div></div>'''
    # Rendered as a RETURNING customer sees it: the chooser above, and every
    # field already carrying the default address. That is WP-05's acceptance
    # ("zero address fields typed") made visible rather than asserted. A guest
    # sees the same nine fields empty, with no chooser.
    fields = [("Mobile number", "98••• ••210", 1), ("Email", "you@example.com", 1),
              ("Full name", "Ateeshay Jain", 1), ("PIN code", "201304", 1), ("City", "Noida", 1),
              ("State", "Uttar Pradesh", 1), ("Address", "N-7011 Parx Laureate", 1),
              ("Address line 2 (optional)", "Sector 108", 1), ("Order notes (optional)", "", 0)]
    inputs = "".join(
        f'<label class="fx-field"><span>{html.escape(l)}</span>'
        + (f'<select><option>{html.escape(v)}</option></select>' if l == "State"
           else f'<input {"value" if filled and v else "placeholder"}="{html.escape(v)}">')
        + '</label>'
        for l, v, filled in fields)
    return f'''<div class="fx-checkout">
<div><div class="woocommerce-form-coupon-toggle"><div class="woocommerce-info">Have a code from a
partner or a creator? <a href="#" class="showcoupon">Enter it here</a></div></div>
{address_chooser()}
<p class="fx-fieldcount">Nine fields for a first order — the audited site asked twenty-five.
A returning customer types none of them.</p>{inputs}</div>
<aside class="fx-summary"><h2>Your order</h2>
<div class="fx-row"><span>3 items</span><span class="fx-num">₹620</span></div>
<div class="fx-row fx-disc"><span>Prepaid saving</span><span class="fx-num">−₹25</span></div>
<div class="fx-row"><span>Shipping</span><span class="fx-num">Free</span></div>
<div class="fx-row fx-total"><span>Total</span><span class="fx-num">₹595</span></div>
<p class="fd-cart-promise is-promise">GST is included. This is the final amount — no handling,
convenience or platform fee is added.</p>
<div class="fx-pay"><label><input type="radio" checked> Pay now
<span class="fd-pay-saving">Save ₹25</span></label>
<label><input type="radio"> Cash on delivery</label></div>
<button class="wp-element-button fx-add fx-add--lg">Place order</button></aside></div>'''


SIGNED_IN = True   # flipped per screen by main()

ADDRESSES = [
    # (label, name, phone, line1, line2, city, state, pin, is_default)
    ("Home", "Ateeshay Jain", "98••• ••210", "N-7011 Parx Laureate", "Sector 108",
     "Noida", "UP", "201304", True),
    ("Office", "Ateeshay Jain", "98••• ••210", "Tower C, 9th floor", "Sector 16",
     "Noida", "UP", "201301", False),
]


def address_book():
    """The fd-address markup inc/address-book.php emits. Same classes, same shape."""
    cards = []
    for label, name, phone, l1, l2, city, state, pin, is_def in ADDRESSES:
        badge = '<span class="fd-address__default">Default</span>' if is_def else ''
        verbs = '<a class="fd-secondary" href="#">Edit</a>'
        if not is_def:
            verbs += ('<form class="fd-address__verb"><button>Make default</button></form>'
                      '<form class="fd-address__verb"><button class="fd-danger">Delete</button></form>')
        cards.append(
            f'<li class="fd-address{" is-default" if is_def else ""}">'
            f'<div class="fd-address__head"><span class="fd-address__label">{label}</span>{badge}</div>'
            f'<p class="fd-address__body">{name} · {phone}<br>{l1}, {l2}, {city}, {pin} {state}</p>'
            f'<div class="fd-address__actions">{verbs}</div></li>')
    return "<ul class=\"fd-address-list\">" + "".join(cards) + "</ul>"


def address_chooser():
    """The checkout chooser. Only renders for a signed-in customer with 2+ saved."""
    opts = "".join(
        f'<label class="fd-address-choose__option">'
        f'<input type="radio" name="fx-addr"{" checked" if is_def else ""}>'
        f'<span class="fd-address-choose__label">{label}</span>'
        f'<span class="fd-address-choose__body">{l1}, {l2}, {city}, {pin}</span></label>'
        for label, _n, _p, l1, l2, city, _s, pin, is_def in ADDRESSES)
    return ('<form class="fd-address-choose"><fieldset><legend>Deliver to</legend>'
            + opts
            + '<button type="button" class="wp-element-button">Use this address</button>'
            + '<a class="fd-address-choose__manage" href="#">Manage saved addresses</a>'
            + '</fieldset></form>')


ORDERS = [
    ("#1194", "22 Aug 2026", "Delivered", "₹805", "3 items"),
    ("#1121", "31 Jul 2026", "Delivered", "₹560", "2 items"),
    ("#1088", "09 Jul 2026", "Delivered", "₹1,240", "6 items"),
]


def my_account(signed_in):
    """WooCommerce's my-account markup, as the classic shortcode emits it."""
    if not signed_in:
        # WooCommerce's own login form. WP-05's OTP plugin replaces exactly this,
        # which is why the theme does not render a form of its own.
        return '''<div class="woocommerce"><div class="fd-signin">
<h2>Sign in</h2>
<p class="fd-account-lead">Your saved addresses come back automatically, so checkout is four taps.</p>
<label class="fx-field"><span>Mobile number</span><input placeholder="98••• ••210"></label>
<button class="wp-element-button">Send code</button>
<p class="fd-account-lead" style="margin-top:1rem">No password. We send a six-digit code to your phone.</p>
<div class="fx-note">WP-05 · week 11 — mobile-OTP login replaces this form once the SMS gateway
is DLT-registered. The theme renders WooCommerce\u2019s form so the OTP plugin can take it over
without a template change. Guest checkout stays the default path either way.</div>
</div></div>'''

    nav = "".join(
        f'<li class="woocommerce-MyAccount-navigation-link{" is-active" if i == 0 else ""}">'
        f'<a href="#">{label}</a></li>'
        for i, label in enumerate(["Orders &amp; reorder", "Saved addresses", "Your details", "Log out"]))

    rows = "".join(
        f'<tr><td><strong>{no}</strong></td><td>{date}</td><td>{status}</td>'
        f'<td class="woocommerce-orders-table__cell-order-total">{total}<span class="fx-meta">{items}</span></td>'
        f'<td class="woocommerce-orders-table__cell-order-actions">'
        f'<button class="wp-element-button fd-reorder">Reorder</button>'
        f'<button class="wp-element-button fd-secondary">Details</button></td></tr>'
        for no, date, status, total, items in ORDERS)

    return f'''<div class="woocommerce">
<nav class="woocommerce-MyAccount-navigation"><ul>{nav}</ul></nav>
<div class="woocommerce-MyAccount-content">
<p class="fd-account-lead">Your past orders are one tap from being your next one.</p>
<table class="woocommerce-orders-table"><thead><tr>
<th>Order</th><th>Date</th><th>Status</th><th>Total</th><th>&nbsp;</th>
</tr></thead><tbody>{rows}</tbody></table>

<h2 style="margin-top:2.5rem">Saved addresses</h2>
<p class="fd-account-lead">Save the places you order to. Checkout fills in your default address on its
own — you only choose when it is going somewhere else.</p>
{address_book()}
<h3 class="fd-address-form__title">Add an address</h3>
<div class="fx-note">WooCommerce stores one billing and one shipping address. WP-05 needs several with a
default flag, so the book is the theme\u2019s own model — and the default is mirrored back into
WooCommerce\u2019s fields on every save, so checkout, the admin screens, Razorpay and the courier
payload all keep reading the meta they have always read.</div>
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
            style += 'gap:var(--wp--style--block-gap)'
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
            items = "".join(f'<a href="#" class="wp-block-navigation-item__content">'
                            f'{json.loads(l).get("label", "")}</a>' for l in labels)
            out.append(f'<nav class="wp-block-navigation fx-nav">{items}</nav>')
            pos = skip_to = ce
            continue
        # A Product Query loop. WordPress renders post-template's inner blocks
        # once per result; this did not, so the shop screen showed one half-card
        # and the no-results paragraph — which is what the client reviewed.
        if name in ("query", "woocommerce/related-products") and not selfclose:
            LOOP_N = int(a.get("query", {}).get("perPage", 8))
            continue
        if name == "post-template" and not selfclose:
            cs, ce = find_close(markup, name, pos)
            inner = markup[pos:cs]
            cols = a.get("layout", {}).get("columnCount", 3)
            cls = a.get("className", "")
            items = "".join(loop_card(inner, PRODUCTS[i % len(PRODUCTS)], i) for i in range(min(LOOP_N, 12)))
            out.append(f'<ul class="wp-block-post-template {cls} is-layout-grid columns-{cols} '
                       f'wp-block-post-template-is-layout-grid">{items}</ul>')
            pos = skip_to = ce
            continue
        if name == "query-no-results" and not selfclose:
            # Only rendered by WordPress when the loop is empty. The fixture loop never is.
            _cs, ce = find_close(markup, name, pos)
            pos = skip_to = ce
            continue
        if name == "query-pagination" and not selfclose:
            _cs, ce = find_close(markup, name, pos)
            out.append('<nav class="fx-pag"><a>1</a><a class="on">2</a><a>Next →</a></nav>')
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
/* constrained layout, as theme.json declares it */
main,header>div,footer>div,.wp-block-group>.wp-block-group,
.wp-block-group[class*=has-background]>*{margin-left:auto;margin-right:auto}
.fx-shell main>*,header>div>*,footer>div>*{max-width:__CONTENT__;margin-left:auto;margin-right:auto}
.fx-shell .alignwide,.fx-shell main>.alignwide{max-width:__WIDE__}
header>div,footer>div{padding-left:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40)}
main{padding-left:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40)}
"""

FIXTURE_CSS = """
.fx-logo{font-size:var(--wp--preset--font-size--xl);font-weight:700;margin:0;letter-spacing:-.02em}
.fx-logo span{color:var(--wp--preset--color--flame-ink)}
.fx-nav{display:flex;gap:22px;flex-wrap:wrap}
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
.fd-signin{max-width:26rem}
.fd-signin h2{font-size:var(--wp--preset--font-size--2xl);margin:0 0 .3em}
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
"""

SCREENS = [
    ("home",     "Home",             "front-page.html"),
    ("shop",     "Category / shop",  "archive-product.html"),
    ("product",  "Product",          "single-product.html"),
    ("cart",     "Cart",             "page-cart.html"),
    ("checkout", "Checkout",         "page-checkout.html"),
    ("account",  "Account",          "page-my-account.html"),
    ("signin",   "Sign in",          "page-my-account.html"),
    ("notfound", "404",              "404.html"),
]

# The body classes WordPress/WooCommerce put on each of these pages. The theme
# scopes the cart and account rules by them, so the preview shell carries them.
BODY_CLASS = {
    "shop": "post-type-archive-product woocommerce",
    "cart": "woocommerce-cart woocommerce-page",
    "checkout": "woocommerce-checkout woocommerce-page",
    "account": "woocommerce-account woocommerce-page",
    "signin": "woocommerce-account woocommerce-page",
}


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
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    layout = (LAYOUT_CSS
              .replace("__CONTENT__", S["layout"]["contentSize"])
              .replace("__WIDE__", S["layout"]["wideSize"]))
    theme_css = open(os.path.join(THEME, "style.css")).read()
    theme_css = theme_css.split("*/", 1)[1] if "*/" in theme_css else theme_css

    panels, tabs = [], []
    for i, (sid, label, fn) in enumerate(SCREENS):
        path = os.path.join(THEME, "templates", fn)
        globals()["SIGNED_IN"] = (sid != "signin")
        body = render(open(path).read())
        # The theme substitutes these from foodify_content_tokens(). The preview
        # must resolve the SAME tokens or it drifts from the site — which is
        # exactly how <!--FOODIFY_YEAR--> reached the live footer as an invisible
        # comment while the preview showed a year the site could never render.
        #
        # FSSAI shows NOT CONFIGURED here on purpose: the client has not supplied
        # the licence number, and the preview showing a plausible one is how the
        # dummy got into four templates in the first place.
        body = body.replace("<!--FOODIFY_YEAR-->", str(datetime.date.today().year))
        body = body.replace("<!--FOODIFY_FSSAI-->", "NOT CONFIGURED")
        # The tagline is the WordPress site description, which bootstrap.sh sets.
        # Read from that line, so the preview's hero says what the site's will.
        body = body.replace("<!--FOODIFY_TAGLINE-->", TAGLINE)
        body = body.replace("<!--FOODIFY_DELIVERY-->", DELIVERY)
        body = body.replace("<!--FOODIFY_CLAIM_MINUTES-->", CLAIM_MINUTES)
        if sid == "product":
            # What inc/product-display.php prints in wp_footer on a product page:
            # the sticky bar (hidden until the real button scrolls away) and the
            # page's one script, embedded verbatim from the theme.
            body += ('<div class="fd-sticky-atc" data-unit="185" hidden><div class="fd-sticky-atc__price">'
                     '<strong class="fd-sticky-atc__total">₹185</strong><span class="fd-sticky-atc__qty">1 pack</span></div>'
                     '<button type="button" class="wp-element-button">Add to cart</button></div>'
                     f'<script>{PDP_SCRIPT}</script>')
        tabs.append(f'<button class="tab" role="tab" aria-selected="{str(i == 0).lower()}" data-s="{sid}">{label}</button>')
        panels.append(f'<div class="fx-shell {BODY_CLASS.get(sid, "")}" id="s-{sid}"{"" if i == 0 else " hidden"}>{body}</div>')

    fonts = ('<link rel="preconnect" href="https://fonts.googleapis.com">'
             '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
             '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?'
             'family=Fraunces:opsz,wght@9..144,300..700&'
             'family=Instrument+Sans:ital,wght@0,400..700;1,400..700&display=swap">')

    doc = f"""<title>Foodify Storefront</title>
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
@media (max-width:781px){{
  .proto-in{{flex-wrap:nowrap;padding:7px 12px;gap:10px}}
  .proto-tag{{display:none}}
  .tabs{{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}}
  .tabs::-webkit-scrollbar{{display:none}}
  .tab{{flex:none}}
  .banner{{font-size:11px;padding:8px 12px}}
}}
.tab{{background:none;border:1px solid transparent;border-radius:999px;padding:5px 13px;
  font-size:12.5px;font-weight:600;color:#9DAEA6;cursor:pointer;font-family:var(--wp--preset--font-family--ui)}}
.tab[aria-selected=true]{{background:var(--wp--preset--color--flame);border-color:var(--wp--preset--color--flame);color:#241703}}
.tab:hover{{color:#F5F2EA}}
.banner{{background:var(--wp--preset--color--flame-wash);color:#6B4A0C;padding:12px 20px;
  font-size:13px;border-bottom:1px solid #EBD9B4;font-family:var(--wp--preset--font-family--ui)}}
.banner b{{font-family:var(--wp--preset--font-family--ui)}}
</style>
<div class="proto"><div class="proto-in">
<span class="proto-tag">Foodify · WP-03 · rendered from the theme</span>
<div class="tabs" role="tablist">{''.join(tabs)}</div>
</div></div>
<div class="banner"><b>This page is generated from the real theme</b> — <code>theme.json</code> tokens,
<code>templates/*.html</code>, <code>parts/*.html</code> and <code>patterns/*.php</code>. Edit the theme and this
changes with it. Food imagery is a CSS placeholder pending the week-3 shoot; product data is fixture data.
It approximates WordPress's block renderer — judge layout, type and hierarchy here, behaviour on staging.</div>
{''.join(panels)}
<script>
document.addEventListener('click', function (e) {{
  var t = e.target.closest('[data-s]'); if (!t) return;
  {json.dumps([s[0] for s in SCREENS])}.forEach(function (s) {{
    document.getElementById('s-' + s).hidden = (s !== t.dataset.s);
  }});
  document.querySelectorAll('.tab').forEach(function (b) {{
    b.setAttribute('aria-selected', String(b.dataset.s === t.dataset.s));
  }});
  window.scrollTo(0, 0);
}});
// What inc/product-display.php prints in wp_footer on the shop: filters start
// closed on a phone. Same line, so the preview closes what the site closes.
if(matchMedia('(max-width:781px)').matches){{document.querySelectorAll('details.fd-filters[open]').forEach(function(d){{d.removeAttribute('open')}})}}
</script>"""
    open(OUT, "w").write(doc)
    print(f"rendered {len(SCREENS)} screens -> {OUT}  ({len(doc):,} bytes)")


if __name__ == "__main__":
    main()
