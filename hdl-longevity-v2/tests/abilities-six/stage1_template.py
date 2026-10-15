#!/usr/bin/env python3
"""v0.48.12 Stage 1 PDF with a sales page (Matthew, 2026-10-09; plan Part C1),
v0.48.15 page 2 = the Full Report offer, page-1 callout bigger (plan 2026-10-13 Task 4).

  python3 stage1_template.py build <backup_dir> <urls.json> <out_dir>   write b08e10ad.{html,css}
  python3 stage1_template.py check <backup_dir> <urls.json> <out_dir>   T2: assert them (exit 0 = pass)

Page 1 is the backup page byte for byte apart from the "What's next" callout
(callout--big, plus the offer defaults), the footer partner line and one
footer line ("Page 1 of 2", kept when offer_show is "0"). Page 2 is new: one title, four greyed sample boxes
(abilities, trajectory, 20 metrics, video; urls.json from
stby-demo-urls.php on the server whose host the trajectory picture must use),
Matthew's grandchild example (v0.48.13: a 20 kg six-year-old, the sum
from HDLV2_Why_Picks::DECLINE) and the offer. Offer words and prices are the
nine offer_* payload keys, each with a | default: from the Decisions gate. The stylesheet is the
backup plus an appended block of new classes only; no accent rail.
"""
import json
import re
import sys
import urllib.request

TID = "b08e10ad"
PAGE_LINE = '      <p class="footer-brand__url">healthdatalab.com</p>\n'
FOOTER_PAGE = '      <p class="footer-brand__url">Page {n} of 2</p>\n'
OVERLINE_OLD = '<span class="footer-brand__overline">Powered by</span>'
OVERLINE_NEW = ('<span class="footer-brand__overline">Powered by HealthDataLab &middot; '
                'a partnership focused on longevity</span>')
OFFER_VARS = ("offer_show", "offer_title", "offer_hook", "offer_today", "offer_list", "offer_code",
              "offer_url", "offer_caption", "offer_video_url")

CALLOUT_OLD = """  <aside class="callout">
    <div class="callout__head">
      <span class="callout__label">What's next</span>
      <span class="callout__cue">Continue &rarr;</span>
    </div>
    <p class="callout__body">Lasting change comes from understanding <em>why</em> it matters to you personally.{% if priority_topic != blank %} Your top priority area is <strong>{{ priority_topic }}</strong>{% if practitioner_name != blank %} &mdash; <strong>{{ practitioner_name }}</strong> will work on this with you in Stage 2{% endif %}.{% else %} Stage 2 captures what you want your future to look like &mdash; and what's worth changing for.{% endif %} Check your inbox for the link to continue.</p>
  </aside>
"""

# v0.48.15 (H3): the one page-1 block that changes. The offer defaults (Decisions
# gate) are assigned here, before page 2, so the PDF renders before Make v33
# passes the keys; offer_show absent or empty = show page 2.
CALLOUT_NEW = """  {% assign offer_show = offer_show | default: "1" %}
  {% assign offer_title = offer_title | default: "Want to go deeper?" %}
  {% assign offer_hook = offer_hook | default: "Find out more about what is impacting your longevity the most" %}
  {% assign offer_today = offer_today | default: "$27" %}
  {% assign offer_list = offer_list | default: "$129" %}
  {% assign offer_code = offer_code | default: "YOURCODE" %}
  {% assign offer_url = offer_url | default: "https://altituding.com/full-report" %}
  {% assign offer_caption = offer_caption | default: "The abilities your choices need, against where you are today" %}
  {% assign offer_video_url = offer_video_url | default: "" %}
  <aside class="callout callout--big">
    <div class="callout__head">
      <span class="callout__label">What's next</span>
      {% if offer_show == "1" %}<span class="callout__cue">See page 2 &rarr;</span>{% endif %}
    </div>
    <p class="callout__body">Your number is a start. The Full Report shows what is ageing you fastest and what to work on first{% if practitioner_name != blank %}, with {{ practitioner_name }}{% endif %}.</p>
  </aside>
"""

