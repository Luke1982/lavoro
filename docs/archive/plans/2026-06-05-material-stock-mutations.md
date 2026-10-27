# Material Stock Mutations & Timeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically adjust a material's stock when it is attached to, detached from, or updated on a service order, and show a timeline of those mutations on the Material show page.

**Architecture:** The existing `logActivity` / `also_attach_to` mechanism is extended so that every material-related activity on a service order is simultaneously attached to the material via the `activityables` pivot. Stock is adjusted in-place on the `materials` row via `increment`/`decrement`. The Material show page reuses the existing `TimelineComponent` with a small addition for a service order back-link.

**Tech Stack:** Laravel 12, Eloquent morphToMany, Inertia, Vue 3, existing `HasActivities` trait, existing `TimelineComponent`

---

## File Map

| File | Change |
|------|--------|
| `app/Models/Material.php` | Add `use HasActivities` trait |
| `app/Http/Controllers/ServiceOrderController.php` | Adjust stock + add `also_attach_to`/`metadata` in `attachMaterial`, `detachMaterial`, `updateMateriable` |
| `app/Http/Controllers/MaterialController.php` | Eager-load `activities.user` in `show()`, pass as Inertia prop |
| `resources/js/Components/Timeline/TimelineComponent.vue` | Map `metadata.service_order_id` to a back-link in the computed items |
| `resources/js/Pages/Materials/ShowPage.vue` | Add `activities` prop + Tijdlijn section |

---

### Task 1: Add HasActivities trait to Material model

**Files:**
- Modify: `app/Models/Material.php`

The `HasActivities` trait defines `activities()` as a morphToMany and provides `logActivity()`. Adding it to Material means the `also_attach_to` check inside `logActivity` (`method_exists($model, 'activities')`) will pass, allowing the service order's activity to be cross-attached to the material.

- [ ] **Step 1: Add the trait import and usage to Material**

Open `app/Models/Material.php`. Add the `use` import and the trait declaration:

```php
<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $divisable
 * @property bool $is_active
 * @property bool $is_service
 */
class Material extends Model
{
    use HasActivities;

    protected $fillable = [
        'name',
        'description',
        'material_category_id',
        'material_usage_unit_id',
        'price',
        'snelstart_id',
        'code',
        'vendor_code',
        'cost_price',
        'divisable',
        'is_active',
        'is_service',
        'stock',
        'min_stock',
        'max_stock',
    ];

    protected $casts = [
        'divisable'  => 'boolean',
        'is_active'  => 'boolean',
        'is_service' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    public function usageUnit()
    {
        return $this->belongsTo(MaterialUsageUnit::class, 'material_usage_unit_id');
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main']);
    }

    public function suppliers()
    {
        return $this->morphToMany(Supplier::class, 'suppliable')
            ->withPivot('article_number', 'is_preferred')
            ->withTimestamps();
    }
}
```

- [ ] **Step 2: Verify the trait loads cleanly**

Run: `php artisan tinker --execute="App\Models\Material::first()->activities()->count();"`

Expected: a number (0 or more), no exception.

---

### Task 2: Modify `attachMaterial` — stock decrement + dual-attach

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php` (lines 638–654)

After attaching the pivot, decrement `material.stock` by the attached quantity. Pass `$material` in `also_attach_to` and include service order metadata so the timeline can show a back-link.

> Note: `$serviceorder->number` is the display number field on ServiceOrder. If that field does not exist on the model, use `$serviceorder->id` instead.

- [ ] **Step 1: Replace `attachMaterial` body**

```php
public function attachMaterial(
    ServiceOrderAttachMaterialRequest $request,
    ServiceOrder $serviceorder,
    Material $material
) {
    $validated = $request->validated();
    $serviceorder->materials()->attach($material, [
        'quantity'  => $validated['quantity'],
        'unforseen' => $validated['unforseen'] ?? false,
    ]);
    $material->decrement('stock', $validated['quantity']);
    $serviceorder->logActivity(
        sprintf('Materiaal toegevoegd: %s (aantal %s)', $material->name, $validated['quantity']),
        also_attach_to: [$material],
        metadata: [
            'service_order_id'     => $serviceorder->id,
            'service_order_number' => $serviceorder->number,
        ]
    );

    return redirect()->back()->with('success', 'Materiaal succesvol gekoppeld aan de werkbon.');
}
```

- [ ] **Step 2: Smoke-test in the browser**

Open a service order, attach a material with quantity 2. Confirm:
- The material appears on the service order.
- `materials.stock` for that material decreased by 2 (check via `php artisan tinker --execute="App\Models\Material::find(ID)->stock;"` or the Material show page).
- The service order activity timeline shows a new "Materiaal toegevoegd" entry.

---

### Task 3: Modify `detachMaterial` — stock restore + dual-attach

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php` (lines 656–680)

