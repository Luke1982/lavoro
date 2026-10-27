# ServiceOrder Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add service order deletion with authorization, safe relation cleanup, material stock restoration with activity logging, and delete buttons on both ShowPage and IndexPage.

**Architecture:** A `ServiceOrderDeleteRequest` handles policy-based authorization (permission + sent_to_administration guard). The `ServiceOrder` model's `deleting` event handles polymorphic pivot cleanup before the DB-level cascades fire. Material stock is restored and logged on the material before deletion.

**Tech Stack:** Laravel 12, Inertia, Vue 3, Heroicons/Lucide

## Global Constraints

- PHP: snake_case for all variable names.
- No inline comments.
- Authorization via Form Request `authorize()` calling policy `can()` — never `hasPermission()` directly in Form Requests.
- Validation belongs in Form Request `rules()` only.
- Confirmation on destructive UI actions uses `confirm()`.
- Dutch language for all user-facing strings.

---

### Task 1: Policy + Form Request + Controller authorization

**Files:**
- Modify: `app/Policies/ServiceOrderPolicy.php`
- Create: `app/Http/Requests/ServiceOrderDeleteRequest.php`
- Modify: `app/Http/Controllers/ServiceOrderController.php`

**Interfaces:**
- Produces: `ServiceOrderPolicy::delete(User, ServiceOrder): bool` — used by the Form Request
- Produces: `ServiceOrderDeleteRequest` — injected into `destroy()`
- Produces: `destroy()` returns redirect to `serviceorders.index` with success flash

- [ ] **Step 1: Add `delete` method to `ServiceOrderPolicy`**

In `app/Policies/ServiceOrderPolicy.php`, add after the last method:

```php
public function delete(User $user, ServiceOrder $serviceOrder): bool
{
    return $user->hasPermission('serviceorder.delete')
        && ! $serviceOrder->sent_to_administration;
}
```

- [ ] **Step 2: Create `ServiceOrderDeleteRequest`**

Create `app/Http/Requests/ServiceOrderDeleteRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service_order = $this->route('serviceorder');

        return $this->user()->can('delete', $service_order);
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 3: Update `destroy()` in `ServiceOrderController`**

Add `ServiceOrderDeleteRequest` to the imports at the top of `app/Http/Controllers/ServiceOrderController.php`:

```php
use App\Http\Requests\ServiceOrderDeleteRequest;
```

Replace the existing `destroy` method (currently has no authorization):

```php
public function destroy(ServiceOrderDeleteRequest $request, ServiceOrder $serviceorder)
{
    $serviceorder->load('materials');

    foreach ($serviceorder->materials as $material) {
        $quantity = (float) ($material->pivot->quantity ?? 0);
        if ($quantity > 0) {
            $material->increment('stock', $quantity);
            $material->logActivity(
                "Voorraad hersteld: +{$quantity} door verwijdering werkbon #{$serviceorder->id}"
            );
        }
    }

    $serviceorder->delete();

    return redirect()->route('serviceorders.index')
        ->with('success', 'Werkbon succesvol verwijderd.');
}
```

- [ ] **Step 4: Verify authorization works manually**

Run the app (`composer run dev`) and attempt to DELETE `/serviceorders/{id}` as a user without the permission — expect a 403. Attempt as a user with the permission on an order with `sent_to_administration = true` — expect 403. Attempt as a user with the permission on a normal order — expect redirect to index with flash.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/ServiceOrderPolicy.php \
        app/Http/Requests/ServiceOrderDeleteRequest.php \
        app/Http/Controllers/ServiceOrderController.php
git commit -m "feat(ServiceOrder): add delete policy, form request, and authorized destroy"
```

---

### Task 2: Model `deleting` event — polymorphic pivot cleanup

**Files:**
- Modify: `app/Models/ServiceOrder.php`

**Interfaces:**
- Consumes: `ServiceOrderDeleteRequest` and `destroy()` from Task 1 — the model event fires automatically when `$serviceorder->delete()` is called
- Produces: All polymorphic pivot rows for the service order are removed before the model record is deleted

- [ ] **Step 1: Add DB import to `ServiceOrder`**

At the top of `app/Models/ServiceOrder.php`, add to the use-imports:

```php
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 2: Add `deleting` listener in `booted()`**

In `ServiceOrder::booted()`, after the existing `creating` listener, add:

```php
static::deleting(function (ServiceOrder $service_order) {
    $id = $service_order->id;
    $morph_class = ServiceOrder::class;

    foreach ([
        'eventables'      => 'eventable',
        'remarkables'     => 'remarkable',
        'imageables'      => 'imageable',
        'documentables'   => 'documentable',
        'materiables'     => 'materiable',
        'activityables'   => 'activityable',
        'customfieldables' => 'customfieldable',
        'userables'       => 'userable',
    ] as $table => $morph) {
        DB::table($table)
            ->where("{$morph}_type", $morph_class)
            ->where("{$morph}_id", $id)
            ->delete();
    }
});
```

Note: `materials` (stock restoration) is handled in the controller before deletion. The `materiables` pivot rows are cleaned up here after stock has already been restored, since `delete()` fires this event synchronously before the DB row is removed.

- [ ] **Step 3: Verify pivot cleanup**

Using Tinker or a test database, attach an image, a remark, and an event to a service order, then delete it. Confirm the rows in `imageables`, `remarkables`, and `eventables` pointing to that service order are gone, but the `images`, `remarks`, and `events` records themselves remain:

```bash
php artisan tinker
# $so = \App\Models\ServiceOrder::find(ID);
# $so->images()->count(); // > 0
# $so->delete();
# DB::table('imageables')->where('imageable_type', \App\Models\ServiceOrder::class)->where('imageable_id', ID)->count(); // 0
# \App\Models\Image::find(IMAGE_ID); // still exists
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/ServiceOrder.php
git commit -m "feat(ServiceOrder): clean up polymorphic pivot rows on deletion"
```

---

### Task 3: ShowPage delete button

**Files:**
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

**Interfaces:**
- Consumes: `serviceOrder.sent_to_administration` (boolean, already in page props)
- Consumes: `hasPermission('serviceorder.delete')` from Utilities
- Produces: "Verwijderen" button in header area; on confirm sends DELETE to `/serviceorders/{id}`; server redirects to index

- [ ] **Step 1: Add `router` to the Inertia import**

In `resources/js/Pages/ServiceOrders/ShowPage.vue`, find the line:

```js
import { Link, useForm, usePage } from '@inertiajs/vue3';
```

Change it to:

```js
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
```

- [ ] **Step 2: Add `deleteServiceOrder` function in `<script setup>`**

Find the `<script setup>` block. Add this function near the other action functions (e.g., near `sendToSnelStart`):

```js
const deleteServiceOrder = () => {
    if (confirm('Weet je zeker dat je deze werkbon wilt verwijderen? Dit kan niet ongedaan worden gemaakt.')) {
        router.delete(`/serviceorders/${props.serviceOrder.id}`)
    }
}
```

- [ ] **Step 3: Add delete button in the template header**

In the template, find the first `<div>` that wraps the breadcrumb and type selector (lines 1–15). Add the delete button inside that flex row, after the type selector div:

```html
<button
    v-if="hasPermission('serviceorder.delete') && !serviceOrder.sent_to_administration"
    @click="deleteServiceOrder"
    class="px-3 py-1.5 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded cursor-pointer">
    Verwijderen
</button>
```

- [ ] **Step 4: Verify in browser**

Start the dev server. Open a service order that has NOT been sent to administration, as a user with `serviceorder.delete`. Confirm the button appears. Click it, confirm the dialog, confirm redirect to index with success flash. Open a service order with `sent_to_administration = true` — confirm button is absent.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ServiceOrders/ShowPage.vue
git commit -m "feat(ServiceOrders): add delete button to ShowPage"
```

---

### Task 4: IndexPage delete button

**Files:**
- Modify: `resources/js/Pages/ServiceOrders/IndexPage.vue`

**Interfaces:**
- Consumes: `so.sent_to_administration` (boolean, serialized with paginated model)
- Consumes: `hasPermission('serviceorder.delete')` from Utilities
- Produces: TrashIcon button per row in the actions column; on confirm sends DELETE; refreshes current page in place

- [ ] **Step 1: Add `TrashIcon` import and `hasPermission` import**

In `resources/js/Pages/ServiceOrders/IndexPage.vue`, find:

```js
import { EyeIcon, RotateCcwIcon } from '@lucide/vue'
import { nlDate, serviceOrderPillText, serviceOrderSentState } from '@/Utilities/Utilities'
```

Change to:

```js
import { EyeIcon, RotateCcwIcon, TrashIcon } from '@lucide/vue'
import { nlDate, hasPermission, serviceOrderPillText, serviceOrderSentState } from '@/Utilities/Utilities'
```

- [ ] **Step 2: Add `deleteServiceOrder` function**

In the `<script setup>` block, add this function near the other action functions (e.g., after `updateInvoiceNo`):

```js
const deleteServiceOrder = (id) => {
    if (confirm('Weet je zeker dat je deze werkbon wilt verwijderen? Dit kan niet ongedaan worden gemaakt.')) {
        useForm({}).delete(`/serviceorders/${id}`, { preserveScroll: true })
    }
}
```

- [ ] **Step 3: Add delete button to the row actions column**

Find the actions column in the row template (currently contains the EyeIcon link, around line 105–111):

```html
<div class="col-span-2 sm:col-span-1 items-center flex justify-end">
    <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex">
        <Link :href="`/serviceorders/${so.id}`" class="text-sm text-lavoro-darkerblue">
            <EyeIcon class="h-5 w-5" />
        </Link>
    </div>
</div>
```

Replace with:

```html
<div class="col-span-2 sm:col-span-1 items-center flex justify-end gap-2">
    <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex">
        <Link :href="`/serviceorders/${so.id}`" class="text-sm text-lavoro-darkerblue">
            <EyeIcon class="h-5 w-5" />
        </Link>
    </div>
    <div
        v-if="hasPermission('serviceorder.delete') && !so.sent_to_administration"
        class="border-1 border-lavoro-darkergray rounded-full p-2 flex cursor-pointer text-red-500 hover:text-red-700"
        @click="deleteServiceOrder(so.id)">
        <TrashIcon class="h-5 w-5" />
    </div>
</div>
```

- [ ] **Step 4: Verify in browser**

On the index page as a user with `serviceorder.delete`, confirm the TrashIcon appears for orders not sent to administration, and is absent for orders with `sent_to_administration = true`. Click the icon, confirm the dialog, confirm the row disappears and the page refreshes in place.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ServiceOrders/IndexPage.vue
git commit -m "feat(ServiceOrders): add per-row delete button to IndexPage"
```