PAGE2 = """
<!-- PAGE 2 — the Full Report, sold from the free result (v0.48.15, Matthew 2026-10-13).
     Every offer word and figure is a payload key from the WordPress setting
     hdlv2_stage1_offer (Make v33); the defaults are assigned with the page-1 callout. -->
{% if offer_show == "1" %}
<section class="page">

  <header class="page-header">
    <div class="brand-wordmark">HealthDataLab</div>
    <div class="page-meta">
      {% if report_date != blank %}{{ report_date | date: "%-d %b %Y" }} &middot; {% endif %}<strong>Confidential</strong>
    </div>
  </header>

  <div class="opener">
    <div class="opener__col">
      <h1 class="opener__title sales__title">{{ offer_title }}</h1>
      <div class="opener__rule"></div>
    </div>
  </div>

  <section class="sales-shows">
    <p class="sales__eyebrow">What the Full Report shows you</p>
    <div class="sales-grid sales-grid--2x2">
      <figure class="sales-pic is-sample">
        <span class="sales-tag">Sample chart</span>
        <img src="{{ABILITIES_URL}}" alt="Example: the abilities a chosen life needs, against where the person is today">
        <figcaption>{{ offer_caption }}</figcaption>
      </figure>
      <figure class="sales-pic is-sample">
        <span class="sales-tag">Sample chart</span>
        <img src="{{TRAJECTORY_URL}}" alt="Example: biological age over the coming decades on today's path">
        <figcaption>Your biological age over the next decades on today's path</figcaption>
      </figure>
      <figure class="sales-pic is-sample">
        <span class="sales-tag">Sample chart</span>
        <img src="{{RADAR_URL}}" alt="Example: twenty health metrics, each scored out of five">
        <figcaption>Twenty health metrics, each scored out of five</figcaption>
      </figure>
      <figure class="sales-pic is-sample sales-pic--video">
        <span class="sales-tag">Sample chart</span>
        {% if offer_video_url != blank %}
        <a class="sales-pic__link" href="{{ offer_video_url }}" aria-label="Watch: how the Full Report works"></a>
        <div class="sales-video"><span class="sales-video__play"></span></div>
        <figcaption>Watch: how the Full Report works (2 min)</figcaption>
        {% else %}
        <div class="sales-video sales-video--soon">Video coming soon</div>
        <figcaption>How the Full Report works</figcaption>
        {% endif %}
      </figure>
    </div>
    <p class="sales__hint">Example pictures from a sample report, not yours.</p>
  </section>

  <section class="sales-example">
    <p class="sales__eyebrow">The sum behind it</p>
    <p class="sales-example__body">Want to lift a six-year-old grandchild (about 20 kg) at 90? Strength fades a little faster each decade, so at 60 that means lifting about 30 kg (women) to 40 kg (men) today. The full report does this sum for every ability you chose, for your age.</p>
  </section>

  <a class="sales-offer" href="{{ offer_url }}">
    <span class="sales-offer__hook">{{ offer_hook }}</span>
    <span class="sales-offer__line">Today <strong>{{ offer_today }}</strong> <span class="sales-offer__was">{{ offer_list }}</span></span>
    <span class="sales-offer__line">Use this code to get the discount</span>
    <span class="sales-offer__code">{{ offer_code }}</span>
    <span class="sales-offer__btn">Click here</span>
  </a>

  <footer class="page-footer">
    <div class="prac">
      {% if practitioner_logo_url != blank %}
        <img class="prac__logo" src="{{ practitioner_logo_url }}" alt="{{ practitioner_name }}">
      {% endif %}
      <div class="prac__lines">
        <span class="prac__overline">Your practitioner</span>
        {% if practitioner_name != blank %}<p class="prac__name">{{ practitioner_name }}</p>{% endif %}
        {% if practitioner_email != blank %}<p class="prac__contact">{{ practitioner_email }}</p>{% endif %}
      </div>
    </div>
    <div class="footer-spacer"></div>
    <div class="footer-brand">
      <span class="footer-brand__overline">Powered by HealthDataLab &middot; a partnership focused on longevity</span>
      <p class="footer-brand__name">HealthDataLab</p>
      <p class="footer-brand__url">healthdatalab.com</p>
      <p class="footer-brand__url">Page 2 of 2</p>
    </div>
  </footer>

</section>
{% endif %}
"""

