# Service Order Stages — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce configurable stages for service orders. Stages are managed via a drag-to-reorder CRUD page; each service order optionally references one stage; a clickable steps progress bar is rendered on the ShowPage when ≥2 stages exist; a new "Werkbonnen" nav chapter exposes a paginated, filterable service orders index plus a "Fases" sub-page.

**Architecture:**
- Backend: one new `ServiceOrderStage` Eloquent model + table, a nullable FK on `service_orders`, four new permissions, one new controller (`ServiceOrderStageController`), four new Form Requests, edits to `ServiceOrderUpdateRequest`/`ServiceOrderController` to fill out the index method, eager-load the stage on show, and log stage changes.
- Frontend: new "Werkbonnen" top-level nav with "Fases" child; new `ServiceOrders/IndexPage.vue` modelled on `Products/IndexPage.vue`; new `ServiceOrderStages/IndexPage.vue` with `vue-draggable-next` reorder; reuse of the existing `Components/UI/StepsProgressBar.vue` on the ShowPage. Stage and status pills use the existing `Components/UI/BadgeComponent.vue`.

**Tech Stack:** Laravel 11, Inertia.js, Vue 3, Tailwind CSS, `vue-draggable-next` (already in `package.json`).

**Conventions (from [claude.md](claude.md)):**
- PHP: snake_case for variables.
- No inline comments; docblocks only when needed.
- No automated tests in this plan — verification is manual in each task.
- No git operations in this plan.
- Authorization via Form Requests (`authorize()`).

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/2026_06_01_000001_create_service_order_stages_table.php` | Create | New stages table |
| `database/migrations/2026_06_01_000002_add_service_order_stage_id_to_service_orders_table.php` | Create | Nullable FK on `service_orders` |
| `database/migrations/2026_06_01_000003_seed_serviceorderstage_permissions.php` | Create | Insert 4 permission rows |
| `app/Models/ServiceOrderStage.php` | Create | Eloquent model with `serviceOrders()` relation |
| `app/Models/ServiceOrder.php` | Modify | Add fillable + `serviceOrderStage()` belongsTo |
| `app/Http/Requests/ServiceOrderStageReadRequest.php` | Create | Index authorization |
| `app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php` | Create | Create/update auth + rules |
| `app/Http/Requests/ServiceOrderStageReorderRequest.php` | Create | Reorder auth + rules |
| `app/Http/Requests/ServiceOrderIndexRequest.php` | Create | SO index auth + filter rules |
| `app/Http/Requests/ServiceOrderUpdateRequest.php` | Modify | Permit `service_order_stage_id` |
| `app/Http/Controllers/ServiceOrderStageController.php` | Create | CRUD + reorder endpoint |
| `app/Http/Controllers/ServiceOrderController.php` | Modify | Fill in `index`; extend `show`; log stage change in `update` |
| `routes/web.php` | Modify | Register stage routes |
| `resources/js/Layouts/MainLayout.vue` | Modify | Add Werkbonnen nav chapter |
| `resources/js/Pages/ServiceOrders/IndexPage.vue` | Create | Products-style paginated list with stage filter |
| `resources/js/Pages/ServiceOrderStages/IndexPage.vue` | Create | Drag-to-reorder CRUD page |
| `resources/js/Pages/ServiceOrders/ShowPage.vue` | Modify | Render `StepsProgressBar` when ≥2 stages |

---

## Task 1: Database migrations

**Files:**
- Create: `database/migrations/2026_06_01_000001_create_service_order_stages_table.php`
- Create: `database/migrations/2026_06_01_000002_add_service_order_stage_id_to_service_orders_table.php`
- Create: `database/migrations/2026_06_01_000003_seed_serviceorderstage_permissions.php`

- [ ] **Step 1: Create the stages table migration**

`database/migrations/2026_06_01_000001_create_service_order_stages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_order_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_stages');
    }
};
```

- [ ] **Step 2: Create the FK migration**

`database/migrations/2026_06_01_000002_add_service_order_stage_id_to_service_orders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignId('service_order_stage_id')
                ->nullable()
                ->constrained('service_order_stages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_order_stage_id');
        });
    }
};
```

- [ ] **Step 3: Create the permissions migration**

`database/migrations/2026_06_01_000003_seed_serviceorderstage_permissions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration {
    private array $permissions = [
        ['name' => 'serviceorderstage.read',   'label' => 'Werkbonfase zien'],
        ['name' => 'serviceorderstage.create', 'label' => 'Werkbonfase aanmaken'],
        ['name' => 'serviceorderstage.update', 'label' => 'Werkbonfase bijwerken'],
        ['name' => 'serviceorderstage.delete', 'label' => 'Werkbonfase verwijderen'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (!Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};
```

- [ ] **Step 4: Run the migrations**

Run: `php artisan migrate`
Expected: Three new migrations execute successfully; output mentions `2026_06_01_000001`, `…000002`, `…000003`.

- [ ] **Step 5: Verify schema and permissions**

Run: `php artisan tinker --execute='echo Schema::hasTable("service_order_stages") ? "ok " : "missing "; echo Schema::hasColumn("service_orders", "service_order_stage_id") ? "ok " : "missing "; echo App\Models\Permission::whereIn("name", ["serviceorderstage.read","serviceorderstage.create","serviceorderstage.update","serviceorderstage.delete"])->count();'`
Expected output: `ok ok 4`

---

## Task 2: Models

**Files:**
- Create: `app/Models/ServiceOrderStage.php`
- Modify: `app/Models/ServiceOrder.php`

- [ ] **Step 1: Create the `ServiceOrderStage` model**

`app/Models/ServiceOrderStage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'order',
    ];

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }
}
```

- [ ] **Step 2: Extend `ServiceOrder` model — add fillable**

In `app/Models/ServiceOrder.php`, append `'service_order_stage_id'` to the `$fillable` array (currently ends with `'actual_end_time',`).

Replace:
```php
        'actual_start_time',
        'actual_end_time',
    ];
