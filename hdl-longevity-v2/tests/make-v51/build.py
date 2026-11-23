#!/usr/bin/env python3
"""Make blueprint v51: the report names from the plugin, the reviewed line, the buyer greeted by first name (plan 12).

Usage: python3 build.py <v50.blueprint.json> <v51.blueprint.json> --env live|stby
                        [--proof-paid <template id> --proof-consultation <template id>]   (stby: both required)

Each e-mail that names a report prints the plugin's report_name and falls back to v50's exact words when
the field is absent, so v51 reads as v50 on a scenario whose plugin does not send it (hdlv2_ff_report_names
off, or a plugin before 0.48.96):
  #63 the buyer's report e-mail (draft route): subject, attachment name, preheader, eyebrow, header, the
      attached line and the upgrade card's eyebrow. The greeting uses the first name (split on a space;
      v50's bare split did not split), escaped.
  #81 / #84 the WHY e-mails (stage 2 route): subject, attachment name, eyebrow, the lines naming the form.
      #81 only in the live file: the test site's #81 still has the older "Stage 2 WHY Profile" words (a
      difference between the two scenarios older than this plan), left as they are.
  #17 the consultation report e-mail to the client (final route): subject, attachment name, preheader,
      eyebrow, header, the finalised line; under the header, the plugin's reviewed_line when it is sent.
  #91 the practitioner's copy (final route): subject, attachment name, header, the finalised line.
Every client name printed in these e-mails' HTML and the reviewed line are escaped (escapeHTML). The
practitioner's name is left as v50 prints it: WordPress stores a display name HTML-encoded already, so
escaping it again would show "&amp;" for "&". The test site's file prints the buyer's PDF and the
consultation PDF from the proof copies whose ids are passed on the command line (kept out of this
public repository). Nothing else changes (check.py asserts it).
"""
import json
import sys

NAMES = {
    "live": "Longevity v2 — v51 (report names, reviewed line)",
    "stby": "STBY — Longevity v2 — v51 (email-fenced, report names, reviewed line, proofs)",
}


def name(fallback):        # plain text: subjects and file names
    return '{{ifempty(1.report_name; "' + fallback + '")}}'


def name_html(fallback):   # in the HTML body
    return '{{escapeHTML(ifempty(1.report_name; "' + fallback + '"))}}'


def name_in_literal(fallback):  # inside a Make string literal of the HTML (the card is one if() argument)
    return '" + escapeHTML(ifempty(1.report_name; "' + fallback + '")) + "'


FONT = "font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;"
REVIEWED = ('\n        {{if(length(ifempty(1.reviewed_line; "")) > 0; "<p style=""margin:8px 0 0;text-align:center;'
            'font-size:13px;line-height:1.5;color:rgba(255,255,255,0.88);' + FONT.replace('"', '""') + '"">" + '
            'escapeHTML(1.reviewed_line) + "</p>"; "")}}')
GREET_OLD = '", " + first(split(1.client_name; )))}}.</h2>'
GREET_NEW = '", " + escapeHTML(first(split(1.client_name; space))))}}.</h2>'
HI_OLD = '"there"; first(split(1.client_name; )))}}'
HI_NEW = '"there"; escapeHTML(first(split(1.client_name; ))))}}'

