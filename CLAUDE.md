# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Architecture

Two-domain platform for longevity health assessments:

- **Frontend**: Static HTML site on Netlify at **healthdatalab.com** — vanilla JS, Tailwind CSS v4 (CDN), GSAP animations
- **Backend**: WordPress on Vultr VPS at **healthdatalab.net** (`/var/www/html/`)
- **V1 Plugin**: `health-data-lab-plugin/` — consumer provisioning, practitioner management, health/longevity forms, credit system
- **V2 Plugin**: `hdl-longevity-v2/` — 3-stage longevity assessment funnel, AI coaching, flight plans, weekly check-ins. Runs alongside V1.
- **Serverless**: `netlify/functions/` — Stripe checkout, webhooks, email, seat counting
- **Edge**: `netlify/edge-functions/geo-currency.js` — geo-IP currency detection (USD/EUR/GBP/CHF/CAD/AUD)
- **Staging**: `stby.healthdatalab.net` — STBY clone of LIVE for V2 testing (Brevo disabled, isolated keys, no OpenAI/Make.com)

V2 boots at `plugins_loaded` priority 20 (after V1 at priority 10) so `HDL_VERSION` is available. V2 also hooks `init` at priority 1 to auto-login users via `?invite=TOKEN` magic links, *before* UM content restriction runs at `the_posts`.

## Development

```bash
npm install                    # Stripe + Nodemailer deps (for Netlify Functions)
netlify dev                    # Local dev server with functions + edge functions
```

- **No build step** — static HTML served from repo root
- **No test framework** — manual testing only (see `docs/TESTING-GUIDE.md`, `hdl-longevity-v2/TESTING.md`)
- **Local WordPress**: `/Volumes/Media/LocalSites/healthdatalab-local/app/public/` (Local by Flywheel). Must mirror production exactly.
- **Cache busting**: Always bump `?v=N` on modified CSS/JS files before pushing

## Frontend

- **Pages**: `index.html` (landing), `personal-report.html` (Stripe checkout), `pricing.html` (multi-currency), `about.html`, `course.html`, `help-centre.html`, `terms.html`, `privacy.html`
- **CSS**: `assets/css/styles.css` (custom design tokens via CSS variables) + Tailwind v4 utility classes via `@tailwindcss/browser@4` CDN
- **JS**: `assets/js/main.js` (nav, modals, checkout, GSAP animations), `hdl-trajectory-chart-hero.js`, `hdl-radar-chart-hero.js`, `practice-assessment.js` (interactive carousel)
- **Routing**: `netlify.toml` rewrites clean URLs → `.html` files (200 status)

## Netlify Functions

| Function | Purpose |
|----------|---------|
| `create-checkout.js` | Creates Stripe Checkout sessions with multi-currency pricing, installments, trials |
| `stripe-webhook.js` | Handles `checkout.session.completed`, `invoice.paid`, `subscription.deleted`; provisions consumers on WP |
| `send-email.js` | Contact form handler via Brevo SMTP; honeypot anti-bot; 3 emails/min rate limit |
| `seat-count.js` | Returns available "Super Early" course seats (25 total) |

## WordPress V1 Plugin (`health-data-lab-plugin/`)

Main loader: `health-data-lab.php` — loads rate limiter (line 146), consumer provisioner (line 177).

Key subsystems:
- **`includes/api/class-consumer-provisioner.php`** — REST endpoints: `/consumer-provision`, `/free-report-provision`, `/annual-renewal`. User creation, credits, magic link emails.
- **`includes/core/class-health-tracker.php`** — Main form logic and practitioner dashboard
- **`includes/core/class-health-tracker-practitioner.php`** — Practitioner tools: invite clients, manage relationships, email templates
- **`includes/core/class-client-linker.php`** — Client-practitioner relationship management (soft-delete model)
- **`includes/credits/class-credit-manager.php`** — Credit allocation. Practitioners have 1000-credit pool; clients draw from practitioner pool via `resolve_credit_owner()`. Consumers (Stripe) have individual credits.
- **`includes/forms/`** — `class-health-form.php` + `health-form-raw.php` (health tracking), `class-longevity-form.php` + `longevity-form-raw.php` (longevity assessment). Shortcode-rendered.
- **`includes/security/class-rate-limiter.php`** — IP-based rate limiting via WP transients

## WordPress V2 Plugin (`hdl-longevity-v2/`)

