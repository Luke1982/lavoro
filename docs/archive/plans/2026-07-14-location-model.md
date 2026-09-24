# Location Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a first-class `Location` (a physical site belonging to one customer) so the app can track which sites exist, which customer owns them, and which assets/orders are where.

**Architecture:** `Customer 1—* Location 1—* Asset`. Locations are lightweight (title/code/address). All location links are single-owner FK columns (`assets.location_id`, `service_orders.location_id`) — no morph pivot. A contract's covered locations are derived from its assets' locations. All changes are additive and opt-in; existing rows keep `location_id = null`.

**Tech Stack:** Laravel 12, Inertia, Vue 3, Tailwind, Leaflet (OSM), Nominatim geocoding.

## Global Constraints

- PHP variables: `snake_case`. No inline comments; docblocks only when needed.
- String concatenation with spaces: `$a . ' ' . $b`.
- No space after `!`: write `!$foo` (pint.json disables `not_operator_with_successor_space`).
- Validation lives in Form Request `rules()` only; frontend displays `form.errors`.
- Form Request `authorize()` calls policy `can()` methods only — never `hasPermission` directly. `hasPermission` lives in the policy.
- Selecting/toggling UI: clicking a selected item deselects; never add separate X/clear buttons.
- Run the formatter after every edit: `./vendor/bin/pint <path>` for PHP, `npm run fix:eslint` for JS/Vue. Not batched.
- Do not write automated tests (project rule) — each task ends in a manual/verification step.
- Do not run git commands — commits are the user's call.
- Permissions convention `{resource}.{action}`, seeded in a `2026_07_*` migration.
- `location_code` is unique **per customer**.

## Reference files (read before implementing the matching task)

- CRUD backend template: `app/Http/Controllers/SupplierController.php`, `app/Http/Requests/Supplier{Read,Store,Update,Destroy}Request.php`, `app/Policies/SupplierPolicy.php`.
- Coords/geocode: `app/Http/Controllers/GeocodeController.php`, `app/Http/Requests/CustomerUpdateCoordsRequest.php`, `CustomerController::updateCoords`, route `customers/{customer}/coords`.
- Morph nothing here — but FK migration style: `database/migrations/2025_06_16_115514_create_customers_table.php` (see `lat`/`lon`, `foreignIdFor`).
- Permission seed template: `database/migrations/2026_07_10_100003_seed_maintenancecontract_permissions.php`.
- Menu: `resources/js/Composables/useSidebarNav.js` (the `Klanten` item with `children`).
- Index/Show page template: `resources/js/Pages/Suppliers/IndexPage.vue`, `resources/js/Pages/Suppliers/ShowPage.vue`.
- Asset select: `resources/js/Components/UI/AssetSelectMenu.vue`, and its two mappings in `resources/js/Components/MaintenanceContracts/MaintenanceContractAssetsWidget.vue` (`availableAssets`) and `resources/js/Pages/ServiceOrders/ShowPage.vue` (`customerAssets`).
- Map: `resources/js/Composables/useCustomerMapMarkers.js`, `resources/js/Pages/ActivityList/UpcomingActivitiesMap.vue`.
- Worklist: `app/Http/Controllers/ActivityListController.php`, `resources/js/Pages/ActivityList/UpcomingActivities.vue`, `resources/js/Components/CustomerUpcomingActivity.vue`.

---

# Phase 1 — Location foundation (CRUD + menu)

Ships: you can create/list/edit/delete locations under a customer, geocode them, and reach them from the menu.

### Task 1: `locations` table + `Location` model + `Customer::locations()`

**Files:**
- Create: `database/migrations/2026_07_14_120001_create_locations_table.php`
- Create: `app/Models/Location.php`
- Modify: `app/Models/Customer.php`

**Interfaces:**
- Produces: `Location` model with `customer()`, `assets()` (defined later Task 7), `addressLine(): string`. Columns: `id, customer_id, title, location_code, address, postal_code, city, country, lat, lon, timestamps`.

- [ ] **Step 1: Create the migration**

```php
<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('location_code');
            $table->string('address');
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'location_code']);
            $table->index(['lat', 'lon']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
```

- [ ] **Step 2: Create `app/Models/Location.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'title',
        'location_code',
        'address',
        'postal_code',
        'city',
        'country',
        'lat',
        'lon',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function addressLine(): string
    {
        return collect([$this->address, trim($this->postal_code . ' ' . $this->city)])
            ->filter(fn ($part) => $part !== null && $part !== '')
            ->implode(', ');
    }
}
```

- [ ] **Step 3: Add `locations()` to `app/Models/Customer.php`**

After the existing `assets()` relation add:

```php
    public function locations()
    {
        return $this->hasMany(Location::class)->orderBy('title');
    }
```

- [ ] **Step 4: Run the migration**

Run: `php artisan migrate`
Expected: `locations` table created, no errors.

- [ ] **Step 5: Format**

Run: `./vendor/bin/pint app/Models/Location.php app/Models/Customer.php database/migrations/2026_07_14_120001_create_locations_table.php`

- [ ] **Step 6: Verify in tinker**

Run: `php artisan tinker --execute="\$c = App\Models\Customer::first(); \$l = \$c->locations()->create(['title'=>'Test','location_code'=>'TST-1','address'=>'Straat 1','postal_code'=>'1234AB','city'=>'Utrecht']); echo \$l->addressLine(); \$l->delete();"`
Expected: prints `Straat 1, 1234AB Utrecht`.

---

### Task 2: `LocationPolicy` + permissions seed

**Files:**
- Create: `app/Policies/LocationPolicy.php`
- Create: `database/migrations/2026_07_14_120002_seed_location_permissions.php`

**Interfaces:**
- Produces: policy abilities `viewAny/view/create/update/delete`; permissions `location.read`, `location.create`, `location.update`, `location.delete`.

- [ ] **Step 1: Create `app/Policies/LocationPolicy.php`** (mirrors `SupplierPolicy`)

