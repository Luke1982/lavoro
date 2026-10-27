# Service Order Minimum Images Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a general admin setting for a minimum number of public images required before a service order can be moved to a closed stage.

**Architecture:** The setting is stored in the existing `GeneralSetting` key-value table under key `serviceorder_min_images`. Enforcement is added to the `withValidator` hook of `ServiceOrderUpdateRequest`, which already handles closing-stage validation. The admin UI follows the existing pattern of separate sub-forms in `GeneralSettingsPage.vue`.

**Tech Stack:** Laravel 12, Inertia.js, Vue 3, `useForm` from `@inertiajs/vue3`.

## Global Constraints

- Dutch copy throughout (labels, validation messages, descriptions)
- No inline PHP comments; no Vue comments unless the why is non-obvious
- Validation rules in Form Request `rules()` only; frontend only shows `form.errors`
- Admin requests authorize with `$this->user()?->isAdmin() ?? false`
- `GeneralSetting::get(key, default)` returns a string; cast to int where needed
- Follow existing snake_case PHP variable naming

---

### Task 1: Form Request for the new setting

**Files:**
- Create: `app/Http/Requests/Admin/UpdateServiceOrderMinImagesRequest.php`

**Interfaces:**
- Produces: `UpdateServiceOrderMinImagesRequest` with validated field `min_images` (integer ≥ 0)

- [ ] **Step 1: Create the Form Request**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceOrderMinImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'min_images' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

- [ ] **Step 2: Verify the file is syntactically valid**

```bash
php artisan about
```
Expected: No PHP parse errors. Laravel boots cleanly.

---

### Task 2: Controller method + route

