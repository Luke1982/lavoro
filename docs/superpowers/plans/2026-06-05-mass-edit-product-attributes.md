# Mass-edit product attributes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users select multiple products on the Products index page and bulk-apply ProductAttributeValue assignments via a drawer, with per-attribute opt-in checkboxes and warnings when an attribute doesn't apply to all selected products.

**Architecture:** Checkbox column + sticky action bar in `IndexPage.vue` drive a `DrawerComponent` that renders per-attribute rows using `AnimatedCheckbox` + `ComboBox`. A single new POST route delegates to a new controller action that runs inside a `DB::transaction`, filtering each attribute change to qualifying products only.

**Tech Stack:** Laravel 12, Inertia, Vue 3 (Composition API), Headless UI, Tailwind CSS.

---

## File map

| Action | File |
|---|---|
| Modify | `app/Http/Controllers/ProductController.php` |
| Create | `app/Http/Requests/ProductBulkUpdateAttributesRequest.php` |
| Modify | `routes/web.php` |
| Modify | `resources/js/Components/UI/AnimatedCheckbox.vue` |
| Modify | `resources/js/Pages/Products/IndexPage.vue` |

---

### Task 1: Extend `productAttributes` prop with `product_type_ids`

**Files:**
- Modify: `app/Http/Controllers/ProductController.php` (the `index` method, around line 68)

The drawer needs to know which attributes apply to which product types so it can derive coverage counts client-side. Add `productTypes` to the eager load and expose a `product_type_ids` array per attribute.

- [ ] **Step 1: Update the productAttributes query in `ProductController::index`**

Replace:
```php
'productAttributes' => ProductAttribute::with('values')
    ->get()
    ->filter(fn($attr) => $attr->values->isNotEmpty())
    ->map(fn($attr) => [
        'id' => $attr->id,
        'name' => $attr->name,
        'values' => $attr->values->map(fn($v) => ['id' => $v->id, 'value' => $v->value])->values(),
    ])
    ->values(),
```

With:
```php
'productAttributes' => ProductAttribute::with(['values', 'productTypes'])
    ->get()
    ->filter(fn($attr) => $attr->values->isNotEmpty())
    ->map(fn($attr) => [
        'id'               => $attr->id,
        'name'             => $attr->name,
        'values'           => $attr->values->map(fn($v) => ['id' => $v->id, 'value' => $v->value])->values(),
        'product_type_ids' => $attr->productTypes->pluck('id')->values(),
    ])
    ->values(),
```

- [ ] **Step 2: Verify the page still loads**

```bash
php artisan route:list | grep products
```

Open `/products` in the browser and confirm the page renders without errors. Open DevTools → Network → XHR and inspect the Inertia response; confirm each `productAttributes` entry now has a `product_type_ids` array.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/ProductController.php
git commit -m "feat(products): expose product_type_ids on productAttributes index prop"
```

---

### Task 2: Add `color` prop to `AnimatedCheckbox`

**Files:**
- Modify: `resources/js/Components/UI/AnimatedCheckbox.vue`

The drawer checkboxes need to use `#081020` (lavorodark) instead of green. Add a `color` prop that replaces the hardcoded `var(--color-lavoro-green)` in the three SVG elements that use it. The checkmark stroke stays white (it provides contrast against the dark background).

- [ ] **Step 1: Add the `color` prop and wire it into the template**

In the `<script setup>` block, replace the existing `defineProps` call:
```js
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    disabled:   { type: Boolean, default: false },
})
```
with:
```js
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    disabled:   { type: Boolean, default: false },
    color:      { type: String, default: 'var(--color-lavoro-green)' },
})
```

- [ ] **Step 2: Use `color` in the SVG template**

In the `<template>`, make three changes:

1. Background circle — change `fill="var(--color-lavoro-green)"` to `:fill="color"`
2. Track ring — change `'var(--color-lavoro-green)'` to `color` in the `:stroke` binding:
   ```html
   :stroke="trackGreen ? color : '#d1d5db'"
   ```
3. Progress ring — change `stroke="var(--color-lavoro-green)"` to `:stroke="color"`

The checkmark `<path>` keeps `stroke="white"` untouched.

- [ ] **Step 3: Verify existing usage is unaffected**

Open any page that uses `AnimatedCheckbox` (e.g. any job/task list). Confirm the checkbox still animates green (the prop defaults to the CSS variable, so existing callers are unchanged).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/UI/AnimatedCheckbox.vue
git commit -m "feat(ui): add color prop to AnimatedCheckbox, default keeps existing green"
```

---

### Task 3: Create `ProductBulkUpdateAttributesRequest`

**Files:**
- Create: `app/Http/Requests/ProductBulkUpdateAttributesRequest.php`

- [ ] **Step 1: Create the Form Request**

```php
<?php

