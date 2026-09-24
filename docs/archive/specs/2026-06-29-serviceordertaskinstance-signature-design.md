# Design: Task Instance Customer Signature

**Date:** 2026-06-29
**Status:** Approved

## Overview

Allow users with the `serviceordertaskinstance.open_close` permission to collect a customer signature on a completed service order task instance. The signature (name + drawn base64 image) is stored on the instance and rendered on the service order PDF.

## Data Layer

### Migration
Add three nullable columns to `service_order_task_instances`:

| Column | Type | Notes |
|---|---|---|
| `signed_by` | `string, nullable` | Customer's name |
| `signature_base64` | `mediumText, nullable` | Base64-encoded JPEG from SignaturePad |
| `signed_at` | `timestamp, nullable` | Set server-side on sign |

### Model (`ServiceOrderTaskInstance`)
- Add `signed_by`, `signature_base64`, `signed_at` to `$fillable`
- Cast `signed_at` as `datetime`

## Backend

### Route
```
POST serviceordertaskinstances/{serviceordertaskinstance}/sign
```
Added alongside the existing `toggle` route in `routes/web.php`.

### Form Request: `ServiceOrderTaskInstanceSignRequest`
- `authorize()`: user has `serviceordertaskinstance.open_close` (mirrors `ServiceOrderTaskInstanceToggleRequest`)
- `rules()`: `signed_by` required string, `signature_base64` required string
- Controller checks `$instance->is_complete`; returns validation error if not complete

### Controller method: `sign()`
- Verifies instance is complete (redirect back with error if not)
- Fills `signed_by`, `signature_base64`, `signed_at = now()`
- Logs activity: `"Taak \"X\" ondertekend door Y"` with category `status`
- Redirect back with success flash
- Re-signing (overwrite) is allowed; no unsign action

## Frontend (`TaskInstancesWidget.vue`)

### Signature button
- `PenLine` Lucide icon button in the action buttons row of each task instance
- Visible when `canToggle` is true AND `instance.is_complete` is true
- Clicking opens the sign modal for that instance

### Sign modal (`ModalDialog`)
- Name text input (label: "Naam"), required
- `SignaturePad.vue` component (existing reusable component)
- Submit button: POSTs to `/serviceordertaskinstances/{id}/sign`
- On success: reactively updates `signed_by`, `signature_base64`, `signed_at` on the instance in `internalInstances`; closes modal; resets form state

### Signed indicator + view modal
- Signed instances (`signed_by` present) show a `BadgeCheck` Lucide icon (green) in the action buttons row — always visible to anyone who can see the task (not gated on permission)
- Clicking opens a read-only `ModalDialog` showing:
  - Name (`signed_by`)
  - Date: `signed_at` formatted `d-m-Y`
  - Time: `signed_at` formatted `H:i`
  - Signature image: `<img :src="instance.signature_base64">`
- Modal has only a close button; no edit actions

## PDF (`resources/views/pdf/serviceorder.blade.php`)

The existing tasks table gains a fourth column **"Ondertekend door"**:
- Header always present
- For signed instances: shows `{{ $instance->signed_by }}` and `{{ $instance->signed_at->format('d-m-Y H:i') }}`, plus `<img src="{{ $instance->signature_base64 }}" style="max-height:60px; max-width:180px; display:block;">`
- For unsigned instances: shows `—`

The `generateServiceOrderPdf` method in `ServiceOrderController` already eager-loads `taskInstances.serviceOrderTask` and `taskInstances.assets`; no additional eager-loading needed for signature fields (they are on the instance itself).

## Permissions

No new permissions. The existing `serviceordertaskinstance.open_close` gates the sign button. The view indicator is ungated (any user who can see the widget sees whether a task is signed).

## Out of Scope

- Unsigning / revoking a signature
- Restricting signing on closed service orders (the `canToggle` computed already respects `isClosed`, which gates the sign button; the view indicator remains visible regardless)
