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
    <dt>Date</dt><dd>9 November 2026 (third edition: compact cards, Add → − 1 +, the View cart bar, the new header, smart search)</dd>
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
    invented so the pages look finished — they are listed by name under “What needs your word”, near the end.</p></div>
  </div>
  <div class="box">
    <p class="big">What the design is trying to do</p>
    <p><b>Six minutes is the product.</b> Prep time is on every card, ahead of price. &nbsp;·&nbsp;
    <b>Show the food, not the pouch.</b> &nbsp;·&nbsp;
    <b>No surprises after the cart.</b> Delivery is shown before checkout, never added at the end. &nbsp;·&nbsp;
    <b>Earn trust honestly.</b> No fake viewer counts, no invented reviews. &nbsp;·&nbsp;
    <b>Designed for a phone first.</b> Nothing a customer reads is smaller than 16px; nothing they tap is smaller than a thumb.</p>
  </div>
  <div class="rule" style="margin:5mm 0 3mm"></div>
  <div style="display:flex;gap:8mm;font-size:9pt;color:var(--mute)">
    <div><b style="color:var(--char)">What follows</b><br>3–4 Home &nbsp;·&nbsp; 5 Search &nbsp;·&nbsp; 6 Category &nbsp;·&nbsp; 7–8 Combos &nbsp;·&nbsp; 9 Build your own box</div>
    <div><br>10–11 Product &nbsp;·&nbsp; 12 Delivery date and offers &nbsp;·&nbsp; 13 Reviews &nbsp;·&nbsp; 14 Filters &nbsp;·&nbsp; 15 Cart &nbsp;·&nbsp; 16 Checkout</div>
    <div><br>17 Account &nbsp;·&nbsp; 18 After the order &nbsp;·&nbsp; 19 Refer a friend &nbsp;·&nbsp; 20 The usability review &nbsp;·&nbsp; 21–22 What needs your word &nbsp;·&nbsp; 23 Next</div>
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

# ── Search ──────────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Search", "Smart search",
    "With 44 products, people type what they want — a dish, an ingredient, a diet. The audited site had no search at all.",
    "search-desktop", "search-phone",
    ["<b>Always there</b>, the glass inside the field: under the logo on a phone, in the header row on a laptop. Tapping it before typing shows <b>popular searches</b> — Express, Hot water, Gluten free, Vegan — one tap each.",
     "As you type, suggestions come <b>in groups</b>: the categories, diets and ingredients the words name (“moong” → <b>Meals with split moong dal · 2 meals</b>), then the meals themselves with prep time and price, then “See all”.",
     "It finds Hindi and regional names once you add them (“राजमा”), forgives a small typo, and puts sold-out items last.",
     "<b>No match is never a dead end</b>: it says so and offers three popular meals."],
    "Laptop · the results page for “dal”", "Phone · typing “moong”",
    "Search"))

# ── 5. Shop ─────────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Category", "Choosing a meal",
    "The page a customer lands on from Google or from the menu, and the one that has to make 44 products easy to narrow down.",
    "shop-desktop", "shop-phone",
    ["<b>Filters by what matters here</b>: prep method, dietary, price. Open beside the products on a laptop; on a phone one <b>Filter</b> line that says how many are on — “Filter · 2” — so the products come first.",
     "<b>Sort is a labelled control</b> next to the result count, and the count is announced to screen readers when a filter changes it.",
     "Every card <b>leads with how it is made</b> — “Hot water · 6 min”, then servings — and carries Jain, vegan or gluten free as words, not a badge on the photo. A <b>Save badge appears only when the discount is genuinely 15% or more</b>.",
     "<b>Add sits on the photo’s corner and becomes − 1 +</b> once the meal is in the cart. Adding never leaves the page: a <b>View cart</b> bar comes up at the bottom (“3 items”), so a shopper keeps browsing. Sold-out items say <b>Sold out</b>, sit last, and offer “Notify me”."],
    "Laptop · category page with filters open", "Phone · filters folded, with a count",
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

