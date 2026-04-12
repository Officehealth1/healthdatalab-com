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
      title: 'Smoking',
      text: 'What\u2019s your smoking status?',
      opts: [
        { v:'a', t:'I smoke daily' },
        { v:'b', t:'I smoke occasionally or socially' },
        { v:'c', t:'I quit within the last 5 years' },
        { v:'d', t:'I quit more than 5 years ago' },
        { v:'e', t:'I\u2019ve never smoked' }
      ]
    },
    q8: {
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

  function loadFonts() {
    if (document.getElementById('hdl-widget-fonts')) return;
    var link = document.createElement('link');
    link.id = 'hdl-widget-fonts';
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap';
    document.head.appendChild(link);
  }

  var B = {
    heading: "'Poppins',sans-serif",
    body: "'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif",
    dark: '#111111', text: '#444444', muted: '#888888', hint: '#aaaaaa',
    border: '#e4e6ea', bg: '#f8f9fb', surface: '#ffffff',
    teal: '#3d8da0', deepTeal: '#004F59', radius: '14px',
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

  // ---------- INVITE MODE ----------

  function getInviteToken() {
    try { return new URLSearchParams(window.location.search).get('invite') || ''; }
    catch (e) { return ''; }
  }

  function showSpinner(el) {
    loadFonts();
    el.innerHTML = '<div style="font-family:' + B.body + ';max-width:420px;margin:0 auto;border:1px solid ' + B.border + ';border-radius:' + B.radius + ';overflow:hidden;background:' + B.surface + ';box-shadow:0 4px 24px rgba(0,0,0,0.06);padding:60px 28px;text-align:center;">'
      + '<div style="width:36px;height:36px;border:3px solid ' + B.border + ';border-top-color:' + B.teal + ';border-radius:50%;margin:0 auto 16px;animation:hdlspin 0.8s linear infinite;"></div>'
      + '<p style="font-size:14px;color:' + B.muted + ';margin:0;">Loading your assessment...</p></div>';
    if (!document.getElementById('hdl-spin-style')) {
      var s = document.createElement('style');
      s.id = 'hdl-spin-style';
      s.textContent = '@keyframes hdlspin{to{transform:rotate(360deg)}} .hdl-img-opt{cursor:pointer;border:3px solid transparent;border-radius:10px;padding:4px;transition:border-color 0.2s,transform 0.15s;} .hdl-img-opt:hover{transform:scale(1.05);} .hdl-img-opt.selected{border-color:#3d8da0;background:rgba(61,141,160,0.08);} .hdl-mcq-opt{cursor:pointer;border:2px solid #e4e6ea;border-radius:10px;padding:10px 14px;margin-bottom:8px;transition:all 0.15s;font-size:13px;line-height:1.45;} .hdl-mcq-opt:hover{border-color:#3d8da0;background:rgba(61,141,160,0.04);} .hdl-mcq-opt.selected{border-color:#3d8da0;background:rgba(61,141,160,0.08);font-weight:600;}';
      document.head.appendChild(s);
    }
  }

  function showInviteError(el, reason) {
    loadFonts();
    var title = reason === 'expired' ? 'This link has expired' : reason === 'completed' ? 'Assessment already completed' : 'Invalid link';
    var msg = reason === 'expired' ? 'Please contact your practitioner for a new assessment link.' : reason === 'completed' ? 'This assessment link has already been used.' : 'This assessment link is not valid.';
    el.innerHTML = '<div style="font-family:' + B.body + ';max-width:420px;margin:0 auto;border:1px solid ' + B.border + ';border-radius:' + B.radius + ';background:' + B.surface + ';box-shadow:0 4px 24px rgba(0,0,0,0.06);padding:40px 28px;text-align:center;">'
      + '<div style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;background:#fef2f2;margin-bottom:16px;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>'
      + '<h3 style="font-family:' + B.heading + ';margin:0 0 8px;font-size:18px;font-weight:700;color:' + B.dark + ';">' + title + '</h3>'
      + '<p style="font-size:14px;color:' + B.muted + ';margin:0;">' + msg + '</p></div>';
  }

  // ---------- WIDGET BUILDER ----------

  function buildWidget(el, invite) {
    loadFonts();
    // Inject styles if not already present
    if (!document.getElementById('hdl-spin-style')) {
      var s = document.createElement('style');
      s.id = 'hdl-spin-style';
      s.textContent = '@keyframes hdlspin{to{transform:rotate(360deg)}} .hdl-img-opt{cursor:pointer;border:3px solid transparent;border-radius:10px;padding:4px;transition:border-color 0.2s,transform 0.15s;} .hdl-img-opt:hover{transform:scale(1.05);} .hdl-img-opt.selected{border-color:#3d8da0;background:rgba(61,141,160,0.08);} .hdl-mcq-opt{cursor:pointer;border:2px solid #e4e6ea;border-radius:10px;padding:10px 14px;margin-bottom:8px;transition:all 0.15s;font-size:13px;line-height:1.45;} .hdl-mcq-opt:hover{border-color:#3d8da0;background:rgba(61,141,160,0.04);} .hdl-mcq-opt.selected{border-color:#3d8da0;background:rgba(61,141,160,0.08);font-weight:600;}';
      document.head.appendChild(s);
    }

    var cfg = {
      pracId: el.getAttribute('data-practitioner-id') || '',
      pracName: el.getAttribute('data-practitioner-name') || '',
      logo: el.getAttribute('data-logo') || '',
      ctaText: el.getAttribute('data-cta-text') || ('Book a session with ' + (el.getAttribute('data-practitioner-name') || 'a practitioner')),
      ctaLink: el.getAttribute('data-cta-link') || '#',
      apiUrl: el.getAttribute('data-api') || '',
      color: el.getAttribute('data-color') || B.teal
    };

    var inviteToken = '', inviteName = '', inviteEmail = '';
    if (invite) {
      cfg.pracId = invite.practitioner_id;
      cfg.pracName = invite.practitioner_name || cfg.pracName;
      cfg.logo = invite.logo_url || cfg.logo;
      cfg.ctaText = invite.cta_text || cfg.ctaText;
      cfg.ctaLink = invite.cta_link || cfg.ctaLink;
      cfg.color = invite.theme_color || cfg.color;
      cfg.apiUrl = invite.api_url || cfg.apiUrl;
      inviteToken = invite._token;
      inviteName = invite.client_name || '';
      inviteEmail = invite.client_email || '';
    }

    var id = 'hdlw-' + cfg.pracId;
    var c = cfg.color;
    var answers = {};
    var currentStep = 0;
    var totalSteps = STEP_COUNT;

    // --- Build HTML shell ---
    el.innerHTML = ''
      + '<div style="font-family:' + B.body + ';max-width:420px;margin:0 auto;border:1px solid ' + B.border + ';border-radius:' + B.radius + ';overflow:hidden;background:' + B.surface + ';box-shadow:0 4px 24px rgba(0,0,0,0.06);">'
      + (cfg.logo ? '<div style="text-align:center;padding:18px 18px 0;"><img src="' + cfg.logo + '" alt="" style="max-height:36px;max-width:140px;"></div>' : '')
      + '<div style="padding:22px 28px 6px;text-align:center;">'
      + '<h3 style="font-family:' + B.heading + ';margin:0 0 6px;font-size:19px;font-weight:700;color:' + B.dark + ';line-height:1.3;">Curious about your rate of ageing?</h3>'
      + '<p style="margin:0 0 8px;font-size:13px;color:' + B.hint + ';">Answer 9 quick questions to find out</p>'
      + '</div>'
      // Progress bar
      + '<div style="padding:0 28px 16px;"><div id="' + id + '-prog" style="height:4px;background:' + B.border + ';border-radius:2px;overflow:hidden;"><div id="' + id + '-progbar" style="height:100%;width:0%;background:' + c + ';border-radius:2px;transition:width 0.3s;"></div></div></div>'
      // Content area
      + '<div id="' + id + '-content" style="padding:0 28px 22px;min-height:280px;"></div>'
      // Footer
      + '<div style="text-align:center;padding:10px;border-top:1px solid ' + B.border + ';">'
      + '<span style="font-size:10px;color:' + B.hint + ';">Powered by <a href="https://healthdatalab.com" target="_blank" rel="noopener" style="color:' + B.hint + ';text-decoration:none;font-weight:500;">HealthDataLab</a></span>'
      + '</div></div>';

    var contentEl = document.getElementById(id + '-content');
    var progBar = document.getElementById(id + '-progbar');

    function updateProgress() {
      var pct = Math.round((currentStep / (totalSteps - 1)) * 100);
      progBar.style.width = pct + '%';
    }

    function goTo(step) {
      currentStep = step;
      updateProgress();
      var renderers = [renderQ1, renderQ2, renderQ3, renderQ4, renderQ5, renderQ6, renderQ7, renderQ8, renderQ9, renderContact, renderResult];
      renderers[step]();
    }

    function advance() {
      setTimeout(function () { goTo(currentStep + 1); }, 250);
    }

    // --- Q1: Age + Sex ---
    function renderQ1() {
      contentEl.innerHTML = ''
        + '<p style="font-size:15px;font-weight:600;color:' + B.dark + ';margin:0 0 4px;">Age &amp; Sex</p>'
        + '<p style="font-size:12px;color:' + B.muted + ';margin:0 0 16px;">These are used to calibrate your results to population norms.</p>'
        + '<div style="margin-bottom:14px;">'
        + '<label style="display:block;font-size:11px;color:' + B.text + ';margin-bottom:4px;font-weight:500;">Age</label>'
        + '<input id="' + id + '-q1age" type="number" min="1" max="120" inputmode="numeric" value="' + (answers.q1_age || '') + '" placeholder="Your age in years" style="width:100%;padding:10px;border:1px solid ' + B.border + ';border-radius:8px;font-size:14px;background:' + B.bg + ';box-sizing:border-box;font-family:' + B.body + ';">'
        + '</div>'
        + '<div style="margin-bottom:18px;">'
        + '<label style="display:block;font-size:11px;color:' + B.text + ';margin-bottom:6px;font-weight:500;">Sex</label>'
        + '<div style="display:flex;gap:10px;">'
        + '<div id="' + id + '-sex-male" class="hdl-mcq-opt' + (answers.q1_sex === 'male' ? ' selected' : '') + '" style="flex:1;text-align:center;padding:12px;font-size:14px;font-weight:500;">Male</div>'
        + '<div id="' + id + '-sex-female" class="hdl-mcq-opt' + (answers.q1_sex === 'female' ? ' selected' : '') + '" style="flex:1;text-align:center;padding:12px;font-size:14px;font-weight:500;">Female</div>'
        + '</div></div>'
        + btn(id + '-q1next', 'Next', c);

      document.getElementById(id + '-sex-male').addEventListener('click', function () {
        answers.q1_sex = 'male';
        document.getElementById(id + '-sex-male').classList.add('selected');
        document.getElementById(id + '-sex-female').classList.remove('selected');
      });
      document.getElementById(id + '-sex-female').addEventListener('click', function () {
        answers.q1_sex = 'female';
        document.getElementById(id + '-sex-female').classList.add('selected');
        document.getElementById(id + '-sex-male').classList.remove('selected');
      });
      document.getElementById(id + '-q1next').addEventListener('click', function () {
        var age = parseInt(document.getElementById(id + '-q1age').value, 10);
        if (!age || age < 1 || !answers.q1_sex) {
          if (!age) document.getElementById(id + '-q1age').style.borderColor = '#e74c3c';
          return;
        }
        answers.q1_age = age;
        goTo(1);
      });
    }

    // --- Q2: Body shape (silhouettes + fat distribution) ---
    function renderQ2() {
      var sex = answers.q1_sex || 'male';
      contentEl.innerHTML = ''
        + '<p style="font-size:15px;font-weight:600;color:' + B.dark + ';margin:0 0 4px;">Body Shape</p>'
        + '<p style="font-size:12px;color:' + B.muted + ';margin:0 0 12px;">Which image most closely matches your current body shape?</p>'
        + '<p style="font-size:10px;color:' + B.hint + ';margin:0 0 10px;font-style:italic;">Based on the Stunkard Figure Rating Scale (r=0.91 correlation with measured BMI).</p>'
        + '<div id="' + id + '-sil" style="display:flex;justify-content:center;gap:6px;margin-bottom:20px;flex-wrap:wrap;">'
        + silOpt(id, sex, 1, 'Very lean')
        + silOpt(id, sex, 2, 'Healthy')
        + silOpt(id, sex, 3, 'Overweight')
        + silOpt(id, sex, 4, 'Obese I')
        + silOpt(id, sex, 5, 'Obese II+')
        + '</div>'
        + '<div id="' + id + '-q2b-section" style="display:' + (answers.q2a ? 'block' : 'none') + ';">'
        + '<p style="font-size:14px;font-weight:600;color:' + B.dark + ';margin:0 0 4px;">Where do you tend to carry extra weight?</p>'
        + '<p style="font-size:11px;color:' + B.muted + ';margin:0 0 10px;">If you\u2019re not sure, select \u201cEvenly spread.\u201d</p>'
        + '<div id="' + id + '-fat" style="display:flex;justify-content:center;gap:10px;margin-bottom:14px;">'
        + fatOpt(id, 'apple', 'Mostly middle', sex)
        + fatOpt(id, 'pear', 'Hips & thighs', sex)
        + fatOpt(id, 'even', 'Evenly spread', sex)
        + '</div></div>';

      // Bind silhouette clicks
      for (var i = 1; i <= 5; i++) {
        (function (n) {
          document.getElementById(id + '-sil-' + n).addEventListener('click', function () {
            answers.q2a = n;
            var opts = document.querySelectorAll('#' + id + '-sil .hdl-img-opt');
            for (var j = 0; j < opts.length; j++) opts[j].classList.remove('selected');
            this.classList.add('selected');
            document.getElementById(id + '-q2b-section').style.display = 'block';
          });
        })(i);
      }

      // Bind fat distribution clicks
      ['apple', 'pear', 'even'].forEach(function (v) {
        document.getElementById(id + '-fat-' + v).addEventListener('click', function () {
          answers.q2b = v;
          var opts = document.querySelectorAll('#' + id + '-fat .hdl-img-opt');
          for (var j = 0; j < opts.length; j++) opts[j].classList.remove('selected');
          this.classList.add('selected');
          advance();
        });
      });

      // Restore selections if going back
      if (answers.q2a) {
        var sel = document.getElementById(id + '-sil-' + answers.q2a);
        if (sel) sel.classList.add('selected');
      }
    }

    // --- Q3-Q9: MCQ renderer ---
    function renderMCQ(qKey, stepIdx) {
      var q = QUESTIONS[qKey];
      var html = ''
        + '<p style="font-size:15px;font-weight:600;color:' + B.dark + ';margin:0 0 4px;">' + q.title + '</p>'
        + '<p style="font-size:13px;color:' + B.text + ';margin:0 0 6px;line-height:1.5;">' + q.text + '</p>';
      if (q.hint) html += '<p style="font-size:11px;color:' + B.muted + ';margin:0 0 10px;line-height:1.4;">' + q.hint + '</p>';
      if (q.cite) html += '<p style="font-size:10px;color:' + B.hint + ';margin:0 0 10px;font-style:italic;">' + q.cite + '</p>';

      html += '<div id="' + id + '-opts" style="margin-top:8px;">';
      for (var i = 0; i < q.opts.length; i++) {
        var o = q.opts[i];
        var sel = answers[qKey] === o.v ? ' selected' : '';
        html += '<div class="hdl-mcq-opt' + sel + '" data-val="' + o.v + '">'
          + '<span style="font-weight:700;color:' + c + ';margin-right:6px;">' + o.v.toUpperCase() + '</span>' + o.t
          + '</div>';
      }
      html += '</div>';

      contentEl.innerHTML = html;

      var opts = contentEl.querySelectorAll('.hdl-mcq-opt');
      for (var j = 0; j < opts.length; j++) {
        opts[j].addEventListener('click', function () {
          answers[qKey] = this.getAttribute('data-val');
          for (var k = 0; k < opts.length; k++) opts[k].classList.remove('selected');
          this.classList.add('selected');
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
      var html = '<div style="text-align:center;margin-bottom:14px;">'
        + '<div style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;background:' + B.bg + ';margin-bottom:10px;">'
        + '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="' + c + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg></div>'
        + '<p style="font-size:14px;font-weight:600;color:' + B.dark + ';margin:0 0 4px;">To receive your results, please provide your details.</p>'
        + '</div><div style="display:grid;gap:10px;margin-bottom:14px;">';

      if (isInv) {
        html += lockedField('Name', inviteName) + lockedField('Email', inviteEmail);
      } else {
        html += inputField(id, 'name', 'Name or nickname', 'text', true, 'This is how your practitioner will address you. Use a nickname if you prefer more privacy.')
          + inputField(id, 'email', 'Email address', 'email', true, 'We\u2019ll send your results here.')
          + inputField(id, 'phone', 'Phone number', 'tel', false, 'Optional \u2014 shared with your practitioner only, in case they\u2019d like to contact you directly.');
      }

      html += '</div>' + btn(id + '-submit', 'See My Results', c)
        + '<p style="font-size:10px;color:' + B.hint + ';text-align:center;margin:10px 0 0;">Your details are shared only with ' + (cfg.pracName || 'your practitioner') + '.</p>';

      contentEl.innerHTML = html;

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
        answers._name = name;
        answers._email = email;
        answers._phone = phone;
        goTo(10);
      });
    }

    // --- Results ---
    function renderResult() {
      var r = compute(answers);
      var rate = r.rate;

      var msg;
      if (rate <= 0.95) {
        msg = 'Your pace of ageing is ' + rate + '\u00d7 \u2014 you\u2019re ageing ' + (Math.round((1 - rate) * 100)) + '% slower than average. Your lifestyle factors are working in your favour.';
      } else if (rate <= 1.05) {
        msg = 'Your pace of ageing is ' + rate + '\u00d7 \u2014 roughly at the population average. Small changes could shift this significantly.';
      } else {
        msg = 'Your pace of ageing is ' + rate + '\u00d7 \u2014 you\u2019re ageing ' + (Math.round((rate - 1) * 100)) + '% faster than average. Targeted changes can bring this down.';
      }

      // Show gauge immediately
      contentEl.innerHTML = ''
        + '<div style="text-align:center;">'
        + '<img src="' + gaugeUrl(rate) + '" alt="Rate of ageing gauge" style="width:100%;max-width:320px;display:block;margin:0 auto 4px;" />'
        + '<p style="font-size:13px;color:' + B.text + ';margin:0 0 12px;line-height:1.65;">' + msg + '</p>'
        + '<p style="font-size:11px;color:' + B.hint + ';margin:0 0 6px;line-height:1.4;font-style:italic;">Your rate of ageing is an approximation based on self-reported data. We encourage you to book a session with your practitioner to ensure your journey towards health and vitality well into your older years is the best it can be.</p>'
        + '<div id="' + id + '-continue" style="margin:16px 0;"></div>'
        + '<a href="' + cfg.ctaLink + '" target="_blank" rel="noopener" style="display:inline-block;padding:11px 28px;background:' + c + ';color:#fff;border-radius:48px;text-decoration:none;font-size:14px;font-weight:600;font-family:' + B.body + ';transition:opacity 0.2s;" onmouseover="this.style.opacity=0.85" onmouseout="this.style.opacity=1">' + cfg.ctaText + '</a>'
        + '</div>';

      // Send lead data and get form_token for continuation
      var payload = {
        practitioner_id: parseInt(cfg.pracId, 10),
        name: answers._name, email: answers._email, phone: answers._phone || '',
        q1_age: answers.q1_age, q1_sex: answers.q1_sex,
        q2a: answers.q2a, q2b: answers.q2b,
        q3: answers.q3, q4: answers.q4, q5: answers.q5,
        q6: answers.q6, q7: answers.q7, q8: answers.q8, q9: answers.q9,
        rate_of_ageing_result: rate
      };
      if (inviteToken) payload.invite_token = inviteToken;

      if (cfg.apiUrl) {
        fetch(cfg.apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.form_token) {
              // Build assessment continuation URL
              var baseUrl = cfg.apiUrl.replace(/\/wp-json\/.*$/, '');
              var continueUrl = baseUrl + '/assessment/?token=' + data.form_token;
              var contEl = document.getElementById(id + '-continue');
              if (contEl) {
                contEl.innerHTML = ''
                  + '<p style="font-size:13px;color:' + B.text + ';margin:0 0 10px;font-weight:500;">Your pace of ageing tells you where you are now. Lasting change comes from understanding <em>why</em> it matters to you personally.</p>'
                  + '<a href="' + continueUrl + '" target="_blank" rel="noopener" style="display:inline-block;padding:11px 28px;background:#004F59;color:#fff;border-radius:48px;text-decoration:none;font-size:14px;font-weight:600;font-family:' + B.body + ';margin-bottom:12px;transition:opacity 0.2s;" onmouseover="this.style.opacity=0.85" onmouseout="this.style.opacity=1">Continue to Stage 2 \u2014 Your WHY \u2192</a>'
                  + '<p style="font-size:11px;color:' + B.hint + ';margin:0;">We\u2019ve also sent this link to your email.</p>';
              }
            }
          })
          .catch(function () { /* silent — gauge already shown */ });
      }
    }

    // --- Start ---
    goTo(0);
  }

  // ---------- UI HELPERS ----------

  function silOpt(id, sex, n, label) {
    return '<div id="' + id + '-sil-' + n + '" class="hdl-img-opt" style="text-align:center;width:60px;">'
      + '<img src="' + IMG_BASE + sex + '-' + n + '.svg" alt="' + label + '" style="width:50px;height:auto;display:block;margin:0 auto 4px;">'
      + '<span style="font-size:9px;color:#64748b;">' + label + '</span></div>';
  }

  function fatOpt(id, val, label, sex) {
    return '<div id="' + id + '-fat-' + val + '" class="hdl-img-opt" style="text-align:center;width:80px;">'
      + '<img src="' + IMG_BASE + 'fat-' + val + '-' + (sex || 'male') + '.svg" alt="' + label + '" style="width:55px;height:auto;display:block;margin:0 auto 4px;">'
      + '<span style="font-size:9px;color:#64748b;">' + label + '</span></div>';
  }

  function inputField(id, name, label, type, required, hint) {
    return '<div>'
      + '<label style="display:block;font-size:11px;color:#444;margin-bottom:4px;font-weight:500;">' + label + (required ? '' : '') + '</label>'
      + '<input id="' + id + '-' + name + '" type="' + type + '"' + (required ? ' required' : '') + ' placeholder=" "'
      + ' style="width:100%;padding:9px 10px;border:1px solid #e4e6ea;border-radius:8px;font-size:13px;background:#f8f9fb;box-sizing:border-box;transition:border-color 0.2s;">'
      + (hint ? '<p style="font-size:10px;color:#aaa;margin:3px 0 0;">' + hint + '</p>' : '')
      + '</div>';
  }

  function lockedField(label, value) {
    return '<div>'
      + '<label style="display:block;font-size:11px;color:#444;margin-bottom:4px;font-weight:500;">' + label + '</label>'
      + '<input type="text" value="' + escHtml(value) + '" readonly style="width:100%;padding:9px 10px;border:1px solid #e4e6ea;border-radius:8px;font-size:13px;background:#eef0f2;box-sizing:border-box;color:#888;cursor:not-allowed;">'
      + '</div>';
  }

  function btn(id, text, color) {
    return '<button id="' + id + '" type="button" style="width:100%;padding:12px;background:' + color + ';color:#fff;border:none;border-radius:48px;font-size:15px;font-weight:600;cursor:pointer;transition:opacity 0.15s;" '
      + 'onmouseover="this.style.opacity=\'0.85\'" onmouseout="this.style.opacity=\'1\'">' + text + '</button>';
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
        buildWidget(widgets[i], null);
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
