const { chromium } = require('/tmp/node_modules/playwright-core');
const path = require('path');
const fs = require('fs');
const SHOTS = path.resolve(__dirname, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });

// Client-facing shots: the mock's own chrome (screen switcher, preview banner)
// is hidden, so these read as the design rather than as a tool.
const HIDE = `.proto, .banner { display: none !important; }`;

const LIST = [
  // [file, screen, width, height, scrollY]
  ['home-desktop-1',   'home',     1440, 900, 0],
  ['home-desktop-2',   'home',     1440, 900, 1000],
  ['home-desktop-3',   'home',     1440, 900, 2100],
  ['shop-desktop',     'shop',     1440, 900, 0],
  ['shop-desktop-2',   'shop',     1440, 900, 700],
  ['product-desktop-1','product',  1440, 900, 0, { setup: "const w=document.querySelector('#s-p-express-dal-fry .fd-deliver'); w.querySelector('input').value='201304'; w.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));" }],
  ['product-desktop-2','product',  1440, 900, 1500],
  ['cart-desktop',     'cart',     1440, 900, 0],
  ['checkout-desktop', 'checkout', 1440, 900, 0],
  ['account-desktop',  'account',  1440, 900, 0],
  ['signin-desktop',   'signin',   1440, 900, 0],
  ['home-phone-1',     'home',     390, 844, 0],
  ['home-phone-2',     'home',     390, 844, 900],
  ['shop-phone',       'shop',     390, 844, 420],
  ['product-phone-1',  'product',  390, 844, 0, { setup: "const w=document.querySelector('#s-p-express-dal-fry .fd-deliver'); w.querySelector('input').value='201304'; w.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));" }],
  ['product-phone-2',  'product',  390, 844, 1500],
  ['product-phone-3',  'product',  390, 844, 2700],
  ['cart-phone',       'cart',     390, 844, 260],
  ['checkout-phone',   'checkout', 390, 844, 200],
  ['account-phone',    'account',  390, 844, 200],
  // Combos — the pack of several meals, and the box's own page.
  ['combos-desktop',   'combos',   1440, 900, 300],
  ['combos-phone',     'combos',   390, 844, 520],
  ['combo-desktop',    'combo',    1440, 900, 1250],
  ['combo-phone',      'combo',    390, 844, 1880],
  // Filters and sorting, close enough to read — and the phone panel OPEN,
  // which is the state a reviewer never sees by scrolling past it.
  ['filters-desktop',  'shop',     1440, 900, 430, { clip: { x: 130, y: 0, width: 1180, height: 620 } }],
  ['filters-phone',    'shop',     390, 844, 380, { open: 'details.fd-filters' }],
  // The cover strip.
  // The Nov 2026 features. `setup` runs in the page after the screen is shown;
  // `scrollTo` puts that element at the top; `type` types into a field like a person.
  ['search-desktop',   'search',   1440, 900, 0],
  ['search-phone',     'home',     390, 844, 0, { click: '#s-home .fd-search__input', type: ['#s-home .fd-search__input', 'moong'] }],
  ['box-desktop',      'box',      1440, 900, 0, { setup: "const b=document.getElementById('s-box'); b.querySelector('input[name=box_size][value=\"6\"]').click(); const p=b.querySelectorAll('.fd-box__plus'); [0,0,1,2].forEach(i=>p[i].click());" }],
  ['box-phone',        'box',      390, 844, 560, { setup: "const b=document.getElementById('s-box'); b.querySelector('input[name=box_size][value=\"6\"]').click(); const p=b.querySelectorAll('.fd-box__plus'); [0,0,1,2].forEach(i=>p[i].click());" }],
  ['buybox-desktop',   'product',  1440, 900, 0, { setup: "const w=document.querySelector('#s-p-express-dal-fry .fd-deliver'); w.querySelector('input').value='201304'; w.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));", clipCol: '#s-p-express-dal-fry .fd-deliver' }],
  ['buybox-phone',     'product',  390, 844, 0, { setup: "const w=document.querySelector('#s-p-express-dal-fry .fd-deliver'); w.querySelector('input').value='201304'; w.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));", scrollTo: '#s-p-express-dal-fry .fd-offers' }],
  ['notify-phone',     'p-aloo-ka-mazaa', 390, 844, 0, { scrollTo: '#s-p-aloo-ka-mazaa #fd-notify' }],
  ['reviews-desktop',  'product',  1440, 900, 0, { scrollTo: '#s-p-express-dal-fry #reviews' }],
  ['reviews-phone',    'product',  390, 844, 0, { setup: "document.querySelector('#s-p-express-dal-fry .fd-rsum__chip[data-fd-filter=\"photos\"]').click();", scrollTo: '#s-p-express-dal-fry .fd-rsum__filter' }],
  ['actions-desktop',  'account',  1440, 900, 0, { setup: "document.querySelector('#s-account [data-view=\"1194\"]').click(); document.querySelector('#s-account #fd-return').open=true;", clipEl: '#s-account .fx-vo-actions' }],
  ['repeat-desktop',   'account',  1440, 900, 0, { setup: "document.querySelector('#s-account [data-view=\"1194\"]').click(); document.querySelector('#s-account .fd-repeat__form button').click(); document.querySelector('#s-account [data-panel=\"repeat-orders\"]').click();", clipEl: '#s-account .woocommerce' }],
  ['again-phone',      'home',     390, 844, 0, { scrollTo: '#s-home .fd-again' }],
  ['refer-desktop',    'account',  1440, 900, 0, { setup: "document.querySelector('#s-account [data-panel=\"refer-a-friend\"]').click();" }],
  ['optins-phone',     'checkout', 390, 844, 0, { scrollTo: '#s-checkout #fx-co-mobile', guest: true }],
  ['cover-a',          'home',     390, 780, 0],
  ['cover-c',          'product',  390, 780, 0],
  ['cover-d',          'cart',     390, 780, 260],
];

