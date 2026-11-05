#!/usr/bin/env node
/**
 * Picks radar background (v0.48.48, W26): "the inside is bad and the outside is good".
 * The REAL radarChart() (with pickIcon, chartLabel, ICONS) from assets/js/hdlv2-staged-form.js, run in vm.
 *   - three bands on the spoke geometry, painted outer first: #ecfdf5 (3/3 of the radius), #fffbeb (2/3),
 *     #fef2f2 (1/3, on top), the status backgrounds from DESIGN.md; no stroke; before the web rings.
 *   - everything else is PR 1's head output (fe1dfd6), byte for byte, once the three bands are removed.
 * Run: node tests/second-form-copy/test-radar-bands.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import crypto from 'node:crypto';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');

const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');

const ctx = { esc: (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') };
vm.runInNewContext([cut('  var ICONS = {', '\n  };\n'), fn('pickIcon'), fn('chartLabel'), fn('radarChart')].join('\n'), ctx);

const THREE = { top: 5, spokes: [{ id: 'strength', label: 'Strength', need: 3 }, { id: 'balance', label: 'Balance', need: 1.5 }, { id: 'mind', label: 'Mind', need: 0 }] };
const SIX = { top: 5, spokes: ['strength', 'mobility', 'balance', 'stamina', 'mind', 'connection'].map((id, i) => ({ id, label: id[0].toUpperCase() + id.slice(1), need: [3.3, 3.3, 3.3, 2.7, 1, 0][i] })) };
const EMPTY = { top: 5, spokes: SIX.spokes.map((s) => Object.assign({}, s, { need: 0 })) };
// fe1dfd6 output (captured before the change) for THREE at 440 px, SIX at 300 px and EMPTY at 300 px.
const PR1 = { three: 'a01d21e0764fab571708ac381b5b7b8b55427d3c42aec952252b7f5ed4027128', six: 'fce55dd66af28c0c14ab00794e3641bb482371dcb650e92d879ddaf0896da000', empty: '1c17c06aaea8f5e8955701f5c4a5cd5617d46745ff788e0ed10be681395e9744' };

const BAND = /<polygon class="hdlv2-why-band" points="([^"]+)" fill="(#[0-9a-f]{6})"\/>/g;
const strip = (svg) => svg.replace(BAND, '');
function check(name, data, width) {
  const svg = ctx.radarChart(data, width);
  const bands = [...svg.matchAll(BAND)];
  ok(bands.map((b) => b[2]).join() === '#ecfdf5,#fffbeb,#fef2f2', `B1.${name} three bands, outer green first, inner red on top`);
  const ring = svg.indexOf('class="hdlv2-why-ring"');
  ok(bands.length === 3 && bands.every((b) => b.index < ring), `B2.${name} all three are painted before the web rings`);
  const cx = +svg.match(/data-cx="([\d.]+)"/)[1], cy = +svg.match(/data-cy="([\d.]+)"/)[1], R = +svg.match(/data-r="(\d+)"/)[1];
  const radii = bands.map((b) => b[1].trim().split(/\s+/).map((p) => { const [x, y] = p.split(',').map(Number); return Math.hypot(x - cx, y - cy) / R; }));
  ok(radii.length === 3 && radii.every((pts) => pts.length === data.spokes.length), `B3.${name} each band has one corner per spoke (${data.spokes.length})`);
  ok(radii.length === 3 && [1, 2 / 3, 1 / 3].every((want, i) => radii[i].every((d) => Math.abs(d - want) < 0.15 / R + 0.002)), `B4.${name} corners at 3/3, 2/3 and 1/3 of the radius`);
  ok(!/hdlv2-why-band"[^>]*stroke/.test(svg), `B5.${name} no stroke on the bands`);
  return sha(strip(svg));
}
console.log('── three spokes, 440 px ──');
ok(check('three', THREE, 440) === PR1.three, 'B6.three the rest is PR 1\'s output, byte for byte');
console.log('── six spokes, 300 px ──');
ok(check('six', SIX, 300) === PR1.six, 'B6.six the rest is PR 1\'s output, byte for byte');
console.log('── nothing picked yet ──');
ok(check('empty', EMPTY, 300) === PR1.empty, 'B6.empty the empty web carries the bands too; the rest unchanged');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
