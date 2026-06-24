# Internal Remarks / Images / Documents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an `internal` boolean to the `remarkables`, `imageables`, and `documentables` pivot tables so a second "internal" widget instance can be placed on the ServiceOrder show page for each type, with internal items hidden from the PDF.

**Architecture:** Each pivot table gets an `internal` column (default `false`). On ServiceOrder, each morphToMany relationship is split into a public scope and an internal scope. The existing relationship method name keeps its public-only behaviour (no consumers break). Controllers receive the flag from the component and store it on the pivot. The PDF only ever loads through the public-scoped relationship so internal items never appear.

**Tech Stack:** Laravel 12, Inertia, Vue 3, DomPDF (barryvdh/laravel-dompdf), Tailwind CSS.

## Global Constraints

- PHP: snake_case for all variable names.
- No inline comments.
- No new permissions — `authorize()` is unchanged on all requests.
- No tests unless explicitly asked.
- No separate "clear" buttons; toggling deselects per CLAUDE.md.
- String concatenation: `$string . ' some other string'` (spaces around `.`).
- All Dutch UI copy.
- Migration filenames: `2026_06_24_<seq>_<description>.php` (seq starts at `100001` to not collide with today's existing migration).

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `database/migrations/2026_06_24_100001_add_internal_to_remarkables_table.php` | Create | Adds `internal` bool to remarkables |
| `database/migrations/2026_06_24_100002_add_internal_to_imageables_table.php` | Create | Adds `internal` bool to imageables |
| `database/migrations/2026_06_24_100003_add_internal_to_documentables_table.php` | Create | Adds `internal` bool to documentables |
| `app/Models/Traits/RemarkableTrait.php` | Modify | Split `remarks()` into public + `internalRemarks()` |
| `app/Http/Requests/RemarkCreateRequest.php` | Modify | Add `internal` validation rule |
| `app/Http/Controllers/RemarkController.php` | Modify | Separate create+attach so pivot data can be set |
| `app/Models/ServiceOrder.php` | Modify | Scope `images()`/`documents()` to non-internal; add `internalImages()`, `internalDocuments()` |
| `app/Http/Requests/ImageStoreRequest.php` | Modify | Add `internal` validation rule |
| `app/Http/Controllers/ImageController.php` | Modify | Pass `internal` pivot on attach; replace `->detach()` with raw DB delete |
| `app/Http/Requests/DocumentStoreRequest.php` | Modify | Add `internal` validation rule |
| `app/Http/Controllers/DocumentController.php` | Modify | Pass `internal` pivot on attach |
| `app/Http/Controllers/ServiceOrderController.php` | Modify | Eager-load `internalRemarks.user`, `internalImages`, `internalDocuments`; pass `remarks` to PDF |
| `resources/views/pdf/serviceorder.blade.php` | Modify | Add public-remarks section |
| `resources/js/Components/RemarksComponent.vue` | Modify | Add `internal` prop; include in post payload |
| `resources/js/Components/ImageUploadComponent.vue` | Modify | Add `internal` prop; include in upload form |
| `resources/js/Components/DocumentUploadComponent.vue` | Modify | Add `internal` prop; include in upload form |
| `resources/js/Pages/ServiceOrders/ShowPage.vue` | Modify | Add second widget instances for remarks, images, documents |

---

## Task 1: Add `internal` to remarkables pivot + update RemarkableTrait, RemarkCreateRequest, RemarkController

**Files:**
- Create: `database/migrations/2026_06_24_100001_add_internal_to_remarkables_table.php`
- Modify: `app/Models/Traits/RemarkableTrait.php`
- Modify: `app/Http/Requests/RemarkCreateRequest.php`
- Modify: `app/Http/Controllers/RemarkController.php`

**Interfaces:**
- Produces: `RemarkableTrait::remarks()` scoped to public; `RemarkableTrait::internalRemarks()` scoped to internal. Both expose a `withPivot('internal')` configuration.

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remarkables', function (Blueprint $table) {
            $table->boolean('internal')->default(false)->after('remark_id');
        });
    }

    public function down(): void
    {
        Schema::table('remarkables', function (Blueprint $table) {
            $table->dropColumn('internal');
        });
    }
};
```

Save to `database/migrations/2026_06_24_100001_add_internal_to_remarkables_table.php`.

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_24_100001_add_internal_to_remarkables_table` … `Migrated`.

- [ ] **Step 3: Update RemarkableTrait**

