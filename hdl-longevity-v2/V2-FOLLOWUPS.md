# V2 Follow-ups

Tracked work items that are not blocking the current build but should be
addressed once the active commit chain ships and stabilises. Each entry
includes context, scope, and a "queue after" hint so they can be ordered.

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