namespace App\Http\Requests;

use App\Models\ProductAttributeValue;
use Illuminate\Foundation\Http\FormRequest;

class ProductBulkUpdateAttributesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('product.update');
    }

    public function rules(): array
    {
        return [
            'product_ids'                             => ['required', 'array', 'min:1'],
            'product_ids.*'                           => ['integer', 'exists:products,id'],
            'attributes'                              => ['required', 'array', 'min:1'],
            'attributes.*.product_attribute_id'       => ['required', 'integer', 'exists:product_attributes,id'],
            'attributes.*.product_attribute_value_id' => [
                'required',
                'integer',
                'exists:product_attribute_values,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $index  = explode('.', $attribute)[1];
                    $attrId = $this->input("attributes.{$index}.product_attribute_id");
                    if (! ProductAttributeValue::where('id', $value)
                        ->where('product_attribute_id', $attrId)
                        ->exists()) {
                        $fail('De geselecteerde waarde hoort niet bij het opgegeven kenmerk.');
                    }
                },
            ],
        ];
    }
}
```

- [ ] **Step 2: Verify the class resolves**

```bash
php artisan tinker --execute="new App\Http\Requests\ProductBulkUpdateAttributesRequest;"
```

Expected: no error output.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/ProductBulkUpdateAttributesRequest.php
git commit -m "feat(products): add ProductBulkUpdateAttributesRequest"
```

---

### Task 4: Add route and `bulkUpdateAttributes` controller action

**Files:**
- Modify: `routes/web.php` (around line 88–89)
- Modify: `app/Http/Controllers/ProductController.php`

- [ ] **Step 1: Add the route to `routes/web.php`**

Find this block (around line 88):
```php
Route::resource('producttypes', ProductTypeController::class)->except(['show', 'edit', 'create']);
Route::resource('products', ProductController::class);
```

Change it to:
```php
Route::resource('producttypes', ProductTypeController::class)->except(['show', 'edit', 'create']);
Route::post('products/bulk-update-attributes', [ProductController::class, 'bulkUpdateAttributes'])
    ->name('products.bulk-update-attributes');
Route::resource('products', ProductController::class);
```

- [ ] **Step 2: Add `bulkUpdateAttributes` to `ProductController`**

Add these two use statements at the top of `ProductController.php` if not already present:
```php
use App\Http\Requests\ProductBulkUpdateAttributesRequest;
use Illuminate\Support\Facades\DB;
```

Then add this method to `ProductController`, after the `update` method:
```php
public function bulkUpdateAttributes(ProductBulkUpdateAttributesRequest $request)
{
    DB::transaction(function () use ($request) {
        $products = Product::whereIn('id', $request->product_ids)
            ->with('productType.productAttributes')
            ->get();

        foreach ($request->attributes as $attr) {
            $attributeId = $attr['product_attribute_id'];
            $valueId     = $attr['product_attribute_value_id'];

            foreach ($products as $product) {
                if (! $product->productType->productAttributes->contains('id', $attributeId)) {
                    continue;
                }

                $product->productAttributeValueables()
                    ->where('product_attribute_id', $attributeId)
                    ->delete();

                $product->productAttributeValueables()->create([
                    'product_attribute_id'        => $attributeId,
                    'product_attribute_value_id'  => $valueId,
                ]);
            }
        }
    });

    return redirect()->back()->with('success', 'Kenmerken bijgewerkt.');
}
```

- [ ] **Step 3: Verify the route exists**

```bash
php artisan route:list | grep bulk
```

Expected output includes a line with `POST` and `products/bulk-update-attributes`.

- [ ] **Step 4: Commit**

```bash
git add routes/web.php app/Http/Controllers/ProductController.php
git commit -m "feat(products): add bulk-update-attributes route and controller action"
```

---

### Task 5: Add checkbox column and selection state to `IndexPage.vue`

**Files:**
- Modify: `resources/js/Pages/Products/IndexPage.vue`

This task adds the `AnimatedCheckbox` import, selection refs/computed/helpers, the checkbox column in the table header and rows, and a grid adjustment (model column shrinks by 1 span to make room).

- [ ] **Step 1: Add the `AnimatedCheckbox` import**

In the `<script setup>` block, add this import after the existing UI component imports (around line 305):
```js
import AnimatedCheckbox from '@/Components/UI/AnimatedCheckbox.vue'
```

- [ ] **Step 2: Add selection state and helpers**