Replace the full content of `app/Models/Traits/RemarkableTrait.php`:

```php
<?php

namespace App\Models\Traits;

use App\Models\Remark;

trait RemarkableTrait
{
    public function remarks()
    {
        return $this->morphToMany(Remark::class, 'remarkable')
            ->withPivot('internal')
            ->wherePivot('internal', false)
            ->orderBy('created_at', 'desc')
            ->withTimestamps();
    }

    public function internalRemarks()
    {
        return $this->morphToMany(Remark::class, 'remarkable')
            ->withPivot('internal')
            ->wherePivot('internal', true)
            ->orderBy('created_at', 'desc')
            ->withTimestamps();
    }
}
```

- [ ] **Step 4: Add `internal` to RemarkCreateRequest rules**

In `app/Http/Requests/RemarkCreateRequest.php`, add `'internal' => 'nullable|boolean'` to the `rules()` return array:

```php
public function rules(): array
{
    return [
        'content' => 'required|string|max:255',
        'remarkable_type' => 'required|string',
        'remarkable_id' => 'required|integer',
        'user_id' => 'required|integer|exists:users,id',
        'internal' => 'nullable|boolean',
    ];
}
```

- [ ] **Step 5: Update RemarkController::store to separate create + attach**

Replace the `store` method in `app/Http/Controllers/RemarkController.php`:

```php
public function store(RemarkCreateRequest $request)
{
    if ($request->has('remarkable_type') && $request->has('remarkable_id')) {
        $remarkable = $request->remarkable_type::find($request->remarkable_id);
        $remark = Remark::create([
            'content' => $request->content,
            'user_id' => Auth::user()->id,
        ]);
        $remarkable->remarks()->attach($remark->id, [
            'internal' => $request->boolean('internal', false),
        ]);
        return redirect()->back()->with([
            'success' => 'Opmerking is toegevoegd.',
        ]);
    }
}
```

Note: `attach()` on a `wherePivot`-scoped relationship still inserts the provided pivot data — the scope only affects SELECT queries.

- [ ] **Step 6: Smoke-test manually**

Open a ServiceOrder, post a remark. Verify the `remarkables` row has `internal = 0`. Check via:

```bash
php artisan tinker --execute="echo App\Models\ServiceOrder::first()->remarks()->getQuery()->toSql();"
```

Expected output contains `where "internal" = 0` (or equivalent binding).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_24_100001_add_internal_to_remarkables_table.php \
        app/Models/Traits/RemarkableTrait.php \
        app/Http/Requests/RemarkCreateRequest.php \
        app/Http/Controllers/RemarkController.php
git commit -m "feat(Remarks) Add internal flag to remarkables pivot and split RemarkableTrait"
```

---

## Task 2: Wire internal remarks into ServiceOrder show page + RemarksComponent

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php`
- Modify: `resources/js/Components/RemarksComponent.vue`
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

**Interfaces:**
- Consumes: `RemarkableTrait::internalRemarks()` from Task 1.
- Produces: `serviceOrder.internal_remarks` available on the Inertia page; `RemarksComponent` accepts `internal` prop.

- [ ] **Step 1: Add `internalRemarks.user` eager load in ServiceOrderController::show**

In `app/Http/Controllers/ServiceOrderController.php`, in the `show` method's `with([...])` array, find the line `'remarks.user',` and add the line below it:

```php
'remarks.user',
'internalRemarks.user',
```

- [ ] **Step 2: Add `internal` prop to RemarksComponent**

In `resources/js/Components/RemarksComponent.vue`, update the `defineProps` block and add `internal` to the `useForm` data:

Replace:
```js
const { comments, remarkableType, remarkableId, disabled } = defineProps({
    comments: Array,
    remarkableType: String,
    remarkableId: Number,
    disabled: {
        type: Boolean,
        default: false
    }
})

const page = usePage();
const form = useForm({
    content: '',
    user_id: page.props.auth.user.id,
    remarkable_type: remarkableType,
    remarkable_id: remarkableId
})
```

With:
```js
const { comments, remarkableType, remarkableId, disabled, internal } = defineProps({
    comments: Array,
    remarkableType: String,
    remarkableId: Number,
    disabled: {
        type: Boolean,
        default: false
    },
    internal: {
        type: Boolean,
        default: false
    }
})

const page = usePage();
const form = useForm({
    content: '',
    user_id: page.props.auth.user.id,
    remarkable_type: remarkableType,
    remarkable_id: remarkableId,
    internal: internal
})
```