```php
<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('location.read');
    }

    public function view(User $user, Location $location): bool
    {
        return $user->hasPermission('location.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('location.create');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->hasPermission('location.update');
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->hasPermission('location.delete');
    }
}
```

- [ ] **Step 2: Create the permission seed migration** (mirrors the maintenancecontract seed)

```php
<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'location.read', 'label' => 'Locaties bekijken'],
        ['name' => 'location.create', 'label' => 'Locaties aanmaken'],
        ['name' => 'location.update', 'label' => 'Locaties wijzigen'],
        ['name' => 'location.delete', 'label' => 'Locaties verwijderen'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (! Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};
```

- [ ] **Step 3: Run migration + format**

Run: `php artisan migrate && ./vendor/bin/pint app/Policies/LocationPolicy.php database/migrations/2026_07_14_120002_seed_location_permissions.php`
Expected: four `location.*` rows in `permissions`. Policy auto-discovered by Laravel (model `Location` → `LocationPolicy`); no registration needed.

- [ ] **Step 4: Verify auto-discovery**

Run: `php artisan tinker --execute="echo App\Models\Permission::whereIn('name',['location.read','location.create','location.update','location.delete'])->count();"`
Expected: `4`.

---

### Task 3: Location Form Requests

**Files:**
- Create: `app/Http/Requests/LocationReadRequest.php`
- Create: `app/Http/Requests/LocationStoreRequest.php`
- Create: `app/Http/Requests/LocationUpdateRequest.php`
- Create: `app/Http/Requests/LocationDestroyRequest.php`
- Create: `app/Http/Requests/LocationUpdateCoordsRequest.php`

**Interfaces:**
- Produces: `LocationStoreRequest::sanitized()` and `LocationUpdateRequest::sanitized()` returning validated data with normalized `postal_code`. `LocationDestroyRequest` validates the delete disposition (`disposition`, `target_location_id`) — used by Task 11.

- [ ] **Step 1: `LocationReadRequest.php`** (mirrors `SupplierReadRequest`)

```php
<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;

class LocationReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $location = $this->route('location');

        return $location
            ? $this->user()->can('view', $location)
            : $this->user()->can('viewAny', Location::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
        ];
    }
}
```

- [ ] **Step 2: `LocationStoreRequest.php`**

The `location_code` unique rule is scoped to `customer_id` so the same code may repeat across customers.

```php
<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Location::class);
    }

    public function rules(): array
    {
        return [
            'customer_id'   => ['required', 'exists:customers,id'],
            'title'         => ['required', 'string', 'max:255'],
            'location_code' => [
                'required', 'string', 'max:255',
                Rule::unique('locations')->where(fn ($q) => $q->where('customer_id', $this->input('customer_id'))),
            ],
            'address'       => ['required', 'string', 'max:255'],
            'postal_code'   => ['nullable', 'regex:/^\d{4}\s?[A-Za-z]{2}$/'],
            'city'          => ['nullable', 'string', 'max:255'],
            'country'       => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'         => 'Titel is verplicht.',
            'location_code.required' => 'Locatiecode is verplicht.',
            'location_code.unique'   => 'Deze locatiecode bestaat al voor deze klant.',
            'address.required'       => 'Adres is verplicht.',
            'postal_code.regex'      => 'Postcode moet 4 cijfers gevolgd door 2 letters zijn (bijv. 1234AB).',
        ];
    }

    public function sanitized(): array
    {
        $data = $this->validated();
        if (!empty($data['postal_code'])) {
            $data['postal_code'] = strtoupper(preg_replace('/\s|-/', '', (string) $data['postal_code']));
        }

        return $data;
    }
}
```

- [ ] **Step 3: `LocationUpdateRequest.php`**

Same as store but authorize on the route model, `customer_id` immutable (not in rules), and the unique rule ignores the current row.

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('location'));
    }

    public function rules(): array
    {
        $location = $this->route('location');

        return [
            'title'         => ['required', 'string', 'max:255'],
            'location_code' => [
                'required', 'string', 'max:255',
                Rule::unique('locations')
                    ->where(fn ($q) => $q->where('customer_id', $location->customer_id))
                    ->ignore($location->id),
            ],
            'address'       => ['required', 'string', 'max:255'],
            'postal_code'   => ['nullable', 'regex:/^\d{4}\s?[A-Za-z]{2}$/'],
            'city'          => ['nullable', 'string', 'max:255'],
            'country'       => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_code.unique' => 'Deze locatiecode bestaat al voor deze klant.',
            'postal_code.regex'    => 'Postcode moet 4 cijfers gevolgd door 2 letters zijn (bijv. 1234AB).',
        ];
    }

    public function sanitized(): array
    {
        $data = $this->validated();
        if (!empty($data['postal_code'])) {
            $data['postal_code'] = strtoupper(preg_replace('/\s|-/', '', (string) $data['postal_code']));
        }

        return $data;
    }
}
```

- [ ] **Step 4: `LocationDestroyRequest.php`**

Validates the disposition used by Task 11's delete flow. `target_location_id` must belong to the same customer and not be the location being deleted.

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('location'));
    }

    public function rules(): array
    {
        $location = $this->route('location');

        return [
            'disposition' => ['sometimes', 'in:detach,move'],
            'target_location_id' => [
                'required_if:disposition,move',
                Rule::exists('locations', 'id')
                    ->where(fn ($q) => $q->where('customer_id', $location->customer_id)),
                Rule::notIn([$location->id]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target_location_id.required_if' => 'Kies een locatie om de machines naartoe te verplaatsen.',
            'target_location_id.not_in'      => 'Je kunt machines niet naar dezelfde locatie verplaatsen.',
        ];
    }
}
```

- [ ] **Step 5: `LocationUpdateCoordsRequest.php`** (mirrors `CustomerUpdateCoordsRequest`, but authorize via policy)

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LocationUpdateCoordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('location'));
    }

    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