# ── Build your own box ──────────────────────────────────────────────────────
pages.append(screen_page(
    "Combos", "Build your own box",
    "The fixed boxes suit most people; this is for the customer who knows exactly what they want.",
    "box-desktop", "box-phone",
    ["<b>Choose a size, then fill it</b> with any mix of single packs. The bar keeps count: “4 of 6 packs · 2 more to fill”.",
     "<b>The discount is shown before you add the box</b>, and it is exactly what the cart charges — worked out the same way, rounded down to the rupee.",
     "In the cart the packs stay separate lines marked “6-pack box”. Take one out and the discount goes, with a message saying how to get it back.",
     "Sizes and discounts are yours to set. Until you do, the page says boxes are coming soon and sells nothing."],
    "Laptop · a 6-pack box, part filled", "Phone · the running total stays in view",
    "Build your own box"))

# ── 6–7. Product ────────────────────────────────────────────────────────────
pages.append(screen_page(
    "Product", "The page that sells",
    "Everything a buyer needs before they commit, in the order they ask for it.",
    "product-desktop-1", "product-phone-1",
    ["The <b>green vegetarian mark</b> sits beside the name, the <b>price per 100 g</b> under the price, and a one-line <b>Contains: milk, gluten</b> above Add to cart, so an allergy is checked before the tap, not after.",
     "Under Add to cart, in the order a buyer asks: what is in a combo, <b>Check delivery</b> — a PIN gives a real date — and <b>Offers</b> as one closed line, “2 offers: pay online, save ₹25 · free delivery from ₹599”. Each comes from the shop’s settings, so it can never promise what checkout will not give.",
     "Three plain assurances close the box: no preservatives, the shelf life, and cash on delivery.",
     "On a phone, a bar with the price and Add to cart follows the customer down the page, so buying is never more than one tap away."],
    "Laptop · top of a product page", "Phone · sticky Add to cart",
    "Product"))

pages.append(screen_page(
    "Product", "What is in it, and the pack declarations",
    "The detail a careful buyer — and Legal Metrology — expects, without burying the page in text.",
    "product-desktop-2", "product-phone-2",
    ["<b>About · Ingredients &amp; nutrition · How to make · FAQ</b> are tabs on a laptop and open-and-close sections on a phone. Same words, one place to edit them.",
     "<b>Pack &amp; label is always open</b>, never hidden in a tab: MRP, best before, shelf life, country of origin, FSSAI licence, marketed by, consumer care.",
     "<b>Allergens and a nutrition panel on every pack.</b> A declaration you have not supplied is shown as <b>“Not provided”</b> rather than quietly left out, and the same fields feed your Google product listing."],
    "Laptop · the tabbed body and the pack table", "Phone · the same, as sections",
    "Product", desk_w="148mm"))

# ── Delivery date, offers, back in stock ────────────────────────────────────
pages.append(f"""<section class="page">
  <div class="head"><span class="tag">Product</span><h2 style="margin:0">No surprises, before the cart</h2></div>
  <p class="sub" style="margin:-1mm 0 3mm">The questions a buyer asks just before tapping Add to cart — when will it come, what will it really cost — answered on the product page.</p>
  <div class="body">
    <div class="col-desk" style="width:118mm">
      <figure class="shot" style="margin:0"><img src="{img('buybox-desktop')}" style="max-height:140mm;width:100%;object-fit:cover;object-position:top"></figure>
      <p class="cap">Laptop · the buy box after a PIN check</p>
    </div>
    <div class="col-desk" style="width:76mm">
      <ul class="notes">
        <li><b>The delivery date comes from your courier’s zones</b>, the dispatch cut-off and Sundays — “Order by 2 pm to ship today”. An unknown PIN says so; it never guesses a date.</li>
        <li><b>The PIN is remembered</b> on the next product and fills itself in at checkout, along with the city and state.</li>
        <li><b>Sold out is not a dead end.</b> “Notify me” takes an email for one message when it is back — then the address is deleted. No mailing list.</li>
        <li>The photo brief now asks for a fifth shot: <b>the back of the pack, label legible</b> — ingredients, allergens and FSSAI number, the way marketplace shoppers check.</li>
      </ul>
    </div>
    <div class="col-phone">
      <figure class="shot" style="margin:0"><img src="{img('notify-phone')}"></figure>
      <p class="cap">Phone · a sold-out product</p>
    </div>
  </div>
  {foot('Product')}
</section>""")

