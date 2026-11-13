// What a shopper SEES and can DO when real WooCommerce renders the theme — in a
// browser, from a WooCommerce nobody has set up yet to a placed order.
//
// WHY THIS EXISTS. Until 13 Nov 2026 every shop page had only been seen in the
// prototype (preview/storefront.html), which draws WooCommerce's markup itself
// and loads none of WooCommerce's CSS or scripts. The first real render found,
// among others: every My account page showing the CART, a checkout with no
// layout, orders saved without a country, filters that never drew, ten script
// errors on the shop page, a dead Order again button and a dead Install button
// (docs/WOO-RENDER-2026-11-13.md has all of them). Each is asserted below, so
// none can come back unseen. tools/wp-render-check.js is the same idea for a
// WordPress WITHOUT WooCommerce.
//
// It goes through the site as a person does:
//   1. Appearance → Foodify setup, by its buttons — then checks the DATABASE
//      (tools/wp-woo-facts.php) that each did what it said, and nothing twice.
//   2. What a shop owner adds next (tools/wp-woo-seed.php): a delivery zone,
//      going Live, a customer.
//   3. Every page type at 1280 and 390: finished, one H1, no raw code, nothing
//      under 3:1, no sideways scroll, no script error, no failed or external
//      request — plus what each page must show.
//   4. The buttons: Add on a card, the stepper, the View cart bar, the cart's
//      steppers and coupon, checkout as a guest, checkout signed in, Order
//      again, removing the samples.
//   5. The PHP error log: nothing from the theme.
//
//   node tools/wp-woo-check.js http://localhost:8793 /login-door.php /path/to/wordpress
// Run by scripts/wp-woo-test.sh, which builds the site and serves it.
const { chromium } = require('/tmp/node_modules/playwright-core');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const [BASE, LOGIN, WP] = process.argv.slice(2);
const SHOTS = process.env.FOODIFY_WOO_SHOTS || '';
let fail = 0, ran = 0;
const check = (label, ok, detail) => {
  ran++;
  console.log(`  ${ok ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'} ${label}${ok || detail === undefined || detail === '' ? '' : '\n         ' + (typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 600)}`);
  if (!ok) fail++;
};
const php = (...args) => execFileSync('php', ['-d', 'display_errors=0', ...args], { encoding: 'utf8' });
const facts = () => JSON.parse(php(path.join(__dirname, 'wp-woo-facts.php'), WP));

// The header cart's count is drawn from the server's answer — the card's own number is optimistic.
const badgeIs = (page, n) => page.waitForFunction(v => { const b = document.querySelector('.wc-block-mini-cart__badge'); return b && b.textContent.trim() === String(v); }, n, { timeout: 20000 }).catch(() => {});

// Everything a page must be, whatever page it is.
const probe = () => {
  const rgb = c => (c.match(/[\d.]+/g) || []).map(Number);
  const lum = ([r, g, b]) => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; }; return .2126 * f(r) + .7152 * f(g) + .0722 * f(b); };
  const bgOf = el => { for (let e = el; e; e = e.parentElement) { const c = rgb(getComputedStyle(e).backgroundColor); if (c.length >= 3 && (c[3] === undefined || c[3] > .5)) return c; } return [255, 255, 255]; };
  const seen = el => { const r = el.getBoundingClientRect(), s = getComputedStyle(el); return r.width > 1 && r.height > 1 && s.visibility !== 'hidden' && s.opacity !== '0'; };
  const faint = [];
  for (const el of document.querySelectorAll('body *')) {
    const text = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join(' ').trim();
    if (!text || !seen(el) || el.closest('.fd-sr,.screen-reader-text,#wpadminbar,script,style,noscript,select,[hidden]')) continue;
    const a = lum(rgb(getComputedStyle(el).color)), b = lum(bgOf(el));
    const ratio = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    if (ratio < 3) faint.push(ratio.toFixed(2) + ':1 "' + text.slice(0, 30) + '"');
  }
  const text = document.body.innerText;
  return {
    h1: document.querySelectorAll('h1').length, footer: !!document.querySelector('footer'),
    raw: (text.match(/\[\/?(?:foodify|woocommerce)_[^\]]*\]|FOODIFY_[A-Z_]+|Fatal error|Warning: |Notice: |Deprecated: |&#8377;|&amp;/g) || []),
    tokens: (document.documentElement.innerHTML.match(/<!--\s*FOODIFY_[A-Z_]+\s*-->/g) || []),
    faint: [...new Set(faint)], sideways: document.documentElement.scrollWidth - innerWidth,
    zeros: (text.match(/₹[\d,]+\.00\b/g) || []).length,
  };
};

