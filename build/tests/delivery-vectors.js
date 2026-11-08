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

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
