# Design: Project Timeline Component

**Date:** 2026-07-03
**Status:** Approved

## Overview

Add a horizontal timeline to the project show page (`Projects/ShowPage.vue`) that visualizes, at a glance, everything happening on a project: milestones, service orders (werkbonnen) with their task instances, and linked events (afspraken). The goal is to answer "which executing user did what, when" without leaving the project page. Modeled loosely on the mockup provided by the user (type-grouped rows: Mijlpalen / Werkbonnen / Afspraken) and reusing interaction patterns from the desktop resource planner (`ResourcePlannerWidget.vue`) where useful (block rendering, detail-on-click).

Creating new events from the timeline is explicitly out of scope for this iteration.

## Data Layer

### Migration: add completion tracking to `service_order_task_instances`

Task instance completion is currently only inferable from the activity log ("Taak … voltooid") or `updated_at`, both fragile for timeline placement. Add real columns, following the pattern of the existing `signed_at` field:

| Column | Type | Notes |
|---|---|---|
| `completed_at` | `timestamp, nullable` | Set server-side when `is_complete` transitions to `true` |
| `completed_by` | `foreignId nullable, constrained: users, nullOnDelete` | The acting user (`auth()->id()`) at completion time |

Migration file: `database/migrations/2026_07_03_000001_add_completion_tracking_to_service_order_task_instances_table.php`.

No backfill — historical instances simply show no marker on the timeline (they remain visible in the task list within the werkbon detail panel, unaffected).

### Model (`ServiceOrderTaskInstance`)
- Add `completed_at`, `completed_by` to `$fillable`
- Cast `completed_at` as `datetime`
- Add `completedBy(): BelongsTo` → `User::class`

### Controller (`ServiceOrderTaskInstanceController::toggle`)
- When `is_complete` transitions `false → true`: set `completed_at = now()`, `completed_by = auth()->id()`
- When toggled back to `false` (reopened): clear `completed_at` and `completed_by` (mirrors the existing clearing of `signed_by`/`signature_base64`/`signed_at` on reopen)

## Backend: timeline data

### Route
```
GET projects/{project}/timeline
```
Added to `routes/web.php` inside the existing `auth` group, alongside the other project routes. JSON endpoint (not an Inertia page) — the widget fetches it lazily via axios when the section mounts, keeping the initial `ShowPage` load unchanged.

### Form Request: `ProjectTimelineRequest`
- `authorize()`: `$this->user()->can('view', $this->route('project'))` — same gate as viewing the project itself; no separate permission introduced.

### Controller method: `ProjectController::timeline()`
Loads and returns:
- `project.milestones.assignedUser`
- `project.serviceOrders` with:
  - `serviceOrderStage`
  - `executingUsers` (via the `HasExecutingUsers` trait)
  - `taskInstances.completedBy`
  - `events.executingUsers` — via `ServiceOrder::events()` (already defined as `morphToMany(Event::class, 'eventable')`), so `serviceOrders.events.executingUsers` is loadable directly on the project's eager-load chain.

Response shape (JSON):
```json
{
  "milestones": [{ id, title, projected_date, actual_date, assigned_user }],
  "service_orders": [{
    id, description, is_closed, stage: { name, is_closed_state },
    actual_start_time, actual_end_time,
    executing_users: [{ id, name }],
    events: [{ id, name, start, end, executing_users }],
    task_instances: [{ id, title, is_complete, is_cancelled, completed_at, completed_by: { id, name } }]
  }]
}
```

No new permission is introduced; visibility of financial fields is irrelevant here since none are exposed. Executing users and events are shown regardless of `event.see_all`, matching the scope of "things happening on a project I can already view."

## Frontend

### Component: `Components/Projects/ProjectTimeline.vue`