```

- [ ] **Step 6: Format**

Run: `./vendor/bin/pint app/Http/Requests/Location*.php`

---

### Task 4: `LocationController` + routes + combo endpoint

**Files:**
- Create: `app/Http/Controllers/LocationController.php`
- Create: `app/Http/Requests/LocationSearchRequest.php`
- Modify: `app/Http/Controllers/ComboSearchController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Produces: routes `locations.{index,show,store,update,destroy}`, `locations.updateCoords`, `combo.customer.locations`.
- Produces: `GET combo/customers/{customer}/locations` → JSON array of `{ id, name }` where `name` = `title` + ` – ` + `city` (city omitted if blank). Consumed by Tasks 8 and 13.
- The `destroy` disposition handling is added in Task 11; here `destroy` deletes only.

- [ ] **Step 1: Create `LocationController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationDestroyRequest;
use App\Http\Requests\LocationReadRequest;
use App\Http\Requests\LocationStoreRequest;
use App\Http\Requests\LocationUpdateCoordsRequest;
use App\Http\Requests\LocationUpdateRequest;
use App\Models\Location;

class LocationController extends Controller
{
    public function index(LocationReadRequest $request)
    {
        $search = $request->input('search');
        $customer_id = $request->input('customer_id');

        $locations = Location::with('customer:id,name')
            ->when($customer_id, fn ($query) => $query->where('customer_id', $customer_id))
            ->when($search !== null && $search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('location_code', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->orderBy('title')
            ->paginate(25)
            ->appends(['search' => $search, 'customer_id' => $customer_id]);

        return inertia('Locations/IndexPage', [
            'locations' => $locations,
            'filters' => ['search' => $search, 'customer_id' => $customer_id],
        ]);
    }

    public function show(LocationReadRequest $request, Location $location)
    {
        $location->load([
            'customer:id,name',
            'assets.product.brand',
            'assets.product.productType',
        ]);

        return inertia('Locations/ShowPage', [
            'location' => $location,
        ]);
    }

    public function store(LocationStoreRequest $request)
    {
        Location::create($request->sanitized());

        return redirect()->back()->with('success', 'Locatie aangemaakt.');
    }

    public function update(LocationUpdateRequest $request, Location $location)
    {
        $location->update($request->sanitized());

        return redirect()->back()->with('success', 'Locatie bijgewerkt.');
    }

    public function destroy(LocationDestroyRequest $request, Location $location)
    {
        $location->delete();

        return redirect()->route('locations.index')->with('success', 'Locatie verwijderd.');
    }

    public function updateCoords(LocationUpdateCoordsRequest $request, Location $location)
    {
        $location->update($request->validated());

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 2: Create `LocationSearchRequest.php`** (authorizes the combo endpoint)

```php
<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;

class LocationSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Location::class);
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 3: Add the combo method to `ComboSearchController.php`**

Add the import `use App\Http\Requests\LocationSearchRequest;` and `use App\Models\Customer;` (Customer is already imported) and this method:

```php
    public function locationsForCustomer(LocationSearchRequest $request, Customer $customer): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = $customer->locations()
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%")
                ->orWhere('location_code', 'like', "%{$q}%")
                ->orWhere('city', 'like', "%{$q}%"))
            ->orderBy('title')
            ->limit(50)
            ->get(['id', 'title', 'city'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->city ? "{$l->title} – {$l->city}" : $l->title,
            ]);

        return response()->json($results);
    }
```

- [ ] **Step 4: Register routes in `routes/web.php`**

Add the controller import at the top with the others: `use App\Http\Controllers\LocationController;`. Inside the `auth` middleware group, next to the customers routes, add:

```php
        Route::resource('locations', LocationController::class)
            ->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::patch('locations/{location}/coords', [LocationController::class, 'updateCoords'])
            ->name('locations.updateCoords');
        Route::get('combo/customers/{customer}/locations', [ComboSearchController::class, 'locationsForCustomer'])
            ->name('combo.customer.locations');
```

- [ ] **Step 5: Format + verify routes**

Run: `./vendor/bin/pint app/Http/Controllers/LocationController.php app/Http/Controllers/ComboSearchController.php app/Http/Requests/LocationSearchRequest.php && php artisan route:list --name=locations`
Expected: `locations.index/show/store/update/destroy` + `locations.updateCoords` listed.

- [ ] **Step 6: Verify combo endpoint**

Run: `php artisan route:list --name=combo.customer.locations`
Expected: the combo route listed with URI `combo/customers/{customer}/locations`.

---

### Task 5: Menu child + Locations Index & Show pages

**Files:**
- Modify: `resources/js/Composables/useSidebarNav.js`
- Create: `resources/js/Pages/Locations/IndexPage.vue`
- Create: `resources/js/Pages/Locations/ShowPage.vue`

**Interfaces:**
- Consumes: `locations` paginator (`{ data, links, ... }`) and `filters` from `LocationController::index`; `location` (with `customer`, `assets`) from `show`.

- [ ] **Step 1: Add the `Locaties` child in `useSidebarNav.js`**

In the `Klanten` navigation item's `children` array (currently Contacten + Onderhoudscontracten), add as the first child:

```js
                { name: 'Locaties', href: '/locations', icon: MapPinIcon, current: false, requiresPermission: 'location.read' },
```

Add `MapPinIcon` to the `@heroicons/vue/24/outline` import list at the top of the file.

- [ ] **Step 2: Create `resources/js/Pages/Locations/IndexPage.vue`**

Mirror `resources/js/Pages/Suppliers/IndexPage.vue` for the header/search/pagination boilerplate. The list renders one row per location showing `title`, `location_code`, the customer name (link to `/customers/{id}`), and city; each row links to `/locations/{id}`. Use `IndexHeaderComponent` with `search-url="/locations"` and `PaginationComponent`. Concrete row block:

