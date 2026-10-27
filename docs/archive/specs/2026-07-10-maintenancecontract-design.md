# MaintenanceContract

## Problem

Customers can be under a maintenance/service agreement: a price billed on a recurring basis,
covering an agreed set of the customer's assets, each serviced at an agreed interval. Nothing in
the app currently models this. This design adds a `MaintenanceContract` resource, subsidiary to
`Customer` in the same way `Contact` is, with a many-to-many link to `Asset` that carries a
per-asset service frequency.

## Data model

### `maintenance_contracts` table

| column | type | notes |
|---|---|---|
| `customer_id` | FK → `customers.id`, cascade delete | required |
| `title` | string, nullable | optional label |
| `start_date` | date | **required** |
| `end_date` | date, nullable | optional |
| `price` | decimal(10,2) | required |
| `price_interval` | string (enum cast) | required — `maandelijks` / `halfjaarlijks` / `jaarlijks` / `aangepast` |
| `price_interval_days` | unsigned int, nullable | required only when `price_interval = aangepast` |
| `manage_frequency_per_asset` | boolean, default `false` | toggles contract-wide vs per-asset frequency |
| `frequency` | string (enum cast), nullable | contract-wide service frequency, used when the toggle above is `false` |
| `frequency_days` | unsigned int, nullable | required only when `frequency = aangepast` |
| timestamps | | |

### `App\Enums\ContractInterval`

String-backed enum, uses `EnumComboBoxArrayTrait` (same shape as `TicketPriorities`):

```php
enum ContractInterval: string
{
    use EnumComboBoxArrayTrait;
    case maandelijks  = 'Maandelijks';
    case halfjaarlijks = 'Halfjaarlijks';
    case jaarlijks     = 'Jaarlijks';
    case aangepast     = 'Aangepast (dagen)';
}
```

Reused for both `price_interval` and `frequency` — same four options in both places.

### `assetables` pivot (new generic morph table)

Modeled directly on `contactables`: `Asset` is the "-able" entity, attachable to any parent type
(only `MaintenanceContract` for now, but the shape allows future reuse without a schema change).

| column | type | notes |
|---|---|---|
| `asset_id` | FK → `assets.id`, cascade delete | |
| `assetable_type` / `assetable_id` | morph columns | the parent (`MaintenanceContract`) |
| `frequency` | string (enum cast `ContractInterval`), nullable | only meaningful when the parent contract has `manage_frequency_per_asset = true` |
| `frequency_days` | unsigned int, nullable | required only when `frequency = aangepast` |
| timestamps | | |

Unique constraint on `(asset_id, assetable_type, assetable_id)`.

### Relationships

- `MaintenanceContract::customer()` — `belongsTo(Customer::class)`
- `Customer::maintenanceContracts()` — `hasMany(MaintenanceContract::class)`
- `MaintenanceContract::assets()` — `morphToMany(Asset::class, 'assetable')->withPivot(['id', 'frequency', 'frequency_days'])->withTimestamps()`
- `Asset::maintenanceContracts()` — `morphedByMany(MaintenanceContract::class, 'assetable')->withPivot(['id', 'frequency', 'frequency_days'])->withTimestamps()`

### Traits

`MaintenanceContract` uses `HasActivities` and `RemarkableTrait` (activity log + remarks, both
fully generic — no new backend work beyond wiring, see Frontend section).

### Derived / appended attributes

- `display_title` — `title` if set, otherwise `"{customer->name} — {start_date} t/m {end_date|'heden'}"` (Dutch-formatted dates). Used everywhere a contract needs a label in a list.
- `status` — computed from today vs. `start_date`/`end_date`: `toekomstig` (not started yet) / `actief` (started, not ended or no end date) / `verlopen` (past `end_date`). Never stored — same "always derive, never trust a raw field" approach as `ServiceOrder::is_closed`.

### Delete cleanup

`MaintenanceContract`'s `booted()` `deleting` hook purges its own rows from `assetables`,
`activityables`, and `remarkables` (matching `ServiceOrder`'s pivot cleanup — since this model
gains `HasActivities`/`RemarkableTrait`, both of those also need cleanup on delete, not just the
asset pivot).

## Backend

### Controller — `MaintenanceContractController`

