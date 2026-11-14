#!/usr/bin/env python3
"""Layout check for the Final report PDF (plan 2026-11-14).

    python3 -I check_layout.py <pdf> [payload.json] [--known "<page>:<check>"]... [--no-payload-ok]

Prints "page N: check C: ..." for every failure. Exit codes: 0 green; 1 a failure
or a stale --known; 2 usage; 3 nothing failed but no payload was given, so check 5
did not run (pass --no-payload-ok when that is meant, as for a PDF whose payload is
not to hand).

  1  page count: 20 when the payload has choices (why_picks), 19 when not;
     with no payload, 19 or 20
  2  the header and the footer of page n (n >= 2) read "n / total"
  3  every word outside the header and footer lies inside the content box (1 pt)
  4  no two words on a page overlap by more than 1.5 pt in both directions
  5  the last six words of every rendered payload text field are in the PDF
  6  the contents page has at least 15 rows and each cites the page holding its
     title; every "Page N" in the text cites the page titled "Actionable
     recommendations", which must exist
  7  the page "Your assessment inputs" exists and on it "/5" always has a digit
     directly before it

--known "<page>:<check>" covers ONE failure of that check on that page and prints it
as KNOWN on every run; a second failure there still fails (pass the pair again to
cover it). A pair that matches no failure prints STALE and fails: take it out.

Check 5 reports page 0 when not even the field's opening words are in the PDF; a
page that is not found is reported as page 0 too. Needs pdftotext (poppler).
"""
import collections
import html
import json
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

MM = 72 / 25.4
# .page__content inset from the template, in mm: top, right, bottom, left.
BOX_PORTRAIT = (22, 18, 25, 18)
BOX_LANDSCAPE = (18, 22, 28, 22)
EDGE_PT = 1.0
OVERLAP_PT = 1.5
# pdftotext reports a font's box, not its letters. Chrome embeds the web fonts as
# Type 3, whose boxes are padded: measured 2026-11-14 at 720 dpi on a Chromium 133
# PDF, the letters start 10% of the box height below its top (ascenders) and end
# 15% above its bottom (descenders). Checks 3 and 4 measure those letter boxes.
# Check 4 keeps the raw top: the chart's own text (Open Sans, not Type 3) has
# brackets 1.4 pt above its reported box, and its legend clash is real.
TOP_PAD, BOTTOM_PAD = 0.10, 0.15
BAND_MM = 15
MIN_PAGES, MIN_ROWS = 19, 15
# Words of one contents row share a baseline; rows are 27 pt apart or more.
ROW_PT = 4.0
NS = '{http://www.w3.org/1999/xhtml}'

# Every payload text field the template prints, with how it prints it. Numbers,
# scores and picture addresses are not text fields. red_fixtures.py checks that
# each field here carries its own marker word in payload-worst.json.
#   first_p: only the first paragraph is printed (page 3 awaken).
#   lines:   one list item per line; every line is checked (milestones).
#   picks:   " | " printed as "; " (the choices).
#   chips:   " · " separates chips; every chip is checked (metabolic panel).
#   twice:   printed on the cover and again in the sign-off line that closes the
#            Summary page; one of the two is not enough (gate row R6).
RENDERED = {
    'client_display_name': '', 'report_date': 'twice', 'report_number': '',
    'intro_pull_quote': '', 'awaken_content': 'first_p', 'client_key_findings': '',
    'family_history': '', 'medications': '', 'existing_conditions': '',
    'results_narrative': '', 'metabolic_driver': '', 'metabolic_chips': 'chips',
    'thrive_content': '', 'lift_content': '', 'ai_body_composition_analysis': '',
    'ai_longevity_influences_explanation': '',
    'distilled_why': '', 'ai_objectives_how': '', 'ai_objectives_who': '',
    'ai_objectives_where': '', 'ai_objectives_when': '',
    'why_picks_people': 'picks', 'why_picks_freedom': 'picks', 'why_picks_things': 'picks',
    'why_abilities': 'picks', 'why_focus_areas': '', 'why_people': '',
    'rec_1_text': '', 'rec_2_text': '', 'rec_3_text': '', 'rec_4_text': '', 'rec_5_text': '',
    'rec_1_lifts': '', 'rec_2_lifts': '', 'rec_3_lifts': '', 'rec_4_lifts': '', 'rec_5_lifts': '',
    'rec_1_category': '', 'rec_2_category': '', 'rec_3_category': '', 'rec_4_category': '',
    'rec_5_category': '',
    'rec_1_freq': '', 'rec_2_freq': '', 'rec_3_freq': '', 'rec_4_freq': '', 'rec_5_freq': '',
    'ms_6mo': 'lines', 'ms_2yr': 'lines', 'ms_5yr': 'lines', 'ms_10yr': 'lines',
    'ai_results_summary': '', 'summary_pull_quote': '', 'summary_nb': '',
    'practitioner_name': '', 'practitioner_email': '', 'practitioner_role': '',
    'practitioner_link': '', 'practitioner_initials': '',
    'label_sit_stand': '', 'label_breath': '', 'label_balance': '', 'label_smoking': '',
    'label_alcohol': '', 'label_diet': '', 'label_activity': '', 'label_sleep_dur': '',
    'label_sleep_qual': '', 'label_stress': '', 'label_social': '', 'label_cognitive': '',
    'label_sunlight': '', 'label_supplements': '', 'label_hydration': '',
}

