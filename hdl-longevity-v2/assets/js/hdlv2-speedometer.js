/**
 * HDL V2 Speedometer — Reusable Rate-of-Ageing Gauge
 *
 * Generates a QuickChart.io gauge URL for the rate of ageing.
 * Shared between the staged form and dashboard. The widget
 * (hdl-lead-magnet.js) keeps its own copy for self-containment.
 *
 * Usage: HDLSpeedometer.buildUrl(rate) → string (image URL)
 *
 * @package HDL_Longevity_V2
 * @since 0.5.0
 */
window.HDLSpeedometer = (function () {
  'use strict';

  function buildUrl(rate) {
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
          backgroundColor: ['rgba(67,191,85,0.95)', 'rgba(65,165,238,0.95)', 'rgba(255,111,75,0.95)'],
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

  return { buildUrl: buildUrl };
})();