- `index` — filters: `customer_id`, `status` (client-computed, so filter server-side by date range equivalent). Eager-loads `customer`.
- `show` — eager-loads `customer`, `assets` (with pivot), `remarks.user`, `internalRemarks.user`, `activities.user` (ordered desc), mirroring `ServiceOrderController::show`.
- `store` — creates the contract, `logActivity('Contract aangemaakt')`.
- `update` — updates; when `manage_frequency_per_asset` flips `false → true` in this request, copy the contract's current `frequency`/`frequency_days` into every currently-attached asset's pivot row that doesn't already have a frequency set (starting point, not a hard reset — doesn't clobber rows a user may have already customized). Logs a specific activity line for this switch plus a generic "Contract bijgewerkt" for other field changes (`sometimes`-based partial updates, so only log what actually changed — same diffing spirit as `ServiceOrderController::update`).
- `destroy` — deletes (triggers the cleanup hook above).
- `attachAsset(MaintenanceContract $maintenancecontract, Asset $asset)` — validates the asset belongs to the contract's customer, attaches via `assets()->attach()` with pivot `frequency`/`frequency_days`, `logActivity('Machine gekoppeld: ...')`.
- `updateAssetable(MaintenanceContract $maintenancecontract, $assetable_id)` — updates the pivot row's `frequency`/`frequency_days`, `logActivity(...)`.
- `detachAsset(MaintenanceContract $maintenancecontract, $assetable_id)` — detaches, `logActivity('Machine losgekoppeld: ...')`.

Nested verb routes, exactly mirroring `serviceorders/{serviceorder}/materials/{material}`:

```php
Route::resource('maintenancecontracts', MaintenanceContractController::class)->except(['create', 'edit']);
Route::post('maintenancecontracts/{maintenancecontract}/assets/{asset}', [MaintenanceContractController::class, 'attachAsset'])->name('maintenancecontracts.attachAsset');
Route::put('maintenancecontracts/{maintenancecontract}/assets/{assetable_id}', [MaintenanceContractController::class, 'updateAssetable'])->name('maintenancecontracts.updateAssetable');
Route::delete('maintenancecontracts/{maintenancecontract}/assets/{assetable_id}', [MaintenanceContractController::class, 'detachAsset'])->name('maintenancecontracts.detachAsset');
```

### Form Requests (`authorize()` delegates to policy only, per project convention)

- `MaintenanceContractStoreRequest`, `MaintenanceContractUpdateRequest`, `MaintenanceContractReadRequest`, `MaintenanceContractDestroyRequest` — CRUD, mirroring `Contact*Request`.
- `MaintenanceContractAttachAssetRequest`, `MaintenanceContractUpdateAssetableRequest`, `MaintenanceContractDetachAssetRequest` — mirroring `ServiceOrderAttachMaterialRequest` etc.

Store rules:

```php
'customer_id'                 => ['required', 'exists:customers,id'],
'title'                       => ['nullable', 'string', 'max:255'],
'start_date'                  => ['required', 'date'],
'end_date'                    => ['nullable', 'date', 'after_or_equal:start_date'],
'price'                       => ['required', 'numeric', DbRange::decimal(10, 2)],
'price_interval'              => ['required', 'string', 'in:' . ContractInterval::validationString()],
'price_interval_days'         => ['required_if:price_interval,aangepast', 'nullable', 'integer', 'min:1'],
'manage_frequency_per_asset'  => ['boolean'],
'frequency'                   => ['required_if:manage_frequency_per_asset,false', 'nullable', 'string', 'in:' . ContractInterval::validationString()],
'frequency_days'              => ['required_if:frequency,aangepast', 'nullable', 'integer', 'min:1'],
```

Update rules: same, each prefixed with `sometimes` (partial PATCH support, like `ContactUpdateRequest`). The frontend always submits `price_interval` together with `price_interval_days` in one request when either changes (same for `frequency`/`frequency_days`), so the `required_if` pair is always evaluated against a consistent payload.

Attach/update-asset rules: `asset_id` (attach only) `required|exists:assets,id` plus a check that the asset's `customer_id` matches the contract's; `frequency`/`frequency_days` same conditional shape as above, only accepted (validated) when the contract has `manage_frequency_per_asset = true` — otherwise rejected, since the contract-wide field is authoritative and per-row values would be misleading.

### Policy — `MaintenanceContractPolicy`

- `view`, `create`, `update`, `delete` — `hasPermission('maintenancecontract.{read,create,update,delete}')`, mirroring `ContactPolicy`.
- `attachAsset`, `updateAssetable`, `detachAsset` — gated on `assetable.{create,update,delete}.maintenancecontract`, mirroring `ServiceOrderPolicy`'s material methods. No separate "read" permission for the pivot — seeing attached assets is part of `maintenancecontract.read`.

### Permissions (new migration, `Permission::create()` guarded style like `contact` permissions)