# Deliberate designs that check 3 or 4 would flag, one line of reason each:
# (check, word, reason). Never loosen a number instead.
ALLOW = [
]


def norm(text):
    """Lower case letters and digits only: spacing, punctuation and case set by CSS drop out."""
    return re.sub(r'[^0-9a-z]', '', html.unescape(text).lower())


def read_pages(pdf):
    out = subprocess.run(['pdftotext', '-bbox-layout', pdf, '-'], capture_output=True, check=True).stdout
    root = ET.fromstring(out)
    pages = []
    for page in root.iter(NS + 'page'):
        w, h = float(page.get('width')), float(page.get('height'))
        top, right, bottom, left = (BOX_LANDSCAPE if w > h else BOX_PORTRAIT)
        box = (left * MM, top * MM, w - right * MM, h - bottom * MM)
        words, lines = [], []
        for block_no, block in enumerate(page.iter(NS + 'block')):
            for line in block.iter(NS + 'line'):
                lw = []
                for word in line.iter(NS + 'word'):
                    x0, y0, x1, y1 = (float(word.get(k)) for k in ('xMin', 'yMin', 'xMax', 'yMax'))
                    pad_top, pad_bottom = (y1 - y0) * TOP_PAD, (y1 - y0) * BOTTOM_PAD
                    cy = (y0 + y1) / 2
                    # Header and footer sit within 15 mm of the page edge; a cut line of the
                    # largest type cannot have its centre that far past the content box.
                    band = 'head' if cy < BAND_MM * MM else 'foot' if cy > h - BAND_MM * MM else 'body'
                    lw.append({'t': word.text or '', 'band': band, 'block': block_no,
                               'letters': (x0, y0 + pad_top, x1, y1 - pad_bottom),
                               'overlap': (x0, y0, x1, y1 - pad_bottom)})
                lines.append(lw)
                words.extend(lw)
        pages.append({'w': w, 'h': h, 'box': box, 'words': words, 'lines': lines})
    return pages


def body_text(page):
    return ' '.join(w['t'] for w in page['words'] if w['band'] == 'body')


def band_lines(page, band):
    return [' '.join(w['t'] for w in lw) for lw in page['lines'] if lw and lw[0]['band'] == band]


def title_page(pages, title):
    """1-based number of the first page after the contents whose body holds the title."""
    t = norm(title)
    for i, p in enumerate(pages[2:], start=3):
        if t in norm(body_text(p)):
            return i
    return None


def allowed(check, word):
    return any(c == check and w == word for c, w, _ in ALLOW)


def rendered_tail(value, how):
    """Last six words of what the template prints for one payload value, per line for lists."""
    text = str(value).replace('\\n', '\n')
    if how == 'first_p' and '</p>' in text:
        text = text.split('</p>')[0]
    if how == 'picks':
        text = text.replace(' | ', '; ')
    parts = text.split('\n') if how == 'lines' else text.split(' · ') if how == 'chips' else [text]
    tails = []
    for part in parts:
        words = re.sub(r'<[^>]+>', ' ', part).split()
        if words:
            tails.append(' '.join(words[-6:]))
    return tails


