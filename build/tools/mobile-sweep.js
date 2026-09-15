// Render every preview screen at phone and desktop width in a REAL browser and
// report horizontal overflow — the thing screenshots kept finding one at a time.
// An element poking past the viewport is named with its selector and overhang.
//
// It also checks COLLISIONS, because overflow is only half of the class. A CSS
// declaration the browser REJECTS — grid-template-columns: repeat(auto-fit,
// minmax(0, max-content)), where auto-repeat needs a definite track size —
// pushes nothing past the edge. It drops the layout and stacks the items on
// top of one another, which this sweep passed clean and only an eye caught.
// Each selector below names a set of elements laid out as ONE row or strip, so
// two of them sharing pixels is a broken layout, never a design decision.
const { chromium } = require('/tmp/node_modules/playwright-core');
const path = require('path');

const ROWS = [
  '.fd-tab__button',      // the PDP tab strip (desktop) / accordion headers (phone)
  '.fd-yield-strip span', // Net · Makes · servings · Ready in
  '.fd-assure__item',     // the five assurances
  '.fd-badges > *',       // commercial badge left, dietary badge right
  '.fd-trust__item',      // the trust strip
];

// Overlaying something on an image is a promise that it fits. space-between
// does not enforce that: when two badges are wider than the box, flex pushes
// the last one straight out of it, past the card edge, and the page still has
// no horizontal overflow to report. These must stay inside their parent.
const INSIDE = ['.fd-badges > *', '.fd-chip', '.fd-save'];

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
      const report = await page.evaluate(([ROWS, INSIDE]) => {
        const vw = document.documentElement.clientWidth;
        const doc = document.documentElement.scrollWidth;
        const bad = [];
        const hits = [];
        for (const sel of ROWS) {
          const nodes = [...document.querySelectorAll(sel)].filter(el => el.getBoundingClientRect().width > 0);
          // A box narrower than the text inside it. This is what a dropped grid
          // track actually looks like: the boxes tile happily at 12px each
          // while their labels paint straight through one another.
          for (const el of nodes) {
            if (el.scrollWidth > el.clientWidth + 2) {
              hits.push(`${sel}: "${el.textContent.trim().slice(0, 20)}" is ${el.clientWidth}px wide holding ${el.scrollWidth}px of content`);
            }
          }
          const els = nodes
            .map(el => el.getBoundingClientRect())
            .filter(r => r.width > 0 && r.height > 0);
          for (let i = 0; i < els.length; i++) {
            for (let j = i + 1; j < els.length; j++) {
              const a = els[i], b = els[j];
              const ox = Math.min(a.right, b.right) - Math.max(a.left, b.left);
              const oy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
              if (ox <= 1 || oy <= 1) continue;
              // A hairline of shared edge is a border, not a collision; a
              // quarter of the smaller box is two things in one place.
              const smaller = Math.min(a.width * a.height, b.width * b.height);
              if (ox * oy > smaller * 0.25) {
                hits.push(`${sel}: two of them overlap by ${Math.round(ox)}×${Math.round(oy)}px`);
              }
            }
          }
        }
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
        for (const sel of INSIDE) {
          for (const el of document.querySelectorAll(sel)) {
            const r = el.getBoundingClientRect();
            if (!r.width) continue;
            const p = el.parentElement.getBoundingClientRect();
            const out = Math.max(r.right - p.right, p.left - r.left);
            if (out > 1) hits.push(`${sel}: "${el.textContent.trim().slice(0, 14)}" sticks ${Math.round(out)}px out of its box`);
          }
        }
        return { vw, doc, bad: [...new Set(bad)].slice(0, 6), hits: [...new Set(hits)].slice(0, 6) };
      }, [ROWS, INSIDE]);
      const overflowing = report.doc > report.vw + 1 || report.bad.length;
      if (overflowing || report.hits.length) {
        failures++;
        console.log(`  FAIL  ${label} ${String(w)}px · ${name}: scrollWidth ${report.doc} vs ${report.vw}`);
        report.bad.forEach(b => console.log(`          ${b}`));
        report.hits.forEach(h => console.log(`          ${h}`));
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
  console.log(failures ? `\n${failures} screen/width combinations overflow or collide` : '\nall screens clean at both widths');
  process.exit(failures ? 1 : 0);
})();
