# Make.com Scenario Spec — V2 Final Longevity Report PDF

> Build this scenario in Make.com. It follows the **same module pattern** as the existing V1 Longevity scenario but is simpler — no AI modules needed because WordPress already generates the report content via Claude API before firing the webhook.
>
> When done, paste the webhook URL into WordPress:
> **wp_options → `hdlv2_make_webhook_final_report`**

---

## How V2 Differs from V1

| Aspect | V1 Longevity Scenario (existing) | V2 Final Report Scenario (new) |
|--------|----------------------------------|-------------------------------|
| **AI generation** | 5 parallel OpenAI branches in Make.com (Introduction, Objectives, Analysis, Health Metrics, Age & Biology) | Already done in WordPress (Claude Sonnet 4) — webhook payload contains pre-generated HTML |
| **Router branches** | 5 AI paths → merge → PDF | No AI branches — straight pipeline to PDF |
| **Total modules** | ~20 (5 AI + 5 Parse JSON + 5 Set Variables + PDF + HTTP + Delete + Router + 2 Emails) | 9 modules (same PDF/email pattern, no AI) |
| **Webhook payload** | Raw form data (scores, answers, client info) | Pre-generated AWAKEN/LIFT/THRIVE HTML + milestones + scores |
| **PDF template** | V1 longevity report template | New V2 ALT Protocol template (AWAKEN / LIFT / THRIVE sections) |
| **Emails** | Client + Practitioner (both with PDF attachment) | Same — Client + Practitioner (both with PDF attachment) |

---

## Visual Comparison

### V1 Existing (from Make.com — ~20 modules):

```
[Webhooks] → [Tools] → [Router] ─┬→ [OpenAI: Intro & Summary]        → [Parse JSON] → [Tools]
                                  ├→ [OpenAI: Objectives & Recs]       → [Parse JSON] → [Tools]
                                  ├→ [OpenAI: Personalized Analysis]   → [Parse JSON] → [Tools]
                                  ├→ [OpenAI: Health Metrics]          → [Parse JSON] → [Tools]
                                  └→ [OpenAI: Age & Biology]           → [Parse JSON] → [Tools]
                                              ↓ (all 5 paths merge)
                                  [Tools] → [PDFMonkey: Generate] → [PDFMonkey: Get] → [HTTP: Get File]
                                      → [PDFMonkey: Delete] → [Router] ─┬→ [Email: Client]
                                                                        └→ [Email: Practitioner]
```

### V2 New (9 modules — same tail pattern, no AI branches):

```
[Webhooks] → [Tools] → [PDFMonkey: Generate] → [PDFMonkey: Get] → [HTTP: Get File]
    → [PDFMonkey: Delete] → [Router] ─┬→ [Email: Client]
                                      └→ [Email: Practitioner]
```

---

## Module-by-Module Build Guide

### Module 1: Webhooks — Custom Webhook

| Setting | Value |
|---------|-------|
| **Module type** | Webhooks → Custom webhook |
| **Scenario name** | "HDL V2 Final Report" |
| **Webhook name** | "V2 Final Report Trigger" |
| **Data structure** | Auto-detect on first run — send a test payload from WordPress to populate |

**Incoming JSON payload from WordPress:**

