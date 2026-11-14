#!/usr/bin/env python3
"""Final report layout (plan 2026-11-14): the candidate Final template, built from
the 2026-11-14 backup of the production template (a091f83e) by exact text edits.

    python3 -I candidate.py <backup.html> <candidate.html>

Every edit names the defect it fixes and how many times its text must occur;
a count that does not match stops the build, so a production template that
changed since the backup is never patched blindly. The backup and the
candidate live in docs/pdfmonkey/ (git-ignored), like tests/why-reasoning.
"""
import hashlib
import sys

EDITS = []


def edit(defect, old, new, count=1):
    EDITS.append((defect, old, new, count))


# --- 2a: page numbers from two values -------------------------------------
# The choices page after page 12 exists only when why_picks is set; every page
# after it, every contents row after Objectives and every "Page 13" move by one.
edit('2a', """{%- comment -%} Liquid newline helper for splitting milestone strings {%- endcomment -%}
""", """{%- comment -%} Liquid newline helper for splitting milestone strings {%- endcomment -%}

{%- comment -%} 2026-11-14 layout: the choices page after page 12 is printed only when
   why_picks is set; pages after it are numbered on by pg_shift. {%- endcomment -%}
{%- if why_picks != blank -%}{%- assign pg_shift = 1 -%}{%- assign pg_total = 20 -%}{%- else -%}{%- assign pg_shift = 0 -%}{%- assign pg_total = 19 -%}{%- endif -%}
""")
for n in range(13, 20):
    edit('2a', '>%d / 19</span>' % n, '>{{ %d | plus: pg_shift }} / {{ pg_total }}</span>' % n, 2)
for n in range(2, 13):
    edit('2a', '>%02d / 19</span>' % n, '>%02d / {{ pg_total }}</span>' % n, 2)
for n in range(13, 19):
    edit('2a', '<span class="toc-page">%d</span>' % n, '<span class="toc-page">{{ %d | plus: pg_shift }}</span>' % n)
edit('2a', 'Page 13', 'Page {{ 13 | plus: pg_shift }}', 9)

# --- 2b: anchor 01 back to the WHY sentence; the choices on their own page (D1, D7)
CHOICES_OLD = ('{% if why_picks != blank %}<br><br><strong>What {{ cover_name | split: " " | first }} chose:</strong> '
               '{% if why_picks_people != blank %}<strong>People I love:</strong> {{ why_picks_people | replace: " | ", "; " }}. {% endif %}'
               '{% if why_picks_freedom != blank %}<strong>My freedom:</strong> {{ why_picks_freedom | replace: " | ", "; " }}. {% endif %}'
               '{% if why_picks_things != blank %}<strong>Things I love:</strong> {{ why_picks_things | replace: " | ", "; " }}. {% endif %}'
               '{% if why_picks_people == blank and why_picks_freedom == blank and why_picks_things == blank %}{{ why_picks | replace: " | ", "; " }}. {% endif %}'
               '{% if why_abilities != blank %}<br><strong>Abilities these depend on most:</strong> {{ why_abilities | replace: " | ", "; " }}{% endif %}'
               '{% if why_focus_areas != blank %}<br><strong>Focus areas:</strong> {{ why_focus_areas }}{% endif %}'
               '{% if why_people != blank %}<br><strong>For:</strong> {{ why_people }}{% endif %}'
               '{% if why_chart_url != blank %}<img src="{{ why_chart_url }}" alt="What the choices need against where they are now, out of 5" '
               'style="display:block;width:55mm;height:auto;margin:8px auto 0">{% endif %}{% endif %}')
