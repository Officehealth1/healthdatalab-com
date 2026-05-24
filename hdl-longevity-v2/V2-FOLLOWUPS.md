# V2 Follow-ups

Tracked work items that are not blocking the current build but should be
addressed once the active commit chain ships and stabilises. Each entry
includes context, scope, and a "queue after" hint so they can be ordered.

---

## V1 rename Gist deploys — 6 files modified locally in W12 but not yet on LIVE V2

**Discovered:** 2026-05-24 during W12 build (Phase 3 edits).

**Decision context.** W12 renamed "Report" → "Trajectory Plan" across 64 user-facing strings — 58 in V2 (deployed via safe-deploy-to-live.sh in commit cc39297) and 6 in V1. The V1 edits ARE present in the local files but cannot reach LIVE V2 through this commit because `health-data-lab-plugin/` is `.gitignored` per the repo root `.gitignore` (V1 is deployed via the Gist + wget pattern documented in CLAUDE.md, not by the rsync script).

**Files modified locally (V1):**
- `forms/longevity-form-content.php:594` — Apple modal heading "Your Longevity Report is on its way!" → "Your Trajectory Plan…" (note: file is also broken — see separate follow-up)
- `forms/health-form-raw.php:15927` — modal title `'Processing Your Report'` → `'Processing Your Trajectory Plan'`
- `forms/longevity-form-raw.php:470` — Apple modal heading (same string as longevity-form-content)
- `api/class-consumer-provisioner.php:900` — email header `'Your Longevity Report'` → `'Your Trajectory Plan'`
- `api/class-consumer-provisioner.php:917` — email subject `'Your {$source_label} Longevity Report — powered by HealthDataLab'` → `'… Trajectory Plan …'`
- `core/class-health-tracker.php:297` — admin practitioner stat label `'Longevity Reports'` → `'Trajectory Plans'` (plural)

**Fix path.** Deploy each file via the existing V1 Gist workflow per CLAUDE.md:

```bash
gh gist edit <GIST_ID> -f <filename> <local_path>
ssh healthdatalab.net 'wget -O /var/www/html/wp-content/plugins/health-data-lab-plugin/.../<filename> "https://gist.githubusercontent.com/raw/<GIST_ID>/<filename>"'
```

