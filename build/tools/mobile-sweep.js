// Render every preview screen at phone and desktop width in a REAL browser and
// report horizontal overflow — the thing screenshots kept finding one at a time.
// An element poking past the viewport is named with its selector and overhang.
const { chromium } = require('/tmp/node_modules/playwright-core');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const file = 'file://' + path.resolve(__dirname, '../preview/storefront.html');
  let failures = 0;

  for (const [label, w, h] of [['phone', 390, 844], ['desktop', 1280, 900]]) {
    const page = await browser.newPage({ viewport: { width: w, height: h } });
    await page.goto(file);
    const tabs = await page.$$('[role=tablist] .tab');
    for (const tab of tabs) {
      const name = (await tab.textContent()).trim();
      await tab.click();
      await page.waitForTimeout(120);
      const report = await page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const doc = document.documentElement.scrollWidth;
        const bad = [];
        for (const el of document.querySelectorAll('body *')) {
          const r = el.getBoundingClientRect();
          if (r.width === 0) continue;
          // Inside a scroll container is fine — that is what overflow-x:auto is for.
          let p = el.parentElement, scrollOK = false;
          while (p) {
            const o = getComputedStyle(p).overflowX;
            if (o === 'auto' || o === 'scroll') { scrollOK = true; break; }
            p = p.parentElement;
          }
          if (scrollOK) continue;
          if (r.right > vw + 1 || r.left < -1) {
            const id = el.id ? '#' + el.id : '';
            const cls = el.className && typeof el.className === 'string'
              ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
            bad.push(`${el.tagName.toLowerCase()}${id}${cls} overhang ${Math.round(Math.max(r.right - vw, -r.left))}px`);
          }
        }
        return { vw, doc, bad: [...new Set(bad)].slice(0, 6) };
      });
      const overflowing = report.doc > report.vw + 1 || report.bad.length;
      if (overflowing) {
        failures++;
        console.log(`  FAIL  ${label} ${String(w)}px · ${name}: scrollWidth ${report.doc} vs ${report.vw}`);
        report.bad.forEach(b => console.log(`          ${b}`));
      } else {
        console.log(`  PASS  ${label} ${String(w)}px · ${name}`);
      }
      if (label === 'phone') {
        await page.screenshot({ path: `/tmp/shots/${name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-phone.png`, fullPage: true });
      }
    }
    await page.close();
  }
  await browser.close();
  console.log(failures ? `\n${failures} screen/width combinations overflow` : '\nall screens clean at both widths');
  process.exit(failures ? 1 : 0);
})();
