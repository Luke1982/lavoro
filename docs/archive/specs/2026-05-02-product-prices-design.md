# Product Retail & Purchase Price Fields

**Date:** 2026-05-02
**Status:** Approved

## Overview

Add nullable `retail_price` and `purchase_price` fields to products, visible and editable only on the product show page by users with the `product.view_prices` permission. A new `Verkoop` (Sales) role is introduced with baseline read access to products and customers, plus the price permission.

## Database

### Migration 1 — price columns
Add two nullable decimal columns to the `products` table:

```
retail_price  decimal(10,2) nullable
purchase_price decimal(10,2) nullable
```

### Migration 2 — permission row
Insert a new row into the `permissions` table:

```
name:  product.view_prices
label: Productprijzen bekijken
```

`down()` deletes the row by name.

## Backend

### Product model (`app/Models/Product.php`)
- Add `retail_price` and `purchase_price` to `$fillable`.
- Cast both as `decimal:2`.

### ProductStoreUpdateRequest (`app/Http/Requests/ProductStoreUpdateRequest.php`)
Add validation rules:
```
retail_price:  nullable|numeric|min:0
purchase_price: nullable|numeric|min:0
```

### ProductController (`app/Http/Controllers/ProductController.php`)
No changes. The full product model is already passed to the show page via Inertia props; the new fields are included automatically once added to `$fillable`.

## Frontend

### ShowPage.vue (`resources/js/Pages/Products/ShowPage.vue`)

**Form object** — add to `useForm`:
```js
retail_price:  props.product.retail_price,
purchase_price: props.product.purchase_price,
```

**Watch array** — add both fields to the existing watch that calls `form.patch(...)`:
```js
watch([
    () => form.description,
    () => form.typical_certificate_days,
    () => form.retail_price,
    () => form.purchase_price,
], () => { form.patch(`/products/${props.product.id}`) })
```

**Template** — add a new row below the description/certificate section, gated by permission:
```html
<div v-if="hasPermission('product.view_prices')" class="mt-4 flex gap-4">
  <div class="w-1/2">
    <h3 class="text-sm font-semibold mb-3">Verkoopprijs</h3>
    <EditableTextField v-model="form.retail_price" type="input" input-type="number" />
  </div>
  <div class="w-1/2">
    <h3 class="text-sm font-semibold mb-3">Inkoopprijs</h3>
    <EditableTextField v-model="form.purchase_price" type="input" input-type="number" />
  </div>
</div>
```

Permission check is frontend-only — consistent with all other permission gates in this codebase (`asset.read`, `customfield.update`, etc.). The prices are present in the Inertia props for all authenticated users but not rendered without the permission.

## Permissions & Seeders

### `database/seeders/data/administratie_permissions.php`
Append `'product.view_prices'` to the existing array.

### New file: `database/seeders/data/verkoop_permissions.php`
```php
<?php
return [
    'product.read',
    'customer.read',
    'product.view_prices',
];
```

### `database/seeders/Industry/InstallationBrancheSeeder.php`
Add the Verkoop role after the Administratie block:
```php
$verkoop_role = Role::firstOrCreate(['name' => 'Verkoop']);
$this->syncPermissions(
    $verkoop_role,
    include base_path('database/seeders/data/verkoop_permissions.php')
);
```

## Files Changed

| File | Change |
|------|--------|
| `database/migrations/YYYY_add_prices_to_products_table.php` | New — decimal columns |
| `database/migrations/YYYY_add_view_prices_permission.php` | New — permission row |
| `app/Models/Product.php` | fillable + casts |
| `app/Http/Requests/ProductStoreUpdateRequest.php` | nullable numeric validation |
| `resources/js/Pages/Products/ShowPage.vue` | form fields, watch, template section |
| `database/seeders/data/administratie_permissions.php` | add `product.view_prices` |
| `database/seeders/data/verkoop_permissions.php` | New — Verkoop role permissions |
| `database/seeders/Industry/InstallationBrancheSeeder.php` | add Verkoop role |

## Out of Scope

- Index page: prices are not shown in the product list table.
- Create form: prices are not set when creating a new product (nullable defaults to null).
- Backend policy enforcement: no `ProductPolicy::viewPrices()` needed; frontend gate matches existing patterns.
- Currency formatting: fields are plain numeric inputs, no currency symbol or locale formatting.
