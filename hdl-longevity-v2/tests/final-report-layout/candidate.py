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