- [ ] **Step 3: Add the internal remarks widget to ShowPage**

In `resources/js/Pages/ServiceOrders/ShowPage.vue`, after the existing remarks `</BoxComponent>` (around line 300), add:

```html
<BoxComponent class="mt-6"
    v-if="!serviceOrder.is_closed || (serviceOrder.is_closed && serviceOrder.internal_remarks.length > 0)">
    <div class="flex items-center gap-x-2 mb-4">
        <span class="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded">Intern</span>
    </div>
    <RemarksComponent :remarkable-type="'App\\Models\\ServiceOrder'"
        :disabled="serviceOrder.is_closed" :remarkable-id="serviceOrder.id"
        :comments="serviceOrder.internal_remarks" :internal="true" />
</BoxComponent>
```

- [ ] **Step 4: Verify in the browser**

Run `npm run dev` and open a ServiceOrder. Verify:
- The public remarks widget shows existing remarks.
- The internal remarks widget appears below it with the amber "Intern" badge.
- Posting a remark from the internal widget stores `internal = 1` in `remarkables`.
- The two widgets are independently populated.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceOrderController.php \
        resources/js/Components/RemarksComponent.vue \
        resources/js/Pages/ServiceOrders/ShowPage.vue
git commit -m "feat(Remarks) Show internal remarks widget on ServiceOrder page"
```

---

## Task 3: Add public remarks section to the ServiceOrder PDF

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php`
- Modify: `resources/views/pdf/serviceorder.blade.php`

**Interfaces:**
- Consumes: `ServiceOrder::remarks()` scoped to non-internal from Task 1.
- Produces: `$remarks` collection available in the PDF Blade view.

- [ ] **Step 1: Load and pass remarks in generateServiceOrderPdf**

In `app/Http/Controllers/ServiceOrderController.php`, in `generateServiceOrderPdf()`:

1. Add `'remarks.user'` to the `$serviceorder->load([...])` call (around line 602):

```php
$serviceorder->load([
    'customer',
    'project',
    'events',
    'events.executingUsers',
    'events.executions',
    'serviceJobs.asset.product.brand',
    'serviceJobs.asset.product.productType',
    'tickets.asset.product.brand',
    'tickets.asset.product.productType',
    'materials.usageUnit',
    'freeformMaterials',
    'taskInstances.serviceOrderTask',
    'taskInstances.assets',
    'images',
    'events',
    'project',
    'remarks.user',
]);
```

2. Add `'remarks' => $serviceorder->remarks,` to the `Pdf::loadView(...)` data array (after `'closingText'`):

```php
$pdf = Pdf::loadView('pdf.serviceorder', [
    'serviceOrder' => $serviceorder,
    'logo' => $logo,
    'descriptionText' => $description_text,
    'plannedDate' => $planned_date,
    'executionLocation' => $execution_location,
    'executingUsers' => $executing_users,
    'tickets' => $serviceorder->tickets,
    'jobs' => $serviceorder->serviceJobs,
    'materialsList' => $materials_list,
    'extraMaterialsList' => $extra_materials_list,
    'taskInstances' => $serviceorder->taskInstances,
    'images' => $serviceorder->images->map(function ($image) {
        $path = storage_path('app/public/' . $image->path);
        if (! file_exists($path)) {
            return null;
        }
        $mime = mime_content_type($path);
        [$width, $height] = @getimagesize($path) ?: [1, 1];

        return [
            'name' => $image->name,
            'data' => 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path)),
            'landscape' => ($width ?? 1) >= ($height ?? 1),
        ];
    })->filter()->values(),
    'company' => $company,
    'closingText' => trim((string) GeneralSetting::get('serviceorder_closing_text', '')),
    'remarks' => $serviceorder->remarks,
])->setPaper('a4');
```

- [ ] **Step 2: Add remarks section to the PDF Blade template**

In `resources/views/pdf/serviceorder.blade.php`, add the following block after the `@endif` that closes the `$taskInstances` section (around line 378) and before the `@if (($images ?? collect())->isNotEmpty())` block:

```blade
@if (($remarks ?? collect())->isNotEmpty())
    <h2 class="section">Opmerkingen</h2>
    <table class="table small compact">
        <thead>
            <tr>
                <th style="width:20%">Datum</th>
                <th style="width:20%">Door</th>
                <th>Opmerking</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($remarks as $remark)
                <tr>
                    <td>{{ $remark->created_at->format('d-m-Y H:i') }}</td>
                    <td>{{ $remark->user->name ?? '—' }}</td>
                    <td>{{ $remark->content }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
```

