#!/usr/bin/env python3
"""v51 differs from v50 only where build.py says (plan 12), and reads as v50 when the plugin sends no new field.

Usage: python3 check.py <v50.blueprint.json> <v51.blueprint.json> [--proof-paid <id> --proof-consultation <id>]
(the test site's pair needs both ids: they are kept out of this public repository)
Exit 0 = pass. Run against v50 itself and it must fail.

  1  Undo build.py's changes and every touched field is v50's, character for character; every other module,
     field and top-level key is v50's (the test site's two PDF modules differ only in their template id).
  2  Safe in any order: each report name is ifempty(1.report_name; <v50's words at that spot>), and the
     reviewed line prints only when the plugin sends it.
  3  Escaped: the reviewed line, the report names in HTML and every client name printed in the touched
     e-mails' HTML go through escapeHTML, and in the WHY e-mails the practitioner's name in alt= too;
     the buyer's greeting splits on a space (the first name).
  4  The test site's e-mail fences unchanged; the live file never mentions the test site.
"""
import json
import os
import re
import sys

sys.dont_write_bytecode = True  # importing build.py must not leave a __pycache__ in the repository
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from build import ALT_NEW, NAMES, changes, get, walk  # noqa: E402

fails = []
base = json.load(open(sys.argv[1], encoding="utf-8"))
cand = json.load(open(sys.argv[2], encoding="utf-8"))
B, C = walk(base["flow"], {}), walk(cand["flow"], {})
stby = "STBY" in base["name"]
env = "stby" if stby else "live"
arg = lambda k: sys.argv[sys.argv.index(k) + 1] if k in sys.argv else None
if sorted(B) != sorted(C):
    fails.append("module ids differ")

# 1 — undo the changes, field by field.
touched = {}
for mid, field, old, new, times in changes(env):
    key = (mid, "html" if field.startswith("html") else field)
    text = touched.get(key, get(C[mid], field))
    if text.count(new) != times:
        fails.append(f"#{mid} {field}: v51 text not found {times} times: {new[:60]!r}")
    touched[key] = text.replace(new, old)
for (mid, field), text in touched.items():
    if text != get(B[mid], field):
        fails.append(f"#{mid} {field} differs from v50 beyond build.py's changes")


def strip(m, mid):
    m = json.loads(json.dumps(m))
    mp = m.get("mapper", {})
    for (tid, field) in touched:
        if tid == mid:
            if field == "file":
                mp["attachments"][0]["fileName"] = None
            else:
                mp[field] = None
    if stby and mid in (3, 48):
        mp["document_template_id"] = None
    return json.dumps({a: b for a, b in m.items() if a not in ("routes", "onerror")}, sort_keys=True)


for k in C:
    if k in B and strip(C[k], k) != strip(B[k], k):
        fails.append(f"#{k} changed beyond its named fields")
if {a: b for a, b in cand.items() if a not in ("flow", "name")} != {a: b for a, b in base.items() if a not in ("flow", "name")}:
    fails.append("top-level keys changed")

# 2 — fallbacks and the reviewed line.
mails = [63, 81, 84, 17, 91]
for mid in mails:
    blob = json.dumps(C[mid]["mapper"], ensure_ascii=False)
    for m in re.finditer(r"1\.report_name", blob):
        if not blob[max(0, m.start() - 8):m.start()].endswith("ifempty("):
            fails.append(f"#{mid} prints report_name without a fallback")
            break
h17 = C[17]["mapper"]["html"]
if h17.count("1.reviewed_line") != 2 or 'if(length(ifempty(1.reviewed_line; "")) > 0;' not in h17 or "escapeHTML(1.reviewed_line)" not in h17:
    fails.append("#17 the reviewed line is not printed once, escaped, only when sent")
at = [h17.find(s) for s in ("Your {{escapeHTML(ifempty(1.report_name;", "escapeHTML(1.reviewed_line)", "your report is ready")]
if -1 in at or at != sorted(at):
    fails.append("#17 the reviewed line is not under the header")
for mid in (63, 81, 84, 91):
    if "reviewed_line" in json.dumps(C[mid]):
        fails.append(f"#{mid} prints the reviewed line (only the consultation e-mail does)")

# 3 — escaping.
for mid in mails:
    h = C[mid]["mapper"]["html"]
    if "{{1.client_name}}" in h or re.search(r"[^(]first\(split\(1\.client_name", h):
        fails.append(f"#{mid} prints a client name without escapeHTML")
    if re.search(r"\{\{ifempty\(1\.report_name", h):
        fails.append(f"#{mid} prints report_name in HTML without escapeHTML")
for mid in (81, 84):
    if ALT_NEW not in C[mid]["mapper"]["html"]:
        fails.append(f"#{mid} the practitioner's name in alt= is not escaped (no quote pass on this route)")
if "escapeHTML(first(split(1.client_name; space)))" not in C[63]["mapper"]["html"]:
    fails.append("#63 the greeting does not use the first name")

# 4 — template ids, fences, names.
for mid, k in ((48, "--proof-paid"), (3, "--proof-consultation")):
    tid = C[mid]["mapper"].get("document_template_id")
    if stby and (not arg(k) or tid != arg(k) or tid == B[mid]["mapper"]["document_template_id"]):
        fails.append(f"#{mid} prints from {tid}, want the proof given with {k}")
    if not stby and tid != B[mid]["mapper"]["document_template_id"]:
        fails.append(f"#{mid} template changed in the live file")
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
