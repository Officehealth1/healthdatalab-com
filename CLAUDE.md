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
- **Staging**: `stby.healthdatalab.net` — STBY clone of LIVE for V2 testing (Brevo disabled, isolated keys, no Make.com)

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
- **Ops scripts**: `scripts/lab-server/` — SSH wrappers for inspecting LIVE (`check-credits.sh`, `check-user.sh`, `recent-submissions.sh`, `tail-logs.sh`, `gate.sh`)

## Frontend

- **Pages**: `index.html` (landing), `personal-report.html` (Stripe checkout), `pricing.html` (multi-currency), `about.html`, `course.html`, `help-centre.html`, `terms.html`, `privacy.html`
- **CSS**: `assets/css/styles.css` (custom design tokens via CSS variables) + Tailwind v4 utility classes via `@tailwindcss/browser@4` CDN
- **JS**: `assets/js/main.js` (nav, modals, checkout, GSAP animations), `hdl-trajectory-chart-hero.js`, `hdl-radar-chart-hero.js`, `practice-assessment.js` (interactive carousel)
- **Routing**: `netlify.toml` rewrites clean URLs → `.html` files (200 status)

### Brand + Status palette (apply across V1, V2, and frontend)

**Brand palette** — use these for primary surfaces, headings, CTAs, links, borders:

| Role | Hex | When |
|------|-----|------|
| Teal (primary) | `#3d8da0` | CTAs, links, primary buttons, focus rings |
| Dark teal | `#004F59` | Headings, hover state on teal |
| Dark navy | `#2c3e50` | Body text emphasis, secondary headings |
| Muted grey | `#888` | Secondary text, captions |
| Border grey | `#e4e6ea` | Dividers, input borders |
| Soft fill | `#f8f9fb` / `#fafbfc` | Card backgrounds, hover states |

**Status palette** — use ONLY these for success/warning/error/info states. Do NOT mix with Flat UI defaults (`#2ecc71`, `#e67e22`, `#e74c3c`, `#c0392b`):

| State | Text | Background | Border |
|-------|------|------------|--------|
| Success | `#10b981` | `#ecfdf5` | `#a7f3d0` |
| Warning | `#d97706` | `#fffbeb` | `#fde68a` |
| Error | `#dc2626` | `#fef2f2` | `#fecaca` |
| Info | `#3b82f6` | `#eff6ff` | `#bfdbfe` |

**Fonts:** Poppins for display headings (`<h1>`/`<h2>`/big stat numbers), Inter for body text. Email-safe stack: `'Poppins', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`. Never default to system fonts only — that's the #1 visual brand violation.

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

Main loader: `hdl-longevity-v2.php` v0.22.56 / DB schema v2.9. Tables prefixed `wp_hdlv2_*` to avoid collision with V1's `wp_health_tracker_*`. REST namespace: `hdl-v2/v1/*`. For deeper V2-specific guidance, see `hdl-longevity-v2/CLAUDE.md` and per-sprint `includes/sprint-*/CLAUDE.md` files.

Sprint-based directory structure under `includes/`:
- **`sprint-1/`** — Stage 1 embeddable widget (9-question mini-form, pace-of-ageing gauge, widget config + invite tokens)
- **`sprint-2/`** — Stage 2 WHY profiling (`class-hdlv2-staged-form.php` 13+ REST endpoints, Claude distillation, rate calculator, email templates)
- **`sprint-2c/`** — Stage 3 final report (draft report, practitioner consultation, my-report client view, trajectory SVG, PDFMonkey integration)
- **`sprint-3/`** — Audio transcription services (server fallback only — see Audio Strategy below)
- **`sprint-4/`** — Client dashboard (timeline, weekly check-ins, status tracking, practitioner dashboard shortcode)
- **`sprint-5/`** — Flight Plan (weekly action plan generated by Claude, PDFMonkey webhook callback)