New component, mounted inside a new `BoxComponent` on `ShowPage.vue`, placed between the existing "Details" box and the "Werkbonnen" list box (matching the mockup's placement). Props: `projectId`, `projectStartDate`, `projectEndDate`.

On mount, fetches `GET /projects/{id}/timeline` via axios and renders client-side; a lightweight loading state (spinner, matching the planner's `eventsLoading` treatment) covers the fetch.

### Layout

Custom-built (no Gantt library), consistent with existing house style (plain divs + Tailwind, as in `ResourcePlannerWidget.vue`):

- **Time axis**: auto-fit — the visible width always maps to `[project.start_date, project.end_date]` (padded by a few days on each side if any item falls outside that range, e.g. a milestone dated before `start_date`). No zoom/scroll controls. Month tick labels across the top, computed from the span. A vertical "today" line rendered when `today` falls within the padded range.
- **Rows, grouped by type** (per mockup):
  1. **Mijlpalen** — each milestone rendered as a diamond marker positioned at `projected_date` (or `actual_date` if present and different — reuse the existing color logic from `ShowPage.vue`'s `milestoneColor()`: green/done, red/overdue, blue/upcoming, gray/no date).
  2. **Werkbonnen** — one row per service order. The bar spans the earliest-to-latest linked event (`start`→`end`); if `actual_start_time`/`actual_end_time` are set, an inner darker segment overlays the actual execution window within/around the planned span. A werkbon with neither events nor actuals renders as a single dot positioned at `created_at` instead of a bar (falls back gracefully rather than being omitted). Completed task instances render as small tick markers along the bar, positioned at `completed_at`. Executing users are shown as stacked initials/avatars on the bar (reusing the avatar/initials treatment already used elsewhere via `initials()` in `Utilities.js`).
  3. **Afspraken** — one row per distinct event linked to any of the project's service orders (deduplicated by event id, since the same event can be linked to multiple service orders). Bar spans `start`→`end`; short events render as a minimum-width block with a time label, consistent with `PlannerEvent.vue`'s handling of short durations.
- Rows within "Werkbonnen" and "Afspraken" groups are sorted chronologically by their start.
- Empty groups (e.g., no events yet) are hidden entirely rather than shown empty, to keep the mockup's density without dead space — except "Werkbonnen", which always shows since the box below already covers the empty state.

### Detail panel & interaction

- Clicking a block (milestone, werkbon bar, or event bar) opens a detail panel using the existing `DrawerComponent`, matching `ShowPage.vue`'s existing milestone drawer pattern rather than introducing a new panel primitive.
- Per the existing UI convention (toggle, not X-to-clear), clicking the already-selected block closes the drawer; clicking a different block swaps the drawer content.
- Drawer content:
  - **Milestone**: title, description, assigned user, projected/actual date (read-only display; editing stays in the existing sidebar milestone list, not duplicated here).
  - **Werkbon**: stage name, executing users, linked events (name + date), task instance list with completion state/date/user, and a link/button ("Open werkbon") navigating to `/serviceorders/{id}`.
  - **Event**: name, description, location, start/end, executing users, and a link to the event (existing event detail route, if one is directly linkable; otherwise omit the link and show details only).
- No inline editing from the timeline in this iteration — it's a read-oriented overview. Existing edit surfaces (milestone sidebar, werkbon page, planner) remain the place to make changes.

### Responsiveness

The mockup is desktop-oriented. On small screens, the timeline box either scrolls horizontally within its container (simplest, consistent with how wide tables are already handled elsewhere) or is hidden below a breakpoint with a note to view on desktop — deferred to implementation to match whatever pattern `ResourcePlannerWidget`/`MobilePlannerView` already establish for this kind of density; no separate mobile redesign is in scope.

## Out of Scope

- Creating new events from the timeline (explicitly deferred by the user)
- Inline editing of any entity from the timeline (drag to reschedule, etc.)
- Zoom/pan controls, week view, or manual time-range selection
- Backfilling `completed_at`/`completed_by` for existing task instances
- A dedicated mobile layout beyond basic horizontal scroll/hide
