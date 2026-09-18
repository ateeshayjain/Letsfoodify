// design-book.html -> docs/Foodify-Storefront-Design.pdf.
// The overflow check is the point: a bullet list that grows by one line runs
// under the footer, and a PDF gives no warning — it just prints it cut off.
const { chromium } = require('/tmp/node_modules/playwright-core');
const path = require('path');

(async () => {
  const here = __dirname;
  const out = path.resolve(here, '../../docs/Foodify-Storefront-Design.pdf');
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const page = await browser.newPage();
  await page.goto('file://' + path.join(here, 'design-book.html'), { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => Promise.all([...document.images].map(i => i.complete ? 1 : new Promise(r => { i.onload = i.onerror = r; }))));
  await page.waitForTimeout(500);

  const missing = await page.evaluate(() => [...document.images].filter(i => !i.naturalWidth).map(i => i.src));
  const over = await page.evaluate(() => {
    const bad = [];
    document.querySelectorAll('.page').forEach((pg, i) => {
      const r = pg.getBoundingClientRect();
      pg.querySelectorAll('*').forEach(el => {
        const e = el.getBoundingClientRect();
        if (e.height && e.bottom > r.bottom - 18) bad.push(`page ${i + 1}: ${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]}`);
      });
    });
    return [...new Set(bad)];
  });
  if (missing.length) { console.error('FAIL images did not load:', missing.slice(0, 5)); process.exit(1); }
  if (over.length)    { console.error('FAIL content runs into the page footer:', over); process.exit(1); }

  await page.pdf({
    path: out, printBackground: true, preferCSSPageSize: true, displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate: '<div style="width:100%;font-family:sans-serif;font-size:7px;color:#6E675E;padding:0 14mm 4mm;text-align:right"><span class="pageNumber"></span> / <span class="totalPages"></span></div>',
  });
  await browser.close();
  console.log('wrote', out);
})();
