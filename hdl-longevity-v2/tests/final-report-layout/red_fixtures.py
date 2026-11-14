#!/usr/bin/env python3
"""Red fixtures for check_layout.py (fix plan 2026-11-14): made-up pages, one fault each.

    python3 -I red_fixtures.py                  run the check on every fixture
    python3 -I red_fixtures.py --build <dir>    write the pages into <dir> and print them
                                                with headless Chrome (then copy to fixtures/red/)

Every line must read "fails as it should": the check exits with the code named here
and its output names the expected check. "base" carries no fault and must pass, so a
fixture's failure comes from its one fault and nothing else. The last line checks
payload-worst.json: every text field the check knows ends in a marker word of its
own, so no other field's text can stand in for it. No real person is in any page; build from
a neutral folder (/tmp/frl-fixtures) so the PDFs carry no home path.
"""
import importlib.util
import json
import os
import re
import subprocess
import sys

sys.dont_write_bytecode = True

HERE = os.path.dirname(os.path.abspath(__file__))
RED = os.path.join(HERE, 'fixtures', 'red')
CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'

# name: (pdf, payload or None, --known pairs, expected exit code, text the output must hold)
CASES = {
    'base':                      ('base', 'base', [], 0, '0 failure(s)'),
    'contents-wrong-number':     ('contents-wrong-number', 'base', [], 1, 'FAIL page 2: check 6'),
    'contents-missing-row':      ('contents-missing-row', 'base', [], 1, 'FAIL page 2: check 6'),
    'overlap-paragraphs':        ('overlap-paragraphs', 'base', [], 1, 'FAIL page 3: check 4'),
    'overlap-label':             ('overlap-label', 'base', [], 1, 'FAIL page 3: check 4'),
    'clean-cut-no-payload':      ('clean-cut', None, [], 3, 'check 5 not run'),
    'one-page':                  ('one-page', None, [], 1, 'FAIL page 1: check 1'),
    'inputs-retitled':           ('inputs-retitled', 'base', [], 1, 'check 7'),
    'recommendations-retitled':  ('recommendations-retitled', 'base', [], 1, 'check 6'),
    'known-matches-nothing':     ('base', 'base', ['4:3'], 1, 'STALE'),
    'known-covers-one-only':     ('two-strays', 'base', ['3:3'], 1, 'FAIL page 3: check 3'),
    'field-not-printed':         ('base', 'field-not-printed', [], 1, 'check 5: the end of practitioner_role'),
}

CSS = """<style>
@page { size: A4 portrait; margin: 0; }
html, body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10pt; }
.page { position: relative; width: 210mm; height: 297mm; overflow: hidden; page-break-after: always; }
.page__header { position: absolute; top: 8mm; left: 18mm; right: 18mm; font-size: 8pt; text-align: right; }
.page__footer { position: absolute; bottom: 8mm; left: 18mm; right: 18mm; font-size: 8pt; text-align: right; }
.page__content { position: absolute; inset: 22mm 18mm 25mm 18mm; overflow: hidden; }
h2 { margin: 0 0 4mm; font-size: 14pt; }
p { margin: 0 0 3mm; line-height: 5mm; }
.toc-row { display: flex; align-items: baseline; height: 9mm; }
.toc-num { width: 14mm; }
.toc-title { flex: 1; }
.line { height: 10mm; line-height: 10mm; }
</style>"""

ROWS = [('01', 'Intro · The two trajectories', 3), ('02', 'Results', 5),
        ('', 'Chronological vs. biological age', 5), ('', 'Ageing rate & body composition', 6),
        ('', 'Detailed health profile', 7), ('', 'Strengths & areas to lift', 8),
        ('03', 'Health trajectory & future scenarios', 9), ('04', 'Analysis', 10),
        ('05', 'Objectives · The five anchors', 12), ('06', 'Actionable recommendations', 13),
        ('', 'Milestones & Flight Plan', 14), ('07', 'Summary & conclusion', 15),
        ('08', 'Your assessment inputs', 16), ('—', 'Scoring rubric & methodology', 17),
        ('—', 'Disclaimer & terms', 18)]