**Files:**
- Modify: `app/Http/Controllers/Admin/GeneralSettingsController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `UpdateServiceOrderMinImagesRequest` (validated field `min_images`)
- Produces: `serviceOrderMinImages` integer prop on `Admin/GeneralSettingsPage` Inertia page

- [ ] **Step 1: Add the use-import for the new request in the controller**

Open `app/Http/Controllers/Admin/GeneralSettingsController.php`. After the existing `use` statements, add:

```php
use App\Http\Requests\Admin\UpdateServiceOrderMinImagesRequest;
```

- [ ] **Step 2: Update `index()` to pass the new prop**

In `GeneralSettingsController::index()`, add `serviceOrderMinImages` to the Inertia render array. The full updated method:

```php
public function index(): Response
{
    return Inertia::render('Admin/GeneralSettingsPage', [
        'locationTracking' => [
            'start' => GeneralSetting::get('location_tracking_start', '07:00'),
            'end'   => GeneralSetting::get('location_tracking_end', '18:00'),
            'days'  => array_map(
                'intval',
                explode(',', GeneralSetting::get('location_tracking_days', '1,2,3,4,5'))
            ),
        ],
        'serviceOrderClosingText'     => GeneralSetting::get('serviceorder_closing_text', ''),
        'allowOverrideUnavailability' => GeneralSetting::get('allow_override_unavailability', '0') === '1',
        'serviceOrderMinImages'       => (int) GeneralSetting::get('serviceorder_min_images', 0),
    ]);
}
```

- [ ] **Step 3: Add the new controller method**

Append this method to the `GeneralSettingsController` class, before the closing `}`:

```php
public function updateServiceOrderMinImages(UpdateServiceOrderMinImagesRequest $request): RedirectResponse
{
    GeneralSetting::set('serviceorder_min_images', $request->validated()['min_images']);

    return redirect()->back()->with('success', 'Instellingen opgeslagen.');
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, after the existing `allow-override-unavailability` route (around line 331), add:

```php
Route::put(
    'admin/settings/serviceorder-min-images',
    [GeneralSettingsController::class, 'updateServiceOrderMinImages'],
)->name('admin.settings.serviceorder-min-images');
```

- [ ] **Step 5: Verify routing**

```bash
php artisan route:list --name=admin.settings
```
Expected output includes a `PUT` row for `admin/settings/serviceorder-min-images`.

---

### Task 3: Enforce minimum in ServiceOrderUpdateRequest

**Files:**
- Modify: `app/Http/Requests/ServiceOrderUpdateRequest.php`

**Interfaces:**
- Consumes: `GeneralSetting::get('serviceorder_min_images', 0)` (cast to int), `$serviceorder->images()->count()` (public images via existing `morphToMany` filtered to `internal = false`)

- [ ] **Step 1: Add the GeneralSetting use-import**

At the top of `app/Http/Requests/ServiceOrderUpdateRequest.php`, add:

```php
use App\Models\GeneralSetting;
```

- [ ] **Step 2: Add the image-count check inside `withValidator`**

The current `withValidator` closure ends after the incomplete-task check. Add the image check immediately after it. The full updated closure body:

```php
$validator->after(function ($validator) {
    $new_stage = ServiceOrderStage::find($this->input('service_order_stage_id'));

    if (! $new_stage) {
        return;
    }

    $serviceorder = $this->route('serviceorder');

    if ($new_stage->id === $serviceorder->service_order_stage_id) {
        return;
    }

    if (! $this->user()->can('updateStage', [$serviceorder, $new_stage])) {
        $validator->errors()->add(
            'service_order_stage_id',
            'Je hebt geen toestemming om de werkbon naar deze fase te verplaatsen.'
        );

        return;
    }

    if (! $new_stage->is_closed_state) {
        return;
    }

    $incomplete = $serviceorder->taskInstances()->where('is_complete', false)->count();
    if ($incomplete > 0) {
        $validator->errors()->add(
            'service_order_stage_id',
            "Er zijn nog {$incomplete} taken niet afgerond. Rond alle taken af voordat je de werkbon sluit."
        );
    }

    $min = (int) GeneralSetting::get('serviceorder_min_images', 0);
    if ($min > 0) {
        $count = $serviceorder->images()->count();
        if ($count < $min) {
            $validator->errors()->add(
                'service_order_stage_id',
                "Er zijn minimaal {$min} foto's vereist om de werkbon te sluiten. Er zijn er {$count} toegevoegd."
            );
        }
    }
});
```

- [ ] **Step 3: Verify Laravel boots cleanly**

```bash
php artisan about
```
Expected: No errors.

---

### Task 4: Admin UI — minimum images form

**Files:**
- Modify: `resources/js/Pages/Admin/GeneralSettingsPage.vue`

**Interfaces:**
- Consumes: `serviceOrderMinImages` integer prop from Inertia (passed by `GeneralSettingsController::index()`)
- Produces: PUT to `/admin/settings/serviceorder-min-images` with body `{ min_images: <integer> }`

- [ ] **Step 1: Add the prop**

In the `<script setup>` block, add `serviceOrderMinImages` to the existing `defineProps`:

```js
const props = defineProps({
    locationTracking: { type: Object, required: true },
    serviceOrderClosingText: { type: String, default: '' },
    allowOverrideUnavailability: { type: Boolean, default: false },
    serviceOrderMinImages: { type: Number, default: 0 },
})
```

- [ ] **Step 2: Add the form ref**

After the existing `overrideForm` declaration, add:

```js
const minImagesForm = useForm({
    min_images: props.serviceOrderMinImages,
})

function submitMinImages() {
    minImagesForm.put('/admin/settings/serviceorder-min-images')
}
```

- [ ] **Step 3: Add the UI section**

In the `<template>`, inside the existing "Werkbon" `<section>` (after the closing text form's `</form>` tag, before the closing `</section>`), add:

```html
<div class="mt-6">
    <form @submit.prevent="submitMinImages" class="space-y-5">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Minimum aantal foto's</label>
            <p class="text-xs text-gray-500 mb-2">
                Het minimum aantal publieke foto's dat toegevoegd moet zijn voordat een werkbon gesloten kan worden. Stel in op 0 voor geen minimum.
            </p>
            <input
                type="number"
                min="0"
                v-model.number="minImagesForm.min_images"
                class="rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white w-24"
            />
            <p v-if="minImagesForm.errors.min_images" class="mt-1 text-xs text-red-600">
                {{ minImagesForm.errors.min_images }}
            </p>
        </div>

        <div>
            <button
                type="submit"
                :disabled="minImagesForm.processing"
                class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-lavoro-blue disabled:opacity-50"
            >
                Opslaan
            </button>
        </div>
    </form>
</div>
```

- [ ] **Step 4: Verify frontend compiles**

```bash
npm run build
```
Expected: Build completes with no errors.

- [ ] **Step 5: Manual smoke test**

1. Open `/admin/settings` in the browser.
2. The "Werkbon" section should now show a "Minimum aantal foto's" number input, defaulting to `0`.
3. Set it to `2` and save — page reloads and the field retains `2`.
4. Open a service order that has fewer than 2 public images.
5. Try to move it to a closed stage — expect the error: *"Er zijn minimaal 2 foto's vereist om de werkbon te sluiten. Er zijn er X toegevoegd."*
6. Add 2+ public images to the service order, then move it to a closed stage — it should succeed.
7. Set the minimum back to `0` — closing without any images should succeed again.
