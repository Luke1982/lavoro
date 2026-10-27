# Internal Remarks on ServiceOrders

**Date:** 2026-06-24

## Overview

Add an `internal` boolean to the `remarkables` pivot so each remark attachment can be flagged as internal or public. On the ServiceOrder detail page, show a second remarks widget for internal remarks. On the PDF, render only the non-internal (public) remarks.

Permissions are unchanged: both remark types are accessible to anyone already authenticated, consistent with the current `RemarkCreateRequest::authorize()` returning `true`.

---

## Database

**New migration** adds a `boolean('internal')->default(false)` column to the `remarkables` table. Existing rows default to `false` (public), so existing remark data is unaffected.

---

## Backend

### `RemarkableTrait`

Split the single `remarks()` relationship into two scoped variants:

- `remarks()` — `morphToMany` with `->withPivot('internal')->wherePivot('internal', false)`. Existing consumers are unaffected.
- `internalRemarks()` — same base, but `->wherePivot('internal', true)`.

### `RemarkCreateRequest`

Add `internal` as an optional boolean field (nullable, validated as `boolean`).

### `RemarkController::store`

Separate remark creation from pivot attachment so the pivot `internal` flag can be set:

```php
$remark = Remark::create(['content' => $request->content, 'user_id' => Auth::id()]);
$remarkable->remarks()->attach($remark->id, ['internal' => $request->boolean('internal', false)]);
```

`destroy` is unchanged.

### `ServiceOrderController::show`

Add `'internalRemarks.user'` to the eager-load list alongside the existing `'remarks.user'`.

### `ServiceOrderController` — PDF export method

Pass `$serviceOrder->remarks` (already filtered to non-internal by the scoped relationship) to the Blade view as `$remarks`.

---

## Frontend

### `RemarksComponent.vue`

Add an `internal` prop (type `Boolean`, default `false`). Include it as `internal: internal` in the `useForm` data so it is submitted to the controller.

The component UI is otherwise unchanged; only the posted payload gains the `internal` field.

### `ServiceOrders/ShowPage.vue`

Add a second `<RemarksComponent>` instance directly below the existing one, inside its own `<BoxComponent>`:

- `comments` bound to `serviceOrder.internal_remarks`
- `internal` set to `true`
- Same `disabled`/`v-if` logic as the public widget
- The section header inside the component stays "Opmerkingen"; the wrapping box will carry a visible label "Interne opmerkingen" added in `ShowPage` as a heading above the component.

Also ensure `serviceOrder.internal_remarks` is available in the Inertia response (comes automatically once the eager-load is added).

---

## PDF (`resources/views/pdf/serviceorder.blade.php`)

Add an "Opmerkingen" section (after materials/before the signature block) that iterates over `$remarks` — the collection passed from the PDF controller. If `$remarks` is empty or absent, the section is hidden. Each remark renders: date, author name, and content.

```html
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

---

## Scope boundaries

- No new permissions.
- No changes to other remark-capable models (Customer, Project, etc.); they continue to use `remarks()` which now silently filters to non-internal — their UIs don't create internal remarks so this is a no-op for them.
- The `remarkables` destroy route is unchanged; deleting a remark deletes the `Remark` row (cascade removes the pivot row).