edit('2b', CHOICES_OLD, '')
edit('2b', """<span class="toc-title">Objectives &middot; The five anchors</span><span class="toc-page">12</span></li>
""", """<span class="toc-title">Objectives &middot; The five anchors</span><span class="toc-page">12</span></li>
      {% if why_picks != blank %}<li class="toc-row toc-row--sub"><span class="toc-title">What {{ cover_name | split: " " | first }} chose</span><span class="toc-page">13</span></li>{% endif %}
""")
edit('2b', """<!-- ============================================================
     PAGE 13 · ACTIONABLE RECOMMENDATIONS""", """<!-- ============================================================
     PAGE 13 · WHAT THE CLIENT CHOSE (only when why_picks is set; was inside
     anchor 01 on page 12, which it pushed past the page with ten choices)
     ============================================================ -->
{% if why_picks != blank %}
<section class="page">
  <div class="page__header">
    <span><strong>HealthDataLab</strong></span>
    <span>05 &middot; Objectives</span>
    <span>13 / {{ pg_total }}</span>
  </div>
  <div class="page__content">
    <div class="h2" style="margin-top: 0;">What {{ cover_name | split: " " | first }} chose</div>
    <p class="body-text">{% if why_picks_people != blank %}<strong>People I love:</strong> {{ why_picks_people | replace: " | ", "; " }}. {% endif %}{% if why_picks_freedom != blank %}<strong>My freedom:</strong> {{ why_picks_freedom | replace: " | ", "; " }}. {% endif %}{% if why_picks_things != blank %}<strong>Things I love:</strong> {{ why_picks_things | replace: " | ", "; " }}. {% endif %}{% if why_picks_people == blank and why_picks_freedom == blank and why_picks_things == blank %}{{ why_picks | replace: " | ", "; " }}. {% endif %}</p>
    {% if why_abilities != blank %}<p class="body-text"><strong>Abilities these depend on most:</strong> {{ why_abilities | replace: " | ", "; " }}</p>{% endif %}
    {% if why_focus_areas != blank or why_people != blank %}<p class="body-text">{% if why_focus_areas != blank %}<strong>Focus areas:</strong> {{ why_focus_areas }}{% endif %}{% if why_focus_areas != blank and why_people != blank %}<br>{% endif %}{% if why_people != blank %}<strong>For:</strong> {{ why_people }}{% endif %}</p>{% endif %}
    {% if why_chart_url != blank %}<img src="{{ why_chart_url }}" alt="What the choices need against where they are now, out of 5" style="display:block;width:100mm;height:auto;margin:6mm auto 0">{% endif %}
  </div>
  <div class="page__footer">
    <span class="page__footer-brand">HealthDataLab.com</span>
    <span class="page__footer-num">13 / {{ pg_total }}</span>
  </div>
</section>
{% endif %}


<!-- ============================================================
     PAGE 13 · ACTIONABLE RECOMMENDATIONS""")

# --- 2c: the gauge's band words in a row under the picture, not over it (D2) --
edit('2c', """.gauge-overlay {
  position: absolute;
  left: 4mm;
  right: 4mm;
  bottom: 11mm;
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  font-family: var(--body);
  pointer-events: none;
}""", """/* 2026-11-14 layout: a row under the picture. Placed over it, the words sat on
   the green and red arc ends and under the needle hub. */
.gauge-bands {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  margin: 1mm 4mm 0;
  font-family: var(--body);
}""")
edit('2c', 'gauge-overlay', 'gauge-bands', 22)
edit('2c', '<div class="gauge-bands" aria-hidden="true">', '<div class="gauge-bands">')
# The row is about 6 mm tall; the gauge picture carries its own white top margin.
edit('2c', """.aging-rate-hero {
  text-align: center;
  margin: 6mm 0 4mm;
}""", """.aging-rate-hero {
  text-align: center;
  margin: 2mm 0 4mm;
}""")

# --- 2d: an input the client left empty reads as missing (D3) -------------
# Every Stage 3 field may be skipped (hdlv2-staged-form.js completeStage3: "All
# fields are optional"; the calculator then sends ''). The template turned '' into
# 0: "/5 URGENT" on the inputs page, "Physical activity /5" under Areas to lift,
# and averages pulled down (Cardio 2.0 from one 4/5 and a missing BP).
def domain(name, fields, named=True):
    """Liquid: NAME_avg over the given inputs that are present, NAME_n, NAME_used."""
    out = ['{%- assign NAME_sum = 0 -%}{%- assign NAME_n = 0 -%}' + ('{%- assign NAME_used = "" -%}' if named else '')]
    for key, var, short in fields:
        line = ('{%- if KEY != blank -%}{%- assign NAME_sum = NAME_sum | plus: VAR -%}{%- assign NAME_n = NAME_n | plus: 1 -%}'
                + ('{%- assign NAME_used = NAME_used | append: ", SHORT" -%}' if named else '') + '{%- endif -%}')
        out.append(line.replace('KEY', key).replace('VAR', var).replace('SHORT', short))
    out.append('{%- if NAME_n > 0 -%}{%- assign NAME_avg = NAME_sum | times: 10 | divided_by: NAME_n | divided_by: 10.0 -%}{%- endif -%}')
    if named:
        out.append('{%- assign NAME_used = NAME_used | remove_first: ", " -%}')
    return '\n'.join(out).replace('NAME', name) + '\n'


