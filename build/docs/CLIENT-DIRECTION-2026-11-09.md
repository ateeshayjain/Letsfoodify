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
