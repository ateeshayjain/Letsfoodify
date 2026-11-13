// theme/foodify/screenshot.png — the theme's card in Appearance → Themes (1200×900,
// WordPress's own size): the prototype's All meals screen, below the announcement
// bar (its licence number is a demo value), with the prototype's own chrome off.
//   node tools/theme-screenshot.js theme/foodify/screenshot.png
const { chromium } = require('/tmp/node_modules/playwright-core');
(async () => {
  const b = await chromium.launch();
  const pg = await b.newPage({ viewport: { width: 1200, height: 940 }, deviceScaleFactor: 1 });
  await pg.goto('file://' + require('path').resolve(__dirname, '../preview/storefront.html') + '');
  await pg.evaluate(() => localStorage.clear()); await pg.reload(); await pg.waitForTimeout(600);
  await pg.evaluate(() => window.fxShow('shop')); await pg.waitForTimeout(400);
  await pg.addStyleTag({ content: '.proto,.banner,.fd-viewcart{display:none!important}' });
  await pg.evaluate(() => window.scrollTo(0, 0)); await pg.waitForTimeout(300);
  await pg.screenshot({ path: process.argv[2], clip: { x: 0, y: 38, width: 1200, height: 900 } });
  await b.close();
})();
