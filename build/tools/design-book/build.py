#!/usr/bin/env python3
"""The design book: every screen, laptop and phone, as a PDF a client can open.

    node tools/design-book/capture.js     # screenshots, chrome hidden
    python3 tools/design-book/build.py    # this file -> design-book.html
    node tools/design-book/render.js      # -> docs/Foodify-Storefront-Design.pdf

The captures come from preview/storefront.html, which is generated from the
theme — so re-running these three after a design change reproduces the book
rather than dating it. The page numbers quoted in the contents on page 2 are
the only thing that has to be checked by hand if pages are added.
"""
import os

HERE = os.path.dirname(os.path.abspath(__file__))
KIT = os.path.dirname(os.path.dirname(HERE))
D = os.path.join(KIT, "theme", "foodify", "assets", "fonts")
S = os.path.join(HERE, "shots")

def img(name):
    return f"file://{S}/{name}.jpg"

HEAD = f"""<!doctype html><html lang="en"><head><meta charset="utf-8">
<style>
@font-face{{font-family:Fraunces;src:url("file://{D}/Fraunces-Variable.woff2") format("woff2");font-weight:300 700}}
@font-face{{font-family:"Instrument Sans";src:url("file://{D}/InstrumentSans-Variable.woff2") format("woff2");font-weight:400 700}}
:root{{
  --paper:#FDFBF7; --surface:#fff; --char:#2B2A27; --mute:#6E675E; --line:#E6DCCB;
  --flame-ink:#C4451A; --flame-wash:#FCEADF; --flame-deep:#B03D14;
  --leaf-ink:#3B6113; --leaf-wash:#E8F0DC; --kraft-pale:#F4EBDF; --kraft-deep:#8A5A28;
  --display:Fraunces, Georgia, serif; --ui:'Instrument Sans', system-ui, sans-serif;
}}
@page{{size:A4 landscape;margin:0}}
*{{box-sizing:border-box}}
html,body{{margin:0;padding:0;background:var(--paper);color:var(--char);
  font-family:var(--ui);font-size:10pt;line-height:1.45;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
.page{{width:297mm;height:210mm;padding:13mm 14mm 12mm;position:relative;overflow:hidden;
  break-after:page;background:var(--paper)}}
.page:last-child{{break-after:auto}}
h1{{font-family:var(--display);font-weight:700;font-size:33pt;line-height:1.02;letter-spacing:-.02em;margin:0}}
h2{{font-family:var(--display);font-weight:600;font-size:17pt;line-height:1.1;margin:0 0 1.5mm}}
h3{{font-size:9pt;font-weight:700;margin:0 0 1mm}}
p{{margin:0 0 2mm}}
.eyebrow{{font-size:7.5pt;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--kraft-deep);margin:0 0 3mm}}
.sub{{color:var(--mute);font-size:10.5pt;max-width:150mm}}
.rule{{height:1px;background:var(--line);margin:4mm 0}}
.foot{{position:absolute;left:14mm;right:14mm;bottom:7mm;display:flex;justify-content:space-between;
  font-size:7pt;color:var(--mute);border-top:1px solid var(--line);padding-top:2mm}}
/* screen pages */
.head{{display:flex;align-items:baseline;gap:4mm;margin-bottom:3mm}}
.head .tag{{font-size:7.5pt;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  color:var(--flame-deep);background:var(--flame-wash);padding:1mm 2.5mm;border-radius:99px}}
.head .lede{{color:var(--mute);font-size:9.5pt;flex:1}}
.body{{display:flex;gap:6mm;align-items:flex-start}}
.shot{{border:1px solid var(--line);border-radius:2mm;overflow:hidden;background:#fff;display:block}}
.shot img{{display:block;width:100%}}
.col-desk{{width:186mm}}
.col-phone{{width:65mm}}
.cap{{font-size:7pt;color:var(--mute);margin-top:1.5mm;letter-spacing:.06em;text-transform:uppercase;font-weight:700}}
ul.notes{{margin:3mm 0 0;padding-left:4mm}}
ul.notes li{{margin:0 0 1.6mm;font-size:9pt}}
ul.notes b{{font-weight:700}}
.two{{display:flex;gap:6mm}}
.two .shot{{width:133mm}}
.cardrow{{display:flex;gap:5mm;margin-top:4mm}}
.card{{flex:1;background:var(--surface);border:1px solid var(--line);border-radius:3mm;padding:4mm 4.5mm}}
.card h3{{font-size:10pt;margin-bottom:1.5mm}}
.card p{{font-size:9pt;color:var(--mute);margin:0}}
.kv{{display:grid;grid-template-columns:52mm 1fr;gap:2mm 5mm;font-size:9.5pt;margin-top:2mm}}
.kv dt{{font-weight:700}}
.kv dd{{margin:0;color:var(--mute)}}
.box{{background:var(--kraft-pale);border-radius:3mm;padding:5mm 6mm;margin-top:4mm}}
.box.leaf{{background:var(--leaf-wash)}}
.box p:last-child{{margin-bottom:0}}
.big{{font-family:var(--display);font-weight:600;font-size:13pt}}
</style></head><body>
"""