Cross-cutting:
- **`security/`** — V2-specific: `class-hdlv2-rate-limiter.php` (transient-backed counter), `class-hdlv2-rate-limit-{policy,middleware,status}.php` (route→tier map, REST pre/post-dispatch hooks, `/rate-limit/status` endpoint), `class-hdlv2-idempotency.php` (Stripe-pattern client-supplied keys for AI-burn callbacks), `class-hdlv2-webhook-monitor.php` (logs every Make.com fire + surfaces failures via wp-admin notice)
- **`class-hdlv2-context-builder.php`** — Builds Claude prompts from client history
- **`class-hdlv2-job-queue.php`** — Async job runner for long-running AI calls (5-minute cron)
- **`class-hdlv2-compatibility.php`** — V1 ↔ V2 data sync glue (e.g., V2 client list spliced into V1 `/clients/` page)

Key services: `sprint-2/class-hdlv2-ai-service.php` (Claude API), `sprint-3/class-hdlv2-audio-service.php` (audio validation + extraction orchestration), `sprint-3/class-hdlv2-deepgram-service.php` (Deepgram Nova-3 — server-side transcription), `sprint-2/class-hdlv2-rate-calculator.php` (pace-of-ageing scoring).

Embeddable widget: `widget/hdl-lead-magnet.js` — can be embedded on external practitioner sites.

### Audio Transcription Strategy (3-tier fallback)

Stage 2 WHY audio + Stage 4 check-ins:
1. **Browser Web Speech API** — fastest path, fails silently in Chrome incognito (watchdog timer detects no-result and falls through)
2. **Client-side Whisper** — `assets/js/hdlv2-transcriber.js` + `hdlv2-transcriber.worker.js` (Transformers.js + whisper-small + WebGPU, falls back to WASM). Feature-detects WebGPU before pipeline init. Beam search + audio preprocessing for accuracy. Zero server cost.
3. **Server fallback** — POST audio blob to `hdl-v2/v1/audio/transcribe-async` → Deepgram Nova-3 (`sprint-3/`). Used only when client-side fails.

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
- **STBY**: `stby.healthdatalab.net` — clone of LIVE for V2 testing. Brevo + email disabled, HDL keys rotated, has Anthropic key but NOT Make.com. IP `136.244.68.235`, port 8443, direct LE cert.

**V2 phase tracking**: `hdl-longevity-v2/docs/phases/PHASE*-CHANGES-FOR-LIVE.md` files document each phase's STBY → LIVE migration. `hdl-longevity-v2/docs/plans/V2-LIVE-MIGRATION-CHECKLIST.md` is the master go-live checklist (open it when user says "ready to go live"). PDFMonkey templates mirrored in `hdl-longevity-v2/docs/pdfmonkey/PDFMONKEY-*.md` — edit these first, then user pastes into PDFMonkey/Make.com UIs. Meeting notes, session handoffs, and V2 plans/specs live in `hdl-longevity-v2/docs/{meetings,plans,specs}/`.

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
- V2 AI keys: `HDLV2_ANTHROPIC_API_KEY`, `HDLV2_DEEPGRAM_API_KEY`
- V2 Make.com webhooks: `HDLV2_MAKE_STAGE1_PDF`, `HDLV2_MAKE_STAGE2_WHY`, `HDLV2_MAKE_DRAFT_REPORT`, `HDLV2_MAKE_FINAL_REPORT`, `HDLV2_MAKE_FLIGHT_PDF`, `HDLV2_MAKE_REDFLAG` (red-flag notification webhook, fired by raw `wp_remote_post` from `class-hdlv2-flags-store.php` with a `wp_mail` fallback), `HDLV2_MAKE_CALLBACK_SECRET` (Bearer token for inbound PDFMonkey callbacks)

## Security