The existing code already reads `$record->material_id` before deleting. Add a read of `$record->quantity`, restore stock, and dual-attach the activity.

- [ ] **Step 1: Replace `detachMaterial` body**

```php
public function detachMaterial(
    ServiceOrderDetachMaterialRequest $request,
    ServiceOrder $serviceorder,
    string $materiable_id
) {
    $pivotQuery = $serviceorder
        ->materials()
        ->newPivotQuery()
        ->where('materiables.id', $materiable_id);

    $record   = $pivotQuery->first();
    $material = $record ? Material::find($record->material_id) : null;
    $quantity = $record ? (float) $record->quantity : 0;

    $pivotQuery->delete();

    if ($material) {
        $material->increment('stock', $quantity);
        $serviceorder->logActivity(
            sprintf('Materiaal verwijderd: %s', $material->name),
            also_attach_to: [$material],
            metadata: [
                'service_order_id'     => $serviceorder->id,
                'service_order_number' => $serviceorder->number,
            ]
        );
    }

    return redirect()->back()
        ->with('success', 'Materiaal succesvol losgekoppeld van de werkbon.');
}
```

- [ ] **Step 2: Smoke-test**

Detach the material added in Task 2. Confirm stock returns to its pre-attach value.

---

### Task 4: Modify `updateMateriable` — stock delta + dual-attach

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php` (lines 682–721)

The existing code has duplicate lines (lines 693–701 repeated). This task fixes that incidentally. When the quantity changes, compute `delta = new − old` and decrement stock by delta (if delta is negative this effectively increments). Only the quantity `logActivity` call gets `also_attach_to`; the `unforseen` toggle does not affect stock.

- [ ] **Step 1: Replace `updateMateriable` body**

```php
public function updateMateriable(
    ServiceOrderUpdateMateriableRequest $request,
    ServiceOrder $serviceorder,
    string $materiable_id
) {
    $pivotQuery = $serviceorder
        ->materials()
        ->newPivotQuery()
        ->where('materiables.id', $materiable_id);

    $record   = $pivotQuery->first();
    $material = $record ? Material::find($record->material_id) : null;

    $validated = $request->validated();
    $pivotQuery->update($validated);

    if ($material) {
        if (array_key_exists('quantity', $validated)) {
            $old_quantity = (float) $record->quantity;
            $new_quantity = (float) $validated['quantity'];
            $delta        = $new_quantity - $old_quantity;
            if ($delta !== 0.0) {
                $material->decrement('stock', $delta);
            }
            $serviceorder->logActivity(
                sprintf('Materiaal hoeveelheid aangepast: %s naar %s', $material->name, $validated['quantity']),
                also_attach_to: [$material],
                metadata: [
                    'service_order_id'     => $serviceorder->id,
                    'service_order_number' => $serviceorder->number,
                ]
            );
        }
        if (array_key_exists('unforseen', $validated)) {
            $serviceorder->logActivity(sprintf(
                'Materiaal gemarkeerd als %s: %s',
                $validated['unforseen'] ? 'onvoorzien' : 'voorzien',
                $material->name
            ));
        }
    }

    return redirect()->back()
        ->with('success', 'Materiaal succesvol bijgewerkt.');
}
```

- [ ] **Step 2: Smoke-test**

On a service order, change a material's quantity from 2 to 5. Confirm stock decreased by 3. Change it back to 2. Confirm stock increased by 3.

---

### Task 5: Pass activities to Material show page

**Files:**
- Modify: `app/Http/Controllers/MaterialController.php` (lines 86–106)

Eager-load `activities` with `user`, ordered newest first. Pass as an Inertia prop.

- [ ] **Step 1: Update `show()` method**

```php
public function show(MaterialReadRequest $request, Material $material)
{
    $material->load([
        'category',
        'usageUnit',
        'suppliers',
        'activities' => fn ($q) => $q->with('user')->latest(),
    ]);

    $supplier_count = \App\Models\Supplier::count();
    $all_suppliers  = $supplier_count <= 50
        ? \App\Models\Supplier::orderBy('name')->get(['id', 'name'])
        : collect();

    return inertia('Materials/ShowPage', [
        'material'          => $material,
        'categories'        => \App\Models\MaterialCategory::orderBy('name')->get(['id', 'name']),
        'usageUnits'        => \App\Models\MaterialUsageUnit::orderBy('name')->get(['id', 'name']),
        'materialSuppliers' => $material->suppliers->map(fn ($s) => [
            'id'             => $s->id,
            'name'           => $s->name,
            'article_number' => $s->pivot->article_number,
            'is_preferred'   => (bool) $s->pivot->is_preferred,
        ])->values()->all(),
        'allSuppliers'      => $all_suppliers,
        'suppliersUseAjax'  => $supplier_count > 50,
        'activities'        => $material->activities,
    ]);
}
```

- [ ] **Step 2: Verify the prop arrives**

Open a material's show page in the browser. Open the browser devtools → Inertia page props. Confirm `activities` array is present (may be empty if no mutations yet).

---

### Task 6: Extend TimelineComponent to render service order back-link

**Files:**
- Modify: `resources/js/Components/Timeline/TimelineComponent.vue`

Add `serviceOrderId` and `serviceOrderNumber` to the mapped items. Render a link when `serviceOrderId` is present. This is fully backwards-compatible — existing usages without metadata are unaffected.

- [ ] **Step 1: Add fields to the computed `visibleItems` map**

In the `.map(a => { ... })` block (around line 80), add two new fields:

```js
serviceOrderId:     a.metadata?.service_order_id ?? null,
serviceOrderNumber: a.metadata?.service_order_number ?? null,
```

The full updated `visibleItems` computed:

```js
const visibleItems = computed(() => (expanded.value ? raw.value : raw.value.slice(0, props.limit)).map(a => {
    const meta = CATEGORY_MAP[a.category] || fallback;
    return {
        id:                 a.id,
        icon:               meta.icon,
        iconBackground:     meta.bg,
        iconStyle:          a.color ? { backgroundColor: a.color } : undefined,
        rendered:           a.rendered ?? a.description,
        user:               a.user ?? null,
        executing_users:    a.executing_users || [],
        thumbnailPath:      a.metadata?.thumbnail_path ?? null,
        is_event:           a.category === 'event',
        completed:          typeof a.status === 'string' ? a.status === 'Afgerond' : false,
        date:               formatDate(a.created_at),
        datetime:           a.created_at,
        serviceOrderId:     a.metadata?.service_order_id ?? null,
        serviceOrderNumber: a.metadata?.service_order_number ?? null,
    };
}));
```

- [ ] **Step 2: Render the link in the template**

After the `<div v-if="event.user" ...>` block (around line 18), add:

```vue
<div v-if="event.serviceOrderId" class="mt-0.5 text-[11px]">
    <a :href="`/serviceorders/${event.serviceOrderId}`"
       class="text-blue-500 hover:underline">
        Werkbon #{{ event.serviceOrderNumber }}
    </a>