CSS_ADD = """

/* ============================================================
   v0.48.12 / v0.48.15 — page 1 callout bigger, page 2 the Full Report offer
   (new classes only; no accent rail: .callout--big swaps the side rule for a top one)
   ============================================================ */
.callout--big {
  border: 1px solid $grey-200; border-top: 3px solid $brand-teal; border-radius: 3mm; padding: 6mm;
  .callout__label { font-size: 11pt; }
  .callout__cue { font-size: 9pt; color: $brand-teal-dark; }
  .callout__body { font-size: 12.5pt; line-height: 1.45; }
}
.sales__title { white-space: normal; font-size: 30pt; line-height: 1.1; }
.sales__eyebrow {
  font-family: $body; font-weight: 600; font-size: 7.5pt; color: $brand-teal-dark;
  letter-spacing: 0.2em; text-transform: uppercase; margin: 2mm 0 3mm;
}
.sales__hint { font-family: $body; font-size: 8pt; color: $grey-500; margin: 2mm 0 0; }
.sales-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; }
.sales-grid--2x2 { grid-template-columns: repeat(2, 1fr); }
.sales-pic {
  position: relative; margin: 0; background: white; border: 1px solid $grey-200; border-radius: 3mm; padding: 3mm;
  display: flex; flex-direction: column; gap: 2mm; page-break-inside: avoid;
  img { display: block; width: 100%; height: 42mm; object-fit: contain; }
  figcaption { font-family: $body; font-size: 8.5pt; line-height: 1.4; color: $grey-700; }
}
.is-sample img { filter: grayscale(.55) opacity(.8); }
.sales-tag {
  position: absolute; top: 2mm; left: 2mm; z-index: 1;
  background: rgba(229, 118, 31, .72); color: #fff; font: 600 7pt $body;
  letter-spacing: .08em; text-transform: uppercase; padding: 1.2mm 2.4mm; border-radius: 2px;
}
.sales-pic__link { position: absolute; inset: 0; z-index: 2; }
.sales-video {
  height: 42mm; border-radius: 2mm; background: $brand-soft;
  display: flex; align-items: center; justify-content: center;
}
.sales-video__play {
  width: 0; height: 0; border-style: solid; border-width: 7mm 0 7mm 11mm;
  border-color: transparent transparent transparent $brand-teal-dark; opacity: .8;
}
.sales-video--soon { font-family: $body; font-size: 10pt; color: $grey-400; filter: grayscale(1); }
.sales-example {
  margin: 5mm 0 0; background: white; border: 1px solid $grey-200; border-radius: 3mm; padding: 4mm 5mm;
}
.sales-example__body { font-family: $body; font-size: 10pt; line-height: 1.5; color: $grey-700; margin: 0; }
.sales-offer {
  margin: 5mm 0 auto; background: $brand-teal-dark; color: white; text-decoration: none; border-radius: 3mm;
  padding: 5mm 6mm; display: flex; flex-direction: column; align-items: center; gap: 2mm;
  text-align: center; page-break-inside: avoid;
}
.sales-offer__hook { font-family: $display; font-weight: 600; font-size: 18pt; line-height: 1.2; }
.sales-offer__line { font-family: $body; font-size: 13pt; strong { font-weight: 700; } }
.sales-offer__was { text-decoration: line-through; font-size: 10pt; opacity: 0.75; margin-left: 2mm; }
.sales-offer__code { font-family: $mono; font-weight: 600; font-size: 15pt; letter-spacing: 0.2em; }
.sales-offer__btn {
  margin-top: 1mm; background: white; color: $brand-teal-dark;
  font-family: $body; font-weight: 600; font-size: 13pt; padding: 2.5mm 8mm; border-radius: 999px;
}
"""


def read(p):
    with open(p, encoding="utf-8") as f:
        return f.read()


def build(backup, urls):
    body = read(f"{backup}/{TID}.html")
    assert body.count(PAGE_LINE) == 1, "page-1 footer line not found once"
    assert body.count(CALLOUT_OLD) == 1 and body.count(OVERLINE_OLD) == 1, "callout or overline not found once"
    page1 = (body.replace(PAGE_LINE, PAGE_LINE + FOOTER_PAGE.format(n=1), 1)
                 .replace(CALLOUT_OLD, CALLOUT_NEW, 1).replace(OVERLINE_OLD, OVERLINE_NEW, 1))
    page2 = (PAGE2.replace("{{ABILITIES_URL}}", urls["abilities"].replace("&", "&amp;"))
                  .replace("{{TRAJECTORY_URL}}", urls["trajectory"].replace("&", "&amp;"))
                  .replace("{{RADAR_URL}}", urls["radar"].replace("&", "&amp;")))
    return page1.rstrip("\n") + "\n" + page2, read(f"{backup}/{TID}.css") + CSS_ADD


def liquid_vars(text):
    out = set()
    for m in re.finditer(r"\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)", text):
        out.add(m.group(1))
    for m in re.finditer(r"\{%\s*(?:if|elsif|unless)\s+([^%]*)%\}", text):
        out.update(re.findall(r"\b([a-z_][a-z0-9_]*)\b", m.group(1)))
    return out - {"and", "or", "blank", "true", "false", "contains", "nil"}


