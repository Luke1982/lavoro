# MaintenanceContract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `MaintenanceContract` resource — subsidiary to `Customer` like `Contact` — with recurring pricing, a per-asset service-frequency morph pivot (`assetables`), activity logging, and remarks.

**Architecture:** Standard Laravel model/policy/FormRequest/controller stack following this repo's existing conventions (`Contact` for CRUD shape, `ServiceOrder`+`materiables` for the morph-pivot-with-extra-columns pattern). Inertia/Vue frontend reusing existing primitives (`EditableTextField`, `ComboBox`, `CurrencyInput`, `SwitchComponent`, `DrawerComponent`, `TimelineComponent`, `RemarksComponent`) — no new generic components except one contract-specific asset widget.

**Tech Stack:** Laravel 12, Inertia + Vue 3, MySQL.

## Global Constraints

- PHP: snake_case for all variable names.
- No inline comments; prefer clear names and docblocks only when needed.
- Don't propose git commands or workflows — every commit step below is for the *implementer* to run themselves, not something to execute automatically without the user's own git workflow in mind (the "Commit" steps use plain `git commit`; note this project's actual convention is to hold commits for user approval — see `docs/superpowers/specs/2026-07-10-maintenancecontract-design.md`. Skip the "Commit" step in each task unless the user has told you to commit as you go.)
- Don't write tests unless asked (this project's CLAUDE.md explicitly overrides this plan template's default TDD/PHPUnit steps — verification below uses `php artisan tinker`, `php -l`, `php artisan route:list`, and a final manual browser walkthrough instead of PHPUnit).
- In Laravel, always check authorization via Form Requests (`authorize()`) and/or policies.
- Reuse the `userables` pivot with `type` column for role-like distinctions — not applicable to this feature (no owner/executing-user concept here).
- Validation belongs in Form Request `rules()` only; frontend only displays `form.errors`.
- Selecting/toggling in UI components: clicking a selected item deselects it — never add separate X / clear buttons.
- String concatenation always uses spaces: `$string . ' some other string'`.

## Critical Implementation Note: enum ↔ ComboBox value mapping

`ContractInterval` is cast on the model via Laravel's native enum cast (`ContractInterval::class`), the same way `ServiceOrder::type` is cast to `ServiceOrderTypes::class`. A *cast* enum column stores and matches on the case **value** (e.g. `"Maandelijks"`), not the case name.

`EnumComboBoxArrayTrait::comboBoxArray()` (used by `TicketPriorities` etc.) returns `{id: case->name, name: case->value}` — e.g. `{id: 'maandelijks', name: 'Maandelijks'}`. Binding a `ComboBox`/`EditableTextField[type=combobox]` directly to that array would submit the **name** (`'maandelijks'`), which will NOT match the enum cast (expects `'Maandelijks'`) or the `in:` validation rule (built from `validationString()`, which lists case values) — a silent, confusing validation failure.

This exact problem already exists in the codebase for `Ticket::priority` (a plain, uncast enum-shaped column), and it's already solved there: `resources/js/Pages/Tickets/IndexPage.vue:368` remaps the combo options for direct-binding usage:

```js
const priorityRowOptions = computed(() => props.priorityOptions.map(o => ({ id: o.name, name: o.name })))
```

**Every Vue component in this plan that binds a `ComboBox`/`EditableTextField` directly to `price_interval` or `frequency` must apply this same one-line remap** (`{ id: o.name, name: o.name }`, where `o.name` is `comboBoxArray()`'s value-holding `name` field) before passing it as `:options`. This is called out again in each relevant task below — don't skip it.

---

## Task 1: `ContractInterval` enum

**Files:**
- Create: `app/Enums/ContractInterval.php`

**Interfaces:**
- Produces: `App\Enums\ContractInterval` (string-backed enum), cases `maandelijks`, `halfjaarlijks`, `jaarlijks`, `aangepast`. Static methods `comboBoxArray(): array` and `validationString(): string` via `EnumComboBoxArrayTrait`.

- [ ] **Step 1: Write the enum**

```php
<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum ContractInterval: string
{
    use EnumComboBoxArrayTrait;

    case maandelijks = 'Maandelijks';
    case halfjaarlijks = 'Halfjaarlijks';
    case jaarlijks = 'Jaarlijks';
    case aangepast = 'Aangepast (dagen)';
}
```

- [ ] **Step 2: Verify via tinker**

Run: `php artisan tinker --execute="print_r(App\Enums\ContractInterval::comboBoxArray()); echo App\Enums\ContractInterval::validationString();"`

Expected output:
```
Array
(
    [0] => Array
        (
            [id] => maandelijks
            [name] => Maandelijks
        )
    [1] => Array
        (
            [id] => halfjaarlijks
            [name] => Halfjaarlijks
        )
    [2] => Array
        (
            [id] => jaarlijks
            [name] => Jaarlijks
        )
    [3] => Array
        (
            [id] => aangepast
            [name] => Aangepast (dagen)
        )
)
Maandelijks,Halfjaarlijks,Jaarlijks,Aangepast (dagen)
```

(`validationString()` joins case **values**, not names — the last line lists the Dutch labels comma-separated, not the lowercase case identifiers.)

- [ ] **Step 3: Commit** (only if the user has told you to commit as you go — see Global Constraints)

```bash
git add app/Enums/ContractInterval.php
git commit -m "feat(maintenancecontract): add ContractInterval enum"
```

---

## Task 2: Migrations — `maintenance_contracts` and `assetables` tables

**Files:**
- Create: `database/migrations/2026_07_10_100001_create_maintenance_contracts_table.php`
- Create: `database/migrations/2026_07_10_100002_create_assetables_table.php`

**Interfaces:**
- Produces: `maintenance_contracts` table (columns: `customer_id`, `title`, `start_date`, `end_date`, `price`, `price_interval`, `price_interval_days`, `manage_frequency_per_asset`, `frequency`, `frequency_days`, timestamps). `assetables` table (columns: `asset_id`, `assetable_type`, `assetable_id`, `frequency`, `frequency_days`, timestamps; unique on `[asset_id, assetable_type, assetable_id]`).

- [ ] **Step 1: Write the `maintenance_contracts` migration**

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
        Schema::create('maintenance_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('price_interval');
            $table->unsignedInteger('price_interval_days')->nullable();
            $table->boolean('manage_frequency_per_asset')->default(false);
            $table->string('frequency')->nullable();
            $table->unsignedInteger('frequency_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_contracts');
    }
};
```

- [ ] **Step 2: Write the `assetables` migration**

```php
<?php

use App\Models\Asset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assetables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Asset::class)->constrained()->cascadeOnDelete();
            $table->morphs('assetable');
            $table->string('frequency')->nullable();
            $table->unsignedInteger('frequency_days')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'assetable_type', 'assetable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetables');
    }
};
```

- [ ] **Step 3: Run the migrations**

Run: `php artisan migrate`
Expected: both `2026_07_10_100001_create_maintenance_contracts_table` and `2026_07_10_100002_create_assetables_table` show as `DONE` in the output, no errors.

- [ ] **Step 4: Verify the schema**

Run: `php artisan tinker --execute="var_dump(Schema::hasColumns('maintenance_contracts', ['customer_id','title','start_date','end_date','price','price_interval','price_interval_days','manage_frequency_per_asset','frequency','frequency_days'])); var_dump(Schema::hasColumns('assetables', ['asset_id','assetable_type','assetable_id','frequency','frequency_days']));"`

Expected: `bool(true)` twice.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_10_100001_create_maintenance_contracts_table.php database/migrations/2026_07_10_100002_create_assetables_table.php
git commit -m "feat(maintenancecontract): add maintenance_contracts and assetables tables"
```

---

## Task 3: `MaintenanceContract` model + `Asset`/`Customer` relation additions

**Files:**
- Create: `app/Models/MaintenanceContract.php`
- Modify: `app/Models/Asset.php` (add `maintenanceContracts()`)
- Modify: `app/Models/Customer.php` (add `maintenanceContracts()`)

