# Product Relations & Asset Relations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add typed product-to-product relationships (Productable morph), auto-create child assets with serials on asset creation, link child assets via AssetRelation (both automatically and manually with product-type hierarchy restrictions), auto-create combined ServiceJobs for related assets (de-duplicated), and group related service jobs in UI and PDF reports.

**Architecture:** A `ProductRelation` model stores named relationship types (e.g. "Onderdeel", "Vereist onderdeel"). A `Productable` morph table links a parent Product to child Product entities with quantity, is_required, and a ProductRelation type. An `AssetRelation` table records parent/child asset links (created automatically or manually); manual linking is restricted to assets whose product type is a child of the parent asset's product type in the ProductType hierarchy. A `parent_service_job_id` column on `service_jobs` groups related jobs so the UI and PDF can render them together. When auto-creating child ServiceJobs, existing jobs for the same asset in the same ServiceOrder are reused rather than duplicated.

**Tech Stack:** Laravel 11, Inertia.js, Vue 3 (Composition API), Tailwind CSS, existing EditableGridComponent / CreateRecordForm / ComboBox UI patterns.

---

## Codebase Context

### Existing patterns to follow
- **Navigation children:** `MainLayout.vue` line 331 – `navigation` ref has items with `children[]` arrays; see Producten block.
- **Simple CRUD index pages:** `ServiceCheckGroups/IndexPage.vue` – uses `IndexHeaderComponent`, `CreateRecordForm`, `EditableGridComponent`, `PaginationComponent`.
- **Morph table pattern:** `producttypeables` migration has `product_type_id` (non-morph FK) + `producttypeable_id` + `producttypeable_type`; mirrored in `ProductType::morphedByMany` / `ServiceCheck::morphToMany`.
- **ServiceJob auto-check-creation:** `ServiceJob::booted()` queries `asset.product.productType.serviceChecks` and creates `ServiceCheckInstance` per check – this runs for every `ServiceJob::create()` call.
- **Asset store:** `AssetController::store()` creates one Asset and redirects back.
- **Product show:** `ProductController::show()` passes `product` with eager-loaded relations to `Products/ShowPage.vue`.

### Key file locations
| File | Purpose |
|---|---|
| `app/Models/ServiceJob.php` | booted hook auto-creates check instances |
| `app/Http/Controllers/ServiceJobController.php` | store() needs child-asset job creation |
| `app/Http/Controllers/AssetController.php` | store() needs child-asset creation |
| `app/Http/Controllers/ProductController.php` | show() needs productable data |
| `resources/js/Layouts/MainLayout.vue` line 334-344 | navigation Producten children |
| `resources/js/Pages/Products/ShowPage.vue` | add related-products section |
| `resources/js/Pages/Assets/ShowPage.vue` | add related-assets section |
| `resources/js/Components/AddAssetForm.vue` | add child serial inputs |

---

## File Map

### New — Migrations
| File | Creates |
|---|---|
| `database/migrations/2026_05_02_200000_create_product_relations_table.php` | `product_relations` |
| `database/migrations/2026_05_02_200001_create_productables_table.php` | `productables` |
| `database/migrations/2026_05_02_200002_create_asset_relations_table.php` | `asset_relations` |

### New — Models
| File | Responsibility |
|---|---|
| `app/Models/ProductRelation.php` | Named relationship types (Onderdeel, Vereist onderdeel…) |
| `app/Models/Productable.php` | Morph pivot: parent Product → child Product, with type/qty/is_required |
| `app/Models/AssetRelation.php` | Links parent Asset → child Asset, traces back to Productable |

### Modified — Models
| File | Change |
|---|---|
| `app/Models/Product.php` | Add `childProducts()` (morphedByMany), `parentProducts()` (morphToMany) |
| `app/Models/Asset.php` | Add `childAssets()` (hasManyThrough), `parentAssets()` (hasManyThrough), `childAssetRelations()`, `parentAssetRelations()` |

### New — Controllers
| File | Routes served |
|---|---|
| `app/Http/Controllers/ProductRelationController.php` | `productrelations` resource (index, store, update, destroy) |
| `app/Http/Controllers/ProductableController.php` | `productables` resource (store, update, destroy) |
| `app/Http/Controllers/AssetRelationController.php` | `assetrelations` resource (store, destroy) — manual asset linking with type-hierarchy validation |

### New — Migrations (additional)
| File | Creates |
|---|---|
| `database/migrations/2026_05_02_200003_add_parent_service_job_id_to_service_jobs_table.php` | `parent_service_job_id` nullable FK on `service_jobs` |

### Modified — Controllers
| File | Change |
|---|---|
| `app/Http/Controllers/ProductController.php` | `show()`: add childProducts, productRelations, eligible child products |
| `app/Http/Controllers/AssetController.php` | `store()`: create child assets + AssetRelations for is_required productables; `show()`: load child/parent asset relations + eligible assets for manual linking |
| `app/Http/Controllers/ServiceJobController.php` | `store()`: after creating parent job, create child ServiceJobs for all child assets (skip duplicates, set parent_service_job_id); `show()` + PDF: group by parent/child |

### Modified — Models (additional)
| File | Change |
|---|---|
| `app/Models/ServiceJob.php` | Add `childJobs()`, `parentJob()` relationships |

### New — Form Requests
| File | Validates |
|---|---|
| `app/Http/Requests/ProductRelationStoreUpdateRequest.php` | name required string |
| `app/Http/Requests/ProductableStoreRequest.php` | product_id, child_product_id, product_relation_id, quantity, is_required |
| `app/Http/Requests/ProductableUpdateRequest.php` | product_relation_id, quantity, is_required |

### Modified — Routes
`routes/web.php` — add `productrelations` resource and `productables` store/update/destroy.

### New — Vue Pages
| File | Purpose |
|---|---|
| `resources/js/Pages/ProductRelations/IndexPage.vue` | CRUD for relation type names |

### Modified — Vue
| File | Change |
|---|---|
| `resources/js/Layouts/MainLayout.vue` | Add "Relaties" child to Producten nav item |
| `resources/js/Pages/Products/ShowPage.vue` | Add "Gerelateerde producten" section |
| `resources/js/Pages/Assets/ShowPage.vue` | Add "Gerelateerde machines" section |
| `resources/js/Components/AddAssetForm.vue` | Add child serial number inputs when product has required productables |

---

## Task 1: ProductRelation type model (menu item)

**Files:**
- Create: `database/migrations/2026_05_02_200000_create_product_relations_table.php`
- Create: `app/Models/ProductRelation.php`
- Create: `app/Http/Controllers/ProductRelationController.php`
- Create: `app/Http/Requests/ProductRelationStoreUpdateRequest.php`
- Create: `resources/js/Pages/ProductRelations/IndexPage.vue`
- Modify: `routes/web.php`
- Modify: `resources/js/Layouts/MainLayout.vue`

- [ ] **Step 1.1: Create the migration**

```php
// database/migrations/2026_05_02_200000_create_product_relations_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_relations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_relations');
    }
};
```

- [ ] **Step 1.2: Run migration**

```bash
cd /home/guido/nvme0n1p1/code/lavoro && php artisan migrate
```
Expected: `product_relations` table created.

- [ ] **Step 1.3: Create the model**

```php
// app/Models/ProductRelation.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductRelation extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function productables()
    {
        return $this->hasMany(Productable::class);
    }
}
```

- [ ] **Step 1.4: Create the form request**

```php
// app/Http/Requests/ProductRelationStoreUpdateRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRelationStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 1.5: Create the controller**

```php
// app/Http/Controllers/ProductRelationController.php
<?php

namespace App\Http\Controllers;

use App\Models\ProductRelation;
use App\Http\Requests\ProductRelationStoreUpdateRequest;
use Illuminate\Http\Request;

class ProductRelationController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $query  = ProductRelation::query();

        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        return inertia('ProductRelations/IndexPage', [
            'relations' => $query->orderBy('name')->paginate(20),
            'search'    => $search,
        ]);
    }

    public function store(ProductRelationStoreUpdateRequest $request)
    {
        ProductRelation::create($request->validated());

        return redirect()->back()->with('success', 'Relatietype aangemaakt.');
    }

    public function update(ProductRelationStoreUpdateRequest $request, ProductRelation $productrelation)
    {
        $productrelation->update($request->validated());

        return redirect()->back()->with('success', 'Relatietype bijgewerkt.');
    }

    public function destroy(ProductRelation $productrelation)
    {
        $productrelation->delete();

        return redirect()->back()->with('success', 'Relatietype verwijderd.');
    }
}
```

- [ ] **Step 1.6: Add routes in `routes/web.php`**

Add after the `products` resource line (line 46):

```php
use App\Http\Controllers\ProductRelationController;
use App\Http\Controllers\ProductableController;
// (add to existing use block at top of file)

// Inside the auth middleware group, after products:
Route::resource('productrelations', ProductRelationController::class)
    ->except(['show', 'edit', 'create']);