Known Gist IDs (from CLAUDE.md):
- `class-consumer-provisioner.php` → `70b1b85de86c1d013d58eac1199d5e86`
- `class-health-tracker.php` → `6ec9205d676814a05d56d15b462c847c`
- `longevity-form-raw.php` → `5f1e5e5d07a13aed7d197f2992dbfd46`
- `health-form-raw.php` → `d8dc28ca026e4e5de3a4574d13be5e2c`
- `longevity-form-content.php` — NOT in the known-Gists table; check if a Gist exists or if this file deploys differently (likely doesn't deploy at all — see related follow-up about its parse error)

**Queue.** Before IIPA launch — V1 modals + email subjects need consistent copy with V2 for the launch to feel coherent to clients. Bundle the 4 known-Gist deploys in one session.

---

## V1 `longevity-form-content.php` — unmatched `}` on line 10325, file appears unused

**Discovered:** 2026-05-24 during W12 lint pass.

**Symptom.** `php -l health-data-lab-plugin/includes/forms/longevity-form-content.php` returns `Parse error: Unmatched '}' in … on line 10325`. Same error present on LIVE V2 (file dated `Feb 22 13:58`, 536KB) → predates the entire W12 work.

**Why not blocking.** `grep -rn 'longevity-form-content' /var/www/html/wp-content/plugins/health-data-lab-plugin/` returns no `require_once` references. The file is never loaded at runtime, so the parse error never fires. WordPress + V1 plugin boot normally.

**Why filing.** Two reasons:
1. **The W12 edit at line 594** (rename "Longevity Report" → "Trajectory Plan" in an Apple modal heading) is functionally a no-op because the file is dead code. It's classified as user-facing and Matthew approved the rename, so it stays in the local file for consistency, but the modal heading no user will see is irrelevant.
2. **Hygiene** — a 536KB PHP file with a syntax error in the active V1 plugin directory is a footgun for anyone running `php -l` across the tree, and a code-quality smell.

**Fix path (either).**
- Fix the brace match — find what's missing/extra on line 10325 and patch.
- Delete the file — verified no consumers; deletion is safe.

The deletion option is faster and safer; the file is presumably a legacy template that was superseded by the `*-raw.php` variants (longevity-form-raw.php, longevity-form-content.php seem to overlap on the same modal markup, with `*-raw.php` being the actively loaded version).

**Queue after:** post-W13 build chain ships, before the V1 Gist deploys above. Confirm no `require_once` exists (already verified) + check git blame for the file's original purpose, then delete or fix.

---

## Detail-view audio + final-report markdown rendering for automation-tier clients

**Discovered:** 2026-05-24 during W11 build (SELF-REPORTED badge + Source filter — automation-tier dashboard surfacing).

**Decision context.** W11's new `loadAutoConsultation()` tab handler renders a read-only "Self-reported data" block (heading + submitted timestamp + body blockquote + Make.com-Route-1 footer). The W11 backend exposes `auto_consultation = { addendum_id, submitted_at, body_text }` per automation-tier client through the `/dashboard/clients` response.

**Deferred scope.** The original W11 spec asked for two additional render elements in the detail view:

- **Inline audio player** for the original self-reported recording (uses V2's existing signed-URL generation helper — same mechanism the practitioner-consultation audio playback uses today).
- **AI-generated Trajectory Plan markdown** rendered inline (from `wp_hdlv2_reports.report_content` or wherever Make.com Route 1's callback stamps the final markdown — depends on whether the Route-1 callback writes back to V2 or only emails the PDF).

**Why deferred.** Make.com Route 1 already emails the rendered PDF directly to the client, and the audio is already captured in the body_text transcript (via W9's `onConfirm` append to the textarea). The practitioner read-only view doesn't STRICTLY need either embedded in the dashboard for the launch to work — the boss can use the SELF-REPORTED badge + submitted timestamp + body text to understand who self-reported and when. The audio file lives in `wp_hdlv2_audio_extractions` if the practitioner needs to listen later (currently no UI for that).

**Fix path (post-launch, gated on real demand).**

1. **Audio player.** Extend `auto_consultation` object in `class-hdlv2-client-status.php::rest_get_clients()` with `audio_signed_url` (look up the audio extraction row matching the form_progress + addendum, generate signed URL via the existing V2 helper). Render `<audio controls src="..." />` inside the W11 `loadAutoConsultation()` body block. ~30 lines server + ~10 lines client.
2. **Final report markdown.** Extend `auto_consultation` with `final_report_md` (read from `wp_hdlv2_reports.report_content` where `form_progress_id` matches and `report_type='final'`). Render via the same markdown helper the practitioner consultation editor uses. ~20 lines server + ~15 lines client.

**Gate.** Only build this when real automation-tier submissions surface a practitioner workflow that needs either element. If the boss never opens the Consultation tab for an automation-tier client because the Make.com PDF email is enough, this stays deferred indefinitely.

**Queue after:** the automation tier build ships (post-W13) AND real automation-tier traffic creates a practitioner workflow that needs either element.

---

## PDFMonkey Final Report template subtitle still references "Longevity Report"

**Discovered:** 2026-05-24 during W12 build (string rename pass).

**Decision context.** The Final Report PDFMonkey template (`1c9f06c5-ca6d-4264-9993-33f3531f9f89`) subtitle still reads "Longevity Report" — that copy is rendered server-side by PDFMonkey, not by the WordPress plugin tree. W12's grep + rename pass covers all `.php` / `.js` / `.css` / `.html` matches inside the repo, but the PDFMonkey template lives outside the codebase.

**Scope.** Matthew or the boss renames the template subtitle inside the PDFMonkey UI from "Longevity Report" → "Trajectory Plan" after W12 ships. Mirrored docs `hdl-longevity-v2/docs/pdfmonkey/PDFMONKEY-FINAL-REPORT-HTML.md` should also be updated in the same pass so the local copy stays in sync.

**Queue after:** W12 deploys and the WordPress-side rename is verified on LIVE V2. PDFMonkey rename is a separate, manual step in the PDFMonkey admin UI.

---

## `HDLV2_AI_Service::MODEL_HAIKU` constant-name vs value mismatch — silent Sonnet misrouting

**Discovered:** 2026-05-24 during W9 build (looking for a Haiku-routable helper for the automation-tier rec/milestone Claude call).

**Symptom.** `class-hdlv2-ai-service.php` lines 24–25:

```php
const MODEL       = 'claude-sonnet-4-6';
const MODEL_HAIKU = 'claude-sonnet-4-6';   // ← name says Haiku, value is Sonnet
```

The comment block immediately above (lines 21–23) documents the original intent: *"MODEL_HAIKU kept as a separate const so the [cost-optimisation Haiku migration] without touching every other caller of MODEL."* So the value is the wrong half of the pair — the rename was applied to `MODEL` (correct: Sonnet) but `MODEL_HAIKU` was set identically instead of to a Haiku model id.

**Active caller** — there is exactly one: `class-hdlv2-ai-service.php:1422` in `generate_stage3_commentary()`:

```php
$response = self::call_claude( $key, $system, $user_prompt, 800, self::MODEL_HAIKU );
```

This call fires every time a client lands on the Stage 3 result page. It's been running on Sonnet (intended: Haiku) since the constant was introduced — roughly 10× cost overrun per call (~$0.005–0.01 Sonnet vs ~$0.0005–0.001 Haiku, 800-token cap).

**Fix path (single-line commit post-W13).** Decide which of the two is canonical:

- **Most likely correct:** change the value — `const MODEL_HAIKU = 'claude-haiku-4-5-20251001';` — matches the constant name and the prior dev's stated intent. Net effect: Stage 3 commentary call migrates to Haiku, ~10× cost reduction on that one path. Risk: Haiku may produce different commentary text quality; eyeball-review one Stage 3 result before/after.
- **Alternative:** rename the constant to `MODEL_SONNET_FALLBACK` (or just delete it and have the caller use `MODEL` directly). Reflects current behaviour without changing it. Lower-risk but doesn't realise the cost saving the original code was designed for.

The name vs value mismatch makes intent ambiguous; the comment block weighs toward Option 1 but a quick eyeball-diff on Stage 3 commentary quality (Sonnet vs Haiku, same input) would settle it before committing.

**Queue after:** the automation tier build ships (post-W13). Either fix path is a single-line commit + push-additive deploy.

---

## Audio `contextType: 'why_collection'` reuse on automation tier

**Discovered:** 2026-05-24 during W8 build (`[hdlv2_auto_consultation]` shortcode).

**Decision context.** The audio recorder mounted by the new automation-tier shortcode uses `HDLAudioComponent.create()` with `contextType: 'why_collection'` — the closest existing semantic for open-ended client input. Existing values are `why_collection` / `consultation_notes` / `weekly_checkin`, all routed by the server-side audio service. Introducing a fourth value (`self_reported`) would require a matching server-side handler change before this build's atomic-commit chain can close.

**Trade-off.** Audio extractions from automation-tier clients land in `wp_hdlv2_audio_extractions` tagged `why_collection` internally — same bucket as Stage 2 WHY recordings. The source-of-truth distinction for automation-tier consultations lives in `wp_hdlv2_consultation_addenda.submitter='client_automation'` (W3 schema), which is queryable and unambiguous. The audio-extractions table is correlational, not authoritative — it doesn't need source separation for any current downstream consumer.

**Why not block the build.** No current code path joins `audio_extractions` with `consultation_addenda` to attribute automation-tier audio specifically. Analytics + reporting (e.g., "how many minutes of audio per client?") would still aggregate correctly because the rows are owned by the same user. The only risk is a future analyst grouping `audio_extractions` by `context_type` and over-counting "WHY" audio — which a `JOIN` against `consultation_addenda.submitter` would correct.

**Fix path (cleanup, post-launch).** Add `'self_reported'` to the audio-service's accepted `context_type` enum, route to the same Deepgram handler with no special processing, and flip W8's JS to send the new value. Single-file change in `sprint-3/class-hdlv2-audio-service.php` + version bump in `assets/js/hdlv2-auto-consultation.js`.

**Queue after:** the automation tier build ships (post-W13) AND analytics/reporting need cleaner separation (no consumer today).

---

## rest_get_version SQL ambiguity — `class-hdlv2-client-status.php` line 874

**Discovered:** 2026-05-24 during W2 catch-up post-deploy verification.
**Pre-existing — not introduced by W2.** First surfaced 2026-05-23 09:54:49 UTC in LIVE V2 LSWS error log; persists in v0.41.25 (the W2 catch-up did not fix it).

**Symptom.** Every call to `GET /wp-json/hdl-v2/v1/client-status/version` emits a `WordPress database error: Column 'created_at' in SELECT is ambiguous`. The endpoint then returns `{ v: 0 }` because `$wpdb->get_var()` returns false on SQL error and is cast to `(int) 0`.

**Frequency on LIVE V2.** ~12 errors/minute when the practitioner dashboard auto-poll is active. 2,162 occurrences captured in 24 hours of error log (1,339 rotated + 823 current at time of W2 deploy).

**Root cause.** The consultation_notes subquery at line 874:

```php
COALESCE(
    (SELECT MAX(COALESCE(approved_at, started_at, created_at))
     FROM {$p}hdlv2_consultation_notes cn
     INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = cn.form_progress_id
     WHERE cn.client_user_id IN ($ids) AND fp.deleted_at IS NULL),
    '1970-01-01 00:00:01'),
```

References `created_at` unqualified inside the `MAX(COALESCE(...))`. Both `cn` (consultation_notes) and `fp` (form_progress) have a `created_at` column. MariaDB rejects the unqualified reference as ambiguous.

**Fix path (NOT to be applied without review).** Either:

- `cn.created_at` — if the intent is "when was the consultation_notes row created" (matches the table the subquery is rooted on, alongside `approved_at` and `started_at` which are cn-only columns)
- `fp.created_at` — if the intent is "when was the underlying form_progress created"

The other two columns in the `COALESCE` — `approved_at` and `started_at` — only exist on `cn`, so the original intent is almost certainly `cn.created_at`. But this should be verified by reading the surrounding logic (v0.35.4 comment block at lines 855–867 explains the broader change) and confirmed against intent before committing.

**User impact.** Practitioner dashboard polling silently gets `v=0` for affected client cohorts → the dashboard's auto-refresh hook fails to fire when check-ins, ticks, addenda, or report updates happen for those clients. Manual page refresh still works (it reads underlying tables directly, not via this endpoint). Not blocking the automation tier build.

**Queue after:** the automation tier build ships (post-W13). Single-character SQL fix + commit + push-additive deploy.

---

*End of follow-ups. Append new entries at the top with the same structure.*