**Interfaces:**
- Consumes: `App\Enums\ContractInterval` (Task 1), `maintenance_contracts`/`assetables` tables (Task 2), `App\Models\Traits\HasActivities`, `App\Models\Traits\RemarkableTrait` (existing).
- Produces:
  - `MaintenanceContract::customer(): BelongsTo`
  - `MaintenanceContract::assets(): MorphToMany` — pivot columns `id`, `frequency`, `frequency_days`
  - `MaintenanceContract->display_title: string` (appended)
  - `MaintenanceContract->status: string` (appended, one of `toekomstig`/`actief`/`verlopen`)
  - `Asset::maintenanceContracts(): MorphToMany`
  - `Customer::maintenanceContracts(): HasMany`

- [ ] **Step 1: Write the model**

```php
<?php

namespace App\Models;

use App\Enums\ContractInterval;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RemarkableTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MaintenanceContract extends Model
{
    use HasActivities;
    use RemarkableTrait;

    protected $fillable = [
        'customer_id',
        'title',
        'start_date',
        'end_date',
        'price',
        'price_interval',
        'price_interval_days',
        'manage_frequency_per_asset',
        'frequency',
        'frequency_days',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'price' => 'decimal:2',
        'price_interval' => ContractInterval::class,
        'manage_frequency_per_asset' => 'boolean',
        'frequency' => ContractInterval::class,
    ];

    protected $appends = ['display_title', 'status'];

    protected static function booted(): void
    {
        static::deleting(function (MaintenanceContract $contract) {
            $id = $contract->id;
            $morph_class = MaintenanceContract::class;
            $pivot_tables = [
                'assetables' => 'assetable',
                'activityables' => 'activityable',
                'remarkables' => 'remarkable',
            ];

            foreach ($pivot_tables as $table => $morph) {
                DB::table($table)
                    ->where("{$morph}_type", $morph_class)
                    ->where("{$morph}_id", $id)
                    ->delete();
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assets()
    {
        return $this->morphToMany(Asset::class, 'assetable')
            ->withPivot(['id', 'frequency', 'frequency_days'])
            ->withTimestamps();
    }

    public function getDisplayTitleAttribute(): string
    {
        if (! empty($this->attributes['title'])) {
            return $this->attributes['title'];
        }

        $customer_name = $this->customer?->name ?? 'Onbekende klant';
        $start = $this->start_date ? Carbon::parse($this->start_date)->format('d-m-Y') : '?';
        $end = $this->end_date ? Carbon::parse($this->end_date)->format('d-m-Y') : 'heden';

        return $customer_name . ' — ' . $start . ' t/m ' . $end;
    }

    public function getStatusAttribute(): string
    {
        $today = Carbon::today();
        $start = $this->start_date ? Carbon::parse($this->start_date) : null;
        $end = $this->end_date ? Carbon::parse($this->end_date) : null;

        if ($start && $today->lt($start)) {
            return 'toekomstig';
        }

        if ($end && $today->gt($end)) {
            return 'verlopen';
        }

        return 'actief';
    }
}
```

- [ ] **Step 2: Add the inverse relation to `Asset`**

In `app/Models/Asset.php`, add after the `parentAssets()` method (before the final closing `}`):

```php
    public function maintenanceContracts()
    {
        return $this->morphedByMany(MaintenanceContract::class, 'assetable')
            ->withPivot(['id', 'frequency', 'frequency_days'])
            ->withTimestamps();
    }
```

- [ ] **Step 3: Add the relation to `Customer`**

In `app/Models/Customer.php`, add directly after the `activeAssets()` method (around line 54):

```php
    public function maintenanceContracts()
    {
        return $this->hasMany(MaintenanceContract::class)->orderByDesc('start_date');
    }
```

- [ ] **Step 4: Verify via tinker — create, relate, accessors, cleanup**

Run this as one `php artisan tinker` session (paste the whole block):

```php
$customer = App\Models\Customer::first();
$asset = App\Models\Asset::where('customer_id', $customer->id)->first();

$contract = App\Models\MaintenanceContract::create([
    'customer_id' => $customer->id,
    'start_date' => now()->subDay(),
    'end_date' => null,
    'price' => 199.99,
    'price_interval' => App\Enums\ContractInterval::maandelijks,
    'manage_frequency_per_asset' => false,
    'frequency' => App\Enums\ContractInterval::jaarlijks,
]);

echo $contract->display_title . PHP_EOL;
echo $contract->status . PHP_EOL;
echo $contract->price_interval->value . PHP_EOL;

if ($asset) {
    $contract->assets()->attach($asset->id, ['frequency' => null, 'frequency_days' => null]);
    echo $contract->assets()->count() . PHP_EOL;
    echo $asset->maintenanceContracts()->count() . PHP_EOL;
}

$contract_id = $contract->id;
$contract->delete();
echo DB::table('assetables')->where('assetable_type', App\Models\MaintenanceContract::class)->where('assetable_id', $contract_id)->count() . PHP_EOL;
```

Expected:
- `display_title` prints `"{customer name} — {yesterday's date} t/m heden"` (no title was set)
- `status` prints `actief`
- `price_interval->value` prints `Maandelijks`
- if an asset existed: both counts print `1`
- final line prints `0` (pivot row cleaned up on delete)

- [ ] **Step 5: Commit**

```bash
git add app/Models/MaintenanceContract.php app/Models/Asset.php app/Models/Customer.php
git commit -m "feat(maintenancecontract): add MaintenanceContract model and Asset/Customer relations"
```

---

## Task 4: Permissions migration

**Files:**
- Create: `database/migrations/2026_07_10_100003_seed_maintenancecontract_permissions.php`

**Interfaces:**
- Produces: 7 `permissions` rows — `maintenancecontract.read`, `maintenancecontract.create`, `maintenancecontract.update`, `maintenancecontract.delete`, `assetable.create.maintenancecontract`, `assetable.update.maintenancecontract`, `assetable.delete.maintenancecontract`.

- [ ] **Step 1: Write the migration**

```php
<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'maintenancecontract.read', 'label' => 'Onderhoudscontracten bekijken'],
        ['name' => 'maintenancecontract.create', 'label' => 'Onderhoudscontracten aanmaken'],
        ['name' => 'maintenancecontract.update', 'label' => 'Onderhoudscontracten wijzigen'],
        ['name' => 'maintenancecontract.delete', 'label' => 'Onderhoudscontracten verwijderen'],
        ['name' => 'assetable.create.maintenancecontract', 'label' => 'Machine aan onderhoudscontract koppelen'],
        ['name' => 'assetable.update.maintenancecontract', 'label' => 'Machinefrequentie op onderhoudscontract bijwerken'],
        ['name' => 'assetable.delete.maintenancecontract', 'label' => 'Machine van onderhoudscontract loskoppelen'],
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

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_07_10_100003_seed_maintenancecontract_permissions ... DONE`

- [ ] **Step 3: Verify**

Run: `php artisan tinker --execute="echo App\Models\Permission::where('name', 'like', '%maintenancecontract%')->count();"`
Expected: `7`

