# Per-User Event Execution — Implementation Report

**Date:** 2026-06-22 · **Branch:** master (uncommitted) · **Plan:** [plans/2026-06-22-event-user-execution.md](plans/2026-06-22-event-user-execution.md)

## Goal

Let an executing user run their own event through a planned → ongoing → completed (or cancelled) lifecycle from the desktop and mobile resource planner, capturing actual start/end times and a mandatory signature, with later date-locked time/signature edits.

## Architecture

- Dedicated `event_user_executions` table (one row per event+user, created lazily) — keeps the shared `userables` pivot clean.
- `EventCompletionStatus` enum (Dutch values: `Gepland`/`Gaande`/`Afgerond`/`Geannuleerd`).
- `EventExecutionController`: `show` (full row incl. signature, for edit prefill), `transition` (start/stop/cancel — server stamps `actual_start`/`actual_end` via `now()`), `update` (date-locked time + signature edit). All scoped to `Auth::id()`'s own row, authorized by `EventPolicy::executeOwn`.
- Planner payload carries per-user `completion_status`/`actual_start`/`actual_end`/`has_signature` (signature body excluded from the poll; fetched on demand via `show`).
- Frontend: `ExecutionModal.vue` (slot-based, hosts `SignaturePad`); `EventExecutionControls.vue` (derives the authenticated user internally; Play/Square/X/SaveAll/Ban by status); mounted on both desktop (`PlannerEvent`) and mobile cards, refetching on each action.

## Files

**New:** `app/Enums/EventCompletionStatus.php`, `app/Models/EventUserExecution.php`, `app/Http/Controllers/EventExecutionController.php`, `app/Http/Requests/EventExecution{Transition,Update}Request.php`, `database/migrations/2026_06_22_000004_create_event_user_executions_table.php`, `database/migrations/2026_06_22_000005_seed_event_execute_permission.php`, `resources/js/Components/Planner/{ExecutionModal,EventExecutionControls}.vue`, `pint.json`.

**Modified:** `app/Models/Event.php` (`executions()`/`executionFor()`), `app/Policies/EventPolicy.php` (`executeOwn`), `app/Http/Controllers/EventApiController.php` (payload), `routes/api.php` (3 routes), `resources/js/Composables/usePlannerEvents.js`, `resources/js/Components/Planner/{PlannerEvent,MobilePlannerView,ResourcePlannerWidget}.vue`, `resources/js/Components/UI/ModalDialog.vue` (opt-in `center` prop).

## Key decisions

- **Dedicated table, not `userables`** — execution data is event-specific; `userables` is shared by service orders/jobs/owners.
- **Lazy rows** — no backfill; a user with no row is treated as `Gepland`.
- **Dedicated `event.execute` permission** — execution is decoupled from `event.update`/`create`. Must be granted to the mechanic role in the admin UI (the migration only creates it).
- **Timezone** — transitions use server `now()` (UTC); the edit modal converts local→UTC on the frontend via `localToUtcDatetime(formatLocalDateAsISO(event.start), time)`, mirroring `EventEditModal`. Date stays locked to the event's planned date.
- **`pint.json` added** (`concat_space: spacing=one`) so Pint enforces the CLAUDE.md spaced-concatenation rule instead of stripping it.
- **Mobile planner refinements** — gray-stripe styling is driven by the user's own `completion_status` (`Afgerond`/`Geannuleerd`), not the serviceorder; serviceorder status shows as an icon in the WB button (amber triangle = incomplete, green check = closed); a "Niet afgerond" counter (amber triangle) totals the user's `Gepland`/`Gaande` events.

## Review findings & resolutions

Final whole-branch review (opus) plus per-task reviews. Real bugs fixed:

- **C1 (Critical):** lazy default/fallbacks wrote `'planned'` (enum case name) while everything else compared `'Gepland'` (enum value) → Play button never rendered. Fixed all four literals to `'Gepland'`; verified via tinker that `executionFor` now returns `Gepland`.
- **I1 (Important):** edit-modal times were sent as raw `H:i` and parsed server-side as UTC wall-clock → every edit shifted by the UTC offset. Restored the frontend `localToUtcDatetime` conversion; `update()` stores received UTC datetimes; request rule `H:i` → `date`.
- **Permission bug (user-reported):** `executeOwn` required `event.update`, so mechanics got a silent 403 on Play. Switched to the new `event.execute` permission.
- **Cross-task fix:** desktop bound the lane user's id to the controls (would render on every lane while the backend acts on `Auth::id()`). Refactored `EventExecutionControls` to derive `authUserId` via `usePage` internally; removed the `userId` prop.

Adjudicated **false positives** (verified, no change): `nullable` + `requiredIf` "conflict" (`requiredIf` is implicit and enforced — verified empirically); `show()` "leaking" signature (it is the prefill endpoint and must include it); modal "closes on error" (`try/finally` rethrows, modal stays open); pivot-hoisting concern (the composable already flattens pivot fields).

## Known / accepted

- **No automated tests** (project rule: tests only when asked).
- **Test suite:** 3 pre-existing failures unrelated to this work — missing `pdo_sqlite` driver for `:memory:` tests, and the default `ExampleTest` redirecting `/` → login.
- **Minor (open):** failed transition/PATCH shows no inline error toast — the modal stays open and the user can retry (console rejection only).
- **Overnight executions** (end before start next day) are intentionally unsupported (`after:actual_start`).
- The `2026_06_22_000005` migration default change wasn't re-applied to the dev DB (the app always sets the value via `executionFor`; moot at runtime).
- Desktop styling still uses serviceorder `is_closed`; the user-status-driven styling was applied to mobile only (as requested).

## Verification status

Migrations ran on the dev DB; `event.execute` seeded; Pint passes on all feature PHP; eslint/`npm run build` clean for all feature Vue files. **Outstanding:** manual browser walk-through of the lifecycle (Play → Stop+signature → SaveAll edit, plus X cancel) on desktop and mobile, and the user's commit decision.
