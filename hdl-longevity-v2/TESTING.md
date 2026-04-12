# HDL V2 Testing Guide — Step by Step

## Prerequisites

### 1. Local Site Running
- Local by Flywheel: `healthdatalab-local.local`
- V1 plugin (`health-data-lab-plugin`) must be active
- V2 plugin (`hdl-longevity-v2`) must be active (v0.7.0)

### 2. Add API Keys to wp-config.php
Open Site Shell in Local, then:
```bash
nano /app/public/wp-config.php
```
Add before `/* That's all, stop editing! */`:
```php
define( 'HDLV2_ANTHROPIC_API_KEY', 'your-anthropic-key-here' );
define( 'HDLV2_OPENAI_API_KEY', 'your-openai-key-here' );
```
(Without these, AI features return placeholders but the flow still works.)

### 3. Create Test Pages in WordPress
Go to WP Admin > Pages > Add New. Create these pages:

| Page Title | Slug | Content (paste into text/code editor) |
|---|---|---|
| Assessment | `/assessment/` | `[hdlv2_assessment]` |
| Weekly Check-in | `/checkin/` | `[hdlv2_checkin]` |
| Flight Plan | `/flight-plan/` | `[hdlv2_flight_plan]` |
| Timeline | `/timeline/` | `[hdlv2_timeline]` |

### 4. Create Test Users
In WP Admin > Users > Add New:
- **Practitioner:** username `dr-test`, role `um_practitioner` (or `administrator`)
- **Client:** username `client-test`, email `client@test.com`, role `um_client`

### 5. Enable Debug Logging
In wp-config.php:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```
Logs go to `/app/public/wp-content/debug.log`

---

## Test Flow 1: Widget (Public Visitor)

**What:** A visitor fills out the 9-question widget on a practitioner's external site.

### Steps

1. **Open widget test page:**
   `https://healthdatalab-local.local/wp-content/plugins/hdl-longevity-v2/widget/test.html`
   (Hard refresh with Cmd+Shift+R if cached)

2. **Q1 — Age + Sex:**
   - Enter age: `45`
   - Click "Male" toggle
   - Click "Next"
   - **Expected:** Advances to Q2a. If age empty or no sex selected, shows error.

3. **Q2a — Body Shape:**
   - 5 silhouette images should appear (male set since you chose Male)
   - Click silhouette 2 (Healthy)
   - **Expected:** Auto-advances to Q2b after 250ms. Selected image gets teal border.

4. **Q2b — Fat Distribution:**
   - 3 images: apple, pear, evenly spread
   - Click "Evenly spread"
   - **Expected:** Auto-advances to Q3.

5. **Q3-Q9 — Multiple Choice:**
   - Each screen shows a question with A-E options
   - Tap any option on each screen
   - **Expected:** Each tap auto-advances to next question. Progress bar updates.
   - Test Q6 specifically: selecting E ("I sleep 9+ hours but still feel tired") should still work.

6. **Lead Capture:**
   - After Q9, shows name/email/phone form
   - Enter: Name `Test User`, Email `test@example.com`, Phone (leave empty)
   - Click "See My Results"
   - **Expected:** Gauge appears with rate between 0.8-1.4. Message describes pace.

7. **Verify caveat text:** Results page should show: "This is an estimate based on limited data."

### What to Check in Database (via WP Admin > phpMyAdmin or Site Shell):
```sql
SELECT * FROM wp_hdlv2_form_progress ORDER BY id DESC LIMIT 1;
-- Should show: current_stage=2, stage1_completed_at is set, stage1_data has q1_age/q1_sex/q2a/q2b/q3-q9

SELECT * FROM wp_hdlv2_widget_leads ORDER BY id DESC LIMIT 1;
-- Should show: visitor_name, visitor_email, rate_of_ageing

SELECT * FROM wp_users WHERE user_email = 'test@example.com';
-- Should show: new user created with role um_client
```

### Possible Failures
| Symptom | Cause | Fix |
|---|---|---|
| Old 5-field form shows | Browser cache | Hard refresh (Cmd+Shift+R) |
| Silhouette images don't load | IMG_BASE URL wrong | Check browser console for 404 on .svg files |
| "See My Results" does nothing | API URL empty | Check `data-api` attribute on widget container |
| No user created in DB | API error | Check debug.log for errors |

---

## Test Flow 2: Staged Assessment — Client Side

**What:** Client completes the full 3-stage assessment via token URL.

