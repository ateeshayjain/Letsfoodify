// The mock has to be clickable, because a reviewer clicks.
//
// WHY THIS EXISTS. The client clicked the cart in the header and nothing
// happened: only the tab strip at the top was wired, so every door the page
// itself offers — the cart pill, the account icon, a product card, Add to
// cart, the nav, the logo — was dead. Worse, once the theme's real hrefs were
// wired in, an unprevented click NAVIGATED away from the single file to a page
// that does not exist here, which looks identical to a dead click.
//
// So each door is opened here, and the screen it lands on is asserted by name.
//
//   node tests/preview-doors.js
const { chromium } = require('/tmp/node_modules/playwright-core');
const path = require('path');

const DOORS = [
  // [what the reviewer clicks, selector, the screen it must open]
  ['the header cart pill',   '.wc-block-mini-cart__button',            'cart'],
  ['the account icon',       '.wc-block-customer-account__account-link','account'],
  ['a nav category',         '.fx-nav a',                              'shop'],
  ['the logo',               '.fx-logo a',                             'home'],
  ['a product card title',   '.fd-products .wp-block-post-title a',    'product'],
  ['Add to cart on a card',  '.add_to_cart_button',                    'cart'],
  ['a footer shop link',     'footer a[data-s=shop]',                  'shop'],
];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.goto('file://' + path.resolve(__dirname, '../preview/storefront.html'));
  await page.waitForTimeout(300);

  let passed = 0, failed = 0;
  const shown = () => page.evaluate(() =>
    [...document.querySelectorAll('[id^=s-]')].filter(e => !e.hidden).map(e => e.id.slice(2)).join(',') || '(none)');
  const goHome = () => page.evaluate(() =>
    [...document.querySelectorAll('.tab')].find(t => t.dataset.s === 'home').click());

  // Nothing may point at a screen that does not exist — that hides every panel.
  const orphans = await page.evaluate(() => {
    const screens = new Set([...document.querySelectorAll('[id^=s-]')].map(e => e.id.slice(2)));
    return [...document.querySelectorAll('[data-s]')]
      .map(e => e.dataset.s).filter(s => s && !screens.has(s));
  });
  if (orphans.length) { failed++; console.log(`  FAIL  doors pointing at no screen: ${[...new Set(orphans)].join(', ')}`); }
  else { passed++; console.log('  PASS  every door points at a screen that exists'); }

  for (const [what, sel, expect] of DOORS) {
    const clicked = await page.evaluate((s) => {
      const el = [...document.querySelectorAll(s)].find(e => e.getBoundingClientRect().width > 0);
      if (!el) return false;
      el.click();
      return true;
    }, sel);
    await page.waitForTimeout(150);
    const now = clicked ? await shown() : 'NOT FOUND';
    if (now === expect) { passed++; console.log(`  PASS  ${what} opens ${expect}`); }
    else { failed++; console.log(`  FAIL  ${what} -> ${now}, expected ${expect}`); }
    await goHome();
    await page.waitForTimeout(100);
  }

  await browser.close();
  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
