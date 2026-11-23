#!/usr/bin/env python3
"""v50 differs from v49 only in module 63's html (the report e-mail, K24) and the name; the test
site's file also prints from the large-charts PROOF template (module 48).

Usage: python3 check.py <v49.blueprint.json> <v50.blueprint.json> [--proof <template id>]
(the test site's pair needs --proof: the proof template's id is kept out of this public repository)
Exit 0 = pass. Run against v49 itself and it must fail.

The e-mail, top to bottom: "Great job, <name>." and "Thank you for filling in your report.";
"Your Full Report is attached as a PDF."; the reply line as today; when the offer shows, a divider
and then the upgrade card, set apart by space, its four texts and button unchanged. No picture.
Subject, attachment name and legal footer unchanged.
"""
import json
import re
import sys

# Each change as (v49 text, v50 text); undone in reverse, the candidate must give v49 back exactly.
CHANGES = [
    ('<h2 style="font-family:Poppins,Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;font-size:20px;font-weight:700;color:#004F59;margin:0 0 14px;line-height:1.3;">Hi {{if(contains(1.client_name; "@"); "there"; first(split(1.client_name; )))}}, your Full Report is in</h2>\n'
     '<p style="font-size:15px;line-height:1.7;color:#2c3e50;margin:0 0 18px;">Your full longevity report is attached as a PDF.</p>',
     '<h2 style="font-family:Poppins,Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;font-size:20px;font-weight:700;color:#004F59;margin:0 0 14px;line-height:1.3;">Great job{{if(contains(ifempty(1.client_name; "@"); "@"); emptystring; ", " + first(split(1.client_name; )))}}.</h2>\n'
     '<p style="font-size:15px;line-height:1.7;color:#2c3e50;margin:0 0 8px;">Thank you for filling in your report.</p>\n'
     '<p style="font-size:15px;line-height:1.7;color:#2c3e50;margin:0 0 18px;">Your Full Report is attached as a PDF.</p>'),
    ('{{if(1.timeline_offer_show = "1"; "<tr><td style=""padding:0 32px 32px;',
     '{{if(1.timeline_offer_show = "1"; "<tr><td style=""padding:0 32px;""><div style=""border-top:1px solid #e4e6ea;height:0;line-height:0;font-size:0;"">&nbsp;</div></td></tr><tr><td style=""padding:28px 32px 32px;'),
]
fails = []


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
        walk(m.get("onerror", []), out)
    return out


base = json.load(open(sys.argv[1], encoding="utf-8"))
cand = json.load(open(sys.argv[2], encoding="utf-8"))
B, C = walk(base["flow"], {}), walk(cand["flow"], {})
stby = "STBY" in base["name"]
if sorted(B) != sorted(C):
    fails.append("module ids differ")

html = C.get(63, {}).get("mapper", {}).get("html", "")
back = html
for old, new in reversed(CHANGES):
    if back.count(new) != 1:
        fails.append(f"#63 change not found once: {new[:60]!r}")
    back = back.replace(new, old)
if back != B[63]["mapper"]["html"]:
    fails.append("#63 html differs beyond the two changes")

order = ["Great job", "Thank you for filling in your report.", "Your Full Report is attached as a PDF.",
         "Questions? Just reply to this email", "border-top:1px solid #e4e6ea;height:0", "An upgrade for your Full Report",
         "See your years beside the people you love", "Your Healthspan Timeline is ", "Yes, upgrade me to the Timeline",
         "NOT MEDICAL ADVICE."]
at = [html.find(s) for s in order]
if -1 in at or at != sorted(at):
    fails.append(f"#63 order or text missing: {dict(zip(order, at))}")
if "your Full Report is in" in html or "full longevity report is attached" in html:
    fails.append("#63 still opens with the old lines")
if html.count("<img") != B[63]["mapper"]["html"].count("<img"):
    fails.append("#63 a picture was added or removed")
if re.search(r"\bthere\b", (html.split("Great job", 1) + [""])[1].split("</h2>", 1)[0]):
    fails.append('#63 the greeting can still read "Great job, there."')
for k in ("subject", "attachments", "to", "cc", "bcc", "replyTo", "from", "contentType"):
    if C[63]["mapper"].get(k) != B[63]["mapper"].get(k):
        fails.append(f"#63 {k} changed")

tid = C.get(48, {}).get("mapper", {}).get("document_template_id")
proof = sys.argv[sys.argv.index("--proof") + 1] if "--proof" in sys.argv else None
if stby and (not proof or tid != proof or tid == B[48]["mapper"]["document_template_id"]):
    fails.append(f"#48 prints from {tid}, want the large-charts proof given with --proof")
if not stby and tid != B[48]["mapper"]["document_template_id"]:
    fails.append("#48 template changed in the live file")


def own(x):
    return json.dumps({a: b for a, b in x.items() if a not in ("routes", "onerror")}, sort_keys=True)  # children checked on their own


for k, m in C.items():
    if k == 63:
        m63 = dict(m, mapper={a: b for a, b in m["mapper"].items() if a != "html"})
        b63 = dict(B[k], mapper={a: b for a, b in B[k]["mapper"].items() if a != "html"})
        if own(m63) != own(b63):
            fails.append("#63 changed beyond its html")
        continue
    if k == 48 and stby:
        m48 = dict(m, mapper=dict(m["mapper"], document_template_id=None))
        b48 = dict(B[k], mapper=dict(B[k]["mapper"], document_template_id=None))
        if own(m48) != own(b48):
            fails.append("#48 changed beyond its template")
        continue
    if own(m) != own(B[k]):
        fails.append(f"#{k} changed")
if {a: b for a, b in cand.items() if a not in ("flow", "name")} != {a: b for a, b in base.items() if a not in ("flow", "name")}:
    fails.append("top-level keys changed")

want = "STBY — Longevity v2 — v50 (email-fenced, report e-mail thanks first, large charts)" if stby else "Longevity v2 — v50 (report e-mail thanks first)"
if cand["name"] != want:
    fails.append("scenario name")
if stby and C[63]["mapper"].get("to") != B[63]["mapper"].get("to"):
    fails.append("the test site's e-mail fence moved")
if not stby and "stby" in json.dumps(cand).lower():
    fails.append("a live blueprint mentions stby")
for f in fails:
    print("FAIL", f)
print("PASS" if not fails else f"{len(fails)} failures")
sys.exit(1 if fails else 0)