Main loader: `hdl-longevity-v2.php` v0.22.8 / DB schema v2.6. Tables prefixed `wp_hdlv2_*` to avoid collision with V1's `wp_health_tracker_*`. REST namespace: `hdl-v2/v1/*`.

Sprint-based directory structure under `includes/`:
- **`sprint-1/`** — Stage 1 embeddable widget (9-question mini-form, pace-of-ageing gauge, widget config + invite tokens)
- **`sprint-2/`** — Stage 2 WHY profiling (`class-hdlv2-staged-form.php` 13+ REST endpoints, Claude distillation, rate calculator, email templates)
- **`sprint-2c/`** — Stage 3 final report (draft report, practitioner consultation, my-report client view, trajectory SVG, PDFMonkey integration)
- **`sprint-3/`** — Audio transcription services (server fallback only — see Audio Strategy below)
- **`sprint-4/`** — Client dashboard (timeline, weekly check-ins, status tracking, practitioner dashboard shortcode)
- **`sprint-5/`** — Flight Plan (weekly action plan generated by Claude, PDFMonkey webhook callback)

Cross-cutting:
- **`security/`** — V2-specific: `class-hdlv2-rate-limiter.php` (transient-backed counter), `class-hdlv2-rate-limit-{policy,middleware,status}.php` (route→tier map, REST pre/post-dispatch hooks, `/rate-limit/status` endpoint), `class-hdlv2-idempotency.php` (Stripe-pattern client-supplied keys for AI-burn callbacks)
- **`class-hdlv2-context-builder.php`** — Builds Claude prompts from client history
- **`class-hdlv2-job-queue.php`** — Async job runner for long-running AI calls (5-minute cron)
- **`class-hdlv2-compatibility.php`** — V1 ↔ V2 data sync glue (e.g., V2 client list spliced into V1 `/clients/` page)

Key services: `sprint-2/class-hdlv2-ai-service.php` (Claude API), `sprint-3/class-hdlv2-audio-service.php` (OpenAI Whisper server), `sprint-3/class-hdlv2-deepgram-service.php` (Deepgram fallback), `sprint-2/class-hdlv2-rate-calculator.php` (pace-of-ageing scoring).

Embeddable widget: `widget/hdl-lead-magnet.js` — can be embedded on external practitioner sites.

### Audio Transcription Strategy (3-tier fallback)

Stage 2 WHY audio + Stage 4 check-ins:
1. **Browser Web Speech API** — fastest path, fails silently in Chrome incognito (watchdog timer detects no-result and falls through)
2. **Client-side Whisper** — `assets/js/hdlv2-transcriber.js` + `hdlv2-transcriber.worker.js` (Transformers.js + whisper-small + WebGPU, falls back to WASM). Feature-detects WebGPU before pipeline init. Beam search + audio preprocessing for accuracy. Zero server cost.
3. **Server fallback** — POST audio blob to `hdl-v2/v1/audio/transcribe` → OpenAI Whisper or Deepgram (`sprint-3/`). Used only when client-side fails.

Component: `assets/js/hdlv2-audio-component.js` orchestrates the fallback chain and posts `/audio/client-error` telemetry on failures.

## Key Flows

### Consumer Provisioning (Stripe → WP)
```
personal-report.html → create-checkout.js → Stripe Checkout
  → checkout.session.completed → stripe-webhook.js
  → POST /wp-json/hdl/v1/consumer-provision
  → class-consumer-provisioner.php: create user, grant credits, send magic link email
```

### Free Report (Partner Sites)
```
altituding.com/report → brevo-submit.js → Brevo contact (critical path)
  → POST /wp-json/hdl/v1/free-report-provision (non-blocking)
  → Create um_client, 1 longevity credit, co-branded magic link email
  → Idempotent via hdl_free_report_provisioned user meta
```

### V2 Assessment Stages
```
Stage 1: Widget → 9 questions → gauge result → client record + emails + Stage 1 PDF (PDFMonkey via Make.com)
Stage 2: WHY → 3 multi-select + audio → 3-tier transcription → Claude distillation → practitioner "Release" gate
Stage 3: Full detail → 5-section wizard → Claude draft report → practitioner consultation
       → finalised report + PDFMonkey Final Report PDF (via Make.com webhook)
Stage 4: Weekly check-ins (audio or text) → status timeline → triggers next-week Flight Plan
Stage 5: Flight Plan (weekly Claude-generated action plan) → PDFMonkey PDF via Make.com
```