```vue
<Link :href="`/locations/${location.id}`"
    class="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-slate-800 hover:bg-gray-50 dark:hover:bg-slate-800/50">
    <div class="flex flex-col min-w-0">
        <span class="font-semibold text-sm text-gray-900 dark:text-slate-100 truncate">{{ location.title }}</span>
        <span class="text-xs text-gray-400 dark:text-slate-500 truncate">
            {{ [location.location_code, location.address, location.city].filter(Boolean).join(' • ') }}
        </span>
    </div>
    <span class="text-xs text-gray-500 dark:text-slate-400 shrink-0">{{ location.customer?.name }}</span>
</Link>
```

Props: `defineProps({ locations: Object, filters: Object })`; iterate `locations.data`.

- [ ] **Step 3: Create `resources/js/Pages/Locations/ShowPage.vue`**

Mirror `resources/js/Pages/Suppliers/ShowPage.vue` layout. Include:
- An editable form (title, location_code, address, postal_code, city, country) using `useForm` + `EditableTextField`, patching `PATCH`/`PUT /locations/{id}` via `router` on update (follow the Suppliers pattern which uses per-field patch or a save button — match whatever Suppliers/ShowPage does).
- An `OpenStreetMapWidget` keyed on the address (see how `Customers/ShowPage.vue` uses it: `:key="`${form.address},${form.postal_code} ${form.city}`"`), passing the location's lat/lon.
- A list of the location's assets (`location.assets`) each linking to `/assets/{id}` showing brand/model + serial.

Props: `defineProps({ location: Object })`.

- [ ] **Step 4: Build + lint**

Run: `npm run fix:eslint && npm run build`
Expected: build succeeds.

- [ ] **Step 5: Drive it**

Start the app (`composer run dev`), log in as an admin, confirm **Klanten → Locaties** appears, open `/locations`, and confirm the empty/list state renders without console errors.

---

### Task 6: Customer "Locaties" section

**Files:**
- Create: `resources/js/Components/Locations/CustomerLocationsWidget.vue`
- Modify: `resources/js/Pages/Customers/ShowPage.vue`
- Modify: `app/Http/Controllers/CustomerController.php`

**Interfaces:**
- Consumes: `customer.locations` (array) provided by `CustomerController::show`.
- Produces: create/edit of locations scoped to the customer via `POST /locations` (with `customer_id`) and `PUT /locations/{id}`.

- [ ] **Step 1: Eager-load locations in `CustomerController::show`**

In the `show` method's `$customer->load([...])` (or equivalent) add `'locations'`. If `show` builds the load array conditionally, append `'locations'` to it.

- [ ] **Step 2: Create `CustomerLocationsWidget.vue`**

A card titled "Locaties" listing `customer.locations` (title, location_code, city; each links to `/locations/{id}`), with an "Locatie toevoegen" toggle that reveals an inline form (title, location_code, address, postal_code, city, country) posting to `/locations` with `customer_id: props.customerId`. Gate the add button on `hasPermission('location.create')`. Show `form.errors` under fields. Follow the structure of `MaintenanceContractAssetsWidget.vue` (toggle + inline form + list). Props: `defineProps({ customerId: Number, locations: Array })`.

- [ ] **Step 3: Mount it in `Customers/ShowPage.vue`**

Place `<CustomerLocationsWidget :customer-id="customer.id" :locations="customer.locations || []" />` inside a `BoxComponent`, next to the existing Contacts section. Import the component.

- [ ] **Step 4: Build + lint + drive**

Run: `npm run fix:eslint && npm run build`
Then on a customer page: add a location, edit it, confirm it appears under **Klanten → Locaties** filtered to that customer, and that a duplicate `location_code` for the same customer shows the validation error.

---

# Phase 2 — Assets ↔ locations

Ships: assets can be placed at a location; the asset selector shows and searches by location.

### Task 7: `assets.location_id` + `Asset::location()` + validation

**Files:**
- Create: `database/migrations/2026_07_14_120003_add_location_id_to_assets_table.php`
- Modify: `app/Models/Asset.php`
- Modify: the asset store/update Form Request(s) (locate via `grep -rl "class Asset.*Request" app/Http/Requests`).

**Interfaces:**
- Produces: `Asset::location()` belongsTo; `assets.location_id` nullable FK; validation rule ensuring a chosen location belongs to the asset's customer.

- [ ] **Step 1: Migration**

```php
<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignIdFor(Location::class)->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Location::class);
        });
    }
};
```

- [ ] **Step 2: `Asset.php` — add to `$fillable` and add relation**

Add `'location_id'` to `$fillable`. Add:

```php
    public function location()
    {
        return $this->belongsTo(Location::class);
    }
```

- [ ] **Step 3: Add the belongs-to-customer rule to the asset store/update request**

In the asset store/update Form Request `rules()`, add `location_id`:

```php
            'location_id' => [
                'nullable',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('customer_id', $this->input('customer_id'))),
            ],
```

Import `use Illuminate\Validation\Rule;`. If the update request has no `customer_id` in the payload, resolve it from the route asset instead: `$q->where('customer_id', $this->route('asset')->customer_id)`.

- [ ] **Step 4: Migrate + format**

Run: `php artisan migrate && ./vendor/bin/pint app/Models/Asset.php database/migrations/2026_07_14_120003_add_location_id_to_assets_table.php`

- [ ] **Step 5: Verify**

Run: `php artisan tinker --execute="echo Schema::hasColumn('assets','location_id') ? 'ok' : 'missing';"`
Expected: `ok`.

---

### Task 8: Asset location picker (asset edit)

**Files:**
- Modify: the asset show/edit page (locate: `resources/js/Pages/Assets/ShowPage.vue` or `EditPage.vue`).

**Interfaces:**
- Consumes: `combo/customers/{customer}/locations`.

- [ ] **Step 1: Add a location `ComboBox` to the asset form**

Below the customer field, add a location picker. Fetch options when the asset's customer is known:

```js
const locationOptions = ref([]);
async function loadLocations(customerId) {
    if (!customerId) { locationOptions.value = []; return; }
    const { data } = await axios.get(`/combo/customers/${customerId}/locations`);
    locationOptions.value = data;
}
```

