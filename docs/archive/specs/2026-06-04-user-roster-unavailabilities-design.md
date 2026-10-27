# User Roster / Unavailabilities

**Date:** 2026-06-04
**Status:** Approved

## Overview

Plannable users need a roster: recurring off-periods (e.g. every Wednesday 13:00–18:00, every other Friday afternoon) and one-time full-day holidays. The resource planner should render these blocks visually and prevent anyone from scheduling on them — soft block, no override.

## Data Model

New table `user_unavailabilities`:

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users | |
| `type` | enum `recurring\|holiday` | |
| `label` | string nullable | display name, e.g. "Vakantie" |
| `day_of_week` | tinyint 0–6 nullable | recurring only; 0 = Monday |
| `start_time` | time nullable | recurring only |
| `end_time` | time nullable | recurring only |
| `repeat` | enum `weekly\|biweekly` nullable | recurring only |
| `reference_date` | date nullable | biweekly anchor; determines which weeks the pattern applies |
| `date` | date nullable | holiday only |
| `timestamps` | | |

New `UserUnavailability` Eloquent model with a `scopeForWeek(Carbon $start, Carbon $end)` scope that:
- For holidays: filters where `date` falls in the range
- For recurring: returns all recurring rows (caller expands into the week)

A helper method (or service) `expandForWeek(Collection $entries, Carbon $weekStart): array` returns a flat array of `{ user_id, date, start_time, end_time }`. Holidays produce one entry per day with `start_time = null, end_time = null` (full day). Biweekly entries are only included if the number of full weeks between `reference_date` and the target date is even (week 0 = the reference week itself is the first occurrence, week 2 is the next, etc.).

## Permissions

Two new permissions seeded in a dedicated migration, following the `{resource}.{action}` convention:

- `roster.manage_own` — can manage unavailabilities on own profile
- `roster.manage_all` — can manage unavailabilities on any user's profile

### Policy

`UserUnavailabilityPolicy` with `create`, `update`, `delete` methods:

```php
public function create(User $authUser, User $targetUser): bool
{
    return $authUser->hasPermission('roster.manage_all')
        || ($authUser->hasPermission('roster.manage_own') && $authUser->id === $targetUser->id);
}
// update and delete follow the same logic
```

Form Requests delegate to the policy via `$this->authorize(...)`.

## Backend — Management Endpoints (web, auth middleware)

Routes nested under the existing users resource:

```
POST   /users/{user}/unavailabilities
PUT    /users/{user}/unavailabilities/{unavailability}
DELETE /users/{user}/unavailabilities/{unavailability}
```

Controller: `UserUnavailabilityController` with `store`, `update`, `destroy` actions.

Form Requests:
- `UserUnavailabilityStoreRequest` — validates type, and the appropriate fields per type; delegates auth to policy
- `UserUnavailabilityUpdateRequest` — same validation rules as store
- `UserUnavailabilityDestroyRequest` — auth only

The existing `UserController@show` (or `edit`) page includes `unavailabilities` as an Inertia prop: `$user->unavailabilities()->orderBy('type')->orderBy('day_of_week')->orderBy('date')->get()`.

## Backend — Planner API Endpoint (Sanctum)

```
GET /api/unavailabilities?start=YYYY-MM-DD&end=YYYY-MM-DD
```

Controller: `UnavailabilityApiController@index`

- Loads all `UserUnavailability` rows relevant to the requested date range
- Expands them into a flat array of `{ user_id, date, start_time, end_time }`
- Returns JSON array

This endpoint is fetched by the planner alongside `GET /api/events` on mount and on every week navigation.

## Frontend — ResourcePlannerWidget.vue

Changes to `ResourcePlannerWidget.vue`:

1. On mount and on week navigation, fetch `GET /api/unavailabilities?start=&end=` in parallel with the events fetch. Store result in an `unavailabilities` ref.
2. Computed `blockedSlots` derives two lookup structures:
   - `fullDayBlocked: Map<userId, Set<"YYYY-MM-DD">>` — from holiday entries (null start/end)
   - `partialBlocked: Map<userId, Array<{date, startMinutes, endMinutes}>>` — from recurring entries
3. Helper `isBlocked(userId, date, slotStartMinutes, slotEndMinutes)` returns true if the slot overlaps any blocked range for that user on that date.
4. Blocked cells:
   - Render with a hatched grey background (CSS class `cell--blocked`)
   - `dragover` handler returns early (prevents drop)
   - `click` handler returns early (prevents event creation)
   - Full-day holiday shows a faint label across the entire day column for that user row
5. No admin override — `isBlocked` is unconditional.

## Frontend — User Show/Edit Page (Roster Management)

A **"Rooster"** section is shown on the user's show/edit page when the viewer has `roster.manage_all` OR (`roster.manage_own` AND viewing their own profile). Rendered via a dedicated `UserRosterWidget.vue` component that receives `unavailabilities` and `userId` as props.

### Recurring blocks sub-section

List of existing recurring entries (day name, time range, weekly/biweekly). Each row has a delete button — fires `DELETE` via axios, then calls `router.reload({ only: ['unavailabilities'] })`.

"Toevoegen" button reveals an inline form:
- Day of week — `SelectMenuComponent` (Maandag–Zondag)
- Start time / end time — `<input type="time">`
- Herhaling — `SelectMenuComponent` (Wekelijks | Om de week)
- Referentiedatum — `<input type="date">` (visible only when biweekly selected)
- Label — `TextInput` (optional)

Submit fires `POST /users/{user}/unavailabilities`, then `router.reload`.

### Holidays sub-section

List of existing holidays (date, label). Each row has a delete button.

"Toevoegen" button reveals an inline form:
- Datum — `<input type="date">`
- Label — `TextInput` (optional, e.g. "Vakantie", "Ziekmelding")

Submit fires `POST /users/{user}/unavailabilities`, then `router.reload`.

## Out of Scope

- Notifications when a planned event conflicts with a newly created unavailability (existing events are not retroactively flagged)
- Recurring patterns more complex than weekly / biweekly (e.g. monthly, specific date ranges)
- Admin bulk-import of holidays