def foot(section):
    return (f'<div class="foot"><span>letsfoodify.com · the new storefront · design for review</span>'
            f'<span>{section}</span></div>')

pages = []

# ── 1. Cover ────────────────────────────────────────────────────────────────
pages.append(f"""<section class="page" style="display:flex;flex-direction:column;justify-content:center">
  <p class="eyebrow">The Foodify Company · letsfoodify.com</p>
  <h1 style="max-width:128mm">The new storefront,<br>screen by screen</h1>
  <p class="sub" style="margin-top:6mm;font-size:12pt;max-width:125mm">Every page of the new website, shown on a laptop and on a phone.
  This is the design — the live site has not changed yet.</p>
  <div class="rule" style="margin:8mm 0 4mm;width:120mm"></div>
  <dl class="kv" style="max-width:140mm">
    <dt>Prepared for</dt><dd>Nalin Agarwal, The Foodify Company</dd>
    <dt>Prepared by</dt><dd>Mindlayer</dd>
    <dt>Date</dt><dd>18 September 2026</dd>
    <dt>Status</dt><dd>For review. Nothing here is live until you approve it.</dd>
  </dl>
  <div style="position:absolute;right:14mm;top:13mm;width:120mm;display:flex;gap:3mm;align-items:flex-start">
    {"".join(f'<figure class="shot" style="margin:0;flex:1"><img src="{img(n)}" style="width:100%;display:block"></figure>'
             for n in ["cover-a", "cover-c", "cover-d"])}
  </div>
  {foot('Cover')}
</section>""")

# ── 2. How to read this ─────────────────────────────────────────────────────
pages.append(f"""<section class="page">
  <h2>How to read these pages</h2>
  <p class="sub">Each of the next pages is one screen of the site: the wide picture is a laptop, the tall one beside it is the same
  page on a phone.</p>
  <div class="cardrow">
    <div class="card"><h3>One website, two shapes</h3><p>The phone screens are the same website re-flowing to a small screen —
    not a separate app to download. Indian food shopping happens mostly on phones, so the tall version is the one to judge
    hardest — though we do not have your analytics yet to put a number on it.</p></div>
    <div class="card"><h3>The photography is missing on purpose</h3><p>Every cream box marked “photography placeholder” is where a
    real photo of the prepared dish goes. Booking that shoot is the single biggest visual upgrade left.</p></div>
    <div class="card"><h3>The words and numbers are samples</h3><p>Product names and prices come from your catalogue. A few values were
    invented so the pages look finished — they are listed by name on the second-to-last page.</p></div>
  </div>
  <div class="box">
    <p class="big">What the design is trying to do</p>
    <p><b>Six minutes is the product.</b> Prep time is on every card, ahead of price. &nbsp;·&nbsp;
    <b>Show the food, not the pouch.</b> &nbsp;·&nbsp;
    <b>No surprises after the cart.</b> Shipping is shown before checkout, never added at the end. &nbsp;·&nbsp;
    <b>Earn trust honestly.</b> No fake viewer counts, no invented reviews. &nbsp;·&nbsp;
    <b>Designed for a phone first.</b> Nothing a customer reads is smaller than 16px; nothing they tap is smaller than a thumb.</p>
  </div>
  <div class="rule" style="margin:5mm 0 3mm"></div>
  <div style="display:flex;gap:8mm;font-size:9pt;color:var(--mute)">
    <div><b style="color:var(--char)">What follows</b><br>3–4 Home &nbsp;·&nbsp; 5 Category &nbsp;·&nbsp; 6–7 Combos</div>
    <div><br>8–9 Product &nbsp;·&nbsp; 10 Filters and sorting &nbsp;·&nbsp; 11 Cart &nbsp;·&nbsp; 12 Checkout</div>
    <div><br>13 Account &nbsp;·&nbsp; 14 What is invented and needs your word &nbsp;·&nbsp; 15 What happens next</div>
  </div>
  {foot('How to read this')}
</section>""")

