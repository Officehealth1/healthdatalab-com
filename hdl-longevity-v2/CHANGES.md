# CHANGES — Stage-2 audio hardening + audit fixes

Branch: `fix/stage2-audio-recovery-and-audit-hardening` (off coherent baseline `8543ab3`).
Scope: **STBY only.** Never deployed to LIVE without explicit "push to live".
Start state: HDLV2_VERSION **0.47.20** / HDLV2_DB_VERSION **3.23** (DB version unchanged by any change here).

Convention: one logical change = one commit + a `HDLV2_VERSION` bump. JS verified with `node --check`; PHP with `php -l`; each deploy md5-verified local↔STBY; STBY opcache is `validate_timestamps=On, revalidate_freq=2` so a scp'd file goes live within ~2s.

---

## Step 0 — coherent baseline (`8543ab3`, 0.47.20)

**What:** Reconciled the version *label* so the working tree is internally coherent. AUDIT.md §3.16 flagged committed JS referencing CSS classes that existed only in uncommitted files, with HEAD's version trailing the tree.
**Why:** A clean, coherent starting point before any fix.
**Finding (important):** the divergence was far smaller than the audit implied — an md5 full-sweep proved the working tree was **byte-identical to STBY 0.47.20** across every php/js/css file *except* the version constant in `hdl-longevity-v2.php` (local said 0.47.18). The .19/.20 content (Section-6 health-background CSS, Stage-1 email + Flight-Notes numeric parity, "Draft Report" wording) was already present.
**Files:** `hdl-longevity-v2.php` (label .18→.20) + the 5 already-modified files committed as the baseline.
**Risk:** none — label-only, no behaviour change.
**Evidence:** post-edit md5 full sweep = 0 differing php/js/css files vs STBY; `/health` 0.47.20.

---

## PART A — Stage-2 voice recording hardening

### Root-cause writeup (the reported "silent stop, lost minutes")

The reported bug — *"recording worked a few minutes then stopped without warning; I was talking and it wasn't transcribing, and because I wasn't looking at the screen I lost a few minutes"* — was **already fixed at v0.47.2/.3** (the "Deepgram-authoritative" rework), confirmed by an 11-agent read of the live code. Specifically, the current code already:

- Records the **full session** with a parallel `MediaRecorder` on *every* take and ships the blob to **server-side Deepgram, which is authoritative** — Web Speech is demoted to a throwaway on-screen *preview* (`_beginCapture` / `_beginPreview`). So Web Speech dying mid-session can no longer lose data.
- Replaced the original false-stop cause: silence auto-stop is now **audio-RMS-driven** (`_armSilenceMeter`), not keyed off Web Speech `onresult` — the latter was what cut off a talking user when Web Speech stalled (the actual 2026-06-27 root cause).
- Keeps a backgrounded tab alive (AudioContext non-running → keep-alive), caps a runaway take at 90 min, cleans up on page-lifecycle events, and polls the transcription job adaptively/resiliently.

So Part A was **targeted hardening of the residual gaps**, not a rewrite. The single canonical text channel is `form_progress.stage2_data.vision_text` → the Make `vision_text` key; every change below keeps that field set byte-identical and does not touch the funnel state machine (`advance_to_stage_3`).

### A1 — block submit while a transcription job is in flight (`5a900d3`, 0.47.21) — HIGH
- **Problem (real silent data loss):** `completeStage2()` had no guard for an active recording or in-flight Deepgram job. During async the shared textarea is replaced by the waveform card, so the backup read returns null and `formData.vision_text` still holds the text typed *before* recording. If ≥10 chars, Submit fired the Make WHY webhook and atomically marked Stage 2 complete on a truncated/empty answer — dropping the transcript.
- **Fix:** `_jobInFlight` busy flag on `HDLAudioComponent` (set at stop + upload-start; cleared at all 6 terminal paths: empty/too-short blob, upload reject, upload network error, poll timeout, completed, failed/cancelled, plus teardown) + public `isBusy()`. `renderStage2` keeps the component handle (`s2Audio`); `completeStage2` blocks with a clear "still saving your recording" message while busy.
- **Files:** `assets/js/hdlv2-audio-component.js`, `assets/js/hdlv2-staged-form.js`.
- **Risk:** low — additive guard; no Make/funnel change. Lifecycle audited so it can neither stick `true` (Submit locked) nor stick `false` during a real job (hole reopens).

