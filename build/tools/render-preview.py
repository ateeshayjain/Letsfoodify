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
        out.append(f".has-{c['slug']}-border-color{{border-color:var(--wp--preset--color--{c['slug']});border-style:solid}}")
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
PRODUCTS = [
    ("Express Dal Fry",        "Express",       "6 MIN", 185, 210, "#D9822B", "4.7", 84),
    ("Idli Sambhar",           "Express",       "6 MIN", 195, 225, "#E0A03C", "4.8", 62),
    ("Express Dal Khichdi",    "Express",       "6 MIN", 185, 0,   "#CE9126", "4.5", 41),
    ("Aloo ka Mazaa",          "Express",       "5 MIN", 100, 0,   "#C98A34", "4.4", 28),
    ("Super Millet Idli",      "Express",       "7 MIN", 210, 0,   "#B98D3E", "4.6", 33),
    ("Pav Bhaji",              "Express",       "6 MIN", 185, 0,   "#C2571F", "4.7", 57),
    ("Coconut Red Chutney",    "Flavors",       "1 MIN", 240, 0,   "#4E8B66", "4.5", 19),
    ("Masala Chai",            "Hot & Fresh",   "3 MIN", 375, 0,   "#A6603A", "4.8", 71),
]
BOWL = ('<div class="fx-bowl" style="--h:{hue}"><span class="fx-time">{time}</span></div>')


def stars(r):
    full = int(float(r))
    return '<span class="fx-stars">' + "★" * full + "☆" * (5 - full) + f'</span> <span class="fx-rc">{r}</span>'


def product_card(p):
    name, rng, time, price, was, hue, rating, count = p
    sale = f'<s>₹{was}</s> ' if was else ""
    return f'''<article class="fx-card">
  <div class="fx-media">{BOWL.format(hue=hue, time=time)}<span class="fx-chip fx-chip--{rng.split()[0].lower()}">{html.escape(rng)}</span></div>
  <h3 class="fx-name">{html.escape(name)}</h3>
  <div class="fx-rating">{stars(rating)} <span class="fx-rc">({count})</span></div>
  <p class="fx-price">{sale}₹{price}</p>
  <button class="wp-element-button fx-add">Add to bag</button>
</article>'''