```
with:
```php
        'actual_start_time',
        'actual_end_time',
        'service_order_stage_id',
    ];
```

- [ ] **Step 3: Extend `ServiceOrder` model — add belongsTo**

In `app/Models/ServiceOrder.php`, immediately after the existing `project()` method, add:

```php
    public function serviceOrderStage()
    {
        return $this->belongsTo(ServiceOrderStage::class);
    }
```

- [ ] **Step 4: Verify the model wires up**

Run: `php artisan tinker --execute='$so = App\Models\ServiceOrder::query()->first(); echo $so ? get_class($so->serviceOrderStage()) : "no service orders";'`
Expected: `Illuminate\Database\Eloquent\Relations\BelongsTo` (or `no service orders` if the DB is empty — the class load succeeds without error either way).

---

## Task 3: Form Requests

**Files:**
- Create: `app/Http/Requests/ServiceOrderStageReadRequest.php`
- Create: `app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php`
- Create: `app/Http/Requests/ServiceOrderStageReorderRequest.php`
- Create: `app/Http/Requests/ServiceOrderIndexRequest.php`
- Modify: `app/Http/Requests/ServiceOrderUpdateRequest.php`

- [ ] **Step 1: Create `ServiceOrderStageReadRequest`**

`app/Http/Requests/ServiceOrderStageReadRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderStageReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('serviceorderstage.read'));
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 2: Create `ServiceOrderStageStoreUpdateRequest`**

`app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderStageStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $permission = $this->isMethod('post')
            ? 'serviceorderstage.create'
            : 'serviceorderstage.update';
        return $user->hasPermission($permission);
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
```

- [ ] **Step 3: Create `ServiceOrderStageReorderRequest`**

