#!/usr/bin/env python3
"""v52 differs from v51 only where build.py says (plan 13), and reads as v51 when the plugin sends no health_note.

Usage: python3 check.py <v51.blueprint.json> <v52.blueprint.json> [--proof-consultation <id>]
(the test site's pair needs the id: it is kept out of this public repository)
Exit 0 = pass. Run against v51 itself and it must fail.

  1  Module 3's payload is v51's with exactly one line added after reviewed_line: health_note, in the
     module's own JSON-safe expression (the same replace() chain as client_name). Every other module,
     field and top-level key is v51's (the test site's module 3 differs only in its template id).
  2  The live file prints from v51's template and never mentions the test site; the test site's file
     prints module 3 from the proof given, module 48 from v51's proof, and keeps every e-mail fence.
"""
import json
import os
import sys

sys.dont_write_bytecode = True  # importing build.py must not leave a __pycache__ in the repository
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from build import AFTER, FIELD, NAMES, PDF_MODULE, note_line, walk  # noqa: E402

fails = []
base = json.load(open(sys.argv[1], encoding="utf-8"))
cand = json.load(open(sys.argv[2], encoding="utf-8"))
B, C = walk(base["flow"], {}), walk(cand["flow"], {})
stby = "STBY" in base["name"]
env = "stby" if stby else "live"
proof = sys.argv[sys.argv.index("--proof-consultation") + 1] if "--proof-consultation" in sys.argv else None
if sorted(B) != sorted(C):
    fails.append("module ids differ")

# 1 — module 3 gains one line; nothing else moves.
bp = B[PDF_MODULE]["mapper"]["payload"]
try:
    at, ins = note_line(bp)
    if C[PDF_MODULE]["mapper"].get("payload") != bp[:at] + ins + bp[at:]:
        fails.append(f"#{PDF_MODULE} payload is not v51's with {FIELD} added after {AFTER}")
except AssertionError as e:
    fails.append(f"#{PDF_MODULE} base payload: {e}")
cp = C[PDF_MODULE]["mapper"].get("payload", "")
if cp.count('"' + FIELD + '":') != 1:
    fails.append(f"#{PDF_MODULE} does not pass {FIELD} once")
if '"' + FIELD + '": "{{replace(replace(replace(replace(replace(1.' + FIELD + ';' not in cp:
    fails.append(f"#{PDF_MODULE} {FIELD} is not built with the module's JSON-safe expression")


def strip(m, mid):
    m = json.loads(json.dumps(m))
    if mid == PDF_MODULE:
        m["mapper"]["payload"] = None
        if stby:
            m["mapper"]["document_template_id"] = None
    return json.dumps({a: b for a, b in m.items() if a not in ("routes", "onerror")}, sort_keys=True)


for k in C:
    if k in B and strip(C[k], k) != strip(B[k], k):
        fails.append(f"#{k} changed beyond its named fields")
if {a: b for a, b in cand.items() if a not in ("flow", "name")} != {a: b for a, b in base.items() if a not in ("flow", "name")}:
    fails.append("top-level keys changed")
if FIELD in json.dumps({k: {a: b for a, b in m.items() if a not in ("routes", "onerror")} for k, m in C.items() if k != PDF_MODULE}):
    fails.append(f"a module other than #{PDF_MODULE} names {FIELD}")

# 2 — template ids, fences, names.
tid = C[PDF_MODULE]["mapper"].get("document_template_id")
if stby and (not proof or tid != proof or tid == B[PDF_MODULE]["mapper"]["document_template_id"]):
    fails.append(f"#{PDF_MODULE} prints from {tid}, want the proof given with --proof-consultation")
if not stby and tid != B[PDF_MODULE]["mapper"]["document_template_id"]:
    fails.append(f"#{PDF_MODULE} template changed in the live file")
if C[48]["mapper"].get("document_template_id") != B[48]["mapper"].get("document_template_id"):
    fails.append("#48 template changed")
if stby:
    for k, m in C.items():
        if m.get("module") == "email:ActionSendEmail" and m["mapper"].get("to") != B[k]["mapper"].get("to"):
            fails.append(f"#{k} the test site's e-mail fence moved")
if not stby and "stby" in json.dumps(cand).lower():
    fails.append("a live blueprint mentions stby")
if cand["name"] != NAMES[env]:
    fails.append("scenario name")

for f in fails:
    print("FAIL", f)
print("PASS" if not fails else f"{len(fails)} failures")
sys.exit(1 if fails else 0)