### Tiers & Roles
- `consumer_single` / `consumer_annual` — valid tier values
- Practitioner pref `yes` → role `um_consumer`, linked to practitioner, 2 credits (health + longevity)
- Practitioner pref `no` → role `um_client`, no link, 1 longevity credit

## Deployment

**Netlify (frontend + functions)**: Auto-deploy on `git push` to `main`.

**WordPress plugin files**: GitHub Gist + wget to server. **Always ask user before deploying. NEVER push to LIVE without explicit "push to live" authorization — STBY is the testbed.**
```bash
# 1. Update Gist
gh gist edit <GIST_ID> -f <filename> <local_path>
# 2. Deploy to server
ssh healthdatalab.net 'wget -O /var/www/html/wp-content/plugins/health-data-lab-plugin/.../<filename> "https://gist.githubusercontent.com/raw/<GIST_ID>/<filename>"'
```

**Servers**:
- **LIVE**: `ssh healthdatalab.net` — Vultr VPS, port 8443, IP `108.61.172.199`, key `~/.ssh/id_ed25519`
- **STBY**: `stby.healthdatalab.net` — clone of LIVE for V2 testing. Brevo + email disabled, HDL keys rotated, has Anthropic key but NOT OpenAI/Make.com. IP `136.244.68.235`, port 8443, direct LE cert.

**V2 phase tracking**: `hdl-longevity-v2/PHASE*-CHANGES-FOR-LIVE.md` files document each phase's STBY → LIVE migration. `V2-LIVE-MIGRATION-CHECKLIST.md` is the master go-live checklist (open it when user says "ready to go live"). PDFMonkey templates mirrored in `PDFMONKEY-{FINAL-REPORT,STAGE1}-{HTML,CSS,DYNAMIC-JSON}.md` — edit these first, then user pastes into PDFMonkey/Make.com UIs.

### Known Gists
| Gist ID | File |
|---------|------|
| `70b1b85de86c1d013d58eac1199d5e86` | `class-consumer-provisioner.php` |
| `6c4f2cff09d0b93b0395ea6cea70795c` | `class-health-tracker-practitioner.php` |
| `8410d9d43252ac8a66d2b25480a80025` | `class-user-management.php` |
| `3958fea30c77faa40f071e7162a8eaa2` | `class-client-linker.php` |
| `d8dc28ca026e4e5de3a4574d13be5e2c` | `health-form-raw.php` |
| `5f1e5e5d07a13aed7d197f2992dbfd46` | `longevity-form-raw.php` |
| `4a5916a0b89c76250963e537be713b54` | `class-health-form.php` |
| `5062a652f3bd58ab295978ffe695c682` | `class-longevity-form.php` |
| `6ec9205d676814a05d56d15b462c847c` | `class-health-tracker.php` |
| `35a469f7551a4f7f7a7f7ddf3963514c` | `class-credit-manager.php` |
| `32bdcce4afcbd929be2131247977ce2b` | `class-health-form-credit-integration.php` |
| `38434d15b567efe284cf3c32b9dc57f2` | `class-longevity-form-credit-integration.php` |
| `2936cc3b962704c4863e44dc1c1abe72` | `class-longevity-webhook-handler.php` |
| `aae7ed481850c0be97ab1e9b873ac1c9` | `class-email-template-components.php` |

## Environment Variables

**Netlify (healthdatalab.com)**: `STRIPE_SK`, `STRIPE_WEBHOOK_SECRET`, `WP_SITE_URL`, `WP_CONSUMER_PROVISION_KEY`, `BREVO_SMTP_LOGIN`, `BREVO_SMTP_KEY`

**Netlify (altituding.com)**: `WP_SITE_URL`, `WP_FREE_REPORT_PROVISION_KEY`, `BREVO_API_KEY`, `BREVO_LIST_ID`

**WordPress wp-config.php**:
- V1: `HDL_CONSUMER_PROVISION_KEY`, `HDL_FREE_REPORT_PROVISION_KEY`, `HDL_MAKE_LONGEVITY_URL`, `HDL_MAKE_HEALTH_URL`
- V2 AI keys: `HDLV2_ANTHROPIC_API_KEY`, `HDLV2_OPENAI_API_KEY`, `HDLV2_DEEPGRAM_API_KEY`
- V2 Make.com webhooks: `HDLV2_MAKE_STAGE1_PDF`, `HDLV2_MAKE_STAGE2_WHY`, `HDLV2_MAKE_DRAFT_REPORT`, `HDLV2_MAKE_FINAL_REPORT`, `HDLV2_MAKE_FLIGHT_PDF`, `HDLV2_MAKE_CALLBACK_SECRET` (Bearer token for inbound PDFMonkey callbacks)