- [ ] **Step 4: Grant the permissions to your own admin/testing user (needed for Task 13's manual walkthrough)**

Check how role grants work for this app before running anything — inspect `database/seeders/data/*_permissions.php` for the role you test with (an admin role likely already has full access via `User::hasPermission`'s admin bypass — if your test user is an admin, no grant is needed at all). If not an admin, attach the 7 new permission names to the appropriate role the same way an existing resource's permissions are attached in `database/seeders/data/`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_10_100003_seed_maintenancecontract_permissions.php
git commit -m "feat(maintenancecontract): seed maintenancecontract and assetable permissions"
```

---

## Task 5: `MaintenanceContractPolicy` + Form Requests

**Files:**
- Create: `app/Policies/MaintenanceContractPolicy.php`
- Create: `app/Http/Requests/MaintenanceContractStoreRequest.php`
- Create: `app/Http/Requests/MaintenanceContractUpdateRequest.php`
- Create: `app/Http/Requests/MaintenanceContractReadRequest.php`
- Create: `app/Http/Requests/MaintenanceContractDestroyRequest.php`
- Create: `app/Http/Requests/MaintenanceContractAttachAssetRequest.php`
- Create: `app/Http/Requests/MaintenanceContractUpdateAssetableRequest.php`
- Create: `app/Http/Requests/MaintenanceContractDetachAssetRequest.php`

**Interfaces:**
- Consumes: `App\Models\MaintenanceContract` (Task 3), `App\Enums\ContractInterval` (Task 1), `App\Rules\DbRange` (existing).
- Produces: policy methods `viewAny`, `view`, `create`, `update`, `delete`, `attachAsset`, `updateAssetable`, `detachAsset` (all `(User $user[, MaintenanceContract $maintenanceContract]): bool`). No explicit `Gate::policy()` registration needed — Laravel auto-discovers `App\Policies\MaintenanceContractPolicy` from `App\Models\MaintenanceContract` by naming convention (confirmed: `ContactPolicy`/`ProductPolicy`/`ServiceOrderPolicy` are not registered in `AppServiceProvider` either).

- [ ] **Step 1: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\MaintenanceContract;
use App\Models\User;

class MaintenanceContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('maintenancecontract.read');
    }

    public function view(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('maintenancecontract.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('maintenancecontract.create');
    }

    public function update(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('maintenancecontract.update');
    }

    public function delete(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('maintenancecontract.delete');
    }

    public function attachAsset(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('assetable.create.maintenancecontract');
    }

    public function updateAssetable(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('assetable.update.maintenancecontract');
    }

    public function detachAsset(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('assetable.delete.maintenancecontract');
    }
}
```

- [ ] **Step 2: Write `MaintenanceContractStoreRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Models\MaintenanceContract;
use App\Rules\DbRange;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MaintenanceContract::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'price' => ['required', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'price_interval' => ['required', 'string', 'in:' . ContractInterval::validationString()],
            'price_interval_days' => [
                'required_if:price_interval,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
            'manage_frequency_per_asset' => ['boolean'],
            'frequency' => [
                'required_if:manage_frequency_per_asset,false',
                'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'frequency_days' => [
                'required_if:frequency,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
        ];
    }
}
```

- [ ] **Step 3: Write `MaintenanceContractUpdateRequest`**

Same field set, each rule prefixed with `sometimes` (partial-PATCH support, matching `ContactUpdateRequest`):

```php
<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Models\MaintenanceContract;
use App\Rules\DbRange;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('maintenancecontract'));
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'required', 'exists:customers,id'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'price_interval' => ['sometimes', 'required', 'string', 'in:' . ContractInterval::validationString()],
            'price_interval_days' => [
                'sometimes', 'required_if:price_interval,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
            'manage_frequency_per_asset' => ['sometimes', 'boolean'],
            'frequency' => [
                'sometimes', 'required_if:manage_frequency_per_asset,false',
                'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'frequency_days' => [
                'sometimes', 'required_if:frequency,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
        ];
    }
}
```

- [ ] **Step 4: Write `MaintenanceContractReadRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $maintenancecontract = $this->route('maintenancecontract');

        return $maintenancecontract
            ? $this->user()->can('view', $maintenancecontract)
            : $this->user()->can('viewAny', \App\Models\MaintenanceContract::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
```

- [ ] **Step 5: Write `MaintenanceContractDestroyRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('maintenancecontract'));
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 6: Write `MaintenanceContractAttachAssetRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MaintenanceContractAttachAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attachAsset', $this->route('maintenancecontract'));
    }

    public function rules(): array
    {
        $maintenancecontract = $this->route('maintenancecontract');
        $manage_per_asset = (bool) $maintenancecontract?->manage_frequency_per_asset;

        return [
            'frequency' => [
                $manage_per_asset ? 'nullable' : 'prohibited',
                'string', 'in:' . ContractInterval::validationString(),
            ],
            'frequency_days' => [
                'required_if:frequency,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $asset = $this->route('asset');
            $maintenancecontract = $this->route('maintenancecontract');
            if ($asset && $maintenancecontract && $asset->customer_id !== $maintenancecontract->customer_id) {
                $validator->errors()->add('asset', 'Deze machine hoort niet bij de klant van dit contract.');
            }
        });
    }
}
```

- [ ] **Step 7: Write `MaintenanceContractUpdateAssetableRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractUpdateAssetableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateAssetable', $this->route('maintenancecontract'));
    }

    public function rules(): array
    {
        $maintenancecontract = $this->route('maintenancecontract');
        $manage_per_asset = (bool) $maintenancecontract?->manage_frequency_per_asset;

        return [
            'frequency' => [
                $manage_per_asset ? 'sometimes' : 'prohibited',
                'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'frequency_days' => [
                'sometimes', 'required_if:frequency,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
        ];
    }
}
```

- [ ] **Step 8: Write `MaintenanceContractDetachAssetRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractDetachAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('detachAsset', $this->route('maintenancecontract'));
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 9: Syntax-check every new file**

Run:
```bash
php -l app/Policies/MaintenanceContractPolicy.php
php -l app/Http/Requests/MaintenanceContractStoreRequest.php
php -l app/Http/Requests/MaintenanceContractUpdateRequest.php
php -l app/Http/Requests/MaintenanceContractReadRequest.php
php -l app/Http/Requests/MaintenanceContractDestroyRequest.php
php -l app/Http/Requests/MaintenanceContractAttachAssetRequest.php
php -l app/Http/Requests/MaintenanceContractUpdateAssetableRequest.php
php -l app/Http/Requests/MaintenanceContractDetachAssetRequest.php
```
Expected: `No syntax errors detected in ...` for all 8 files.

- [ ] **Step 10: Verify the Store/Update rules behave correctly via tinker**

```php
$rules = (new App\Http\Requests\MaintenanceContractStoreRequest())->rules();

$bad = Illuminate\Support\Facades\Validator::make([
    'customer_id' => App\Models\Customer::first()->id,
    'start_date' => '2026-01-01',
    'price' => 100,
    'price_interval' => 'Aangepast (dagen)',
    'manage_frequency_per_asset' => false,
    'frequency' => 'Jaarlijks',
], $rules);
echo $bad->fails() ? 'FAILS (expected, missing price_interval_days)' : 'PASSES (unexpected)';
echo PHP_EOL;

$good = Illuminate\Support\Facades\Validator::make([
    'customer_id' => App\Models\Customer::first()->id,
    'start_date' => '2026-01-01',
    'price' => 100,
    'price_interval' => 'Maandelijks',
    'manage_frequency_per_asset' => false,
    'frequency' => 'Jaarlijks',
], $rules);
echo $good->fails() ? 'FAILS (unexpected)' : 'PASSES (expected)';
```

Expected:
```
FAILS (expected, missing price_interval_days)
PASSES (expected)
```

- [ ] **Step 11: Commit**

```bash
git add app/Policies/MaintenanceContractPolicy.php app/Http/Requests/MaintenanceContract*.php
git commit -m "feat(maintenancecontract): add policy and form requests"
```

---

## Task 6: `MaintenanceContractController` + routes

