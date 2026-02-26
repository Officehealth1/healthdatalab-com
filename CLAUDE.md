# HealthDataLab — Project Context

## Architecture
- **Frontend**: Static site on Netlify (healthdatalab.com) — HTML/CSS/JS + Netlify Functions
- **Backend**: WordPress on Vultr VPS (healthdatalab.net) — `/var/www/html/`
- **Plugin**: `health-data-lab-plugin/` — custom WP plugin for credits, forms, practitioner management, consumer provisioning
- **Plugin loader**: `health-data-lab-plugin/health-data-lab.php` — loads rate limiter (line 146), consumer provisioner (line 177)

## Consumer Provisioning System (Personal Reports)
End-to-end automated flow: Stripe checkout → Netlify webhook → WordPress REST API → account creation + welcome email with magic link.

### Key Files
| File | Purpose |
|------|---------|
| `netlify/functions/stripe-webhook.js` | Receives Stripe `checkout.session.completed`, calls WP provisioning API |
| `netlify/functions/create-checkout-session.js` | Creates Stripe Checkout sessions for consumer plans |
| `health-data-lab-plugin/includes/api/class-consumer-provisioner.php` | REST endpoint, user creation, credits, magic link emails |
| `health-data-lab-plugin/includes/security/class-rate-limiter.php` | IP-based rate limiting (transient-backed) |

### Security (hardened 2026-02-24)
- Rate limiting: 10/hour on provisioning, 5/15min on auto-login, 5/hour on free report (via `HDL_Rate_Limiter`)
- Input validation: tier whitelist, session ID regex, practitioner pref normalization, source whitelist
- Token hashing: HMAC-SHA256 for magic link tokens (raw token only in email URL)
- Response redaction: no `user_id` or `new_user` in API responses
- Session-only auth cookie on magic link login

### Tiers & Roles
- `consumer_single` / `consumer_annual` — valid tier values
- Practitioner pref `yes` → role `um_consumer`, linked to Matthew, 2 credits (health + longevity)
- Practitioner pref `no` → role `um_client`, no link, 1 longevity credit

## Free Report Provisioning (Partner Sites)
Automated flow: altituding.com/report form → Netlify brevo-submit → WordPress REST API → account creation + 1 longevity credit + co-branded magic link email.

### Key Files
| File | Purpose |
|------|---------|
| `altitud.com/netlify/functions/brevo-submit.js` | Brevo contact + calls WP free report endpoint (non-blocking) |
| `health-data-lab-plugin/includes/api/class-consumer-provisioner.php` | `/free-report-provision` endpoint, `handle_free_provision()` |

### Flow
1. User submits altituding.com/report form → Brevo contact created (critical path)
2. `provisionFreeReport()` POSTs to `healthdatalab.net/wp-json/hdl/v1/free-report-provision`
3. WP creates user (`um_client`), grants 1 longevity credit, sends co-branded email with magic link
4. User clicks link → auto-login → longevity form → Make.com → PDF report emailed

### Idempotency
- `hdl_free_report_provisioned` user meta flag — duplicates return 200 without new credit

### User Meta Set
- `hdl_free_report_provisioned`, `hdl_consumer_user`, `hdl_source`, `hdl_longevity_goal`

### Environment Variables
- **Netlify (healthdatalab.com)**: `WP_SITE_URL`, `WP_CONSUMER_PROVISION_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`
- **Netlify (altituding.com)**: `WP_SITE_URL`, `WP_FREE_REPORT_PROVISION_KEY`, `BREVO_API_KEY`, `BREVO_LIST_ID`
- **WordPress wp-config.php**: `HDL_CONSUMER_PROVISION_KEY`, `HDL_FREE_REPORT_PROVISION_KEY`

## Deployment
- **WordPress plugin files**: GitHub Gist + wget to server (see steps below)
- **Netlify functions**: auto-deploy on git push to `main`
- **Server**: Vultr VPS, SSH as root (`ssh healthdatalab.net` — port 8443, direct IP 108.61.172.199, key at `~/.ssh/id_ed25519`)

### WP Plugin Deploy Steps (always ask user before deploying)
1. Update Gist: `gh gist edit <GIST_ID> -f <filename> <local_path>`
2. Deploy to server: `ssh healthdatalab.net 'wget -O /var/www/html/wp-content/plugins/health-data-lab-plugin/.../<filename> "https://gist.githubusercontent.com/raw/<GIST_ID>/<filename>"'`

### Known Gists
| Gist ID | File |
|---------|------|
| `70b1b85de86c1d013d58eac1199d5e86` | `class-consumer-provisioner.php` |
| `6c4f2cff09d0b93b0395ea6cea70795c` | `class-health-tracker-practitioner.php` |
| `8410d9d43252ac8a66d2b25480a80025` | `class-user-management.php` |
| `3958fea30c77faa40f071e7162a8eaa2` | `class-client-linker.php` |
| `d8dc28ca026e4e5de3a4574d13be5e2c` | `health-form-raw.php` |

## Change Log
| Date | Change | Files |
|------|--------|-------|
| 2026-02-26 | Fix invited-client form UX: hide Skip button and "(optional)" label on locked practitioner email field; fix "Nov 30, -0001" date bug with defensive `strtotime() > 0` guard | `health-form-raw.php`, `class-health-tracker-practitioner.php` |
| 2026-02-26 | Soft-delete relationship fixes: `validate_client_access()` security gap, re-invite/auto-link restoration of soft-deleted rows, stale `hdl_invited_by_practitioner` meta cleanup on removal, backfill filter | `class-health-tracker-practitioner.php`, `class-client-linker.php` |
| 2026-02-25 | Admin "Signup Source" column: tracks `hdl_source` on all creation paths, color-coded labels, inference fallback for existing users | `class-consumer-provisioner.php`, `class-health-tracker-practitioner.php`, `class-user-management.php` |
| 2026-02-25 | Magic link security: one-time use enforcement, rate limiting, session cookies, expired status check | `class-health-tracker-practitioner.php`, `class-consumer-provisioner.php` |
| 2026-02-25 | Free report provisioning: REST endpoint, credit grant, co-branded email for partner sites (Altituding) | `class-consumer-provisioner.php` |
| 2026-02-25 | Altituding integration: replaced practitioner invite with free report provisioning + backlinks | `brevo-submit.js`, all HTML pages |
| 2026-02-25 | Added `HDL_FREE_REPORT_PROVISION_KEY` to wp-config.php | server |
| 2026-02-24 | Fix: set `hdl_invited_by_practitioner` meta for consumer-provisioned users so forms lock practitioner email field | `class-consumer-provisioner.php` |
| 2026-02-24 | Security hardening: rate limiting, input validation, token hashing, response redaction, session cookie | `class-consumer-provisioner.php` |
| 2026-02-24 | Deployed hardened provisioner to production | Gist `70b1b85de86c1d013d58eac1199d5e86` |
| 2026-02-23 | Consumer provisioning v1: REST API, magic link, credits, practitioner linking | `class-consumer-provisioner.php`, `stripe-webhook.js`, `health-data-lab.php` |
| 2026-02-23 | Welcome email switched to magic link (replaced post-checkout banner) | `class-consumer-provisioner.php` |
| 2026-02-23 | Added User-Agent header to WP provisioning request | `stripe-webhook.js` |
| 2026-02-23 | Fixed silent failure: replaced fetch with https module | `stripe-webhook.js` |
| 2026-02-23 | Added consumer provisioning flow for personal report purchases | `stripe-webhook.js`, `class-consumer-provisioner.php` |