Call `loadLocations(form.customer_id)` on mount and when the customer changes. Bind a `ComboBox`:

```vue
<ComboBox :options="locationOptions" v-model="form.location_id" label="Locatie"
    placeholder="Geen locatie" @update:model-value="patch('location_id')" />
```

Follow whatever save mechanism the asset page already uses (per-field `patch` or a submit). Clicking the selected option deselects it (ComboBox already toggles; do not add a clear button).

- [ ] **Step 2: Build + lint + drive**

Run: `npm run fix:eslint && npm run build`
Then: open an asset whose customer has locations, assign a location, reload, confirm it persisted; confirm the asset appears under that location's Show page (Task 3).

---

### Task 9: Shared asset-select mapping helper

**Files:**
- Modify: `resources/js/Utilities/Utilities.js`
- Modify: `resources/js/Components/MaintenanceContracts/MaintenanceContractAssetsWidget.vue`
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`
- Modify: `app/Http/Controllers/ServiceOrderController.php` and `app/Http/Controllers/MaintenanceContractController.php` (eager-load `assets.location` where `customerAssets`/`customer.assets` are built).

**Interfaces:**
- Produces: `mapAssetForSelect(asset)` → `{ id, name, brand, model, category, article_number, serial_number, is_bundle, next_service_date, location, thumbnail_url }`. `location` is `{ id, title, city }` or null. Consumed by Task 10's `AssetSelectMenu`.

- [ ] **Step 1: Add `mapAssetForSelect` to `Utilities.js`**

```js
export function mapAssetForSelect(asset) {
    const brand = asset.product?.brand?.name ?? '';
    const model = asset.product?.model ?? '';
    return {
        id: asset.id,
        name: `${brand} ${model}`.trim() || asset.serial_number || `Machine #${asset.id}`,
        brand,
        model,
        category: asset.product?.product_type?.name ?? null,
        article_number: asset.product?.part_no ?? null,
        serial_number: asset.serial_number,
        is_bundle: !!asset.product?.bundle,
        next_service_date: asset.next_service_date,
        location: asset.location ? { id: asset.location.id, title: asset.location.title, city: asset.location.city } : null,
        thumbnail_url: asset.product?.images?.length > 0 ? `/storage/${asset.product.images[0]?.path}` : null,
    };
}
```

- [ ] **Step 2: Use it in `MaintenanceContractAssetsWidget.vue`**

Replace the inline `availableAssets` `.map(...)` body with `mapAssetForSelect(a)` (import it from `@/Utilities/Utilities`). Keep the `.filter(...)` for already-attached assets.

- [ ] **Step 3: Use it in `ServiceOrders/ShowPage.vue`**

Find the `customerAssets` computed and replace its per-asset mapping with `mapAssetForSelect(a)` (import the helper).

- [ ] **Step 4: Eager-load `location` on the source queries**

In `ServiceOrderController::show`, add `'customer.assets.location'` to the `with([...])`. In `MaintenanceContractController` show (the method returning `maintenanceContract.customer.assets`), add `'customer.assets.location'` (or `assets.location` as appropriate to how `customer.assets` is loaded).

- [ ] **Step 5: Format + build + lint**

Run: `./vendor/bin/pint app/Http/Controllers/ServiceOrderController.php app/Http/Controllers/MaintenanceContractController.php && npm run fix:eslint && npm run build`

- [ ] **Step 6: Drive**

Confirm the contract "Machine toevoegen" selector and the service order asset selector both still list assets (regression check before Task 10 adds UI).

---

### Task 10: `AssetSelectMenu` — show location + search

**Files:**
- Modify: `resources/js/Components/UI/AssetSelectMenu.vue`

**Interfaces:**
- Consumes: assets shaped by `mapAssetForSelect` (has `brand`, `model`, `serial_number`, `location`).

- [ ] **Step 1: Add a client-side search state + filtered list**

In `<script setup>` add:

```js
import { ref, computed } from 'vue'

const search = ref('')

const filteredAssets = computed(() => {
    const term = search.value.trim().toLowerCase()
    if (!term) return props.assets
    return props.assets.filter((a) => {
        const haystack = [a.brand, a.model, a.serial_number, a.location?.title, a.location?.city]
            .filter(Boolean).join(' ').toLowerCase()
        return haystack.includes(term)
    })
})
```

Change the options `v-for` from `asset in assets` to `asset in filteredAssets`.

- [ ] **Step 2: Add the search input pinned at the top of `ListboxOptions`**

Immediately inside `<ListboxOptions ...>`, before the `v-for`, add a sticky search row. `@keydown.stop` prevents the Listbox from hijacking typing:

```vue
<div class="sticky top-0 z-10 bg-white dark:bg-gray-800 p-2 border-b border-gray-100 dark:border-slate-700">
    <input v-model="search" type="text" placeholder="Zoek op merk, model, serienummer of locatie"
        @keydown.stop @click.stop
        class="w-full rounded-md border border-gray-200 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-lavoro-blue" />
</div>
```

- [ ] **Step 3: Show location on each option row and on the selected display**

In the option row's secondary lines, add a location line after the serial/next-service line:

```vue
<p v-if="asset.location" :class="[active ? 'text-indigo-200' : 'text-gray-400 dark:text-slate-400', 'text-xs truncate']">
    {{ [asset.location.title, asset.location.city].filter(Boolean).join(' · ') }}
