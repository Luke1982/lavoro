# Override Unavailability — Design Spec

**Date:** 2026-06-25

## Goal

Allow planners to schedule events on time slots that have a user unavailability set, instead of being silently blocked. A confirmation dialog warns the planner which user(s) are unavailable and requires explicit opt-in to proceed. Overriding is gated behind an admin-controlled setting; when the setting is off the existing blocking behaviour is preserved.

---

## Admin Setting

**Key:** `allow_override_unavailability`  
**Store:** `general_settings` table (existing key-value store via `GeneralSetting` model)  
**Default:** `'0'` (off)  
**Exposed as:** Boolean prop `allowOverrideUnavailability` passed from `PlannerController` → `Planner/IndexPage` → `ResourcePlannerWidget`.

### Admin UI

New section **"Planning"** in `Pages/Admin/GeneralSettingsPage.vue` using the existing `SwitchComponent`. Saved via `PUT /admin/settings/allow-override-unavailability` (new route + controller method in `GeneralSettingsController`).

---

## The Dialog: `UnavailabilityOverrideDialog.vue`

New component at `resources/js/Components/Planner/UnavailabilityOverrideDialog.vue`.

Uses HeadlessUI `Dialog` / `TransitionRoot` / `TransitionChild` (same pattern as existing `ModalDialog`).

**Content:**
- Warning icon (Lucide `AlertTriangle`) on an amber background circle
- Title: **"Niet beschikbaar"**
- Body: lists each affected entry as `"<name> — <label>"` (label falls back to "Niet beschikbaar" when null)
- Two buttons: **"Annuleren"** (secondary/outline) · **"Doorgaan"** (bg-lavoro-blue text-white)

**Props:** `open: Boolean`, `users: Array<{ name: string, label: string | null }>`  
**Emits:** `confirm`, `cancel`

---

## Pending-Action Pattern (ResourcePlannerWidget)

Because planner event handlers are synchronous, a callback-ref pattern is used instead of async/await:

```
const unavailOverrideDialog = ref({ open: false, users: [] })
let pendingOverrideAction = null

function requestOverride(affectedUsers, actionFn) {
    unavailOverrideDialog.value = { open: true, users: affectedUsers }
    pendingOverrideAction = actionFn
}
function onOverrideConfirm() {
    unavailOverrideDialog.value.open = false
    const fn = pendingOverrideAction
    pendingOverrideAction = null
    fn?.()
}
function onOverrideCancel() {
    unavailOverrideDialog.value.open = false
    pendingOverrideAction = null
}
```

Helper `getBlockedUsers(userId, dayIso, startMin, endMin)` returns `Array<{ name, label }>` — filters the user's unavailabilities to those that match the day and overlap the time range, maps to `{ name: user.name, label: unav.label }`.

---

## Interception Points

### 1. Click / click-drag to create (`onCellPointerDown` + `onWindowPointerUp` select)

**`onCellPointerDown`**

```
if (isBlockedAtTime(user.id, day.iso, startMin, endMin)) {
    if (!props.allowOverrideUnavailability) return   // current behaviour
    // override ON: fall through — selection rect starts normally
}
```

The selection rect and drag state start as usual; visual feedback (blue rect) is unaffected.

**`onWindowPointerUp` — select branch** (added check before `openCreate`)

```
if (isBlockedAtTime(sel.userId, sel.dayIso, startMin, endMin)) {
    if (props.allowOverrideUnavailability) {
        const blockedUsers = getBlockedUsers(...)
        requestOverride(blockedUsers, () => openCreate({ start, end, userId: sel.userId }))
    }
    return
}
openCreate({ start, end, userId: sel.userId })
```

Catches both: initial click on blocked slot **and** drag from clean area into blocked area.

### 2. Drop service order on blocked slot (`onExternalDrop`)

```
if (isBlockedAtTime(user.id, day.iso, startMin, startMin + duration)) {
    if (props.allowOverrideUnavailability) {
        requestOverride(blockedUsers, () => createEventFromDrop({ start, end, userId, payload }))
    }
    return
}
```

**`onDragOver`**: When override is ON and slot is blocked, show the ghost (allow visual preview) instead of clearing it and setting `dropEffect = 'none'`. When override is OFF, current behaviour (ghost hidden, `dropEffect = 'none'`).

### 3. Move/resize existing event to blocked slot (`onWindowPointerUp` — move/resize branch)

Added check after capturing preview values but before calling `persistEventChange`:

```
const previewDayIso = drag.value.previewDayIso   // captured before clearing drag state
// ... clear drag state as now ...
if (movedTime || movedUser) {
    const startMin = minutesFromDayStart(previewStart)
    const endMin   = minutesFromDayStart(previewEnd)
    if (isBlockedAtTime(previewUserId, previewDayIso, startMin, endMin)) {
        if (props.allowOverrideUnavailability) {
            requestOverride(blockedUsers, () =>
                persistEventChange(ev, previewStart, previewEnd, movedUser ? previewUserId : null)
            )
        }
        return
    }
    await persistEventChange(...)
}
```

---

## Visual: Gray Stripes Preserved

The unavailability overlay uses `pointer-events-none z-[5]`. Events are rendered after the overlay in the DOM with a higher effective stacking, so placed events naturally appear on top of the stripes. No change needed.

---

## Files Changed

| File | Type |
|---|---|
| `resources/js/Components/Planner/UnavailabilityOverrideDialog.vue` | New |
| `resources/js/Components/Planner/ResourcePlannerWidget.vue` | Modified |
| `resources/js/Pages/Admin/GeneralSettingsPage.vue` | Modified |
| `app/Http/Controllers/Admin/GeneralSettingsController.php` | Modified |
| `app/Http/Controllers/PlannerController.php` | Modified |
| `routes/web.php` | Modified |

No migration needed.
