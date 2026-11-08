#!/usr/bin/env node
/*
 * The delivery widget's JS against the SAME vectors the PHP reference passes.
 *
 * WHY THIS EXISTS. The date is computed in the browser (see the note at the top
 * of inc/delivery-estimate.php), so there are two implementations of one rule.
 * Two implementations drift; this is what notices. The script is read out of
 * the theme through PHP — the exact source the page prints — never copied here.
 *
 *   node tests/delivery-vectors.js
 */
'use strict';
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const vm = require('vm');

const KIT = path.dirname(__dirname);
const inc = path.join(KIT, 'theme', 'foodify', 'inc', 'delivery-estimate.php');
let pass = 0, fail = 0;
function check(label, ok) {
  if (ok) { console.log('  \x1b[32mPASS\x1b[0m ' + label); pass++; }
  else    { console.log('  \x1b[31mFAIL\x1b[0m ' + label); fail++; }
}

const src = execFileSync('php', ['-r', `define('ABSPATH', 1); require '${inc}'; echo foodify_delivery_script();`]).toString();
check('the theme returned a script (' + src.length + ' bytes) — an empty source is not a pass', src.length > 1000);

const sandbox = { window: {} };
vm.runInNewContext(src, sandbox);
const api = sandbox.window.foodifyDelivery;
check('the script exposes window.foodifyDelivery.answer without a DOM', !!api && typeof api.answer === 'function');

const vec = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'delivery-vectors.json'), 'utf8'));
check('there are vectors to run (' + vec.cases.length + ')', vec.cases.length >= 15);

// Normalised config is what the page ships; the PHP side normalises the same
// raw config, and normalising is idempotent (pinned in lane-a-test.php).
const norm = JSON.parse(execFileSync('php', ['-r',
  `define('ABSPATH', 1); require '${inc}'; $c = json_decode($argv[1], true); $o = []; foreach ($c as $k => $v) { $o[$k] = foodify_delivery_normalise_config($v); } echo json_encode($o);`,
  JSON.stringify(vec.configs)]).toString());

for (const c of vec.cases) {
  const got = api.answer(c.pin, c.lookup, norm[c.config], Date.parse(c.now));
  const same = JSON.stringify(got) === JSON.stringify(c.expect);
  check(c.name + (same ? '' : '\n        want ' + JSON.stringify(c.expect) + '\n        got  ' + JSON.stringify(got)), same);
}

// ── the widget's error state (UX review, PRODUCT-18) ─────────────────────
// A bad or unknown PIN marks the FIELD, not just the message: aria-invalid on
// the input and the error class on both, cleared again by a good answer. Run
// against a minimal fake DOM, through the same source the page prints.
(async () => {
  function el(tag) {
    const attrs = {}, cls = new Set(), kids = [], on = {};
    return {
      tag, hidden: true, value: '', textContent: '', kids, on,
      get className() { return [...cls].join(' '); },
      set className(v) { cls.clear(); String(v).split(/\s+/).filter(Boolean).forEach(c => cls.add(c)); },
      classList: { add: c => cls.add(c), remove: c => cls.delete(c), contains: c => cls.has(c),
                   toggle: (c, f) => { if (f === undefined ? !cls.has(c) : f) cls.add(c); else cls.delete(c); } },
      setAttribute: (k, v) => { attrs[k] = String(v); }, getAttribute: k => (k in attrs ? attrs[k] : null),
      removeAttribute: k => { delete attrs[k]; }, hasAttribute: k => k in attrs,
      addEventListener: (t, f) => { on[t] = f; }, appendChild: c => kids.push(c), attrs,
    };
  }
  const box = el('div'), form = el('form'), input = el('input'), out = el('p');
  box.setAttribute('data-config', JSON.stringify(norm.main));
  box.setAttribute('data-pin-url', '/pin/');
  box.querySelector = sel => (sel === 'form' ? form : sel === 'input' ? input : out);
  const lookups = { '201304': { city: 'Gautam Buddha Nagar', state: 'UP' } };
  const win = { localStorage: { getItem: () => null, setItem: () => {} },
                foodifyPinLookup: pin => lookups[pin] || null };
  const doc = { querySelectorAll: () => [box], createElement: el };
  vm.runInNewContext(src, { window: win, document: doc, Promise, Date });
  const tick = () => new Promise(r => setTimeout(r, 0));
  const submit = async v => { input.value = v; form.on.submit({ preventDefault() {} }); await tick(); await tick(); };

  check('the widget un-hides itself once bound', box.hidden === false);
  await submit('2013');
  check('a short PIN: aria-invalid="true" on the field', input.getAttribute('aria-invalid') === 'true');
  check('… the field carries is-invalid, the message is-error',
    input.classList.contains('is-invalid') && out.classList.contains('is-error'));
  await submit('201304');
  check('a good PIN clears both', input.getAttribute('aria-invalid') === null && !input.classList.contains('is-invalid') && out.classList.contains('is-ok'));
  await submit('999999');
  check('a PIN the table does not know is an error on the field too (it was a grey "no")',
    input.getAttribute('aria-invalid') === 'true' && out.classList.contains('is-error'));

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})();
