#!/usr/bin/env python3
"""v0.48.10 PDF templates: the choices block, built from the 2026-10-07 backup.

  python3 templates.py build <backup_dir> <out_dir>   write the four candidate bodies
  python3 templates.py check <backup_dir> <out_dir>   T1: assert them (exit 0 = pass)

Stage 2 WHY is wrapped whole: {% if why_picks != blank %} new {% else %} the
backup body byte for byte {% endif %}. Draft, Final and Flight notes get one
inserted block guarded by why_picks; removing it gives the backup byte for byte.
Stylesheets are not changed. Old-flow payloads never carry why_picks (Make's
old Stage 2 branch does not send it; the plugin sends '' for those rows).
"""
import hashlib
import os
import sys

STAGE2, DRAFT, FINAL, NOTES = "fdc6ba73", "4385d9b5", "1c9f06c5", "3dd65fe4"


def footer(page):
    return f"""  <footer class="page-footer">
    <div class="prac">
      {{% if practitioner_logo_url != blank %}}
        <img class="prac__logo" src="{{{{ practitioner_logo_url }}}}" alt="{{{{ practitioner_name }}}}">
      {{% endif %}}
      <div class="prac__lines">
        <span class="prac__overline">Practitioner of record</span>
        {{% if practitioner_name != blank %}}<p class="prac__name">{{{{ practitioner_name }}}}</p>{{% endif %}}
        {{% if practitioner_email != blank %}}<p class="prac__contact">{{{{ practitioner_email }}}}</p>{{% endif %}}
      </div>
    </div>
    <div class="footer-spacer"></div>
    <div class="footer-brand">
      <span class="footer-brand__overline">Powered by</span>
      <p class="footer-brand__name">HealthDataLab</p>
      <p class="footer-brand__url">healthdatalab.com</p>
      <p class="footer-brand__page">Page {page} of {{{{ pages_total }}}}</p>
    </div>
  </footer>
"""


HEADER = """  <header class="page-header">
    <div class="brand-wordmark">HealthDataLab</div>
    <div class="page-meta">
      {% if report_date != blank %}{{ report_date }} &middot; {% endif %}<strong>Practitioner briefing</strong>
    </div>
  </header>
"""


def col(var, cls, num, title):
    return f"""    {{% if {var} != blank %}}
    <div class="why-col {cls}">
      <span class="why-col__num">{num}</span>
      <h3 class="why-col__label">{title}</h3>
      <ul class="why-list">
        {{% assign picks_list = {var} | split: " | " %}}
        {{% for pick in picks_list %}}
          {{% assign cleaned = pick | strip %}}
          {{% if cleaned != blank %}}<li>{{{{ cleaned }}}}</li>{{% endif %}}
        {{% endfor %}}
      </ul>
    </div>
    {{% endif %}}
"""


NONE_GROUPED = "why_picks_people == blank and why_picks_freedom == blank and why_picks_things == blank"