`app/Http/Requests/ServiceOrderStageReorderRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderStageReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('serviceorderstage.update'));
    }

    public function rules(): array
    {
        return [
            'payload'         => ['required', 'array'],
            'payload.*.id'    => ['required', 'integer', 'exists:service_order_stages,id'],
            'payload.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

- [ ] **Step 4: Create `ServiceOrderIndexRequest`**

`app/Http/Requests/ServiceOrderIndexRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('serviceorder.read'));
    }

    public function rules(): array
    {
        return [
            'search'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'onlyStage' => ['sometimes', 'nullable', 'integer', 'exists:service_order_stages,id'],
            'perPage'   => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

- [ ] **Step 5: Permit `service_order_stage_id` in `ServiceOrderUpdateRequest`**

In `app/Http/Requests/ServiceOrderUpdateRequest.php`, inside the `rules()` array, add one entry after `'actual_end_time' => ...`:

Replace:
```php
            'actual_end_time' => 'nullable|date_format:H:i|after:actual_start_time',
        ];
```
with:
```php
            'actual_end_time' => 'nullable|date_format:H:i|after:actual_start_time',
            'service_order_stage_id' => 'nullable|exists:service_order_stages,id',
        ];
```

- [ ] **Step 6: Sanity-check classes load**

Run: `php -r "require 'vendor/autoload.php'; require 'bootstrap/app.php'; foreach (['ServiceOrderStageReadRequest','ServiceOrderStageStoreUpdateRequest','ServiceOrderStageReorderRequest','ServiceOrderIndexRequest','ServiceOrderUpdateRequest'] as \$c) { class_exists('App\\\\Http\\\\Requests\\\\'.\$c) ? print(\$c.\" ok\n\") : print(\$c.\" FAIL\n\"); }"`
Expected: each request prints `ok`.

---

## Task 4: ServiceOrderStageController + routes

**Files:**
- Create: `app/Http/Controllers/ServiceOrderStageController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the controller**

`app/Http/Controllers/ServiceOrderStageController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrderStage;
use App\Http\Requests\ServiceOrderStageReadRequest;
use App\Http\Requests\ServiceOrderStageStoreUpdateRequest;
use App\Http\Requests\ServiceOrderStageReorderRequest;
use Illuminate\Support\Facades\DB;

class ServiceOrderStageController extends Controller
{
    public function index(ServiceOrderStageReadRequest $request)
    {
        $search = $request->get('search', '');
        $query = ServiceOrderStage::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return inertia('ServiceOrderStages/IndexPage', [
            'stages' => $query->orderBy('order')->paginate(25),
            'search' => $search,
        ]);
    }

    public function store(ServiceOrderStageStoreUpdateRequest $request)
    {
        $data = $request->validated();
        if (!isset($data['order'])) {
            $data['order'] = (ServiceOrderStage::max('order') ?? 0) + 1;
        }
        ServiceOrderStage::create($data);

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is aangemaakt');
    }

    public function update(
        ServiceOrderStageStoreUpdateRequest $request,
        ServiceOrderStage $serviceorderstage
    ) {
        $serviceorderstage->update($request->validated());

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is bijgewerkt');
    }

    public function destroy(ServiceOrderStage $serviceorderstage)
    {
        $serviceorderstage->delete();
        return redirect()->back()->with('success', 'Fase is verwijderd');
    }

    public function updateOrder(ServiceOrderStageReorderRequest $request)
    {
        $payload = $request->validated()['payload'];
        DB::transaction(function () use ($payload) {
            foreach ($payload as $row) {
                ServiceOrderStage::where('id', $row['id'])->update(['order' => $row['order']]);
            }
        });
        return redirect()->back();
    }
}
```

- [ ] **Step 2: Register routes**

In `routes/web.php`, locate the existing `Route::resource('servicecheckgroups', …)` block (around line 90). Immediately after the `servicecheckgroups` resource line and before `Route::post('servicecheckvalues/reorder', …)`, add:

```php
        Route::resource('serviceorderstages', \App\Http\Controllers\ServiceOrderStageController::class)
            ->except(['show', 'edit', 'create']);
        Route::post('serviceorderstages/reorder', [\App\Http\Controllers\ServiceOrderStageController::class, 'updateOrder'])
            ->name('serviceorderstages.reorder');
```

(Use the FQCN so this step does not require touching the existing `use` block at the top of `routes/web.php`.)

- [ ] **Step 3: Verify routes registered**

Run: `php artisan route:list --path=serviceorderstages`
Expected output lists at least:
- `GET  serviceorderstages` → `serviceorderstages.index`
- `POST serviceorderstages` → `serviceorderstages.store`
- `PUT  serviceorderstages/{serviceorderstage}` → `serviceorderstages.update`
- `DELETE serviceorderstages/{serviceorderstage}` → `serviceorderstages.destroy`
- `POST serviceorderstages/reorder` → `serviceorderstages.reorder`

---

## Task 5: ServiceOrderController — index, show, update

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php`

- [ ] **Step 1: Add the new imports**

At the top of `app/Http/Controllers/ServiceOrderController.php`, alongside the existing `use App\Models\…` lines, add:

```php
use App\Models\ServiceOrderStage;
use App\Http\Requests\ServiceOrderIndexRequest;
```

- [ ] **Step 2: Implement `index`**

Replace the existing empty `index` method:

```php
    public function index()
    {
        //
    }
```
with:

```php
    public function index(ServiceOrderIndexRequest $request)
    {
        $search = $request->get('search', '');
        $only_stage = $request->get('onlyStage');
        $per_page = (int) ($request->get('perPage') ?: 25);

        $query = ServiceOrder::with(['customer', 'serviceOrderStage']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('external_purchaseorder_no', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($only_stage) {
            $query->where('service_order_stage_id', $only_stage);
        }

        return inertia('ServiceOrders/IndexPage', [
            'serviceOrders' => $query->orderByDesc('created_at')->paginate($per_page)->withQueryString(),
            'stages'        => ServiceOrderStage::orderBy('order')->get(),
            'search'        => $search,
            'onlyStage'     => $only_stage,
            'perPage'       => $per_page,
        ]);
    }
```

- [ ] **Step 3: Extend `show` to eager-load the stage and pass `stages`**

In the existing `show` method, find the `->with([` array and add `'serviceOrderStage',` as a new entry (anywhere in the list — e.g. directly after `'customer.assets.product.brand',`).

Then change the return statement.

Replace:
```php
        return inertia('ServiceOrders/ShowPage', [
            'serviceOrder' => $service_order,
            'allMaterials' => Material::all()->load([
                'usageUnit',
            ]),
            'customFields' => $service_order->allCustomFieldsWithValues(),
        ]);
```
with:
```php
        return inertia('ServiceOrders/ShowPage', [
            'serviceOrder' => $service_order,
            'allMaterials' => Material::all()->load([
                'usageUnit',
            ]),
            'customFields' => $service_order->allCustomFieldsWithValues(),
            'stages'       => ServiceOrderStage::orderBy('order')->get(),
        ]);
```

- [ ] **Step 4: Log stage change in `update`**

Replace the existing `update` method body:

```php
    public function update(ServiceOrderUpdateRequest $request, ServiceOrder $serviceorder)
    {
        $data = $request->validated();
        if ($serviceorder->status !== 'closed' && $request->input('status') === 'closed') {
            $data['closed_on'] = now();
        }
        $serviceorder->update($data);
        return redirect()->back()->with('success', 'Werkbon succesvol bijgewerkt.');
    }
```

with:

```php
    public function update(ServiceOrderUpdateRequest $request, ServiceOrder $serviceorder)
    {
        $data = $request->validated();
        if ($serviceorder->status !== 'closed' && $request->input('status') === 'closed') {
            $data['closed_on'] = now();
        }

        $previous_stage_id = $serviceorder->service_order_stage_id;
        $serviceorder->update($data);

        if (
            array_key_exists('service_order_stage_id', $data)
            && $data['service_order_stage_id'] != $previous_stage_id
        ) {
            if ($data['service_order_stage_id'] === null) {
                $serviceorder->logActivity('Fase verwijderd');
            } else {
                $new_stage = ServiceOrderStage::find($data['service_order_stage_id']);
                if ($new_stage) {
                    $serviceorder->logActivity("Fase gewijzigd naar: {$new_stage->name}");
                }
            }
        }

        return redirect()->back()->with('success', 'Werkbon succesvol bijgewerkt.');
    }
```

- [ ] **Step 5: Verify the controller boots**

Run: `php artisan route:list --path=serviceorders | head -5`
Expected output includes a `GET  serviceorders` row pointing at `ServiceOrderController@index` (already there, but now backed by a real method).

---

## Task 6: Navigation — Werkbonnen chapter

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue`

- [ ] **Step 1: Add icon imports**

In the `@heroicons/vue/24/outline` import block (around line 341), add `DocumentTextIcon` and `Bars4Icon`. Replace the closing `}` of that import with:

```js
    ClipboardDocumentListIcon,
    LinkIcon,
    TagIcon,
    DocumentTextIcon,
    Bars4Icon,
} from '@heroicons/vue/24/outline'
```

- [ ] **Step 2: Add the Werkbonnen nav item**

In the `navigation` array, insert the new entry directly after the `Storingen` entry (`{ name: 'Storingen', href: '/tickets', … }`) and before the `Keurpunten` entry:

```js
    {
        name: 'Werkbonnen',
        href: '/serviceorders',
        icon: DocumentTextIcon,
        current: false,
        requiresPermission: 'serviceorder.read',
        children: [
            { name: 'Fases', href: '/serviceorderstages', icon: Bars4Icon, current: false, requiresPermission: 'serviceorderstage.read' },
        ],
        open: false,
    },
