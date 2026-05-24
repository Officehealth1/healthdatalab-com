# W12 Rename Classification — "Report" → "Trajectory Plan"

**Generated:** 2026-05-24 (Phase 1 discovery — no edits applied yet)
**Pause:** Phase 2 review by Matthew required before Phase 3 edits land.

## Rubric

| Class | Meaning | Action |
|---|---|---|
| USER-FACING | HTML displayed to a user, email subject/body, JS UI text, button label, modal title, placeholder, REST error message that bubbles to a JSON message field | **CHANGE** |
| AI-PROMPT | String sent to Claude as part of a system/user prompt that influences user-visible AI output | **CHANGE** (so Claude generates "Trajectory Plan" in its narrative) |
| COMMENT | `//` or `/* */` or PHP docblock — developer-facing only, no runtime effect | **KEEP** |
| IDENTIFIER | Class name (`HDLV2_Final_Report`), constant (`HDLV2_MAKE_FINAL_REPORT`), method call (`::regenerate()`), error-log prefix, internal log breadcrumb | **KEEP** |

## Summary counts

| Class | Count |
|---|---|
| USER-FACING + AI-PROMPT (V1) | 6 |
| USER-FACING + AI-PROMPT (V2) | 58 |
| **TOTAL TO CHANGE** | **64** |
| COMMENT (keep) | 107 |
| IDENTIFIER (keep) | 9 |
| **TOTAL TO KEEP** | **116** |
| **GRAND TOTAL grep matches** | **180** |

## Phrase "the way you can live and thrive"

Zero matches. Phrase does not appear in either plugin tree across `*.php` / `*.js` / `*.css` / `*.html`. No rename needed.

## Verified infrastructure (NEVER renamed by Phase 3)

| Kind | Where | Reason |
|---|---|---|
| REST route | `hdl-v2/v1/reports/draft` (`class-hdlv2-client-draft-view.php:49`) | URL contract |
| REST route | `hdl-v2/v1/form/generate-report` (`class-hdlv2-staged-form.php:56`) | URL contract |
| REST route | `hdl/v1/paid-report-provision` (W4) | URL contract |
| Table | `wp_hdlv2_reports` | DB schema |
| Class | `HDLV2_Final_Report` | PHP class name |
| Constant | `HDLV2_MAKE_FINAL_REPORT` | wp-config constant for the Make.com webhook URL |
| Column/array keys | `report_type`, `report_id`, `report_content`, `report_payload` | DB/array structure |
| ENUM values | `'draft'`, `'final'`, `'stage1'` (in `report_type` column) | DB values + payload values |
| CPT | none — V2 does not register any report-related custom post type | n/a |
| Option keys | none containing `report` in `wp_options` | n/a |

---

## Section A — Strings to CHANGE (64)

### V1 (health-data-lab-plugin) — 6 matches

- `includes/forms/longevity-form-content.php:594` — `<h3 class="apple-modal-title">Your Longevity Report is on its way!</h3>`
- `includes/forms/health-form-raw.php:15927` — `title: 'Processing Your Report',` *(was ambiguous — classified user-facing)*
- `includes/forms/longevity-form-raw.php:470` — `<h3 class="apple-modal-title">Your Longevity Report is on its way!</h3>`
- `includes/api/class-consumer-provisioner.php:900` — `'Your Longevity Report',` *(was ambiguous — classified user-facing)*
- `includes/api/class-consumer-provisioner.php:917` — `$subject = 'Your ' . $source_label . ' Longevity Report — powered by HealthDataLab';` *(was ambiguous — classified user-facing)*
- `includes/core/class-health-tracker.php:297` — `<div style="font-size:12px;color:#86868b;margin-bottom:4px;">Longevity Reports</div>`

### V2 (hdl-longevity-v2) — 58 matches

