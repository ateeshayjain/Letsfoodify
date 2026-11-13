// What a visitor SEES when real WordPress renders the theme — in a browser, with
// WordPress's own block stylesheet loaded.
//
// WHY THIS EXISTS. The prototype (preview/storefront.html) is built from the
// theme's files but does not load WordPress's block CSS and does not run
// WordPress's render pipeline. Two defects lived in that gap until someone
// installed the zip on a blank WordPress (13 Nov 2026):
//   · the hero's H1 and outline button were WHITE on kraft-pale — a cover block
//     is white text unless it declares a light overlay;
//   · the reviews section printed `[foodify_google_reviews limit="3"]` — a
//     shortcode inside a pattern is never run by WordPress.
// wp-boot-test.sh reads PHP diagnostics; neither of these is one. This looks.
//
// A third lived there too: is_shop() called unguarded, a FATAL on every page but
// the home page — a blank page with a 200 — on a site without WooCommerce.
//
// WooCommerce is not installed in this WordPress (see wp-boot-test.sh), so this
// covers a site WITHOUT it — exactly what a person sees after uploading the zip
// to an empty WordPress: the home page, every page the theme links to (after
// Appearance → Foodify setup has created them), a post, search and a 404; and
// the setup screen itself. The shop pages WITH WooCommerce still need staging.
//
//   node tools/wp-render-check.js http://localhost:8765 [/login-file.php]
const { chromium } = require('/tmp/node_modules/playwright-core');
const BASE = process.argv[2], LOGIN = process.argv[3] || '';
let fail = 0;
const check = (label, ok, detail) => { console.log(`  ${ok ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'} ${label}${ok || !detail ? '' : '\n         ' + detail}`); if (!ok) fail++; };

