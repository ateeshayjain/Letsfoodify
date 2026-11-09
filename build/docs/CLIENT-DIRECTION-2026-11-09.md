# Client direction — 9 November 2026

Seven requests made while reviewing the prototype on a phone, with Blinkit
screenshots for reference. This records what each became, where it lives,
how it is tested, and what still needs a look on staging.

## What changed

| Request | What it became | Where |
|---|---|---|
| "If something is added to cart it should show like this" (− 1 +) | A card's **Add** becomes **− N +** once the product is in the cart, on every card and on Buy again. Pressing − to nothing brings Add back. A pack inside a build-your-own box is never the card's to change. | `inc/cart-stepper.php` |
| "It should not take me to cart… show a little cart pop up below" | Adding never opens the drawer or the cart. A **View cart** bar comes up at the bottom with up to three of the items' pictures and "3 items" — no amount. On a phone's product page it sits on the sticky Add-to-cart bar. Not shown on the cart, checkout or account. The header mini-cart is set to *not* open its drawer on add. | `inc/cart-stepper.php`, `parts/header.html` |
| The product page's Add to cart | Adds in place (no reload), says "Added ✓", announces "3 × Dal Makhni added to your cart", and the bar comes up. A variable or grouped product keeps WooCommerce's own submit; any failure falls back to it. | `inc/cart-stepper.php` |
| "Spacing and alignment is not proper" / "taking too much space" | The card is ~35% shorter: Add on the photo's corner, SAVE on the photo under Bestseller, name first (two lines at most), how it's made, diet, a one-line **★ 4.7 (84)**, then the price (now before was) and unit price pinned to the foot so prices line up across a row. The prep line is text, not a pill. | `style.css` ("The product card, compact"), `inc/reviews.php` (`foodify_card_rating_html`) |
| Phone header like Blinkit; profile left of cart, cart rightmost; search below | Phone: logo · profile · cart on one row, the search its own row under them, then a scrolling row of categories (All meals, Express, Hot & Fresh, Flavors, Combos, Build a box) in place of the menu button. | `parts/header.html`, `style.css` ("The header, compact") |
| "Less cluttered… just the profile icon, no amount on the cart, glass inside the search, more compact" | Every width: the account is a 44px icon (its words kept for screen readers), the cart a 44px icon with a count badge, the search one pill-shaped field with the glass inside it, the header row tighter. | same |
| "Smart search — final products, ingredients, etc." | Suggestions in groups: the categories, diet/prep labels and ingredients the words name ("moong" → *Meals with split moong dal · 2 meals*), then the meals, then "See all". An empty field offers popular searches as chips. Diet and prep labels are now searchable ("Jain", "Gluten free", "Hot water"). | `inc/search.php` |

Also in this round: an address we do not deliver to stops checkout with a
pop-up (separate commit, `843d843`, see `FEATURES-A-NOTES.md`).

## Decisions taken on the client's behalf (say if any is wrong)

- **The card button reads "Add"** (screen readers still hear "Add Dal Fry to cart").
- **Adding from a search suggestion is not offered.** Suggestions are a
  keyboard-navigable listbox, which may only contain options, not buttons;
  the Add stays on the cards one tap away.
- **"How it works" left the phone menu** — the phone has no menu now; it is
  the home page's second button.
- **The amount is not on the View cart bar either** (the client asked for no
  amount on the header cart; one rule for both). The total is on the cart page,
  with delivery.

## How it is tested

- `tests/cart-stepper-test.php` (27): markup, words, Store API use, the
  no-cart-state-on-a-cached-page rule, wiring.
- `tests/preview-doors.js` (106, in a real browser against the theme's own
  scripts): Add → − 1 +; five fast taps end at six; − to nothing; box lines
  untouched; the bar's count and visibility; the product page adds in place;
  the phone header layout; the category row; smart search groups, chips,
  keyboard, results.
- `tests/search-test.php` (99): facets run in node over fixed vectors; the
  ingredient parser; the versioned index key.
- `tests/reviews-test.php` (153): the one-line card rating.
- `tools/mobile-sweep.js`: no sideways scroll at any width; the header's cart
  is rightmost on the logo's row and the phone search spans its own row.