```json
{
  "report_type": "final",
  "client_name": "Jane Smith",
  "client_email": "jane@example.com",
  "practitioner_name": "Bob 9000",
  "practitioner_email": "bob@healthdatalab.com",
  "chronological_age": 45,
  "biological_age": 49.9,
  "rate_of_ageing": 1.11,
  "awaken_content": "<p>HTML content — current state assessment...</p>",
  "lift_content": "<p>HTML content — action plan with bullet points...</p>",
  "thrive_content": "<p>HTML content — future vision connected to WHY...</p>",
  "milestones": {
    "six_months": [
      {"milestone": "Walk 5km without stopping", "category": "movement", "measurable": true},
      {"milestone": "Reduce waist by 3cm", "category": "body_composition", "measurable": true},
      {"milestone": "Establish daily 30min exercise routine", "category": "movement", "measurable": true}
    ],
    "two_years": [
      {"milestone": "Achieve BMI under 27", "category": "body_composition", "measurable": true},
      {"milestone": "Resting heart rate below 65bpm", "category": "cardiovascular", "measurable": true}
    ],
    "five_years": [
      {"milestone": "Biological age matches chronological age", "category": "overall", "measurable": true}
    ],
    "ten_plus_years": [
      {"milestone": "Active grandparent — playing, hiking, keeping up without limitations", "category": "personal", "measurable": false}
    ]
  },
  "why_profile": {
    "distilled_why": "To remain vibrant and independent at 80 so I can fully engage with my grandchildren",
    "key_people": ["Children", "Partner"],
    "motivations": ["Independence", "Experiences"]
  },
  "recommendations": [
    {"category": "Exercise", "text": "Start with 30min brisk walking 5x/week", "priority": "High", "frequency": "Daily"},
    {"category": "Diet", "text": "Reduce processed food, increase vegetables", "priority": "Medium", "frequency": "Daily"}
  ],
  "health_data_changes": [
    {"field": "weight", "original": "100", "new_value": "98", "reason": "Practitioner measured in clinic"}
  ],
  "all_scores": {
    "bmiScore": 2, "whrScore": 3, "whtrScore": 4, "overallHealthScore": 3,
    "bloodPressureScore": 3, "heartRateScore": 0, "skinElasticity": 0,
    "physicalActivity": 3, "sleepDuration": 0, "sleepQuality": 0,
    "stressLevels": 0, "socialConnections": 0, "dietQuality": 0,
    "alcoholConsumption": 0, "smokingStatus": 0, "cognitiveActivity": 0,
    "sunlightExposure": 0, "supplementIntake": 0, "dailyHydration": 0,
    "sitToStand": 0, "breathHold": 0, "balance": 0
  },
  "consultation_notes_summary": "Client motivated but needs cardiovascular focus...",
  "generated_at": "2026-04-06T16:45:00+00:00"
}
```

---

### Module 2: Tools — Set multiple variables

> **How to add this module:** Click the `+` after Module 1 → search "Tools" → select **Set multiple variables**

The Tools module dialog shows a **Variables** section. Click **+ Add item** to add each variable. Each item has two fields:

- **Variable name** * (required)
- **Variable value** (supports formula mode — click the `>` toggle to switch)

**Configure these items (click `+ Add item` for each):**

---

**Item 1:**

| Field | Value |
|-------|-------|
| **Variable name** | `gauge_url` |
| **Variable value** | *(paste the full URL below — click `>` to enable formula mode first)* |

Variable value (gauge URL formula — paste this exactly):
```
https://quickchart.io/chart?w=380&h=340&bkg=white&c={"type":"gauge","data":{"labels":["Slower","Average","Faster"],"datasets":[{"data":[0.9,1.1,1.4],"value":{{1.rate_of_ageing}},"minValue":0.6,"maxValue":1.4,"backgroundColor":["rgba(67,191,85,0.95)","rgba(65,165,238,0.95)","rgba(255,111,75,0.95)"],"borderWidth":1,"borderColor":"rgba(255,255,255,0.8)"}]},"options":{"needle":{"radiusPercentage":2.5,"widthPercentage":4,"lengthPercentage":68,"color":"#004F59"},"valueLabel":{"display":true,"fontSize":36,"color":"#004F59","backgroundColor":"transparent"}}}
```

> `{{1.rate_of_ageing}}` is a reference to Module 1's webhook data. Make.com will auto-resolve it.

---

**Item 2:**

| Field | Value |
|-------|-------|
| **Variable name** | `report_date` |
| **Variable value** | `{{formatDate(1.generated_at; "D MMMM YYYY")}}` |

---

**Item 3:**

| Field | Value |
|-------|-------|
| **Variable name** | `filename` |
| **Variable value** | `HDL-Final-Report-{{1.client_name}}-{{formatDate(1.generated_at; "YYYY-MM-DD")}}` |

---

**Item 4:**

| Field | Value |
|-------|-------|
| **Variable name** | `milestones_6mo` |
| **Variable value** | `{{1.milestones.six_months}}` |

---

**Item 5:**

| Field | Value |
|-------|-------|
| **Variable name** | `milestones_2yr` |
| **Variable value** | `{{1.milestones.two_years}}` |

---

**Item 6:**

| Field | Value |
|-------|-------|
| **Variable name** | `milestones_5yr` |
| **Variable value** | `{{1.milestones.five_years}}` |

---

**Item 7:**

| Field | Value |
|-------|-------|
| **Variable name** | `milestones_10yr` |
| **Variable value** | `{{1.milestones.ten_plus_years}}` |

---

After adding all 7 items, click **Save**.

---

### Module 3: PDFMonkey — Generate a Document

| Setting | Value |
|---------|-------|
| **Module type** | PDFMonkey → Generate a Document |
| **Connection** | Your PDFMonkey API connection (same one used by V1) |
| **Template ID** | Create a new V2 template in PDFMonkey (see Template section below) |
| **Filename** | `{{2.filename}}` |

**Field mapping (webhook → PDFMonkey template variables):**