</div>
```

The full inner `min-w-0 flex-1` div after the change:

```vue
<div class="min-w-0 flex-1">
    <div class="text-sm text-gray-600 dark:text-gray-400 flex items-center gap-1">
        <span v-html="event.rendered"></span>
    </div>
    <div v-if="event.user" class="mt-0.5 text-[11px] text-gray-400 dark:text-slate-500">
        {{ event.user.name }}
    </div>
    <div v-if="event.serviceOrderId" class="mt-0.5 text-[11px]">
        <a :href="`/serviceorders/${event.serviceOrderId}`"
           class="text-blue-500 hover:underline">
            Werkbon #{{ event.serviceOrderNumber }}
        </a>
    </div>
    <div v-if="event.executing_users?.length" class="mt-1 flex flex-wrap gap-2">
        <div v-for="u in event.executing_users" :key="u.id"
            class="inline-flex items-center gap-1">
            <img v-if="u.avatar" :src="u.avatar" :alt="u.name"
                class="h-4 w-4 rounded-full ring-1 ring-gray-300 object-cover" />
            <span v-else
                class="h-4 w-4 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-[9px] font-medium ring-1 ring-gray-300">{{
                    initials(u.name) }}</span>
            <span class="text-[11px] leading-none text-gray-600 dark:text-gray-400">{{
                u.name }}</span>
        </div>
    </div>
    <div v-if="event.thumbnailPath" class="mt-2">
        <img :src="`/storage/${event.thumbnailPath}`" alt=""
            class="h-16 w-24 object-cover rounded-md border border-gray-200 dark:border-slate-700" />
    </div>