## Check on staging (cannot be proven from here)

1. **Store API from the cards.** Add, +, − on a card call
   `/wp-json/wc/store/v1/cart/*` with the `Nonce` header taken from a
   response. Confirm with a guest and a signed-in customer, and that a
   page served from the edge cache still works (it carries no nonce by design).
2. **Mini-cart sync.** After a card add, the header badge updates (the script
   sends `wc-blocks_added_to_cart`, as buy-again already did) and the drawer
   does **not** open.
3. **`render_block_woocommerce/product-button` context.** The filter reads the
   product from `$instance->context['postId']` (fallback `get_the_ID()`);
   confirm the control appears on shop, category, search, home and related
   grids, and that sold-out cards still say "Notify me".
4. **The search index** rebuilds under its new key
   (`foodify_search_index_v2`) on first search — no flush needed — and the
   categories / Jain / ingredient groups appear.
5. **On a real phone** (add to `DEVICE-CHECKLIST.md` run): the View cart bar
   clears the home indicator; the category row scrolls with a thumb; the
   search field does not zoom the page on focus (it is 16px).

---

## Second round — "all of the 11" (what else to borrow from Blinkit)

I proposed eleven things to borrow, and four not to (no countdowns or time
promises, no invented "2 left" stock, no text under 16px, no tips or fees).
The client asked for all eleven.

| # | What it became | Where |
|---|---|---|
| 1 | **A delivery line under the logo**: "By Wed 11 Nov · 201301 ▾". Tap it to change the PIN in a small sheet (the same check as the product page). It is printed hidden and filled in the browser from the PIN this device last checked, so a cached page carries no PIN or date. It is absent while `foodify_delivery_zones` is unset. | `inc/delivery-estimate.php`, `parts/header.html` |
| 2 | **Free delivery on the View cart bar**: "Add ₹34 more for free delivery" with a thin meter, then "Free delivery unlocked". The amount is counted on the same basis checkout uses (items with tax, after codes, before the box discount). | `inc/cart-stepper.php` |
| 3 | **Pack size on every card**: "80 g · Serves 2", from the declared net quantity. | `inc/product-spec.php` |
| 4 | **The cart as a bill**: "Cart totals" becomes **Bill details**. A green "You saved ₹X on this order" sits above checkout and counts sale prices, codes and the box discount. It leaves out the pay-online saving, which is not decided until checkout. **You might also like** under the cart shows best sellers that are not already in it. | `inc/cart-extras.php`, `inc/home-rows.php`, `templates/page-cart.html` |
| 5 | **Home rows**: Bestsellers · Just add hot water · Under ₹200 · Jain meals. On a phone each row swipes sideways; from 782px it is one row of four with "See all". Rows are queried by slug and price, never by term id, and show in-stock meals only. | `inc/home-rows.php`, `templates/front-page.html` |
| 6 | **− N + on the product page's sticky bar** once the meal is in the cart. | `inc/cart-stepper.php`, `inc/product-display.php` |
| 7 | **Recent searches**: the last five, shown above Popular searches, with Clear. They are stored on this device only, never in the page, and never sent anywhere. | `inc/search.php` |
| 8 | **Where your order is**, as steps: Placed → Packed → Shipped → Delivered. It appears on the order's own page and on Track order. | `inc/order-progress.php` |
| 9 | **Available offers in the cart** with one-tap Apply. It lists the coupon already named in `foodify_public_offer`, in the product page's own words, read live from the coupon. | `inc/cart-extras.php`, `inc/offers.php` |
| 10 | **Order again** on every order (processing, delivered, problem reported, cancelled), first in the orders list and under the order. | `inc/order-progress.php` |
| 11 | **A tab bar on phones**: Home · Categories · Order again · Account. The View cart bar floats just above it. It is not shown on a product page, the cart or checkout, where those pages' own bars own the bottom. | `inc/tab-bar.php` |

### Found while building these (worth knowing)

