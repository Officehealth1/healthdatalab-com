#!/usr/bin/env python3
"""Make blueprint v32-STBY: v31 + the Stage 1 sales page + the abilities chart picture.

Usage: python3 build.py <v31.blueprint.json> <v32.blueprint.json> <stage1-duplicate-template-id>

Changes (plan memory/prompts/2026-10-09_stage2_abilities_six_and_sales_page_v1.md, C2):
  - module 42 (Stage 1 PDF) points at the Stage 1 DUPLICATE with the sales page
  - module 1 declares why_chart_url (designer only; Make passes every field)
  - modules 48 (draft), 3 (final) and 259 (Stage 2, no AI) send why_chart_url
Everything else is left exactly as in v31 (check.py asserts it).
"""
import copy
import json
import re
import sys

NAME = "STBY — Longevity v2 — v32 (email-fenced, choices branch, sales page)"
STAGE1_LABEL = "DUP — Stage 1 — sales page — 2026-10-09"
FIELD = "why_chart_url"


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
    return out


def add_field(payload):
    """Send why_chart_url the way the module already sends why_picks (same quote-safe wrapper)."""
    expr = re.search(r'"why_picks":\s*"(\{\{.*?\}\})"', payload).group(1)
    body = payload.rstrip()
    assert body.endswith("}")
    return body[:-1].rstrip() + f',\n  "{FIELD}": "{expr.replace("1.why_picks", "1." + FIELD)}"' + "\n}"


def build(v31, stage1_dup):
    bp = copy.deepcopy(v31)
    bp["name"] = NAME
    M = walk(bp["flow"], {})
    M[1]["metadata"]["interface"].append({"name": FIELD, "type": "text"})
    M[42]["mapper"]["document_template_id"] = stage1_dup
    M[42]["metadata"]["restore"]["expect"]["document_template_id"]["label"] = STAGE1_LABEL
    for mid in (48, 3, 259):
        M[mid]["mapper"]["payload"] = add_field(M[mid]["mapper"]["payload"])
    return bp


if __name__ == "__main__":
    src = json.load(open(sys.argv[1], encoding="utf-8"))
    with open(sys.argv[2], "w", encoding="utf-8") as f:
        json.dump(build(src, sys.argv[3]), f, ensure_ascii=False, indent=4)
    print("wrote", sys.argv[2])
