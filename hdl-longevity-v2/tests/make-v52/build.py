#!/usr/bin/env python3
"""Make blueprint v52: the client's health note on the Comprehensive Report (plan 13).

Usage: python3 build.py <v51.blueprint.json> <v52.blueprint.json> --env live|stby
                        [--proof-consultation <template id>]   (stby: required)

Module 3 (the consultation PDF) passes its template a fixed list of fields; v52 adds one, health_note,
after reviewed_line, built with the module's own JSON-safe expression. The plugin sends it only when a
practitioner has approved the note (hdlv2_ff_health_analysis on); absent, it is "" and the template prints
neither the heading nor the note, so v52 reads as v51 on a scenario whose plugin does not send it. The
note is HTML the plugin escaped (to_html()); it carries its own <p> and <br> because this expression turns
every line break into a space. The test site's file also prints the consultation PDF from the proof copy
whose id is passed on the command line (kept out of this public repository). Nothing else changes
(check.py asserts it).
"""
import json
import re
import sys

NAMES = {
    "live": "Longevity v2 — v52 (health note)",
    "stby": "STBY — Longevity v2 — v52 (email-fenced, health note, proofs)",
}
PDF_MODULE, AFTER, FIELD = 3, "reviewed_line", "health_note"


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
        walk(m.get("onerror", []), out)
    return out


def note_line(payload):
    """Where the new line goes (just after the `reviewed_line` field) and the line, in the module's own expression."""
    expr = re.search(r'"client_name": "(\{\{.*?\}\})",\n', payload).group(1)
    assert "1.client_name" in expr and expr.count("1.client_name") == 1
    line = re.search(r'\n  "' + AFTER + r'": "[^\n]*",\n', payload)
    assert line and payload.count('"' + AFTER + '":') == 1, AFTER
    assert '"' + FIELD + '":' not in payload, "already has " + FIELD
    return line.end(), '  "' + FIELD + '": "' + expr.replace("1.client_name", "1." + FIELD) + '",\n'


if __name__ == "__main__":
    src, dst, env = sys.argv[1], sys.argv[2], sys.argv[sys.argv.index("--env") + 1]
    bp = json.load(open(src, encoding="utf-8"))
    mods = walk(bp["flow"], {})
    p = mods[PDF_MODULE]["mapper"]["payload"]
    at, ins = note_line(p)
    mods[PDF_MODULE]["mapper"]["payload"] = p[:at] + ins + p[at:]
    if env == "stby":
        mods[PDF_MODULE]["mapper"]["document_template_id"] = sys.argv[sys.argv.index("--proof-consultation") + 1]
    bp["name"] = NAMES[env]
    json.dump(bp, open(dst, "w", encoding="utf-8"), ensure_ascii=False, indent=4)
    print("wrote", dst)