def screen_page(tag, title, lede, desk, phone, notes, desk_cap, phone_cap, section, desk_w="186mm"):
    items = "".join(f"<li>{n}</li>" for n in notes)
    return f"""<section class="page">
  <div class="head"><span class="tag">{tag}</span><h2 style="margin:0">{title}</h2></div>
  <p class="sub" style="margin:-1mm 0 3mm">{lede}</p>
  <div class="body">
    <div class="col-desk" style="width:{desk_w}">
      <figure class="shot" style="margin:0"><img src="{img(desk)}"></figure>
      <p class="cap">{desk_cap}</p>
      <ul class="notes">{items}</ul>
    </div>
    <div class="col-phone">
      <figure class="shot" style="margin:0"><img src="{img(phone)}"></figure>
      <p class="cap">{phone_cap}</p>
    </div>
  </div>
  {foot(section)}
</section>"""

# ── 3–4. Home ───────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Home", "The first ten seconds",
    "A first-time visitor decides here whether this is real food and whether to trust the shop.",
    "home-desktop-1", "home-phone-1",
    ["The claim is the headline — <b>6 minute mein ghar ka khana ready!</b> — and it is the only claim the site makes about time. It is written in one place, so it can never say six on one page and ten on another.",
     "The four facts under it answer what a first-time buyer actually asks: no preservatives, how long it keeps, who is licensed, who cooks it.",
     "<b>Three steps, six minutes</b> explains the product without a video and without a paragraph."],
    "Laptop · top of the home page", "Phone · the same page",
    "Home"))

pages.append(f"""<section class="page">
  <div class="head"><span class="tag">Home</span><h2 style="margin:0">Further down the page</h2></div>
  <p class="sub" style="margin:-1mm 0 3mm">Eight best sellers, not a wall of products — the audited site put 140 product tiles on the homepage for a catalogue of 44.</p>
  <div class="two">
    <figure class="shot" style="margin:0"><img src="{img('home-desktop-2')}"><figcaption></figcaption></figure>
    <figure class="shot" style="margin:0"><img src="{img('home-desktop-3')}"></figure>
  </div>
  <ul class="notes" style="columns:2;column-gap:8mm">
    <li><b>What people reorder</b> — eight products, chosen by how often they sell, refreshed automatically.</li>
    <li><b>Sorted by how you make it</b> — hot water, cold water, cooking: the way a customer decides.</li>
    <li>Reviews carry real names and a verified-purchase mark, and only appear once real customers leave them.</li>
    <li>The trust strip repeats the four facts at the point where a hesitant buyer scrolls back up.</li>
  </ul>
  {foot('Home')}
</section>""")

# ── 5. Shop ─────────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Category", "Choosing a meal",
    "The page a customer lands on from Google or from the menu, and the one that has to make 44 products easy to narrow down.",
    "shop-desktop", "shop-phone",
    ["<b>Filters are visible, not hidden behind an icon</b>: prep method, dietary, price. A Jain customer, or someone with no stove, can get to their shortlist in one tap.",
     "<b>Sort is a labelled control</b> next to the result count — most popular, best rated, price.",
     "Every card carries prep time, servings, rating, price — and a <b>Save badge only when the discount is genuinely 15% or more</b>, so a discount never looks invented.",
     "Sold-out items are greyed out and cannot be added to a cart."],
    "Laptop · category page with filters open", "Phone · filter and sort stay reachable",
    "Category / shop"))

