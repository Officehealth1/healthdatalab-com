/**
 * HDL V2 Staged Assessment Form — Stages 1, 2, 3
 *
 * Token-based multi-stage longevity assessment.
 * Stage 1: Quick Insight (6 fields, gauge result)
 * Stage 2: Your WHY (free-text — key people, motivations, vision; no multi-select)
 * Stage 3: Full Detail (22 factors, wizard mode, skip options, draft report)
 *
 * Requires: hdlv2-speedometer.js (HDLSpeedometer.buildUrl)
 *
 * @package HDL_Longevity_V2
 * @since 0.5.0
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_form || {};

  // Stage 1 question icon mapping. Files served from
  // /wp-content/plugins/hdl-longevity-v2/assets/images/stage1-icons/.
  var STAGE1_ICON_BASE = (CFG.plugin_url || '') + 'assets/images/stage1-icons/';
  var STAGE1_ICONS = {
    q3: 'q3-zone2.png',
    q4: 'q4-stairs.png',
    q5: 'q5-sit-stand.png'
  };
  if (!CFG.api_base) return;
  // Match API protocol to page protocol (avoids mixed-content block on HTTPS)
  if (CFG.api_base && location.protocol === 'https:') {
    CFG.api_base = CFG.api_base.replace(/^http:/, 'https:');
  }

  var root = document.getElementById('hdlv2-assessment');
  if (!root) return;

  // ── STATE ──
  var token = '';
  var formData = {};
  var currentStage = 1;
  var saveTimer = null;
  // v0.47.15 — sentinel stored in a Stage-3 Section-6 text field when the client
  // ticks "None (to my knowledge)". Lets the value round-trip (reload / section
  // re-render) and read clearly in the practitioner consultation + Claude draft
  // instead of an ambiguous blank (blank = "skipped", sentinel = "nothing to report").
  var NONE_SENTINEL = 'None (to my knowledge)';
  var saving = false;
  var serverData = {};
  var wizardSection = 0;

  // v0.40.2 — Centralised width modifier for .hdlv2-assessment-root.
  // Three states: '' (default 620px), 'is-medium' (760px), 'is-wide' (full).
  // Each renderer calls this at its entry so navigation between stages can't
  // leave the root in a stale wider/narrower state.
  function setRootClasses(modifier) {
    if (!root) return;
    root.classList.remove('is-wide', 'is-medium');
    if (modifier) root.classList.add(modifier);
  }

  // ── CONSTANTS ──
  // Fallback used only for fields that don't have their own S3_OPTIONS entry.
  // Real per-field answer anchors live in S3_OPTIONS below and are what the
  // client actually sees on Stage 3. Sourced from V1 longevity-form-raw.php
  // (lines 11436-11561 of health-data-lab-plugin) so V2 matches the tested
  // V1 scoring anchors the rate calculator was calibrated against.
  var SCORE_OPTIONS = [
    { v: '', l: 'Select...' },
    { v: '0', l: 'Very Poor / Not at all' },
    { v: '1', l: 'Poor / Rarely' },
    { v: '2', l: 'Below Average / Sometimes' },
    { v: '3', l: 'Average / Moderate' },
    { v: '4', l: 'Good / Often' },
    { v: '5', l: 'Excellent / Very Often' }
    // v0.40.0 — 'skip' / "I don't know" option removed per Item 11.6.
    // Per-section helper text now covers the skip case; the fallback
    // SCORE_OPTIONS array is only used when a field has no S3_OPTIONS
    // entry (none do today), so this is a dead branch even when present.
  ];

  // Per-field answer sets for Stage 3 score questions. Each matches V1's
  // quiz-flow anchors verbatim where meaningful (reps / seconds / hours /
  // drinks per week) so scoring intent is preserved across versions.
  // All lists are 0-5 + skip to stay within clamp_score's 0-5 range.
  // Hydration uses 6 tiers (V1 had 4) so it can reach full score; V1's
  // 4-tier card UI can be layered back on later when we do Stage 3 UI parity.
  var S3_OPTIONS = {
    // v0.38.0 — Self-explanatory dropdown labels per spec. Each option now
    // names the protocol unit (reps in 30 seconds / seconds holding breath /
    // seconds balancing on one leg) so a client glancing at the dropdown
    // without reading the tooltip still understands what's being measured.
    sitToStand: [
      { v: '',  l: 'Select…' },
      { v: '0', l: '0 reps in 30 seconds' },
      { v: '1', l: '1–7 reps in 30 seconds' },
      { v: '2', l: '8–12 reps in 30 seconds' },
      { v: '3', l: '13–17 reps in 30 seconds' },
      { v: '4', l: '18–24 reps in 30 seconds' },
      { v: '5', l: '25+ reps in 30 seconds' }
    ],
    breathHold: [
      { v: '',  l: 'Select…' },
      { v: '0', l: 'Held for less than 15 seconds' },
      { v: '1', l: 'Held for 15–29 seconds' },
      { v: '2', l: 'Held for 30–45 seconds' },
      { v: '3', l: 'Held for 46–60 seconds' },
      { v: '4', l: 'Held for 61–90 seconds' },
      { v: '5', l: 'Held for 90+ seconds' }
    ],
    balance: [
      { v: '',  l: 'Select…' },
      { v: '0', l: 'Less than 10 seconds on one leg' },
      { v: '1', l: '10–19 seconds on one leg' },
      { v: '2', l: '20–29 seconds on one leg' },
      { v: '3', l: '30–39 seconds on one leg' },
      { v: '4', l: '40–59 seconds on one leg' },
      { v: '5', l: '60+ seconds on one leg' }
    ],
    // skinElasticity — label restated inside each option so clients don't
    // need to recall the pinch-and-snap-back convention. Lower seconds = better.
    skinElasticity: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Skin takes 30+ seconds to return' },
      { v: '1', l: 'Skin takes 16–30 seconds to return' },
      { v: '2', l: 'Skin takes 10–15 seconds to return' },
      { v: '3', l: 'Skin takes 5–9 seconds to return' },
      { v: '4', l: 'Skin takes 3–4 seconds to return' },
      { v: '5', l: 'Skin takes 1–2 seconds to return' },
    ],
    sleepDuration: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Less than 4 hours (severely insufficient)' },
      { v: '1', l: '4–5 hours (very short sleep)' },
      { v: '2', l: '5–6 hours (short sleep)' },
      { v: '3', l: '6–7 hours (slightly below average)' },
      { v: '4', l: '7–8 hours (recommended duration)' },
      { v: '5', l: 'More than 8 hours (extended sleep)' },
    ],
    sleepQuality: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Never (I never sleep well)' },
      { v: '1', l: 'Rarely (seldom restful sleep)' },
      { v: '2', l: 'Occasionally (inconsistent quality)' },
      { v: '3', l: 'Sometimes (moderate quality sleep)' },
      { v: '4', l: 'Often (mostly restful sleep)' },
      { v: '5', l: 'Always (consistently high quality sleep)' },
    ],
    stressLevels: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Very stressed' },
      { v: '1', l: 'Often stressed' },
      { v: '2', l: 'Sometimes stressed' },
      { v: '3', l: 'Manageable' },
      { v: '4', l: 'Generally relaxed' },
      { v: '5', l: 'Very relaxed' },
    ],
    socialConnections: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'None (no regular social interaction)' },
      { v: '1', l: 'Rarely (infrequent social contact)' },
      { v: '2', l: 'Occasionally (sporadic interaction with friends/family)' },
      { v: '3', l: 'Regularly (consistent weekly social contact)' },
      { v: '4', l: 'Often (frequent social engagement)' },
      { v: '5', l: 'Daily (social interactions every day)' },
    ],
    cognitiveActivity: [
      { v: '', l: 'Select...' },
      { v: '0', l: "Never — don't challenge my brain" },
      { v: '1', l: 'Rarely' },
      { v: '2', l: 'Sometimes' },
      { v: '3', l: 'Regularly' },
      { v: '4', l: 'Often' },
      { v: '5', l: 'Daily — consistent practice' },
    ],
    dietQuality: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Very poor (nutrient deficient, unhealthy choices)' },
      { v: '1', l: 'Poor (limited variety, low nutrient density)' },
      { v: '2', l: 'Below average (occasional healthy meals, frequent unhealthy choices)' },
      { v: '3', l: 'Average (balanced diet with some healthy choices)' },
      { v: '4', l: 'Good (mostly nutritious and balanced)' },
      { v: '5', l: 'Excellent (high nutrient density, varied and balanced)' },
    ],
    alcoholConsumption: [
      { v: '', l: 'Select...' },
      { v: '0', l: '15+ drinks per week' },
      { v: '1', l: '10–14 drinks per week' },
      { v: '2', l: '6–9 drinks per week' },
      { v: '3', l: '3–5 drinks per week' },
      { v: '4', l: '1–2 drinks per week' },
      { v: '5', l: 'None' },
    ],
    smokingStatus: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Current daily smoker (smokes every day)' },
      { v: '1', l: 'Regular smoker (smokes on most days)' },
      { v: '2', l: 'Occasional smoker (smokes infrequently)' },
      { v: '3', l: 'Recently quit (stopped smoking within the last 6 months)' },
      { v: '4', l: 'Former smoker (quit more than 6 months ago)' },
      { v: '5', l: 'Never smoked (no history of smoking)' },
    ],
    supplementIntake: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Never' },
      { v: '1', l: 'Rarely' },
      { v: '2', l: 'Sometimes' },
      { v: '3', l: 'Regularly' },
      { v: '4', l: 'Often' },
      { v: '5', l: 'Daily' },
    ],
    sunlightExposure: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Rarely — mostly indoors' },
      { v: '1', l: 'Irregular exposure' },
      { v: '2', l: 'Midday only' },
      { v: '3', l: 'Extended midday sun' },
      { v: '4', l: 'Morning or evening' },
      { v: '5', l: 'Morning and evening daily' },
    ],
    dailyHydration: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Less than 1 litre' },
      { v: '1', l: '1–1.5 litres' },
      { v: '2', l: '1.5–2 litres' },
      { v: '3', l: '2–2.5 litres' },
      { v: '4', l: '2.5–3 litres' },
      { v: '5', l: '3+ litres' },
    ],
    physicalActivity: [
      { v: '', l: 'Select...' },
      { v: '0', l: 'Sedentary (minimal activity)' },
      { v: '1', l: 'Very low (occasional walking)' },
      { v: '2', l: 'Low (regular walking or light activity)' },
      { v: '3', l: 'Moderate (regular moderate exercise)' },
      { v: '4', l: 'High (structured exercise 3+ times/week)' },
      { v: '5', l: 'Very high (intense training 4+ times/week)' }
    ]
  };


  // ─────────────────────────────────────────────────────────────────
  // Stage 1 → Stage 3 pre-fill mapping for duplicated questions.
  //
  // Stage 1 asks coarse versions of Sleep / Smoking / Social / Diet (q6-q9).
  // Stage 3 asks granular versions of the same areas. To avoid making the
  // client answer twice, we pre-fill Stage 3 fields when their Stage 1
  // counterpart exists. The mappings are deterministic and best-effort —
  // the client always sees the pre-filled value and can change it.
  //
  // Q6 (sleep) maps to BOTH sleepDuration AND sleepQuality because the
  // Stage 1 question conflates duration and quality into a single answer.
  // Option 'e' is the paradoxical "9+ hours but still feel tired" — high
  // duration, poor quality.
  //
  // Q3 (Zone 2 activity, hours/week) maps to physicalActivity. Option 'e'
  // ("More than 4 hours/week") is mapped to '4' (High), not '5' (Very high) —
  // V1's Very-high band ("intense training 4+ times/week") implies structure
  // + intensity beyond just Zone 2 hours, so we land conservatively and let
  // the client adjust upward if applicable.
  //
  // Cognitive Activity is intentionally absent — Stage 1 has no equivalent
  // question, so Stage 3 collects it fresh.
  // ─────────────────────────────────────────────────────────────────
  var S1_TO_S3_DEFAULTS = {
    q3: {
      physicalActivity: { a: '0', b: '1', c: '2', d: '3', e: '4' }
    },
    q6: {
      sleepDuration: { a: '0', b: '2', c: '3', d: '4', e: '5' },
      sleepQuality:  { a: '0', b: '1', c: '3', d: '4', e: '1' }
    },
    q7: {
      smokingStatus: { a: '0', b: '2', c: '4', d: '4', e: '5' }
    },
    q8: {
      socialConnections: { a: '1', b: '2', c: '3', d: '4', e: '5' }
    },
    q9: {
      dietQuality: { a: '0', b: '2', c: '3', d: '4', e: '5' }
    }
  };

  function s1Prefill(fieldName, stage1Data, currentValue) {
    if (currentValue) return currentValue;
    if (!stage1Data) return currentValue;
    for (var qKey in S1_TO_S3_DEFAULTS) {
      var qMap = S1_TO_S3_DEFAULTS[qKey];
      if (qMap[fieldName] && stage1Data[qKey]) {
        var mapped = qMap[fieldName][stage1Data[qKey]];
        if (mapped) {
          // v0.22.21 — also write the prefilled value into formData so the
          // next autoSave (Next button or any field change) persists it. The
          // value is already shown selected in the dropdown via the value
          // attribute, but without this assignment a user who clicks Next
          // without touching the pre-filled field would lose the prefill on
          // server-side save (formData[field] would still be empty).
          if (typeof formData !== 'undefined' && formData) {
            formData[fieldName] = mapped;
          }
          return mapped;
        }
      }
    }
    return currentValue;
  }

  // Stage 1 wizard state
  var s1Step = 0;
  var S1_TOTAL_STEPS = 10; // q1, q2a, q2b, q3-q9
  var s1KeyHandler = null; // document-level A-E keyboard shortcut handler (rebound per MCQ render)
  // v0.20.8 — tracks the 200ms auto-advance setTimeout so s1Back() can cancel
  // it when the user clicks Previous during the advance window. Without this,
  // the scheduled s1Step++ fires after Back decremented, landing on the wrong step.
  var s1AdvanceTimer = null;

  // Derive image base URL from plugin URL in config, or from script src
  var S1_IMG_BASE = (function(){
    if (window.hdlv2_form && window.hdlv2_form.plugin_url) return window.hdlv2_form.plugin_url + 'assets/images/silhouettes/';
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      var src = scripts[i].src || '';
      if (src.indexOf('hdlv2-staged-form') !== -1) return src.replace(/assets\/js\/hdlv2-staged-form\.js.*$/, 'assets/images/silhouettes/');
    }
    return '';
  })();

  var S1_LETTER_MAP = { a:1, b:2, c:3, d:4, e:5 };
  var S1_Q2A_SCORES = { 1:2, 2:5, 3:3, 4:2, 5:1 };
  var S1_Q2B_MODS = { apple:0.5, pear:-0.3, even:0.0 };

  var S1_QUESTIONS = {
    q3: { category:'Fitness', title:'Zone 2 Activity', text:'How much steady, moderate-effort activity do you do in a typical week?',
      hint:'Think: brisk walking, easy cycling, swimming at a comfortable pace, gardening \u2014 anything where you could hold a conversation but you\u2019re definitely moving.',
      opts:[{v:'a',t:'Almost none \u2014 I\u2019m mostly sedentary'},{v:'b',t:'About 30\u201360 minutes per week'},{v:'c',t:'About 1\u20132 hours per week'},{v:'d',t:'About 2\u20134 hours per week'},{v:'e',t:'More than 4 hours per week'}] },
    q4: { category:'Fitness', title:'Cardiovascular Capacity', text:'Imagine you\u2019re in a building with no lift. How would you handle climbing stairs?',
      cite:'Stair climbing validated as a predictor of cardiovascular fitness (Peteiro et al., 2020 \u2014 94% sensitivity).',
      opts:[{v:'a',t:'One flight is difficult \u2014 I\u2019d need to stop and rest'},{v:'b',t:'I can walk up 2\u20133 flights but I\u2019d be noticeably out of breath'},{v:'c',t:'I can walk up 4\u20135 flights at a steady pace without stopping'},{v:'d',t:'I can walk up 5+ flights comfortably, or jog up 3\u20134 flights'},{v:'e',t:'I could run up 4\u20135 flights without significant difficulty'}] },
    q5: { category:'Fitness', title:'Functional Strength', text:'Imagine sitting cross-legged on the floor. How easily could you get back up to standing?',
      cite:'Based on the Sitting-Rising Test (Dr Claudio Gil Ara\u00fajo). Each point scored reduced mortality risk by 21% over 6 years.',
      opts:[{v:'a',t:'I couldn\u2019t get down, or I\u2019d need someone to help me back up'},{v:'b',t:'I\u2019d need furniture or both hands and a knee on the ground'},{v:'c',t:'I\u2019d use one hand or one knee for a bit of support'},{v:'d',t:'I could do it without support, but it takes effort'},{v:'e',t:'I can sit down and stand back up smoothly, no hands'}] },
    q6: { category:'Sleep', title:'Sleep', text:'On a typical night, how would you describe your sleep?',
      opts:[{v:'a',t:'Fewer than 5 hours, or I struggle most nights'},{v:'b',t:'About 5\u20136 hours, or I wake frequently and don\u2019t feel rested'},{v:'c',t:'About 6\u20137 hours, reasonable quality'},{v:'d',t:'About 7\u20138 hours, good quality \u2014 I usually wake feeling rested'},{v:'e',t:'I sleep 9+ hours but still feel tired'}] },
    q7: { category:'Lifestyle', title:'Smoking', text:'What\u2019s your smoking status?',
      opts:[{v:'a',t:'I smoke daily'},{v:'b',t:'I smoke occasionally or socially'},{v:'c',t:'I quit within the last 5 years'},{v:'d',t:'I quit more than 5 years ago'},{v:'e',t:'I\u2019ve never smoked (in last 10 years)'}] },
    q8: { category:'Mental Wellbeing', title:'Social Connection', text:'How often do you spend meaningful time with people you care about?',
      hint:'In person, by phone, or video \u2014 not just social media or texting.',
      opts:[{v:'a',t:'Rarely \u2014 I feel isolated most of the time'},{v:'b',t:'Once or twice a month'},{v:'c',t:'About once a week'},{v:'d',t:'Several times a week'},{v:'e',t:'Daily \u2014 strong, regular connections'}] },
    q9: { category:'Nutrition', title:'Diet Pattern', text:'Which best describes your typical eating pattern?',
      opts:[{v:'a',t:'Mostly processed or fast food, very few vegetables'},{v:'b',t:'Mixed \u2014 some healthy meals, a lot of convenience food'},{v:'c',t:'Reasonably healthy \u2014 I cook most meals'},{v:'d',t:'Mostly whole foods, plenty of vegetables'},{v:'e',t:'Very clean \u2014 whole foods, diverse vegetables, minimal processed'}] }
  };

  var S3_SECTIONS = [
    { id: 'body', title: 'Body Measurements', fields: ['hip', 'bpSystolic', 'bpDiastolic', 'restingHeartRate'] },
    { id: 'fitness', title: 'Fitness & Performance', fields: ['physicalActivity', 'sitToStand', 'breathHold', 'balance', 'skinElasticity'] },
    { id: 'sleep', title: 'Sleep', fields: ['sleepDuration', 'sleepQuality'] },
    { id: 'mental', title: 'Mental & Social', fields: ['stressLevels', 'socialConnections', 'cognitiveActivity'] },
    { id: 'lifestyle', title: 'Lifestyle', fields: ['dietQuality', 'alcoholConsumption', 'smokingStatus', 'supplementIntake', 'sunlightExposure', 'dailyHydration'] },
    // v0.38.0 — Section 6. Three optional long-form text fields. Not consumed
    // by HDLV2_Rate_Calculator (zero impact on scores), surfaced to the
    // practitioner in the consultation page and fed into the Stage 3 draft
    // report AI prompt so Claude can reference family history / meds /
    // existing conditions when writing AWAKEN/LIFT/THRIVE.
    { id: 'background', title: 'Health Background', fields: ['family_history', 'medications', 'existing_conditions'] }
  ];

  // ── INIT ──
  function init() {
    try { token = new URLSearchParams(window.location.search).get('token') || ''; } catch (e) { token = ''; }
    if (!token || !/^[a-f0-9]{64}$/.test(token)) {
      showError('Invalid assessment link', 'Please check the URL or contact your practitioner for a new link.');
      return;
    }
    showLoading();
    loadForm();
  }

  function loadForm() {
    showLoading();
    fetch(CFG.api_base + '/load?token=' + encodeURIComponent(token), { headers: { 'X-WP-Nonce': CFG.nonce } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.code === 'invalid_token') { showError('Assessment not found', 'This link may have expired. Contact your practitioner.'); return; }
        serverData = data;
        currentStage = data.current_stage || 1;
        if (currentStage === 1) { formData = data.stage1_data || {}; renderStage1(data); }
        else if (currentStage === 2) { formData = data.stage2_data || {}; renderStage2(data); }
        else { formData = data.stage3_data || {}; renderStage3(data); }
      })
      .catch(function () { showError('Connection error', 'Could not load your assessment. Please try again.'); });
  }

  // ── LOADING / ERROR ──
  function showLoading() {
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-loading"><div class="hdlv2-spinner"></div><p style="color:#888;margin:0;">Loading your assessment...</p></div></div>';
  }

  function showError(title, message) {
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-error">'
      + '<div class="hdlv2-error-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>'
      + '<h3 style="font-family:Poppins,sans-serif;margin:0 0 8px;font-size:18px;font-weight:700;color:#111;">' + esc(title) + '</h3>'
      + '<p style="font-size:14px;color:#888;margin:0;line-height:1.5;">' + esc(message) + '</p></div></div>';
  }

  // ── PROGRESS BAR ──
  // v0.40.3 — Labels match the recent stage-naming changes so the progress
  // bar reads as one coherent journey across form, emails, and PDF:
  //   Stage 1 → "Rate of Ageing" (matches v25 Stage 1 email banner + PDF)
  //   Stage 2 → "Your Why"       (matches Item 7 + Items 8/9 email subjects)
  //   Stage 3 → "More Health Details" (matches v0.38.0 form title)
  //
  // v0.40.4 — Class names renamed from .hdlv2-progress* → .hdlv2-stage-flow*
  // to break a global-namespace collision with hdlv2-loading.css. The
  // loading helper animates content from "." → "..." on
  // .hdlv2-progress-step::after; both stylesheets are enqueued on the
  // assessment page, so the loading dots were leaking onto the form's
  // step labels. See hdlv2-form.css header for the full rationale.
  function progressBar(active) {
    var stages = [{num:1,label:'Rate of Ageing'},{num:2,label:'Your Why'},{num:3,label:'More Health Details'}];
    var h = '<div class="hdlv2-stage-flow">';
    stages.forEach(function(s){
      var cls = s.num < active ? 'completed' : s.num === active ? 'active' : 'pending';
      h += '<div class="hdlv2-stage-flow-step '+cls+'"><div class="hdlv2-stage-flow-dot">'+(s.num<active?'&#10003;':s.num)+'</div><div class="hdlv2-stage-flow-label">'+s.label+'</div>'+(s.num<3?'<div class="hdlv2-stage-flow-line"></div>':'')+'</div>';
    });
    return h + '</div>';
  }

  // ── AUTO-SAVE ──
  function onFieldChange(e) {
    var name = e.target.getAttribute('data-field');
    if (!name) return;
    formData[name] = e.target.value.trim();
    e.target.classList.remove('error');
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(function(){ autoSave(currentStage); }, 2000);
  }

  function autoSave(stage) {
    if (saving) return;
    saving = true;
    setSaveStatus('saving', 'Saving...');
    fetch(CFG.api_base + '/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ token: token, stage: stage, data: formData })
    }).then(function(r){ return r.json(); })
      .then(function(res){ saving=false; if(res.success){setSaveStatus('saved','Saved');setTimeout(function(){setSaveStatus('','');},3000);}else{setSaveStatus('error','Save failed');}})
      .catch(function(){ saving=false; setSaveStatus('error','Connection error'); });
  }

  function setSaveStatus(cls, text) {
    var el = document.getElementById('hdlv2-save-indicator');
    if (!el) return;
    el.className = 'hdlv2-save-status' + (cls ? ' ' + cls : '');
    el.textContent = text;
  }

  function bindFieldListeners() {
    root.querySelectorAll('.hdlv2-form-body input:not([type="range"]):not([type="checkbox"]), .hdlv2-form-body select').forEach(function(el){
      el.addEventListener('change', onFieldChange);
      el.addEventListener('blur', onFieldChange);
    });
    // v0.47.15 — textareas were NEVER bound here (the selector above only takes
    // input + select), so Stage 3 Section 6 (Health Background) free-text was
    // silently dropped from formData and the char counter never moved. Bind
    // 'input' for live capture + counter, plus change/blur for the autosave debounce.
    root.querySelectorAll('.hdlv2-form-body textarea[data-field]').forEach(function(el){
      el.addEventListener('input', onTextareaInput);
      el.addEventListener('change', onFieldChange);
      el.addEventListener('blur', onFieldChange);
    });
    // "None (to my knowledge)" toggles — disable the paired textarea + store the
    // sentinel so the field reads clearly downstream (consultation + Claude draft).
    root.querySelectorAll('.hdlv2-form-body .hdlv2-none-check').forEach(function(el){
      el.addEventListener('change', onNoneToggle);
    });
  }

  function onTextareaInput(e) {
    var name = e.target.getAttribute('data-field');
    if (!name) return;
    formData[name] = e.target.value;
    updateCharCount(name, e.target.value.length);
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(function(){ autoSave(currentStage); }, 2000);
  }

  function onNoneToggle(e) {
    var name = e.target.getAttribute('data-none-for');
    if (!name) return;
    var ta = document.getElementById('hdlv2-f-' + name);
    if (!ta) return;
    if (e.target.checked) {
      ta.value = '';
      ta.disabled = true;
      formData[name] = NONE_SENTINEL;
    } else {
      ta.disabled = false;
      formData[name] = '';
      ta.focus();
    }
    updateCharCount(name, 0);
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(function(){ autoSave(currentStage); }, 800);
  }

  function updateCharCount(name, len) {
    var el = document.getElementById('hdlv2-charcount-' + name);
    if (!el) return;
    var ta  = document.getElementById('hdlv2-f-' + name);
    var max = ta ? ta.getAttribute('maxlength') : null;
    el.textContent = len + ' / ' + (max || '∞');
  }

  // v0.36.20 — added Terms of Service link to the staged-form footer
  // (applies across every stage that renders footer()).
  function footer() { return '<div class="hdlv2-footer">Powered by <a href="https://healthdatalab.com" target="_blank" rel="noopener">HealthDataLab</a> &middot; <a href="https://healthdatalab.com/terms" target="_blank" rel="noopener">Terms of Service</a></div>'; }
  function saveIndicator() { return '<div id="hdlv2-save-indicator" class="hdlv2-save-status"></div>'; }

  // ══════════════════════════════════════════════════════════════
  //  STAGE 1: QUICK INSIGHT — 9 evidence-based questions
  // ══════════════════════════════════════════════════════════════

  function renderStage1(data) {
    if (data.stage1_completed_at && data.stage1_data && data.stage1_data.server_result) {
      renderStage1Result(data.stage1_data.server_result, data.stage1_data); return;
    }
    setRootClasses(''); // v0.40.2 — default 620px for Stage 1 question form
    s1Step = 0;
    renderS1Step(data);
  }

  function renderS1Step(data) {
    var d = formData;
    var steps = [renderS1_Q1, renderS1_Q2a, renderS1_Q2b, renderS1_Q3, renderS1_Q4, renderS1_Q5, renderS1_Q6, renderS1_Q7, renderS1_Q8, renderS1_Q9];
    var pct = Math.round((s1Step / S1_TOTAL_STEPS) * 100);

    // v0.20.9 — "← Previous" button shown from Q2a onwards (step 1+). Hidden
    // on Q1 (step 0). Uses .hdlv2-nav-back (ghost style) + .hdlv2-s1-back-btn
    // (Stage-1 size modifier). Reads as chrome, not as a primary action —
    // matches the visual vocabulary of .hdlv2-s1-sex-btn.
    var backBtn = s1Step > 0
      ? '<button type="button" id="hdlv2-s1-back" class="hdlv2-nav-back hdlv2-s1-back-btn" aria-label="Go back to previous question">&#8592; Previous</button>'
      : '';

    // Stage 1 wrapper — .hdlv2-s1 animates in on every re-render via CSS keyframes.
    // Counter sits above the bar; body lives inside so the fade+slide covers it too.
    var html = '<div class="hdlv2-card">' + progressBar(1)
      + '<div class="hdlv2-header"><h2>Quick Health Insight</h2><p>10 quick questions, takes about 60 seconds</p></div>'
      + '<div class="hdlv2-s1">'
      +   '<div class="hdlv2-s1-header">'
      +     backBtn
      +     '<p class="hdlv2-s1-counter">Question ' + (s1Step + 1) + ' &nbsp;/&nbsp; ' + S1_TOTAL_STEPS + '</p>'
      +     '<div class="hdlv2-s1-progress"><div class="hdlv2-s1-progress-fill" style="width:' + pct + '%;"></div></div>'
      +   '</div>'
      +   '<div class="hdlv2-form-body" id="hdlv2-s1-body"></div>'
      + '</div>'
      + saveIndicator() + footer() + '</div>';
    root.innerHTML = html;

    // Bind back button (only present when s1Step > 0).
    var backEl = document.getElementById('hdlv2-s1-back');
    if (backEl) {
      backEl.addEventListener('click', function () { s1Back(data); });
    }

    var body = document.getElementById('hdlv2-s1-body');
    steps[s1Step](body, d, data);
  }

  function s1Advance(data) {
    // Auto-save current state
    if (saveTimer) clearTimeout(saveTimer);
    autoSave(1);
    // Unbind the MCQ keyboard handler — the next render will rebind for its own options.
    if (s1KeyHandler) { document.removeEventListener('keydown', s1KeyHandler); s1KeyHandler = null; }
    // v0.20.8 — track the advance timer so s1Back() can cancel it if the user
    // clicks Previous during the 200ms window.
    if (s1AdvanceTimer) clearTimeout(s1AdvanceTimer);
    s1AdvanceTimer = setTimeout(function(){
      s1AdvanceTimer = null;
      s1Step++;
      renderS1Step(data);
      root.scrollIntoView({behavior:'smooth'});
    }, 200);
  }

  // v0.20.8 — Previous button handler for Stage 1. Matthew 2026-04-23:
  // "it would be good to put a back button just because if somebody makes
  // a mistake they kind of go oh no i misunderstood that and then they
  // can go back". Decrements s1Step and re-renders; stored answers in
  // formData are pre-selected by the per-step render functions so the
  // user sees their prior choice highlighted.
  function s1Back(data) {
    // Cancel any pending auto-advance to avoid a race where Back decrements
    // and then the scheduled s1Step++ fires, landing two steps back.
    if (s1AdvanceTimer) {
      clearTimeout(s1AdvanceTimer);
      s1AdvanceTimer = null;
    }
    // Guard: can't go back from Q1. The render hides the button on step 0
    // so this is defence-in-depth for rapid double-click scenarios.
    if (s1Step <= 0) return;
    if (s1KeyHandler) {
      document.removeEventListener('keydown', s1KeyHandler);
      s1KeyHandler = null;
    }
    s1Step--;
    renderS1Step(data);
    root.scrollIntoView({behavior:'smooth'});
  }

  // Q1: Age + Sex
  function renderS1_Q1(body, d, data) {
    body.innerHTML = ''
      + '<h3 class="hdlv2-s1-title">Age &amp; Sex</h3>'
      + '<p class="hdlv2-s1-prompt">Used to calibrate your results to population norms.</p>'
      + fieldInput('q1_age','Age','number',d.q1_age || 50,'Your age in years')
      + '<div class="hdlv2-field"><label>Sex</label>'
      +   '<div class="hdlv2-s1-sex">'
      +     '<button type="button" class="hdlv2-s1-sex-btn'+(d.q1_sex==='male'?' selected':'')+'" data-sex="male">Male</button>'
      +     '<button type="button" class="hdlv2-s1-sex-btn'+(d.q1_sex==='female'?' selected':'')+'" data-sex="female">Female</button>'
      +   '</div>'
      + '</div>'
      + '<button id="hdlv2-s1-next" class="hdlv2-btn hdlv2-s1-next">Next</button>';

    body.querySelectorAll('[data-sex]').forEach(function(btn){
      btn.addEventListener('click', function(){
        formData.q1_sex = btn.getAttribute('data-sex');
        body.querySelectorAll('[data-sex]').forEach(function(b){ b.classList.remove('selected'); });
        btn.classList.add('selected');
      });
    });
    body.querySelector('#hdlv2-s1-next').addEventListener('click', function(){
      var ageEl = body.querySelector('[data-field="q1_age"]');
      var age = parseInt(ageEl ? ageEl.value : '', 10);
      if (!age || age < 1) { if(ageEl) ageEl.classList.add('error'); return; }
      if (!formData.q1_sex) { setSaveStatus('error','Please select Male or Female'); return; }
      formData.q1_age = age;
      s1Advance(data);
    });
    bindFieldListeners();
  }

  // Q2a: Body shape silhouettes
  function renderS1_Q2a(body, d, data) {
    var sex = d.q1_sex || 'male';
    var labels = ['Very lean','Healthy','Overweight','Obese I','Obese II+'];
    var html = '<h3 class="hdlv2-s1-title">Overall Body Size</h3>'
      + '<p class="hdlv2-s1-prompt">Which image most closely matches your current body shape?</p>'
      + '<p class="hdlv2-s1-cite">Based on the Stunkard Figure Rating Scale (r=0.91 correlation with measured BMI).</p>'
      + '<div class="hdlv2-s1-sil-grid">';
    for (var i = 1; i <= 5; i++) {
      var sel = d.q2a === i;
      html += '<button type="button" class="hdlv2-s1-sil' + (sel ? ' selected' : '') + '" data-sil="' + i + '">'
        + '<img src="' + S1_IMG_BASE + sex + '-' + i + '.svg" alt="' + labels[i-1] + '">'
        + '<span class="hdlv2-s1-sil-label">' + labels[i-1] + '</span>'
        + '</button>';
    }
    html += '</div>';
    body.innerHTML = html;

    body.querySelectorAll('.hdlv2-s1-sil').forEach(function(opt){
      opt.addEventListener('click', function(){
        formData.q2a = parseInt(opt.getAttribute('data-sil'), 10);
        body.querySelectorAll('.hdlv2-s1-sil').forEach(function(o){ o.classList.remove('selected'); });
        opt.classList.add('selected');
        s1Advance(data);
      });
    });
  }

  // Q2b: Fat distribution
  function renderS1_Q2b(body, d, data) {
    var sex = d.q1_sex || 'male';
    var fatOpts = [
      { v:'apple', label:'Mostly around the middle', img:'fat-apple-' + sex + '.svg' },
      { v:'pear', label:'Mostly around hips and thighs', img:'fat-pear-' + sex + '.svg' },
      { v:'even', label:'Fairly evenly spread', img:'fat-even-' + sex + '.svg' }
    ];
    var html = '<h3 class="hdlv2-s1-title">Fat Distribution</h3>'
      + '<p class="hdlv2-s1-prompt">Where do you tend to carry extra weight?</p>'
      + '<p class="hdlv2-s1-helper">If you\u2019re not sure or feel you don\u2019t carry much extra weight, select \u201cFairly evenly spread.\u201d</p>'
      + '<div class="hdlv2-s1-fat-grid">';
    fatOpts.forEach(function(f){
      var sel = d.q2b === f.v;
      html += '<button type="button" class="hdlv2-s1-sil hdlv2-s1-fat' + (sel ? ' selected' : '') + '" data-fat="' + f.v + '">'
        + '<img src="' + S1_IMG_BASE + f.img + '" alt="' + f.label + '">'
        + '<span class="hdlv2-s1-sil-label">' + f.label + '</span>'
        + '</button>';
    });
    html += '</div>';
    body.innerHTML = html;

    body.querySelectorAll('.hdlv2-s1-fat').forEach(function(opt){
      opt.addEventListener('click', function(){
        formData.q2b = opt.getAttribute('data-fat');
        body.querySelectorAll('.hdlv2-s1-fat').forEach(function(o){ o.classList.remove('selected'); });
        opt.classList.add('selected');
        s1Advance(data);
      });
    });
  }

  // Generic MCQ renderer for Stage 1
  function renderS1_MCQ(body, d, data, qKey) {
    var q = S1_QUESTIONS[qKey];
    // Stage-3-style category prefix on the heading. Skipped when category
    // matches title (e.g., q6 "Sleep") to avoid the "Sleep: Sleep" redundancy.
    var heading = (q.category && q.category !== q.title) ? (q.category + ': ' + q.title) : q.title;
    var html = '<h3 class="hdlv2-s1-title">' + heading + '</h3>'
      + '<p class="hdlv2-s1-prompt">' + q.text + '</p>';
    var iconName = STAGE1_ICONS[qKey];
    if (iconName && CFG.plugin_url) {
      html += '<div class="hdlv2-s1-icon-wrap"><img src="' + STAGE1_ICON_BASE + iconName + '" alt="" class="hdlv2-s1-icon"/></div>';
    }
    if (q.hint) html += '<p class="hdlv2-s1-helper">' + q.hint + '</p>';
    if (q.cite) html += '<p class="hdlv2-s1-cite">' + q.cite + '</p>';

    html += '<div class="hdlv2-s1-options" role="radiogroup">';
    q.opts.forEach(function(o){
      var sel = d[qKey] === o.v;
      html += '<button type="button" class="hdlv2-s1-opt' + (sel ? ' selected' : '') + '" data-val="' + o.v + '" role="radio" aria-checked="' + (sel ? 'true' : 'false') + '">'
        + '<span class="hdlv2-s1-chip" aria-hidden="true">' + o.v.toUpperCase() + '</span>'
        + '<span class="hdlv2-s1-opt-text">' + o.t + '</span>'
        + '</button>';
    });
    html += '</div>';

    // Desktop-only keyboard-shortcut hint (CSS hides on mobile)
    html += '<p class="hdlv2-s1-kbd-hint">Press <kbd>A</kbd>–<kbd>' + q.opts[q.opts.length - 1].v.toUpperCase() + '</kbd> to select</p>';

    body.innerHTML = html;

    body.querySelectorAll('.hdlv2-s1-opt').forEach(function(opt){
      opt.addEventListener('click', function(){
        formData[qKey] = opt.getAttribute('data-val');
        body.querySelectorAll('.hdlv2-s1-opt').forEach(function(o){
          o.classList.remove('selected');
          o.setAttribute('aria-checked', 'false');
        });
        opt.classList.add('selected');
        opt.setAttribute('aria-checked', 'true');
        // Last question (q9) triggers completion instead of advance
        if (qKey === 'q9') { completeStage1(); }
        else { s1Advance(data); }
      });
    });

    // Keyboard shortcut: A, B, C... key presses click the matching option.
    // Re-bound per render; s1Advance() unbinds before the next render mounts.
    if (s1KeyHandler) document.removeEventListener('keydown', s1KeyHandler);
    s1KeyHandler = function (e) {
      // Don't hijack keys while the user is typing in an input/textarea.
      var ae = document.activeElement;
      if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.isContentEditable)) return;
      var key = (e.key || '').toLowerCase();
      if (!/^[a-z]$/.test(key)) return;
      var opt = body.querySelector('.hdlv2-s1-opt[data-val="' + key + '"]');
      if (opt) { e.preventDefault(); opt.click(); }
    };
    document.addEventListener('keydown', s1KeyHandler);
  }

  function renderS1_Q3(body, d, data) { renderS1_MCQ(body, d, data, 'q3'); }
  function renderS1_Q4(body, d, data) { renderS1_MCQ(body, d, data, 'q4'); }
  function renderS1_Q5(body, d, data) { renderS1_MCQ(body, d, data, 'q5'); }
  function renderS1_Q6(body, d, data) { renderS1_MCQ(body, d, data, 'q6'); }
  function renderS1_Q7(body, d, data) { renderS1_MCQ(body, d, data, 'q7'); }
  function renderS1_Q8(body, d, data) { renderS1_MCQ(body, d, data, 'q8'); }
  function renderS1_Q9(body, d, data) { renderS1_MCQ(body, d, data, 'q9'); }

  function completeStage1() {
    // Leaving Stage 1 — unbind the A–E keyboard shortcut handler so it
    // doesn't hijack typing on later stages.
    if (s1KeyHandler) { document.removeEventListener('keydown', s1KeyHandler); s1KeyHandler = null; }

    var required = ['q1_age','q1_sex','q2a','q2b','q3','q4','q5','q6','q7','q8','q9'], hasErr = false;
    required.forEach(function(n){ if(!formData[n]){ hasErr=true; } });
    if (hasErr) { setSaveStatus('error','Please answer all questions'); return; }

    showLoading();
    fetch(CFG.api_base+'/save',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token,stage:1,data:formData})})
      .then(function(){return fetch(CFG.api_base+'/complete-stage',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token,stage:1})});})
      .then(function(r){return r.json();})
      .then(function(res){if(res.success&&res.result){renderStage1Result(res.result,formData);}else{setSaveStatus('error',res.message||'Please complete all questions');s1Step=0;renderS1Step(serverData);}})
      .catch(function(){setSaveStatus('error','Connection error');s1Step=0;renderS1Step(serverData);});
  }

  // v0.22.0 \u2014 premium result page mirroring the Final Report shell.
  //   - Hero greeting with "Stage 1" pill
  //   - 3-stat strip: Rate of Ageing, Biological Age, Chronological Age
  //   - Real QuickChart gauge (640x560) via HDLSpeedometer
  //   - "What This Means For You" \u2014 async-fetched Haiku commentary,
  //     deterministic fallback if the API fails so the page never blanks
  //   - "What Happens Next" with Continue-to-Stage-2 CTA
  function renderStage1Result(result, stageData) {
    // v0.22.0 — widen the SPA root so the premium result layout breaks
    // out of the narrow 620px form column. Removed when navigating to S2.
    // v0.40.2 — via setRootClasses helper (also clears is-medium if set).
    setRootClasses('is-wide');

    var rate = parseFloat(result.rate) || 1.0;
    var age  = parseInt(stageData.q1_age, 10) || 0;
    var bio  = age > 0 ? Math.round(rate * age * 10) / 10 : null;
    var diff = (bio !== null && age > 0) ? Math.round((bio - age) * 10) / 10 : null;

    var bandLabel, bandClass;
    if (rate <= 0.95)      { bandLabel = 'Slower \u2014 in your favour'; bandClass = 'good'; }
    else if (rate <= 1.05) { bandLabel = 'On average';                   bandClass = 'avg'; }
    else                    { bandLabel = 'Faster \u2014 let\u2019s work on this'; bandClass = 'warn'; }

    var diffLabel = '';
    if (diff !== null) {
      if (diff > 0)      diffLabel = '+' + diff + ' yrs vs chronological';
      else if (diff < 0) diffLabel = diff + ' yrs vs chronological';
      else               diffLabel = 'in line with chronological';
    }

    var firstName = '';
    if (serverData && serverData.client_name) {
      firstName = String(serverData.client_name).split(' ')[0];
    } else if (stageData && stageData.name) {
      firstName = String(stageData.name).split(' ')[0];
    }
    var greet = firstName ? ('Hi ' + esc(firstName) + ' \u2014') : 'Hi there \u2014';

    var gaugeUrl = HDLSpeedometer.buildUrl(rate, { width: 640, height: 560 });

    // Phase 17 (v0.22.26) \u2014 practitioner CTA + footer data from /form/load.
    // The buttons row hides "Book a session" entirely when no cta_link is
    // configured for this practitioner. Avatar falls back to initials when
    // no logo is set.
    var pracCtaLink = (serverData && serverData.practitioner_cta_link) || '';
    var pracCtaText = (serverData && serverData.practitioner_cta_text) || 'Book a session';
    var pracName    = (serverData && serverData.practitioner_name) || '';
    var pracLogoUrl = (serverData && serverData.practitioner_logo_url) || '';
    var pracInitials = pracName
      ? pracName.split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('')
      : 'HDL';

    // v0.22.46 \u2014 2-column page layout.
    //   LEFT col: gauge card (top), then 3 stat cards stacked.
    //   RIGHT col: AWAKEN commentary (async), then What-Happens-Next + CTAs.
    //   Hero stays full-width above the grid; practitioner footer stays
    //   full-width below it (now with a "details forwarded to {practitioner}"
    //   confirmation line above the avatar/powered row).
    //   Collapses to single column at <=900px viewport \u2014 the left (data)
    //   column lives first in source order so it shows first when stacked,
    //   matching today's "see your numbers, then read what they mean" flow.
    root.innerHTML =
        '<div class="hdlv2-s1-result">'
      + progressBar(1)

      // Hero (full width)
      + '<section class="hdlv2-s1-hero">'
      +   '<div class="hdlv2-s1-hero-row">'
      +     '<div>'
      +       '<p class="hdlv2-s1-hero-greeting">' + greet + '</p>'
      +       '<h1 class="hdlv2-s1-hero-title">Your Quick Insight</h1>'
      +     '</div>'
      +     '<span class="hdlv2-s1-hero-pill">Stage 1</span>'
      +   '</div>'
      +   '<p class="hdlv2-s1-hero-body">Here\u2019s your starting picture, built from your 9 quick answers. Next, your <em>WHY</em> \u2014 what really matters to you \u2014 turns this snapshot into a plan that\u2019s truly yours.</p>'
      + '</section>'

      // 2-column page grid
      + '<div class="hdlv2-s1-twocol">'

      // LEFT col: gauge (top) + 3 stat cards stacked
      +   '<div class="hdlv2-s1-twocol-left">'
      +     '<div class="hdlv2-s1-card hdlv2-s1-gauge-card">'
      +       '<span class="hdlv2-s1-eyebrow">\u2726 Pace of Ageing</span>'
      +       '<h2>Your Health Gauge</h2>'
      +       '<p class="hdlv2-s1-lede">A snapshot of how fast you\u2019re ageing today, based on your quick answers.</p>'
      +       '<img class="hdlv2-s1-gauge-img" src="' + gaugeUrl + '" alt="Pace of ageing gauge \u2014 ' + rate.toFixed(2) + '" width="640" height="560">'
      +     '</div>'
      +     '<div class="hdlv2-s1-stat">'
      +       '<p class="hdlv2-s1-stat-label">Rate of Ageing</p>'
      +       '<p class="hdlv2-s1-stat-value">' + rate.toFixed(2) + '\u00d7</p>'
      +       '<p class="hdlv2-s1-stat-note ' + bandClass + '">' + bandLabel + '</p>'
      +     '</div>'
      +     '<div class="hdlv2-s1-stat">'
      +       '<p class="hdlv2-s1-stat-label">Biological Age</p>'
      +       '<p class="hdlv2-s1-stat-value">' + (bio !== null ? bio.toFixed(1) + ' yrs' : '\u2014') + '</p>'
      +       '<p class="hdlv2-s1-stat-note ' + bandClass + '">' + esc(diffLabel) + '</p>'
      +     '</div>'
      +     '<div class="hdlv2-s1-stat">'
      +       '<p class="hdlv2-s1-stat-label">Chronological Age</p>'
      +       '<p class="hdlv2-s1-stat-value">' + (age > 0 ? age + ' yrs' : '\u2014') + '</p>'
      +       '<p class="hdlv2-s1-stat-note">Your real age</p>'
      +     '</div>'
      +   '</div>'

      // RIGHT col: AWAKEN commentary (async) + What Happens Next + CTAs
      +   '<div class="hdlv2-s1-twocol-right">'
      +     '<div class="hdlv2-s1-card">'
      +       '<span class="hdlv2-s1-eyebrow">\u2726 Awaken</span>'
      +       '<h2>What This Means For You</h2>'
      +       '<p class="hdlv2-s1-lede">A plain-English read of your gauge.</p>'
      +       '<div id="hdlv2-s1-commentary" class="hdlv2-s1-commentary hdlv2-s1-commentary-loading">'
      +         '<div class="hdlv2-spinner" style="margin:0 auto 14px;"></div>'
      +         '<p style="text-align:center;color:#888;font-size:13px;margin:0;">Personalising your read\u2026</p>'
      +       '</div>'
      +     '</div>'
      +     '<div class="hdlv2-s1-card">'
      +       '<h2>What Happens Next</h2>'
      +       '<p class="hdlv2-s1-lede">Your gauge is a snapshot. The next two stages turn it into a plan.</p>'
      +       '<ol class="hdlv2-s1-next-list">'
      +         '<li><strong>Stage 2 \u2014 Your WHY.</strong> Tell us what motivates you. We capture your reasons in your own words. ~10 minutes.</li>'
      +         '<li><strong>Stage 3 \u2014 Full Health Detail.</strong> Replace today\u2019s visual estimates with real measurements (body, fitness, sleep, lifestyle).</li>'
      +         '<li><strong>Your Trajectory Plan arrives.</strong> Reviewed by your practitioner, with your weekly Flight Plan to follow.</li>'
      +       '</ol>'
      // Phase 17 (v0.22.26) two CTAs preserved.
      //   Primary: continue the assessment in-line (Stage 2).
      //   Secondary: bail to the practitioner's calendar/session link
      //     (`practitioner_cta_link` from /form/load) only rendered when
      //     a link is configured, otherwise the button is hidden.
      +       '<div class="hdlv2-s1-buttons">'
      +         '<button id="hdlv2-goto-s2" class="hdlv2-s1-btn hdlv2-s1-btn-primary" type="button">Continue to Stage 2 \u2014 Your WHY \u2192</button>'
      +         '<p class="hdlv2-s1-email-note">We\u2019ve also sent this link to your email.</p>'
      +         ( pracCtaLink
              ? '<a id="hdlv2-book-session" class="hdlv2-s1-btn hdlv2-s1-btn-secondary" href="' + esc(pracCtaLink) + '" target="_blank" rel="noopener">' + esc(pracCtaText) + '</a>'
              : '' )
      +       '</div>'
      +     '</div>'
      +   '</div>'

      + '</div>' // end .hdlv2-s1-twocol

      // Practitioner footer (full width). Confirm line above the avatar row
      // explicitly tells the client which practitioner has received their data.
      // Hidden when no practitioner is attached (defensive \u2014 should never happen
      // in production since every Stage 1 client is bound to a practitioner_id).
      + '<div class="hdlv2-s1-prac-foot">'
      +   ( pracName
          ? '<p class="hdlv2-s1-prac-confirm">\u2713 Sent to your practitioner ' + esc(pracName) + '.</p>'
          : '' )
      +   '<div class="hdlv2-s1-prac-foot-row">'
      +     '<div class="hdlv2-s1-prac-foot-left">'
      +       ( pracLogoUrl
            ? '<img class="hdlv2-s1-prac-foot-logo" src="' + esc(pracLogoUrl) + '" alt="' + esc(pracName || 'Practitioner') + '">'
            : '<div class="hdlv2-s1-prac-foot-avatar">' + esc(pracInitials) + '</div>' )
      +       '<div>'
      +         '<p class="hdlv2-s1-prac-foot-name">' + esc(pracName || 'Your longevity practitioner') + '</p>'
      // v0.22.54 — dropped role line ("Your longevity practitioner") under the
      // avatar name (Quim 2026-04-28 audit B2: confirm line above already says
      // "your practitioner"). Also dropped the right-side "Powered by
      // HealthDataLab" span (audit B1: it was being doubled by the page-level
      // footer() helper rendering the same line right below — kept only the
      // page-level footer instance so brand attribution sits at the very
      // bottom of the page, not inside the practitioner-identity card).
      +       '</div>'
      +     '</div>'
      +   '</div>'
      + '</div>'

      + footer()
      + '</div>';

    document.getElementById('hdlv2-goto-s2').addEventListener('click', function(){
      // v0.40.2 — class cleanup happens inside renderStage2 via setRootClasses,
      // no manual remove needed here.
      currentStage = 2; formData = {}; loadForm();
    });

    fetchStage1Commentary();
  }

  // Async-fetch the Haiku-generated "What This Means For You" commentary.
  // Server caches the result in stage1_data.commentary_html, so a refresh
  // of /assessment/?token=... that re-renders this page incurs zero AI cost.
  function fetchStage1Commentary() {
    var box = document.getElementById('hdlv2-s1-commentary');
    if (!box) return;

    fetch(CFG.api_base + '/stage1-commentary', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ token: token })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.commentary_html) {
          box.classList.remove('hdlv2-s1-commentary-loading');
          box.innerHTML = res.commentary_html;
        } else {
          renderCommentaryFallback(box);
        }
      })
      .catch(function () { renderCommentaryFallback(box); });
  }

  function renderCommentaryFallback(box) {
    box.classList.remove('hdlv2-s1-commentary-loading');
    box.innerHTML =
        '<p>Your pace-of-ageing snapshot has been calculated from your 9 answers. '
      + 'A more detailed personalised read will be available shortly \u2014 if it doesn\u2019t appear, refresh this page.</p>'
      + '<p>This isn\u2019t a verdict \u2014 it\u2019s a starting picture. Most of these levers are within your control. Stage 2 captures your <em>why</em>, then Stage 3 turns this into your full Trajectory Plan.</p>';
  }

  // ══════════════════════════════════════════════════════════════
  //  STAGE 2: YOUR WHY
  // ══════════════════════════════════════════════════════════════

  function renderStage2(data) {
    if (data.stage2_completed_at && data.stage2_data) { renderStage2ThankYou(data); return; }
    // v0.40.2 — Stage 2 WHY form widened to 760px. Reading-heavy intro
    // + 3 questions + tall textarea read pinched at the 620px default.
    setRootClasses('is-medium');
    var d = formData;
    if (!d.key_people) d.key_people = {};

    root.innerHTML = '<div class="hdlv2-card">'+progressBar(2)
      +'<div class="hdlv2-header"><h2>Your Why</h2></div>'
      +'<div class="hdlv2-form-body">'
      // v0.36.20 — Stage 2 page restructured:
      //   1. Subtitle removed from header (title only).
      //   2. Two-column row (video card + recommendation card) replaced
      //      by a single 16:9 video placeholder. Real YouTube embed
      //      swaps in when the explainer URL is ready.
      //   3. Old "Share your WHY" block replaced with verbatim editorial
      //      copy: 4 intro paragraphs + 3 guiding question prompts.
      //      Questions are guidance, NOT separate inputs.
      //   4. Audio component textarea height doubled (CSS rule).
      //   5. ToS link added to footer (see footer() above).
      +'<div class="hdlv2-s2-video-placeholder" aria-label="Video placeholder">'
      +  '<div class="hdlv2-s2-video-placeholder-inner">'
      +    '<div class="hdlv2-s2-video-placeholder-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="#ffffff"><polygon points="8,5 20,12 8,19"/></svg></div>'
      +    '<p class="hdlv2-s2-video-placeholder-label">Video coming soon</p>'
      +  '</div>'
      +'</div>'
      // Intro prose — four editorial paragraphs of guidance.
      +'<div class="hdlv2-s2-intro-prose">'
      +  '<p>Explain in your own words why this is important. We\u2019ll use your response in your reports and weekly flight plans \u2014 and revisit it if your motivation wanes.</p>'
      +  '<p>Before starting, take a moment to reflect. Go for a walk, sit quietly with a coffee, or watch this short clip showing two contrasting examples of later life \u2014 one with good health, and one without. Return here when you\u2019re ready.</p>'
      +  '<p>It should take about 5\u201310 minutes. Please complete it in one session \u2014 saving midway isn\u2019t possible. You can type your answers or use the microphone icon to dictate.</p>'
      +  '<p>Be honest and go deep. Don\u2019t worry about repetition, perfection, or accuracy. Let your thoughts flow naturally. When dictating, you\u2019ll have the chance to review and edit before submitting.</p>'
      +'</div>'
      // Three guiding question prompts. Not separate inputs — the
      // client writes one continuous answer addressing all three.
      +'<div class="hdlv2-s2-questions">'
      +  '<div class="hdlv2-s2-question">'
      +    '<p class="hdlv2-s2-question-title">1. What you most want to avoid in your later years</p>'
      +    '<p class="hdlv2-s2-question-hint">Physical decline, loss of independence, diminished mental sharpness, becoming a burden, missing out on loved ones. Be truthful \u2014 this helps us prioritise.</p>'
      +  '</div>'
      +  '<div class="hdlv2-s2-question">'
      +    '<p class="hdlv2-s2-question-title">2. Who\u2019s important? Who do you most want to be present for?</p>'
      +    '<p class="hdlv2-s2-question-hint">Partner, children, grandchildren, great-grandchildren, close friends. The people in your life now, especially those you\u2019d like to be there for in your later decades.</p>'
      +  '</div>'
      +  '<div class="hdlv2-s2-question">'
      +    '<p class="hdlv2-s2-question-title">3. What you want your later years to actually look like</p>'
      +    '<p class="hdlv2-s2-question-hint">Visualise your eighties, nineties and beyond. What do you value most? What do you still want to do \u2014 and how do you want to spend your time?</p>'
      +  '</div>'
      +'</div>'
      // Single doubled-height textarea. Audio dictation flows its
      // transcript into this same textarea.
      +'<div class="hdlv2-field" style="margin-top:20px;">'
      +  '<div id="hdlv2-s2-audio" class="hdlv2-s2-audio-tall"></div>'
      +'</div>'
      +'<button id="hdlv2-complete-s2" class="hdlv2-btn">Submit Your WHY</button>'
      +'</div>'+saveIndicator()+footer()+'</div>';

    // Initialize audio component for open-ended WHY input
    var audioContainer = document.getElementById('hdlv2-s2-audio');
    if (audioContainer && window.HDLAudioComponent) {
      HDLAudioComponent.create(audioContainer, {
        contextType: 'why_collection',
        apiBase: CFG.api_base.replace('/form', '/audio'),
        nonce: CFG.nonce,
        token: token,
        simpleMode: true,
        // v0.17.0 — uploads go server-side via Deepgram (handles 20+ min
        // audio without the 120s browser-Whisper timeout). Live mic recording
        // still uses the browser Speech API for real-time streaming.
        // referenceId is 0/omitted — client's why_profile row doesn't exist
        // until Submit; the transcript flows into formData.vision_text via
        // onChange and the existing submit path persists it.
        asyncUpload: true,
        onChange: function(text) {
          formData.vision_text = text;
        },
        onConfirm: function(summary) {
          formData.vision_text = summary;
          formData.audio_summary = summary;
          if(saveTimer) clearTimeout(saveTimer);
          autoSave(2);
        }
      });
      // Sync textarea directly to formData so typing works without clicking Process
      var acTextarea = audioContainer.querySelector('.hdlv2-ac-text');
      if (acTextarea) {
        if (formData.vision_text) acTextarea.value = formData.vision_text;
        acTextarea.addEventListener('input', function() {
          formData.vision_text = acTextarea.value;
        });
        acTextarea.addEventListener('blur', function() {
          formData.vision_text = acTextarea.value;
          if(saveTimer) clearTimeout(saveTimer);
          saveTimer = setTimeout(function(){ autoSave(2); }, 2000);
        });
      }
    }

    bindFieldListeners();
    document.getElementById('hdlv2-complete-s2').addEventListener('click', completeStage2);
  }

  function completeStage2() {
    // Read from visible textarea as backup (covers transcript review state)
    var visibleTa = document.querySelector('.hdlv2-ac-text');
    if (visibleTa && visibleTa.value.trim()) {
      formData.vision_text = visibleTa.value.trim();
    }

    if (!formData.vision_text || formData.vision_text.trim().length < 10) {
      setSaveStatus('error', 'Please share your thoughts \u2014 type or use the audio recorder');
      return;
    }

    var btn = document.getElementById('hdlv2-complete-s2');
    btn.disabled = true; btn.textContent = 'Saving...';

    // Save with submitted:true — do NOT call /complete-stage (that endpoint is
    // the practitioner gate; the practitioner triggers the Stage 3 release).
    // v0.46.20 (F7/F8/F9) — the {stage:2, submitted:true} save now also marks
    // Stage 2 complete server-side (stamps stage2_completed_at), notifies the
    // practitioner, and — when Make.com is absent — runs the WHY extraction
    // inline so the practitioner's Release button appears promptly. All three
    // are idempotent (one-shot on the completion transition), so re-submitting
    // is safe. This is what makes a returning client land on the result page
    // (renderStage2 gate) instead of the blank intake form.
    fetch(CFG.api_base+'/save',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token,stage:2,data:formData,submitted:true})})
      .then(function(r){return r.json();})
      .then(function(res){
        if(res.success){ renderStage2ThankYou(serverData); }
        else{btn.disabled=false;btn.textContent='Submit Your WHY';setSaveStatus('error',res.message||'Could not save. Please try again.');}
      }).catch(function(){btn.disabled=false;btn.textContent='Submit Your WHY';setSaveStatus('error','Connection error');});
  }

  // v0.46.26 — Stage 2 now shows a thank-you card (mirrors the Stage 3
  // renderThankYou) instead of the on-screen "Your Why" result. The WHY
  // distillation still runs server-side — Make module [67], or the inline
  // extract_why fallback when Make.com is absent — for the practitioner brief
  // and the Stage-2 PDF; it is simply no longer surfaced to the client here.
  function renderStage2ThankYou(data) {
    var s1        = data || serverData || {};
    var fullName  = s1.client_name || (s1.stage1_data && s1.stage1_data.name) || '';
    var email     = s1.client_email || (s1.stage1_data && s1.stage1_data.email) || '';
    var firstName = deriveFirstName(fullName, email);

    var pracName  = s1.practitioner_name || 'your practitioner';
    var pracEmail = s1.practitioner_email || '';
    var pracLogo  = s1.practitioner_logo_url || '';

    // Content-narrow single card, same as the Stage 3 thank-you.
    setRootClasses('');

    // v0.46.57 (P4 — A1 reword) — copy now mirrors the Make [84] client
    // email ("Answers Stage 2: Your Why") so screen and inbox say the same
    // thing. The old copy promised a "WHY summary" email — [84] carries no
    // summary (the brief is practitioner-only), so that promise is gone.
    var pracFirst = s1.practitioner_name ? s1.practitioner_name.split(/\s+/)[0] : '';
    var leadLine  = 'What you shared is now saved with your assessment. Your practitioner'
      + (s1.practitioner_name ? ', ' + esc(s1.practitioner_name) + ',' : '')
      + ' will read it with care and unlock your next step shortly.';
    var noteLine  = pracEmail
      ? 'Questions? Email <a href="mailto:' + esc(pracEmail) + '" class="hdlv2-ty-link">'
        + esc(pracEmail) + '</a> — ' + (pracFirst ? esc(pracFirst) : 'your practitioner') + ' will see it.'
      : '';

    root.innerHTML = '<div class="hdlv2-card hdlv2-ty-card">'
      + thankYouHeader(pracName, pracLogo)
      + '<div class="hdlv2-ty-banner"><h1>Stage&nbsp;2&nbsp;·&nbsp;Your&nbsp;WHY</h1></div>'
      + '<div class="hdlv2-ty-body">'
      +   '<h2 class="hdlv2-ty-h1">Thank you for sharing, ' + esc(firstName) + '.</h2>'
      +   '<p class="hdlv2-ty-lead">' + leadLine + '</p>'
      +   (noteLine ? '<p class="hdlv2-ty-note">' + noteLine + '</p>' : '')
      + '</div>'
      + thankYouFooter()
      + '</div>';
  }

  // ══════════════════════════════════════════════════════════════
  //  STAGE 3: FULL DETAIL (WIZARD MODE)
  // ══════════════════════════════════════════════════════════════

  function renderStage3(data) {
    if (data.stage3_completed_at && data.stage3_data && data.stage3_data.server_result) {
      // v0.39.0 — Stage 3 already completed → render the Thank-You confirmation
      // inline. Replaces the legacy redirect to /longevity-draft-report/ (heavy
      // browser draft view retired for clients; email + PDF is the only client-
      // facing report artefact). Practitioners still get the rich view via their
      // consultation page, which mounts hdlv2-draft-report.js itself.
      renderThankYou(data);
      return;
    }
    // v0.40.2 — Stage 3 wizard widened to 760px. Multi-section form with
    // paired body-measurement / cardio / fitness fields reads cramped at
    // 620px (Section 1's "Body shape" + "Cardiovascular" rows benefit
    // from breathing room).
    setRootClasses('is-medium');
    // physicalActivity is now collected by the user in the fitness section
    // (with Stage 1 q3 pre-fill via S1_TO_S3_DEFAULTS), so no preamble mapping
    // is needed here. The legacy hack that wrote formData.physicalActivity
    // straight from q3 was removed in v0.22.23 — s1Prefill() handles it.
    wizardSection = 0;
    renderWizardSection(data);
  }

  function renderWizardSection(data) {
    // v0.40.2 — defensive idempotent set (renderStage3 sets it on entry, but
    // wizard sections may also be re-mounted directly during navigation).
    setRootClasses('is-medium');
    var s1 = data || serverData;
    var s1d = (s1 && s1.stage1_data) || {};
    var d = formData;
    var sec = S3_SECTIONS[wizardSection];
    var total = S3_SECTIONS.length;

    // Section content
    var content = '';
    if (sec.id === 'body') {
      // v0.38.0 \u2014 Section 1 regrouped per spec:
      //   Row 1 (Body shape):    Height \u00b7 Weight \u00b7 Waist \u00b7 Hip   (with waist/hip tooltips)
      //   Row 2 (Cardiovascular): Resting HR \u00b7 Systolic BP \u00b7 Diastolic BP   (with shared helper line)
      // Tooltip text ported verbatim from V1 longevity-form-raw.php.
      // Sex display title-cased (was lower-case "male" from Stage 1 q1_sex).
      var sexRaw = s1d.q1_sex || s1d.gender || '';
      var sexDisplay = sexRaw.replace(/\b\w/g, function (c) { return c.toUpperCase(); });

      content = '<div class="hdlv2-field-grid cols-2">'
        + fieldReadonly('Age', s1d.q1_age || s1d.age, 'years')
        + fieldReadonly('Sex', sexDisplay, '')
        + '</div>'
        + prefillHint()
        + '<h5 class="hdlv2-subgroup-heading">Body shape</h5>'
        + '<div class="hdlv2-field-grid cols-2">'
        + fieldInputWithSkip('height', 'Height (cm)', 'number', d.height, '170')
        + fieldInputWithSkip('weight', 'Weight (kg)', 'number', d.weight, '75')
        + '</div><div class="hdlv2-field-grid cols-2">'
        + fieldInputWithSkip('waist', 'Waist (cm)', 'number', d.waist, '85', {
            tooltip: 'Measure around your waist at the level of your belly button. Keep the tape measure horizontal.'
          })
        + fieldInputWithSkip('hip', 'Hip (cm)', 'number', d.hip, '95', {
            tooltip: 'Measure around the widest part of your hips. Keep the tape measure horizontal.'
          })
        + '</div>'
        + '<h5 class="hdlv2-subgroup-heading">Cardiovascular</h5>'
        + '<div class="hdlv2-field-grid cols-3">'
        + fieldInputWithSkip('restingHeartRate', 'Resting Heart Rate (bpm)', 'number', d.restingHeartRate, '70')
        + fieldInputWithSkip('bpSystolic', 'Systolic BP (mmHg)', 'number', d.bpSystolic, '120')
        + fieldInputWithSkip('bpDiastolic', 'Diastolic BP (mmHg)', 'number', d.bpDiastolic, '80')
        + '</div>'
        + '<p class="hdlv2-field-hint">Best measured in the morning, sat down, before caffeine. Use an average of 2\u20133 recent readings if you have them.</p>';
        // v0.23.1 \u2014 Overall Health Rating slider removed (Matthew 2026-04-28).
    } else if (sec.id === 'fitness') {
      // v0.20.7 — use per-field S3_OPTIONS so answer choices match the
      // question (reps for sit-to-stand, seconds for breath-hold, etc.)
      // instead of the generic Poor/Average/Excellent scale. Matthew flagged
      // this during 2026-04-23 walkthrough: "sit to stand and then average
      // poor sometimes those answers don't make sense for sit to stand".
      //
      // v0.22.23 — Pre-fill physicalActivity from Stage 1 q3 (Zone 2 Activity)
      // if not already answered (see S1_TO_S3_DEFAULTS). Field leads the
      // section because it carries weight 1.0 in calculate_full() — equal to
      // bloodPressure, only smokingStatus is heavier.
      // v0.38.0 — Tooltips ported from V1 longevity-form-raw.php so the
      // client knows the protocol for each physical test (chair height,
      // breath-hold posture, one-leg balance, pinch-and-snap-back).
      var fitnessPrefilled = !d.physicalActivity && !!s1d.q3;
      content = (fitnessPrefilled ? prefillHint() : '')
        + '<div class="hdlv2-field-grid">'
        + fieldSelect('physicalActivity','Physical Activity Level',S3_OPTIONS.physicalActivity, s1Prefill('physicalActivity', s1d, d.physicalActivity))
        + '</div><div class="hdlv2-field-grid cols-2">'
        + fieldSelect('sitToStand','Sit-to-Stand Test',S3_OPTIONS.sitToStand,d.sitToStand, {
            tooltip: 'Using a standard chair (about 43 cm seat height, no armrests), sit with feet flat on the floor and arms crossed on your shoulders. On "go", stand up fully and sit back down as many times as possible in 30 seconds. Count only complete reps. Measures lower-body strength.'
          })
        + fieldSelect('breathHold','Breath Hold Test',S3_OPTIONS.breathHold,d.breathHold, {
            tooltip: 'After a normal exhale, hold your breath for as long as comfortable. Use a stopwatch. Take your best safe attempt — don’t push past discomfort.'
          })
        + '</div><div class="hdlv2-field-grid cols-2">'
        + fieldSelect('balance','Balance Test',S3_OPTIONS.balance,d.balance, {
            tooltip: 'Stand on one leg without support and time how long you can hold it. Use a stopwatch. Take your best attempt. Stand near something for safety in case you wobble.'
          })
        + fieldSelect('skinElasticity','Skin Elasticity',S3_OPTIONS.skinElasticity,d.skinElasticity, {
            tooltip: 'Gently pinch the skin on the back of your hand for 2 seconds, release, and time how long it takes to flatten back. Use a stopwatch.'
          })
        + '</div>';
    } else if (sec.id === 'sleep') {
      // Pre-filled from Stage 1 q6 if not already answered (see S1_TO_S3_DEFAULTS).
      var sleepPrefilled = !d.sleepDuration && !!s1d.q6;
      content = (sleepPrefilled ? prefillHint() : '')
        + '<div class="hdlv2-field-grid cols-2">'
        + fieldSelect('sleepDuration','Sleep Duration',S3_OPTIONS.sleepDuration, s1Prefill('sleepDuration', s1d, d.sleepDuration))
        + fieldSelect('sleepQuality','Sleep Quality',S3_OPTIONS.sleepQuality, s1Prefill('sleepQuality', s1d, d.sleepQuality))
        + '</div>';
    } else if (sec.id === 'mental') {
      // socialConnections pre-filled from Stage 1 q8 if not already answered.
      var mentalPrefilled = !d.socialConnections && !!s1d.q8;
      content = (mentalPrefilled ? prefillHint() : '')
        + '<div class="hdlv2-field-grid cols-3">'
        + fieldSelect('stressLevels','Stress Levels',S3_OPTIONS.stressLevels,d.stressLevels)
        + fieldSelect('socialConnections','Social Connections',S3_OPTIONS.socialConnections, s1Prefill('socialConnections', s1d, d.socialConnections))
        // v0.38.0 — Hint per spec: name examples so clients know what counts as cognitive activity.
        + fieldSelect('cognitiveActivity','Cognitive Activity',S3_OPTIONS.cognitiveActivity,d.cognitiveActivity, {
            hint: 'e.g. reading, learning new skills, puzzles, problem-solving at work.'
          })
        + '</div>';
    } else if (sec.id === 'lifestyle') {
      // dietQuality + smokingStatus pre-filled from Stage 1 q7/q9 if not already answered.
      var lifestylePrefilled = (!d.dietQuality && !!s1d.q9) || (!d.smokingStatus && !!s1d.q7);
      content = (lifestylePrefilled ? prefillHint() : '')
        + '<div class="hdlv2-field-grid cols-3">'
        + fieldSelect('dietQuality','Diet Quality',S3_OPTIONS.dietQuality, s1Prefill('dietQuality', s1d, d.dietQuality))
        + fieldSelect('alcoholConsumption','Alcohol',S3_OPTIONS.alcoholConsumption,d.alcoholConsumption)
        + fieldSelect('smokingStatus','Smoking',S3_OPTIONS.smokingStatus, s1Prefill('smokingStatus', s1d, d.smokingStatus))
        + '</div><div class="hdlv2-field-grid cols-3">'
        + fieldSelect('supplementIntake','Supplements',S3_OPTIONS.supplementIntake,d.supplementIntake) + fieldSelect('sunlightExposure','Sunlight',S3_OPTIONS.sunlightExposure,d.sunlightExposure) + fieldSelect('dailyHydration','Hydration',S3_OPTIONS.dailyHydration,d.dailyHydration)
        + '</div>';
    } else if (sec.id === 'background') {
      // v0.38.0 — Health Background. Three optional long-form text fields.
      // Stored in stage3_data (passes through array_merge in rest_save_form).
      // NOT consumed by the rate calculator — scores are unaffected.
      // Surfaced to the practitioner in the consultation page (new Background
      // group in renderHealthFields) and woven into the Stage 3 draft Claude
      // prompt (HDLV2_Context_Builder).
      content = '<div class="hdlv2-section-intro">We’ll go through these in detail at your consultation — no need to write much here. Just the basics you can think of off the top of your head.</div>'
        + '<div class="hdlv2-field-grid">'
        + fieldTextarea('family_history', 'Family history', d.family_history, 'e.g. parent or sibling with heart disease, cancer, diabetes, dementia. Parents’ age (or age at death) if you know it.', 600)
        + '</div><div class="hdlv2-field-grid">'
        + fieldTextarea('medications', 'Current medications', d.medications, 'Anything you take regularly.', 400)
        + '</div><div class="hdlv2-field-grid">'
        + fieldTextarea('existing_conditions', 'Existing conditions / diagnoses', d.existing_conditions, 'Anything ongoing you’d want me to know.', 600)
        + '</div>';
    }

    // Wizard progress dots
    var dots = '<div class="hdlv2-wizard-progress">';
    for (var i = 0; i < total; i++) {
      dots += '<span class="hdlv2-wizard-dot' + (i < wizardSection ? ' done' : i === wizardSection ? ' active' : '') + '">' + (i + 1) + '</span>';
    }
    dots += '</div>';

    var isFirst = wizardSection === 0;
    var isLast = wizardSection === total - 1;

    // v0.38.0 \u2014 Stage 3 wizard chrome trimmed per spec:
    //   - Drop the 3-step progressBar (Quick Insight / Your WHY / Full Detail).
    //     The 1-N dots below are sufficient orientation.
    //   - Drop the "Section X of N" sub-line (dots convey position).
    //   - Title now reads "More Health Details \u2014 Stage 3" (was "Full Health Detail").
    //   - Skip helper line standardised: same phrasing on every section.
    root.innerHTML = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>More Health Details \u2014 Stage 3</h2><p>' + esc(sec.title) + '</p></div>'
      + dots
      + (isFirst ? '<div class="hdlv2-wizard-info">Don\u2019t worry if you don\u2019t know the details. Just do your best. Your practitioner or the report can still be generated if you haven\u2019t got all these details.</div>' : '')
      + '<div class="hdlv2-form-body">' + content
      + '<p class="hdlv2-wizard-skip-note">Don\u2019t know or not sure? That\u2019s fine \u2014 skip what you don\u2019t know and keep going.</p>'
      + '<div class="hdlv2-wizard-nav">'
      + (isFirst ? '<span></span>' : '<button type="button" id="hdlv2-wizard-prev" class="hdlv2-nav-back" aria-label="Go back to previous section">&#8592; Previous</button>')
      + (isLast ? '<button id="hdlv2-complete-s3" class="hdlv2-btn" style="width:auto;padding:13px 32px;">Complete Assessment &#10003;</button>' : '<button type="button" id="hdlv2-wizard-next" class="hdlv2-btn" style="width:auto;padding:13px 32px;">Next &#8594;</button>')
      + '</div></div>' + saveIndicator() + footer() + '</div>';

    bindFieldListeners();
    // v0.38.0 — bindSkipCheckboxes() removed; "I don't know" checkbox dropped
    // from numeric inputs per spec. The single section-level helper line now
    // covers skip semantics. Stored 'skip' values still load (back-compat).
    bindRangeSlider();

    if (!isFirst) document.getElementById('hdlv2-wizard-prev').addEventListener('click', function(){ wizardSection--; renderWizardSection(data); root.scrollIntoView({behavior:'smooth'}); });
    if (isLast) document.getElementById('hdlv2-complete-s3').addEventListener('click', function(){ completeStage3(data); });
    else document.getElementById('hdlv2-wizard-next').addEventListener('click', function(){ autoSave(3); wizardSection++; renderWizardSection(data); root.scrollIntoView({behavior:'smooth'}); });
  }

  // v0.38.0 — bindSkipCheckboxes() removed: numeric "I don't know"
  // checkbox dropped from fieldInputWithSkip(). Section-level helper line
  // ("Don't know or not sure? That's fine…") now signals the same.
  // Stored 'skip' values from pre-v0.38 rows still load via
  // fieldInputWithSkip's existing back-compat (renders empty input).

  function bindRangeSlider() {
    // v0.24.9 — body removed. The overallHealthPercent slider was retired
    // in v0.23.1 (Matthew 2026-04-28); this wrapper stayed as a no-op and
    // is now empty. Kept as a stub so existing call sites don't crash.
  }

  function completeStage3(data) {
    // All fields are optional (skip allowed) — just ensure stage data exists
    var btn = document.getElementById('hdlv2-complete-s3');
    btn.disabled = true; btn.textContent = 'Submitting…';

    fetch(CFG.api_base+'/save',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token,stage:3,data:formData})})
      .then(function(){return fetch(CFG.api_base+'/complete-stage',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token,stage:3})});})
      .then(function(r){return r.json();})
      .then(function(res){
        if (res.success && res.result) {
          // v0.39.0 — Stage 3 submit flow rebuilt. The heavy browser draft
          // report view is retired for clients; the Make.com-delivered email
          // + PDF is the only client-facing report artefact at this stage.
          //
          // Server-side belt-and-braces in rest_complete_stage already fires
          // the /generate-report worker non-blocking (class-hdlv2-staged-form.php
          // line ~466). The JS fire-and-forget call below provides double-
          // redundancy via the idempotency_key (5-min cache dedup guarantees a
          // single Claude burn + single Make.com webhook fire even if both
          // workers race to claim).
          //
          // We don't wait for the response — Thank-You renders immediately so
          // the client sees acknowledgement instead of a 20-45s spinner. PDF
          // email arrives a couple of minutes later.
          fetch(CFG.api_base+'/generate-report', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},
            body: JSON.stringify({token:token, idempotency_key:'gen-'+token}),
            keepalive: true
          }).catch(function(){ /* swallow — server-side fire-and-forget is primary */ });

          renderThankYou(data || serverData);
        } else {
          btn.disabled=false; btn.textContent='Complete Assessment \u2713';
          setSaveStatus('error', res.message||'Please try again');
        }
      }).catch(function(){btn.disabled=false;btn.textContent='Complete Assessment \u2713';setSaveStatus('error','Connection error');});
  }

  function showReportLoading() {
    // v0.37.0 — replaces a static spinner-on-blank with a progress-bar
    // illusion + rotating step ladder so the 20-45s Claude wait feels
    // active. The bar fills asymptotically toward 92%, snaps to 100%
    // only when /generate-report responds (or moot — we redirect to
    // /longevity-draft-report/ before that frame is visible).
    //
    // v0.39.0 — Kept as a stub for any path that might still call it
    // (none today; completeStage3 now renders Thank-You directly). The
    // legacy "Generating your personalised draft report" loading screen
    // is retired alongside the heavy browser draft view.
  }

  // ────────────────────────────────────────────────────────────────────
  //  THANK-YOU (Stage 3 submit + revisit) — v0.39.0
  //
  //  Replaces the legacy in-browser heavy draft report view. The client
  //  sees a concise confirmation page; the canonical report artefact is
  //  the Make.com-delivered email + PDF attachment. Visual shell mirrors
  //  the email layout exactly — HDL logo left + practitioner block right
  //  + teal banner + body + standard footer — so the in-browser ack feels
  //  like a continuation of the brand experience the email reinforces.
  // ────────────────────────────────────────────────────────────────────
  function renderThankYou(data) {
    var s1        = data || serverData || {};
    var fullName  = s1.client_name || (s1.stage1_data && s1.stage1_data.name) || '';
    var email     = s1.client_email || (s1.stage1_data && s1.stage1_data.email) || '';
    var firstName = deriveFirstName(fullName, email);

    var pracName  = s1.practitioner_name || 'your practitioner';
    var pracEmail = s1.practitioner_email || '';
    var pracLogo  = s1.practitioner_logo_url || '';

    // v0.40.2 — Thank-You page is content-narrow (single card with copy).
    // Reset to default 620px (clears both is-wide and is-medium if set).
    setRootClasses('');

    var noteLine  = pracEmail
      ? 'If you don’t see the email within 1 hour, check your spam folder or contact <a href="mailto:'
        + esc(pracEmail) + '" class="hdlv2-ty-link">' + esc(pracEmail) + '</a>.'
      : 'If you don’t see the email within 1 hour, check your spam folder.';

    root.innerHTML = '<div class="hdlv2-card hdlv2-ty-card">'
      + thankYouHeader(pracName, pracLogo)
      + '<div class="hdlv2-ty-banner"><h1>Stage&nbsp;3&nbsp;complete</h1></div>'
      + '<div class="hdlv2-ty-body">'
      +   '<h2 class="hdlv2-ty-h1">Thanks, ' + esc(firstName) + ' — your assessment is in.</h2>'
      +   '<p class="hdlv2-ty-lead">We’ve just sent your draft report to your email. It should arrive within a few minutes.</p>'
      +   '<p class="hdlv2-ty-subhead">What happens next</p>'
      +   '<ol class="hdlv2-ty-list">'
      +     '<li>Check your inbox for your Draft Trajectory Plan.</li>'
      +     '<li>If you haven’t already done so, please book your consultation with your practitioner.</li>'
      +     '<li>Your final personalised report follows after that consultation.</li>'
      +   '</ol>'
      +   '<p class="hdlv2-ty-note">' + noteLine + '</p>'
      + '</div>'
      + thankYouFooter()
      + '</div>';
  }

  // v0.46.29 — Thank-you card header now carries the practitioner attribution
  // ONLY. The redundant in-card HealthDataLab logo was removed: the page
  // (Divi site header) already brands HDL, so a second logo here merely
  // duplicated it. Left-aligned with a small "Your practitioner" eyebrow so
  // the lone name reads as deliberate attribution. A practitioner's own
  // custom logo (when set, and not the HDL fallback) still renders above
  // their name. Shared by both renderStage2ThankYou and renderThankYou.
  function thankYouHeader(pracName, logoUrl) {
    var attr;
    // Mirror the Make.com IML conditional: if the URL is a real http(s)
    // address AND is not the HDL fallback PNG, render the practitioner's
    // own logo above their name. Otherwise render the name only.
    var hasCustom = logoUrl
      && logoUrl.indexOf('://') !== -1
      && logoUrl.indexOf('HDL-Logo-2309') === -1;
    if (hasCustom) {
      attr = '<img src="' + esc(logoUrl) + '" alt="' + esc(pracName) + '" class="hdlv2-ty-prac-logo" />'
        +    '<span class="hdlv2-ty-prac-name">' + esc(pracName) + '</span>';
    } else {
      attr = '<span class="hdlv2-ty-prac-name-only">' + esc(pracName) + '</span>';
    }
    return '<div class="hdlv2-ty-header hdlv2-ty-header--prac">'
      +   '<div class="hdlv2-ty-prac-attr">'
      +     '<span class="hdlv2-ty-prac-label">Your practitioner</span>'
      +     attr
      +   '</div>'
      + '</div>';
  }

  function thankYouFooter() {
    return '<div class="hdlv2-ty-footer">'
      +   'Powered by <strong>HealthDataLab</strong> · '
      +   '<a href="https://healthdatalab.com/terms" target="_blank" rel="noopener">Terms</a> · '
      +   '<a href="https://healthdatalab.com/privacy" target="_blank" rel="noopener">Privacy</a>'
      + '</div>';
  }

  // Mirror PHP HDLV2_Email_Templates::derive_first_name — return 'there'
  // for empty / email-shaped / email-equal / digit-only-suffix inputs.
  function deriveFirstName(name, email) {
    if (!name || typeof name !== 'string') return 'there';
    name = name.trim();
    if (!name) return 'there';
    if (name.indexOf('@') !== -1) return 'there';
    if (email && name.toLowerCase() === String(email).toLowerCase()) return 'there';
    var first = name.split(/\s+/)[0];
    // Strip trailing digit suffix ("Quim123" → "Quim")
    first = first.replace(/\d+$/, '');
    return first || 'there';
  }


    // v0.20.7 \u2014 renderDraftReport() and renderStage3Result() removed.
  // Both were legacy inline renderers for the post-Stage-3 state. Stage 3
  // completion now redirects to /longevity-draft-report/?t=<token> (the new
  // layout), and re-visiting a completed token redirects the same way
  // (see renderStage3 above). The single source of truth for report
  // presentation is hdlv2-draft-report.js on the dedicated report page.

  // ══════════════════════════════════════════════════════════════
  //  FIELD HELPERS
  // ══════════════════════════════════════════════════════════════

  function fieldInput(name, label, type, value, placeholder) {
    return '<div class="hdlv2-field"><label for="hdlv2-f-'+name+'">'+label+'</label>'
      +'<input id="hdlv2-f-'+name+'" data-field="'+name+'" type="'+type+'" value="'+esc(value||'')+'"'
      +(placeholder?' placeholder="'+esc(placeholder)+'"':'')
      +(type==='number'?' min="0" step="any" inputmode="decimal"':'')
      +'></div>';
  }

  // v0.38.0 \u2014 "I don't know" checkbox removed. The section-level helper
  // line ("Don't know or not sure? That's fine\u2026") now covers skip
  // semantics. Pre-existing rows that stored 'skip' render as an empty
  // input (back-compat); the calculator already treats null/empty
  // identically to 'skip' via clamp_score().
  function fieldInputWithSkip(name, label, type, value, placeholder, opts) {
    opts = opts || {};
    var isSkipped = value === 'skip';
    var tooltip   = opts.tooltip ? renderTooltip(opts.tooltip) : '';
    return '<div class="hdlv2-field"><label for="hdlv2-f-'+name+'">'+label+tooltip+'</label>'
      +'<input id="hdlv2-f-'+name+'" data-field="'+name+'" type="'+type+'" value="'+esc(isSkipped?'':value||'')+'"'
      +(placeholder?' placeholder="'+esc(placeholder)+'"':'')
      +(type==='number'?' min="0" step="any" inputmode="decimal"':'')
      +'></div>';
  }

  // Inline info-icon tooltip \u2014 V1 protocol text ported to Stage 3.
  // Hover on desktop, tap to toggle on mobile (CSS-only via :focus-within).
  function renderTooltip(text) {
    return ' <span class="hdlv2-tooltip-trigger" tabindex="0" aria-label="What this means">'
      + '<span class="hdlv2-tooltip-bubble" role="tooltip">' + esc(text) + '</span>'
      + '</span>';
  }

  function fieldSelect(name, label, options, value, opts) {
    opts = opts || {};
    // If a per-field answer set exists in S3_OPTIONS, prefer it over the
    // generic SCORE_OPTIONS passed in. This lets all existing call sites
    // (renderWizardSection) stay unchanged while the client sees the correct
    // per-question anchors (0 reps / 30s / 1-2L / etc).
    var optList = ( S3_OPTIONS && S3_OPTIONS[ name ] ) ? S3_OPTIONS[ name ] : options;
    var tooltip = opts.tooltip ? renderTooltip(opts.tooltip) : '';
    var hint    = opts.hint    ? '<p class="hdlv2-field-hint">' + esc(opts.hint) + '</p>' : '';
    var h = '<div class="hdlv2-field"><label for="hdlv2-f-'+name+'">'+label+tooltip+'</label><select id="hdlv2-f-'+name+'" data-field="'+name+'">';
    optList.forEach(function(o){ h += '<option value="'+esc(o.v)+'"'+(String(o.v)===String(value||'')?'selected':'')+'>'+esc(o.l)+'</option>'; });
    return h + '</select>' + hint + '</div>';
  }

  function fieldTextarea(name, label, value, placeholder, maxlength) {
    // v0.47.15 \u2014 a ticked "None (to my knowledge)" stores NONE_SENTINEL as the
    // field value; render that as the checkbox checked + an empty, disabled
    // textarea so the state round-trips on reload / section re-render.
    var isNone = (value === NONE_SENTINEL);
    var taVal  = isNone ? '' : (value || '');
    return '<div class="hdlv2-field"><label for="hdlv2-f-'+name+'">'+label+'</label>'
      +'<textarea id="hdlv2-f-'+name+'" data-field="'+name+'" placeholder="'+esc(placeholder||'')+'"'
      +(maxlength?' maxlength="'+maxlength+'"':'')+' rows="4"'+(isNone?' disabled':'')+'>'+esc(taVal)+'</textarea>'
      +'<div class="hdlv2-field-foot">'
      +'<label class="hdlv2-none-toggle"><input type="checkbox" class="hdlv2-none-check" data-none-for="'+name+'"'+(isNone?' checked':'')+'><span>None (to my knowledge)</span></label>'
      +'<div class="hdlv2-char-count" id="hdlv2-charcount-'+name+'">'+(taVal?taVal.length:0)+' / '+(maxlength||'\u221E')+'</div>'
      +'</div></div>';
  }

  function fieldCheckboxGroup(name, label, options, selectedValues) {
    var sel = Array.isArray(selectedValues) ? selectedValues : [];
    var h = '<div class="hdlv2-field"><label>'+label+'</label><div class="hdlv2-checkbox-grid" data-field="'+name+'">';
    options.forEach(function(opt){
      var checked = sel.indexOf(opt) !== -1 ? ' checked' : '';
      var id = 'hdlv2-cb-'+name+'-'+opt.toLowerCase().replace(/[^a-z0-9]/g,'');
      h += '<label class="hdlv2-checkbox-item" for="'+id+'"><input type="checkbox" id="'+id+'" value="'+esc(opt)+'"'+checked+'><span class="hdlv2-checkbox-label">'+esc(opt)+'</span></label>';
    });
    return h + '</div></div>';
  }

  function fieldRange(name, label, value, min, max) {
    var val = (value !== undefined && value !== '' && value !== null) ? value : Math.round((max-min)/2+min);
    return '<div class="hdlv2-field"><label for="hdlv2-f-'+name+'">'+label+'</label>'
      +'<div class="hdlv2-range-wrap"><input id="hdlv2-f-'+name+'" data-field="'+name+'" type="range" min="'+min+'" max="'+max+'" value="'+esc(val)+'">'
      +'<span class="hdlv2-range-value" id="hdlv2-rv-'+name+'">'+esc(val)+'</span></div></div>';
  }

  function fieldReadonly(label, value, unit) {
    return '<div class="hdlv2-field"><label>'+label+'</label><div class="hdlv2-readonly-value">'+esc(value!=null?value:'\u2014')+(unit?' '+esc(unit):'')+'</div></div>';
  }

  // Inline hint shown above sections that auto-pre-filled values from
  // Stage 1. Calm, no icon, matching the existing wizard-info copy block
  // style (small, low-contrast, single sentence).
  // v0.38.0 \u2014 Copy standardised per spec ("we could", "complete the rest").
  function prefillHint() {
    return '<p class="hdlv2-prefill-hint">'
      + 'We\u2019ve pre-filled what we could from your Stage 1 check. Please complete the rest.'
      + '</p>';
  }

  function esc(s) { var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML.replace(/"/g,'&quot;'); }

  // ── GOOGLE FONTS ──
  if (!document.getElementById('hdlv2-form-fonts')) {
    var link = document.createElement('link');
    link.id = 'hdlv2-form-fonts'; link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap';
    document.head.appendChild(link);
  }

  // ── START ──
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
