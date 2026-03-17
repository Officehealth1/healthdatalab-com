/**
 * HealthDataLab — Health Trajectory Chart (Hero Fork)
 * Forked from hdl-trajectory-chart.js with data-hdl attributes on every
 * animatable element so GSAP can orchestrate a progressive reveal.
 *
 * Font-family changed to Inter to match site design.
 * IDs prefixed with hdl-hero- to avoid conflicts.
 */

var HDLTrajectoryChart = (function () {
  'use strict';

  // ─── CONFIGURATION ───────────────────────────────────────────
  var PEAK_AGE = 28;
  var CHART_MAX_AGE = 120;
  var DECLINE_EXPONENT = 1.8;

  var BANDS = [
    { id: 1, label: '5th',  rate: 1.50, peak: 62, endAge: 64,  color: '#e74c3c' },
    { id: 2, label: '10th', rate: 1.35, peak: 66, endAge: 69,  color: '#e67e22' },
    { id: 3, label: '20th', rate: 1.20, peak: 70, endAge: 74,  color: '#f0a500' },
    { id: 4, label: '35th', rate: 1.08, peak: 75, endAge: 78,  color: '#c4b34d' },
    { id: 5, label: '50th', rate: 1.00, peak: 80, endAge: 79,  color: '#95a5a6' },
    { id: 6, label: '65th', rate: 0.93, peak: 84, endAge: 87,  color: '#7dba7d' },
    { id: 7, label: '80th', rate: 0.86, peak: 88, endAge: 96,  color: '#48a999' },
    { id: 8, label: '90th', rate: 0.80, peak: 91, endAge: 105, color: '#2d8e82' },
    { id: 9, label: '95th', rate: 0.74, peak: 94, endAge: 115, color: '#1a6b5a' },
  ];

  var POP_IDX = 4;

  var MARGIN = { top: 30, right: 30, bottom: 50, left: 55 };
  var CW = 780;
  var CH = 440;
  var IW = CW - MARGIN.left - MARGIN.right;
  var IH = CH - MARGIN.top - MARGIN.bottom;

  // ─── CORE BAND MATH ─────────────────────────────────────────
  function getBandHealth(bandIdx, age) {
    var b = BANDS[bandIdx];
    if (age < 0) return null;
    if (age <= PEAK_AGE) {
      var x = age / PEAK_AGE;
      return b.peak * x * (2 - x);
    }
    var bioAge = PEAK_AGE + (age - PEAK_AGE) * b.rate;
    var yrs = bioAge - PEAK_AGE;
    var total = (b.endAge * b.rate) - PEAK_AGE + 10;
    var prog = Math.min(1, yrs / total);
    var curve = Math.pow(prog, DECLINE_EXPONENT);
    var h = b.peak - (b.peak * curve);
    return h < 1 ? null : Math.max(0, h);
  }

  function interpolateHealthForRate(age, rate) {
    for (var i = 0; i < BANDS.length - 1; i++) {
      if (rate >= BANDS[i + 1].rate && rate <= BANDS[i].rate) {
        var frac = (BANDS[i].rate - rate) / (BANDS[i].rate - BANDS[i + 1].rate);
        var hL = getBandHealth(i, age);
        var hU = getBandHealth(i + 1, age);
        if (hL === null || hU === null) return null;
        return hL + frac * (hU - hL);
      }
    }
    if (rate >= BANDS[0].rate) return getBandHealth(0, age);
    if (rate <= BANDS[8].rate) return getBandHealth(8, age);
    return getBandHealth(4, age);
  }

  // ─── FUTURE PROJECTIONS ────────────────────────────────────
  function generateOptimistic(startAge, startHealth, currentRate) {
    var improvedRate = Math.max(0.70, currentRate * 0.75);
    var improvementPotential = currentRate >= 1.1
      ? Math.min(18, (currentRate - 0.85) * 20)
      : currentRate >= 1.0
        ? Math.min(12, (currentRate - 0.85) * 15)
        : Math.min(7, Math.max(2, (100 - startHealth) * 0.12));
    var ceiling = Math.min(95, startHealth + improvementPotential);
    var riseDuration = Math.min(8, Math.max(3, improvementPotential * 0.5));
    var endAge = improvedRate < 0.80 ? 102
      : improvedRate < 0.85 ? 98
      : improvedRate < 0.90 ? 94
      : improvedRate < 0.95 ? 90
      : improvedRate < 1.00 ? 86
      : 80;
    var effectiveEnd = Math.max(startAge + 12, endAge);
    var declineDuration = effectiveEnd - (startAge + riseDuration);
    var peakHealth = ceiling;
    var floorHealth = Math.max(30, peakHealth * 0.35);
    var points = [];
    for (var age = startAge; age <= effectiveEnd; age += 0.5) {
      var yearsFromNow = age - startAge;
      var health;
      if (yearsFromNow <= riseDuration) {
        var riseProgress = yearsFromNow / riseDuration;
        var sineFactor = Math.sin(riseProgress * Math.PI / 2);
        health = startHealth + (peakHealth - startHealth) * sineFactor;
      } else {
        var declineYears = yearsFromNow - riseDuration;
        var declineProgress = Math.min(1, declineYears / declineDuration);
        var declineCurve = Math.pow(declineProgress, 1.9);
        health = peakHealth - (peakHealth - floorHealth) * declineCurve;
      }
      if (health < floorHealth) break;
      points.push({ age: age, health: health });
    }
    return { points: points, peakHealth: peakHealth, endAge: effectiveEnd, improvedRate: improvedRate };
  }

  function generatePessimistic(startAge, startHealth, currentRate) {
    var worsenedRate = Math.min(1.65, currentRate * 1.30);
    var endAge = worsenedRate >= 1.50 ? 62
      : worsenedRate >= 1.35 ? 67
      : worsenedRate >= 1.20 ? 72
      : worsenedRate >= 1.10 ? 76
      : worsenedRate >= 1.00 ? 80
      : 85;
    var effectiveEnd = Math.max(startAge + 10, endAge);
    var remainingYears = effectiveEnd - startAge;
    var floorHealth = 3;
    var declineExp = worsenedRate >= 1.30 ? 1.4
      : worsenedRate >= 1.10 ? 1.5
      : 1.6;
    var points = [];
    for (var age = startAge; age <= effectiveEnd; age += 0.5) {
      var yearsFromNow = age - startAge;
      var progress = yearsFromNow / remainingYears;
      if (progress > 1.0) break;
      var declineCurve = Math.pow(progress, declineExp);
      var healthRange = startHealth - floorHealth;
      var health = startHealth - (healthRange * declineCurve);
      if (health < floorHealth) break;
      points.push({ age: age, health: Math.max(floorHealth, health) });
    }
    return { points: points, endAge: effectiveEnd, worsenedRate: worsenedRate };
  }

  // ─── USER HISTORY LINE ────────────────────────────────────
  function generateUserHistory(effectiveAge, rate) {
    var pts = [];
    for (var a = 0; a <= effectiveAge; a += 0.5) {
      var h = interpolateHealthForRate(a, rate);
      if (h === null) break;
      pts.push({ age: a, health: h });
    }
    return pts;
  }

  // ─── BAND PATH DATA ──────────────────────────────────────
  function bandPathData(idx, startAge, endAge) {
    var pts = [];
    var s = startAge || 0;
    var e = endAge || CHART_MAX_AGE;
    for (var a = s; a <= e; a += 0.5) {
      var h = getBandHealth(idx, a);
      if (h === null) break;
      pts.push({ age: a, health: h });
    }
    return pts;
  }

  // ─── SVG HELPERS ──────────────────────────────────────────
  function sx(age) { return MARGIN.left + (age / CHART_MAX_AGE) * IW; }
  function sy(health) { return MARGIN.top + IH - (health / 100) * IH; }

  function makePath(pts) {
    var parts = [];
    for (var i = 0; i < pts.length; i++) {
      if (pts[i].health === null || pts[i].health === undefined) continue;
      var cmd = parts.length === 0 ? 'M' : 'L';
      parts.push(cmd + sx(pts[i].age).toFixed(1) + ',' + sy(pts[i].health).toFixed(1));
    }
    return parts.join(' ');
  }

  function svgEl(tag, attrs, children) {
    var ns = 'http://www.w3.org/2000/svg';
    var el = document.createElementNS(ns, tag);
    if (attrs) {
      for (var k in attrs) {
        if (attrs.hasOwnProperty(k)) {
          el.setAttribute(k, attrs[k]);
        }
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

  // ─── MAIN RENDER ──────────────────────────────────────────
  function render(selector, options) {
    var container = typeof selector === 'string'
      ? document.querySelector(selector)
      : selector;

    if (!container) {
      console.error('HDLTrajectoryChart: container not found:', selector);
      return;
    }

    var chronoAge = options.chronoAge || 50;
    var agingRate = options.agingRate || 1.0;
    var prevAssessments = options.previousAssessments || [];
    var showBands = options.showBands !== false;
    var showProjections = options.showProjections !== false;

    var allAssessments = prevAssessments.length > 0
      ? prevAssessments
      : [{ monthsOffset: 0, rate: agingRate }];

    var currentAssessment = allAssessments[allAssessments.length - 1];
    var currentRate = currentAssessment.rate;
    var effectiveAge = chronoAge + (currentAssessment.monthsOffset || 0) / 12;

    var currentHealth = interpolateHealthForRate(effectiveAge, currentRate) || 50;
    var bioAge = (effectiveAge * currentRate).toFixed(1);
    var ageShift = (effectiveAge - effectiveAge * currentRate).toFixed(1);

    var currentHistory = generateUserHistory(effectiveAge, currentRate);
    var optimistic = generateOptimistic(effectiveAge, currentHealth, currentRate);
    var pessimistic = generatePessimistic(effectiveAge, currentHealth, currentRate);

    // ── Build SVG ──────────────────────────────────────────
    var svg = svgEl('svg', {
      viewBox: '0 0 ' + CW + ' ' + CH,
      style: "width:100%;height:auto;font-family:'Inter',system-ui,-apple-system,sans-serif;"
    });

    // ── Defs ───────────────────────────────────────────────
    var defs = svgEl('defs');

    var grad1 = svgEl('linearGradient', { id: 'hdl-hero-indepZone', x1: '0', y1: '0', x2: '0', y2: '1' });
    grad1.appendChild(svgEl('stop', { offset: '0%', 'stop-color': '#94a3b8', 'stop-opacity': '0.06' }));
    grad1.appendChild(svgEl('stop', { offset: '100%', 'stop-color': '#94a3b8', 'stop-opacity': '0.15' }));
    defs.appendChild(grad1);

    var grad2 = svgEl('linearGradient', { id: 'hdl-hero-criticalZone', x1: '0', y1: '0', x2: '0', y2: '1' });
    grad2.appendChild(svgEl('stop', { offset: '0%', 'stop-color': '#78879a', 'stop-opacity': '0.18' }));
    grad2.appendChild(svgEl('stop', { offset: '100%', 'stop-color': '#64748b', 'stop-opacity': '0.32' }));
    defs.appendChild(grad2);

    var clipPast = svgEl('clipPath', { id: 'hdl-hero-clipPast' });
    clipPast.appendChild(svgEl('rect', {
      x: MARGIN.left, y: MARGIN.top,
      width: sx(effectiveAge) - MARGIN.left, height: IH
    }));
    defs.appendChild(clipPast);

    var clipFuture = svgEl('clipPath', { id: 'hdl-hero-clipFuture' });
    clipFuture.appendChild(svgEl('rect', {
      x: sx(effectiveAge), y: MARGIN.top,
      width: CW - MARGIN.right - sx(effectiveAge), height: IH
    }));
    defs.appendChild(clipFuture);

    var greenGlow = svgEl('filter', { id: 'hdl-hero-greenGlow' });
    greenGlow.appendChild(svgEl('feGaussianBlur', { stdDeviation: '2', result: 'blur' }));
    var gMerge = svgEl('feMerge');
    gMerge.appendChild(svgEl('feMergeNode', { 'in': 'blur' }));
    gMerge.appendChild(svgEl('feMergeNode', { 'in': 'SourceGraphic' }));
    greenGlow.appendChild(gMerge);
    defs.appendChild(greenGlow);

    var redGlow = svgEl('filter', { id: 'hdl-hero-redGlow' });
    redGlow.appendChild(svgEl('feGaussianBlur', { stdDeviation: '2', result: 'blur' }));
    var rMerge = svgEl('feMerge');
    rMerge.appendChild(svgEl('feMergeNode', { 'in': 'blur' }));
    rMerge.appendChild(svgEl('feMergeNode', { 'in': 'SourceGraphic' }));
    redGlow.appendChild(rMerge);
    defs.appendChild(redGlow);

    svg.appendChild(defs);

    // ── Grid lines ───────────────────────────────────────────
    var gridHealthValues = [0, 20, 40, 50, 60, 80, 100];
    for (var gi = 0; gi < gridHealthValues.length; gi++) {
      var gh = gridHealthValues[gi];
      svg.appendChild(svgEl('line', {
        x1: MARGIN.left, x2: CW - MARGIN.right,
        y1: sy(gh), y2: sy(gh),
        stroke: gh === 50 ? '#cbd5e1' : '#eef1f5',
        'stroke-width': gh === 50 ? 0.8 : 0.4,
        'data-hdl': 'grid'
      }));
      svg.appendChild(svgEl('text', {
        x: MARGIN.left - 7, y: sy(gh) + 3,
        'text-anchor': 'end', 'font-size': '11', fill: '#94a3b8',
        'data-hdl': 'grid-label'
      }, gh + '%'));
    }

    var gridAgeValues = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120];
    for (var ai = 0; ai < gridAgeValues.length; ai++) {
      var ga = gridAgeValues[ai];
      svg.appendChild(svgEl('line', {
        x1: sx(ga), x2: sx(ga),
        y1: MARGIN.top, y2: CH - MARGIN.bottom,
        stroke: '#eef1f5', 'stroke-width': 0.4,
        'data-hdl': 'grid'
      }));
      svg.appendChild(svgEl('text', {
        x: sx(ga), y: CH - MARGIN.bottom + 14,
        'text-anchor': 'middle', 'font-size': '11', fill: '#94a3b8',
        'data-hdl': 'grid-label'
      }, '' + ga));
    }

    // Axis labels
    svg.appendChild(svgEl('text', {
      x: CW / 2, y: CH - 5,
      'text-anchor': 'middle', 'font-size': '12.5', fill: '#64748b', 'font-weight': '500',
      'data-hdl': 'axis-label'
    }, 'Age (years)'));

    var yLabel = svgEl('text', {
      x: 12, y: CH / 2,
      'text-anchor': 'middle', 'font-size': '12.5', fill: '#64748b', 'font-weight': '500',
      transform: 'rotate(-90, 12, ' + (CH / 2) + ')',
      'data-hdl': 'axis-label'
    }, 'Strength & Wellbeing (%)');
    svg.appendChild(yLabel);

    // ── Independence / Critical zones ────────────────────────
    svg.appendChild(svgEl('rect', {
      x: MARGIN.left, y: sy(50),
      width: IW, height: sy(20) - sy(50),
      fill: 'url(#hdl-hero-indepZone)',
      'data-hdl': 'zone'
    }));
    svg.appendChild(svgEl('rect', {
      x: MARGIN.left, y: sy(20),
      width: IW, height: sy(0) - sy(20),
      fill: 'url(#hdl-hero-criticalZone)',
      'data-hdl': 'zone'
    }));
    svg.appendChild(svgEl('text', {
      x: CW - MARGIN.right - 4, y: sy(35),
      'text-anchor': 'end', 'font-size': '10', fill: '#94a3b8',
      'font-style': 'italic', opacity: '0.6',
      'data-hdl': 'zone-label'
    }, 'Independence at risk'));
    svg.appendChild(svgEl('text', {
      x: CW - MARGIN.right - 4, y: sy(10),
      'text-anchor': 'end', 'font-size': '10', fill: '#78879a',
      'font-style': 'italic', opacity: '0.6',
      'data-hdl': 'zone-label'
    }, 'Critical'));

    // ── PAST BANDS ───────────────────────────────────────────
    if (showBands) {
      var pastGroup = svgEl('g', { 'clip-path': 'url(#hdl-hero-clipPast)', 'data-hdl': 'band-past' });
      for (var bi = 0; bi < BANDS.length; bi++) {
        var bPath = makePath(bandPathData(bi));
        var isPop = bi === POP_IDX;
        pastGroup.appendChild(svgEl('path', {
          d: bPath, fill: 'none', stroke: BANDS[bi].color,
          'stroke-width': isPop ? 2 : 0.9,
          'stroke-dasharray': isPop ? 'none' : '3,3',
          opacity: isPop ? 0.6 : 0.4
        }));
      }
      svg.appendChild(pastGroup);

      // Future bands
      var futureGroup = svgEl('g', { 'clip-path': 'url(#hdl-hero-clipFuture)', 'data-hdl': 'band-future' });
      for (var bj = 0; bj < BANDS.length; bj++) {
        var fbPath = makePath(bandPathData(bj));
        var isPopF = bj === POP_IDX;
        futureGroup.appendChild(svgEl('path', {
          d: fbPath, fill: 'none', stroke: BANDS[bj].color,
          'stroke-width': isPopF ? 1.4 : 0.6,
          'stroke-dasharray': isPopF ? '6,4' : '3,4',
          opacity: isPopF ? 0.3 : 0.15
        }));
      }
      svg.appendChild(futureGroup);

      // Band labels on past side
      for (var bl = 0; bl < BANDS.length; bl++) {
        var labelAge = Math.min(effectiveAge - 4, 18);
        var labelH = getBandHealth(bl, labelAge);
        if (labelH === null || labelH < 8 || bl === POP_IDX) continue;
        svg.appendChild(svgEl('text', {
          x: sx(labelAge), y: sy(labelH) - 3,
          'font-size': '9', fill: BANDS[bl].color, opacity: '0.4', 'font-weight': '500',
          'data-hdl': 'band-label'
        }, BANDS[bl].label));
      }
      // Pop avg label
      var popLabelH = getBandHealth(POP_IDX, 15);
      if (popLabelH) {
        svg.appendChild(svgEl('text', {
          x: sx(15), y: sy(popLabelH) - 4,
          'font-size': '9.5', fill: '#95a5a6', opacity: '0.5', 'font-weight': '600',
          'data-hdl': 'band-label'
        }, '50th (avg)'));
      }
    }

    // ── "NOW" divider ────────────────────────────────────────
    svg.appendChild(svgEl('line', {
      x1: sx(effectiveAge), x2: sx(effectiveAge),
      y1: MARGIN.top, y2: CH - MARGIN.bottom,
      stroke: '#0d7377', 'stroke-width': 1, 'stroke-dasharray': '4,3', opacity: '0.35',
      'data-hdl': 'now-line'
    }));
    svg.appendChild(svgEl('text', {
      x: sx(effectiveAge), y: MARGIN.top - 5,
      'text-anchor': 'middle', 'font-size': '11', fill: '#0d7377',
      'font-weight': '600', opacity: '0.65',
      'data-hdl': 'now-label'
    }, 'Now'));

    // Region labels
    svg.appendChild(svgEl('text', {
      x: sx(effectiveAge / 2), y: MARGIN.top + 12,
      'text-anchor': 'middle', 'font-size': '11', fill: '#94a3b8',
      opacity: '0.5', 'font-weight': '500',
      'data-hdl': 'region-label'
    }, 'Your Health History'));
    svg.appendChild(svgEl('text', {
      x: sx(effectiveAge) + (CW - MARGIN.right - sx(effectiveAge)) / 2,
      y: MARGIN.top + 12,
      'text-anchor': 'middle', 'font-size': '11', fill: '#94a3b8',
      opacity: '0.5', 'font-weight': '500',
      'data-hdl': 'region-label'
    }, 'Future Projections'));

    // ── PESSIMISTIC PROJECTION ───────────────────────────────
    if (showProjections && pessimistic.points.length > 1) {
      var redLast = pessimistic.points[pessimistic.points.length - 1];
      svg.appendChild(svgEl('path', {
        d: makePath(pessimistic.points) +
          ' L' + sx(redLast.age).toFixed(1) + ',' + sy(0).toFixed(1) +
          ' L' + sx(effectiveAge).toFixed(1) + ',' + sy(0).toFixed(1) + ' Z',
        fill: 'rgba(217,79,79,0.04)',
        'data-hdl': 'pessimistic-fill'
      }));
      svg.appendChild(svgEl('path', {
        d: makePath(pessimistic.points),
        fill: 'none', stroke: '#d94f4f', 'stroke-width': 2.2,
        'stroke-dasharray': '8,4', opacity: '0.75',
        filter: 'url(#hdl-hero-redGlow)',
        'data-hdl': 'pessimistic-line'
      }));
    }

    // ── OPTIMISTIC PROJECTION ────────────────────────────────
    if (showProjections && optimistic.points.length > 1) {
      var greenLast = optimistic.points[optimistic.points.length - 1];
      svg.appendChild(svgEl('path', {
        d: makePath(optimistic.points) +
          ' L' + sx(greenLast.age).toFixed(1) + ',' + sy(0).toFixed(1) +
          ' L' + sx(effectiveAge).toFixed(1) + ',' + sy(0).toFixed(1) + ' Z',
        fill: 'rgba(39,174,96,0.04)',
        'data-hdl': 'optimistic-fill'
      }));
      svg.appendChild(svgEl('path', {
        d: makePath(optimistic.points),
        fill: 'none', stroke: '#27ae60', 'stroke-width': 2.4,
        'stroke-dasharray': '5,2,5,2', opacity: '0.8',
        filter: 'url(#hdl-hero-greenGlow)',
        'data-hdl': 'optimistic-line'
      }));

      // Peak indicator
      var peakPt = optimistic.points[0];
      for (var pk = 1; pk < optimistic.points.length; pk++) {
        if (optimistic.points[pk].health > peakPt.health) peakPt = optimistic.points[pk];
      }
      var peakLabelY = sy(Math.min(98, peakPt.health + 8));
      var peakG = svgEl('g', { opacity: '0.65', 'data-hdl': 'optimistic-peak' });
      peakG.appendChild(svgEl('circle', {
        cx: sx(peakPt.age), cy: sy(peakPt.health), r: 2.5, fill: '#27ae60'
      }));
      peakG.appendChild(svgEl('line', {
        x1: sx(peakPt.age), y1: sy(peakPt.health) - 3,
        x2: sx(peakPt.age) + 14, y2: peakLabelY + 3,
        stroke: '#27ae60', 'stroke-width': 0.6, opacity: '0.5'
      }));
      peakG.appendChild(svgEl('text', {
        x: sx(peakPt.age) + 16, y: peakLabelY,
        'font-size': '11', fill: '#27ae60', 'font-weight': '600'
      }, 'Peak ' + peakPt.health.toFixed(0) + '%'));
      svg.appendChild(peakG);
    }

    // ── USER HISTORY LINE ────────────────────────────────────
    if (currentHistory.length > 1) {
      svg.appendChild(svgEl('path', {
        d: makePath(currentHistory),
        fill: 'none', stroke: '#1565c0', 'stroke-width': 2.8,
        'stroke-linecap': 'round',
        'data-hdl': 'user-line'
      }));
    }

    // ── ANCHOR PULSE RINGS (behind solid dots) ─────────────
    svg.appendChild(svgEl('circle', {
      cx: sx(effectiveAge), cy: sy(currentHealth),
      r: 7, fill: 'none', stroke: '#1565c0', 'stroke-width': 1.5, opacity: '0',
      'data-hdl': 'anchor-pulse'
    }));
    svg.appendChild(svgEl('circle', {
      cx: sx(effectiveAge), cy: sy(currentHealth),
      r: 7, fill: 'none', stroke: '#1565c0', 'stroke-width': 1.5, opacity: '0',
      'data-hdl': 'anchor-pulse'
    }));

    // ── ANCHOR DOT ───────────────────────────────────────────
    svg.appendChild(svgEl('circle', {
      cx: sx(effectiveAge), cy: sy(currentHealth),
      r: 7, fill: 'white', stroke: '#1565c0', 'stroke-width': 2.5,
      'data-hdl': 'anchor-dot'
    }));
    svg.appendChild(svgEl('circle', {
      cx: sx(effectiveAge), cy: sy(currentHealth),
      r: 3, fill: '#1565c0',
      'data-hdl': 'anchor-dot'
    }));

    // ── RATE BADGE (wrapped in <g> for animation) ────────────
    var isUnhealthy = currentRate >= 1.0;
    var badgeBg = currentRate >= 1.15 ? '#d94f4f' : currentRate >= 1.0 ? '#e67e22' : '#0d7377';
    var badgeIcon = isUnhealthy ? '\u26A0' : '\u2713';
    var badgeLabel = isUnhealthy ? 'Accelerated' : 'Slower';
    var badgeX = sx(effectiveAge) - 149;
    var badgeY = Math.min(sy(currentHealth) + 50, CH - MARGIN.bottom - 42);

    var badgeG = svgEl('g', { 'data-hdl': 'badge' });
    badgeG.appendChild(svgEl('line', {
      x1: sx(effectiveAge), y1: sy(currentHealth) + 7,
      x2: badgeX + 68, y2: badgeY,
      stroke: badgeBg, 'stroke-width': 0.7, opacity: '0.35',
      'stroke-dasharray': '3,2'
    }));
    badgeG.appendChild(svgEl('rect', {
      x: badgeX, y: badgeY, width: 135, height: 36, rx: 5, fill: badgeBg
    }));
    badgeG.appendChild(svgEl('text', {
      x: badgeX + 7, y: badgeY + 14,
      'font-size': '11', fill: 'rgba(255,255,255,0.8)', 'font-weight': '500'
    }, badgeIcon + ' ' + badgeLabel + ' Aging'));
    badgeG.appendChild(svgEl('text', {
      x: badgeX + 7, y: badgeY + 28,
      'font-size': '13.5', fill: 'white', 'font-weight': '700'
    }, 'Rate: ' + currentRate.toFixed(2) + '\u00d7'));
    svg.appendChild(badgeG);

    // ── LEGEND ───────────────────────────────────────────────
    var legendG = svgEl('g', {
      transform: 'translate(' + (MARGIN.left + 6) + ',' + (MARGIN.top + 24) + ')',
      'data-hdl': 'legend'
    });
    var legendH = showProjections ? 76 : 42;
    legendG.appendChild(svgEl('rect', {
      x: 0, y: 0, width: 150, height: legendH, rx: 4,
      fill: 'white', 'fill-opacity': '0.92', stroke: '#e8ecf0', 'stroke-width': 0.5
    }));
    legendG.appendChild(svgEl('line', { x1: 8, x2: 26, y1: 14, y2: 14, stroke: '#1565c0', 'stroke-width': 2.5 }));
    legendG.appendChild(svgEl('text', { x: 32, y: 18, 'font-size': '11', fill: '#334155' }, 'Your Trajectory'));
    legendG.appendChild(svgEl('line', { x1: 8, x2: 26, y1: 30, y2: 30, stroke: '#95a5a6', 'stroke-width': 1.5 }));
    legendG.appendChild(svgEl('text', { x: 32, y: 34, 'font-size': '11', fill: '#334155' }, 'Population Avg'));

    if (showProjections) {
      legendG.appendChild(svgEl('line', { x1: 8, x2: 26, y1: 46, y2: 46, stroke: '#27ae60', 'stroke-width': 1.8, 'stroke-dasharray': '5,2' }));
      legendG.appendChild(svgEl('text', { x: 32, y: 50, 'font-size': '11', fill: '#334155' }, 'With Changes'));
      legendG.appendChild(svgEl('line', { x1: 8, x2: 26, y1: 62, y2: 62, stroke: '#d94f4f', 'stroke-width': 1.8, 'stroke-dasharray': '8,4' }));
      legendG.appendChild(svgEl('text', { x: 32, y: 66, 'font-size': '11', fill: '#334155' }, 'Without Changes'));
    }

    svg.appendChild(legendG);

    // ── INSERT INTO DOM ──────────────────────────────────────
    container.innerHTML = '';
    container.appendChild(svg);

    return {
      currentHealth: currentHealth,
      bioAge: parseFloat(bioAge),
      ageShift: parseFloat(ageShift),
      agingRate: currentRate,
      optimistic: { peakHealth: optimistic.peakHealth, endAge: optimistic.endAge },
      pessimistic: { endAge: pessimistic.endAge }
    };
  }

  return {
    render: render,
    interpolateHealthForRate: interpolateHealthForRate,
    generateOptimistic: generateOptimistic,
    generatePessimistic: generatePessimistic,
    getBandHealth: getBandHealth,
    BANDS: BANDS
  };

})();
