// The prototype has to WORK, because a client uses it like the site.
//
// WHY THIS EXISTS. The client clicked the cart in the header and nothing
// happened: only the tab strip at the top was wired. Then the cart opened but a
// card's Add to cart did not add anything, every product card opened the same
// Dal Fry page, the filters filtered nothing and "Place order" was a toast. A
// prototype that LOOKS like a shop and does not behave like one gets reported
// as a broken shop.
//
// So this walks the journey a customer walks — browse, filter, open a product,
// add, adjust the cart, use a code, check out both ways, find the order, reorder,
// sign out, check out as a guest, sign in — and asserts what each step changes.
// It ends with a sweep that clicks every link and button on every screen and
// fails on any that only says "not built".
//
//   node tests/preview-doors.js
const { chromium } = require('/tmp/node_modules/playwright-core');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('file://' + path.resolve(__dirname, '../preview/storefront.html'));
  await page.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
  await page.reload();
  await page.waitForTimeout(300);

  let passed = 0, failed = 0;
  const check = (label, ok, detail) => {
    if (ok) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${detail !== undefined ? ' — ' + JSON.stringify(detail) : ''}`); }
  };
  const shown = () => page.evaluate(() => [...document.querySelectorAll('[id^=s-]')].filter(e => !e.hidden).map(e => e.id.slice(2)).join(','));
  const go = (id) => page.evaluate((i) => window.fxShow(i), id);
  // Click the first VISIBLE match, the way a person would.
  const click = (sel) => page.evaluate((s) => {
    const el = [...document.querySelectorAll(s)].find(e => e.getBoundingClientRect().width > 0);
    if (!el) return false; el.click(); return true;
  }, sel);
  const num = (sel) => page.evaluate((s) => {
    const el = [...document.querySelectorAll(s)].find(e => e.getBoundingClientRect().width > 0) || document.querySelector(s);
    return el ? parseInt(el.textContent.replace(/[^0-9]/g, ''), 10) : NaN;
  }, sel);
  const badge = () => num('.wc-block-mini-cart__badge');
  const drawerOpen = () => page.evaluate(() => !document.querySelector('.fx-drawer-overlay').hidden);
  const wait = (ms = 120) => page.waitForTimeout(ms);

  // ── every door points somewhere ───────────────────────────────────────────
  const orphans = await page.evaluate(() => {
    const ids = new Set(window.fxScreens);
    return [...new Set([...document.querySelectorAll('[data-s]')].map(e => e.dataset.s).filter(s => s && !ids.has(s)))];
  });
  check('every link points at a screen that exists', !orphans.length, orphans);
  const pdps = await page.evaluate(() => window.fxScreens.filter(s => s.startsWith('p-')).length);
  check('there is a product page for every product (38 singles + 6 combos: the live site has 44)', pdps === 44, pdps);

  // ── declarations: every product page states allergens; every single pack has a nutrition panel ──
  const decl = await page.evaluate(() => window.fxScreens.filter(s => s.startsWith('p-')).map(id => {
    const el = document.getElementById('s-' + id);
    const row = [...el.querySelectorAll('.fd-spec__list > div')].find(d => d.querySelector('dt')?.textContent === 'Allergens');
    const ing = [...el.querySelectorAll('.fd-spec__list > div')].find(d => d.querySelector('dt')?.textContent === 'Ingredients');
    return { id, allergens: row ? row.querySelector('dd').textContent : null, ing: ing ? ing.querySelector('dd').textContent : '',
             combo: !!el.querySelector('.fd-combo'), nutrition: el.querySelectorAll('.fd-nutrition tr').length };
  }));
  const noAl = decl.filter(d => !d.allergens || /not provided/i.test(d.allergens));
  check('every product page declares its allergens (none says "Not provided")', !noAl.length, noAl.map(d => d.id));
  const ghee = decl.filter(d => /\b(ghee|butter|cream|curd)\b/i.test(d.ing) && !/^.*Milk/.test(d.allergens || ''));
  check('a pack made with ghee, butter, cream or curd declares milk', !ghee.length, ghee.map(d => d.id));
  const noNut = decl.filter(d => !d.combo && d.nutrition < 6);
  check('every single pack prints a six-line nutrition panel', !noNut.length, noNut.map(d => [d.id, d.nutrition]));
  const comboNut = decl.filter(d => d.combo && d.nutrition > 0);
  check('a box prints no nutrition panel of its own (each pack has one)', !comboNut.length, comboNut.map(d => d.id));

  // ── pagination: twelve a page, and the pages follow the filters ───────────
  const pg = await page.evaluate(() => {
    window.fxShow('shop', false);
    const root = document.getElementById('s-shop');
    const vis = () => [...root.querySelectorAll('.fd-shop__results li.wp-block-post')].filter(li => !li.hidden).length;
    const count = () => root.querySelector('.woocommerce-result-count').textContent;
    const nums = () => [...root.querySelectorAll('.wp-block-query-pagination .page-numbers')].map(e => e.textContent).join(' ');
    const r = { p1: vis(), c1: count(), nums: nums() };
    root.querySelector('.wp-block-query-pagination-next').click();
    r.p2 = vis(); r.c2 = count(); r.prev = !!root.querySelector('.wp-block-query-pagination-previous');
    root.querySelector('.wp-block-query-pagination .page-numbers[data-page="4"]').click();
    r.p4 = vis(); r.c4 = count(); r.next4 = !!root.querySelector('.wp-block-query-pagination-next');
    const jain = root.querySelector('.wc-block-components-checkbox__input[value="Jain"]');
    jain.click(); jain.dispatchEvent(new Event('change', { bubbles: true }));
    r.jain = vis(); r.cj = count(); r.navHidden = root.querySelector('.wp-block-query-pagination').hidden;
    jain.click(); jain.dispatchEvent(new Event('change', { bubbles: true }));
    window.fxShow('combos', false);
    r.comboNav = !!document.querySelector('#s-combos .wp-block-query-pagination');
    return r;
  });
  check('the shop shows twelve of 38 on page 1, with pages 1–4', pg.p1 === 12 && pg.c1 === 'Showing 1–12 of 38 results' && pg.nums === '1 2 3 4', pg);
  check('Next opens page 2 (13–24) and offers Previous', pg.p2 === 12 && pg.c2 === 'Showing 13–24 of 38 results' && pg.prev, pg);
  check('page 4 holds the last two and has no Next', pg.p4 === 2 && pg.c4 === 'Showing 37–38 of 38 results' && !pg.next4, pg);
  check('filtering re-paginates: Jain fits one page and the pager goes away', pg.jain > 0 && pg.jain <= 12 && pg.navHidden && /^Showing all \d+ results$/.test(pg.cj), pg);
  check('a listing with one page prints no pager', !pg.comboNav, pg);

  // ── browsing ──────────────────────────────────────────────────────────────
  for (const [what, sel, expect] of [
    ['the logo', '#s-home .fx-logo a', 'home'],
    ['the Express menu item', '#s-home .fx-nav a[href*="express"]', 'cat-express'],
    ['the Combos menu item', '#s-home .fx-nav a[href*="combos"]', 'combos'],
    ['the hero "Shop all meals" button', '#s-home a[href="/shop/"]', 'shop'],
    ['a footer page link (Shipping)', '#s-home footer a[href="/shipping/"]', 'page-shipping'],
    ['the account icon (signed in)', '#s-home .wc-block-customer-account__account-link', 'account'],
  ]) {
    await go('home'); await click(sel); await wait();
    check(`${what} opens ${expect}`, (await shown()) === expect, await shown());
  }

  // A card opens ITS product, not the one product page every card used to open.
  await go('shop');
  const card = await page.evaluate(() => {
    const t = [...document.querySelectorAll('#s-shop .fd-products .wp-block-post-title a')][3];
    const name = t.textContent.trim(); t.click();
    const now = [...document.querySelectorAll('[id^=s-]')].find(e => !e.hidden);
    return { name, title: now.querySelector('h1').textContent.trim() };
  });
  check('a product card opens that product\'s own page', card.name === card.title, card);

  // ── filters and sort do what they say ─────────────────────────────────────
  await go('shop');
  const filtered = await page.evaluate(() => {
    const r = document.getElementById('s-shop');
    const box = [...r.querySelectorAll('.wc-block-components-checkbox__input')].find(c => c.value === 'Requires cooking');
    box.click();
    const vis = [...r.querySelectorAll('.fd-products > li')].filter(li => !li.hidden);
    const all = [...r.querySelectorAll('.fd-products > li')].filter(li => li.dataset.prep.includes('Requires cooking')).length;
    const out = { n: vis.length, all, allCook: vis.every(li => li.dataset.prep.includes('Requires cooking')),
                  count: r.querySelector('.woocommerce-result-count').textContent };
    box.click();
    return out;
  });
  check('a Prep method filter leaves only matching products and recounts', filtered.n === filtered.all && filtered.n > 3 && filtered.n <= 12 && filtered.allCook && filtered.count === `Showing all ${filtered.n} results`, filtered);
  const priced = await page.evaluate(() => {
    const r = document.getElementById('s-shop'), mx = r.querySelector('.wc-block-price-filter__amount--max');
    mx.value = 200; mx.dispatchEvent(new Event('change', { bubbles: true }));
    const vis = [...r.querySelectorAll('.fd-products > li')].filter(li => !li.hidden).map(li => +li.dataset.price);
    mx.value = mx.dataset.ceil; mx.dispatchEvent(new Event('change', { bubbles: true }));
    return vis;
  });
  check('the price filter hides everything above the maximum', priced.length > 0 && priced.every(p => p <= 200), priced);
  const sorted = await page.evaluate(() => {
    const r = document.getElementById('s-shop'), sel = r.querySelector('select.orderby');
    sel.value = 'price'; sel.dispatchEvent(new Event('change', { bubbles: true }));
    const prices = [...r.querySelectorAll('.fd-products > li')].map(li => +li.dataset.price);
    sel.value = 'popularity'; sel.dispatchEvent(new Event('change', { bubbles: true }));
    return prices;
  });
  check('"Price: low to high" sorts the grid', sorted.every((p, i) => i === 0 || sorted[i - 1] <= p), sorted);
  const empty = await page.evaluate(() => {
    const r = document.getElementById('s-shop');
    const a = [...r.querySelectorAll('.wc-block-components-checkbox__input')];
    // No chutney is high in protein: an honest empty result.
    a.find(c => c.value === 'Stir with drinking water').click(); a.find(c => c.value === 'High protein').click();
    const shownNo = !r.querySelector('.fx-noresults').hidden;
    r.querySelector('.fx-noresults a').click();
    return { shownNo, after: [...r.querySelectorAll('.fd-products > li')].filter(li => !li.hidden).length };
  });
  check('filtering to nothing says so, and its link clears the filters', empty.shownNo && empty.after === 12, empty);

  // ── adding to the cart ────────────────────────────────────────────────────
  const b0 = await badge();
  await go('cat-express'); await click('#s-cat-express .add_to_cart_button'); await wait();
  check('Add to cart on a card adds one and opens the mini-cart drawer', (await badge()) === b0 + 1 && await drawerOpen(), { b0, b1: await badge() });
  // The card itself says so — WooCommerce's Product Button block shows "N in cart".
  const cardLabel = () => page.evaluate(() => {
    const b = [...document.querySelectorAll('#s-cat-express .add_to_cart_button')].find(e => e.getBoundingClientRect().width > 0)
      || document.querySelector('#s-cat-express .add_to_cart_button[data-add]');
    const slug = b.dataset.add, a = b.textContent.trim();
    const other = document.querySelector(`#s-home .add_to_cart_button[data-add="${slug}"]`);
    return { slug, label: a, home: other ? other.textContent.trim() : null };
  });
  const lab = await cardLabel();
  check('after adding, the card says how many are in the cart — on every page that lists it', /^\d+ in cart$/.test(lab.label) && lab.home === lab.label, lab);
  await click('.fx-drawer-overlay .wc-block-mini-cart__footer-cart'); await wait();
  check('the drawer\'s "View my cart" opens the cart', (await shown()) === 'cart', await shown());

  await go('p-dal-makhni');
  await page.evaluate(() => { const q = document.querySelector('#s-p-dal-makhni form.cart input.qty'); q.value = 3; });
  await click('#s-p-dal-makhni .single_add_to_cart_button'); await wait();
  const makhni = await page.evaluate(() => { window.fxShow('cart'); const tr = document.querySelector('#s-cart tr[data-s-line="dal-makhni"]'); return tr ? +tr.querySelector('input.qty').value : 0; });
  check('the product page adds the quantity chosen', makhni === 3, makhni);
  await go('p-aloo-ka-mazaa');
  const soldOut = await page.evaluate(() => { const r = document.getElementById('s-p-aloo-ka-mazaa'); return { form: !!r.querySelector('form.cart'), stock: r.querySelector('.stock').textContent }; });
  check('a sold-out product has no Add to cart and says Out of stock', !soldOut.form && /Out of stock/.test(soldOut.stock), soldOut);
  await click('.wc-block-mini-cart__button'); await wait();
  check('the header cart opens the drawer', await drawerOpen());
  await page.keyboard.press('Escape'); await wait();
  check('Escape closes it', !(await drawerOpen()));

  // ── the cart adds up, by the shop's real rules ────────────────────────────
  await go('cart');
  const math = () => page.evaluate(() => {
    const r = document.getElementById('s-cart'), n = s => parseInt((r.querySelector(s) || { textContent: '' }).textContent.replace(/[^0-9]/g, ''), 10) || 0;
    const lines = [...r.querySelectorAll('tr.cart_item')].map(tr => parseInt(tr.querySelector('.product-subtotal').textContent.replace(/[^0-9]/g, ''), 10));
    const disc = r.querySelector('.cart-discount').hidden ? 0 : n('.cart-discount td');
    return { lines: lines.reduce((a, b) => a + b, 0), sub: n('.cart-subtotal td'), disc, ship: /Free/.test(r.querySelector('.shipping td').textContent) ? 0 : n('.shipping td'), total: n('.order-total td') };
  });
  let m = await math();
  check('subtotal is the sum of the lines', m.sub === m.lines, m);
  await page.fill('#coupon_code', 'nope'); await click('#s-cart button[name=apply_coupon]'); await wait();
  check('an unknown code is refused in WooCommerce\'s words', /does not exist/.test(await page.textContent('#s-cart .woocommerce-notices-wrapper')));
  await page.fill('#coupon_code', 'nalin10'); await click('#s-cart button[name=apply_coupon]'); await wait();
  m = await math();
  check('NALIN10 takes 10% off', m.disc === Math.round(m.sub * 0.10), m);
  check('shipping is free from ₹599 after the code, ₹59 below it', m.ship === ((m.sub - m.disc) >= 599 ? 0 : 59), m);
  check('total = subtotal − code + shipping', m.total === m.sub - m.disc + m.ship, m);
  const before = await page.evaluate(() => document.querySelectorAll('#s-cart tr.cart_item').length);
  await click('#s-cart tr.cart_item a.remove'); await wait();
  const after = await page.evaluate(() => document.querySelectorAll('#s-cart tr.cart_item').length);
  check('× removes the line, and offers Undo', after === before - 1 && /Undo/.test(await page.textContent('#s-cart .woocommerce-notices-wrapper')), { before, after });
  await page.evaluate(() => { const q = document.querySelector('#s-cart tr.cart_item input.qty'); q.value = 2; q.dispatchEvent(new Event('input', { bubbles: true })); });
  await wait(900);   // inc/checkout-flow.php applies a quantity after a 700 ms pause
  m = await math();
  check('changing a quantity re-adds the cart after the theme\'s pause', m.sub === m.lines && m.total === m.sub - m.disc + m.ship, m);
  check('the header pill follows the cart', (await num('#s-cart .wc-block-mini-cart__amount')) === m.sub, { pill: await num('#s-cart .wc-block-mini-cart__amount'), sub: m.sub });

  // The threshold AT its edges, with carts built for it. bootstrap.sh sets free
  // shipping at ₹599 compared AFTER discounts, ₹59 below. A cart that only ever
  // sits on one side of the line cannot tell 599 from 699.
  const shipFor = async (lines, coupon) => {
    const saved = await page.evaluate(() => localStorage.getItem('foodify-prototype-v2'));
    await page.evaluate(([l, c]) => { window.fxShow('cart'); window.fxSetCart(l, c); }, [lines, coupon]);
    const r = await math();
    await page.evaluate((s) => { localStorage.setItem('foodify-prototype-v2', s); }, saved);
    return r;
  };
  const cartNow = await page.evaluate(() => [...document.querySelectorAll('#s-cart tr.cart_item')].map(tr => [tr.dataset.sLine, +tr.querySelector('input.qty').value]));
  const s555 = await shipFor([['express-dal-fry', 3]], null);
  const s615 = await shipFor([['coconut-red-chutney', 1], ['masala-chai', 1]], null);
  const s615c = await shipFor([['coconut-red-chutney', 1], ['masala-chai', 1]], 'NALIN10');
  check('₹555 pays ₹59 shipping', s555.sub === 555 && s555.ship === 59, s555);
  check('₹615 ships free', s615.sub === 615 && s615.ship === 0, s615);
  check('₹615 less a 10% code is under ₹599 and pays shipping (the threshold is after discounts)', s615c.ship === 59 && s615c.total === 615 - 62 + 59, s615c);
  await page.evaluate((l) => window.fxSetCart(l, 'NALIN10'), cartNow);

  // ── checkout, signed in, paying online ────────────────────────────────────
  await click('#s-cart .checkout-button'); await wait();
  check('Proceed to checkout opens checkout', (await shown()) === 'checkout', await shown());
  const filled = await page.evaluate(() => ({ pin: document.getElementById('fx-co-pin').value, chooser: !!document.querySelector('#s-checkout .fd-address-choose') }));
  check('a returning customer\'s address is already filled in, with the chooser', filled.pin === '201304' && filled.chooser, filled);
  const coTotals = () => page.evaluate(() => { const n = s => parseInt(document.querySelector(s).textContent.replace(/[^0-9]/g, ''), 10) || 0; return { total: n('.fx-co-total'), pre: !document.querySelector('.fx-co-prepaid-row').hidden }; });
  const online = await coTotals();
  await page.check('#s-checkout input[value=cod]'); await wait();
  const cod = await coTotals();
  check('paying online is ₹25 less than cash on delivery', online.pre && !cod.pre && cod.total - online.total === 25, { online, cod });
  await page.check('#s-checkout input[value=razorpay]'); await wait();
  await click('.fx-place'); await wait();
  check('Pay now opens the (simulated) Razorpay window', await page.evaluate(() => !document.querySelector('.fx-rzp').hidden));
  await click('.fx-rzp-cancel'); await wait();
  check('cancelling payment keeps you on checkout with the order unplaced', (await shown()) === 'checkout' && /cancelled/.test(await page.textContent('#s-checkout .woocommerce-NoticeGroup')));
  await click('.fx-place'); await wait(); await click('.fx-rzp-ok'); await wait();
  const placed = await page.evaluate(() => ({ screen: [...document.querySelectorAll('[id^=s-]')].find(e => !e.hidden).id, no: document.querySelector('#s-order .fx-o-no').textContent,
    total: parseInt(document.querySelector('#s-order .fx-o-total').textContent.replace(/[^0-9]/g, ''), 10) }));
  check('a successful payment lands on Order received with an order number and the same total', placed.screen === 's-order' && placed.no === '1195' && placed.total === online.total, { placed, online });
  check('the cart is empty after ordering', (await badge()) === 0);
  const chrome = await page.evaluate(() => ({
    order: { back: /Back to cart/.test(document.getElementById('s-order').textContent), nav: !!document.querySelector('#s-order .fx-nav') },
    checkout: /Back to cart/.test(document.getElementById('s-checkout').textContent) }));
  check('order received has the site header (no "Back to cart" to an empty cart); checkout keeps its own',
    !chrome.order.back && chrome.order.nav && chrome.checkout, chrome);
  const stale = await page.evaluate(() => [...document.querySelectorAll('.add_to_cart_button[data-add]')].filter(b => /in cart/.test(b.textContent)).length);
  check('and no card still claims "in cart"', stale === 0, stale);
  await go('checkout');
  check('checkout with an empty cart sends you to the cart', (await shown()) === 'cart', await shown());

  // ── the account remembers ─────────────────────────────────────────────────
  await go('account');
  const first = await page.evaluate(() => document.querySelector('#s-account .fx-orders tr td strong').textContent);
  check('the new order heads the account\'s order list', first === '#1195', first);
  await click('#s-account [data-view="1088"]'); await wait();
  check('Details opens that order', /1088/.test(await page.textContent('#s-account .fx-vo-head')));
  await click('#s-account .fx-vo-head ~ * [data-panel="orders"], #s-account section[data-panel-body="view-order"] [data-panel="orders"]'); await wait();
  check('"All orders" goes back to the list', await page.evaluate(() => !document.querySelector('#s-account [data-panel-body="orders"]').hidden));
  await click('#s-account [data-reorder="1121"]'); await wait();
  check('Reorder puts that order\'s items in the cart', (await shown()) === 'cart' && (await badge()) === 2, { screen: await shown(), badge: await badge() });

  // ── signed out: guest checkout and the OTP sign-in ────────────────────────
  await go('account'); await click('#s-account [data-panel="customer-logout"]'); await wait();
  check('Log out lands on sign in', (await shown()) === 'signin', await shown());
  await go('home'); await click('#s-home .wc-block-customer-account__account-link'); await wait();
  check('signed out, the account icon opens sign in', (await shown()) === 'signin', await shown());
  await go('checkout');
  const guest = await page.evaluate(() => ({ pin: document.getElementById('fx-co-pin').value, chooser: !!document.querySelector('#s-checkout .fd-address-choose'), signin: !document.querySelector('#s-checkout .fx-co-signin').hidden }));
  check('a guest sees empty fields, no chooser, and the sign-in offer', guest.pin === '' && !guest.chooser && guest.signin, guest);
  await page.check('#s-checkout input[value=cod]'); await click('.fx-place'); await wait();
  const errs = await page.evaluate(() => document.querySelectorAll('#s-checkout .woocommerce-error li').length);
  check('placing an order with empty fields lists what is missing', errs >= 6, errs);
  await click('#s-checkout .fx-co-signin a'); await wait();
  await page.fill('#fx-mobile', '9811122233'); await click('#s-signin .fx-otp-1 button'); await wait();
  await page.fill('#fx-otp', '482913'); await click('#s-signin .fx-otp-2 button'); await wait();
  const back = await page.evaluate(() => ({ screen: [...document.querySelectorAll('[id^=s-]')].find(e => !e.hidden).id, pin: document.getElementById('fx-co-pin').value }));
  check('signing in from checkout returns to checkout, filled in', back.screen === 's-checkout' && back.pin.length === 6, back);

  // ── track an order ────────────────────────────────────────────────────────
  await go('page-track-order'); await page.fill('#fx-t-order', '1194'); await click('#s-page-track-order .fx-track button'); await wait();
  check('Track order finds a known order', /1194/.test(await page.textContent('#s-page-track-order .fx-track-result')));

  // ── the browser's Back button ─────────────────────────────────────────────
  await go('home'); await go('combos'); await page.goBack(); await wait(200);
  check('the browser Back button returns to the previous screen', (await shown()) === 'home', await shown());

  // ── the account can be found, and reviews can be read ────────────────────
  // "Where is my account?" — the header button was a bare icon.
  await go('home');
  const acct = await page.evaluate(() => { const l = document.querySelector('#s-home .wc-block-customer-account__account-link .label');
    return l ? { text: l.textContent.trim(), width: l.getBoundingClientRect().width } : null; });
  check('the header account button says "My account" on a laptop', acct && acct.text === 'My account' && acct.width > 0, acct);
  await go('p-trial-box-3-meals');
  await page.evaluate(() => window.scrollTo(0, 0));
  await click('#s-p-trial-box-3-meals .woocommerce-review-link'); await wait(900);
  const rev = await page.evaluate(() => { const r = document.querySelector('#s-p-trial-box-3-meals #reviews');
    return { top: Math.round(r.getBoundingClientRect().top), cards: r.querySelectorAll('.fd-review').length,
             summary: r.querySelector('.fd-rsum__avg').textContent }; });
  check('the review count jumps to the reviews, landing in view', rev.top > 0 && rev.top < 400, rev);
  check('there are reviews to read, with the rating and count', rev.cards >= 3 && /out of 5/.test(rev.summary) && /46/.test(rev.summary), rev);

  // ── the Nov 2026 features (FEATURES-A…D-NOTES.md), each through its own door ──
  const feat = await page.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms)), r = {};
    document.querySelector('.proto-reset').click();
    // 1 · delivery date by PIN, remembered on the next product
    window.fxShow('p-express-dal-fry', false);
    const w = document.querySelector('#s-p-express-dal-fry .fd-deliver');
    w.querySelector('input').value = '201304'; w.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await sl(200); r.pin = w.querySelector('.fd-deliver__msg')?.textContent || '';
    window.fxShow('p-rajma-chawal', false); await sl(200);
    r.pinNext = document.querySelector('#s-p-rajma-chawal .fd-deliver__msg')?.textContent || '';
    r.pinBad = window.foodifyDelivery.answer('999999', null, JSON.parse(w.getAttribute('data-config')), Date.now()).status;
    // 3 · veg mark and unit price; 6 · offers that match the cart's own rules
    const d = document.getElementById('s-p-express-dal-fry');
    r.veg = !!d.querySelector('h1 .fd-veg'); r.unit = d.querySelector('.fd-unit-price')?.textContent;
    r.offers = [...d.querySelectorAll('.fd-offer__text')].map(e => e.textContent).join(' | ');
    // 5 · the photo brief asks for the back of the pack
    r.brief = d.querySelector('.fx-brief summary').textContent + ' ' + d.querySelector('.fx-brief').textContent.includes('Back of pack');
    // 4 · notify me on a sold-out product
    window.fxShow('p-aloo-ka-mazaa', false);
    const a = document.getElementById('s-p-aloo-ka-mazaa'); a.querySelector('.fd-notify__input').value = 'a@b.in';
    a.querySelector('.fd-notify__go').click(); r.notify = a.querySelector('.fd-notify.is-done')?.textContent || '';
    // 2 · search: the results page uses the header's ranking
    window.fxShow('home', false);
    const f = document.querySelector('#s-home .fd-search__form'); f.querySelector('input[name=s]').value = 'rajma';
    f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    r.search = [...document.querySelectorAll('#s-search .fd-products li.wp-block-post')].filter(l => !l.hidden).map(l => l.getAttribute('data-slug'));
    f.querySelector('input[name=s]').value = 'zzqx'; f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    r.searchNone = !document.querySelector('#s-search .fx-noresults').hidden && document.querySelectorAll('#s-search .fx-noresults li.wp-block-post').length === 3;
    // 7 · buy again, from the seeded orders
    window.fxShow('home', false); r.again = document.querySelectorAll('#s-home .fd-again__add').length;
    const before = +document.querySelector('.wc-block-mini-cart__badge').textContent;
    document.querySelector('#s-home .fd-again__add').click(); r.againAdds = +document.querySelector('.wc-block-mini-cart__badge').textContent - before;
    // 11 · the review filter
    window.fxShow('p-express-dal-fry', false);
    d.querySelector('.fd-rsum__chip[data-fd-filter="photos"]').click();
    r.photos = [...d.querySelectorAll('.fd-rv')].filter(x => !x.hidden).every(x => x.querySelector('.fd-rv-photo')) && d.querySelector('[data-fd-review-count]').textContent;
    // 8 · a full box gets its discount in the cart; one pack out and it goes
    window.fxSetCart([], null); window.fxShow('box', false);
    const bx = document.getElementById('s-box');
    bx.querySelector('input[name=box_size][value="3"]').click();
    const plus = bx.querySelectorAll('.fd-box__plus'); plus[0].click(); plus[0].click(); plus[0].click();
    bx.querySelector('[data-fd-box-add]').click(); await sl(50);
    const c = document.getElementById('s-cart');
    r.boxFee = c.querySelector('.fx-fee')?.textContent || '';
    const q = c.querySelector('.fx-lines tr input.qty'); q.value = 2; q.dispatchEvent(new Event('input', { bubbles: true })); await sl(900);
    r.boxBroken = !c.querySelector('.fx-fee') && /not applied/.test(c.querySelector('.fx-box-notices')?.textContent || '');
    // 10 · cancel a processing order; report a problem with a recent delivery
    window.fxSetCart([['pav-bhaji', 1]], null); window.fxShow('account', false);
    document.querySelector('#s-account [data-view="1194"]').click();
    r.returnForm = !!document.querySelector('#s-account #fd-return form');
    // 12 · repeat this order
    document.querySelector('#s-account .fd-repeat__form button').click();
    r.repeat = document.querySelector('#s-account .fd-repeat.is-set')?.textContent || '';
    // 14 · refer a friend, and your own code refused in the cart
    document.querySelector('#s-account [data-panel="refer-a-friend"]').click();
    r.refer = document.querySelector('#s-account .fd-refer__code-value')?.textContent;
    // 9 + 13 · opt-ins at checkout are unticked
    window.fxShow('checkout', false);
    r.optins = [...document.querySelectorAll('#s-checkout .fd-optin input[type=checkbox]')].map(i => i.checked);
    return r;
  });
  check('a PIN on a product page gives a delivery date', /^Delivers /.test(feat.pin), feat.pin);
  check('the PIN is remembered on the next product page', feat.pinNext === feat.pin, feat);
  check('an unknown PIN is said to be unknown, not given a date', feat.pinBad === 'unknown', feat.pinBad);
  check('the product page shows the veg mark and the price per 100 g', feat.veg && feat.unit === '₹231.25/100 g', feat);
  check('the offers are the cart\'s own rules (₹599, ₹25 online, COD)', /599/.test(feat.offers) && /₹25/.test(feat.offers) && /Cash on delivery/.test(feat.offers), feat.offers);
  check('the photo brief asks for the back-of-pack label', /5 shots/.test(feat.brief) && /true$/.test(feat.brief), feat.brief);
  check('a sold-out product takes an email for one back-in-stock alert', /email a@b\.in once/.test(feat.notify), feat.notify);
  check('search finds Rajma Chawal for "rajma"', feat.search[0] === 'rajma-chawal', feat.search);
  check('a search with no match offers three popular products', feat.searchNone, feat);
  check('buy again offers past items on the home page, and adds one', feat.again > 0 && feat.againAdds === 1, feat);
  check('the "With photos" filter leaves only reviews with photos', !!feat.photos && /Showing 2 of/.test(feat.photos), feat.photos);
  check('a full box shows its discount in the cart', /Your box \(3 packs\) — 5% off/.test(feat.boxFee), feat.boxFee);
  check('a box that no longer fits loses the discount and says why', feat.boxBroken, feat);
  check('a recent delivery can be reported as a problem', feat.returnForm, feat);
  check('an order can be set to repeat', /repeats every 2 weeks/.test(feat.repeat), feat.repeat);
  check('refer a friend shows a code', /^REF/.test(feat.refer || ''), feat.refer);
  check('the WhatsApp and cart-reminder opt-ins start unticked', feat.optins.length === 2 && feat.optins.every(x => x === false), feat.optins);

  // On a phone: the product page opens on the product, and the account is in the menu.
  const phone = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await phone.goto('file://' + path.resolve(__dirname, '../preview/storefront.html'));
  await phone.waitForTimeout(300);
  const landed = await phone.evaluate(() => { window.fxShow('p-trial-box-3-meals');
    return Math.round(document.querySelector('#s-p-trial-box-3-meals h1').getBoundingClientRect().top); });
  check('on a phone, a tapped product shows its name on the first screen', landed > 0 && landed < 844, landed);
  await phone.evaluate(() => [...document.querySelectorAll('#s-home .fx-burger')][0].click());
  const menuAcct = await phone.evaluate(() => { const a = [...document.querySelectorAll('.fx-menu a')].find(x => /My account/.test(x.textContent));
    if (!a) return null; a.click(); return [...document.querySelectorAll('[id^=s-]')].find(e => !e.hidden).id; });
  check('on a phone, the menu has "My account" and it opens the account', menuAcct === 's-account', menuAcct);
  await phone.close();

  // ── nothing anywhere is dead ──────────────────────────────────────────────
  // Every visible link and button on every screen is clicked. A click that the
  // prototype does not handle shows the "not built" toast; there must be none.
  const dead = await page.evaluate(() => {
    const out = [];
    for (const id of window.fxScreens) {
      window.fxShow(id, false);
      const n = [...document.querySelectorAll('#s-' + id + ' a, #s-' + id + ' button')].filter(e => e.getBoundingClientRect().width > 0).length;
      for (let i = 0; i < n; i++) {
        window.fxShow(id, false);
        const el = [...document.querySelectorAll('#s-' + id + ' a, #s-' + id + ' button')].filter(e => e.getBoundingClientRect().width > 0)[i];
        if (!el) continue;
        const t = document.querySelector('.fx-toast'); t.hidden = true; t.textContent = '';
        el.click();
        if (!t.hidden && t.textContent.startsWith('Not built')) out.push(id + ': ' + (el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 40));
        document.querySelector('.fx-rzp').hidden = true;
      }
    }
    return [...new Set(out)];
  });
  check('no link or button on any screen is a dead end', !dead.length, dead);
  check('no script errors', !errors.length, errors);

  await browser.close();
  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
