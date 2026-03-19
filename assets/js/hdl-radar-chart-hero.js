/**
 * HealthDataLab — Health Assessment Radar Chart (Hero)
 * Split-view: left half = healthy (teal), right half = unhealthy (pink).
 * Single spider-web grid with data-hdl-radar attributes for GSAP animation.
 *
 * Adapted for HealthDataLab.com homepage from the Altituding report chart.
 * Font-family: Inter to match site design.
 */

var HDLRadarChart = (function () {
  'use strict';

  // ─── CONFIGURATION ───────────────────────────────────────────
  var PILLARS = [
    'physicalActivity', 'sitToStand', 'breathHold', 'balance',
    'sleepDuration', 'sleepQuality', 'stressLevels', 'socialConnections',
    'dietQuality', 'alcoholConsumption', 'smokingStatus', 'cognitiveActivity',
    'sunlightExposure', 'supplementIntake', 'dailyHydration', 'skinElasticity',
    'bmiScore', 'whrScore', 'whtrScore', 'bloodPressureScore', 'heartRateScore', 'overallHealthScore'
  ];

  var DISPLAY_NAMES = {
    physicalActivity: 'Physical Activity',
    sitToStand: 'Strength',
    breathHold: 'Lung Function',
    balance: 'Balance',
    sleepDuration: 'Sleep Duration',
    sleepQuality: 'Sleep Quality',
    stressLevels: 'Stress Management',
    socialConnections: 'Social Connections',
    dietQuality: 'Nutrition',
    alcoholConsumption: 'Alcohol Intake',
    smokingStatus: 'Smoking',
    cognitiveActivity: 'Brain Health',
    sunlightExposure: 'Sunlight',
    supplementIntake: 'Supplements',
    dailyHydration: 'Hydration',
    skinElasticity: 'Skin Health',
    bmiScore: 'BMI',
    whrScore: 'Waist/Hip',
    whtrScore: 'Waist/Height',
    bloodPressureScore: 'Blood Pressure',
    heartRateScore: 'Heart Rate',
    overallHealthScore: 'Overall Health'
  };

  var NUM = PILLARS.length;
  var CX = 300, CY = 220, MAX_R = 170, MAX_SCORE = 5;
  var ANGLE_STEP = (2 * Math.PI) / NUM;

  // Split indices: 0 = top (shared), 11 = bottom (shared)
  var RIGHT_INDICES = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];   // unhealthy
  var LEFT_INDICES  = [0, 21, 20, 19, 18, 17, 16, 15, 14, 13, 12, 11]; // healthy (counter-clockwise)

  // ─── CURATED PROFILES ──────────────────────────────────────────
  var PROFILES = [
    { // Profile 1: "The Active One"
      healthy: {
        physicalActivity: 4.5, sitToStand: 4.0, breathHold: 4.0, balance: 4.5,
        sleepDuration: 4.0, sleepQuality: 4.0, stressLevels: 3.5, socialConnections: 4.0,
        dietQuality: 4.5, alcoholConsumption: 4.5, smokingStatus: 4.5, cognitiveActivity: 4.5,
        sunlightExposure: 4.0, supplementIntake: 4.5, dailyHydration: 4.0, skinElasticity: 4.0,
        bmiScore: 4.5, whrScore: 4.0, whtrScore: 4.5, bloodPressureScore: 4.5,
        heartRateScore: 4.5, overallHealthScore: 4.0
      },
      unhealthy: {
        physicalActivity: 1.5, sitToStand: 1.5, breathHold: 1.0, balance: 1.5,
        sleepDuration: 2.0, sleepQuality: 1.5, stressLevels: 1.5, socialConnections: 2.0,
        dietQuality: 1.0, alcoholConsumption: 2.0, smokingStatus: 2.5, cognitiveActivity: 1.5,
        sunlightExposure: 1.5, supplementIntake: 1.0, dailyHydration: 2.0, skinElasticity: 1.5,
        bmiScore: 2.0, whrScore: 2.0, whtrScore: 2.0, bloodPressureScore: 2.0,
        heartRateScore: 2.0, overallHealthScore: 1.5
      }
    },
    { // Profile 2: "The Mindful Eater"
      healthy: {
        physicalActivity: 3.5, sitToStand: 3.0, breathHold: 3.5, balance: 3.5,
        sleepDuration: 4.5, sleepQuality: 4.5, stressLevels: 4.0, socialConnections: 3.5,
        dietQuality: 5.0, alcoholConsumption: 5.0, smokingStatus: 5.0, cognitiveActivity: 4.0,
        sunlightExposure: 4.5, supplementIntake: 5.0, dailyHydration: 5.0, skinElasticity: 4.5,
        bmiScore: 5.0, whrScore: 4.5, whtrScore: 5.0, bloodPressureScore: 4.0,
        heartRateScore: 3.5, overallHealthScore: 4.5
      },
      unhealthy: {
        physicalActivity: 2.5, sitToStand: 2.0, breathHold: 1.5, balance: 2.0,
        sleepDuration: 1.5, sleepQuality: 1.0, stressLevels: 2.5, socialConnections: 1.5,
        dietQuality: 1.0, alcoholConsumption: 1.5, smokingStatus: 1.0, cognitiveActivity: 2.0,
        sunlightExposure: 1.0, supplementIntake: 1.0, dailyHydration: 1.0, skinElasticity: 2.0,
        bmiScore: 1.5, whrScore: 1.5, whtrScore: 1.5, bloodPressureScore: 2.5,
        heartRateScore: 2.5, overallHealthScore: 1.5
      }
    },
    { // Profile 3: "The Social Connector"
      healthy: {
        physicalActivity: 3.5, sitToStand: 3.5, breathHold: 3.0, balance: 3.5,
        sleepDuration: 4.0, sleepQuality: 5.0, stressLevels: 4.5, socialConnections: 5.0,
        dietQuality: 4.0, alcoholConsumption: 4.0, smokingStatus: 4.5, cognitiveActivity: 5.0,
        sunlightExposure: 4.0, supplementIntake: 3.5, dailyHydration: 4.0, skinElasticity: 4.0,
        bmiScore: 3.5, whrScore: 3.5, whtrScore: 3.5, bloodPressureScore: 4.0,
        heartRateScore: 4.0, overallHealthScore: 4.0
      },
      unhealthy: {
        physicalActivity: 1.0, sitToStand: 1.0, breathHold: 1.5, balance: 1.0,
        sleepDuration: 2.5, sleepQuality: 2.0, stressLevels: 1.0, socialConnections: 1.0,
        dietQuality: 2.0, alcoholConsumption: 2.5, smokingStatus: 2.0, cognitiveActivity: 1.0,
        sunlightExposure: 2.0, supplementIntake: 2.0, dailyHydration: 1.5, skinElasticity: 1.0,
        bmiScore: 2.5, whrScore: 2.5, whtrScore: 2.5, bloodPressureScore: 1.5,
        heartRateScore: 1.5, overallHealthScore: 1.0
      }
    },
    { // Profile 4: "The Balanced Improver"
      healthy: {
        physicalActivity: 4.0, sitToStand: 4.0, breathHold: 4.0, balance: 4.0,
        sleepDuration: 4.0, sleepQuality: 4.0, stressLevels: 4.0, socialConnections: 4.0,
        dietQuality: 4.0, alcoholConsumption: 4.0, smokingStatus: 4.0, cognitiveActivity: 4.0,
        sunlightExposure: 4.0, supplementIntake: 4.0, dailyHydration: 4.0, skinElasticity: 4.0,
        bmiScore: 4.0, whrScore: 4.0, whtrScore: 4.0, bloodPressureScore: 4.0,
        heartRateScore: 4.0, overallHealthScore: 4.0
      },
      unhealthy: {
        physicalActivity: 1.5, sitToStand: 1.5, breathHold: 1.5, balance: 1.5,
        sleepDuration: 1.5, sleepQuality: 1.5, stressLevels: 1.5, socialConnections: 1.5,
        dietQuality: 1.5, alcoholConsumption: 1.5, smokingStatus: 1.5, cognitiveActivity: 1.5,
        sunlightExposure: 1.5, supplementIntake: 1.5, dailyHydration: 1.5, skinElasticity: 1.5,
        bmiScore: 1.5, whrScore: 1.5, whtrScore: 1.5, bloodPressureScore: 1.5,
        heartRateScore: 1.5, overallHealthScore: 1.5
      }
    }
  ];

  // ─── INTERNAL STATE (populated by render, used by update) ──────
  var _currentHealthy = null;
  var _currentUnhealthy = null;
  var _hDots = [];
  var _uDots = [];
  var _hFill = null;
  var _hStroke = null;
  var _uFill = null;
  var _uStroke = null;
  var _wPulse = [];

  // ─── SVG HELPERS ──────────────────────────────────────────
  function svgEl(tag, attrs, children) {
    var ns = 'http://www.w3.org/2000/svg';
    var el = document.createElementNS(ns, tag);
    if (attrs) {
      for (var k in attrs) {
        if (attrs.hasOwnProperty(k)) el.setAttribute(k, attrs[k]);
      }
    }
    if (typeof children === 'string') {
      el.textContent = children;
    } else if (Array.isArray(children)) {
      for (var i = 0; i < children.length; i++) {
        if (children[i]) el.appendChild(children[i]);
      }
    } else if (children) {
      el.appendChild(children);
    }
    return el;
  }

  function pillarAngle(i) {
    return i * ANGLE_STEP;
  }

  function polarToXY(angle, radius) {
    return {
      x: CX + radius * Math.sin(angle),
      y: CY - radius * Math.cos(angle)
    };
  }

  function scoreToRadius(score) {
    return (score / MAX_SCORE) * MAX_R;
  }

  function buildGridPolygon(level) {
    var parts = [];
    var r = scoreToRadius(level);
    for (var i = 0; i < NUM; i++) {
      var pt = polarToXY(pillarAngle(i), r);
      parts.push((i === 0 ? 'M' : 'L') + pt.x.toFixed(1) + ',' + pt.y.toFixed(1));
    }
    parts.push('Z');
    return parts.join(' ');
  }

  function buildHalfPolygonPath(scores, indices) {
    var parts = [];
    for (var i = 0; i < indices.length; i++) {
      var idx = indices[i];
      var s = scores[PILLARS[idx]] || 0;
      var pt = polarToXY(pillarAngle(idx), scoreToRadius(s));
      parts.push((i === 0 ? 'M' : 'L') + pt.x.toFixed(1) + ',' + pt.y.toFixed(1));
    }
    parts.push('L' + CX + ',' + CY);
    parts.push('Z');
    return parts.join(' ');
  }

  // ─── MAIN RENDER ──────────────────────────────────────────
  function render(selector, options) {
    var container = typeof selector === 'string'
      ? document.querySelector(selector) : selector;
    if (!container) {
      console.error('HDLRadarChart: container not found:', selector);
      return;
    }

    var healthy = options.healthy;
    var unhealthy = options.unhealthy;
    var i, pt, angle, aDeg, anchor, dy, score, labelFill;

    // Reset state arrays
    _hDots = [];
    _uDots = [];
    _wPulse = [];

    var svg = svgEl('svg', {
      viewBox: '-30 -25 660 495',
      style: "width:100%;height:auto;font-family:'Inter',system-ui,-apple-system,sans-serif;"
    });

    // ── Grid polygons (score 1–5, spider-web style) ──────────
    for (i = 1; i <= 5; i++) {
      var gridAttrs = {
        d: buildGridPolygon(i),
        fill: 'none',
        stroke: i === 5 ? 'rgba(45,149,150,0.35)' : '#cbd5e1',
        'stroke-width': i === 5 ? 1.0 : 0.7,
        'data-hdl-radar': 'grid-circle'
      };
      if (i === 5) gridAttrs['stroke-dasharray'] = '8,5';
      svg.appendChild(svgEl('path', gridAttrs));
    }

    // ── Spokes ──────────────────────────────────────────────
    for (i = 0; i < NUM; i++) {
      pt = polarToXY(pillarAngle(i), MAX_R);
      svg.appendChild(svgEl('line', {
        x1: CX, y1: CY, x2: pt.x, y2: pt.y,
        stroke: '#cbd5e1', 'stroke-width': 0.6,
        'data-hdl-radar': 'spoke'
      }));
    }

    // ── Divider line (vertical center: 12 o'clock to 6 o'clock) ──
    svg.appendChild(svgEl('line', {
      x1: CX, y1: CY - MAX_R,
      x2: CX, y2: CY + MAX_R,
      stroke: '#94a3b8', 'stroke-width': 1,
      'stroke-dasharray': '4,4',
      'data-hdl-radar': 'divider-line'
    }));

    // ── Side headers (raised above pillar labels) ───────────
    svg.appendChild(svgEl('text', {
      x: CX - 60, y: CY - MAX_R - 50,
      'text-anchor': 'middle',
      'font-size': '11', 'font-weight': '600',
      fill: 'rgba(45,149,150,0.9)',
      'data-hdl-radar': 'side-header'
    }, 'With guidance'));
    svg.appendChild(svgEl('text', {
      x: CX + 60, y: CY - MAX_R - 50,
      'text-anchor': 'middle',
      'font-size': '11', 'font-weight': '600',
      fill: 'rgba(219,112,147,0.9)',
      'data-hdl-radar': 'side-header'
    }, 'Without change'));

    // ── Pillar labels (color-coded by side) ──────────────────
    var LABEL_R = MAX_R + 30;
    for (i = 0; i < NUM; i++) {
      angle = pillarAngle(i);
      pt = polarToXY(angle, LABEL_R);
      aDeg = (i / NUM) * 360;

      if (aDeg > 12 && aDeg < 168) anchor = 'start';
      else if (aDeg > 192 && aDeg < 348) anchor = 'end';
      else anchor = 'middle';

      if (aDeg < 12 || aDeg > 348) dy = '-0.3em';
      else if (aDeg > 168 && aDeg < 192) dy = '1em';
      else dy = '0.35em';

      // Color by side: right = pink, left = teal, divider = neutral
      if (i === 0 || i === 11) {
        labelFill = '#475569';
      } else if (i >= 1 && i <= 10) {
        labelFill = 'rgba(219,112,147,0.7)';
      } else {
        labelFill = 'rgba(45,149,150,0.7)';
      }

      svg.appendChild(svgEl('text', {
        x: pt.x, y: pt.y,
        'text-anchor': anchor,
        'font-size': '9', fill: labelFill, dy: dy,
        'data-hdl-radar': 'pillar-label'
      }, DISPLAY_NAMES[PILLARS[i]]));
    }

    // ── Unhealthy right-half polygon ─────────────────────────
    var uPath = buildHalfPolygonPath(unhealthy, RIGHT_INDICES);
    _uFill = svgEl('path', {
      d: uPath, fill: 'rgba(219,112,147,0.15)',
      'data-hdl-radar': 'unhealthy-fill'
    });
    svg.appendChild(_uFill);
    _uStroke = svgEl('path', {
      d: uPath, fill: 'none',
      stroke: 'rgba(219,112,147,0.8)', 'stroke-width': 2,
      'stroke-dasharray': '6,3',
      'data-hdl-radar': 'unhealthy-stroke'
    });
    svg.appendChild(_uStroke);
    for (i = 0; i < RIGHT_INDICES.length; i++) {
      var ri = RIGHT_INDICES[i];
      score = unhealthy[PILLARS[ri]] || 0;
      pt = polarToXY(pillarAngle(ri), scoreToRadius(score));
      var uDot = svgEl('circle', {
        cx: pt.x, cy: pt.y, r: 4,
        fill: 'rgba(219,112,147,0.9)',
        stroke: 'white', 'stroke-width': 1,
        'data-hdl-radar': 'unhealthy-dot'
      });
      svg.appendChild(uDot);
      _uDots.push(uDot);
    }

    // ── Healthy left-half polygon ────────────────────────────
    var hPath = buildHalfPolygonPath(healthy, LEFT_INDICES);
    _hFill = svgEl('path', {
      d: hPath, fill: 'rgba(54,162,235,0.2)',
      'data-hdl-radar': 'healthy-fill'
    });
    svg.appendChild(_hFill);
    _hStroke = svgEl('path', {
      d: hPath, fill: 'none',
      stroke: 'rgba(45,149,150,0.8)', 'stroke-width': 2.5,
      'data-hdl-radar': 'healthy-stroke'
    });
    svg.appendChild(_hStroke);
    for (i = 0; i < LEFT_INDICES.length; i++) {
      var li = LEFT_INDICES[i];
      score = healthy[PILLARS[li]] || 0;
      pt = polarToXY(pillarAngle(li), scoreToRadius(score));
      var hDot = svgEl('circle', {
        cx: pt.x, cy: pt.y, r: 5,
        fill: 'rgba(45,149,150,0.9)', stroke: 'white', 'stroke-width': 1,
        'data-hdl-radar': 'healthy-dot'
      });
      svg.appendChild(hDot);
      _hDots.push(hDot);
    }

    // ── Legend (split for phased animation) ──────────────────
    var legendUnhealthyG = svgEl('g', {
      transform: 'translate(140,456)',
      'data-hdl-radar': 'legend-unhealthy'
    });
    legendUnhealthyG.appendChild(svgEl('rect', {
      x: 0, y: -2, width: 150, height: 16, rx: 3,
      fill: 'white', 'fill-opacity': '0.9',
      stroke: '#e8ecf0', 'stroke-width': 0.5
    }));
    legendUnhealthyG.appendChild(svgEl('line', {
      x1: 12, y1: 6, x2: 32, y2: 6,
      stroke: 'rgba(219,112,147,0.8)', 'stroke-width': 2,
      'stroke-dasharray': '6,3'
    }));
    legendUnhealthyG.appendChild(svgEl('text', {
      x: 38, y: 10, 'font-size': '10', fill: '#334155'
    }, 'Without change'));
    svg.appendChild(legendUnhealthyG);

    var legendHealthyG = svgEl('g', {
      transform: 'translate(310,456)',
      'data-hdl-radar': 'legend-healthy'
    });
    legendHealthyG.appendChild(svgEl('rect', {
      x: 0, y: -2, width: 150, height: 16, rx: 3,
      fill: 'white', 'fill-opacity': '0.9',
      stroke: '#e8ecf0', 'stroke-width': 0.5
    }));
    legendHealthyG.appendChild(svgEl('line', {
      x1: 12, y1: 6, x2: 32, y2: 6,
      stroke: 'rgba(45,149,150,0.8)', 'stroke-width': 2.5
    }));
    legendHealthyG.appendChild(svgEl('text', {
      x: 38, y: 10, 'font-size': '10', fill: '#334155'
    }, 'With guidance'));
    svg.appendChild(legendHealthyG);

    // ── Weakness pulse rings (2 lowest healthy scores) ──────
    var sorted = [];
    for (i = 0; i < NUM; i++) {
      sorted.push({ idx: i, score: healthy[PILLARS[i]] || 0 });
    }
    sorted.sort(function (a, b) { return a.score - b.score; });
    for (i = 0; i < 2; i++) {
      score = sorted[i].score;
      pt = polarToXY(pillarAngle(sorted[i].idx), scoreToRadius(score));
      var pulseEl = svgEl('circle', {
        cx: pt.x, cy: pt.y, r: 5,
        fill: 'none', stroke: 'rgba(45,149,150,0.9)', 'stroke-width': 1.5,
        opacity: '0',
        'data-hdl-radar': 'weakness-pulse'
      });
      svg.appendChild(pulseEl);
      _wPulse.push(pulseEl);
    }

    // ── Insert into DOM ───────────────────────────────────
    container.innerHTML = '';
    container.appendChild(svg);

    // Store current scores for update() interpolation
    _currentHealthy = healthy;
    _currentUnhealthy = unhealthy;
  }

  // ─── UPDATE METHOD (morph to new profile) ──────────────────
  function update(newHealthy, newUnhealthy, duration, onComplete) {
    if (!_currentHealthy || typeof gsap === 'undefined') return;

    // Clear intro stroke-draw artifacts
    if (_hStroke) gsap.set(_hStroke, { clearProps: 'strokeDasharray,strokeDashoffset' });
    if (_uStroke) gsap.set(_uStroke, { clearProps: 'strokeDasharray,strokeDashoffset' });

    // Hide pulse circles during morph (they don't track intermediate positions)
    for (var p = 0; p < _wPulse.length; p++) {
      gsap.killTweensOf(_wPulse[p]);
      gsap.set(_wPulse[p], { scale: 1, opacity: 0 });
    }

    var oldH = {};
    var oldU = {};
    var i, key;
    for (i = 0; i < PILLARS.length; i++) {
      key = PILLARS[i];
      oldH[key] = _currentHealthy[key] || 0;
      oldU[key] = _currentUnhealthy[key] || 0;
    }

    gsap.to({ t: 0 }, {
      t: 1, duration: duration, ease: 'power2.inOut',
      onUpdate: function () {
        var t = this.targets()[0].t;
        var blendedH = {};
        var blendedU = {};
        var j, k;
        for (j = 0; j < PILLARS.length; j++) {
          k = PILLARS[j];
          blendedH[k] = oldH[k] + (newHealthy[k] - oldH[k]) * t;
          blendedU[k] = oldU[k] + (newUnhealthy[k] - oldU[k]) * t;
        }

        // Update unhealthy dot positions
        for (var ri = 0; ri < RIGHT_INDICES.length; ri++) {
          var rIdx = RIGHT_INDICES[ri];
          var rScore = blendedU[PILLARS[rIdx]] || 0;
          var rPt = polarToXY(pillarAngle(rIdx), scoreToRadius(rScore));
          if (_uDots[ri]) {
            _uDots[ri].setAttribute('cx', rPt.x);
            _uDots[ri].setAttribute('cy', rPt.y);
          }
        }

        // Update healthy dot positions
        for (var li = 0; li < LEFT_INDICES.length; li++) {
          var lIdx = LEFT_INDICES[li];
          var lScore = blendedH[PILLARS[lIdx]] || 0;
          var lPt = polarToXY(pillarAngle(lIdx), scoreToRadius(lScore));
          if (_hDots[li]) {
            _hDots[li].setAttribute('cx', lPt.x);
            _hDots[li].setAttribute('cy', lPt.y);
          }
        }

        // Rebuild polygon paths
        var uPath = buildHalfPolygonPath(blendedU, RIGHT_INDICES);
        var hPath = buildHalfPolygonPath(blendedH, LEFT_INDICES);

        if (_uFill) _uFill.setAttribute('d', uPath);
        if (_uStroke) _uStroke.setAttribute('d', uPath);
        if (_hFill) _hFill.setAttribute('d', hPath);
        if (_hStroke) _hStroke.setAttribute('d', hPath);
      },
      onComplete: function () {
        _currentHealthy = newHealthy;
        _currentUnhealthy = newUnhealthy;

        // Move weakness pulse to new 2-lowest positions
        var sorted = [];
        for (var wi = 0; wi < NUM; wi++) {
          sorted.push({ idx: wi, score: newHealthy[PILLARS[wi]] || 0 });
        }
        sorted.sort(function (a, b) { return a.score - b.score; });

        for (var pi = 0; pi < Math.min(2, _wPulse.length); pi++) {
          var pScore = sorted[pi].score;
          var pPt = polarToXY(pillarAngle(sorted[pi].idx), scoreToRadius(pScore));
          _wPulse[pi].setAttribute('cx', pPt.x);
          _wPulse[pi].setAttribute('cy', pPt.y);
        }

        if (onComplete) onComplete();
      }
    });
  }

  return { render: render, update: update, PROFILES: PROFILES };
})();