## Security

- **API auth**: `X-HDL-Provision-Key` / `X-HDL-Free-Report-Key` headers validated against wp-config constants
- **V1 rate limiting**: `HDL_Rate_Limiter` — 10/hour provisioning, 5/15min auto-login, 5/hour free report
- **V2 rate limiting**: route→tier policy (`class-hdlv2-rate-limit-policy.php`) wired into REST pre-dispatch; client-side awareness module (`hdlv2-rate-limit.js`) reads `/rate-limit/status` to surface limits in UI
- **V2 idempotency**: client-supplied keys (Stripe pattern) on AI-burn endpoints to prevent duplicate Claude/OpenAI charges on retry. `class-hdlv2-idempotency.php` normalises `WP_Error` → `WP_REST_Response`.
- **Token hashing**: HMAC-SHA256 for magic link tokens (raw token only in email URL); `?invite=TOKEN` matches `/^[a-f0-9]{64}$/` regex before lookup
- **Input validation**: Tier whitelist, session ID regex, practitioner pref normalization, source whitelist
- **CSP**: Configured in `netlify.toml` — allows Stripe, Google Analytics, jsdelivr, cdnjs (and `cdn.jsdelivr.net` for Transformers.js Whisper model)
- **Session auth**: Session-only cookies on magic link login (no persistent tokens)
- **Response redaction**: No `user_id` or `new_user` in provisioning API responses
- **Pre-launch audit**: `.planning/SCALE-AND-SECURITY-PLAN-2026-04.md` — 10-point security checklist for V2 go-live