(async () => {
  if (!BASE) { console.log('  \x1b[31mFAIL\x1b[0m no URL given — this check DID NOT RUN'); process.exit(2); }
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    const res = await page.goto(BASE + '/', { waitUntil: 'load', timeout: 60000 });
    await page.waitForTimeout(400);
    const at = `@${width}`;
    check(`the home page answers 200 ${at}`, res.status() === 200, 'status ' + res.status());
    const seen = await page.evaluate(() => {
      const rgb = c => (c.match(/[\d.]+/g) || []).map(Number);
      const lum = ([r, g, b]) => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; }; return .2126 * f(r) + .7152 * f(g) + .0722 * f(b); };
      const bgOf = el => { for (let e = el; e; e = e.parentElement) { const c = rgb(getComputedStyle(e).backgroundColor); if (c.length >= 3 && (c[3] === undefined || c[3] > .5)) return c; } return [255, 255, 255]; };
      const faint = [];
      for (const el of document.querySelectorAll('h1,h2,h3,h4,p,a,span,li,button,label,summary')) {
        const text = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join(' ').trim();
        if (!text || !el.offsetParent || el.closest('.fd-sr,.screen-reader-text,#wpadminbar')) continue;
        const a = lum(rgb(getComputedStyle(el).color)), b = lum(bgOf(el));
        const ratio = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
        if (ratio < 3) faint.push(ratio.toFixed(2) + ':1 "' + text.slice(0, 40) + '"');
      }
      const text = document.body.innerText;
      const emptyShelves = [...document.querySelectorAll('.fd-row')].filter(r => r.offsetParent && !r.querySelector('.wp-block-post')).length;
      const postsInShelves = [...document.querySelectorAll('.fd-row .wp-block-post')].filter(li => !li.classList.contains('product')).length;
      const reviews = document.querySelector('.fd-reviews-section');
      return { faint, raw: (text.match(/\[\/?foodify_[^\]]*\]|FOODIFY_[A-Z_]+/g) || []), emptyShelves, postsInShelves,
        emptyReviews: !!(reviews && reviews.offsetParent && !reviews.querySelector('.fd-review')),
        h1: document.querySelectorAll('h1').length, sideways: document.documentElement.scrollWidth > innerWidth + 1 };
    });
    check(`no text a visitor cannot read (under 3:1 against its background) ${at}`, !seen.faint.length, seen.faint.slice(0, 6).join(' · '));
    check(`no raw shortcode or template token on the page ${at}`, !seen.raw.length, seen.raw.slice(0, 4).join(' · '));
    check(`no shelf is a heading over nothing ${at}`, seen.emptyShelves === 0, seen.emptyShelves + ' empty');
    check(`a shelf never lists blog posts ${at}`, seen.postsInShelves === 0, seen.postsInShelves + ' posts');
    check(`no reviews heading over no reviews ${at}`, !seen.emptyReviews);
    check(`one H1 ${at}`, seen.h1 === 1, seen.h1 + ' found');
    check(`the page does not scroll sideways ${at}`, !seen.sideways);
    check(`no script error ${at}`, !errors.length, errors.slice(0, 3).join(' · '));
    await page.close();
  }

  // Every page: finished (a fatal cuts the document short — its footer never
  // arrives), headed, readable, and free of raw code.
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const look = async (path, want) => {
    const res = await page.goto(BASE + path, { waitUntil: 'load', timeout: 60000 });
    const s = await page.evaluate(() => ({ footer: !!document.querySelector('footer'), h1: document.querySelectorAll('h1').length,
      raw: (document.body.innerText.match(/\[\/?(?:foodify|woocommerce)_[^\]]*\]|FOODIFY_[A-Z_]+|Fatal error|Warning: /g) || []) }));
    check(`${path} — ${want.status}, finished, one H1, no raw code`, res.status() === want.status && s.footer && s.h1 === 1 && !s.raw.length,
      `status ${res.status()} · footer ${s.footer} · ${s.h1} h1 · ${s.raw.slice(0, 3).join(' ')}`);
  };
  await page.goto(BASE + '/', { waitUntil: 'load' });
  const links = await page.evaluate(() => [...new Set([...document.querySelectorAll('header a[href], footer a[href]')].map(a => new URL(a.href))
    .filter(u => u.origin === location.origin).map(u => u.pathname))]);
  // Product categories and the partner page are WooCommerce's and the coupon
  // module's; without WooCommerce they do not exist, and that is not this check's.
  const pages = links.filter(p => p !== '/' && !/^\/(product-category|partner|wp-)/.test(p));
  check(`the header and footer link to pages (${pages.length})`, pages.length >= 8, pages.join(' '));
  for (const p of pages) await look(p, { status: 200 });
  for (const p of ['/cart/', '/checkout/']) await look(p, { status: 200 });
  await look('/this-page-does-not-exist/', { status: 404 });

  if (LOGIN) {
    await page.goto(BASE + LOGIN, { waitUntil: 'load', timeout: 60000 });
    const s = await page.evaluate(() => ({ steps: [...document.querySelectorAll('.fd-setup__step')].map(e => e.id.replace('fd-setup-', '') + ':' + (e.className.match(/--(\w+)/) || [])[1]),
      raw: (document.body.innerText.match(/Fatal error|Warning: [^\n]*foodify[^\n]*/g) || []), install: !!document.querySelector('.fd-requirements a.button') }));
    check('Appearance → Foodify setup opens, with its five steps', s.steps.length === 5 && !s.raw.length, s.steps.join(' ') + ' ' + s.raw.join(' '));
    check('  pages are done; the shop steps wait for WooCommerce', s.steps.includes('pages:done') && s.steps.includes('settings:blocked') && s.steps.includes('products:blocked'), s.steps.join(' '));
    check('  and it offers the Install WooCommerce button', s.install);
  }
  await page.close();
  await browser.close();
  console.log(`\n${fail ? fail + ' failed' : 'real-WordPress render: clean'}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.log('  \x1b[31mFAIL\x1b[0m the check crashed: ' + e.message.split('\n')[0]); process.exit(2); });