def product_grid(n, cols=4):
    items = "".join(product_card(PRODUCTS[i % len(PRODUCTS)]) for i in range(n))
    return f'<div class="fx-grid" style="--cols:{cols}">{items}</div>'


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
        return '<button class="fx-bag">Bag <span class="fx-bagn">3</span></button>'
    if name == "woocommerce/customer-account":
        return '<button class="fx-acct" aria-label="Account">◍</button>'
    if name == "woocommerce/breadcrumbs":
        return '<p class="fx-crumb"><a href="#">Home</a> / <a href="#">Express</a> / Dal Fry</p>'
    if name == "woocommerce/product-image-gallery":
        return ('<div class="fx-gallery">' + BOWL.format(hue="#D9822B", time="6 MIN") +
                '<div class="fx-thumbs">' + "".join(
                    BOWL.format(hue=h, time="") for h in ("#D9822B", "#C67A2A", "#B9702B", "#E0A03C")) +
                '</div><p class="fx-ph">Photography placeholder — pack shot, prepared bowl, ingredients, scale</p></div>')
    if name == "woocommerce/product-rating":
        return f'<div class="fx-rating">{stars("4.7")} <span class="fx-rc">84 reviews</span></div>'
    if name == "woocommerce/product-price":
        return f'<p class="fx-price fx-price--lg {cls_for(a)}"><s>₹210</s> ₹185 <span class="fx-off">12% off</span></p>'
    if name == "woocommerce/product-stock-indicator":
        return '<p class="fx-stock">In stock</p>'
    if name == "woocommerce/add-to-cart-form":
        return ('<div class="fx-atc"><div class="fx-qty"><button>−</button><span>1</span><button>+</button></div>'
                '<button class="wp-element-button fx-add fx-add--lg">Add to bag · ₹185</button></div>')
    if name == "woocommerce/product-details":
        rows = [("Net quantity", "80 g"), ("Servings", "2"), ("Shelf life", "12 months"),
                ("Best before", "14 Aug 2027"), ("Veg / non-veg", '<span class="fx-veg">● Vegetarian</span>'),
                ("Allergens", "Milk (ghee)"), ("Country of origin", "India"),
                ("FSSAI licence", "10012345678901"), ("Marketed by", "AVAC Ventures, Noida 201304"),
                ("Consumer care", "care@letsfoodify.com")]
        cells = "".join(f"<div><dt>{k}</dt><dd>{v}</dd></div>" for k, v in rows)
        return (f'<section class="fx-spec"><h2>Pack &amp; label</h2>'
                f'<p class="fx-spec-note">Structured fields — the same data feeds the Google product feed.</p>'
                f'<dl>{cells}</dl></section>')
    if name in ("woocommerce/product-best-sellers", "woocommerce/related-products"):
        cols = a.get("columns", 4); rows_ = a.get("rows", 1)
        return product_grid(cols * rows_, cols)
    if name == "woocommerce/catalog-sorting":
        return '<div class="fx-sort">Sort: <strong>Bestselling</strong></div>'
    if name == "woocommerce/product-results-count":
        return '<div class="fx-count">Showing 1–12 of 14</div>'
    if name.startswith("woocommerce/") and "filter" in name:
        if a.get("heading") == "Price":
            return '<div class="fx-filter"><h4>Price</h4><div class="fx-range"></div><p class="fx-rangev">₹100 — ₹1,800</p></div>'
        opts = {"Prep method": ["Just add hot water (14)", "Stir with drinking water (7)", "Requires cooking (11)"],
                "Dietary": ["Vegan (9)", "Gluten free (12)", "Jain (6)", "Millet based (5)", "High protein (7)"]}
        h = a.get("heading", "Filter")
        lis = "".join(f'<label><input type="checkbox">{o}</label>' for o in opts.get(h, []))
        return f'<div class="fx-filter"><h4>{h}</h4>{lis}</div>'
    if name == "woocommerce/classic-shortcode":
        sc = a.get("shortcode", "cart")
        if sc == "my_account":
            return my_account(SIGNED_IN)
        return cart_or_checkout(sc)
    if name.startswith("query-pagination"):
        return '' if name != "query-pagination" else '<nav class="fx-pag"><a>1</a><a class="on">2</a><a>Next →</a></nav>'
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
        lines = "".join(
            f'<tr><td>{BOWL.format(hue=p[5], time="")}</td><td><strong>{html.escape(p[0])}</strong>'
            f'<span class="fx-meta">{p[1]} · {p[2]}</span></td>'
            f'<td class="fx-qtycell"><button>−</button>1<button>+</button></td>'
            f'<td class="fx-num">₹{p[3]}</td></tr>' for p in PRODUCTS[:3])
        return f'''<div class="fx-cart"><table class="fx-carttable"><tbody>{lines}</tbody></table>
<aside class="fx-summary"><h2>Summary</h2>
<div class="fx-row"><span>Subtotal</span><span class="fx-num">₹620</span></div>
<div class="fx-row fx-disc"><span>NALIN10 · 10%</span><span class="fx-num">−₹62</span></div>
<div class="fx-row"><span>Shipping</span><span class="fx-num">Free</span></div>
<div class="fx-row"><span>GST</span><span class="fx-num">Included</span></div>
<div class="fx-row fx-total"><span>Total</span><span class="fx-num">₹558</span></div>
<button class="wp-element-button fx-add fx-add--lg">Checkout</button></aside></div>'''
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
<div>{address_chooser()}
<p class="fx-fieldcount">Nine fields for a first order — the audited site asked twenty-five.
A returning customer types none of them.</p>{inputs}</div>
<aside class="fx-summary"><h2>Your order</h2>
<div class="fx-row"><span>3 items</span><span class="fx-num">₹620</span></div>
<div class="fx-row fx-disc"><span>Prepaid saving</span><span class="fx-num">−₹25</span></div>
<div class="fx-row"><span>Shipping</span><span class="fx-num">Free</span></div>
<div class="fx-row fx-total"><span>Total</span><span class="fx-num">₹595</span></div>
<div class="fx-pay"><label><input type="radio" checked> Pay now — save ₹25</label>
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