**Files:**
- Create: `app/Http/Controllers/MaintenanceContractController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: everything from Tasks 1–5.
- Produces: named routes `maintenancecontracts.index`, `.show`, `.store`, `.update`, `.destroy`, `.attachAsset`, `.updateAssetable`, `.detachAsset`.

- [ ] **Step 1: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceContractAttachAssetRequest;
use App\Http\Requests\MaintenanceContractDestroyRequest;
use App\Http\Requests\MaintenanceContractDetachAssetRequest;
use App\Http\Requests\MaintenanceContractReadRequest;
use App\Http\Requests\MaintenanceContractStoreRequest;
use App\Http\Requests\MaintenanceContractUpdateAssetableRequest;
use App\Http\Requests\MaintenanceContractUpdateRequest;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\MaintenanceContract;

class MaintenanceContractController extends Controller
{
    public function index(MaintenanceContractReadRequest $request)
    {
        $search = trim((string) $request->input('search', ''));

        $query = MaintenanceContract::query()->with('customer');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($search !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));
        }

        $maintenancecontracts = $query->orderByDesc('start_date')->paginate(20)->withQueryString();

        return inertia('MaintenanceContracts/IndexPage', [
            'maintenanceContracts' => $maintenancecontracts,
            'allCustomers' => Customer::select('id', 'name')->orderBy('name')->get(),
            'contractIntervalOptions' => \App\Enums\ContractInterval::comboBoxArray(),
            'search' => $search,
        ]);
    }

    public function show(MaintenanceContractReadRequest $request, MaintenanceContract $maintenancecontract)
    {
        $maintenancecontract->load([
            'customer.assets.product',
            'assets.product',
            'activities' => function ($q) {
                $q->with('user:id,name')->orderByDesc('activityables.created_at');
            },
            'remarks.user',
        ]);

        return inertia('MaintenanceContracts/ShowPage', [
            'maintenanceContract' => $maintenancecontract,
            'contractIntervalOptions' => \App\Enums\ContractInterval::comboBoxArray(),
        ]);
    }

    public function store(MaintenanceContractStoreRequest $request)
    {
        $maintenancecontract = MaintenanceContract::create($request->validated());
        $maintenancecontract->logActivity('Contract aangemaakt');

        return redirect()->back()->with('success', 'Onderhoudscontract aangemaakt.');
    }

    public function update(MaintenanceContractUpdateRequest $request, MaintenanceContract $maintenancecontract)
    {
        $validated = $request->validated();
        $was_contract_wide = ! $maintenancecontract->manage_frequency_per_asset;

        $maintenancecontract->update($validated);

        $switched_to_individual = array_key_exists('manage_frequency_per_asset', $validated)
            && $validated['manage_frequency_per_asset']
            && $was_contract_wide;

        if ($switched_to_individual) {
            $maintenancecontract->assets()
                ->newPivotQuery()
                ->whereNull('frequency')
                ->update([
                    'frequency' => $validated['frequency'] ?? $maintenancecontract->getRawOriginal('frequency'),
                    'frequency_days' => $validated['frequency_days'] ?? $maintenancecontract->frequency_days,
                ]);
            $maintenancecontract->logActivity('Frequentiebeheer gewijzigd naar per machine');
        } elseif (array_key_exists('manage_frequency_per_asset', $validated) && ! $validated['manage_frequency_per_asset']) {
            $maintenancecontract->logActivity('Frequentiebeheer gewijzigd naar contractbreed');
        }

        if (array_diff(array_keys($validated), ['manage_frequency_per_asset']) !== []) {
            $maintenancecontract->logActivity('Contract bijgewerkt');
        }

        return redirect()->back()->with('success', 'Onderhoudscontract bijgewerkt.');
    }

    public function destroy(MaintenanceContractDestroyRequest $request, MaintenanceContract $maintenancecontract)
    {
        $maintenancecontract->delete();

        return redirect()->route('maintenancecontracts.index')->with('success', 'Onderhoudscontract verwijderd.');
    }

    public function attachAsset(
        MaintenanceContractAttachAssetRequest $request,
        MaintenanceContract $maintenancecontract,
        Asset $asset
    ) {
        $validated = $request->validated();
        $maintenancecontract->assets()->attach($asset->id, [
            'frequency' => $validated['frequency'] ?? null,
            'frequency_days' => $validated['frequency_days'] ?? null,
        ]);
        $maintenancecontract->logActivity('Machine gekoppeld: ' . ($asset->serial_number ?? ('#' . $asset->id)));

        return redirect()->back()->with('success', 'Machine gekoppeld aan het contract.');
    }

    public function updateAssetable(
        MaintenanceContractUpdateAssetableRequest $request,
        MaintenanceContract $maintenancecontract,
        string $assetable_id
    ) {
        $pivot_query = $maintenancecontract->assets()->newPivotQuery()->where('assetables.id', $assetable_id);
        $pivot_query->update($request->validated());
        $maintenancecontract->logActivity('Machinefrequentie bijgewerkt');

        return redirect()->back()->with('success', 'Frequentie bijgewerkt.');
    }

    public function detachAsset(
        MaintenanceContractDetachAssetRequest $request,
        MaintenanceContract $maintenancecontract,
        string $assetable_id
    ) {
        $maintenancecontract->assets()->newPivotQuery()->where('assetables.id', $assetable_id)->delete();
        $maintenancecontract->logActivity('Machine losgekoppeld');

        return redirect()->back()->with('success', 'Machine losgekoppeld van het contract.');
    }
}
```

`getRawOriginal('frequency')` returns the raw DB string (not the cast enum instance), which is what the pivot's `frequency` column expects — used as a fallback for the case where `manage_frequency_per_asset` was flipped to `true` without `frequency`/`frequency_days` also present in the same payload (already-set contract-wide values carry over as the copy-down source).

- [ ] **Step 2: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\MaintenanceContractController;` to the `use` block at the top (alphabetically near the other controller imports), then add this block directly after the existing `Route::resource('contacts', ContactController::class)->except(['create', 'edit']);` line:

```php
        Route::resource('maintenancecontracts', MaintenanceContractController::class)->except(['create', 'edit']);
        Route::post(
            'maintenancecontracts/{maintenancecontract}/assets/{asset}',
            [MaintenanceContractController::class, 'attachAsset']
        )->name('maintenancecontracts.attachAsset');
        Route::put(
            'maintenancecontracts/{maintenancecontract}/assets/{assetable_id}',
            [MaintenanceContractController::class, 'updateAssetable']
        )->name('maintenancecontracts.updateAssetable');
        Route::delete(
            'maintenancecontracts/{maintenancecontract}/assets/{assetable_id}',
            [MaintenanceContractController::class, 'detachAsset']
        )->name('maintenancecontracts.detachAsset');
```

- [ ] **Step 3: Syntax-check and verify routes**

Run:
```bash
php -l app/Http/Controllers/MaintenanceContractController.php
php artisan route:list --name=maintenancecontract
```

Expected: no syntax errors, and the route list shows all 8 routes (`GET|HEAD maintenancecontracts`, `POST maintenancecontracts`, `GET|HEAD maintenancecontracts/{maintenancecontract}`, `PUT|PATCH maintenancecontracts/{maintenancecontract}`, `DELETE maintenancecontracts/{maintenancecontract}`, plus the 3 nested asset routes).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/MaintenanceContractController.php routes/web.php
git commit -m "feat(maintenancecontract): add controller and routes"
```

---

## Task 7: `Utilities.js` status helpers

**Files:**
- Modify: `resources/js/Utilities/Utilities.js`

**Interfaces:**
- Produces: `maintenanceContractStatusText(status)`, `maintenanceContractStatusClasses(status)` — both take the model's already-computed `status` string (`'toekomstig' | 'actief' | 'verlopen'`).

- [ ] **Step 1: Add the helpers**

Add after `projectStatusClass` (around line 128):

```js
export const maintenanceContractStatusText = (status) => {
    switch (status) {
        case "actief":
            return "Actief";
        case "toekomstig":
            return "Toekomstig";
        case "verlopen":
            return "Verlopen";
        default:
            return status || "";
    }
};

export const maintenanceContractStatusClasses = (status) => {
    switch (status) {
        case "actief":
            return "bg-green-100 text-green-700 border-green-300 dark:bg-green-900/30 dark:text-green-300 dark:border-green-700/50";
        case "toekomstig":
            return "bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-700/50";
        case "verlopen":
            return "bg-gray-100 text-gray-600 border-gray-300 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600";
        default:
            return "bg-gray-100 text-gray-600 border-gray-300 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600";
    }
};
```

- [ ] **Step 2: Lint**

Run: `npm run fix:eslint`
Expected: no errors reported for `Utilities.js`.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Utilities/Utilities.js
git commit -m "feat(maintenancecontract): add status pill helpers"
```

---

## Task 8: Nav entry in `MainLayout.vue`

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue`

**Interfaces:**
- Produces: a "Onderhoudscontracten" nav item under the "Klanten" group, `href: '/maintenancecontracts'`, gated on `maintenancecontract.read`.

- [ ] **Step 1: Add the icon import**

In the `@heroicons/vue/24/outline` import block (around line 410-442), add `ClipboardDocumentCheckIcon` (alphabetical position doesn't matter here — the existing list isn't strictly sorted; add it near `ClipboardDocumentListIcon`... note that one is actually imported from `@lucide/vue`, so just add `ClipboardDocumentCheckIcon` anywhere in the heroicons import list, e.g. right after `TagIcon`):

```js
    TagIcon,
    ClipboardDocumentCheckIcon,
    DocumentTextIcon,
```

- [ ] **Step 2: Add the nav entry**

In the `Klanten` group's `children` array, directly after the `Contacten` entry (line 521):

```js
            { name: 'Contacten', href: '/contacts', icon: UserIcon, current: false, requiresPermission: 'contact.read' },
            { name: 'Onderhoudscontracten', href: '/maintenancecontracts', icon: ClipboardDocumentCheckIcon, current: false, requiresPermission: 'maintenancecontract.read' },
```

- [ ] **Step 3: Lint**