```

- [ ] **Step 3: Verify in the browser**

Start the dev server (`npm run dev` in one terminal, `php artisan serve` in another, if not already running). Log in as an admin user, open the app, and confirm:
- A new top-level "Werkbonnen" chapter appears in the sidebar.
- Expanding it shows a "Fases" sub-item.
- Clicking "Werkbonnen" navigates to `/serviceorders` (will 500 until Task 7 lands — that is expected; just confirm the link itself fires the navigation).
- Clicking "Fases" navigates to `/serviceorderstages` (will likewise error until Task 8 lands).

---

## Task 7: ServiceOrders IndexPage

**Files:**
- Create: `resources/js/Pages/ServiceOrders/IndexPage.vue`

- [ ] **Step 1: Create the IndexPage**

`resources/js/Pages/ServiceOrders/IndexPage.vue`:

```vue
<template>
    <IndexHeaderComponent title="Werkbonnen" subtitle="Overzicht van alle werkbonnen"
        search-url="/serviceorders" search-label="Zoek binnen werkbonnen"
        search-placeholder="Zoek op klant, beschrijving of inkoopordernr."
        :search-other-params="filterParams" :paginator="false"
        :has-active-filters="activeFilters.length > 0">
        <template #filters>
            <div class="flex flex-col sm:flex-row gap-y-4 sm:gap-y-0">
                <div class="flex-grow">
                    <div class="flex items-end gap-2">
                        <ComboBox :options="stages" v-model="stageFilter"
                            placeholder="Selecteer fase" class="w-full" label="Filter op fase" />
                        <button type="button" @click="stageFilter = null"
                            class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600"
                            v-tooltip="'Reset filter op fase'">
                            <XCircleIcon class="h-5 w-5" />
                        </button>
                    </div>
                </div>
                <div class="hidden sm:flex w-1/6 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
            <div v-if="activeFilters.length" class="flex flex-wrap gap-2 mt-3" v-auto-animate>
                <span v-for="filter in activeFilters" :key="filter.key"
                    class="inline-flex items-center gap-x-1.5 rounded-md px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 bg-white dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                    <span class="text-gray-400 dark:text-slate-400">{{ filter.label }}:</span>
                    {{ filter.value }}
                    <button type="button" @click="filter.clear()"
                        class="group relative -mr-1 h-3.5 w-3.5 rounded-sm hover:bg-gray-500/20">
                        <span class="sr-only">Verwijder filter</span>
                        <svg viewBox="0 0 14 14"
                            class="h-3.5 w-3.5 stroke-gray-600/75 group-hover:stroke-gray-600 dark:stroke-slate-400 dark:group-hover:stroke-slate-300">
                            <path d="M4 4l6 6m0-6l-6 6" />
                        </svg>
                        <span class="absolute -inset-1" />
                    </button>
                </span>
                <div class="flex sm:hidden p-2 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
        </template>
    </IndexHeaderComponent>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="serviceOrders.data.length">
            <div class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-3">Klant</div>
                <div class="col-span-3">Beschrijving</div>
                <div class="col-span-2">Fase</div>
                <div class="col-span-2">Status</div>
                <div class="col-span-1">Aangemaakt</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="so in serviceOrders.data" :key="so.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2">
                <div class="col-span-10 sm:col-span-3 flex flex-col">
                    <Link :href="`/serviceorders/${so.id}`" class="font-bold mb-1">
                        {{ so.customer?.name ?? '—' }}
                    </Link>
                    <span v-if="so.external_purchaseorder_no" class="text-slate-600 text-xs">
                        Inkoopordernr.: {{ so.external_purchaseorder_no }}
                    </span>
                    <div class="flex flex-wrap gap-2 mt-2 sm:hidden">
                        <BadgeComponent :color="so.service_order_stage ? 'blue' : 'gray'" :has-dot="false">
                            {{ so.service_order_stage?.name ?? 'Geen fase' }}
                        </BadgeComponent>
                        <BadgeComponent :color="badgeColorFor(so)" :has-dot="false">
                            {{ serviceOrderPillText(so) }}
                        </BadgeComponent>
                    </div>
                </div>
                <div class="col-span-3 items-center hidden sm:flex pr-2 text-slate-700 dark:text-slate-300">
                    <span class="line-clamp-2">{{ so.description || '—' }}</span>
                </div>
                <div class="col-span-2 items-center hidden sm:flex pr-2">
                    <BadgeComponent :color="so.service_order_stage ? 'blue' : 'gray'" :has-dot="false">
                        {{ so.service_order_stage?.name ?? 'Geen fase' }}
                    </BadgeComponent>
                </div>
                <div class="col-span-2 items-center hidden sm:flex pr-2">
                    <BadgeComponent :color="badgeColorFor(so)" :has-dot="false">
                        {{ serviceOrderPillText(so) }}
                    </BadgeComponent>
                </div>
                <div class="col-span-1 items-center hidden sm:flex pr-2 text-slate-700 dark:text-slate-300">
                    {{ nlDate(so.created_at) }}
                </div>
                <div class="col-span-2 sm:col-span-1 items-center flex justify-end">
                    <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex">
                        <Link :href="`/serviceorders/${so.id}`" class="text-sm text-lavoro-darkerblue">
                            <EyeIcon class="h-5 w-5" />
                        </Link>
                    </div>
                </div>
            </div>
            <div class="flex justify-between bg-white rounded-b-lavoro-sm p-4 dark:bg-slate-900">
                <PageRecordCountComponent :total="serviceOrders.total" :per-page="perPage" label="werkbonnen" />
                <PaginationComponent :paginator="serviceOrders" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <ClipboardDocumentListIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen werkbonnen gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import { XCircleIcon } from '@heroicons/vue/24/outline'
