#!/usr/bin/env python3
"""M2: Make blueprint v32-STBY differs from v31 only in the intended places.

Usage: python3 check.py <v31.blueprint.json> <candidate.blueprint.json> <stage1-duplicate-template-id>
Exit 0 = pass. Run against v31 itself and it must fail.
"""
import copy
import json
import sys

FENCE = "team+stby@irislab.com"
PROD_STAGE1 = "b08e10ad-b7be-4010-b7b8-4dcdce217ddf"
FIELD = "why_chart_url"
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
dup = sys.argv[3]
B, C = walk(base["flow"], {}), walk(cand["flow"], {})

check(sorted(C) == sorted(B), "module ids differ from v31")
check(B[42]["mapper"]["document_template_id"].lower() == PROD_STAGE1, "v31 module 42 is not on the production Stage 1 template (wrong base?)")
check(C[42]["mapper"]["document_template_id"] == dup and dup.lower() != PROD_STAGE1, "module 42 not on the Stage 1 duplicate")
check(C[1]["parameters"] == B[1]["parameters"], "webhook hook changed")
check([f["name"] for f in C[1]["metadata"]["interface"]] == [f["name"] for f in B[1]["metadata"]["interface"]] + [FIELD],
      "module 1 interface is not v31's + why_chart_url")
for mid in (48, 3, 259):
    pl, old = C[mid]["mapper"]["payload"], B[mid]["mapper"]["payload"]
    check(pl.startswith(old.rstrip()[:-1].rstrip()), f"#{mid} payload does not start with v31's")
    check(f"1.{FIELD}" in pl and pl.count(FIELD) == 2, f"#{mid} does not send why_chart_url once")
    check(json.loads(pl.replace("{{", "").replace("}}", "")) is not None if "{{" not in pl else True, f"#{mid} payload")
for k, m in C.items():
    if m["module"] == "email:ActionSendEmail":
        to = m["mapper"]["to"]
        check((to if isinstance(to, list) else [to]) == [FENCE], f"#{k} 'to' is not fenced")
        check(not m["mapper"].get("cc") and not m["mapper"].get("bcc"), f"#{k} has cc/bcc")

r = copy.deepcopy(cand)
R = walk(r["flow"], {})
if all(k in R for k in (1, 3, 42, 48, 259)):
    R[1]["metadata"]["interface"] = B[1]["metadata"]["interface"]
    R[42]["mapper"] = B[42]["mapper"]
    R[42]["metadata"]["restore"] = B[42]["metadata"]["restore"]
    for mid in (48, 3, 259):
        R[mid]["mapper"]["payload"] = B[mid]["mapper"]["payload"]
    r["name"] = base["name"]
    check(json.dumps(r, sort_keys=True) == json.dumps(base, sort_keys=True), "something else changed against v31")

for f in fails:
    print("FAIL", f)
print("MAKE V32:", "FAIL" if fails else "PASS")
sys.exit(1 if fails else 0)
