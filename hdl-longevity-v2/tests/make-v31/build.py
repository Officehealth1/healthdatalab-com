#!/usr/bin/env python3
"""Make blueprint v31-STBY: v29 + a no-AI Stage 2 branch for choices-page clients.

Usage: python3 build.py <v29.blueprint.json> <v31.blueprint.json>

Changes (plan memory/prompts/2026-10-07_why_everywhere_v2.md, Design 5):
  - module 1 declares 13 more text fields (designer only; Make passes every field)
  - router 23 gets a route "Stage 2 Why — choices (no AI)": report_type = stage2_why
    AND form_flow = single; copies of 70 -> 71 -> 72 -> 88 -> 86, no e-mail module
  - route 67 (old Stage 2) gets a belt condition: form_flow not equal single
  - modules 48 (draft PDF) and 3 (final PDF) send the eight why_* fields
  - modules 70-copy, 48 and 3 point at the DUPLICATE templates (STBY file only)
Everything else is left exactly as in v29 (check.py asserts it).
"""
import copy
import json
import re
import sys

DUP = {
    "stage2": ("8d4de60c-57da-4a42-a3ff-6f6b1130e396", "DUP — Stage 2 Why — choices block — 2026-10-07"),
    48: ("57764484-67c6-4232-9e2f-2cfcd241ba64", "DUP — HDL V2 Draft Longevity Report — choices block — 2026-10-07"),
    3: ("a091f83e-1e92-40f5-aaa6-62f2bb4bb745", "DUP — Final Longevity Report v3 (DRAFT - WIP) — choices block — 2026-10-07"),
}
NEW_FIELDS = ["form_flow", "distilled_why", "key_people_csv", "motivations_csv", "ai_reformulation", "why_picks",
              "why_abilities", "why_focus_areas", "why_people", "why_own_words", "why_picks_people",
              "why_picks_freedom", "why_picks_things"]
WHY8 = ["why_picks", "why_abilities", "why_focus_areas", "why_people", "why_own_words",
        "why_picks_people", "why_picks_freedom", "why_picks_things"]
ROUTE_NAME = "Stage 2 Why — choices (no AI)"
BELT = {"a": "{{1.form_flow}}", "b": "single", "o": "text:notequal"}
FIRST_NEW_ID = 259


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
    return out


def wrapper(payload, field):
    """The quote-safe replace(...) wrapper a module already uses, split around its argument."""
    expr = re.search(r'"%s":\s*"(\{\{.*?\}\})"' % field, payload).group(1)
    pre, post = expr.split("1." + field)
    return pre, post


def esc(expr, wrap):
    return wrap[0] + expr + wrap[1]


def add_fields(payload, wrap):
    body = payload.rstrip()
    assert body.endswith("}")
    extra = "".join(f',\n  "{f}": "{esc("1." + f, wrap)}"' for f in WHY8)
    return body[:-1].rstrip() + extra + "\n}"


def build(v29):
    bp = copy.deepcopy(v29)
    bp["name"] = "STBY — Longevity v2 — v31 (email-fenced, choices branch)"
    M = walk(bp["flow"], {})

    M[1]["metadata"]["interface"] += [{"name": f, "type": "text"} for f in NEW_FIELDS]
    M[67]["filter"]["conditions"][0].append(dict(BELT))

    for mid, field in ((48, "ai_opening"), (3, "family_history")):
        mod = M[mid]
        mod["mapper"]["payload"] = add_fields(mod["mapper"]["payload"], wrapper(mod["mapper"]["payload"], field))
        mod["mapper"]["document_template_id"] = DUP[mid][0]
        mod["metadata"]["restore"]["expect"]["document_template_id"]["label"] = DUP[mid][1]

    # The no-AI branch: copies of 70 -> 71 -> 72 -> 88 -> 86.
    ids = dict(zip((70, 71, 72, 88, 86), range(FIRST_NEW_ID, FIRST_NEW_ID + 5)))
    para = wrapper(M[70]["mapper"]["payload"], "client_name")
    pdf = copy.deepcopy(M[70])
    pdf["id"] = ids[70]
    pdf["filter"] = {"name": ROUTE_NAME, "conditions": [[
        {"a": "{{1.report_type}}", "b": "stage2_why", "o": "text:equal"},
        {"a": "{{1.form_flow}}", "b": "single", "o": "text:equal"}]]}
    lines = []
    for key, val in (
            ("client_name", esc("1.client_name", para)), ("client_email", "{{1.client_email}}"),
            ("client_age", "{{1.client_age}}"), ("client_sex", "{{1.client_sex}}"),
            ("chronological_age", "{{1.chronological_age}}"), ("biological_age", "{{1.biological_age}}"),
            ("rate_of_ageing", "{{1.rate_of_ageing}}"),
            ("practitioner_name", esc("1.practitioner_name", para)), ("practitioner_email", "{{1.practitioner_email}}"),
            ("practitioner_logo_url", "{{1.practitioner_logo_url}}"), ("report_date", "{{1.report_date}}"),
            ("submitted_date", "{{1.submitted_at}}"),
            ("distilled_why", esc("1.distilled_why", para)), ("ai_reformulation", esc("1.ai_reformulation", para)),
            ("key_people", esc("1.key_people_csv", para)), ("key_motivations", esc("1.motivations_csv", para)),
            ("key_fears", ""), ("why_now", ""), ("practitioner_brief", ""), ("vision_text", ""),
    ) + tuple((f, esc("1." + f, para)) for f in WHY8):
        lines.append(f'  "{key}": "{val}"')
    pdf["mapper"]["payload"] = "{\n" + ",\n".join(lines) + "\n}"
    pdf["mapper"]["document_template_id"] = DUP["stage2"][0]
    pdf["metadata"]["restore"]["expect"]["document_template_id"]["label"] = DUP["stage2"][1]

    get = copy.deepcopy(M[71]); get["id"] = ids[71]; get["mapper"]["document_id"] = "{{%d.id}}" % ids[70]
    fetch = copy.deepcopy(M[72]); fetch["id"] = ids[72]; fetch["mapper"]["url"] = "{{%d.download_url}}" % ids[71]
    js = copy.deepcopy(M[88]); js["id"] = ids[88]
    # The plugin stores only pdf_url for a choices row; the structure's two
    # required text fields are filled from the webhook so the module never errors.
    js["mapper"] = {"token": "{{1.token}}", "pdf_url": "{{%d.download_url}}" % ids[71],
                    "distilled_why": "{{1.distilled_why}}", "ai_reformulation": "{{1.ai_reformulation}}"}
    post = copy.deepcopy(M[86]); post["id"] = ids[86]; post["mapper"]["data"] = "{{%d.json}}" % ids[88]
    for i, mod in enumerate((pdf, get, fetch, js, post)):
        mod["metadata"]["designer"] = {"x": 600 + 300 * i, "y": 1950}
    M[23]["routes"].append({"flow": [pdf, get, fetch, js, post]})
    return bp


if __name__ == "__main__":
    src = json.load(open(sys.argv[1], encoding="utf-8"))
    with open(sys.argv[2], "w", encoding="utf-8") as f:
        json.dump(build(src), f, ensure_ascii=False, indent=4)
    print("wrote", sys.argv[2])
