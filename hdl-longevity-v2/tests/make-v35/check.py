#!/usr/bin/env python3
"""Make blueprint v35 differs from its source only in the intended places.

Usage: python3 check.py <source.blueprint.json> <candidate.blueprint.json>
Exit 0 = pass. Run against the source itself and it must fail.
Designer positions are ignored (cosmetic). Prints the leaf diff against the source.
"""
import copy
import json
import re
import sys

FENCE = "team+stby@irislab.com"
fails = []


def check(ok, msg):
    if not ok:
        fails.append(msg)


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
        walk(m.get("onerror", []), out)
    return out


def all_ids(flow, out):
    for m in flow:
        out.append(m["id"])
        for r in m.get("routes", []):
            all_ids(r["flow"], out)
        all_ids(m.get("onerror", []), out)
    return out


def no_designer(x):
    if isinstance(x, dict):
        return {k: no_designer(v) for k, v in x.items() if k != "designer"}
    if isinstance(x, list):
        return [no_designer(v) for v in x]
    return x


def leaves(x, path=""):
    if isinstance(x, dict):
        for k, v in x.items():
            yield from leaves(v, f"{path}/{k}")
    elif isinstance(x, list):
        for i, v in enumerate(x):
            yield from leaves(v, f"{path}/{i}")
    else:
        yield path, x


def parses(payload):
    try:
        return json.loads(re.sub(r"\{\{.*?\}\}", "X", payload, flags=re.S))
    except ValueError:
        return None


base = json.load(open(sys.argv[1], encoding="utf-8"))
cand = json.load(open(sys.argv[2], encoding="utf-8"))
stby = "STBY" in base["name"]
B, C = walk(base["flow"], {}), walk(cand["flow"], {})
ids = all_ids(cand["flow"], [])

check(len(ids) == 47, f"{len(ids)} modules, not 47")
check(len(ids) == len(set(ids)), "module ids repeat")
check(all(ids.count(i) == 1 for i in (265, 266, 267)), "ids 265-267 not present exactly once")
route = next((r["flow"] for m in cand["flow"] for r in m.get("routes", []) if [x["id"] for x in r["flow"]][:4] == [29, 42, 33, 34]), [])
check([x["id"] for x in route] == [29, 42, 33, 34, 265, 266, 267, 37], "route 'Stage 1 Results PDF' is not 29 42 33 34 265 266 267 37")
check(C.get(1, {}).get("parameters") == B[1]["parameters"], "webhook hook changed")
if all(k in C for k in (265, 266, 267)):
    g, d, f = C[265], C[266], C[267]
    want = no_designer(copy.deepcopy(B[42]))
    want["id"] = 265
    pl = want["mapper"]["payload"].splitlines()
    i = next(n for n, ln in enumerate(pl) if ln.strip().startswith('"offer_show":'))
    got = g["mapper"]["payload"].splitlines()
    check(len(got) == len(pl) and got[i] == '  "offer_show": "0",', "#265 offer_show is not the literal \"0\"")
    check([a for n, a in enumerate(got) if n != i] == [a for n, a in enumerate(pl) if n != i], "#265 payload differs from #42 beyond offer_show")
    check(parses(g["mapper"]["payload"]) is not None and parses(g["mapper"]["payload"])["offer_show"] == "0", "#265 payload is not JSON once filled")
    want["mapper"]["payload"] = g["mapper"]["payload"]
    check(no_designer(g) == want, "#265 is not #42 (template, connection, rest)")
    for new, old, field, val in ((d, 33, "document_id", "{{265.id}}"), (f, 34, "url", "{{266.download_url}}")):
        w = no_designer(copy.deepcopy(B[old]))
        w["id"], w["mapper"][field] = new["id"], val
        check(no_designer(new) == w, f"#{new['id']} is not #{old} with {field} {val}")
check(C[40]["mapper"]["attachments"][0]["data"] == "{{267.data}}", "#40 does not attach the one-page copy")
check('"pdf_url": "{{266.download_url}}"' in C[258]["mapper"]["data"], "#258 does not send the one-page URL")
check(C[41]["mapper"]["attachments"][0]["data"] == "{{34.data}}", "#41 (client) does not keep the two-page PDF")
if stby:
    for k, m in C.items():
        if m["module"] == "email:ActionSendEmail":
            to = m["mapper"]["to"]
            check((to if isinstance(to, list) else [to]) == [FENCE], f"#{k} 'to' is not fenced")
            check(not m["mapper"].get("cc") and not m["mapper"].get("bcc"), f"#{k} has cc/bcc")
    check(cand["name"] == "STBY — Longevity v2 — v35 (email-fenced, practitioner one-page Stage 1)", "scenario name")
else:
    check(cand["name"] == "Longevity v2 — v35 (practitioner one-page Stage 1)", "scenario name")
    check("stby" not in json.dumps(cand).lower(), "a live blueprint mentions stby")

# Everything else byte-equal to the source.
r = no_designer(copy.deepcopy(cand))
R = walk(r["flow"], {})
if all(k in R for k in (265, 266, 267, 40, 258)):
    for flow in [x["flow"] for m in r["flow"] for x in m.get("routes", [])]:
        flow[:] = [m for m in flow if m["id"] not in (265, 266, 267)]
    R[40]["mapper"]["attachments"][0]["data"] = B[40]["mapper"]["attachments"][0]["data"]
    R[258]["mapper"]["data"] = B[258]["mapper"]["data"]
    r["name"] = base["name"]
    check(json.dumps(r, sort_keys=True) == json.dumps(no_designer(base), sort_keys=True), "something else changed against the source")

def by_module(bp):
    """Leaves keyed by module id (not list position: the three new modules shift positions)."""
    out = {f"/name": bp["name"]}
    for mid, m in walk(no_designer(bp)["flow"], {}).items():
        own = {k: v for k, v in m.items() if k != "routes"}
        out.update({f"#{mid}{k}": v for k, v in leaves(own)})
    return out


bl, cl = by_module(base), by_module(cand)
changed = sorted({k.split("/")[0] if k.startswith("#26") and k.split("/")[0] in ("#265", "#266", "#267") else k
                  for k in set(bl) | set(cl) if bl.get(k) != cl.get(k)})
print("leaf diff:", len(changed))
for k in changed:
    print("  ", k)
for f in fails:
    print("FAIL", f)
print("MAKE V35:", "FAIL" if fails else "PASS")
sys.exit(1 if fails else 0)