# (module, field, v50 text, v51 text, times). field: subject | file | html | html-all (every occurrence).
CHANGES = [
    (63, "subject", "Your Full Longevity Report — ", "Your " + name("Full Longevity Report") + " — ", 1),
    (63, "file", " — Full longevity report — ", " — " + name("Full longevity report") + " — ", 1),
    (63, "html", ">Your Full Report is attached.</div>", ">Your " + name_html("Full Report") + " is attached.</div>", 1),
    (63, "html", ">Full Report</p>", ">" + name_html("Full Report") + "</p>", 1),
    (63, "html", ">Your Full Report</p>", ">Your " + name_html("Full Report") + "</p>", 1),
    (63, "html", GREET_OLD, GREET_NEW, 1),
    (63, "html", ">Your Full Report is attached as a PDF.</p>", ">Your " + name_html("Full Report") + " is attached as a PDF.</p>", 1),
    (63, "html", "An upgrade for your Full Report</p>", "An upgrade for your " + name_in_literal("Full Report") + "</p>", 1),
    (81, "subject", "Full Report · WHY profile — ", name("Full Report") + " · WHY profile — ", 1),
    (81, "file", " — Full Report WHY — ", " — " + name("Full Report") + " WHY — ", 1),
    (81, "html-all", "{{1.client_name}}", "{{escapeHTML(1.client_name)}}", 3),
    (81, "html", "then open their Full Report form.</div>", "then open their " + name_html("Full Report") + " form.</div>", 1),
    (81, "html", ">Full Report · WHY profile</p>", ">" + name_html("Full Report") + " · WHY profile</p>", 1),
    (81, "html", "has shared their Full Report WHY.", "has shared their " + name_html("Full Report") + " WHY.", 1),
    (81, "html", "to open their Full Report form.</p>", "to open their " + name_html("Full Report") + " form.</p>", 1),
    (84, "subject", "Full Report: Your Why — ", name("Full Report") + ": Your Why — ", 1),
    (84, "html", ">Full Report · Your WHY</p>", ">" + name_html("Full Report") + " · Your WHY</p>", 1),
    (84, "html", HI_OLD, HI_NEW, 1),
    (17, "subject", "Your Longevity Report — ", "Your " + name("Longevity Report") + " — ", 1),
    (17, "file", " — Final longevity report — ", " — " + name("Final longevity report") + " — ", 1),
    (17, "html", ">Your finalised longevity report is attached.", ">Your " + name_html("finalised longevity report") + " is attached.", 1),
    (17, "html", ">Final report</p>", ">" + name_html("Final report") + "</p>", 1),
    (17, "html", ">Your Longevity Report</p>", ">Your " + name_html("Longevity Report") + "</p>" + REVIEWED, 1),
    (17, "html", HI_OLD, HI_NEW, 1),
    (17, "html", ">Your personalised longevity report has been finalised", ">Your " + name_html("personalised longevity report") + " has been finalised", 1),
    (91, "subject", "Final Report delivered — ", name("Final Report") + " delivered — ", 1),
    (91, "file", " — Final longevity report — ", " — " + name("Final longevity report") + " — ", 1),
    (91, "html-all", "{{1.client_name}}", "{{escapeHTML(1.client_name)}}", 3),
    (91, "html", ">Final Report Delivered</p>", ">" + name_html("Final Report") + " Delivered</p>", 1),
    (91, "html", ">The finalised longevity report for <strong>", ">The " + name_html("finalised longevity report") + " for <strong>", 1),
]


def walk(flow, out):
    for m in flow:
        out[m["id"]] = m
        for r in m.get("routes", []):
            walk(r["flow"], out)
        walk(m.get("onerror", []), out)
    return out


def get(m, field):
    mp = m["mapper"]
    return mp["attachments"][0]["fileName"] if field == "file" else mp["html" if field.startswith("html") else field]


def put(m, field, value):
    mp = m["mapper"]
    if field == "file":
        mp["attachments"][0]["fileName"] = value
    else:
        mp["html" if field.startswith("html") else field] = value


LIVE_ONLY = {81}


def apply(mods, env):
    for mid, field, old, new, times in CHANGES:
        if env == "stby" and mid in LIVE_ONLY:
            continue
        text = get(mods[mid], field)
        assert text.count(old) == times, f"#{mid} {field}: {old[:50]!r} found {text.count(old)} times, want {times}"
        put(mods[mid], field, text.replace(old, new))


if __name__ == "__main__":
    src, dst, env = sys.argv[1], sys.argv[2], sys.argv[sys.argv.index("--env") + 1]
    bp = json.load(open(src, encoding="utf-8"))
    mods = walk(bp["flow"], {})
    apply(mods, env)
    if env == "stby":
        mods[48]["mapper"]["document_template_id"] = sys.argv[sys.argv.index("--proof-paid") + 1]
        mods[3]["mapper"]["document_template_id"] = sys.argv[sys.argv.index("--proof-consultation") + 1]
    bp["name"] = NAMES[env]
    json.dump(bp, open(dst, "w", encoding="utf-8"), ensure_ascii=False, indent=4)
    print("wrote", dst)