import { EyeIcon, RotateCcwIcon, ClipboardDocumentListIcon } from '@lucide/vue'
import { nlDate, serviceOrderPillText, serviceOrderSentState } from '@/Utilities/Utilities'

const { serviceOrders, stages, perPage } = defineProps({
    serviceOrders: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    onlyStage: { type: [Number, String, null], default: null },
    perPage: { type: Number, default: 25 },
})

const stageFromUrl = typeof window !== 'undefined'
    ? Number(new URLSearchParams(window.location.search).get('onlyStage')) || null
    : null
const stageFilter = ref(stageFromUrl)

const filterParams = computed(() => ({
    onlyStage: stageFilter.value ?? '',
}))

const activeFilters = computed(() => {
    const out = []
    if (stageFilter.value) {
        const match = stages.find(s => s.id === stageFilter.value)
        if (match) {
            out.push({
                key: `stage-${match.id}`,
                label: 'Fase',
                value: match.name,
                clear: () => { stageFilter.value = null },
            })
        }
    }
    return out
})

function clearAllFilters() {
    stageFilter.value = null
}

function badgeColorFor(so) {
    switch (serviceOrderSentState(so)) {
        case 'both':
        case 'administration':
            return 'green'
        case 'customer':
            return 'blue'
        default:
            return 'gray'
    }
}
</script>
```

- [ ] **Step 2: Build assets and verify in the browser**

Make sure the Vite dev server is running (`npm run dev`). Then:

- Visit `/serviceorders` — page loads, header shows "Werkbonnen", existing service orders are listed.
- Click "Filters" to open the filter panel; verify the stage combobox is empty (no stages exist yet — Task 8 creates them).
- Verify the empty-state path by filtering with the search box for a string with no matches; the "Geen werkbonnen gevonden" empty state should render.
- Verify pagination works (if there are more than 25 service orders) and the `PageRecordCountComponent` per-page selector changes the page size.

---

## Task 8: ServiceOrderStages IndexPage (drag-to-reorder CRUD)

**Files:**
- Create: `resources/js/Pages/ServiceOrderStages/IndexPage.vue`

- [ ] **Step 1: Create the page**

`resources/js/Pages/ServiceOrderStages/IndexPage.vue`:

```vue
<template>
    <IndexHeaderComponent title="Werkbonfases" subtitle="Overzicht en volgorde van werkbonfases"
        search-url="/serviceorderstages" search-label="Zoek binnen fases"
        search-placeholder="bijv. 'Voorbereiding'"
        :paginator="stages" add-label="Voeg fase toe"
        @add="() => stageFormRef?.show()" />

    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="stageFormRef" external-trigger action="/serviceorderstages"
            :fields="stageFields" add-button-label="Voeg fase toe" submit-label="Opslaan" />
    </div>

    <BoxComponent padding="md:mx-0 px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="internalStages.length" class="mt-3">
            <div class="hidden md:grid md:grid-cols-12 px-4 py-2 text-sm font-semibold text-left border-b border-gray-200 dark:border-slate-700">
                <div class="col-span-1 text-gray-900 dark:text-gray-300"></div>
                <div class="col-span-2 text-gray-900 dark:text-gray-300">Volgorde</div>
                <div class="col-span-7 text-gray-900 dark:text-gray-300">Naam</div>
                <div class="col-span-2"></div>
            </div>
            <draggable v-model="internalStages" item-key="id" handle=".draghandle" :animation="200" @change="onReorder">
                <template #item="{ element: stage }">
                    <div :key="stage.id"
                        class="odd:bg-white even:bg-gray-100 dark:odd:bg-slate-800 dark:even:bg-slate-900">
                        <div class="relative pt-5 md:pt-0 md:grid grid-cols-12 break-all">
                            <div class="flex items-center px-4 py-2 col-span-1">
                                <Bars4Icon class="size-6 text-gray-500 cursor-move draghandle"
                                    v-tooltip="'Sleep om de volgorde aan te passen'" />
                            </div>
                            <div class="flex flex-col px-4 py-2 col-span-2">
                                <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Volgorde</span>
                                <span class="text-gray-800 dark:text-slate-200">{{ stage.order }}</span>
                            </div>
                            <div class="flex flex-col px-4 py-2 col-span-7">
                                <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Naam</span>
                                <div v-if="stage.open">
                                    <TextInput v-model="stage.name" />
                                </div>
                                <span v-else class="text-gray-800 dark:text-slate-200">{{ stage.name }}</span>
                            </div>
                            <div class="px-4 py-2 flex items-start justify-end gap-2 text-sm font-medium col-span-2">
                                <button v-if="!stage.open" @click="toggleRecord(stage.id)"
                                    v-tooltip="'Bewerk deze fase'">
                                    <PencilSquareIcon class="size-6 text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100" />
                                </button>
                                <button v-else @click="saveRecord(stage)" class="text-green-600 hover:text-green-800"
                                    v-tooltip="'Opslaan'">
                                    <CheckIcon class="size-6" />
                                </button>
                                <button @click.stop="deleteStage(stage.id)" v-tooltip="'Verwijder deze fase'">
                                    <TrashIcon class="size-6 text-red-400 hover:text-red-600" />
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </draggable>
        </div>
        <PaginationComponent v-if="internalStages.length" :paginator="stages"
            class="border-t border-gray-200 dark:border-slate-700 pt-2" />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen fases gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { VueDraggableNext as draggable } from 'vue-draggable-next'
