/**
 * HDL V2 Speedometer — Reusable Rate-of-Ageing Gauge
 *
 * Generates a QuickChart.io gauge URL for the rate of ageing.
 * Shared between the staged form and dashboard. The widget
 * (hdl-lead-magnet.js) keeps its own copy for self-containment.
 *
 * Usage:
 *   HDLSpeedometer.buildUrl(rate)                       → Stage 1 default (0.8-1.4)
 *   HDLSpeedometer.buildUrl(rate, { stage3: true })     → Stage 3 wide (0.5-2.0)
 *   HDLSpeedometer.buildUrl(rate, { minValue, maxValue })→ explicit override
 *
 * v0.23.0 — added stage3 / explicit bounds. Stage 1 calc returns 0.8-1.4 so
 * the default is unchanged. Stage 3 (calculate_full) returns 0.5-2.0 — old
 * code clamped to 0.8-1.4 and silently lopped extreme rates, contradicting
 * the stat card text rendered next to the gauge.
 *
 * @package HDL_Longevity_V2
 * @since 0.5.0
 */
window.HDLSpeedometer = (function () {
  'use strict';

  function buildUrl(rate, opts) {
    opts = opts || {};
    var w = parseInt(opts.width  || 380, 10);
    var h = parseInt(opts.height || 340, 10);

    // Bounds resolution. stage3 flag is the safe shortcut; explicit
    // minValue/maxValue still wins for callers that want full control.
    var minValue = (typeof opts.minValue === 'number') ? opts.minValue
                 : (opts.stage3 ? 0.5 : 0.8);
    var maxValue = (typeof opts.maxValue === 'number') ? opts.maxValue
                 : (opts.stage3 ? 2.0 : 1.4);

    var clamped = Math.max(minValue, Math.min(maxValue, parseFloat(rate.toFixed(2))));

    // Band edges proportional to the configured range so the gauge segments
    // visually carry the same "slower / average / faster" meaning regardless
    // of bounds. Slower/average split at 5% below 1.0; average/faster at 5%
    // above 1.0 — matches the prior 0.9 / 1.1 split in spirit.
    var slowerEdge  = Math.max(minValue + 0.05, 1.0 - 0.05 * (1.0 - minValue) / 0.2);
    var averageEdge = Math.min(maxValue - 0.05, 1.0 + 0.05 * (maxValue - 1.0) / 0.4);
    // Fallback to legacy 0.9 / 1.1 when bounds match Stage 1 default.
    if (minValue === 0.8 && maxValue === 1.4) { slowerEdge = 0.9; averageEdge = 1.1; }

    var interp, interpColor;
    if (clamped <= slowerEdge)       { interp = 'Slower';  interpColor = 'rgba(67, 191, 85, 1)'; }
    else if (clamped <= averageEdge) { interp = 'Average'; interpColor = 'rgba(65, 165, 238, 1)'; }
    else                              { interp = 'Faster';  interpColor = 'rgba(255, 111, 75, 1)'; }

    var cfg = {
      type: 'gauge',
      data: {
        labels: ['Slower', 'Average', 'Faster'],
        datasets: [{
          data: [slowerEdge, averageEdge, maxValue], value: clamped, minValue: minValue, maxValue: maxValue,
          backgroundColor: ['rgba(67,191,85,0.95)', 'rgba(65,165,238,0.95)', 'rgba(255,111,75,0.95)'],
          borderWidth: 1, borderColor: 'rgba(255,255,255,0.8)', borderRadius: 5
        }]
      },
      options: {
        layout: { padding: { top: 30, bottom: 15, left: 15, right: 15 } },
        plugins: {
          datalabels: { display: false }
        },
        needle: { radiusPercentage:2.5, widthPercentage:4.0, lengthPercentage:68, color:'#004F59', shadowColor:'rgba(0,79,89,0.4)', shadowBlur:8, shadowOffsetY:4, borderWidth:2, borderColor:'rgba(255,255,255,1.0)' },
        valueLabel: { display:true, fontSize:36, fontFamily:"'Inter',sans-serif", fontWeight:'bold', color:'#004F59', backgroundColor:'transparent', bottomMarginPercentage:-10, padding:8 },
        centerArea: { displayText:false, backgroundColor:'transparent' },
        arc: { borderWidth:0, padding:2, margin:3, roundedCorners:true },
        subtitle: { display:true, text:interp, color:interpColor, font:{size:20,weight:'bold',family:"'Inter',sans-serif"}, padding:{top:8} }
      }
    };
    return 'https://quickchart.io/chart?c=' + encodeURIComponent(JSON.stringify(cfg)) + '&w=' + w + '&h=' + h + '&bkg=white';
  }

  return { buildUrl: buildUrl };
})();
