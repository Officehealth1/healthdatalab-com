#!/usr/bin/env python3
"""M1: Make blueprint v31-STBY differs from v29 only in the intended places.

Usage: python3 check.py <v29.blueprint.json> <candidate.blueprint.json>
Exit 0 = pass. Run against v29 itself and it must fail.
"""
import copy
import json
import sys

FENCE = "team+stby@irislab.com"
NEW_IDS = (259, 260, 261, 262, 263)
DUP_STAGE2 = "8d4de60c-57da-4a42-a3ff-6f6b1130e396"
DUP_DRAFT = "57764484-67c6-4232-9e2f-2cfcd241ba64"
DUP_FINAL = "a091f83e-1e92-40f5-aaa6-62f2bb4bb745"
NEW_FIELDS = ["form_flow", "distilled_why", "key_people_csv", "motivations_csv", "ai_reformulation", "why_picks",
              "why_abilities", "why_focus_areas", "why_people", "why_own_words", "why_picks_people",
              "why_picks_freedom", "why_picks_things"]
WHY8 = NEW_FIELDS[5:]
fails = []


def check(ok, msg):
    if not ok:
        fails.append(msg)


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
    return out


base = json.load(open(sys.argv[1], encoding="utf-8"))
cand = json.load(open(sys.argv[2], encoding="utf-8"))
B, C = walk(base["flow"], {}), walk(cand["flow"], {})

check(len(C) == len(B) + 5, f"module count {len(C)}, want {len(B) + 5}")
check(all(i in C for i in NEW_IDS) and not any(i in B for i in NEW_IDS), "new module ids 259-263 missing or clashing")

# Module 1: hook unchanged, 13 fields declared after the 21
check(C[1]["parameters"] == B[1]["parameters"], "webhook hook changed")
check([f["name"] for f in C[1]["metadata"]["interface"]] == [f["name"] for f in B[1]["metadata"]["interface"]] + NEW_FIELDS,
      "module 1 interface is not v29's 21 fields + the 13 new ones")

# Router: one route more, the new one last, with the no-AI flow
rb, rc = B[23]["routes"], C[23]["routes"]
check(len(rc) == len(rb) + 1, "router 23 does not have exactly one more route")
new = rc[-1]["flow"] if rc else []
check([m["id"] for m in new] == list(NEW_IDS), "new route is not 259 -> 263")
check([m["module"] for m in new] == [B[i]["module"] for i in (70, 71, 72, 88, 86)], "new route modules are not copies of 70, 71, 72, 88, 86")
check(not any("email" in m["module"] for m in new), "new route sends e-mail")
f = (C.get(259) or {}).get("filter", {})
check(f.get("conditions") == [[{"a": "{{1.report_type}}", "b": "stage2_why", "o": "text:equal"},
                               {"a": "{{1.form_flow}}", "b": "single", "o": "text:equal"}]], "new route filter wrong")
p259 = (C.get(259) or {}).get("mapper", {}).get("payload", "")
check(C.get(259, {}).get("mapper", {}).get("document_template_id") == DUP_STAGE2, "259 not on the Stage 2 duplicate")
check("76." not in p259, "259 payload reads module 76 (the AI step)")
for k in WHY8 + ["distilled_why", "ai_reformulation", "key_people_csv", "motivations_csv"]:
    check(f"1.{k};" in p259, f"259 payload missing {k}")
for k in ("key_fears", "why_now", "practitioner_brief", "vision_text"):
    check(f'"{k}": ""' in p259, f"259 payload {k} not empty")
check(C.get(260, {}).get("mapper", {}).get("document_id") == "{{259.id}}", "260 not reading 259")
check(C.get(261, {}).get("mapper", {}).get("url") == "{{260.download_url}}", "261 not reading 260")
check(C.get(262, {}).get("mapper", {}) == {"token": "{{1.token}}", "pdf_url": "{{260.download_url}}",
                                           "distilled_why": "{{1.distilled_why}}", "ai_reformulation": "{{1.ai_reformulation}}"}, "262 JSON wrong")
m263, m86 = copy.deepcopy(C.get(263, {})), B[86]
check(m263.get("mapper", {}).get("data") == "{{262.json}}", "263 not posting 262")
if m263:
    m263["mapper"]["data"] = m86["mapper"]["data"]
    check(m263["mapper"] == m86["mapper"] and m263["parameters"] == m86["parameters"], "263 POST differs from 86 beyond its data")

# Old Stage 2 route: belt added, nothing else
check(C[67]["filter"]["conditions"] == [B[67]["filter"]["conditions"][0] + [{"a": "{{1.form_flow}}", "b": "single", "o": "text:notequal"}]],
      "route 67 filter is not v29's + the form_flow belt")

# Draft + Final PDFs: duplicates and the eight fields, quote-safe
for mid, dup in ((48, DUP_DRAFT), (3, DUP_FINAL)):
    pl = C[mid]["mapper"]["payload"]
    check(C[mid]["mapper"]["document_template_id"] == dup, f"#{mid} not on its duplicate")
    check(pl.startswith(B[mid]["mapper"]["payload"].rstrip()[:-1].rstrip()), f"#{mid} payload does not start with v29's")
    for k in WHY8:
        check(f'"{k}": "{{{{replace(replace(replace(replace(replace(1.{k};' in pl, f"#{mid} missing quote-safe {k}")

# Fence: every e-mail still goes to the STBY inbox only
for k, m in C.items():
    if m["module"] == "email:ActionSendEmail":
        to = m["mapper"]["to"]
        check((to if isinstance(to, list) else [to]) == [FENCE], f"#{k} 'to' is not fenced")
        check(not m["mapper"].get("cc") and not m["mapper"].get("bcc"), f"#{k} has cc/bcc")

# Everything else byte-identical to v29
r = copy.deepcopy(cand)
R = walk(r["flow"], {})
if all(k in R for k in (1, 3, 23, 48, 67)) and len(R[23]["routes"]) == len(rb) + 1:
    R[1]["metadata"]["interface"] = B[1]["metadata"]["interface"]
    R[67]["filter"] = B[67]["filter"]
    for mid in (48, 3):
        R[mid]["mapper"] = B[mid]["mapper"]
        R[mid]["metadata"]["restore"] = B[mid]["metadata"]["restore"]
    R[23]["routes"] = R[23]["routes"][:-1]
    r["name"] = base["name"]
    check(json.dumps(r, sort_keys=True) == json.dumps(base, sort_keys=True), "something else changed against v29")

for f in fails:
    print("FAIL", f)
print("MAKE V31:", "FAIL" if fails else "PASS")
sys.exit(1 if fails else 0)
