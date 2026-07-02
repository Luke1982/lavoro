---
name: serviceorder-delete
description: Design for adding service order deletion with authorization, relation cleanup, stock restoration, and UI buttons on ShowPage and IndexPage
metadata:
  type: project
---

# ServiceOrder Delete — Design

## Overview

Add the ability to delete service orders. The `serviceorder.delete` permission already exists. The controller's `destroy()` method already exists but has no authorization. This feature wires up authorization, safe relation cleanup, stock restoration with activity logging, and UI buttons on both ShowPage and IndexPage.

## Backend

### Policy

Add `delete(User $user, ServiceOrder $serviceOrder): bool` to `ServiceOrderPolicy`:

```php
return $user->hasPermission('serviceorder.delete') && !$serviceOrder->sent_to_administration;
```

Blocking on `sent_to_administration` is a business rule — it lives in the policy, not the controller.

### Form Request

Create `ServiceOrderDeleteRequest`:
- `authorize()`: `$this->user()->can('delete', $this->route('serviceorder'))`
- `rules()`: `[]`

### Controller

Update `ServiceOrderController::destroy()` to accept `ServiceOrderDeleteRequest`:

1. Load `materials` relationship (with pivot quantities) before deletion.
2. For each material where `pivot->quantity > 0`: increment stock, log activity on the material: `"Voorraad hersteld: +{qty} door verwijdering werkbon #{id}"`.
3. Call `$serviceorder->delete()`.
4. Redirect to `route('serviceorders.index')` with success flash (not `back()` — the page no longer exists after deletion).

### Model — `deleting` event

In `ServiceOrder::booted()`, add a `deleting` listener that cleans up polymorphic pivot rows that have no DB-level cascade (morph side has no FK constraint):

| Table | Morph columns |
|---|---|
| `eventables` | `eventable_type` / `eventable_id` |
| `remarkables` | `remarkable_type` / `remarkable_id` |
| `imageables` | `imageable_type` / `imageable_id` |
| `documentables` | `documentable_type` / `documentable_id` |
| `materiables` | `materiable_type` / `materiable_id` |
| `activityables` | `activityable_type` / `activityable_id` |
| `customfieldables` | `customfieldable_type` / `customfieldable_id` |
| `userables` | `userable_type` / `userable_id` |

Each cleaned up with `DB::table($table)->where("{$morph}_type", ServiceOrder::class)->where("{$morph}_id", $serviceOrder->id)->delete()`.

**Already handled at DB level (no action needed):**
- `service_order_task_instances` → `cascadeOnDelete`
- `service_jobs` → `cascadeOnDelete`
- `freeform_materials` → `cascadeOnDelete`
- `tickets.service_order_id` → `nullOnDelete` (tickets become unlinked, not deleted)
- `assets.service_order_task_instance_id` → `nullOnDelete` (via task instance cascade)

**Note:** Material stock restoration (step 2 in controller) must happen before the `materiables` pivot rows are cleaned up, since the relationship reads from that table.

## Frontend

### ShowPage.vue

Add a "Verwijderen" button in the header action area. Guard: `hasPermission('serviceorder.delete') && !serviceOrder.sent_to_administration`. On click: `confirm('Weet je zeker dat je deze werkbon wilt verwijderen?')`, then `router.delete(\`/serviceorders/${serviceOrder.id}\`)`. After deletion the server redirects to the index.

### IndexPage.vue

Add a per-row delete action alongside existing row controls. Guard: same permission + state check. On click: `confirm(...)`, then `useForm({}).delete(\`/serviceorders/${id}\`, { preserveScroll: true })`.
