/**
 * HDL V2 Staged Assessment Form — Stages 1, 2, 3
 *
 * Token-based multi-stage longevity assessment.
 * Stage 1: Quick Insight (6 fields, gauge result)
 * Stage 2: Your WHY (structured key people, motivations, vision text)
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
  var saving = false;
  var serverData = {};
  var wizardSection = 0;

  // ── CONSTANTS ──
  var SCORE_OPTIONS = [
    { v: '', l: 'Select...' },
    { v: '0', l: 'Very Poor / Not at all' },
    { v: '1', l: 'Poor / Rarely' },
    { v: '2', l: 'Below Average / Sometimes' },
    { v: '3', l: 'Average / Moderate' },
    { v: '4', l: 'Good / Often' },
    { v: '5', l: 'Excellent / Very Often' },
    { v: 'skip', l: "I don't know" }
  ];


  // Stage 1 wizard state
  var s1Step = 0;
  var S1_TOTAL_STEPS = 10; // q1, q2a, q2b, q3-q9

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
    q3: { title:'Zone 2 Activity', text:'How much steady, moderate-effort activity do you do in a typical week?',
      hint:'Think: brisk walking, easy cycling, swimming at a comfortable pace, gardening \u2014 anything where you could hold a conversation but you\u2019re definitely moving.',
      opts:[{v:'a',t:'Almost none \u2014 I\u2019m mostly sedentary'},{v:'b',t:'About 30\u201360 minutes per week'},{v:'c',t:'About 1\u20132 hours per week'},{v:'d',t:'About 2\u20134 hours per week'},{v:'e',t:'More than 4 hours per week'}] },
    q4: { title:'Cardiovascular Capacity', text:'Imagine you\u2019re in a building with no lift. How would you handle climbing stairs?',
      cite:'Stair climbing validated as a predictor of cardiovascular fitness (Peteiro et al., 2020 \u2014 94% sensitivity).',
      opts:[{v:'a',t:'One flight is difficult \u2014 I\u2019d need to stop and rest'},{v:'b',t:'I can walk up 2\u20133 flights but I\u2019d be noticeably out of breath'},{v:'c',t:'I can walk up 4\u20135 flights at a steady pace without stopping'},{v:'d',t:'I can walk up 5+ flights comfortably, or jog up 3\u20134 flights'},{v:'e',t:'I could run up 4\u20135 flights without significant difficulty'}] },
    q5: { title:'Functional Strength', text:'Imagine sitting cross-legged on the floor. How easily could you get back up to standing?',
      cite:'Based on the Sitting-Rising Test (Dr Claudio Gil Ara\u00fajo). Each point scored reduced mortality risk by 21% over 6 years.',
      opts:[{v:'a',t:'I couldn\u2019t get down, or I\u2019d need someone to help me back up'},{v:'b',t:'I\u2019d need furniture or both hands and a knee on the ground'},{v:'c',t:'I\u2019d use one hand or one knee for a bit of support'},{v:'d',t:'I could do it without support, but it takes effort'},{v:'e',t:'I can sit down and stand back up smoothly, no hands'}] },
    q6: { title:'Sleep', text:'On a typical night, how would you describe your sleep?',
      opts:[{v:'a',t:'Fewer than 5 hours, or I struggle most nights'},{v:'b',t:'About 5\u20136 hours, or I wake frequently and don\u2019t feel rested'},{v:'c',t:'About 6\u20137 hours, reasonable quality'},{v:'d',t:'About 7\u20138 hours, good quality \u2014 I usually wake feeling rested'},{v:'e',t:'I sleep 9+ hours but still feel tired'}] },
    q7: { title:'Smoking', text:'What\u2019s your smoking status?',
      opts:[{v:'a',t:'I smoke daily'},{v:'b',t:'I smoke occasionally or socially'},{v:'c',t:'I quit within the last 5 years'},{v:'d',t:'I quit more than 5 years ago'},{v:'e',t:'I\u2019ve never smoked'}] },
    q8: { title:'Social Connection', text:'How often do you spend meaningful time with people you care about?',
      hint:'In person, by phone, or video \u2014 not just social media or texting.',
      opts:[{v:'a',t:'Rarely \u2014 I feel isolated most of the time'},{v:'b',t:'Once or twice a month'},{v:'c',t:'About once a week'},{v:'d',t:'Several times a week'},{v:'e',t:'Daily \u2014 strong, regular connections'}] },
    q9: { title:'Diet Pattern', text:'Which best describes your typical eating pattern?',
      opts:[{v:'a',t:'Mostly processed or fast food, very few vegetables'},{v:'b',t:'Mixed \u2014 some healthy meals, a lot of convenience food'},{v:'c',t:'Reasonably healthy \u2014 I cook most meals'},{v:'d',t:'Mostly whole foods, plenty of vegetables'},{v:'e',t:'Very clean \u2014 whole foods, diverse vegetables, minimal processed'}] }
  };

  var S3_SECTIONS = [
    { id: 'body', title: 'Body Measurements', fields: ['hip', 'bpSystolic', 'bpDiastolic', 'restingHeartRate', 'overallHealthPercent'] },
    { id: 'fitness', title: 'Fitness & Performance', fields: ['sitToStand', 'breathHold', 'balance', 'skinElasticity'] },
    { id: 'sleep', title: 'Sleep', fields: ['sleepDuration', 'sleepQuality'] },
    { id: 'mental', title: 'Mental & Social', fields: ['stressLevels', 'socialConnections', 'cognitiveActivity'] },
    { id: 'lifestyle', title: 'Lifestyle', fields: ['dietQuality', 'alcoholConsumption', 'smokingStatus', 'supplementIntake', 'sunlightExposure', 'dailyHydration'] }
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
  function progressBar(active) {
    var stages = [{num:1,label:'Quick Insight'},{num:2,label:'Your WHY'},{num:3,label:'Full Detail'}];
    var h = '<div class="hdlv2-progress">';
    stages.forEach(function(s){
      var cls = s.num < active ? 'completed' : s.num === active ? 'active' : 'pending';
      h += '<div class="hdlv2-progress-step '+cls+'"><div class="hdlv2-progress-dot">'+(s.num<active?'&#10003;':s.num)+'</div><div class="hdlv2-progress-label">'+s.label+'</div>'+(s.num<3?'<div class="hdlv2-progress-line"></div>':'')+'</div>';
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
  }

  function footer() { return '<div class="hdlv2-footer">Powered by <a href="https://healthdatalab.com" target="_blank" rel="noopener">HealthDataLab</a></div>'; }
  function saveIndicator() { return '<div id="hdlv2-save-indicator" class="hdlv2-save-status"></div>'; }

  // ══════════════════════════════════════════════════════════════
  //  STAGE 1: QUICK INSIGHT — 9 evidence-based questions
  // ══════════════════════════════════════════════════════════════

  function renderStage1(data) {
    if (data.stage1_completed_at && data.stage1_data && data.stage1_data.server_result) {
      renderStage1Result(data.stage1_data.server_result, data.stage1_data); return;
    }
    s1Step = 0;
    renderS1Step(data);
  }

  function renderS1Step(data) {
    var d = formData;
    var steps = [renderS1_Q1, renderS1_Q2a, renderS1_Q2b, renderS1_Q3, renderS1_Q4, renderS1_Q5, renderS1_Q6, renderS1_Q7, renderS1_Q8, renderS1_Q9];
    var pct = Math.round((s1Step / S1_TOTAL_STEPS) * 100);

    // Wrapper with progress
    var html = '<div class="hdlv2-card">' + progressBar(1)
      + '<div class="hdlv2-header"><h2>Quick Health Insight</h2><p>9 questions, takes about 60 seconds</p></div>'
      + '<div style="padding:0 24px 8px;"><div style="height:4px;background:#e4e6ea;border-radius:2px;overflow:hidden;"><div style="height:100%;width:'+pct+'%;background:#3d8da0;border-radius:2px;transition:width 0.3s;"></div></div>'
      + '<p style="text-align:right;font-size:11px;color:#aaa;margin:4px 0 0;">Question '+(s1Step+1)+' of '+S1_TOTAL_STEPS+'</p></div>'
      + '<div class="hdlv2-form-body" id="hdlv2-s1-body"></div>'
      + saveIndicator() + footer() + '</div>';
    root.innerHTML = html;

    var body = document.getElementById('hdlv2-s1-body');
    steps[s1Step](body, d, data);
  }

  function s1Advance(data) {
    // Auto-save current state
    if (saveTimer) clearTimeout(saveTimer);
    autoSave(1);
    setTimeout(function(){ s1Step++; renderS1Step(data); root.scrollIntoView({behavior:'smooth'}); }, 200);
  }

  // Q1: Age + Sex
  function renderS1_Q1(body, d, data) {
    body.innerHTML = ''
      + '<p style="font-size:16px;font-weight:600;color:#111;margin:0 0 4px;">Age &amp; Sex</p>'
      + '<p style="font-size:12px;color:#888;margin:0 0 16px;">Used to calibrate your results to population norms.</p>'
      + fieldInput('q1_age','Age','number',d.q1_age,'Your age in years')
      + '<div class="hdlv2-field"><label>Sex</label>'
      + '<div style="display:flex;gap:10px;">'
      + '<button type="button" class="hdlv2-btn-toggle'+(d.q1_sex==='male'?' selected':'')+'" data-sex="male" style="flex:1;padding:12px;border:2px solid '+(d.q1_sex==='male'?'#3d8da0':'#e4e6ea')+';border-radius:10px;background:'+(d.q1_sex==='male'?'rgba(61,141,160,0.08)':'#f8f9fb')+';font-size:14px;font-weight:600;cursor:pointer;">Male</button>'
      + '<button type="button" class="hdlv2-btn-toggle'+(d.q1_sex==='female'?' selected':'')+'" data-sex="female" style="flex:1;padding:12px;border:2px solid '+(d.q1_sex==='female'?'#3d8da0':'#e4e6ea')+';border-radius:10px;background:'+(d.q1_sex==='female'?'rgba(61,141,160,0.08)':'#f8f9fb')+';font-size:14px;font-weight:600;cursor:pointer;">Female</button>'
      + '</div></div>'
      + '<button id="hdlv2-s1-next" class="hdlv2-btn" style="margin-top:12px;">Next</button>';

    body.querySelectorAll('[data-sex]').forEach(function(btn){
      btn.addEventListener('click', function(){
        formData.q1_sex = btn.getAttribute('data-sex');
        body.querySelectorAll('[data-sex]').forEach(function(b){ b.style.borderColor='#e4e6ea'; b.style.background='#f8f9fb'; });
        btn.style.borderColor='#3d8da0'; btn.style.background='rgba(61,141,160,0.08)';
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
    var html = '<p style="font-size:16px;font-weight:600;color:#111;margin:0 0 4px;">Overall Body Size</p>'
      + '<p style="font-size:13px;color:#444;margin:0 0 6px;">Which image most closely matches your current body shape?</p>'
      + '<p style="font-size:10px;color:#aaa;margin:0 0 12px;font-style:italic;">Based on the Stunkard Figure Rating Scale (r=0.91 correlation with measured BMI).</p>'
      + '<div style="display:flex;justify-content:center;gap:8px;flex-wrap:wrap;">';
    for (var i = 1; i <= 5; i++) {
      var sel = d.q2a === i;
      html += '<div class="hdlv2-sil-opt" data-sil="'+i+'" style="text-align:center;width:65px;cursor:pointer;border:3px solid '+(sel?'#3d8da0':'transparent')+';border-radius:10px;padding:6px;transition:all 0.2s;'+(sel?'background:rgba(61,141,160,0.08);':'background:transparent;')+'">'
        + '<img src="'+S1_IMG_BASE+sex+'-'+i+'.svg" alt="'+labels[i-1]+'" style="width:50px;height:auto;display:block;margin:0 auto 4px;">'
        + '<span style="font-size:9px;color:#64748b;">'+labels[i-1]+'</span></div>';
    }
    html += '</div>';
    body.innerHTML = html;

    body.querySelectorAll('.hdlv2-sil-opt').forEach(function(opt){
      opt.addEventListener('click', function(){
        formData.q2a = parseInt(opt.getAttribute('data-sil'), 10);
        body.querySelectorAll('.hdlv2-sil-opt').forEach(function(o){ o.style.borderColor='transparent'; o.style.background='transparent'; });
        opt.style.borderColor='#3d8da0'; opt.style.background='rgba(61,141,160,0.08)';
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
    var html = '<p style="font-size:16px;font-weight:600;color:#111;margin:0 0 4px;">Fat Distribution</p>'
      + '<p style="font-size:13px;color:#444;margin:0 0 6px;">Where do you tend to carry extra weight?</p>'
      + '<p style="font-size:11px;color:#888;margin:0 0 12px;">If you\u2019re not sure or feel you don\u2019t carry much extra weight, select \u201cFairly evenly spread.\u201d</p>'
      + '<div style="display:flex;justify-content:center;gap:12px;">';
    fatOpts.forEach(function(f){
      var sel = d.q2b === f.v;
      html += '<div class="hdlv2-fat-opt" data-fat="'+f.v+'" style="text-align:center;width:90px;cursor:pointer;border:3px solid '+(sel?'#3d8da0':'transparent')+';border-radius:10px;padding:8px;transition:all 0.2s;'+(sel?'background:rgba(61,141,160,0.08);':'')+'">'
        + '<img src="'+S1_IMG_BASE+f.img+'" alt="'+f.label+'" style="width:60px;height:auto;display:block;margin:0 auto 6px;">'
        + '<span style="font-size:10px;color:#64748b;line-height:1.3;display:block;">'+f.label+'</span></div>';
    });
    html += '</div>';
    body.innerHTML = html;

    body.querySelectorAll('.hdlv2-fat-opt').forEach(function(opt){
      opt.addEventListener('click', function(){
        formData.q2b = opt.getAttribute('data-fat');
        body.querySelectorAll('.hdlv2-fat-opt').forEach(function(o){ o.style.borderColor='transparent'; o.style.background='transparent'; });
        opt.style.borderColor='#3d8da0'; opt.style.background='rgba(61,141,160,0.08)';
        s1Advance(data);
      });
    });
  }

  // Generic MCQ renderer for Stage 1
  function renderS1_MCQ(body, d, data, qKey) {
    var q = S1_QUESTIONS[qKey];
    var html = '<p style="font-size:16px;font-weight:600;color:#111;margin:0 0 4px;">'+q.title+'</p>'
      + '<p style="font-size:13px;color:#444;margin:0 0 6px;line-height:1.5;">'+q.text+'</p>';
    if (q.hint) html += '<p style="font-size:11px;color:#888;margin:0 0 8px;line-height:1.4;">'+q.hint+'</p>';
    if (q.cite) html += '<p style="font-size:10px;color:#aaa;margin:0 0 8px;font-style:italic;">'+q.cite+'</p>';

    q.opts.forEach(function(o){
      var sel = d[qKey] === o.v;
      html += '<div class="hdlv2-s1-opt" data-val="'+o.v+'" style="cursor:pointer;border:2px solid '+(sel?'#3d8da0':'#e4e6ea')+';border-radius:10px;padding:11px 14px;margin-bottom:8px;transition:all 0.15s;font-size:13px;line-height:1.45;'+(sel?'background:rgba(61,141,160,0.08);font-weight:600;':'')+'">'
        + '<span style="font-weight:700;color:#3d8da0;margin-right:6px;">'+o.v.toUpperCase()+'</span>'+o.t+'</div>';
    });
    body.innerHTML = html;

    body.querySelectorAll('.hdlv2-s1-opt').forEach(function(opt){
      opt.addEventListener('click', function(){
        formData[qKey] = opt.getAttribute('data-val');
        body.querySelectorAll('.hdlv2-s1-opt').forEach(function(o){ o.style.borderColor='#e4e6ea'; o.style.background=''; o.style.fontWeight=''; });
        opt.style.borderColor='#3d8da0'; opt.style.background='rgba(61,141,160,0.08)'; opt.style.fontWeight='600';
        // Last question (q9) triggers completion instead of advance
        if (qKey === 'q9') { completeStage1(); }
        else { s1Advance(data); }
      });
    });
  }

  function renderS1_Q3(body, d, data) { renderS1_MCQ(body, d, data, 'q3'); }
  function renderS1_Q4(body, d, data) { renderS1_MCQ(body, d, data, 'q4'); }
  function renderS1_Q5(body, d, data) { renderS1_MCQ(body, d, data, 'q5'); }
  function renderS1_Q6(body, d, data) { renderS1_MCQ(body, d, data, 'q6'); }
  function renderS1_Q7(body, d, data) { renderS1_MCQ(body, d, data, 'q7'); }
  function renderS1_Q8(body, d, data) { renderS1_MCQ(body, d, data, 'q8'); }
  function renderS1_Q9(body, d, data) { renderS1_MCQ(body, d, data, 'q9'); }

  function completeStage1() {
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

  function renderStage1Result(result, stageData) {
    var rate = result.rate || 1.0;
    var age = stageData.q1_age || '?';
    var msg;
    if (rate <= 0.95) {
      msg = 'Your pace of ageing is '+rate+'\u00d7 \u2014 you\u2019re ageing '+(Math.round((1-rate)*100))+'% slower than average. Your lifestyle factors are working in your favour.';
    } else if (rate <= 1.05) {
      msg = 'Your pace of ageing is '+rate+'\u00d7 \u2014 roughly at the population average. Small changes could shift this significantly.';
    } else {
      msg = 'Your pace of ageing is '+rate+'\u00d7 \u2014 you\u2019re ageing '+(Math.round((rate-1)*100))+'% faster than average. Targeted changes can bring this down.';
    }

    root.innerHTML = '<div class="hdlv2-card">'+progressBar(1)
      +'<div class="hdlv2-header"><h2>Your Quick Insight</h2><p>Based on 9 evidence-based questions</p></div>'
      +'<div class="hdlv2-result">'
      +'<img src="'+HDLSpeedometer.buildUrl(rate)+'" alt="Rate of ageing gauge">'
      +'<p class="hdlv2-result-message">'+esc(msg)+'</p>'
      +'<p class="hdlv2-result-note" style="font-style:italic;">This is an estimate based on limited data. Your full assessment with your practitioner will be more precise.</p>'
      +'<p class="hdlv2-result-note">Continue to Stage 2 to explore your WHY \u2014 then Stage 3 for a comprehensive analysis with actual measurements.</p>'
      +'<button id="hdlv2-goto-s2" class="hdlv2-btn-secondary">Continue to Stage 2 \u2014 Your WHY &#8594;</button>'
      +'</div>'+footer()+'</div>';
    document.getElementById('hdlv2-goto-s2').addEventListener('click', function(){ currentStage=2; formData={}; loadForm(); });
  }

  // ══════════════════════════════════════════════════════════════
  //  STAGE 2: YOUR WHY
  // ══════════════════════════════════════════════════════════════

  function renderStage2(data) {
    if (data.stage2_completed_at && data.stage2_data) { renderStage2Result(data.stage2_data); return; }
    var d = formData;
    if (!d.key_people) d.key_people = {};

    root.innerHTML = '<div class="hdlv2-card">'+progressBar(2)
      +'<div class="hdlv2-header"><h2>Your WHY</h2><p>10\u201320 minutes \u2014 explore what truly motivates your health journey</p></div>'
      +'<div class="hdlv2-form-body">'
      // Environment guidance
      +'<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#166534;line-height:1.6;">'
      +'This works best when you have 10\u201320 minutes of quiet, uninterrupted time. Some people find it helpful to go for a walk and record their thoughts on their phone. There\u2019s no wrong way to do this \u2014 just speak or write honestly about what matters to you.'
      +'</div>'
      // Two Trajectories video — spec: "shown before/alongside the form"
      +'<div style="background:#f0f9fa;border:1px solid #b2dfdb;border-radius:10px;padding:16px;margin-bottom:20px;text-align:center;">'
      +'<div style="display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;background:#004F59;margin-bottom:10px;">'
      +'<svg width="20" height="20" viewBox="0 0 24 24" fill="white"><polygon points="8,5 20,12 8,19"/></svg>'
      +'</div>'
      +'<p style="font-size:13px;color:#444;line-height:1.5;margin:0 0 10px;">Not sure where to start? This short video shows two versions of the same future \u2014 and might help you think about what really matters to you.</p>'
      +'<a href="https://altituding.com/two-trajectories" target="_blank" rel="noopener" style="display:inline-block;padding:9px 22px;background:#2D9596;color:#fff;border-radius:48px;text-decoration:none;font-size:13px;font-weight:600;">Watch the Two Trajectories Video \u2192</a>'
      +'</div>'
      // Share your WHY — prompt + audio recording + text
      +'<div class="hdlv2-field"><label style="font-size:15px;font-weight:700;margin-bottom:8px;display:block;color:#111;">Share your WHY</label>'
      +'<p style="font-size:14px;color:#333;margin:0 0 8px;line-height:1.6;font-weight:500;">Think about the people who are important to you and how you want to have a positive influence on their lives. What does a healthy future look like for you?</p>'
      +'<p style="font-size:12px;color:#888;margin:0 0 16px;line-height:1.5;">We recommend recording an audio message \u2014 go for a walk, talk freely for 10\u201320 minutes. The recording will be transcribed into the text box below. You can also type directly if you prefer.</p>'
      +'<div id="hdlv2-s2-audio"></div>'
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

    // Save only — do NOT call complete-stage (practitioner triggers AI extraction later)
    fetch(CFG.api_base+'/save',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token,stage:2,data:formData,submitted:true})})
      .then(function(r){return r.json();})
      .then(function(res){
        if(res.success){ renderStage2Confirmation(); }
        else{btn.disabled=false;btn.textContent='Submit Your WHY';setSaveStatus('error',res.message||'Could not save. Please try again.');}
      }).catch(function(){btn.disabled=false;btn.textContent='Submit Your WHY';setSaveStatus('error','Connection error');});
  }

  function renderStage2Confirmation() {
    root.innerHTML = '<div class="hdlv2-card">'+progressBar(2)
      +'<div style="text-align:center;padding:48px 24px;">'
      +'<div style="width:56px;height:56px;margin:0 auto 24px;background:#3d8da0;border-radius:50%;display:flex;align-items:center;justify-content:center;">'
      +'<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>'
      +'</div>'
      +'<h3 style="font-size:20px;font-weight:600;color:#1a1a1a;margin:0 0 12px;">Thank you for sharing your WHY</h3>'
      +'<p style="font-size:15px;color:#6b7280;line-height:1.6;max-width:400px;margin:0 auto;">Your responses have been saved. Your practitioner will use these to personalise your full Longevity Report.</p>'
      +'<p style="font-size:14px;color:#9ca3af;margin-top:16px;">You\u2019ll receive an invitation to continue once your practitioner has reviewed your input.</p>'
      +'</div>'+footer()+'</div>';
  }

  function renderStage2Result(stageData) {
    var mot = (stageData.what_do || stageData.motivations || []).join(', ') || '(none)';
    var vision = (stageData.vision_text || '').substring(0, 200);
    var why = stageData.distilled_why || '';
    var reform = stageData.ai_reformulation || '';

    root.innerHTML = '<div class="hdlv2-card">'+progressBar(2)
      +'<div class="hdlv2-header"><h2>Your WHY</h2><p>Here\u2019s what we captured</p></div>'
      +'<div class="hdlv2-result">'
      + (why ? '<div style="background:#f8f9fb;border-left:4px solid #3d8da0;padding:14px 18px;border-radius:8px;margin:0 0 16px;text-align:left;"><p style="font-size:11px;color:#3d8da0;font-weight:600;text-transform:uppercase;margin:0 0 6px;">Your Distilled WHY</p><p style="font-size:15px;font-weight:600;color:#111;margin:0;line-height:1.5;font-style:italic;">\u201C'+esc(why)+'\u201D</p></div>' : '')
      + (reform ? '<div style="text-align:left;color:#444;line-height:1.7;margin:0 0 16px;">'+reform+'</div>' : '')
      +'<p class="hdlv2-result-message" style="text-align:left;"><strong>What matters:</strong> '+esc(mot)+'</p>'
      +'<p class="hdlv2-result-note">Thank you. Your responses have been saved. Your practitioner will use these to personalise your full Longevity Report. You\u2019ll receive an email when the next stage is ready for you.</p>'
      +'</div>'+footer()+'</div>';
  }

  // ══════════════════════════════════════════════════════════════
  //  STAGE 3: FULL DETAIL (WIZARD MODE)
  // ══════════════════════════════════════════════════════════════

  function renderStage3(data) {
    if (data.stage3_completed_at && data.stage3_data && data.stage3_data.server_result) {
      renderStage3Result(data.stage3_data.server_result, data); return;
    }
    var s1 = data.stage1_data || {};
    // Map new Stage 1 answers to physicalActivity score for calculate_full()
    // Q3 (zone 2 activity) maps: a=0, b=1, c=2, d=3, e=4→5
    if (!formData.physicalActivity && s1.q3) {
      var q3map = { a:'0', b:'1', c:'2', d:'3', e:'5' };
      formData.physicalActivity = q3map[s1.q3] || '';
    }
    wizardSection = 0;
    renderWizardSection(data);
  }

  function renderWizardSection(data) {
    var s1 = data || serverData;
    var s1d = (s1 && s1.stage1_data) || {};
    var d = formData;
    var sec = S3_SECTIONS[wizardSection];
    var total = S3_SECTIONS.length;

    // Section content
    var content = '';
    if (sec.id === 'body') {
      // Age and sex from Stage 1 (readonly), actual measurements now collected here
      content = '<div class="hdlv2-field-grid cols-2">'
        + fieldReadonly('Age', s1d.q1_age || s1d.age, 'years') + fieldReadonly('Sex', s1d.q1_sex || s1d.gender, '')
        + '</div>'
        + '<p style="font-size:12px;color:#888;margin:0 0 10px;">These measurements replace the Stage 1 visual estimates with actual numbers.</p>'
        + '<div class="hdlv2-field-grid cols-3">'
        + fieldInputWithSkip('height', 'Height (cm)', 'number', d.height, '170')
        + fieldInputWithSkip('weight', 'Weight (kg)', 'number', d.weight, '75')
        + fieldInputWithSkip('waist', 'Waist (cm)', 'number', d.waist, '85')
        + '</div><div class="hdlv2-field-grid cols-2">'
        + fieldInputWithSkip('hip', 'Hip (cm)', 'number', d.hip, '95')
        + fieldInputWithSkip('restingHeartRate', 'Resting Heart Rate (bpm)', 'number', d.restingHeartRate, '70')
        + '</div><div class="hdlv2-field-grid cols-2">'
        + fieldInputWithSkip('bpSystolic', 'Systolic BP (mmHg)', 'number', d.bpSystolic, '120')
        + fieldInputWithSkip('bpDiastolic', 'Diastolic BP (mmHg)', 'number', d.bpDiastolic, '80')
        + '</div><div class="hdlv2-field-grid">'
        + fieldRange('overallHealthPercent', 'Overall Health Rating (0\u2013100)', d.overallHealthPercent, 0, 100)
        + '</div>';
    } else if (sec.id === 'fitness') {
      content = '<div class="hdlv2-field-grid cols-2">'
        + fieldSelect('sitToStand','Sit-to-Stand Test',SCORE_OPTIONS,d.sitToStand) + fieldSelect('breathHold','Breath Hold Test',SCORE_OPTIONS,d.breathHold)
        + '</div><div class="hdlv2-field-grid cols-2">'
        + fieldSelect('balance','Balance Test',SCORE_OPTIONS,d.balance) + fieldSelect('skinElasticity','Skin Elasticity',SCORE_OPTIONS,d.skinElasticity)
        + '</div>';
    } else if (sec.id === 'sleep') {
      content = '<div class="hdlv2-field-grid cols-2">'
        + fieldSelect('sleepDuration','Sleep Duration',SCORE_OPTIONS,d.sleepDuration) + fieldSelect('sleepQuality','Sleep Quality',SCORE_OPTIONS,d.sleepQuality)
        + '</div>';
    } else if (sec.id === 'mental') {
      content = '<div class="hdlv2-field-grid cols-3">'
        + fieldSelect('stressLevels','Stress Levels',SCORE_OPTIONS,d.stressLevels)
        + fieldSelect('socialConnections','Social Connections',SCORE_OPTIONS,d.socialConnections)
        + fieldSelect('cognitiveActivity','Cognitive Activity',SCORE_OPTIONS,d.cognitiveActivity)
        + '</div>';
    } else if (sec.id === 'lifestyle') {
      content = '<div class="hdlv2-field-grid cols-3">'
        + fieldSelect('dietQuality','Diet Quality',SCORE_OPTIONS,d.dietQuality) + fieldSelect('alcoholConsumption','Alcohol',SCORE_OPTIONS,d.alcoholConsumption) + fieldSelect('smokingStatus','Smoking',SCORE_OPTIONS,d.smokingStatus)
        + '</div><div class="hdlv2-field-grid cols-3">'
        + fieldSelect('supplementIntake','Supplements',SCORE_OPTIONS,d.supplementIntake) + fieldSelect('sunlightExposure','Sunlight',SCORE_OPTIONS,d.sunlightExposure) + fieldSelect('dailyHydration','Hydration',SCORE_OPTIONS,d.dailyHydration)
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

    root.innerHTML = '<div class="hdlv2-card">' + progressBar(3)
      + '<div class="hdlv2-header"><h2>Full Health Detail</h2><p>Section ' + (wizardSection+1) + ' of ' + total + ' \u2014 ' + esc(sec.title) + '</p></div>'
      + dots
      + (isFirst ? '<div class="hdlv2-wizard-info">Don\u2019t worry if you don\u2019t know the details. Just do your best. Your practitioner or the report can still be generated if you haven\u2019t got all these details.</div>' : '')
      + '<div class="hdlv2-form-body">' + content
      + '<p class="hdlv2-wizard-skip-note">Not sure? That\u2019s fine \u2014 skip what you don\u2019t know and keep going.</p>'
      + '<div class="hdlv2-wizard-nav">'
      + (isFirst ? '<span></span>' : '<button type="button" id="hdlv2-wizard-prev" class="hdlv2-btn-secondary" style="font-size:14px;padding:10px 24px;">&#8592; Previous</button>')
      + (isLast ? '<button id="hdlv2-complete-s3" class="hdlv2-btn" style="width:auto;padding:13px 32px;">Complete Assessment &#10003;</button>' : '<button type="button" id="hdlv2-wizard-next" class="hdlv2-btn" style="width:auto;padding:13px 32px;">Next &#8594;</button>')
      + '</div></div>' + saveIndicator() + footer() + '</div>';

    bindFieldListeners();
    bindSkipCheckboxes();
    bindRangeSlider();

    if (!isFirst) document.getElementById('hdlv2-wizard-prev').addEventListener('click', function(){ wizardSection--; renderWizardSection(data); root.scrollIntoView({behavior:'smooth'}); });
    if (isLast) document.getElementById('hdlv2-complete-s3').addEventListener('click', function(){ completeStage3(data); });
    else document.getElementById('hdlv2-wizard-next').addEventListener('click', function(){ autoSave(3); wizardSection++; renderWizardSection(data); root.scrollIntoView({behavior:'smooth'}); });
  }

  function bindSkipCheckboxes() {
    root.querySelectorAll('.hdlv2-skip-check input[type="checkbox"]').forEach(function(cb){
      cb.addEventListener('change', function(){
        var field = cb.getAttribute('data-skip-for');
        var inp = root.querySelector('[data-field="'+field+'"]');
        if (cb.checked) {
          if (inp) { inp.disabled = true; inp.value = ''; }
          formData[field] = 'skip';
        } else {
          if (inp) { inp.disabled = false; }
          formData[field] = '';
        }
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(function(){ autoSave(3); }, 1000);
      });
    });
  }

  function bindRangeSlider() {
    var rangeEl = root.querySelector('input[type="range"][data-field="overallHealthPercent"]');
    if (rangeEl) {
      rangeEl.addEventListener('input', function(){ var rv=document.getElementById('hdlv2-rv-overallHealthPercent'); if(rv)rv.textContent=rangeEl.value; formData.overallHealthPercent=rangeEl.value; });
      rangeEl.addEventListener('change', function(){ formData.overallHealthPercent=rangeEl.value; if(saveTimer)clearTimeout(saveTimer); saveTimer=setTimeout(function(){autoSave(3);},1000); });
    }
  }

  function completeStage3(data) {
    // All fields are optional (skip allowed) — just ensure stage data exists
    var btn = document.getElementById('hdlv2-complete-s3');
    btn.disabled = true; btn.textContent = 'Calculating...';

    fetch(CFG.api_base+'/save',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token,stage:3,data:formData})})
      .then(function(){return fetch(CFG.api_base+'/complete-stage',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token,stage:3})});})
      .then(function(r){return r.json();})
      .then(function(res){
        if (res.success && res.result) {
          // Show loading for report generation
          showReportLoading();
          // Request report generation
          fetch(CFG.api_base+'/generate-report',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},body:JSON.stringify({token:token})})
            .then(function(r){return r.json();})
            .then(function(report){
              if (report.success) renderDraftReport(res.result, report, data);
              else renderStage3Result(res.result, data); // Fallback
            })
            .catch(function(){ renderStage3Result(res.result, data); });
        } else {
          btn.disabled=false; btn.textContent='Complete Assessment \u2713';
          setSaveStatus('error', res.message||'Please try again');
        }
      }).catch(function(){btn.disabled=false;btn.textContent='Complete Assessment \u2713';setSaveStatus('error','Connection error');});
  }

  function showReportLoading() {
    root.innerHTML = '<div class="hdlv2-card">'+progressBar(3)
      +'<div class="hdlv2-loading"><div class="hdlv2-spinner"></div>'
      +'<p style="color:#888;margin:0;">Generating your personalised draft report...</p>'
      +'<p style="color:#aaa;margin:8px 0 0;font-size:12px;">This may take a moment</p>'
      +'</div></div>';
  }

  function renderDraftReport(calcResult, report, data) {
    var rate = calcResult.rate || 1.0;
    var bioAge = calcResult.bio_age || '?';
    var s1 = (data && data.stage1_data) || serverData.stage1_data || {};
    var age = s1.age || '?';

    root.innerHTML = '<div class="hdlv2-card">'+progressBar(3)
      +'<div class="hdlv2-header"><h2>Your Draft Report</h2>'
      +'<span class="hdlv2-draft-label">DRAFT</span>'
      +'<p>Comprehensive analysis across 22 factors</p></div>'
      +'<div class="hdlv2-result">'
      +'<img src="'+HDLSpeedometer.buildUrl(rate)+'" alt="Rate of ageing gauge">'
      +'<p class="hdlv2-result-message">Rate of Ageing: <strong>'+rate+'</strong> &mdash; Biological Age: <strong>'+bioAge+'</strong> (chronological: '+age+')</p>'
      +'</div>'
      // AWAKEN
      +'<div class="hdlv2-report-section awaken"><h3>Awaken \u2014 Your Current State</h3><div>'+( report.awaken_content || '<p>Content pending.</p>')+'</div></div>'
      // LIFT
      +'<div class="hdlv2-report-section lift"><h3>Lift \u2014 Your Action Plan</h3><div>'+(report.lift_content || '<p>Content pending.</p>')+'</div></div>'
      // THRIVE
      +'<div class="hdlv2-report-section thrive"><h3>Thrive \u2014 Your Future Self</h3><div>'+(report.thrive_content || '<p>Content pending.</p>')+'</div></div>'
      +'<p class="hdlv2-result-note" style="margin-top:24px;">Your practitioner has been notified and will prepare your final personalised report. You\u2019ll receive an email when it\u2019s ready.</p>'
      +footer()+'</div>';
  }

  function renderStage3Result(result, data) {
    var rate = result.rate || 1.0, bioAge = result.bio_age || '?';
    var s1 = (data && data.stage1_data) || {};
    var age = s1.age || '?';
    root.innerHTML = '<div class="hdlv2-card">'+progressBar(3)
      +'<div class="hdlv2-header"><h2>Assessment Complete</h2><p>Comprehensive analysis across 22 factors</p></div>'
      +'<div class="hdlv2-result">'
      +'<img src="'+HDLSpeedometer.buildUrl(rate)+'" alt="Rate of ageing gauge">'
      +'<p class="hdlv2-result-message">Rate of Ageing: <strong>'+rate+'</strong> &mdash; Biological Age: <strong>'+bioAge+'</strong> (chronological: '+age+')</p>'
      +'<p class="hdlv2-result-note">Your practitioner has been notified and will prepare your personalised longevity report.</p>'
      +'</div>'+footer()+'</div>';
  }

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

  function fieldInputWithSkip(name, label, type, value, placeholder) {
    var isSkipped = value === 'skip';
    return '<div class="hdlv2-field"><label for="hdlv2-f-'+name+'">'+label+'</label>'
      +'<input id="hdlv2-f-'+name+'" data-field="'+name+'" type="'+type+'" value="'+esc(isSkipped?'':value||'')+'"'
      +(placeholder?' placeholder="'+esc(placeholder)+'"':'')
      +(type==='number'?' min="0" step="any" inputmode="decimal"':'')
      +(isSkipped?' disabled':'')
      +'>'
      +'<label class="hdlv2-skip-check"><input type="checkbox" data-skip-for="'+name+'"'+(isSkipped?' checked':'')+'>I don\u2019t know</label>'
      +'</div>';
  }

  function fieldSelect(name, label, options, value) {
    var h = '<div class="hdlv2-field"><label for="hdlv2-f-'+name+'">'+label+'</label><select id="hdlv2-f-'+name+'" data-field="'+name+'">';
    options.forEach(function(o){ h += '<option value="'+esc(o.v)+'"'+(String(o.v)===String(value||'')?'selected':'')+'>'+esc(o.l)+'</option>'; });
    return h + '</select></div>';
  }

  function fieldTextarea(name, label, value, placeholder, maxlength) {
    return '<div class="hdlv2-field"><label for="hdlv2-f-'+name+'">'+label+'</label>'
      +'<textarea id="hdlv2-f-'+name+'" data-field="'+name+'" placeholder="'+esc(placeholder||'')+'"'
      +(maxlength?' maxlength="'+maxlength+'"':'')+' rows="4">'+esc(value||'')+'</textarea>'
      +'<div class="hdlv2-char-count" id="hdlv2-charcount-'+name+'">'+(value?value.length:0)+' / '+(maxlength||'\u221E')+'</div></div>';
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
