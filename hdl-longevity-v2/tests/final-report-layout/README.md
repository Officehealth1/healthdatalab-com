# Final report layout check

Run before any change to the Final template; a red check means do not write production.

The Final report PDF is 19 fixed A4 boxes (20 when the client used the choices page).
Anything longer than its box is clipped without a warning. `check_layout.py` reads a
rendered PDF and fails, by page number, on:

1. a page count other than 20 with choices / 19 without (with no payload: 19 or 20);
2. a header or footer that does not read "n / total";
3. text past the content box (cut at the foot of a page);
4. text on top of text, every pair of words on a page;
5. a payload field whose last six words are missing from the PDF; the report date
   must be there twice (cover and the sign-off that closes the Summary page);
6. fewer than 15 contents rows, a row or a "Page N" reference that cites the wrong
   page, or no page titled "Actionable recommendations";
7. no page titled "Your assessment inputs", or a bare "/5" (no score) on it.

Exit codes: 0 green; 1 a failure or a stale `--known`; 2 usage; 3 nothing failed but
no payload was given, so check 5 did not run. Pass `--no-payload-ok` only when the
payload is truly not to hand: without it a cleanly cut ending cannot be seen.

## The commands

Render on STBY with WP-CLI from the WordPress root (the key is read in place; only a
template named "PROOF — " is written or rendered). The payload goes in `/tmp/frl/`
(mode 700); the PDF comes back from there.

```sh
wp eval-file - payload <proof-id> payload-worst.json worst.pdf < render-proof.php
python3 -I check_layout.py worst.pdf payload-worst.json
python3 -I red_fixtures.py
php render-proof.php selftest
```

Do the same with `payload-min.json` (19 pages). A failure that is known and still open
is passed as `--known "<page>:<check>"`: it covers one failure, is printed as KNOWN on
every run, and prints STALE and fails once it matches nothing.

`red_fixtures.py` runs the check on made-up PDFs with one fault each
(`fixtures/red/`, HTML source beside each PDF) and expects every one to fail and name
its check. A change to the check gets a new fixture first.

## The files

- `payload-worst.json`: made up; every text field at its limit, ten choices, six
  abilities, six people, blood pressure empty. Each field ends with its own marker word.
- `payload-worst-plus.json`: the same with every AI text field 1.3 times its word
  limit. The candidate is NOT green on it: pages 12, 14, 15 and 16 are too small for
  that much text (see the pull request for the points per page).
- `payload-min.json`: made up; no choices, no health background, five inputs empty.
- `candidate.py`: builds the candidate template from the backup in
  `docs/pdfmonkey/backups/2026-11-14/` by counted text edits, one block per defect.
- `proof-template.php`: `create` copies the production template into the PROOF copy;
  `put <id>` writes `/tmp/frl/candidate.html` to it: body and stylesheet, each with
  its draft, in one call, read back equal.
- `render-proof.php`: `payload`; `doc` (re-render a PDFMonkey document's own payload
  and save that payload beside the PDF for check 5; it goes only to the git-ignored
  `_local/` folder with its PDF, never into the repository); `cleanup` (delete the
  proof's documents: a card of another template is skipped, more than 15 listed
  deletes nothing); `selftest` (that rule on made-up cards, no WordPress needed).

Remove `/tmp/frl` on STBY when done: a re-rendered client PDF and its payload are
personal data.
