#!/usr/bin/env python3
"""v0.48.12 Stage 1 PDF with a sales page (Matthew, 2026-10-09; plan Part C1).

  python3 stage1_template.py build <backup_dir> <urls.json> <out_dir>   write b08e10ad.{html,css}
  python3 stage1_template.py check <backup_dir> <urls.json> <out_dir>   T2: assert them (exit 0 = pass)

Page 1 is the backup page byte for byte apart from one footer line
("Page 1 of 2"). Page 2 is new: why the free result is a ballpark, three
demo pictures (abilities, trajectory, 20 metrics; urls.json from
stby-demo-urls.php on the server whose host the trajectory picture must use),
Matthew's 30 kg example and the offer. Prices and the buy link are one
Liquid assign each (placeholders Matthew owns). The stylesheet is the
backup plus an appended block of new classes only; no accent rail.
"""
import json
import re
import sys
import urllib.request

TID = "b08e10ad"
PAGE_LINE = '      <p class="footer-brand__url">healthdatalab.com</p>\n'
FOOTER_PAGE = '      <p class="footer-brand__url">Page {n} of 2</p>\n'

PAGE2 = """
<!-- PAGE 2 — the full report, sold from the free result (v0.48.12, Matthew 2026-10-09).
     Prices and link are placeholders Matthew owns: change the assigns only. -->
{% assign show_offer = true %}
{% assign list_price = "$129" %}
{% assign today_price = "$27" %}
{% assign buy_url = "https://healthdatalab.com/personal-report" %}
<section class="page">

  <header class="page-header">
    <div class="brand-wordmark">HealthDataLab</div>
    <div class="page-meta">
      {% if report_date != blank %}{{ report_date | date: "%-d %b %Y" }} &middot; {% endif %}<strong>Confidential</strong>
    </div>
  </header>

  <div class="opener">
    <div class="opener__col">
      <div class="opener__num">Stage 1 is a ballpark</div>
      <h1 class="opener__title sales__title">Where you are, and where you want to be</h1>
      <div class="opener__rule"></div>
      <p class="opener__sub">Your rate of ageing comes from nine answers. It is a useful first look, not the full picture: it cannot tell you which parts of your life are ageing you fastest, or which abilities your future depends on.</p>
    </div>
  </div>

  <section class="sales-shows">
    <div class="sales__head"><span class="sales__eyebrow">What the full report shows you</span><span class="sales__hint">Example pictures, not yours</span></div>
    <div class="sales-grid">
      <figure class="sales-pic">
        <img src="{{ABILITIES_URL}}" alt="Example: the abilities a chosen life needs, against where the person is today">
        <figcaption>The abilities your choices need, against where you are today</figcaption>
      </figure>
      <figure class="sales-pic">
        <img src="{{TRAJECTORY_URL}}" alt="Example: biological age over the coming decades on today's path">
        <figcaption>Your biological age over the next decades on today's path</figcaption>
      </figure>
      <figure class="sales-pic">
        <img src="{{RADAR_URL}}" alt="Example: twenty health metrics, each scored out of five">
        <figcaption>Twenty health metrics, each scored out of five</figcaption>
      </figure>
    </div>
  </section>

  <section class="sales-example">
    <p class="sales__eyebrow">The sum behind it</p>
    <p class="sales-example__body">Want to lift a 30 kg grandchild at 90? Capacity falls about 20% a decade after 60, so at 60 you need to lift about 45 kg to keep that. The full report does this sum for every ability you chose.</p>
  </section>

  {% if show_offer %}
  <section class="sales-offer">
    <div class="sales-offer__text">
      <p class="sales__eyebrow">Full longevity report</p>
      <p class="sales-offer__price">Normally <span class="sales-offer__was">{{ list_price }}</span> &middot; today <strong>{{ today_price }}</strong></p>
      <p class="sales-offer__body">Find out what is ageing you fastest, and what to work on first.</p>
    </div>
    <a class="sales-offer__btn" href="{{ buy_url }}">Get the full report</a>
  </section>
  {% else %}
  <section class="sales-offer">
    <p class="sales-offer__body">Check your inbox for the link to continue.</p>
  </section>
  {% endif %}

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
      <span class="footer-brand__overline">Powered by</span>
      <p class="footer-brand__name">HealthDataLab</p>
      <p class="footer-brand__url">healthdatalab.com</p>
      <p class="footer-brand__url">Page 2 of 2</p>
    </div>
  </footer>

</section>
"""