- **API auth**: `X-HDL-Provision-Key` / `X-HDL-Free-Report-Key` headers validated against wp-config constants
- **V1 rate limiting**: `HDL_Rate_Limiter` — 10/hour provisioning, 5/15min auto-login, 5/hour free report
- **V2 rate limiting**: route→tier policy (`class-hdlv2-rate-limit-policy.php`) wired into REST pre-dispatch; client-side awareness module (`hdlv2-rate-limit.js`) reads `/rate-limit/status` to surface limits in UI
- **V2 idempotency**: client-supplied keys (Stripe pattern) on AI-burn endpoints to prevent duplicate Claude/Deepgram charges on retry. `class-hdlv2-idempotency.php` normalises `WP_Error` → `WP_REST_Response`.
- **Token hashing**: HMAC-SHA256 for magic link tokens (raw token only in email URL); `?invite=TOKEN` matches `/^[a-f0-9]{64}$/` regex before lookup
- **Input validation**: Tier whitelist, session ID regex, practitioner pref normalization, source whitelist
- **CSP**: Configured in `netlify.toml` — allows Stripe, Google Analytics, jsdelivr, cdnjs (and `cdn.jsdelivr.net` for Transformers.js Whisper model)
- **Session auth**: Session-only cookies on magic link login (no persistent tokens)
- **Response redaction**: No `user_id` or `new_user` in provisioning API responses
- **Pre-launch audit**: `.planning/SCALE-AND-SECURITY-PLAN-2026-04.md` — 10-point security checklist for V2 go-live

## Change Log

Detailed change rationale moved to [`docs/CLAUDE-CHANGELOG.md`](docs/CLAUDE-CHANGELOG.md) to keep this file under the 40k char performance threshold. Read it when you need *why* a change was made; for *what* changed and *when*, prefer `git log` / `git blame`.

Most recent entry: 2026-07-14 — V2 v0.47.69 (hotfix branch) / v0.47.70 (bundle) (**idle-dashboard self-429 fix — DEPLOYED to STBY (bundle variant 0.47.70) + STBY-verified; LIVE leg = hotfix 0.47.69 pending "push to live"**). RCA 2026-07-13 (read-only, LIVE): TIER_READ 200/hr is keyed per WP user and shared by every open tab+device; the client-list 60s fallback poll pair (`/dashboard/clients` + `/widget/leads/pending`) consumed 120 reads/hr PER TAB, so ≥2 idle tabs drained the practitioner's own budget — LIVE logged 114 blocks (all tier=read, exactly those two routes, matthew=122 across 2 IPs/3 tabs), flaring at each hourly window tail; the v0.20.0 global fetch wrapper then modal'd "Give us a moment" on an idle screen. The 4s digest poll was NOT the consumer (unmapped → null tier → unmetered for logged-in). Fix: **[1]** fallback tick full-fetches ONLY when the digest advanced or data >5min stale (idle 120→~20 reads/hr/tab); null roster on 429 keeps the rendered list; **[2]** X-HDLV2-Bg-marked background polls NEVER open the modal (pill only; user-action 429s still modal); **[3]** shared Retry-After cooldown (`hdlv2RateLimit.isCoolingDown()`) armed on any V2 429 — enhancer + practitioner-dashboard 20s + nav-bar 4s pollers all stop until it elapses; **[4]** authenticated-practitioner GETs on the two roster routes consume a new `prac-read` bucket 1200/hr/user (policy `prac_read_config()`, middleware branch BEFORE the read consume; anonymous/token limits byte-untouched, token precedence stands); **[5]** `loadStatus()` now sends a localized REST nonce (`hdlv2RateLimitCfg`) so the status probe counts to the real user; `log_block()` logs `user:<id>` / truncated token (full 64-hex credential no longer written to logs). TDD red→green: `tests/rl-prac-read/` 15 + `tests/rl-idle-poll/` 12+7 (real sources evaluated); regressions green on both variants (token-expiry 26, digest, stage2-retry 16, progress-point, tenancy, iris 87; bundle also action-unify 22+41+6 + resend-link 38+15+22 + inbox). Also fixed stale `scenario-roster-resend.php` fixtures (3/7→7/7, flagged in drift-recovery notes — fixtures now seed the resolve_resend_status tables via a 'funnel' knob). STBY live proof: `/dashboard/clients` as prac returns `X-RateLimit-Tier: prac-read` limit 1200 (bucket shared with pending-leads); other reads stay `read` 200; curl burst as prac 206 → 429 at request 201 with `Retry-After` + STBY OLS log `[HDL-RL BLOCK tier=read id=user:206 …]` (new identity format live); 3-tab idle window (prac 122). ⚠ FOUND during verify: **STBY had `hdlv2_rate_limit_disabled_tiers='all'`** — every RL tier bypassed on STBY (why this class of bug never reproduces there); enforcement enabled for the test window, standing value needs a decision (LIVE runs fully enforced). Branches: hotfix `fix/rl-idle-dashboard-2026-07-14` `b6f2c28` (off d1d6624 = LIVE 0.47.59 tree, for the standalone LIVE deploy AHEAD of the gated dashboard bundle); bundle carry `47b5573` + test fix `13f0f13` on `fix/button-qa-gaps-2026-07-13` (version resolved to 0.47.70; also fixes the 0.47.66-header/0.47.68-constant drift). §B2 (digest 4s→12s + micro-cache) remains a SEPARATE server-load task — the digest is unmetered, so it was never this bug.