pages.append(screen_page(
    "Product", "Reviews you can read, and filter",
    "Reviews only from customers who ordered the product, shown the way people actually read them.",
    "reviews-desktop", "reviews-phone",
    ["<b>The spread, not just the average</b>: how many fives, fours and threes, so a 4.5 earned honestly reads that way.",
     "<b>Filter by stars, or “With photos”</b> — the count line always says how many are showing.",
     "<b>Customers who ordered can add up to three photos.</b> Each is checked by a person before it appears, and location and camera details are stripped from every photo."],
    "Laptop · the reviews under a product", "Phone · filtered to reviews with photos",
    "Product"))

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
    ["A bar at the top says <b>how far the order is from free delivery</b> — the cheapest way to lift an average order value. It disappears when the cart is empty rather than leaving an empty green box.",
     "Quantity, remove and a partner or creator coupon field. Totals show GST included and <b>Delivery</b> as it will actually be charged — one word for it on every screen, never “Shipping” on one and “Delivery” on the next.",
     "A box is never dearer than its packs bought one by one: free delivery is judged on the cart before the box discount, so filling a box cannot lose it.",
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
     "The page has no menu to wander off into, and nothing is added to the price after this screen.",
     "<b>An address you do not deliver to stops the order</b>: a pop-up says so the moment the PIN is typed, and Place order stays locked until it changes.",
     "Two optional ticks, <b>off unless the customer ticks them</b>: order updates on WhatsApp, and one email with their cart if they do not finish."],
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

# ── After the order ─────────────────────────────────────────────────────────
pages.append(f"""<section class="page">
  <div class="head"><span class="tag">Account</span><h2 style="margin:0">After the order</h2></div>
  <p class="sub" style="margin:-1mm 0 3mm">What Amazon customers expect to do themselves, without writing an email.</p>
  <div class="body">
    <div class="col-desk">
      <div class="two">
        <figure class="shot" style="margin:0"><img src="{img('actions-desktop')}"></figure>
        <figure class="shot" style="margin:0"><img src="{img('repeat-desktop')}"></figure>
      </div>
      <p class="cap">Laptop · an order’s own page &nbsp;·&nbsp; the repeat orders page</p>
      <ul class="notes">
        <li><b>Cancel</b> until the order leaves your kitchen. A paid order is refunded to where it came from; if the automatic refund fails, the customer is told your team will send it by hand.</li>
        <li><b>Report a problem</b> for a few days after delivery — damaged, wrong item, missing, expired — straight to you as a request, never an automatic refund.</li>
        <li><b>Repeat this order</b> every 2, 4 or 6 weeks: an email two days before with a one-tap link. <b>Nothing is charged automatically</b> — the customer pays each time, and can skip, pause or cancel.</li>
      </ul>
    </div>
    <div class="col-phone">
      <figure class="shot" style="margin:0"><img src="{img('again-phone')}"></figure>
      <p class="cap">Phone · Buy again on the home page</p>
      <p style="font-size:8.5pt;color:var(--mute);margin-top:2mm">A signed-in customer sees their recent items on the home page, one tap from the cart. It is never shown to anyone else.</p>
    </div>
  </div>
  {foot('After the order')}
</section>""")

pages.append(screen_page(
    "Account", "Refer a friend",
    "A food brand grows by people telling each other. This gives them a reason, and stops it being gamed.",
    "refer-desktop", "optins-phone",
    ["<b>Give ₹X, get ₹X</b>: the friend saves on their first order; the customer gets a code once that order has shipped.",
     "Share on WhatsApp, copy the link, or read out the code.",
     "<b>Not for your own orders, number or addresses</b>, one reward per friend, a monthly cap, and a reward is withdrawn if the friend’s order is refunded.",
     "Referral codes are kept apart from partner and creator codes, so they never count as a partner’s sales."],
    "Laptop · the Refer a friend page", "Phone · checkout’s two optional ticks",
    "Refer a friend"))