- `widget/hdl-lead-magnet.js:1221` — `+     '<li><strong>Your Longevity Report arrives.</strong> Reviewed by your practitioner, with your weekly Flight Plan to follow.</li>'`
- `includes/sprint-2c/class-hdlv2-client-draft-view.php:412` — `'page_label'  => 'Longevity Report',`
- `includes/sprint-2c/class-hdlv2-final-report.php:507` — `return new WP_Error( 'db_error', 'Could not update Final Report row. Please try again.', array( 'status' => 500 ) );` *(was ambiguous — classified user-facing)*
- `includes/sprint-2c/class-hdlv2-final-report.php:520` — `return new WP_Error( 'db_error', 'Could not create Final Report row. Please try again.', array( 'status' => 500 ) );` *(was ambiguous — classified user-facing)*
- `includes/sprint-2c/class-hdlv2-final-report.php:531` — `return new WP_Error( 'db_error', 'Final Report write returned no id. Please try again.', array( 'status' => 500 ) );` *(was ambiguous — classified user-facing)*
- `includes/sprint-2c/class-hdlv2-final-report.php:1174` — `'Your Longevity Report is Ready — HealthDataLab',` *(was ambiguous — classified user-facing)*
- `includes/sprint-2c/class-hdlv2-final-report.php:1495` — `'Cannot regenerate: no Final Report exists yet. Click Generate Final Report first.',` *(was ambiguous — classified user-facing)*
- `includes/sprint-4/class-hdlv2-practitioner-dashboard.php:374` — `$t .= '<li><strong>Stage 3 &middot; Full detail</strong>22 measurements, 5 sections. Auto-drafts the Longevity Report for your review.</li>'`
- `includes/sprint-4/class-hdlv2-practitioner-dashboard.php:375` — `$t .= '<li><strong>Consultation &middot; Final Report</strong>You edit, add notes, finalise. PDF + Flight Plan delivered to the client.</li>`
- `includes/sprint-4/class-hdlv2-client-dashboard.php:1048` — `$h .= '<div class="cdp-report-meta">Your Longevity Report &middot; <span class="cdp-report-badge cdp-report-badge-' . ( $is_final ? 'final' `
- `includes/sprint-4/class-hdlv2-client-dashboard.php:1656` — `3 => array( 'Stage 3 &middot; Full detail',       '22 measurements across 5 sections. Auto-drafts your Longevity Report for your practitione`
- `includes/sprint-4/class-hdlv2-client-dashboard.php:1657` — `4 => array( 'Consultation &middot; Final Report', 'Your practitioner edits, adds notes, finalises. PDF + Flight Plan delivered to you.' ),`
- `includes/sprint-2/class-hdlv2-email-templates.php:228` — `<p style='color:" . self::MUTED . ";margin:0 0 24px;line-height:1.5;'>Draft Report is ready. Your client <strong>{$cname}</strong> has compl`
- `includes/sprint-2/class-hdlv2-email-templates.php:252` — `. self::cta_button( $url, 'Review Draft Report' );`
- `includes/sprint-2/class-hdlv2-email-templates.php:254` — `return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Draft Report — Review Required' );`
- `includes/sprint-2/class-hdlv2-email-templates.php:276` — `return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Your Draft Report' );`
- `includes/sprint-2/class-hdlv2-email-templates.php:583` — `. "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 18px;'>I've reviewed the answers you shared in your &ldquo;Your Why&rdq`
- `includes/sprint-2/class-hdlv2-email-templates.php:911` — `. self::cta_button( $url, 'Open Final Report' )`
- `includes/sprint-2/class-hdlv2-email-templates.php:914` — `return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Final Report Delivered' );`
- `includes/sprint-2/class-hdlv2-email-templates.php:944` — `<h2 style='margin:0 0 12px;color:" . self::DARK . ";font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;'>Hi " . esc_html( $`
- `includes/sprint-2/class-hdlv2-email-templates.php:948` — `. self::cta_button( $url, 'View Your Longevity Report' )`
- `includes/sprint-2/class-hdlv2-email-templates.php:951` — `return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Your Longevity Report' );`
- `includes/sprint-2/class-hdlv2-ai-service.php:1050` — `. "- Do not invent numeric values from addenda text. The numeric calc is unchanged from the original Final Report.";` **[AI prompt]**
- `includes/sprint-2/class-hdlv2-ai-service.php:1129` — `$system = "You integrate new practitioner Addenda (timestamped clinical updates added AFTER the original consultation) into an existing cura` **[AI prompt]**
- `includes/sprint-2/class-hdlv2-ai-service.php:1403` — `. "- Para 3: bridge to their WHY (use it if non-empty, otherwise to next steps). Mention practitioner consultation + final Longevity Report.` **[AI prompt]**
- `includes/sprint-2/class-hdlv2-staged-form.php:1306` — `. '<p>Your draft Longevity Report is being generated now. Your practitioner will review it, then walk you through it in your consultation.</`
- `includes/sprint-2/class-hdlv2-staged-form.php:1480` — `wp_mail( $client_email, 'Your Draft Longevity Report is Ready', $html,`
- `includes/sprint-2/class-hdlv2-staged-form.php:1495` — `sprintf( 'Draft Report Ready: %s', $client_name ?: $client_email )` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-client-list-enhance.js:1760` — `+ 'Final report hasn’t been generated yet. Open the Consultation tab and click <strong>Generate Final Report</strong> after reviewing the `
- `assets/js/hdlv2-draft-report.js:177` — `? 'This is your <strong style="font-weight:600;">finalised Longevity Report</strong>, reviewed by your practitioner. Use it as your roadmap `
- `assets/js/hdlv2-draft-report.js:184` — `'<h1 class="hdlv2-dr-hero-title">Your Longevity Report</h1>' +`
- `assets/js/hdlv2-consultation.js:280` — `return '<p class="hdlv2-consult-hint">Final Report already generated. Use the <strong>Consultation Addenda</strong> section below to add fol`
- `assets/js/hdlv2-consultation.js:283` — `return '<button id="hdlv2-action-btn" class="hdlv2-btn hdlv2-consult-generate-btn" type="button">Generate Final Report</button>'`
- `assets/js/hdlv2-consultation.js:625` — `actionBtn.textContent = 'Generate Final Report';`
- `assets/js/hdlv2-consultation.js:647` — `actionBtn.textContent = 'Generate Final Report';`
- `assets/js/hdlv2-consultation.js:653` — `actionBtn.textContent = 'Generate Final Report';`
- `assets/js/hdlv2-consultation.js:1495` — `btn.textContent = 'Generate Final Report';`
- `assets/js/hdlv2-consultation.js:1503` — `title: 'Generate the Final Report for ' + clientName + '?',` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-consultation.js:1505` — `confirmLabel: 'Generate Final Report',` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-consultation.js:1508` — `: Promise.resolve(window.confirm('Generate the Final Report for ' + clientName + '? This will replace the Draft.'));` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-consultation.js:1512` — `showSpinner('Generating Final Report…');` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-consultation.js:1935` — `+     '<strong>Final Report was recovered.</strong> '`
- `assets/js/hdlv2-consultation.js:1949` — `: '<p class="hdlv2-addenda-empty">No addenda yet. Add the first one below — anything that has changed since the Final Report was generated`
- `assets/js/hdlv2-consultation.js:1958` — `+ '<p class="hdlv2-consult-hint">Add timestamped observations after the Final Report has been generated. Past addenda are read-only — Matt`
- `assets/js/hdlv2-consultation.js:1982` — `+     '<textarea id="hdlv2-addendum-text" class="hdlv2-consult-notes-input" rows="6" placeholder="What has changed since the Final Report? O` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-consultation.js:2183` — `body: 'This will:
• Save your addendum (timestamped, never deleted)
• Re-run the AI with the original consultation + every addendum
�` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-consultation.js:2189` — `body: 'No new addendum to save — your AI summary edits above are already auto-saved. Continuing will:
• Re-run the AI with the existing` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-consultation.js:2333` — `'Regeneration completed server-side but the page could not confirm the new Final Report. Please refresh and check the report URL.'` *(was ambiguous — classified user-facing)*
- `assets/js/hdlv2-consultation.js:2456` — `+   '<h4>Final Report regenerated</h4>'`
- `assets/js/hdlv2-consultation.js:2462` — `+   (reportUrl ? '<a class="hdlv2-update-success-link" href="' + esc(reportUrl) + '" target="_blank" rel="noopener">View the new Final Repor`
- `assets/js/hdlv2-staged-form.js:855` — `+         '<li><strong>Your Longevity Report arrives.</strong> Reviewed by your practitioner, with your weekly Flight Plan to follow.</li>'`
- `assets/js/hdlv2-staged-form.js:942` — `+ '<p>This isn’t a verdict — it’s a starting picture. Most of these levers are within your control. Stage 2 captures your <em`
- `assets/js/hdlv2-staged-form.js:1203` — `+      '<li><strong>Your full Longevity Report arrives.</strong> Built around the WHY you just shared, with a weekly Flight Plan to follow.<`
- `assets/js/hdlv2-staged-form.js:1648` — `+     '<li>Check your inbox for your draft Longevity Report.</li>'`
- `assets/js/hdlv2-staged-form.js:1775` — `+    '<p class="hdlv2-s1-hero-body">All three stages complete. Your draft Longevity Report is being prepared in the background — your p`
- `assets/js/hdlv2-staged-form.js:1828` — `+    '<p class="hdlv2-s1-lede">Your draft Longevity Report is being prepared right now.</p>'`
- `assets/js/hdlv2-staged-form.js:1832` — `+      '<li><strong>You receive your final Longevity Report + weekly Flight Plan.</strong> Personalised, practitioner-approved, ready to act`
- `assets/js/hdlv2-staged-form.js:1834` — `+    '<a id="hdlv2-view-draft" href="/longevity-draft-report/?t=' + encodeURIComponent(token) + '" class="hdlv2-btn" style="display:inline-b`

---

## Section B — Strings to KEEP (116)

### B1. Comments / docblocks (107) — internal, developer-facing only

Comments naming Final Report / Longevity Report / Draft Report describe the underlying feature in code comments — they have no runtime effect. Leaving them as-is avoids needless churn in git blame and respects YAGNI.

Sample (top 15 of 107 — see `/tmp/w12-classified.txt` for the full set):

- `hdl-longevity-v2.php:807`
- `hdl-longevity-v2.php:862`
- `includes/class-hdlv2-activator.php:341`
- `includes/class-hdlv2-activator.php:1591`
- `includes/class-hdlv2-practitioner.php:8`
- `includes/class-hdlv2-practitioner.php:40`
- `includes/class-hdlv2-practitioner.php:66`
- `includes/class-hdlv2-compatibility.php:9`
- `includes/class-hdlv2-compatibility.php:346`
- `includes/class-hdlv2-context-builder.php:45`
- `includes/security/class-hdlv2-webhook-retry.php:6`
- `includes/security/class-hdlv2-webhook-monitor.php:5`
- `includes/security/class-hdlv2-webhook-monitor.php:6`
- `includes/sprint-1/class-hdlv2-widget-config.php:1010`
- `includes/sprint-1/class-hdlv2-widget-config.php:1043`

### B2. Identifiers / call sites (9) — class names, constants, method invocations

- `includes/sprint-2c/class-hdlv2-consultation.php:1265` — `$result = HDLV2_Final_Report::regenerate( $progress_id, $consult_id, get_current_user_id() );`
- `includes/sprint-2c/class-hdlv2-consultation.php:1408` — `$result = HDLV2_Final_Report::regenerate( $progress_id, $consult_id, get_current_user_id() );`
- `includes/sprint-2c/class-hdlv2-consultation.php:1425` — `$result = HDLV2_Final_Report::generate( $progress_id, $consult_id, get_current_user_id() );`
- `includes/sprint-2c/class-hdlv2-final-report.php:699` — `error_log( '[HDLV2] Final Report webhook skipped — HDLV2_MAKE_FINAL_REPORT not configured.' );`
- `includes/sprint-2c/class-hdlv2-final-report.php:1183` — `'[HDLV2 send_emails] link-only Final Report email suppressed for client_email=%s (HDLV2_MAKE_FINAL_REPORT configured; Make.com will deliver `
- `includes/sprint-2c/class-hdlv2-auto-consultation.php:347` — `$webhook_result = HDLV2_Final_Report::fire_for_automation_tier(`
- `includes/sprint-4/class-hdlv2-client-dashboard.php:1022` — `return HDLV2_Final_Report::build_radar_chart_url( $scores );`
- `includes/sprint-2/class-hdlv2-staged-form.php:1450` — `? HDLV2_Final_Report::build_radar_chart_url( $scores )`
- `includes/sprint-2/class-hdlv2-staged-form.php:1782` — `? HDLV2_Final_Report::build_radar_chart_url( $scores )`

---

## Phase 3 plan (after Matthew approval)

1. Apply CHANGE edits to the items above. Each edit uses precise file-level Edit calls (no global sed) so the diff is auditable.
2. Preserve case context: Report → Trajectory Plan, report → trajectory plan, REPORT → TRAJECTORY PLAN.
3. Preserve British English, em-dashes, HTML entities, surrounding markup verbatim.
4. Compound forms — see Open Question below for Matthew's call.

## Phase 4 plan — Default-sort

- Preferred: pure DOM-side sort in `hdlv2-client-list-enhance.js`. Sort the `<tr>` collection by `c.latest_event_at` already returned in the `/dashboard/clients` response (W11 didn't change this field — it existed pre-W11). No backend change needed.
- Fallback: if the V1 table renders rows pre-sorted in a way the JS can't undo, fall back to PHP injection via the existing `HDLV2_Compatibility` splice machinery. Surface diff before commit.
- User-driven sort (clicking a column header) still works — change only affects the default state.

## Open question (Matthew, pre-Phase-3)

**Compound forms — "Final Report" and "Draft Report":**
- Option A: `Final Report` → `Trajectory Plan` (drop "Final" entirely — "Trajectory Plan" already carries the finalised semantic)
- Option B: `Final Report` → `Final Trajectory Plan` (literal substitution, keeps the qualifier)
- For `Draft Report`: literal substitution `Draft Report` → `Draft Trajectory Plan` (the "Draft" qualifier is meaningful — distinguishes from the finalised plan during practitioner review window)

Recommendation: **Option A for "Final Report"**, **literal for "Draft Report"**. Confirm before Phase 3 begins.