Run: `npm run fix:eslint`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/MainLayout.vue
git commit -m "feat(maintenancecontract): add nav entry"
```

---

## Task 9: `Pages/MaintenanceContracts/IndexPage.vue`

**Files:**
- Create: `resources/js/Pages/MaintenanceContracts/IndexPage.vue`

**Interfaces:**
- Consumes: route `maintenancecontracts.index` (Task 6) props `maintenanceContracts` (paginator), `allCustomers` (array of `{id, name}`), `contractIntervalOptions` (from `ContractInterval::comboBoxArray()`, Task 1), `search` (String). Utilities from Task 7 (`nlDate`, `nlCurrency`, `maintenanceContractStatusText`, `maintenanceContractStatusClasses`, `hasPermission`).
- Produces: page at `/maintenancecontracts` with list + create drawer.

- [ ] **Step 1: Write the page**

```vue
<template>
    <IndexHeaderComponent title="Onderhoudscontracten" subtitle="Overzicht van alle onderhoudscontracten"
        search-url="/maintenancecontracts" search-placeholder="Zoek..."
        add-label="Voeg contract toe" @add="showCreateDrawer = true"
        :can-add="hasPermission('maintenancecontract.create')"
        :has-active-filters="Boolean(customerFilter)">
        <template #filters>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Klant</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="allCustomers" v-model="customerFilter" placeholder="Alle klanten" />
                </div>
            </div>
        </template>
    </IndexHeaderComponent>

    <PaginationComponent v-if="(maintenanceContracts.links || []).length" :paginator="maintenanceContracts"
        :params="{ search: searchParam }" class="border-b border-gray-200 dark:border-slate-700/60" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="maintenanceContracts.data?.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-3">Klant</div>
                <div class="col-span-3">Contract</div>
                <div class="col-span-2">Periode</div>
                <div class="col-span-1">Prijs</div>
                <div class="col-span-2">Status</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="contract in maintenanceContracts.data" :key="contract.id"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                <Link :href="`/maintenancecontracts/${contract.id}`" class="col-span-3 text-gray-900 dark:text-slate-100 hover:underline">
                    {{ contract.customer?.name }}
                </Link>
                <Link :href="`/maintenancecontracts/${contract.id}`" class="col-span-3 font-medium text-gray-900 dark:text-slate-100 hover:underline">
                    {{ contract.display_title }}
                </Link>
                <div class="col-span-2 text-gray-500 dark:text-slate-400">
                    {{ nlDate(contract.start_date) }} – {{ contract.end_date ? nlDate(contract.end_date) : 'heden' }}
                </div>
                <div class="col-span-1 text-gray-500 dark:text-slate-400">
                    {{ nlCurrency(contract.price) }} / {{ contract.price_interval }}
                </div>
                <div class="col-span-2">
                    <span :class="['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium border', maintenanceContractStatusClasses(contract.status)]">
                        {{ maintenanceContractStatusText(contract.status) }}
                    </span>
                </div>
                <div class="col-span-1 flex justify-end">
                    <div v-if="hasPermission('maintenancecontract.delete')" class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteContract(contract.id)" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <ClipboardDocumentCheckIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen onderhoudscontracten gevonden</p>
            </div>
        </div>
    </BoxComponent>

    <PaginationComponent v-if="(maintenanceContracts.links || []).length" :paginator="maintenanceContracts"
        :params="{ search: searchParam }" class="border-t border-gray-200 dark:border-slate-700/60" />

    <DrawerComponent v-model="showCreateDrawer" title="Nieuw onderhoudscontract"
        subtitle="Vul de gegevens in van het nieuwe contract.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700" v-auto-animate>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Klant</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="customerOptions" v-model="form.customer_id" placeholder="Selecteer klant"
                        :hasError="Boolean(form.errors.customer_id)" :errorMessage="form.errors.customer_id" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.title" type="text" placeholder="Optioneel"
                        :hasError="Boolean(form.errors.title)" :errorMessage="form.errors.title" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Startdatum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.start_date" type="date"
                        :hasError="Boolean(form.errors.start_date)" :errorMessage="form.errors.start_date" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Einddatum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.end_date" type="date" placeholder="Optioneel"
                        :hasError="Boolean(form.errors.end_date)" :errorMessage="form.errors.end_date" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prijs</label>
                <div class="sm:col-span-2">
                    <CurrencyInput v-model="form.price"
                        :hasError="Boolean(form.errors.price)" :errorMessage="form.errors.price" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prijsinterval</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="intervalOptions" v-model="form.price_interval"
                        :hasError="Boolean(form.errors.price_interval)" :errorMessage="form.errors.price_interval" />
                </div>
            </div>
            <div v-if="form.price_interval === 'Aangepast (dagen)'"
                class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.price_interval_days" type="number"
                        :hasError="Boolean(form.errors.price_interval_days)"
                        :errorMessage="form.errors.price_interval_days" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Frequentie per machine beheren</label>
                <div class="sm:col-span-2">
                    <SwitchComponent v-model="form.manage_frequency_per_asset" />
                </div>
            </div>
            <template v-if="!form.manage_frequency_per_asset">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Servicefrequentie</label>
                    <div class="sm:col-span-2">
                        <ComboBox :options="intervalOptions" v-model="form.frequency"
                            :hasError="Boolean(form.errors.frequency)" :errorMessage="form.errors.frequency" />
                    </div>
                </div>
                <div v-if="form.frequency === 'Aangepast (dagen)'"
                    class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="form.frequency_days" type="number"
                            :hasError="Boolean(form.errors.frequency_days)"
                            :errorMessage="form.errors.frequency_days" />
                    </div>
                </div>
            </template>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeCreateDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitCreate" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { ClipboardDocumentCheckIcon, TrashIcon } from '@heroicons/vue/24/outline'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import CurrencyInput from '@/Components/UI/CurrencyInput.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import { hasPermission, nlDate, nlCurrency, maintenanceContractStatusText, maintenanceContractStatusClasses } from '@/Utilities/Utilities'

const props = defineProps({
    maintenanceContracts: { type: Object, required: true },
    allCustomers: { type: Array, default: () => [] },
    contractIntervalOptions: { type: Array, default: () => [] },
    search: { type: String, default: '' },
})

const searchParam = props.search

const showCreateDrawer = ref(false)

const customerOptions = computed(() => props.allCustomers)

const urlParams = new URLSearchParams(window.location.search)
const customerFilter = ref(urlParams.get('customer_id') ? Number(urlParams.get('customer_id')) : null)

