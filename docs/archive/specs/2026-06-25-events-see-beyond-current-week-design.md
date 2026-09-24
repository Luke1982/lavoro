# Design: `events.see_beyond_current_week` permission

**Date:** 2026-06-25

## Overview

Add a permission `events.see_beyond_current_week`. When a user **lacks** it, the resource planner (desktop and mobile) is locked to the current ISO week and navigation buttons that would leave it are hidden. The API endpoint is also guarded via the Form Request so no out-of-range data can be fetched even by direct requests.

## Permission

- **Name:** `events.see_beyond_current_week`
- **Label:** `Mag verder dan de huidige week plannen`
- Convention follows `{resource}.{action}` — plural `events` because it is a collection-level read constraint.
- Seeded via a new migration (`2026_06_25_000002_seed_events_see_beyond_current_week_permission.php`).

## Backend

### Policy — `app/Policies/EventPolicy.php`

New method:

```php
public function seeBeyondCurrentWeek(User $user): bool
{
    return $user->isAdmin() || $user->hasPermission('events.see_beyond_current_week');
}
```

### Form Request — `app/Http/Requests/EventReadRequest.php`

- `authorize()` already checks `event.read`; no change there.
- Add `prepareForValidation()`: if the user lacks `events.see_beyond_current_week`, overwrite the incoming `start` and `end` parameters with the boundaries of the current ISO week (Monday 00:00 UTC → Sunday+1 00:00 UTC).
- The controller reads `$request->start` / `$request->end` directly after this hook, so the clamp is transparent.

### Controller — `app/Http/Controllers/EventApiController.php`

- Change the `index()` type hint from `Request` to `EventReadRequest`.
- No other change needed; the date range is already clamped by the Form Request before the controller executes.

## Frontend

"Current week" is the ISO week (Monday–Sunday) that contains today, matching what the planner already uses via `dayjs().startOf('isoWeek')`.

A computed `canSeeBeyondWeek` reads `hasPermission('events.see_beyond_current_week')` from Inertia's shared `auth.permissions`.

### Desktop — `ResourcePlannerWidget.vue`

**Initial load:** If `!canSeeBeyondWeek`, clamp `weekStart` to `startOfWeek(new Date())` on mount, ignoring any stored value in `localStorage`.

**Navigation buttons (prev/next):**

- In **week view**: hide both buttons when `!canSeeBeyondWeek`.
- In **day view**: the prev button is hidden when the current day is Monday of the current week (can't go further back); the next button is hidden when the current day is Sunday of the current week (can't go further forward).

**`shiftPeriod()`:** Guard the shift so it never moves outside the current week when `!canSeeBeyondWeek` (defensive, in case something else calls it).

**`goToday()`:** No change needed — always navigates to today, which is always within the current week.

**`setPlannerView('week')`:** Already snaps `weekStart` to `startOfWeek(weekStart)`. When `!canSeeBeyondWeek` this always snaps to the current week because `weekStart` can't be elsewhere.

**`localStorage`:** When `!canSeeBeyondWeek`, skip writing `weekStart` to localStorage (so a permission change takes effect cleanly on next load rather than restoring an out-of-range stored value). Reading from localStorage is also skipped.

### Mobile — `MobilePlannerView.vue`

**Initial load:** Always start on `startOfWeek(new Date())` when `!canSeeBeyondWeek`.

**Navigation buttons (prev/next chevrons):** Hide both when `!canSeeBeyondWeek`.

**Swipe:** `useSwipe` calls `shiftWeek(±1)`. Guard `shiftWeek()` to no-op when `!canSeeBeyondWeek`.

**`shiftWeek()`:** No-op guard when `!canSeeBeyondWeek`.

## Data flow summary

```
User opens planner (no permission)
  → weekStart forced to current ISO week (frontend)
  → navigation buttons hidden (frontend)
  → fetchEvents sends current-week start/end to API
  → EventReadRequest.prepareForValidation() clamps to current week anyway (backend)
  → controller queries only current-week events
```

## What is NOT changed

- `event.read` authorization in `EventReadRequest::authorize()` — untouched.
- The polling interval, fingerprinting, or any other planner logic.
- Any other policy methods.
- Store/update/delete endpoints — this permission is read/navigation only.
