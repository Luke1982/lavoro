# Per-User Diverging Times in the Resource Planner

**Date:** 2026-06-12

## Overview

Each executing user on an event can optionally have their own start/end times that differ from the event's global start/end. When `has_diverging_times` is true for a user, the planner grid (desktop) and the mobile timeline both use those times for positioning and display instead of the event-level times.

---

## 1. Database

### Migration: add 3 columns to `userables`

The `userables` pivot already stores `breaktime` per executing user. The same pattern is used here.

| Column | Type | Nullable | Default |
|---|---|---|---|
| `has_diverging_times` | boolean | no | 0 |
| `diverging_start` | time (HH:MM:SS) | yes | null |
| `diverging_end` | time (HH:MM:SS) | yes | null |

These columns are only meaningful on rows where `type = 'executing'` on an `Event` userable. They remain null for other models (ServiceOrder, etc.) and are simply ignored.

---

## 2. Backend

### `HasExecutingUsers` trait — `syncExecutingUsers()`

Add a fourth parameter `array $diverging_times = []`, keyed by user_id, each value being an associative array:

```php
[
    'has_diverging_times' => bool,
    'diverging_start'     => 'HH:MM' | null,
    'diverging_end'       => 'HH:MM' | null,
]
```

The method writes these into the `userables` pivot alongside `type` and `breaktime`.

### `EventApiController::withUserRoles()`

After setting `user_role_ids` on the pivot, also append `has_diverging_times`, `diverging_start`, and `diverging_end` from `$user->pivot` to each executing user object in the response.

### `EventApiController::store()` and `update()`

Pass the new `executing_user_diverging_times` request field through to `syncExecutingUsers()`.

### `EventStoreRequest` and `EventUpdateRequest` — validation rules

```php
'executing_user_diverging_times'                    => 'nullable|array',
'executing_user_diverging_times.*.has_diverging_times' => 'boolean',
'executing_user_diverging_times.*.diverging_start'  => 'nullable|date_format:H:i',
'executing_user_diverging_times.*.diverging_end'    => 'nullable|date_format:H:i',
```

---

## 3. Frontend — `usePlannerEvents.js`

In `mapEvent()`, extend each `executing_users` entry to include:

```js
{
    id, name, breaktime, user_role_ids,
    has_diverging_times: u.pivot?.has_diverging_times ?? false,
    diverging_start: u.pivot?.diverging_start ?? null,  // "HH:MM" or null
    diverging_end:   u.pivot?.diverging_end   ?? null,
}
```

---

## 4. Frontend — `EventEditModal.vue`

### State additions

- `userDivergingTimes`: a reactive object keyed by `String(user.id)`, each value:
  ```js
  { has_diverging_times: false, startHour: '08', startMinute: '00', endHour: '09', endMinute: '00' }
  ```
- Initialised from `props.initial.executing_users` on mount (reading `has_diverging_times`, `diverging_start`, `diverging_end`).
- On `onUserSelected`: add a default entry (has=false, 08:00–09:00).

### UI — per user row

After the existing roles `ComboBox`, add a new sub-row when the user row is expanded:

```
[ ✓ checkbox "Afwijkende tijden" ]   (when checked →)
  Start: [HH select] : [MM select]   Einde: [HH select] : [MM select]
```

The four selects reuse the same `hours`/`minutes` arrays and `timeSelectClass()` already present in the modal. When the checkbox is unchecked the selects are hidden (not just disabled) to keep the UI clean.

### Payload on save

In the `save()` function, add to `payload`:

```js
executing_user_diverging_times: Object.fromEntries(
    form.executing_user_ids.map(id => [id, {
        has_diverging_times: userDivergingTimes.value[String(id)]?.has_diverging_times ?? false,
        diverging_start: userDivergingTimes.value[String(id)]?.has_diverging_times
            ? `${userDivergingTimes.value[String(id)].startHour}:${userDivergingTimes.value[String(id)].startMinute}`
            : null,
        diverging_end: userDivergingTimes.value[String(id)]?.has_diverging_times
            ? `${userDivergingTimes.value[String(id)].endHour}:${userDivergingTimes.value[String(id)].endMinute}`
            : null,
    }])
)
```

### `openEdit` in `ResourcePlannerWidget.vue` and `MobilePlannerView.vue`

Pass `executing_users` (with the full pivot data including diverging times) through `modalInitial` so the modal can initialise the per-user state correctly. The `ResourcePlannerWidget.openEdit` already passes `executing_users`; `MobilePlannerView.handleEventTap` currently does not — add it.

---

## 5. Frontend — `PlannerEvent.vue` (desktop grid)

### New computed: `effectiveStartMin` / `effectiveEndMin`

```js
const divergingUser = computed(() =>
    props.event.executing_users?.find(u => u.id === props.userId && u.has_diverging_times)
)

const effectiveStartMin = computed(() => {
    if (divergingUser.value?.diverging_start) {
        const [h, m] = divergingUser.value.diverging_start.split(':').map(Number)
        return h * 60 + m - props.dayStartHour * 60
    }
    return minutesFromDayStart(props.event.start)
})

const effectiveEndMin = computed(() => {
    if (divergingUser.value?.diverging_end) {
        const [h, m] = divergingUser.value.diverging_end.split(':').map(Number)
        return h * 60 + m - props.dayStartHour * 60
    }
    return minutesFromDayStart(props.event.end)
})
```

Replace `minutesFromDayStart(props.event.start)` / `minutesFromDayStart(props.event.end)` with these in the `style` computed.

### Time display row

Show the diverging time in the clock row when active:
- If `divergingUser.value`: display `diverging_start – diverging_end` (formatted HH:MM) instead of `event.start – event.end`.

---

## 6. Frontend — `MobilePlannerView.vue`

### Time column (left side, `nlTime(ev.start)`)

Add a helper `effectiveStartTime(ev)`:
```js
function effectiveStartTime(ev) {
    const u = ev.executing_users?.find(u => u.id === selectedUserId.value && u.has_diverging_times)
    if (u?.diverging_start) return u.diverging_start.slice(0, 5) // "HH:MM"
    return nlTime(ev.start)
}
```

Use `effectiveStartTime(ev)` in the template instead of `nlTime(ev.start)`.

### `durationLabel(ev)`

When a selected user has diverging times, compute duration from `diverging_start`/`diverging_end` instead of `ev.end - ev.start`.

---

## 7. Out of scope

- Diverging times on the _date_ (not just time) — only intra-day time shifts are supported.
- Copying diverging times when an event is copied (`EventApiController::copy`) — copy without diverging times (same behaviour as breaktime today).
- Validation that diverging times fall within the event's date span — not needed; times are visual only.