- [ ] **Step 3: Generate a PDF and verify**

Open a ServiceOrder that has at least one public remark and at least one internal remark. Export the PDF. Verify:
- The "Opmerkingen" section appears and contains only the public remark.
- The internal remark does not appear anywhere in the PDF.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/ServiceOrderController.php \
        resources/views/pdf/serviceorder.blade.php
git commit -m "feat(Remarks) Add public remarks section to ServiceOrder PDF"
```

---

## Task 4: Add `internal` to imageables pivot + update ServiceOrder image relationships + ImageController

**Files:**
- Create: `database/migrations/2026_06_24_100002_add_internal_to_imageables_table.php`
- Modify: `app/Models/ServiceOrder.php`
- Modify: `app/Http/Requests/ImageStoreRequest.php`
- Modify: `app/Http/Controllers/ImageController.php`

**Interfaces:**
- Produces: `ServiceOrder::images()` scoped to non-internal; `ServiceOrder::internalImages()` scoped to internal. `ImageController` sets the `internal` flag on every new attach.

**Important:** `ImageController::destroy` currently calls `$imageable_record->images()->detach($image->id)`. Because `detach()` in Laravel applies `wherePivot` constraints, this would silently fail to remove the pivot row for an internal image. It must be replaced with a direct `DB::table` delete instead.

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imageables', function (Blueprint $table) {
            $table->boolean('internal')->default(false)->after('main');
        });
    }

    public function down(): void
    {
        Schema::table('imageables', function (Blueprint $table) {
            $table->dropColumn('internal');
        });
    }
};
```

Save to `database/migrations/2026_06_24_100002_add_internal_to_imageables_table.php`.

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrated: 2026_06_24_100002_add_internal_to_imageables_table`.

- [ ] **Step 3: Update ServiceOrder::images() and add internalImages()**

In `app/Models/ServiceOrder.php`, replace:

```php
public function images()
{
    return $this->morphToMany(Image::class, 'imageable')
        ->withPivot(['main'])
        ->withTimestamps();
}
```

With:

```php
public function images()
{
    return $this->morphToMany(Image::class, 'imageable')
        ->withPivot(['main', 'internal'])
        ->wherePivot('internal', false)
        ->withTimestamps();
}

public function internalImages()
{
    return $this->morphToMany(Image::class, 'imageable')
        ->withPivot(['main', 'internal'])
        ->wherePivot('internal', true)
        ->withTimestamps();
}
```

- [ ] **Step 4: Add `internal` to ImageStoreRequest rules**

In `app/Http/Requests/ImageStoreRequest.php`, add `'internal' => 'nullable|boolean'` to the `rules()` return array:

```php
public function rules(): array
{
    return [
        'images.*'       => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        'imageable_id'   => 'required|integer',
        'imageable_type' => 'required|string',
        'titles'         => 'array',
        'internal'       => 'nullable|boolean',
    ];
}
```

- [ ] **Step 5: Pass `internal` pivot data in ImageController::store**

In `app/Http/Controllers/ImageController.php`, in the `store` method, replace:

```php
$imageable_record->images()->attach($new_image->id);
```

With:

```php
$imageable_record->images()->attach($new_image->id, [
    'internal' => $request->boolean('internal', false),
]);
```

- [ ] **Step 6: Fix ImageController::destroy to use raw DB delete**

The `destroy` method currently calls `$imageable_record->images()->detach($image->id)`. Because `images()` now has `wherePivot('internal', false)`, calling `detach()` on that scoped relationship would include the `WHERE internal = false` clause, silently failing to remove an internal image's pivot row. Replace the detach call with a direct DB delete.

In `app/Http/Controllers/ImageController.php`, add `use Illuminate\Support\Facades\DB;` to the imports (already present — check before adding).

Replace in the `destroy` method:

```php
$imageable_record->images()->detach($image->id);
```

With:

```php
DB::table('imageables')
    ->where('imageable_type', (new ($request->imageable_type))->getMorphClass())
    ->where('imageable_id', (int) $request->imageable_id)
    ->where('image_id', $image->id)
    ->delete();