(async () => {
  if (!BASE || !WP) { console.log('  \x1b[31mFAIL\x1b[0m no URL or WordPress path given — this check did NOT run'); process.exit(2); }
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const watch = (page, box) => {
    page.on('pageerror', e => box.push('script error: ' + e.message.slice(0, 160)));
    page.on('response', r => { if (r.status() >= 400 && r.url().startsWith(BASE) && !/favicon/.test(r.url())) box.push(r.status() + ' ' + r.url().replace(BASE, '').slice(0, 100)); });
    page.on('request', r => { if (!r.url().startsWith(BASE) && !/^(data|blob|about):/.test(r.url())) box.push('external request: ' + r.url().slice(0, 100)); });
  };
  const shot = async (page, name) => { if (SHOTS) { fs.mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }); } };

  // ── 1 · Appearance → Foodify setup, by its buttons ─────────────────────────
  console.log('── Appearance → Foodify setup, clicked through ──');
  const admin = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const ap = await admin.newPage();
  const aerr = []; ap.on('pageerror', e => aerr.push(e.message.slice(0, 160)));
  await ap.goto(BASE + LOGIN + '?u=1', { waitUntil: 'load', timeout: 90000 });
  const screen = () => ap.evaluate(() => ({
    steps: Object.fromEntries([...document.querySelectorAll('.fd-setup__step')].map(e => [e.id.replace('fd-setup-', ''), (e.className.match(/--(\w+)/) || [])[1]])),
    buttons: [...document.querySelectorAll('.fd-setup button')].map(b => b.innerText.trim()),
    text: document.querySelector('.fd-setup') ? document.querySelector('.fd-setup').innerText : '',
    installLinks: [...document.querySelectorAll('.fd-requirements a.button, .fd-requirements a')].map(a => a.getAttribute('href') || ''),
    raw: (document.body.innerText.match(/Fatal error|Warning: [^\n]*foodify[^\n]*/g) || []),
  }));
  const press = async text => {
    const b = ap.locator('.fd-setup button', { hasText: text }).first();
    if (!await b.count()) return false;
    await Promise.all([ap.waitForNavigation({ waitUntil: 'load', timeout: 120000 }), b.click()]);
    return true;
  };
  let s = await screen();
  check('the setup screen opens on a WooCommerce nobody has set up: WooCommerce done, the other four to do',
    s.steps.plugins === 'done' && s.steps.pages === 'todo' && s.steps.settings === 'todo' && s.steps.details === 'todo' && s.steps.products === 'todo' && !s.raw.length, s.steps);
  check('  it says WooCommerce is hiding the shop behind "Coming soon", and where to change that', /Coming soon/.test(s.text) && /Site visibility/.test(s.text));
  check('  the plugin Install links are escaped once (a doubly escaped link "has expired" when clicked)', s.installLinks.length > 0 && s.installLinks.every(h => !/&amp;/.test(h) && /[?&]_wpnonce=/.test(h)), s.installLinks[0]);
  check('  samples are not offered before the settings they depend on', !s.buttons.some(b => /sample/i.test(b)), s.buttons);

  check('"Create the missing pages" is there and runs', await press('missing page'));
  let f = facts();
  check('  every page the theme links to exists, WooCommerce points at its four, links are readable', !f.pages_missing.length && !f.pages_unwired.length && f.permalinks !== '', { missing: f.pages_missing, unwired: f.pages_unwired, permalinks: f.permalinks });

  check('"Apply these shop settings" is there and runs', await press('Apply these shop settings'));
  s = await screen(); f = facts();
  check('  the step turns Done', s.steps.settings === 'done', s.steps);
  check('  every setting is what the theme (and bootstrap.sh) says', !f.options_wrong.length, f.options_wrong);
  check('  the prep and dietary attributes exist, registered, with every term', Object.values(f.attributes).every(a => a.id > 0 && a.registered && a.want_terms.every(t => a.terms.includes(t))), f.attributes);
  check('  the four ranges exist as product categories', f.want_categories.every(c => f.categories.includes(c)), f.categories);

  await ap.fill('#fd-setup-tagline', '6 minute mein ghar ka khana ready!');
  await ap.fill('#fd-setup-phone', '+91 98765 43210');
  await ap.fill('#fd-setup-fssai', '1234');
  check('"Save business details" runs', await press('Save business details'));
  s = await screen(); f = facts();
  check('  a licence that is not 14 digits is refused and SAID, not stored', /14 digits/.test(s.text) && !f.details.fssai && s.steps.details === 'todo', f.details);
  check('  the headline and the phone were kept', f.tagline === '6 minute mein ghar ka khana ready!' && f.details.phone === '+91 98765 43210', f);

  check('"Add the sample products" is offered once the settings are done, and runs', await press('sample products'));
  s = await screen(); f = facts();
  check(`  all ${f.sample_slugs.length} samples exist — published, in their range, with prep, pack size, price and stock, each marked a sample`,
    f.sample_slugs.length >= 12 && f.sample_slugs.every(x => f.products.includes(x)) && !f.product_faults.length, f.product_faults.length ? f.product_faults : f.products);
  check('  the screen says they are samples and offers to remove them', /SAMPLES ARE NOT THE CATALOGUE/.test(s.text) && s.buttons.some(b => /Remove the \d+ sample/.test(b)), s.buttons);
  const again = JSON.parse(php(path.join(__dirname, 'wp-woo-facts.php'), WP, 'rerun'));
  check('  every step run a SECOND time changes nothing', again.pages === 0 && again.settings === 0 && again.samples === 0, again);
  check('  no script error on the setup screen', !aerr.length, aerr);

  // A visitor BEFORE the owner goes live: this is what the client would see.
  const early = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await early.goto(BASE + '/shop/', { waitUntil: 'load', timeout: 90000 });
  const hidden = await early.evaluate(() => document.querySelectorAll('.fd-products > li').length === 0);
  check('(a new WooCommerce really does hide the shop from visitors until someone goes Live — the reason the screen says so)', hidden);
  await early.close();

  // ── 2 · what the owner adds next ───────────────────────────────────────────
  const seed = JSON.parse(php(path.join(__dirname, 'wp-woo-seed.php'), WP).trim().split('\n').pop());
  check('a delivery zone with a flat rate and free delivery from a minimum, a customer, and the shop Live', seed.zone > 0 && seed.customer > 0 && seed.threshold > 0, seed);
  fs.writeFileSync(path.join(WP, 'wp-content', 'debug.log'), '');   // the log is read from HERE on

  // ── 3 · every page type, both widths ───────────────────────────────────────
  const PRODUCT = '/product/express-dal-fry/';
  const pages = [
    ['/', 200, 'home'], ['/shop/', 200, 'shop'], ['/product-category/express/', 200, 'category'],
    ['/shop/?filter_prep=cooking&query_type_prep=or', 200, 'filtered'],
    ['/?s=dal&post_type=product', 200, 'search'], ['/?s=zzzzqq&post_type=product', 200, 'search-none'],
    [PRODUCT, 200, 'product'], ['/product/aloo-ka-mazaa/', 200, 'product-sold-out'],
    ['/cart/', 200, 'cart-empty'], ['/my-account/', 200, 'sign-in'], ['/track-order/', 200, 'track'],
    ['/build-a-box/', 200, 'box'], ['/no-such-page-here/', 404, '404'],
  ];
  for (const width of [1280, 390]) {
    console.log(`── every page type, as a visitor, ${width}px ──`);
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    const box = []; watch(page, box);
    for (const [url, status, name] of pages) {
      box.length = 0;
      const res = await page.goto(BASE + url, { waitUntil: 'load', timeout: 90000 });
      await page.waitForTimeout(500);
      const p = await page.evaluate(probe);
      const noise = box.filter(b => !(status === 404 && b.startsWith('404 ' + url)));
      const bad = [];
      if (res.status() !== status) bad.push('status ' + res.status());
      if (!p.footer) bad.push('the page stops before its footer');
      if (p.h1 !== 1) bad.push(p.h1 + ' H1');
      if (p.raw.length || p.tokens.length) bad.push('raw code: ' + [...p.raw, ...p.tokens].slice(0, 3).join(' '));
      if (p.faint.length) bad.push('under 3:1: ' + p.faint.slice(0, 3).join(' · '));
      if (p.sideways > 1) bad.push('scrolls sideways by ' + p.sideways + 'px');
      if (p.zeros) bad.push(p.zeros + ' prices printed with .00');
      if (noise.length) bad.push([...new Set(noise)].slice(0, 3).join(' · '));
      check(`${name} ${url} @${width} — ${status}, finished, one H1, readable, no raw code, no sideways scroll, no error`, !bad.length, bad.join(' | '));
      await shot(page, `${name}-${width}`);

      if (name === 'home') {
        const h = await page.evaluate(() => ({ cards: document.querySelectorAll('.fd-row .wp-block-post.product').length, posts: [...document.querySelectorAll('.fd-row .wp-block-post')].filter(li => !li.classList.contains('product')).length,
          empty: [...document.querySelectorAll('.fd-row')].filter(r => r.offsetParent && !r.querySelector('.wp-block-post')).length }));
        check(`  the home shelves hold products (${h.cards}), never a post, never a heading over nothing`, h.cards >= 8 && h.posts === 0 && h.empty === 0, h);
      }
      if (name === 'shop') {
        const h = await page.evaluate(() => { const cards = [...document.querySelectorAll('.fd-products > li')]; return {
          h1: document.querySelector('h1').innerText, n: cards.length, veg: cards.filter(c => c.querySelector('h3 .fd-veg')).length,
          prep: cards.map(c => c.querySelectorAll('.fd-card-prep, .fd-chip').length), saleFlash: document.querySelectorAll('.onsale, .wc-block-components-product-sale-badge').length,
          perServing: [...document.querySelectorAll('.fd-products .fd-per-serving')].filter(e => e.offsetParent).length,
          terms: [...document.querySelectorAll('.fd-filters .wc-block-product-filter-checkbox-list__text')].map(e => e.textContent.trim()),
          counts: document.querySelectorAll('.fd-filters .wc-block-product-filter-checkbox-list__count').length,
          filtersSeen: innerWidth < 782 ? !!document.querySelector('.fd-filters > summary') : !!(document.querySelector('.fd-filters input[type=checkbox]') || {}).offsetParent,
          price: !!document.querySelector('.fd-filters .wc-block-product-filter-price-slider__content'), scripts: document.querySelectorAll('script[src]').length }; });
        check('  the heading is the page\'s name, not "Archives: Shop"', !/^Archives/.test(h.h1), h.h1);
        check(`  all 12 products, each with the veg mark and ONE prep line`, h.n === 12 && h.veg === 12 && h.prep.every(n => n === 2), { n: h.n, veg: h.veg, prep: h.prep });
        check('  no WooCommerce Sale! flash and no per-serving line on a card', h.saleFlash === 0 && h.perServing === 0, h);
        check(`  the filters are DRAWN: prep and dietary terms with counts, and price`, h.terms.includes('Just add hot water') && h.terms.includes('Jain') && h.counts >= 6 && h.price && h.filtersSeen, h);
        check(`  the page loads ${h.scripts} script files — the old React filters took it to 59`, h.scripts <= 30, h.scripts);
      }
      if (name === 'category') check('  the Express range lists its products', await page.evaluate(() => document.querySelectorAll('.fd-products > li').length) === 8);
      if (name === 'filtered') {
        const h = await page.evaluate(() => ({ n: document.querySelectorAll('.fd-products > li').length, checked: [...document.querySelectorAll('.fd-filters input:checked')].map(i => i.value), count: (document.querySelector('.fd-filters__count') || {}).textContent }));
        check('  a filter in the address filters the grid, ticks its box and counts itself on the Filter control', h.n === 3 && h.checked.includes('cooking') && h.count === '1', h);
      }
      if (name === 'search') check('  searching "dal" finds products', await page.evaluate(() => document.querySelectorAll('.fd-products > li.product').length) >= 2);
      if (name === 'search-none') {
        const h = await page.evaluate(() => ({ none: (document.querySelector('.fd-search-none') || {}).innerText || '', cards: document.querySelectorAll('.fd-products > li.product').length }));
        check('  a search with no match says so and shows what is popular instead', /No match/.test(h.none) && h.cards >= 1, h);
      }
      if (name === 'product') {
        const h = await page.evaluate(() => { const price = document.querySelector('.fd-pdp__buy .wc-block-components-product-price'); return {
          price: price ? parseFloat(getComputedStyle(price).fontSize) : 0, wooTabs: document.querySelectorAll('.woocommerce-tabs').length, flash: document.querySelectorAll('.onsale').length,
          tabs: [...document.querySelectorAll('.fd-tab__button')].map(b => b.innerText.trim()), mrp: ([...document.querySelectorAll('.fd-label dt')].find(d => /MRP/.test(d.innerText)) || { nextElementSibling: {} }).nextElementSibling.innerText,
          sr: (document.querySelector('.fd-pdp__buy .fd-sr') || {}).textContent || '', related: [...document.querySelectorAll('.fd-related .wp-block-post')].map(li => li.classList.contains('product')),
          tabsLeft: (document.querySelector('.fd-tabs') || document.body).getBoundingClientRect().left, railLeft: (document.querySelector('.fd-pdp') || document.body).getBoundingClientRect().left,
          veg: !!document.querySelector('h1 .fd-veg'), body: document.querySelectorAll('.fd-tabs, .fd-label').length }; });
        check('  the price is display-sized (WooCommerce spells the class has-2xl-font-size)', h.price >= 24, h.price);
        check('  WooCommerce\'s own tabs and Sale! flash are gone; the theme\'s body is there', h.wooTabs === 0 && h.flash === 0 && h.body === 2, h);
        check('  "How to make" is a tab (the prep method arrives as a slug)', h.tabs.includes('How to make'), h.tabs);
        check('  MRP reads "₹225", in the table and to a screen reader — never an entity', /^₹225/.test(h.mrp) && /MRP ₹225/.test(h.sr), { mrp: h.mrp, sr: h.sr });
        check('  "Complete the meal" lists products only', h.related.length >= 1 && h.related.every(Boolean), h.related);
        check('  the tabs sit on the page\'s left rail, not in a centred column', Math.abs(h.tabsLeft - h.railLeft) < 2, { tabs: h.tabsLeft, rail: h.railLeft });
      }
      if (name === 'product-sold-out') check('  a sold-out product offers no Add to cart', await page.evaluate(() => !document.querySelector('.single_add_to_cart_button')));
      if (name === 'cart-empty') check('  an empty cart shows no free-delivery bar', await page.evaluate(() => !(document.querySelector('[data-foodify-shipping-progress]') || {}).offsetParent));
      if (name === 'sign-in') {
        const h = await page.evaluate(() => ({ form: !!document.querySelector('form.woocommerce-form-login'), cart: !!document.querySelector('.woocommerce-cart-form, .cart-empty, .wc-empty-cart-message'),
          acct: (() => { const a = document.querySelector('.wc-block-customer-account .wc-block-customer-account__account-link'); if (!a) return null; const r = a.getBoundingClientRect(); const l = a.querySelector('.label'); return { w: Math.round(r.width), h: Math.round(r.height), label: l ? l.textContent.trim() : '', labelW: l ? Math.round(l.getBoundingClientRect().width) : -1 }; })() }));
        check('  My account is the ACCOUNT (the sign-in form) — not the cart', h.form && !h.cart, h);
        check('  the header\'s account button is the 44px icon, labelled "Sign in" for a screen reader', !!h.acct && h.acct.w >= 44 && h.acct.w <= 48 && h.acct.h === h.acct.w && h.acct.label === 'Sign in' && h.acct.labelW <= 6, h.acct);
      }
    }
    await ctx.close();
  }

  // ── 4 · the buttons ────────────────────────────────────────────────────────
  console.log('── a guest buys, on a phone (390) ──');
  const guest = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const gp = await guest.newPage(); const gbox = []; watch(gp, gbox);
  await gp.goto(BASE + '/shop/', { waitUntil: 'load', timeout: 90000 });
  const card = gp.locator('.fd-products > li', { has: gp.locator('a[href*="express-dal-fry"]') }).first();
  await card.locator('[data-fd-add]').click();
  await gp.waitForFunction(() => { const c = document.querySelector('.fd-products a[href*="express-dal-fry"]').closest('li'); return c.querySelector('[data-fd-qty]').textContent === '1' && !c.querySelector('.fd-cartctl__stepper').hidden; }, null, { timeout: 15000 }).catch(() => {});
  await badgeIs(gp, 1);
  let c1 = await card.evaluate(c => ({ qty: c.querySelector('[data-fd-qty]').textContent, stepper: !c.querySelector('.fd-cartctl__stepper').hidden }));
  check('Add on a card becomes − 1 +, without leaving the page', c1.qty === '1' && c1.stepper && /\/shop\/$/.test(gp.url()), c1);
  await card.locator('[data-fd-inc]').click();
  await gp.waitForFunction(() => document.querySelector('.fd-products a[href*="express-dal-fry"]').closest('li').querySelector('[data-fd-qty]').textContent === '2', null, { timeout: 15000 }).catch(() => {});
  await badgeIs(gp, 2);
  const bar = await gp.evaluate(() => { const b = document.querySelector('.fd-viewcart'); return { qty: document.querySelector('.fd-products a[href*="express-dal-fry"]').closest('li').querySelector('[data-fd-qty]').textContent, bar: b && !b.hidden ? b.innerText.replace(/\s+/g, ' ') : '', badge: (document.querySelector('.wc-block-mini-cart__badge') || {}).textContent }; });
  check('+ makes it 2; the View cart bar comes up with the count and what free delivery still needs', bar.qty === '2' && /View cart/.test(bar.bar) && /2 items/.test(bar.bar) && /more for free delivery/.test(bar.bar), bar);
  check('  and the header cart shows 2', String(bar.badge).trim() === '2', bar.badge);

  await gp.goto(BASE + '/cart/', { waitUntil: 'load', timeout: 90000 });
  const cart = () => gp.evaluate(() => ({ total: (document.querySelector('.order-total td') || {}).innerText, qty: [...document.querySelectorAll('.cart_item input.qty')].map(i => i.value), ship: (document.querySelector('[data-foodify-shipping-progress]') || {}).innerText || '',
    discount: !!document.querySelector('.cart_totals tr.cart-discount'), sideways: document.documentElement.scrollWidth - innerWidth }));
  let c = await cart();
  check('the cart: 2 × ₹185 + ₹59 delivery = ₹429, and the bar says what free delivery still needs', c.total === '₹429' && c.qty.join() === '2' && /₹229 away/.test(c.ship) && c.sideways <= 1, c);
  for (let i = 0; i < 2; i++) {
    await gp.locator('.cart_item [data-fd-cartqty-step="1"]').first().click();
    await gp.waitForFunction(n => (document.querySelector('.cart_item input.qty') || {}).value === String(n) && !document.querySelector('.blockUI'), 3 + i, { timeout: 20000 }).catch(() => {});
    await gp.waitForTimeout(1500);
  }
  c = await cart();
  check('the cart\'s + updates the line and the total by itself: 4 × ₹185 = ₹740, delivery free', c.qty.join() === '4' && c.total === '₹740', c);
  check('  and the free-delivery bar FOLLOWS it (it is refreshed by a cart fragment)', /Free delivery unlocked/.test(c.ship), c.ship);
  await gp.fill('#coupon_code', 'TEST10');
  await gp.locator('button[name="apply_coupon"]').click();
  await gp.waitForFunction(() => !!document.querySelector('.cart_totals tr.cart-discount'), null, { timeout: 20000 }).catch(() => {});
  await gp.waitForTimeout(800);
  c = await cart();
  check('a coupon code applies: 10% off ₹740 = ₹666', c.discount && c.total === '₹666', c);
  await shot(gp, 'cart-390');

  await gp.goto(BASE + '/checkout/', { waitUntil: 'load', timeout: 90000 });
  await gp.waitForTimeout(800);
  let co = await gp.evaluate(() => ({ fields: [...document.querySelectorAll('.woocommerce-billing-fields .form-row, .woocommerce-additional-fields .form-row')].filter(r => r.offsetParent).length,
    shipDifferent: !!document.querySelector('#ship-to-different-address-checkbox'), methods: [...document.querySelectorAll('.wc_payment_method label')].map(l => l.innerText.trim()), sideways: document.documentElement.scrollWidth - innerWidth }));
  check('checkout asks for nine fields, has no "Ship to a different address?", and offers cash on delivery', co.fields === 9 && !co.shipDifferent && co.methods.includes('Cash on delivery') && co.sideways <= 1, co);
  await gp.fill('#billing_phone', '9876543210'); await gp.fill('#billing_email', 'guest@example.org'); await gp.fill('#billing_first_name', 'Priya Sharma');
  await gp.fill('#billing_postcode', '201304'); await gp.locator('#billing_postcode').blur(); await gp.waitForTimeout(2500);
  const pin = await gp.evaluate(() => ({ city: document.querySelector('#billing_city').value, state: document.querySelector('#billing_state').value }));
  check('the PIN code fills the city and the state', pin.city !== '' && pin.state === 'UP', pin);
  await gp.fill('#billing_address_1', 'N-7011 Parx Laureate'); await gp.waitForTimeout(2000);
  await shot(gp, 'checkout-390');
  await gp.locator('#place_order').click();
  await gp.waitForURL(/order-received/, { timeout: 60000 }).catch(() => {});
  await gp.waitForTimeout(800);
  const rec = await gp.evaluate(() => ({ h1: (document.querySelector('h1') || {}).innerText, heads: [...document.querySelectorAll('main h2')].map(h => h.innerText.trim()), text: document.querySelector('main') ? document.querySelector('main').innerText : '', sideways: document.documentElement.scrollWidth - innerWidth,
    errors: [...document.querySelectorAll('.woocommerce-NoticeGroup, .woocommerce-error')].map(e => e.innerText.trim().slice(0, 200)) }));
  check('Place order lands on "Order received"', /order-received/.test(gp.url()) && rec.h1 === 'Order received', rec.errors.length ? rec.errors : gp.url());
  check('  it says what happens next, the amount to keep ready, and ONE address — headed "Delivery address", with the state by name', /Keep ₹666 ready in cash/.test(rec.text) && rec.heads.filter(h => /address/i.test(h)).join() === 'Delivery address' && /Uttar Pradesh/.test(rec.text) && /Delivery:/.test(rec.text) && !/Shipping/.test(rec.text) && rec.sideways <= 1, rec.heads);
  await shot(gp, 'order-received-390');
  f = facts();
  const go = f.orders[f.orders.length - 1] || {};
  check('  the ORDER in the database: processing, cash on delivery, ₹666, country IN, a delivery address, the full name split', go.status === 'processing' && go.payment === 'cod' && go.total === 666 && go.billing_country === 'IN' && go.shipping_country === 'IN' && go.shipping_address_1 === 'N-7011 Parx Laureate' && go.name === 'Priya Sharma', go);
  check('  no script error, failed request or outside request on the way', !gbox.length, [...new Set(gbox)].slice(0, 4));
  await guest.close();

  console.log('── a returning customer buys, on a laptop (1280) ──');
  const cust = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const cp = await cust.newPage(); const cbox = []; watch(cp, cbox);
  await cp.goto(BASE + LOGIN + '?u=' + seed.customer + '&to=' + encodeURIComponent(PRODUCT), { waitUntil: 'load', timeout: 90000 });
  await cp.locator('.single_add_to_cart_button').first().click();
  await cp.waitForFunction(() => { const b = document.querySelector('.wc-block-mini-cart__badge'); return b && b.textContent.trim() === '1'; }, null, { timeout: 20000 }).catch(() => {});
  check('Add to cart on the product page adds in place (the header cart shows 1, the page did not change)', await cp.evaluate(() => (document.querySelector('.wc-block-mini-cart__badge') || {}).textContent.trim() === '1') && cp.url().includes(PRODUCT));
  await cp.goto(BASE + '/checkout/', { waitUntil: 'load', timeout: 90000 }); await cp.waitForTimeout(800);
  co = await cp.evaluate(() => { const a = document.querySelector('#customer_details').getBoundingClientRect(), b = document.querySelector('#order_review').getBoundingClientRect(), h = document.querySelector('#order_review_heading').getBoundingClientRect();
    return { name: document.querySelector('#billing_first_name').value, twoCols: b.left >= a.right - 1 && Math.abs(h.left - b.left) < 2 && b.top < a.bottom, inside: b.right <= innerWidth && document.querySelector('.woocommerce-checkout-review-order-table').getBoundingClientRect().right <= b.right + 1,
      place: (() => { const p = document.querySelector('#place_order').getBoundingClientRect(); return p.left >= b.left && p.right <= b.right + 1; })() }; });
  check('checkout is two columns — the fields, and "Your order" as a card beside them holding the totals and the button', co.twoCols && co.inside && co.place, co);
  check('  "Full name" carries the customer\'s WHOLE name (they were saved as first + last)', co.name === 'Asha Verma', co.name);
  await shot(cp, 'checkout-1280');
  await cp.locator('#place_order').click();
  await cp.waitForURL(/order-received/, { timeout: 60000 }).catch(() => {});
  f = facts();
  const so = f.orders[f.orders.length - 1] || {};
  check('  their order is placed and is theirs: ₹244 (₹185 + ₹59), Asha Verma, to their saved address', /order-received/.test(cp.url()) && so.customer === seed.customer && so.total === 244 && so.name === 'Asha Verma' && so.shipping_address_1 === '14 Civil Lines', so);

  const acct = async url => { await cp.goto(BASE + url, { waitUntil: 'load', timeout: 90000 }); await cp.waitForTimeout(400); return cp.evaluate(probe); };
  const okPage = p => p.h1 === 1 && p.footer && !p.raw.length && !p.faint.length && p.sideways <= 1;
  let p = await acct('/my-account/');
  let a = await cp.evaluate(() => ({ profile: !!document.querySelector('.fd-profile'), cart: !!document.querySelector('.woocommerce-cart-form'), text: document.querySelector('main').innerText,
    nav: (document.querySelector('.woocommerce-MyAccount-navigation') || document.body).getBoundingClientRect().width, content: (document.querySelector('.woocommerce-MyAccount-content') || document.body).getBoundingClientRect().width, avatar: document.querySelectorAll('header img.avatar, header img[src*="gravatar"]').length }));
  check('My account is the PROFILE — name, mobile, email — not the cart', okPage(p) && a.profile && !a.cart && /Asha Verma/.test(a.text) && /9876543210/.test(a.text), p);
  check('  laid out as a menu beside the page (the menu is the narrow column)', a.nav < 300 && a.content > 600, { nav: a.nav, content: a.content });
  check('  and the header shows the icon, not a Gravatar', a.avatar === 0);
  await shot(cp, 'account-1280');
  p = await acct('/my-account/orders/');
  a = await cp.evaluate(() => ({ cards: document.querySelector('main').innerText, again: [...document.querySelectorAll('main a')].filter(x => /Order again/.test(x.innerText)).map(x => x.getAttribute('href')) }));
  check('Orders lists the order as a card, with where it is and Order again', okPage(p) && /Being packed/.test(a.cards) && /₹244/.test(a.cards) && a.again.length >= 1, p);
  check('  the Order again link is escaped once (escaped twice it opens the cart and adds nothing)', a.again.every(h => !/&amp;/.test(h) && /_wpnonce=/.test(h)), a.again[0]);
  p = await acct('/my-account/view-order/' + so.id + '/');
  a = await cp.evaluate(() => ({ steps: [...document.querySelectorAll('main li')].map(l => l.innerText.replace(/\s+/g, ' ').trim()).filter(t => /Placed|Being packed|Shipped|Delivered/.test(t)).length, text: document.querySelector('main').innerText,
    again: [...document.querySelectorAll('main a, main button')].filter(x => x.offsetParent && /^Order again$/.test(x.innerText.trim())).length, heads: [...document.querySelectorAll('main h2')].map(h => h.innerText.trim()) }));
  check('Order summary shows the four steps, the lines and the delivery address', okPage(p) && a.steps >= 4 && /Where your order is/.test(a.text) && a.heads.includes('Delivery address'), a.heads);
  check('  one Order again, no second "Actions:" row, "Delivery: ₹59" without "via Delivery"', a.again === 1 && !/Actions:/.test(a.text) && /Delivery:\s*₹59/.test(a.text) && !/via Delivery/.test(a.text), { again: a.again });
  await shot(cp, 'order-summary-1280');
  await Promise.all([cp.waitForNavigation({ waitUntil: 'load', timeout: 60000 }), cp.locator('main a', { hasText: /^Order again$/ }).first().click()]);
  await cp.waitForTimeout(600);
  a = await cp.evaluate(() => ({ url: location.pathname, lines: [...document.querySelectorAll('.cart_item .product-name')].map(n => n.innerText.replace(/\s+/g, ' ').trim()), qty: [...document.querySelectorAll('.cart_item input.qty')].map(i => i.value) }));
  check('Order again puts that order back in the cart', a.url === '/cart/' && a.lines.length === 1 && /Express Dal Fry/.test(a.lines[0]) && a.qty.join() === '1', a);
  for (const [url, name] of [['/my-account/edit-address/', 'Addresses'], ['/my-account/edit-account/', 'Your details']]) { p = await acct(url); check(`${name} opens: finished, one H1, readable`, okPage(p), p); }
  check('  no script error, failed request or outside request for a signed-in customer (gravatar.com was one)', !cbox.length, [...new Set(cbox)].slice(0, 4));
  await cust.close();

  // ── the samples go, and only the samples ───────────────────────────────────
  console.log('── removing the samples ──');
  php('-r', 'require $argv[1] . "/wp-load.php"; $p = new WC_Product_Simple(); $p->set_name( "A real product" ); $p->set_slug( "a-real-product" ); $p->set_regular_price( "99" ); $p->set_status( "publish" ); $p->save();', WP);
  await ap.goto(BASE + '/wp-admin/themes.php?page=foodify-setup', { waitUntil: 'load', timeout: 90000 });
  check('"Remove the sample products" is there and runs', await press('Remove the'));
  s = await screen(); f = facts();
  check('  exactly the samples are gone; a product the shop added itself is untouched', f.products.join() === 'a-real-product' && s.steps.products === 'done', f.products);
  await admin.close();

  // ── 5 · the PHP error log ──────────────────────────────────────────────────
  const log = fs.readFileSync(path.join(WP, 'wp-content', 'debug.log'), 'utf8').split('\n')
    .filter(l => /PHP (Fatal|Parse)|themes\/foodify|foodify/i.test(l) && !/wp_(update|version)_|_load_textdomain_just_in_time.*woocommerce/.test(l));
  check('the PHP error log holds nothing from the theme, and no fatal, for the whole run', !log.length, log.slice(0, 3).join(' ¶ '));

  await browser.close();
  console.log(`\n${fail ? fail + ' of ' + ran + ' failed' : 'real-WooCommerce render: clean (' + ran + ' checks)'}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.log('  \x1b[31mFAIL\x1b[0m the check crashed and did NOT finish: ' + (e.stack || e.message).split('\n').slice(0, 3).join(' | ')); process.exit(2); });
