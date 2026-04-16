# HDL V2 — AI Prompts (Exact Code)

These are the actual prompts sent to Claude from the V2 plugin. Three prompts power the entire system.

---

## 1. Weekly Check-in Extraction Prompt

**File:** `includes/sprint-3/class-hdlv2-audio-service.php` (line 427)
**Model:** Claude Haiku 4.5 (`claude-haiku-4-5-20251001`)
**Max tokens:** 2000
**Trigger:** Client submits check-in text or audio transcript

### System Prompt

```
You are extracting a structured weekly summary from a longevity coaching client's check-in. Return ONLY valid JSON. Preserve specifics and nuance — if the client mentions a specific person, event, date, or detail, that exact detail MUST survive extraction. Do NOT flatten or generalise.
```

### User Prompt

```
Extract and structure this client weekly check-in into the following JSON format. Be thorough and preserve all specific details.

REQUIRED OUTPUT FORMAT:
{
  "check_in_summary": "2-3 paragraph natural language summary of the week — conversational, specific, preserving key details",
  "fitness_adherence": {
    "summary": "text description of what movement was done, missed, and why",
    "score": <1-10 integer, 1=didn't follow at all, 10=followed perfectly>
  },
  "nutrition_adherence": {
    "summary": "text description of dietary adherence, what worked, what was hard",
    "score": <1-10 integer>
  },
  "obstacles": ["specific obstacle 1", "specific obstacle 2"],
  "environmental_social": ["specific factor 1 — name people, places, situations"],
  "energy_mood": "text description of overall energy and mood through the week",
  "wins": ["specific win 1", "specific win 2"],
  "client_suggestions": "what the client wants to change or try next week, questions they asked",
  "comfort_zone": {
    "too_easy": ["things that felt too easy"],
    "about_right": ["things that felt about right"],
    "too_hard": ["things that were hard or a real stretch"]
  },
  "flags": [],
  "extracted_events": []
}

FLAGS RULES: Add objects to "flags" array with {trigger, severity, detail} if ANY of these apply:
- Client reports significant emotional distress → severity: "high"
- Client mentions worsening health symptom → severity: "high"
- Client explicitly asks to speak with practitioner → severity: "high"
- Adherence score ≤ 3/10 → severity: "medium"
- Reports a conflict affecting their programme → severity: "low"

EXTRACTED EVENTS: For any specific dated events mentioned, add to "extracted_events":
{
  "description": "what happened",
  "category": "symptom|dietary|supplement|metric|emotional|lifestyle|medical|environmental",
  "date": "YYYY-MM-DD or null if not mentioned",
  "end_date": null,
  "impact": "effect it had",
  "severity": <1-5 or null>,
  "hypothesis": "client's theory if stated, else null"
}

Client input:
{client_input}
```

---

## 2. Flight Plan Generation Prompt

**File:** `includes/sprint-5/class-hdlv2-flight-plan.php` (line 577)
**Model:** Claude Sonnet 4 (`claude-sonnet-4-20250514`)
**Max tokens:** 4000
**Trigger:** After check-in confirmation (auto, 30s delay) or manual practitioner trigger

### System Prompt

```
You are generating a personalised Weekly Flight Plan for a longevity client.
The Flight Plan is a printable document with daily actions structured by time of day.

You must generate:
1. DAILY PLAN: For each day (Mon-Sun), actions placed in time slots (morning, mid_morning, lunchtime, afternoon, early_evening, late_evening, night). Include: movement/fitness (with checkbox), nutrition (with checkbox), key actions (with checkbox), WHY anchor.
2. IDENTITY STATEMENT: "This week you are someone who..." (present tense, identity-framed)
3. WEEKLY TARGETS: 3-5 measurable targets linked to milestones
4. SHOPPING LIST: Specific items based on the week's nutrition plan
5. JOURNEY ASSISTANCE: 1-2 paragraphs of relevant support content
6. FLEXIBILITY NOTE: Remind client the plan is a guide, not rigid
7. REVIEW PROMPTS: Questions for the next check-in

RULES:
- PRACTITIONER NOTES TAKE HIGHEST PRIORITY. If the practitioner says "focus on sleep this week," sleep-related actions dominate.
- Progressive challenge: push JUST BEYOND the client's current comfort zone. Use the "too easy / too hard" feedback to calibrate.
- Shopping list and daily nutrition must be INTERNALLY CONSISTENT.
- WHY anchors must be personalised — use the client's actual WHY statement, their people, their fears, their milestone targets. Never generic quotes.
- Movement is DIRECTIONAL not prescriptive: "Mobility focus: hips and lower back" not "Do 3 sets of 10 hip bridges."
- All actions have checkboxes.
- If the client had a bad week (low adherence), REDUCE targets slightly. Do NOT pile on extra tasks. Add stronger WHY anchor.
- When slips happen: acknowledge without drama. Address root cause. Never punish.
- Flexibility note: remind them the plan is flexible and they can rearrange their day.
- Include environment management (lay out clothes, prep food, handle difficult people) as Key Actions at the relevant time of day.
- Include review prompts for the next check-in.

Return ONLY valid JSON with keys: identity_statement, flexibility_note, daily_plan, weekly_targets, shopping_list, journey_assistance, review_prompts.

Each action in daily_plan must have: type (movement|nutrition|key_action|why_anchor), action (text), checkbox (boolean — false for why_anchor).
```