TITLES = {3: 'Intro', 4: 'The two trajectories', 5: 'Results: chronological vs. biological age',
          6: 'Ageing rate & body composition', 7: 'Detailed health profile', 8: 'Strengths & areas to lift',
          9: 'Health trajectory & future scenarios', 10: 'Analysis', 11: 'Analysis, continued',
          12: 'Objectives', 13: 'Actionable recommendations', 14: 'Milestones & Flight Plan',
          15: 'Summary & conclusion', 16: 'Your assessment inputs', 17: 'Scoring rubric & methodology',
          18: 'Disclaimer & terms', 19: 'Notes'}
WHY = 'Made-up closing words for the layout check that end in zqbase.'
PAYLOADS = {
    'base': {'why_picks': '', 'distilled_why': WHY},
    'field-not-printed': {'why_picks': '', 'distilled_why': WHY, 'practitioner_role': 'Made-up role of the practitioner zqrole'},
}


def page(n, total, body, stray=''):
    return ('<section class="page"><div class="page__header">%02d / %02d</div><div class="page__content">%s</div>%s'
            '<div class="page__footer">%02d / %02d</div></section>') % (n, total, body, stray, n, total)


def document(name, rows=ROWS, titles=TITLES, extra3='', stray3='', page_ref=True):
    """The 19 made-up pages; extra3 and stray3 put a fault on page 3."""
    out = ['<!doctype html><meta charset="utf-8"><title>red fixture %s</title>' % name, CSS,
           '<section class="page"><div class="page__content"><h2>Cover</h2></div></section>']
    toc = ''.join('<div class="toc-row"><span class="toc-num">%s</span><span class="toc-title">%s</span><span>%02d</span></div>'
                  % (num, title.replace('&', '&amp;'), cited) for num, title, cited in rows)
    out.append(page(2, 19, '<h2>Contents</h2>' + toc))
    for n in range(3, 20):
        body = '<h2>%s</h2><p>Made-up text for the layout check, page %d.</p>' % (titles[n].replace('&', '&amp;'), n)
        if n == 3:
            body += extra3
        if n == 12:
            body += '<p>%s</p>' % WHY + ('<p>The plan is on Page 13.</p>' if page_ref else '')
        if n == 16:
            body += '<p>Blood pressure %s</p>' % ('4/5 GOOD' if titles[n] == TITLES[16] else '/5 URGENT')
        out.append(page(n, 19, body, stray3 if n == 3 else ''))
    return ''.join(out)


def retitled(n, new):
    old = TITLES[n]
    return [(num, new if title == old else title, cited) for num, title, cited in ROWS], {**TITLES, n: new}