- **The orders list never had a reorder button on the live theme.**
  WooCommerce's orders list offers only Pay · View · Cancel; there is no
  "order again" action there (checked against its source). The theme's
  earlier "Reorder first" filter relabelled an action that never existed. The
  prototype showed the button, so nobody noticed. Item 10 adds it, using
  WooCommerce's own order-again URL and nonce. WooCommerce's handler does the
  adding and checks the order belongs to the customer.
- **Nothing created `/track-order/`**, although the footer links to it.
  `bootstrap.sh` now creates it with WooCommerce's tracking form, and only
  when it is missing. An existing page without the form is reported, not
  rewritten.
- **"Out for delivery" is not a step.** The store has no such status; only a
  courier plugin would add it, and the step appears automatically if one does.
  A step the store can never reach would sit grey on every order and read as
  "stuck". The courier link appears on Shipped once something supplies
  `foodify_order_tracking_url`, the same filter the WhatsApp updates read.
  Today nothing does.
- **Offers come from the existing setting, not a new one.** A second,
  hand-typed list of offers could say something checkout does not honour.
  Partner and creator codes are coupons too, so the theme never lists
  coupons by itself.

### Decisions taken on the client's behalf (say if any is wrong)

- **"Packed" has no date.** The store records when an order was placed,
  completed (shipped) and marked delivered, but not when it was packed.
- **Order again also covers cancelled orders.** It does not cover unpaid or
  failed orders, which offer Pay instead.
- **The tab bar's Categories goes to the shop** (every meal, with filters).
  The category row under the search is still one tap away on every page.
- **Tab labels are 16px**, like all text. "Order again" fits a 390px phone;
  the label shortens with "…" rather than shrink on anything narrower.

### How it is tested

- `tests/cart-extras-test.php` (40): You saved, the offer words and Apply,
  the home rows' queries, You might also like.
- `tests/order-progress-test.php` (31): every status's steps, the courier
  link rules, Order again's URL, statuses and placement.
- `tests/tab-bar-test.php` (19): the tabs, the current tab, where it is not
  shown, the stacking.
- `tests/search-test.php`: recent searches run in node (dedupe, cap,
  one-letter rule).
- `tests/preview-doors.js` (118, real browser): recent searches and Clear;
  the tab bar, its current tab and the View cart bar above it; Bill details,
  You saved, Apply and the saving it adds; You might also like leaving out
  the cart; Order again on every order; the order's steps.

### Check on staging

6. **Order again** from the orders list puts the order's items in the cart
   and lands on the cart, for a processing order as well as a delivered one.
7. **`/track-order/`**: with an order number and its email, the steps appear
   above the order's lines.
8. **Bill details**: the heading reads so, and "You saved" equals the
   struck-through prices plus any code plus any box discount on a real cart.
9. **The tab bar on a real phone** clears the home indicator, and the View
   cart bar sits above it.

---

## Third round: "This is too cluttered" (the card)

The client's screenshot showed a card with eight rows and two badges, with a
solid red stepper covering a third of the photo. The client added: "Serves 2
can be displayed when we click. We can remove Jain and High Protein."

| Was | Now |
|---|---|
| Bestseller **and** SAVE 17% stacked on the photo | **One badge**: Sold out, else a real saving, else Bestseller / New |
| A solid red − 1 + pill over the photo | The same size (− and + stay 44px), drawn like **Add**: white, with an ink outline |
| ● **Hot water · 6 min** (bold, coloured dot) | Hot water · 6 min, in quiet regular weight |
| 80 g · Serves 2 | Serves 2 is on the product page. The pack size moved to the foot |
| Jain · High protein | Removed from the card. They remain on the product page, as filters, in search and as the Jain shelf |
| ₹231.25/100 g, on a line of its own | **80 g · ₹231/100 g** on one line. The card rounds to the rupee; the product page keeps the exact figure |
| A combo's full list of meals (stretching every card in its row) | One line, since its name already says "3 meals" |

The card now reads: photo · name · how it is made · ★ rating · price ·
pack and unit price. In the phone's swipe rows it is 289px tall instead of
348px, and the empty band above the price is gone.

Tested in `tests/pdp-test.php` ("the card, decluttered") and in
`tests/preview-doors.js`.

