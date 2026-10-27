# Supplier Feature Design

**Date:** 2026-06-05

## Overview

Introduce a `Supplier` model to track vendors. Suppliers link to products and materials via a morphic `suppliables` pivot that carries `article_number` and `is_preferred`. An Excel import mirrors the existing Customer import workflow.

---

## Data Model

### `suppliers` table

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | required |
| email | string | nullable |
| phone | string | nullable |
| mobile | string | nullable |
| website | string | nullable |
| contact_person | string | nullable |
| address | string | nullable |
| postal_code | string | nullable |
| city | string | nullable |
| country | string | nullable, default 'Nederland' |
| iban | string | nullable |
| vat_number | string | nullable |
| kvk_number | string | nullable |
| timestamps | | |

### `suppliables` pivot table

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| supplier_id | FK → suppliers | cascade delete |
| suppliable_type | string | `App\Models\Product` or `App\Models\Material` |
| suppliable_id | bigint | |
| article_number | string | nullable |
| is_preferred | boolean | default false |
| timestamps | | |

Unique constraint: `(supplier_id, suppliable_type, suppliable_id)`.

---

## Permissions

Migration seeds four permissions: `supplier.read`, `supplier.create`, `supplier.update`, `supplier.delete`.

---

## Backend

### `Supplier` model (`app/Models/Supplier.php`)

- Fillable: all columns listed above.
- Traits: `HasFactory`, `HasCustomFields`.
- `products()`: `morphedByMany(Product, 'suppliable')->withPivot('article_number', 'is_preferred')`
- `materials()`: `morphedByMany(Material, 'suppliable')->withPivot('article_number', 'is_preferred')`

### `Product` and `Material` models

Both gain a `suppliers()` relationship:
```php
morphToMany(Supplier, 'suppliable')->withPivot('article_number', 'is_preferred')
```

### `SupplierPolicy` (`app/Policies/SupplierPolicy.php`)

Standard four-method policy gating on `supplier.read`, `supplier.create`, `supplier.update`, `supplier.delete`.

### Form Requests

- `SupplierReadRequest` — authorizes `supplier.read`
- `SupplierStoreRequest` — authorizes `supplier.create`; validates `name` required, phone/postal_code sanitized (same as Customer pattern)
- `SupplierUpdateRequest` — authorizes `supplier.update`; same rules as store
- `SupplierDestroyRequest` — authorizes `supplier.delete`
- `SupplierImportPreviewRequest` — authorizes `supplier.create`; validates xlsx/xls file ≤ 10 MB
- `SupplierImportConfirmRequest` — authorizes `supplier.create`

### `SupplierController` (`app/Http/Controllers/SupplierController.php`)

- `index()` — paginated list with search across name, email, city, contact_person, kvk_number, vat_number
- `show()` — supplier with `products` and `materials` pivot data loaded
- `store()` — creates supplier; sanitizes phone/postal_code
- `update()` — updates supplier
- `destroy()` — deletes supplier (suppliables cascade)

### `SupplierImportController` (`app/Http/Controllers/SupplierImportController.php`)

Mirrors `CustomerImportController` exactly. Columns (Dutch):

| Column header | Field |
|---|---|
| Naam * | name |
| E-mail | email |
| Telefoon | phone |
| Mobiel | mobile |
| Website | website |
| Contactpersoon | contact_person |
| Adres | address |
| Postcode | postal_code |
| Plaats | city |
| Land | country |
| IBAN | iban |
| BTW-nummer | vat_number |
| KVK-nummer | kvk_number |

- `preview()` — reads Excel, detects duplicates by `name`, returns preview rows with warnings
- `confirm()` — dispatches `ProcessSupplierImportJob`
- `example()` — streams a downloadable example `.xlsx`

### `ProcessSupplierImportJob` (`app/Jobs/ProcessSupplierImportJob.php`)

Background job: upserts suppliers by name match; sanitizes phone/postal_code.

---

## Routes (`routes/web.php`)

```
GET    /suppliers                          SupplierController@index
POST   /suppliers                          SupplierController@store
GET    /suppliers/{supplier}               SupplierController@show
PUT    /suppliers/{supplier}               SupplierController@update
DELETE /suppliers/{supplier}               SupplierController@destroy
POST   /suppliers/import/preview           SupplierImportController@preview
POST   /suppliers/import/confirm           SupplierImportController@confirm
GET    /suppliers/import/example           SupplierImportController@example
```

---

## Frontend

### `Pages/Suppliers/IndexPage.vue`

- Searchable paginated table: Naam, E-mail, Stad, Contactpersoon.
- Excel import widget identical to Customers: upload → preview table with warnings → confirm.
- Permissions gate: `supplier.create` for add/import buttons.
- Row click navigates to show page.

### `Pages/Suppliers/ShowPage.vue`

- Editable header fields (same EditableTextField pattern as other show pages).
- Read-only **Producten** panel: table of linked products with article_number and preferred badge.
- Read-only **Materialen** panel: table of linked materials with article_number and preferred badge.
- No inline add/remove of links from this page (managed from product/material side).

### Product and Material show pages

If a show page exists for either model, add a **Leveranciers** (Suppliers) section:
- Table of linked suppliers with article_number column and preferred toggle.
- Search-and-add: ComboBox to find a supplier → input for article_number → add button.
- Remove link button per row.
- Calls new API endpoints (or Inertia PUT/POST) for managing `suppliables` pivot.

If a show page does not exist for a model, this section is deferred.

---

## Navigation

Add "Leveranciers" link to the main sidebar (`MainLayout.vue`), gated on `supplier.read`.
