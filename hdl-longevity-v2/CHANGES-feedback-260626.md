# CHANGES — client/practitioner feedback fixes (F1–F7)

Branch: `fix/feedback-260626` (off `bd7e0b0`, itself the tip of `fix/stage2-audio-recovery-and-audit-hardening` — Part A audio + Part B audit + iris capture-only, all dark/on-hold).
Scope: **STBY only.** Never LIVE without explicit "push to live" + the three hard gates (see bottom).
Start state: STBY **0.47.42 / DB 3.24**, healthy. End state: STBY **0.47.47 / DB 3.24** (no schema change).
Convention: one logical fix = one commit + `HDLV2_VERSION` bump; `php -l` / `node --check` clean; each file scp'd to STBY + **md5-verified byte-identical** local↔STBY; `/health` re-checked after each deploy.

> **Assess-first (per Quim's steer 2026-07-02):** every item was checked against the live STBY code *before* changing anything. **3 of 7 were already fixed** and were left untouched; **2 more were effectively done** bar a whisker; only the genuinely-broken items were changed.

---

## Status summary

| # | Reported issue | Verdict | Version |
|---|----------------|---------|---------|
| **F1** | Bio age 46 vs 45.9 | **Already ~fixed** (tile→1dp @0.47.15, page/widget `toFixed(1)`, payload source `round(,1)`). Fixed the real residual: whole-number bio-age (46.0) JSON-encoded as int `46` in the Make payload; + the task's explicit "compute once"; + stale comment. | 0.47.43 |
| **F2** | Chrono age blank in email | **Genuinely broken** — payload had no top-level `chronological_age`. Added it. | 0.47.44 |
| **F3** | Pending leads not in main list | **Genuinely broken** — built the pinned "Pending confirmation" group + dropdown + Confirm/Reject. (+ .47 hardening: sort re-pin + keep-dropdown-open, from the adversarial review.) | 0.47.45 / .47 |
| **F4** | "Where is pdf?" | **Already done (0.47.12, LIVE E2E-proven).** Stage-1 detail renders a download card when a PDF exists, nothing when absent. **Skipped.** | — |
| **F5** | "None (to my knowledge)" checkboxes | **Already done (0.47.15/.16).** Full round-trip verified. **Skipped.** | — |
| **F6** | "Draft Trajectory Plan" → "Draft Report" | **Mostly done (0.47.20).** Fixed the one client-facing straggler (Stage-3 AI fallback). | 0.47.46 |
| **F7** | Support email domain | **Flagged — no clear bug.** `office+matthew@` is only in the *dark* automation-tier admin; user-facing support addresses are all `@healthdatalab.com` (consistent). Quim confirmed: leave `@healthdatalab.com`. **No change.** | — |

Files changed (whole feedback diff, `git diff bd7e0b0..HEAD`): 7 files, +288/-14.
Part A (audio) files (`hdlv2-audio-component.js`, `hdlv2-staged-form.js`) and Part B files (audio-service, job-queue, report-pdf, deepgram, webhook-monitor, activator schema, deactivator) are **not touched** by this diff → no Part A/B regression by construction.

---

## F1 — one-decimal biological age, computed once (`854d424`, 0.47.43)

**What:** New canonical helper `HDLV2_Stage1_Commentary::biological_age($rate,$age)` → `round(rate×age,1)` or `null`. Routed through it: `p1_headline()`, `build_structured()` (the payload source), and the practitioner Stage-1 tile (`client-status.php`). The Stage-1 webhook `biological_age` field is now `number_format((float)…,1,'.','')` — a stable 1-dp **string** (mirrors `rate_of_ageing` above it + the Final-Report payload). Widget stat card → `bio.toFixed(1)`. Stale "integer rounding" comment in client-status.php corrected.
**Why:** The reported 45.9-vs-46 was already resolved by earlier parity work (tile 1dp @0.47.15). The genuine residual: a *whole-number* bio-age (e.g. 46.0) JSON-encoded as integer `46`, so the Make email/PDF could show "46" while the screen/tile show "46.0". Plus the task's explicit "compute ONCE" (3 duplicate `round(rate*age,1)` sites) and the drift-inviting stale comment.
**Files:** `class-hdlv2-stage1-commentary.php`, `class-hdlv2-client-status.php`, `class-hdlv2-widget-config.php` (payload), `widget/hdl-lead-magnet.js`, `hdl-longevity-v2.php`.
**Risk:** low. Value unchanged for the reported case (45.9). Payload value/format only — no field renamed/removed. Load order guarantees the helper class is loaded before client-status runs (sprint-2 before sprint-4).
**Evidence (STBY, live code):** `helper(0.9,51)=45.9`; **whole-number case** `helper(1.0,46)=46.0` → OLD json `{"biological_age":46}` (the bug) vs NEW `{"biological_age":"46.0"}`; `build_structured` routes through the helper. Client page + tile already 1-dp (`toFixed(1)` + server `round(,1)`).

## F2 — chronological_age in the Stage-1 payload (`3e60a54`, 0.47.44)

**What:** Added `'chronological_age' => (string)(int)($stage1_data['q1_age'] ?? 0)` to the Stage-1 webhook `$pdf_payload`, right after `biological_age`.
**Why:** The Make Stage-1 email renders `{{1.chronological_age}}` but the payload had no such top-level field (the value lived only inside `stage1_data.q1_age`, which the PDF reads) → the email rendered blank. Mirrors the Final-Report payload, which already carries `chronological_age`. String cast per this payload's Make type-cache convention.
**Files:** `class-hdlv2-widget-config.php`, `hdl-longevity-v2.php`.
**Risk:** low — purely additive.
**Evidence:** real payload capture (below).

## F3 — pending widget leads in the main client list (`664b668`, 0.47.45)

**What:** A pinned "Pending confirmation" group at the top of the practitioner client table, one row per pre-Confirm widget lead, with an expand-dropdown of their Stage-1 details + inline Confirm/Reject.
- Backend (`widget-config.php`, `rest_list_pending_leads`): the response now includes a **whitelisted** decoded `stage1_data` (only the 9 questionnaire answers — never `server_result`/`_safety`). Own-practitioner-scoped query; `null` for older answer-less leads.
- JS (`hdlv2-client-list-enhance.js`): `renderPendingLeadsGroup()` reuses `state.pendingLeads` (no new fetch), builds a group head + rows + a self-contained (esc()-escaped, empty-data-guarded) Stage-1 dropdown; Confirm/Reject reuse the shared `doQueuePendingAction()` (same endpoints + idempotency as the top queue). Hooked into both the initial render and the 4s poll. New theme-matched CSS block.
**Why:** Matthew's ask — a lead only appeared in the top "New widget submissions" queue; the practitioner couldn't review a lead's details in the list below until confirming. Matches the approved design spec `docs/specs/2026-06-27-pending-leads-in-list-and-client-link-gap-design.md` (Piece A). Shipped **unconditionally** — the orphan `hdlv2_ff_pending_in_list` flag seed was removed as dead code in B.9.
**Design choices that matter:**
- Rows are **NOT `.client-row`** → immune to the column-sort comparator + the stalled/status/source filters (both target `.client-row`), so the group stays pinned + always visible (matches the top queue). `enhanceMatchedRows`/`reconcileRows`/`findRowForClient` key on `.client-row` + `clientHash` and so ignore the pending rows.
- **No dedupe risk:** pending leads have no `client_user_id`/`form_progress` row → can never collide with a confirmed client row. On Confirm the next poll drops the pending row and renders the real client row below.
- **Idempotent:** the prior group (head + rows + open details) is wiped before re-insert → the 4s poll never duplicates or flickers it.
**Files:** `class-hdlv2-widget-config.php` (endpoint), `hdlv2-client-list-enhance.js` (render + CSS), `hdl-longevity-v2.php`.
**Risk:** medium (touches the roster render pipeline) — mitigated by non-`.client-row` isolation + idempotent re-render + reuse of the existing action handler.
**Evidence (STBY):** `/widget/leads/pending` returns whitelisted `stage1_data` (lead #91: all 11 answer fields; assertion passed). **Visual browser smoke deferred to Quim** — it needs a logged-in practitioner session and Matthew (prac 122) is the only real practitioner; I did not touch his live account. (Precise QA steps below.)
**Not in scope (documented follow-up):** the spec's *Piece B* (`form_progress.client_user_id` backfill for the invisible-client gap) is a separate latent bug with a LIVE automation-email risk (spec §5) and is **not** part of F3 — left for a separate, gated decision.

## F6 — client-facing "Draft Report" wording (`4fcfb4c`, 0.47.46)

**What:** `staged-form.php` Stage-3 AI-fallback commentary: "Your Draft Trajectory Plan is being generated now" → "Your Draft Report is being generated now."
**Why:** The exact reported line ("Check your inbox for your Draft Trajectory Plan") and the client draft email were already "Draft Report" (0.47.20). This fallback (shown only when the Claude commentary call returns nothing) was the one remaining client-facing draft mislabel. Practitioner-facing "Trajectory Plan" (draft-review email, notify subject) and the **final** deliverable "Trajectory Plan" (Stage-1 "what happens next") are intentionally left.
**Files:** `class-hdlv2-staged-form.php`, `hdl-longevity-v2.php`. **Risk:** none (copy-only).

## F7 — support email (flagged, no change)

`office+matthew@healthdatalab.com` appears **only** in the automation-tier admin settings default (`class-hdlv2-admin-automation-tier.php`) — a dark/disabled feature — **not** on the Stage-3 complete page (which shows no support email). User-facing support addresses are `office@healthdatalab.com` (archive recovery) and `support@healthdatalab.com` (dashboard help) — both `@healthdatalab.com`, mutually consistent. `.com` is the brand/frontend (Netlify) domain; `.net` is the WP backend — both legitimate. Quim's call: **leave `@healthdatalab.com`**. No change made.

---

## Make Stage-1 payload — before/after (proves the contract held)

Captured the **real** outbound payload on STBY in-process (intercepted `pre_http_request` for the Stage-1 webhook + suppressed `wp_mail`, so neither Make nor any email fired; test lead cleaned up).

**Field set (after):** 24 keys —
`report_type, client_name, client_email, client_phone, practitioner_id, practitioner_name, practitioner_email, practitioner_logo_url, practitioner_logo_shape, rate_of_ageing, gauge_url, report_date, stage1_data, form_token, callback_url, callback_secret, timestamp, headline_text, biological_age, chronological_age, strongest_topic, strongest_text, priority_topic, priority_text`.

**Diff vs before (23 keys):**
- `biological_age`: value/format changed `float` → `number_format(...,1)` **string** (e.g. `45.9`, `46.0`). Same source value.
- `chronological_age`: **ADDED** (e.g. `"51"`).
- **No key renamed or removed.** Every field the Make scenario reads is still present with the same name.

Captured sample: `biological_age='55.1'`, `chronological_age='51'` (matches `stage1_data.q1_age`).

---

## Adversarial review

A fresh adversarial reviewer audited the full `git diff bd7e0b0..HEAD`. **Overall verdict: SHIP-ABLE** — no security regression, no crash, no Make-contract break, no Part A/B regression. It confirmed:

| Area | Verdict |
|------|---------|
| Make payload contract | **PASS** — no key renamed/removed; only `biological_age` reformatted + `chronological_age` added; `stage1_data` untouched (24 keys). |
| F1 correctness + load order | **PASS** — helper class loads before client-status; null/age-0 guarded; widget `bio` is a Number. |
| F3 XSS | **PASS** — every lead-derived value `esc()`-escaped (text-node context); only attribute interp is the `(int)` lead id. |
| F3 IDOR / whitelist | **PASS** — endpoint still scoped `WHERE practitioner_user_id`; `stage1_data` allow-list excludes `server_result`/`_safety`. |
| Part A / Part B | **PASS** — nothing touches audio/PII-logging/jobs-IDOR/SSRF/schema/crons; DB stays 3.24. |
| Syntax / `state.colSpan` | **PASS** — `php -l` + `node --check` clean; colSpan always defined (=7). |

**It found 2 real F3 UX defects — both fixed in `0.47.47` (`251c0dd`):**
1. **(MEDIUM) Sort scattered the group.** My "immune to the column-sort comparator" claim was **wrong**: V1's `sortTable()` (`health-data-lab-plugin`) re-appends *every* `<tr>`, not just `.client-row`, so a header sort intermixed the lead rows and scattered the head/detail rows until the next poll (≤60 s). **Fix:** `bindSortRepin()` — a one-time delegated header-click listener that re-pins the group on the next tick (after V1's synchronous re-append). Comment corrected.
2. **(LOW/MED) Open dropdown collapsed on every poll.** `renderPendingLeadsGroup` rebuilt rows collapsed each poll, snapping shut a Stage-1 dropdown mid-read. **Fix:** remember expanded lead IDs before the wipe and re-open them after re-render (mirrors how client detail panels survive `reconcileRows`).

**Non-blocking notes (accepted / documented):**
- `biological_age` flips JSON number→string, so Make's first-payload type cache for that field changes type — mirrors the already-working `rate_of_ageing` string pattern; STBY shares LIVE's Make scenario so it is verifiable before LIVE.
- A practitioner with **zero** confirmed clients has no `.clients-table` (V1 renders an empty-state), so the enhancer doesn't mount and the pending group shows only in the top queue for them — a pre-existing V2-enhancer limitation, not introduced here. (Not applicable to Matthew, who has clients.)
- Stage-2 "AWAKEN" widget paragraphs still use a non-1-dp `bio` in prose — pre-existing, out of F1 scope (prose may stay approximate).

---

## Verified by me vs. needs Quim / LIVE

**Verified on STBY (evidence above):** F1 mechanism (helper + 1-dp string payload, incl. whole-number case); F2 (real payload capture, `chronological_age` present); F3 backend (endpoint returns whitelisted `stage1_data`); F4/F5 confirmed already-correct by code read; F6/F7 copy.
**Needs Quim (browser, per the project's device-QA pattern):**
- **F3 visual smoke:** as a practitioner with ≥1 pending widget lead, open the client list → the "Pending confirmation" group shows at the top; the chevron expands the Stage-1 detail; Confirm turns the lead into a normal client row below (and drops it from both the group and the top queue); Reject removes it; the 4s poll doesn't duplicate/flicker; the stalled/status filters don't hide it; mobile 390px looks right.
- **F1/F2 rendered email + PDF:** confirmed on LIVE during the go-live E2E (STBY can fire Make, but the email/PDF assertion is a LIVE-with-a-test-account check) — the email shows bio age one-decimal (e.g. 45.9) and a populated chronological age, and the attached PDF matches.

**Make-dependency note:** STBY *does* now share the LIVE Make scenario, so a real Stage-1 fire on STBY would hit the shared scenario (email + PDFMonkey). The payload capture above deliberately intercepted the fire to avoid that.

---

## LIVE cutover coordination (gated — NOT executed)

These feedback fixes sit on top of Part A + Part B + the dark iris capture-only work on the parent branch. LIVE is far behind (**0.41.34 / DB 3.13**) and predates all of it, so the go-live is the **master V2 migration** (`docs/plans/V2-LIVE-MIGRATION-CHECKLIST.md`), not a piecemeal push. **Open decision for the cutover:** whether it bundles the dark iris code (flags OFF) or ships feedback+A+B without it — this is Quim/Matthew's call at go-live.
**Hard gates before any LIVE write:** (1) Part A device QA passed; (2) a verified restorable LIVE backup exists (code + DB) — LIVE backups were disabled, sort this first; (3) Matthew's explicit "push to live" + sign-off.
