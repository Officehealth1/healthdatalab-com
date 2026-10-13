#!/usr/bin/env python3
"""Make blueprint v33-STBY: v32 + the Full Report offer on the Stage 1 PDF.

Usage: python3 build.py <v32.blueprint.json> <v33.blueprint.json>

Changes (plan prompts/2026-10-13_full_report_funnel_stby_build_v1.md, Task 7.2):
  - module 42 (Stage 1 PDF) sends the nine offer_* keys, each with client_name's
    escaping chain; module 1 declares them (designer only; Make passes every field)
  - client emails say "Full Report", never "Stage 2" (H1): 41 (Stage 1 result,
    body line "Next: Stage 2"; subject says Stage 1 and stays) and 84 (old-flow
    WHY thank-you, subject + banner). Practitioner copies (40, 81) keep their words;
    the choices branch (259) and draft/final emails (63, 17, 91) carry none.
Everything else is left exactly as in v32 (check.py asserts it).
"""
import copy
import json
import sys

NAME = "STBY — Longevity v2 — v33 (email-fenced, choices branch, Full Report)"
OFFER = ("offer_show", "offer_title", "offer_hook", "offer_today", "offer_list", "offer_code",
         "offer_url", "offer_caption", "offer_video_url")
# (module id, mapper field, v32 text, v33 text) — each v32 text occurs exactly once.
WORDING = (
    (41, "html", "Next: Stage 2", "Next: the Full Report"),
    (84, "subject", "Answers Stage 2: Your Why", "Full Report: Your Why"),
    (84, "html", "Stage 2 · Your WHY", "Full Report · Your WHY"),
)


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
    return out


def escaped(field, payload):
    """client_name's quote-safe replace() chain, pointed at 1.<field>."""
    line = next(ln for ln in payload.splitlines() if ln.strip().startswith('"client_name":'))
    expr = line.split(":", 1)[1].strip().rstrip(",")
    assert expr.count("1.client_name") == 1
    return expr.replace("1.client_name", "1." + field)


def add_offer(payload):
    body = payload.rstrip()
    assert body.endswith("}")
    lines = "".join(f',\n  "{k}": {escaped(k, payload)}' for k in OFFER)
    return body[:-1].rstrip() + lines + "\n}"


def build(v32):
    bp = copy.deepcopy(v32)
    bp["name"] = NAME
    M = walk(bp["flow"], {})
    M[1]["metadata"]["interface"] += [{"name": k, "type": "text"} for k in OFFER]
    M[42]["mapper"]["payload"] = add_offer(M[42]["mapper"]["payload"])
    for mid, field, old, new in WORDING:
        assert M[mid]["mapper"][field].count(old) == 1, (mid, field, old)
        M[mid]["mapper"][field] = M[mid]["mapper"][field].replace(old, new)
    return bp


if __name__ == "__main__":
    src = json.load(open(sys.argv[1], encoding="utf-8"))
    with open(sys.argv[2], "w", encoding="utf-8") as f:
        json.dump(build(src), f, ensure_ascii=False, indent=4)
    print("wrote", sys.argv[2])