Previous entry: 2026-07-11 — V2 v0.47.59 (**Stage-2 retry cron dead-row fix, Option B revision — DEPLOYED to STBY AND LIVE ("push to live" bundle 0.47.56/57/59, same day). LIVE == STBY == 0.47.59.** LIVE deploy: 4 files (main loader, client-status, compatibility, staged-form) md5-checked against c2b1171 pre-deploy (zero drift), backed up to `/root/live-backups/bundle-04759-20260711/`, byte-identical to STBY-verified files post-deploy, lint clean, `/health` 0.47.59 db_in_sync, no migration (DB stays 3.25). LIVE verified: real digest handler as prac 122 returns epoch 1783667980 with no DB error (0.47.56 realtime restored); retry-cron candidate query schema-valid, 0 stuck rows, cron scheduled next 2026-07-12 04:36 (0.47.59); `write_progress_point` present, behaviour STBY-proven byte-identical (0.47.57); branch `fix/v2-digest-and-progress-point`, commits `2977bb2` 0.47.58 → `d1d6624` 0.47.59). `run_stage2_extraction_retry()` re-fired soft-deleted / token-expired stuck rows to Make ~2×/week forever — the callback then always 404'd "Assessment not found" (`get_progress_by_token()` enforces `deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP()` since B4; the module-86 Bearer was never the issue, it matches both servers' `HDLV2_MAKE_CALLBACK_SECRET`). Final shape (Option B, per Quim after the 0.47.58 review flagged attempt-3 starvation): candidate SELECT gains ONLY `fp.deleted_at IS NULL` (+ `fp.token_expires_at` in the SELECT list); the expiry check moved into the loop (`' UTC'` parse, NULL fail-closed) and gates ONLY the Make re-fire branch — expired-but-alive rows now run the token-independent `local_extract_why_profile()` Claude rescue on every attempt (idempotent guarded upsert) instead of being dropped. Tests `tests/stage2-retry/` red(8-fail)→green 16/16. STBY verified live: deleted row not selected; expired row rescued by a REAL Claude extract (why row written, counter 1, `webhook_log` count unchanged → zero Make fires → zero 404s); valid row proven selected (SQL) + Make-branch-routed (unit test — real fire not exercised on STBY to avoid the shared LIVE Make scenario); baseline restored byte-identical. 4-lens adversarial review: PASS ×4, zero findings. Full suite green (stage2-retry 16, token-expiry 26, digest 6, progress-point 23, tenancy 7, iris 87). Both servers had 0 stuck rows at deploy (prophylactic).