In the `<script setup>` block, add after the `showProductDrawer` ref (after line 315):
```js
const selectedIds = ref([])

const allCurrentPageSelected = computed(() =>
    displayProducts.value.length > 0 &&
    displayProducts.value.every(p => selectedIds.value.includes(p.id))
)

function toggleSelectProduct(id) {
    const idx = selectedIds.value.indexOf(id)
    if (idx === -1) {
        selectedIds.value.push(id)
    } else {
        selectedIds.value.splice(idx, 1)
    }
}

function toggleSelectAll() {
    if (allCurrentPageSelected.value) {
        const pageIds = new Set(displayProducts.value.map(p => p.id))
        selectedIds.value = selectedIds.value.filter(id => !pageIds.has(id))
    } else {
        const existing = new Set(selectedIds.value)
        displayProducts.value.forEach(p => {
            if (!existing.has(p.id)) selectedIds.value.push(p.id)
        })
    }
}
```

- [ ] **Step 3: Update the table header to include the checkbox column**

In the `<template>`, find the header row (around line 76):
```html
<div
    class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
    <div class="col-span-4">Model</div>
    <div class="col-span-2">Merk</div>
    <div class="col-span-2">Producttype</div>
    <div class="col-span-2">Verkoopperiode</div>
    <div class="col-span-1">Bundel</div>
    <div class="col-span-1 text-right">Acties</div>
</div>
```

Replace with:
```html
<div
    class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
    <div class="col-span-1 flex items-center">
        <AnimatedCheckbox
            :model-value="allCurrentPageSelected"
            color="#081020"
            @update:model-value="toggleSelectAll"
        />
    </div>
    <div class="col-span-3">Model</div>
    <div class="col-span-2">Merk</div>
    <div class="col-span-2">Producttype</div>
    <div class="col-span-2">Verkoopperiode</div>
    <div class="col-span-1">Bundel</div>
    <div class="col-span-1 text-right">Acties</div>
</div>
```

- [ ] **Step 4: Update each product row to include the checkbox cell**

Find the row opening (around line 85–87):
```html
<div v-for="product in displayProducts" :key="product.id" role="row"
    class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2">
    <div class="col-span-10 sm:col-span-4 flex items-center gap-4">
```

Replace with:
```html
<div v-for="product in displayProducts" :key="product.id" role="row"
    :class="['grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2', selectedIds.includes(product.id) && 'bg-blue-50 dark:bg-slate-800/60']">
    <div class="col-span-1 flex items-center">
        <AnimatedCheckbox
            :model-value="selectedIds.includes(product.id)"
            color="#081020"
            @update:model-value="toggleSelectProduct(product.id)"
        />
    </div>
    <div class="col-span-9 sm:col-span-3 flex items-center gap-4">
```

- [ ] **Step 5: Verify selection works**

Run `npm run dev`, open `/products`, and confirm:
- Each row has a checkbox on the left.
- Clicking a row checkbox toggles the blue background highlight.
- The header checkbox selects/deselects all rows on the current page.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Products/IndexPage.vue
git commit -m "feat(products): add checkbox column and selection state to products index"
```

---

### Task 6: Add sticky action bar to `IndexPage.vue`

**Files:**
- Modify: `resources/js/Pages/Products/IndexPage.vue`

- [ ] **Step 1: Add the sticky action bar at the end of the `<template>`, just before `</template>`**

Add after the closing `</DrawerComponent>` tag (currently around line 286) and before `</template>` (line 287):
```html
<Teleport to="body">
    <Transition
        enter-active-class="transition ease-out duration-200"
        enter-from-class="translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition ease-in duration-150"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-full opacity-0"
    >
        <div v-if="selectedIds.length"
            class="fixed bottom-0 inset-x-0 z-40 bg-lavoro-dark text-white px-6 py-4 flex items-center justify-between shadow-2xl">
            <div class="flex items-center gap-4 text-sm">
                <span class="font-bold text-base">{{ selectedIds.length }} producten geselecteerd</span>
                <button type="button" @click="selectedIds = []"
                    class="text-xs text-slate-400 underline hover:text-slate-200">
                    Deselecteer alles
                </button>
            </div>
            <button type="button" @click="openBulkEditDrawer"
                class="bg-white text-lavoro-dark font-bold text-sm px-5 py-2 rounded-md hover:bg-gray-100">
                Kenmerken bewerken
            </button>
        </div>
    </Transition>
</Teleport>
```

- [ ] **Step 2: Add `openBulkEditDrawer` and its state to `<script setup>`**

Add after the `toggleSelectAll` function (end of Task 5 additions):
```js
const bulkEditOpen   = ref(false)
const bulkEditChecked = reactive({})
const bulkEditValues  = reactive({})

const drawerAttributes = computed(() => {
    if (!selectedIds.value.length) return []
    const selectedTypeIds = new Set(
        displayProducts.value
            .filter(p => selectedIds.value.includes(p.id))
            .map(p => p.product_type_id)
    )
    return productAttributes
        .filter(attr => attr.product_type_ids.some(tid => selectedTypeIds.has(tid)))
        .map(attr => {
            const applicableCount = displayProducts.value.filter(
                p => selectedIds.value.includes(p.id) &&
                     attr.product_type_ids.includes(p.product_type_id)
            ).length
            return { ...attr, applicableCount }
        })
})