</p>
```

In the selected-asset display block (where `model.category`/`model.article_number` show), add a similar `v-if="model.location"` line.

- [ ] **Step 4: Reset search when the menu closes**

Add `@update:model-value` handling or watch the Listbox open state is not exposed; simplest: clear `search.value = ''` inside `onSelect` after a selection is made.

- [ ] **Step 5: Build + lint + drive**

Run: `npm run fix:eslint && npm run build`
Then verify in all three usages (service order add-asset, service order new-ticket, contract add-asset): the dropdown shows a search box, filters by brand/model/serial/location, shows each asset's location, and selecting still works.

---

# Phase 3 — ServiceOrder location

Ships: a werkbon can be pinned to a customer location; `resolved_location` drives calendar/PDF/exports.

### Task 11 (also completes Phase 1's delete flow): `service_orders.location_id` + `resolved_location` + location delete disposition

> Two independently useful deliverables share the "service order + location wiring" context; keep them one task only if executed together, otherwise split at Step 6.

**Files:**
- Create: `database/migrations/2026_07_14_120004_add_location_id_to_service_orders_table.php`
- Modify: `app/Models/ServiceOrder.php`
- Modify: `app/Http/Controllers/LocationController.php` (destroy disposition)
- Modify: `resources/js/Components/Locations/CustomerLocationsWidget.vue`, `resources/js/Pages/Locations/ShowPage.vue`, `resources/js/Pages/Locations/IndexPage.vue` (delete modal)
- Create: `resources/js/Components/Locations/LocationDeleteModal.vue`

**Interfaces:**
- Produces: `ServiceOrder::location()` belongsTo; appended attribute `resolved_location` (string|null). Consumed by Task 12 (exports) and Task 13 (UI).

- [ ] **Step 1: Migration**

```php
<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignIdFor(Location::class)->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Location::class);
        });
    }
};
```

- [ ] **Step 2: `ServiceOrder.php` — fillable, relation, `$with`, `$appends`, accessor**

Add `'location_id'` to `$fillable`. Add `'location'` to `$with` (currently `['serviceOrderStage']`). Add `'resolved_location'` to `$appends`. Add:

```php
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function getResolvedLocationAttribute(): ?string
    {
        if ($this->location) {
            return $this->location->addressLine();
        }
        if (!empty($this->execution_location)) {
            return $this->execution_location;
        }

        return $this->relationLoaded('project') ? $this->project?->location : null;
    }
```

- [ ] **Step 3: Migrate + format + verify accessor**

Run: `php artisan migrate && ./vendor/bin/pint app/Models/ServiceOrder.php`
Then: `php artisan tinker --execute="\$o = App\Models\ServiceOrder::first(); echo \$o->resolved_location ?? 'null';"`
Expected: prints the existing `execution_location` (or `null`) — proving the accessor is wired and back-compatible.

- [ ] **Step 4: Implement the location delete disposition in `LocationController::destroy`**

Replace the body of `destroy` with:

```php
    public function destroy(LocationDestroyRequest $request, Location $location)
    {
        $disposition = $request->input('disposition', 'detach');

        if ($disposition === 'move') {
            $location->assets()->update(['location_id' => $request->input('target_location_id')]);
        } else {
            $location->assets()->update(['location_id' => null]);
        }

        $location->delete();

        return redirect()->route('locations.index')->with('success', 'Locatie verwijderd.');
    }
```

- [ ] **Step 5: Create `LocationDeleteModal.vue`**

A `ModalDialog` shown when deleting a location. If the location has assets, present two choices (toggle-style, not X buttons): "Machines loskoppelen" (disposition `detach`) or "Verplaats naar andere locatie" (disposition `move` + a `ComboBox` of the customer's *other* locations). Confirm issues `router.delete('/locations/'+id, { data: { disposition, target_location_id } })`. If the location has no assets, confirm deletes directly. Props: `defineProps({ location: Object, otherLocations: Array })`.

- [ ] **Step 6: Wire the modal into the delete affordances**

On `Locations/ShowPage.vue` (a "Verwijderen" button) and in `CustomerLocationsWidget.vue` (trash icon per row), open `LocationDeleteModal` instead of deleting directly. Pass the customer's other locations for the "move" option.

- [ ] **Step 7: Build + lint + drive**

Run: `npm run fix:eslint && npm run build`
Then: create a location with an asset, delete it choosing "move" → asset now at the target location; repeat choosing "detach" → asset's location cleared (still on customer). Delete an empty location → deletes without prompting for disposition.

---

### Task 12: Wire `resolved_location` into store/exports + worklist prefill

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php` (store validation; the `executionLocation` prop build; worklist create prefill)
- Modify: `app/Services/PlannerExportService.php`
- Modify: `app/Services/Google/EventPayloadBuilder.php`

**Interfaces:**
- Consumes: `ServiceOrder->resolved_location`.

- [ ] **Step 1: Accept `location_id` in `ServiceOrderController::store`**

In `store`, extend the inline validation and creation:

```php
        $serviceorder = ServiceOrder::create($request->validate([
            'customer_id' => 'required|exists:customers,id',
            'project_id' => 'nullable|exists:projects,id',
            'location_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('locations', 'id')
                    ->where(fn ($q) => $q->where('customer_id', $request->input('customer_id'))),
            ],
        ]));
```

- [ ] **Step 2: Prefill `location_id` when creating from the worklist**

In `store`, after assets are attached, if no `location_id` was passed and all attached assets share one non-null `location_id`, set it:

```php
        if (!$serviceorder->location_id && $request->has('assets')) {
            $location_ids = \App\Models\Asset::whereIn('id', $request->input('assets'))
                ->pluck('location_id')->filter()->unique();
            if ($location_ids->count() === 1) {
                $serviceorder->location_id = $location_ids->first();
                $serviceorder->save();
            }
        }
```

- [ ] **Step 3: Use `resolved_location` in the `executionLocation` prop build**

Find the line building `$execution_location` (currently `$first_event?->location ?: $serviceorder->execution_location ?: $serviceorder->project?->location`). Replace with location-wins logic:

```php
        $execution_location = $serviceorder->location_id
            ? $serviceorder->resolved_location
            : ($first_event?->location ?: $serviceorder->resolved_location);
```

- [ ] **Step 4: Update the two export services**

In `PlannerExportService.php` and `EventPayloadBuilder.php`, wherever they read `$service_order->execution_location`, read `$service_order->resolved_location` instead (keeping any existing null-guards).