def contents_rows(toc):
    """(title, cited page) of each contents row. pdftotext reads a row's number, its
    title and its page as three separate lines, so a row is put together here: the
    words on one baseline that end in a two-digit number at the right edge of the
    content box."""
    def mid(w):
        return (w['letters'][1] + w['letters'][3]) / 2
    rows, row = [], []
    for w in sorted((w for w in toc['words'] if w['band'] == 'body'), key=mid):
        if row and mid(w) - mid(row[0]) > ROW_PT:
            rows.append(row)
            row = []
        row.append(w)
    out = []
    for row in rows + [row]:
        row.sort(key=lambda w: w['letters'][0])
        if len(row) > 1 and re.fullmatch(r'\d{2}', row[-1]['t']) and abs(row[-1]['letters'][2] - toc['box'][2]) <= 3:
            title = [w['t'] for w in row[:-1]]
            if re.fullmatch(r'\d{2}|—', title[0]):
                title = title[1:]
            out.append((' '.join(title), int(row[-1]['t'])))
    return out


def check(pdf, payload, known, no_payload_ok=False):
    pages = read_pages(pdf)
    total = len(pages)
    fails = []

    def fail(page, num, msg):
        fails.append((page, num, msg))

    # 1 page count
    if payload is not None:
        want = 20 if str(payload.get('why_picks', '')).strip() else 19
        if total != want:
            fail(total, 1, 'the PDF has %d pages, expected %d' % (total, want))
    elif not MIN_PAGES <= total <= MIN_PAGES + 1:
        fail(total, 1, 'the PDF has %d page(s), expected %d or %d' % (total, MIN_PAGES, MIN_PAGES + 1))

    # 2 header and footer numbers
    for n, p in enumerate(pages[1:], start=2):
        for band in ('head', 'foot'):
            found = [m.groups() for l in band_lines(p, band) for m in re.finditer(r'\b(\d{1,2}) / (\d{1,2})\b', l)]
            if (str(n).zfill(2), str(total).zfill(2)) not in found:
                fail(n, 2, '%s reads %s, expected %02d / %02d' % (
                    'header' if band == 'head' else 'footer', ' '.join('/'.join(f) for f in found) or 'nothing', n, total))

    # 3 inside the content box
    for n, p in enumerate(pages[1:], start=2):
        x0, y0, x1, y1 = p['box']
        for w in p['words']:
            if w['band'] != 'body':
                continue
            b = w['letters']
            if b[0] < x0 - EDGE_PT or b[1] < y0 - EDGE_PT or b[2] > x1 + EDGE_PT or b[3] > y1 + EDGE_PT:
                if not allowed(3, w['t']):
                    fail(n, 3, 'word "%s" at x %.1f-%.1f y %.1f-%.1f is outside the content box (x %.1f-%.1f y %.1f-%.1f)'
                         % (w['t'][:24], b[0], b[2], b[1], b[3], x0, x1, y0, y1))

    # 4 overlapping words: every pair on the page. Words of one pdftotext block are
    # lines of one paragraph in the web fonts, whose padded boxes overlap a little at
    # the template's tightest line heights (3.2 pt on a 39 pt title box), so inside a
    # block the letters are measured; across blocks the raw top stays (see TOP_PAD).
    # Ceiling: chart text that pdftotext puts in one block is measured by letter
    # boxes it does not have, so a clash there under about 3.5 pt can pass. Reading
    # each word's font (pdftohtml -xml with pdffonts) would close it.
    for n, p in enumerate(pages[1:], start=2):
        ws = p['words']
        for i, a in enumerate(ws):
            for c in ws[i + 1:]:
                box = 'letters' if a['block'] == c['block'] else 'overlap'
                A, C = a[box], c[box]
                ox = min(A[2], C[2]) - max(A[0], C[0])
                oy = min(A[3], C[3]) - max(A[1], C[1])
                if ox > OVERLAP_PT and oy > OVERLAP_PT and not (allowed(4, a['t']) or allowed(4, c['t'])):
                    fail(n, 4, 'words "%s" and "%s" overlap by %.1f x %.1f pt' % (a['t'][:24], c['t'][:24], ox, oy))

    # 5 every rendered field ends in the PDF. Read in drawing order (pdftotext -raw):
    # Chrome draws text in page order, so side-by-side columns do not interleave.
    if payload is not None:
        raw = [norm(t) for t in subprocess.run(['pdftotext', '-raw', pdf, '-'], capture_output=True, check=True)
               .stdout.decode('utf-8').split('\f')]
        whole = ''.join(raw)
        fields = dict(payload)
        if not fields.get('client_display_name'):  # the template's own fallback for the cover name
            fields['client_display_name'] = str(fields.get('client_name', '')).capitalize()
        for key, how in RENDERED.items():
            value = fields.get(key, '')
            if value in ('', None) or (key == 'metabolic_chips' and not fields.get('metabolic_score')):
                continue  # the chips are printed only inside the metabolic panel, which needs a score
            for tail in rendered_tail(value, how):
                found = whole.count(norm(tail))
                if not found:
                    head = norm(' '.join(re.sub(r'<[^>]+>', ' ', str(value)).split()[:6]))
                    where = next((i for i, t in enumerate(raw, start=1) if head and head in t), 0)
                    fail(where, 5, 'the end of %s ("...%s") is not in the PDF' % (key, tail[-40:]))
                elif how == 'twice' and found < 2:
                    fail(title_page(pages, 'Summary & conclusion') or 0, 5,
                         'the end of %s ("...%s") is in the PDF once, expected on the cover and in the sign-off' % (key, tail[-40:]))

    # 6 contents rows and "Page N" references
    rows = contents_rows(pages[1]) if total > 1 else []
    if len(rows) < MIN_ROWS:
        fail(2, 6, 'the contents page reads %d row(s), expected at least %d' % (len(rows), MIN_ROWS))
    for title, cited in rows:
        name = title.split(' · ')[0]
        words = [x for x in re.findall(r'[A-Za-z]+', name) if len(x) >= 4 or x.lower() == 'age']
        if not words:
            fail(2, 6, 'contents row citing page %d has no title to look for ("%s")' % (cited, title))
        elif not 1 <= cited <= total:
            fail(2, 6, 'contents row "%s" cites page %d of %d' % (title, cited, total))
        else:
            text = norm(body_text(pages[cited - 1]))
            missing = [x for x in words if norm(x) not in text]
            if missing:
                fail(2, 6, 'contents row "%s" cites page %d, which does not hold %s' % (title, cited, ', '.join(missing)))
    recs = title_page(pages, 'Actionable recommendations')
    if recs is None:
        fail(0, 6, 'no page is titled "Actionable recommendations"')
    for n, p in enumerate(pages, start=1):
        for m in re.finditer(r'\bPage (\d{1,2})\b', body_text(p)):
            if recs is None or int(m.group(1)) != recs:
                fail(n, 6, '"Page %s" should cite page %s (Actionable recommendations)' % (m.group(1), recs))

    # 7 a bare "/5" on the inputs page
    inputs = title_page(pages, 'Your assessment inputs')
    if inputs is None:
        fail(0, 7, 'no page is titled "Your assessment inputs"')
    else:
        hits = re.findall(r'(?<![0-9])/5', body_text(pages[inputs - 1]))
        if hits:
            fail(inputs, 7, '"/5" with no score before it, %d time(s)' % len(hits))

    # Each --known pair covers one failure; what is left over matched nothing.
    left = collections.Counter(known)
    open_fails = 0
    for page, num, msg in fails:
        pair = '%d:%d' % (page, num)
        tag = 'KNOWN' if left[pair] > 0 else 'FAIL'
        left[pair] -= tag == 'KNOWN'
        open_fails += tag == 'FAIL'
        print('%s page %d: check %d: %s' % (tag, page, num, msg))
    stale = sorted(pair for pair, n in left.items() if n > 0)
    for pair in stale:
        print('STALE --known "%s" matches no failure: take it out' % pair)
    print('%s: %d pages, %d failure(s), %d known, %d stale%s' % (
        pdf.rsplit('/', 1)[-1], total, open_fails, len(fails) - open_fails, len(stale),
        '' if payload is not None else '; check 5 not run (no payload)'))
    if open_fails or stale:
        return 1
    return 3 if payload is None and not no_payload_ok else 0


def main(argv):
    args, known, no_payload_ok = [], [], False
    it = iter(argv)
    for a in it:
        if a == '--known':
            known.append(next(it, ''))
        elif a == '--no-payload-ok':
            no_payload_ok = True
        else:
            args.append(a)
    if not 1 <= len(args) <= 2 or any(not re.fullmatch(r'\d+:\d', k) for k in known):
        print(__doc__)
        return 2
    payload = json.load(open(args[1], encoding='utf-8')) if len(args) > 1 else None
    return check(args[0], payload, known, no_payload_ok)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
