# Final report layout check

Run before any change to the Final template; a red check means do not write production.

The Final report PDF is 19 fixed A4 boxes (20 when the client used the choices page).
Anything longer than its box is clipped without a warning. `check_layout.py` reads a
rendered PDF and fails, by page number, on:

1. a page count other than 20 with choices / 19 without;
2. a header or footer that does not read "n / total";
3. text past the content box (cut at the foot of a page);
4. text on top of text;
5. a payload field whose last six words are missing from the PDF;
6. a contents row, or a "Page N" reference, that cites the wrong page;
7. a bare "/5" (no score) on "Your assessment inputs".

## The two commands

Render on STBY with WP-CLI from the WordPress root, as the web server's user (the key
is read in place; only a template named "PROOF — " is written or rendered). The
payload goes in `/tmp/frl/` (create it with mode 700); the PDF comes back from there.

```sh
wp eval-file - payload <proof-id> payload-worst.json worst.pdf < render-proof.php
python3 -I check_layout.py worst.pdf payload-worst.json
```

Do the same with `payload-min.json` (19 pages). A failure that is known and still
open is passed as `--known "<page>:<check>"` and printed as KNOWN on every run.

## The files

- `payload-worst.json`: made up; every text field at its limit, ten choices, six
  abilities, six people, blood pressure empty. Each field ends with its own marker word.
- `payload-min.json`: made up; no choices, no health background, five inputs empty.
- `candidate.py`: builds the candidate template from the backup in
  `docs/pdfmonkey/backups/2026-11-14/` by counted text edits, one block per defect.
- `proof-template.php`: `create` copies the production template into the PROOF copy;
  `put <id>` writes `/tmp/frl/candidate.html` to it, body and stylesheet together.
- `render-proof.php`: `payload`, `doc` (re-render a PDFMonkey document's own payload,
  which never leaves the server) and `cleanup` (delete the proof's documents).

Remove `/tmp/frl` on STBY when done: a re-rendered client PDF is personal data.
