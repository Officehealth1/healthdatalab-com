# HDL V2 Testing Guide — Stage 1 Mini-Form Widget

**Date:** 8 April 2026
**For:** Matthew (review with Cowork)
**Status:** Ready for local testing

---

## What Was Built

The Stage 1 Mini-Form from `HDL-Stage1-MiniForm-Spec.md` is now implemented as the lead magnet widget. It replaces the old 5-field widget (Age/Height/Weight/Waist/Activity Level) with the research-backed 9-question assessment.

### The Flow (Three-Stage Funnel)

```
CLIENT VISITS PRACTITIONER'S WEBSITE
        |
        v
[Widget loads — "Curious about your rate of ageing?"]
        |
        v
Q1: Age & Sex (numeric + Male/Female)
Q2a: Body Shape (5 silhouettes per sex — Stunkard Scale)
Q2b: Fat Distribution (Apple / Pear / Even)
Q3: Zone 2 Activity (5 MCQ options)
Q4: Cardiovascular — Stair Climbing (5 MCQ)
Q5: Functional Strength — Sit-to-Stand (5 MCQ)
Q6: Sleep (5 MCQ, option E = oversleep penalty)
Q7: Smoking (5 MCQ)
Q8: Social Connection (5 MCQ)
Q9: Diet Pattern (5 MCQ)
        |
        v
[Lead Capture: Name, Email, Phone (optional)]
        |
        v
[On-screen: Pace of Ageing speedometer gauge (0.8x - 1.4x)]
[Disclaimer: "This is an estimate based on limited data..."]
[CTA: Book a session + Continue to Stage 2]
        |
        v  (happens in background)
1. Client record created and linked to practitioner
2. Client email sent: gauge + results + Two Trajectories video + booking CTA + Stage 2 link
3. Practitioner email sent: new lead notification with gauge + contact details
4. Make.com webhook fired: Stage 1 data for PDF generation
5. Invite token marked as completed (if from Assessment Link)
```

---

## How to Test

### Test 1: Assessment Links (Practitioner sends invite to client)

1. Log in as practitioner on `healthdatalab-local.local`
2. Go to **Clients** page → click **Client Tools** button
3. Click the **Assessment Links** tab
4. Enter a client name and email → select expiry → click **Generate Link**
5. You should see: **"Link created and email sent!"**
6. Check client email inbox — should receive a branded invite email with magic link
7. Open the invite link in an **incognito/private window**
8. You should see the widget with Q1 (Age & Sex) — NOT a login page
9. Complete all 9 questions → enter contact details → see gauge result
10. Check client email again — should receive results email with:
    - Speedometer gauge image
    - "Continue to Stage 2" button
    - Two Trajectories video link
    - Booking encouragement + practitioner's booking link

### Test 2: Embed Widget (Practitioner's external website)

1. Go to **Client Tools** → **Embed Code** tab
2. Copy the embed snippet
3. Paste into any HTML page
4. The widget should render with practitioner's logo and branding
5. Complete the assessment as a new visitor
6. Same email flow as above

### Test 3: Scoring Verification

Use these test cases to verify the scoring algorithm:

| Test Case | Expected Rate | Why |
|-----------|--------------|-----|
| All "E" answers, age 40, male | ~0.80x | Best possible score |
| All "C" answers, age 40, male | ~1.00x | Average across the board |
| All "A" answers, age 40, male | ~1.40x | Worst possible score |
| Mixed: E on Q3-Q5, A on Q7 (smoker) | ~1.10x | Strong fitness but smoking drags score up |

---

## Scoring Algorithm Summary

- **9 questions**, weighted by research evidence:
  - Smoking (2.5x) — strongest single risk factor
  - Zone 2 Activity, VO2/Stairs, Sit-to-Stand (2.0x each) — physical capacity
  - Body Shape, Sleep, Social, Diet (1.5x each) — lifestyle factors
- **Age adjustment** on Q3, Q4, Q5 — same answer scores better for older clients
- **Q6 Option E** (oversleep) scores as 2 not 5 — U-shaped mortality curve
- **Q2 combined**: silhouette BMI proxy + fat distribution modifier (apple +0.5 risk, pear -0.3 risk)
- **Output**: Pace of Ageing 0.8x (best) to 1.4x (worst), where 1.0x = population average

---

## What's Different From the Old Widget

| Old Widget (replaced) | New Mini-Form |
|-----------------------|---------------|
| 5 fields: Age, Height, Weight, Waist, Activity Level | 9 evidence-based questions (Q1-Q9) |
| Required tape measure for waist | Visual silhouette selection — no equipment needed |
| Single activity dropdown | Separate Zone 2, VO2 max, and strength questions |
| No sleep/smoking/social/diet | All major longevity factors covered |
| Basic BMI calculation | Weighted scoring with age norms + research citations |
| ~30 seconds | ~90 seconds (under 2 minutes) |

---

## Email System

### Client receives (after completing Stage 1):
1. **Results email** — HTML with gauge image, pace of ageing score, WHY intro, Two Trajectories video link, booking CTA, "Continue to Stage 2" button
2. **PDF email** — via Make.com webhook (one-page PDF with results + answers)

### Client receives (from Assessment Link):
1. **Invite email** — branded HTML with practitioner name, "Please click here to go to the form" CTA button, expiry date

### Practitioner receives:
1. **Lead notification** — new lead details + gauge + "Reply to [client]" button

---

## Data Flow

- Every Stage 1 completion creates a **client record** linked to the practitioner whose widget was used
- If Bob's website -> Jane fills in -> Jane is Bob's client in HDL
- Client record stored in `wp_hdlv2_form_progress` with `practitioner_user_id`
- Practitioner-client link created in V1 table for backwards compatibility
- All question answers stored as `stage1_data` JSON for later use in Stage 2/3

---

## Known Limitations

1. **Silhouette images** are placeholder SVGs — need better medical-grade illustrations
2. **Two Trajectories video URL** is placeholder (`altituding.com/two-trajectories`) — needs Matthew's final video
3. **Make.com PDF webhook** needs the Make.com scenario configured to receive the payload and generate the PDF
4. **Sex-specific age norms** not yet implemented (age norms apply but don't differentiate male/female thresholds)

---

## Files Changed in This Session

| File | Change |
|------|--------|
| `hdl-longevity-v2/includes/sprint-1/class-hdlv2-widget-config.php` | Assessment Links email, client results email (video + booking), Make.com Stage 1 webhook |
| `hdl-longevity-v2/assets/js/hdlv2-dashboard.js` | "Link created and email sent!" confirmation |
| `hdl-longevity-v2/widget/hdl-lead-magnet.js` | Updated Name/Phone field hints to match spec |

---

## wp-config.php Constants Required

Add these to `wp-config.php` on the server:

```php
// Stage 1 PDF generation via Make.com
define('HDLV2_MAKE_STAGE1_PDF', 'https://hook.eu2.make.com/mih1y4vi9de5tpivf6iv79i5cdl2wbes');
```
