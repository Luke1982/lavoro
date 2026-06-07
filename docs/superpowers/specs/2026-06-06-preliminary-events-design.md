# Preliminary Events Design

## Summary

Events in the planner can be marked as "voorlopig" (preliminary). Preliminary is a boolean flag orthogonal to the existing `status` enum — an event can be Gepland and preliminary at the same time. Preliminary events render with a lighter background, a dashed border, and a warning icon in the planner card.

## Data layer

- New migration adds `is_preliminary` boolean column to `events` table, default `false`, not nullable.
- `Event::$fillable` gains `'is_preliminary'`; cast to `bool`.
- `EventStoreRequest` and `EventUpdateRequest` each add `'is_preliminary' => 'boolean'` to their `rules()`.
- `EventApiController` includes `is_preliminary` in the event payload returned to the frontend.

## Edit modal

- A "Voorlopig" checkbox is added to `EventEditModal.vue`, near the status field.
- Bound to `form.is_preliminary`.
- No conditional logic — just a boolean toggle.

## Planner visual rendering

Changes are confined to two components:

**ResourcePlannerWidget.vue** — where event card inline styles are computed:
- Background: `color-mix(in srgb, ${color} 8%, white)` (down from 18%) when `is_preliminary` is true.
- Border style: `dashed` instead of `solid` (same color, same 4px width) when `is_preliminary` is true.

**PlannerEvent.vue** — event card template:
- When `event.is_preliminary` is true, render a small Heroicons `ExclamationTriangleIcon` inline in the title row.
