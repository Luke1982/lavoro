# Stage Flags Replace ServiceOrder Status

**Date:** 2026-06-01
**Status:** Draft

## Overview

Replace the `service_orders.status` column with three boolean flags on the `service_order_stages` table that put the user in control of which stages represent **planned**, **closed**, and **plannable** behaviour. The existing `is_closed_state` flag drives the `is_closed` accessor (which gates send-PDF, send-to-SnelStart, signature edits, ShowPage close button, …). The `is_planned_state` flag is auto-applied when an event is attached to an SO. The `is_plannable_state` flag (multi-select) gates which SOs appear in the planner's unplanned widget.

The `ServiceOrderStates` enum (currently unused) is deleted as cleanup.

## Database

### Migration 1 — add flag columns
Add to `service_order_stages`:
- `is_planned_state`   boolean default false
- `is_closed_state`    boolean default false
- `is_plannable_state` boolean default false

### Migration 2 — backfill + drop status
Up:
1. If any `service_orders.status = 'closed'` row exists AND no `service_order_stages` row has `is_closed_state = true`:
   - Create stage `name = 'Afgerond'`, `order = (max(order) ?? 0) + 1`, `is_closed_state = true`.
2. If any closed SOs exist: find the stage with `is_closed_state = true` (which now is guaranteed to exist) and `UPDATE service_orders SET service_order_stage_id = {that_id} WHERE status = 'closed'`.
3. `Schema::table('service_orders', fn ($t) => $t->dropColumn('status'));`

Down:
1. Re-add the `status` string column with default `'open'`.
2. `UPDATE service_orders SET status = 'closed' WHERE closed_on IS NOT NULL;` — using `closed_on` (preserved across the up/down round-trip) rather than the current stage flag, in case the user has since toggled `is_closed_state` off.
3. (No attempt to remove the auto-created "Afgerond" stage — leaving it in place is harmless and the user may have customised it.)

### Cleanup
Delete `app/Enums/ServiceOrderStates.php`. Grep-confirmed unused.

## Backend

### `app/Models/ServiceOrderStage.php`
- Extend `$fillable` with `'is_planned_state'`, `'is_closed_state'`, `'is_plannable_state'`.
- Add `protected $casts = ['is_planned_state' => 'boolean', 'is_closed_state' => 'boolean', 'is_plannable_state' => 'boolean'];`.

### `app/Models/ServiceOrder.php`
- Remove `'status'` from `$fillable`.
- Remove the `'status' => 'string'` cast entry.
- Rewrite `getIsClosedAttribute()`:
  ```php
  public function getIsClosedAttribute(): bool
  {
      return $this->serviceOrderStage?->is_closed_state === true;
  }
  ```
- Add `protected $appends = ['is_closed'];` so the accessor serialises to JSON for the frontend.
- Add `protected $with = ['serviceOrderStage'];` so the relation is always eager-loaded when the model is fetched. The stages table is tiny (a handful of rows); the extra trivial JOIN/lookup avoids N+1 hits everywhere `is_closed` is accessed (dashboard list, planner widget, email-PDF flows, etc.) without requiring every caller to remember an explicit `with()` call.
- New method:
  ```php
  public function advanceToPlannedStage(): void
  {
      $planned = ServiceOrderStage::where('is_planned_state', true)->first();
      if (!$planned) {
          return;
      }
      $current = $this->serviceOrderStage; // requires loaded relation or lazy-load
      if ($current && $current->order >= $planned->order) {
          return;
      }
      $this->service_order_stage_id = $planned->id;
      $this->save();
      $this->logActivity("Fase gewijzigd naar: {$planned->name} (door koppeling agenda)");
  }
  ```
  *Note*: callers should `$so->load('serviceOrderStage')` before invoking if the relation is not already loaded — the method tolerates lazy-load but avoids the extra query when pre-loaded.

### `app/Http/Requests/ServiceOrderUpdateRequest.php`
- **Remove** the `'status' => 'nullable|in:open,closed'` rule.
- Rewrite `authorize()`:
  ```php
  public function authorize(): bool
  {
      $user = Auth::user();
      $serviceorder = request()->route('serviceorder');
      if (!$user || !$serviceorder instanceof ServiceOrder) {
          return true;
      }
      $new_stage_id = $this->input('service_order_stage_id', '__unchanged__');
      if ($new_stage_id === '__unchanged__') {
          return true;
      }
      $current_is_closed = $serviceorder->is_closed;
      $new_stage = $new_stage_id === null
          ? null
          : ServiceOrderStage::find($new_stage_id);
      $new_is_closed = $new_stage?->is_closed_state === true;
      if ($new_is_closed && !$current_is_closed) {
          return $user->hasPermission('serviceorder.close');
      }
      if (!$new_is_closed && $current_is_closed) {
          return $user->hasPermission('serviceorder.reopen');
      }
      return true;
  }
  ```
