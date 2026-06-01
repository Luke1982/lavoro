# Service Order Stages

**Date:** 2026-06-01
**Status:** Draft

## Overview

Introduce configurable **stages** (fases) for service orders. A new `ServiceOrderStage` model with `name` and `order` is managed via a drag-to-reorder CRUD page. Each `ServiceOrder` optionally references one stage. When two or more stages exist, the service order ShowPage renders a clickable steps-progress-bar; clicking a step advances the order to that stage.

A new top-level navigation chapter "Werkbonnen" is added with a sub-item "Fases". The chapter links to a new paginated service orders index page (search + filter by stage); the sub-item links to the stages CRUD.

## Database

### Migration 1 — `create_service_order_stages_table`
```
id
name           string
order          unsignedInteger default 0
timestamps
```

### Migration 2 — `add_service_order_stage_id_to_service_orders_table`
Add nullable FK column to `service_orders`:
```
service_order_stage_id  unsignedBigInteger nullable
                        FK -> service_order_stages.id  nullOnDelete()
```

Existing rows are untouched (no backfill).

### Migration 3 — seed permissions
Insert four rows into `permissions`:
```
serviceorderstage.read    | Werkbonfase zien
serviceorderstage.create  | Werkbonfase aanmaken
serviceorderstage.update  | Werkbonfase bijwerken
serviceorderstage.delete  | Werkbonfase verwijderen
```

`down()` deletes the four rows by name.

## Backend

### `app/Models/ServiceOrderStage.php` (new)
- `$fillable = ['name', 'order']`
- `serviceOrders()` → `hasMany(ServiceOrder::class)`

### `app/Models/ServiceOrder.php` (edit)
- Add `'service_order_stage_id'` to `$fillable`.
- Add `serviceOrderStage()` → `belongsTo(ServiceOrderStage::class)`.

### `app/Http/Requests/ServiceOrderStageReadRequest.php` (new)
- `authorize()` → admin OR `serviceorderstage.read`.
- `rules()` → `search: sometimes|nullable|string|max:255`.

### `app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php` (new)
- `authorize()` → on POST: admin OR `serviceorderstage.create`. On PUT/PATCH: admin OR `serviceorderstage.update`.
- `rules()`:
  - `name: required|string|max:255`
  - `order: nullable|integer|min:0`

### `app/Http/Requests/ServiceOrderStageReorderRequest.php` (new)
- `authorize()` → admin OR `serviceorderstage.update`.
- `rules()`:
  - `payload: required|array`
  - `payload.*.id: required|integer|exists:service_order_stages,id`
  - `payload.*.order: required|integer|min:0`

### `app/Http/Requests/ServiceOrderIndexRequest.php` (new)
- `authorize()` → admin OR `serviceorder.read`.
- `rules()`:
  - `search: sometimes|nullable|string|max:255`
  - `onlyStage: sometimes|nullable|integer|exists:service_order_stages,id`

### `app/Http/Requests/ServiceOrderUpdateRequest.php` (edit)
Add to `rules()`:
```
service_order_stage_id: nullable|exists:service_order_stages,id
```
Existing authorize/messages logic unchanged. Setting/changing the stage requires only the existing `serviceorder.update` permission path (no separate gate).

### `app/Http/Controllers/ServiceOrderStageController.php` (new)
Mirrors `ServiceCheckGroupController` shape.

- `index(ServiceOrderStageReadRequest $request)`:
  - Optional `search` filter on `name`.
  - Returns `inertia('ServiceOrderStages/IndexPage', ['stages' => ServiceOrderStage::orderBy('order')->paginate(25)])`.
- `store(ServiceOrderStageStoreUpdateRequest $request)`:
  - `order = (ServiceOrderStage::max('order') ?? 0) + 1` if not provided.
  - Create and `redirect()->route('serviceorderstages.index')->with('success', 'Fase is aangemaakt')` (matches `ServiceCheckGroupController` pattern).
