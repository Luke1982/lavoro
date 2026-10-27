# Spec: Create service order on the fly from the planner event modal

**Date:** 2026-06-06

## Problem

When creating a new event in the planner, users must first navigate away to create a service order before they can link it. There is no way to create a bare service order inline.

## Goal

Add a checkbox in the "Nieuwe afspraak" modal that, when checked, creates a new service order (for the selected customer) atomically with the event. All normal service order creation procedures must be respected (first-stage auto-assignment via the model boot hook).

## Scope

- New-event flow only (`editingExisting === false`). No change to the edit flow.
- The new service order is created bare: only `customer_id`. All other SO fields are filled in later on the SO's own page.

---

## Backend

### `EventStoreRequest` — two new rules

```
'create_service_order' => 'nullable|boolean',
'customer_id'          => 'required_if:create_service_order,true|nullable|exists:customers,id',
```

`eventable_id` stays `nullable` (unchanged). When `create_service_order` is true the controller sets it; the request doesn't need to validate its presence.

### `EventApiController::store()` — SO creation before event creation

Before the `Event::create($data)` call, add:

```php
if ($request->boolean('create_service_order')) {
    $service_order = ServiceOrder::create([
        'customer_id' => $request->input('customer_id'),
    ]);
    // The ServiceOrder booted() hook assigns the first stage automatically.
    $data['eventable_type'] = '\\App\\Models\\ServiceOrder';
    $data['eventable_id']   = $service_order->id;
}
```

The rest of the `store()` method is unchanged: the event is created, then the SO-event pivot is attached, `advanceToPlannedStage()` is called, and executing users are synced.

---

## Frontend

### `EventEditModal.vue`

**Form state:** add `create_service_order: false` to `useForm(...)`.

**Werkbon section (only when `!editingExisting`):**

Below the existing Werkbon ComboBox, render a checkbox row. When `create_service_order` is true:
- The Werkbon ComboBox is hidden (replaced by a small italic notice: *"Er wordt een nieuwe werkbon aangemaakt voor de geselecteerde klant."*)
- `form.eventable_id` is set to `null` (not empty string — the API middleware does not run `ConvertEmptyStringsToNull`, so an empty string would fail the `nullable|exists` validation rule).

The checkbox is disabled (and unchecked) when no customer is selected (`selectedCustomer` is null/falsy). Rationale: a customer is required to create a SO.

**`save()` payload:** include `create_service_order: form.create_service_order` and `customer_id: selectedCustomer` in the axios POST body. These keys are already harmlessly ignored on the update path, but `create_service_order` is only `true` in the new-event flow so no guard is needed.

---

## Data flow summary

```
User checks checkbox → create_service_order = true
↓
save() → POST /api/events { create_service_order: true, customer_id: X, ... }
↓
EventStoreRequest validates customer_id exists
↓
EventApiController::store():
  ServiceOrder::create({ customer_id: X })   ← booted() assigns first stage
  data.eventable_id = new SO id
  Event::create(data)
  SO.events().attach(event.id)
  SO.advanceToPlannedStage()
  syncExecutingUsers(...)
↓
Response: 201 with event + serviceOrders eager-loaded
↓
Frontend emits 'saved', planner refreshes
```

---

## Out of scope

- Choosing a project for the new SO at creation time.
- Pre-filling SO description from the event description.
- Any change to the edit-event flow.