| PDFMonkey Variable | Source |
|-------------------|--------|
| `client_name` | `{{1.client_name}}` |
| `practitioner_name` | `{{1.practitioner_name}}` |
| `chronological_age` | `{{1.chronological_age}}` |
| `biological_age` | `{{1.biological_age}}` |
| `rate_of_ageing` | `{{1.rate_of_ageing}}` |
| `gauge_url` | `{{2.gauge_url}}` |
| `report_date` | `{{2.report_date}}` |
| `awaken_content` | `{{1.awaken_content}}` |
| `lift_content` | `{{1.lift_content}}` |
| `thrive_content` | `{{1.thrive_content}}` |
| `distilled_why` | `{{1.why_profile.distilled_why}}` |
| `milestones_6mo` | `{{2.milestones_6mo}}` |
| `milestones_2yr` | `{{2.milestones_2yr}}` |
| `milestones_5yr` | `{{2.milestones_5yr}}` |
| `milestones_10yr` | `{{2.milestones_10yr}}` |
| `recommendations` | `{{1.recommendations}}` |
| `all_scores` | `{{1.all_scores}}` |

---

### Module 4: PDFMonkey — Get a Document

| Setting | Value |
|---------|-------|
| **Module type** | PDFMonkey → Get a Document |
| **Name** | "Wait for PDF" |
| **Document ID** | `{{3.id}}` (output from Module 3) |
| **Wait for completion** | Yes (polling — same as V1) |
| **Timeout** | 60 seconds |

This waits until PDFMonkey finishes rendering and returns the download URL in `{{4.download_url}}`.

---

### Module 5: HTTP — Get a File

| Setting | Value |
|---------|-------|
| **Module type** | HTTP → Get a File (or HTTP legacy → Get a File) |
| **Name** | "Download PDF" |
| **URL** | `{{4.download_url}}` (from Module 4) |

> **Why this module?** Same pattern as V1 — PDFMonkey returns a URL, we download the actual file binary so it can be attached to emails. Without this, the email module only gets a URL string, not the file.

Output: the PDF file binary in `{{5.data}}` with filename in `{{5.fileName}}`.

---

### Module 6: PDFMonkey — Delete a Document

| Setting | Value |
|---------|-------|
| **Module type** | PDFMonkey → Delete a Document |
| **Name** | "Cleanup" |
| **Document ID** | `{{3.id}}` (same ID from Module 3) |

> **Why delete?** Same as V1 — cleanup the generated document from PDFMonkey after downloading. Keeps PDFMonkey storage clean.

---

### Module 7: Router — Split to Email Paths

| Setting | Value |
|---------|-------|
| **Module type** | Router |
| **Name** | "Send Emails" |
| **Routes** | 2 (one to client, one to practitioner) |
| **Filters** | None (both routes always fire) |

---

### Module 8: Email — Send to Client

| Setting | Value |
|---------|-------|
| **Module type** | Email → Send an Email |
| **Name** | "Email to Client" |
| **To** | `{{1.client_email}}` |
| **From name** | `HealthDataLab` |
| **From email** | `reports@healthdatalab.com` (or your configured sender) |
| **Subject** | `Your Personalised Longevity Report — HealthDataLab` |
| **Content type** | HTML |
| **Attachments** | Map file from Module 5 (`{{5.data}}`, filename: `{{2.filename}}.pdf`) |

**HTML body:**

```html
<div style="max-width:520px;margin:0 auto;font-family:Inter,-apple-system,sans-serif;color:#333;">
  <div style="text-align:center;padding:24px 0;">
    <img src="https://healthdatalab.com/images/hdl-logo.png" alt="HealthDataLab" width="140">
  </div>
  <div style="background:#fff;border-radius:14px;padding:28px;border:1px solid #e4e6ea;box-shadow:0 4px 24px rgba(0,0,0,0.06);">
    <h2 style="color:#111;margin:0 0 12px;font-family:Poppins,sans-serif;">Hi {{1.client_name}},</h2>
    <p style="line-height:1.6;">Your personalised longevity report is ready! Your practitioner <strong>{{1.practitioner_name}}</strong> has reviewed your assessment and prepared your final report.</p>
    <div style="background:#f8f9fb;border-radius:10px;padding:16px;text-align:center;margin:20px 0;">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:4px;">Your Rate of Ageing</div>
      <div style="font-size:36px;font-weight:700;color:#3d8da0;">{{1.rate_of_ageing}}</div>
      <div style="font-size:13px;color:#666;margin-top:4px;">Biological Age: <strong>{{1.biological_age}}</strong> (Chronological: {{1.chronological_age}})</div>
    </div>
    <p style="line-height:1.6;">Your report is attached as a PDF. It includes your full AWAKEN / LIFT / THRIVE analysis and personalised milestone timeline.</p>
    <p style="line-height:1.6;color:#888;font-size:13px;">If you have questions, reach out to {{1.practitioner_name}} directly.</p>
  </div>
  <div style="text-align:center;padding:20px 0;font-size:11px;color:#aaa;">
    &copy; 2026 HealthDataLab. All rights reserved.
  </div>
</div>
```