watch(customerFilter, (value) => {
    router.get('/maintenancecontracts', value ? { customer_id: value } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
})

// See "Critical Implementation Note" in the plan header: comboBoxArray() gives
// {id: case-name, name: case-value}. price_interval/frequency are cast on the
// model by case *value*, so both id and name must be the value here.
const intervalOptions = computed(() => props.contractIntervalOptions.map(o => ({ id: o.name, name: o.name })))

const form = useForm({
    customer_id: null,
    title: '',
    start_date: '',
    end_date: '',
    price: null,
    price_interval: 'Maandelijks',
    price_interval_days: null,
    manage_frequency_per_asset: false,
    frequency: 'Jaarlijks',
    frequency_days: null,
})

function submitCreate() {
    form.post('/maintenancecontracts', {
        preserveScroll: true,
        onSuccess: () => {
            showCreateDrawer.value = false
            form.reset()
        },
    })
}

function closeCreateDrawer() {
    showCreateDrawer.value = false
    form.reset()
    form.clearErrors()
}

function deleteContract(id) {
    if (!confirm('Weet je zeker dat je dit onderhoudscontract wilt verwijderen?')) return
    useForm({}).delete(`/maintenancecontracts/${id}`, { preserveScroll: true, preserveState: true })
}
</script>
```

- [ ] **Step 2: Register the controller's index() props to match**

Confirm `MaintenanceContractController::index()` (Task 6, Step 1) already passes `maintenanceContracts`, `allCustomers`, `contractIntervalOptions` — it does. No change needed here.

No status filter is included — `status` is a derived/computed accessor (Task 3), not a stored column, so filtering on it server-side would need extra date-range query logic beyond what the approved spec's "filters for customer and status" line accounted for in detail. The status *badge* still displays per-row (Step 1 above). Add a status filter later if it's actually needed.

- [ ] **Step 3: Lint**

Run: `npm run fix:eslint`
Expected: no errors for `IndexPage.vue`.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/MaintenanceContracts/IndexPage.vue
git commit -m "feat(maintenancecontract): add index page"
```

---

## Task 10: `Pages/MaintenanceContracts/ShowPage.vue`

**Files:**
- Create: `resources/js/Pages/MaintenanceContracts/ShowPage.vue`

**Interfaces:**
- Consumes: route `maintenancecontracts.show` (Task 6) props `maintenanceContract` (with `customer`, `assets` (pivot `id`/`frequency`/`frequency_days`), `activities`, `remarks`, plus appended `display_title`/`status`), `contractIntervalOptions`.
- Produces: page at `/maintenancecontracts/{id}` with editable fields, `MaintenanceContractAssetsWidget` (Task 11), `TimelineComponent`, `RemarksComponent`.

- [ ] **Step 1: Write the page**

```vue
<template>
    <div class="flex items-center">
        <Link href="/maintenancecontracts" class="text-slate-400 text-sm font-medium">Onderhoudscontracten</Link>
        <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
        <span class="text-slate-800 dark:text-slate-100 font-bold text-sm">{{ maintenanceContract.display_title }}</span>
    </div>

    <div class="flex flex-col mt-6 mb-4">
        <h1 class="text-2xl font-bold dark:text-slate-100">{{ maintenanceContract.display_title }}</h1>
        <Link :href="`/customers/${maintenanceContract.customer.id}`"
            class="text-gray-500 dark:text-slate-400 text-sm mt-1 hover:underline">
            {{ maintenanceContract.customer.name }}
        </Link>
    </div>

    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex items-center mb-4">
                    <ClipboardDocumentCheckIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
                    <span class="text-md font-bold dark:text-slate-100">Contractgegevens</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6" v-auto-animate>
                    <EditableTextField v-model="form.title" type="input" label="Titel" placeholder="Geen titel"
                        :error="form.errors.title" :readonly="!canUpdate"
                        @update="() => patch('title')" @revert="form.clearErrors('title')" />
                    <EditableTextField v-model="form.start_date" type="input" inputType="date" label="Startdatum"
                        :error="form.errors.start_date" :readonly="!canUpdate"
                        @update="() => patch('start_date')" @revert="form.clearErrors('start_date')" />
                    <EditableTextField v-model="form.end_date" type="input" inputType="date" label="Einddatum"
                        placeholder="Geen einddatum"
                        :error="form.errors.end_date" :readonly="!canUpdate"
                        @update="() => patch('end_date')" @revert="form.clearErrors('end_date')" />
                    <EditableTextField v-model="form.price" type="input" inputType="currency" label="Prijs"
                        :error="form.errors.price" :readonly="!canUpdate"
                        @update="() => patch('price')" @revert="form.clearErrors('price')" />
                    <EditableTextField v-model="form.price_interval" type="combobox" label="Prijsinterval"
                        :options="intervalOptions"
                        :error="form.errors.price_interval" :readonly="!canUpdate"
                        @update="() => patch('price_interval', 'price_interval_days')"
                        @revert="form.clearErrors('price_interval')" />
                    <EditableTextField v-if="form.price_interval === 'Aangepast (dagen)'"
                        v-model="form.price_interval_days" type="input" inputType="number" label="Elke ... dagen"
                        :error="form.errors.price_interval_days" :readonly="!canUpdate"
                        @update="() => patch('price_interval_days')" @revert="form.clearErrors('price_interval_days')" />
                </div>
            </BoxComponent>

            <BoxComponent class="mt-4">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <ClockIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
                        <span class="text-md font-bold dark:text-slate-100">Servicefrequentie</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 dark:text-slate-400">Per machine beheren</span>
                        <SwitchComponent v-model="form.manage_frequency_per_asset" :disabled="!canUpdate"
                            @update:model-value="() => patch('manage_frequency_per_asset', 'frequency', 'frequency_days')" />
                    </div>
                </div>
                <div v-if="!form.manage_frequency_per_asset" class="grid grid-cols-1 md:grid-cols-2 gap-6" v-auto-animate>
                    <EditableTextField v-model="form.frequency" type="combobox" label="Servicefrequentie"
                        :options="intervalOptions"
                        :error="form.errors.frequency" :readonly="!canUpdate"
                        @update="() => patch('frequency', 'frequency_days')" @revert="form.clearErrors('frequency')" />
                    <EditableTextField v-if="form.frequency === 'Aangepast (dagen)'"
                        v-model="form.frequency_days" type="input" inputType="number" label="Elke ... dagen"
                        :error="form.errors.frequency_days" :readonly="!canUpdate"
                        @update="() => patch('frequency_days')" @revert="form.clearErrors('frequency_days')" />
                </div>
                <p v-else class="text-sm text-gray-500 dark:text-slate-400">
                    Frequentie wordt per machine ingesteld hieronder.
                </p>
            </BoxComponent>

            <div class="mt-4">
                <MaintenanceContractAssetsWidget :maintenance-contract-id="maintenanceContract.id"
                    :assets="maintenanceContract.assets" :customer-assets="maintenanceContract.customer.assets || []"
                    :manage-per-asset="maintenanceContract.manage_frequency_per_asset"
                    :interval-options="intervalOptions" />
            </div>

            <BoxComponent class="mt-4">
                <RemarksComponent :remarkable-type="'App\\Models\\MaintenanceContract'"
                    :remarkable-id="maintenanceContract.id" :comments="maintenanceContract.remarks || []" />
            </BoxComponent>
        </template>

        <template #sidebar>
            <BoxComponent>
                <div class="flex items-center mb-4">
                    <ClockIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
                    <span class="text-md font-bold dark:text-slate-100">Activiteiten</span>
                </div>
                <TimelineComponent :activities="maintenanceContract.activities || []" />
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ChevronRightIcon, ClipboardDocumentCheckIcon, ClockIcon } from '@heroicons/vue/24/outline'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue'
import RemarksComponent from '@/Components/RemarksComponent.vue'
import MaintenanceContractAssetsWidget from '@/Components/MaintenanceContracts/MaintenanceContractAssetsWidget.vue'
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue'
import { hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    maintenanceContract: { type: Object, required: true },
    contractIntervalOptions: { type: Array, default: () => [] },
})

const canUpdate = computed(() => hasPermission('maintenancecontract.update'))

// See "Critical Implementation Note" in the plan header.
const intervalOptions = computed(() => props.contractIntervalOptions.map(o => ({ id: o.name, name: o.name })))

const form = useForm({
    title: props.maintenanceContract.title ?? '',
    start_date: props.maintenanceContract.start_date,
    end_date: props.maintenanceContract.end_date,
    price: props.maintenanceContract.price,
    price_interval: props.maintenanceContract.price_interval,
    price_interval_days: props.maintenanceContract.price_interval_days,
    manage_frequency_per_asset: props.maintenanceContract.manage_frequency_per_asset,
    frequency: props.maintenanceContract.frequency,
    frequency_days: props.maintenanceContract.frequency_days,
})

function patch(...fields) {
    form.transform(data => {
        const payload = {}
        fields.forEach(f => { payload[f] = data[f] })
        return payload
    }).patch(`/maintenancecontracts/${props.maintenanceContract.id}`, { preserveScroll: true })
}
</script>
```

`TwoThirdsOneThird` (`resources/js/Layouts/TwoThirdsOneThird.vue`) is a plain two-column grid with `#main` (8/12 width) and `#sidebar` (4/12 width) slots — already used this way in `Customers/ShowPage.vue`.

- [ ] **Step 2: Lint**

Run: `npm run fix:eslint`
Expected: no errors for `ShowPage.vue`.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/MaintenanceContracts/ShowPage.vue
git commit -m "feat(maintenancecontract): add show page"
```

---

## Task 11: `MaintenanceContractAssetsWidget.vue`

**Files:**
- Create: `resources/js/Components/MaintenanceContracts/MaintenanceContractAssetsWidget.vue`

**Interfaces:**
- Consumes: prop `maintenanceContractId` (Number), `assets` (Array — `MaintenanceContract.assets`, each with `.pivot.id/.frequency/.frequency_days`, `.product`, `.serial_number`), `customerAssets` (Array — full list of the contract's customer's assets, for the add-picker), `managePerAsset` (Boolean), `intervalOptions` (Array, already remapped `{id: value, name: value}` per Task 10). Routes: `POST/PUT/DELETE /maintenancecontracts/{id}/assets/{asset|assetable_id}` (Task 6).
- Produces: attach/detach/update-frequency UI, modeled on `resources/js/Components/Materials/MaterialsWidget.vue`.

- [ ] **Step 1: Write the widget**

```vue
<template>
    <div>
        <div class="flex items-start sm:items-center justify-between mb-4">
            <div class="flex items-start sm:items-center gap-3">
                <div class="flex items-center justify-center w-11 h-11 rounded-lavoro-sm bg-lavoro-blue flex-none">
                    <PuzzlePieceIcon class="h-5 w-5 text-white" />
                </div>
                <div class="flex flex-col">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Machines</h2>
                    <p class="text-xs text-slate-400 dark:text-slate-400">Machines die onder dit contract vallen.</p>
                </div>
            </div>
            <button v-if="canCreate" type="button" @click="showAddForm = !showAddForm"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-lavoro-blue hover:bg-lavoro-blue/90 rounded-md transition-colors">
                <PlusIcon class="size-4" />
                <span class="hidden sm:inline">Machine toevoegen</span>
            </button>
        </div>

        <div v-auto-animate>
            <div v-if="showAddForm && canCreate"
                class="flex flex-col md:flex-row items-start gap-2 mb-4 p-4 rounded-lavoro-sm dark:bg-slate-800/50 border border-gray-200/70 dark:border-slate-700">
                <div class="flex flex-col flex-grow w-full">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Kies een machine</span>
                    <ComboBox :options="availableAssetOptions" v-model="assetToAdd" placeholder="Selecteer machine" />
                </div>
                <div v-if="managePerAsset" class="flex flex-col w-full md:w-48">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Frequentie</span>
                    <ComboBox :options="intervalOptions" v-model="addForm.frequency" />
                </div>
                <div v-if="managePerAsset && addForm.frequency === 'Aangepast (dagen)'" class="flex flex-col w-full md:w-32">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Elke ... dagen</span>
                    <TextInput v-model="addForm.frequency_days" type="number" />
                </div>
                <button @click="attachAsset" :disabled="!assetToAdd || addForm.processing"
                    class="w-full md:w-auto px-4 py-2 rounded-md text-sm font-medium transition-colors mt-4 md:mt-5.5 bg-lavoro-blue text-white hover:bg-lavoro-blue/90 disabled:bg-gray-300 disabled:dark:bg-slate-700 disabled:text-gray-500 disabled:cursor-not-allowed">
                    Toevoegen
                </button>
            </div>
        </div>

        <div v-if="assets.length > 0" class="border-1 rounded-lavoro-sm border-gray-200/70">
            <div class="hidden md:grid grid-cols-12 text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-400 border-b border-gray-200/70 bg-gray-50/60 pt-3 pb-4 dark:border-slate-700 mb-1">
                <div class="col-span-5 pl-4">Machine</div>
                <div class="col-span-5">Frequentie</div>
                <div class="col-span-2 text-right pr-2">Acties</div>
            </div>
            <div v-auto-animate>
                <div v-for="asset in assets" :key="asset.pivot.id"
                    class="grid grid-cols-12 py-3 items-center border-b border-gray-100 dark:border-slate-800 last:border-b-0 px-3 sm:px-1">
                    <div class="col-span-11 md:col-span-5 flex flex-col sm:pl-3 min-w-0">
                        <span class="font-semibold text-sm text-gray-900 dark:text-slate-100 truncate">
                            {{ asset.product?.name || asset.serial_number }}
                        </span>
                        <span v-if="asset.serial_number" class="text-xs text-gray-400 dark:text-slate-500">
                            Serienummer: {{ asset.serial_number }}
                        </span>
                    </div>
                    <div class="col-span-12 md:col-span-5 mt-2 md:mt-0">
                        <template v-if="managePerAsset">
                            <template v-if="canUpdate">
                                <ComboBox :options="intervalOptions" :model-value="asset.pivot.frequency"
                                    @update:model-value="val => updateFrequency(asset, { frequency: val, frequency_days: val === 'Aangepast (dagen)' ? asset.pivot.frequency_days : null })" />
                                <div v-if="asset.pivot.frequency === 'Aangepast (dagen)'" class="mt-2" v-auto-animate>
                                    <TextInput type="number" :model-value="asset.pivot.frequency_days"
                                        @update:model-value="val => updateFrequency(asset, { frequency_days: val })"
                                        placeholder="Aantal dagen" />
                                </div>
                            </template>
                            <span v-else class="text-sm text-gray-700 dark:text-slate-300">{{ asset.pivot.frequency || '—' }}</span>
                        </template>
                        <span v-else class="text-sm text-gray-500 dark:text-slate-400 italic">Contractfrequentie</span>
                    </div>
                    <div class="col-span-1 flex justify-end pr-0 sm:pr-2">
                        <TrashIcon v-if="canDelete"
                            class="size-10 sm:size-5 text-red-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer transition-colors"
                            @click="detachAsset(asset)" v-tooltip="'Machine loskoppelen'" />
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-gray-400 dark:text-slate-500 italic">Nog geen machines gekoppeld.</p>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { PuzzlePieceIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    maintenanceContractId: { type: Number, required: true },
    assets: { type: Array, default: () => [] },
    customerAssets: { type: Array, default: () => [] },
    managePerAsset: { type: Boolean, default: false },
    intervalOptions: { type: Array, default: () => [] },
})

