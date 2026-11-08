#!/usr/bin/env node
/*
 * The product page's two scripts, run against a minimal fake DOM — the
 * behaviour the UX review of 8 Nov 2026 found wrong, pinned as behaviour
 * rather than as source text:
 *
 *  PRODUCT-2  the sticky add-to-cart bar showed on FIRST LOAD, over the price,
 *             because "the form is not intersecting" is also true when the
 *             form is simply below the fold. It must show only once the form
 *             has left the viewport UPWARDS.
 *  PRODUCT-6  the review filter chips said "5★ (62)" and filtered the five
 *             reviews on the page. When the page holds fewer reviews than the
 *             total, each chip counts the reviews it will actually show, the
 *             label says "Filter the 5 reviews shown", and a chip with none on
 *             the page is hidden.
 *
 * Both scripts are read out of the theme through PHP — the exact source the
 * page prints — never copied here. Run by tests/pdp-test.php and
 * tests/reviews-test.php; a missing node fails those suites.
 *
 *   node tests/pdp-scripts.js
 */
'use strict';
const { execFileSync } = require('child_process');
const path = require('path');
const vm = require('vm');

const INC = path.join(path.dirname(__dirname), 'theme', 'foodify', 'inc');
let pass = 0, fail = 0;
function check(label, ok) {
  if (ok) { console.log('  \x1b[32mPASS\x1b[0m ' + label); pass++; }
  else    { console.log('  \x1b[31mFAIL\x1b[0m ' + label); fail++; }
}
const php = (file, fn) => execFileSync('php', ['-r', `define('ABSPATH', 1); require '${path.join(INC, file)}'; echo ${fn}();`]).toString();

function el(tag, cls) {
  const attrs = {}, classes = new Set(String(cls || '').split(/\s+/).filter(Boolean)), on = {};
  const node = {
    tag, hidden: false, textContent: '', on, attrs, children: [],
    get className() { return [...classes].join(' '); },
    classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c),
                 toggle: (c, f) => { if (f === undefined ? !classes.has(c) : f) classes.add(c); else classes.delete(c); } },
    setAttribute: (k, v) => { attrs[k] = String(v); }, getAttribute: k => (k in attrs ? attrs[k] : null),
    removeAttribute: k => { delete attrs[k]; }, hasAttribute: k => k in attrs,
    addEventListener: (t, f) => { on[t] = f; }, focus() { node.focused = true; },
    querySelector: () => null, querySelectorAll: () => [],
  };
  return node;
}

/* ── PRODUCT-2: the sticky bar ─────────────────────────────────────────── */
{
  const src = php('product-spec.php', 'foodify_pdp_script');
  check('the theme returned the product script (' + src.length + ' bytes)', src.length > 500);
  const bar = el('div', 'fd-sticky-atc'), form = el('form', 'cart'), qty = el('input', 'qty');
  const total = el('strong'), unitEl = el('span'), btn = el('button');
  bar.hidden = true; qty.value = '1';
  bar.setAttribute('data-unit', '185');
  bar.querySelector = s => (s === '.fd-sticky-atc__total' ? total : s === '.fd-sticky-atc__qty' ? unitEl : s === 'button' ? btn : null);
  form.querySelector = s => (s === 'input.qty' ? qty : null);
  let observe = null;
  const doc = {
    querySelector: s => (s === '.fd-sticky-atc' ? bar : s === 'form.cart' ? form : null),
    getElementById: () => null,
  };
  const win = {
    IntersectionObserver: function (cb) { observe = cb; this.observe = () => {}; },
  };
  const ctx = { window: win, document: doc, IntersectionObserver: win.IntersectionObserver, location: { hash: '' },
                matchMedia: () => ({ matches: false, addEventListener() {} }), Intl };
  ctx.window = ctx;   // 'IntersectionObserver' in window
  vm.runInNewContext(src, ctx);
  check('the bar is wired to an IntersectionObserver', typeof observe === 'function');
  const fire = (isIntersecting, top) => observe([{ isIntersecting, boundingClientRect: { top, bottom: top + 120 } }]);

  fire(false, 900);   // first load on a phone: the button is BELOW the fold
  check('first load, form below the fold: the bar stays hidden (it covered the price)', bar.hidden === true);
  fire(true, 400);
  check('form on screen: hidden', bar.hidden === true);
  fire(false, -300);  // scrolled past: the form is above the viewport
  check('form scrolled off the top: the bar shows', bar.hidden === false);
  fire(true, 10);
  check('scrolled back to the form: hidden again', bar.hidden === true);
  check('the bar carries the live total', total.textContent === '₹185');

  // Arriving at #fd-notify moves focus to the form (PRODUCT-19).
  const notify = el('form');
  const ctx2 = { document: { querySelector: () => null, getElementById: id => (id === 'fd-notify' ? notify : null) },
                 location: { hash: '#fd-notify' }, matchMedia: () => ({ matches: false, addEventListener() {} }) };
  ctx2.window = ctx2;
  vm.runInNewContext(src, ctx2);
  check('arriving at #fd-notify focuses the alert form', notify.focused === true && notify.getAttribute('tabindex') === '-1');
}