---

### Module 9: Email — Send to Practitioner

| Setting | Value |
|---------|-------|
| **Module type** | Email → Send an Email |
| **Name** | "Email to Practitioner" |
| **To** | `{{1.practitioner_email}}` |
| **From name** | `HealthDataLab` |
| **From email** | `reports@healthdatalab.com` |
| **Subject** | `Final Report Delivered: {{1.client_name}}` |
| **Content type** | HTML |
| **Attachments** | Map file from Module 5 (same PDF as client email) |

**HTML body:**

```html
<div style="max-width:520px;margin:0 auto;font-family:Inter,-apple-system,sans-serif;color:#333;">
  <div style="background:#fff;border-radius:14px;padding:28px;border:1px solid #e4e6ea;">
    <h2 style="color:#111;margin:0 0 12px;font-family:Poppins,sans-serif;">Final Report Delivered</h2>
    <p>The final longevity report for <strong>{{1.client_name}}</strong> has been generated and emailed to <strong>{{1.client_email}}</strong>.</p>
    <table style="width:100%;margin:16px 0;font-size:14px;">
      <tr><td style="padding:6px 0;color:#888;">Rate of Ageing:</td><td style="font-weight:600;color:#3d8da0;">{{1.rate_of_ageing}}</td></tr>
      <tr><td style="padding:6px 0;color:#888;">Biological Age:</td><td style="font-weight:600;">{{1.biological_age}}</td></tr>
      <tr><td style="padding:6px 0;color:#888;">Chronological Age:</td><td style="font-weight:600;">{{1.chronological_age}}</td></tr>
    </table>
    <p style="font-size:13px;color:#888;">A copy of the PDF is attached for your records.</p>
  </div>
</div>
```

---

## Complete Flow Diagram (Matching V1 Pattern)

```
Module 1         Module 2         Module 3              Module 4            Module 5
[Webhooks]  →  [Tools]  →  [PDFMonkey: Generate]  →  [PDFMonkey: Get]  →  [HTTP: Get File]
                             (V2 ALT template)        (wait for PDF)       (download binary)

                Module 6              Module 7        Module 8          Module 9
            →  [PDFMonkey: Delete]  →  [Router]  ─┬→  [Email: Client]   (with PDF)
                (cleanup)                          └→  [Email: Practitioner]  (with PDF)
```