- `update(ServiceOrderStageStoreUpdateRequest $request, ServiceOrderStage $serviceorderstage)`:
  - Update and `redirect()->route('serviceorderstages.index')->with('success', 'Fase is bijgewerkt')`.
- `destroy(ServiceOrderStage $serviceorderstage)`:
  - Delete and `redirect()->back()`. (FK nullOnDelete handles linked service orders.)
- `updateOrder(ServiceOrderStageReorderRequest $request)`:
  - For each `{id, order}` in `payload`, update the row inside a single DB transaction.
  - `redirect()->back()` with no flash; preserveScroll handled client-side.

### `app/Http/Controllers/ServiceOrderController.php` (edit)

`index(ServiceOrderIndexRequest $request)` — replace the empty stub with:
- Query `ServiceOrder::with(['customer', 'serviceOrderStage'])`.
- Apply `search` across `description`, `external_purchaseorder_no`, and `customer.name` (using `whereHas` on customer).
- Apply `onlyStage` filter when present.
- Order by `created_at desc`, paginate `$per_page = 25`.
- Return:
  ```
  inertia('ServiceOrders/IndexPage', [
      'serviceOrders' => $paginator,
      'stages'        => ServiceOrderStage::orderBy('order')->get(),
      'search'        => $request->get('search', ''),
      'onlyStage'     => $request->get('onlyStage'),
      'perPage'       => $per_page,
  ])
  ```

`show(string $id)` — extend the existing eager-loads:
- Add `'serviceOrderStage'` to the `with([...])` list.
- Pass an extra prop `'stages' => ServiceOrderStage::orderBy('order')->get()` so the progress bar can render even when the current stage is null.

### Activity logging
In `ServiceOrderController::update`, before `$serviceorder->update($data)` capture `$previous_stage_id = $serviceorder->service_order_stage_id`. After update, when `array_key_exists('service_order_stage_id', $data)` and the value differs from `$previous_stage_id`, call:
- `$serviceorder->logActivity("Fase gewijzigd naar: {$newStage->name}")` when a new stage is set, or
- `$serviceorder->logActivity('Fase verwijderd')` when cleared to null.

No log entry when the stage is absent from the payload or unchanged.

### Routes (`routes/web.php`)
Add inside the existing auth group (next to `servicecheckgroups`):
```php
Route::resource('serviceorderstages', ServiceOrderStageController::class)
    ->except(['show', 'edit', 'create']);
Route::post('serviceorderstages/reorder', [ServiceOrderStageController::class, 'updateOrder'])
    ->name('serviceorderstages.reorder');
```
The `Route::resource('serviceorders', ...)` line already registers `serviceorders.index`; it begins working once `ServiceOrderController::index` is implemented.

## Frontend

### `resources/js/Layouts/MainLayout.vue` (edit)
Add a new top-level nav item, placed after the `Tickets` ("Storingen") entry to group customer-facing work resources together:
```js
{
  name: 'Werkbonnen',
  href: '/serviceorders',
  icon: DocumentTextIcon,
  current: false,
  requiresPermission: 'serviceorder.read',
  children: [
    {
      name: 'Fases',
      href: '/serviceorderstages',
      icon: Bars4Icon,
      current: false,
      requiresPermission: 'serviceorderstage.read',
    },
  ],
  open: false,
}
```
Add the icon imports (`DocumentTextIcon`, `Bars4Icon`) to the existing `@heroicons/vue/24/outline` import block.

### `resources/js/Pages/ServiceOrders/IndexPage.vue` (new)
Visual layout mirrors `Pages/Products/IndexPage.vue` (header → filter slot with combobox + reset + active-filter chips → `BoxComponent` with `bg-lavoro-lightgray` grid header → striped rows → bottom bar with `PageRecordCountComponent` + `PaginationComponent` → empty state with icon).

