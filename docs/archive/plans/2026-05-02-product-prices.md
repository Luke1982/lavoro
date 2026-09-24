# Product Retail & Purchase Price Fields — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add nullable `retail_price` and `purchase_price` fields to products, auto-saved from the show page, visible only to users with `product.view_prices` permission; introduce a `Verkoop` role with that permission plus `product.read` and `customer.read`.

**Architecture:** Two migrations (price columns + permission row), model/validation/controller updates, a single Vue show-page change gated by `hasPermission('product.view_prices')`, and seeder data files for the new `Verkoop` role. Permission is frontend-only gated — matching every other permission check in this codebase.

**Tech Stack:** Laravel 11, PHPUnit 11, Inertia.js, Vue 3, Tailwind CSS

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/2026_05_02_100000_add_prices_to_products_table.php` | Create | Add `retail_price` and `purchase_price` decimal columns |
| `database/migrations/2026_05_02_100001_add_view_prices_permission.php` | Create | Insert `product.view_prices` permission row |
| `app/Models/Product.php` | Modify | Add fields to `$fillable` and `$casts` |
| `app/Http/Requests/ProductStoreUpdateRequest.php` | Modify | Add nullable numeric validation rules |
| `app/Http/Controllers/ProductController.php` | Modify | Add price fields to `store()` and `update()` field maps |
| `resources/js/Pages/Products/ShowPage.vue` | Modify | Add form fields, watch, permission-gated template section |
| `database/seeders/data/administratie_permissions.php` | Modify | Append `product.view_prices` |
| `database/seeders/data/verkoop_permissions.php` | Create | Permissions array for the Verkoop role |
| `database/seeders/Industry/InstallationBrancheSeeder.php` | Modify | Add Verkoop role creation |
| `tests/Feature/Products/ProductPriceTest.php` | Create | Feature tests for price persistence |

---

## Task 1: Price columns migration + model + form request + controller

**Files:**
- Create: `database/migrations/2026_05_02_100000_add_prices_to_products_table.php`
- Modify: `app/Models/Product.php`
- Modify: `app/Http/Requests/ProductStoreUpdateRequest.php`
- Modify: `app/Http/Controllers/ProductController.php`
- Create: `tests/Feature/Products/ProductPriceTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

namespace Tests\Feature\Products;