# ── Combos ──────────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Combos", "A box of several meals",
    "The Combos category has existed in the menu since the start with nothing behind it. This is what a pack of meals looks like.",
    "combos-desktop", "combos-phone",
    ["<b>The card says what is in the box</b> — \u201c3 meals \u00b7 Dal Fry, Idli Sambhar, Dal Khichdi\u201d — instead of the servings line a single pack carries. Four names and it counts the rest, rather than truncating a word.",
     "<b>The saving is in rupees, against a figure you enter</b>: what those same packs cost bought one by one. No figure, no saving shown — a box that is not cheaper is still a convenience, and an invented discount is the pattern this rebuild is removing.",
     "Boxes carry the same badges as any other product, so a bestselling box or a Jain box reads the same way everywhere."],
    "Laptop \u00b7 the Combos category", "Phone \u00b7 two boxes across",
    "Combos"))

pages.append(screen_page(
    "Combos", "What is inside the box",
    "The first question a combo buyer asks is not \u201cwhat is it?\u201d but \u201cwhat do I get?\u201d \u2014 so the answer sits above the tabs, not inside them.",
    "combo-desktop", "combo-phone",
    ["<b>The contents are a list with counts</b>, read down the left like a packing slip — not a sentence a buyer has to parse.",
     "<b>\u20b9175 per meal</b> is arithmetic, not marketing: the box price divided by the number of meals, rounded down so it can never flatter the pack.",
     "The strip under the price counts <b>meals and per-meal price</b> where a single pack counts grams and servings.",
     "Every pack inside keeps its own label and its own product page; the box does not pretend to declare for all three."],
    "Laptop \u00b7 the box\u2019s own page", "Phone \u00b7 the same panel",
    "Combos"))

# ── 6–7. Product ────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Product", "The page that sells",
    "Everything a buyer needs before they commit, in the order they ask for it.",
    "product-desktop-1", "product-phone-1",
    ["Under the name: <b>the delivery promise</b>. Under the price: net weight, how much cooked food it makes, how many it serves, and how long it takes.",
     "<b>Five assurances sit directly under the Add to cart button</b> — ready in six minutes, no preservatives, shelf life, cash on delivery, free shipping over ₹599.",
     "On a phone, a bar with the price and Add to cart follows the customer down the page, so buying is never more than one tap away."],
    "Laptop · top of a product page", "Phone · sticky Add to cart",
    "Product"))

pages.append(screen_page(
    "Product", "What is in it, and the pack declarations",
    "The detail a careful buyer — and Legal Metrology — expects, without burying the page in text.",
    "product-desktop-2", "product-phone-2",
    ["<b>About · Ingredients &amp; nutrition · How to cook · FAQ</b> are tabs on a laptop and open-and-close sections on a phone. Same words, one place to edit them.",
     "<b>Pack &amp; label is always open</b>, never hidden in a tab: MRP, best before, shelf life, country of origin, FSSAI licence, marketed by, consumer care.",
     "A declaration you have not supplied is shown as <b>“Not provided”</b> rather than quietly left out — that is the honest way to handle allergens, and the same fields feed your Google product listing."],
    "Laptop · the tabbed body and the pack table", "Phone · the same, as sections",
    "Product", desk_w="148mm"))

