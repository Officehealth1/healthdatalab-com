#!/usr/bin/env python3
"""Make blueprint v49: no inbox pass in any PDF link (gate row F17, plan 2026-11-19 plan 4).

Usage: python3 build.py <v48.blueprint.json> <v49.blueprint.json> --env live|stby

The plugin puts the inbox pass (&own=...) on offer_url when hdlv2_ff_straight_to_form is on.
The results e-mail (module 41) keeps it: that is Matthew's straight-to-the-form (M31). The two
PDFMonkey documents (42 the client's, 265 the practitioner's one-page copy) get offer_url with
the pass cut out, so a forwarded PDF opens the buying page and never the form. Nothing else
changes (check.py asserts it).
"""
import json
import sys

NAMES = {
    "live": "Longevity v2 — v49 (no pass in the PDF)",
    "stby": "STBY — Longevity v2 — v49 (email-fenced, no pass in the PDF)",
}
OLD = "1.offer_url;"
NEW = 'replace(1.offer_url; "/&own=[^&]*/g"; emptystring);'


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
for k in (42, 265):
    p = mods[k]["mapper"]["payload"]
    assert p.count(OLD) == 1, f"#{k}: offer_url not found once"
    mods[k]["mapper"]["payload"] = p.replace(OLD, NEW)
bp["name"] = NAMES[env]
json.dump(bp, open(dst, "w", encoding="utf-8"), ensure_ascii=False, indent=4)
print("wrote", dst)