function openBulkEditDrawer() {
    drawerAttributes.value.forEach(attr => {
        bulkEditChecked[attr.id] = false
        bulkEditValues[attr.id]  = null
    })
    bulkEditOpen.value = true
}

function saveBulkEdit() {
    const attributes = drawerAttributes.value
        .filter(attr => bulkEditChecked[attr.id] && bulkEditValues[attr.id])
        .map(attr => ({
            product_attribute_id:       attr.id,
            product_attribute_value_id: bulkEditValues[attr.id]?.id ?? bulkEditValues[attr.id],
        }))

    if (!attributes.length) return

    router.post('/products/bulk-update-attributes', {
        product_ids: selectedIds.value,
        attributes,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            bulkEditOpen.value = false
            selectedIds.value  = []
        },
    })
}
```

- [ ] **Step 3: Verify the action bar appears and disappears**

With `npm run dev` open, select one or more products. The dark action bar should slide up from the bottom. Clicking "Deselecteer alles" should clear the selection and the bar should slide away.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Products/IndexPage.vue
git commit -m "feat(products): add sticky action bar for bulk selection"
```

---

### Task 7: Add bulk edit drawer to `IndexPage.vue`

**Files:**
- Modify: `resources/js/Pages/Products/IndexPage.vue`

- [ ] **Step 1: Add the bulk edit `DrawerComponent` to the template**

Add after the existing "Nieuw product" `<DrawerComponent>` (after line 286, before `</template>`), and before the `<Teleport>` block added in Task 6:

```html
<DrawerComponent v-model="bulkEditOpen"
    title="Kenmerken bewerken"
    :subtitle="`${selectedIds.length} producten geselecteerd`">
    <div class="divide-y divide-gray-100 dark:divide-slate-700">
        <div class="px-4 sm:px-6 py-4">
            <p class="text-sm text-gray-500 dark:text-slate-400 bg-gray-50 dark:bg-slate-900/40 rounded-md px-3 py-2 border-l-2 border-gray-200 dark:border-slate-600">
                Vink de kenmerken aan die je wilt toepassen. Niet-aangevinkte kenmerken worden niet gewijzigd.
            </p>
        </div>
        <div v-for="attr in drawerAttributes" :key="attr.id"
            class="flex items-start gap-3 px-4 sm:px-6 py-4">
            <AnimatedCheckbox
                v-model="bulkEditChecked[attr.id]"
                check-color="#081020"
                class="mt-0.5 flex-shrink-0"
            />
            <div class="flex-1 min-w-0">
                <div class="flex items-center flex-wrap gap-2 mb-2">
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ attr.name }}</span>
                    <span v-if="attr.applicableCount < selectedIds.length"
                        class="inline-flex items-center gap-1 text-xs text-amber-700 bg-amber-50 dark:bg-amber-900/20 dark:text-amber-400 px-2 py-0.5 rounded">
                        ⚠ Geldt voor {{ attr.applicableCount }} van {{ selectedIds.length }} producten
                    </span>
                </div>
                <ComboBox
                    :options="attr.values.map(v => ({ id: v.id, name: v.value }))"
                    v-model="bulkEditValues[attr.id]"
                    :placeholder="`Selecteer ${attr.name.toLowerCase()}`"
                    :disabled="!bulkEditChecked[attr.id]"
                />
            </div>
        </div>
        <div v-if="!drawerAttributes.length" class="px-4 sm:px-6 py-8 text-center text-sm text-gray-400">
            Geen kenmerken beschikbaar voor de geselecteerde producten.
        </div>
    </div>
    <template #footer>
        <div class="flex justify-end gap-2">
            <button type="button" @click="bulkEditOpen = false"
                class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                Annuleren
            </button>
            <button type="button" @click="saveBulkEdit"
                class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90">
                Opslaan
            </button>
        </div>
    </template>
</DrawerComponent>
```

- [ ] **Step 2: End-to-end smoke test**

With `npm run dev` and the dev server running (`composer run dev`):

1. Open `/products`.
2. Select 2–3 products that have different product types.
3. Click "Kenmerken bewerken" in the action bar — the drawer opens.
4. Confirm that attributes for all selected types appear.
5. Confirm attributes that don't apply to all selected products show the amber warning.
6. Check an attribute's checkbox — its ComboBox should become enabled.
7. Select a value in the enabled ComboBox.
8. Click "Opslaan".
9. Confirm the drawer closes, the selection clears, and a success flash appears.
10. Open one of the affected products' detail pages and verify the attribute value was updated.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Products/IndexPage.vue
git commit -m "feat(products): add bulk edit drawer for product attributes"
```
