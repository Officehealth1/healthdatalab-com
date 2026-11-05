#!/usr/bin/env python3
"""Make blueprint v35: the practitioner gets a one-page Stage 1 PDF, without the code.

Usage: python3 build.py <source.blueprint.json> <v35.blueprint.json> --env live|stby
  live: source = live v34 (hook 4026177); stby: source = STBY v33 (hook 4264185, fenced).

Change (plan prompts/2026-10-16_email_prefill_and_practitioner_pdf_v1.md, Task 4), in the
route "Stage 1 Results PDF" only: after module 34, three new modules render a second
document with offer_show "0" (page 2 and the code hidden):
  265 = copy of 42 (PDFMonkey Generate), payload offer_show the literal "0"
  266 = copy of 33 (GetDocument) on {{265.id}}
  267 = copy of 34 (GetFile) on {{266.download_url}}
Module 40 (practitioner email) attaches {{267.data}}; module 258 (callback) sends
{{266.download_url}}, so the dashboard's PDF is the one-page copy too (D2). Module 41
(client email) keeps the two-page {{34.data}}. Designer x of 37 and its branches +900.
Everything else is left exactly as in the source (check.py asserts it).
"""
import copy
import json
import sys

NAMES = {
    "live": "Longevity v2 — v35 (practitioner one-page Stage 1)",
    "stby": "STBY — Longevity v2 — v35 (email-fenced, practitioner one-page Stage 1)",
}
SHIFT = 900


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
    return out


def stage1_route(bp):
    for m in bp["flow"]:
        for r in m.get("routes", []):
            if [x["id"] for x in r["flow"]][:4] == [29, 42, 33, 34]:
                return r["flow"]
    raise SystemExit("route 'Stage 1 Results PDF' not found")


def one_page_payload(payload):
    lines = payload.splitlines()
    hits = [i for i, ln in enumerate(lines) if ln.strip().startswith('"offer_show":')]
    assert len(hits) == 1, hits
    lines[hits[0]] = '  "offer_show": "0"' + ("," if lines[hits[0]].rstrip().endswith(",") else "")
    return "\n".join(lines) + ("\n" if payload.endswith("\n") else "")


def shift(m):
    m["metadata"]["designer"]["x"] += SHIFT
    for r in m.get("routes", []):
        for x in r["flow"]:
            shift(x)


def build(src, env):
    bp = copy.deepcopy(src)
    bp["name"] = NAMES[env]
    flow = stage1_route(bp)
    M = {m["id"]: m for m in flow}
    g, d, f = copy.deepcopy(M[42]), copy.deepcopy(M[33]), copy.deepcopy(M[34])
    g["id"], d["id"], f["id"] = 265, 266, 267
    g["mapper"]["payload"] = one_page_payload(g["mapper"]["payload"])
    assert d["mapper"]["document_id"] == "{{42.id}}"
    d["mapper"]["document_id"] = "{{265.id}}"
    assert f["mapper"]["url"] == "{{33.download_url}}"
    f["mapper"]["url"] = "{{266.download_url}}"
    for i, m in enumerate((g, d, f)):
        m["metadata"]["designer"] = {"x": M[37]["metadata"]["designer"]["x"] + 300 * i, "y": M[34]["metadata"]["designer"]["y"]}
    shift(M[37])
    at = flow.index(M[37])
    flow[at:at] = [g, d, f]
    B = walk([M[37]], {})
    att = B[40]["mapper"]["attachments"]
    assert len(att) == 1 and att[0]["data"] == "{{34.data}}"
    att[0]["data"] = "{{267.data}}"
    cb = B[258]["mapper"]["data"]
    assert cb.count('"pdf_url": "{{33.download_url}}"') == 1
    B[258]["mapper"]["data"] = cb.replace('"pdf_url": "{{33.download_url}}"', '"pdf_url": "{{266.download_url}}"')
    return bp


if __name__ == "__main__":
    env = sys.argv[sys.argv.index("--env") + 1]
    src = json.load(open(sys.argv[1], encoding="utf-8"))
    with open(sys.argv[2], "w", encoding="utf-8") as fh:
        json.dump(build(src, env), fh, ensure_ascii=False, indent=4)
    print("wrote", sys.argv[2])
