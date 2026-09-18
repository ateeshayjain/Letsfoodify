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
  ['product-desktop-1','product',  1440, 900, 0],
  ['product-desktop-2','product',  1440, 900, 1500],
  ['cart-desktop',     'cart',     1440, 900, 0],
  ['checkout-desktop', 'checkout', 1440, 900, 0],
  ['account-desktop',  'account',  1440, 900, 0],
  ['signin-desktop',   'signin',   1440, 900, 0],
  ['home-phone-1',     'home',     390, 844, 0],
  ['home-phone-2',     'home',     390, 844, 900],
  ['shop-phone',       'shop',     390, 844, 420],
  ['product-phone-1',  'product',  390, 844, 0],
  ['product-phone-2',  'product',  390, 844, 1500],
  ['product-phone-3',  'product',  390, 844, 2700],
  ['cart-phone',       'cart',     390, 844, 260],
  ['checkout-phone',   'checkout', 390, 844, 200],
  ['account-phone',    'account',  390, 844, 200],
  // The cover strip.
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
  for (let [name, screen, w, h, y] of LIST) {
    if (NARROW[name]) { w = NARROW[name]; h = 860; }
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
    await page.goto(file);
    await page.addStyleTag({ content: HIDE });
    await page.evaluate((s) => {
      const t = [...document.querySelectorAll('.tab')].find(x => x.dataset.s === s);
      t.click();
    }, screen);
    await page.waitForTimeout(450);
    await page.evaluate((v) => window.scrollTo(0, v), y);
    await page.waitForTimeout(350);
    await page.screenshot({ path: `${SHOTS}/${name}.jpg`, type: 'jpeg', quality: 86 });
    await page.close();
    process.stdout.write(name + ' ');
  }
  await browser.close();
  console.log('\ndone');
})();
