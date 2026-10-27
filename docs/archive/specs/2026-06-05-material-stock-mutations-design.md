# Material Stock Mutations & Timeline

## Overview

When a material is attached to, detached from, or has its quantity updated on a service order, the material's `stock` field is adjusted accordingly. The same activity record that is logged on the service order is also attached to the material (via the existing `activityables` pivot), enabling a stock-mutation timeline on the Material show page.

## Backend: Stock Mutation

Three methods in `ServiceOrderController` are modified:

### `attachMaterial`
After attaching the pivot record, decrement stock:
```php
$material->decrement('stock', $validated['quantity']);
```

### `detachMaterial`
Before deleting the pivot, read its quantity from the `materiables` record (queried by `id`), then restore stock:
```php
$material->increment('stock', $old_quantity);
```

### `updateMateriable`
Read old quantity from the pivot, compute delta, adjust stock:
```php
$delta = $new_quantity - $old_quantity;
$material->decrement('stock', $delta); // negative delta → increment
```

## Backend: Activity Dual-Attach

Each `logActivity` call in the three methods above gains:
- `also_attach_to: [$material]` — inserts the activity into `activityables` for the material as well as the service order.
- `metadata: ['service_order_id' => $serviceorder->id, 'service_order_number' => $serviceorder->number]` — carries the service order reference for the frontend link.

Existing descriptions are unchanged:
- "Materiaal toegevoegd: {name} (aantal {qty})"
- "Materiaal verwijderd: {name}"
- "Materiaal hoeveelheid aangepast: {name} naar {qty}"
- "Materiaal gemarkeerd als onvoorzien/voorzien: {name}"

## Backend: Material Activities Relationship

`Material` model gets an `activities()` morphToMany relationship pointing at the `activityables` pivot — the same pattern as `ServiceOrder`.

`MaterialController::show()` eager-loads `activities` with `user`, ordered `created_at` descending. Activities are passed to the Inertia page as a prop.

## Frontend: TimelineComponent Extension

`TimelineComponent` gets a small addition: when an activity has `metadata.service_order_id`, render a "Werkbon #{number}" link below the description, linking to the service order show route.

This is conditional — activities without that metadata field (e.g. those logged from other contexts) are unaffected.

## Frontend: Material ShowPage

`Materials/ShowPage.vue` receives the `activities` prop and renders a "Tijdlijn" section using the existing `TimelineComponent`. No new component is created.

## Scope

- All materials are tracked (including `is_service = true`).
- All three mutation operations (attach, detach, update quantity) adjust stock and produce a dual-attached activity.
- The `unforseen` toggle does **not** affect stock (it has no quantity change).
- No new database tables or migrations required.
