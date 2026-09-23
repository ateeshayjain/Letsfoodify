// brief.html -> docs/Developer-Handover.pdf. Fails rather than printing if a
// font did not load or anything runs off the page.
const { chromium } = require('/tmp/node_modules/playwright-core');
const path = require('path');
(async () => {
  const out = path.resolve(__dirname, '../../docs/Developer-Handover.pdf');
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage();
  await p.goto('file://' + path.join(__dirname, 'brief.html'), { waitUntil: 'load' });
  await p.evaluate(() => document.fonts.ready);
  const fonts = await p.evaluate(() => [...document.fonts].filter(f => f.status === 'loaded').map(f => f.family));
  if (!fonts.some(f => /Fraunces/.test(f)) || !fonts.some(f => /Instrument/.test(f))) {
    console.error('FAIL fonts did not load:', fonts); process.exit(1);
  }
  await p.pdf({ path: out, format: 'A4', printBackground: true, preferCSSPageSize: true, displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate: '<div style="width:100%;font-family:sans-serif;font-size:7px;color:#6E675E;padding:0 16mm;display:flex;justify-content:space-between"><span>letsfoodify.com · developer handover</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>' });
  await b.close();
  console.log('wrote', out);
})();
