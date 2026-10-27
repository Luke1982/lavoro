# Project Location + Planner Location Resolution

**Date:** 2026-06-12

## Overview

Add a freetext `location` field to `Project`, then update both planner views (desktop + mobile) to show the best available location for each event using a three-level priority chain.

---

## Part 1 — Project location field

### Database

Migration: add `location` string, nullable, to the `projects` table.

### Model

Add `location` to `Project::$fillable`.

### Form Requests

- `ProjectStoreRequest`: add `'location' => ['nullable', 'string', 'max:255']`
- `ProjectUpdateRequest`: add `'location' => ['sometimes', 'nullable', 'string', 'max:255']`

### UI — Projects/ShowPage.vue

Add an `EditableTextField` for `location` following the existing inline-edit pattern used for title, description, start_date, etc. Include it in the `useForm` initialisation and the watch loop that calls `patchField`.

---

## Part 2 — Planner location resolution

### Priority chain (resolved once per event, server-side data only)

1. **Event's own `location`** — use if non-empty
2. **Project location** — if the event's service order belongs to a project that has a non-empty `location`, use that
3. **Customer address** — `[customer.address, customer.city].filter(Boolean).join(', ')` from the service order's customer

### EventApiController

Change the eager-load select from `serviceOrders.project:id,title` to `serviceOrders.project:id,title,location` so the project location travels with the API response.

### usePlannerEvents.js — mapEvent

Replace:
```js
location: ev.location || null,
```
with:
```js
location: ev.location
    || ev.service_orders?.[0]?.project?.location
    || [customer?.address, customer?.city].filter(Boolean).join(', ')
    || null,
```
(`customer` is already derived earlier in the same function as `ev.service_orders?.[0]?.customer ?? null`)

### Desktop — PlannerEvent.vue

No change needed. Already renders `event.location` with a MapPinIcon.

### Mobile — MobilePlannerView.vue

- Remove the `resolveAddress(ev)` helper function (it manually reconstructed address from `props.allServiceOrders`)
- Replace the Address template block `v-if="resolveAddress(ev)"` with `v-if="ev.location"` showing `ev.location` directly

---

## What does NOT change

- The `allServiceOrders` Inertia prop stays (used for the WB-number badge and other mobile functionality)
- No changes to how the desktop event tooltip works
- No new permissions needed

---

## Files touched

| File | Change |
|---|---|
| `database/migrations/YYYY_MM_DD_..._add_location_to_projects.php` | new migration |
| `app/Models/Project.php` | add `location` to `$fillable` |
| `app/Http/Requests/ProjectStoreRequest.php` | add `location` rule |
| `app/Http/Requests/ProjectUpdateRequest.php` | add `location` rule |
| `app/Http/Controllers/EventApiController.php` | add `location` to project eager-load select |
| `resources/js/Composables/usePlannerEvents.js` | priority-chain location resolution in `mapEvent` |
| `resources/js/Pages/Projects/ShowPage.vue` | add `location` EditableTextField |
| `resources/js/Components/Planner/MobilePlannerView.vue` | replace `resolveAddress` with `ev.location` |