def main():
    mode, backup, urls_path, out_dir = sys.argv[1:5]
    urls = json.loads(read(urls_path))
    html, css = build(backup, urls)
    if mode == "build":
        with open(f"{out_dir}/{TID}.html", "w", encoding="utf-8") as f:
            f.write(html)
        with open(f"{out_dir}/{TID}.css", "w", encoding="utf-8") as f:
            f.write(css)
        print("built", len(html), len(css))
        return 0
    fails = []
    cand, cand_css = read(f"{out_dir}/{TID}.html"), read(f"{out_dir}/{TID}.css")
    orig, orig_css = read(f"{backup}/{TID}.html"), read(f"{backup}/{TID}.css")
    if cand != html or cand_css != css:
        fails.append("candidate differs from the build")
    first, sep, rest = cand.partition("\n<!-- PAGE 2")
    if not sep or first.count(FOOTER_PAGE.format(n=1)) != 1:
        fails.append("page 1 lacks the one page line")
    if not cand_css.startswith(orig_css):
        fails.append("stylesheet does not start with the backup byte for byte")
    if "border-left" in CSS_ADD:
        fails.append("new CSS draws an accent rail")
    if cand.count('<section class="page">') != 2:
        fails.append("not two pages")
    page2 = rest
    allowed = {"report_date", "practitioner_logo_url", "practitioner_name", "practitioner_email", *OFFER_VARS}
    extra = liquid_vars(page2) - allowed
    if extra:
        fails.append("page 2 uses variables outside the payload: " + ", ".join(sorted(extra)))
    for bad in ("six questions", "21 metrics", "21 health", "30 kg grandchild", "45 kg"):
        if bad in page2.lower():
            fails.append(f"wrong fact on page 2: {bad}")
    for need in ("Twenty health metrics", "about 20 kg", "Page 2 of 2"):
        if need not in page2:
            fails.append(f"page 2 missing: {need}")
    # v0.48.15 (plan 2026-10-13 Task 1.9): page 1 callout bigger, page 2 the Full Report offer.
    p1 = first.replace(FOOTER_PAGE.format(n=1), "", 1)
    if CALLOUT_NEW not in p1 or p1.count(OVERLINE_NEW) != 1:
        fails.append("page 1 lacks the big callout or the partner line")
    elif p1.replace(CALLOUT_NEW, CALLOUT_OLD, 1).replace(OVERLINE_NEW, OVERLINE_OLD, 1).rstrip("\n") != orig.rstrip("\n"):
        fails.append("page 1 changed outside the callout, the partner line and the page line")
    if "callout--big" not in CALLOUT_NEW or "the full report" not in CALLOUT_NEW.lower():
        fails.append("callout is not callout--big naming the Full Report")
    if re.search(r"\bStage ?(2|3|two|three)\b", cand, re.I):
        fails.append("document still says Stage 2/3")
    if OVERLINE_NEW not in page2:
        fails.append("page 2 lacks the partner line")
    for need in ("{{ offer_title }}", "{{ offer_hook }}", "Click here", "{{ offer_today }}", "{{ offer_code }}",
                 "{{ offer_url }}", "Use this code to get the discount", "{{ offer_caption }}",
                 '<span class="sales-offer__was">{{ offer_list }}</span>', "sales-grid--2x2",
                 "Example pictures from a sample report, not yours.", "Video coming soon"):
        if need not in page2:
            fails.append(f"page 2 missing: {need}")
    was = re.search(r"\.sales-offer__was \{[^}]*\}", cand_css)
    if not was or "line-through" not in was.group(0):
        fails.append("offer_list is not struck through")
    if page2.count('class="sales-pic is-sample') != 4 or page2.count('<span class="sales-tag">Sample chart</span>') != 4:
        fails.append("page 2 does not have four greyed sample boxes, each tagged")
    if ".is-sample img" not in CSS_ADD or ".sales-grid--2x2" not in CSS_ADD:
        fails.append("CSS lacks the grey sample or the 2x2 grid")
    caps = re.findall(r"<figcaption>(.*?)</figcaption>", page2)
    if not caps or caps[0] != "{{ offer_caption }}":
        fails.append("first caption is not offer_caption")
    gate, endif = page2.find('{% if offer_show == "1" %}'), page2.rstrip().endswith("{% endif %}")
    if gate < 0 or not endif or gate > page2.find('<section class="page">'):
        fails.append("page 2 is not wrapped in offer_show")
    if not re.search(r'\{% assign offer_show = offer_show \| default: "1" %\}', first):
        fails.append("offer_show does not default to showing page 2")
    for v in OFFER_VARS:
        if not re.search(r"\{%% assign %s = %s \| default: " % (v, v), cand):
            fails.append(f"{v} has no default")
    for k, u in urls.items():
        try:
            with urllib.request.urlopen(urllib.request.Request(u, headers={"User-Agent": "hdl-t2"}), timeout=30) as r:
                ct = r.headers.get("Content-Type", "")
                if r.status != 200 or not (ct.startswith("image/png") or ct.startswith("image/svg+xml")):
                    fails.append(f"{k} picture: {r.status} {ct}")
        except Exception as e:  # noqa: BLE001 — any failure is a failed check
            fails.append(f"{k} picture: {e}")
    for f in fails:
        print("FAIL", f)
    print("STAGE1 T2:", "FAIL" if fails else "PASS")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
