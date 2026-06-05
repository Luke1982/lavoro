---
name: mobile-planner-view
description: Mobile timeline view for the planner — vertical day list, week navigation, permission-aware user switcher
metadata:
  type: project
---

# Mobile Planner View

## Goal

Add a mobile-optimised timeline view to the planner page that is shown on small screens and hidden on `md+` breakpoints, while the existing `ResourcePlannerWidget` continues to be shown on larger screens. No changes to the desktop widget.

## Permissions

| Permission | Behaviour |
|---|---|
| `event.see_all` | User switcher (SelectMenu) visible; can view any plannable user's week or all users merged |
| no `event.see_all` | No user switcher; view is always scoped to the authenticated user |
| `event.update` | Tapping own event opens `EventEditModal` |
| `event.update_others` | Tapping any event opens `EventEditModal` |
| neither update permission | Cards are non-interactive (read-only) |

## Component structure

- **New file:** `resources/js/Components/Planner/MobilePlannerView.vue`
- **Modified file:** `resources/js/Pages/Planner/IndexPage.vue`
  - Renders `MobilePlannerView` with `class="md:hidden"` above the existing desktop grid
  - Wraps the existing desktop grid in `class="hidden md:grid ..."` (or equivalent)

`MobilePlannerView` receives the same props already available in `IndexPage`: `eventTypes`, `allCustomers`, `allServiceOrders`, `eventStatusses`, `allUsers`, `plannableUsers`. No backend changes required.

## Layout

### Sticky header bar
- `←` / `→` chevron buttons flank a centred week label (e.g. "2 – 8 juni 2026")
- Week label format matches the existing `weekTitle` computed in `ResourcePlannerWidget`
- Below the nav row: user-switcher (conditional, see below)

### User switcher (event.see_all only)
- A styled dropdown listing all plannable users by name, plus an "Alle monteurs" option at the top
- Uses the project's `SelectMenuComponent` as the UI primitive
- When a specific user is selected: show user card + that user's events
- When "Alle monteurs": hide user card, show all events merged and sorted chronologically; each card shows a small user-name label instead of an avatar stack

### User card (hidden in "Alle monteurs" mode)
- Circle avatar (image if available, initials fallback)
- Name + "Monteur" role label
- Stats row: `📅 N Afspraken` · `🕐 Xu Ym Gepland`
- Stats are derived from the fetched events for the selected week

### Timeline
Vertical scrolling list. Each item:

```
[HH:MM]  ●──  [ event card ]
[Xu Ym]
```

- Left column: start time (bold) + duration (muted, smaller)
- Blue dot + vertical line connector between items
- Event card (right):
  - Coloured left border stripe (`event.color`)
  - Event type icon (generic calendar icon if no specific icon available)
  - Title (bold)
  - Customer name (muted, if present)
  - Address line (customer address, if resolvable from linked service order)
  - WB badge: `WB-YYYY-NNNN` style derived from `eventable_id`, with external link icon navigating to `/serviceorders/{id}`
  - Executing-user avatar stack (up to 3 circles, "+N" overflow chip) — replaced by user-name label in "Alle monteurs" mode
  - Tapping: opens `EventEditModal` if the user has update permission for this event; otherwise no-op

### Empty state
Centred text: "Geen afspraken deze week"

## Data fetching

`MobilePlannerView` manages its own `weekStart` ref and calls `/api/events` with the same start/end params as the desktop widget. The component is mounted but hidden on `md+`; it fetches independently on week navigation. Because only one view is ever visible at a time, duplicate network calls are not a concern.

`fetchUnavailabilities` is **not** needed for the mobile view (no overlay grid).

## WB number formatting

The service order ID (`eventable_id`) is formatted as `WB-{year}-{id padded to 4 digits}` for display. The year comes from the event start date.

## Address line

Resolved client-side: match `eventable_id` against the `allServiceOrders` prop (already passed in), find the customer via `customer_id`, look up in `allCustomers`. Show `street + city` if present; omit the line silently if not resolvable.

## What is NOT in scope

- Creating events from the mobile view (no tap-to-create)
- Travel distance / km stat (not tracked in the data model)
- Drag-and-drop
- Unavailability overlays
- Project all-day bars