```

- [ ] **Step 7: Pass `internal: false` in ImageController::importFromUrl**

Imported images are always non-internal. In `importFromUrl` (around line 251), replace:

```php
$imageable_record->images()->attach($new_image->id);
```

With:

```php
$imageable_record->images()->attach($new_image->id, ['internal' => false]);
```

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_24_100002_add_internal_to_imageables_table.php \
        app/Models/ServiceOrder.php \
        app/Http/Requests/ImageStoreRequest.php \
        app/Http/Controllers/ImageController.php
git commit -m "feat(Images) Add internal flag to imageables pivot; scope ServiceOrder relationships"
```

---

## Task 5: Wire internal images into ServiceOrder show page + ImageUploadComponent

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php`
- Modify: `resources/js/Components/ImageUploadComponent.vue`
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

**Interfaces:**
- Consumes: `ServiceOrder::internalImages()` from Task 4.
- Produces: `serviceOrder.internal_images` available on the Inertia page; `ImageUploadComponent` accepts `internal` prop.

- [ ] **Step 1: Add `internalImages` eager load in ServiceOrderController::show**

In the `show` method's `with([...])` array, find `'images',` and add below it:

```php
'images',
'internalImages',
```

- [ ] **Step 2: Add `internal` prop to ImageUploadComponent**

In `resources/js/Components/ImageUploadComponent.vue`, in the `defineProps` block add:

```js
const props = defineProps({
    imageableId: Number,
    imageableType: String,
    existing: Array,
    internal: {
        type: Boolean,
        default: false
    },
});
```

Then in `uploadImagesForm`, add `internal: props.internal`:

```js
const uploadImagesForm = useForm({
    images: [],
    imageable_id: props.imageableId,
    imageable_type: props.imageableType,
    titles: {},
    imageToUpdate: null,
    newTitle: '',
    internal: props.internal,
});
```

- [ ] **Step 3: Add the internal images widget to ShowPage**

In `resources/js/Pages/ServiceOrders/ShowPage.vue`, after the existing `</BoxComponent>` that wraps `ImageUploadComponent` (around line 306), add:

```html
<BoxComponent class="mt-6">
    <div class="flex items-center gap-x-2 mb-4">
        <span class="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded">Intern</span>
        <span class="text-sm font-semibold text-gray-700 dark:text-slate-200">Interne foto's</span>
    </div>
    <ImageUploadComponent :existing="serviceOrder.internal_images" :imageable-id="serviceOrder.id"
        imageable-type="App\Models\ServiceOrder" :internal="true" />
</BoxComponent>
```

- [ ] **Step 4: Verify in the browser**

Open a ServiceOrder. Verify:
- The public images widget shows only non-internal images.
- The internal images widget appears below with the amber "Intern" badge.
- Uploading to the internal widget stores `internal = 1` in `imageables`.
- Deleting an internal image works (no DB error).
- The `setMain` star still works on public images.

- [ ] **Step 5: Confirm PDF excludes internal images**

Export a PDF for a ServiceOrder that has both public and internal images. Verify only public images appear in the "Foto's" section.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ServiceOrderController.php \
        resources/js/Components/ImageUploadComponent.vue \
        resources/js/Pages/ServiceOrders/ShowPage.vue
git commit -m "feat(Images) Show internal images widget on ServiceOrder page"
```

---

## Task 6: Add `internal` to documentables pivot + update ServiceOrder document relationships + DocumentController

**Files:**
- Create: `database/migrations/2026_06_24_100003_add_internal_to_documentables_table.php`
- Modify: `app/Models/ServiceOrder.php`
- Modify: `app/Http/Requests/DocumentStoreRequest.php`
- Modify: `app/Http/Controllers/DocumentController.php`

**Interfaces:**
- Produces: `ServiceOrder::documents()` scoped to non-internal; `ServiceOrder::internalDocuments()` scoped to internal. `DocumentController` sets the `internal` flag on every new attach.

**Note on destroy:** `DocumentController::destroy` calls `$document->delete()`. The `documentables` FK has `->cascadeOnDelete()`, so the pivot row is removed automatically via the database cascade — no changes needed to `destroy`.

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentables', function (Blueprint $table) {
            $table->boolean('internal')->default(false)->after('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('documentables', function (Blueprint $table) {
            $table->dropColumn('internal');
        });
    }
};
```

Save to `database/migrations/2026_06_24_100003_add_internal_to_documentables_table.php`.

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrated: 2026_06_24_100003_add_internal_to_documentables_table`.

- [ ] **Step 3: Update ServiceOrder::documents() and add internalDocuments()**