STAGE2_NEW = (
    """{%- comment -%} v0.48.10: a choices-page client (why_picks sent) gets their ticked statements
   printed from data under the three headings. Every other payload renders the
   original template in the else branch, unchanged. {%- endcomment -%}
{% if why_picks != blank %}
{% assign first_name = client_name | split: " " | first %}
{% assign pages_total = 1 %}
{% if ai_reformulation != blank %}{% assign pages_total = pages_total | plus: 1 %}{% endif %}
{% if why_own_words != blank %}{% assign pages_total = pages_total | plus: 1 %}{% endif %}
<section class="page">

""" + HEADER + """
  <div class="opener">
    <div class="opener__num">Stage 2 &middot; Why &middot; Their choices</div>
    <h1 class="opener__title">{% if first_name != blank %}{{ first_name }}'s WHY Briefing{% else %}WHY Briefing{% endif %}</h1>
    <div class="opener__rule"></div>
    <p class="opener__sub">{% if practitioner_name != blank %}Prepared for <strong>{{ practitioner_name }}</strong>. {% endif %}The statements below are exactly the ones {% if first_name != blank %}{{ first_name }}{% else %}the client{% endif %} ticked, under the headings they were offered in, with the abilities they depend on.</p>
  </div>

  <div class="meta-strip">
    <div class="meta-cell">
      <span class="meta-cell__label">Client</span>
      <span class="meta-cell__value">{% if client_name != blank %}{{ client_name }}{% else %}&mdash;{% endif %}</span>
    </div>
    <div class="meta-cell">
      <span class="meta-cell__label">Age</span>
      <span class="meta-cell__value">{% if client_age != blank %}{{ client_age }}{% else %}&mdash;{% endif %}</span>
    </div>
    <div class="meta-cell">
      <span class="meta-cell__label">Submitted</span>
      <span class="meta-cell__value-sm">{% if submitted_date != blank %}{{ submitted_date | date: "%-d %b %Y" }}{% elsif report_date != blank %}{{ report_date }}{% else %}&mdash;{% endif %}</span>
    </div>
    <div class="meta-cell">
      <span class="meta-cell__label">Stage</span>
      <span class="meta-cell__value-sm">Stage 2 &mdash; Why</span>
    </div>
  </div>

  {% if distilled_why != blank %}
  <div class="why-hero">
    <div class="why-hero__label">Distilled why</div>
    <p class="why-hero__quote">{{ distilled_why }}</p>
  </div>
  {% endif %}

  <div class="why-cols">
"""
    + col("why_picks_people", "why-col--people", "i &middot; Chosen", "People I love")
    + col("why_picks_freedom", "why-col--drives", "ii &middot; Chosen", "My freedom")
    + col("why_picks_things", "why-col--people", "iii &middot; Chosen", "Things I love")
    + "    {% if " + NONE_GROUPED + " %}\n"
    + col("why_picks", "why-col--people", "Chosen", "What they chose").replace("    {% if why_picks != blank %}\n", "").rsplit("    {% endif %}\n", 1)[0]
    + """    {% endif %}
  </div>

  {% if why_people != blank or key_people != blank or why_abilities != blank %}
  <div class="why-cols">
    {% if why_people != blank or key_people != blank %}
    <div class="why-col why-col--people">
      <span class="why-col__num">Named</span>
      <h3 class="why-col__label">People who matter</h3>
      <ul class="why-list">
        <li>{% if why_people != blank %}{{ why_people | replace: "|||PARA|||", " " }}{% else %}{{ key_people }}{% endif %}</li>
      </ul>
    </div>
    {% endif %}
    {% if why_abilities != blank %}
    <div class="why-col why-col--drives">
      <span class="why-col__num">Measured</span>
      <h3 class="why-col__label">Abilities these depend on</h3>
      <ul class="why-list">
        {% assign abilities_list = why_abilities | split: " | " %}
        {% for ability in abilities_list %}
          {% if ability contains "focus area" %}<li><strong>{{ ability }}</strong></li>{% else %}<li>{{ ability }}</li>{% endif %}
        {% endfor %}
      </ul>
    </div>
    {% endif %}
  </div>
  {% endif %}

""" + footer(1) + """
</section>

{% if ai_reformulation != blank %}
<section class="page">

""" + HEADER + """
  <div class="opener">
    <div class="opener__num">Stage 2 &middot; Why &middot; Summary</div>
    <h1 class="opener__title">Summary</h1>
    <div class="opener__rule"></div>
    <p class="opener__sub">Written from {% if first_name != blank %}{{ first_name }}'s{% else %}the client's{% endif %} ticked statements, the people they named and their own words. Reference, do not read verbatim.</p>
  </div>

  <div class="brief">
    {% assign summary_paras = ai_reformulation | split: "|||PARA|||" %}
    {% for p in summary_paras %}
      {% assign clean = p | strip %}
      {% if clean != blank %}<p class="brief__p">{{ clean }}</p>{% endif %}
    {% endfor %}
  </div>

""" + footer(2) + """
</section>
{% endif %}

{% if why_own_words != blank %}
<section class="page">

""" + HEADER + """
  <div class="opener">
    <div class="opener__num">Stage 2 &middot; Why &middot; Own words</div>
    <h1 class="opener__title">In {% if first_name != blank %}{{ first_name }}'s{% else %}the client's{% endif %} own words</h1>
    <div class="opener__rule"></div>
    <p class="opener__sub">What {% if first_name != blank %}<strong>{{ first_name }}</strong>{% else %}the client{% endif %} typed in the own-words box, unedited.</p>
  </div>

  <div class="transcript">
    <div class="transcript__head">
      <span class="transcript__eyebrow">Own words</span>
      <span class="transcript__hint">Stage 2 &middot; submitted {% if submitted_date != blank %}{{ submitted_date | date: "%-d %b %Y" }}{% elsif report_date != blank %}{{ report_date }}{% endif %}</span>
    </div>
    <div class="transcript__body">
      {% assign own_paras = why_own_words | split: "|||PARA|||" %}
      {% for p in own_paras %}
        {% assign clean = p | strip %}
        {% if clean != blank %}<p>{{ clean }}</p>{% endif %}
      {% endfor %}
    </div>
  </div>

""" + footer("{{ pages_total }}") + """
</section>
{% endif %}
{% else %}
"""
)
STAGE2_END = "{% endif %}\n"


def grouped_line(sep_after, open_="", close=""):
    """'People I love: a; b. My freedom: …', each heading only when it has picks."""
    out = ""
    for var, title in (("why_picks_people", "People I love"), ("why_picks_freedom", "My freedom"), ("why_picks_things", "Things I love")):
        out += f'{{% if {var} != blank %}}{open_}<strong>{title}:</strong> {{{{ {var} | replace: " | ", "; " }}}}{sep_after}{close}{{% endif %}}'
    out += f'{{% if {NONE_GROUPED} %}}{open_}{{{{ why_picks | replace: " | ", "; " }}}}{sep_after}{close}{{% endif %}}'
    return out