# ── Filters and sorting ─────────────────────────────────────────────────────
pages.append(f"""<section class="page">
  <div class="head"><span class="tag">Filters</span><h2 style="margin:0">Narrowing 44 products to the three you want</h2></div>
  <p class="sub" style="margin:-1mm 0 3mm">Shown larger here because this is the machinery of the category page: on the audited site there is no way to filter at all.</p>
  <div class="body">
    <div class="col-desk" style="width:196mm">
      <figure class="shot" style="margin:0"><img src="{img('filters-desktop')}"></figure>
      <p class="cap">Laptop \u00b7 the filter column and the sort control, full size</p>
      <ul class="notes" style="columns:2;column-gap:8mm">
        <li><b>Prep method</b> \u2014 hot water, drinking water, requires cooking. The brand\u2019s own proposition, made filterable.</li>
        <li><b>Dietary</b> \u2014 vegan, gluten free, Jain, millet based, high protein. A Jain customer finds their six products in one tap.</li>
        <li><b>Price</b> \u2014 a range, not a set of bands nobody\u2019s budget matches.</li>
        <li><b>Counts beside every filter</b>, so nobody taps into an empty result.</li>
        <li><b>Sort is labelled</b> \u2014 \u201cSort by: Most popular\u201d \u2014 rather than an unmarked icon.</li>
        <li>These come from product attributes, so adding a product to a category puts it in the filters automatically.</li>
      </ul>
    </div>
    <div class="col-phone">
      <figure class="shot" style="margin:0"><img src="{img('filters-phone')}"></figure>
      <p class="cap">Phone \u00b7 the panel open</p>
      <p style="font-size:8.5pt;color:var(--mute);margin-top:2mm">On a phone the column folds into a <b>Filter</b> button and opens as this panel, so the products stay on screen and nothing is lost.</p>
    </div>
  </div>
  {foot('Filters and sorting')}
</section>""")

# ── 8. Cart ─────────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Cart", "No surprises",
    "The audited site turned ₹210 into ₹285 at the final screen. This one shows the true cost before the customer commits.",
    "cart-desktop", "cart-phone",
    ["A bar at the top says <b>how far the order is from free shipping</b> — the cheapest way to lift an average order value.",
     "Quantity, remove and a partner or creator coupon field. Totals show GST included and shipping as it will actually be charged.",
     "<b>“Continue shopping”</b> is on the page, so the cart is never a dead end."],
    "Laptop · the cart", "Phone · one row per item",
    "Cart"))

# ── 9. Checkout ─────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Checkout", "Nine fields, not twenty-five",
    "The single biggest recoverable loss on the current site.",
    "checkout-desktop", "checkout-phone",
    ["<b>Nine fields for a first order.</b> The PIN code fills in the city and state; the state is a list, not free text.",
     "<b>A returning customer types nothing</b> — they pick a saved address and pay.",
     "Cash on delivery sits beside online payment, with the prepaid saving named.",
     "The page has no menu to wander off into, and nothing is added to the price after this screen."],
    "Laptop · checkout with a saved address", "Phone · the same nine fields",
    "Checkout"))

# ── 10. Account ─────────────────────────────────────────────────────────────
pages.append(f"""<section class="page">
  <div class="head"><span class="tag">Account</span><h2 style="margin:0">Coming back</h2></div>
  <p class="sub" style="margin:-1mm 0 3mm">Repeat orders are where a food brand makes its money. Signing in is a mobile number and a six-digit code — no password to forget.</p>
  <div class="body">
    <div class="col-desk">
      <div class="two">
        <figure class="shot" style="margin:0"><img src="{img('account-desktop')}"></figure>
        <figure class="shot" style="margin:0"><img src="{img('signin-desktop')}"></figure>
      </div>
      <p class="cap">Laptop · past orders with one-tap reorder &nbsp;·&nbsp; signing in</p>
      <ul class="notes">
        <li><b>Reorder</b> puts a past order back in the cart in one tap — the shortest path to a second sale.</li>
        <li>Saved addresses mean a returning customer never types an address again.</li>
        <li>Guest checkout stays the default: nobody is forced to make an account to buy.</li>
        <li>Sign-in by code depends on DLT registration for transactional SMS, which is yours to obtain; until it is done, the
        standard email-and-password sign-in stands in.</li>
      </ul>
    </div>
    <div class="col-phone">
      <figure class="shot" style="margin:0"><img src="{img('account-phone')}"></figure>
      <p class="cap">Phone · the account</p>
    </div>
  </div>
  {foot('Account')}
</section>""")

