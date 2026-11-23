#!/usr/bin/env python3
"""Make blueprint v50: the report e-mail says thanks first, the upgrade in its own card (K24, plan 10).

Usage: python3 build.py <v49.blueprint.json> <v50.blueprint.json> --env live|stby [--proof <template id>]

Module 63 (the Full Report e-mail): "Great job, <name>." and "Thank you for filling in your
report." (Matthew's words, draft copy for him), then "Your Full Report is attached as a PDF.", the
reply line as today, then, when the offer shows, a divider and the upgrade card set apart by space.
The greeting drops the name when there is none or it is an e-mail address (never "Great job, there.").
The name goes through v49's own first(split(name; )); Make does not split on that bare space, so
the whole name prints, as in v49 (seen on the test site 2026-11-23).
The test site's file also prints from the large-charts PROOF template (module 48), whose id is
passed with --proof (kept out of this public repository). Nothing else changes (check.py asserts it).
"""
import json
import sys

NAMES = {
    "live": "Longevity v2 — v50 (report e-mail thanks first)",
    "stby": "STBY — Longevity v2 — v50 (email-fenced, report e-mail thanks first, large charts)",
}
H2 = '<h2 style="font-family:Poppins,Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;font-size:20px;font-weight:700;color:#004F59;margin:0 0 14px;line-height:1.3;">'
P = '<p style="font-size:15px;line-height:1.7;color:#2c3e50;margin:0 0 '
CHANGES = [
    (H2 + 'Hi {{if(contains(1.client_name; "@"); "there"; first(split(1.client_name; )))}}, your Full Report is in</h2>\n'
     + P + '18px;">Your full longevity report is attached as a PDF.</p>',
     H2 + 'Great job{{if(contains(ifempty(1.client_name; "@"); "@"); emptystring; ", " + first(split(1.client_name; )))}}.</h2>\n'
     + P + '8px;">Thank you for filling in your report.</p>\n'
     + P + '18px;">Your Full Report is attached as a PDF.</p>'),
    ('{{if(1.timeline_offer_show = "1"; "<tr><td style=""padding:0 32px 32px;',
     '{{if(1.timeline_offer_show = "1"; "<tr><td style=""padding:0 32px;""><div style=""border-top:1px solid #e4e6ea;height:0;line-height:0;font-size:0;"">&nbsp;</div></td></tr><tr><td style=""padding:28px 32px 32px;'),
]


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
        walk(m.get("onerror", []), out)
    return out


src, dst, env = sys.argv[1], sys.argv[2], sys.argv[sys.argv.index("--env") + 1]
bp = json.load(open(src, encoding="utf-8"))
mods = walk(bp["flow"], {})
html = mods[63]["mapper"]["html"]
for old, new in CHANGES:
    assert html.count(old) == 1, f"#63: not found once: {old[:60]!r}"
    html = html.replace(old, new)
mods[63]["mapper"]["html"] = html
if env == "stby":
    mods[48]["mapper"]["document_template_id"] = sys.argv[sys.argv.index("--proof") + 1]
bp["name"] = NAMES[env]
json.dump(bp, open(dst, "w", encoding="utf-8"), ensure_ascii=False, indent=4)
print("wrote", dst)