- `IndexHeaderComponent`:
  - `title="Werkbonnen"`, `subtitle="Overzicht van alle werkbonnen"`.
  - `search-url="/serviceorders"`, `search-placeholder="Zoek op klant, beschrijving of inkoopordernr."`.
  - `:search-other-params="filterParams"` (computed: `{ onlyStage: stageFilter }`).
  - `:paginator="false"` (pagination is rendered at the bottom of the box, like Products).
  - `:has-active-filters="activeFilters.length > 0"`.
  - **No** `add-label` — service orders are created from other flows (tickets, assets, dashboard).
  - `#filters` slot:
    - `ComboBox` of stages (label `"Filter op fase"`) bound to `stageFilter`, plus `XCircleIcon` reset button (same shape as Products' type/brand filter rows).
    - Below the combobox, the active-filter chip strip + "Wis filters" link — copied from Products' `activeFilters` rendering (the small inline `<span>` chips with embedded SVG `×` button). This is a filter chip, not a status badge, so it stays as inline markup matching Products exactly.
- Listing inside `BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0"`:
  - Grid header row: `class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray"` with columns:
    - `col-span-3` **Klant**
    - `col-span-3` **Beschrijving**
    - `col-span-2` **Fase**
    - `col-span-2` **Status**
    - `col-span-1` **Aangemaakt**
    - `col-span-1 text-right` **Acties**
  - Each row: `class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2"`, content:
    - **Klant**: bold `<Link :href="`/customers/${so.customer.id}`">` with customer name; below, small `text-slate-600` `external_purchaseorder_no` if any. Mobile (`sm:hidden`) version shows stage badge + status badge stacked underneath, matching how Products mobile-stacks bundle badges.
    - **Beschrijving**: truncated description.
    - **Fase**: `<BadgeComponent :color="so.service_order_stage ? 'blue' : 'gray'" :has-dot="false">{{ so.service_order_stage?.name ?? 'Geen fase' }}</BadgeComponent>`.
    - **Status**: `<BadgeComponent :color="badgeColorFor(so)" :has-dot="false">{{ serviceOrderPillText(so) }}</BadgeComponent>`, where the local `badgeColorFor` maps the existing `serviceOrderSentState(so)`:
      - `both` / `administration` → `'green'`
      - `customer` → `'blue'`
      - `none` → `'gray'`
      This avoids creating a pill on the fly and reuses the existing Dutch label text. The bespoke `serviceOrderPillColorClasses` helper is **not** used here (it stays in place for other callers like the ShowPage; this design does not touch it).
    - **Aangemaakt**: `nlDate(so.created_at)`.
    - **Acties**: rounded action group identical to Products (`EyeIcon` linking to ShowPage; no delete here — destructive actions stay on the ShowPage).
- Bottom: `<div class="flex justify-between bg-white rounded-b-lavoro-sm p-4"> <PageRecordCountComponent :total="serviceOrders.total" :per-page="perPage" label="werkbonnen" /> <PaginationComponent v-if="serviceOrders.data.length" :paginator="serviceOrders" /> </div>`.
- Empty state when `serviceOrders.data.length === 0`: centered icon (`ClipboardDocumentListIcon` or similar) + `"Geen werkbonnen gevonden"`, matching Products' empty state pattern.
- `activeFilters` computed:
  - For each id in `stageFilter` (single-value here, but the chip strip still handles it), produce `{ key, label: 'Fase', value: stage.name, clear: () => stageFilter.value = null }`.
- `clearAllFilters()` resets `stageFilter` (and any future filters).
- Controller passes a `perPage` prop (default 25) to keep this consistent with Products.

### `resources/js/Pages/ServiceOrderStages/IndexPage.vue` (new)
Drag-to-reorder CRUD, modelled on `ServiceCheckValueListComponent` (drag mechanic) and `ServiceChecks/IndexPage.vue` (open/save inline edit pattern):

- `IndexHeaderComponent`:
  - `title="Werkbonfases"`, `subtitle="Overzicht en volgorde van werkbonfases"`.
  - `search-url="/serviceorderstages"`.
  - `paginator="stages"`.
  - `add-label="Voeg fase toe"`, opens a `CreateRecordForm` with a single `name` text field, action `/serviceorderstages`.
- `BoxComponent` containing:
  - `<draggable v-model="list" item-key="id" handle=".draghandle" :animation="200" @change="onReorder">`.
  - Per-row template (mobile-responsive grid, same odd/even striping as `ServiceChecks/IndexPage.vue`):
    - Drag handle: `<Bars4Icon class="size-6 cursor-move draghandle" />`.
    - Name: text in read mode, `TextInput` in open mode.
    - Order: read-only display of `order`.
    - Actions: edit (toggles `open`), save (`CheckIcon`, PATCH), delete (`TrashIcon`, DELETE with confirm).
- `onReorder`:
  ```
  updateForm.payload = list.value.map((s, i) => ({ id: s.id, order: i }))
  updateForm.post('/serviceorderstages/reorder', { preserveScroll: true })
  ```
- `import { VueDraggableNext as draggable } from 'vue-draggable-next'`.
- `PaginationComponent` at the bottom (paginator size large enough that reordering rarely crosses page boundaries; 25 default is fine).

### Progress bar — reuse `Components/UI/StepsProgressBar.vue`
The existing `StepsProgressBar` already provides exactly the needed UX: a horizontal numbered stepper with `complete` / `current` / `upcoming` styling, click-to-emit `update:modelValue`, and a mobile-only `Listbox` fallback. No new component.

Stage rows are passed via `:steps` as `[{ id, name }, ...]` (it accepts any object with `id` + `name`; an optional `description` is rendered in the mobile listbox).

### `resources/js/Pages/ServiceOrders/ShowPage.vue` (edit)
- Accept new prop `stages: Array` (default `[]`).
- Just above the "Uitgevoerde werkzaamheden" heading (around line 117), render:
  ```vue
  <div v-if="stages.length > 1" class="mb-4" :class="{ 'pointer-events-none opacity-60': serviceOrder.status === 'closed' }">
    <StepsProgressBar
      :steps="stages"
      :model-value="serviceOrder.service_order_stage_id"
      @update:modelValue="onStageChange"
    />
  </div>
  ```
- `onStageChange(stageId)`:
  ```js
  const form = useForm({
    customer_id: serviceOrder.customer_id,
    service_order_stage_id: stageId,
  })
  form.patch(`/serviceorders/${serviceOrder.id}`, { preserveScroll: true })
  ```
  `customer_id` is included because `ServiceOrderUpdateRequest::rules()` requires it.
- Import `StepsProgressBar` from `@/Components/UI/StepsProgressBar.vue`.
- The closed-SO gating uses `pointer-events-none + opacity-60` on the wrapper (no change to the shared `StepsProgressBar` component).

## Edge cases

- **0 or 1 stage exists** → progress bar is hidden on ShowPage (`v-if="stages.length > 1"`); IndexPage filter combobox renders an empty/single-option list (the filter is still functional, just unhelpful).
- **`service_order_stage_id` is null on a SO** → progress bar renders all stages as upcoming; clicking any sets it.
- **Stage deleted while assigned to one or more SOs** → `nullOnDelete()` clears the FK; SOs survive with no stage.
- **Reorder while another user has the list open** → last write wins; both writes are simple integer updates and idempotent given the same payload.
- **SO closed** → progress bar still visible but `:disabled="true"`; users with `serviceorder.reopen` can reopen via the existing flow before changing the stage.
- **Index page search with `onlyStage` filter** → both filters AND together; pagination preserves both via `search-other-params`.

## Permissions summary

New: `serviceorderstage.{read, create, update, delete}` (4 rows).
Existing reused: `serviceorder.read` (for the index page), `serviceorder.update` (for setting the stage from the progress bar).