```

- [ ] **Step 1.7: Create the Vue index page**

```vue
<!-- resources/js/Pages/ProductRelations/IndexPage.vue -->
<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border border-gray-200 dark:border-slate-800 shadow-sm">
        <IndexHeaderComponent
            title="Relatietypes"
            subtitle="Overzicht van alle productrelatietypes"
            search-url="/productrelations"
            search-label="Zoek binnen relatietypes"
            search-placeholder="bijv. 'Onderdeel'"
            :paginator="relations"
            add-label="Voeg relatietype toe"
            @add="() => formRef?.show()"
        />
    </div>

    <div class="mb-4" v-auto-animate>
        <CreateRecordForm
            ref="formRef"
            external-trigger
            action="/productrelations"
            :fields="fields"
            add-button-label="Voeg relatietype toe"
            submit-label="Opslaan"
        />
    </div>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent
            :headers="headers"
            :items="relations.data"
            url-base="productrelations"
            :has-detail-pages="false"
            @update="onCellUpdate"
        />
        <PaginationComponent
            v-if="relations.data.length"
            :paginator="relations"
            class="border-t border-gray-200 dark:border-slate-700 pt-2"
        />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen relatietypes gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

defineProps({
    relations: { type: Object, required: true },
    search:    { type: String, default: '' },
})

const formRef = ref(null)

const fields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 70 },
]

function onCellUpdate({ item }) {
    router.patch(`/productrelations/${item.id}`, { name: item.name }, {
        preserveScroll: true,
        preserveState: true,
    })
}
</script>
```

- [ ] **Step 1.8: Add "Relaties" to the Producten submenu in `MainLayout.vue`**

Find the `children` array inside the Producten nav item (around line 340) and add the new child:

```js
// In the children array of the Producten nav item (after the 'Merken' entry):
{ name: 'Relaties', href: '/productrelations', icon: LinkIcon, current: false, requiresPermission: 'productrelation.read' },
```

Also add `LinkIcon` to the import from `@heroicons/vue/24/outline` at the top of the script section.

- [ ] **Step 1.9: Verify in browser**

Navigate to `/productrelations`. Create a few types: "Onderdeel", "Vereist onderdeel", "Optioneel onderdeel". Confirm they appear in the list and can be edited/deleted.

- [ ] **Step 1.10: Commit**

```bash
git add database/migrations/2026_05_02_200000_create_product_relations_table.php \
        app/Models/ProductRelation.php \
        app/Http/Controllers/ProductRelationController.php \
        app/Http/Requests/ProductRelationStoreUpdateRequest.php \
        resources/js/Pages/ProductRelations/IndexPage.vue \
        routes/web.php \
        resources/js/Layouts/MainLayout.vue
git commit -m "feat(ProductRelation): add relation type model, CRUD page and nav submenu"
```

---

## Task 2: Productable morph table + Product ShowPage UI

**Files:**
- Create: `database/migrations/2026_05_02_200001_create_productables_table.php`
- Create: `app/Models/Productable.php`
- Create: `app/Http/Controllers/ProductableController.php`
- Create: `app/Http/Requests/ProductableStoreRequest.php`
- Create: `app/Http/Requests/ProductableUpdateRequest.php`
- Modify: `app/Models/Product.php`
- Modify: `app/Http/Controllers/ProductController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Products/ShowPage.vue`

- [ ] **Step 2.1: Create the productables migration**

```php
// database/migrations/2026_05_02_200001_create_productables_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('productable_type');
            $table->unsignedBigInteger('productable_id');
            $table->foreignId('product_relation_id')
                ->nullable()
                ->nullOnDelete()
                ->constrained('product_relations');
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->index(['productable_type', 'productable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productables');
    }
};
```

- [ ] **Step 2.2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 2.3: Create the Productable model**

```php
// app/Models/Productable.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class Productable extends MorphPivot
{
    use HasFactory;

    protected $table = 'productables';

    protected $fillable = [
        'product_id',
        'productable_type',
        'productable_id',
        'product_relation_id',
        'quantity',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'quantity'    => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productRelation()
    {
        return $this->belongsTo(ProductRelation::class);
    }

    public function childProduct()
    {
        return $this->belongsTo(Product::class, 'productable_id');
    }
}
```

> **Note:** Extending `MorphPivot` (not `Model`) allows this to be used as a custom pivot class in the `morphedByMany`/`morphToMany` relationships via `->using(Productable::class)`.

- [ ] **Step 2.4: Add relationships to `Product` model**

```php
// In app/Models/Product.php, add these methods:

public function childProducts()
{
    return $this->morphedByMany(Product::class, 'productable')
        ->withPivot(['id', 'product_relation_id', 'quantity', 'is_required'])
        ->using(Productable::class)
        ->withTimestamps();
}

public function parentProducts()
{
    return $this->morphToMany(Product::class, 'productable')
        ->withPivot(['id', 'product_relation_id', 'quantity', 'is_required'])
        ->using(Productable::class)
        ->withTimestamps();
}

// Also add to the existing relationships:
public function productables()
{
    return $this->hasMany(Productable::class);
}
```

- [ ] **Step 2.5: Create the form requests**

```php
// app/Http/Requests/ProductableStoreRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductableStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'product_id'          => ['required', 'integer', 'exists:products,id'],
            'child_product_id'    => ['required', 'integer', 'exists:products,id', 'different:product_id'],
            'product_relation_id' => ['nullable', 'integer', 'exists:product_relations,id'],
            'quantity'            => ['required', 'integer', 'min:1'],
            'is_required'         => ['boolean'],
        ];
    }
}
```

```php
// app/Http/Requests/ProductableUpdateRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductableUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'product_relation_id' => ['nullable', 'integer', 'exists:product_relations,id'],
            'quantity'            => ['required', 'integer', 'min:1'],
            'is_required'         => ['boolean'],
        ];
    }
}
```

- [ ] **Step 2.6: Create the ProductableController**

```php
// app/Http/Controllers/ProductableController.php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Productable;
use App\Http\Requests\ProductableStoreRequest;
use App\Http\Requests\ProductableUpdateRequest;

class ProductableController extends Controller
{
    public function store(ProductableStoreRequest $request)
    {
        $v = $request->validated();

        Productable::create([
            'product_id'          => $v['product_id'],
            'productable_type'    => Product::class,
            'productable_id'      => $v['child_product_id'],
            'product_relation_id' => $v['product_relation_id'] ?? null,
            'quantity'            => $v['quantity'],
            'is_required'         => $v['is_required'] ?? false,
        ]);

        return redirect()->back()->with('success', 'Gerelateerd product toegevoegd.');
    }

    public function update(ProductableUpdateRequest $request, Productable $productable)
    {
        $productable->update($request->validated());

        return redirect()->back()->with('success', 'Productrelatie bijgewerkt.');
    }

    public function destroy(Productable $productable)
    {
        $productable->delete();

        return redirect()->back()->with('success', 'Productrelatie verwijderd.');
    }
}
```

- [ ] **Step 2.7: Add productables route to `routes/web.php`**

```php
// Add to the imports at top:
use App\Http\Controllers\ProductableController;

// Add inside auth middleware group:
Route::resource('productables', ProductableController::class)
    ->only(['store', 'update', 'destroy']);