- [ ] **Step 5: Format + verify**

Run: `./vendor/bin/pint app/Http/Controllers/ServiceOrderController.php app/Services/PlannerExportService.php app/Services/Google/EventPayloadBuilder.php`
Then create a werkbon from the worklist for assets sharing a location and confirm its `location_id` is set: `php artisan tinker --execute="echo App\Models\ServiceOrder::latest('id')->first()->location_id ?? 'null';"`

---

### Task 13: ServiceOrder ShowPage location combobox

**Files:**
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

**Interfaces:**
- Consumes: `combo/customers/{customer}/locations`; `serviceOrder.location_id`, `serviceOrder.execution_location`.

- [ ] **Step 1: Load the customer's locations**

Add:

```js
const locationOptions = ref([]);
onMounted(async () => {
    if (serviceOrder.customer_id) {
        const { data } = await axios.get(`/combo/customers/${serviceOrder.customer_id}/locations`);
        locationOptions.value = data;
    }
});
```

- [ ] **Step 2: Add the combobox and make the freeform field conditional**

Near the existing "Uitvoeringslocatie" `EditableTextField` (bound to `form.execution_location`), add above it:

```vue
<ComboBox :options="locationOptions" v-model="form.location_id" label="Locatie"
    placeholder="Geen locatie (vrije invoer)" @update:model-value="val => { form.location_id = val; patchLocation(); }" />
```

Wrap the existing freeform `execution_location` field in `v-if="!form.location_id"` so it hides when a location is selected. Add `location_id` to the page's `useForm({...})` initialized from `serviceOrder.location_id`.

- [ ] **Step 3: Persist the change**

Add a `patchLocation()` that saves `location_id` (and clears `execution_location` when a location is chosen) using the page's existing save mechanism (match how `execution_location` is currently persisted around the `watch(() => form.execution_location, ...)` block).

- [ ] **Step 4: Build + lint + drive**

Run: `npm run fix:eslint && npm run build`
Then on a werkbon: pick a location → freeform field hides, and the displayed/exported location uses the location address; clear it → freeform reappears and is used.

---

# Phase 4 — Contracts: derived locations

### Task 14: Derived `locations` on contract + display + generation grouping

**Files:**
- Modify: `app/Models/MaintenanceContract.php`
- Modify: `app/Http/Controllers/MaintenanceContractController.php` (eager-load `assets.location`)
- Modify: `resources/js/Pages/MaintenanceContracts/ShowPage.vue`
- Modify: the contract → service-order generation code (locate: `grep -rn "generateServiceOrders\|generatedServiceOrders" app/`).

**Interfaces:**
- Produces: `MaintenanceContract->locations` (collection of distinct `Location` from its assets).

- [ ] **Step 1: Add the derived accessor to `MaintenanceContract.php`**

```php
    public function getLocationsAttribute()
    {
        return $this->assets->map->location->filter()->unique('id')->values();
    }
```

- [ ] **Step 2: Eager-load `assets.location` in the contract show controller**

In the method returning `MaintenanceContracts/ShowPage`, ensure the contract loads `assets.location` (add `'assets.location'` to the `with`/`load`).

- [ ] **Step 3: Show "Locaties in dit contract" on the contract page**

Add a read-only chip/list derived from the assets. Because the accessor needs `assets` loaded with `location`, expose it explicitly — either append `locations` for this response or compute client-side from `maintenanceContract.assets`:

```vue
<div v-if="contractLocations.length" class="mt-2 flex flex-wrap gap-1">
    <span class="text-xs text-gray-500 dark:text-slate-400 mr-1">Locaties:</span>
    <BadgeComponent v-for="loc in contractLocations" :key="loc.id" color="gray" :has-dot="false"
        :url="`/locations/${loc.id}`">{{ loc.title }}</BadgeComponent>
</div>
```

```js
const contractLocations = computed(() => {
    const map = new Map();
    (props.maintenanceContract.assets || []).forEach(a => { if (a.location) map.set(a.location.id, a.location); });
    return [...map.values()];
});
```

- [ ] **Step 4: Group generated werkbonnen by location**

In the generation logic, when creating service orders from the contract's assets, group assets by `location_id` (assets sharing a `location_id` → one order; location-less assets → one order per the current grouping rule) and set each generated order's `location_id` from its group. Keep the existing per-order behavior otherwise (see the contract-generation grouping decision).

- [ ] **Step 5: Format + build + lint + drive**

Run: `./vendor/bin/pint app/Models/MaintenanceContract.php app/Http/Controllers/MaintenanceContractController.php && npm run fix:eslint && npm run build`
Then: on a contract with assets at two locations, confirm the "Locaties" chips show both; generate werkbonnen and confirm each generated order carries the right `location_id`.

---

# Phase 5 — Worklist + map regroup (highest risk)

### Task 15: Generalize `useCustomerMapMarkers` coords URL

**Files:**
- Modify: `resources/js/Composables/useCustomerMapMarkers.js`

**Interfaces:**
- Produces: optional `coordsUrl(item)` param; default preserves current `/customers/${id}/coords`.

- [ ] **Step 1: Add the `coordsUrl` option**

In the destructured options add `coordsUrl = (item) => `/customers/${item.id}/coords``. Replace the hardcoded persist call:

```js
await axios.patch(coordsUrl(item), { lat: item.lat, lon: item.lon });
```

- [ ] **Step 2: Build + lint + drive (regression)**

Run: `npm run fix:eslint && npm run build`
Then open the existing customer map (`/upcomingactivities/map`) and confirm markers still geocode + persist exactly as before (default path unchanged).

---

### Task 16: `ActivityListController` — group worklist + map by location

**Files:**
- Modify: `app/Http/Controllers/ActivityListController.php`

**Interfaces:**
- Produces: worklist grouped as `Customer → Location → assets` with a per-customer "no location" bucket; map items array where each item has `type: 'location' | 'customer'`, `id`, `name`, `address`, `postal_code`, `city`, `lat`, `lon`, and its assets.

