# Location model — design

**Date:** 2026-07-14
**Status:** approved design, pending spec review

## Problem

Customers are frequently **contractors**, not the end user of the equipment. Two recurring shapes:

1. A contractor customer sells/installs an asset (e.g. an aircon) for an end user at a **different physical address**.
2. A single customer (a hoofdvestiging) owns assets spread across **many of its own vestigingen**.

Today "where the work happens" is only free-text (`service_orders.execution_location`, `events.location`, `projects.location`) and every asset hangs directly off `customers.id`. There is no way to record the physical sites a customer relates to, which assets are at which site, and to route/plan by site.

## Goal

Introduce a first-class **`Location`** — a physical site that **belongs to one customer** — so we can track which locations exist, which customer they belong to, and which assets are where. The customer stays the commercial party; the location is the physical "where".

Spine: **`Customer 1—* Location 1—* Asset`**, with an asset also allowed to sit directly on a customer (no location).

## Decisions (locked)

- **Ownership:** a location belongs to exactly one customer. End users are never separate customer records; a contractor's client sites and a customer's own branches are both just locations under that customer.
- **Minimum fields:** `title`, `location_code`, `address` are required; postal_code/city/country/lat/lon optional.
- **`location_code`** is unique **per customer** (not global).
- **Migration:** no backfill. Existing assets keep `location_id = null`; the customer's own address stays as its commercial address. Locations are opt-in going forward.
- **Service orders:** a location combobox (the customer's locations). None selected → show/use freeform `execution_location`. One selected → hide the freeform field and use the location's address.
- **Contracts:** no direct contract↔location relationship. A contract's covered locations are **derived** from the locations of its chosen assets.
- **Worklist + map (Aankomende activiteiten):** group by location, customer as parent; assets without a location fall back to the customer.
- **Deleting a location that still has assets:** confirmation modal — either move the assets to another location of the same customer, or detach (null their `location_id`). Empty locations delete directly.
- **Naming:** the model is `App\Models\Location` (a customer site). It is unrelated to the existing `LocationPing` GPS user-tracking. UI label: "Locatie".

## Data model

All changes are additive (nullable columns + one table). Nothing is removed.

### New `locations` table

```
locations
  id
  customer_id     FK -> customers, constrained, cascadeOnDelete
  title           string, required
  location_code   string, required
  address         string, required
  postal_code     string, nullable
  city            string, nullable
  country         string, nullable
  lat             decimal(10,7) nullable
  lon             decimal(10,7) nullable
  timestamps
  unique(customer_id, location_code)
  index(lat, lon)
```

### New / changed columns on existing tables

- `assets.location_id` → nullable FK to `locations`, `nullOnDelete` at the DB level as a safety net. Keeps `customer_id`. The user-facing delete flow (below) still resolves assets explicitly (move or detach) before a location is removed; the DB rule only guards against orphaned references (e.g. a cascaded customer delete).
- `service_orders.location_id` → nullable FK to `locations`.

### Relationship inventory (all single-owner FKs — no morph pivot)

| Link | Cardinality | Mechanism |
|---|---|---|
| Location → Customer | one owner | `locations.customer_id` FK |
| Asset → Location | one | `assets.location_id` FK (nullable) |
| ServiceOrder → Location | one | `service_orders.location_id` FK (nullable) |
| Contract → Location(s) | derived | from `contract.assets.*.location` — no table |

> Rationale for no `-ables` pivot: the codebase uses `-ables` morph pivots only for many-to-many attachments (`assetables`, `eventables`, …) and plain `foreignIdFor` columns for single-owner links (`assets.customer_id`, `service_orders.customer_id`/`project_id`). Every location link here is single-owner or derived, so none is a pivot. A `locationables` morph pivot would only reappear if some future model needs to attach to *many* locations.

## Backend

Follows existing conventions (snake_case vars, Form Requests own validation, `authorize()` delegates to a policy, permissions seeded in a `2026_07_*` migration).

### `Location` model
- `belongsTo(Customer)`, `hasMany(Asset)`.
- `addressLine()` accessor: `address, postal_code city` — the single source for a location's display/geocode string.
- Fillables in snake_case.

### `Customer` model
- Add `locations()` hasMany.
- `customers.location_code` and `customers.address` untouched.

### `Asset` model
- Add `location()` belongsTo + `location_id` fillable.
- Validation rule (in the store/update Form Request): if `location_id` is set, that location must belong to the asset's `customer_id`.

### `ServiceOrder` model
- Add `location()` belongsTo + `location_id` fillable.
- Append a resolved-location accessor (mirrors the existing `is_closed`/`is_incomplete`/`is_invoiced` appended accessors):
  ```php
  protected $appends = ['is_closed', 'is_incomplete', 'is_invoiced', 'resolved_location'];
  protected $with = ['serviceOrderStage', 'location'];

  public function getResolvedLocationAttribute(): ?string
  {
      if ($this->location) {
          return $this->location->addressLine();
      }
      if (! empty($this->execution_location)) {
          return $this->execution_location;
      }
      return $this->relationLoaded('project') ? $this->project?->location : null;
  }
  ```
  - `location` added to `$with` for N+1 safety; the `project` fallback is guarded with `relationLoaded('project')`.
  - The Google/planner exports and PDF read `service_order.resolved_location` instead of recomputing.
  - The event-location cascade in `ServiceOrderController` becomes: use `resolved_location`, and only fall back to `first_event?->location` when no `location_id` is set (an explicitly chosen location beats a stray event string).

### `MaintenanceContract` model
- No location relation/pivot. Derived accessor:
  ```php
  public function getLocationsAttribute()
  {
      return $this->assets->map->location->filter()->unique('id')->values();
  }
  ```
- Contract show controller eager-loads `assets.location`.
- Contract generation groups assets by location so each generated werkbon inherits a single `location_id`.

### Controller, requests, policy, routes
- `LocationController` — resource controller under the `auth` group: `index` (all locations, filterable by customer), `show` (location + its assets), `store`, `update`, `destroy`. Plus `updateCoords`.
- Form Requests: `LocationStoreRequest`, `LocationUpdateRequest`, `LocationDestroyRequest`, `LocationUpdateCoordsRequest`. `authorize()` delegates to `LocationPolicy`; `rules()` holds validation, including `location_code` uniqueness scoped to `customer_id` (`Rule::unique('locations')->where('customer_id', …)`, ignoring self on update).
- `LocationPolicy` — `view/create/update/delete` gated on `location.*`.
- Permissions migration (`2026_07_*`, mirroring the maintenancecontract seed): `location.read`, `location.create`, `location.update`, `location.delete`.
- Routes: `Route::resource('locations', LocationController::class)` + `PATCH locations/{location}/coords` + combo endpoint below.
- Combo endpoint on `ComboSearchController`: `GET combo/customers/{customer}/locations` → `[{id, name}]` (name = `title – city`), feeding the service-order combobox and the asset location picker.

### Delete flow (`LocationController::destroy`)
- If the location has no assets → delete directly.
- If it has assets, `destroy` accepts a disposition:
  - `target_location_id` — move the assets to that location (must belong to the same customer), or
  - detach — set the assets' `location_id = null`.
- The disposition comes from a frontend confirmation modal (see UI).

## Service-order location behaviour

Combobox on the werkbon, options from `combo/customers/{customer}/locations`:
- **None selected** → freeform `execution_location` text field shown and used.
- **Location selected** → freeform field hidden; the order's location string comes from the location via `resolved_location`.

When a werkbon is created from the worklist and all selected assets share one location, that location prefills `location_id`.

Events and service jobs: **no schema change** — they read location through the order/asset (read-through). Events keep receiving a denormalized location string at creation as they do today.

## Worklist + map (`ActivityListController`)

### Worklist (`buildCustomerAssetList`)
Reshape to `Customer → Location → assets`. Assets with `location_id = null` go into a customer-level "no location" bucket. Selection, "select all", and the create-werkbon flow operate per `(customer, location)` group so a generated order carries one customer + one location.

### Map (`map`)
Emit **map items = locations** (their own lat/lon/address) plus customer-level items for location-less assets (customer address, as today). Each item carries `type: 'location' | 'customer'`.

### Geocoding + caching (reuse, no new Nominatim traffic for known addresses)
Two existing cache layers are reused:
1. **Server cache** — `GeocodeController::lookup` already does `Cache::rememberForever('geocode:' . sha1(normalized_address))`. It is keyed by address, so a location at an address already looked up for a customer costs zero new Nominatim calls.
2. **Per-row persistence** — locations get their own `lat`/`lon`; after first geocode the map persists them, so a location is never geocoded twice.

Frontend change: generalize `useCustomerMapMarkers` — replace the hardcoded `PATCH /customers/${id}/coords` with a `coordsUrl(item)` callback (defaults to customers). The activity map passes `item => item.type === 'location' ? '/locations/'+id+'/coords' : '/customers/'+id+'/coords'`. The 1100ms request spacing and the status overlay are unchanged.

## Frontend

### Menu
Add `{ name: 'Locaties', href: '/locations', requiresPermission: 'location.read' }` as the third child under **Klanten** in `useSidebarNav.js` (alongside Contacten, Onderhoudscontracten).

### Pages
- `Locations/IndexPage.vue` — list, filterable by customer (mirrors an existing index page).
- `Locations/ShowPage.vue` — editable details (title/code/address), an `OpenStreetMapWidget`, its assets, and (derived) the contracts that cover at least one asset at this location (`MaintenanceContract::whereHas('assets', … location_id = this)`).
- Customer `ShowPage.vue` — a "Locaties" section listing the customer's locations with add/edit, mirroring the existing Contacts/Contracts sections.
- Asset create/edit — optional location picker filtered to the asset's customer's locations (combo endpoint).
- ServiceOrder `ShowPage.vue` — the location combobox + conditional freeform field.
- Location delete confirmation modal — when the location has assets, offer "move to another location" (combo of the customer's other locations) or "detach machines" (null `location_id`).

### `AssetSelectMenu` enhancements (shared component; benefits order, ticket, and contract flows)
1. **Show location** on each option row and on the selected-asset display (title · city). Parent mappings (`customerAssets`, `availableAssets`) must include the asset's `location`; the backing queries eager-load `asset.location`.
2. **Search input when open** — a text field pinned at the top of the open `ListboxOptions` with `@keydown.stop` so listbox keyboard nav doesn't hijack typing. Client-side, case-insensitive substring filter over the loaded `assets` prop across **brand, model, serial_number, and location**. The parent mappings expose `brand`, `model`, `serial_number`, and `location` explicitly (today brand+model are combined into `name`) so the filter is precise.

## Out of scope

SnelStart syncing of locations; per-location contacts; a location-level portal; moving a customer's own address into a location record. Say if any should be pulled in.

## Affected areas — summary

| Area | Change |
|---|---|
| Assets | nullable `location_id` FK; `location()`; picker on create/edit; shown in AssetSelectMenu |
| Customers | `locations()` hasMany; "Locaties" section; menu child |
| ActivityList (worklist + map) | group by location; map plots locations; geocoding/caching reused for locations |
| Contracts | covered locations derived from assets; no pivot; assets picked via enhanced AssetSelectMenu |
| ServiceOrders | nullable `location_id`; location combobox; `resolved_location` appended accessor |
| ServiceJobs | none (read-through via asset/order) |
| Events | none (read-through; denormalized location string as today) |
| New | `Location` model, controller, requests, policy, permissions, routes, combo endpoint, pages |