LIFESTYLE = ['activity', 'sleep_dur', 'sleep_qual', 'stress', 'social', 'diet', 'alcohol', 'smoking',
             'cognitive', 'supplements', 'sunlight', 'hydration']
edit('2d', """{%- comment -%} Domain averages — *10 then ÷N then ÷10.0 to get one-decimal float {%- endcomment -%}
{%- assign cardio_sum = s_hr | plus: s_bp -%}
{%- assign cardio_avg = cardio_sum | times: 10 | divided_by: 2 | divided_by: 10.0 -%}

{%- assign body_comp_sum = s_bmi | plus: s_whr | plus: s_whtr -%}
{%- assign body_comp_avg = body_comp_sum | times: 10 | divided_by: 3 | divided_by: 10.0 -%}

{%- assign physical_sum = s_sit_stand | plus: s_breath | plus: s_balance -%}
{%- assign physical_avg = physical_sum | times: 10 | divided_by: 3 | divided_by: 10.0 -%}

{%- assign lifestyle_sum = s_activity | plus: s_sleep_dur | plus: s_sleep_qual | plus: s_stress | plus: s_social | plus: s_diet | plus: s_alcohol | plus: s_smoking | plus: s_cognitive | plus: s_supplements | plus: s_sunlight | plus: s_hydration -%}
{%- assign lifestyle_avg = lifestyle_sum | times: 10 | divided_by: 12 | divided_by: 10.0 -%}
""", """{%- comment -%} Domain averages — *10 then ÷N then ÷10.0 to get one-decimal float.
   2026-11-14 layout: N counts only the inputs the client gave (any Stage 3 field may be
   left empty); a domain with none has no average and prints the dash. {%- endcomment -%}
""" + domain('cardio', [('score_hr', 's_hr', 'HR'), ('score_bp', 's_bp', 'BP')])
    + domain('body_comp', [('score_bmi', 's_bmi', 'BMI'), ('score_whr', 's_whr', 'WHR'), ('score_whtr', 's_whtr', 'WHtR')])
    + domain('physical', [('score_sit_stand', 's_sit_stand', 'Sit-Stand'), ('score_breath', 's_breath', 'Breath'),
                          ('score_balance', 's_balance', 'Balance')])
    + domain('lifestyle', [('score_' + f, 's_' + f, '') for f in LIFESTYLE], named=False)
    + '{%- assign n_inputs = cardio_n | plus: body_comp_n | plus: physical_n | plus: lifestyle_n -%}\n')
edit('2d', '<div class="kpi-card__value">{{lifestyle_avg}}<span', '<div class="kpi-card__value">{{lifestyle_avg | default: "&mdash;"}}<span')
for name, words in (('cardio', 'HR, BP'), ('body_comp', 'BMI, WHR, WHtR'), ('physical', 'Sit-Stand, Breath, Balance')):
    edit('2d', '<div class="domain-card__sub">avg of %s</div>' % words,
         '<div class="domain-card__sub">{%% if %s_n > 0 %%}avg of {{ %s_used }}{%% else %%}Not provided{%% endif %%}</div>' % (name, name))
edit('2d', '<div class="domain-card__sub">avg of 12 lifestyle inputs</div>',
     '<div class="domain-card__sub">{% if lifestyle_n > 0 %}avg of {{ lifestyle_n }} lifestyle inputs{% else %}Not provided{% endif %}</div>')