### A2 — detect mid-session mic loss + flush the partial take (`8698da9`, 0.47.22) — MED
- **Problem:** no `MediaStreamTrack 'ended'` handler — a mid-recording mic revoke / USB unplug / device steal went undetected, and the silence meter treats the dead track's flat-128 buffer as "keep alive", so it never auto-stopped → UI stuck on "recording" until the 90-min cap, with the captured audio never shipped.
- **Fix:** per-audio-track `'ended'` listener in `_beginCapture` → `_onMicLost()`, which calls `stopRecording` (flushing the captured blob to Deepgram — partial audio preserved, not discarded) and shows "Microphone disconnected — we saved what we captured." Late `'ended'` from our own stop is ignored (`isRecording` already false + recorder identity re-checked); one message per loss.
- **Files:** `assets/js/hdlv2-audio-component.js`.
- **Risk:** low — guarded against self-triggered/superseded events.

### A3 — autosave the transcript on delivery (`8a44a34`, 0.47.23) — MED
- **Problem:** the Stage-2 `onChange` (how the Deepgram transcript reaches the form) only set `formData.vision_text` in memory; persistence relied on Submit/onConfirm/blur. Record → see transcript → close tab before blurring = transcript lost (the why_collection transcript is not written server-side during transcription).
- **Fix:** `onChange` now debounce-schedules `autoSave(2)`. `autoSave` posts `{token,stage,data}` with **no** `submitted` flag → merges into `stage2_data` only: no Make webhook, no `stage2_completed_at` stamp (verified). Reuses the existing `saveTimer`.
- **Files:** `assets/js/hdlv2-staged-form.js`.
- **Risk:** low — submitted:false save; debounce coalesces with the blur autosave.

