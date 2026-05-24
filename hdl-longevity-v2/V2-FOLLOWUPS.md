# V2 Follow-ups

Tracked work items that are not blocking the current build but should be
addressed once the active commit chain ships and stabilises. Each entry
includes context, scope, and a "queue after" hint so they can be ordered.

---

## `HDLV2_AI_Service::MODEL_HAIKU` constant-name vs value mismatch — silent Sonnet misrouting

**Discovered:** 2026-05-24 during W9 build (looking for a Haiku-routable helper for the automation-tier rec/milestone Claude call).

**Symptom.** `class-hdlv2-ai-service.php` lines 24–25:

```php
const MODEL       = 'claude-sonnet-4-6';
const MODEL_HAIKU = 'claude-sonnet-4-6';   // ← name says Haiku, value is Sonnet
```

The comment block immediately above (lines 21–23) documents the original intent: *"MODEL_HAIKU kept as a separate const so the [cost-optimisation Haiku migration] without touching every other caller of MODEL."* So the value is the wrong half of the pair — the rename was applied to `MODEL` (correct: Sonnet) but `MODEL_HAIKU` was set identically instead of to a Haiku model id.

**Active caller** — there is exactly one: `class-hdlv2-ai-service.php:1422` in `generate_stage3_commentary()`:

```php
$response = self::call_claude( $key, $system, $user_prompt, 800, self::MODEL_HAIKU );
```

This call fires every time a client lands on the Stage 3 result page. It's been running on Sonnet (intended: Haiku) since the constant was introduced — roughly 10× cost overrun per call (~$0.005–0.01 Sonnet vs ~$0.0005–0.001 Haiku, 800-token cap).

**Fix path (single-line commit post-W13).** Decide which of the two is canonical:

- **Most likely correct:** change the value — `const MODEL_HAIKU = 'claude-haiku-4-5-20251001';` — matches the constant name and the prior dev's stated intent. Net effect: Stage 3 commentary call migrates to Haiku, ~10× cost reduction on that one path. Risk: Haiku may produce different commentary text quality; eyeball-review one Stage 3 result before/after.
- **Alternative:** rename the constant to `MODEL_SONNET_FALLBACK` (or just delete it and have the caller use `MODEL` directly). Reflects current behaviour without changing it. Lower-risk but doesn't realise the cost saving the original code was designed for.

The name vs value mismatch makes intent ambiguous; the comment block weighs toward Option 1 but a quick eyeball-diff on Stage 3 commentary quality (Sonnet vs Haiku, same input) would settle it before committing.

**Queue after:** the automation tier build ships (post-W13). Either fix path is a single-line commit + push-additive deploy.

---

## Audio `contextType: 'why_collection'` reuse on automation tier

**Discovered:** 2026-05-24 during W8 build (`[hdlv2_auto_consultation]` shortcode).

**Decision context.** The audio recorder mounted by the new automation-tier shortcode uses `HDLAudioComponent.create()` with `contextType: 'why_collection'` — the closest existing semantic for open-ended client input. Existing values are `why_collection` / `consultation_notes` / `weekly_checkin`, all routed by the server-side audio service. Introducing a fourth value (`self_reported`) would require a matching server-side handler change before this build's atomic-commit chain can close.

**Trade-off.** Audio extractions from automation-tier clients land in `wp_hdlv2_audio_extractions` tagged `why_collection` internally — same bucket as Stage 2 WHY recordings. The source-of-truth distinction for automation-tier consultations lives in `wp_hdlv2_consultation_addenda.submitter='client_automation'` (W3 schema), which is queryable and unambiguous. The audio-extractions table is correlational, not authoritative — it doesn't need source separation for any current downstream consumer.

**Why not block the build.** No current code path joins `audio_extractions` with `consultation_addenda` to attribute automation-tier audio specifically. Analytics + reporting (e.g., "how many minutes of audio per client?") would still aggregate correctly because the rows are owned by the same user. The only risk is a future analyst grouping `audio_extractions` by `context_type` and over-counting "WHY" audio — which a `JOIN` against `consultation_addenda.submitter` would correct.

**Fix path (cleanup, post-launch).** Add `'self_reported'` to the audio-service's accepted `context_type` enum, route to the same Deepgram handler with no special processing, and flip W8's JS to send the new value. Single-file change in `sprint-3/class-hdlv2-audio-service.php` + version bump in `assets/js/hdlv2-auto-consultation.js`.

**Queue after:** the automation tier build ships (post-W13) AND analytics/reporting need cleaner separation (no consumer today).

---

## rest_get_version SQL ambiguity — `class-hdlv2-client-status.php` line 874

**Discovered:** 2026-05-24 during W2 catch-up post-deploy verification.
**Pre-existing — not introduced by W2.** First surfaced 2026-05-23 09:54:49 UTC in LIVE V2 LSWS error log; persists in v0.41.25 (the W2 catch-up did not fix it).

**Symptom.** Every call to `GET /wp-json/hdl-v2/v1/client-status/version` emits a `WordPress database error: Column 'created_at' in SELECT is ambiguous`. The endpoint then returns `{ v: 0 }` because `$wpdb->get_var()` returns false on SQL error and is cast to `(int) 0`.

**Frequency on LIVE V2.** ~12 errors/minute when the practitioner dashboard auto-poll is active. 2,162 occurrences captured in 24 hours of error log (1,339 rotated + 823 current at time of W2 deploy).

**Root cause.** The consultation_notes subquery at line 874:

```php
COALESCE(
    (SELECT MAX(COALESCE(approved_at, started_at, created_at))
     FROM {$p}hdlv2_consultation_notes cn
     INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = cn.form_progress_id
     WHERE cn.client_user_id IN ($ids) AND fp.deleted_at IS NULL),
    '1970-01-01 00:00:01'),
```

References `created_at` unqualified inside the `MAX(COALESCE(...))`. Both `cn` (consultation_notes) and `fp` (form_progress) have a `created_at` column. MariaDB rejects the unqualified reference as ambiguous.

**Fix path (NOT to be applied without review).** Either:

- `cn.created_at` — if the intent is "when was the consultation_notes row created" (matches the table the subquery is rooted on, alongside `approved_at` and `started_at` which are cn-only columns)
- `fp.created_at` — if the intent is "when was the underlying form_progress created"

The other two columns in the `COALESCE` — `approved_at` and `started_at` — only exist on `cn`, so the original intent is almost certainly `cn.created_at`. But this should be verified by reading the surrounding logic (v0.35.4 comment block at lines 855–867 explains the broader change) and confirmed against intent before committing.

**User impact.** Practitioner dashboard polling silently gets `v=0` for affected client cohorts → the dashboard's auto-refresh hook fails to fire when check-ins, ticks, addenda, or report updates happen for those clients. Manual page refresh still works (it reads underlying tables directly, not via this endpoint). Not blocking the automation tier build.

**Queue after:** the automation tier build ships (post-W13). Single-character SQL fix + commit + push-additive deploy.

---

*End of follow-ups. Append new entries at the top with the same structure.*