def pages():
    """name -> HTML of every fixture PDF."""
    wrong = [(num, title, 16 if title == 'Summary & conclusion' else cited) for num, title, cited in ROWS]
    in_rows, in_titles = retitled(16, 'Your inputs at assessment')
    rec_rows, rec_titles = retitled(13, 'Recommended actions')
    stray = '<div style="position:absolute;bottom:18mm;left:%dmm">%s</div>'
    return {
        'base': document('base'),
        'contents-wrong-number': document('contents-wrong-number', rows=wrong),
        'contents-missing-row': document('contents-missing-row', rows=ROWS[:-1]),
        # a paragraph pulled up over the one before it
        'overlap-paragraphs': document('overlap-paragraphs', extra3=(
            '<p style="margin:0">first paragraph words printed here for the overlap test today</p>'
            '<p style="margin:-4mm 0 0">second paragraph words printed right on top of the first one</p>')),
        # a label placed over a line of running text (a legend over an axis title)
        'overlap-label': document('overlap-label', extra3=(
            '<div style="position:relative"><p style="margin:0">Age (years) running text under the label here</p>'
            '<div style="position:absolute;top:0.6mm;left:2mm;line-height:5mm">With Changes legend</div></div>')),
        # 30 lines of 10 mm in a box that holds 25: lines 26 to 30 are cut clean at a line boundary
        'clean-cut': document('clean-cut', extra3=''.join(
            '<div class="line">line %02d alpha beta gamma delta epsilon mark%02d</div>' % (i, i) for i in range(1, 31))),
        'one-page': '<!doctype html><meta charset="utf-8"><title>red fixture one-page</title>' + CSS
                    + '<section class="page"><div class="page__content"><h2>Cover</h2></div></section>',
        'inputs-retitled': document('inputs-retitled', rows=in_rows, titles=in_titles),
        'recommendations-retitled': document('recommendations-retitled', rows=rec_rows, titles=rec_titles, page_ref=False),
        # two words between the content box and the footer
        'two-strays': document('two-strays', stray3=stray % (30, 'strayone') + stray % (100, 'straytwo')),
    }


def build(out):
    os.makedirs(out, exist_ok=True)
    for name, html in pages().items():
        src = os.path.join(out, name + '.html')
        open(src, 'w', encoding='utf-8').write(html)
        subprocess.run([CHROME, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions',
                        '--disable-background-networking', '--user-data-dir=' + os.path.join(out, '.profile'),
                        '--no-pdf-header-footer', '--disable-pdf-tagging', '--print-to-pdf=' + os.path.join(out, name + '.pdf'), 'file://' + src],
                       check=True, capture_output=True)
        print('built', name)
    for name, payload in PAYLOADS.items():
        json.dump(payload, open(os.path.join(out, 'payload-%s.json' % name), 'w', encoding='utf-8'), indent=1)


def without_marker(check):
    """Fields of the check's RENDERED list whose last words in payload-worst.json hold no
    marker of their own: a made-up "zq" word no other field has ("QZ" for the two initials)."""
    spec = importlib.util.spec_from_file_location('check_layout', check)
    cl = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(cl)
    payload = json.load(open(os.path.join(HERE, 'payload-worst.json'), encoding='utf-8'))

    def words(value):
        return {cl.norm(w) for w in re.sub(r'<[^>]+>', ' ', str(value)).split()} - {''}
    bare = []
    for key, how in cl.RENDERED.items():
        tails = cl.rendered_tail(payload.get(key, ''), how)
        others = set().union(*(words(v) for k, v in payload.items() if k != key))
        if not tails or not [w for w in words(tails[-1]) - others if 'zq' in w or w == 'qz']:
            bare.append(key)
    return bare


def run(check):
    bad = 0
    for name, (pdf, payload, known, want_exit, want_text) in CASES.items():
        cmd = [sys.executable, '-I', check, os.path.join(RED, pdf + '.pdf')]
        if payload:
            cmd.append(os.path.join(RED, 'payload-%s.json' % payload))
        for pair in known:
            cmd += ['--known', pair]
        got = subprocess.run(cmd, capture_output=True, text=True)
        ok = got.returncode == want_exit and want_text in got.stdout
        bad += not ok
        verdict = ('passes as it should' if want_exit == 0 else 'fails as it should') if ok else 'WRONG'
        print('%-26s exit %d (expected %d, "%s"): %s' % (name, got.returncode, want_exit, want_text, verdict))
    bare = without_marker(check)
    print('%-26s %s' % ('payload-worst markers', 'every field has its own' if not bare
                        else 'WRONG, no marker of its own: ' + ', '.join(bare)))
    return 1 if bad or bare else 0


if __name__ == '__main__':
    if sys.argv[1:2] == ['--build'] and len(sys.argv) == 3:
        build(sys.argv[2])
    else:
        sys.exit(run(sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, 'check_layout.py')))