In `app/Models/ServiceOrder.php`, replace:

```php
public function documents()
{
    return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
}
```

With:

```php
public function documents()
{
    return $this->morphToMany(Document::class, 'documentable')
        ->withPivot('internal')
        ->wherePivot('internal', false)
        ->withTimestamps();
}

public function internalDocuments()
{
    return $this->morphToMany(Document::class, 'documentable')
        ->withPivot('internal')
        ->wherePivot('internal', true)
        ->withTimestamps();
}
```

- [ ] **Step 4: Add `internal` to DocumentStoreRequest rules**

In `app/Http/Requests/DocumentStoreRequest.php`, add `'internal' => 'nullable|boolean'` to the `rules()` return array:

```php
public function rules(): array
{
    return [
        'documents.*' => 'required|file|mimes:pdf,odt,odf,doc,docx,xls,xlsx,ppt,pptx,txt|max:20480',
        'documentable_id' => 'required|integer',
        'documentable_type' => 'required|string',
        'internal' => 'nullable|boolean',
    ];
}
```

- [ ] **Step 5: Pass `internal` pivot data in DocumentController::store**

In `app/Http/Controllers/DocumentController.php`, replace:

```php
$documentable_record->documents()->attach($document->id);
```

With:

```php
$documentable_record->documents()->attach($document->id, [
    'internal' => $request->boolean('internal', false),
]);
```

Also add `use Illuminate\Http\Request;` is already part of the FormRequest — `$request->boolean()` is available on `DocumentStoreRequest` which extends `FormRequest`. No import change needed.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_24_100003_add_internal_to_documentables_table.php \
        app/Models/ServiceOrder.php \
        app/Http/Requests/DocumentStoreRequest.php \
        app/Http/Controllers/DocumentController.php
git commit -m "feat(Documents) Add internal flag to documentables pivot; scope ServiceOrder relationships"
```

---

## Task 7: Wire internal documents into ServiceOrder show page + DocumentUploadComponent

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php`
- Modify: `resources/js/Components/DocumentUploadComponent.vue`
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

**Interfaces:**
- Consumes: `ServiceOrder::internalDocuments()` from Task 6.
- Produces: `serviceOrder.internal_documents` available on the Inertia page; `DocumentUploadComponent` accepts `internal` prop.

- [ ] **Step 1: Add `internalDocuments` eager load in ServiceOrderController::show**

In the `show` method's `with([...])` array, find `'documents',` and add below it:

```php
'documents',
'internalDocuments',
```

- [ ] **Step 2: Add `internal` prop to DocumentUploadComponent**

In `resources/js/Components/DocumentUploadComponent.vue`, add `internal` to the `defineProps` block:

```js
const props = defineProps({
    documentableId: {
        type: Number,
        required: true,
    },
    documentableType: {
        type: String,
        required: true,
    },
    existing: {
        type: Array,
        default: () => [],
    },
    internal: {
        type: Boolean,
        default: false,
    },
});
```

Add `internal` to the `uploadForm`:

```js
const uploadForm = useForm({
    documents: [],
    documentable_id: props.documentableId,
    documentable_type: props.documentableType,
    internal: props.internal,
});
```

- [ ] **Step 3: Add the internal documents widget to ShowPage**

In `resources/js/Pages/ServiceOrders/ShowPage.vue`, after the existing `DocumentUploadComponent` line (around line 302), add:

```html
<div class="mt-6">
    <div class="flex items-center gap-x-2 mb-2 px-1">
        <span class="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded">Intern</span>
    </div>
    <DocumentUploadComponent :existing="serviceOrder.internal_documents" :documentable-id="serviceOrder.id"
        documentable-type="\App\Models\ServiceOrder" :internal="true" />
</div>
```

- [ ] **Step 4: Verify in the browser**

Open a ServiceOrder. Verify:
- The public documents widget shows only non-internal documents.
- The internal documents widget appears below with the amber "Intern" badge.
- Uploading to the internal widget stores `internal = 1` in `documentables`.
- Deleting a document works for both public and internal documents.
- Internal documents do NOT appear on the generated PDF (documents were never in the PDF to begin with — confirm no regression).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceOrderController.php \
        resources/js/Components/DocumentUploadComponent.vue \
        resources/js/Pages/ServiceOrders/ShowPage.vue
git commit -m "feat(Documents) Show internal documents widget on ServiceOrder page"
```