# ── The usability review ───────────────────────────────────────────────────
pages.append(f"""<section class="page">
  <div class="head"><span class="tag">Review</span><h2 style="margin:0">Every screen, read as a customer would</h2></div>
  <p class="sub" style="margin:-1mm 0 3mm">Before this edition every screen was checked at phone width (390px) and laptop width (1280px), every
  button and link was tapped — about 3,500 taps per width — and each problem was confirmed before it was fixed. 94 findings; all four
  that could cost a customer money or an order are closed.</p>
  <div class="cardrow">
    <div class="card"><h3>The four that mattered most</h3><ul class="notes">
      <li>A filled box could lose free delivery and cost <b>more</b> than its packs bought singly.</li>
      <li>Choosing a saved address at checkout did not fill the address in.</li>
      <li>A ₹59 delivery charge could survive after free delivery was earned.</li>
      <li>The fixed “3–5 days pan-India” line could contradict the date the PIN check gave.</li></ul></div>
    <div class="card"><h3>What changed everywhere</h3><ul class="notes">
      <li><b>Nothing a customer reads is under 16px</b>; nothing they tap is under a thumb (44px).</li>
      <li>A visible outline shows where the keyboard is, on every control.</li>
      <li><b>One name per thing</b>: Delivery, Free delivery, Sold out, Report a problem.</li>
      <li>Errors say what went wrong and what to type instead.</li></ul></div>
    <div class="card"><h3>What a laptop cannot test</h3><p>Typing the six-digit code from an SMS, the number keypad,
      a cheap Android phone on 4G and screen readers on a real device. We have written a twenty-minute checklist for
      a real phone; it is the last step before launch.</p></div>
  </div>
  <div class="box" style="margin-top:4mm">
    <p class="big">Design calls we made — say if you disagree</p>
    <p>Below 900px the menu is a scrolling row of categories under the search — no menu button to find. &nbsp;·&nbsp; On phones the
    filters start folded. &nbsp;·&nbsp; Cards show diet as words (“Jain · High protein”) and the Save badge on the photo. &nbsp;·&nbsp;
    The account and the cart are icons; the cart shows a count, never an amount.</p>
    <p class="big" style="margin-top:3mm">Things only you can confirm</p>
    <p><b>Is “No preservatives” true of every product?</b> It now appears on each product page. &nbsp;·&nbsp;
    <b>Box savings:</b> at the current prices five of the six boxes save under 15%, so they show the saving in rupees but no
    Save badge. &nbsp;·&nbsp; <b>Where you deliver</b>: an address outside your delivery zones now stops the order with a
    pop-up, as you asked — so the zones list has to name every state you serve.</p>
  </div>
  {foot('The usability review')}
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
    <div class="card"><h3>Photography</h3><p>Five shots per product, in this order: pack front, prepared in a bowl, ingredients laid out,
    one in a hand or beside a mug, and the back of the pack. The first is what Google Shopping uses.</p></div>
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

# ── Settings the new features need ─────────────────────────────────────────
pages.append(f"""<section class="page">
  <h2>What the new features need from you</h2>
  <p class="sub">Each of these features stays hidden on the live site until you set its numbers. The pictures use our guesses,
  named here so none of them is taken as decided.</p>
  <div class="cardrow" style="margin-top:5mm">
    <div class="card"><h3>Delivery zones</h3><p>Which areas your courier reaches in how many days, your dispatch cut-off and the days you
    do not dispatch. The PIN check shows nothing until these exist.</p></div>
    <div class="card"><h3>Box sizes and discounts</h3><p>Shown as 3, 6 and 9 packs at 5, 10 and 12% off. Should a box also take a partner
    code? Which packs, if any, are left out?</p></div>
    <div class="card"><h3>Returns window</h3><p>Shown as 7 days after delivery. When you mark an order as dispatched also decides when
    cancelling stops.</p></div>
  </div>
  <div class="cardrow">
    <div class="card"><h3>Referrals</h3><p>Shown as give ₹100, get ₹100 on a first order over ₹499. Your amounts, the minimum order and a
    monthly cap.</p></div>
    <div class="card"><h3>Repeat orders</h3><p>How many days’ notice (we suggest 2), and whether repeat orders get a discount. With no
    discount the page says “Repeat this order”, never “save”.</p></div>
    <div class="card"><h3>WhatsApp</h3><p>Meta charges per message. It stays off until you approve that, choose the number and the
    message templates are approved.</p></div>
  </div>
  <div class="cardrow">
    <div class="card"><h3>Names people search by</h3><p>Hindi and regional names for each product (“राजमा”, “poha”), typed once into
    the product editor.</p></div>
    <div class="card"><h3>An online payment gateway</h3><p>None is set up yet, so “pay online and save ₹25” cannot apply on the live site
    until one is.</p></div>
    <div class="card" style="background:var(--kraft-pale)"><h3>How to answer</h3><p>Our full list has sixteen short questions. A reply
    to the first three cards is enough to start testing.</p></div>
  </div>
  {foot('Awaiting your word')}
</section>""")

# ── 12. What happens next ───────────────────────────────────────────────────
pages.append(f"""<section class="page">
  <h2>What happens next</h2>
  <p class="sub">The build is complete in code and checked by 36 automated test suites that run before anything can be deployed.
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