def render(markup, depth=0):
    if depth > 8:
        return markup
    out, pos = [], 0
    for m in BLOCK.finditer(markup):
        out.append(markup[pos:m.start()])
        pos = m.end()
        closing, name, attrs, selfclose = m.group(1), m.group(2), m.group(3), m.group(4)
        a = json.loads(attrs) if attrs else {}
        if closing:
            continue
        if name == "template-part":
            p = os.path.join(THEME, "parts", a.get("slug", "") + ".html")
            tag = a.get("tagName", "div")
            if os.path.exists(p):
                out.append(f"<{tag}>" + render(open(p).read(), depth + 1) + f"</{tag}>")
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
.wp-block-columns{display:flex;gap:var(--wp--preset--spacing--50);flex-wrap:wrap;align-items:flex-start}
.wp-block-column{flex:1 1 0;min-width:0}
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
.fx-bag{background:var(--wp--preset--color--char);color:var(--wp--preset--color--paper);border:0;
  border-radius:var(--wp--custom--radius--pill);padding:0 16px;height:40px;display:inline-flex;
  align-items:center;gap:8px;font-weight:600;font-size:var(--wp--preset--font-size--sm);cursor:pointer}
.fx-bagn{background:var(--wp--preset--color--flame);color:#241703;border-radius:999px;
  min-width:19px;height:19px;display:grid;place-items:center;font-size:11px}
.fx-acct{background:none;border:1px solid var(--wp--preset--color--line-strong);border-radius:999px;
  width:40px;height:40px;cursor:pointer;color:var(--wp--preset--color--char)}