- The existing `messages()` rule for `status.in` becomes dead — remove it.

### `app/Http/Controllers/ServiceOrderController.php`

**`update`** — rewrite the existing `closed_on` block so it's driven by stage transitions rather than the (now-gone) `status` field:

```php
public function update(ServiceOrderUpdateRequest $request, ServiceOrder $serviceorder)
{
    $data = $request->validated();
    $serviceorder->load('serviceOrderStage');
    $previous_stage_id = $serviceorder->service_order_stage_id;
    $previous_is_closed = $serviceorder->is_closed;

    $serviceorder->update($data);
    $serviceorder->load('serviceOrderStage');
    $new_is_closed = $serviceorder->is_closed;

    if ($new_is_closed && !$previous_is_closed) {
        $serviceorder->closed_on = now();
        $serviceorder->save();
    } elseif (!$new_is_closed && $previous_is_closed) {
        $serviceorder->closed_on = null;
        $serviceorder->save();
    }

    if (
        array_key_exists('service_order_stage_id', $data)
        && $data['service_order_stage_id'] != $previous_stage_id
    ) {
        if ($data['service_order_stage_id'] === null) {
            $serviceorder->logActivity('Fase verwijderd');
        } else {
            $new_stage = $serviceorder->serviceOrderStage;
            if ($new_stage) {
                $serviceorder->logActivity("Fase gewijzigd naar: {$new_stage->name}");
            }
        }
    }

    return redirect()->back()->with('success', 'Werkbon succesvol bijgewerkt.');
}
```

**`emailPdf` / `emailPdfWithJobs` / `sendToSnelStart`** — replace each `if ($serviceorder->status !== 'closed')` with `if (!$serviceorder->is_closed)`. Each of these three methods already calls `$serviceorder->load([...])`; add `'serviceOrderStage'` to the eager-load list.

**`show`** — pass an extra prop:
```php
'closedStageId' => ServiceOrderStage::where('is_closed_state', true)->value('id'),
```

### `app/Http/Controllers/ServiceOrderStageController.php`

In `store` and `update`, wrap the write in `DB::transaction(...)`. After validating, if the incoming payload has `is_planned_state === true`, run `ServiceOrderStage::where('id', '!=', $this_id)->update(['is_planned_state' => false]);` before creating/updating the current row. Same for `is_closed_state`. `is_plannable_state` flows through unchanged.

For `store`, the `$this_id` doesn't exist yet — clear the flags on every other row, then create. For `update`, exclude the current row via `where('id', '!=', $serviceorderstage->id)`.

### `app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php`
Extend `rules()`:
```php
'is_planned_state'   => ['sometimes', 'boolean'],
'is_closed_state'    => ['sometimes', 'boolean'],
'is_plannable_state' => ['sometimes', 'boolean'],
```

### `app/Http/Controllers/EventApiController.php`

After the `attach` calls in **both** `store` (line 57) and `update` (line 89), add:
```php
if ($model instanceof \App\Models\ServiceOrder) {
    $model->advanceToPlannedStage();
}
```

### `app/Http/Controllers/PlannerController.php`

Change the `unplannedServiceOrders` query — add the stage filter alongside the existing filters:
```php
'unplannedServiceOrders' => ServiceOrder::with(['customer', 'serviceOrderStage'])
    ->doesntHave('events')
    ->whereNull('project_id')
    ->whereHas('serviceOrderStage', fn ($q) => $q->where('is_plannable_state', true))
    ->orderByDesc('created_at')
    ->get(),
```

## Frontend

### `resources/js/Pages/ServiceOrderStages/IndexPage.vue`

Restructure the row grid to 12 columns: handle (1), Volgorde (1), Naam (4), Plannen (2), Sluiten (2), Plannable (1), Acties (1). Header row gets the three new labels (`Plannen`, `Sluiten`, `Plannable`).

Each of the three flag cells renders a `SwitchComponent`:
```vue
<div class="col-span-2 flex items-center justify-center">
    <SwitchComponent
        :model-value="stage.is_planned_state"
        @update:modelValue="(v) => saveStage(stage.id, { is_planned_state: v })" />
</div>
```
…analogously for `is_closed_state` (col-span-2) and `is_plannable_state` (col-span-1).

`saveStage` already exists and only sends `name`. Extend its signature:
```js
function saveStage(id, payload) {
    router.patch(`/serviceorderstages/${id}`, payload, { preserveScroll: true })
}
```
Existing `EditableTextField @update` callbacks pass `(val) => saveStage(stage.id, { name: val })`.