import {
    PencilSquareIcon,
    TrashIcon,
    CheckIcon,
    Bars4Icon,
} from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

const { stages } = defineProps({
    stages: { type: Object, required: true },
    search: { type: String, default: '' },
})

const stageFormRef = ref(null)
const stageFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const internalStages = ref(
    (stages.data || []).map(s => ({ ...s, open: false }))
)

watch(
    () => stages.data,
    (newData) => {
        const existingById = {}
        for (const s of internalStages.value) existingById[s.id] = s
        internalStages.value = (newData || []).map(s => ({
            ...s,
            open: existingById[s.id]?.open || false,
        }))
    }
)

const reorderForm = useForm({ payload: [] })

function onReorder() {
    reorderForm.payload = internalStages.value.map((s, i) => ({ id: s.id, order: i + 1 }))
    reorderForm.post('/serviceorderstages/reorder', { preserveScroll: true })
}

function toggleRecord(id) {
    internalStages.value = internalStages.value.map((s) => {
        if (s.open) {
            const updateForm = useForm({ name: s.name, order: s.order })
            updateForm.patch(`/serviceorderstages/${s.id}`, { preserveScroll: true })
        }
        return { ...s, open: s.id === id ? !s.open : false }
    })
}

function saveRecord(stage) {
    const form = useForm({ name: stage.name, order: stage.order })
    form.patch(`/serviceorderstages/${stage.id}`, { preserveScroll: true, preserveState: false })
}