```

- [ ] **Step 2.8: Update `ProductController::show()` to pass relation data**

Replace the `show()` method in `app/Http/Controllers/ProductController.php`:

```php
public function show(ProductReadRequest $request, Product $product)
{
    $product->load([
        'brand',
        'productType.children',
        'images',
        'documents',
        'assets.customer',
        'assets.openTickets',
        'assets.pendingTickets',
        'assets.closedTickets',
        'assets.product.productType',
        'assets.product.brand',
        'customFields',
        'childProducts.brand',
        'childProducts.productType',
        'childProducts.productable', // loads the pivot as a relation via Productable model
    ]);

    // IDs of child product types (children of this product's type)
    $childTypeIds = $product->productType
        ? $product->productType->children()->pluck('id')->all()
        : [];

    // Products eligible to be a child (belong to a child product type)
    $eligibleChildProducts = [];
    if (!empty($childTypeIds)) {
        $eligibleChildProducts = Product::query()
            ->whereIn('product_type_id', $childTypeIds)
            ->with(['brand', 'productType'])
            ->orderBy('model')
            ->get()
            ->map(fn($p) => [
                'id'   => $p->id,
                'name' => $p->brand->name . ' ' . $p->model . ' (' . $p->productType->name . ')',
            ])
            ->values()
            ->all();
    }

    // Build child products list with pivot data
    $childProductsWithPivot = $product->childProducts->map(function ($child) {
        $pivot = $child->pivot;
        return [
            'productable_id'      => $pivot->id,
            'product_id'          => $child->id,
            'name'                => $child->brand->name . ' ' . $child->model . ' (' . $child->productType->name . ')',
            'product_relation_id' => $pivot->product_relation_id,
            'quantity'            => $pivot->quantity,
            'is_required'         => $pivot->is_required,
        ];
    })->values()->all();

    return inertia('Products/ShowPage', [
        'product'               => $product,
        'allCustomers'          => Customer::orderBy('name', 'ASC')
            ->get(['id', 'name'])
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->name]),
        'customFields'          => $product->allCustomFieldsWithValues(),
        'productRelations'      => \App\Models\ProductRelation::orderBy('name')->get(['id', 'name']),
        'eligibleChildProducts' => $eligibleChildProducts,
        'childProducts'         => $childProductsWithPivot,
    ]);
}
```

- [ ] **Step 2.9: Add "Gerelateerde producten" section to `Products/ShowPage.vue`**

Add these new props to the `defineProps` block:

```js
productRelations:      { type: Array, default: () => [] },
eligibleChildProducts: { type: Array, default: () => [] },
childProducts:         { type: Array, default: () => [] },
```

Add the `relatedProducts` reactive state and form below the existing `form` ref:

```js
import { ref, computed, watch, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { LinkIcon, TrashIcon, PlusIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/Components/UI/ComboBox.vue'

const relatedProducts = ref([...props.childProducts])
const addingRelation  = ref(false)
const newRelation     = reactive({
    child_product_id:    null,
    product_relation_id: null,
    quantity:            1,
    is_required:         false,
})

function submitNewRelation() {
    router.post('/productables', {
        product_id:          props.product.id,
        child_product_id:    newRelation.child_product_id,
        product_relation_id: newRelation.product_relation_id,
        quantity:            newRelation.quantity,
        is_required:         newRelation.is_required,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingRelation.value = false
            newRelation.child_product_id    = null
            newRelation.product_relation_id = null
            newRelation.quantity            = 1
            newRelation.is_required         = false
        },
    })
}

function removeRelation(productableId) {
    router.delete(`/productables/${productableId}`, { preserveScroll: true })
}
```

Add this template section inside `<template #main>` in the `<BoxComponent>`, after the existing assets section:

```vue
<!-- Gerelateerde producten -->
<div v-if="hasPermission('productable.read')" class="mt-6">
    <div class="flex items-center justify-between py-3 border-t border-gray-200 mt-2">
        <div class="flex items-center">
            <LinkIcon class="size-5 text-gray-500 mr-2" />
            <h3 class="text-sm font-medium">Gerelateerde producten</h3>
        </div>
        <button
            v-if="hasPermission('productable.create') && eligibleChildProducts.length"
            @click="addingRelation = !addingRelation"
            class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800"
        >
            <PlusIcon class="size-4" /> Toevoegen
        </button>
    </div>

    <!-- Add form -->
    <div v-if="addingRelation" class="mb-3 p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2">
        <div class="flex gap-2 flex-wrap">
            <div class="flex-1 min-w-40">
                <label class="block text-xs text-gray-500 mb-1">Gerelateerd product</label>
                <ComboBox :options="eligibleChildProducts" v-model="newRelation.child_product_id" placeholder="Selecteer product" />
            </div>
            <div class="flex-1 min-w-32">
                <label class="block text-xs text-gray-500 mb-1">Relatietype</label>
                <ComboBox :options="productRelations" v-model="newRelation.product_relation_id" placeholder="Selecteer type" />
            </div>
            <div class="w-20">
                <label class="block text-xs text-gray-500 mb-1">Aantal</label>
                <input type="number" min="1" v-model.number="newRelation.quantity"
                    class="w-full rounded border-gray-300 text-sm p-1 border" />
            </div>
            <div class="flex items-end pb-1">
                <label class="flex items-center gap-1 text-xs text-gray-600 cursor-pointer">
                    <input type="checkbox" v-model="newRelation.is_required" class="rounded" />
                    Verplicht
                </label>
            </div>
        </div>
        <div class="flex gap-2">
            <button @click="submitNewRelation"
                class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                Opslaan
            </button>
            <button @click="addingRelation = false"
                class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                Annuleren
            </button>
        </div>
    </div>

    <!-- Existing relations -->
    <div v-if="childProducts.length === 0 && !addingRelation" class="text-sm text-gray-400 italic">
        Geen gerelateerde producten.
    </div>
    <table v-if="childProducts.length" class="w-full text-sm">
        <thead>
            <tr class="text-xs text-gray-400 border-b">
                <th class="text-left py-1 font-medium">Product</th>
                <th class="text-left py-1 font-medium">Type</th>
                <th class="text-center py-1 font-medium">Aantal</th>
                <th class="text-center py-1 font-medium">Verplicht</th>
                <th class="py-1"></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="rel in childProducts" :key="rel.productable_id" class="border-b border-gray-100">
                <td class="py-1.5">{{ rel.name }}</td>
                <td class="py-1.5 text-gray-500">
                    {{ productRelations.find(r => r.id === rel.product_relation_id)?.name ?? '—' }}
                </td>
                <td class="py-1.5 text-center">{{ rel.quantity }}</td>
                <td class="py-1.5 text-center">
                    <span v-if="rel.is_required" class="text-green-600 text-xs">✓</span>
                    <span v-else class="text-gray-300 text-xs">—</span>
                </td>
                <td class="py-1.5 text-right">
                    <button
                        v-if="hasPermission('productable.delete')"
                        @click="removeRelation(rel.productable_id)"
                        class="text-red-400 hover:text-red-600"
                    >
                        <TrashIcon class="size-4" />
                    </button>
                </td>
            </tr>
        </tbody>
    </table>
    <p v-if="eligibleChildProducts.length === 0 && !childProducts.length"
        class="text-xs text-gray-400 mt-1">
        Dit producttype heeft geen subtypen, dus er kunnen geen gerelateerde producten worden toegevoegd.
    </p>
</div>
```

- [ ] **Step 2.10: Verify in browser**

Open a product whose product type has children (e.g. an "Airco" product type). Confirm the related products section appears, a child product can be added with a relation type, quantity and is_required flag, and the row appears in the table after saving.

- [ ] **Step 2.11: Commit**

```bash
git add database/migrations/2026_05_02_200001_create_productables_table.php \
        app/Models/Productable.php \
        app/Http/Controllers/ProductableController.php \
        app/Http/Requests/ProductableStoreRequest.php \
        app/Http/Requests/ProductableUpdateRequest.php \
        app/Models/Product.php \
        app/Http/Controllers/ProductController.php \
        routes/web.php \
        resources/js/Pages/Products/ShowPage.vue
git commit -m "feat(Productable): morph pivot for product relations, UI on product show page"
```

---

## Task 3: AssetRelation + auto-creation of child assets with serial prompts

**Files:**
- Create: `database/migrations/2026_05_02_200002_create_asset_relations_table.php`
- Create: `app/Models/AssetRelation.php`
- Modify: `app/Models/Asset.php`
- Modify: `app/Http/Controllers/AssetController.php`
- Modify: `app/Http/Requests/AssetStoreRequest.php`
- Modify: `resources/js/Components/AddAssetForm.vue`
- Modify: `resources/js/Pages/Assets/ShowPage.vue`
- Modify: `resources/js/Pages/Products/ShowPage.vue` (pass productables to AddAssetForm)
- Modify: `resources/js/Pages/Assets/IndexPage.vue` (pass productables to AddAssetForm)
- Modify: `app/Http/Controllers/AssetController.php` show() and index()

- [ ] **Step 3.1: Create the asset_relations migration**

```php
// database/migrations/2026_05_02_200002_create_asset_relations_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_asset_id')
                ->constrained('assets')
                ->cascadeOnDelete();
            $table->foreignId('child_asset_id')
                ->constrained('assets')
                ->cascadeOnDelete();
            $table->foreignId('productable_id')
                ->nullable()
                ->nullOnDelete()
                ->constrained('productables');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_relations');
    }
};
```

- [ ] **Step 3.2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 3.3: Create the AssetRelation model**

```php
// app/Models/AssetRelation.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetRelation extends Model
{
    protected $fillable = [
        'parent_asset_id',
        'child_asset_id',
        'productable_id',
    ];

    public function parentAsset()
    {
        return $this->belongsTo(Asset::class, 'parent_asset_id');
    }

    public function childAsset()
    {
        return $this->belongsTo(Asset::class, 'child_asset_id');
    }

    public function productable()
    {
        return $this->belongsTo(Productable::class);
    }
}
```

- [ ] **Step 3.4: Add relationships to `Asset` model**

```php
// Add to app/Models/Asset.php:

public function childAssetRelations()
{
    return $this->hasMany(AssetRelation::class, 'parent_asset_id');
}

public function parentAssetRelations()
{
    return $this->hasMany(AssetRelation::class, 'child_asset_id');
}

public function childAssets()
{
    return $this->hasManyThrough(
        Asset::class,
        AssetRelation::class,
        'parent_asset_id',
        'id',
        'id',
        'child_asset_id'
    );
}

public function parentAssets()
{
    return $this->hasManyThrough(
        Asset::class,
        AssetRelation::class,
        'child_asset_id',
        'id',
        'id',
        'parent_asset_id'
    );
}
```

- [ ] **Step 3.5: Update `AssetStoreRequest` to allow child_assets**

Find `app/Http/Requests/AssetStoreRequest.php` and add to `rules()`:

```php
'child_assets'                => ['nullable', 'array'],
'child_assets.*.productable_id' => ['required_with:child_assets', 'integer', 'exists:productables,id'],
'child_assets.*.serial_number'  => ['required_with:child_assets', 'string', 'max:255'],
```

- [ ] **Step 3.6: Update `AssetController::store()` to create child assets**

Replace `store()` in `app/Http/Controllers/AssetController.php`:

```php
use App\Models\AssetRelation;
use App\Models\Productable;
// (add to top of file)

public function store(AssetStoreRequest $request)
{
    $validated = $request->validated();

    $asset = Asset::create([
        'product_id'        => $validated['product_id'],
        'customer_id'       => $validated['customer_id'],
        'serial_number'     => $validated['serial_number'] ?? null,
        'next_service_date' => $validated['next_service_date'] ?? null,
        'status'            => ($validated['is_active'] ?? true) ? 'Actief' : 'Niet actief',
    ]);

    // Create child assets for required productables
    foreach ($validated['child_assets'] ?? [] as $childData) {
        $productable = Productable::find($childData['productable_id']);
        if (!$productable || !$productable->is_required) {
            continue;
        }

        $childAsset = Asset::create([
            'product_id'        => $productable->productable_id,
            'customer_id'       => $validated['customer_id'],
            'serial_number'     => $childData['serial_number'],
            'next_service_date' => $validated['next_service_date'] ?? null,
            'status'            => ($validated['is_active'] ?? true) ? 'Actief' : 'Niet actief',
        ]);

        AssetRelation::create([
            'parent_asset_id' => $asset->id,
            'child_asset_id'  => $childAsset->id,
            'productable_id'  => $productable->id,
        ]);
    }

    $created = $asset->load([
        'product.brand',
        'product.images',
        'product.productType',
        'customer',
    ]);

    return redirect()->back()
        ->with('success', 'Machine toegevoegd.')
        ->with('extra', $created);
}
```

- [ ] **Step 3.7: Update `AssetController::show()` to load relations**

In `show()`, update the load call to include:

```php
'asset' => $asset->load([
    'images',
    'tickets',
    'product.brand',
    'product.images',
    'product.productType',
    'product.productables.childProduct.brand',
    'product.productables.childProduct.productType',
    'product.productables.productRelation',
    'customer',
    'servicejobs',
    'customFields',
    'childAssetRelations.childAsset.product.brand',
    'childAssetRelations.childAsset.product.productType',
    'childAssetRelations.productable.productRelation',
    'parentAssetRelations.parentAsset.product.brand',
    'parentAssetRelations.parentAsset.product.productType',
    'parentAssetRelations.productable.productRelation',
]),
```

- [ ] **Step 3.8: Pass required productables to the AddAssetForm from `ProductController::show()`**

In the existing `show()` from Task 2 Step 2.8, also pass:

```php
// Add to the inertia return array:
'requiredProductablesByProduct' => $this->buildRequiredProductablesMap(),
```

Add a private helper method to `ProductController`:

```php
private function buildRequiredProductablesMap(): array
{
    // For all products, build a map: product_id => [required productable data]
    // This is used by AddAssetForm to show serial prompts per product
    return \App\Models\Productable::query()
        ->where('is_required', true)
        ->where('productable_type', \App\Models\Product::class)
        ->with(['childProduct.brand', 'childProduct.productType', 'productRelation'])
        ->get()
        ->groupBy('product_id')
        ->map(fn($items) => $items->map(fn($p) => [
            'productable_id'   => $p->id,
            'child_product_id' => $p->productable_id,
            'name'             => $p->childProduct->brand->name
                . ' ' . $p->childProduct->model
                . ' (' . $p->childProduct->productType->name . ')',
            'quantity'         => $p->quantity,
            'relation_name'    => $p->productRelation?->name ?? 'Onderdeel',
        ])->values()->all())
        ->all();
}
```

Also pass this map in `AssetController::index()` for the `AddAssetForm` used on the Assets index page:

```php
// In AssetController::index(), add to the inertia return:
'requiredProductablesByProduct' => app(ProductController::class)->buildRequiredProductablesMap(),
```

> **Note:** Extract `buildRequiredProductablesMap()` to a shared service class `app/Services/ProductableService.php` if you prefer not to call `ProductController` from `AssetController`. For simplicity, a static helper works:

```php
// app/Services/ProductableService.php
<?php

namespace App\Services;

use App\Models\Productable;
use App\Models\Product;

class ProductableService
{
    public static function requiredProductablesMap(): array
    {
        return Productable::query()
            ->where('is_required', true)
            ->where('productable_type', Product::class)
            ->with(['childProduct.brand', 'childProduct.productType', 'productRelation'])
            ->get()
            ->groupBy('product_id')
            ->map(fn($items) => $items->map(fn($p) => [
                'productable_id'   => $p->id,
                'child_product_id' => $p->productable_id,
                'name'             => $p->childProduct->brand->name
                    . ' ' . $p->childProduct->model
                    . ' (' . $p->childProduct->productType->name . ')',
                'quantity'         => $p->quantity,
                'relation_name'    => $p->productRelation?->name ?? 'Onderdeel',
            ])->values()->all())
            ->all();
    }
}
```

Then in both controllers call `ProductableService::requiredProductablesMap()`.

- [ ] **Step 3.9: Update `AddAssetForm.vue` to show child serial inputs when product has required parts**

`AddAssetForm.vue` accepts `productId` and `allCustomers`. Extend it to also accept `requiredProductablesByProduct`:

```vue
<!-- resources/js/Components/AddAssetForm.vue -->
<!-- Add to defineProps: -->
const props = defineProps({
    allCustomers:                  { type: Array,  required: true },
    productId:                     { type: Number, default: null },
    requiredProductablesByProduct: { type: Object, default: () => ({}) },
})

<!-- Add computed to derive required parts for the current product_id: -->
import { computed } from 'vue'

const requiredParts = computed(() => {
    const pid = form.product_id ?? props.productId
    if (!pid) return []
    return props.requiredProductablesByProduct[pid] ?? []
})
```

Add reactive child_assets tracking to the form:

```js
// Extend the useForm object to include child_assets:
const form = useForm({
    // ... existing fields ...
    child_assets: [],
})

// Watch product_id to reset child_assets and rebuild from requiredParts:
watch(() => form.product_id, () => {
    form.child_assets = requiredParts.value.map(part => ({
        productable_id: part.productable_id,
        serial_number: '',
    }))
})
```

Add the child serial fields in the template, below the main serial number input:

```vue
<!-- After the serial_number field, inside the form: -->
<template v-if="requiredParts.length">
    <div class="mt-3 border-t pt-3">
        <p class="text-xs font-medium text-gray-500 mb-2">
            Serienummers vereiste onderdelen
        </p>
        <div v-for="(part, i) in requiredParts" :key="part.productable_id" class="mb-2">
            <label class="block text-xs text-gray-500 mb-1">
                {{ part.relation_name }}: {{ part.name }}
                <span v-if="part.quantity > 1">(×{{ part.quantity }})</span>
            </label>
            <TextInput
                v-model="form.child_assets[i].serial_number"
                :placeholder="'Serienummer ' + part.name"
                class="w-full"
            />
        </div>
    </div>
</template>
```

- [ ] **Step 3.10: Add "Gerelateerde machines" section to `Assets/ShowPage.vue`**

Add to the `defineProps`:
```js
// Asset already has childAssetRelations and parentAssetRelations loaded from the controller.
// No extra prop needed — they're inside the asset prop.
```

Add a "Gerelateerde machines" section in the main `BoxComponent`, after the tickets section:

```vue
<div v-if="asset.child_asset_relations?.length || asset.parent_asset_relations?.length" class="mt-6">
    <div class="flex items-center py-3 border-t border-gray-200">
        <LinkIcon class="size-5 text-gray-500 mr-2" />
        <h3 class="text-sm font-medium">Gerelateerde machines</h3>
    </div>

    <!-- Child assets -->
    <div v-if="asset.child_asset_relations?.length">
        <p class="text-xs text-gray-400 mb-1">Onderdelen</p>
        <div v-for="rel in asset.child_asset_relations" :key="rel.id"
            class="flex items-center justify-between py-1 border-b border-gray-50">
            <div>
                <Link :href="`/assets/${rel.child_asset.id}`" class="text-blue-600 underline text-sm">
                    {{ rel.child_asset.product.brand.name }} {{ rel.child_asset.product.model }}
                </Link>
                <span class="text-xs text-gray-400 ml-2">{{ rel.child_asset.serial_number }}</span>
            </div>
            <span class="text-xs text-gray-400">{{ rel.productable?.product_relation?.name ?? '—' }}</span>
        </div>
    </div>

    <!-- Parent assets -->
    <div v-if="asset.parent_asset_relations?.length" class="mt-2">
        <p class="text-xs text-gray-400 mb-1">Onderdeel van</p>
        <div v-for="rel in asset.parent_asset_relations" :key="rel.id"
            class="flex items-center justify-between py-1 border-b border-gray-50">
            <div>
                <Link :href="`/assets/${rel.parent_asset.id}`" class="text-blue-600 underline text-sm">
                    {{ rel.parent_asset.product.brand.name }} {{ rel.parent_asset.product.model }}
                </Link>
                <span class="text-xs text-gray-400 ml-2">{{ rel.parent_asset.serial_number }}</span>
            </div>
            <span class="text-xs text-gray-400">{{ rel.productable?.product_relation?.name ?? '—' }}</span>
        </div>
    </div>
</div>
```

Also import `LinkIcon` and `Link` in the script section if not already present.

- [ ] **Step 3.11: Verify end-to-end in browser**

1. Create a product with a required child product (from Task 2).
2. On the Assets index or product show page, add a new asset for that product. Confirm the child serial number input appears.
3. Submit the form with a child serial. Confirm two assets are created.
4. Open the parent asset — confirm "Gerelateerde machines" section shows the child.
5. Open the child asset — confirm "Onderdeel van" section shows the parent.

- [ ] **Step 3.12: Commit**

```bash
git add database/migrations/2026_05_02_200002_create_asset_relations_table.php \
        app/Models/AssetRelation.php \
        app/Models/Asset.php \
        app/Services/ProductableService.php \
        app/Http/Controllers/AssetController.php \
        app/Http/Requests/AssetStoreRequest.php \
        resources/js/Components/AddAssetForm.vue \
        resources/js/Pages/Assets/ShowPage.vue \
        resources/js/Pages/Products/ShowPage.vue \
        resources/js/Pages/Assets/IndexPage.vue
git commit -m "feat(AssetRelation): auto-create child assets with serials, show related assets on asset page"
```

---

## Task 4: Combined ServiceJob creation for related assets

**Files:**
- Modify: `app/Http/Controllers/ServiceJobController.php`
- Modify: `resources/js/Pages/ServiceJob/ShowPage.vue`
- Modify: `resources/views/pdf/servicejob.blade.php`

- [ ] **Step 4.1: Update `ServiceJobController::store()` to create child ServiceJobs**

Replace the `store()` method in `app/Http/Controllers/ServiceJobController.php`:

```php
use App\Models\Asset;
// (add to top of file if not present)

public function store(ServiceJobCreateRequest $request)
{
    $job = ServiceJob::create($request->validated());

    $serviceOrder = ServiceOrder::with('customer')->find($job->service_order_id);
    if ($serviceOrder) {
        $asset = $job->asset()->with(['product.brand', 'product.productType'])->first();
        if ($asset) {
            $serviceOrder->logActivity(sprintf(
                'Keuring toegevoegd: %s %s %s (serienummer %s)',
                $asset->product->productType->name ?? 'Onbekend type',
                $asset->product->brand->name ?? '',
                $asset->product->model ?? '',
                $asset->serial_number ?? '-'
            ));
        }
    }

    // Auto-create service jobs for all child assets (combined service job)
    $parentAsset = Asset::with([
        'childAssets.product.brand',
        'childAssets.product.productType',
    ])->find($job->asset_id);

    foreach ($parentAsset->childAssets as $childAsset) {
        $childJob = ServiceJob::create([
            'asset_id'         => $childAsset->id,
            'service_order_id' => $job->service_order_id,
            'outcome'          => ServiceJobOutcomes::nog_geen_uitkomst->value,
        ]);

        if ($serviceOrder) {
            $serviceOrder->logActivity(sprintf(
                'Gecombineerde keuring toegevoegd voor onderdeel: %s %s %s (serienummer %s)',
                $childAsset->product->productType->name ?? 'Onbekend type',
                $childAsset->product->brand->name ?? '',
                $childAsset->product->model ?? '',
                $childAsset->serial_number ?? '-'
            ));
        }
    }

    $childCount = $parentAsset->childAssets->count();
    $message = $childCount > 0
        ? "Keuring succesvol aangemaakt. {$childCount} gecombineerde keuring(en) aangemaakt voor gerelateerde onderdelen."
        : 'Keuring succesvol aangemaakt.';

    return redirect()->back()->with('success', $message);
}
```

- [ ] **Step 4.2: Load parent/child context in `ServiceJobController::show()`**

In `show()`, add to the load array:

```php
$servicejob->load([
    // ... existing loads ...
    'asset.parentAssetRelations.parentAsset.product.brand',
    'asset.parentAssetRelations.parentAsset.product.productType',
    'asset.childAssetRelations.childAsset.product.brand',
    'asset.childAssetRelations.childAsset.product.productType',
]);
```

Also add to the inertia return:

```php
// Find sibling service jobs in the same service order for related assets
$siblingJobs = collect();
if ($servicejob->service_order_id) {
    $relatedAssetIds = collect();

    // Child asset IDs
    $relatedAssetIds = $relatedAssetIds->merge(
        $servicejob->asset->childAssets()->pluck('assets.id')
    );
    // Parent asset IDs
    $relatedAssetIds = $relatedAssetIds->merge(
        $servicejob->asset->parentAssets()->pluck('assets.id')
    );

    if ($relatedAssetIds->isNotEmpty()) {
        $siblingJobs = ServiceJob::query()
            ->where('service_order_id', $servicejob->service_order_id)
            ->whereIn('asset_id', $relatedAssetIds)
            ->where('id', '!=', $servicejob->id)
            ->with(['asset.product.brand', 'asset.product.productType'])
            ->get()
            ->map(fn($j) => [
                'id'          => $j->id,
                'asset_label' => $j->asset->product->brand->name
                    . ' ' . $j->asset->product->model
                    . ' (' . ($j->asset->serial_number ?? '-') . ')',
                'outcome'     => $j->outcome,
            ]);
    }
}

return inertia('ServiceJob/ShowPage', [
    // ... existing data ...
    'sibling_jobs' => $siblingJobs,
]);
```

- [ ] **Step 4.3: Show sibling jobs in `ServiceJob/ShowPage.vue`**

Add prop:
```js
sibling_jobs: { type: Array, default: () => [] },
```

Add a notice near the top of the main content area (just below the job header):

```vue
<div v-if="sibling_jobs.length" class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-md">
    <p class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-1">
        Gecombineerde keuring — gerelateerde keuringen in dit werkorder:
    </p>
    <ul class="space-y-1">
        <li v-for="sj in sibling_jobs" :key="sj.id">
            <Link :href="`/servicejobs/${sj.id}`" class="text-blue-600 underline text-sm">
                {{ sj.asset_label }}
            </Link>
            <span class="text-xs text-gray-400 ml-2">{{ sj.outcome }}</span>
        </li>
    </ul>
</div>
```

- [ ] **Step 4.4: Update the service job PDF template to show sibling context**

Find `resources/views/pdf/servicejob.blade.php`. Add a section near the top of the document body (after the asset/customer header) that conditionally lists related jobs when passed:

The `generateServiceJobPdf()` method in the controller needs to pass sibling info. Update `generateServiceJobPdf()` to also compute and pass sibling jobs:

```php
// In generateServiceJobPdf(), before the Pdf::loadView call, add:
$siblingJobLabels = [];
if ($servicejob->service_order_id) {
    $relatedAssetIds = collect()
        ->merge($servicejob->asset->childAssets()->pluck('assets.id'))
        ->merge($servicejob->asset->parentAssets()->pluck('assets.id'));

    if ($relatedAssetIds->isNotEmpty()) {
        $siblingJobLabels = ServiceJob::query()
            ->where('service_order_id', $servicejob->service_order_id)
            ->whereIn('asset_id', $relatedAssetIds)
            ->where('id', '!=', $servicejob->id)
            ->with(['asset.product.brand', 'asset.product.productType'])
            ->get()
            ->map(fn($j) => $j->asset->product->brand->name
                . ' ' . $j->asset->product->model
                . ' — ' . ($j->asset->serial_number ?? '-'))
            ->all();
    }
}
```

Pass `'siblingJobLabels' => $siblingJobLabels` to the blade view, then in the blade file add:

```blade
@if(!empty($siblingJobLabels))
<div style="margin-bottom: 12px; padding: 8px; background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 4px;">
    <strong style="font-size: 10px;">Gecombineerde keuring – ook gekeurde onderdelen:</strong>
    <ul style="margin: 4px 0 0 12px; font-size: 10px;">
        @foreach($siblingJobLabels as $label)
            <li>{{ $label }}</li>
        @endforeach
    </ul>
</div>
@endif
```

- [ ] **Step 4.5: Verify combined service job flow in browser**

1. Create an asset that has a child asset (from Task 3 setup).
2. Open a ServiceOrder and add a ServiceJob for the parent asset.
3. Confirm two ServiceJobs appear in the ServiceOrder (one per asset).
4. Open the parent job — confirm the sibling-jobs notice is shown.
5. Check ServiceCheckInstances: open each job and confirm it has checks loaded from its own asset's product type.

- [ ] **Step 4.6: Commit**

```bash
git add app/Http/Controllers/ServiceJobController.php \
        resources/js/Pages/ServiceJob/ShowPage.vue \
        resources/views/pdf/servicejob.blade.php
git commit -m "feat(ServiceJob): auto-create child asset service jobs in combined job, show sibling context"
```

---

## Task 5: Manual asset linking with product-type hierarchy restrictions

**Files:**
- Create: `app/Http/Controllers/AssetRelationController.php`
- Create: `app/Http/Requests/AssetRelationStoreRequest.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/AssetController.php` (show — pass eligible assets for manual linking)
- Modify: `resources/js/Pages/Assets/ShowPage.vue` (add manual link UI in related-assets section)

- [ ] **Step 5.1: Create `AssetRelationStoreRequest`**

```php
// app/Http/Requests/AssetRelationStoreRequest.php
<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssetRelationStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'parent_asset_id'     => ['required', 'integer', 'exists:assets,id'],
            'child_asset_id'      => ['required', 'integer', 'exists:assets,id', 'different:parent_asset_id'],
            'product_relation_id' => ['nullable', 'integer', 'exists:product_relations,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) return;

                $parent = Asset::with('product.productType')->find($this->parent_asset_id);
                $child  = Asset::with('product.productType')->find($this->child_asset_id);

                $parentTypeId = $parent?->product?->productType?->id;
                $childParentTypeId = $child?->product?->productType?->parent_id;

                if ($parentTypeId === null || $childParentTypeId !== $parentTypeId) {
                    $validator->errors()->add(
                        'child_asset_id',
                        'Het producttype van de te koppelen machine is niet compatibel met dit apparaat. '
                        . 'Alleen machines waarvan het producttype een subtype is van "'
                        . ($parent?->product?->productType?->name ?? 'onbekend')
                        . '" kunnen worden gekoppeld.'
                    );
                }
            },
        ];
    }
}
```

- [ ] **Step 5.2: Create `AssetRelationController`**

```php
// app/Http/Controllers/AssetRelationController.php
<?php

namespace App\Http\Controllers;

use App\Models\AssetRelation;
use App\Http\Requests\AssetRelationStoreRequest;

class AssetRelationController extends Controller
{
    public function store(AssetRelationStoreRequest $request)
    {
        $v = $request->validated();

        // Prevent duplicate links
        $exists = AssetRelation::where('parent_asset_id', $v['parent_asset_id'])
            ->where('child_asset_id', $v['child_asset_id'])
            ->exists();

        if ($exists) {
            return redirect()->back()->with('info', 'Deze koppeling bestaat al.');
        }

        AssetRelation::create([
            'parent_asset_id'     => $v['parent_asset_id'],
            'child_asset_id'      => $v['child_asset_id'],
            'productable_id'      => null,
            'product_relation_id' => $v['product_relation_id'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Machine gekoppeld.');
    }

    public function destroy(AssetRelation $assetrelation)
    {
        $assetrelation->delete();

        return redirect()->back()->with('success', 'Koppeling verwijderd.');
    }
}
```

> **Note:** `product_relation_id` needs to be added to `AssetRelation::$fillable` and the `asset_relations` migration (add nullable `product_relation_id` FK to `product_relations`). Update the migration from Task 3 Step 3.1 accordingly:
>
> ```php
> $table->foreignId('product_relation_id')
>     ->nullable()
>     ->nullOnDelete()
>     ->constrained('product_relations');
> ```
>
> And add to `AssetRelation::$fillable`: `'product_relation_id'`.

- [ ] **Step 5.3: Add assetrelations route to `routes/web.php`**

```php
use App\Http\Controllers\AssetRelationController;

Route::resource('assetrelations', AssetRelationController::class)
    ->only(['store', 'destroy']);
```

- [ ] **Step 5.4: Update `AssetController::show()` to pass eligible assets for manual linking**

After loading the asset, compute assets eligible to be manually linked as children (their product type is a child of this asset's product type):

```php
// In AssetController::show(), add:
$currentTypeId = $asset->product?->productType?->id;

// Assets whose product type is a direct child of this asset's product type,
// excluding assets already linked as children or this asset itself.
$existingChildIds = $asset->childAssetRelations()->pluck('child_asset_id')->all();
$eligibleChildAssets = [];

if ($currentTypeId) {
    $childTypeIds = \App\Models\ProductType::query()
        ->where('parent_id', $currentTypeId)
        ->pluck('id')
        ->all();

    if (!empty($childTypeIds)) {
        $eligibleChildAssets = \App\Models\Asset::query()
            ->whereHas('product', fn($q) => $q->whereIn('product_type_id', $childTypeIds))
            ->where('customer_id', $asset->customer_id)
            ->whereNotIn('id', [...$existingChildIds, $asset->id])
            ->with(['product.brand', 'product.productType'])
            ->get()
            ->map(fn($a) => [
                'id'   => $a->id,
                'name' => $a->product->brand->name . ' ' . $a->product->model
                    . ' (' . $a->product->productType->name . ')'
                    . ' — ' . ($a->serial_number ?? 'geen serienr.'),
            ])
            ->values()
            ->all();
    }
}

// Add to inertia return:
'eligibleChildAssets' => $eligibleChildAssets,
'productRelations'    => \App\Models\ProductRelation::orderBy('name')->get(['id', 'name']),
```

- [ ] **Step 5.5: Add manual link UI in `Assets/ShowPage.vue`**

Add to `defineProps`:
```js
eligibleChildAssets: { type: Array, default: () => [] },
productRelations:    { type: Array, default: () => [] },
```

In the "Gerelateerde machines" section (added in Task 3 Step 3.10), add a manual-link form below the existing child/parent lists:

```vue
<div v-if="eligibleChildAssets.length && hasPermission('assetrelation.create')" class="mt-3">
    <div v-if="!addingManualLink">
        <button @click="addingManualLink = true"
            class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800">
            <PlusIcon class="size-4" /> Machine handmatig koppelen
        </button>
    </div>
    <div v-else class="p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2 mt-2">
        <div class="flex gap-2 flex-wrap">
            <div class="flex-1 min-w-48">
                <label class="block text-xs text-gray-500 mb-1">Machine</label>
                <ComboBox :options="eligibleChildAssets" v-model="manualLink.child_asset_id"
                    placeholder="Selecteer machine" />
            </div>
            <div class="flex-1 min-w-32">
                <label class="block text-xs text-gray-500 mb-1">Relatietype</label>
                <ComboBox :options="productRelations" v-model="manualLink.product_relation_id"
                    placeholder="Selecteer type" />
            </div>
        </div>
        <div class="flex gap-2">
            <button @click="submitManualLink"
                class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                Koppelen
            </button>
            <button @click="addingManualLink = false"
                class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                Annuleren
            </button>
        </div>
    </div>
</div>
```

Add to the script section:

```js
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { PlusIcon, LinkIcon, TrashIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/Components/UI/ComboBox.vue'

const addingManualLink = ref(false)
const manualLink = reactive({ child_asset_id: null, product_relation_id: null })

function submitManualLink() {
    router.post('/assetrelations', {
        parent_asset_id:      props.asset.id,
        child_asset_id:       manualLink.child_asset_id,
        product_relation_id:  manualLink.product_relation_id,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingManualLink.value = false
            manualLink.child_asset_id = null
            manualLink.product_relation_id = null
        },
    })
}

function removeAssetRelation(relationId) {
    router.delete(`/assetrelations/${relationId}`, { preserveScroll: true })
}
```

Update the child-asset rows in the template to include a delete button (using `removeAssetRelation(rel.id)`).

- [ ] **Step 5.6: Verify in browser**

1. Open an asset whose product type has children in the hierarchy.
2. Confirm the "Machine handmatig koppelen" button appears.
3. The dropdown shows only same-customer assets with compatible product types.
4. Saving creates the AssetRelation and the linked machine appears.
5. Try linking an incompatible asset (different customer or wrong type) — confirm it fails with the error message.

- [ ] **Step 5.7: Commit**

```bash
git add app/Http/Controllers/AssetRelationController.php \
        app/Http/Requests/AssetRelationStoreRequest.php \
        app/Models/AssetRelation.php \
        app/Http/Controllers/AssetController.php \
        resources/js/Pages/Assets/ShowPage.vue \
        routes/web.php
git commit -m "feat(AssetRelation): manual asset linking with product-type hierarchy validation"
```

---

## Task 6: ServiceJob grouping — de-duplicate, parent_service_job_id, grouped UI and PDF

**Files:**
- Create: `database/migrations/2026_05_02_200003_add_parent_service_job_id_to_service_jobs_table.php`
- Modify: `app/Models/ServiceJob.php`
- Modify: `app/Http/Controllers/ServiceJobController.php`
- Modify: `resources/js/Pages/ServiceJob/ShowPage.vue`
- Modify: `resources/views/pdf/servicejob.blade.php`

- [ ] **Step 6.1: Add `parent_service_job_id` migration**

```php
// database/migrations/2026_05_02_200003_add_parent_service_job_id_to_service_jobs_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->foreignId('parent_service_job_id')
                ->nullable()
                ->after('service_order_id')
                ->nullOnDelete()
                ->constrained('service_jobs');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropForeign(['parent_service_job_id']);
            $table->dropColumn('parent_service_job_id');
        });
    }
};
```

- [ ] **Step 6.2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 6.3: Update `ServiceJob` model**

Add to `$fillable`:
```php
'parent_service_job_id',
```

Add relationships:
```php
public function parentJob()
{
    return $this->belongsTo(ServiceJob::class, 'parent_service_job_id');
}

public function childJobs()
{
    return $this->hasMany(ServiceJob::class, 'parent_service_job_id');
}
```

- [ ] **Step 6.4: Update `ServiceJobController::store()` — de-duplicate and set parent_service_job_id**

Replace the store method (from Task 4 Step 4.1) with the updated version that:
- Does NOT create a duplicate if a ServiceJob already exists for this asset+serviceOrder
- Sets `parent_service_job_id` on each child job

```php
public function store(ServiceJobCreateRequest $request)
{
    $job = ServiceJob::create($request->validated());

    $serviceOrder = ServiceOrder::with('customer')->find($job->service_order_id);
    if ($serviceOrder) {
        $asset = $job->asset()->with(['product.brand', 'product.productType'])->first();
        if ($asset) {
            $serviceOrder->logActivity(sprintf(
                'Keuring toegevoegd: %s %s %s (serienummer %s)',
                $asset->product->productType->name ?? 'Onbekend type',
                $asset->product->brand->name ?? '',
                $asset->product->model ?? '',
                $asset->serial_number ?? '-'
            ));
        }
    }

    // Auto-create (or find existing) service jobs for all child assets
    $parentAsset = Asset::with([
        'childAssets.product.brand',
        'childAssets.product.productType',
    ])->find($job->asset_id);

    $newChildCount = 0;

    foreach ($parentAsset->childAssets as $childAsset) {
        // Reuse an existing job for this asset in the same ServiceOrder rather than double-linking
        $childJob = ServiceJob::firstOrCreate(
            [
                'asset_id'         => $childAsset->id,
                'service_order_id' => $job->service_order_id,
            ],
            [
                'outcome'                => ServiceJobOutcomes::nog_geen_uitkomst->value,
                'parent_service_job_id'  => $job->id,
            ]
        );

        // If it existed already, just ensure the parent link is set
        if (!$childJob->wasRecentlyCreated && $childJob->parent_service_job_id === null) {
            $childJob->update(['parent_service_job_id' => $job->id]);
        }

        if ($childJob->wasRecentlyCreated) {
            $newChildCount++;
            if ($serviceOrder) {
                $serviceOrder->logActivity(sprintf(
                    'Gecombineerde keuring toegevoegd voor onderdeel: %s %s %s (serienummer %s)',
                    $childAsset->product->productType->name ?? 'Onbekend type',
                    $childAsset->product->brand->name ?? '',
                    $childAsset->product->model ?? '',
                    $childAsset->serial_number ?? '-'
                ));
            }
        }
    }

    $message = $newChildCount > 0
        ? "Keuring succesvol aangemaakt. {$newChildCount} gecombineerde keuring(en) aangemaakt voor gerelateerde onderdelen."
        : 'Keuring succesvol aangemaakt.';

    return redirect()->back()->with('success', $message);
}
```

- [ ] **Step 6.5: Update `ServiceJobController::show()` to load grouped context**

Add to the existing load array in `show()`:

```php
$servicejob->load([
    // ... existing loads ...
    'parentJob.asset.product.brand',
    'parentJob.asset.product.productType',
    'parentJob.asset.customer',
    'childJobs.asset.product.brand',
    'childJobs.asset.product.productType',
    'childJobs.checkInstances.serviceCheck.group',
    'childJobs.checkInstances.serviceCheck.values',
    'childJobs.checkInstances.values',
    'childJobs.checkInstances.images',
    'childJobs.checkInstances.remarks.user',
]);
```

Remove the sibling-jobs computation from Task 4 Step 4.2 (replaced by parent/child job relationships). Pass to inertia:

```php
return inertia('ServiceJob/ShowPage', [
    'servicejob'            => $servicejob,
    'checkTypesWithOptions' => array_keys(ServiceCheckTypes::getTypesWithOptions()),
    'possibleOutcomes'      => ServiceJobOutcomes::comboBoxArray(),
    'missing_checks'        => $missing,
    'missing_checks_count'  => $missing->count(),
    // parent/child group data:
    'parent_job' => $servicejob->parentJob ? [
        'id'          => $servicejob->parentJob->id,
        'asset_label' => $servicejob->parentJob->asset->product->brand->name
            . ' ' . $servicejob->parentJob->asset->product->model
            . ' (' . ($servicejob->parentJob->asset->serial_number ?? '-') . ')',
        'outcome'     => $servicejob->parentJob->outcome,
    ] : null,
    'child_jobs' => $servicejob->childJobs->map(fn($j) => [
        'id'          => $j->id,
        'asset_label' => $j->asset->product->brand->name
            . ' ' . $j->asset->product->model
            . ' (' . ($j->asset->serial_number ?? '-') . ')',
        'outcome'     => $j->outcome,
    ]),
]);
```

- [ ] **Step 6.6: Update `ServiceJob/ShowPage.vue` for grouped display**

Replace the `sibling_jobs` prop (from Task 4 Step 4.3) with:

```js
parent_job: { type: Object, default: null },
child_jobs: { type: Array,  default: () => [] },
```

Replace the sibling-jobs notice with a group context block that renders differently for parent vs child jobs:

```vue
<!-- Group context banner — shown at the top of the check list area -->
<div v-if="parent_job || child_jobs.length"
    class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-md">

    <!-- This is a child job — show link back to parent -->
    <div v-if="parent_job">
        <p class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-1">
            Onderdeel van gecombineerde keuring:
        </p>
        <Link :href="`/servicejobs/${parent_job.id}`" class="text-blue-600 underline text-sm">
            {{ parent_job.asset_label }}
        </Link>
        <span class="text-xs text-gray-400 ml-2">{{ parent_job.outcome }}</span>
    </div>

    <!-- This is a parent job — show child jobs below -->
    <div v-if="child_jobs.length">
        <p class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-1">
            Gecombineerde keuring — onderdelen:
        </p>
        <ul class="space-y-1">
            <li v-for="cj in child_jobs" :key="cj.id" class="flex items-center gap-2">
                <Link :href="`/servicejobs/${cj.id}`" class="text-blue-600 underline text-sm">
                    {{ cj.asset_label }}
                </Link>
                <span class="text-xs text-gray-400">{{ cj.outcome }}</span>
            </li>
        </ul>
    </div>
</div>
```

- [ ] **Step 6.7: Update `generateServiceJobPdf()` for grouped report**

In `ServiceJobController::generateServiceJobPdf()`, load the parent and child jobs and build a combined grouped structure for the PDF:

```php
$servicejob->load([
    // ... existing loads ...
    'parentJob.asset.product.brand',
    'parentJob.asset.product.productType',
    'childJobs.asset.product.brand',
    'childJobs.asset.product.productType',
    'childJobs.asset.customer',
    'childJobs.checkInstances.serviceCheck.group',
    'childJobs.checkInstances.serviceCheck.values',
    'childJobs.checkInstances.values',
    'childJobs.checkInstances.images',
    'childJobs.checkInstances.remarks.user',
    'childJobs.asset.product.productType.serviceCheckGroups',
]);

// Build child job report sections (same grouping logic as the main job)
$childJobSections = $servicejob->childJobs->map(function ($childJob) {
    $childInstances = $childJob->checkInstances;
    $childPtGroups  = collect($childJob->asset?->product?->productType?->serviceCheckGroups ?? [])
        ->map(fn($g) => ['id' => $g->id, 'name' => $g->name, 'order' => $g->order ?? PHP_INT_MAX, 'items' => []])
        ->keyBy('id');
    $childOther = ['key' => 'other', 'name' => 'Overige keurpunten', 'order' => PHP_INT_MAX, 'items' => []];

    foreach ($childInstances as $ci) {
        $gid = $ci->serviceCheck?->group?->id;
        if ($gid && $childPtGroups->has($gid)) {
            $group = $childPtGroups->get($gid);
            $group['items'][] = $ci;
            $childPtGroups->put($gid, $group);
        } else {
            $childOther['items'][] = $ci;
        }
    }
    $childGroups = $childPtGroups->filter(fn($g) => count($g['items']) > 0)
        ->sortBy('order')->values()->all();
    if (count($childOther['items']) > 0) $childGroups[] = $childOther;

    return [
        'asset_label' => $childJob->asset->product->brand->name
            . ' ' . $childJob->asset->product->model
            . ' — ' . ($childJob->asset->serial_number ?? '-'),
        'outcome'     => $childJob->outcome,
        'groups'      => array_map(function ($g) {
            $g['items'] = array_map(fn($ci) => [
                'check_name'   => $ci->serviceCheck?->name,
                'type'         => $ci->serviceCheck?->type,
                'description'  => $ci->description,
                'switch_state' => $ci->switch_state ?? null,
                'values'       => $ci->values?->pluck('value')->all() ?? [],
                'remarks'      => ($ci->remarks ?? collect())->map(fn($r) => $r->content)->all(),
                'images'       => $ci->images,
            ], $g['items']);
            return $g;
        }, $childGroups),
    ];
})->all();
```

Pass to the Blade view:
```php
'childJobSections' => $childJobSections,
'isChildJob'       => $servicejob->parent_service_job_id !== null,
'parentJobLabel'   => $servicejob->parentJob
    ? $servicejob->parentJob->asset->product->brand->name
        . ' ' . $servicejob->parentJob->asset->product->model
        . ' — ' . ($servicejob->parentJob->asset->serial_number ?? '-')
    : null,
```

- [ ] **Step 6.8: Update `resources/views/pdf/servicejob.blade.php` for grouped sections**

Replace the sibling-jobs notice from Task 4 Step 4.4 with a richer grouped section. After the asset/customer header block, add:

```blade
{{-- Parent context (when this is a child job) --}}
@if($isChildJob && $parentJobLabel)
<div style="margin-bottom: 12px; padding: 6px 10px; background: #eff6ff; border-left: 3px solid #3b82f6; font-size: 10px;">
    <strong>Onderdeel van gecombineerde keuring:</strong> {{ $parentJobLabel }}
</div>
@endif

{{-- Main job checks already rendered by existing template logic --}}
```

After the main job checks section, add child job sections:

```blade
@if(!empty($childJobSections))
    @foreach($childJobSections as $childSection)
    <div style="margin-top: 24px; border-top: 2px solid #3b82f6; padding-top: 12px;">
        <h3 style="font-size: 12px; font-weight: bold; color: #1e40af; margin-bottom: 8px;">
            Onderdeel: {{ $childSection['asset_label'] }}
        </h3>
        @foreach($childSection['groups'] as $group)
            <h4 style="font-size: 11px; font-weight: bold; margin: 10px 0 4px;">{{ $group['name'] }}</h4>
            <table style="width:100%; border-collapse:collapse; font-size: 9px;">
                <thead>
                    <tr style="background: #f3f4f6;">
                        <th style="text-align:left; padding:3px 6px; border:1px solid #e5e7eb;">Keurpunt</th>
                        <th style="text-align:left; padding:3px 6px; border:1px solid #e5e7eb; width:120px;">Resultaat</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($group['items'] as $item)
                    <tr>
                        <td style="padding:3px 6px; border:1px solid #e5e7eb;">{{ $item['check_name'] }}</td>
                        <td style="padding:3px 6px; border:1px solid #e5e7eb;">
                            @if($item['type'] === 'boolean')
                                {{ $item['switch_state'] === true ? 'Ja' : ($item['switch_state'] === false ? 'Nee' : '—') }}
                            @elseif(!empty($item['values']))
                                {{ implode(', ', $item['values']) }}
                            @elseif($item['description'])
                                {{ $item['description'] }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @if(!empty($item['remarks']))
                    <tr>
                        <td colspan="2" style="padding:2px 6px 4px; border:1px solid #e5e7eb; color:#6b7280; font-style:italic; font-size:8px;">
                            {{ implode(' | ', $item['remarks']) }}
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        @endforeach
        <p style="font-size:9px; margin-top:6px;">
            <strong>Uitkomst onderdeel:</strong> {{ $childSection['outcome'] }}
        </p>
    </div>
    @endforeach
@endif
```

- [ ] **Step 6.9: Verify combined PDF in browser**

1. Open a ServiceJob for a parent asset that has child jobs.
2. Click "Export PDF".
3. Confirm the PDF contains the parent job's checks followed by clearly headed child-job sections.
4. Open a child job's PDF — confirm it shows the "Onderdeel van gecombineerde keuring" notice.

- [ ] **Step 6.10: Commit**

```bash
git add database/migrations/2026_05_02_200003_add_parent_service_job_id_to_service_jobs_table.php \
        app/Models/ServiceJob.php \
        app/Http/Controllers/ServiceJobController.php \
        resources/js/Pages/ServiceJob/ShowPage.vue \
        resources/views/pdf/servicejob.blade.php
git commit -m "feat(ServiceJob): group parent/child jobs, de-duplicate, grouped PDF report"
```

---

## Task 7: Permissions for new resources

**Files:**
- Modify: `database/seeders/Customer/SpeeTotaaltechniekSeeder.php` (or a shared permissions seeder)

The application uses a string-based permission system checked via `hasPermission()` in Vue. New permissions needed:

| Permission | Used for |
|---|---|
| `productrelation.read` | Nav item visibility + index page access |
| `productrelation.create` | Create button on index page |
| `productrelation.update` | Inline edit in grid |
| `productrelation.delete` | Delete button in grid |
| `productable.read` | Related products section on product show page |
| `productable.create` | Add relation button |
| `productable.delete` | Delete relation button |

- [ ] **Step 5.1: Find how permissions are seeded**

```bash
grep -r "Permission::firstOrCreate\|Permission::create\|view_prices" \
    /home/guido/nvme0n1p1/code/lavoro/database/seeders/ --include="*.php" -l
```

Open those files and follow the same pattern to add the new permissions.

- [ ] **Step 5.2: Seed the new permissions and assign to the appropriate roles**

Follow the same pattern as the `view_prices` permission. The exact seeder file and role-assignment syntax will be clear from Step 5.1. New permissions to seed: `productrelation.read`, `productrelation.create`, `productrelation.update`, `productrelation.delete`, `productable.read`, `productable.create`, `productable.delete`.

- [ ] **Step 5.3: Run the seeder**

```bash
php artisan db:seed --seeder=<PermissionSeeder identified in Step 5.1>
```

- [ ] **Step 5.4: Commit**

```bash
git add database/seeders/
git commit -m "feat(Permissions): add product relation and productable permissions"
```

---

## Self-Review Against Spec

### Spec coverage check

| Requirement | Task |
|---|---|
| Morph model called `productable` | Task 2 (Productable model, morphedByMany on Product) |
| Model called `ProductRelation` | Task 1 |
| ProductRelation in menu as Products submenu | Task 1 Step 1.8 |
| Create relation types (part, required part, optional part…) | Task 1 |
| Product show: select child products from child product types | Task 2 Step 2.8, 2.9 |
| Relation type, quantity, is_required on the link | Task 2 (Productable fields) |
| Auto-create child assets when is_required=true | Task 3 Step 3.6 |
| Prompt for child asset serials | Task 3 Step 3.9 (AddAssetForm) |
| AssetRelation model (parent_id, child_id, relation type) | Task 3 Steps 3.1–3.4 |
| Combined ServiceJob for child assets | Task 4 Step 4.1 |
| ServiceCheckInstances for child asset jobs | Task 4 — handled automatically by existing `ServiceJob::booted()` hook |
| ServiceJob reports show sibling context | Task 4 Steps 4.3–4.4 |
| Permissions | Task 5 |

### Gaps / clarifications resolved

- **ServiceCheckInstances on assets directly?** No, they're on ServiceJobs. The existing `ServiceJob::booted()` creates them per job. No new morph relation needed — creating ServiceJobs for child assets is sufficient.
- **ServiceOrder PDF for combined jobs:** The existing `serviceorders.emailPdfWithJobs` already combines all jobs in the order. No change needed there.
- **Per-job PDF:** Shows sibling context notice (Task 4 Step 4.4). Each job's own checks are in its own PDF.
- **Child product type filtering:** Only products whose `product_type.parent_id` = current product's `product_type_id` are shown as eligible children. If the current product type has no children, the section shows a note.
- **Optional parts (is_required=false):** Asset creation does NOT auto-create them. But if they were manually added as AssetRelations, they DO get combined ServiceJobs (all child assets, regardless of is_required, get combined jobs — the is_required flag only governs auto-creation).