for spaced in ('s_activity   <= 4', 's_sleep_qual <= 4', 's_sleep_dur  <= 4', 's_stress     <= 4', 's_diet       <= 4',
               's_sit_stand  <= 4'):
    var = spaced.split()[0]
    edit('2d', spaced, 'score_%s != blank and %s <= 4' % (var[2:], var), 2)
edit('2d', '8 sections, 20 metrics,', '8 sections, {{ n_inputs }} metrics,')
edit('2d', 'computed from 20 inputs across', 'computed from {{ n_inputs }} inputs across')
edit('2d', 'Each axis is one of the 20 inputs you provided.', 'Each axis is one of the {{ n_inputs }} inputs you provided.')
edit('2d', 'full 20-spoke radar below', 'full {{ n_inputs }}-spoke radar below')

NOT_PROVIDED = '<span class="pill pill--neutral stat-cell__pill">Not provided</span>'
for metric in ('bmi', 'whr', 'whtr'):
    edit('2d', '<div class="stat-cell__value">{{%s}}</div><span class="pill pill--{{%s_class}} stat-cell__pill">{{score_%s}}/5 {{%s_label | upcase}}</span>'
         % (metric, metric, metric, metric),
         '<div class="stat-cell__value">{{%s | default: "&mdash;"}}</div>{%% if score_%s != blank %%}<span class="pill pill--{{%s_class}} stat-cell__pill">{{score_%s}}/5 {{%s_label | upcase}}</span>{%% else %%}%s{%% endif %%}'
         % (metric, metric, metric, metric, metric, NOT_PROVIDED))
for score, raw in (('hr', '{% if resting_hr_bpm != blank %}{{resting_hr_bpm}}<span class="stat-cell__unit">bpm</span>'),
                   ('bp', '{% if bp_systolic != blank %}{{bp_systolic}}<span class="stat-cell__unit">/{{bp_diastolic}}</span>')):
    edit('2d', '%s{%% else %%}{{score_%s}}/5{%% endif %%}</div><span class="pill pill--{{%s_class}} stat-cell__pill">{{score_%s}}/5 {{%s_label}}</span>'
         % (raw, score, score, score, score),
         '%s{%% elsif score_%s != blank %%}{{score_%s}}/5{%% else %%}&mdash;{%% endif %%}</div>{%% if score_%s != blank %%}<span class="pill pill--{{%s_class}} stat-cell__pill">{{score_%s}}/5 {{%s_label}}</span>{%% else %%}%s{%% endif %%}'
         % (raw, score, score, score, score, score, score, NOT_PROVIDED))
for f in ['sit_stand', 'breath', 'balance'] + LIFESTYLE:
    edit('2d', '{%% if label_%s != blank %%}{{label_%s}}{%% else %%}{{score_%s}}/5{%% endif %%}</div>' % (f, f, f),
         '{%% if label_%s != blank %%}{{label_%s}}{%% elsif score_%s != blank %%}{{score_%s}}/5{%% else %%}&mdash;{%% endif %%}</div>' % (f, f, f, f))
    pill = '<span class="pill pill--{{%s_class}} stat-cell__pill">{%% if label_%s != blank %%}{{score_%s}}/5 {%% endif %%}{{%s_label}}</span>' % (f, f, f, f)
    edit('2d', pill, '{%% if score_%s != blank %%}%s{%% else %%}%s{%% endif %%}' % (f, pill, NOT_PROVIDED))


# --- 2e: the large quote mark inside the quote's own space (D5) -----------
# At top -8mm the mark reached the block above (the three cards on page 3, the
# subtitle and text on pages 12 and 15). It now sits in the quote's left margin,
# beside its first line.
edit('2e', """.pull-quote::before {
  content: '\\201C'; position: absolute;
  top: -8mm; left: -2mm;""", """/* 2026-11-14 layout: the mark sits in the quote's own left margin, beside its
   first line; at top -8mm it reached the block above. */
.pull-quote::before {
  content: '\\201C'; position: absolute;
  top: -1mm; left: -8.5mm;""")