**Total: 9 modules** (vs V1's ~20 — no AI branches needed)

---

## PDFMonkey Template — V2 ALT Protocol

Create a new template in PDFMonkey for the V2 Final Report. The template receives the variables mapped in Module 3 and renders a branded PDF.

**Template structure:**

```
Page 1: Cover
  - HealthDataLab logo
  - Practitioner name
  - Client name
  - Report date
  - Gauge image (rate of ageing)
  - Biological age vs chronological age
  - Distilled WHY quote

Page 2-3: AWAKEN — Where You Are Now
  - awaken_content (HTML — render directly)
  - Score breakdown table (from all_scores)

Page 3-4: LIFT — What Needs to Change
  - lift_content (HTML — render directly)
  - Recommendations table (from recommendations array)

Page 4-5: THRIVE — What's Possible
  - thrive_content (HTML — render directly)
  - Milestone timeline:
    - 6 Months: milestones_6mo (list)
    - 2 Years: milestones_2yr (list)
    - 5 Years: milestones_5yr (list)
    - 10+ Years: milestones_10yr (highlighted, personal goal)

Footer on every page:
  - "HealthDataLab — Longevity Report for [client_name]"
  - Page number
```

**Brand tokens for the template:**
- Primary colour: `#3d8da0` (teal)
- Headings font: Poppins (600, 700 weight)
- Body font: Inter (400, 500)
- AWAKEN section accent: `#3d8da0` (teal)
- LIFT section accent: `#10b981` (green)
- THRIVE section accent: `#f59e0b` (amber)
- Card background: `#ffffff`
- Page background: `#f4f5f7`

---

## Where to Paste the Webhook URL

Once the scenario is created and the webhook module gives you a URL (e.g., `https://hook.eu2.make.com/abc123xyz...`):

**Option A — Database (recommended for local testing):**
```sql
INSERT INTO wp_options (option_name, option_value, autoload)
VALUES ('hdlv2_make_webhook_final_report', 'https://hook.eu2.make.com/YOUR_WEBHOOK_ID', 'no')
ON DUPLICATE KEY UPDATE option_value = 'https://hook.eu2.make.com/YOUR_WEBHOOK_ID';
```

**Option B — PHP constant:**
In the V2 plugin code, `class-hdlv2-final-report.php` line 192 uses:
```php
$webhook_url = get_option( 'hdlv2_make_webhook_final_report', 'https://hook.eu2.make.com/PLACEHOLDER_FINAL_REPORT' );
```
Set the wp_option and the placeholder gets overridden.

---

## Draft Report Webhook (Optional — Separate Scenario)

The draft report also fires a webhook (before practitioner consultation). This is optional — the draft is shown on screen, not as a PDF. If you want a draft PDF too, create a second scenario with the same 9-module pattern.

**Draft webhook URL placeholder location:**
`hdl-longevity-v2/includes/sprint-2/class-hdlv2-staged-form.php` — inside `fire_make_webhook()` method.

**Draft payload** (smaller — no milestones, no recommendations, no health_data_changes):
```json
{
  "report_type": "draft",
  "client_name": "...",
  "client_email": "...",
  "practitioner_name": "...",
  "practitioner_email": "...",
  "rate_of_ageing": 1.11,
  "biological_age": 49.9,
  "chronological_age": 45,
  "awaken_content": "<p>...</p>",
  "lift_content": "<p>...</p>",
  "thrive_content": "<p>...</p>",
  "why_profile": "distilled WHY text",
  "health_scores": { ... },
  "bmi": 29.4,
  "whr": null,
  "whtr": 0.54,
  "milestones": null
}
```

---

## All Webhooks (V1 + V2)

| Scenario | Webhook URL | Status | Modules |
|----------|------------|--------|---------|
| V1 Longevity Report | `https://hook.eu2.make.com/rb1qjeq2waa8s7g1pd527j2te8fukovg` | Live | ~20 (5 AI branches) |
| V1 Health Report | `https://hook.eu2.make.com/772uyriw655wberxengvfd1geb73qthe` | Live | ~20 (5 AI branches) |
| **V2 Final Report** | `hdlv2_make_webhook_final_report` wp_option | **Needs scenario + URL** | 9 |
| V2 Draft Report | `PLACEHOLDER_DRAFT_REPORT` | Optional | 9 (same pattern) |

---

## Testing the Webhook

To test without running the full WordPress flow, send a manual POST to the webhook URL:

```bash
curl -X POST "https://hook.eu2.make.com/YOUR_WEBHOOK_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "report_type": "final",
    "client_name": "Test Client",
    "client_email": "your-test-email@example.com",
    "practitioner_name": "Bob 9000",
    "practitioner_email": "your-email@example.com",
    "chronological_age": 45,
    "biological_age": 49.9,
    "rate_of_ageing": 1.11,
    "awaken_content": "<p><strong>Your biological age is 49.9</strong> — approximately 5 years older than your chronological age of 45.</p>",
    "lift_content": "<p>Focus areas: <strong>cardiovascular health</strong> and <strong>sleep quality</strong>.</p><ul><li>30 minutes brisk walking, 5 days per week</li><li>Consistent 10pm bedtime routine</li></ul>",
    "thrive_content": "<p>With consistent effort, you could see your biological age align with your chronological age within 2-3 years.</p>",
    "milestones": {"six_months":[{"milestone":"Walk 5km without stopping","category":"movement","measurable":true}],"two_years":[{"milestone":"BMI under 27","category":"body","measurable":true}],"five_years":[{"milestone":"Bio age = chrono age","category":"overall","measurable":true}],"ten_plus_years":[{"milestone":"Active grandparent at 80","category":"personal","measurable":false}]},
    "why_profile": {"distilled_why":"To be active and present for my grandchildren","key_people":["Children","Partner"],"motivations":["Independence"]},
    "recommendations": [{"category":"Exercise","text":"Brisk walking 30min 5x/week","priority":"High","frequency":"Daily"}],
    "health_data_changes": [],
    "all_scores": {"bmiScore":2,"whrScore":3,"whtrScore":4,"overallHealthScore":3,"physicalActivity":3,"sleepDuration":0,"sleepQuality":0},
    "consultation_notes_summary": "Focus on cardiovascular and sleep",
    "generated_at": "2026-04-07T12:00:00+00:00"
  }'
```

You should receive two emails (client + practitioner) each with the PDF attached.