DRAFT_ANCHOR = """    <p class="notice__body">Your practitioner will review this with you during your consultation. Together you'll refine the score breakdown, prioritise the biggest levers, and shape the plan that comes next. The final personalised Longevity Report follows after that conversation.</p>
  </div>
"""
DRAFT_BLOCK = (
    """
  {% if why_picks != blank %}
  <div class="steps__head">
    <span class="steps__eyebrow">What you chose</span>
    <span class="steps__hint">Your priorities</span>
  </div>
  <div class="steps">
"""
    + grouped_line("", "\n    <p class=\"step__body\">", "</p>\n    ").replace("{% endif %}{% if", "{% endif %}\n    {% if") + "\n"
    + """    {% if why_focus_areas != blank %}<p class="step__body"><strong>Where we start:</strong> {{ why_focus_areas }}</p>{% endif %}
  </div>
  {% endif %}
"""
)

FINAL_ANCHOR = """        <div class="pillar-detail__copy">{{distilled_why_clean | default: 'Your purpose &mdash; captured in your own words during the consultation. The reason every action in this plan exists.'}}"""
FINAL_BLOCK = (
    """{% if why_picks != blank %}<br><br><strong>What {{ cover_name | split: " " | first }} chose:</strong> """
    + grouped_line(". ")
    + """{% if why_abilities != blank %}<br><strong>Abilities these depend on most:</strong> {{ why_abilities | replace: " | ", "; " }}{% endif %}"""
    + """{% if why_focus_areas != blank %}<br><strong>Focus areas:</strong> {{ why_focus_areas }}{% endif %}"""
    + """{% if why_people != blank %}<br><strong>For:</strong> {{ why_people }}{% endif %}{% endif %}"""
)

NOTES_ANCHOR = """      {% if why_quote != blank %}
      <div class="fn-grp">
        <div class="fn-grp-label">Why</div>
        <div class="fn-quote">&ldquo;{{ why_quote }}&rdquo;</div>
      </div>
      {% endif %}
"""
NOTES_BLOCK = """      {% if why_picks != blank %}
      <div class="fn-grp">
        <div class="fn-grp-label">Chosen priorities</div>
        <div class="fn-kv"><span class="v">{{ why_picks | replace: " | ", "; " }}</span></div>
        {% if why_focus_areas != blank %}<div class="fn-kv"><span class="k">Focus areas:</span> <span class="v">{{ why_focus_areas }}</span></div>{% endif %}
      </div>
      {% endif %}
"""

INSERTS = {DRAFT: (DRAFT_ANCHOR, DRAFT_BLOCK), FINAL: (FINAL_ANCHOR, FINAL_BLOCK), NOTES: (NOTES_ANCHOR, NOTES_BLOCK)}


def read(path):
    with open(path, encoding="utf-8") as f:
        return f.read()


def build(backup):
    out = {STAGE2: STAGE2_NEW + read(f"{backup}/{STAGE2}.html") + STAGE2_END}
    for tid, (anchor, block) in INSERTS.items():
        body = read(f"{backup}/{tid}.html")
        assert body.count(anchor) == 1, f"{tid}: anchor not found once"
        out[tid] = body.replace(anchor, anchor + block, 1)
    return out


def main():
    mode, backup, out_dir = sys.argv[1], sys.argv[2], sys.argv[3]
    built = build(backup)
    if mode == "build":
        os.makedirs(out_dir, exist_ok=True)
        for tid, body in built.items():
            with open(f"{out_dir}/{tid}.html", "w", encoding="utf-8") as f:
                f.write(body)
            print(tid, hashlib.sha256(body.encode()).hexdigest())
        return 0
    fails = []
    for tid, body in built.items():
        cand = read(f"{out_dir}/{tid}.html")
        orig = read(f"{backup}/{tid}.html")
        if cand != body:
            fails.append(f"{tid}: candidate differs from the build")
        if tid == STAGE2:
            head, _, rest = cand.partition("{% else %}\n")
            if rest[: -len(STAGE2_END)] != orig or not rest.endswith(STAGE2_END):
                fails.append(f"{tid}: else branch is not the backup byte for byte")
            for h in ("People I love", "My freedom", "Things I love", "{% if why_picks != blank %}"):
                if h not in head:
                    fails.append(f"{tid}: missing {h}")
        else:
            anchor, block = INSERTS[tid]
            if cand.replace(block, "", 1) != orig:
                fails.append(f"{tid}: removing the block does not give the backup")
            if not block.strip().startswith("{% if why_picks != blank %}"):
                fails.append(f"{tid}: block not guarded by why_picks")
            if tid != NOTES:
                for h in ("People I love", "My freedom", "Things I love"):
                    if h not in block:
                        fails.append(f"{tid}: missing {h}")
    for f in fails:
        print("FAIL", f)
    print("TEMPLATES:", "FAIL" if fails else "PASS", f"({len(built)} templates)")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
