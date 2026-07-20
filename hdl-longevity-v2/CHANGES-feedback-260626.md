# CHANGES — client/practitioner feedback fixes (F1–F7)

Branch: `fix/feedback-260626` (off `bd7e0b0`, itself the tip of `fix/stage2-audio-recovery-and-audit-hardening` — Part A audio + Part B audit + iris capture-only, all dark/on-hold).
Scope: **STBY only.** Never LIVE without explicit "push to live" + the three hard gates (see bottom).
Start state: STBY **0.47.42 / DB 3.24**, healthy. End state: STBY **0.47.49 / DB 3.24** (no schema change). [Later follow-ups: F3 de-dup .48, action-queue accordion fix .49 — see bottom.]
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
**Evidence (STBY):** `/widget/leads/pending` returns whitelisted `stage1_data` (lead #91: all 11 answer fields; assertion passed). **Visual browser smoke — DONE (2026-07-02):** spun up a throwaway practitioner (id 378) + 1 confirmed client (so V1 renders `.clients-table`) + 2 pending leads (one with detail, one without) + a `prac_login` token; drove Playwright to `/clients/`. Confirmed: the "Pending confirmation (2)" group renders at the top of the list (teal head, amber PENDING badges, Confirm/Reject on each row); Ada's dropdown shows contact + rate + age/sex + all 9 mapped Stage-1 answers; Grace's dropdown degrades gracefully ("No Stage-1 answer detail was captured for this lead."); the confirmed client renders as a separate V2-only row below; both surfaces (top queue + main list) show the leads. Screenshots captured. **All test data torn down afterwards (users + leads + rows deleted; STBY verified clean).** I did NOT click Confirm/Reject in-browser (a real Confirm fires the shared LIVE Make scenario + email — my eval-level suppression doesn't apply to browser clicks); their render + wiring are verified by the endpoint test + the shared-handler review. (One test-harness artifact: the magic-login session lacked a valid nonce for the `/dashboard/version` poll → harmless 403s; the initial data fetches succeeded, which is why the group rendered.)
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
**F3 visual smoke — DONE by me (2026-07-02):** throwaway practitioner on STBY, group + dropdowns (with-detail + empty-guard) + Confirm/Reject render all confirmed via Playwright screenshots; test data torn down. Not exercised in-browser (deliberately, to avoid the shared Make/email side effects): the actual Confirm→client-row transition and the 4s-poll no-flicker/no-collapse behaviour — those remain worth a glance during Quim's normal-login QA, but the mechanisms are verified by the endpoint test + adversarial review.
**Needs Quim (browser, per the project's device-QA pattern):**
- **F3 mobile 390px** + a live Confirm/Reject on a real (normally-logged-in) practitioner session.
- **F1/F2 rendered email + PDF:** confirmed on LIVE during the go-live E2E (STBY can fire Make, but the email/PDF assertion is a LIVE-with-a-test-account check) — the email shows bio age one-decimal (e.g. 45.9) and a populated chronological age, and the attached PDF matches.

**Make-dependency note:** STBY *does* now share the LIVE Make scenario, so a real Stage-1 fire on STBY would hit the shared scenario (email + PDFMonkey). The payload capture above deliberately intercepted the fire to avoid that.

---

## LIVE cutover coordination (gated — NOT executed)

These feedback fixes sit on top of Part A + Part B + the dark iris capture-only work on the parent branch. LIVE is far behind (**0.41.34 / DB 3.13**) and predates all of it, so the go-live is the **master V2 migration** (`docs/plans/V2-LIVE-MIGRATION-CHECKLIST.md`), not a piecemeal push. **Open decision for the cutover:** whether it bundles the dark iris code (flags OFF) or ships feedback+A+B without it — this is Quim/Matthew's call at go-live.
**Hard gates before any LIVE write:** (1) Part A device QA passed; (2) a verified restorable LIVE backup exists (code + DB) — LIVE backups were disabled, sort this first; (3) Matthew's explicit "push to live" + sign-off.

---

## F3 follow-up — de-duplicate: move view-details into the top strip (0.47.48)

**What:** F3 put a "Pending confirmation" group of unconfirmed leads in the MAIN client list, but those same leads already show in the top "What needs you today → STAGE 1: NEW WIDGET SUBMISSIONS" strip — a duplicate. This change **removes the main-list group entirely** and **moves the view-details capability into the top strip**: each lead there now has a "View details" toggle that expands an inline panel with the same whitelisted Stage-1 data (name, email, rate, age, sex, the 9 answers). A pending lead now lives in exactly ONE place (the strip) until confirmed; confirmed clients appear in the main list; nothing shows twice.
**Why:** Matthew feedback — the two-places duplicate. (The relocation supersedes F3's main-list placement; F3's *endpoint* — whitelisted `stage1_data` — is reused unchanged.)
**How:**
- **Removed** (dead after de-dup): `renderPendingLeadsGroup`, `bindSortRepin`, `buildPendingGroupHeadRow`, `buildPendingLeadRow`, `injectPendingExpandButton`, `togglePendingDetail`, `bindPendingLeadListActions` + their 2 render call-sites + the main-list group CSS (176 lines net removed).
- **Kept + reused:** `buildPendingLeadDetailHTML` (the whitelisted detail panel) — now rendered inside each strip item.
- **Added:** `_openPendingDetails` module object (open panels tracked OUTSIDE the DOM so they survive `renderActionQueue`'s wholesale per-poll `innerHTML` rebuild — this is the correct fix for the "dropdown collapses on poll" class of bug); a "View details" button (`data-aq="lead-details"`) + inline `.hdlv2-aq-lead-detail` panel in `renderPendingLeadAQRow`; a toggle handler in `bindPendingLeadQueueActions`; strip CSS (`.hdlv2-aq-btn-pending-details`, `.hdlv2-aq-lead-detail`).
- **Unchanged:** the backend `/widget/leads/pending` endpoint (whitelist + `WHERE practitioner_user_id` ownership intact); the strip's Confirm/Reject (pre-existing `doQueuePendingAction`, untouched).
**Files:** `assets/js/hdlv2-client-list-enhance.js`, `hdl-longevity-v2.php`. **Risk:** low — front-end relocation; no backend/schema/funnel change; F1/F2/F6 + Part A/B files untouched.
**Evidence (STBY, throwaway practitioner 380 + 1 confirmed client + 2 pending leads Ada/Grace, then torn down clean):**
- DOM: main-list pending group = **0** rows (removed); strip = 2 lead items, 2 "View details", 2 (hidden) detail panels, 2 Confirm + 2 Reject; main list = 1 V2-only confirmed client. **No duplication.**
- Screenshots: strip with both leads expanded — Ada shows contact + rate 0.90× + age 54 + sex female + all 9 Stage-1 answers; Grace (no `stage1_data`) shows the graceful "No Stage-1 answer detail was captured for this lead." guard. Main-list screenshot shows only the confirmed client, no pending group.
- Confirm→main-list is demonstrated by the confirmed client already sitting in the list (created via the same endpoint the strip Confirm calls); Confirm/Reject were not clicked in-browser to avoid firing the shared LIVE Make scenario + email (their handler is the unchanged pre-F3 `doQueuePendingAction`).
- `node --check` + `php -l` clean; md5-verified on STBY; `/health` 0.47.48, db_in_sync, 16/16 tables.
- **Adversarial pass over the diff: SAFE TO SHIP.** (F3-dedup) All six checked areas PASS — (1) no duplication (renderPendingLeadsGroup + call sites gone; nothing injects pending rows into the table; leads render only in the strip); (2) no dangling refs to the 7 removed functions or removed CSS classes; (3) no XSS (all lead-derived values `esc()`-escaped); (4) open-state survives the strip's per-poll `innerHTML` rebuild (read from `_openPendingDetails`) with no double-bind; (5) no regression — only `client-list-enhance.js` + version changed, F1/F2/F6 + Part A/B untouched, Confirm/Reject still route through the unchanged `doQueuePendingAction`; (6) `node --check` + `php -l` clean, version bump consistent. Two non-blocking nits, both **pre-existing (not introduced here)**: the strip meta line interpolates `visitor_age` unescaped (server-cast to `int` → not exploitable; the same value is escaped in the detail panel), and `_openPendingDetails` never prunes actioned-lead keys (bounded, harmless — ids never reused).

---

## Action-queue accordion — stop the auto-reopen (0.47.49)

**Reported:** the "What needs you today" banner re-opens itself after the practitioner closes it — annoying, not wanted.
**Root cause (confirmed by code trace):** it was never a plain accordion. `toggleActionQueue` stored a **content hash + date**; `applyCollapseState` ran on every render (4s version-poll on any queued-client timestamp bump, `rest_get_version`; + 60s fallback) and **force-expanded** whenever `computeQueueHash` (each queued client's `latest_event_at` + each pending lead's `created_at`) differed from the stored hash. So essentially any client activity re-opened a deliberately-closed banner. Intentional ("un-dismiss on new event") but too aggressive.
**Fix (Option A — sticky, day-scoped collapse):** dropped the content-hash entirely. The collapse is now persisted as `{date}` only and **honoured for the whole day** across polls + reloads, regardless of queue activity; it resets to expanded only at local midnight (matching the banner's "today" purpose). The header **count pill still updates live while collapsed**, so new work is surfaced without forcing the banner open. Removed the now-dead `computeQueueHash`; `getCollapseState`/`setCollapseState`/`applyCollapseState`/`toggleActionQueue` simplified; `applyCollapseState()` no longer takes a list.
**Files:** `assets/js/hdlv2-client-list-enhance.js`, `hdl-longevity-v2.php`. **Risk:** low — collapse-state logic only; no backend/funnel/schema change; Confirm/Reject/View-details untouched.
**Evidence (STBY, throwaway practitioner, torn down clean):** collapse → localStorage = `{"date":"2026-07-02"}` (no hash); then **added a 3rd pending lead + reloaded** → banner **stayed collapsed** (old code would have re-expanded on the hash change) and the count pill updated to **3**. `node --check`/`php -l` clean; md5-verified; `/health` 0.47.49, db_in_sync, 16/16.

---

## Pending-lead View-details: correct labels + answer prose (0.47.50, `3ec2ddc`)

**Found by the 2026-07-03 full smoke test** (3-agent: practitioner+client browser flows, backend audit, go-live readiness — all PASS otherwise; see memory `project_smoke_test_2026_07_03`).
**Bug:** the strip panel's JS label map mislabelled **6 of 9** Stage-1 answers (q4 "Sitting", q5 "Sleep", q6 "Smoking", q7 "Alcohol", q8 "Diet", q9 "Social" — vs the widget's real q4 Cardiovascular capacity, q5 Functional strength, q6 Sleep, q7 Smoking, q8 Social connection, q9 Diet) **and** would render raw `a–e` letters for real widget leads (the widget submits letters; only my F3 test seeds contained full text — which is exactly why F3 verification missed it: the seeded values coincidentally matched the wrong labels, and every real STBY lead had null `stage1_data`).
**Fix (single wording source):** new public `HDLV2_Client_Status::s1_pending_display_pairs()` builds ordered `{label, value}` pairs from the SAME tables the confirmed-client Stage-1 tab uses (`s1_option_label` for Q3–Q9 prose, `s1_q2b_label` for the Q2b code, the tab's exact label nouns incl. "(Qn)" suffixes). `/widget/leads/pending` now emits `stage1_display` alongside the whitelisted raw `stage1_data`; the JS renders the pairs and its wrong map is **deleted** (no dead code). Unknown letters ("z"→"Z") and legacy free-text pass through raw so captured data is never hidden. Whitelist (no `server_result`/`_safety`) unchanged; endpoint stays own-practitioner-scoped.
**Files:** `class-hdlv2-client-status.php`, `class-hdlv2-widget-config.php`, `hdlv2-client-list-enhance.js`, `hdl-longevity-v2.php`.
**Evidence (STBY):** in-process endpoint test — seeded a real-format lead (letters + `q2b:'apple'` + 2 fallback edge values) → **8/8 assertions pass** ("silhouette #4 · Mostly middle", "Zone-2 cardio (Q3) = About 30–60 minutes per week", …, legacy text passthrough, "Z", no old labels, whitelist intact); Playwright render as practitioner Bob shows the correct pairs; empty-guard lead (Zeus) still graceful; no horizontal overflow; md5-verified; `/health` 0.47.50. Test lead torn down.

---

## Flight-plan prompt: forbid invented clinical figures/names (0.47.51, `b988306`)

**Found by the same smoke test:** a May-generated plan's Journey Assistance addressed the client by the wrong name ("James") and cited a **fabricated biological age (43.2)** contradicting every real surface (55–56, pace 1.10–1.13).
**Root cause (live gap, not just stale data):** the flight-plan system prompt — unlike the draft/final report prompts, which all carry "you never invent clinical markers" whitelist rules — had **no guard against fabricating numbers**; `journey_assistance` is free prose.
**Fix:** one RULES line — numeric clinical figures (bio age, pace, BP, BMI, weight, any measurement) may only be stated if the EXACT figure appears in the input, quoted verbatim; otherwise speak qualitatively; client name likewise input-only. Prompt-text only; no payload/funnel/schema change.
**Evidence:** guard grep-confirmed in the deployed file; md5-verified; `/health` 0.47.51. (Not exercised with a live Claude call — plan generation is an AI burn; the next real weekly generation is the E2E check, and the go-live funnel E2E must include reading the generated plan copy for numeric consistency.)

---

## Smoke-test findings resolved WITHOUT code (2026-07-03)

- **Cross-surface 1.10× vs 1.13× (Kim Smoke Test) — legacy data, NOT a live bug.** Her April reports have no baked `calc_snapshot` (predate v0.46.28), so `/my-report/` takes its documented legacy fallback (live recompute → 1.13/56.3) while the stored narrative + `rate_snapshot` say 1.10. Every report generated since v0.46.28 freezes the snapshot and renders byte-identical to the PDF. The dashboard "baseline" tile reads `reports.rate_snapshot` (generation-time truth).
- **Post SMTP 43% failure rate — 100% test noise.** Every failure is "Invalid To address" for synthetic `*.test` addresses from automated diagnostics (`irc_test_*`, `diag_*`); 139/139 real sends OK.
- **Dead links:** 44 unique same-origin links checked across `/clients/` + `/consultation/` as a logged-in practitioner — zero 4xx/5xx (client pages already had zero failed requests in the smoke run).

**Left for humans (deliberately untouched):** `hdlv2_ff_stalled_filter` still ON on STBY (demo leftover — flip needs Quim's say-so; auto-mode correctly blocked me); LIVE public-audio relocation (needs LIVE-write approval); `/consultation/` page has an empty WP page title (cosmetic; changing `post_title` could surface a theme H1 — Quim's call); `?invite=`/`?prac_login=` magic links are suppressed while another session is active (safe-by-design, but worth knowing during QA); `/my-report/` offers no on-page PDF download (email-delivery by design — feature decision, not a bug).

---

## Client Tools audit fixes A+B+D (0.47.52)

**Found by the 2026-07-04 Client Tools 5-tab E2E + code audit** (all 5 popup tabs PASS in browser; see memory `project_client_tools_e2e_2026_07_04`).

**Fix A — V1 practitioner-client link silently never created (compatibility.php).** `create_practitioner_client_link()` SELECTed and INSERTed a `client_user_id` column on `wp_health_tracker_practitioner_clients` — a column V1's schema has never had (the table keys on email hashes; verified against V1's CREATE TABLE + every ALTER). Both queries died with `ERROR 1054`, `$wpdb->insert`'s result was never checked, and the function returned `true` anyway — so every widget/lead-confirmed client got NO V1 link row (masked by the V2 form_progress splice on the dashboard). Now: requires both userdata lookups up-front, dedupes on `(practitioner_user_id OR practitioner_email_hash) AND client_email_hash` (pre-migration NULL-practitioner rows still dedupe), backfills `practitioner_user_id` on the update path, checks the insert result and logs `$wpdb->last_error` on failure (returns false). Soft-delete refuse-to-restore behaviour unchanged. Also deleted `get_practitioner_for_client()` — same broken WHERE clause, zero callers repo-wide.

**Fix B — Widget Settings demo link read a config field PHP never provides (hdlv2-dashboard.js:746).** Used `CFG.site_url`, which `maybe_enqueue_dashboard_js` never localizes; it degraded to a root-relative URL that only worked because the dashboard runs on the WP origin. Now uses the already-localized `CFG.widget_page_url` (root-relative fallback kept).

**Fix D — 262 lines of unreachable code deleted (hdlv2-dashboard.js).** The "V2 CLIENT PROGRESS + WHY GATE RELEASE" block (`loadV2Clients`/`renderV2Clients`/`statusBadgeHtml`/`handleQuickAction`/`pollFlightPlanJob`/`releaseWhy` + `statusData`) rendered into `#hdlv2-client-list`, an element no popup tab emits; its only entry point was a self-referential call inside `releaseWhy`. It even referenced an undefined `restBase` (would have thrown if ever reached). The /clients/ roster surface (`hdlv2-client-list-enhance.js`) owns those interactions with its own same-named functions — unaffected. No behaviour change.

**Files:** `class-hdlv2-compatibility.php`, `hdlv2-dashboard.js`, `hdl-longevity-v2.php` (0.47.52; no DB change).
**Evidence (STBY):** pre-deploy md5 = git HEAD (no drift); post-deploy md5 match + `/health` 0.47.52; served JS contains `widget_page_url`, zero `loadV2Clients`. Confirm E2E re-run: see below.
**Confirm E2E re-run on 0.47.52 (browser, throwaway practitioner 387, torn down):** Confirm on a seeded pending lead → `wp_health_tracker_practitioner_clients` row NOW CREATED (practitioner_user_id=387, client_name correct, both email hashes byte-match independently computed sha256 values, deleted_at NULL) alongside the unchanged chain (lead→confirmed, client user created, form_progress stage 2, magic-link email success=1). Second invocation of the fixed function → update path, still 1 row (dedup works). Demo link `href` attribute now absolute (`https://stby.healthdatalab.net/rate-of-ageing-widget/`). All 5 tabs cycled post-Fix-D with zero console errors. Zero test residue after teardown.
