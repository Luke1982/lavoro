# Serviceorder "needs closing" filter

## Problem

On the serviceorder index, users can filter by stage but have no quick way to spot orders whose
work is effectively done — all their (non-cancelled) events have already happened — but that
haven't been moved to a closed stage yet. This design adds a toggle for that.

## Filter semantics

A serviceorder matches the "needs closing" filter when all of the following hold:

1. It has at least one **non-cancelled** event.
2. None of its non-cancelled events have an `end` time in the future (i.e. all of them have
   already finished).
3. Its current `ServiceOrderStage.is_closed_state` is not `true` (no stage also counts as "not
   closed").

Cancelled events (`status = Geannuleerd`) are ignored entirely — both when checking "has events"
and when checking "all events are in the past". A serviceorder whose only events are cancelled
does not match (condition 1 fails).

This is independent of, and combines with (AND), the existing stage filter and search.

## Backend

`app/Http/Requests/ServiceOrderIndexRequest.php`:
- Add `'onlyNeedsClosing' => ['sometimes', 'nullable', 'boolean']` to `rules()`.

`app/Http/Controllers/ServiceOrderController.php` (`index`):
- Inline query addition (matching the existing `only_stages` inline style — no new model scope):

```php
if ($request->boolean('onlyNeedsClosing')) {
    $query->whereHas('events', fn ($q) => $q->where('status', '!=', EventStatusses::cancelled->value))
        ->whereDoesntHave('events', fn ($q) => $q->where('status', '!=', EventStatusses::cancelled->value)->where('end', '>=', now()))
        ->whereDoesntHave('serviceOrderStage', fn ($q) => $q->where('is_closed_state', true));
}
```

- Pass `'onlyNeedsClosing' => $request->boolean('onlyNeedsClosing')` back to the Inertia props,
  same round-trip pattern as `onlyStage`.

## Frontend

`resources/js/Pages/ServiceOrders/IndexPage.vue`:
- New prop `onlyNeedsClosing: { type: Boolean, default: false }`.
- A `SwitchComponent` placed next to the stage `ComboBox` in the `#filters` slot, with a label
  (e.g. "Alleen te sluiten") and a small helper text underneath stating that cancelled events are
  not taken into account.
- Boolean `ref`, synced to the URL query string and `localStorage`
  (key `serviceOrderFilter_needsClosing`), mirroring exactly how `stageFilter` already works today:
  - restored from the URL on mount, falling back to `localStorage` (`onMounted`)
  - persisted to `localStorage` on change (`watch`), removed when turned off
  - included in `filterParams` sent with search/filter requests
- Appears in the `activeFilters` chip row when on ("Te sluiten: Ja"), clearable via its own chip
  (which flips the switch back off), consistent with how stage-filter chips clear themselves.
  `clearAllFilters` also resets it.

## Out of scope

- No changes to `ServiceOrderStage`, `pastOpenEvents()`/`comingEvents()`, or any other index page.
- No backend model scope — kept inline to match the existing controller style for this filter set.
