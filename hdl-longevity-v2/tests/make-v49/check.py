#!/usr/bin/env python3
"""v49 differs from v48 only in offer_url of modules 42 and 265 (gate row F17) and the name.

Usage: python3 check.py <v48.blueprint.json> <v49.blueprint.json>
Exit 0 = pass. Run against v48 itself and it must fail.
"""
import json
import re
import sys

NEW = 'replace(1.offer_url; "/&own=[^&]*/g"; emptystring);'
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
if sorted(B) != sorted(C):
    fails.append("module ids differ")
for k in (42, 265):
    got = C.get(k, {}).get("mapper", {}).get("payload", "")
    line = next((ln for ln in got.splitlines() if '"offer_url":' in ln), "")
    if NEW not in line:
        fails.append(f"#{k} offer_url keeps the pass")
    if got.replace(NEW, "1.offer_url;") != B[k]["mapper"]["payload"]:
        fails.append(f"#{k} payload differs beyond offer_url")
    # The cut, as Make reads it: the pass goes, the e-mail in the fragment stays.
    url = "https://altituding.com/full-report-average#email=ivy%40example.test&own=1763500000." + "a" * 64
    if re.sub(r"&own=[^&]*", "", url) != "https://altituding.com/full-report-average#email=ivy%40example.test":
        fails.append("the pattern does not cut the pass")
for k, m in C.items():
    if k in (42, 265):
        continue
    own = lambda x: json.dumps({a: b for a, b in x.items() if a not in ("routes", "onerror")}, sort_keys=True)  # children are checked on their own
    if own(m) != own(B[k]):
        fails.append(f"#{k} changed")
if "1.offer_url +" not in json.dumps(C[41]["mapper"]):
    fails.append("#41 (the e-mail) no longer carries offer_url as sent")
stby = "STBY" in base["name"]
want = "STBY — Longevity v2 — v49 (email-fenced, no pass in the PDF)" if stby else "Longevity v2 — v49 (no pass in the PDF)"
if cand["name"] != want:
    fails.append("scenario name")
if not stby and "stby" in json.dumps(cand).lower():
    fails.append("a live blueprint mentions stby")
for f in fails:
    print("FAIL", f)
print("PASS" if not fails else f"{len(fails)} failures")
sys.exit(1 if fails else 0)