CSS_ADD = """

/* ============================================================
   v0.48.12 — page 2, the sales page (new classes only; page 1 untouched)
   ============================================================ */
.sales__title { white-space: normal; font-size: 26pt; line-height: 1.1; }
.sales__head { display: flex; justify-content: space-between; align-items: baseline; margin: 2mm 0 3mm; }
.sales__eyebrow {
  font-family: $body; font-weight: 600; font-size: 7.5pt; color: $brand-teal-dark;
  letter-spacing: 0.2em; text-transform: uppercase; margin: 0 0 1.5mm;
}
.sales__hint { font-family: $mono; font-size: 7pt; color: $grey-500; letter-spacing: 0.12em; text-transform: uppercase; }
.sales-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; }
.sales-pic {
  margin: 0; background: white; border: 1px solid $grey-200; border-radius: 3mm; padding: 3mm;
  display: flex; flex-direction: column; gap: 2mm; page-break-inside: avoid;
  img { display: block; width: 100%; height: 52mm; object-fit: contain; }
  figcaption { font-family: $body; font-size: 8.5pt; line-height: 1.4; color: $grey-700; }
}
.sales-example {
  margin: 6mm 0 0; background: white; border: 1px solid $grey-200; border-radius: 3mm; padding: 4mm 5mm;
}
.sales-example__body { font-family: $body; font-size: 10pt; line-height: 1.5; color: $grey-700; margin: 0; }
.sales-offer {
  margin: 6mm 0 auto; background: $brand-teal-dark; color: white; border-radius: 3mm; padding: 5mm 6mm;
  display: flex; align-items: center; justify-content: space-between; gap: 6mm; page-break-inside: avoid;
  .sales__eyebrow { color: rgba(255, 255, 255, 0.75); }
}
.sales-offer__price { font-family: $display; font-weight: 600; font-size: 16pt; margin: 0 0 1mm; color: white; }
.sales-offer__was { text-decoration: line-through; font-weight: 500; opacity: 0.75; }
.sales-offer__body { font-family: $body; font-size: 9.5pt; line-height: 1.5; margin: 0; color: rgba(255, 255, 255, 0.9); }
.sales-offer__btn {
  flex-shrink: 0; display: inline-block; background: white; color: $brand-teal-dark; text-decoration: none;
  font-family: $body; font-weight: 600; font-size: 10pt; padding: 3mm 6mm; border-radius: 999px;
}
"""


def read(p):
    with open(p, encoding="utf-8") as f:
        return f.read()


def build(backup, urls):
    body = read(f"{backup}/{TID}.html")
    assert body.count(PAGE_LINE) == 1, "page-1 footer line not found once"
    page1 = body.replace(PAGE_LINE, PAGE_LINE + FOOTER_PAGE.format(n=1), 1)
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
    if not sep or first.replace(FOOTER_PAGE.format(n=1), "", 1) + "\n" != orig.rstrip("\n") + "\n":
        fails.append("page 1 is not the backup plus the one page line")
    if not cand_css.startswith(orig_css):
        fails.append("stylesheet does not start with the backup byte for byte")
    if "border-left" in CSS_ADD:
        fails.append("new CSS draws an accent rail")
    if cand.count('<section class="page">') != 2:
        fails.append("not two pages")
    page2 = rest
    allowed = {"report_date", "practitioner_logo_url", "practitioner_name", "practitioner_email",
               "show_offer", "list_price", "today_price", "buy_url"}
    extra = liquid_vars(page2) - allowed
    if extra:
        fails.append("page 2 uses variables outside the payload and its assigns: " + ", ".join(sorted(extra)))
    for bad in ("six questions", "21 metrics", "21 health"):
        if bad in page2.lower():
            fails.append(f"wrong fact on page 2: {bad}")
    for need in ("nine answers", "Twenty health metrics", "Example pictures, not yours", "Page 2 of 2", "{{ list_price }}", "{{ today_price }}", "{{ buy_url }}"):
        if need not in page2:
            fails.append(f"page 2 missing: {need}")
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