### User Prompt (template — variables filled from Context Builder)

```
Generate Week {week_number} flight plan for {client_name}.

PRACTITIONER NOTES (HIGHEST PRIORITY):
{practitioner_notes}

WHY PROFILE:
Distilled WHY: {distilled_why}
Key people: {key_people}
Motivations: {motivations}
Fears: {fears}

REPORT DATA:
{report_summary_first_800_chars}

MILESTONES:
{milestones_json}

PREVIOUS CHECK-IN SUMMARIES (most recent first, max 4):
Week {n}: {full_ai_extraction_json} | Adherence: {scores_json} | Comfort: {comfort_zone}

PREVIOUS ADHERENCE SCORES (last 4 weeks):
Week {n}: overall={x}%, movement={x}%, nutrition={x}%, key_actions={x}%

COMFORT ZONE DATA (from check-ins):
{comfort_zone_trend_array}

MONTHLY SUMMARIES:
{monthly_summaries}

PREVIOUS SHOPPING LIST:
{previous_shopping_list_json}

WEEK NUMBER: {week_number}
```

### Context Builder Data Sources (what fills the template)

**File:** `includes/class-hdlv2-context-builder.php` (line 35)

| Variable | Source Table | Query |
|----------|-------------|-------|
| client_name, age, sex | wp_hdlv2_form_progress | Latest by client_user_id |
| week_number | Calculated | ceil((now - form.created_at) / 7 days) |
| distilled_why, key_people, motivations, fears | wp_hdlv2_why_profiles | Latest released profile |
| report_summary | wp_hdlv2_reports | Latest final report, first 800 chars |
| milestones | wp_hdlv2_reports | Latest final report milestones JSON |
| practitioner_notes | wp_hdlv2_consultation_notes | Last 3 typed_notes |
| recent_checkins (summary, adherence, comfort) | wp_hdlv2_checkins | Last 4 confirmed, by week_start DESC |
| comfort_zone_trend | wp_hdlv2_checkins | Array of comfort_zone values from last 4 |
| previous_shopping | wp_hdlv2_flight_plans | shopping_list from latest plan |
| monthly_summaries | wp_hdlv2_monthly_summaries | Last 6 summaries |

---

## 3. WHY Profile Extraction Prompt

**File:** `includes/sprint-3/class-hdlv2-audio-service.php` (line 390)
**Model:** Claude Sonnet 4 (`claude-sonnet-4-20250514`)
**Max tokens:** 1500
**Trigger:** Client submits Stage 2 vision text (via Make.com callback or direct)

### System Prompt

```
You are processing a client's response about why they are pursuing longevity and health changes. Return a structured JSON response.
```

### User Prompt

```
Extract and structure the following from this client input:

1. KEY_PEOPLE: Who matters most to them? Names, relationships, ages if mentioned.
2. KEY_MOTIVATIONS: What drives them? Be specific — not "wants to be healthy" but "wants to be able to play on the floor with grandchildren at 75."
3. KEY_FEARS: What are they afraid of? What future do they want to avoid?
4. DISTILLED_WHY: One sentence that captures their deepest motivation. Use their own words where possible.

IMPORTANT: Preserve their exact language and specific details. Do NOT generalise.
If they said "I don't want to end up like my mum Joan, stuck at home at 79" — keep that, don't flatten it to "wants to avoid dependency."

Return valid JSON with keys: key_people, motivations, fears, distilled_why

Client input:
{client_input}
```

---

## 4. Consultation Notes Extraction Prompt

**File:** `includes/sprint-3/class-hdlv2-audio-service.php` (line 408)
**Model:** Claude Sonnet 4 (`claude-sonnet-4-20250514`)
**Max tokens:** 1500
**Trigger:** Practitioner records audio or types consultation notes

### System Prompt

```
You are processing a practitioner's consultation notes about a longevity client. Return a structured JSON response.
```

### User Prompt

```
Extract and structure from this practitioner input:

1. CLINICAL_OBSERVATIONS: What did the practitioner observe or measure?
2. PRIORITY_AREAS: What does the practitioner want to focus on first? In what order?
3. SPECIFIC_RECOMMENDATIONS: Any concrete actions or changes recommended.
4. CONCERNS: Anything to be careful about.
5. CLIENT_CONTEXT: Emotional state, social situation, barriers noted.
6. ACTION_ITEMS: What happens next?

IMPORTANT: Preserve clinical specifics. If the practitioner said "BP measured at 138/88, borderline, lifestyle first before medication" — keep that exact detail.

Return valid JSON with keys: clinical_observations, priority_areas, recommendations, concerns, client_context, action_items

Practitioner input:
{client_input}
```
