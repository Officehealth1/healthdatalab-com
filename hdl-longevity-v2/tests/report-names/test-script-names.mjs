// Plan 12 of the $27 path: each script names a report through its own rn( key, today ), which reads
// the names the PHP handed in report_names (HDLV2_Report_Names::config(): no key with the switch off,
// both names with it on). Off, the script prints today's words; on, the class's names.
// Run:  node tests/report-names/test-script-names.mjs
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const scripts = {
  'assets/js/hdlv2-staged-form.js': 'hdlv2_form',
  'assets/js/hdlv2-draft-report.js': 'HDLV2_DRAFT_REPORT',
  'assets/js/hdlv2-consultation.js': 'hdlv2_consult',
  'assets/js/hdlv2-client-list-enhance.js': 'hdlv2_client_enhance',
  'assets/js/hdlv2-iris-consult.js': 'hdlv2_client_enhance',
  'assets/js/hdlv2-practitioner-dashboard.js': 'hdlv2_prac_dashboard',
  'assets/js/hdlv2-healthspan-timeline.js': 'HDLV2_HT',
};
const on = { paid: 'Longevity Report', consultation: 'Comprehensive Report' };
let pass = 0, fail = 0;
const check = (label, ok) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + label); ok ? pass++ : fail++; };

for (const [file, cfg] of Object.entries(scripts)) {
  const src = readFileSync(join(root, file), 'utf8');
  const m = src.match(/function rn\(\s*key,\s*today\s*\)\s*\{[^}]*\}/);
  check(`${file}: has rn( key, today )`, !!m);
  if (!m) continue;
  const run = (names) => {
    const win = { [cfg]: names === undefined ? {} : { report_names: names } };
    // eslint-disable-next-line no-new-func
    return new Function('window', 'root', cfg, `${m[0]}; return [rn('paid', 'Full Report'), rn('consultation', 'Trajectory Plan'), rn('free', 'Rate of ageing report')];`)(win, win, win[cfg]);
  };
  check(`${file}: switch off (no report_names) prints today's words`, JSON.stringify(run(undefined)) === JSON.stringify(['Full Report', 'Trajectory Plan', 'Rate of ageing report']));
  check(`${file}: switch on prints the class's names; the free report keeps today's`, JSON.stringify(run(on)) === JSON.stringify(['Longevity Report', 'Comprehensive Report', 'Rate of ageing report']));
}
console.log(`\nPASS=${pass} FAIL=${fail}`);
process.exit(fail ? 1 : 0);
