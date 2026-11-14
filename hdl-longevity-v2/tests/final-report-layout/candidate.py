#!/usr/bin/env python3
"""Final report layout (plan 2026-11-14): the candidate Final template, built from
the 2026-11-14 backup of the production template (a091f83e) by exact text edits.

    python3 -I candidate.py <backup.html> <candidate.html>

Every edit names the defect it fixes and how many times its text must occur;
a count that does not match stops the build, so a production template that
changed since the backup is never patched blindly. The backup and the
candidate live in docs/pdfmonkey/ (git-ignored), like tests/why-reasoning.
"""
import hashlib
import sys

EDITS = []


def edit(defect, old, new, count=1):
    EDITS.append((defect, old, new, count))


# --- 2a: page numbers from two values -------------------------------------
# The choices page after page 12 exists only when why_picks is set; every page
# after it, every contents row after Objectives and every "Page 13" move by one.
edit('2a', """{%- comment -%} Liquid newline helper for splitting milestone strings {%- endcomment -%}
""", """{%- comment -%} Liquid newline helper for splitting milestone strings {%- endcomment -%}

{%- comment -%} 2026-11-14 layout: the choices page after page 12 is printed only when
   why_picks is set; pages after it are numbered on by pg_shift. {%- endcomment -%}
{%- if why_picks != blank -%}{%- assign pg_shift = 1 -%}{%- assign pg_total = 20 -%}{%- else -%}{%- assign pg_shift = 0 -%}{%- assign pg_total = 19 -%}{%- endif -%}
""")
for n in range(13, 20):
    edit('2a', '>%d / 19</span>' % n, '>{{ %d | plus: pg_shift }} / {{ pg_total }}</span>' % n, 2)
for n in range(2, 13):
    edit('2a', '>%02d / 19</span>' % n, '>%02d / {{ pg_total }}</span>' % n, 2)
for n in range(13, 19):
    edit('2a', '<span class="toc-page">%d</span>' % n, '<span class="toc-page">{{ %d | plus: pg_shift }}</span>' % n)
edit('2a', 'Page 13', 'Page {{ 13 | plus: pg_shift }}', 9)


def build(body):
    for defect, old, new, count in EDITS:
        found = body.count(old)
        if found != count:
            sys.exit('%s: expected %d of %r, found %d' % (defect, count, old[:70], found))
        body = body.replace(old, new)
    return body


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    src = open(sys.argv[1], encoding='utf-8', newline='').read()
    out = build(src)
    open(sys.argv[2], 'w', encoding='utf-8', newline='').write(out)
    print('%d edits, %s -> %s' % (len(EDITS), hashlib.sha256(src.encode()).hexdigest()[:12],
                                  hashlib.sha256(out.encode()).hexdigest()[:12]))