```
maintenancecontract.read    Onderhoudscontracten bekijken
maintenancecontract.create  Onderhoudscontracten aanmaken
maintenancecontract.update  Onderhoudscontracten wijzigen
maintenancecontract.delete  Onderhoudscontracten verwijderen
assetable.create.maintenancecontract  Machine aan onderhoudscontract koppelen
assetable.update.maintenancecontract  Machinefrequentie op onderhoudscontract bijwerken
assetable.delete.maintenancecontract  Machine van onderhoudscontract loskoppelen
```

Existing roles do not get these grants automatically (matches how `contact.*`/`productattribute.*`
were introduced) — an admin assigns them via the roles UI afterward.

## Frontend

### Navigation

`resources/js/Layouts/MainLayout.vue` — new entry under the "Klanten" group, gated on
`maintenancecontract.read`:

```js
{ name: 'Onderhoudscontracten', href: '/maintenancecontracts', icon: WrenchScrewdriverIcon, current: false, requiresPermission: 'maintenancecontract.read' },
```

### `Pages/MaintenanceContracts/IndexPage.vue`

`IndexHeaderComponent` + paginated table (columns: customer, `display_title`, start–end dates,
price + interval, status badge), filters for customer and status. Own create trigger opens a
`DrawerComponent` form (see below) with a searchable customer `ComboBox` (via `useComboSearch`),
same split as `Contacts/IndexPage.vue` vs. the customer-page drawer.

### `Pages/MaintenanceContracts/ShowPage.vue`

Breadcrumb + `EditableTextField` fields for `title`, `start_date`, `end_date`, `price`
(`type="currency"`), `price_interval` (combobox, with the `price_interval_days` input revealed via
`v-auto-animate` when `aangepast` is selected — same idiom used across 40+ existing components),
`manage_frequency_per_asset` (switch), and the contract-wide `frequency`/`frequency_days` pair
(shown, animated, only while the switch is off).

Below that:
- New `MaintenanceContractAssetsWidget.vue`, modeled directly on `MaterialsWidget.vue`: an add-row
  `ComboBox` sourced from `maintenanceContract.customer.assets` (minus already-attached ones — no
  new AJAX endpoint needed, same as how `ServiceOrders/ShowPage.vue` already gets a customer's
  assets for free), a per-row frequency `ComboBox` + animated custom-days input **shown only when
  `manage_frequency_per_asset` is true** (otherwise the row just shows "Contractfrequentie:
  {label}" as read-only text), and a remove icon per row — POST/PUT/DELETE to the nested verb
  routes above.
- `TimelineComponent :activities="maintenanceContract.activities"`, mirroring
  `ServiceOrders/ShowPage.vue` (no events to merge in here, so no mixed-timeline computed needed —
  activities alone, sorted desc).
- `RemarksComponent :remarkable-type="'App\\Models\\MaintenanceContract'" :remarkable-id="maintenanceContract.id"` — drop-in, no other wiring required.

### `Customers/ShowPage.vue`

New "Onderhoudscontracten" box (same area/treatment as the existing Contacts box), listing
`customer.maintenanceContracts` (`display_title`, dates, status badge), each row linking to its
`ShowPage`. A `PlusCircleIcon` opens a `DrawerComponent` — **not** `CreateRecordForm` (legacy) —
built the same way as the existing "Nieuw contact" drawer at `Customers/ShowPage.vue:382-421`:
plain `useForm()`, a `v-auto-animate` container wrapping the conditional day-count inputs, fields
for `title` (optional), `start_date`, `end_date`, `price` (`CurrencyInput`), `price_interval`
(`ComboBox`) + animated `price_interval_days`, `manage_frequency_per_asset` (`SwitchComponent`),
and the animated `frequency`/`frequency_days` pair. `customer_id` is not a visible field — the
form's initial state includes `customer_id: props.customer.id` directly, same as
`newContactForm.customer_id = props.customer.id`. On success, closes the drawer and lets Inertia's
page reload refresh the list.

### `Utilities.js`

New `maintenanceContractStatusPillText`/`PillColorClasses` (or a small local computed if a full
utility pair is overkill for three states) mirroring `serviceOrderSentState`/`PillText`/
`PillColorClasses`, used by the Index table, the Show page header, and the Customer sidebar box.

## Out of scope

- No `HasOwner`/`HasExecutingUsers`/`HasCustomFields` — not requested.
- No new AJAX combo-search endpoint for assets — the existing "pass the customer's asset list
  wholesale" pattern is reused, same as `ServiceOrder`.
- No bulk "apply frequency to all assets" button — the `manage_frequency_per_asset` toggle already
  gives that behavior for free (contract-wide mode *is* "one frequency for every asset").
- No dedicated `CreatePage.vue` route — no resource in this app has one; creation is always a
  drawer, both from the Index page and from the Customer page.