// The tabbed product body is a narrower frame than the rest — at 1440 half the
// picture is empty, because the pack table is a single column by design.
const NARROW = { 'product-desktop-2': 1150 };

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const file = 'file://' + path.resolve(__dirname, '../../preview/storefront.html');
  for (let [name, screen, w, h, y, opts = {}] of LIST) {
    if (NARROW[name]) { w = NARROW[name]; h = 860; }
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
    await page.goto(file);
    await page.addStyleTag({ content: HIDE });
    // The prototype now has a screen per product; the book's "product" and
    // "combo" pages are the Dal Fry and the Trial Box, as they always were.
    const ALIAS = { product: 'p-express-dal-fry', combo: 'p-trial-box-3-meals' };
    await page.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
    await page.evaluate((s) => window.fxShow(s, false), ALIAS[screen] || screen);
    await page.waitForTimeout(450);
    // A guest sees empty checkout fields, with the opt-ins under mobile and email.
    if (opts.guest) {
      await page.evaluate(() => { window.fxShow('account', false); document.querySelector('#s-account [data-panel="customer-logout"]').click(); });
      await page.evaluate((s) => window.fxShow(s, false), ALIAS[screen] || screen);
    }
    if (opts.setup) { await page.evaluate(opts.setup); await page.waitForTimeout(400); }
    if (opts.click) { await page.click(opts.click); await page.waitForTimeout(250); }
    if (opts.type) { await page.click(opts.type[0]); await page.keyboard.type(opts.type[1], { delay: 40 }); await page.waitForTimeout(400); }
    await page.evaluate((v) => window.scrollTo(0, v), y);
    if (opts.scrollTo) {
      await page.evaluate((sel) => { const e = document.querySelector(sel); if (e) window.scrollTo(0, e.getBoundingClientRect().top + window.scrollY - 24); }, opts.scrollTo);
    }
    // The buy box alone, cropped to its column, so the delivery check and offers read at full size.
    if (opts.clipCol) {
      opts.clip = await page.evaluate((sel) => {
        const e = document.querySelector(sel); const col = e && (e.closest('.wp-block-column') || e.parentElement);
        window.scrollTo(0, col.getBoundingClientRect().top + window.scrollY - 16);
        const r = col.getBoundingClientRect();
        return { x: Math.max(0, r.left - 16), y: 0, width: Math.min(r.width + 32, 760), height: Math.min(r.height + 32, 880) };
      }, opts.clipCol);
    }
    await page.waitForTimeout(350);
    // Some states only exist after a tap — the phone filter panel is closed on
    // load (the theme closes it), and a screenshot of a closed panel is not a
    // picture of the filter design.
    if (opts.open) {
      await page.evaluate((sel) => {
        const d = [...document.querySelectorAll(sel)].find(e => e.getBoundingClientRect().width);
        if (d) d.open = true;
      }, opts.open);
      await page.waitForTimeout(250);
    }
    // Just this element, for screens that are mostly margin at laptop width.
    if (opts.clipEl) {
      opts.clip = await page.evaluate((sel) => {
        const e = document.querySelector(sel); window.scrollTo(0, e.getBoundingClientRect().top + window.scrollY - 24);
        const r = e.getBoundingClientRect();
        return { x: Math.max(0, r.left - 24), y: 0, width: Math.min(r.width + 48, 1100), height: Math.min(r.height + 48, 640) };
      }, opts.clipEl);
      await page.waitForTimeout(200);
    }
    await page.screenshot({ path: `${SHOTS}/${name}.jpg`, type: 'jpeg', quality: 86, ...(opts.clip ? { clip: opts.clip } : {}) });
    await page.close();
    process.stdout.write(name + ' ');
  }
  await browser.close();
  console.log('\ndone');
})();