## Change Log
| Date | Change | Files |
|------|--------|-------|
| 2026-04-27 | V2 v0.22.22 / DB v2.8, STBY-only — Round 1 chart-audit fixes for Effort vs Outcomes: **(1)** New Phase H migration adds `rate_snapshot DECIMAL(4,2)` column to `hdlv2_reports`. **(2)** Final Report `generate()` now writes the rate to that column at write-time so each report has its own canonical rate value (previously all reports re-derived from the same form_progress, producing a misleadingly flat outcome line once a client had multiple reports). **(3)** `rest_get_effort_outcomes` reads `rate_snapshot` first; falls back to re-derive via `HDLV2_Rate_Calculator::calculate_full` for legacy rows where the column is NULL. **(4)** Chart y1 (rate) axis is now dynamic — extends `min`/`max` to fit actual data, no more silent clipping at hard-coded 1.30. Adherence axis stays 0–100 (correct fixed range). Verified on STBY: legacy outcome (kim's pre-v0.22.22 final report) still returns rate 1.17 via re-derive fallback; new reports going forward will store the snapshot. | `class-hdlv2-activator.php`, `class-hdlv2-final-report.php`, `class-hdlv2-client-status.php`, `hdlv2-client-list-enhance.js`, `hdl-longevity-v2.php` |
| 2026-04-27 | V2 v0.22.21, STBY-only — prefill auto-save fix: `s1Prefill()` now writes the mapped value into `formData[fieldName]` as a side effect, so the next autoSave (Next button or any field change) persists the pre-filled value. Without this, a client who opened Stage 3 and clicked Next without touching the pre-filled fields would lose the prefill on server-side save. | `hdlv2-staged-form.js`, `hdl-longevity-v2.php` |
| 2026-04-27 | V2 v0.22.20, STBY-only — two practitioner-feedback fixes: **(1)** Removed V1 invite tab from V2's "Client Tools" popup. The V1 `#invite-client-form` is hidden via `display:none` (DOM stays so V1's success/send-form handlers keep working on existing rows). New tab order: Sent Invites (default) · Widget Settings · Embed Code. `TAB_IDS` array updated; `switchPopupTab('widget-invites')` called on init so the default panel is visible on open. **(2)** Stage 3 duplicate-question fix — sleep, smoking, social, diet pre-fill from Stage 1 q6/q7/q8/q9 via new `S1_TO_S3_DEFAULTS` deterministic map + `s1Prefill()` helper. Stage 3 option labels updated to V1 longevity-form descriptive style (e.g. "Less than 4 hours (severely insufficient)"). New `prefillHint()` shown above pre-filled sections — no icons, V2 theme. Cognitive Activity is NOT a duplicate (no Stage 1 question) — left untouched. | `hdlv2-dashboard.js`, `hdlv2-staged-form.js`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 Phase 13 follow-up — transaction rollback safety (v0.22.19, STBY-only): the v0.22.18 transaction wrap on `calculate_adherence()` had no rollback path. If anything inside the block threw (DB lock timeout, schema mismatch, missing class), the connection would hold row locks until end-of-request. Now wrapped in `try { ... COMMIT } catch ( \Throwable $e ) { ROLLBACK + error_log }`. Catch swallows the throwable rather than re-raising — `rest_tick()` already persisted the user-visible tick before this runs, so a failed adherence recompute should not bubble up as a 500. The next tick auto-corrects any drift. Five lines added; same E2E behaviour for the happy path. | `class-hdlv2-flight-plan.php`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 round-1 P0/P1 fixes from post-deployment audit (v0.22.18 / DB v2.7, STBY-only): **(1)** 8 composite indexes added via Phase G migration — closes the `/dashboard/version` perf bomb (EXPLAIN now shows "Select tables optimized away") and Phase A's 4 unindexed COUNTs on `flight_plan_ticks`. **(2)** New `HDLV2_Idempotency::wrap_ai()` with 300-second TTL applied to `/form/generate-report`, `/consultation/finalise`, `/flight-plan/{id}/generate`, `/checkin/submit` (newly wrapped — was firing Claude unguarded), `/consultation/organise-notes` (newly wrapped). Default 30s TTL kept for non-AI endpoints. **(3)** New `HDLV2_Webhook_Monitor` class: every Make.com webhook (Stage 1 PDF, Stage 2 WHY, Final Report, Flight Plan PDF) now goes through `HDLV2_Webhook_Monitor::fire()` with blocking=true + 10s timeout, structured `error_log` prefix, rolling 24h failure log in `hdlv2_webhook_failures` option, admin notice when failures > 0 in last hour. **(4)** Phase D `/effort-outcomes` paginated: 52-week adherence cap + 12-report outcome cap. **(5)** Phase A `calculate_adherence()` now wraps the 4 COUNTs + UPDATE + UPSERT in a transaction (BEGIN/COMMIT) so concurrent ticks don't see phantom counts. **(6)** New public `GET /hdl-v2/v1/health` endpoint returns 200/`status:"ok"` when v1_active + db_in_sync + 15/15 tables present, 503/`status:"degraded"` otherwise — designed as the CI/CD smoke gate. | `class-hdlv2-activator.php`, `class-hdlv2-idempotency.php`, `class-hdlv2-webhook-monitor.php` (new), `class-hdlv2-rate-limit-policy.php`, `class-hdlv2-staged-form.php`, `class-hdlv2-consultation.php`, `class-hdlv2-final-report.php`, `class-hdlv2-checkin.php`, `class-hdlv2-client-status.php`, `class-hdlv2-flight-plan.php`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 Phase D — Effort vs Outcomes "money chart" on practitioner dashboard (v0.22.16, STBY-only): new tab **Progress** added at the end of the existing client expanded panel (Flight Plan · Check-ins · Timeline · Consultation · **Progress**). New REST endpoint `GET /hdl-v2/v1/dashboard/client/{client_id}/effort-outcomes` returns adherence series (from `hdlv2_timeline` 'adherence' rows written by Phase A) + outcome series (rate of ageing re-derived per report via `HDLV2_Rate_Calculator::calculate_full`). IDOR-guarded by `practitioner_owns_client()`. Chart.js 3.9.1 enqueued via jsdelivr (same CDN/version V1 uses on the longevity-form results page). New `loadProgress()` JS renders dual-axis line chart: adherence left (0-100%, teal), rate right (0.80-1.30, amber). Single-outcome state renders rate as dashed baseline; 2+ outcomes draw a real line. Empty state shown when neither series has data. Rate-limit policy: GET tier on the new route. | `class-hdlv2-client-status.php`, `class-hdlv2-rate-limit-policy.php`, `class-hdlv2-practitioner-dashboard.php`, `hdlv2-client-list-enhance.js`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 Stage 3 Result — Pace of Ageing gauge added (v0.22.15, STBY-only): new `renderPaceSection()` in `hdlv2-draft-report.js` replaces the flat `.hdlv2-dr-stats` grid with a gauge hero (existing `HDLSpeedometer.buildUrl(rate)` PNG — same module Stage 1 uses) above the three stat cards (Rate of Ageing / Biological Age / Chronological Age). Gauge value driven by `data.calc.rate` from REST — same source the stat card consumed, so dial position and "1.07×" number stay in lockstep. Defensive: gauge silently omits when rate is missing or speedometer module fails to load. New CSS: `.hdlv2-dr-pace`, `.hdlv2-dr-pace-head`, `.hdlv2-dr-gauge-wrap`, `.hdlv2-dr-pace-stats` (3-col grid above 640px, single column below, gauge shrinks to 360px). Speedometer enqueued explicitly in `class-hdlv2-client-draft-view.php` (mirrors consultation page) and added as `hdlv2-draft-report` JS dep. Theme parity: `#004F59` needle + `#3d8da0` band — already used on practitioner-notes header. | `hdlv2-draft-report.js`, `hdlv2-draft-report.css`, `class-hdlv2-client-draft-view.php`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 Phase A — adherence timeline writes (v0.22.14, STBY-only): every tick toggle now triggers `calculate_adherence($plan_id, true)` from `rest_tick()` and `rest_tick_all()`. The `write_timeline=true` branch upserts the timeline row by `(client_id, entry_type='adherence', date=week_start)` — replaces the prior `$wpdb->insert` (which would have produced N duplicate rows per week if it had ever been called). Fixes the silent-data gap where spec-required `entry_type='adherence'` rows never reached `wp_hdlv2_timeline`. No schema change. | `class-hdlv2-flight-plan.php`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 Stage 2 Result page wired to existing extraction (v0.22.12, STBY-only): `/form/stage2-insight` now reads `distilled_why` + `motivations` + (richer) `ai_reformulation` from priority chain — `hdlv2_why_profiles` (Make.com canonical) → `stage2_data.audio_summary` (Extract Themes click result) → static fallback. No new AI call from the result page; pure read of pre-extracted data. Static AWAKEN paragraphs from v0.22.11 are kept as the `ai_reformulation` fallback when Make.com hasn't completed yet. New `normalize_motivations()` helper coerces array entries (string OR object with text/label/motivation key) into clean string array for JS chip renderer. | `class-hdlv2-staged-form.php`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 Stage 2 Insight AI removed (v0.22.11, STBY-only): new `HDLV2_Stage2_Insight` deterministic 3-paragraph builder replaces the Haiku-backed `generate_stage2_insight()`. Same REST shape (`/form/stage2-insight` returns `{distilled_why, ai_reformulation, motivations, vision_text, cached:false}`); `distilled_why` and `motivations` arrays are now always empty (frontend already hides empty chip block + callout). 3 paragraphs: receipt with deterministic length variant (short/substantial/rich byte-count buckets), practitioner promise, what's-next. Make.com → `/form/stage2-callback` still populates `hdlv2_why_profiles` for the practitioner consultation interface (unchanged). | `class-hdlv2-stage2-insight.php` (new), `class-hdlv2-staged-form.php`, `class-hdlv2-ai-service.php`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 Stage 1 Result AI removed (v0.22.10, STBY-only): new `HDLV2_Stage1_Commentary` deterministic 5-paragraph builder replaces the Haiku-backed `generate_stage1_commentary()`. Same REST shape (`/form/stage1-commentary` returns `{success, commentary_html, cached:false}`), no JS change. Tone matches Haiku (softer/neutral, British English, second person). Picker scores 9 answers, selects strongest + two weakest factors with clinical-priority tie-break. 5 rate bands × ~38 paragraph blocks. Removes ~$0.0005/run cost, 2-5s latency, JSON-parse failure surface. Old `stage1_data.commentary_html` cache field is now ignored (recompute is microsecond). | `class-hdlv2-stage1-commentary.php` (new), `class-hdlv2-staged-form.php`, `class-hdlv2-ai-service.php`, `hdl-longevity-v2.php` |
| 2026-04-26 | V2 IDOR hardening (v0.22.9, STBY-only): new `HDLV2_Compatibility::practitioner_owns_client()` helper enforced on 15 routes that took `client_id` / `progress_id` from URL or body. Closes Timeline (read/write/export, add-note), Flight Plan (current, history, generate, tick, tick-all, adherence, settings, preview, priorities), Final Report `generate()`, and Consultation `edit-field`. Admin escape hatch (`manage_options`) preserved. | `class-hdlv2-compatibility.php`, `class-hdlv2-timeline.php`, `class-hdlv2-flight-plan.php`, `class-hdlv2-final-report.php`, `class-hdlv2-consultation.php`, `hdl-longevity-v2.php` |
| 2026-04-25 | V2 audio: client-side Whisper transcription (Transformers.js + WebGPU, whisper-small + beam search + preprocessing); WebGPU feature-detect before pipeline init | `hdlv2-transcriber.js`, `hdlv2-transcriber.worker.js`, `hdlv2-audio-component.js` |
| 2026-04-25 | V2: prevent duplicate Final Report on Back/Refresh (3-layer fix); Generate Final Report spinner + honest progress | `class-hdlv2-final-report.php`, `class-hdlv2-consultation.php`, `hdlv2-consultation.js` |
| 2026-04-24 | V2: rate limiter + idempotency tracker wired into REST loader; consultation form polish; idempotency wrap normalises WP_Error → WP_REST_Response | `class-hdlv2-rate-limiter.php`, `class-hdlv2-idempotency.php`, `class-hdlv2-rate-limit-{policy,middleware,status}.php`, `hdlv2-rate-limit.js` |
| 2026-04-24 | V2: Flight Plan PDF webhook + PDFMonkey callback (PR C); invite URL `?invite=TOKEN` redirects to assessment with form token; check-in confirm produces NEXT week's Flight Plan | `class-hdlv2-flight-plan.php`, `class-hdlv2-checkin.php`, `hdl-longevity-v2.php` |
| 2026-04-23 | V2: client dashboard spliced into existing `/clients/` page (drop separate dashboard approach); practitioner dashboard shortcode | `class-hdlv2-compatibility.php`, `class-hdlv2-practitioner-dashboard.php` |
| 2026-04-21 | STBY staging server provisioned (clone of LIVE); 5-sprint V2 staged workflow (Stage 0→1B→2A→2B→2C→3→4→5) | Server infra, V2 plugin |
| 2026-03-08 | Email template improvements: resolve_user_display_name() helper, "Your practitioner" wording, conditional pre-loaded note, magic link second-click fix | `class-health-tracker-practitioner.php`, `class-email-template-components.php` |
| 2026-03-05 | Consumer health credits for all: removed `$has_practitioner` gate. Practitioner email AJAX validation on forms. | `class-consumer-provisioner.php`, `class-health-tracker.php`, `class-health-tracker-practitioner.php`, `class-longevity-form.php`, `longevity-form-raw.php`, `class-health-form.php`, `health-form-raw.php` |
| 2026-03-05 | Fix Make.com webhook for invited clients: `resolve_credit_owner()` deducts from practitioner pool | `class-longevity-webhook-handler.php`, `longevity-form-raw.php` |
| 2026-03-05 | Credit system overhaul: practitioner 1000-credit bucket model via `resolve_credit_owner()` | `class-credit-manager.php`, `class-health-form-credit-integration.php`, `class-longevity-form-credit-integration.php`, `class-health-tracker-practitioner.php` |
| 2026-03-04 | Fix assessment magic link prefill: moved to `class-longevity-form.php`, pure JS prefill | `class-longevity-form.php`, `longevity-form-raw.php` |
| 2026-02-27 | Practitioner switch: notify new practitioner, JS confirmation dialog | `class-health-tracker-practitioner.php`, `class-health-tracker.php` |
| 2026-02-26 | Fix invited-client form UX: hide Skip on locked field; fix "Nov 30, -0001" date bug | `health-form-raw.php`, `class-health-tracker-practitioner.php` |
| 2026-02-26 | Soft-delete relationship fixes: security gap, re-invite restoration, stale meta cleanup | `class-health-tracker-practitioner.php`, `class-client-linker.php` |
| 2026-02-25 | Admin "Signup Source" column, magic link security, free report provisioning, Altituding integration | Multiple files |
| 2026-02-24 | Security hardening: rate limiting, input validation, token hashing, response redaction | `class-consumer-provisioner.php` |
| 2026-02-23 | Consumer provisioning v1: Stripe → webhook → WP REST API → magic link | `stripe-webhook.js`, `class-consumer-provisioner.php`, `health-data-lab.php` |