.fx-bowl{position:relative;aspect-ratio:1;border-radius:50%;
  background:radial-gradient(circle at 38% 32%,color-mix(in srgb,var(--h) 22%,#fff) 0,transparent 42%),
             radial-gradient(circle at 50% 50%,var(--h) 0,color-mix(in srgb,var(--h) 70%,#2A1B08) 76%);
  box-shadow:inset 0 0 0 6px color-mix(in srgb,var(--h) 28%,#FFFDF8)}
.fx-time{position:absolute;bottom:2%;right:-2%;background:var(--wp--preset--color--char);
  color:var(--wp--preset--color--paper);font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px}
.fx-grid{display:grid;grid-template-columns:repeat(var(--cols),minmax(0,1fr));gap:var(--wp--preset--spacing--40)}
.fx-card{background:var(--wp--preset--color--surface);border:1px solid var(--wp--preset--color--line);
  border-radius:var(--wp--custom--radius--card);padding:var(--wp--preset--spacing--40);
  display:flex;flex-direction:column;gap:var(--wp--preset--spacing--20)}
.fx-media{position:relative;padding:4px 10px 0}
.fx-chip{position:absolute;top:0;left:0;font-size:10px;font-weight:700;letter-spacing:.08em;
  text-transform:uppercase;padding:4px 9px;border-radius:999px;
  background:var(--wp--preset--color--flame-wash);color:var(--wp--preset--color--flame-deep)}
.fx-chip--flavors{background:var(--wp--preset--color--leaf-wash);color:var(--wp--preset--color--leaf-ink)}
.fx-chip--hot{background:#F3E3D4;color:var(--wp--preset--color--kraft-deep)}
.fx-name{font-size:var(--wp--preset--font-size--md);margin:0}
.fx-rating{font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute)}
.fx-stars{color:var(--wp--preset--color--flame);letter-spacing:-1px}
.fx-price{font-weight:700;margin:auto 0 0;font-variant-numeric:tabular-nums}
.fx-price s{color:var(--wp--preset--color--mute);font-weight:400;margin-right:6px}
.fx-price--lg{font-size:var(--wp--preset--font-size--2xl)}
.fx-off{font-size:12px;background:var(--wp--preset--color--flame-wash);color:var(--wp--preset--color--flame-deep);
  padding:3px 8px;border-radius:3px;vertical-align:middle}
.fx-add{width:100%}
.fx-add--lg{width:auto;flex:1;min-height:52px}
.fx-crumb{font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute)}
.fx-crumb a{color:inherit}
.fx-gallery .fx-bowl{max-width:440px;margin:0 auto}
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
.fx-filter{margin-bottom:var(--wp--preset--spacing--50)}
.fx-filter h4{font-size:10.5px;letter-spacing:.11em;text-transform:uppercase;
  color:var(--wp--preset--color--mute);margin:0 0 10px;font-family:var(--wp--preset--font-family--ui)}
.fx-filter label{display:flex;gap:9px;align-items:center;font-size:var(--wp--preset--font-size--sm);
  padding:5px 0;color:var(--wp--preset--color--char)}
.fx-range{height:4px;background:var(--wp--preset--color--line);border-radius:999px;position:relative}
.fx-range::after{content:"";position:absolute;left:10%;right:25%;top:0;bottom:0;
  background:var(--wp--preset--color--flame-ink);border-radius:999px}
.fx-rangev{font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute);margin-top:8px}
.fx-sort,.fx-count{display:inline-block;font-size:var(--wp--preset--font-size--sm);
  color:var(--wp--preset--color--mute);margin:0 18px var(--wp--preset--spacing--40) 0}
.fx-pag{display:flex;gap:10px;justify-content:center;margin-top:var(--wp--preset--spacing--60)}
.fx-pag a{padding:8px 14px;border:1px solid var(--wp--preset--color--line);border-radius:6px;
  font-size:var(--wp--preset--font-size--sm);cursor:pointer}
.fx-pag a.on{background:var(--wp--preset--color--char);color:var(--wp--preset--color--paper);border-color:var(--wp--preset--color--char)}
.fx-cart,.fx-checkout{display:grid;grid-template-columns:1.6fr 1fr;gap:var(--wp--preset--spacing--60);align-items:start}
.fx-carttable{width:100%;border-collapse:collapse}
.fx-carttable td{padding:16px 12px 16px 0;border-bottom:1px solid var(--wp--preset--color--line);vertical-align:middle}
.fx-carttable .fx-bowl{width:64px}
.fx-meta{display:block;font-size:var(--wp--preset--font-size--sm);color:var(--wp--preset--color--mute)}
.fx-qtycell button{background:none;border:1px solid var(--wp--preset--color--line-strong);
  border-radius:6px;width:28px;height:28px;margin:0 6px;cursor:pointer}
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
@media (max-width:900px){
  .fx-grid{--cols:2 !important}
    .fx-cart,.fx-checkout{grid-template-columns:1fr}
  .wp-block-columns{flex-direction:column}
  .wp-block-columns.is-not-stacked-on-mobile{flex-direction:row;flex-wrap:wrap}
  .wp-block-columns.is-not-stacked-on-mobile>.wp-block-column{flex:1 1 45%}
  .fx-spec dl{grid-template-columns:1fr}
  .fx-spec dl>div:nth-child(odd){border-right:0}
}
@media (max-width:560px){ .fx-grid{--cols:1 !important} .fx-nav{display:none} }
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
        body = body.replace("<!--FOODIFY_YEAR-->", str(datetime.date.today().year))
        tabs.append(f'<button class="tab" role="tab" aria-selected="{str(i == 0).lower()}" data-s="{sid}">{label}</button>')
        panels.append(f'<div class="fx-shell" id="s-{sid}"{"" if i == 0 else " hidden"}>{body}</div>')

    fonts = ('<link rel="preconnect" href="https://fonts.googleapis.com">'
             '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
             '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?'
             'family=Fraunces:opsz,wght@9..144,300..700&'
             'family=Instrument+Sans:ital,wght@0,400..700;1,400..700&display=swap">')

    doc = f"""<title>Foodify Block Theme</title>
{fonts}
<style>
{token_css()}
{styles_css()}
{layout}
{theme_css}
{FIXTURE_CSS}
.proto{{position:sticky;top:0;z-index:99;background:#14201C;color:#F5F2EA;border-bottom:1px solid #23332D}}
.proto-in{{max-width:1240px;margin:0 auto;padding:9px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}}
.proto-tag{{font-size:10px;letter-spacing:.1em;text-transform:uppercase;opacity:.6;
  font-family:var(--wp--preset--font-family--ui)}}
.tabs{{display:flex;gap:3px;flex-wrap:wrap}}
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
</script>"""
    open(OUT, "w").write(doc)
    print(f"rendered {len(SCREENS)} screens -> {OUT}  ({len(doc):,} bytes)")


if __name__ == "__main__":
    main()