function deleteStage(id) {
    if (!confirm('Weet je zeker dat je deze fase wilt verwijderen?')) return
    useForm({}).delete(`/serviceorderstages/${id}`, { preserveScroll: true })
}
</script>
```

- [ ] **Step 2: Verify in the browser**

With `npm run dev` and `php artisan serve` running:

- Visit `/serviceorderstages`. The page loads with the "Werkbonfases" header. No rows yet.
- Click "Voeg fase toe", enter `Voorbereiding`, save. The row appears with `Volgorde = 1`.
- Add `Uitvoering`, `Afronding`. They get `Volgorde = 2, 3`.
- Drag `Afronding` to the top. After release, the rows re-number to `1, 2, 3` and the new order persists after a hard refresh.
- Click the pencil on a row, change the name, click the green check to save. The new name shows after the page reloads.
- Click the trash icon on a row, confirm the dialog. The row disappears.

---

## Task 9: ShowPage — render `StepsProgressBar`

**Files:**
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

- [ ] **Step 1: Add the import**

Near the existing component imports at the bottom of the `<script setup>` block (around the `import GlobalNotification from …` lines, or with the other `@/Components/UI/…` imports), add:

```js
import StepsProgressBar from '@/Components/UI/StepsProgressBar.vue'
```

- [ ] **Step 2: Accept the `stages` prop**

Locate the existing `defineProps({ … })` call in `<script setup>`. Add a `stages` entry. If the existing call already destructures with object syntax (e.g. `defineProps({ serviceOrder: { … }, allMaterials: …, customFields: … })`), add:

```js
    stages: { type: Array, default: () => [] },
