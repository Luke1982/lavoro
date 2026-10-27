# Mass-edit product attributes

## Overview

Allow users to select multiple products on the Products index page and bulk-apply `ProductAttributeValue` assignments via a drawer. Each attribute can be individually opted-in via an animated checkbox; attributes that don't apply to all selected products show an inline warning and are only saved to qualifying products.

## Selection UX

- A checkbox column is added as the first column of the products index table.
- The column header contains a "select all on current page" checkbox.
- Selected rows are visually highlighted (light blue background).
- When one or more products are selected, a sticky dark action bar fades in at the bottom of the page showing:
  - `X producten geselecteerd`
  - `Deselecteer alles` link (clears selection)
  - `Kenmerken bewerken` button (opens the drawer)
- Deselecting all products hides the action bar.

## Drawer

Uses the existing `DrawerComponent`.

**Header:** "Kenmerken bewerken" + subtitle "X producten geselecteerd".

**Body:**
- A short tip text: "Vink de kenmerken aan die je wilt toepassen. Niet-aangevinkte kenmerken worden niet gewijzigd."
- One row per unique `ProductAttribute` across all selected products' types, sorted by attribute name.
- Each row contains:
  - `AnimatedCheckbox` (checkmark color: `lavorodark` / `#081020`) — unchecked by default. Must be checked for this attribute to be included in the save.
  - Attribute name label.
  - `ComboBox` with the possible `ProductAttributeValue`s for that attribute. Disabled when the checkbox is unchecked.
  - Amber warning badge `⚠ Geldt voor X van Y producten` when the attribute does not apply to all selected products (i.e., not all selected products have a type that includes this attribute).

**Footer:** `Annuleren` (closes drawer) + `Opslaan` (submits).

**Attribute derivation (client-side):**
The set of attributes to show is derived from the existing `productAttributes` prop (already loaded on the index page for filtering). Each attribute in the prop must include a `product_type_ids` array (see Backend section — small addition to the controller query). For each selected product, look up its `product_type_id` and collect all `productAttributes` where `product_type_ids` includes that type. Count how many selected products each attribute applies to, and show the warning when the count is less than the total selection.

**On success:** close drawer, clear selection, rely on Inertia's flash for success feedback.

## Backend

### Route

```
POST /products/bulk-update-attributes
```

Added to `routes/web.php` inside the `auth` middleware group, pointing to `ProductController::bulkUpdateAttributes`.

### Form Request — `ProductBulkUpdateAttributesRequest`

- `authorize()`: `$user->can('update', Product::class)` (or `hasPermission('product.update')`).
- `rules()`:
  - `product_ids`: `required|array|min:1`
  - `product_ids.*`: `integer|exists:products,id`
  - `attributes`: `required|array|min:1`
  - `attributes.*.product_attribute_id`: `required|integer|exists:product_attributes,id`
  - `attributes.*.product_attribute_value_id`: `required|integer|exists:product_attribute_values,id` + custom rule that the value belongs to the given attribute

### Controller — `ProductController::bulkUpdateAttributes`

```
DB::transaction(function () use ($request) {
    $products = Product::whereIn('id', $request->product_ids)
        ->with('productType.productAttributes')
        ->get();

    foreach ($request->attributes as $attr) {
        $attributeId = $attr['product_attribute_id'];
        $valueId     = $attr['product_attribute_value_id'];

        foreach ($products as $product) {
            $typeHasAttr = $product->productType->productAttributes
                ->contains('id', $attributeId);

            if (!$typeHasAttr) continue;

            // Delete existing value for this attribute on this product, insert new
            $product->productAttributeValueables()
                ->whereHas('productAttributeValue', fn($q) =>
                    $q->where('product_attribute_id', $attributeId))
                ->delete();

            $product->productAttributeValueables()->create([
                'product_attribute_value_id' => $valueId,
            ]);
        }
    }
});

return redirect()->back()->with('success', 'Kenmerken bijgewerkt.');
```

## Data flow

One small prop addition is needed. The drawer reads from:
- `productAttributes` prop: provides attribute ids, names, possible values, **and `product_type_ids`** (array of type IDs this attribute is linked to — needs to be added to the controller query via `with('productTypes:id')` or a pluck on the morph relation).
- The selected product rows in the page's reactive state: provides each product's `product_type_id` to derive which attributes apply.

The save call uses Inertia's `router.post('/products/bulk-update-attributes', payload)`. On success the `redirect()->back()` response is handled natively by Inertia and the flash message appears without a separate reload.

## Out of scope

- Bulk-editing custom fields (separate system, separate feature).
- Bulk-editing core product fields (brand, type, sale period).
- Pagination-spanning "select all" (only current page selection).