---

## Fourth round: the cart, orders and profile, from Blinkit screenshots

The client sent Blinkit's checkout, profile, order history and order summary
as references. Each became the version below, keeping this rebuild's rules:
no time promises, no invented fees, and only doors the shop actually has.

### Cart (`inc/cart-extras.php`)

- **The items are one card.** "Shipment of 3 items" sits on top, with the
  delivery date for this device's PIN and **Change**. Each line shows a
  picture, the name, the pack size and **Remove** on the left, and
  **− N +** over the struck and paid price on the right.
- **How the steppers work.** − and + only change WooCommerce's own quantity
  field and fire its input event. The existing auto-apply (checkout-flow.php)
  submits after a pause, so five quick taps make one update. Without
  JavaScript the plain field and Update cart still work.
- **On a phone, Proceed is a bar fixed to the bottom.** It shows "Delivering
  · By Wed 11 Nov · 201301 · Change", then the total and **Proceed to
  checkout**. "You saved" moved into the bill, under the total, so it is not
  inside the bar.
- **Not borrowed:**
  - the ₹30 gift bag, which is a product and a fee that don't exist (the
    client's call);
  - "Move to wishlist", because the shop has no wishlist;
  - "Delivery in 15 minutes", because we make no time promises.

### Orders list and order page (`inc/order-progress.php`)

- **Every order is a card.** It shows where the order is ("Delivered",
  "Being packed", "Shipped", "Cancelled"), then "₹720 · 3 Nov, 8:11 am ·
  #1194", the meals' pictures ("+2" beyond four), and **Order again |
  Details**.
- **The ⋮ menu holds everything else** WooCommerce or the theme offers for
  that order: Pay, Cancel, Report a problem, Invoice. It is a `<details>`
  menu, so it works without JavaScript.
- **How it is built.** It replaces WooCommerce's orders-table renderer (not
  its template) and keeps the same query filter, actions and pagination.
- **On the order's own page,** each line has its picture and pack ("80 g ×
  2"). On a phone, **Order again** is a bar fixed to the bottom, and the tab
  bar steps aside there.
- **Not borrowed:**
  - "Arrived in 10 minutes";
  - delete order;
  - ratings on the list;
  - a struck MRP on a past order, because the order never recorded what the
    MRP was, and today's may differ.

### Profile (`inc/profile.php`)

- **The account opens on the profile.** It shows the name, mobile and email,
  then three tiles (Orders, Addresses, Help).
- **"Your information" is the live account menu,** one round-icon row per
  section: Orders & reorder, Repeat orders, Saved addresses, Your details,
  Refer a friend, and anything a plugin adds later.
- **Then Help** (Track an order, Contact us), and **Log out** last.
- **On a phone the side menu is hidden:** the profile is the menu, and every
  other account page starts with "‹ Profile".
- **Not borrowed:** wallet, wishlist, recipes, prescriptions, gift cards and
  the appearance switch. The shop has none of these.

### Also fixed

- **The laptop header.** The delivery line now sits under the logo, as it
  does on phones. Below 1024px the header uses the phone/tablet layout (the
  search on its own row, then the category row) instead of a cramped menu row.
  Between 900 and 1100px the menu used to wrap "How it works" onto a second
  line.

### Tests

- `tests/cart-extras-test.php` (56): the items card, steppers, pay bar and
  "You saved" placement.
- `tests/order-progress-test.php` (49): headlines, cards, menu, the order-page
  lines, the renderer swap, and the tab bar stepping aside.
- `tests/profile-test.php` (20, new).
- `tests/preview-doors.js` (123, real browser): stepping a cart line, the
  card, the pay bar, the profile landing and rows, and the order cards.

### Check on staging

10. **Cart:** − and + update the line and the totals through WooCommerce's
    own update. On a phone the bar's total matches the bill.
11. **My account → Orders** shows the cards, with pagination past ten orders.
    ⋮ → Cancel on a processing order still goes through the cancel flow.
12. **The profile** lists every account section that is actually installed.
    On a phone, "‹ Profile" appears on each section.