### A4 — resilient upload: retry + offline-aware + idempotent (`d3d5d65`, 0.47.24) — MED
- **Problem:** the transcribe-async upload was a single attempt; a dropped connection at upload time forced a full re-record (polling already retried, the upload didn't).
- **Fix:** `uploadFileAsync` now waits for connectivity when `navigator.onLine === false`, retries transient failures (network error / HTTP 5xx) up to 2× with backoff (surfacing 4xx immediately), and sends **one stable `idempotency_key`** per upload (Idempotency-Key header + `idempotency_key` form field) shared across retries. `rest_transcribe_async` is already idempotency-wrapped (token-scoped), so a retried-but-already-processed POST replays the first result instead of storing a second file + re-charging Deepgram. Key matches the server's `[A-Za-z0-9_\-]{8,128}` contract.
- **Files:** `assets/js/hdlv2-audio-component.js`.
- **Risk:** low — normal single uploads use a unique key each (unaffected); preserves the A1 busy lifecycle via the `fail()` helper.

### A5 — audible + haptic cue on stop (`54ee770`, 0.47.25) — LOW (spec A.3.3)
- **Problem:** recording stop was conveyed visually only; the original report was a user who looked away.
- **Fix:** opt-in `stopCue` → `_playStopCue()` (quiet 660 Hz/0.18 s WebAudio blip + `navigator.vibrate(60)`) fired from `stopRecording` (covers manual stop, silence auto-stop, the cap, and mic-loss — not page-unload). Fully try/catch-wrapped; degrades silently where WebAudio/vibrate is unavailable (iOS has no vibrate). Stage-2 WHY passes `stopCue:true`; clinical/check-in surfaces stay silent (opt-in default off).
- **Files:** `assets/js/hdlv2-audio-component.js`, `assets/js/hdlv2-staged-form.js`.
- **Risk:** low — guarded, opt-in.

### A6 — cleanups (`161e33f`, 0.47.26) — LOW
- Renamed the user-facing fallback button 'Try local transcription' → 'Try again' (the in-browser Whisper tier was removed at E4 v0.46.47; it just restarts capture with the preview off — still server Deepgram). Removed the dead `onConfirm` from the Stage-2 mount (simpleMode renders no "Process with AI" action row, so it was never called; A3 routes persistence through `onChange`).
- **Intentionally retained:** `warmupStream` (vestigial but inert null-checks threaded through the sensitive page-lifecycle teardown — removing it churns load-bearing code for zero functional gain). Two historical *comments* still reference the legacy "Try local transcription" button name (accurate as history).
- **Files:** `assets/js/hdlv2-audio-component.js`, `assets/js/hdlv2-staged-form.js`.
- **Risk:** none — rename + dead-code removal.

### A7 — harden mic-loss + upload edge paths from adversarial review (`5d7d519`, 0.47.27)
A fresh adversarial sub-agent reviewed the full Part A diff and found **no HIGH regressions** and confirmed the A1 Submit-guard hole is genuinely closed for the normal flow, but flagged edge-path defects the PR itself introduced. Fixed here:
- **MED-1:** on a mid-session mic loss where the browser auto-stops the recorder *before* dispatching track `'ended'`, `stopRecording`'s `mediaRecorder.stop()` threw `InvalidStateError` (caught silently) → no `'stop'` event → `_jobInFlight` never cleared → Stage-2 Submit blocked until re-record, plus a false "we saved" message. Now `stopRecording` checks `recorder.state==='inactive'`/catches the throw and clears busy; message softened.
- **MED-2:** A4's unbounded offline-await could exceed the server's 30 s idempotency TTL, after which a retry re-stored the file + re-charged Deepgram. Bounded the whole upload to 25 s (`UPLOAD_DEADLINE_MS`) so a late retry always replays.
- **LOW-3/LOW-5:** offline-await re-checks on a 4 s timer (recovers from `navigator.onLine` false-negatives) and the deadline ends the loop (no permanent busy strand); `attempt()` bails if `_destroyed`.
- **Files:** `assets/js/hdlv2-audio-component.js`. **Risk:** low — edge-path only.
- **Documented residuals (no fix this pass):** LOW-4 (worker-origin 5xx replays a cached error — latency only, no data loss); LOW-6 (single busy boolean vs two *concurrent* takes — unusual flow; the textarea-overwrite race predates this work); ordering-Y partial loss on mic-revoke (rare browser race where the recorder auto-stops before `'ended'` — the common ordering preserves the partial). These are flagged for Quim's device QA.

### Part A verification
- **Static:** `node --check` clean on both JS files at every commit; `php -l` clean. `_jobInFlight` set/clear lifecycle audited end-to-end (set at stop + upload-start; cleared at every terminal path incl. inactive-recorder/stop-throw after A7).
- **Adversarial review:** fresh sub-agent over the full diff — verdict **no HIGH regressions; A1 guard genuinely closed; Make-payload + funnel invariants intact; shared-component (5 surfaces) safe; A3 autosave confirmed non-submitting**. The 2 MED + 2 LOW it raised are fixed in A7; remaining LOWs documented as residuals above.
- **Deploy:** files scp'd to STBY, **md5-verified byte-identical** local↔STBY; `/health` reports `0.47.27`, `db_in_sync:true`, tables 16/16; STBY serves the new JS (build 0.47.27, new symbols present).
- **Behavioural / device E2E (Quim):** the Part A acceptance suite (15-min continuous recording with silent gaps; induced background/network-drop/mic-revoke; iOS Safari + Android Chrome) is inherently real-device testing — handed to Quim per `docs/QA-RUNBOOK-2026-06-27-STAGE2-RECORDING.md`. Desktop browser automation cannot grant a mic, feed audio, or induce a mid-session revoke.

---

## PART B — audit findings

Each its own commit + `HDLV2_VERSION` bump; STBY-deployed + md5-verified. **No `HDLV2_DB_VERSION` bump anywhere — DB stays 3.23.**

### B.1 — store voice recordings outside the web root (`8bba0ed`, 0.47.28) — Medium
- **What/why:** §8.6 / finding #1. Recordings (Art. 9 PHI) were in `wp-content/uploads/hdlv2-audio/` — public, filename-obscurity only. **Reproduced live:** a real 759 KB recording returned HTTP 200 at its URL (OLS ignores `.htaccess` deny). Mirrored the PDF pipeline: store under `HDLV2_PRIVATE_DIR` (default `<wp-parent>/hdlv2-private/hdlv2-audio`) + `Require-all-denied` guard, dir 0700, files 0600. No serve route needed (Deepgram reads by absolute path, unchanged). `cleanup_old_audio()` sweeps both bases; `relocate_legacy_audio()` migrates existing files (run as www-data).
- **Files:** `includes/sprint-3/class-hdlv2-audio-service.php`. **Risk:** low; no Make/funnel/schema change.
- **Evidence (STBY):** 54 legacy recordings relocated (private 54 / public 0), the previously-exposed URL now **404s**, migrated file intact at 0600.

### B.2 — fingerprint PII out of logs (`0f8230c`, 0.47.29) — Medium
- **What/why:** §8.9 / finding #2 (+ #7). AI JSON-parse failures logged Claude-output substrings (client health data); the webhook monitor stored 200-char Make-response excerpts in `debug.log` + a `wp_option`. Replaced every site with `sha256 + length + decode-reason` — 8 ai-service sites, deepgram shape-failure, webhook-monitor body (the `$body_excerpt` carrier now holds the fingerprint, so the log lines + option rows are PII-free).
- **Files:** `class-hdlv2-ai-service.php`, `class-hdlv2-deepgram-service.php`, `class-hdlv2-webhook-monitor.php`. **Risk:** low — observability-only failure paths; no return-value change.
- **Evidence:** `php -l` clean; grep confirms zero residual client-content logging.

### B.3 — close the `/jobs/{id}/status` IDOR for all job types (`595e6ab`, 0.47.30) — Medium
- **What/why:** §8.4 / finding #3. Only 2 of 11 job types were ownership-gated; the other 9 leaked status/result to any authenticated caller walking IDs. `rest_status()` now gates UNCONDITIONALLY via `caller_owns_job()` — a fail-closed per-type resolver (unknown types deny by default). Bindings verified against every `enqueue()` call-site + live columns; the existing transcribe/organise logic preserved verbatim. Gate kept above the self-healing worker kick. 404 (not 403) — no enumeration.
- **Files:** `includes/class-hdlv2-job-queue.php`. **Risk:** medium (load-bearing poll) — mitigated by verifying the legit pollers (client-token draft poll, practitioner polls).
- **Evidence (STBY):** owner token → **200**, different valid token → **404**, no auth → **401**, previously-ungated `render_flight_plan_pdf` polled by non-owner → **404**.

### B.4 — SSRF-harden the two outbound fetches (`571dd9e`, 0.47.31) — Low
- **What/why:** §8.7 / #5 + #6. Swapped `wp_remote_post`→`wp_safe_remote_post` (practitioner webhook) and `wp_remote_get`→`wp_safe_remote_get` (callback `pdf_url`). **Verification exposed a real gap:** `wp_safe_remote_get` still reached `169.254.169.254` (cloud metadata) — `wp_http_validate_url` blocks RFC-1918 + loopback but NOT link-local. Added `HDLV2_Report_PDF::url_targets_reserved_ip()` (`filter_var NO_PRIV_RANGE|NO_RES_RANGE` over all resolved A/AAAA records) as a pre-check on both sites; callback also keeps its Bearer gate + `%PDF-` check.
- **Files:** `class-hdlv2-report-pdf.php`, `class-hdlv2-widget-config.php`. **Risk:** low.
- **Evidence (STBY):** 127.0.0.1 / 169.254.169.254 / 10.x / 192.168.x / `ftp://` all BLOCKED; `https://example.com` allowed. **Residual:** a redirect HOP to link-local still relies on the `%PDF-` check (WP revalidates hops only for RFC-1918).

### B.5 — remove the dead webhook-retry subsystem (`6ccd214`, 0.47.32) — decision: REMOVE
- **What/why:** §5b / §9.3. `HDLV2_Webhook_Retry::maybe_retry()` had zero callers and its 3 `refire_*` handlers were never defined → nothing was ever re-sent (false confidence). **Option (a) full-implement is not safely achievable in-plugin:** re-firing re-runs the Make scenario → re-generates a PDFMonkey doc (cost) + re-sends the client email, with no Make-side idempotency, and Make returns 200 even on scenario error (so a transport retry misses the dominant failure while risking a double-send). Removed the require, the boot registration, and the 213-line file. KEPT: the Webhook_Monitor ledger + admin notice + stuck-PDF watchdog (the detection layer we rely on) and the separate working Stage-2 extraction retry.
- **Files:** `hdl-longevity-v2.php`, deleted `includes/security/class-hdlv2-webhook-retry.php`. **Risk:** very low.
- **Evidence (STBY):** zero residual references, plugin boots, Stage-2 extraction retry intact.

### B.6 — fold the 6 migration-only columns into CREATE TABLE (`e7553c9`, 0.47.33) — Correctness
- **What/why:** §4.1 / §4.7. Six columns existed only via ALTER, so a fresh install and a migrated DB could diverge. Added each to its CREATE block with the same DDL; left the version-gated ALTERs in place. **No DB-version bump** (existing installs already have all six; bumping would force the whole A→AD chain to re-run).
- **Files:** `includes/class-hdlv2-activator.php`. **Risk:** low (the ENUM dbDelta-churn risk was checked and did not materialise).
- **Evidence (STBY):** re-running `upgrade()` emitted **0 dbDelta ALTERs** for these columns, `db_version` stayed 3.23, no SQL error, all six columns remain exactly one each (no drop/recreate/dup).

### B.7 — clear the 2 leaked deactivation crons (`c4be6cb`, 0.47.34) — Correctness
- §4.6/§4.8. Deactivator cleared 10/12 hooks; added `wp_clear_scheduled_hook` for `hdlv2_attention_email_cron` + `hdlv2_iris_reconcile`. **Files:** `class-hdlv2-deactivator.php`. **Evidence:** now clears all 12 (grep 12=12). Runtime confirmation (deactivate→re-list) deferred to go-live / Quim.

### B.8 — delete the ~22.5 MB orphaned vendor blobs (`63ae0ae`, 0.47.36) — Cleanup
- §3.12/§3.15. `transformers.min.js` + ORT wasm/mjs — zero functional references (only a historical comment), never deployed to STBY. Deleted. **Risk:** none.

### B.9 — remove the orphan flag seed (`61648ec`, 0.47.35) — Cleanup
- §7.2/§7.6. `hdlv2_ff_pending_in_list` was `add_option`'d but never read (project-wide grep = 1 hit, the seed). Removed the seeding; left the Phase AC backfill intact. **Risk:** none.

### B.10 — correct stale model comments + docs (`322d55f`, 0.47.37) — Docs
- §5a/§3.10/§5b. Doc-only. ~12 Sonnet/Haiku comments → `claude-opus-4-8` (Stage-1/2/3 result builders relabelled "deterministic static — no AI"); `hdl-longevity-v2/CLAUDE.md` rewritten to the 2-tier audio chain + 19 tables + 85/74 routes (NB: this file is gitignored, so the fix is on disk only); root `CLAUDE.md` gained `HDLV2_MAKE_REDFLAG`. Left untouched: `MODEL_HAIKU` constant name (= claude-opus-4-8), "Make.com Sonnet" external-pipeline notes, "Replaces Haiku" historical mentions.

### Part B verification
- **Per item:** the specific problem demonstrated fixed AND the legitimate path confirmed working on STBY (see each entry's evidence). All PHP `php -l` clean; each file md5-verified byte-identical local↔STBY; `/health` 0.47.37, `db_in_sync`, tables 16/16.
- **Second adversarial pass (fresh sub-agent over the full Part B diff):** verdict **no HIGH or MED defects — correct and safe to proceed.** It re-verified the B.3 bindings at every enqueue call-site + live columns (no fail-open: every branch guards `!$uid||!$ref`, `$uid===$ref` is same-namespace so no cross-client coincidence; no false-404: the real pollers all resolve correctly, and the 4 non-polled branches fail-closed defensively), B.1 migration safety (guards skipped in cleanup, idempotent relocate, no traversal), B.4 (resolves all A/AAAA, fail-closed on unresolvable; residuals neutralised by the `%PDF-` gate), B.2 (json_last_error_msg valid at each site; no residual raw logging), B.5 (zero references; working retry intact), B.6 (all 6 columns match the ALTER DDL exactly; no dup; NOT NULL columns have defaults). 3 LOW/informational residuals, all pre-mitigated — none block shipping.
- **Contracts:** no change touches the Make Stage-2 payload field set or the `advance_to_stage_3` funnel transition (B.1–B.10 are storage/security/logging/schema/docs).

---

## Staging is green

**STBY at HDLV2_VERSION 0.47.37 / DB 3.23** (`/health` ok, `db_in_sync`, 16/16 tables). Branch `fix/stage2-audio-recovery-and-audit-hardening` = 1 coherent baseline + 7 Part-A + 10 Part-B atomic commits, each version-bumped, `php -l`/`node --check` clean, md5-verified byte-identical local↔STBY, **both adversarial passes clean** (Part A's findings fixed in A7; Part B clean).

**What was fixed:** the Stage-2 voice recorder hardened to the full A.3 spec (the original silent-stop was already fixed at 0.47.3; this closed the residual submit-while-busy data-loss path + mic-loss + autosave + upload resilience + cue), and all 10 audit findings — the live-exploitable audio-folder exposure, PII-in-logs, the all-types `/jobs` IDOR, SSRF (incl. the cloud-metadata gap the swap alone missed), the dead retry subsystem, schema coherence, the leaked crons, the orphaned blobs + flag, and the stale model docs.

**What is verified by me vs. what needs you:**
- **Verified on STBY** (concrete evidence per item above): every Part-B security fix demonstrated closed AND its legitimate path confirmed working; B.6 a proven dbDelta no-op; both adversarial reviews.
- **Needs Quim (device QA, Part A):** the audio acceptance suite is inherently real-device — 15-min continuous recording with silent gaps; induced background/network-drop/mic-revoke; iOS Safari + Android Chrome; submit-while-transcribing blocked; the stop cue. Runbook: `docs/QA-RUNBOOK-2026-06-27-STAGE2-RECORDING.md`.

**Residual risks (documented, non-blocking):** Part A — rare browser-race partial loss on mid-recording mic-revoke; cached-5xx replay (latency only); two rapid concurrent takes vs the single busy flag. Part B — B.4 IPv6-mapped/DNS-rebind-to-link-local theoretical residual (neutralised by the `%PDF-` content gate; widget webhook never reads its response).

---

## Recommended LIVE-migration plan (for human approval — NOT executed)

LIVE is **0.41.18 / DB 3.13**; this branch is **0.47.37 / DB 3.23**. ⚠ The gap is far more than this work — the 0.47.20 baseline already carries months of prior STBY features (iris, safety screen, flight notes, parity, etc.). Treat this as the **master V2 go-live**, run against `docs/plans/V2-LIVE-MIGRATION-CHECKLIST.md`, **only on explicit "push to live"** + Matthew sign-off + Quim's device QA. Do **not** push the audio JS/IDOR/SSRF changes to LIVE piecemeal — they assume the 0.47.37 server state.

Ordered sequence:
1. **Pre-flight.** Confirm LIVE `wp-config.php` has all V2 constants (incl. `HDLV2_MAKE_REDFLAG`, `HDLV2_PRIVATE_DIR` or the default sibling dir is writable by www-data). Full DB backup + a plugin-files snapshot for rollback. Confirm system cron / loopback is firing (memory: LIVE/STBY have had WP-Cron disabled).
2. **Deploy the plugin files** (Gist+wget or scp) for the whole 0.47.37 tree — not a partial set. Then **`opcache_reset`** (OLS) so the new code + version load.
3. **Let `maybe_upgrade_db()` run the Phase chain 3.13 → 3.23** on first boot (automatic, idempotent, INFORMATION_SCHEMA-guarded). Verify `/health` → `plugin_version:0.47.37`, `db_in_sync:true`, expected table count. Tail `debug.log` for any Phase error. (B.6's CREATE-table additions are a dbDelta no-op here — the columns already arrive via the Phase ALTERs.)
4. **B.1 — relocate existing LIVE recordings:** run **as www-data** `wp eval 'echo HDLV2_Audio_Service::relocate_legacy_audio();'` (NOT as root — root trips WP's filesystem-credentials path against www-data-owned files). Then HTTP-GET a known recording URL to confirm it now **404s**, and confirm the private dir holds the files at 0600 with the deny guard.
5. **Smoke the security fixes on LIVE:** `/jobs/{id}/status` cross-account → 404 (B.3); a real Make→PDFMonkey callback still self-hosts a PDF (B.4 — confirm the legit public presigned URL passes; this is the path STBY couldn't exercise); confirm no PII in the new `debug.log` lines (B.2). Confirm a Make fire still logs `[HDLV2 Webhook][...] OK 200` and no admin notice (B.5 — detection layer intact).
6. **Walk the funnel** (Stage 1 → 2 WHY incl. audio → release → 3 → draft → consultation → finalise) on LIVE with one test client; confirm the Make Stage-2 webhook fires with the unchanged 21-key payload and the report PDFs render.
7. **Update the LIVE drift note** + the master checklist; keep the rollback snapshot until a clean 24-48h.

Per-item LIVE notes: **B.7** (deactivator) only matters if the plugin is ever deactivated — no action on a file deploy. **B.8/B.9/B.10** are inert on LIVE (blobs were never deployed; flag seed is harmless; docs). **B.6** needs no DB bump. The one LIVE-specific action unique to this work is **step 4** (audio relocation).