# ── 11. Invented values ─────────────────────────────────────────────────────
pages.append(f"""<section class="page">
  <h2>What is invented in these pictures, and needs your word</h2>
  <p class="sub">So nothing here is mistaken for a decision already taken. Each of these is a question for you; until you answer,
  the site shows the honest version — for example, a licence number it has not been given prints as <i>NOT CONFIGURED</i>.</p>
  <div class="cardrow" style="margin-top:5mm">
    <div class="card"><h3>FSSAI licence number</h3><p>Shown as 10012345000001 so the layout is right. We need the real number — it belongs on
    the header strip, every product page and every invoice.</p></div>
    <div class="card"><h3>Same-day dispatch in Delhi NCR</h3><p>Written into the header and the product page as a promise. Only keep it if
    operations can hold it every working day.</p></div>
    <div class="card"><h3>“Complete the meal” pairings</h3><p>Which products go with which — a chutney with a dal, a chai with a khichdi.
    We have guessed; you know.</p></div>
  </div>
  <div class="cardrow">
    <div class="card"><h3>Per-product copy</h3><p>Taste notes, cooked weight and the two or three questions customers actually ask about
    each SKU. The fields are built and empty.</p></div>
    <div class="card"><h3>Photography</h3><p>Four shots per product, in this order: pack front, prepared in a bowl, ingredients laid out,
    and one in a hand or beside a mug. The first is what Google Shopping uses.</p></div>
    <div class="card"><h3>Prices and reviews</h3><p>Sample values from the catalogue. Real prices, stock and reviews come from your
    WooCommerce back office, which is unchanged.</p></div>
  </div>
  <div class="cardrow">
    <div class="card"><h3>The boxes themselves</h3><p>Trial, Week, Hostel and Chai &amp; Chutney are our guess at a range. Which boxes exist,
    what goes in each and what they cost is yours to set.</p></div>
    <div class="card"><h3>What a box saves</h3><p>Each box needs the figure its contents cost bought separately. The page calculates the
    saving from that — it never guesses one.</p></div>
    <div class="card" style="background:var(--kraft-pale)"><h3>How to answer</h3><p>A reply naming the boxes, their contents and the two
    prices for each is enough. Everything else on this page can follow later.</p></div>
  </div>
  {foot('Awaiting your word')}
</section>""")

# ── 12. What happens next ───────────────────────────────────────────────────
pages.append(f"""<section class="page">
  <h2>What happens next</h2>
  <p class="sub">The build is complete in code and checked by 29 automated tests that run before anything can be deployed.
  What is left is your input and a launch week.</p>
  <div class="cardrow" style="margin-top:5mm">
    <div class="card"><h3>1 · From you</h3><p>FSSAI licence number · GST details · product photography and pack weights ·
    payment gateway and SMS credentials · answers to the six questions on the previous page.</p></div>
    <div class="card"><h3>2 · On a test site</h3><p>The new front end is installed beside a copy of your shop, with real products and real
    orders, and checked end to end — including a real payment and a real invoice.</p></div>
    <div class="card"><h3>3 · Launch week</h3><p>The switch happens on the same WooCommerce back office you already use. Orders,
    customers and product web addresses are kept exactly as they are, so nothing that ranks on Google is lost.</p></div>
  </div>
  <div class="box leaf">
    <p class="big">What does not change</p>
    <p>Your products, your order history, your customers and your admin remain where they are. This project replaces the layer
    the customer sees — not the shop behind it. If anything goes wrong in launch week, the old front end can be put back the same day.</p>
  </div>
  <p style="margin-top:5mm;font-size:9.5pt;color:var(--mute)">Send the six answers on the previous page and the test site
  can be standing the same week. Anything in these pages can still be changed — that is what a review is for.</p>
  {foot('Next steps')}
</section>""")

out = os.path.join(HERE, "design-book.html")
open(out, "w").write(HEAD + "\n".join(pages) + "\n</body></html>")
print("pages:", len(pages))
