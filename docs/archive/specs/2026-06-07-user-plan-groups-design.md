# UserPlanGroup — Design Spec

**Date:** 2026-06-07

## Overview

Introduce `UserPlanGroup` — a server-persisted grouping of plannable users, managed inline in the planner's right sidebar. Groups have a name, a color, and an explicit sort order. The resource panel left sidebar displays a colored vertical bar spanning each group's rows, and sorts users by group order then alphabetically within each group. Ungrouped users appear at the bottom in a virtual "Geen groep" section.

---

## 1. Data Model

### `user_plan_groups` table

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | required |
| `color` | string | hex color, e.g. `#2563ff` |
| `sort_order` | integer | default 0; ordered asc, then id asc as tiebreaker |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### `users` table change

Add nullable `user_plan_group_id` FK → `user_plan_groups.id` (on delete set null).

### Models

**`UserPlanGroup`:**
- `fillable: ['name', 'color', 'sort_order']`
- `hasMany(User::class)`

**`User`:**
- Add `user_plan_group_id` to `$fillable` and `$casts` (integer or null)
- `belongsTo(UserPlanGroup::class)`

---

## 2. Backend API

All routes are Sanctum-protected under `routes/api.php`. Authorization requires `event.see_all` OR `event.create_others` (checked in each Form Request's `authorize()`).

### Group CRUD — `UserPlanGroupController`

| method | route | action |
|---|---|---|
| `GET` | `/api/plan-groups` | list all groups, each with `user_ids[]` |
| `POST` | `/api/plan-groups` | create `{name, color}`, auto-appends to end of sort order |
| `PUT` | `/api/plan-groups/{group}` | update `{name?, color?}` |
| `DELETE` | `/api/plan-groups/{group}` | delete; sets `user_plan_group_id = null` for all members |

### Group ordering — `UserPlanGroupController`

| method | route | action |
|---|---|---|
| `PUT` | `/api/plan-groups/reorder` | `{ids: [3,1,2]}` — sets `sort_order` by array position |

### User assignment — `UserPlanGroupController`

| method | route | action |
|---|---|---|
| `PUT` | `/api/plan-groups/{group}/users/{user}` | assign user to group |
| `DELETE` | `/api/plan-groups/{group}/users/{user}` | remove user from group (sets `user_plan_group_id = null`) |

### Plannable toggle — `UserPlannableController` (single-action)

| method | route | action |
|---|---|---|
| `PATCH` | `/api/users/{user}/plannable` | `{plannable: bool}` |

Authorization for `PATCH plannable`: same (`event.see_all` OR `event.create_others`).

### Form Requests

- `StorePlanGroupRequest` — validates `name` required string, `color` required hex string
- `UpdatePlanGroupRequest` — same rules, all optional
- `ReorderPlanGroupsRequest` — validates `ids` is array of existing group IDs
- `UpdateUserPlannableRequest` — validates `plannable` required boolean

### `PlannerController::index` changes

- `plannableUsers` gains `plan_group_id` field per user
- New `plan_groups` key: `UserPlanGroup::orderBy('sort_order')->orderBy('id')->get()` mapped to `{id, name, color, sort_order, user_ids: [...]}`

---

## 3. `PlanGroupsWidget.vue`

New component: `resources/js/Components/Planner/PlanGroupsWidget.vue`

### Props

```js
planGroups: Array   // [{id, name, color, sort_order, user_ids}]
allUsers: Array     // all plannable + non-plannable users with {id, name, avatar, plannable, plan_group_id}
```

### Emits

```js
'group-created'    // (group)      → parent calls POST /api/plan-groups
'group-updated'    // (id, patch)  → parent calls PUT /api/plan-groups/{id}
'group-deleted'    // (id)         → parent calls DELETE /api/plan-groups/{id}
'group-reordered'  // (ids[])      → parent calls PUT /api/plan-groups/reorder
'user-assigned'    // (groupId, userId)   → parent calls PUT /api/plan-groups/{group}/users/{user}
'user-unassigned'  // (userId)            → parent calls DELETE /api/plan-groups/{group}/users/{user}
'plannable-toggled'// (userId, value)     → parent calls PATCH /api/users/{user}/plannable
```

### Layout & styling

Follows `UnplannedServiceOrdersWidget` patterns: wrapped in `BoxComponent`, `text-lavoro-dark` header, `border-b-lavoro-gray-150` separators, `text-lavoro-blue` accents, `p-3` padding.

**Header row:** "Groepen" label (text-xs text-lavoro-dark) + "+" button (text-lavoro-blue) to create a new group.

**Group rows (ordered by sort_order):**
- Drag handle icon (for group reordering) — `application/x-plan-group` drag type
- Colored square (4×4, rounded, group color) + editable name (click → `<input>` inline)
- `<input type="color">` for color picking (shown as a small swatch button)
- Trash icon — `window.confirm` if group has members, immediate delete if empty
- Chevron to collapse/expand the user list within the group

**User list within group:**
- Avatar/initials + name
- Plannable toggle (small checkbox or `SwitchComponent`)
- Draggable (`application/x-plan-group-user`) — can be dropped onto another group header

**"Geen groep" section:** same user list format; receives drops to unassign users from groups. No rename/color/delete controls.

**Drag distinction:** `dragstart` sets `dataTransfer` type to `application/x-plan-group` (group reorder) or `application/x-plan-group-user` (user assignment). Drop targets check `dataTransfer.types` to accept only the appropriate type.

---

## 4. `ResourcePlannerWidget.vue` changes

### New prop

```js
groups: Array  // [{id, name, color, sort_order, user_ids}], ordered by sort_order
```

### Sorted `visibleUsers`

```js
const visibleUsers = computed(() => {
    const base = hasPermission('event.see_all')
        ? props.plannableUsers
        : props.plannableUsers.filter(u => u.id === authUserId.value)

    const groupIndex = Object.fromEntries(
        props.groups.map((g, i) => [g.id, i])
    )

    return [...base].sort((a, b) => {
        const ga = a.plan_group_id != null ? (groupIndex[a.plan_group_id] ?? Infinity) : Infinity
        const gb = b.plan_group_id != null ? (groupIndex[b.plan_group_id] ?? Infinity) : Infinity
        if (ga !== gb) return ga - gb
        return a.name.localeCompare(b.name)
    })
})
```

### Group bar overlay

Inside the existing `flex-1 overflow-y-auto` scroll container (add `relative`), inject a `pointer-events-none` overlay `div` positioned `absolute inset-0 w-0 overflow-visible`.

**Bar computation** (mirroring `lockedGroupOverlays`): iterate `visibleUsers`, accumulate `top` per row using `rowHeightFor(user.id)`. For each group + "Geen groep", record `{top, height, color, name}` spanning its members.

**Bar element per group:**
```html
<div class="absolute left-0 w-1 rounded-sm"
     :style="{ top: bar.top + 'px', height: bar.height + 'px', background: bar.color }">
  <span style="writing-mode: vertical-rl; transform: rotate(180deg)"
        class="text-[9px] font-semibold text-white px-0.5 truncate select-none">
    {{ bar.name }}
  </span>
</div>
```

Width: `4px` (`w-1`). Sits within existing `pl-9` (36px) left padding — no existing markup changes needed. "Geen groep" bar uses a neutral gray (`#9ca3af`).

---

## 5. `IndexPage.vue` changes

### Reactive state

```js
const planGroups = ref(props.planGroups)          // [{id, name, color, sort_order, user_ids}]
const plannableUsers = ref(props.plannableUsers)  // [{id, name, avatar, plannable, plan_group_id}]
```

(Props remain the source of truth on page load; local refs allow mutation without reload.)

### Layout

Right sidebar (`col-span-2`) is visible when `canPlan || canManageGroups`.

```js
const canManageGroups = computed(() =>
    hasPermission('event.see_all') || hasPermission('event.create_others')
)
const showSidebar = computed(() => canPlan.value || canManageGroups.value)
```

Planner column: `col-span-10` when sidebar shown, `col-span-12` otherwise.

### Widget stack

```html
<UnplannedServiceOrdersWidget v-if="canPlan" :service-orders="unplanned" />
<PlanGroupsWidget
    v-if="canManageGroups"
    :plan-groups="planGroups"
    :all-users="allPlanUsers"
    @group-created="onGroupCreated"
    @group-updated="onGroupUpdated"
    @group-deleted="onGroupDeleted"
    @group-reordered="onGroupReordered"
    @user-assigned="onUserAssigned"
    @user-unassigned="onUserUnassigned"
    @plannable-toggled="onPlannableToggled"
/>
```

`allPlanUsers` = all users (plannable or not) with `{id, name, avatar, plannable, plan_group_id}`, passed from controller as new `allPlanUsers` prop.

### Event handlers

Each handler calls axios, then updates `planGroups` and `plannableUsers` refs in place on success. Errors surface via `page.props.flash.error`.

---

## 6. Permissions seeding

A new migration seeds two permissions if they don't already exist:

- `plan_group.manage` — optionally a dedicated permission; but given the feature gates on existing `event.see_all` / `event.create_others`, no new permission is strictly needed. Decision: reuse existing permissions, no new seed migration required.

---

## Files changed / created

| file | action |
|---|---|
| `database/migrations/2026_06_07_000001_create_user_plan_groups_table.php` | create |
| `database/migrations/2026_06_07_000002_add_user_plan_group_id_to_users_table.php` | create |
| `app/Models/UserPlanGroup.php` | create |
| `app/Models/User.php` | update (fillable, belongsTo) |
| `app/Http/Controllers/UserPlanGroupController.php` | create |
| `app/Http/Controllers/UserPlannableController.php` | create |
| `app/Http/Requests/StorePlanGroupRequest.php` | create |
| `app/Http/Requests/UpdatePlanGroupRequest.php` | create |
| `app/Http/Requests/ReorderPlanGroupsRequest.php` | create |
| `app/Http/Requests/UpdateUserPlannableRequest.php` | create |
| `app/Http/Controllers/PlannerController.php` | update (new props) |
| `routes/api.php` | update (new routes) |
| `resources/js/Components/Planner/PlanGroupsWidget.vue` | create |
| `resources/js/Components/Planner/ResourcePlannerWidget.vue` | update (groups prop, sorted visibleUsers, bar overlay) |
| `resources/js/Pages/Planner/IndexPage.vue` | update (reactive refs, sidebar logic, widget) |