Previous entry: 2026-07-11 — V2 v0.47.56 + v0.47.57 (**two pre-existing bug fixes, DEPLOYED to STBY + verified; LIVE pending "push to live" sign-off**; branch `fix/v2-digest-and-progress-point` off `c2b1171`). **(1) v0.47.56 dashboard version digest** — `rest_get_version()` (sprint-4 client-status) referenced `COALESCE(approved_at, started_at, created_at)` bare inside the `cn INNER JOIN fp` subquery; `created_at` exists in BOTH tables → MariaDB error 1054 on every 4s poll → `{"v":0}` forever → realtime roster refresh dead + 1 DB-error log line/4s per open dashboard (~500/day on LIVE). Fix qualifies all three as `cn.*`. STBY verified: digest returns real epoch for prac 122+206, increments on a real `/form/save`, HTTPS polls 200 with cookie+nonce, 0 ambiguous errors post-deploy, AND a real browser session (one-shot `?prac_login=`) showed 4s polls + automatic roster refetch when the digest bumped. Tests `hdl-longevity-v2/tests/dashboard-digest/` (fake wpdb implements the error-1054 rule — behavioural red→green). **(2) v0.47.57 write_progress_point** (compatibility bridge) — V1-compat trend write on Final Report silently failed forever: nonexistent `metric_value` column (real: `current_value`), missing NOT-NULL `form_source`, AND `wp_hash()` (32-hex) user_hash while every V1 reader keys on salted sha256 (64-hex, `generate_user_hash`); returned true unconditionally. Now: correct columns; V1 salted hash; `form_source='longevity'` (only value the client dashboard's filtered tab renders); writes WHITELISTED to the 5 headline metrics (rate_of_ageing, biological_age, bmi, whr, whtr — the caller's ~21 sub-scores would blank V1 Progress Insights' `slice(0,5)` window); `measurement_date` left to DB DEFAULT so V2 rows share V1's clock (the thrice-fixed BST divergence class); V1-parity trend columns (previous/change/percent clamped decimal(5,2), first point = baseline anchor); same-day skip-not-replace idempotency (regenerate refires; a delete could destroy a same-day V1 row); 0 allowed (V2 worst-band score ≠ V1 missing-sentinel); honest aggregate return + error_log. STBY verified with disposable prac 389/client 390 full finalise: 2 progress rows landed (rate 1.17, bio_age 52.6, hash == V1 salted sha256, DB-clock stamp), report+job chain unaffected, 0 "Unknown column" errors; all artifacts deleted, counts byte-identical to pre-test. Known residual: first finalise writes ≤5 NULL-change baselines (Progress Insights can render an empty section until the second report); V1 `recalculate_user_progress` still wipes V2 rows on assessment delete (best-effort by design). Note: STBY Make round-trips no longer return (shared scenario callbacks repointed to LIVE at cutover) — WHY extraction verified via the real `hdlv2_stage2_local_extract` fallback handler. Tests `hdl-longevity-v2/tests/progress-point/` (fake wpdb enforces the real V1 schema; red→green ×2 incl. post-review revision). Both fixes 4-lens adversarially reviewed (correctness/security/data-integrity/edge-cases), all confirmed findings addressed. Full suite green: token-expiry 26, consultation-tenancy, iris 87, dashboard-digest, progress-point.

Previous entry: 2026-07-10 — V2 v0.47.54 + DB v3.25 (**B4 fix: magic-link token expiry** — windows signed off by Quim, **DEPLOYED to STBY + verified** same day: Phase AF success log, 0 NULL rows left of 83/93 backfilled, `_bak_v325` verified 93/93 then dropped per Quim, browser E2E green. LIVE back-port prepared NOT applied: `hdl-longevity-v2/docs/plans/LIVE-BACKPORT-B4-TOKEN-EXPIRY-2026-07-10.md`). `form_progress.token` was a permanent reusable login credential: `token_expires_at` existed since v3.8 but nothing ever wrote it and the init login treated NULL as valid forever. Now: (1) new `HDLV2_CLIENT_TOKEN_TTL_DAYS = 90` **FIXED window from issuance** (v0.47.54 — NOT sliding; re-fetching a link never extends it, closing the link-scanner keep-alive) — set at both INSERT sites (staged-form New Client + widget-config complete_signup), refreshed ONLY on practitioner re-issue of an existing client's link (long-running check-in clients need a re-issue roughly quarterly); `?invite=` (30min–90d per flow, NOT NULL, verified 0 NULL/zero rows on STBY) and `?prac_login=` (30-min one-shot transient) confirmed already-expiring — no change; (2) init `?token=` + `?invite=` checks fail CLOSED (NULL/garbage = expired) and parse stored-UTC datetimes with `' UTC'` (they were rejecting still-valid links 1h early on BST); (3) `AND token_expires_at > UTC_TIMESTAMP()` added to the 8 client-credential token lookups (job-queue, timeline, audio ×2, checkin, staged-form, flight-plan ×2); draft-view checks in PHP with a self-or-owning-practitioner bypass (`practitioner_owns_client`) so practitioners keep dormant clients' reports; report-pdf Make.com callback and the practitioner breadcrumb deliberately NOT gated. (4) Phase AF (v3.25) migration: full-table backup to `wp_hdlv2_form_progress_bak_v325` (existence-guarded, aborts backfill if backup fails), then backfill NULL rows to `GREATEST(created_at + 90d, UTC_TIMESTAMP() + 14d)` — 14-day deploy-grace floor so nobody mid-funnel is locked out; NULL-count verified + logged. `?prac_login=` untouched (already 30-min one-shot transient, v0.40.7). Tests: `hdl-longevity-v2/tests/token-expiry/` (26 assertions, standalone spy harness, TDD red→green). LIVE 0.41.34 confirmed vulnerable (same hole; needs separate back-port against 0.41.x files). Branch `fix/v2-token-expiry`.

Previous entry: 2026-05-01 — V2 v0.28.0 → v0.28.1 + DB v3.0 → v3.1 (**Consultation Addenda** feature, STBY-only). Practitioner-appended, timestamped clinical observations replace the deleted "Reset & start fresh" pattern. New table `wp_hdlv2_consultation_addenda` (13 cols, 4 indexes). New `HDLV2_AI_Service::merge_consultation_with_addenda()` — pure transform that prepends a chronological addenda block to the existing typed_notes before `generate_final_report` runs. New REST routes `POST /consultation/addendum` (TIER_WRITE, IDOR'd) and `POST /consultation/save-and-update-plan` (TIER_AI_BURN, `wrap_ai('updateplan:pid:cid')`). `HDLV2_Final_Report::generate()` now loads addenda + merges them + stamps `superseded_by_report_id` on each addendum after insert + returns `generated_at` so the frontend shows "Generated DD MMM YYYY · HH:MM". Frontend adds an Addenda timeline + entry form (with reused `HDLAudioComponent` for mic + audio-upload, "Audio files only" reminder pill, editable timestamp + priority) + new "Save & Update Plan" CTA + stepped progress UX + success card with timestamp. Carry-along **B1 fix:** `regenerate()` Flight-Plan archive ENUM list (was `'ready'`, never matched; now `IN ('generated','delivered','active')`). All deployed to STBY with `/health` ok at 0.28.1, 16/16 tables, db_in_sync. Phase 4 = browser smoke test by Quim per runbook in `hdl-longevity-v2/docs/meetings/MEETING-NOTES-2026-04-30-MATTHEW.md`. Phase 5 (B2/B3/B4 bundle) deferred. Quarterly Part 1/2/3 cycle still future. Never push to LIVE without explicit "push to live" authorisation. STBY only.

Previous entry: 2026-04-30 — V2 v0.27.3 (Reset removal — Matthew transcript: *"let's not give them the option to reset but they have the option to add more information to it"*). Dropped the destructive **"Reset & start fresh"** button + REST route + handler from the practitioner consultation review panel. Frontend (`hdlv2-consultation.js`): removed button HTML in `renderReviewPanel`, `bindResetButton()` (~28 lines), `doReset()` (~46 lines), the `bindResetButton()` call in the review-stage path, and the now-orphan `AbortController` plumbing in `flushAutoSave` (only consumer was `doReset`). Backend (`class-hdlv2-consultation.php`): removed `register_rest_route('/consultation/reset', …)` and `rest_reset_consultation()` (~83 lines). CSS: removed `.hdlv2-btn-danger` (only consumer was the deleted button). **Safe** — Reset never reached LIVE (v0.24.8 STBY-only); no other JS or REST consumer (project-wide grep); the post-Final "Save and Re-generate" banner is a separate code path and unaffected; `beforeunload` save-guard still works. Net: ~85 JS + ~95 PHP + 11 CSS lines deleted, 3 state vars + 2 functions + 1 REST route + 1 AbortError branch gone. Forward path: additive "Update 1/2/3" timestamped fields per Matthew's transcript — captured in `hdl-longevity-v2/docs/meetings/MEETING-NOTES-2026-04-30-MATTHEW.md`. STBY only.

Previous entry: 2026-04-30 — V2 v0.27.1 (/ultrareview LOW polish bundle: 4 fixes closing the cloud-review backlog. **#13** all 34 `[HDL-DEBUG]` console.log calls in `hdlv2-audio-component.js` gated behind `HDLV2_AC_DEBUG` flag (defaults false; set `window.HDLV2_AC_DEBUG = true` to surface) — production users no longer get devtools spam. **#14** removed dead `renderFinalisedView()` function in `hdlv2-consultation.js` (defined but no callers — left over from v0.21.2 redirect refactor). **#15** `formatRelativeTime()` no longer hard-appends `'Z'` to MySQL timestamps — was forcing UTC interpretation of strings stored in server-local time, causing "5 minutes ago" to render as "1h 5m from now" on BST. **#16** flight-plan PDF poll `setInterval` hoisted to mount-scope so plan regeneration mid-session can clear the previous timer; previously it kept polling for the archived plan's pdf_url forever. STBY only. Plugin-side /ultrareview backlog now empty.

Previous entry: 2026-04-30 — V2 v0.27.0 (/ultrareview MEDIUM bundle: 5 fixes from cloud review verify phase. **#11** practitioner Generate-Final-Report click now awaits `/save-notes` before firing `/organise` (was racing — Claude often saw stale or empty `raw_notes`); `saveNotes()` refactored to return Promise<boolean>. **#8** audio component recognises `audio/mp4` from Safari/iOS — was silently uploading `recording.webm` with mp4 contents → server mime-check rejected. **#12** `showAsyncProcessing()` polling re-renders no longer clobber the saved typed text with empty string when textarea has been replaced. **#10** `send_reminders` SQL switched from `SELECT DISTINCT` to `GROUP BY client_user_id + ANY_VALUE()` — clients with multiple form_progress rows no longer get duplicate weekly reminder emails. **#9** Flight plan `mount()` swapped 7 `document.getElementById` calls to `root.querySelector` so two simultaneously-mounted plans don't steal each other's button handlers. STBY only.

Previous entry: 2026-04-30 — V2 v0.26.2 + DB v3.0 (/ultrareview HIGH-severity bundle: 5 fixes from the cloud review's verify phase before it crashed during dedup. **#2** practitioner /finalise click chain now sends `Idempotency-Key` header on /approve + /finalise so server-side `wrap_ai` actually dedupes double-clicks; **#3** consultation reset WHERE clause was matching zero rows because `wp_hdlv2_flight_plans.status` ENUM never had a `'ready'` value AND the SET 'archived' value wasn't in the enum either — Phase J migration adds `'archived'` to both `hdlv2_reports.status` and `hdlv2_flight_plans.status` enums + WHERE rewritten to `IN ('generated','delivered','active')`; **#4** three Claude-firing endpoints `/consultation/organise`, `/consultation/refresh-brief`, `/consultation/save-and-regenerate` now in TIER_AI_BURN (were falling through to TIER_WRITE 60/hr ≈ $4-6/hr Claude burn); **#5** five `strtotime($row->expires_at)` reads now append `' UTC'` so 30-min invites stop being born expired on non-UTC servers (London BST = UTC+1 in summer); **#6** rate-limit identity precedence is now token > user_id > IP so two practitioners on same office IP no longer share a single 8/hr AI-burn bucket. STBY only.
