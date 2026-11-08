# Real-phone check: what only a real device can show

The automated sweep and the UI review run in desktop Chromium at phone width.
None of that shows the on-screen keyboard, the notch, Safari's address bar,
real touch, or a slow network. Run this on **staging**, on one iPhone (Safari)
and one mid-range Android (Chrome). It takes about 40 minutes. Note anything that
fails with a screenshot and the phone model.

**Before you start:** set a delivery-zones value and a box-sizes value on
staging, so those features show. Use a test customer who has one completed
order. Set the phone to normal text size, then repeat step 9 at the largest.

## 1 · Home and header (both phones)
- [ ] The header fits on one line: logo, menu, search, account and cart. Nothing is cut off at the right.
- [ ] Tap the search icon: the keyboard opens straight away (iOS often refuses unless focus happens inside the tap).
- [ ] Type "dal": suggestions appear above the keyboard, not hidden behind it, and tapping one opens the product.
- [ ] ☰ opens the menu. It closes with ×, and the page under it does not scroll while it is open.
- [ ] Pull to refresh and the back gesture both behave normally.

## 2 · Product page
- [ ] Check delivery: the PIN field opens the **number** keypad. Enter 6 digits → a date appears. Leave the page and open another product → the PIN is already filled in.
- [ ] The sticky Add to cart bar sits above the home indicator (iPhone) and the gesture bar (Android), not under it.
- [ ] Tap a review photo: it opens large, Close works, and the back gesture closes it rather than leaving the page.
- [ ] A sold-out product: the email field opens the email keyboard (with @). Notify me works.

## 3 · Cart and box
- [ ] Add to cart opens the drawer. The drawer scrolls on its own, and the page behind it does not.
- [ ] In the cart, change a quantity with the keyboard: the totals update after a short pause, not on every key.
- [ ] Build a box on the phone: the summary bar stays at the bottom while you scroll the packs, and "Add box to cart" is never hidden by the keyboard or the browser bar.

## 4 · Checkout (signed out)
- [ ] Each field opens the right keyboard: mobile → number pad; email → email; PIN → number pad.
- [ ] Autofill (iOS contact card / Chrome addresses) fills name, phone, email and address in one tap.
- [ ] Typing the PIN fills the city and state.
- [ ] On an empty submit, the page scrolls to the error list and it is readable above the keyboard.
- [ ] The Razorpay window opens over the page and returns to Order received. Test this with a real test-mode payment on staging, not the prototype's stand-in.

## 5 · Account
- [ ] Sign in with the code: the code from the SMS is offered above the keyboard (one-time-code autofill).
- [ ] With six items, the account menu is usable with a thumb, and the open item is obvious.
- [ ] Cancel an order and report a problem: the confirmation panel opens in view, not off-screen.
- [ ] Refer a friend → Share opens the phone's share sheet. Send on WhatsApp opens WhatsApp with the message.

## 6 · Network and resilience
- [ ] On "Slow 4G" (Chrome DevTools remote debugging, or a real weak signal), the home page shows text before the fonts and images arrive.
- [ ] Turn on aeroplane mode, then tap Add to cart: the phone says something went wrong; nothing silently does nothing.

## 7 · Accessibility on the device
- [ ] VoiceOver (iPhone): swipe through a product page. Every button says what it does, the veg mark is read as "Vegetarian", and the delivery result is announced when it appears.
- [ ] TalkBack (Android): the same, on checkout. Each field is announced with its label, and errors are read out.
- [ ] Largest text size: the product page and checkout still work; text wraps rather than being cut off.

## 8 · Record it
List each failure in REVIEW-NOTES.md with the device, browser version and a screenshot.
Fix anything in sections 2–4 before launch: those are the screens where an order is lost.
