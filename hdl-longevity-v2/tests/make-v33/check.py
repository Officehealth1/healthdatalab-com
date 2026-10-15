#!/usr/bin/env python3
"""M3: Make blueprint v33-STBY differs from v32 only in the intended places.

Usage: python3 check.py <v32.blueprint.json> <candidate.blueprint.json>
Exit 0 = pass. Run against v32 itself and it must fail.
"""
import copy
import json
import re
import sys

FENCE = "team+stby@irislab.com"
OFFER = ("offer_show", "offer_title", "offer_hook", "offer_today", "offer_list", "offer_code",
         "offer_url", "offer_caption", "offer_video_url")
CLIENT_EMAILS = (41, 63, 84, 17)  # 40, 81, 91 are practitioner copies
STAGE23 = re.compile(r"\bStage ?(2|3|two|three)\b", re.I)
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


def all_ids(flow, out):
    for m in flow:
        out.append(m["id"])
        for r in m.get("routes", []):
            all_ids(r["flow"], out)
        all_ids(m.get("onerror", []), out)
    return out


def templates(flow):
    return sorted((k, m["mapper"]["document_template_id"]) for k, m in walk(flow, {}).items()
                  if m["module"] == "pdfmonkey:GenerateDocument")


def parses(payload):
    """The payload is JSON once Make fills each {{...}} (stand-in: a bare word)."""
    try:
        return json.loads(re.sub(r"\{\{.*?\}\}", "X", payload, flags=re.S))
    except ValueError:
        return None


base = json.load(open(sys.argv[1], encoding="utf-8"))
cand = json.load(open(sys.argv[2], encoding="utf-8"))
B, C = walk(base["flow"], {}), walk(cand["flow"], {})

check(sorted(C) == sorted(B), "module ids differ from v32")
ids = all_ids(cand["flow"], [])
check(len(ids) == len(set(ids)), "module ids repeat (Make: 'references non-existing module')")
check(templates(cand["flow"]) == templates(base["flow"]), "a PDFMonkey template id changed")
check(C[1]["parameters"] == B[1]["parameters"], "webhook hook changed")
check([f["name"] for f in C[1]["metadata"]["interface"]] == [f["name"] for f in B[1]["metadata"]["interface"]] + list(OFFER),
      "module 1 interface is not v32's + the nine offer keys")
old, pl = B[42]["mapper"]["payload"], C[42]["mapper"]["payload"]
check(pl.startswith(old.rstrip()[:-1].rstrip()), "#42 payload does not start with v32's")
doc = parses(pl)
check(doc is not None, "#42 payload is not JSON once filled")
if doc is not None:
    check(list(doc)[-9:] == list(OFFER) and len(doc) == len(parses(old)) + 9, "#42 does not add the nine offer keys at the end")
cn = re.search(r'"client_name":\s*"(\{\{.*?\}\})"', pl).group(1)
for k in OFFER:
    check(f'"{k}": "{cn.replace("1.client_name", "1." + k)}"' in pl, f"#42 {k} lacks client_name's escaping chain")
for mid in (48, 3, 259):
    check(parses(C[mid]["mapper"]["payload"]) is not None, f"#{mid} payload is not JSON once filled")
for k, m in C.items():
    if m["module"] == "email:ActionSendEmail":
        to = m["mapper"]["to"]
        check((to if isinstance(to, list) else [to]) == [FENCE], f"#{k} 'to' is not fenced")
        check(not m["mapper"].get("cc") and not m["mapper"].get("bcc"), f"#{k} has cc/bcc")
for k in CLIENT_EMAILS:
    mp = C[k]["mapper"]
    check(not STAGE23.search(mp["subject"] + mp["html"]), f"#{k} client email still says Stage 2/3")
check(C[41]["mapper"]["subject"] == B[41]["mapper"]["subject"], "#41 subject changed (it says Stage 1)")
check("Full Report" in C[41]["mapper"]["html"] and "Full Report" in C[84]["mapper"]["subject"], "client emails do not name the Full Report")

r = copy.deepcopy(cand)
R = walk(r["flow"], {})
if all(k in R for k in (1, 41, 42, 84)):
    R[1]["metadata"]["interface"] = B[1]["metadata"]["interface"]
    R[42]["mapper"]["payload"] = old
    for mid in (41, 84):
        for f in ("subject", "html"):
            R[mid]["mapper"][f] = B[mid]["mapper"][f]
    r["name"] = base["name"]
    check(json.dumps(r, sort_keys=True) == json.dumps(base, sort_keys=True), "something else changed against v32")
check(cand["name"] == "STBY — Longevity v2 — v33 (email-fenced, choices branch, Full Report)", "scenario name")

for f in fails:
    print("FAIL", f)
print("MAKE V33:", "FAIL" if fails else "PASS")
sys.exit(1 if fails else 0)