- [ ] **Step 1: Reshape `buildCustomerAssetList`**

After collecting a customer's inner assets, split them into groups keyed by `location_id` (null = the customer bucket). Attach to each customer a `location_groups` structure the frontend iterates, e.g.:

```php
$groups = $assets->groupBy('location_id')->map(function ($group, $location_id) {
    $location = $group->first()->location;
    return [
        'location' => $location ? $location->only(['id', 'title', 'address', 'postal_code', 'city', 'lat', 'lon']) : null,
        'assets' => $group->values(),
    ];
})->values();
$main->customer->setAttribute('location_groups', $groups);
```

Ensure the inner query eager-loads `location` (`->with([... 'location'])`).

- [ ] **Step 2: Reshape `map` to emit location + customer items**

Build a combined collection: one item per location that has eligible assets (using the location's own `lat/lon`/address), plus one customer item per customer that has location-less eligible assets (customer address, as today). Tag each with `type`. Keep the existing `has_expired_assets`/`next_service_in_days` computation per item.

```php
return inertia('ActivityList/UpcomingActivitiesMap', [
    'items' => $items,   // each: { type, id, name, address, postal_code, city, lat, lon, ... }
]);
```

- [ ] **Step 3: Format + verify JSON shape**

Run: `./vendor/bin/pint app/Http/Controllers/ActivityListController.php`
Then hit `/upcomingactivities` and `/upcomingactivities/map` while logged in and inspect the Inertia props (Vue devtools / network) to confirm `location_groups` and `items[].type` are present.

---

### Task 17: Worklist + map frontend regroup

**Files:**
- Modify: `resources/js/Pages/ActivityList/UpcomingActivities.vue`
- Modify: `resources/js/Components/CustomerUpcomingActivity.vue`
- Modify: `resources/js/Pages/ActivityList/UpcomingActivitiesMap.vue`

**Interfaces:**
- Consumes: `customer.location_groups` (worklist) and `items` with `type` (map).

- [ ] **Step 1: Render location sub-groups in `CustomerUpcomingActivity.vue`**

Iterate `mainAsset.customer.location_groups`; for each group render a location sub-header (title + city, or "Geen locatie" when `group.location` is null) followed by that group's asset rows (the existing asset row markup, now iterating `group.assets` instead of `customer.upcoming_assets`). Keep the existing checkbox/selection semantics per asset; the "select all" and `customerState` helpers continue to operate at the customer level.

- [ ] **Step 2: Ensure the create-werkbon selection carries a single location**

In `UpcomingActivities.vue`, no change is required to the payload (the backend prefills `location_id` from the selected assets in Task 12). Verify selecting assets from a single location produces an order with that location; document in the drive step.

- [ ] **Step 3: Point the map at the generalized composable + new items**

In `UpcomingActivitiesMap.vue`, pass `items` (mixed customer/location) to `useCustomerMapMarkers` and supply:

```js
coordsUrl: (item) => item.type === 'location' ? `/locations/${item.id}/coords` : `/customers/${item.id}/coords`,
```

The popup component and `markerColor` continue to work on the item shape; adjust the popup to read `item.name`/`item.type` labels.

- [ ] **Step 4: Build + lint + drive (careful regression)**

Run: `npm run fix:eslint && npm run build`
Then verify: the worklist shows customers with per-location sub-groups (and a "Geen locatie" bucket for location-less assets); selection + "select all" still work; creating a werkbon from a single-location selection sets the order's location; the map plots location pins at the locations' own addresses (geocoding + persisting to `/locations/{id}/coords`) and customer pins for location-less assets, with no repeated Nominatim calls on reload (coords persisted).

---

## Self-Review

**Spec coverage** — every spec section maps to a task:
- Location model/table/`addressLine` → Task 1. `location_code` unique per customer → Tasks 1 (DB) + 3 (rules). No backfill → all migrations additive/nullable.
- Permissions/policy/requests/controller/routes/combo → Tasks 2–4.
- Menu child + Index/Show + customer section → Tasks 5–6.
- Asset↔location FK + validation + picker + AssetSelectMenu (location + search) → Tasks 7–10.
- ServiceOrder `location_id` + `resolved_location` + combobox + exports + worklist prefill → Tasks 11–13.
- Contracts derive locations from assets (no pivot) + generation grouping → Task 14.
- Worklist/map regroup by location + geocoding/cache reuse (server cache untouched; per-row persistence via `coordsUrl`) → Tasks 15–17.
- Delete disposition (move/detach) → Task 11.
- Events/ServiceJobs: no schema change (read-through) — intentionally no task; `resolved_location` + export changes (Task 12) cover the calendar/PDF path.

**Placeholder scan** — no "TBD/TODO"; each code step carries real code. Page-heavy Vue tasks (5, 6, 8, 13, 17) reference named sibling templates for boilerplate and give concrete code for the location-specific parts — acceptable in this existing codebase, not placeholders.

**Type consistency** — `mapAssetForSelect` shape (Task 9) matches `AssetSelectMenu`'s consumed fields (Task 10: `brand`, `model`, `serial_number`, `location.{title,city}`). `resolved_location` accessor name consistent across Tasks 11–13 and exports. Combo endpoint name `combo.customer.locations` and shape `{id,name}` consistent across Tasks 4, 8, 13. `coordsUrl` signature consistent across Tasks 15 and 17. Map `items[].type` consistent across Tasks 16–17.

## Notes for the executor
- Migration timestamps (`2026_07_14_1200xx`) must sort after existing `2026_07_14_000001` and keep the given order (locations → permissions → assets.location_id → service_orders.location_id).
- Confirm the exact asset store/update request class name(s) before Task 7 (`grep -rl "extends FormRequest" app/Http/Requests | xargs grep -l "customer_id"`), and the exact asset edit page before Task 8.
- Phases are independently shippable; stop for review at each phase boundary.