</div>
```

- [ ] **Step 3: Verify no regressions**

Open a service order show page and check its activity timeline still renders correctly (no service order link appears since those activities have no `service_order_id` in metadata).

---

### Task 7: Add Tijdlijn section to Materials/ShowPage.vue

**Files:**
- Modify: `resources/js/Pages/Materials/ShowPage.vue`

Add `activities` prop, import `TimelineComponent`, and add a "Tijdlijn" `BoxComponent` below the existing grid. The timeline is placed in the left column (full-width below the main grid) to keep the supplier panel on the right.

- [ ] **Step 1: Add the `activities` prop**

In the `defineProps` block (around line 178), add:

```js
const props = defineProps({
    material:          { type: Object, required: true },
    categories:        { type: Array, default: () => [] },
    usageUnits:        { type: Array, default: () => [] },
    materialSuppliers: { type: Array, default: () => [] },
    allSuppliers:      { type: Array, default: () => [] },
    suppliersUseAjax:  { type: Boolean, default: false },
    activities:        { type: Array, default: () => [] },
});
```

- [ ] **Step 2: Import TimelineComponent and BoxComponent (BoxComponent already imported)**

Add to the import block at the top of `<script setup>`:

```js
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue';
```

(Note: `BoxComponent` is already imported on line 173.)

- [ ] **Step 3: Add the Tijdlijn section after the grid**

After the closing `</div>` of the `grid grid-cols-1 lg:grid-cols-3` div (line 168), add:

```vue
<div class="mt-6">
    <BoxComponent>
        <div class="flex items-center mb-4">
            <span class="text-md font-bold">Tijdlijn</span>
        </div>
        <TimelineComponent :activities="activities" />
    </BoxComponent>
</div>
```

- [ ] **Step 4: Verify the full flow end-to-end**

1. Open a material's show page. Confirm the "Tijdlijn" section appears (showing "Geen activiteiten" if empty).
2. Open a service order, attach this material with quantity 3.
3. Return to the material's show page. Confirm:
   - "Tijdlijn" shows "Materiaal toegevoegd: {name} (aantal 3)" with the user name and a "Werkbon #X" link.
   - The `stock` field in "Voorraad" decreased by 3.
4. On the service order, update the quantity to 5. Return to the material. Confirm:
   - New "Materiaal hoeveelheid aangepast" entry appears in the timeline with "Werkbon #X" link.
   - Stock decreased by 2 more.
5. Remove the material from the service order. Confirm:
   - "Materiaal verwijderd" entry appears in the timeline.
   - Stock is restored to its original value.
