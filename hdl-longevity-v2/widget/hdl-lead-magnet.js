/**
 * HDL Lead Magnet Widget — Self-contained embeddable widget.
 *
 * V2: 9 evidence-based questions with visual silhouette selectors.
 * Flow: Q1 (age+sex) → Q2a (silhouettes) → Q2b (fat dist) → Q3-Q9 (MCQ) → Lead capture → Results
 *
 * Gauge: QuickChart.io gauge chart — range 0.8-1.4.
 * Zero external JS dependencies. Works on any site.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */
(function () {
  'use strict';

  // ---------- CALCULATION ENGINE (9-question algorithm) ----------

  var QUICK_WEIGHTS = { q2_body:1.5, q3_zone2:2.0, q4_vo2:2.0, q5_sts:2.0, q6_sleep:1.5, q7_smoking:2.5, q8_social:1.5, q9_diet:1.5 };
  var WEIGHT_SUM = 14.5;
  var Q2A_SCORES = { 1:2, 2:5, 3:3, 4:2, 5:1 };
  var Q2B_MODS = { apple:0.5, pear:-0.3, even:0.0 };
  var LETTER_MAP = { a:1, b:2, c:3, d:4, e:5 };

  // Age norms for physical questions — keys are upper age bounds
  var AGE_NORMS = {
    q3_zone2: [[29,3.8],[39,3.5],[49,3.0],[59,2.5],[69,2.0],[79,1.8],[999,1.5]],
    q4_vo2:   [[29,4.0],[39,3.5],[49,3.0],[59,2.5],[69,2.0],[79,1.5],[999,1.2]],
    q5_sts:   [[29,4.0],[39,3.5],[49,3.0],[59,2.5],[69,2.0],[79,1.5],[999,1.2]]
  };

  function applyAgeNorm(raw, age, norms) {
    var expected = 3.0;
    for (var i = 0; i < norms.length; i++) {
      if (age <= norms[i][0]) { expected = norms[i][1]; break; }
    }
    return Math.max(1, Math.min(5, 3.0 + (raw - expected)));
  }

  function compute(answers) {
    var age = Math.max(1, parseInt(answers.q1_age, 10) || 0);

    // Q2 combined
    var q2a = Q2A_SCORES[parseInt(answers.q2a, 10)] || 3;
    var q2b = Q2B_MODS[answers.q2b] || 0;
    var q2 = Math.max(1, Math.min(5, q2a - q2b));

    // Q3-Q9
    var raw = {
      q3_zone2: LETTER_MAP[answers.q3] || 3,
      q4_vo2:   LETTER_MAP[answers.q4] || 3,
      q5_sts:   LETTER_MAP[answers.q5] || 3,
      q6_sleep: answers.q6 === 'e' ? 2 : (LETTER_MAP[answers.q6] || 3),
      q7_smoking: LETTER_MAP[answers.q7] || 3,
      q8_social:  LETTER_MAP[answers.q8] || 3,
      q9_diet:    LETTER_MAP[answers.q9] || 3
    };

    // Apply age norms to physical Qs
    var scores = { q2_body: q2 };
    for (var k in raw) {
      scores[k] = AGE_NORMS[k] ? applyAgeNorm(raw[k], age, AGE_NORMS[k]) : raw[k];
    }

    // Weighted total → normalise → pace
    var total = 0;
    for (var w in QUICK_WEIGHTS) {
      total += (scores[w] || 3) * QUICK_WEIGHTS[w];
    }
    var norm = Math.max(0, Math.min(1, (total - WEIGHT_SUM) / (5 * WEIGHT_SUM - WEIGHT_SUM)));
    var rate = Math.max(0.8, Math.min(1.4, Math.round((1.4 - norm * 0.6) * 100) / 100));

    return { rate: rate, age: age, sex: answers.q1_sex || 'male', scores: scores };
  }

  // ---------- QUICKCHART GAUGE (0.8-1.4 range) ----------

  function gaugeUrl(rate) {
    var clamped = Math.max(0.8, Math.min(1.4, parseFloat(rate.toFixed(2))));
    var interp, interpColor;
    if (clamped <= 0.9) { interp = 'Slower'; interpColor = 'rgba(67, 191, 85, 1)'; }
    else if (clamped <= 1.1) { interp = 'Average'; interpColor = 'rgba(65, 165, 238, 1)'; }
    else { interp = 'Faster'; interpColor = 'rgba(255, 111, 75, 1)'; }

    var cfg = {
      type: 'gauge',
      data: {
        labels: ['Slower', 'Average', 'Faster'],
        datasets: [{
          data: [0.9, 1.1, 1.4], value: clamped, minValue: 0.8, maxValue: 1.4,
          backgroundColor: ['rgba(67,191,85,0.95)','rgba(65,165,238,0.95)','rgba(255,111,75,0.95)'],
          borderWidth: 1, borderColor: 'rgba(255,255,255,0.8)', borderRadius: 5
        }]
      },
      options: {
        layout: { padding: { top: 30, bottom: 15, left: 15, right: 15 } },
        plugins: {
          annotation: { annotations: {
            s08: { type:'label', content:'0.8', position:'center', font:{size:10,weight:'600',family:"'Inter',sans-serif"}, color:'rgba(0,79,89,0.6)', xValue:-0.9, yValue:0.75 },
            s09: { type:'label', content:'0.9', position:'center', font:{size:10,weight:'600',family:"'Inter',sans-serif"}, color:'rgba(0,79,89,0.6)', xValue:-0.6, yValue:0.75 },
            s10: { type:'label', content:'1.0', position:'center', font:{size:10,weight:'600',family:"'Inter',sans-serif"}, color:'rgba(0,79,89,0.6)', xValue:-0.1, yValue:0.8 },
            s11: { type:'label', content:'1.1', position:'center', font:{size:10,weight:'600',family:"'Inter',sans-serif"}, color:'rgba(0,79,89,0.6)', xValue:0.4, yValue:0.75 },
            s12: { type:'label', content:'1.2', position:'center', font:{size:10,weight:'600',family:"'Inter',sans-serif"}, color:'rgba(0,79,89,0.6)', xValue:0.7, yValue:0.75 },
            s13: { type:'label', content:'1.3', position:'center', font:{size:10,weight:'600',family:"'Inter',sans-serif"}, color:'rgba(0,79,89,0.6)', xValue:0.9, yValue:0.75 }
          }},
          datalabels: { display: false }
        },
        needle: { radiusPercentage:2.5, widthPercentage:4.0, lengthPercentage:68, color:'#004F59', shadowColor:'rgba(0,79,89,0.4)', shadowBlur:8, shadowOffsetY:4, borderWidth:2, borderColor:'rgba(255,255,255,1.0)' },
        valueLabel: { display:true, fontSize:36, fontFamily:"'Inter',sans-serif", fontWeight:'bold', color:'#004F59', backgroundColor:'transparent', bottomMarginPercentage:-10, padding:8 },
        centerArea: { displayText:false, backgroundColor:'transparent' },
        arc: { borderWidth:0, padding:2, margin:3, roundedCorners:true },
        subtitle: { display:true, text:interp, color:interpColor, font:{size:20,weight:'bold',family:"'Inter',sans-serif"}, padding:{top:8} }
      }
    };
    return 'https://quickchart.io/chart?c=' + encodeURIComponent(JSON.stringify(cfg)) + '&w=380&h=340&bkg=white';
  }

  // ---------- QUESTION DEFINITIONS ----------

  var QUESTIONS = {
    q3: {
      category: 'Fitness',
      title: 'Zone 2 Activity',
      text: 'How much steady, moderate-effort activity do you do in a typical week?',
      hint: 'Think: brisk walking, easy cycling, swimming at a comfortable pace, gardening \u2014 anything where you could hold a conversation but you\u2019re definitely moving.',
      opts: [
        { v:'a', t:'Almost none \u2014 I\u2019m mostly sedentary' },
        { v:'b', t:'About 30\u201360 minutes per week' },
        { v:'c', t:'About 1\u20132 hours per week' },
        { v:'d', t:'About 2\u20134 hours per week' },
        { v:'e', t:'More than 4 hours per week' }
      ]
    },
    q4: {
      category: 'Fitness',
      title: 'Cardiovascular Capacity',
      text: 'Imagine you\u2019re in a building with no lift. How would you handle climbing stairs?',
      cite: 'Stair climbing validated as a predictor of cardiovascular fitness (Peteiro et al., 2020 \u2014 94% sensitivity).',
      opts: [
        { v:'a', t:'One flight is difficult \u2014 I\u2019d need to stop and rest' },
        { v:'b', t:'I can walk up 2\u20133 flights but I\u2019d be noticeably out of breath' },
        { v:'c', t:'I can walk up 4\u20135 flights at a steady pace without stopping' },
        { v:'d', t:'I can walk up 5+ flights comfortably, or jog up 3\u20134 flights' },
        { v:'e', t:'I could run up 4\u20135 flights without significant difficulty' }
      ]
    },
    q5: {
      category: 'Fitness',
      title: 'Functional Strength',
      text: 'Imagine sitting cross-legged on the floor. How easily could you get back up to standing?',
      cite: 'Based on the Sitting-Rising Test (Dr Claudio Gil Ara\u00fajo). Each point scored reduced mortality risk by 21% over 6 years.',
      opts: [
        { v:'a', t:'I couldn\u2019t get down, or I\u2019d need someone to help me back up' },
        { v:'b', t:'I\u2019d need to use furniture or put both hands and a knee on the ground' },
        { v:'c', t:'I\u2019d use one hand or one knee for a bit of support' },
        { v:'d', t:'I could do it without support, but it takes effort' },
        { v:'e', t:'I can sit down and stand back up smoothly, no hands, no problem' }
      ]
    },
    q6: {
      category: 'Sleep',
      title: 'Sleep',
      text: 'On a typical night, how would you describe your sleep?',
      opts: [
        { v:'a', t:'Fewer than 5 hours, or I struggle most nights' },
        { v:'b', t:'About 5\u20136 hours, or I wake frequently and don\u2019t feel rested' },
        { v:'c', t:'About 6\u20137 hours, reasonable quality' },
        { v:'d', t:'About 7\u20138 hours, good quality \u2014 I usually wake feeling rested' },
        { v:'e', t:'I sleep 9+ hours but still feel tired' }
      ]
    },
    q7: {
      category: 'Lifestyle',
      title: 'Smoking',
      text: 'What\u2019s your smoking status?',
      opts: [
        { v:'a', t:'I smoke daily' },
        { v:'b', t:'I smoke occasionally or socially' },
        { v:'c', t:'I quit within the last 5 years' },
        { v:'d', t:'I quit more than 5 years ago' },
        { v:'e', t:'I\u2019ve never smoked (in last 10 years)' }
      ]
    },
    q8: {
      category: 'Mental Wellbeing',
      title: 'Social Connection',
      text: 'How often do you spend meaningful time with people you care about?',
      hint: 'In person, by phone, or video \u2014 not just social media or texting.',
      opts: [
        { v:'a', t:'Rarely \u2014 I feel isolated most of the time' },
        { v:'b', t:'Once or twice a month' },
        { v:'c', t:'About once a week' },
        { v:'d', t:'Several times a week' },
        { v:'e', t:'Daily \u2014 strong, regular connections' }
      ]
    },
    q9: {
      category: 'Nutrition',
      title: 'Diet Pattern',
      text: 'Which best describes your typical eating pattern?',
      opts: [
        { v:'a', t:'Mostly processed or fast food, very few vegetables' },
        { v:'b', t:'Mixed \u2014 some healthy meals, a lot of convenience food' },
        { v:'c', t:'Reasonably healthy \u2014 I cook most meals' },
        { v:'d', t:'Mostly whole foods, plenty of vegetables' },
        { v:'e', t:'Very clean \u2014 whole foods, diverse vegetables, minimal processed' }
      ]
    }
  };

  // Steps: q1, q2, q3, q4, q5, q6, q7, q8, q9, contact, result
  var STEP_COUNT = 11;

  // ---------- FONTS & BRAND ----------

  // v0.36.0 (Phase P) — editorial typography. Source Serif Pro for display
  // headings (matches STBY result-hero .hdlv2-s1-hero-title), Inter for body
  // and word eyebrows (matches .hdlv2-s1-stat-label), JetBrains Mono kept
  // only for tight numerical labels (step counter, MCQ option counters).
  function loadFonts() {
    if (document.getElementById('hdl-widget-fonts')) return;
    var link = document.createElement('link');
    link.id = 'hdl-widget-fonts';
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Source+Serif+Pro:wght@400;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap';
    document.head.appendChild(link);
  }

  // Brand tokens. v0.36.0 — added serif/mono families and the cream paper
  // surface lifted from the Stage 1 PDF mockup (mockups/v2-stage1-premium).
  var B = {
    serif:  "'Source Serif Pro',Georgia,serif",
    body:   "'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif",
    mono:   "'JetBrains Mono',ui-monospace,'SF Mono',Menlo,monospace",
    // Legacy alias kept for the result-page block which still references
    // B.heading. Source Serif Pro reads identically there.
    heading: "'Source Serif Pro',Georgia,serif",
    dark: '#1f2937', text: '#374151', muted: '#6b7280', hint: '#9ca3af',
    border: '#e4e6ea', bg: '#f8f9fb', surface: '#ffffff',
    cream: '#faf8f5', creamDeep: '#f4f0e8',
    teal: '#3d8da0', deepTeal: '#004F59', radius: '4px',
    green: '#10b981', coral: '#f59e0b'
  };

  // ---------- DERIVE ASSET BASE URL ----------

  function getAssetBase() {
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      var src = scripts[i].src || '';
      if (src.indexOf('hdl-lead-magnet.js') !== -1) {
        return src.replace(/widget\/hdl-lead-magnet\.js.*$/, 'assets/images/silhouettes/');
      }
    }
    return '';
  }

  var IMG_BASE = getAssetBase();

  // v0.36.0 (Phase P) — Stage 1 PNG icons retired. The form is now a clean
  // editorial card; the q3/q4/q5 illustration spots that used to load
  // /assets/images/stage1-icons/*.png are replaced by italic citation
  // microcopy (`q.cite`) sitting under each question prompt. The PNG files
  // remain on disk for compatibility but nothing references them.

  // ---------- INVITE MODE ----------

  function getInviteToken() {
    try { return new URLSearchParams(window.location.search).get('invite') || ''; }
    catch (e) { return ''; }
  }

  // v0.36.0 (Phase P) — single editorial style block injected once per page.
  // The widget runs sandboxed on third-party sites, so we keep every selector
  // namespaced under .hdlw-* (form) and .hdlw-r-* (result, defined inline by
  // renderResult). No global CSS leaks. The style block is intentionally
  // verbose so render functions stay tiny class-based templates.
  function injectEditorialStyles() {
    if (document.getElementById('hdl-spin-style')) return;
    var s = document.createElement('style');
    s.id = 'hdl-spin-style';
    s.textContent = ''
      + '@keyframes hdlspin{to{transform:rotate(360deg)}}'
      // ----- Outer shell + cream paper card -----
      + '.hdlw-shell{position:relative;font-family:' + B.body + ';max-width:480px;margin:0 auto;}'
      + '.hdlw-card{background:' + B.cream + ';background-image:radial-gradient(circle at 0% 100%,rgba(61,141,160,0.05) 0%,transparent 50%),radial-gradient(circle at 100% 0%,rgba(0,79,89,0.035) 0%,transparent 45%);background-repeat:no-repeat;border:1px solid ' + B.border + ';border-radius:' + B.radius + ';box-shadow:0 6px 32px rgba(0,0,0,0.08);overflow:hidden;color:' + B.dark + ';display:flex;flex-direction:column;}'
      // ----- Hairline progress + header strip -----
      + '.hdlw-progress{height:2px;background:' + B.border + ';position:relative;}'
      + '.hdlw-progress-bar{position:absolute;left:0;top:0;bottom:0;background:var(--hdl-accent,#3d8da0);transition:width 0.4s ease;}'
      + '.hdlw-header{display:flex;justify-content:space-between;align-items:center;padding:16px 28px 14px;border-bottom:1px solid ' + B.border + ';}'
      + '.hdlw-eyebrow{font-family:' + B.body + ';font-size:10px;color:var(--hdl-accent-deep,#004F59);letter-spacing:0.14em;text-transform:uppercase;font-weight:600;display:flex;align-items:center;gap:8px;}'
      + '.hdlw-eyebrow::before{content:"";width:16px;height:1px;background:var(--hdl-accent-deep,#004F59);display:inline-block;}'
      + '.hdlw-step{font-family:' + B.mono + ';font-size:9px;color:' + B.muted + ';letter-spacing:0.16em;text-transform:uppercase;}'
      + '.hdlw-step b{color:var(--hdl-accent-deep,#004F59);font-weight:600;font-style:normal;}'
      // ----- Section opener (eyebrow + Source Serif Pro title + rule + prompt) -----
      + '.hdlw-q-opener{padding:24px 28px 14px;}'
      + '.hdlw-q-section{font-family:' + B.body + ';font-size:10.5px;color:' + B.muted + ';letter-spacing:0.12em;text-transform:uppercase;margin:0 0 8px;font-weight:600;}'
      + '.hdlw-q-title{font-family:' + B.serif + ';font-weight:600;font-size:24px;line-height:1.1;color:var(--hdl-accent-deep,#004F59);letter-spacing:-0.02em;margin:0;}'
      + '.hdlw-q-rule{width:36px;height:2px;background:var(--hdl-accent,#3d8da0);margin:12px 0 14px;}'
      + '.hdlw-q-prompt{font-family:' + B.body + ';font-size:14px;line-height:1.55;color:' + B.text + ';margin:0 0 8px;}'
      + '.hdlw-q-hint{font-family:' + B.body + ';font-size:12.5px;line-height:1.55;color:' + B.muted + ';margin:4px 0 0;font-style:italic;}'
      + '.hdlw-q-cite{font-family:' + B.body + ';font-size:11px;color:' + B.muted + ';letter-spacing:0.01em;margin:12px 0 0;line-height:1.55;font-style:italic;}'
      // ----- MCQ options (mono numerals 01-05, border-left accent) -----
      + '.hdlw-opts{padding:8px 28px 22px;display:flex;flex-direction:column;gap:6px;}'
      + '.hdlw-opt{display:grid;grid-template-columns:26px 1fr;gap:12px;align-items:baseline;padding:12px 14px 12px 12px;background:' + B.surface + ';border:1px solid ' + B.border + ';border-left:2px solid transparent;border-radius:2px;cursor:pointer;transition:border-color 0.15s,background 0.15s;}'
      + '.hdlw-opt:hover{border-color:#d1d5db;border-left-color:var(--hdl-accent,#3d8da0);}'
      + '.hdlw-opt.hdlw-selected{border-left-color:var(--hdl-accent,#3d8da0);background:rgba(61,141,160,0.05);}'
      + '.hdlw-opt-num{font-family:' + B.mono + ';font-size:9.5px;color:var(--hdl-accent,#3d8da0);letter-spacing:0.1em;font-weight:500;line-height:1.6;padding-top:2px;}'
      + '.hdlw-opt.hdlw-selected .hdlw-opt-num{color:var(--hdl-accent-deep,#004F59);font-weight:600;}'
      + '.hdlw-opt-text{font-family:' + B.body + ';font-size:13.5px;color:' + B.text + ';line-height:1.5;}'
      + '.hdlw-opt.hdlw-selected .hdlw-opt-text{color:' + B.dark + ';font-weight:500;}'
      // ----- Q1 fields + sex selector -----
      + '.hdlw-fields{padding:4px 28px 22px;}'
      + '.hdlw-field{margin-bottom:16px;}'
      + '.hdlw-field-label{font-family:' + B.body + ';font-size:10.5px;color:' + B.muted + ';letter-spacing:0.1em;text-transform:uppercase;margin:0 0 8px;display:block;font-weight:600;}'
      + '.hdlw-field-input{width:100%;padding:12px 14px;background:' + B.surface + ';border:1px solid ' + B.border + ';border-radius:2px;font-family:' + B.body + ';font-size:16px;color:' + B.dark + ';box-sizing:border-box;transition:border-color 0.15s;}' /* v0.46.21 (QA F14) 14px->16px: <16px makes iOS Safari auto-zoom on focus */
      + '.hdlw-field-input:focus{outline:none;border-color:var(--hdl-accent,#3d8da0);}'
      + '.hdlw-field-foot{font-family:' + B.body + ';font-size:11px;color:' + B.muted + ';margin:4px 0 0;}'
      + '.hdlw-field-locked{display:block;padding:12px 14px;background:' + B.creamDeep + ';border:1px solid ' + B.border + ';border-radius:2px;font-family:' + B.body + ';font-size:13.5px;color:' + B.muted + ';}'
      + '.hdlw-sex-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;}'
      + '.hdlw-sex-opt{background:' + B.surface + ';border:1px solid ' + B.border + ';border-left:2px solid transparent;padding:13px;text-align:center;cursor:pointer;font-family:' + B.body + ';font-size:13.5px;font-weight:500;color:' + B.text + ';border-radius:2px;transition:border-color 0.15s,background 0.15s;}'
      + '.hdlw-sex-opt:hover{border-color:#d1d5db;}'
      + '.hdlw-sex-opt.hdlw-selected{border-left-color:var(--hdl-accent,#3d8da0);background:rgba(61,141,160,0.05);color:' + B.dark + ';}'
      // ----- Primary CTA (Inter weight 600, no rounded pill) -----
      + '.hdlw-cta{display:block;width:100%;padding:14px 24px;background:var(--hdl-accent-deep,#004F59);color:#fff;border:1px solid var(--hdl-accent-deep,#004F59);border-radius:2px;font-family:' + B.body + ';font-size:13.5px;font-weight:600;letter-spacing:0.04em;cursor:pointer;text-align:center;transition:background 0.15s;margin-top:12px;}'
      + '.hdlw-cta:hover{background:var(--hdl-accent,#3d8da0);border-color:var(--hdl-accent,#3d8da0);}'
      + '.hdlw-cta:disabled{opacity:0.6;cursor:default;}'
      // ----- Q2 silhouettes (no halos, thin teal underline on selection) -----
      + '.hdlw-sil-strip{padding:6px 24px 14px;display:flex;gap:4px;justify-content:space-between;}'
      + '.hdlw-sil{flex:1;text-align:center;cursor:pointer;padding:10px 4px 6px;border-bottom:2px solid transparent;transition:background 0.15s,border-color 0.15s;border-radius:2px 2px 0 0;}'
      + '.hdlw-sil:hover{background:rgba(61,141,160,0.04);}'
      + '.hdlw-sil.hdlw-selected{border-bottom-color:var(--hdl-accent,#3d8da0);background:rgba(61,141,160,0.04);}'
      + '.hdlw-sil img{width:38px;height:auto;display:block;margin:0 auto 6px;filter:grayscale(0.3);}'
      + '.hdlw-sil.hdlw-selected img{filter:none;}'
      + '.hdlw-sil-label{font-family:' + B.body + ';font-size:9px;color:' + B.muted + ';letter-spacing:0.08em;text-transform:uppercase;font-weight:600;line-height:1.3;}'
      + '.hdlw-sil.hdlw-selected .hdlw-sil-label{color:var(--hdl-accent-deep,#004F59);}'
      // ----- Q2b fat distribution -----
      + '.hdlw-q2b{margin:0 28px 18px;padding-top:16px;border-top:1px dashed ' + B.border + ';}'
      + '.hdlw-q2b-title{font-family:' + B.body + ';font-size:12.5px;color:' + B.text + ';margin:0 0 4px;font-weight:500;}'
      + '.hdlw-q2b-hint{font-family:' + B.body + ';font-size:11.5px;color:' + B.muted + ';margin:0 0 12px;font-style:italic;}'
      + '.hdlw-fat-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;}'
      + '.hdlw-fat{background:' + B.surface + ';border:1px solid ' + B.border + ';border-left:2px solid transparent;padding:12px 6px;text-align:center;cursor:pointer;border-radius:2px;transition:border-color 0.15s,background 0.15s;}'
      + '.hdlw-fat:hover{border-color:#d1d5db;}'
      + '.hdlw-fat.hdlw-selected{border-left-color:var(--hdl-accent,#3d8da0);background:rgba(61,141,160,0.05);}'
      + '.hdlw-fat img{width:34px;height:auto;display:block;margin:0 auto 6px;filter:grayscale(0.3);}'
      + '.hdlw-fat.hdlw-selected img{filter:none;}'
      + '.hdlw-fat-label{font-family:' + B.body + ';font-size:11.5px;color:' + B.text + ';font-weight:500;margin:0;line-height:1.3;}'
      + '.hdlw-fat.hdlw-selected .hdlw-fat-label{color:' + B.dark + ';}'
      // ----- Contact callout (replaces shield SVG) -----
      + '.hdlw-callout{margin:6px 28px 16px;background:' + B.surface + ';border-left:3px solid var(--hdl-accent,#3d8da0);padding:14px 18px;border-radius:0 3px 3px 0;border-top:1px solid ' + B.border + ';border-right:1px solid ' + B.border + ';border-bottom:1px solid ' + B.border + ';}'
      + '.hdlw-callout-label{font-family:' + B.body + ';font-weight:600;font-size:10.5px;color:var(--hdl-accent-deep,#004F59);letter-spacing:0.12em;text-transform:uppercase;margin:0 0 6px;}'
      + '.hdlw-callout-body{font-family:' + B.body + ';font-size:12.5px;line-height:1.55;color:' + B.text + ';margin:0;}'
      + '.hdlw-callout-body b{color:var(--hdl-accent-deep,#004F59);font-weight:600;font-style:normal;}'
      + '.hdlw-privacy{font-family:' + B.body + ';font-size:11px;color:' + B.hint + ';text-align:center;letter-spacing:0.02em;margin:14px 0 0;line-height:1.5;}'
      + '.hdlw-privacy b{color:var(--hdl-accent-deep,#004F59);font-weight:500;font-style:normal;}'
      // ----- Practitioner footer (every form card, shape-aware logo) -----
      // v0.36.12 — stacked left layout: logo on top, practitioner name in the
      // middle, "Powered by HealthDataLab" at the bottom. All anchored to the
      // left edge. Previous layout was a 3-column grid (logo+name | spacer |
      // powered-by right-aligned).
      + '.hdlw-footer{border-top:1px solid ' + B.border + ';padding:14px 24px;background:rgba(244,240,232,0.4);display:flex;flex-direction:column;align-items:flex-start;gap:8px;}'
      + '.hdlw-prac-text{margin:0;}'
      + '.hdlw-prac-overline{font-family:' + B.body + ';font-size:9px;color:' + B.hint + ';letter-spacing:0.12em;text-transform:uppercase;font-weight:600;margin:0;line-height:1.2;}'
      + '.hdlw-prac-name{font-family:' + B.body + ';font-weight:600;font-size:12.5px;color:var(--hdl-accent-deep,#004F59);margin:1px 0 0;line-height:1.2;}'
      + '.hdlw-powered{font-family:' + B.body + ';font-size:9.5px;color:' + B.hint + ';letter-spacing:0.1em;text-transform:uppercase;font-weight:500;text-align:left;}'
      + '.hdlw-powered b{color:var(--hdl-accent-deep,#004F59);font-weight:500;font-style:normal;}'
      // ----- Shape-aware logo container -----
      + '.hdlw-logo{background:#fff;border:1px solid ' + B.border + ';flex-shrink:0;display:flex;align-items:center;justify-content:center;overflow:hidden;box-sizing:border-box;}'
      + '.hdlw-logo[data-shape="square"]{width:36px;height:36px;border-radius:50%;padding:5px;}'
      + '.hdlw-logo[data-shape="wordmark"]{height:32px;padding:4px 10px;border-radius:6px;}'
      + '.hdlw-logo[data-shape="tall"]{height:36px;padding:3px 6px;border-radius:4px;}'
      + '.hdlw-logo img{max-width:100%;max-height:100%;width:auto;height:auto;display:block;object-fit:contain;}'
      // ----- Top-corner nav arrows (kept from prior version, restyled) -----
      + '.hdlw-nav{position:absolute;top:8px;width:26px;height:26px;display:none;align-items:center;justify-content:center;background:transparent;border:none;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;font-family:inherit;padding:0;transition:background 0.15s,color 0.15s;z-index:2;}'
      + '.hdlw-nav-prev{left:6px;}'
      + '.hdlw-nav-next{right:6px;}'
      + '.hdlw-nav:hover{background:rgba(61,141,160,0.08);}'
      + '.hdlw-nav:disabled{cursor:default;color:#cbd5e1 !important;}'
      // ----- Spinner card + invite-error card -----
      + '.hdlw-spin-card{font-family:' + B.body + ';max-width:480px;margin:0 auto;border:1px solid ' + B.border + ';border-radius:' + B.radius + ';overflow:hidden;background:' + B.cream + ';box-shadow:0 6px 32px rgba(0,0,0,0.08);padding:60px 28px;text-align:center;}'
      + '.hdlw-spin-dot{width:36px;height:36px;border:3px solid ' + B.border + ';border-top-color:var(--hdl-accent,#3d8da0);border-radius:50%;margin:0 auto 16px;animation:hdlspin 0.8s linear infinite;}'
      + '.hdlw-spin-text{font-family:' + B.body + ';font-size:13px;color:' + B.muted + ';margin:0;letter-spacing:0.04em;}'
      + '.hdlw-error-card{font-family:' + B.body + ';max-width:480px;margin:0 auto;border:1px solid ' + B.border + ';border-radius:' + B.radius + ';background:' + B.cream + ';box-shadow:0 6px 32px rgba(0,0,0,0.08);padding:48px 32px;text-align:center;}'
      + '.hdlw-error-eyebrow{font-family:' + B.body + ';font-size:10px;color:#dc2626;letter-spacing:0.18em;text-transform:uppercase;font-weight:600;margin:0 0 10px;}'
      + '.hdlw-error-title{font-family:' + B.serif + ';margin:0 0 8px;font-size:22px;font-weight:600;color:var(--hdl-accent-deep,#004F59);letter-spacing:-0.01em;line-height:1.15;}'
      + '.hdlw-error-msg{font-family:' + B.body + ';font-size:13.5px;color:' + B.text + ';margin:0;line-height:1.55;}'
      // ----- Result-page logo override -----
      // The renderResult inline block emits .hdlw-r-prac-foot-logo as a 42px
      // circle (object-fit:cover) which crops wordmarks. v0.36.0 retires
      // that class in favour of the shape-aware .hdlw-logo placed inside
      // .hdlw-r-prac-foot-left, so the new render path uses the same rules
      // as every other surface. Legacy class kept as a no-op for any page
      // that still has cached HTML — width:auto + max-* lets the browser
      // hand off to the inner .hdlw-logo sizing.
      // v0.36.14 — scope result-footer logo overrides to .hdlw-r-prac-foot
      // (the .hdlw-r-prac-foot-left flex wrapper is retired). Strip the white
      // container (bg/border/padding) so the wordmark renders naked on the
      // page background. Square avatars bump up to 48px since they're now
      // anchoring the practitioner identity block without a chip behind them.
      + '.hdlw-r-prac-foot .hdlw-logo{background:transparent;border:0;padding:0;}'
      + '.hdlw-r-prac-foot .hdlw-logo[data-shape="square"]{width:48px;height:48px;border-radius:50%;}'
      + '.hdlw-r-prac-foot .hdlw-logo[data-shape="tall"]{height:46px;}'
      + '.hdlw-r-prac-foot .hdlw-logo[data-shape="wordmark"]{height:38px;}'
      // ----- v0.43.0 front-door safety screen (checkbox rows) -----
      + '.hdlw-sfx-opt{grid-template-columns:24px 1fr;align-items:center;}'
      + '.hdlw-sfx-box{width:16px;height:16px;border:1.5px solid #cbd5e1;border-radius:3px;background:#fff;position:relative;flex-shrink:0;transition:background 0.15s,border-color 0.15s;}'
      + '.hdlw-sfx-opt:hover .hdlw-sfx-box{border-color:var(--hdl-accent,#3d8da0);}'
      + '.hdlw-sfx-opt.hdlw-selected .hdlw-sfx-box{background:var(--hdl-accent,#3d8da0);border-color:var(--hdl-accent,#3d8da0);}'
      + '.hdlw-sfx-opt.hdlw-selected .hdlw-sfx-box::after{content:"";position:absolute;left:5px;top:2px;width:4px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg);}'
      + '.hdlw-sfx-none{margin-top:6px;}'
      // v0.45.2 — stacked sections, each list 2-up. "A quick safety check"
      // then "How you’ve been feeling", each opener full-width above its own
      // 2-up checklist. Removes the long-vs-short dead space and the old 168px
      // header-align magic number. renderSafetyScreen still widens the shell to
      // 880px for the grid and restores it on exit (sfxContinue); collapses to
      // one column under 720px (phones / narrow embeds).
      + '.hdlw-sfx-grid{padding:8px 28px 22px;display:grid;grid-template-columns:1fr 1fr;gap:6px;}'
      + '.hdlw-sfx-grid .hdlw-sfx-none{grid-column:1 / -1;}'
      + '.hdlw-sfx-sec + .hdlw-sfx-sec .hdlw-q-opener{padding-top:6px;}'
      + '@media (max-width:720px){.hdlw-sfx-grid{grid-template-columns:1fr;}}';
    document.head.appendChild(s);
  }

  function showSpinner(el) {
    loadFonts();
    injectEditorialStyles();
    el.innerHTML = '<div class="hdlw-shell"><div class="hdlw-spin-card">'
      + '<div class="hdlw-spin-dot"></div>'
      + '<p class="hdlw-spin-text">Loading your assessment…</p>'
      + '</div></div>';
  }

  function showInviteError(el, reason) {
    loadFonts();
    injectEditorialStyles();
    var title = reason === 'expired' ? 'This link has expired' : reason === 'completed' ? 'Assessment already completed' : 'Invalid link';
    var msg   = reason === 'expired' ? 'Please contact your practitioner for a new assessment link.' : reason === 'completed' ? 'This assessment link has already been used.' : 'This assessment link is not valid.';
    el.innerHTML = '<div class="hdlw-shell"><div class="hdlw-error-card">'
      + '<p class="hdlw-error-eyebrow">Assessment link</p>'
      + '<h3 class="hdlw-error-title">' + title + '</h3>'
      + '<p class="hdlw-error-msg">' + msg + '</p>'
      + '</div></div>';
  }

  // ---------- WIDGET BUILDER ----------

  function buildWidget(el, invite, publicCfg) {
    loadFonts();
    injectEditorialStyles();

    var cfg = {
      pracId: el.getAttribute('data-practitioner-id') || '',
      pracName: el.getAttribute('data-practitioner-name') || '',
      logo: el.getAttribute('data-logo') || '',
      // v0.36.0 (Phase P) — server-detected aspect-ratio shape for the
      // practitioner logo. Renders correctly on every surface without
      // client-side detection. Defaults to 'square' so the round-avatar
      // path is the safe default for legacy embeds without the attr.
      logoShape: el.getAttribute('data-logo-shape') || 'square',
      ctaText: el.getAttribute('data-cta-text') || ('Book a session with ' + (el.getAttribute('data-practitioner-name') || 'a practitioner')),
      ctaLink: el.getAttribute('data-cta-link') || '#',
      apiUrl: el.getAttribute('data-api') || '',
      color: el.getAttribute('data-color') || B.teal,
      // v0.35.0 (Phase O) — server-controlled toggle for showing the
      // Book-a-Session secondary button on the public-path result page.
      // Default false (Matthew's "no button" rule); flipped to true via
      // the public-config fetch if widget_config.show_book_button_after_widget
      // is set for this practitioner. Invite path always shows Book when
      // cta_link is configured (separate code path; this flag only gates
      // the public-path render).
      showBookOnPublic: false
    };

    // v0.35.0 (Phase O) — public-config overlay. The init layer fetches
    // /widget/public-config before calling buildWidget, so the embed is
    // set-and-forget: practitioners update Widget Settings (booking link,
    // theme colour, CTA text, the Book-button toggle) and every embedded
    // widget picks up the new values on the next page load. Without this
    // overlay the widget would only ever see the data-* attributes baked
    // into the embed snippet at copy-paste time.
    //
    // Applied BEFORE the invite override below so a signed invite can
    // still take precedence — the invite payload is server-validated and
    // is the authoritative source for that specific session.
    if (publicCfg && !invite) {
      if (publicCfg.practitioner_name) cfg.pracName  = publicCfg.practitioner_name;
      if (publicCfg.logo_url)          cfg.logo      = publicCfg.logo_url;
      if (publicCfg.logo_shape)        cfg.logoShape = publicCfg.logo_shape;
      if (publicCfg.cta_link)          cfg.ctaLink   = publicCfg.cta_link;
      if (publicCfg.cta_text)          cfg.ctaText   = publicCfg.cta_text;
      if (publicCfg.theme_color)       cfg.color     = publicCfg.theme_color;
      cfg.showBookOnPublic = !!publicCfg.show_book_button_after_widget;
    }

    var inviteToken = '', inviteName = '', inviteEmail = '';
    if (invite) {
      cfg.pracId = invite.practitioner_id;
      cfg.pracName = invite.practitioner_name || cfg.pracName;
      cfg.logo = invite.logo_url || cfg.logo;
      cfg.logoShape = invite.logo_shape || cfg.logoShape;
      cfg.ctaText = invite.cta_text || cfg.ctaText;
      cfg.ctaLink = invite.cta_link || cfg.ctaLink;
      cfg.color = invite.theme_color || cfg.color;
      cfg.apiUrl = invite.api_url || cfg.apiUrl;
      cfg.showBookOnPublic = invite.show_book_button_after_widget !== undefined
        ? !!invite.show_book_button_after_widget
        : cfg.showBookOnPublic;
      inviteToken = invite._token;
      inviteName = invite.client_name || '';
      inviteEmail = invite.client_email || '';
    }
    // Defensive — legacy practitioners without a logo_shape on file land
    // on 'square' (centred circle). Any unknown value also falls through
    // to 'square' so the CSS always finds a matching rule.
    if (cfg.logoShape !== 'square' && cfg.logoShape !== 'wordmark' && cfg.logoShape !== 'tall') {
      cfg.logoShape = 'square';
    }

    // v0.43.0 - front-door safety screen flag. Exposed on BOTH the public-
    // config payload (public path) and the invite verify payload (invite
    // path), so it reaches both Stage-1 entry routes.
    cfg.safetyScreen = !!((publicCfg && publicCfg.safety_screen_enabled) || (invite && invite.safety_screen_enabled));

    var id = 'hdlw-' + cfg.pracId;
    var c = cfg.color;
    // v0.40.11 — stamp practitioner colour onto the widget root so the
    // class-based CSS (which now reads var(--hdl-accent,…) and
    // var(--hdl-accent-deep,…)) cascades the picked colour to every
    // styled element. Single colour drives both vars; this also makes
    // the Client Tools Widget Settings live preview actually react when
    // the colour picker changes, because dashboard.js re-renders <div>
    // with a fresh data-color and re-calls hdlRateWidgetInit().
    try {
      el.style.setProperty('--hdl-accent', c);
      el.style.setProperty('--hdl-accent-deep', c);
    } catch (e) { /* no-op: CSSStyleDeclaration always exists on HTMLElement */ }
    var answers = {};
    var currentStep = 0;
    var totalSteps = STEP_COUNT;

    // v0.29.0 — Invite-path prefill. When the practitioner issued this invite
    // for an email that previously submitted the public widget, the server
    // hands us the prior 9 answers so the visitor lands on a populated form
    // and can review/edit before continuing to Stage 2. The MCQ + silhouette
    // renderers each read answers[qKey] when binding `.selected`, so writing
    // here is enough — no per-step rebind needed. Whitelist matches the
    // server-side sanitiser in rest_verify_invite().
    if (invite && invite.prefill_stage1 && typeof invite.prefill_stage1 === 'object') {
      var pf = invite.prefill_stage1;
      var keys = ['q1_age','q1_sex','q2a','q2b','q3','q4','q5','q6','q7','q8','q9'];
      for (var pi = 0; pi < keys.length; pi++) {
        var k = keys[pi];
        if (pf[k] === undefined || pf[k] === null) continue;
        if (k === 'q1_age' || k === 'q2a') {
          var n = parseInt(pf[k], 10);
          if (n > 0) answers[k] = n;
        } else {
          answers[k] = String(pf[k]);
        }
      }
    }

    // --- Build HTML shell ---
    // position:relative on the outer card so the top-corner Prev/Next arrows
    // can be absolutely positioned inside it. Both arrows render with display:none
    // initially; updateNavArrows() (called from every goTo()) toggles visibility +
    // disabled-state per step.
    // v0.36.0 (Phase P) — Editorial shell. Cream paper card with a top
    // hairline progress bar, header strip carrying the "QUICK ASSESSMENT"
    // word eyebrow + the mono step counter ("01/09"), and a fixed
    // practitioner footer that uses the shape-aware .hdlw-logo so a
    // wordmark logo no longer crops into a 42px circle. The previous
    // top-of-card centred logo + "Curious about your rate of ageing?"
    // marquee is dropped — Q1 now opens with its own SECTION 01 ·
    // CALIBRATION editorial header (set by renderQ1).
    // v0.36.12 — emit logo + name block as direct siblings of .hdlw-footer.
    // The footer is now a left-aligned vertical flex column (logo on top, name
    // in the middle, "Powered by HDL" at the bottom). The old .hdlw-prac
    // flex-row wrapper is gone.
    var pracBlock = ''
      + ( cfg.logo
          ? '<div class="hdlw-logo" data-shape="' + escHtml(cfg.logoShape) + '"><img src="' + escHtml(cfg.logo) + '" alt=""></div>'
          : '' )
      + '<div class="hdlw-prac-text">'
      +   '<p class="hdlw-prac-overline">Your practitioner</p>'
      +   '<p class="hdlw-prac-name">' + escHtml(cfg.pracName || 'Your longevity practitioner') + '</p>'
      + '</div>';

    el.innerHTML = ''
      + '<div class="hdlw-shell">'
      +   '<button id="' + id + '-nav-prev" type="button" aria-label="Previous question" class="hdlw-nav hdlw-nav-prev" style="color:' + c + ';">‹</button>'
      +   '<button id="' + id + '-nav-next" type="button" aria-label="Next question"   class="hdlw-nav hdlw-nav-next" style="color:' + c + ';">›</button>'
      +   '<div class="hdlw-card">'
      +     '<div class="hdlw-progress"><div class="hdlw-progress-bar" id="' + id + '-progbar"></div></div>'
      +     '<div class="hdlw-header">'
      +       '<span class="hdlw-eyebrow">Quick Assessment</span>'
      +       '<span class="hdlw-step" id="' + id + '-step"><b>01</b>&thinsp;/&thinsp;09</span>'
      +     '</div>'
      +     '<div id="' + id + '-content"></div>'
      +     '<div class="hdlw-footer">'
      +       pracBlock
      +       '<span class="hdlw-powered">Powered by <b>HealthDataLab</b></span>'
      +     '</div>'
      +   '</div>'
      + '</div>';

    var contentEl = document.getElementById(id + '-content');
    var progBar   = document.getElementById(id + '-progbar');
    var stepEl    = document.getElementById(id + '-step');

    // Step counter labels track the editorial 01–09 numerals + named
    // states. Welcome / Q1 = "01", Q2 = "02", ..., Q9 = "09",
    // Contact = "Contact", Result = "Done".
    function updateStep(step) {
      if (!stepEl) return;
      var labels = ['01','02','03','04','05','06','07','08','09','Contact','Done'];
      var label  = labels[step] || '01';
      // Mono numerals only — words ("Contact", "Done") render as plain
      // Inter via the .hdlw-step b[font-style:normal] override.
      stepEl.innerHTML = '<b>' + label + '</b>' + (step <= 8 ? '&thinsp;/&thinsp;09' : '');
    }

    function updateProgress() {
      var pct = Math.round((currentStep / (totalSteps - 1)) * 100);
      progBar.style.width = pct + '%';
    }

    function goTo(step) {
      currentStep = step;
      updateProgress();
      updateStep(step);
      var renderers = [renderQ1, renderQ2, renderQ3, renderQ4, renderQ5, renderQ6, renderQ7, renderQ8, renderQ9, renderContact, renderResult];
      renderers[step]();
      updateNavArrows();
    }

    function advance() {
      setTimeout(function () { goTo(currentStep + 1); }, 250);
    }

    function goBack() {
      // Step 0 (Q1) and step 10 (result) have no "back". Steps 1-9 do.
      if (currentStep > 0 && currentStep < 10) goTo(currentStep - 1);
    }

    // Per the visibility table:
    //   step 0  (Q1):       both hidden — bottom Next button is the only forward CTA
    //   step 1-8 (Q2-Q9):   prev visible, next visible (next disabled until answered)
    //   step 9  (contact):  prev visible, next hidden (contact has its own Submit)
    //   step 10 (result):   both hidden
    function updateNavArrows() {
      var prev = document.getElementById(id + '-nav-prev');
      var next = document.getElementById(id + '-nav-next');
      if (!prev || !next) return;

      if (currentStep === 0 || currentStep === 10) {
        prev.style.display = 'none';
        next.style.display = 'none';
        return;
      }
      prev.style.display = 'flex';
      next.style.display = (currentStep === 9) ? 'none' : 'flex';

      var ready = isStepAnswered(currentStep);
      next.style.color  = ready ? c : '#cbd5e1';
      next.style.cursor = ready ? 'pointer' : 'default';
      next.disabled     = !ready;
    }

    function isStepAnswered(step) {
      switch (step) {
        case 1: return answers.q2a != null && answers.q2b != null; // Body Shape needs both halves
        case 2: return !!answers.q3;
        case 3: return !!answers.q4;
        case 4: return !!answers.q5;
        case 5: return !!answers.q6;
        case 6: return !!answers.q7;
        case 7: return !!answers.q8;
        case 8: return !!answers.q9;
        default: return false;
      }
    }

    // Wire up the Prev/Next click + hover behaviour. Bindings live here (just
    // after navigation primitives) so they're set up once on init.
    var navPrevBtn = document.getElementById(id + '-nav-prev');
    var navNextBtn = document.getElementById(id + '-nav-next');
    if (navPrevBtn) {
      navPrevBtn.addEventListener('click', goBack);
      navPrevBtn.addEventListener('mouseenter', function () { this.style.background = 'rgba(61,141,160,0.08)'; });
      navPrevBtn.addEventListener('mouseleave', function () { this.style.background = 'transparent'; });
    }
    if (navNextBtn) {
      navNextBtn.addEventListener('click', function () {
        if (!isStepAnswered(currentStep)) return; // disabled state — silent no-op
        goTo(currentStep + 1);
      });
      navNextBtn.addEventListener('mouseenter', function () {
        if (!this.disabled) this.style.background = 'rgba(61,141,160,0.08)';
      });
      navNextBtn.addEventListener('mouseleave', function () { this.style.background = 'transparent'; });
    }

    // --- Q1: Age + Sex (editorial entry) ---
    function renderQ1() {
      contentEl.innerHTML = ''
        + '<div class="hdlw-q-opener">'
        +   '<p class="hdlw-q-section">Section 01 · Calibration</p>'
        +   '<h2 class="hdlw-q-title">Age &amp; Sex</h2>'
        +   '<div class="hdlw-q-rule"></div>'
        +   '<p class="hdlw-q-prompt">These are used to calibrate your results to population norms.</p>'
        + '</div>'
        + '<div class="hdlw-fields">'
        +   '<div class="hdlw-field">'
        +     '<label class="hdlw-field-label" for="' + id + '-q1age">Age in years</label>'
        +     '<input id="' + id + '-q1age" class="hdlw-field-input" type="number" min="1" max="120" inputmode="numeric" value="' + (answers.q1_age || 50) + '" placeholder="Your age in years">'
        +   '</div>'
        +   '<div class="hdlw-field">'
        +     '<label class="hdlw-field-label">Sex</label>'
        +     '<div class="hdlw-sex-row">'
        +       '<div id="' + id + '-sex-male" class="hdlw-sex-opt' + (answers.q1_sex === 'male' ? ' hdlw-selected' : '') + '">Male</div>'
        +       '<div id="' + id + '-sex-female" class="hdlw-sex-opt' + (answers.q1_sex === 'female' ? ' hdlw-selected' : '') + '">Female</div>'
        +     '</div>'
        +   '</div>'
        +   '<button id="' + id + '-q1next" type="button" class="hdlw-cta">Continue →</button>'
        + '</div>';

      document.getElementById(id + '-sex-male').addEventListener('click', function () {
        answers.q1_sex = 'male';
        this.classList.add('hdlw-selected');
        document.getElementById(id + '-sex-female').classList.remove('hdlw-selected');
      });
      document.getElementById(id + '-sex-female').addEventListener('click', function () {
        answers.q1_sex = 'female';
        this.classList.add('hdlw-selected');
        document.getElementById(id + '-sex-male').classList.remove('hdlw-selected');
      });
      document.getElementById(id + '-q1next').addEventListener('click', function () {
        var ageInput = document.getElementById(id + '-q1age');
        var age = parseInt(ageInput.value, 10);
        if (!age || age < 1 || !answers.q1_sex) {
          if (!age) ageInput.style.borderColor = '#dc2626';
          return;
        }
        answers.q1_age = age;
        goTo(1);
      });
    }

    // --- Q2: Body shape (silhouettes + fat distribution) ---
    // Reads answers.q1_sex set on Q1 and switches imagery: male-1.svg ...
    // male-5.svg / female-1.svg ... female-5.svg from /assets/images/
    // silhouettes/ \u2014 fully wired and unchanged from prior versions.
    function renderQ2() {
      var sex = answers.q1_sex || 'male';
      contentEl.innerHTML = ''
        + '<div class="hdlw-q-opener">'
        +   '<p class="hdlw-q-section">Section 02 \u00b7 Body Composition</p>'
        +   '<h2 class="hdlw-q-title">Body Shape</h2>'
        +   '<div class="hdlw-q-rule"></div>'
        +   '<p class="hdlw-q-prompt">Which image most closely matches your current body shape?</p>'
        +   '<p class="hdlw-q-cite">Stunkard Figure Rating Scale \u2014 r=0.91 correlation with measured BMI.</p>'
        + '</div>'
        + '<div id="' + id + '-sil" class="hdlw-sil-strip">'
        +   silOpt(id, sex, 1, 'Very lean')
        +   silOpt(id, sex, 2, 'Healthy')
        +   silOpt(id, sex, 3, 'Overweight')
        +   silOpt(id, sex, 4, 'Obese I')
        +   silOpt(id, sex, 5, 'Obese II+')
        + '</div>'
        + '<div id="' + id + '-q2b-section" class="hdlw-q2b" style="display:' + (answers.q2a ? 'block' : 'none') + ';">'
        +   '<p class="hdlw-q2b-title">Where do you tend to carry extra weight?</p>'
        +   '<p class="hdlw-q2b-hint">If you\u2019re not sure, select \u201cEvenly spread.\u201d</p>'
        +   '<div id="' + id + '-fat" class="hdlw-fat-row">'
        +     fatOpt(id, 'apple', 'Mostly middle', sex)
        +     fatOpt(id, 'pear', 'Hips & thighs', sex)
        +     fatOpt(id, 'even', 'Evenly spread', sex)
        +   '</div>'
        + '</div>';

      // Bind silhouette clicks
      for (var i = 1; i <= 5; i++) {
        (function (n) {
          document.getElementById(id + '-sil-' + n).addEventListener('click', function () {
            answers.q2a = n;
            var opts = document.querySelectorAll('#' + id + '-sil .hdlw-sil');
            for (var j = 0; j < opts.length; j++) opts[j].classList.remove('hdlw-selected');
            this.classList.add('hdlw-selected');
            document.getElementById(id + '-q2b-section').style.display = 'block';
          });
        })(i);
      }

      // Bind fat distribution clicks
      ['apple', 'pear', 'even'].forEach(function (v) {
        document.getElementById(id + '-fat-' + v).addEventListener('click', function () {
          answers.q2b = v;
          var opts = document.querySelectorAll('#' + id + '-fat .hdlw-fat');
          for (var j = 0; j < opts.length; j++) opts[j].classList.remove('hdlw-selected');
          this.classList.add('hdlw-selected');
          advance();
        });
      });

      // Restore selections if going back
      if (answers.q2a) {
        var sel = document.getElementById(id + '-sil-' + answers.q2a);
        if (sel) sel.classList.add('hdlw-selected');
      }
      if (answers.q2b) {
        var fsel = document.getElementById(id + '-fat-' + answers.q2b);
        if (fsel) fsel.classList.add('hdlw-selected');
      }
    }

    // --- Q3-Q9: MCQ renderer (editorial) ---
    // v0.36.0 (Phase P) — illustration icons (q3-zone2.png, q4-stairs.png,
    // q5-sit-stand.png) retired in favour of italic citation microcopy.
    // Letter prefixes A-E replaced with mono numerals 01-05 matching the
    // PDF's "Your Answers" grid.
    function renderMCQ(qKey, stepIdx) {
      var q = QUESTIONS[qKey];
      // Step ordinal for the SECTION 0X · TOPIC eyebrow. stepIdx is the
      // 0-indexed step (Q3 = step 2, Q9 = step 8); the eyebrow shows the
      // 1-indexed question number to match the editorial 01-09 rhythm.
      var stepNum = String(stepIdx + 1);
      if (stepNum.length < 2) stepNum = '0' + stepNum;
      var section = 'Section ' + stepNum + ' · ' + (q.category || q.title);

      var html = ''
        + '<div class="hdlw-q-opener">'
        +   '<p class="hdlw-q-section">' + escHtml(section) + '</p>'
        +   '<h2 class="hdlw-q-title">' + escHtml(q.title) + '</h2>'
        +   '<div class="hdlw-q-rule"></div>'
        +   '<p class="hdlw-q-prompt">' + escHtml(q.text) + '</p>'
        +   ( q.hint ? '<p class="hdlw-q-hint">' + escHtml(q.hint) + '</p>' : '' )
        +   ( q.cite ? '<p class="hdlw-q-cite">' + escHtml(q.cite) + '</p>' : '' )
        + '</div>'
        + '<div id="' + id + '-opts" class="hdlw-opts">';
      for (var i = 0; i < q.opts.length; i++) {
        var o = q.opts[i];
        var sel = answers[qKey] === o.v ? ' hdlw-selected' : '';
        var num = (i + 1) < 10 ? '0' + (i + 1) : String(i + 1);
        html += '<div class="hdlw-opt' + sel + '" data-val="' + escHtml(o.v) + '">'
          +   '<span class="hdlw-opt-num">' + num + '</span>'
          +   '<span class="hdlw-opt-text">' + escHtml(o.t) + '</span>'
          + '</div>';
      }
      html += '</div>';

      contentEl.innerHTML = html;

      var opts = contentEl.querySelectorAll('.hdlw-opt');
      for (var j = 0; j < opts.length; j++) {
        opts[j].addEventListener('click', function () {
          answers[qKey] = this.getAttribute('data-val');
          for (var k = 0; k < opts.length; k++) opts[k].classList.remove('hdlw-selected');
          this.classList.add('hdlw-selected');
          advance();
        });
      }
    }

    function renderQ3() { renderMCQ('q3', 2); }
    function renderQ4() { renderMCQ('q4', 3); }
    function renderQ5() { renderMCQ('q5', 4); }
    function renderQ6() { renderMCQ('q6', 5); }
    function renderQ7() { renderMCQ('q7', 6); }
    function renderQ8() { renderMCQ('q8', 7); }
    function renderQ9() { renderMCQ('q9', 8); }

    // --- Contact (lead capture) ---
    function renderContact() {
      var isInv = !!inviteToken;
      var pracForCopy = escHtml(cfg.pracName || 'your practitioner');
      var fieldsHtml = '';

      if (isInv) {
        fieldsHtml += lockedField('Name · from your invite', inviteName)
          + lockedField('Email · from your invite', inviteEmail);
      } else {
        fieldsHtml += inputField(id, 'name', 'Name or nickname', 'text', true, 'This is how your practitioner will address you. Use a nickname if you prefer more privacy.')
          + inputField(id, 'email', 'Email address', 'email', true, 'We\u2019ll send your results here.')
          + inputField(id, 'phone', 'Phone number', 'tel', false, 'Optional \u2014 shared with your practitioner only, in case they\u2019d like to contact you directly.');
      }

      contentEl.innerHTML = ''
        + '<div class="hdlw-q-opener">'
        +   '<p class="hdlw-q-section">Section 10 · Delivery</p>'
        +   '<h2 class="hdlw-q-title">Where shall<br>we send it?</h2>'
        +   '<div class="hdlw-q-rule"></div>'
        + '</div>'
        + '<div class="hdlw-callout">'
        +   '<p class="hdlw-callout-label">Heads up</p>'
        +   '<p class="hdlw-callout-body">Your details are shared <b>only</b> with ' + pracForCopy + '. We use them to send your gauge and for your practitioner to follow up. No marketing list, no third parties.</p>'
        + '</div>'
        + '<div class="hdlw-fields">'
        +   fieldsHtml
        // Honeypot — visually hidden + ARIA hidden so screen readers skip
        // it too. Real humans never interact with it; bots fill every
        // input on the page. Server silently accepts+discards when non-
        // empty. Kept identical to prior version.
        +   '<div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">'
        +     '<label for="' + id + '-website">Website (leave empty)</label>'
        +     '<input type="text" id="' + id + '-website" name="website" tabindex="-1" autocomplete="off" />'
        +   '</div>'
        +   '<button id="' + id + '-submit" type="button" class="hdlw-cta">See my results →</button>'
        +   '<p class="hdlw-privacy">Your details are shared only with <b>' + pracForCopy + '</b>.</p>'
        + '</div>';

      document.getElementById(id + '-submit').addEventListener('click', function () {
        var name = isInv ? inviteName : valOf(id + '-name');
        var email = isInv ? inviteEmail : valOf(id + '-email');
        var phone = isInv ? '' : valOf(id + '-phone');
        if (!name || !email) {
          if (!isInv) {
            if (!name) markErr(id + '-name');
            if (!email) markErr(id + '-email');
          }
          return;
        }
        // v0.29.1 — disable submit immediately to prevent double-fire (the
        // server has its own 60s dedupe transient as the safety-net). Re-
        // enabling on the result page is unnecessary; user is past contact.
        var btnEl = document.getElementById(id + '-submit');
        if (btnEl) {
          btnEl.disabled = true;
          btnEl.textContent = 'Sending…';
        }
        answers._name = name;
        answers._email = email;
        answers._phone = phone;
        // v0.43.0 - front-door safety screen before the result (flag-gated).
        // Off = identical to before. On = collect the 2 safety answers + fire
        // any crisis/hard interrupt, then goTo(10).
        if (cfg.safetyScreen && !answers._safetyDone) {
          renderSafetyScreen();
        } else {
          goTo(10);
        }
      });
    }

    // --- v0.43.0 Front-door safety screen ---
    //
    // Shown after Contact, before the result, ONLY when cfg.safetyScreen is
    // true (the global dark flag). A short FIXED symptom + mental-health
    // check. Ticks are mapped to medical flags server-side
    // (HDLV2_Safety_Screen). A crisis tick (self-harm) or a hard symptom
    // fires an on-screen interrupt before the result is shown.
    var SFX_SYMPTOMS = [
      { k: 'chest_pain',        t: 'Chest pain or pressure, especially on effort' },
      { k: 'breathless',        t: 'Unusual breathlessness — at rest, lying flat, or with light effort' },
      { k: 'fainting',          t: 'Fainting, blackouts, or near-blackouts' },
      { k: 'stroke_weakness',   t: 'Sudden weakness or numbness, especially on one side' },
      { k: 'stroke_speech',     t: 'Sudden trouble speaking or finding words' },
      { k: 'stroke_vision',     t: 'Sudden loss of vision, or double vision' },
      { k: 'worst_headache',    t: 'A sudden, severe headache unlike any before' },
      { k: 'weight_loss',       t: 'Losing weight without trying' },
      { k: 'new_lump',          t: 'A new lump anywhere' },
      { k: 'bleeding',          t: 'Blood in your stool, urine, or when you cough' },
      { k: 'swallowing',        t: 'Difficulty or pain swallowing' },
      { k: 'persistent_cough',  t: 'A cough lasting more than three weeks' },
      { k: 'changing_mole',     t: 'A mole or skin patch that is changing' },
      { k: 'abnormal_bleeding', t: 'Bleeding after the menopause, or unusually heavy or irregular bleeding' }
    ];
    var SFX_MH = [
      { k: 'low_mood',        t: 'Persistent low mood for weeks at a time' },
      { k: 'anxiety',         t: 'Severe anxiety affecting sleep, work, or relationships' },
      { k: 'self_harm',       t: 'Thoughts of harming myself' },
      { k: 'life_not_worth',  t: 'Thoughts that life might not be worth living' }
    ];
    var sfxSel = { symptoms: {}, mh: {} };

    function sfxRow(group, item) {
      var sel = sfxSel[group][item.k] ? ' hdlw-selected' : '';
      return '<div class="hdlw-opt hdlw-sfx-opt' + sel + '" data-sfx-group="' + group + '" data-sfx-key="' + item.k + '">'
        +   '<span class="hdlw-sfx-box" aria-hidden="true"></span>'
        +   '<span class="hdlw-opt-text">' + item.t + '</span>'
        + '</div>';
    }

    function sfxNoneRow(group, label) {
      var sel = sfxSel[group].__none ? ' hdlw-selected' : '';
      return '<div class="hdlw-opt hdlw-sfx-opt hdlw-sfx-none' + sel + '" data-sfx-group="' + group + '" data-sfx-key="__none">'
        +   '<span class="hdlw-sfx-box" aria-hidden="true"></span>'
        +   '<span class="hdlw-opt-text">' + label + '</span>'
        + '</div>';
    }

    function sfxBindToggles() {
      var opts = contentEl.querySelectorAll('.hdlw-sfx-opt');
      for (var i = 0; i < opts.length; i++) {
        opts[i].addEventListener('click', function () {
          var g = this.getAttribute('data-sfx-group');
          var k = this.getAttribute('data-sfx-key');
          if (k === '__none') {
            var was = !!sfxSel[g].__none;
            sfxSel[g] = {};
            if (!was) sfxSel[g].__none = true;
          } else {
            if (sfxSel[g].__none) delete sfxSel[g].__none;
            if (sfxSel[g][k]) delete sfxSel[g][k]; else sfxSel[g][k] = true;
          }
          var rows = contentEl.querySelectorAll('.hdlw-sfx-opt[data-sfx-group="' + g + '"]');
          for (var j = 0; j < rows.length; j++) {
            var rk = rows[j].getAttribute('data-sfx-key');
            if (sfxSel[g][rk]) rows[j].classList.add('hdlw-selected');
            else rows[j].classList.remove('hdlw-selected');
          }
        });
      }
    }

    function renderSafetyScreen() {
      var np = document.getElementById(id + '-nav-prev'); if (np) np.style.display = 'none';
      var nx = document.getElementById(id + '-nav-next'); if (nx) nx.style.display = 'none';
      if (stepEl) stepEl.innerHTML = '<b>Safety</b>';
      // v0.43.3 — widen for this two-section step. The host mount (e.g. altituding’s
      // #hdl-widget-NN) caps the widget at 480px; break out of that cap like the
      // result page does (el.maxWidth:none), then cap the shell at 880px so it
      // centres instead of spanning the full container.
      var sfxShell = contentEl.closest('.hdlw-shell');
      if (sfxShell) {
        if (sfxShell.parentElement) sfxShell.parentElement.style.maxWidth = 'none';
        sfxShell.style.maxWidth = '880px';
      }

      var symHtml = '';
      for (var a = 0; a < SFX_SYMPTOMS.length; a++) symHtml += sfxRow('symptoms', SFX_SYMPTOMS[a]);
      var mhHtml = '';
      for (var b = 0; b < SFX_MH.length; b++) mhHtml += sfxRow('mh', SFX_MH[b]);

      contentEl.innerHTML = ''
        + '<div class="hdlw-sfx-sec">'
        +   '<div class="hdlw-q-opener">'
        +     '<p class="hdlw-q-section">One last check · before your result</p>'
        +     '<h2 class="hdlw-q-title">A quick safety check</h2>'
        +     '<div class="hdlw-q-rule"></div>'
        +     '<p class="hdlw-q-prompt">This isn’t a medical assessment — but part of our job is to notice anything that deserves a doctor’s eye before we build your plan. Tick anything you’ve noticed in the last 6–12 months, even once or twice.</p>'
        +   '</div>'
        +   '<div class="hdlw-sfx-grid">' + symHtml + sfxNoneRow('symptoms', 'None of these') + '</div>'
        + '</div>'
        + '<div class="hdlw-sfx-sec">'
        +   '<div class="hdlw-q-opener">'
        +     '<p class="hdlw-q-section">Optional &amp; confidential</p>'
        +     '<h2 class="hdlw-q-title">How you’ve been feeling</h2>'
        +     '<div class="hdlw-q-rule"></div>'
        +     '<p class="hdlw-q-prompt">And how have you been feeling in yourself lately? Skip anything you’d rather not answer.</p>'
        +   '</div>'
        +   '<div class="hdlw-sfx-grid">' + mhHtml + sfxNoneRow('mh', 'None of these · I’d rather not say') + '</div>'
        + '</div>'
        + '<div class="hdlw-fields" style="padding-top:0;">'
        +   '<button id="' + id + '-sfx-continue" type="button" class="hdlw-cta">See my results →</button>'
        +   '<p class="hdlw-privacy">If something feels like an emergency, call <b>999</b>.</p>'
        + '</div>';

      sfxBindToggles();
      var cont = document.getElementById(id + '-sfx-continue');
      if (cont) cont.addEventListener('click', sfxContinue);
    }

    function sfxCollect() {
      var picked = {};
      var sym = [];
      for (var k in sfxSel.symptoms) { if (k !== '__none' && sfxSel.symptoms[k]) sym.push(k); }
      var mh = [];
      for (var m in sfxSel.mh) { if (m !== '__none' && sfxSel.mh[m]) mh.push(m); }
      if (sym.length) picked.symptoms = sym;
      if (mh.length) picked.mh = mh;
      return picked;
    }

    function sfxContinue() {
      // v0.43.3 — restore widths before leaving (crisis/hard render narrow; the
      // result re-widens itself). Reverts the shell + the host-mount breakout.
      var sfxShell = contentEl.closest('.hdlw-shell');
      if (sfxShell) {
        sfxShell.style.maxWidth = '';
        if (sfxShell.parentElement) sfxShell.parentElement.style.maxWidth = '';
      }
      var picked = sfxCollect();
      answers._safety = (picked.symptoms || picked.mh) ? picked : null;
      answers._safetyDone = true;
      var mh = picked.mh || [];
      var crisis = mh.indexOf('self_harm') > -1 || mh.indexOf('life_not_worth') > -1;
      var hasHard = !!(picked.symptoms && picked.symptoms.length);
      if (crisis) { renderCrisisScreen(hasHard); return; }
      if (hasHard) { renderHardScreen(); return; }
      goTo(10);
    }

    function renderCrisisScreen(hasHard) {
      contentEl.innerHTML = ''
        + '<div class="hdlw-q-opener">'
        +   '<p class="hdlw-q-section">You don’t have to face this alone</p>'
        +   '<h2 class="hdlw-q-title">Please reach out — now</h2>'
        +   '<div class="hdlw-q-rule"></div>'
        +   '<p class="hdlw-q-prompt">Thank you for being honest. What you shared matters. These lines are free, confidential, and open right now.</p>'
        + '</div>'
        + '<div class="hdlw-callout"><p class="hdlw-callout-body">'
        +   'If you feel unsafe right now: call <b>999</b> or go to A&amp;E.<br>'
        +   '<b>Samaritans</b> — call <b>116 123</b>, any time.<br>'
        +   '<b>NHS 111</b> — then choose the mental-health option.<br>'
        +   '<b>Shout</b> — text <b>85258</b>.'
        + '</p></div>'
        + '<div class="hdlw-fields" style="padding-top:0;">'
        +   '<button id="' + id + '-sfx-go" type="button" class="hdlw-cta">' + (hasHard ? 'Continue' : 'See my results →') + '</button>'
        + '</div>';
      var go = document.getElementById(id + '-sfx-go');
      if (go) go.addEventListener('click', function () { if (hasHard) renderHardScreen(); else goTo(10); });
    }

    function renderHardScreen() {
      contentEl.innerHTML = ''
        + '<div class="hdlw-q-opener">'
        +   '<p class="hdlw-q-section">Worth a doctor’s eye</p>'
        +   '<h2 class="hdlw-q-title">One thing before your result</h2>'
        +   '<div class="hdlw-q-rule"></div>'
        +   '<p class="hdlw-q-prompt">You mentioned something it’s worth having a doctor look at before we build your plan. This isn’t a diagnosis — but please don’t sit on it.</p>'
        + '</div>'
        + '<div class="hdlw-callout"><p class="hdlw-callout-body">'
        +   'Please contact <b>NHS 111</b> or your <b>GP</b> soon. If anything is severe or came on suddenly, call <b>999</b>.'
        + '</p></div>'
        + '<div class="hdlw-fields" style="padding-top:0;">'
        +   '<button id="' + id + '-sfx-go2" type="button" class="hdlw-cta">I’ve seen this — show my result →</button>'
        + '</div>';
      var go2 = document.getElementById(id + '-sfx-go2');
      if (go2) go2.addEventListener('click', function () { goTo(10); });
    }

    // --- Results ---
    //
    // Phase 17 (v0.22.26) \u2014 full mockup parity. Replaces the previous narrow
    // gauge-only view with the same hero / 3-stat / gauge / Awaken / What
    // Happens Next / practitioner-footer layout shown on /assessment/.
    // Renders into the widget's contentEl plus widens the outer shell (the
    // funnel header + progress bar) by hiding them, since the result is
    // semantically a different page from the funnel.
    //
    // Two CTAs below the What Happens Next list:
    //   1. Continue to Stage 2 \u2014 populated async after the lead-capture
    //      POST returns a form_token (invite-fast-path) or replaced with
    //      the pending-verification card.
    //   2. Book a session \u2014 uses cfg.ctaLink. Always visible if a link is
    //      configured for this practitioner; hidden otherwise so we never
    //      ship a dead button.
    //
    // Awaken commentary is a deterministic 3-paragraph builder \u2014 no AI
    // call. Practitioner sites embed the widget in a sandbox so we keep
    // every style inline.
    function renderResult() {
      // Local HTML-escape helper. Defence in depth — the only user-supplied
      // strings here are answers._name and cfg.pracName, but both flow into
      // an HTML string concatenation, so we sanitise before insertion.
      function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML.replace(/"/g, '&quot;');
      }

      var r = compute(answers);
      var rate = r.rate;
      var rateDisplay = (Math.round(rate * 100) / 100).toFixed(2);
      var age = parseInt(answers.q1_age, 10) || 0;
      var bio = age > 0 ? Math.round(rate * age * 10) / 10 : null;
      var diff = (bio !== null && age > 0) ? Math.round((bio - age) * 10) / 10 : null;

      var firstName = (answers._name || '').split(/\s+/)[0] || 'there';
      var greeting = firstName === 'there' ? 'Hi there \u2014' : 'Hi ' + firstName + ' \u2014';

      // Rate band drives the headline copy + the Awaken third paragraph.
      var bandColor, bandLabel, bandClass, awakenP1, awakenP3;
      var pct = Math.abs(Math.round((rate - 1) * 100));
      if (rate <= 0.95) {
        bandColor = '#166534'; // good (green)
        bandClass = 'good';
        bandLabel = 'Slower \u2014 in your favour';
        awakenP1  = firstName + ', your rate of ageing of ' + rateDisplay + '\u00d7 tells us you\u2019re currently ageing about ' + pct + '% slower than the calendar suggests. That puts your biological age at ' + (bio !== null ? bio + ' years' : 'in line with your chronological age') + (age ? ', against your chronological age of ' + age + '.' : '.');
        awakenP3  = 'A ' + rateDisplay + '\u00d7 rate means your current habits are working in your favour. Stage 2 captures why this matters to you, and Stage 3 gives your practitioner the granularity to fine-tune it from here.';
      } else if (rate <= 1.05) {
        bandColor = '#3d8da0'; // average (teal)
        bandClass = 'avg';
        bandLabel = 'On average';
        awakenP1  = firstName + ', your rate of ageing of ' + rateDisplay + '\u00d7 puts you roughly at the population average. Your biological age is ' + (bio !== null ? bio + ' years' : 'close to your chronological age') + (age ? ', against your chronological ' + age + '.' : '.');
        awakenP3  = 'A ' + rateDisplay + '\u00d7 rate is right around average \u2014 small, focused changes could shift this meaningfully. Stage 2 captures why a healthier ageing arc matters to you, and Stage 3 gives your practitioner the data to design what\u2019s next.';
      } else {
        bandColor = '#c2410c'; // warn (amber-red)
        bandClass = 'warn';
        bandLabel = 'Faster \u2014 let\u2019s work on this';
        awakenP1  = firstName + ', your rate of ageing of ' + rateDisplay + '\u00d7 tells us you\u2019re currently ageing about ' + pct + '% faster than the calendar suggests. That puts your biological age at ' + (bio !== null ? bio + ' years' : 'ahead of your chronological age') + (age && bio !== null ? ' even though your real age is ' + age + '.' : '.');
        awakenP3  = 'The good news: a ' + rateDisplay + '\u00d7 rate is well inside the range that responds quickly to focused changes in strength training, sleep consistency and daily movement. Your practitioner will use this gauge as the baseline for everything that follows.';
      }
      var awakenP2 = 'This isn\u2019t a verdict \u2014 it\u2019s a starting picture. The 9 questions captured your activity, sleep, social, smoking and diet patterns. The result reflects today, not tomorrow. Most of these levers are within your control.';

      var diffLabel = '';
      if (diff !== null) {
        if (diff > 0)      diffLabel = '+' + diff + ' yrs vs chronological';
        else if (diff < 0) diffLabel = diff + ' yrs vs chronological';
        else               diffLabel = 'in line with chronological';
      }

      // Practitioner footer initials \u2014 fall back to "HDL" if we don't have
      // a practitioner name (which would be unusual since the widget is
      // always tied to a practitioner_id).
      var pracInitials = cfg.pracName
        ? cfg.pracName.split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('')
        : 'HDL';

      // Widen the outer shell for the result page only and hide the funnel
      // chrome (header strip, progress bar, footer practitioner block) — the
      // result page renders its own .hdlw-r-prac-foot at the bottom.
      //
      // v0.22.28 / v0.36.0 — the funnel shell carries its own .hdlw-shell
      // (480px max-width) + .hdlw-card (border, cream paper). The result
      // band needs more room (1080px) and a transparent canvas so each
      // result card renders its own border/shadow without double frames.
      el.style.maxWidth = 'none';
      var innerShell = el.firstElementChild; // .hdlw-shell
      if (innerShell) {
        innerShell.style.maxWidth = '1080px';
      }
      var funnelCard = el.querySelector('.hdlw-card');
      if (funnelCard) {
        funnelCard.style.border = '0';
        funnelCard.style.boxShadow = 'none';
        funnelCard.style.background = 'transparent';
        funnelCard.style.borderRadius = '0';
        funnelCard.style.overflow = 'visible';
        funnelCard.style.backgroundImage = 'none';
      }
      var funnelHeader = el.querySelector('.hdlw-header');
      if (funnelHeader) funnelHeader.style.display = 'none';
      var funnelProgress = el.querySelector('.hdlw-progress');
      if (funnelProgress) funnelProgress.style.display = 'none';
      var funnelFooter = el.querySelector('.hdlw-footer');
      if (funnelFooter) funnelFooter.style.display = 'none';

      var hasCtaLink = !!(cfg.ctaLink && cfg.ctaLink !== '#');

      // v0.35.0 (Phase O) — Book-a-Session visibility rules:
      //   - Invite path: show whenever cta_link is configured (unchanged from
      //     v0.34.x; the practitioner already approved this client).
      //   - Public path: show ONLY when the practitioner has opted in via
      //     widget_config.show_book_button_after_widget. Default OFF per
      //     Matthew's "no button on public-path result page" rule from the
      //     2026-05-? smoke-test transcript ("a button means that they
      //     probably think something should happen … having like a dead end
      //     will feel difficult"). Practitioners who want a single Calendly
      //     CTA on their embed can flip the toggle in Widget Settings.
      var showBookOnPublic = !!cfg.showBookOnPublic;
      var showBook = hasCtaLink && (!!inviteToken || showBookOnPublic);
      var bookSessionBtn = showBook
        ? '<a class="hdlw-r-btn-secondary" href="' + cfg.ctaLink + '" target="_blank" rel="noopener">' + escapeHtml(cfg.ctaText) + '</a>'
        : '';

      // v0.22.29 \u2014 match mockup proportions exactly (mockups/stage1-result.html).
      // Inject one <style> block scoped under .hdlw-r so the result page can
      // use real classes + a media query (inline styles can't carry @media).
      // Mobile collapse at 640px \u2192 stat-strip becomes single column. Above
      // that, full mockup layout: 3-col stat strip, larger Source-Serif
      // headings, generous card padding.
      var SERIF = "'Source Serif Pro', Georgia, serif";
      // Make sure the practitioner site has Source Serif Pro available \u2014 the
      // widget can't assume the host page loads it. Fetched once per render.
      if (!document.getElementById('hdlw-fonts')) {
        var fontsLink = document.createElement('link');
        fontsLink.id = 'hdlw-fonts';
        fontsLink.rel = 'stylesheet';
        fontsLink.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+Pro:wght@400;600&display=swap';
        document.head.appendChild(fontsLink);
      }

      contentEl.innerHTML = ''
        + '<style>'
        + '.hdlw-r{font-family:' + B.body + ';color:#111;line-height:1.6;}'
        + '.hdlw-r-hero{position:relative;background:linear-gradient(135deg,#3d8da0 0%,#5bb0c4 100%);color:#fff;border-radius:14px;padding:32px 36px;margin:0 0 20px;overflow:hidden;}'
        + '.hdlw-r-hero::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 80% 20%,rgba(255,255,255,0.12),transparent 60%);pointer-events:none;}'
        + '.hdlw-r-hero-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;position:relative;}'
        + '.hdlw-r-hero-greeting{font-size:14px;opacity:0.85;margin:0 0 4px;font-weight:500;}'
        + '.hdlw-r-hero-title{font-family:' + SERIF + ';font-size:32px;font-weight:600;margin:0;letter-spacing:-0.02em;line-height:1.15;color:#fff;}'
        + '.hdlw-r-hero-pill{background:#fff;color:#3d8da0;font-size:12px;font-weight:600;padding:6px 14px;border-radius:999px;letter-spacing:0.02em;flex-shrink:0;white-space:nowrap;}'
        + '.hdlw-r-hero-body{font-size:15px;opacity:0.92;margin:14px 0 0;max-width:560px;line-height:1.6;position:relative;}'
        + '.hdlw-r-stat-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:0 0 20px;}'
        + '.hdlw-r-stat{background:#fff;border:1px solid #e4e6ea;border-radius:12px;padding:18px 20px;text-align:center;}'
        + '.hdlw-r-stat-label{font-size:12px;color:#555;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;margin:0 0 8px;}'
        + '.hdlw-r-stat-value{font-family:' + SERIF + ';font-size:30px;font-weight:600;color:#111;margin:0;line-height:1.1;}'
        + '.hdlw-r-stat-note{font-size:12px;margin:6px 0 0;font-weight:500;}'
        + '.hdlw-r-stat-note.warn{color:#c2410c;}.hdlw-r-stat-note.good{color:#166534;}.hdlw-r-stat-note.avg{color:#3d8da0;}.hdlw-r-stat-note.muted{color:#888;}'
        + '.hdlw-r-card{background:#fff;border:1px solid #e4e6ea;border-radius:14px;padding:28px 32px;margin:0 0 16px;}'
        + '.hdlw-r-card h2{font-family:' + SERIF + ';font-size:24px;font-weight:600;color:#111;margin:0 0 8px;letter-spacing:-0.01em;line-height:1.2;}'
        + '.hdlw-r-card .hdlw-r-lede{font-size:13px;color:#888;margin:0 0 18px;}'
        + '.hdlw-r-card p{margin:0 0 14px;font-size:15px;color:#555;line-height:1.75;}'
        + '.hdlw-r-card p:last-child{margin-bottom:0;}'
        + '.hdlw-r-card p strong{color:#111;font-weight:600;}'
        + '.hdlw-r-eyebrow{display:inline-block;font-size:11px;color:#3d8da0;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 10px;}'
        + '.hdlw-r-gauge-card{text-align:center;}'
        + '.hdlw-r-gauge-img{display:block;margin:8px auto 0;max-width:640px;width:100%;height:auto;}'
        + '.hdlw-r-next-list{counter-reset:step;list-style:none;padding:0;margin:0 0 22px;}'
        + '.hdlw-r-next-list li{counter-increment:step;position:relative;padding:14px 14px 14px 56px;font-size:14px;color:#555;line-height:1.5;background:#fafbfc;border:1px solid #e4e6ea;border-radius:10px;margin:0 0 8px;}'
        + '.hdlw-r-next-list li::before{content:counter(step);position:absolute;left:14px;top:50%;transform:translateY(-50%);width:28px;height:28px;border-radius:50%;background:#3d8da0;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;}'
        + '.hdlw-r-next-list li strong{color:#111;font-weight:600;}'
        + '.hdlw-r-buttons{display:flex;flex-direction:column;align-items:center;gap:8px;}'
        + '.hdlw-r-buttons-continue{width:100%;display:flex;flex-direction:column;align-items:center;gap:8px;}'
        // v0.35.0 (Phase O) — public-path thank-you wall styling. Single
        // paragraph, generous breathing room, no CTA. Matches the "Awaken"
        // card font-stack so the page reads as one coherent visual.
        + '.hdlw-r-thanks-headline{font-family:' + SERIF + ';font-size:24px;font-weight:600;color:#111;margin:0 0 12px;letter-spacing:-0.01em;line-height:1.2;}'
        + '.hdlw-r-thanks-body{font-size:15px;color:#555;line-height:1.75;margin:0;}'
        + '.hdlw-r-btn-primary{display:inline-block;width:100%;max-width:320px;box-sizing:border-box;padding:12px 28px;background:#004F59;color:#fff;border:1px solid #004F59;border-radius:999px;text-decoration:none;font-size:14px;font-weight:600;text-align:center;transition:background .15s ease;}'
        + '.hdlw-r-btn-primary:hover{background:#003a42;border-color:#003a42;}'
        + '.hdlw-r-btn-secondary{display:inline-block;width:100%;max-width:320px;box-sizing:border-box;padding:12px 28px;background:#fff;color:#3d8da0;border:1px solid #3d8da0;border-radius:999px;text-decoration:none;font-size:14px;font-weight:600;text-align:center;transition:background .15s ease,color .15s ease;}'
        + '.hdlw-r-btn-secondary:hover{background:#3d8da0;color:#fff;}'
        + '.hdlw-r-email-note{font-size:12px;color:#888;margin:4px 0 0;text-align:center;}'
        // v0.36.14 — result-page footer drops the white card wrapper entirely.
        // Confirmation lines + practitioner identity now sit directly on the
        // page background (the warm-cream report bg). Editorial, not boxed.
        // Logo loses its white-square container too; wordmark renders naked.
        + '.hdlw-r-prac-foot{background:transparent;border:0;border-radius:0;padding:0;margin:24px 0 0;}'
        + '.hdlw-r-prac-foot-row{display:flex;flex-direction:column;align-items:flex-start;gap:6px;}'
        // Confirmation lines (✓ practitioner forwarded, ✉ email copy sent).
        // 17px Inter navy body, teal icon, bold dark-teal practitioner name.
        + '.hdlw-r-foot-line{display:flex;align-items:flex-start;gap:12px;margin:0 0 12px;font-family:' + B.body + ';font-size:17px;line-height:1.5;color:#2c3e50;}'
        + '.hdlw-r-foot-line:last-of-type{margin-bottom:0;}'
        + '.hdlw-r-foot-line b{color:#004F59;font-weight:600;font-style:normal;}'
        + '.hdlw-r-foot-icon{flex-shrink:0;width:22px;font-size:16px;line-height:1.5;color:#3d8da0;font-weight:700;text-align:center;}'
        + '.hdlw-r-foot-divider{border:0;border-top:1px solid #d6cdb7;margin:20px 0 18px;}'
        // Practitioner identity block — wordmark naked on top, overline,
        // name beneath. No wrapper, no chip, no border. Mirrors the form
        // footer pattern shipped in 0.36.12 so the widget reads as one
        // consistent practitioner identity treatment end-to-end.
        + '.hdlw-r-prac-foot-overline{font-family:' + B.body + ';font-size:10px;color:#888;letter-spacing:0.16em;text-transform:uppercase;font-weight:600;margin:6px 0 0;line-height:1.2;}'
        + '.hdlw-r-prac-foot-avatar{flex-shrink:0;width:42px;height:42px;border-radius:50%;background:#3d8da0;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;}'
        + '.hdlw-r-prac-foot-name{margin:2px 0 0;font-size:18px;font-weight:600;color:#004F59;line-height:1.25;letter-spacing:-0.005em;}'
        + '.hdlw-r-prac-foot-role{font-size:12px;color:#888;margin:0;}'
        + '.hdlw-r-prac-foot-powered{font-size:12px;color:#888;}'
        + '.hdlw-r-prac-foot-powered strong{color:#111;font-weight:600;}'
        // v0.22.46 — 2-column page grid (LEFT: gauge + 3 stat cards stacked,
        // RIGHT: AWAKEN + What-Happens-Next + CTAs). Collapses to single
        // column at <=900px, keeping the data column first in source order.
        + '.hdlw-r-twocol{display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start;margin:0 0 16px;}'
        + '.hdlw-r-twocol-left,.hdlw-r-twocol-right{display:flex;flex-direction:column;gap:16px;min-width:0;}'
        + '.hdlw-r-twocol-left .hdlw-r-stat{margin:0;padding:20px 24px;}'
        + '.hdlw-r-twocol-left .hdlw-r-card{margin:0;}'
        + '.hdlw-r-twocol-right .hdlw-r-card{margin:0;}'
        + '@media (max-width:900px){'
        +   '.hdlw-r-twocol{grid-template-columns:1fr;gap:16px;}'
        +   '.hdlw-r-gauge-img{max-width:360px;}'
        + '}'
        + '@media (max-width:640px){'
        +   '.hdlw-r-stat-strip{grid-template-columns:1fr;}'
        +   '.hdlw-r-hero{padding:24px 22px;}'
        +   '.hdlw-r-hero-title{font-size:24px;}'
        +   '.hdlw-r-hero-row{flex-direction:column;align-items:flex-start;}'
        +   '.hdlw-r-card{padding:22px 22px;}'
        +   '.hdlw-r-prac-foot-row{flex-direction:column;align-items:flex-start;}'
        + '}'
        + '</style>'
        + '<div class="hdlw-r">'
        // Hero
        + '<section class="hdlw-r-hero">'
        +   '<div class="hdlw-r-hero-row">'
        +     '<div>'
        +       '<p class="hdlw-r-hero-greeting">' + greeting + '</p>'
        +       '<h1 class="hdlw-r-hero-title">Your Quick Insight</h1>'
        +     '</div>'
        +     '<span class="hdlw-r-hero-pill">Stage 1</span>'
        +   '</div>'
        +   '<p class="hdlw-r-hero-body">Here\u2019s your starting picture, built from your 9 quick answers. Next, your <em>WHY</em> \u2014 what really matters to you \u2014 turns this snapshot into a plan that\u2019s truly yours.</p>'
        + '</section>'

        // v0.22.46 \u2014 2-column page grid below hero.
        // LEFT col: gauge card (top) + 3 stat cards stacked.
        // RIGHT col: AWAKEN commentary + What-Happens-Next + CTAs.
        // Collapses to single column at <=900px, with the LEFT (data) column
        // first in source order so it shows first when stacked.
        + '<div class="hdlw-r-twocol">'

        // LEFT: gauge (top) + 3 stat cards stacked
        +   '<div class="hdlw-r-twocol-left">'
        +     '<div class="hdlw-r-card hdlw-r-gauge-card">'
        +       '<span class="hdlw-r-eyebrow">Pace of Ageing</span>'
        +       '<h2>Your Health Gauge</h2>'
        +       '<p class="hdlw-r-lede">A snapshot of how fast you\u2019re ageing today, based on your quick answers.</p>'
        +       '<img class="hdlw-r-gauge-img" src="' + gaugeUrl(rate) + '" alt="Rate of ageing gauge \u2014 ' + rateDisplay + '">'
        +     '</div>'
        +     '<div class="hdlw-r-stat">'
        +       '<p class="hdlw-r-stat-label">Rate of Ageing</p>'
        +       '<p class="hdlw-r-stat-value">' + rateDisplay + '\u00d7</p>'
        +       '<p class="hdlw-r-stat-note ' + bandClass + '">' + bandLabel + '</p>'
        +     '</div>'
        +     '<div class="hdlw-r-stat">'
        +       '<p class="hdlw-r-stat-label">Biological Age</p>'
        +       '<p class="hdlw-r-stat-value">' + (bio !== null ? bio + ' yrs' : '\u2014') + '</p>'
        +       '<p class="hdlw-r-stat-note ' + bandClass + '">' + escapeHtml(diffLabel) + '</p>'
        +     '</div>'
        +     '<div class="hdlw-r-stat">'
        +       '<p class="hdlw-r-stat-label">Chronological Age</p>'
        +       '<p class="hdlw-r-stat-value">' + (age > 0 ? age + ' yrs' : '\u2014') + '</p>'
        +       '<p class="hdlw-r-stat-note muted">Your real age</p>'
        +     '</div>'
        +   '</div>'

        // RIGHT: AWAKEN + What-Happens-Next + CTAs
        +   '<div class="hdlw-r-twocol-right">'
        +     '<div class="hdlw-r-card">'
        +       '<span class="hdlw-r-eyebrow">Awaken</span>'
        +       '<h2>What This Means For You</h2>'
        +       '<p class="hdlw-r-lede">A plain-English read of your gauge.</p>'
        +       '<p><strong>' + escapeHtml(awakenP1) + '</strong></p>'
        +       '<p>' + escapeHtml(awakenP2) + '</p>'
        +       '<p>' + escapeHtml(awakenP3) + '</p>'
        +     '</div>'
        // v0.35.0 (Phase O) \u2014 branch the "What Happens Next" card on the
        // submission path:
        //
        //   INVITE path (?invite=TOKEN): keep the existing 3-stage list +
        //   Continue button placeholder + Book button. Invite is by-
        //   definition a practitioner-approved client, so the multi-stage
        //   roadmap copy is honest \u2014 they CAN proceed once submit returns
        //   the form_token.
        //
        //   PUBLIC path: replace with Matthew's thank-you wall. Verbatim
        //   from the 2026-05-? smoke-test transcript: "your practitioner
        //   has been sent your data. Once they have looked at your data,
        //   you will receive another email." Zero buttons unless the
        //   practitioner explicitly opts in to a Book-a-Session via
        //   widget_config.show_book_button_after_widget. The dead-end is
        //   intentional: it forces the practitioner-confirm gate that
        //   keeps the dashboard spam-free.
        +     '<div class="hdlw-r-card">'
        +       ( inviteToken
            ? ''
            +   '<h2>What Happens Next</h2>'
            +   '<p class="hdlw-r-lede">Your gauge is a snapshot. The next two stages turn it into a plan.</p>'
            +   '<ol class="hdlw-r-next-list">'
            +     '<li><strong>Stage 2 \u2014 Your WHY.</strong> Tell us what motivates you. We capture your reasons in your own words. ~10 minutes.</li>'
            +     '<li><strong>Stage 3 \u2014 Full Health Detail.</strong> Replace today\u2019s visual estimates with real measurements (body, fitness, sleep, lifestyle).</li>'
            +     '<li><strong>Your Trajectory Plan arrives.</strong> Reviewed by your practitioner, with your weekly Flight Plan to follow.</li>'
            +   '</ol>'
            +   '<div class="hdlw-r-buttons">'
            +     '<div id="' + id + '-continue" class="hdlw-r-buttons-continue">'
            +       '<div style="font-size:12px;color:#888;font-style:italic;">Sending your results\u2026</div>'
            +     '</div>'
            +     bookSessionBtn
            +   '</div>'
            : ''
            +   '<h2 class="hdlw-r-thanks-headline">Thank you.</h2>'
            +   '<p class="hdlw-r-thanks-body">Your practitioner has been sent your data. Once they have looked at your data, you will receive another email.</p>'
            +   ( showBook
                ? '<div class="hdlw-r-buttons" style="margin-top:22px;">' + bookSessionBtn + '</div>'
                : '' )
        )
        +     '</div>'
        +   '</div>'

        + '</div>' // end .hdlw-r-twocol

        // v0.36.13 \u2014 single footer card. Confirm lines on top (17px, big and
        // theme-matched), hairline divider, logo+name row beneath. All inside
        // one .hdlw-r-prac-foot wrapper so the page stops feeling like a
        // stack of separate boxes at the bottom of the report.
        + '<div class="hdlw-r-prac-foot">'
        +   ( cfg.pracName
            ? '<div class="hdlw-r-foot-line">'
              +   '<span class="hdlw-r-foot-icon">\u2713</span>'
              +   '<span>Your assessment is now with <b>' + escapeHtml(cfg.pracName) + '</b> — they’ll be in touch.</span>'
              + '</div>'
            : '' )
        +   '<div class="hdlw-r-foot-line">'
        // v0.36.15 \u2014 inline SVG envelope (was \u2709, which is in Unicode
        // Dingbats and missing from many webfont stacks; rendered as tofu).
        +     '<span class="hdlw-r-foot-icon"><svg viewBox="0 0 22 16" width="18" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><rect x="1" y="1" width="20" height="14" rx="1.5"/><path d="M1.6 2.6l9.4 6.8 9.4-6.8"/></svg></span>'
        +     '<span>A copy of your Quick Insight is on its way to your inbox.</span>'
        +   '</div>'
        +   '<hr class="hdlw-r-foot-divider" />'
        // v0.36.14 — practitioner identity stacked vertically (logo on top,
        // "Your practitioner" overline, name beneath). The .hdlw-r-prac-foot-left
        // flex-row wrapper is retired; logo + text are now direct children of
        // .hdlw-r-prac-foot-row. Logo loses its white container via the
        // .hdlw-r-prac-foot .hdlw-logo override in injectEditorialStyles.
        +   '<div class="hdlw-r-prac-foot-row">'
        +     ( cfg.logo
              ? '<div class="hdlw-logo" data-shape="' + escapeHtml(cfg.logoShape) + '"><img src="' + escapeHtml(cfg.logo) + '" alt=""></div>'
              : '<div class="hdlw-r-prac-foot-avatar">' + escapeHtml(pracInitials) + '</div>' )
        +     '<div>'
        +       '<p class="hdlw-r-prac-foot-overline">Your practitioner</p>'
        +       '<p class="hdlw-r-prac-foot-name">' + escapeHtml(cfg.pracName || 'Your longevity practitioner') + '</p>'
        +     '</div>'
        +   '</div>'
        + '</div>'
        + '</div>'; // close .hdlw-r wrapper

      // Send lead data. Server may respond in three shapes:
      //   - { form_token, rate }  → invite-fast-path (immediate continue)
      //   - { pending: true, masked_email, rate } → verification flow
      //   - WP_Error with status 429 → cooldown / rate limit
      var payload = {
        practitioner_id: parseInt(cfg.pracId, 10),
        name: answers._name, email: answers._email, phone: answers._phone || '',
        // Honeypot — invisible to real users, bots fill everything; server
        // silently accepts and discards if non-empty.
        website: (function () { var h = document.getElementById(id + '-website'); return h ? h.value : ''; })(),
        q1_age: answers.q1_age, q1_sex: answers.q1_sex,
        q2a: answers.q2a, q2b: answers.q2b,
        q3: answers.q3, q4: answers.q4, q5: answers.q5,
        q6: answers.q6, q7: answers.q7, q8: answers.q8, q9: answers.q9,
        rate_of_ageing_result: rate
      };
      // v0.43.0 - front-door safety answers (present only when the flag is on
      // and something was ticked). Server sanitises against a key allowlist.
      if (answers._safety) payload.safety = answers._safety;
      if (inviteToken) payload.invite_token = inviteToken;

      if (cfg.apiUrl) {
        fetch(cfg.apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
          .then(function (res) {
            var contEl = document.getElementById(id + '-continue');
            if (!contEl) return;

            // Server-side rate limit / cooldown / format error
            if (!res.ok || res.body.code) {
              var msg = (res.body && res.body.message)
                ? res.body.message
                : 'Something went wrong saving your submission. Please try again in a moment.';
              contEl.innerHTML = ''
                + '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px 16px;text-align:left;color:#9a3412;font-size:13px;line-height:1.5;">'
                + '<strong style="display:block;margin-bottom:4px;">We couldn\u2019t send your verification email</strong>'
                + msg
                + '</div>';
              return;
            }

            var data = res.body || {};

            // Invite-fast-path: pre-trusted, immediate magic link.
            // Phase 17 \u2014 primary CTA styled to match the mockup pill, full
            // width within the buttons row, dark teal #004F59. The "we've
            // also sent this link to your email" caption sits below as a
            // safety-net for clients who walk away.
            if (data.form_token) {
              var baseUrl = cfg.apiUrl.replace(/\/wp-json\/.*$/, '');
              var continueUrl = baseUrl + '/assessment/?token=' + data.form_token;
              contEl.innerHTML = ''
                + '<a class="hdlw-r-btn-primary" href="' + continueUrl + '" target="_blank" rel="noopener">Continue to Stage 2 \u2014 Your WHY \u2192</a>'
                + '<p class="hdlw-r-email-note">We\u2019ve also sent this link to your email.</p>';
              return;
            }

            // v0.29.0 — public lead-capture path: no Continue button,
            // no email card. The practitioner footer above already renders
            // the "details forwarded to your practitioner" confirm line,
            // and the Book-a-Session button is rendered inline as the only
            // CTA. Clear the "Sending your results..." placeholder by
            // collapsing the continue area to nothing.
            contEl.innerHTML = '';
          })
          .catch(function () { /* silent — gauge already shown */ });
      }
    }

    // v0.29.1 — Expose a step-jumper on the host element so the practitioner
    // dashboard can preview Q1 / Q5 / Result without walking the funnel. Only
    // ever called from the practitioner-side preview wiring; never from
    // production embeds. Synthesises plausible answers when jumping straight
    // to the result page so the gauge + stats render with stable test data
    // instead of NaN.
    el.__hdlGoTo = function (s) {
      if (s === 10) {
        if (!answers.q1_age) answers.q1_age = 50;
        if (!answers.q1_sex) answers.q1_sex = 'female';
        if (answers.q2a == null) { answers.q2a = 2; answers.q2b = 'even'; }
        ['q3','q4','q5','q6','q7','q8','q9'].forEach(function (k) { if (!answers[k]) answers[k] = 'd'; });
        if (!answers._name) answers._name = 'Preview';
        if (!answers._email) answers._email = 'preview@example.com';
      }
      goTo(s);
    };

    // --- Start ---
    goTo(0);
  }

  // ---------- UI HELPERS ----------

  // v0.36.0 (Phase P) — helpers all return class-based markup. Inline
  // styles dropped in favour of the editorial style block injected once
  // by injectEditorialStyles(). Silhouette + fat-distribution images
  // continue to switch on sex (male-N.svg / female-N.svg / fat-VAL-SEX.svg)
  // — that wiring is unchanged from prior versions.
  function silOpt(id, sex, n, label) {
    return '<div id="' + id + '-sil-' + n + '" class="hdlw-sil">'
      + '<img src="' + IMG_BASE + sex + '-' + n + '.svg" alt="' + escHtml(label) + '">'
      + '<span class="hdlw-sil-label">' + escHtml(label) + '</span></div>';
  }

  function fatOpt(id, val, label, sex) {
    return '<div id="' + id + '-fat-' + val + '" class="hdlw-fat">'
      + '<img src="' + IMG_BASE + 'fat-' + val + '-' + (sex || 'male') + '.svg" alt="' + escHtml(label) + '">'
      + '<span class="hdlw-fat-label">' + escHtml(label) + '</span></div>';
  }

  function inputField(id, name, label, type, required, hint) {
    return '<div class="hdlw-field">'
      + '<label class="hdlw-field-label" for="' + id + '-' + name + '">' + escHtml(label) + '</label>'
      + '<input id="' + id + '-' + name + '" class="hdlw-field-input" type="' + type + '"' + (required ? ' required' : '') + ' placeholder=" ">'
      + (hint ? '<p class="hdlw-field-foot">' + escHtml(hint) + '</p>' : '')
      + '</div>';
  }

  function lockedField(label, value) {
    return '<div class="hdlw-field">'
      + '<label class="hdlw-field-label">' + escHtml(label) + '</label>'
      + '<div class="hdlw-field-locked">' + escHtml(value) + '</div>'
      + '</div>';
  }

  // btn() retained for any callers that haven't been migrated to .hdlw-cta
  // markup directly. Renders the same editorial CTA so the visual is
  // identical regardless of call site.
  function btn(id, text, color) {
    return '<button id="' + id + '" type="button" class="hdlw-cta">' + escHtml(text) + '</button>';
  }

  function valOf(elId) {
    var el = document.getElementById(elId);
    return el ? el.value.trim() : '';
  }

  function markErr(elId) {
    var el = document.getElementById(elId);
    if (el) el.style.borderColor = '#e74c3c';
  }

  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML.replace(/"/g, '&quot;');
  }

  // ---------- INIT ----------

  // v0.35.0 (Phase O) — Pull fresh display config from /widget/public-config
  // before building a public-path widget. Lets practitioners update their
  // booking link / theme / CTA text in Widget Settings without re-pasting
  // the embed snippet on every host site.
  //
  // Invite path skips this fetch — the verify-invite response already
  // carries the same fields with a server-side signature, so it's
  // authoritative.
  //
  // Network failure or timeout: invokes the callback with null. buildWidget
  // then falls back to the embed's data-* attributes, so old embed snippets
  // never break.
  function pullPublicConfig(el, callback) {
    var pracId = el.getAttribute('data-practitioner-id') || '';
    var apiUrl = el.getAttribute('data-api') || '';
    if (!pracId || !apiUrl) { callback(null); return; }

    var configUrl = apiUrl.replace(/\/widget\/lead\/?$/, '/widget/public-config');
    if (configUrl === apiUrl) { callback(null); return; } // unable to derive

    var done = false;
    var safety = setTimeout(function () {
      if (done) return;
      done = true;
      callback(null);
    }, 1500);

    fetch(configUrl + '?practitioner_id=' + encodeURIComponent(pracId), {
      method: 'GET',
      credentials: 'omit',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (done) return;
        done = true;
        clearTimeout(safety);
        callback(data && data.config ? data.config : null);
      })
      .catch(function () {
        if (done) return;
        done = true;
        clearTimeout(safety);
        callback(null);
      });
  }

  function init() {
    var inviteToken = getInviteToken();
    var widgets = document.querySelectorAll('.hdl-rate-widget');

    for (var i = 0; i < widgets.length; i++) {
      if (widgets[i].getAttribute('data-hdl-init')) continue;
      widgets[i].setAttribute('data-hdl-init', '1');

      if (inviteToken && i === 0) {
        (function (el, token) {
          showSpinner(el);
          var verifyUrl = el.getAttribute('data-verify-api') || '';
          if (!verifyUrl) {
            var apiUrl = el.getAttribute('data-api') || '';
            verifyUrl = apiUrl ? apiUrl.replace('/widget/lead', '/widget/verify-invite') : '';
          }
          if (!verifyUrl) { showInviteError(el, 'invalid'); return; }

          fetch(verifyUrl + '?token=' + encodeURIComponent(token))
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.valid) { data._token = token; buildWidget(el, data); }
              else { showInviteError(el, data.reason || 'invalid'); }
            })
            .catch(function () { showInviteError(el, 'invalid'); });
        })(widgets[i], inviteToken);
      } else {
        // Public path — fetch fresh config before render, fall back to
        // embed's data-* attributes on timeout/error.
        (function (el) {
          pullPublicConfig(el, function (publicCfg) {
            buildWidget(el, null, publicCfg);
          });
        })(widgets[i]);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.hdlRateWidgetInit = init;
})();