```

as a new entry inside the same object.

- [ ] **Step 3: Add the stage-change handler**

The existing script uses `const props = defineProps({...})` (not destructured), so script-side access requires the `props.` prefix. Inside `<script setup>`, near the other `useForm`/handler declarations, add:

```js
function onStageChange(stage_id) {
    const form = useForm({
        customer_id: props.serviceOrder.customer.id,
        service_order_stage_id: stage_id,
    })
    form.patch(`/serviceorders/${props.serviceOrder.id}`, { preserveScroll: true })
}
```

(`useForm` is already imported at the top of the file.)

- [ ] **Step 4: Render the progress bar in the template**

Find the heading `<h2 class="text-lg font-medium my-4 …">Uitgevoerde werkzaamheden</h2>` (around line 117). Insert the following block on the line **before** that `<h2>`:

```vue
                <div v-if="stages.length > 1" class="mb-4"
                    :class="{ 'pointer-events-none opacity-60': serviceOrder.status === 'closed' }">
                    <StepsProgressBar :steps="stages"
                        :model-value="serviceOrder.service_order_stage_id"
                        @update:modelValue="onStageChange" />
                </div>
```

- [ ] **Step 5: Verify in the browser**

With at least two stages defined (use the page from Task 8):

- Open any service order at `/serviceorders/{id}`. The progress bar shows above "Uitgevoerde werkzaamheden". With no stage set, all steps render as upcoming.
- Click a step. The page reflects the new stage on next render (the current step gets the "current" indigo ring + label). A new activity entry appears in the activity log: `Fase gewijzigd naar: <stage name>`.
- Re-click an earlier step. It becomes the current; later steps become upcoming.
- Confirm the bar is hidden when only 0 or 1 stage exists (delete enough on `/serviceorderstages` to test, then re-add).
- Close the service order via the existing status flow and verify the progress bar dims and is unclickable (`pointer-events-none opacity-60`). Reopen the SO and confirm clicking works again.

---

## Self-Review

- **Spec coverage:** Each numbered section of [docs/superpowers/specs/2026-06-01-serviceorder-stages-design.md](docs/superpowers/specs/2026-06-01-serviceorder-stages-design.md) maps to a task:
  - Database (3 migrations) → Task 1
  - Models → Task 2
  - Form Requests → Task 3
  - `ServiceOrderStageController` + routes → Task 4
  - `ServiceOrderController` (index/show/update + activity logging) → Task 5
  - Nav (`MainLayout.vue`) → Task 6
  - `ServiceOrders/IndexPage.vue` (Products-style + BadgeComponent + stage filter) → Task 7
  - `ServiceOrderStages/IndexPage.vue` (drag CRUD) → Task 8
  - `ShowPage.vue` (reuse `StepsProgressBar`) → Task 9
  - Edge cases (closed SO disables bar, null stage, 0/1 stages hide bar, FK nullOnDelete) → covered by Tasks 1, 5, 9 verifications.
- **Placeholder scan:** No "TBD"/"TODO"/"add appropriate error handling" — every step has the actual code or command.
- **Type consistency:** `service_order_stage_id` (snake_case) used in PHP fillable, FK, requests, and controller; `service_order_stage` used as the eager-loaded relation key on the JSON shape (Laravel auto-snake-cases the relation method `serviceOrderStage()`); `stages` is the consistent prop name on both pages. Permission strings are stable: `serviceorderstage.{read,create,update,delete}`.