After a flag-toggle PATCH, Inertia re-renders with the updated paginator (server enforces planned/closed exclusivity, so the other rows' switches will already reflect the unset state).

Import `SwitchComponent from '@/Components/UI/SwitchComponent.vue'`.

### `resources/js/Pages/ServiceOrders/ShowPage.vue`

Replace status reads throughout (lines 118, 129, 138, 162, 182, 220, 241, 263, 267, 273, 281, 290–295):
- `serviceOrder.status === 'closed'` → `serviceOrder.is_closed`
- `serviceOrder.status !== 'closed'` → `!serviceOrder.is_closed`
- The fragment `serviceOrder.status !== 'open'` on line 294 disappears — the reopen button condition becomes simply `serviceOrder.is_closed && hasPermission('serviceorder.reopen')`.

Accept the new prop:
```js
closedStageId: { type: [Number, null], default: null },
```

Replace the two close/reopen buttons:
```vue
<button class="w-full p-4 rounded-md bg-green-600 text-white mt-3 hover:bg-green-700"
    @click="closeViaStage"
    v-if="props.closedStageId !== null && !serviceOrder.is_closed && hasPermission('serviceorder.close')">
    Werkbon afsluiten
</button>
<button class="w-full p-4 rounded-md bg-blue-500 text-white mt-3" @click="reopenViaStage"
    v-else-if="serviceOrder.is_closed && hasPermission('serviceorder.reopen')">
    Werkbon heropenen
</button>
```

Handlers — both delegate to the existing `onStageChange` (we wrote this in the stages feature):
```js
function closeViaStage() {
    if (!canClose.value) {
        alert('Vul zowel de naam als de handtekening in om de werkbon te kunnen afsluiten.')
        return
    }
    if (!confirm('Weet je zeker dat je de werkbon wilt sluiten? Je kunt er daarna geen wijzigingen meer in aanbrengen.')) {
        return
    }
    onStageChange(props.closedStageId)
}
function reopenViaStage() {
    onStageChange(null)
}
```

Delete the obsolete `updateStatus` function (lines 542–558).

### `resources/js/Components/UI/StepsProgressBar.vue`

Replace the entire `md:hidden` mobile block (currently lines 3–40 — a `<Listbox>` with bespoke option markup) with a single `<SelectMenuComponent>`:
```vue
<div class="md:hidden">
    <SelectMenuComponent
        :options="selectOptions"
        :model-value="modelValue"
        @update:modelValue="(v) => $emit('update:modelValue', v)" />
</div>
```
With a computed inside `<script setup>`:
```js
const selectOptions = computed(() => props.steps.map(s => ({
    value: s.id,
    title: s.name,
    description: s.description,
})))
```
Imports gain `SelectMenuComponent`; drop `Listbox`/`ListboxButton`/`ListboxLabel`/`ListboxOption`/`ListboxOptions` from `@headlessui/vue` and the `ChevronDownIcon` import that the old Listbox uses. The desktop `<nav aria-label="Progress" class="hidden md:block">` block stays unchanged.

This change also improves the Projects ShowPage mobile experience for free (same component).

## Permissions

- `serviceorder.close` / `serviceorder.reopen`: kept, semantics shift to "may transition (to|from) the closed-state stage". No DB changes.
- `serviceorderstage.update` already gates the flag-switch PATCHes. No new permissions.

## Edge cases

- **No closed-state stage configured** → `is_closed` is always false; "Werkbon afsluiten" button hidden via `closedStageId === null` guard; send-PDF and SnelStart remain blocked (`if (!$serviceorder->is_closed)`) — user must configure a closed-state stage and assign the SO to it before sending.
- **No planned-state stage** → `advanceToPlannedStage()` is a no-op; events attach without affecting stage.
- **No plannable stages** → `unplannedServiceOrders` returns empty; planner widget shows nothing.
- **Stage with `is_closed_state=true` deleted** → FK `nullOnDelete()` clears the column on closed SOs; `is_closed` flips to false; `closed_on` is preserved (not auto-cleared). On next `update()` the closed_on lifecycle re-applies if the user reassigns to another closed-state stage.
- **User toggles `is_closed_state` off** on the stage that closed SOs are currently assigned to → those SOs stop being "closed" per the accessor. `closed_on` is left in place. Mildly surprising but consistent with flag-driven semantics; accepted.
- **`advanceToPlannedStage` ordering** — uses `stage.order` for comparison, not `id`. SOs in stages with `order >= planned.order` are not touched. Specifically this protects already-closed SOs (typically `order` higher than planned) from being reverted by event attachment.
- **Backfill migration is reversible** — the down migration recreates `status` and re-derives `closed` from the current stage flags.