### Setup
You need a form token. Either:
- **Option A:** Use the token from Test Flow 1 (it's in `wp_hdlv2_form_progress.token`)
- **Option B:** Create one via REST API as practitioner:
  ```bash
  # In Site Shell, get a nonce first by logging in as practitioner
  curl -X POST https://healthdatalab-local.local/wp-json/hdl-v2/v1/form/create \
    -H "Content-Type: application/json" \
    -H "Cookie: <your-auth-cookie>" \
    -d '{"client_name":"Test Client","client_email":"client@test.com"}'
  ```

### Stage 1 Steps

1. **Open assessment URL:**
   `https://healthdatalab-local.local/assessment/?token=<TOKEN>`

2. **3-stage progress bar** should show at top (Stage 1 active)

3. **Same 9-question wizard** as widget (Q1 through Q9)
   - Progress bar inside Stage 1 shows "Question X of 10"
   - Auto-save should trigger (check "Saved" indicator)

4. **After Q9:** Shows loading, then gauge result
   - **Expected:** Gauge with rate, "This is an estimate..." caveat
   - "Continue to Stage 2" button (NOT auto-advance, because WHY gate)

### Stage 2 Steps

5. **Click "Continue to Stage 2"**
   - Environment guidance shown (green box about quiet time)
   - "Who matters most?" — multi-select pills
   - "What do you want to be able to do?" — multi-select pills
   - "What are you most afraid of?" — multi-select pills
   - Open text section with audio component (Record/Upload/Type)

6. **Fill in Stage 2:**
   - Select 2-3 options in each multi-select group
   - Type something in the text area (at least 10 chars): "I want to be around for my kids"
   - Click "Submit Your WHY"

7. **Expected result screen:**
   - "Your responses have been saved. Your practitioner will use these..."
   - NO "Continue to Stage 3" button (WHY gate blocks it)

### Check WHY Gate in Database:
```sql
SELECT released, released_at FROM wp_hdlv2_why_profiles ORDER BY id DESC LIMIT 1;
-- Should show: released=0, released_at=NULL
```

### Stage 3 (After Practitioner Releases WHY Gate)

8. After the practitioner releases the gate (see Test Flow 3), client receives email with Stage 3 link

9. **Open assessment URL again** — should now show Stage 3 wizard

10. **Stage 3 body section:**
    - Age and Sex shown as readonly (from Stage 1)
    - Height, Weight, Waist are now EDITABLE inputs (not readonly!)
    - Fill in: Height `175`, Weight `80`, Waist `90`

11. **Complete remaining sections** (Fitness, Sleep, Mental, Lifestyle)
    - Use "I don't know" skip on fields you want to skip

12. **Click "Complete Assessment"** → Shows loading → Draft Report

### Possible Failures
| Symptom | Cause | Fix |
|---|---|---|
| "Invalid assessment link" | Bad or expired token | Check token exists in form_progress table |
| Stage 2 shows old key-people toggles | Cached JS | Hard refresh |
| Stage 3 shows height/weight as readonly | Old JS cached | Hard refresh |
| Draft report empty | No API key | Add HDLV2_ANTHROPIC_API_KEY |

---

## Test Flow 3: Practitioner Side

**What:** Practitioner reviews client data, releases WHY gate, runs consultation, generates final report.

### Setup
- Log in to WP Admin as practitioner user

### Release WHY Gate

1. **Via REST API** (Site Shell or browser dev tools):
   ```
   POST /wp-json/hdl-v2/v1/form/release-why
   Body: {"progress_id": <ID from form_progress table>}
   Headers: X-WP-Nonce: <nonce>, Cookie: <auth-cookie>
   ```

2. **Expected:**
   - Response: `{"success":true,"released":true}`
   - Database: `wp_hdlv2_why_profiles.released = 1`
   - Email sent to client with Stage 3 link

### Client Status Dashboard

3. **Check client statuses via REST:**
   ```
   GET /wp-json/hdl-v2/v1/dashboard/clients
   ```
   **Expected:** Array of clients with status labels:
   - `not_started` (grey) — no Stage 1
   - `low_data` (amber) — Stage 1 done but not Stage 3
   - `awaiting_consult` (blue) — all stages done, no final report
   - `progress_normal` (green) — check-ins coming
   - `needs_attention` (red) — flags or missed check-ins

### Consultation

4. **Open consultation page:**
   `https://healthdatalab-local.local/consultation/?progress_id=<ID>`
   (Needs a page with `[hdlv2_consultation]` shortcode)

5. **Left panel:** Draft report with speedometer
6. **Right panel:**
   - Health fields — click to edit (shows original + new value + reason)
   - Notes textarea — type notes, auto-saves after 30s
   - **Audio recording section** — should show Record/Upload/Type buttons (NOT the old "coming after Sprint 3" text)
   - Recommendations — add category + text + priority
   - "Generate Final Report" button (enabled after making changes)

7. **Record or type consultation notes** → Audio component processes → Appends to notes

8. **Click "Generate Final Report"**
   - **Expected:** Loading → Final report with AWAKEN/LIFT/THRIVE sections + milestones

### Possible Failures
| Symptom | Cause | Fix |
|---|---|---|
| release-why returns 403 | Not logged in as practitioner | Check user role |
| Consultation shows "Audio recording available after Sprint 3" | Old JS cached | Hard refresh |
| Generate button stays disabled | No changes made | Edit a field or add a note |

---

## Test Flow 4: Weekly Check-in (Client)

**What:** Client submits weekly reflection.

### Steps

1. **Open check-in page:**
   `https://healthdatalab-local.local/checkin/?token=<TOKEN>`

2. **Expected:** Card with bullet-point prompts + audio component

3. **Type a check-in** in the text box:
   "This week fitness was good, walked 4 times. Sleep was hard, only 5 hours most nights. Felt stressed about work. Eating was decent."

4. **Click "Process"** (in the audio component)
   - **Expected:** Spinner → AI-extracted summary displayed

5. **Review summary** → Click "Confirm"
   - **Expected:** Shows "Check-in saved. Your practitioner has been notified."

### Check Database:
```sql
SELECT id, week_number, summary, has_flags, status FROM wp_hdlv2_checkins ORDER BY id DESC LIMIT 1;
-- Should show: status='confirmed', summary has structured JSON

SELECT * FROM wp_hdlv2_timeline WHERE entry_type='checkin' ORDER BY id DESC LIMIT 1;
-- Should show: timeline entry created
```

### Test Flag Detection
6. Submit another check-in with concerning content:
   "Terrible week. Barely moved. Feeling really low, don't see the point. Only eating takeaway."

7. **Expected:** `has_flags = 1` in database. Practitioner should see attention flag.

---

## Test Flow 5: Flight Plan (Practitioner Generates, Client Uses)

### Practitioner: Generate Plan

1. **Via REST API:**
   ```
   POST /wp-json/hdl-v2/v1/flight-plan/<client_user_id>/generate
   Headers: X-WP-Nonce, Cookie (practitioner auth)
   ```

2. **Expected:** `{"success":true,"plan_id":<ID>}`

3. **Check database:**
   ```sql
   SELECT id, week_number, identity_statement, status FROM wp_hdlv2_flight_plans ORDER BY id DESC LIMIT 1;
   -- plan_data should be JSON with daily_plan, shopping_list, etc.

   SELECT COUNT(*) FROM wp_hdlv2_flight_plan_ticks WHERE flight_plan_id = <ID>;
   -- Should have tick rows for each action item
   ```

### Client: View and Use Plan

4. **Open flight plan page:**
   `https://healthdatalab-local.local/flight-plan/?client_id=<ID>`

5. **Expected:**
   - Week number + identity statement
   - 7-column grid (Mon-Sun) with action items
   - Each action has a checkbox
   - "Tick All" and "Untick All" buttons
   - Adherence percentages at bottom (Movement %, Nutrition %, Key Actions %, Overall %)

6. **Click "Tick All"** → all checkboxes tick → adherence shows 100%

7. **Untick a few items** → adherence updates in real-time

8. **Print button** → opens browser print dialog

### Possible Failures
| Symptom | Cause | Fix |
|---|---|---|
| Generate returns error | No API key | Add HDLV2_ANTHROPIC_API_KEY |
| Plan data is `{"raw":"..."}` | Claude didn't return valid JSON | Check debug.log for API response |
| No ticks created | Plan JSON structure unexpected | Check plan_data format |

---

## Test Flow 6: Timeline (Practitioner + Client Views)

### Practitioner View

1. **Via REST API or shortcode page:**
   ```
   GET /wp-json/hdl-v2/v1/timeline/<client_id>
   ```

2. **Expected:** Array of entries — milestones, check-ins, notes, documents
   - Each entry has: type, title, summary, created_at

3. **Add a note:**
   ```
   POST /wp-json/hdl-v2/v1/timeline/add-note
   Body: {"client_id":<ID>,"title":"Follow-up note","summary":"Client doing well, increase walking target."}
   ```

### Client View

4. **Via REST API:**
   ```
   GET /wp-json/hdl-v2/v1/timeline/<client_id>/client
   ```
   **Expected:** Same entries BUT with `is_private=true` entries filtered out.

---

## Test Flow 7: Email Templates

Each email can be tested by triggering its associated action:

| Email | Trigger | How to Test |
|---|---|---|
| Stage 1 results | Complete Stage 1 | Do Test Flow 2, Stage 1 |
| Stage 2 saved | Complete Stage 2 | Do Test Flow 2, Stage 2 — email says "saved, practitioner will use" |
| WHY gate released | Release-why endpoint | Do Test Flow 3 |
| Check-in reminder | Cron `hdlv2_checkin_reminder` | `wp cron event run hdlv2_checkin_reminder` in Site Shell |
| Flight plan ready | After generate (manual trigger needed) | Add email send to flight plan generate flow |
| Quarterly review | Cron `hdlv2_quarterly_review` | Set a client's last assessment to 4+ months ago, run cron |
| Client needs attention | Cron + status check | Submit check-in with flags, verify practitioner notified |

**Check emails:** WP on Local doesn't actually send emails. Install the "WP Mail Log" plugin to see emails in WP Admin, or check debug.log.

---

## Test Flow 8: Scoring Algorithm Verification

### Via Site Shell (WP-CLI):
```bash
wp shell
```

Then paste:
```php
// All middle answers — age 45
$r = HDLV2_Rate_Calculator::calculate_quick([
  'q1_age'=>45, 'q1_sex'=>'male', 'q2a'=>2, 'q2b'=>'even',
  'q3'=>'c', 'q4'=>'c', 'q5'=>'c', 'q6'=>'d', 'q7'=>'e', 'q8'=>'d', 'q9'=>'c'
]);
echo "Rate: {$r['rate']}\n"; // Expected: ~0.96-1.04

// All best answers
$r = HDLV2_Rate_Calculator::calculate_quick([
  'q1_age'=>45, 'q1_sex'=>'male', 'q2a'=>2, 'q2b'=>'pear',
  'q3'=>'e', 'q4'=>'e', 'q5'=>'e', 'q6'=>'d', 'q7'=>'e', 'q8'=>'e', 'q9'=>'e'
]);
echo "Best: {$r['rate']}\n"; // Expected: ~0.82

// All worst answers
$r = HDLV2_Rate_Calculator::calculate_quick([
  'q1_age'=>45, 'q1_sex'=>'male', 'q2a'=>5, 'q2b'=>'apple',
  'q3'=>'a', 'q4'=>'a', 'q5'=>'a', 'q6'=>'a', 'q7'=>'a', 'q8'=>'a', 'q9'=>'a'
]);
echo "Worst: {$r['rate']}\n"; // Expected: ~1.38

// Age adjustment test: 70-year-old, all C answers
$r = HDLV2_Rate_Calculator::calculate_quick([
  'q1_age'=>70, 'q1_sex'=>'female', 'q2a'=>3, 'q2b'=>'even',
  'q3'=>'c', 'q4'=>'c', 'q5'=>'c', 'q6'=>'c', 'q7'=>'c', 'q8'=>'c', 'q9'=>'c'
]);
echo "70yo avg: {$r['rate']}\n"; // Expected: ~0.94 (above norm for age)
```

---

## Quick Reference: Database Tables to Check

```sql
-- Stage 1 data (9 questions)
SELECT id, token, current_stage, stage1_completed_at,
       JSON_EXTRACT(stage1_data, '$.q1_age') as age,
       JSON_EXTRACT(stage1_data, '$.server_result.rate') as rate
FROM wp_hdlv2_form_progress ORDER BY id DESC LIMIT 5;

-- WHY gate status
SELECT id, form_progress_id, released, released_at, distilled_why
FROM wp_hdlv2_why_profiles ORDER BY id DESC LIMIT 5;

-- Check-ins
SELECT id, client_id, week_number, has_flags, status, confirmed_at
FROM wp_hdlv2_checkins ORDER BY id DESC LIMIT 5;

-- Timeline entries
SELECT id, client_id, entry_type, title, created_at
FROM wp_hdlv2_timeline ORDER BY id DESC LIMIT 10;

-- Flight plans
SELECT id, client_id, week_number, status, identity_statement
FROM wp_hdlv2_flight_plans ORDER BY id DESC LIMIT 5;

-- Flight plan ticks (adherence)
SELECT flight_plan_id, category, COUNT(*) as total,
       SUM(ticked) as done, ROUND(SUM(ticked)/COUNT(*)*100) as pct
FROM wp_hdlv2_flight_plan_ticks
GROUP BY flight_plan_id, category;
```

---

## Recommended Test Order

1. **Widget** (Test Flow 1) — fastest, no auth needed
2. **Scoring algorithm** (Test Flow 8) — verify math via CLI
3. **Staged Assessment Stage 1+2** (Test Flow 2) — verify 9Q form + WHY gate
4. **Practitioner: Release WHY + consultation** (Test Flow 3) — requires login
5. **Staged Assessment Stage 3** (Test Flow 2 continued)
6. **Weekly Check-in** (Test Flow 4)
7. **Flight Plan generate + use** (Test Flow 5)
8. **Timeline** (Test Flow 6) — verify all entries appear
9. **Emails** (Test Flow 7) — install WP Mail Log plugin first