# --- 2f: the age shift on one line: the number at figure size, "yrs" as the unit (D6)
for sign in ('−', '+'):
    edit('2f', "{%%- capture age_shift_display -%%}%s{{ age_shift_abs }} yrs{%%- endcapture -%%}" % sign,
         "{%%- capture age_shift_display -%%}%s{{ age_shift_abs }} yrs{%%- endcapture -%%}\n"
         "  {%%- capture age_shift_num -%%}%s{{ age_shift_abs }}{%%- endcapture -%%}" % (sign, sign))
edit('2f', '{%- assign age_shift_display = "0 yrs" -%}',
     '{%- assign age_shift_display = "0 yrs" -%}\n  {%- assign age_shift_num = "0" -%}')
edit('2f', '<div class="kpi-card__value kpi-card__value--{{age_shift_value_class}}">{{age_shift_display}}</div>',
     '<div class="kpi-card__value kpi-card__value--{{age_shift_value_class}}">{{age_shift_num}}<span class="kpi-card__value-unit">yrs</span></div>')


# --- 2g: the health background leaves page 3 for page 10, unchanged (D8) ---
# Three answers at their form limits (600 + 400 + 600 characters) need about
# 100 mm; page 3 has about 35 mm left under the Key findings.
HEALTH_BG = r"""    {% comment %}
      v0.38.1 — Stage 3 Section 6 "Health Background".
      Three optional long-form text fields the client typed at Stage 3.
      Block hides entirely if all 3 are empty; each row independently
      guarded so empty fields don't show. Reuses .callout vocabulary.
    {% endcomment %}
    {% if family_history != blank or medications != blank or existing_conditions != blank %}
    <div class="callout">
      <span class="callout__label">Client&rsquo;s health background &middot; shared in your Full Report form</span>
      <div class="callout__body">
        {% if family_history != blank %}
          <p><strong>Family history:</strong> {{ family_history | newline_to_br | replace: '\n', '<br>' }}</p>
        {% endif %}
        {% if medications != blank %}
          <p><strong>Current medications:</strong> {{ medications | newline_to_br | replace: '\n', '<br>' }}</p>
        {% endif %}
        {% if existing_conditions != blank %}
          <p><strong>Existing conditions:</strong> {{ existing_conditions | newline_to_br | replace: '\n', '<br>' }}</p>
        {% endif %}
      </div>
    </div>
    {% endif %}
"""
edit('2g', HEALTH_BG, '')
edit('2g', """designed to bend this curve.</p>
    {% endif %}
""", """designed to bend this curve.</p>
    {% endif %}

""" + HEALTH_BG)


# --- page 11: "Overall lifestyle score" moves to page 8 (worst-case render) ----
# Three analysis blocks at their word limits (130 + 130 + 150) cut the last one
# at the foot of page 11. The lifestyle block reads the client's pattern, so it
# joins "The pattern" on page 8, which had about 125 mm free.
LIFT = """    <div class="h3">Overall lifestyle score</div>
    {% if lift_clean != blank %}
    <div class="body-text">{{lift_clean}}</div>
    {% endif %}
"""
edit('page 11', LIFT + '\n    <div class="h3" style="margin-top: 10mm;">BMI &amp; Waist-Hip Ratio</div>',
     '    <div class="h3">BMI &amp; Waist-Hip Ratio</div>')
edit('page 11', """  </div>
  <div class="page__footer">
    <span class="page__footer-brand">HealthDataLab.com</span>
    <span class="page__footer-num">08 / {{ pg_total }}</span>""", LIFT + """  </div>
  <div class="page__footer">
    <span class="page__footer-brand">HealthDataLab.com</span>
    <span class="page__footer-num">08 / {{ pg_total }}</span>""")


def build(body):
    for defect, old, new, count in EDITS:
        found = body.count(old)
        if found != count:
            sys.exit('%s: expected %d of %r, found %d' % (defect, count, old[:70], found))
        body = body.replace(old, new)
    return body


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    src = open(sys.argv[1], encoding='utf-8', newline='').read()
    out = build(src)
    open(sys.argv[2], 'w', encoding='utf-8', newline='').write(out)
    print('%d edits, %s -> %s' % (len(EDITS), hashlib.sha256(src.encode()).hexdigest()[:12],
                                  hashlib.sha256(out.encode()).hexdigest()[:12]))