use App\Models\Brand;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPriceTest extends TestCase
{
    use RefreshDatabase;

    private function userWithUpdatePermission(): User
    {
        $permission = Permission::create(['name' => 'product.update', 'label' => 'Product bijwerken']);
        $role = Role::create(['name' => 'TestRole']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$role->id]);
        return $user;
    }

    private function makeProduct(): Product
    {
        $brand = Brand::create(['name' => 'TestBrand']);
        $type  = ProductType::create(['name' => 'TestType']);
        return Product::create([
            'brand_id'        => $brand->id,
            'product_type_id' => $type->id,
            'model'           => 'Test Model',
            'start_sell'      => '2024-01-01',
            'end_sell'        => '2026-01-01',
        ]);
    }

    public function test_retail_and_purchase_price_are_saved_on_update(): void
    {
        $user    = $this->userWithUpdatePermission();
        $product = $this->makeProduct();

        $response = $this->actingAs($user)->patch(route('products.update', $product), [
            'product_type_id'  => $product->product_type_id,
            'brand_id'         => $product->brand_id,
            'model'            => $product->model,
            'start_sell'       => '2024-01-01',
            'end_sell'         => '2026-01-01',
            'retail_price'     => 1299.99,
            'purchase_price'   => 850.00,
            'origin'           => 'showPage',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('products', [
            'id'             => $product->id,
            'retail_price'   => 1299.99,
            'purchase_price' => 850.00,
        ]);
    }

    public function test_prices_are_nullable(): void
    {
        $user    = $this->userWithUpdatePermission();
        $product = $this->makeProduct();

        $response = $this->actingAs($user)->patch(route('products.update', $product), [
            'product_type_id' => $product->product_type_id,
            'brand_id'        => $product->brand_id,
            'model'           => $product->model,
            'start_sell'      => '2024-01-01',
            'end_sell'        => '2026-01-01',
            'origin'          => 'showPage',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('products', [
            'id'             => $product->id,
            'retail_price'   => null,
            'purchase_price' => null,
        ]);
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

```bash
php artisan test tests/Feature/Products/ProductPriceTest.php
```

Expected: Both tests fail — `retail_price` column does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('retail_price', 10, 2)->nullable()->after('typical_certificate_days');
            $table->decimal('purchase_price', 10, 2)->nullable()->after('retail_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['retail_price', 'purchase_price']);
        });
    }
};
```

- [ ] **Step 4: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_05_02_100000_add_prices_to_products_table` → done.

- [ ] **Step 5: Update `app/Models/Product.php`**

Add `retail_price` and `purchase_price` to `$fillable`:

```php
protected $fillable = [
    'product_type_id',
    'brand_id',
    'model',
    'description',
    'start_sell',
    'end_sell',
    'typical_certificate_days',
    'retail_price',
    'purchase_price',
];
```

Add casts (add or update the `$casts` property — if it doesn't exist yet, add it below `$fillable`):

```php
protected $casts = [
    'retail_price'  => 'decimal:2',
    'purchase_price' => 'decimal:2',
];
```

- [ ] **Step 6: Update `app/Http/Requests/ProductStoreUpdateRequest.php`**

Add the two new rules inside the `rules()` return array:

```php
'retail_price'   => 'nullable|numeric|min:0',
'purchase_price' => 'nullable|numeric|min:0',
```

Full `rules()` array after edit:

```php
public function rules(): array
{
    return [
        'product_type_id'          => 'required|exists:product_types,id',
        'brand_id'                 => 'required|exists:brands,id',
        'model'                    => 'required|string|max:255',
        'description'              => 'nullable|string',
        'start_sell'               => 'required|date',
        'end_sell'                 => 'required|date|after_or_equal:start_sell',
        'typical_certificate_days' => 'nullable|integer|min:1',
        'retail_price'             => 'nullable|numeric|min:0',
        'purchase_price'           => 'nullable|numeric|min:0',
    ];
}
```

- [ ] **Step 7: Update `app/Http/Controllers/ProductController.php`**

In `store()`, add the two fields to the `Product::create()` array:

```php
$product = Product::create([
    'product_type_id'          => $request->product_type_id,
    'brand_id'                 => $request->brand_id,
    'model'                    => $request->model,
    'description'              => $request->description,
    'start_sell'               => $request->start_sell,
    'end_sell'                 => $request->end_sell,
    'typical_certificate_days' => $request->typical_certificate_days,
    'retail_price'             => $request->retail_price,
    'purchase_price'           => $request->purchase_price,
]);
```

In `update()`, add the two fields to the `$product->update()` array:

```php
$product->update([
    'product_type_id'          => $request->product_type_id,
    'brand_id'                 => $request->brand_id,
    'model'                    => $request->model,
    'description'              => $request->description,
    'start_sell'               => $request->start_sell,
    'end_sell'                 => $request->end_sell,
    'typical_certificate_days' => $request->typical_certificate_days,
    'retail_price'             => $request->retail_price,
    'purchase_price'           => $request->purchase_price,
]);
```

- [ ] **Step 8: Run the tests — expect both to pass**

```bash
php artisan test tests/Feature/Products/ProductPriceTest.php
```

Expected output:
```
PASS  Tests\Feature\Products\ProductPriceTest
✓ retail and purchase price are saved on update
✓ prices are nullable
```

- [ ] **Step 9: Commit**

```bash
git add \
  database/migrations/2026_05_02_100000_add_prices_to_products_table.php \
  app/Models/Product.php \
  app/Http/Requests/ProductStoreUpdateRequest.php \
  app/Http/Controllers/ProductController.php \
  tests/Feature/Products/ProductPriceTest.php
git commit -m "feat(Products) Add nullable retail_price and purchase_price fields"
```

---

## Task 2: Permission migration

**Files:**
- Create: `database/migrations/2026_05_02_100001_add_view_prices_permission.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insert([
            'name'       => 'product.view_prices',
            'label'      => 'Productprijzen bekijken',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'product.view_prices')->delete();
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_05_02_100001_add_view_prices_permission` → done.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_02_100001_add_view_prices_permission.php
git commit -m "feat(Permissions) Add product.view_prices permission"
```

---

## Task 3: Seeder — Verkoop role and administratie permission

**Files:**
- Modify: `database/seeders/data/administratie_permissions.php`
- Create: `database/seeders/data/verkoop_permissions.php`
- Modify: `database/seeders/Industry/InstallationBrancheSeeder.php`

- [ ] **Step 1: Add `product.view_prices` to `database/seeders/data/administratie_permissions.php`**

Append the entry after `'activitylist.read'`:

```php
'activitylist.read',

// Pricing
'product.view_prices',
```

- [ ] **Step 2: Create `database/seeders/data/verkoop_permissions.php`**

```php
<?php

return [
    'product.read',
    'customer.read',
    'product.view_prices',
];
```

- [ ] **Step 3: Update `database/seeders/Industry/InstallationBrancheSeeder.php`**

Add the Verkoop block directly after the Administratie block (before the `$event_types` lines):

```php
$verkoop_role = Role::firstOrCreate(['name' => 'Verkoop']);
$this->syncPermissions(
    $verkoop_role,
    include base_path('database/seeders/data/verkoop_permissions.php')
);
```

- [ ] **Step 4: Commit**

```bash
git add \
  database/seeders/data/administratie_permissions.php \
  database/seeders/data/verkoop_permissions.php \
  database/seeders/Industry/InstallationBrancheSeeder.php
git commit -m "feat(Seeders) Add Verkoop role and grant view_prices to Administratie"
```

---

## Task 4: ShowPage.vue — price fields

**Files:**
- Modify: `resources/js/Pages/Products/ShowPage.vue`

- [ ] **Step 1: Add price fields to the `useForm` object**

The current `useForm` call (around line 106) is:

```js
const form = useForm({
    description: props.product.description,
    id: props.product.id,
    model: props.product.model,
    brand_id: props.product.brand.id,
    product_type_id: props.product.product_type.id,
    start_sell: props.product.start_sell,
    end_sell: props.product.end_sell,
    typical_certificate_days: props.product.typical_certificate_days,
    origin: 'showPage'
});
```

Replace with:

```js
const form = useForm({
    description: props.product.description,
    id: props.product.id,
    model: props.product.model,
    brand_id: props.product.brand.id,
    product_type_id: props.product.product_type.id,
    start_sell: props.product.start_sell,
    end_sell: props.product.end_sell,
    typical_certificate_days: props.product.typical_certificate_days,
    retail_price: props.product.retail_price,
    purchase_price: props.product.purchase_price,
    origin: 'showPage'
});
```

- [ ] **Step 2: Add price fields to the watch array**

The current watch (around line 118) is:

```js
watch([
    () => form.description,
    () => form.typical_certificate_days
], () => {
    form.patch(`/products/${props.product.id}`);
});
```

Replace with:

```js
watch([
    () => form.description,
    () => form.typical_certificate_days,
    () => form.retail_price,
    () => form.purchase_price,
], () => {
    form.patch(`/products/${props.product.id}`);
});
```

- [ ] **Step 3: Add the price section to the template**

After the closing `</div>` of the description/certificate row (the `<div class="mt-4 flex">` block that ends around line 54), add:

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

- [ ] **Step 4: Verify the build compiles without errors**

```bash
npm run build 2>&1 | tail -20
```

Expected: no TypeScript or Vite errors.

- [ ] **Step 5: Manual test**

1. Log in as a user with `product.view_prices` permission (or temporarily as admin).
2. Navigate to any product show page.
3. Confirm *Verkoopprijs* and *Inkoopprijs* fields appear below the description/certificate section.
4. Enter a value in *Verkoopprijs* — verify the field auto-saves (no button press needed) and the value persists on page refresh.
5. Log in as a user without `product.view_prices` — confirm the price section is not rendered.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Products/ShowPage.vue
git commit -m "feat(Products) Show retail and purchase price fields on product detail page"
```

---

## Self-Review Checklist (completed)

- **Spec coverage:** All 8 file changes from the spec are covered. Controller update (missed in spec but found during planning) is included in Task 1.
- **Placeholder scan:** No TBDs. All code blocks are complete.
- **Type consistency:** `retail_price` / `purchase_price` used consistently across migration, model, form request, controller, and Vue form.
- **Permission name:** `product.view_prices` used consistently in migration, seeder files, and Vue template.
- **Factory dependency:** Tests use direct `Brand::create` / `ProductType::create` instead of the factory (which uses `ProductType::all()->random()` and would fail with empty DB in `RefreshDatabase`).