const canCreate = computed(() => hasPermission('assetable.create.maintenancecontract'))
const canUpdate = computed(() => hasPermission('assetable.update.maintenancecontract'))
const canDelete = computed(() => hasPermission('assetable.delete.maintenancecontract'))

const showAddForm = ref(false)
const assetToAdd = ref(null)

const attachedAssetIds = computed(() => props.assets.map(a => a.id))
const availableAssetOptions = computed(() =>
    props.customerAssets
        .filter(a => !attachedAssetIds.value.includes(a.id))
        .map(a => ({ id: a.id, name: a.product?.name ? `${a.product.name} (${a.serial_number || '—'})` : (a.serial_number || `Machine #${a.id}`) }))
)

const addForm = useForm({ frequency: null, frequency_days: null })

function attachAsset() {
    if (!assetToAdd.value) return
    addForm.post(`/maintenancecontracts/${props.maintenanceContractId}/assets/${assetToAdd.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            showAddForm.value = false
            assetToAdd.value = null
            addForm.reset()
        },
    })
}

function updateFrequency(asset, payload) {
    useForm(payload).put(
        `/maintenancecontracts/${props.maintenanceContractId}/assets/${asset.pivot.id}`,
        { preserveScroll: true }
    )
}

function detachAsset(asset) {
    useForm({}).delete(
        `/maintenancecontracts/${props.maintenanceContractId}/assets/${asset.pivot.id}`,
        { preserveScroll: true }
    )
}
</script>
```

- [ ] **Step 2: Lint**

Run: `npm run fix:eslint`
Expected: no errors for `MaintenanceContractAssetsWidget.vue`.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/MaintenanceContracts/MaintenanceContractAssetsWidget.vue
git commit -m "feat(maintenancecontract): add assets widget"
```

---

## Task 12: `Customers/ShowPage.vue` additions

**Files:**
- Modify: `app/Http/Controllers/CustomerController.php` (eager-load `maintenanceContracts`)
- Modify: `resources/js/Pages/Customers/ShowPage.vue` (new box + create drawer)

**Interfaces:**
- Consumes: `Customer::maintenanceContracts()` (Task 3), route `maintenancecontracts.store` (Task 6).
- Produces: a "Onderhoudscontracten" box on the customer page listing `customer.maintenanceContracts`, with a create-drawer trigger that pre-fills `customer_id`.

- [ ] **Step 1: Eager-load the relation**

In `app/Http/Controllers/CustomerController.php`, add `'maintenanceContracts',` to the `$customer->load([...])` array (around line 95, next to `'contacts',`).

- [ ] **Step 2: Add the box + drawer to the Vue page**

In `resources/js/Pages/Customers/ShowPage.vue`, find the existing Contacts sidebar `BoxComponent` (per the design research, chapter 0 area) and add a sibling box directly after it:

```vue
    <BoxComponent class="mt-4">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center">
                <ClipboardDocumentCheckIcon class="size-5 mr-2 flex-none text-gray-500 dark:text-slate-400" />
                <span class="text-md font-bold dark:text-slate-100">Onderhoudscontracten</span>
            </div>
            <PlusCircleIcon v-if="canCreateMaintenanceContract"
                class="size-6 flex-none text-green-500 cursor-pointer hover:text-green-700"
                @click="showMaintenanceContractDrawer = true"
                v-tooltip="`Nieuw onderhoudscontract voor ${form.name}`" />
        </div>
        <div v-if="customer.maintenance_contracts?.length" class="space-y-2">
            <Link v-for="contract in customer.maintenance_contracts" :key="contract.id"
                :href="`/maintenancecontracts/${contract.id}`"
                class="block text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                {{ contract.display_title }}
            </Link>
        </div>
        <p v-else class="text-sm text-gray-400 dark:text-slate-500">Geen onderhoudscontracten</p>
    </BoxComponent>
```

Eloquent snake_cases relation keys when serializing to JSON (confirmed elsewhere in this codebase: the `taskInstances()` relation is read on the frontend as `serviceOrder.task_instances`, and `productType()` as `product.product_type`) — so the `maintenanceContracts()` relation is `customer.maintenance_contracts` in the prop, as written above.

- [ ] **Step 3: Add the create-drawer**

Near the existing "Nieuw contact" `DrawerComponent` (around line 382), add a sibling drawer:

```vue
    <DrawerComponent v-model="showMaintenanceContractDrawer" :title="`Nieuw onderhoudscontract voor ${form.name}`"
        subtitle="Vul de gegevens in van het nieuwe contract.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700" v-auto-animate>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMaintenanceContractForm.title" type="text" placeholder="Optioneel" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Startdatum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMaintenanceContractForm.start_date" type="date"
                        :hasError="Boolean(newMaintenanceContractForm.errors.start_date)"
                        :errorMessage="newMaintenanceContractForm.errors.start_date" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Einddatum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMaintenanceContractForm.end_date" type="date" placeholder="Optioneel" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prijs</label>
                <div class="sm:col-span-2">
                    <CurrencyInput v-model="newMaintenanceContractForm.price"
                        :hasError="Boolean(newMaintenanceContractForm.errors.price)"
                        :errorMessage="newMaintenanceContractForm.errors.price" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prijsinterval</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="maintenanceContractIntervalOptions" v-model="newMaintenanceContractForm.price_interval" />
                </div>
            </div>
            <div v-if="newMaintenanceContractForm.price_interval === 'Aangepast (dagen)'"
                class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMaintenanceContractForm.price_interval_days" type="number" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Frequentie per machine beheren</label>
                <div class="sm:col-span-2">
                    <SwitchComponent v-model="newMaintenanceContractForm.manage_frequency_per_asset" />
                </div>
            </div>
            <template v-if="!newMaintenanceContractForm.manage_frequency_per_asset">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Servicefrequentie</label>
                    <div class="sm:col-span-2">
                        <ComboBox :options="maintenanceContractIntervalOptions" v-model="newMaintenanceContractForm.frequency" />
                    </div>
                </div>
                <div v-if="newMaintenanceContractForm.frequency === 'Aangepast (dagen)'"
                    class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="newMaintenanceContractForm.frequency_days" type="number" />
                    </div>
                </div>
            </template>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeMaintenanceContractDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewMaintenanceContract" :disabled="newMaintenanceContractForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
```

- [ ] **Step 4: Add the script additions**

Add to the `<script setup>` block (near the existing `canCreateServiceOrder`/`canCreateProject` computeds and `newContactForm`):

```js
const canCreateMaintenanceContract = computed(() => hasPermission('maintenancecontract.create'))
const showMaintenanceContractDrawer = ref(false)

// See "Critical Implementation Note" in the maintenancecontract plan: comboBoxArray()
// gives {id: case-name, name: case-value}; the model casts by value, so remap both to value.
const maintenanceContractIntervalOptions = computed(() =>
    (props.contractIntervalOptions || []).map(o => ({ id: o.name, name: o.name }))
)

const newMaintenanceContractForm = useForm({
    customer_id: props.customer.id,
    title: '',
    start_date: '',
    end_date: '',
    price: null,
    price_interval: 'Maandelijks',
    price_interval_days: null,
    manage_frequency_per_asset: false,
    frequency: 'Jaarlijks',
    frequency_days: null,
})

function submitNewMaintenanceContract() {
    newMaintenanceContractForm.post('/maintenancecontracts', {
        preserveScroll: true,
        onSuccess: () => {
            showMaintenanceContractDrawer.value = false
            newMaintenanceContractForm.reset()
        },
    })
}

function closeMaintenanceContractDrawer() {
    showMaintenanceContractDrawer.value = false
    newMaintenanceContractForm.reset()
    newMaintenanceContractForm.clearErrors()
}
```

Also add `import ClipboardDocumentCheckIcon` and `import SwitchComponent`/`CurrencyInput`/`ComboBox` to the top-of-file imports if not already present (`ComboBox` almost certainly already is, given `billing_customer_id` uses it), and add a new controller-supplied prop:

```js
    contractIntervalOptions: { type: Array, default: () => [] },
```

- [ ] **Step 5: Pass the new prop from the controller**

In `CustomerController::show()`, add `'contractIntervalOptions' => \App\Enums\ContractInterval::comboBoxArray(),` to the `inertia(...)` props array (wherever the existing `allCustomers`/`assets` props are assembled for the `Customers/ShowPage` response).

- [ ] **Step 6: Syntax-check and lint**

Run:
```bash
php -l app/Http/Controllers/CustomerController.php
npm run fix:eslint
```
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CustomerController.php resources/js/Pages/Customers/ShowPage.vue
git commit -m "feat(maintenancecontract): add customer page box and create drawer"
```

---

## Task 13: End-to-end manual verification

**Files:** none (verification only).

Per this project's convention (CLAUDE.md: "For UI or frontend changes, start the dev server and use the feature in a browser before reporting the task as complete"), drive the full feature live rather than relying on unit tests.

- [ ] **Step 1: Start the dev stack**

Run: `composer run dev` (starts Laravel, queue, log tail, Vite together).

- [ ] **Step 2: Walk the golden path in a browser**

1. Log in, open a Customer's show page. Confirm the new "Onderhoudscontracten" box appears (empty state first).
2. Click the create trigger; confirm the drawer opens with no visible customer field, fill in start date, price, leave price interval as "Maandelijks", submit. Confirm it appears in the box afterward as a link.
3. Click into the contract's Show page. Confirm `display_title` (customer + date range) shows since no title was set.
4. Edit the title inline; confirm it now shows instead of the fallback.
5. Change price interval to "Aangepast (dagen)"; confirm the days input animates in and is required (try saving without it — expect a validation error inline).
6. Toggle "Frequentie per machine beheren" on; confirm the contract-wide frequency fields disappear and the assets widget's per-row frequency controls become active.
7. Attach an asset from the widget's combobox (only the contract's customer's assets should be selectable), set a frequency, save. Confirm it appears in the table.
8. Toggle "Frequentie per machine beheren" back off, then on again; confirm the previously-set per-asset frequency was not wiped (copy-down only fills nulls, doesn't overwrite).
9. Remove the asset via the trash icon; confirm it disappears.
10. Add a remark via the `RemarksComponent`; confirm it appears and the `TimelineComponent` shows the various logged activities (created, updated, machine gekoppeld/losgekoppeld, frequentiebeheer gewijzigd) in reverse-chronological order.
11. Go to `/maintenancecontracts` via the new nav entry under "Klanten"; confirm the new contract is listed with correct status badge (should read "Actief" if start date is today/past and no end date, or the date-appropriate state otherwise).
12. From `/maintenancecontracts`, delete the contract via the trash icon on its row (Task 9). Confirm the confirmation prompt, the row disappearing, and that `assetables`/`activityables`/`remarkables` rows are gone (spot-check via `php artisan tinker`).
13. Log in as (or impersonate) a user without `maintenancecontract.read` and confirm the nav entry and pages are inaccessible (403 or hidden nav item).

- [ ] **Step 3: Fix anything broken during the walkthrough, re-verify, then stop** — do not mark this task done until the full golden path in Step 2 works without errors in the browser console or Laravel log.
