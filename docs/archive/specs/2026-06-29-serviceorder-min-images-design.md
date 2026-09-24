---
name: serviceorder-min-images
description: Admin setting for minimum number of public images required before a service order can be closed
metadata:
  type: project
---

# Minimum Images Before Service Order Close

## Overview

A general setting lets an admin configure a minimum number of **public** images that must be attached to a service order before it can be moved to a closed stage. The default is `0`, meaning no minimum is enforced.

## Setting storage

- Key: `serviceorder_min_images`
- Model: `GeneralSetting` (key-value store, stored as string, cast to int at read time)
- Default: `0` (no minimum)

## Backend

### New Form Request

`app/Http/Requests/Admin/UpdateServiceOrderMinImagesRequest.php`

- `authorize()`: `$this->user()->hasPermission('admin')` (same pattern as other admin setting requests)
- `rules()`: `['value' => 'required|integer|min:0']`

### Controller method

New method `updateServiceOrderMinImages` on `App\Http\Controllers\Admin\GeneralSettingsController`:

- Reads `value` from validated request
- Calls `GeneralSetting::set('serviceorder_min_images', $request->validated()['value'])`
- Returns `redirect()->back()->with('success', 'Instellingen opgeslagen.')`

Also update `index()` to pass `serviceOrderMinImages` prop (integer) to the Inertia page.

### Route

```
PUT admin/settings/serviceorder-min-images
→ GeneralSettingsController@updateServiceOrderMinImages
name: admin.settings.serviceorder-min-images
```

### Enforcement

In `ServiceOrderUpdateRequest::withValidator`, after the existing incomplete-tasks check, add:

```
if ($new_stage->is_closed_state) {
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
}
```

`images()` uses the existing `morphToMany` relation filtered to `internal = false` (public images only).

## Frontend (Admin settings page)

Add a new sub-form inside the existing "Werkbon" section of `GeneralSettingsPage.vue`:

- Label: "Minimum aantal foto's"
- Description: "Het minimum aantal publieke foto's dat toegevoegd moet zijn voordat een werkbon gesloten kan worden. Stel in op 0 voor geen minimum."
- Input: `<input type="number" min="0">` bound to `minImagesForm.value`
- Submit button: "Opslaan" → `PUT /admin/settings/serviceorder-min-images`
- Follow the same separate-form pattern as the closing text and override-unavailability settings.

## Error display

The closing-stage validation error surfaces on `form.errors.service_order_stage_id` in the service order show page, same as the incomplete-tasks error. No new UI wiring needed.