/* ── PRODUCT-6: the review filter on a paged list ──────────────────────── */
function reviewPage(total, ratings, photos) {
  const src = php('reviews.php', 'foodify_review_filter_script');
  const root = el('div'), bar = el('div'), count = el('p'), clear = el('button'), label = el('p');
  root.setAttribute('data-total', String(total));
  bar.hidden = true;
  label.textContent = 'Filter reviews';
  const keys = ['5', '4', '3', '2', '1', 'photos'];
  const chips = keys.map(k => { const c = el('button', 'fd-rsum__chip'); c.setAttribute('data-fd-filter', k); c.textContent = k + '★ (total)'; return c; });
  const items = ratings.map((r, i) => el('li', 'fd-rv fd-rv--' + r + (photos.includes(i) ? ' fd-rv--photos' : '')));
  root.querySelector = s => (s === '[data-fd-review-filter]' ? bar : null);
  bar.querySelectorAll = s => (s === '[data-fd-filter]' ? chips : []);
  bar.querySelector = s => (s === '[data-fd-review-count]' ? count : s === '[data-fd-filter-clear]' ? clear : s === '[data-fd-filter-label]' ? label : null);
  const doc = {
    querySelector: s => (s === '[data-fd-review-summary]' ? root : null),
    querySelectorAll: s => (s === '.fd-rv' ? items : []),
  };
  const ctx = { document: doc, HTMLDialogElement: undefined };
  ctx.window = ctx;
  vm.runInNewContext(src, ctx);
  return { bar, chips: Object.fromEntries(keys.map((k, i) => [k, chips[i]])), label, count, items };
}
{
  // 84 reviews in all, five on this page: three 5★, two 4★, one with photos.
  const p = reviewPage(84, [5, 5, 4, 5, 4], [1]);
  check('paged: the label says what the chips can filter', p.label.textContent === 'Filter the 5 reviews shown');
  check('paged: "5★ (3)", not the total "5★ (62)"', p.chips['5'].textContent === '5★ (3)');
  check('paged: "4★ (2)"', p.chips['4'].textContent === '4★ (2)');
  check('paged: the photo chip counts the page too', p.chips.photos.textContent === 'With photos (1)');
  check('paged: the accessible name matches the visible count', p.chips['5'].getAttribute('aria-label') === '5 stars, 3 reviews');
  check('paged: a star with none on this page is not offered', p.chips['3'].hidden === true && p.chips['1'].hidden === true);
  p.chips['4'].on.click();
  check('pressing "4★ (2)" shows exactly two', p.items.filter(i => !i.hidden).length === 2);
  check('… and the count line names the page', p.count.textContent === 'Showing 2 of the 5 reviews on this page (84 in all)');

  // Everything on the page: the chips keep the server's totals and label.
  const all = reviewPage(3, [5, 4, 5], []);
  check('all loaded: the label is unchanged', all.label.textContent === 'Filter reviews');
  check('all loaded: the chips keep the server\'s text', all.chips['5'].textContent === '5★ (total)');
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
