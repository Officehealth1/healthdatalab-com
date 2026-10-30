/**
 * HDL V2 — Healthspan Timeline (v0.48.29; layout v0.48.30; Phosphor duotone icons v0.48.32;
 * a glyph on every heading, sliders drawn by the page v0.48.33; linked people v0.48.34).
 *
 * Matthew's Cowork v3 page (evidence copy of 2026-10-13), ported into the
 * plugin. The model functions keep the prototype's names so the Cowork page
 * and tests/healthspan-timeline stay comparable. Data comes from
 * window.HDLV2_HT, printed by HDLV2_Healthspan_Timeline:
 *   mode 'full'    — the client's report numbers, loved ones editable;
 *   mode 'preview' — the client's own lane live, loved ones blurred behind
 *                    the offer line. Without a token the numbers come from
 *                    the URL fragment (#a=&s=&r=&n=), which never reaches a server.
 * Loved ones stay in this browser (localStorage, one key per client); with HDLV2_HT.links
 * (v0.48.34) the client can also invite one by link, and a loved one who said yes is drawn
 * from their own report's numbers (HDLV2_Timeline_Links on the server).
 * The curve is the report's own engine (HDLTrajectoryChart); no second model.
 */
(function (root) {
  'use strict';

  /* ---------- knobs (DA4: the calculation review signs or changes them) ---------- */
  var HDLV2_HT_KNOBS = {
    recovK: 2.5,    // biological years recovered per 0.1 of pace change: placeholder, Matthew to sign
    recovT: 3,      // years for a shift to settle in: placeholder, Matthew to sign
    compK: 1.5,     // decline length ∝ pace^compK: placeholder, Matthew to sign
    fsMax: 0.6,     // future science, slowing at "wild" 30 years after fsStart: placeholder, Matthew to sign
    fsStart: 2035,  // future science start year: placeholder, Matthew to sign
    // share of the usual time together left when YOU are in each stage, by closeness: placeholder, Matthew to sign
    STAGE_TIME: { close: [1, 0.9, 0.65, 0.4, 0.05], distant: [1, 0.75, 0.45, 0.2, 0.02] },
    // hours a week together: American Time Use Survey pattern (no UK equivalent)
    DEFAULT_HRS: { spouse: 35, child: 14, grandchild: 3, parent: 3, sibling: 2, friend: 2, other: 2 },
    // the four preset paces: placeholder, Matthew to sign
    PROFILES: [
      { n: 'Poor', r: 1.25, c: 'var(--ht-red)', d: 'Sedentary, poor sleep, no plan', icon: 'couch' },
      { n: 'Average', r: 1.00, c: 'var(--ht-yellow)', d: 'Ageing with the calendar', icon: 'person-simple-walk' },
      { n: 'Healthy', r: 0.85, c: 'var(--ht-green)', d: 'Trains, eats well, sleeps, measures', icon: 'person-simple-run' },
      { n: 'Optimal', r: 0.70, c: 'var(--ht-accent)', d: 'Everything that is known to work, consistently', icon: 'sparkle' }
    ],
    // biological age where Yellow, Orange, Red, Grey start and the lane ends, at 1.00×.
    // Green end = UK healthy life expectancy, end = UK life expectancy at birth (ONS 2021–23);
    // the three between: placeholder, Matthew to sign.
    STAGE_BOUNDS_F: [61.9, 72.3, 78.6, 81.8, 82.8],
    STAGE_BOUNDS_M: [61.5, 70.2, 75.4, 77.8, 78.8],
    // share living with 2+ conditions by age (Barnett et al., 2012, UK)
    MM: [[20, 5], [30, 10], [40, 16], [50, 25], [60, 40], [70, 60], [80, 78], [90, 85], [100, 90]]
  };
  var K = HDLV2_HT_KNOBS;

  /* ---------- model ---------- */
  var NOW_Y = new Date().getFullYear(), NOW_F = NOW_Y + (new Date().getMonth() + 0.5) / 12;
  var STAGES = [
    { k: 1, name: 'Green', label: 'Free and capable', c: 'var(--ht-green)', hex: '#3fae4a', desc: 'Living on your own terms. Not perfect health, but able to do most of what you want.' },
    { k: 2, name: 'Yellow', label: 'Early limits', c: 'var(--ht-yellow)', hex: '#e0b128', desc: 'Limits are present most days: long walks and travel get harder, more time on medication and appointments. Still your own life.' },
    { k: 3, name: 'Orange', label: 'Health takes priority', c: 'var(--ht-orange)', hex: '#e5761f', desc: 'Managing health is a big part of the day. Others start covering for you.' },
    { k: 4, name: 'Red', label: 'Dependent', c: 'var(--ht-red)', hex: '#c93437', desc: 'Going out is a mission. Others run parts of your life for you.' },
    { k: 5, name: 'Grey', label: 'Bedridden', c: 'var(--ht-grey)', hex: '#b4bcb9', desc: 'Bedridden or in hospice care. Most of the day is spent coping.' }
  ];
  var REL = ['spouse', 'child', 'grandchild', 'parent', 'sibling', 'friend', 'other'];
  var CLOSE = { spouse: 'close', child: 'close', parent: 'close', grandchild: 'distant', sibling: 'distant', friend: 'distant', other: 'distant' };

  var S;
  function defaultPeople(you) {
    return [
      { id: 'p1', name: 'Spouse', rel: 'spouse', born: you.born + 5, sex: you.sex === 'F' ? 'M' : 'F', rate: 'same' },
      { id: 'p2', name: 'Child', rel: 'child', born: you.born + 25, sex: 'F', rate: 'same' },
      { id: 'p3', name: 'Grandchild', rel: 'grandchild', born: you.born + 50, sex: 'M', rate: 'same' }
    ];
  }
  function fresh(cfg) {
    var y = (cfg && cfg.you) || {};
    var you = {
      name: y.name || 'You', born: +y.born || NOW_Y - 50, sex: y.sex === 'M' ? 'M' : 'F',
      bio: y.bio == null ? '' : y.bio, rate0: +y.rate0 || 1, life: lifestyleFromScores(y.life_scores), lifeSource: y.life_source || ''
    };
    you.rate = you.rate0;
    return { mode: cfg && cfg.mode === 'full' ? 'full' : 'preview', tab: 'you', you: you, people: defaultPeople(you), zoom: 0, fs: 0 };
  }

  var rateOf = function (p) { return p === S.you ? +S.you.rate : (p.rate === 'same' ? +S.you.rate : +p.rate); };
  var rate0Of = function (p) { return p === S.you ? +S.you.rate0 : (p.rate === 'same' ? +S.you.rate0 : +p.rate); };
  var bioNow = function (p) { return p === S.you && S.you.bio !== '' && !isNaN(+S.you.bio) ? +S.you.bio : (NOW_F - p.born); };
  var fsFrac = function () { return S.fs / 100; };
  function dividend(rate, fs) { var foundation = Math.max(0, Math.min(1, (1.6 - rate) / 0.9)); return K.fsMax * fs * (0.4 + 0.6 * foundation); }
  // biological age at calendar t for pace r, starting pace r0 (recovery = shift response), fs = future-science fraction 0..1
  function bioAtRate(p, t, r, r0, fs) {
    var base = bioNow(p); if (t <= NOW_F) return base - r0 * (NOW_F - t);
    var acc;
    if (!fs || t <= K.fsStart || NOW_F >= K.fsStart + 30) acc = r * (t - NOW_F);
    else {
      var d = dividend(r, fs), T0 = Math.max(K.fsStart, NOW_F), T1 = K.fsStart + 30; acc = r * Math.max(0, T0 - NOW_F); var t1 = Math.min(t, T1);
      acc += r * ((t1 - T0) - d * (Math.pow(t1 - K.fsStart, 2) - Math.pow(T0 - K.fsStart, 2)) / (2 * 30)); if (t > T1) acc += r * (1 - d) * (t - T1);
    }
    var R = (r0 - r) * 10 * K.recovK; var rec = R * (1 - Math.exp(-(t - NOW_F) / K.recovT));
    return base + acc - rec;
  }
  var bioAt = function (p, t) { return bioAtRate(p, t, rateOf(p), rate0Of(p), fsFrac()); };
  // stage boundaries for a person on pace r: green boundary fixed, later ones compressed/stretched by r^compK
  function stagesFor(p, r) { var T = p.sex === 'M' ? K.STAGE_BOUNDS_M : K.STAGE_BOUNDS_F; var f = Math.pow(Math.max(0.5, Math.min(1.6, r)), K.compK); return T.map(function (v, i) { return i === 0 ? v : T[0] + (v - T[0]) * f; }); }
  function stageOfBio(p, b, r) { var T = stagesFor(p, r); if (b >= T[4]) return 6; for (var i = 0; i < 4; i++) if (b < T[i]) return i + 1; return 5; }
  var stageAt = function (p, t) { return t < p.born ? 0 : stageOfBio(p, bioAt(p, t), rateOf(p)); };
  var stageAtRate = function (p, t, r, r0, fs) { return t < p.born ? 0 : stageOfBio(p, bioAtRate(p, t, r, r0, fs), r); };
  function capability(p, b, r) { var T = stagesFor(p, r); var pts = [[30, 100], [T[0], 70], [T[1], 50], [T[2], 30], [T[3], 15], [T[4], 5]]; if (b <= 30) return 100; for (var i = 1; i < pts.length; i++) { if (b <= pts[i][0]) { var a0 = pts[i - 1][0], v0 = pts[i - 1][1], a1 = pts[i][0], v1 = pts[i][1]; return v0 + (v1 - v0) * (b - a0) / (a1 - a0); } } return 5; }
  function multimorb(b) { var MM = K.MM; if (b <= 20) return 3; for (var i = 1; i < MM.length; i++) if (b <= MM[i][0]) { var a0 = MM[i - 1][0], v0 = MM[i - 1][1], a1 = MM[i][0], v1 = MM[i][1]; return v0 + (v1 - v0) * (b - a0) / (a1 - a0); } return 92; }
  function difficulty(age, rate) { var d = 1 + Math.max(0, age - 30) / 8; d += Math.max(0, rate - 1) * 4; return Math.max(1, Math.min(10, d)); }
  function gainPotential(life) { var h = (5 - Math.min(5, Math.max(1, life))) / 4; return Math.round(100 * (1 - Math.exp(-2.2 * h)) / (1 - Math.exp(-2.2))); }
  function yearsByStage(p, r, r0, fs) { var out = [0, 0, 0, 0, 0]; for (var t = NOW_F; t < NOW_F + 140; t += 1 / 12) { var s = stageOfBio(p, bioAtRate(p, t, r, r0, fs), r); if (s === 6) break; out[s - 1] += 1 / 12; } return out; }
  // weeks of real time together, bucketed by YOUR stage
  function weeksTogether(p, mode) { // mode "new" | "ref"
    var rY = mode === 'new' ? rateOf(S.you) : rate0Of(S.you), r0Y = rate0Of(S.you), fs = mode === 'new' ? fsFrac() : 0;
    var rP = mode === 'new' ? rateOf(p) : rate0Of(p), r0P = rate0Of(p);
    var hrs = K.DEFAULT_HRS[p.rel] != null ? K.DEFAULT_HRS[p.rel] : 2, prof = K.STAGE_TIME[CLOSE[p.rel] || 'distant']; var out = [0, 0, 0, 0, 0];
    for (var t = NOW_F; t < NOW_F + 140; t += 1 / 12) {
      if (t < p.born) continue; var sy = stageAtRate(S.you, t, rY, r0Y, fs), sp = stageAtRate(p, t, rP, r0P, fs); if (sy >= 6 || sp >= 6) break; if (sy === 0 || sp === 0) continue;
      out[sy - 1] += hrs * (52 / 12) * prof[sy - 1] * prof[sp - 1];
    }
    return out.map(function (h) { return h / (7 * 16); }); // full weeks of 16 waking hours
  }

  /* ---------- inputs from the server and the fragment ---------- */
  // Mean of the values the server picked (the 12 lifestyle scores, or the Stage 1 answers), one decimal; none → null.
  function lifestyleFromScores(x) {
    var v = x && typeof x === 'object' ? Object.keys(x).map(function (k) { return x[k]; }).filter(function (n) { return typeof n === 'number' && isFinite(n); }) : [];
    return v.length ? Math.round(10 * v.reduce(function (a, b) { return a + b; }, 0) / v.length) / 10 : null;
  }
  function bornFromAge(age, iso) { return (iso ? +String(iso).slice(0, 4) : NOW_Y) - age; }
  // #a=<age>&s=<F|M>&r=<rate>&n=<first name>; anything out of range → null (the page opens on its defaults).
  function readFragment(hash) {
    var q = {};
    String(hash || '').replace(/^#/, '').split('&').forEach(function (kv) {
      var i = kv.indexOf('='); if (i < 1) return;
      try { q[kv.slice(0, i)] = decodeURIComponent(kv.slice(i + 1)); } catch (e) { /* malformed escape: ignore the pair */ }
    });
    var a = +q.a, r = +q.r;
    if (!(a >= 18 && a <= 100 && Math.floor(a) === a) || (q.s !== 'F' && q.s !== 'M') || !(r >= 0.6 && r <= 1.6)) return null;
    r = Math.round(r * 100) / 100;
    return { name: String(q.n || '').trim().slice(0, 40) || 'You', born: bornFromAge(a), sex: q.s, rate0: r, bio: Math.round(r * a * 10) / 10 };
  }
  function paceLabel(r, src) {
    return src === 'report'
      ? 'Your pace of ageing from your report: ' + (+r).toFixed(2) + '× (1.00× = ageing with the calendar)'
      : 'Your pace of ageing from your free check';
  }
  function visibleZoomBands() { return ['year', 'month']; } // DA5: weeks removed
  // whole years as people say their age (52.8 → 52, as the facts line); one decimal on the month band
  function ageLabel(age, unit) { return age < 0 ? '–' : unit === 'year' ? String(Math.floor(age)) : age.toFixed(1); }

  /* ---------- helpers ---------- */
  var rootEl = null;
  var $ = function (s) { return rootEl.querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var stageHex = function (k) { return k >= 1 && k <= 5 ? STAGES[k - 1].hex : 'transparent'; };
  var others = function () { return S.people.concat((S.links || []).map(function (l) { return linkedPerson(l, S.you); })); };
  var people = function () { return [S.you].concat(others()).sort(function (a, b) { return a.born - b.born; }); };
  var visiblePeople = function () { return S.mode === 'preview' ? [S.you] : people(); };
  /* ---------- icons: Phosphor duotone (github.com/phosphor-icons/core, assets/duotone) ----------
     MIT License, Copyright (c) 2023 Phosphor Icons. Geometry 1:1, inlined, no runtime dependency, no CDN.
     The one icon family on this page (Quim, 2026-10-30: itshover has no lifestyle glyphs); itshover stays
     the rule everywhere else. Each entry: [the soft layer, drawn at opacity 0.2; the outline], both filled. */
  var HT_ICONS = {
    'couch': ['M240,120v48a8,8,0,0,1-8,8H24a8,8,0,0,1-8-8V120a8,8,0,0,1,8-8V72a8,8,0,0,1,8-8H224a8,8,0,0,1,8,8v40A8,8,0,0,1,240,120Z', 'M240,106.17V72a16,16,0,0,0-16-16H32A16,16,0,0,0,16,72v34.17A16,16,0,0,0,8,120v48a16,16,0,0,0,16,16h8v16a8,8,0,0,0,16,0V184H208v16a8,8,0,0,0,16,0V184h8a16,16,0,0,0,16-16V120A16,16,0,0,0,240,106.17ZM224,104h-8a16,16,0,0,0-16,16v16H136V72h88ZM120,72v64H56V120a16,16,0,0,0-16-16H32V72Zm112,96H24V120H40v24a8,8,0,0,0,8,8H208a8,8,0,0,0,8-8V120h16Z'],
    'person-simple-walk': ['M176,48a24,24,0,1,1-24-24A24,24,0,0,1,176,48Z', 'M152,80a32,32,0,1,0-32-32A32,32,0,0,0,152,80Zm0-48a16,16,0,1,1-16,16A16,16,0,0,1,152,32Zm64,112a8,8,0,0,1-8,8c-35.31,0-52.95-17.81-67.12-32.12-2.74-2.77-5.36-5.4-8-7.84l-13.43,30.88,37.2,26.57A8,8,0,0,1,160,176v56a8,8,0,0,1-16,0V180.12l-31.07-22.2L79.34,235.19A8,8,0,0,1,72,240a7.84,7.84,0,0,1-3.19-.67,8,8,0,0,1-4.15-10.52l54.08-124.37c-9.31-1.65-20.92,1.2-34.7,8.58a163.88,163.88,0,0,0-30.57,21.77,8,8,0,0,1-10.95-11.66c2.5-2.35,61.69-57.22,98.72-25.08,3.83,3.32,7.48,7,11,10.58C166.19,122.7,179.36,136,208,136A8,8,0,0,1,216,144Z'],
    'person-simple-run': ['M176,56a24,24,0,1,1-24-24A24,24,0,0,1,176,56Z', 'M152,88a32,32,0,1,0-32-32A32,32,0,0,0,152,88Zm0-48a16,16,0,1,1-16,16A16,16,0,0,1,152,40Zm67.31,100.68c-.61.28-7.49,3.28-19.67,3.28-13.85,0-34.55-3.88-60.69-20a169.31,169.31,0,0,1-15.41,32.34,104.29,104.29,0,0,1,31.31,15.81C173.92,186.65,184,207.35,184,232a8,8,0,0,1-16,0c0-41.7-34.69-56.71-54.14-61.85-.55.7-1.12,1.41-1.69,2.1-19.64,23.8-44.25,36.18-71.63,36.18A92.29,92.29,0,0,1,31.2,208,8,8,0,0,1,32.8,192c25.92,2.59,48.47-7.49,67-30,12.49-15.14,21-33.61,25.25-47C86.13,92.34,61.27,111.63,61,111.84A8,8,0,1,1,51,99.36c1.5-1.2,37.22-29,89.51,6.57,45.47,30.91,71.93,20.31,72.18,20.19a8,8,0,1,1,6.63,14.56Z'],
    'sparkle': ['M194.82,151.43l-55.09,20.3-20.3,55.09a7.92,7.92,0,0,1-14.86,0l-20.3-55.09-55.09-20.3a7.92,7.92,0,0,1,0-14.86l55.09-20.3,20.3-55.09a7.92,7.92,0,0,1,14.86,0l20.3,55.09,55.09,20.3A7.92,7.92,0,0,1,194.82,151.43Z', 'M197.58,129.06,146,110l-19-51.62a15.92,15.92,0,0,0-29.88,0L78,110l-51.62,19a15.92,15.92,0,0,0,0,29.88L78,178l19,51.62a15.92,15.92,0,0,0,29.88,0L146,178l51.62-19a15.92,15.92,0,0,0,0-29.88ZM137,164.22a8,8,0,0,0-4.74,4.74L112,223.85,91.78,169A8,8,0,0,0,87,164.22L32.15,144,87,123.78A8,8,0,0,0,91.78,119L112,64.15,132.22,119a8,8,0,0,0,4.74,4.74L191.85,144ZM144,40a8,8,0,0,1,8-8h16V16a8,8,0,0,1,16,0V32h16a8,8,0,0,1,0,16H184V64a8,8,0,0,1-16,0V48H152A8,8,0,0,1,144,40ZM248,88a8,8,0,0,1-8,8h-8v8a8,8,0,0,1-16,0V96h-8a8,8,0,0,1,0-16h8V72a8,8,0,0,1,16,0v8h8A8,8,0,0,1,248,88Z'],
    'person-arms-spread': ['M104,40a24,24,0,1,1,24,24A24,24,0,0,1,104,40ZM212,80H44c-12.87,0-16.71,17.5-5,22.9L96,128,73.13,214.93a12,12,0,0,0,21.75,10.14L128,168l33.12,57.07a12,12,0,0,0,21.75-10.14L160,128l57-25.1C228.69,97.5,224.85,80,212,80Z', 'M160,40a32,32,0,1,0-32,32A32,32,0,0,0,160,40ZM128,56a16,16,0,1,1,16-16A16,16,0,0,1,128,56ZM231.5,87.71A19.62,19.62,0,0,0,212,72H44a20,20,0,0,0-8.38,38.16l.13.06,50.75,22.35-21,79.72A20,20,0,0,0,102,228.82l26-44.88,26,44.88a20,20,0,0,0,36.4-16.53l-21-79.72,50.75-22.35.13-.06A19.63,19.63,0,0,0,231.5,87.71Zm-17.8,7.9-56.93,25.07a8,8,0,0,0-4.51,9.36L175.13,217a7,7,0,0,0,.49,1.35,4,4,0,1,1-7.25,3.38c-.11-.22-.22-.43-.34-.63L134.92,164a8,8,0,0,0-13.84,0L88,221.06c-.12.2-.23.41-.34.63a4,4,0,1,1-7.25-3.38,7,7,0,0,0,.49-1.35L103.74,130a8,8,0,0,0-4.51-9.36L42.3,95.61A4,4,0,0,1,44,88H212a4,4,0,0,1,1.73,7.61Z'],
    'users-three': ['M168,144a40,40,0,1,1-40-40A40,40,0,0,1,168,144ZM64,56A32,32,0,1,0,96,88,32,32,0,0,0,64,56Zm128,0a32,32,0,1,0,32,32A32,32,0,0,0,192,56Z', 'M244.8,150.4a8,8,0,0,1-11.2-1.6A51.6,51.6,0,0,0,192,128a8,8,0,0,1,0-16,24,24,0,1,0-23.24-30,8,8,0,1,1-15.5-4A40,40,0,1,1,219,117.51a67.94,67.94,0,0,1,27.43,21.68A8,8,0,0,1,244.8,150.4ZM190.92,212a8,8,0,1,1-13.85,8,57,57,0,0,0-98.15,0,8,8,0,1,1-13.84-8,72.06,72.06,0,0,1,33.74-29.92,48,48,0,1,1,58.36,0A72.06,72.06,0,0,1,190.92,212ZM128,176a32,32,0,1,0-32-32A32,32,0,0,0,128,176ZM72,120a8,8,0,0,0-8-8A24,24,0,1,1,87.24,82a8,8,0,1,0,15.5-4A40,40,0,1,0,37,117.51,67.94,67.94,0,0,0,9.6,139.19a8,8,0,1,0,12.8,9.61A51.6,51.6,0,0,1,64,128,8,8,0,0,0,72,120Z'],
    'lock': ['M216,96V208a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V96a8,8,0,0,1,8-8H208A8,8,0,0,1,216,96Z', 'M208,80H176V56a48,48,0,0,0-96,0V80H48A16,16,0,0,0,32,96V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V96A16,16,0,0,0,208,80ZM96,56a32,32,0,0,1,64,0V80H96ZM208,208H48V96H208V208Zm-68-56a12,12,0,1,1-12-12A12,12,0,0,1,140,152Z'],
    // v0.48.33: one glyph per heading and per moment card
    'heartbeat': ['M232,102c0,66-104,122-104,122S24,168,24,102A54,54,0,0,1,78,48c22.59,0,41.94,12.31,50,32,8.06-19.69,27.41-32,50-32A54,54,0,0,1,232,102Z', 'M72,144H32a8,8,0,0,1,0-16H67.72l13.62-20.44a8,8,0,0,1,13.32,0l25.34,38,9.34-14A8,8,0,0,1,136,128h24a8,8,0,0,1,0,16H140.28l-13.62,20.44a8,8,0,0,1-13.32,0L88,126.42l-9.34,14A8,8,0,0,1,72,144ZM178,40c-20.65,0-38.73,8.88-50,23.89C116.73,48.88,98.65,40,78,40a62.07,62.07,0,0,0-62,62c0,.75,0,1.5,0,2.25a8,8,0,1,0,16-.5c0-.58,0-1.17,0-1.75A46.06,46.06,0,0,1,78,56c19.45,0,35.78,10.36,42.6,27a8,8,0,0,0,14.8,0c6.82-16.67,23.15-27,42.6-27a46.06,46.06,0,0,1,46,46c0,53.61-77.76,102.15-96,112.8-10.83-6.31-42.63-26-66.68-52.21a8,8,0,1,0-11.8,10.82c31.17,34,72.93,56.68,74.69,57.63a8,8,0,0,0,7.58,0C136.21,228.66,240,172,240,102A62.07,62.07,0,0,0,178,40Z'],
    'mountains': ['M144,52a20,20,0,1,1,20,20A20,20,0,0,1,144,52Zm49.44,55.92a8,8,0,0,0-13.77,0l-33,55.75-21-35.67H50.35L8,200H248Z', 'M164,80a28,28,0,1,0-28-28A28,28,0,0,0,164,80Zm0-40a12,12,0,1,1-12,12A12,12,0,0,1,164,40Zm90.88,155.92-54.56-92.08A15.87,15.87,0,0,0,186.55,96h0a15.85,15.85,0,0,0-13.76,7.84L146.63,148l-44.84-76.1a16,16,0,0,0-27.58,0L1.11,195.94A8,8,0,0,0,8,208H248a8,8,0,0,0,6.88-12.08ZM88,80l23.57,40H64.43ZM22,192l33-56h66l33,56Zm150.57,0-16.66-28.28L186.55,112,234,192Z'],
    'trend-up': ['M232,56v64L168,56Z', 'M232,48H168a8,8,0,0,0-5.66,13.66L188.69,88,136,140.69l-34.34-34.35a8,8,0,0,0-11.32,0l-72,72a8,8,0,0,0,11.32,11.32L96,123.31l34.34,34.35a8,8,0,0,0,11.32,0L200,99.31l26.34,26.35A8,8,0,0,0,240,120V56A8,8,0,0,0,232,48Zm-8,52.69L187.31,64H224Z'],
    'hourglass': ['M188.82,82,128,128,67.2,82.4A8,8,0,0,1,64,76V40a8,8,0,0,1,8-8H184a8,8,0,0,1,8,8V75.64A8,8,0,0,1,188.82,82ZM64,180v36a8,8,0,0,0,8,8H184a8,8,0,0,0,8-8V180.36a8,8,0,0,0-3.18-6.38L128,128,67.2,173.6A8,8,0,0,0,64,180Z', 'M200,75.64V40a16,16,0,0,0-16-16H72A16,16,0,0,0,56,40V76a16.07,16.07,0,0,0,6.4,12.8L114.67,128,62.4,167.2A16.07,16.07,0,0,0,56,180v36a16,16,0,0,0,16,16H184a16,16,0,0,0,16-16V180.36a16.09,16.09,0,0,0-6.35-12.77L141.27,128l52.38-39.59A16.09,16.09,0,0,0,200,75.64ZM184,216H72V180l56-42,56,42.35Zm0-140.36L128,118,72,76V40H184Z'],
    'chart-line-up': ['M224,64V208H32V48H208A16,16,0,0,1,224,64Z', 'M232,208a8,8,0,0,1-8,8H32a8,8,0,0,1-8-8V48a8,8,0,0,1,16,0V156.69l50.34-50.35a8,8,0,0,1,11.32,0L128,132.69,180.69,80H160a8,8,0,0,1,0-16h40a8,8,0,0,1,8,8v40a8,8,0,0,1-16,0V91.31l-58.34,58.35a8,8,0,0,1-11.32,0L96,123.31l-56,56V200H224A8,8,0,0,1,232,208Z'],
    'hand-heart': ['M16,152H48v56H16a8,8,0,0,1-8-8V160A8,8,0,0,1,16,152ZM192.54,40A39.12,39.12,0,0,0,156,64a39.12,39.12,0,0,0-36.54-24C97.67,40,80,58.31,80,80c0,14.56,7,27.71,16.73,40H140a20,20,0,0,1,0,40h4l37.78-8.68C203.82,135.07,232,109.23,232,80,232,58.31,214.33,40,192.54,40Z', 'M230.33,141.06a24.34,24.34,0,0,0-18.61-4.77C230.5,117.33,240,98.48,240,80c0-26.47-21.29-48-47.46-48A47.58,47.58,0,0,0,156,48.75,47.58,47.58,0,0,0,119.46,32C93.29,32,72,53.53,72,80c0,11,3.24,21.69,10.06,33a31.87,31.87,0,0,0-14.75,8.4L44.69,144H16A16,16,0,0,0,0,160v40a16,16,0,0,0,16,16H120a7.93,7.93,0,0,0,1.94-.24l64-16a6.94,6.94,0,0,0,1.19-.4L226,182.82l.44-.2a24.6,24.6,0,0,0,3.93-41.56ZM119.46,48A31.15,31.15,0,0,1,148.6,67a8,8,0,0,0,14.8,0,31.15,31.15,0,0,1,29.14-19C209.59,48,224,62.65,224,80c0,19.51-15.79,41.58-45.66,63.9l-11.09,2.55A28,28,0,0,0,140,112H100.68C92.05,100.36,88,90.12,88,80,88,62.65,102.41,48,119.46,48ZM16,160H40v40H16Zm203.43,8.21-38,16.18L119,200H56V155.31l22.63-22.62A15.86,15.86,0,0,1,89.94,128H140a12,12,0,0,1,0,24H112a8,8,0,0,0,0,16h32a8.32,8.32,0,0,0,1.79-.2l67-15.41.31-.08a8.6,8.6,0,0,1,6.3,15.9Z'],
    'path': ['M224,200a24,24,0,1,1-24-24A24,24,0,0,1,224,200Z', 'M200,168a32.06,32.06,0,0,0-31,24H72a32,32,0,0,1,0-64h96a40,40,0,0,0,0-80H72a8,8,0,0,0,0,16h96a24,24,0,0,1,0,48H72a48,48,0,0,0,0,96h97a32,32,0,1,0,31-40Zm0,48a16,16,0,1,1,16-16A16,16,0,0,1,200,216Z'],
    'compass': ['M128,32a96,96,0,1,0,96,96A96,96,0,0,0,128,32Zm16,112L80,176l32-64,64-32Z', 'M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216ZM172.42,72.84l-64,32a8.05,8.05,0,0,0-3.58,3.58l-32,64A8,8,0,0,0,80,184a8.1,8.1,0,0,0,3.58-.84l64-32a8.05,8.05,0,0,0,3.58-3.58l32-64a8,8,0,0,0-10.74-10.74ZM138,138,97.89,158.11,118,118l40.15-20.07Z'],
    'user': ['M192,96a64,64,0,1,1-64-64A64,64,0,0,1,192,96Z', 'M230.92,212c-15.23-26.33-38.7-45.21-66.09-54.16a72,72,0,1,0-73.66,0C63.78,166.78,40.31,185.66,25.08,212a8,8,0,1,0,13.85,8c18.84-32.56,52.14-52,89.07-52s70.23,19.44,89.07,52a8,8,0,1,0,13.85-8ZM72,96a56,56,0,1,1,56,56A56.06,56.06,0,0,1,72,96Z'],
    'users': ['M136,108A52,52,0,1,1,84,56,52,52,0,0,1,136,108Z', 'M117.25,157.92a60,60,0,1,0-66.5,0A95.83,95.83,0,0,0,3.53,195.63a8,8,0,1,0,13.4,8.74,80,80,0,0,1,134.14,0,8,8,0,0,0,13.4-8.74A95.83,95.83,0,0,0,117.25,157.92ZM40,108a44,44,0,1,1,44,44A44.05,44.05,0,0,1,40,108Zm210.14,98.7a8,8,0,0,1-11.07-2.33A79.83,79.83,0,0,0,172,168a8,8,0,0,1,0-16,44,44,0,1,0-16.34-84.87,8,8,0,1,1-5.94-14.85,60,60,0,0,1,55.53,105.64,95.83,95.83,0,0,1,47.22,37.71A8,8,0,0,1,250.14,206.7Z'],
    'flag-checkered': ['M224,56v60c-19.89,17.23-38.23,18.39-56,13.48v-60C185.76,74.39,204.11,73.23,224,56ZM104,102.52v-60C86.24,37.61,67.89,38.77,48,56v60c19.89-17.22,38.23-18.39,56-13.48v60c21.62,6,42.38,21,64,27v-60c-21.62-6-42.38-21-64-27Z', 'M227.32,48.75A8,8,0,0,0,218.76,50c-28,24.22-51.72,12.48-79.21-1.13C111.07,34.76,78.78,18.79,42.76,50A8,8,0,0,0,40,56V224a8,8,0,0,0,16,0V179.77c26.79-21.16,49.87-9.75,76.45,3.41,28.49,14.09,60.77,30.06,96.79-1.13a8,8,0,0,0,2.76-6V56A8,8,0,0,0,227.32,48.75ZM216,71.6v40.65c-14,11.06-27,13.22-40,10.88V79.34A60.05,60.05,0,0,0,216,71.6Zm-56,3.76v43c-6.66-2.67-13.43-6-20.45-9.48-8.82-4.37-18-8.91-27.55-12.18v-43c6.66,2.66,13.43,6,20.45,9.48C141.27,67.55,150.46,72.09,160,75.36ZM96,48.91V92.69a60.06,60.06,0,0,0-40,7.75V59.78C70,48.72,83,46.57,96,48.91ZM86.58,152A60.06,60.06,0,0,0,56,160.43V119.78c14-11.06,27-13.22,40-10.88v43.8A65.61,65.61,0,0,0,86.58,152ZM112,156.67v-43c6.66,2.66,13.43,6,20.45,9.48,8.82,4.37,18,8.9,27.55,12.17v43c-6.66-2.67-13.43-6-20.45-9.48C130.73,164.47,121.54,159.94,112,156.67Zm64,26.45v-43.8a65.61,65.61,0,0,0,9.42.72A60.11,60.11,0,0,0,216,131.57v40.68C202,183.31,189,185.46,176,183.12Z']
  };
  function iconHtml(key, colour) {
    var i = HT_ICONS[key]; if (!i) return '';
    return '<svg class="ht-ico" data-ht-icon="' + key + '" viewBox="0 0 256 256" fill="' + (colour || 'currentColor') + '" aria-hidden="true" focusable="false"><path d="' + i[0] + '" opacity="0.2"/><path d="' + i[1] + '"/></svg>';
  }
  // A path button: its glyph and the pace on top, then the name and its words; the glyph takes the band colour (CSS).
  function presetHtml(p, rate) {
    return '<button type="button" class="ht-prof" style="--c:' + p.c + '" data-r="' + p.r + '" aria-pressed="' + (Math.abs(p.r - rate) < 0.005) + '">' + iconHtml(p.icon) + ' <b>' + p.n + '</b> <span class="ht-prof-r">' + p.r.toFixed(2) + '×</span> <small>' + p.d + '</small></button>';
  }
  // Years needing help as one stacked bar: orange, red, grey, each its share of the bar (the numbers are in the list under it).
  function depBarHtml(y) {
    var v = [y.orange, y.red, y.grey], t = v[0] + v[1] + v[2];
    if (!(t > 0)) return '';
    return '<div class="ht-sbar ht-depbar" aria-hidden="true">' + v.map(function (n, i) { return '<i style="--c:' + STAGES[i + 2].c + ';--w:' + (100 * n / t).toFixed(2) + '%"></i>'; }).join('') + '</div>';
  }
  // The filled part of a slider's track: --p, read by the CSS gradient, is where the thumb sits (the theme strips the native look).
  function paintRange(el) { if (el) el.style.setProperty('--p', (100 * (el.value - el.min) / (el.max - el.min)).toFixed(1) + '%'); }
  var fsLabel = function (v) { return v === 0 ? 'off' : v <= 25 ? 'cautious' : v <= 55 ? 'likely' : v <= 85 ? 'bold' : 'wild'; };

  /* ---------- the page shell (DA5: no Settings tab, two zoom bands, science only when switched on) ---------- */
  function shellHtml(cfg) {
    var sci = !!cfg.show_science;
    return '<header class="ht-top"><h1 id="ht-title"></h1><p class="ht-sub">In relation to the people you love. Take stock of where you are, see where today\'s path leads, then change the path and watch what moves.</p></header>'
      + '<div class="ht-tabs" role="tablist" id="ht-tabs" aria-label="Inputs">'
      + '<button type="button" role="tab" id="ht-tab-btn-you" aria-controls="ht-tab-you" data-t="you">' + iconHtml('user') + 'You</button>'
      + '<button type="button" role="tab" id="ht-tab-btn-people" aria-controls="ht-tab-people" data-t="people">' + iconHtml('users') + 'Loved ones</button></div>'
      + '<div class="ht-tab" id="ht-tab-you" role="tabpanel" aria-labelledby="ht-tab-btn-you">'
      + '<div id="ht-you"></div>'
      + '<div class="ht-eyebrow ht-pick">Choose a new path · what happens if you shift</div>'
      + '<div class="ht-profiles" id="ht-profiles"></div>'
      + '<div class="ht-sliders">'
      + '<div class="ht-sl"><label class="ht-eyebrow" for="ht-rate">New pace</label><input id="ht-rate" type="range" min="0.6" max="1.6" step="0.01"><output id="ht-rate-out" for="ht-rate">1.00×</output><span class="ht-play" id="ht-rate-note"></span></div>'
      + (sci ? '<div class="ht-sl ht-fs"><label class="ht-eyebrow" for="ht-fs">Future science</label><input id="ht-fs" type="range" min="0" max="100" step="1"><output id="ht-fs-out" for="ht-fs">off</output><span class="ht-play" id="ht-fs-note"></span></div>' : '')
      + '</div></div>'
      + '<div class="ht-tab" id="ht-tab-people" role="tabpanel" aria-labelledby="ht-tab-btn-people" hidden>'
      + '<div class="ht-row ht-head" aria-hidden="true"><span>Name</span><span>Relationship</span><span>Born</span><span>Sex</span><span>Pace of ageing</span><span></span></div>'
      + '<div id="ht-people"></div><div id="ht-linked"></div><p class="ht-note ht-links-msg" id="ht-links-msg" role="status" aria-live="polite"></p>'
      + '<form class="ht-row" id="ht-add"><input id="ht-a-name" placeholder="Name" aria-label="Name" maxlength="40" required><select id="ht-a-rel" aria-label="Relationship"></select><input id="ht-a-born" type="number" placeholder="Year" aria-label="Year born" required><select id="ht-a-sex" aria-label="Sex"><option value="F">F</option><option value="M">M</option></select><select id="ht-a-rate" aria-label="Pace of ageing"></select><button class="ht-btn" type="submit">Add</button></form>'
      + '<div class="ht-lock" id="ht-people-lock" hidden></div>'
      + '<p class="ht-note">"Same as you" assumes your habits shape the household. Time together uses population averages for each relationship.</p>'
      + '</div>'
      + '<div class="ht-band"><div class="ht-bandbar"><div><span class="ht-eyebrow">' + iconHtml('path') + 'Timeline</span> <span id="ht-unit" class="ht-unit"></span></div>'
      + '<div class="ht-zoom"><span>Years</span><input id="ht-zoom" type="range" min="0" max="100" value="0" aria-label="Zoom from years to months"><span>Months</span></div></div>'
      + '<div class="ht-tl"><div class="ht-scroller" id="ht-scroller"><canvas id="ht-cv" role="img"></canvas></div><div class="ht-lockband" id="ht-lockband" hidden></div></div>'
      + '<div class="ht-legend" id="ht-legend"></div></div>'
      + '<div class="ht-panel ht-moment" id="ht-moment"></div>'
      + '<section class="ht-sec"><div class="ht-ahead">'
      + '<div class="ht-panel"><h2>' + iconHtml('hourglass') + 'Your years ahead</h2><p class="ht-lead">What is left, stage by stage, on the new path. The thin bar underneath is today\'s path, so the difference is visible.</p><div id="ht-stacks"></div></div>'
      + '<div><div class="ht-panel ht-dep"><h2>Years you depend on others</h2><p class="ht-lead">Orange, red and grey are the years someone has to feed you, wash you and run your day. Yellow is not counted; limits are present, but you still run your own life.</p><div id="ht-dep"></div></div>'
      + '<aside class="ht-panel ht-card" id="ht-card" hidden></aside></div>'
      + '</div></section>'
      + '<section class="ht-sec"><div class="ht-traj' + (sci ? '' : ' ht-traj-solo') + '">'
      + '<div class="ht-panel ht-chart"><h2>' + iconHtml('chart-line-up') + 'Your trajectory</h2><p class="ht-lead">The trajectory chart your report uses, drawn from your pace. The teal line is the new path you pick above.</p><div id="ht-traj"></div></div>'
      + (sci ? '<div class="ht-panel ht-future"><span class="ht-spec">Speculative, not hocus-pocus</span><h2>Science is moving</h2>'
        + '<p>Regenerative medicine, cellular reprogramming, better diagnostics. We reliably underestimate exponential change, so the "Future science" slider lets you stretch the picture from cautious to wild.</p>'
        + '<p>Whatever arrives will work best on a body that is already well kept. A solid foundation improves life now, improves it later, and raises the odds that the next tools work on you.</p>'
        + '<p>The aim is not more years. It is more good years and fewer in the suffering phase. Length tends to follow as a side effect.</p><div class="ht-note" id="ht-fs-detail"></div></div>' : '')
      + '</div></section>'
      + '<section class="ht-sec"><h2>' + iconHtml('hand-heart') + 'Time with the people you love</h2><p class="ht-lead">Each dot is one full week of real time together, coloured by your stage at the time. Green, yellow and orange all count; in orange the hours are fewer and more of them are care. Closer relationships hold up; more distant ones thin out as you decline. Every relationship is different.</p>'
      + '<div class="ht-lockwrap"><div class="ht-lo" id="ht-loved"></div><div class="ht-lockmsg" id="ht-loved-lock" hidden></div></div></section>'
      + '<p class="ht-disc" id="ht-disc"></p>';
  }

  /* ---------- timeline (fixed axis) ---------- */
  var cv, ctx, geo = null, hover = null;
  function drawTimeline() {
    var preview = S.mode === 'preview';
    // preview: the You lane first, then the default loved ones (drawn, blurred, not interactive)
    var ps = preview ? [S.you].concat(S.people.slice().sort(function (a, b) { return a.born - b.born; })) : people();
    var all = people(); var minY = Math.min.apply(null, all.map(function (p) { return p.born; })), maxY = Math.max.apply(null, all.map(function (p) { return p.born; })) + 110;
    var span = maxY - minY + 1, W = $('#ht-scroller').clientWidth, left = W < 520 ? 96 : 122, fitPx = (W - left - 16) / span, z = S.zoom;
    // two zoom bands: 0–49 years, 50–100 months; pixel scale grows continuously
    var unit = z < 50 ? 'year' : 'month', per = unit === 'year' ? 1 : 12;
    var minPx = unit === 'year' ? fitPx : 36, maxPx = unit === 'year' ? 30 : 150, f = unit === 'year' ? z / 49 : (z - 50) / 50;
    var pxYear = Math.max(fitPx, minPx + (maxPx - minPx) * f);
    var cellW = pxYear / per, laneH = 24, youH = 48, refH = 5, gap = 12, top = 34;
    var rows = ps.map(function (p) { return { p: p, h: p === S.you ? youH : laneH }; }); var yy = top; rows.forEach(function (r) { r.y = yy; yy += r.h + refH + 2 + gap; });
    var width = Math.max(W, left + span * pxYear + 16), height = yy + 2;
    var dpr = root.devicePixelRatio || 1; cv.width = width * dpr; cv.height = height * dpr; cv.style.width = width + 'px'; cv.style.height = height + 'px'; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var cs = getComputedStyle(rootEl), fg = cs.getPropertyValue('--ht-fg').trim(), muted = cs.getPropertyValue('--ht-muted').trim(), line = cs.getPropertyValue('--ht-line').trim(), past = cs.getPropertyValue('--ht-past').trim(), acc = cs.getPropertyValue('--ht-accent2').trim();
    ctx.clearRect(0, 0, width, height);
    ctx.font = '11px Inter, system-ui'; ctx.textAlign = 'center';
    // the first year step whose labels sit at least 36 px apart (10 years overlapped on a phone)
    var step = [1, 5, 10, 20, 50].filter(function (n) { return n * pxYear >= 36; })[0] || 100;
    for (var y = Math.ceil(minY / step) * step; y <= maxY; y += step) { var x = left + (y - minY) * pxYear; ctx.fillStyle = muted; ctx.fillText(y, x, top - 14); ctx.fillStyle = line; ctx.fillRect(x, top - 9, 1, 5); }
    geo = { minY: minY, pxYear: pxYear, cellW: cellW, per: per, unit: unit, left: left, top: top, rows: rows.filter(function (r) { return visiblePeople().indexOf(r.p) !== -1; }) };
    var n = Math.round(span * per), gapPx = cellW > 3 ? 1 : 0;
    rows.forEach(function (row) {
      var p = row.p, y0 = row.y, h = row.h;
      ctx.textAlign = 'left'; ctx.fillStyle = p === S.you ? acc : fg; ctx.font = (p === S.you ? '700 16px' : '600 13px') + ' Inter, system-ui'; ctx.fillText(fitName(p.name, left - 14), 8, y0 + h / 2 - 1);
      ctx.font = '11px Inter, system-ui'; ctx.fillStyle = muted; var a = NOW_Y - p.born; ctx.fillText(p.fromReport ? (left < 110 ? 'their report' : 'from their report') : a < 0 ? 'born ' + p.born : a + ' · ' + rateOf(p).toFixed(2) + '×', 8, y0 + h / 2 + 12);
      for (var k = 0; k < n; k++) { var t = minY + (k + 0.5) / per; var st = stageAt(p, t); if (st === 0 || st === 6) continue; ctx.fillStyle = stageHex(st); ctx.fillRect(left + k * cellW, y0, Math.max(cellW - gapPx, 0.6), h); }
      var refPer = Math.min(per, 12), refN = Math.round(span * refPer), refW = pxYear / refPer;
      for (var j = 0; j < refN; j++) { var tr = minY + (j + 0.5) / refPer; if (tr < p.born) continue; var sr = stageOfBio(p, bioAtRate(p, tr, rate0Of(p), rate0Of(p), 0), rate0Of(p)); if (sr === 6) continue; ctx.fillStyle = stageHex(sr); ctx.fillRect(left + j * refW, y0 + h + 2, Math.max(refW, 0.6), refH); }
      ctx.fillStyle = past; ctx.fillRect(left, y0, Math.max(0, (NOW_F - minY) * pxYear), h + refH + 2);
    });
    var tx = left + (NOW_F - minY) * pxYear; ctx.fillStyle = fg; ctx.fillRect(tx - 1, top - 4, 2, height - top); ctx.font = '700 10px Inter, system-ui'; ctx.textAlign = 'left'; ctx.fillText('TODAY', tx + 4, top - 4);
    if (hover) { ctx.strokeStyle = fg; ctx.lineWidth = 2; ctx.strokeRect(hover.x + 0.5, hover.y + 0.5, Math.max(hover.w, 2), hover.h - 1); }
    $('#ht-unit').innerHTML = 'one cell = <b>one ' + unit + '</b> · axis stays fixed when you change pace';
    var band = $('#ht-lockband');
    band.hidden = !preview || rows.length < 2;
    if (!band.hidden) { band.style.top = (rows[1].y - gap / 2) + 'px'; band.style.height = (height - rows[1].y + gap / 2) + 'px'; }
    var cur = yearsByStage(S.you, rateOf(S.you), rate0Of(S.you), fsFrac()), st0 = stageOfBio(S.you, bioNow(S.you), rateOf(S.you));
    cv.setAttribute('aria-label', 'Timeline for ' + S.you.name + ': today in the ' + (STAGES[st0 - 1] || { name: 'last' }).name + ' stage; the new path gives ' + cur[0].toFixed(0) + ' green years.');
  }
  // the lane's name in the label column: up to 16 characters, cut with an ellipsis where it would run into the lane
  function fitName(s, max) {
    s = s.slice(0, 16); if (ctx.measureText(s).width <= max) return s;
    while (s.length > 1 && ctx.measureText(s + '…').width > max) s = s.slice(0, -1);
    return s + '…';
  }
  function onMove(e) {
    if (!geo) return; var r = cv.getBoundingClientRect(); var x = e.clientX - r.left, y = e.clientY - r.top;
    var row = geo.rows.find(function (rw) { return y >= rw.y && y < rw.y + rw.h; }); if (!row || x < geo.left) return;
    var k = Math.floor((x - geo.left) / geo.cellW); var t = geo.minY + (k + 0.5) / geo.per;
    hover = { x: geo.left + k * geo.cellW, y: row.y, w: geo.cellW, h: row.h, p: row.p, t: t }; drawTimeline(); renderMoment(row.p, t, geo.unit);
  }

  /* ---------- moment panel ---------- */
  function renderMoment(p, t, unit, isNow) { $('#ht-moment').innerHTML = momentHtml(p, t, unit, isNow); }
  function momentHtml(p, t, unit, isNow) {
    var age = t - p.born; var r = rateOf(p); var b = age < 0 ? null : bioAt(p, t); var st = b === null ? 0 : stageOfBio(p, b, r); var gone = st === 6; var ok = b !== null && !gone;
    var life = p === S.you ? S.you.life : 3;
    var cap = ok ? capability(p, b, r) : 0, mm = ok ? multimorb(b) : 0, diff = ok ? difficulty(age, r) : 0, gain = ok && life != null ? gainPotential(life) : null;
    var dateStr = isNow ? 'today' : unit === 'year' ? String(Math.floor(t)) : new Date(Math.floor(t), Math.floor((t % 1) * 12)).toLocaleString('en-GB', { month: 'short', year: 'numeric' });
    var pill = b === null ? '<span class="ht-pill" style="--c:var(--ht-muted)">not yet born</span>' : gone ? '<span class="ht-pill" style="--c:var(--ht-muted)">beyond expected lifespan</span>' : '<span class="ht-pill" style="--c:' + STAGES[st - 1].c + '">' + STAGES[st - 1].name + ' · ' + STAGES[st - 1].label + '</span>';
    var na = '<small>n/a</small>';
    var lifeWords = p !== S.you ? 'Set from their own report\'s lifestyle score.'
      : life == null ? 'Your lifestyle score comes with the full report.'
      : 'From a lifestyle score of ' + life + '/5' + (S.you.lifeSource === 'stage1' ? ' (your free check)' : '') + ': ' + (gain > 60 ? 'the fast part of the curve, early changes pay off quickly.' : gain > 30 ? 'solid headroom left.' : 'already near the top; gains come slower now.');
    return '<div class="ht-eyebrow">' + iconHtml('compass') + (isNow ? 'Where you are right now' : 'This moment') + ' · hover the timeline to move it</div>'
      + '<div class="ht-who"><b class="ht-name">' + esc(p.name) + '</b><span>' + dateStr + '</span><span>age <b>' + ageLabel(age, unit) + '</b></span><span>biological age <b>' + (ok ? b.toFixed(1) : '–') + '</b></span>' + pill + '</div>'
      + '<div class="ht-cards">'
      + '<div class="ht-mcard"><div class="ht-eyebrow">Capability &amp; independence</div><div class="ht-v">' + iconHtml('person-arms-spread', ok ? STAGES[st - 1].hex : '#888') + (ok ? Math.round(cap) + '<small>/100</small>' : na) + '</div><div class="ht-bar"><i style="--c:' + (ok ? STAGES[st - 1].c : 'var(--ht-muted)') + ';--w:' + cap + '%"></i></div><div class="ht-s">' + (ok ? STAGES[st - 1].desc : b === null ? 'Not born yet at this point.' : 'Past the expected end of life on this path.') + '</div></div>'
      + '<div class="ht-mcard"><div class="ht-eyebrow">Living with 2+ health conditions</div><div class="ht-v">' + iconHtml('heartbeat') + (ok ? Math.round(mm) + '%' : na) + '</div><div class="ht-bar"><i style="--c:var(--ht-orange);--w:' + mm + '%"></i></div><div class="ht-s">Share of people at this biological age (UK pattern).</div></div>'
      + '<div class="ht-mcard"><div class="ht-eyebrow">Difficulty of changing course</div><div class="ht-v">' + iconHtml('mountains') + (ok ? diff.toFixed(1) + '<small>/10</small>' : na) + '</div><div class="ht-bar"><i style="--c:var(--ht-red);--w:' + (diff * 10) + '%"></i></div><div class="ht-s">' + (!ok ? '–' : diff < 4 ? 'Habits set now compound for decades.' : diff < 7 ? 'Still very doable; consistency beats intensity.' : 'Big gains still possible, but they take support and patience.') + '</div></div>'
      + '<div class="ht-mcard"><div class="ht-eyebrow">Potential gain if you act</div><div class="ht-v">' + iconHtml('trend-up') + (gain != null ? gain + '%' : na) + '</div><div class="ht-bar"><i style="--c:var(--ht-green);--w:' + (gain || 0) + '%"></i></div><div class="ht-s">' + lifeWords + '</div></div>'
      + '</div>';
  }

  /* ---------- years ahead + dependence ---------- */
  function renderAhead() {
    var Y = S.you, r = rateOf(Y), r0 = rate0Of(Y); var cur = yearsByStage(Y, r, r0, fsFrac()), ref = yearsByStage(Y, r0, r0, 0);
    var sum = function (a) { return a.reduce(function (x, y) { return x + y; }, 0); };
    var tot = sum(cur), rtot = sum(ref); var maxTot = Math.max(tot, rtot) || 1;
    var bar = function (vals, cls) { var t = sum(vals); return '<div class="ht-sbar ' + cls + '" style="width:' + (100 * t / maxTot) + '%">' + vals.map(function (v, i) { return '<i style="--c:' + STAGES[i].c + ';--w:' + (100 * v / (t || 1)) + '%"><span>' + (cls || v < 2 ? '' : v.toFixed(0) + 'y') + '</span></i>'; }).join('') + '</div>'; };
    var dg = cur[0] - ref[0];
    $('#ht-stacks').innerHTML = '<div class="ht-stack"><div class="ht-lbl"><b>New path · ' + r.toFixed(2) + '×' + (S.fs ? ' + science' : '') + '</b><span>' + tot.toFixed(0) + ' years left, ' + cur[0].toFixed(0) + ' of them green</span></div>' + bar(cur, '') + bar(ref, 'ht-ref') + '<div class="ht-lbl" style="margin-top:4px"><span>Today\'s path · ' + r0.toFixed(2) + '×</span><span>' + rtot.toFixed(0) + ' years, ' + ref[0].toFixed(0) + ' green</span></div></div>'
      + '<div class="ht-cmp">' + (Math.abs(dg) < 0.5 ? 'Same as today\'s path. Pick a new path above to see what shifts.' : '<b>' + (dg > 0 ? '+' : '') + dg.toFixed(1) + ' green years</b> and <b>' + ((tot - rtot) >= 0 ? '+' : '') + (tot - rtot).toFixed(1) + ' years</b> in total compared with today\'s path.') + '</div>';
    var dep = cur[2] + cur[3] + cur[4], rdep = ref[2] + ref[3] + ref[4];
    $('#ht-dep').innerHTML = '<div class="ht-big">' + iconHtml('users-three') + dep.toFixed(1) + '<small> years needing help</small></div>' + (depBarHtml({ orange: cur[2], red: cur[3], grey: cur[4] }) || '<p class="ht-note">none on this path</p>')
      + '<div class="ht-kv"><span>Orange · others cover for you</span><b>' + cur[2].toFixed(1) + ' y</b><span>Red · others run parts of your life</span><b>' + cur[3].toFixed(1) + ' y</b><span>Grey · round-the-clock care</span><b>' + cur[4].toFixed(1) + ' y</b><span>On today\'s path</span><b>' + rdep.toFixed(1) + ' y</b></div>'
      + '<div class="ht-cmp">' + (dep < rdep - 0.3 ? '<b>' + (rdep - dep).toFixed(1) + ' fewer years</b> your family carries you.' : dep > rdep + 0.3 ? '<b>' + (dep - rdep).toFixed(1) + ' more years</b> your family carries you.' : 'Same as today\'s path.') + '</div>';
  }

  /* ---------- trajectory: the report's engine, plus the new path ---------- */
  // The engine's plot box (hdl-trajectory-chart-hero.js MARGIN, CW, CH, CHART_MAX_AGE; that file is frozen).
  var TRAJ = { left: 55, top: 30, w: 780 - 55 - 30, h: 440 - 30 - 50, maxAge: 120 };
  var trajKey = '';
  function newPathD(E, age, r0, r) {
    var d = '';
    for (var a = age; a <= TRAJ.maxAge; a += 0.5) {
      var h1 = E.interpolateHealthForRate(a, r); if (h1 === null) break;
      var h0 = E.interpolateHealthForRate(a, r0);
      // the shift settles in over recovT years, as on the lanes; the curves themselves are the engine's
      var h = h0 === null ? h1 : h0 + (h1 - h0) * (1 - Math.exp(-(a - age) / K.recovT));
      d += (d ? ' L' : 'M') + (TRAJ.left + a / TRAJ.maxAge * TRAJ.w).toFixed(1) + ',' + (TRAJ.top + TRAJ.h - h / 100 * TRAJ.h).toFixed(1);
    }
    return d;
  }
  function renderTraj() {
    var E = root.HDLTrajectoryChart, el = $('#ht-traj');
    if (!E || !E.render) return;
    var age = NOW_Y - S.you.born, r0 = +S.you.rate0, key = age + '|' + r0;
    if (key !== trajKey || !el.querySelector('svg')) {
      E.render(el, { chronoAge: age, agingRate: r0, showBands: true, showProjections: true });
      trajKey = key;
      var svg = el.querySelector('svg');
      if (svg) { var p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('data-ht', 'new-path'); p.setAttribute('fill', 'none'); p.setAttribute('stroke', '#3d8da0'); p.setAttribute('stroke-width', '3'); svg.appendChild(p); }
      var lg = el.querySelector('[data-hdl="legend"]');
      if (lg) { var it = document.createElement('div'); it.style.cssText = 'display:flex;align-items:center;gap:6px;'; it.innerHTML = '<span style="display:inline-block;width:18px;height:0;border-top:3px solid #3d8da0;"></span><span>New path</span>'; lg.appendChild(it); }
    }
    var path = el.querySelector('[data-ht="new-path"]');
    if (path) path.setAttribute('d', newPathD(E, age, r0, rateOf(S.you)));
  }

  /* ---------- loved ones ---------- */
  function renderLoved() {
    var preview = S.mode === 'preview';
    $('#ht-loved').innerHTML = others().map(function (p) {
      var cur = weeksTogether(p, 'new'), ref = weeksTogether(p, 'ref'); var w = Math.round(cur.reduce(function (a, b) { return a + b; }, 0)), rw = Math.round(ref.reduce(function (a, b) { return a + b; }, 0));
      var counts = cur.map(Math.round); var shown = Math.min(Math.max(w, rw), 700);
      var dots = '', k = 0; counts.forEach(function (c, i) { for (var j = 0; j < c && k < 700; j++, k++) dots += '<i style="--c:' + STAGES[i].c + '"></i>'; }); for (; k < shown; k++) dots += '<i style="--c:var(--ht-line)"></i>';
      var s18 = stageAt(S.you, p.born + 18);
      return '<div class="ht-loc"><div class="ht-nm">' + esc(p.name) + '<small>' + p.rel + ' · ' + rateOf(p).toFixed(2) + '×' + (p.fromReport ? ' · from their report' : '') + '</small></div>'
        + '<div class="ht-big">' + w + '<small> full weeks of real time together, from today</small></div>'
        + '<div class="ht-dots">' + dots + '</div>' + (Math.max(w, rw) > 700 ? '<div class="ht-note" style="margin-top:4px">showing 700 of ' + Math.max(w, rw) + '</div>' : '')
        + '<div class="ht-kv"><span>Green · Yellow · Orange weeks</span><b>' + counts[0] + ' · ' + counts[1] + ' · ' + counts[2] + '</b><span>On today\'s path</span><b>' + rw + ' weeks</b><span>Usual time together</span><b>' + (K.DEFAULT_HRS[p.rel] != null ? K.DEFAULT_HRS[p.rel] : 2) + ' h/week</b><span>You when they turn 18</span><b>' + (p.born > S.you.born ? (s18 >= 1 && s18 <= 5 ? STAGES[s18 - 1].name : s18 === 6 ? 'gone' : '–') : '–') + '</b></div></div>';
    }).join('');
    var lo = $('#ht-loved');
    lo.classList.toggle('ht-blur', preview);
    if (preview) { lo.setAttribute('aria-hidden', 'true'); lo.setAttribute('inert', ''); } else { lo.removeAttribute('aria-hidden'); lo.removeAttribute('inert'); }
  }

  /* ---------- the lock line, the card ---------- */
  function lockHtml(offer) {
    offer = offer || {};
    return '<p>' + iconHtml('lock') + esc(offer.line || 'Your loved ones unlock with the full report.') + '</p>'
      + (offer.button && /^https:\/\//i.test(offer.url || '') ? '<a class="ht-btn" href="' + esc(offer.url) + '" target="_blank" rel="noopener noreferrer">' + esc(offer.button) + '</a>' : '');
  }
  function renderStatic(cfg) {
    $('#ht-title').textContent = cfg.title || 'Healthspan Timeline';
    var preview = S.mode === 'preview';
    ['#ht-lockband', '#ht-loved-lock', '#ht-people-lock'].forEach(function (s) { $(s).innerHTML = lockHtml(cfg.offer); });
    $('#ht-loved-lock').hidden = !preview; $('#ht-people-lock').hidden = !preview; $('#ht-add').hidden = preview;
    var card = cfg.card, el = $('#ht-card');
    if (card && card.title && card.button && /^https:\/\//i.test(card.url || '')) {
      el.innerHTML = '<h2>' + iconHtml('flag-checkered') + '<span></span></h2>' + (card.text ? '<p class="ht-lead"></p>' : '') + '<a class="ht-btn" target="_blank" rel="noopener noreferrer"></a>';
      el.querySelector('h2 span').textContent = card.title;
      if (card.text) el.querySelector('p').textContent = card.text;
      var a = el.querySelector('a'); a.textContent = card.button; a.href = card.url;
      el.hidden = false;
    }
    $('#ht-legend').innerHTML = STAGES.map(function (s) { return '<span><i style="--c:' + s.c + '"></i>' + s.name + ' · ' + s.label + '</span>'; }).join('') + '<span><i style="--c:var(--ht-line)"></i>Faded = already lived</span><span><i style="--c:var(--ht-muted);height:5px"></i>Thin bar under each lane = today\'s path, for comparison</span>';
    $('#ht-disc').textContent = 'Estimates, not predictions. Stage boundaries use UK life and healthy-life expectancy (Office for National Statistics, 2021–23); the health-conditions curve uses published UK multimorbidity data (Barnett et al., 2012); time-together figures use US time-use survey averages because no UK equivalent exists; the shift response, decline compression and future-science slider are assumptions, not measurements. Your own biological age and pace come from your HealthDataLab report. Your report measures pace against people your own age; this page reads it as years of ageing per calendar year, so the two are close but not the same measure. Everyone else\'s are assumptions you can edit, unless they shared their own. Not medical advice. Its job is to make the years ahead visible so you act on them now.';
  }

  /* ---------- inputs ---------- */
  var relOpts = function (sel) { return REL.map(function (r) { return '<option value="' + r + '"' + (r === sel ? ' selected' : '') + '>' + r[0].toUpperCase() + r.slice(1) + '</option>'; }).join(''); };
  var paceOpts = function (sel) { return '<option value="same"' + (sel === 'same' ? ' selected' : '') + '>Same as you</option>' + K.PROFILES.map(function (p) { return '<option value="' + p.r + '"' + (sel !== 'same' && Math.abs(+sel - p.r) < 0.005 ? ' selected' : '') + '>' + p.n + ' ' + p.r.toFixed(2) + '×</option>'; }).join(''); };
  var highlightPace = false;
  function renderYou() {
    var Y = S.you, age = NOW_Y - Y.born, el = $('#ht-you');
    var facts = '<span>age <b>' + age + '</b></span><span>biological age <b>' + (Y.bio !== '' ? (+Y.bio).toFixed(1) : age) + '</b></span><span>lifestyle <b>' + (Y.life != null ? Y.life + '/5' : 'n/a') + '</b></span>';
    if (S.mode === 'full') {
      el.innerHTML = '<div class="ht-facts"><b class="ht-name"></b><span>' + (Y.sex === 'M' ? 'Male' : 'Female') + '</span>' + facts + '</div><p class="ht-pace">' + esc(paceLabel(Y.rate0, 'report')) + '</p>';
      el.querySelector('.ht-name').textContent = Y.name;
      return;
    }
    el.innerHTML = '<div class="ht-row ht-you-row"><label>Name<input id="ht-y-name" maxlength="40"></label><label>Born<input id="ht-y-born" type="number" min="1920" max="' + (NOW_Y - 18) + '"></label>'
      + '<label>Sex<select id="ht-y-sex"><option value="F">Female</option><option value="M">Male</option></select></label>'
      + '<label class="ht-pace-in' + (highlightPace ? ' ht-hl' : '') + '">' + esc(paceLabel(Y.rate0, 'check')) + '<input id="ht-y-rate0" type="number" step="0.01" min="0.6" max="1.6"></label></div>'
      + '<div class="ht-facts">' + facts + '</div>';
    $('#ht-y-name').value = Y.name; $('#ht-y-born').value = Y.born; $('#ht-y-sex').value = Y.sex; $('#ht-y-rate0').value = (+Y.rate0).toFixed(2);
    $('#ht-y-name').onchange = function (e) { S.you.name = e.target.value.trim().slice(0, 40) || 'You'; save(); renderAll(); };
    $('#ht-y-born').onchange = function (e) { var v = parseInt(e.target.value, 10); if (v >= 1920 && v <= NOW_Y - 18) { S.you.born = v; S.you.bio = derivedBio(); save(); renderAll(); } };
    $('#ht-y-sex').onchange = function (e) { S.you.sex = e.target.value === 'M' ? 'M' : 'F'; save(); renderAll(); };
    $('#ht-y-rate0').onchange = function (e) { var v = +e.target.value; if (v >= 0.6 && v <= 1.6) { S.you.rate0 = v; S.you.rate = v; S.you.bio = derivedBio(); highlightPace = false; save(); renderAll(); } };
  }
  // the free check's biological age: pace × age (as the widget and HDLV2_Stage1_Commentary::biological_age)
  function derivedBio() { return Math.round(S.you.rate0 * (NOW_Y - S.you.born) * 10) / 10; }
  function renderInputs() {
    var preview = S.mode === 'preview';
    rootEl.querySelectorAll('#ht-tabs button').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.t === S.tab ? 'true' : 'false'); b.tabIndex = b.dataset.t === S.tab ? 0 : -1; });
    ['you', 'people'].forEach(function (t) { $('#ht-tab-' + t).hidden = S.tab !== t; });
    renderYou();
    var Y = S.you;
    $('#ht-rate').value = Y.rate; paintRange($('#ht-rate')); $('#ht-rate-out').value = (+Y.rate).toFixed(2) + '×';
    $('#ht-profiles').innerHTML = K.PROFILES.map(function (p) { return presetHtml(p, Y.rate); }).join('');
    $('#ht-profiles').querySelectorAll('button').forEach(function (b) { b.onclick = function () { S.you.rate = +b.dataset.r; save(); renderAll(); }; });
    var dr = Y.rate - Y.rate0;
    $('#ht-rate-note').textContent = Math.abs(dr) < 0.02 ? 'Same as today\'s path. Pick a path or drag to see what a shift does.' : dr < 0 ? 'A shift of ' + (-dr).toFixed(2) + ': you\'d recover about ' + ((-dr) * 10 * K.recovK).toFixed(1) + ' biological years over ~' + K.recovT + ' years, then age slower.' : 'Faster than today by ' + dr.toFixed(2) + ': you\'d lose about ' + (dr * 10 * K.recovK).toFixed(1) + ' biological years, then age faster.';
    if ($('#ht-fs')) {
      var dv = dividend(rateOf(Y), fsFrac());
      $('#ht-fs').value = S.fs; paintRange($('#ht-fs')); $('#ht-fs-out').value = fsLabel(S.fs);
      $('#ht-fs-note').textContent = S.fs === 0 ? 'Off. Slide to imagine what research might add.' : fsLabel(S.fs) + ': pace eases from ' + K.fsStart + ', ' + (dv * 100).toFixed(0) + '% slower by ' + (K.fsStart + 30) + ' on your foundation.';
      $('#ht-fs-detail').textContent = 'Your foundation decides your share: at ' + rateOf(Y).toFixed(2) + '× you earn ' + Math.round(100 * (0.4 + 0.6 * Math.max(0, Math.min(1, (1.6 - rateOf(Y)) / 0.9)))) + '% of whatever the slider offers.';
    }
    var pe = $('#ht-people'); pe.innerHTML = '';
    S.people.forEach(function (p) {
      var d = document.createElement('div'); d.className = 'ht-row' + (preview ? ' ht-locked' : '');
      d.innerHTML = '<input aria-label="Name" maxlength="40"><select aria-label="Relationship">' + relOpts(p.rel) + '</select><input type="number" aria-label="Year born"><select aria-label="Sex"><option value="F"' + (p.sex === 'F' ? ' selected' : '') + '>F</option><option value="M"' + (p.sex === 'M' ? ' selected' : '') + '>M</option></select><select aria-label="Pace of ageing">' + paceOpts(p.rate) + '</select><button class="ht-x" type="button" aria-label="Remove ' + esc(p.name) + '">×</button>';
      var c = d.children, n = c[0], r = c[1], b = c[2], sx = c[3], rt = c[4], x = c[5];
      n.value = p.name; b.value = p.born;
      if (preview) { [n, r, b, sx, rt, x].forEach(function (i) { i.disabled = true; }); pe.appendChild(d); return; }
      n.onchange = function () { p.name = n.value.trim().slice(0, 40) || p.name; save(); renderAll(); }; r.onchange = function () { p.rel = REL.indexOf(r.value) !== -1 ? r.value : p.rel; save(); renderAll(); };
      b.onchange = function () { var v = parseInt(b.value, 10); if (v > 1850 && v < 2200) { p.born = v; save(); renderAll(); } }; sx.onchange = function () { p.sex = sx.value === 'M' ? 'M' : 'F'; save(); renderAll(); };
      rt.onchange = function () { p.rate = rt.value; save(); renderAll(); }; x.onclick = function () { S.people = S.people.filter(function (q) { return q !== p; }); save(); renderAll(); }; pe.appendChild(d);
      if (S.links) {
        var inv = document.createElement('div'); inv.className = 'ht-link-line';
        inv.innerHTML = '<button type="button" class="ht-btn ht-btn-line">Invite ' + esc(p.name) + ' by link</button>';
        inv.firstChild.onclick = function () { invite(p, inv.firstChild); }; d.appendChild(inv);
      }
    });
    renderLinked();
    $('#ht-a-rel').innerHTML = relOpts('friend');
    $('#ht-a-rate').innerHTML = paceOpts('same');
    $('#ht-zoom').value = S.zoom; paintRange($('#ht-zoom'));
  }
  function renderAll() { renderInputs(); drawTimeline(); if (hover) renderMoment(hover.p, hover.t, geo.unit); else renderMoment(S.you, NOW_F, 'year', true); renderAhead(); renderTraj(); renderLoved(); }
  function renderLive() { drawTimeline(); renderMoment(S.you, NOW_F, 'year', true); renderAhead(); renderTraj(); renderLoved(); }

  /* ---------- linked people (v0.48.34): HDLV2_HT.links → GET / POST / DELETE /timeline/links ---------- */
  var CHIPS = { invited: 'Invited', accepted: 'Shared', declined: 'Declined', withdrawn: 'Withdrawn', revoked: 'Ended', expired: 'Ended' };
  function chipLabel(status) { return CHIPS[status] || 'Ended'; }
  // A link as a lane: a shared one from its three values (born = this year − age), anything else from the client's guess.
  function linkedPerson(l, you) {
    var sh = l.status === 'accepted' && l.shared ? l.shared : null;
    return {
      id: 'L' + l.id, name: l.label, rel: REL.indexOf(l.relationship) !== -1 ? l.relationship : 'other',
      born: sh ? NOW_Y - sh.age : (l.born_guess > 1850 && l.born_guess < 2200 ? l.born_guess : you.born),
      sex: (sh && sh.sex) || (l.sex_guess === 'M' ? 'M' : 'F'), rate: sh ? +sh.rate : 'same', fromReport: !!sh
    };
  }
  var capWord = function (w) { return w ? w[0].toUpperCase() + w.slice(1) : ''; };
  // Read-only: the chip, the three values when shared, and "End link" with its inline confirm while the link is open.
  function linkedRowHtml(l) {
    var sh = l.status === 'accepted' && l.shared ? l.shared : null, open = l.status === 'invited' || l.status === 'accepted';
    var act = !open ? '' : l.confirming
      ? '<span class="ht-note">End this link?</span><button type="button" class="ht-btn" data-act="end-yes" data-id="' + l.id + '">Confirm</button><button type="button" class="ht-btn ht-btn-line" data-act="end-no" data-id="' + l.id + '">Keep</button>'
      : '<button type="button" class="ht-btn ht-btn-line" data-act="end" data-id="' + l.id + '">End link</button>';
    return '<div class="ht-row ht-linked"><span class="ht-cell">' + esc(l.label) + '</span><span class="ht-cell">' + capWord(esc(l.relationship)) + '</span>'
      + '<span class="ht-cell">' + (sh ? 'age ' + sh.age : esc(l.born_guess || '–')) + '</span><span class="ht-cell">' + esc((sh ? sh.sex : l.sex_guess) || '–') + '</span>'
      + '<span class="ht-cell">' + (sh ? (+sh.rate).toFixed(2) + '× <small class="ht-mark">from their report</small>' : 'Same as you') + '</span>'
      + '<span class="ht-link-act"><span class="ht-chip" data-status="' + esc(l.status) + '">' + chipLabel(l.status) + '</span>' + act + '</span></div>';
  }
  function inviteBoxHtml(url, canShare) {
    return '<div class="ht-invite"><label class="ht-eyebrow" for="ht-invite-url">Send this link yourself, by any message</label>'
      + '<div class="ht-invite-row"><input id="ht-invite-url" readonly value="' + esc(url) + '"><button type="button" class="ht-btn" data-act="copy">Copy</button>'
      + (canShare ? '<button type="button" class="ht-btn ht-btn-line" data-act="share">Share</button>' : '') + '</div>'
      + '<p class="ht-note">Nothing is sent by us. They do the free check through it, then choose whether to share three numbers with you.</p></div>';
  }
  var LINKS = null, OWNER = '';
  function linksApi(method, path, body) {
    var o = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-HDLV2-Token': OWNER, 'X-WP-Nonce': LINKS.nonce } };
    if (body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    return fetch(LINKS.url + path, o).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j || {} }; }); });
  }
  var offline = function () { S.linkMsg = 'We could not reach the server. Please try again.'; renderAll(); };
  function renderLinked() {
    var el = $('#ht-linked'); if (!el) return;
    el.innerHTML = !LINKS ? '' : !S.links ? '<div class="ht-skel" aria-hidden="true"></div>'
      : S.links.map(function (l) { return linkedRowHtml(l) + (S.inviteBox && S.inviteBox.id === l.id ? inviteBoxHtml(S.inviteBox.url, typeof navigator !== 'undefined' && !!navigator.share) : ''); }).join('');
    $('#ht-links-msg').textContent = S.linkMsg || '';
  }
  function loadLinks() {
    linksApi('GET', '').then(function (res) {
      if (res.status === 200 && Array.isArray(res.body.links)) { S.links = res.body.links; S.linkMsg = ''; } else { S.linkMsg = res.body.message || 'Your links could not be loaded.'; }
      renderAll();
    }, offline);
  }
  function invite(p, btn) {
    btn.disabled = true; btn.textContent = 'Making the link…';
    linksApi('POST', '', { label: p.name, relationship: p.rel, born_guess: p.born, sex_guess: p.sex }).then(function (res) {
      if (res.status === 201 && res.body.link) {
        S.people = S.people.filter(function (q) { return q !== p; }); save();
        S.links.push(res.body.link); S.inviteBox = { id: res.body.id, url: res.body.url }; S.linkMsg = '';
      } else { S.linkMsg = res.body.message || 'The link could not be made. Please try again.'; }
      renderAll();
    }, offline);
  }
  function onLinkAction(e) {
    var b = e.target.closest('[data-act]'); if (!b) return;
    var act = b.dataset.act, l = (S.links || []).filter(function (x) { return String(x.id) === b.dataset.id; })[0];
    if (act === 'copy') {
      var inp = $('#ht-invite-url'); inp.select();
      var copied = function () { b.textContent = 'Copied'; };
      if (navigator.clipboard) navigator.clipboard.writeText(inp.value).then(copied, function () { /* the text stays selected for a manual copy */ }); else if (document.execCommand('copy')) copied();
    } else if (act === 'share') {
      navigator.share({ title: 'See our healthspans side by side', url: $('#ht-invite-url').value }).catch(function () { /* closed the share sheet */ });
    } else if (l && (act === 'end' || act === 'end-no')) {
      l.confirming = act === 'end'; renderLinked();
    } else if (l && act === 'end-yes') {
      b.disabled = true;
      linksApi('DELETE', '/' + l.id).then(function (res) {
        if (res.status === 200) { l.status = 'revoked'; l.shared = null; if (S.inviteBox && S.inviteBox.id === l.id) S.inviteBox = null; S.linkMsg = ''; }
        else { S.linkMsg = res.body.message || 'The link could not be ended. Please try again.'; }
        l.confirming = false; renderAll();
      }, offline);
    }
  }

  /* ---------- storage (DA3: this browser only, one key per client) ---------- */
  var STORE = 'hs-state-preview', fixedYou = false;
  function cleanPerson(p) {
    if (!p || typeof p !== 'object') return null;
    var born = parseInt(p.born, 10), rate = p.rate === 'same' ? 'same' : +p.rate;
    if (!(born > 1850 && born < 2200) || (rate !== 'same' && !(rate >= 0.6 && rate <= 1.6))) return null;
    return { id: String(p.id || 'p' + born).slice(0, 20), name: String(p.name || '').slice(0, 40) || 'Someone', rel: REL.indexOf(p.rel) !== -1 ? p.rel : 'other', born: born, sex: p.sex === 'M' ? 'M' : 'F', rate: rate };
  }
  function restore() {
    var s; try { s = JSON.parse(root.localStorage.getItem(STORE)); } catch (e) { s = null; }
    if (!s || typeof s !== 'object') return;
    if (s.zoom >= 0 && s.zoom <= 100) S.zoom = +s.zoom;
    if (s.tab === 'you' || s.tab === 'people') S.tab = s.tab;
    if (S.mode === 'full') {
      if (Array.isArray(s.people)) S.people = s.people.map(cleanPerson).filter(Boolean).slice(0, 30);
      if (s.rate >= 0.6 && s.rate <= 1.6) S.you.rate = +s.rate;
      if ($('#ht-fs') && s.fs >= 0 && s.fs <= 100) S.fs = +s.fs;
    } else if (!fixedYou && s.you && typeof s.you === 'object') {
      var y = cleanPerson({ born: s.you.born, rate: s.you.rate0, name: s.you.name, sex: s.you.sex });
      if (y) { S.you.name = y.name; S.you.born = y.born; S.you.sex = y.sex; S.you.rate0 = S.you.rate = y.rate; S.you.bio = derivedBio(); S.people = defaultPeople(S.you); highlightPace = false; }
    }
  }
  function save() {
    var keep = { zoom: S.zoom, tab: S.tab };
    if (S.mode === 'full') { keep.people = S.people; keep.rate = S.you.rate; keep.fs = S.fs; }
    else if (!fixedYou) keep.you = { name: S.you.name, born: S.you.born, sex: S.you.sex, rate0: S.you.rate0 };
    try { root.localStorage.setItem(STORE, JSON.stringify(keep)); } catch (e) { /* private mode: the page still works, nothing kept */ }
  }

  /* ---------- boot ---------- */
  function reset(cfg) { S = fresh(cfg || {}); hover = null; return S; }
  function boot() {
    rootEl = document.getElementById('hdlv2-ht-root');
    if (!rootEl) return;
    var cfg = root.HDLV2_HT || {};
    var hasYou = !!(cfg.you && cfg.you.rate0);
    if (!hasYou && cfg.mode !== 'full') {
      var fr = readFragment(root.location.hash);
      if (fr) { cfg = Object.assign({}, cfg, { you: fr }); hasYou = true; }
    }
    reset(cfg);
    if (!hasYou) { S.you.bio = derivedBio(); }
    fixedYou = hasYou; highlightPace = !hasYou;
    STORE = S.mode === 'full' && cfg.store ? String(cfg.store) : 'hs-state-preview';
    rootEl.innerHTML = shellHtml(cfg);
    cv = $('#ht-cv'); ctx = cv.getContext('2d');
    renderStatic(cfg);
    restore();
    if (S.mode === 'full' && cfg.links && /^https:\/\//.test(cfg.links.url || '')) {
      try { OWNER = new URLSearchParams(root.location.search).get('t') || ''; } catch (e) { OWNER = ''; }
      if (/^[a-f0-9]{64}$/.test(OWNER)) { LINKS = cfg.links; $('#ht-tab-people').addEventListener('click', onLinkAction); loadLinks(); }
    }

    cv.addEventListener('mousemove', onMove);
    cv.addEventListener('mouseleave', function () { hover = null; drawTimeline(); renderMoment(S.you, NOW_F, 'year', true); });
    $('#ht-tabs').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; S.tab = b.dataset.t; save(); renderInputs(); };
    $('#ht-rate').oninput = function (e) { paintRange(e.target); S.you.rate = +e.target.value; $('#ht-rate-out').value = S.you.rate.toFixed(2) + '×'; rootEl.querySelectorAll('.ht-prof').forEach(function (b) { b.setAttribute('aria-pressed', Math.abs(+b.dataset.r - S.you.rate) < 0.005); }); renderLive(); };
    $('#ht-rate').onchange = function () { save(); renderAll(); };
    if ($('#ht-fs')) {
      $('#ht-fs').oninput = function (e) { paintRange(e.target); S.fs = +e.target.value; $('#ht-fs-out').value = fsLabel(S.fs); renderLive(); };
      $('#ht-fs').onchange = function () { save(); renderAll(); };
    }
    $('#ht-add').onsubmit = function (e) {
      e.preventDefault(); var name = $('#ht-a-name').value.trim().slice(0, 40), born = parseInt($('#ht-a-born').value, 10); if (!name || !(born > 1850 && born < 2200)) return;
      var p = cleanPerson({ id: 'p' + Date.now(), name: name, rel: $('#ht-a-rel').value, born: born, sex: $('#ht-a-sex').value, rate: $('#ht-a-rate').value });
      if (p) { S.people.push(p); save(); e.target.reset(); renderAll(); }
    };
    $('#ht-zoom').oninput = function (e) { paintRange(e.target); S.zoom = +e.target.value; drawTimeline(); var sc = $('#ht-scroller'); if (geo) sc.scrollLeft = Math.max(0, geo.left + (NOW_F - geo.minY) * geo.pxYear - sc.clientWidth / 2); };
    $('#ht-zoom').onchange = function () { save(); };
    var rt; root.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(drawTimeline, 120); });
    if (document.fonts) document.fonts.ready.then(drawTimeline);
    renderAll();
    if (highlightPace && S.tab === 'you') $('#ht-y-rate0').focus({ preventScroll: true });
  }

  root.HDLV2HealthspanTimeline = {
    KNOBS: K, NOW_Y: NOW_Y, NOW_F: NOW_F, state: function () { return S; }, reset: reset,
    bioAtRate: bioAtRate, stagesFor: stagesFor, yearsByStage: yearsByStage, weeksTogether: weeksTogether, visiblePeople: visiblePeople,
    lifestyleFromScores: lifestyleFromScores, bornFromAge: bornFromAge, readFragment: readFragment, paceLabel: paceLabel,
    visibleZoomBands: visibleZoomBands, ageLabel: ageLabel, shellHtml: shellHtml, iconHtml: iconHtml, depBarHtml: depBarHtml, presetHtml: presetHtml,
    HT_ICONS: HT_ICONS, momentHtml: momentHtml, paintRange: paintRange,
    linkedPerson: linkedPerson, chipLabel: chipLabel, linkedRowHtml: linkedRowHtml, inviteBoxHtml: inviteBoxHtml
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})(window);
