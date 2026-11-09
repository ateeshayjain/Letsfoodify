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

  // ── adding to the cart: Add → − 1 +, a View cart bar, and no detour ──────
  // Client direction 9 Nov: adding keeps the shopper on the page; the card
  // becomes a stepper and a bar at the bottom says how many are in the cart.
  await page.evaluate(() => window.fxSetCart([], null));
  const b0 = await badge();
  await go('cat-express'); await click('#s-cat-express [data-fd-add]'); await wait();
  const stepCard = () => page.evaluate(() => {
    const c = [...document.querySelectorAll('#s-cat-express [data-fd-cartctl]')].find(e => e.getBoundingClientRect().width > 0);
    const id = c.getAttribute('data-product-id'), home = document.querySelector(`#s-home [data-fd-cartctl][data-product-id="${id}"] [data-fd-qty]`);
    const bar = document.querySelector('[data-fd-viewcart]');
    return { id, add: !c.querySelector('[data-fd-add]').hidden, stepper: !c.querySelector('.fd-cartctl__stepper').hidden, qty: c.querySelector('[data-fd-qty]').textContent,
             home: home ? home.textContent : null, bar: getComputedStyle(bar).display !== 'none', sum: bar.querySelector('[data-fd-viewcart-sum]').textContent };
  });
  const c1 = await stepCard();
  check('Add on a card adds one and stays on the page — no drawer, no cart', (await badge()) === b0 + 1 && !(await drawerOpen()) && (await shown()) === 'cat-express', { b0, b1: await badge() });
  check('the card turns into − 1 +, and so does the same product on every page that lists it', !c1.add && c1.stepper && c1.qty === '1' && c1.home === '1', c1);
  check('a View cart bar comes up with the item count and no amount', c1.bar && c1.sum === '1 item', c1);
  // Fast taps are reconciled, not lost: five quick + presses end at six.
  await page.evaluate(() => { const c = [...document.querySelectorAll('#s-cat-express [data-fd-cartctl]')].find(e => e.getBoundingClientRect().width > 0);
    for (let i = 0; i < 5; i++) c.querySelector('[data-fd-inc]').click(); });
  await wait(250);
  const c6 = await stepCard();
  check('five fast taps on + leave six on the card, six in the cart and "6 items" on the bar', c6.qty === '6' && (await badge()) === b0 + 6 && c6.sum === '6 items', { c6, badge: await badge() });
  await page.evaluate(() => { const c = [...document.querySelectorAll('#s-cat-express [data-fd-cartctl]')].find(e => e.getBoundingClientRect().width > 0);
    for (let i = 0; i < 6; i++) c.querySelector('[data-fd-dec]').click(); });
  await wait(250);
  const c0 = await stepCard();
  check('− down to nothing takes it out, brings back Add and hides the bar', c0.add && !c0.stepper && (await badge()) === b0 && !c0.bar, c0);
  // A pack inside a box is not the card's to change.
  const boxed = await page.evaluate(() => {
    window.fxSetCart([['pav-bhaji', 2, { id: 'b1', size: 3 }], ['idli-sambhar', 1, { id: 'b1', size: 3 }]], null); window.fxShow('shop', false);
    const c = document.querySelector('#s-shop [data-fd-cartctl][data-name="Pav Bhaji"]');
    const r = { qty: c.querySelector('[data-fd-qty]').textContent, add: !c.querySelector('[data-fd-add]').hidden };
    c.querySelector('[data-fd-add]').click();
    return r;
  });
  await wait(200);
  const lines = await page.evaluate(() => JSON.parse(localStorage.getItem('foodify-prototype-v3')).cart.filter(l => l.s === 'pav-bhaji').map(l => (l.box ? 'box:' : 'loose:') + l.q));
  check('packs inside a box do not show on the card, and Add adds a loose pack beside them', boxed.add && lines.indexOf('box:2') >= 0 && lines.indexOf('loose:1') >= 0, { boxed, lines });
  // The link, not the wrapper: the free-delivery strip rides on top of it (client, 9 Nov).
  await page.evaluate(() => window.fxShow('shop', false)); await click('[data-fd-viewcart] .fd-viewcart__link'); await wait();
  check('the View cart bar opens the cart', (await shown()) === 'cart', await shown());
  check('… and is not shown on the cart itself', await page.evaluate(() => getComputedStyle(document.querySelector('[data-fd-viewcart]')).display === 'none'));
  await click('.wc-block-mini-cart__button'); await wait();
  await click('.fx-drawer-overlay .wc-block-mini-cart__footer-cart'); await wait();
  check('the drawer\'s "View my cart" opens the cart', (await shown()) === 'cart', await shown());
  // The box above is complete and carries its own discount; the sums below are
  // about codes and delivery. Back to the one loose meal the steps below expect.
  await page.evaluate(() => window.fxSetCart([['express-dal-fry', 1]], null));

  await go('p-dal-makhni');
  await page.evaluate(() => { const q = document.querySelector('#s-p-dal-makhni form.cart input.qty'); q.value = 3; });
  await click('#s-p-dal-makhni .single_add_to_cart_button'); await wait();
  const stayed = await page.evaluate(() => ({ screen: [...document.querySelectorAll('[id^=s-]')].find(s => !s.hidden).id, drawer: !document.querySelector('.fx-drawer-overlay').hidden,
    btn: document.querySelector('#s-p-dal-makhni .single_add_to_cart_button').textContent, said: document.querySelector('[data-fd-cart-status]').textContent }));
  check('the product page adds in place — same page, no drawer, "Added ✓", the name announced', stayed.screen === 's-p-dal-makhni' && !stayed.drawer && /Added/.test(stayed.btn) && /3 × Dal Makhni added/.test(stayed.said), stayed);
  const makhni = await page.evaluate(() => { window.fxShow('cart'); const tr = document.querySelector('#s-cart tr[data-s-line="dal-makhni"]'); return tr ? +tr.querySelector('input.qty').value : 0; });
  check('the product page adds the quantity chosen', makhni === 3, makhni);
  await go('p-aloo-ka-mazaa');
  const soldOut = await page.evaluate(() => { const r = document.getElementById('s-p-aloo-ka-mazaa'); return { form: !!r.querySelector('form.cart'), stock: r.querySelector('.stock').textContent }; });
  check('a sold-out product has no Add to cart and says Sold out', !soldOut.form && /Sold out/.test(soldOut.stock), soldOut);
  await click('.wc-block-mini-cart__button'); await wait();
  check('the header cart opens the drawer', await drawerOpen());
  await page.keyboard.press('Escape'); await wait();
  check('Escape closes it', !(await drawerOpen()));

  // ── the cart adds up, by the shop's real rules ────────────────────────────
  await go('cart');
  const math = () => page.evaluate(() => {
    const r = document.getElementById('s-cart'), n = s => parseInt((r.querySelector(s) || { textContent: '' }).textContent.replace(/[^0-9]/g, ''), 10) || 0;
    // The paid figure, not the struck one before it (inc/cart-extras.php).
    const lines = [...r.querySelectorAll('tr.cart_item')].map(tr => { const c = tr.querySelector('.product-subtotal').cloneNode(true); c.querySelectorAll('del').forEach(d => d.remove()); return parseInt(c.textContent.replace(/[^0-9]/g, ''), 10); });
    const disc = r.querySelector('.cart-discount').hidden ? 0 : n('.cart-discount td');
    return { lines: lines.reduce((a, b) => a + b, 0), sub: n('.cart-subtotal td'), disc, ship: /Free/.test(r.querySelector('.shipping td').textContent) ? 0 : n('.shipping td'), total: n('.order-total td') };
  });
  let m = await math();
  check('subtotal is the sum of the lines', m.sub === m.lines, m);
  await page.fill('#coupon_code', 'nope'); await click('#s-cart button[name=apply_coupon]'); await wait();
  check('an unknown code is refused, naming it and saying what to do', /couldn't find the code NOPE\. Check the spelling/.test(await page.textContent('#s-cart .woocommerce-notices-wrapper')));
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
  // It opens on the profile (inc/profile.php); its first row is the orders.
  await go('account'); await click('#s-account .fd-profile__row[data-panel="orders"]'); await wait();
  const first = await page.evaluate(() => document.querySelector('#s-account .fx-orders .fd-ocard [data-view]').getAttribute('data-view'));
  check('the new order heads the account\'s order list', first === '1195', first);
  await click('#s-account [data-view="1088"]'); await wait();
  check('Details opens that order', /1088/.test(await page.textContent('#s-account .fx-vo-head')));
  await click('#s-account .fd-profile-back a[data-panel="orders"]'); await wait();
  check('"‹ Orders" on an order\'s page goes back to the list', await page.evaluate(() => !document.querySelector('#s-account [data-panel-body="orders"]').hidden));
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
    r.cod = [...d.querySelectorAll('.fd-assure__item')].some(e => /Cash on delivery/.test(e.textContent));
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
    window.fxShow('home', false); r.again = document.querySelectorAll('#s-home .fd-again [data-fd-cartctl]').length;
    const before = +document.querySelector('.wc-block-mini-cart__badge').textContent;
    const ag = [...document.querySelectorAll('#s-home .fd-again [data-fd-cartctl]')].find(c => !c.querySelector('[data-fd-add]').hidden);
    ag.querySelector('[data-fd-add]').click(); await new Promise(res => setTimeout(res, 50));
    r.againAdds = +document.querySelector('.wc-block-mini-cart__badge').textContent - before;
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
  check('the offers are the cart\'s own rules (₹599, ₹25 online); COD is an assurance, not an offer', /599/.test(feat.offers) && /₹25/.test(feat.offers) && !/Cash on delivery/.test(feat.offers) && feat.cod, feat);
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

  // ── UX review P0s (8 Nov 2026) ─────────────────────────────────────────────
  const p0 = await page.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms)), r = {};
    document.querySelector('.proto-reset').click();
    // BUY-1: Rajma ×2 + Idli = ₹615. As a complete 3-pack box it must not cost more.
    window.fxSetCart([['rajma-chawal', 2], ['idli-sambhar', 1]], null); window.fxShow('cart', false);
    const total = () => document.querySelector('#s-cart .order-total td').textContent.replace(/[^0-9]/g, '');
    r.loose = +total();
    window.fxSetCart([], null); window.fxShow('box', false);
    const b = document.getElementById('s-box'), ids = Object.entries(window.FX_BOX_IDS);
    b.querySelector('input[name=box_size][value="3"]').click();
    const plus = slug => b.querySelector('.fd-box__pack[data-id="' + ids.find(x => x[1] === slug)[0] + '"] .fd-box__plus');
    plus('rajma-chawal').click(); plus('rajma-chawal').click(); plus('idli-sambhar').click();
    b.querySelector('[data-fd-box-add]').click(); await sl(50);
    r.boxed = +total();
    r.shipping = document.querySelector('#s-cart .shipping td').textContent;
    // BUY-2: choosing "Office" is enough — the order goes to Office without "Use this address".
    window.fxSetCart([['pav-bhaji', 4]], null); window.fxShow('checkout', false);
    const office = document.querySelector('#s-checkout input[name=fx-addr][value="a2"]');
    office.click(); office.dispatchEvent(new Event('input', { bubbles: true }));
    r.pin = document.getElementById('fx-co-pin').value;
    return r;
  });
  check('a complete box never costs more than the same packs loose (no ₹59 comes back)', p0.boxed <= p0.loose && /Free delivery/.test(p0.shipping), p0);
  check('choosing a saved address fills checkout with it at once', p0.pin === '201301', p0);

  // Client decision 8 Nov: a PIN no delivery zone covers pops up and stops the order.
  const nodel = await page.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms));
    const orders = () => (JSON.parse(localStorage.getItem('foodify-prototype-v3') || '{}').orders || []).length;
    window.fxSetCart([['pav-bhaji', 4]], null); window.fxShow('checkout', false);
    const pin = document.getElementById('fx-co-pin'), d = () => document.getElementById('fd-pin-block'), btn = document.getElementById('place_order');
    const r = { before: orders() };
    pin.value = '744101'; pin.dispatchEvent(new Event('input', { bubbles: true })); await sl(30);
    r.popped = !!(d() && d().open); r.msg = d() && d().querySelector('[data-fd-pin-block-msg]').textContent; r.locked = btn.getAttribute('aria-disabled');
    // A dialog's close event is queued, not immediate: wait for focus to land (bounded), not a fixed 10 ms.
    d().close(); for (let t = 0; t < 20 && document.activeElement !== pin; t++) await sl(50); r.focusBack = document.activeElement === pin;
    btn.click(); await sl(30);
    r.reopened = d().open; r.payWindow = !document.querySelector('.fx-rzp').hidden; r.after = orders();
    d().close();
    pin.value = '201301'; pin.dispatchEvent(new Event('input', { bubbles: true })); await sl(30);
    r.unlocked = btn.getAttribute('aria-disabled') === null && !btn.classList.contains('is-blocked');
    return r;
  });
  check('a PIN we do not deliver to pops up, names the place, and locks Place order', nodel.popped && /744101 \(South Andaman\)/.test(nodel.msg) && nodel.locked === 'true', nodel);
  check('closing the pop-up puts the cursor back in the PIN field', nodel.focusBack, nodel);
  check('Place order then re-opens the pop-up — no payment window, no order', nodel.reopened && !nodel.payWindow && nodel.after === nodel.before, nodel);
  check('a PIN we deliver to unlocks it', nodel.unlocked, nodel);

  // On a phone: the product page opens on the product, and the account is in the menu.
  const phone = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await phone.goto('file://' + path.resolve(__dirname, '../preview/storefront.html'));
  await phone.waitForTimeout(300);
  // Measured from the SITE's top (its header), not the page's: the prototype's
  // own tab strip and banner sit above it and are not on letsfoodify.com.
  const landed = await phone.evaluate(() => { window.fxShow('p-trial-box-3-meals');
    const s = document.querySelector('#s-p-trial-box-3-meals');
    return Math.round(s.querySelector('h1').getBoundingClientRect().top - s.querySelector('header').getBoundingClientRect().top); });
  check('on a phone, a tapped product shows its name on the first screen', landed > 0 && landed < 844, landed);
  // The phone header (client, 9 Nov): logo | profile | cart, the search its own
  // row, the categories a row under it — and no menu button left to find.
  const ph = await phone.evaluate(() => { window.fxShow('home', false); window.scrollTo(0, 0);
    const h = document.querySelector('#s-home .fd-header'), vis = el => el && getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().width > 0;
    const acct = h.querySelector('.wc-block-customer-account__account-link'), cart = h.querySelector('.wc-block-mini-cart__button');
    const cats = [...h.querySelectorAll('.fd-catrow a')].map(a => a.textContent);
    return { burger: vis(h.querySelector('.fx-burger')), acctLabel: acct.textContent.trim(), acctWide: Math.round(acct.getBoundingClientRect().width),
      amount: vis(h.querySelector('.wc-block-mini-cart__amount')), cartRight: Math.round(h.getBoundingClientRect().right - cart.getBoundingClientRect().right),
      searchBelow: h.querySelector('.fd-search').getBoundingClientRect().top >= cart.getBoundingClientRect().bottom, cats }; });
  check('on a phone: no menu button; the profile is an icon that still says "Sign in"/"My account" to a screen reader', !ph.burger && ph.acctWide === 44 && /Sign in|My account/.test(ph.acctLabel), ph);
  check('… the cart is the rightmost icon and shows no amount, the search sits on its own row below', ph.cartRight === 0 && !ph.amount && ph.searchBelow, ph);
  check('… and the categories are a row of their own', ph.cats.join('|') === 'All meals|Express|Hot & Fresh|Flavors|Combos|Build a box', ph.cats);
  const toExpress = await phone.evaluate(() => { [...document.querySelectorAll('#s-home .fd-catrow a')].find(a => a.textContent === 'Express').click();
    return [...document.querySelectorAll('[id^=s-]')].find(e => !e.hidden).id; });
  check('a category in that row opens its page', toExpress === 's-cat-express', toExpress);
  const menuAcct = await phone.evaluate(() => { window.fxShow('home', false); document.querySelector('#s-home .wc-block-customer-account__account-link').click();
    return [...document.querySelectorAll('[id^=s-]')].find(e => !e.hidden).id; });
  check('the profile icon opens the account (or sign-in)', menuAcct === 's-account' || menuAcct === 's-signin', menuAcct);

  // Smart search (client, 9 Nov): products, ingredients, categories, labels.
  const smart = await phone.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms));
    window.fxShow('home', false);
    const inp = document.querySelector('#s-home #fd-search-q'), list = () => document.querySelector('#s-home .fd-search__list');
    inp.focus(); await sl(30);
    const empty = { chips: list().classList.contains('is-chips'), n: list().querySelectorAll('[role=option]').length, head: list().firstElementChild.textContent };
    inp.value = 'moong'; inp.dispatchEvent(new Event('input', { bubbles: true })); await sl(30);
    const moong = [...list().children].map(li => (li.getAttribute('role') === 'option' ? '' : '# ') + li.textContent.trim());
    inp.value = 'jain'; inp.dispatchEvent(new Event('input', { bubbles: true })); await sl(30);
    const jain = [...list().querySelectorAll('.fd-search__facet .fd-search__name')].map(n => n.textContent);
    // Arrow keys skip the headings; Enter on the label opens its results.
    inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    const first = document.getElementById(inp.getAttribute('aria-activedescendant'))?.textContent.trim();
    inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); await sl(50);
    const screen = [...document.querySelectorAll('[id^=s-]')].find(e => !e.hidden).id;
    const shown = [...document.querySelectorAll('#s-search .fd-products li.wp-block-post')].filter(l => !l.hidden).length;
    return { empty, moong, jain, first, screen, shown };
  });
  check('an empty search offers popular searches as chips', smart.empty.chips && smart.empty.n >= 4 && /Popular/.test(smart.empty.head), smart.empty);
  check('"moong" groups an ingredient ("Meals with …") above the meals, then "See all"',
    smart.moong[0].startsWith('# ') && smart.moong.some(r => /^Meals with .*moong/.test(r)) && smart.moong.includes('# Meals') && /^See all/.test(smart.moong[smart.moong.length - 1]), smart.moong);
  check('"jain" offers the Jain label', smart.jain.includes('Jain'), smart.jain);
  check('ArrowDown lands on the first option, not a heading; Enter opens its results', /^Jain/.test(smart.first || '') && smart.screen === 's-search' && smart.shown > 0, smart);

  // ── the eleven Blinkit borrowings (client, 9 Nov) ─────────────────────────
  // Recent searches: the search just made comes back first, and Clear empties it.
  const recent = await phone.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms));
    window.fxShow('home', false);
    const inp = document.querySelector('#s-home #fd-search-q'), list = () => document.querySelector('#s-home .fd-search__list');
    inp.value = ''; inp.blur(); await sl(20); inp.focus(); inp.dispatchEvent(new Event('input', { bubbles: true })); await sl(30);
    const heads = [...list().querySelectorAll('.fd-search__head')].map(h => h.textContent);
    const items = [...list().querySelectorAll('.fd-search__recent')].map(r => r.textContent.trim());
    const clear = document.querySelector('#s-home .fd-search__clear');
    const clearOut = clear && !clear.hidden && !list().contains(clear);
    clear.click(); await sl(30);
    return { heads, items, clearOut, after: list().querySelectorAll('.fd-search__recent').length, stored: localStorage.getItem('fd-recent-searches') };
  });
  check('recent searches come first when the field is empty, newest first', recent.heads[0] === 'Recent searches' && recent.heads[1] === 'Popular searches' && /^jain$/i.test(recent.items[0] || ''), recent);
  check('… Clear sits outside the list and empties it', recent.clearOut && recent.after === 0 && !recent.stored, recent);

  // The tab bar: four tabs, the View cart bar above it, gone where another bar owns the bottom.
  const tabs = await phone.evaluate(() => {
    window.fxSetCart([['express-dal-fry', 1]], null); window.fxShow('home', false);
    const tb = document.querySelector('.fd-tabbar'), vc = document.querySelector('.fd-viewcart'), vis = e => getComputedStyle(e).display !== 'none';
    const r = { labels: [...tb.querySelectorAll('.fd-tabbar__label')].map(l => l.textContent), current: tb.querySelector('[aria-current=page]')?.textContent,
      above: vis(vc) && Math.round(vc.getBoundingClientRect().bottom) <= Math.round(tb.getBoundingClientRect().top), bottom: Math.round(innerHeight - tb.getBoundingClientRect().bottom) };
    window.fxShow('p-express-dal-fry', false); r.onProduct = vis(tb);
    window.fxShow('cart', false); r.onCart = vis(tb);
    window.fxShow('shop', false); r.shopCurrent = tb.querySelector('[aria-current=page]')?.textContent;
    return r;
  });
  check('phones get a tab bar: Home · Categories · Order again · Account, the page\'s own tab marked', tabs.labels.join('|') === 'Home|Categories|Order again|Account' && tabs.current === 'Home' && tabs.shopCurrent === 'Categories' && tabs.bottom === 0, tabs);
  check('… the View cart bar floats above it, never under it', tabs.above, tabs);
  check('… and it is not there on a product page or the cart, whose own bars own the bottom', !tabs.onProduct && !tabs.onCart, tabs);

  // The cart as a bill.
  const bill = await phone.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms));
    window.fxSetCart([['express-dal-fry', 2]], null); window.fxShow('cart', false); await sl(30);
    const c = document.getElementById('s-cart');
    const r = { h2: c.querySelector('.cart_totals h2').textContent, saved: c.querySelector('.fd-saved')?.textContent,
      offer: c.querySelector('.fd-cart-offer__title')?.textContent, partnerListed: /NALIN10/.test(c.querySelector('.fx-offers').textContent),
      suggestHasDal: [...c.querySelectorAll('.fd-row--cart li')].filter(l => !l.hidden).some(l => l.querySelector('[data-fd-cartctl]')?.getAttribute('data-name') === 'Express Dal Fry'),
      suggestions: [...c.querySelectorAll('.fd-row--cart li')].filter(l => !l.hidden).length };
    c.querySelector('[data-fd-offer]').click(); await sl(60);
    r.applied = c.querySelector('.fd-cart-offer__applied')?.textContent; r.savedAfter = c.querySelector('.fd-saved')?.textContent;
    r.discount = c.querySelector('.cart-discount td')?.textContent;
    return r;
  });
  check('the cart totals read "Bill details", and "You saved" adds up the sale price (2 × ₹40)', bill.h2 === 'Bill details' && bill.saved === 'You saved ₹80 on this order', bill);
  check('Available offers lists the PUBLIC offer only — never a partner code', bill.offer === 'Use code WELCOME5: 5% off your order' && !bill.partnerListed, bill);
  check('… Apply applies it through the coupon form, and the saving grows by the code', bill.applied === 'Applied' && /^−₹19/.test(bill.discount || '') && bill.savedAfter === 'You saved ₹99 on this order', bill);
  check('"You might also like" never offers what is already in the cart', bill.suggestions > 0 && !bill.suggestHasDal, bill);

  // Where an order is, and Order again.
  const ord = await phone.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms));
    window.fxShow('account', false); await sl(30);
    const a = document.getElementById('s-account');
    const again = [...a.querySelectorAll('.fx-orders .fd-reorder')].map(b => b.textContent);
    a.querySelector('.fx-orders button[data-view]').click(); await sl(60);
    const steps = [...a.querySelectorAll('.fx-vo-progress .fd-track__step')].map(s => s.className.replace('fd-track__step ', '') + ':' + s.querySelector('.fd-track__label').textContent.replace(/^(Done|Now|Next): /, ''));
    return { again, steps, title: a.querySelector('.fx-vo-progress h2')?.textContent, viewAgain: a.querySelector('.fx-vo-reorder')?.textContent };
  });
  check('every order in the list has "Order again", first', ord.again.length > 0 && ord.again.every(t => t === 'Order again'), ord);
  const heads = await phone.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms));
    window.fxShow('account', true); await sl(60);
    const a = document.getElementById('s-account'), h = () => a.querySelector('h1').textContent;
    const profile = h(); a.querySelector('.fx-orders button[data-view]').click(); await sl(60);
    return { profile, order: h(), line: a.querySelector('.fx-vo-head').textContent };
  });
  check('the account is headed "Profile"; an order\'s page "Order summary", with "Order #… · placed … · …" under it',
    heads.profile === 'Profile' && heads.order === 'Order summary' && /^Order #\d+ · placed .+ · \w/.test(heads.line), heads);
  check('an order shows where it is as steps — Placed, Packed, Shipped, Delivered — with no step the store cannot reach', ord.title === 'Where your order is' && ord.steps.join('|') === 'is-done:Placed|is-done:Packed|is-done:Shipped|is-done:Delivered', ord);
  check('… and Order again under it', ord.viewAgain === 'Order again', ord);

  // The cart, Blinkit's checkout: one card of lines, − N + on each, the phone's pay bar.
  const cart2 = await phone.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms));
    window.fxSetCart([['express-dal-fry', 1], ['masala-chai', 1]], null); window.fxShow('cart', false); await sl(30);
    const c = document.getElementById('s-cart'), row = c.querySelector('tr.cart_item');
    const r = { ship: c.querySelector('.fd-cart-ship__title').textContent, remove: row.querySelector('a.remove').textContent, pack: row.querySelector('.fd-cart-line__pack')?.textContent,
      was: !!row.querySelector('.product-subtotal del'), bar: getComputedStyle(c.querySelector('.wc-proceed-to-checkout')).position };
    row.querySelector('[data-fd-cartqty-step="1"]').click(); row.querySelector('[data-fd-cartqty-step="1"]').click();
    // The quantity applies after a 700 ms pause; wait for it (bounded), not a fixed sleep that load can outrun.
    for (let t = 0; t < 30 && c.querySelector('.fd-cart-ship__title').textContent !== 'Shipment of 4 items'; t++) await sl(100);
    r.qty = c.querySelector('tr.cart_item input.qty').value; r.total = c.querySelector('.fx-paybar-amt').textContent; r.ship2 = c.querySelector('.fd-cart-ship__title').textContent;
    return r;
  });
  check('the cart\'s lines are one card: "Shipment of 2 items", each line with its pack, a struck price and "Remove"', cart2.ship === 'Shipment of 2 items' && cart2.pack === '80 g' && cart2.was && cart2.remove === 'Remove', cart2);
  check('… + steps the quantity in place (two taps, one update) and the pay bar\'s total follows', cart2.qty === '3' && cart2.ship2 === 'Shipment of 4 items' && /₹/.test(cart2.total), cart2);
  check('… on a phone Proceed is a bar fixed to the bottom', cart2.bar === 'fixed', cart2);

  // Orders as cards, and the profile.
  const prof = await phone.evaluate(async () => {
    const sl = ms => new Promise(r => setTimeout(r, ms));
    window.fxShow('account', true); await sl(60);
    const a = document.getElementById('s-account'), card = a.querySelector('.fd-ocard');
    return { profile: !a.querySelector('[data-panel-body="dashboard"]').hidden, name: a.querySelector('.fd-profile__name')?.textContent,
      rows: [...a.querySelectorAll('.fd-profile__row')].map(r => r.textContent.trim()), nav: getComputedStyle(a.querySelector('.woocommerce-MyAccount-navigation')).display,
      cardTitle: card.querySelector('.fd-ocard__title').firstChild.textContent, foot: [...card.querySelectorAll('.fd-ocard__act')].map(b => b.textContent),
      menu: [...card.querySelectorAll('.fd-ocard__menuitem')].map(m => m.textContent), thumbs: card.querySelectorAll('.fd-ocard__thumb').length };
  });
  check('the account opens on the profile: name, then the shop\'s own doors, Log out last; no side menu on a phone', prof.profile && prof.name === 'Ateeshay Jain' && prof.rows[0] === 'Orders & reorder' && prof.rows[prof.rows.length - 1] === 'Log out' && prof.nav === 'none', prof);
  check('each order is a card: where it is, its pictures, Order again | Details, the rest behind ⋮', prof.cardTitle === 'Delivered' && prof.thumbs > 0 && prof.foot.join('|') === 'Order again|Details' && prof.menu.includes('Invoice'), prof);
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
