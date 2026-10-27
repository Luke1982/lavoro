# Stage Flags Replace ServiceOrder Status — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `service_orders.status` column with three boolean flags on `service_order_stages` (`is_planned_state`, `is_closed_state`, `is_plannable_state`); make stage transitions drive `closed_on` and the `is_closed` accessor; have event attachment advance the SO to the planned-state stage; filter the planner widget by `is_plannable_state`.

**Architecture:** Two migrations (add flags, then backfill + drop status); the `is_closed` accessor reads through the new stage relation; the planned-state transition lives on the model (`advanceToPlannedStage`) and is invoked from `EventApiController`; stage controller enforces singleton flags inside a DB transaction; the shared `StepsProgressBar` swaps its mobile branch to use the project's `SelectMenuComponent`.

**Tech Stack:** Laravel 11, Inertia.js, Vue 3, Tailwind, `@headlessui/vue` (replaced for the mobile stepper).

**Spec:** [docs/superpowers/specs/2026-06-01-stage-flags-replace-status-design.md](docs/superpowers/specs/2026-06-01-stage-flags-replace-status-design.md).

**Conventions (from [claude.md](claude.md)):**
- PHP snake_case variables.
- No inline comments. Docblocks only when really useful.
- No automated tests — manual verification per task.
- Authorization via Form Requests.

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/2026_06_01_100001_add_state_flags_to_service_order_stages_table.php` | Create | Add three boolean columns |
| `database/migrations/2026_06_01_100002_backfill_and_drop_status_from_service_orders_table.php` | Create | Auto-create closed stage if needed; reassign closed SOs; drop `status` |
| `app/Enums/ServiceOrderStates.php` | Delete | Unused enum (no references in code) |
| `app/Models/ServiceOrderStage.php` | Modify | Extend `$fillable` + add boolean casts |
| `app/Models/ServiceOrder.php` | Modify | Drop `status` fillable/cast; rewrite `getIsClosedAttribute`; add `$appends`, `$with`; add `advanceToPlannedStage()` |
| `app/Http/Requests/ServiceOrderUpdateRequest.php` | Modify | Drop `status` rule + message; rewrite `authorize()` to gate stage-based transitions |
| `app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php` | Modify | Add three boolean rules |
| `app/Http/Controllers/ServiceOrderController.php` | Modify | `update`: drive `closed_on` from stage transition; `emailPdf`/`emailPdfWithJobs`/`sendToSnelStart`: read via `is_closed`; `show`: pass `closedStageId` |
| `app/Http/Controllers/ServiceOrderStageController.php` | Modify | Enforce singleton flags in `store`/`update` |
| `app/Http/Controllers/EventApiController.php` | Modify | Call `advanceToPlannedStage()` after event-attach (store + update) |
| `app/Http/Controllers/PlannerController.php` | Modify | Filter `unplannedServiceOrders` by `is_plannable_state` |
| `resources/js/Pages/ServiceOrderStages/IndexPage.vue` | Modify | New grid layout with 3 flag switches per row |
| `resources/js/Pages/ServiceOrders/ShowPage.vue` | Modify | Replace `status` reads with `is_closed`; replace close/reopen buttons to drive stage transitions; drop `updateStatus` |
| `resources/js/Components/UI/StepsProgressBar.vue` | Modify | Replace mobile Listbox branch with `SelectMenuComponent` |

---

## Task 1: Migrations — add flag columns, then backfill + drop status

**Files:**
- Create: `database/migrations/2026_06_01_100001_add_state_flags_to_service_order_stages_table.php`
- Create: `database/migrations/2026_06_01_100002_backfill_and_drop_status_from_service_orders_table.php`

- [ ] **Step 1: Create the flags migration**

`database/migrations/2026_06_01_100001_add_state_flags_to_service_order_stages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_order_stages', function (Blueprint $table) {
            $table->boolean('is_planned_state')->default(false);
            $table->boolean('is_closed_state')->default(false);
            $table->boolean('is_plannable_state')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('service_order_stages', function (Blueprint $table) {
            $table->dropColumn(['is_planned_state', 'is_closed_state', 'is_plannable_state']);
        });
    }
};
```

- [ ] **Step 2: Create the backfill + drop-status migration**

`database/migrations/2026_06_01_100002_backfill_and_drop_status_from_service_orders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $has_closed_sos = DB::table('service_orders')->where('status', 'closed')->exists();
        $closed_stage_id = DB::table('service_order_stages')->where('is_closed_state', true)->value('id');

        if ($has_closed_sos && !$closed_stage_id) {
            $max_order = (int) (DB::table('service_order_stages')->max('order') ?? 0);
            $closed_stage_id = DB::table('service_order_stages')->insertGetId([
                'name'            => 'Afgerond',
                'order'           => $max_order + 1,
                'is_closed_state' => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        if ($has_closed_sos && $closed_stage_id) {
            DB::table('service_orders')
                ->where('status', 'closed')
                ->update(['service_order_stage_id' => $closed_stage_id]);
        }

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('status')->default('open')->nullable()->after('sent_to_customer');
        });

        DB::table('service_orders')
            ->whereNotNull('closed_on')
            ->update(['status' => 'closed']);
    }
};
```

- [ ] **Step 3: Run migrations**

Run: `php artisan migrate`
Expected: both migrations print `DONE`.

- [ ] **Step 4: Verify schema + data**

Run:
```
php artisan tinker --execute='
echo "stage columns: ";
foreach (["is_planned_state","is_closed_state","is_plannable_state"] as $c) {
    echo Schema::hasColumn("service_order_stages", $c) ? "ok " : "missing ";
}
echo "\nstatus dropped: " . (Schema::hasColumn("service_orders", "status") ? "STILL THERE" : "ok") . "\n";
echo "closed stages count: " . App\Models\ServiceOrderStage::where("is_closed_state", true)->count() . "\n";
'
```
Expected output:
```
stage columns: ok ok ok
status dropped: ok
closed stages count: 0 or 1
```
(`0` if no SOs were `status='closed'` at migration time, `1` if the auto-created "Afgerond" stage exists.)

- [ ] **Step 5: Commit**

```
git add database/migrations/2026_06_01_100001_add_state_flags_to_service_order_stages_table.php database/migrations/2026_06_01_100002_backfill_and_drop_status_from_service_orders_table.php
git commit -m "feat(ServiceOrderStages): add stage flags + drop service_orders.status"
```

---

## Task 2: Delete unused enum

**Files:**
- Delete: `app/Enums/ServiceOrderStates.php`

- [ ] **Step 1: Confirm unused**

Run:
```
grep -rn "ServiceOrderStates" app config database routes resources/js tests 2>/dev/null | grep -v "app/Enums/ServiceOrderStates.php"
```
Expected: no output (no callers).

- [ ] **Step 2: Delete the file**

Run: `git rm app/Enums/ServiceOrderStates.php`

- [ ] **Step 3: Commit**

```
git commit -m "chore: drop unused ServiceOrderStates enum"
```

---

## Task 3: Models — `ServiceOrderStage` casts + `ServiceOrder` accessor / appends / with / `advanceToPlannedStage`

**Files:**
- Modify: `app/Models/ServiceOrderStage.php`
- Modify: `app/Models/ServiceOrder.php`

- [ ] **Step 1: Extend `ServiceOrderStage`**

In `app/Models/ServiceOrderStage.php`, replace the current `$fillable` block:

```php
    protected $fillable = [
        'name',
        'order',
    ];
```

with:

```php
    protected $fillable = [
        'name',
        'order',
        'is_planned_state',
        'is_closed_state',
        'is_plannable_state',
    ];

    protected $casts = [
        'is_planned_state'   => 'boolean',
        'is_closed_state'    => 'boolean',
        'is_plannable_state' => 'boolean',
    ];
```

- [ ] **Step 2: Update `ServiceOrder` fillable + casts**

In `app/Models/ServiceOrder.php`, remove `'status'` from `$fillable`. Replace:

```php
    protected $fillable = [
        'description',
        'customer_id',
        'project_id',
        'closed_on',
        'signed_by',
        'signature_base64',
        'sent_to_administration',
        'sent_to_customer',
        'status',
        'external_purchaseorder_no',
        'actual_start_time',
        'actual_end_time',
        'service_order_stage_id',
    ];
```

with:

```php
    protected $fillable = [
        'description',
        'customer_id',
        'project_id',
        'closed_on',
        'signed_by',
        'signature_base64',
        'sent_to_administration',
        'sent_to_customer',
        'external_purchaseorder_no',
        'actual_start_time',
        'actual_end_time',
        'service_order_stage_id',
    ];
```

And remove the `'status' => 'string'` cast. Replace:

```php
    protected $casts = [
        'sent_to_administration' => 'boolean',
        'sent_to_customer' => 'boolean',
        'status' => 'string',
    ];
```

with:

```php
    protected $casts = [
        'sent_to_administration' => 'boolean',
        'sent_to_customer' => 'boolean',
    ];

    protected $appends = ['is_closed'];

    protected $with = ['serviceOrderStage'];
```

- [ ] **Step 3: Rewrite `getIsClosedAttribute`**

In the same file, replace:

```php
    public function getIsClosedAttribute(): bool
    {
        $status = is_string($this->status) ? strtolower($this->status) : null;
        return $status === 'closed';
    }
```

with:

```php
    public function getIsClosedAttribute(): bool
    {
        return $this->serviceOrderStage?->is_closed_state === true;
    }
```

- [ ] **Step 4: Add `advanceToPlannedStage` method**

In `app/Models/ServiceOrder.php`, add this method immediately after the existing `serviceOrderStage()` relation method:

```php
    public function advanceToPlannedStage(): void
    {
        $planned = ServiceOrderStage::where('is_planned_state', true)->first();
        if (!$planned) {
            return;
        }
        $current = $this->serviceOrderStage;
        if ($current && $current->order >= $planned->order) {
            return;
        }
        $this->service_order_stage_id = $planned->id;
        $this->save();
        $this->logActivity("Fase gewijzigd naar: {$planned->name} (door koppeling agenda)");
    }
```

- [ ] **Step 5: Verify**

Run:
```
php artisan tinker --execute='
$so = App\Models\ServiceOrder::query()->first();
if ($so) {
    echo "is_closed: " . ($so->is_closed ? "y" : "n") . "\n";
    echo "appends has is_closed: " . (in_array("is_closed", $so->toArray() ? array_keys($so->toArray()) : []) ? "y" : "n") . "\n";
    echo "method advanceToPlannedStage exists: " . (method_exists($so, "advanceToPlannedStage") ? "y" : "n") . "\n";
} else {
    echo "no service orders\n";
}
'
```
Expected: the `is_closed` boolean prints, `appends has is_closed: y`, `method ... y`. If no SOs exist, just confirm no PHP error fires.

- [ ] **Step 6: Commit**

```
git add app/Models/ServiceOrderStage.php app/Models/ServiceOrder.php
git commit -m "feat(ServiceOrderStages): wire stage flags into models + advanceToPlannedStage"
```

---

## Task 4: `ServiceOrderUpdateRequest` — drop status rule, rewrite authorize

**Files:**
- Modify: `app/Http/Requests/ServiceOrderUpdateRequest.php`

- [ ] **Step 1: Replace the imports + class body**

Open `app/Http/Requests/ServiceOrderUpdateRequest.php`. Add the import (alongside the existing `use App\Models\ServiceOrder;`):

```php
use App\Models\ServiceOrderStage;
```

- [ ] **Step 2: Rewrite `authorize()`**

Replace:

```php
    public function authorize(): bool
    {
        $user = Auth::user();
        $serviceorder = request()->route('serviceorder');
        if ($user && $serviceorder instanceof ServiceOrder) {
            $new = $this->status ?? null;
            $current = $serviceorder->status;
            if ($new === 'closed' && $current !== 'closed') {
                return $user->hasPermission('serviceorder.close');
            }
            if ($new === 'open' && $current !== 'open') {
                return $user->hasPermission('serviceorder.reopen');
            }
        }
        return true;
    }
```

with:

```php
    public function authorize(): bool
    {
        $user = Auth::user();
        $serviceorder = request()->route('serviceorder');
        if (!$user || !$serviceorder instanceof ServiceOrder) {
            return true;
        }
        if (!$this->has('service_order_stage_id')) {
            return true;
        }

        $new_stage_id = $this->input('service_order_stage_id');
        $new_stage = $new_stage_id === null
            ? null
            : ServiceOrderStage::find($new_stage_id);
        $new_is_closed = $new_stage?->is_closed_state === true;
        $current_is_closed = $serviceorder->is_closed;

        if ($new_is_closed && !$current_is_closed) {
            return $user->hasPermission('serviceorder.close');
        }
        if (!$new_is_closed && $current_is_closed) {
            return $user->hasPermission('serviceorder.reopen');
        }
        return true;
    }
```

- [ ] **Step 3: Drop the obsolete `status` rule**

In the `rules()` array, remove the `'status' => 'nullable|in:open,closed',` line. The block should keep the other rules untouched.

- [ ] **Step 4: Drop the obsolete `status.in` message**

In the `messages()` array, remove the `'status.in' => 'Ongeldige status opgegeven.',` line. Leave the rest of the messages untouched.

- [ ] **Step 5: Update docblock**

In the class-level docblock, remove the line `* @property string|null $status`. Leave the other `@property` lines untouched.

- [ ] **Step 6: Verify**

Run:
```
php -l app/Http/Requests/ServiceOrderUpdateRequest.php
php artisan tinker --execute='echo class_exists("App\\\\Http\\\\Requests\\\\ServiceOrderUpdateRequest") ? "ok" : "FAIL";'
```
Expected: `No syntax errors detected`, then `ok`.

- [ ] **Step 7: Commit**

```
git add app/Http/Requests/ServiceOrderUpdateRequest.php
git commit -m "feat(ServiceOrderStages): gate update via stage transition, drop status rule"
```

---

## Task 5: `ServiceOrderStageStoreUpdateRequest` — add boolean rules

**Files:**
- Modify: `app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php`

- [ ] **Step 1: Extend `rules()`**

Replace:

```php
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
```

with:

```php
    public function rules(): array
    {
        return [
            'name'               => ['sometimes', 'required', 'string', 'max:255'],
            'order'              => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_planned_state'   => ['sometimes', 'boolean'],
            'is_closed_state'    => ['sometimes', 'boolean'],
            'is_plannable_state' => ['sometimes', 'boolean'],
        ];
    }
```

(`name` becomes `sometimes|required` so partial PATCHes that only flip a flag don't have to resend it.)

- [ ] **Step 2: Verify**

Run: `php -l app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```
git add app/Http/Requests/ServiceOrderStageStoreUpdateRequest.php
git commit -m "feat(ServiceOrderStages): permit flag fields on stage store/update"
```

---

## Task 6: `ServiceOrderStageController` — enforce singleton flags in transactions

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderStageController.php`

- [ ] **Step 1: Rewrite `store`**

Replace:

```php
    public function store(ServiceOrderStageStoreUpdateRequest $request)
    {
        $data = $request->validated();
        if (!isset($data['order'])) {
            $data['order'] = (ServiceOrderStage::max('order') ?? 0) + 1;
        }
        ServiceOrderStage::create($data);

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is aangemaakt');
    }
```

with:

```php
    public function store(ServiceOrderStageStoreUpdateRequest $request)
    {
        $data = $request->validated();
        if (!isset($data['order'])) {
            $data['order'] = (ServiceOrderStage::max('order') ?? 0) + 1;
        }

        DB::transaction(function () use ($data) {
            if (!empty($data['is_planned_state'])) {
                ServiceOrderStage::where('is_planned_state', true)
                    ->update(['is_planned_state' => false]);
            }
            if (!empty($data['is_closed_state'])) {
                ServiceOrderStage::where('is_closed_state', true)
                    ->update(['is_closed_state' => false]);
            }
            ServiceOrderStage::create($data);
        });

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is aangemaakt');
    }
```

- [ ] **Step 2: Rewrite `update`**

Replace:

```php
    public function update(
        ServiceOrderStageStoreUpdateRequest $request,
        ServiceOrderStage $serviceorderstage
    ) {
        $serviceorderstage->update($request->validated());

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is bijgewerkt');
    }
```

with:

```php
    public function update(
        ServiceOrderStageStoreUpdateRequest $request,
        ServiceOrderStage $serviceorderstage
    ) {
        $data = $request->validated();

        DB::transaction(function () use ($data, $serviceorderstage) {
            if (!empty($data['is_planned_state'])) {
                ServiceOrderStage::where('id', '!=', $serviceorderstage->id)
                    ->where('is_planned_state', true)
                    ->update(['is_planned_state' => false]);
            }
            if (!empty($data['is_closed_state'])) {
                ServiceOrderStage::where('id', '!=', $serviceorderstage->id)
                    ->where('is_closed_state', true)
                    ->update(['is_closed_state' => false]);
            }
            $serviceorderstage->update($data);
        });

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is bijgewerkt');
    }
```

(The `DB` facade is already imported in this file from the existing `updateOrder` method.)

- [ ] **Step 3: Verify**

Run: `php -l app/Http/Controllers/ServiceOrderStageController.php`
Expected: `No syntax errors detected`.

Run: `php artisan route:list --path=serviceorderstages`
Expected: routes still listed (sanity check that the controller loads).

- [ ] **Step 4: Commit**

```
git add app/Http/Controllers/ServiceOrderStageController.php
git commit -m "feat(ServiceOrderStages): enforce singleton planned/closed flags"
```

---

## Task 7: `ServiceOrderController` — stage-driven `closed_on`, `is_closed` reads, `closedStageId` prop

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php`

- [ ] **Step 1: Rewrite `update`**

Replace:

```php
    public function update(ServiceOrderUpdateRequest $request, ServiceOrder $serviceorder)
    {
        $data = $request->validated();
        if ($serviceorder->status !== 'closed' && $request->input('status') === 'closed') {
            $data['closed_on'] = now();
        }

        $previous_stage_id = $serviceorder->service_order_stage_id;
        $serviceorder->update($data);

        if (
            array_key_exists('service_order_stage_id', $data)
            && $data['service_order_stage_id'] != $previous_stage_id
        ) {
            if ($data['service_order_stage_id'] === null) {
                $serviceorder->logActivity('Fase verwijderd');
            } else {
                $new_stage = ServiceOrderStage::find($data['service_order_stage_id']);
                if ($new_stage) {
                    $serviceorder->logActivity("Fase gewijzigd naar: {$new_stage->name}");
                }
            }
        }

        return redirect()->back()->with('success', 'Werkbon succesvol bijgewerkt.');
    }
```

with:

```php
    public function update(ServiceOrderUpdateRequest $request, ServiceOrder $serviceorder)
    {
        $data = $request->validated();

        $previous_stage_id = $serviceorder->service_order_stage_id;
        $previous_is_closed = $serviceorder->is_closed;

        $serviceorder->update($data);
        $serviceorder->load('serviceOrderStage');
        $new_is_closed = $serviceorder->is_closed;

        if ($new_is_closed && !$previous_is_closed) {
            $serviceorder->closed_on = now();
            $serviceorder->save();
        } elseif (!$new_is_closed && $previous_is_closed) {
            $serviceorder->closed_on = null;
            $serviceorder->save();
        }

        if (
            array_key_exists('service_order_stage_id', $data)
            && $data['service_order_stage_id'] != $previous_stage_id
        ) {
            if ($data['service_order_stage_id'] === null) {
                $serviceorder->logActivity('Fase verwijderd');
            } else {
                $new_stage = $serviceorder->serviceOrderStage;
                if ($new_stage) {
                    $serviceorder->logActivity("Fase gewijzigd naar: {$new_stage->name}");
                }
            }
        }

        return redirect()->back()->with('success', 'Werkbon succesvol bijgewerkt.');
    }
```

- [ ] **Step 2: Replace `status !== 'closed'` with `!is_closed` in `emailPdf`**

In `app/Http/Controllers/ServiceOrderController.php`, replace:

```php
        if ($serviceorder->status !== 'closed') {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je de PDF kunt e-mailen.');
        }
        $serviceorder->load(['customer']);
```

with:

```php
        $serviceorder->load(['customer', 'serviceOrderStage']);
        if (!$serviceorder->is_closed) {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je de PDF kunt e-mailen.');
        }
```

- [ ] **Step 3: Same change in `emailPdfWithJobs`**

Replace:

```php
        if ($serviceorder->status !== 'closed') {
            return redirect()->back()->with(
                'error',
                'Sluit de werkbon af voordat je de PDF met keuringen kunt e-mailen.'
            );
        }
        $serviceorder->load(['customer', 'serviceJobs.asset.customer']);
```

with:

```php
        $serviceorder->load(['customer', 'serviceJobs.asset.customer', 'serviceOrderStage']);
        if (!$serviceorder->is_closed) {
            return redirect()->back()->with(
                'error',
                'Sluit de werkbon af voordat je de PDF met keuringen kunt e-mailen.'
            );
        }
```

- [ ] **Step 4: Same change in `sendToSnelStart`**

Replace:

```php
        if ($serviceorder->sent_to_administration) {
            return redirect()->back()->with('error', 'Deze werkbon is al verzonden naar SnelStart.');
        }
        if ($serviceorder->status !== 'closed') {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je kunt versturen naar SnelStart.');
        }
        $serviceorder->load(['customer.billingCustomer', 'materials']);
```

with:

```php
        if ($serviceorder->sent_to_administration) {
            return redirect()->back()->with('error', 'Deze werkbon is al verzonden naar SnelStart.');
        }
        $serviceorder->load(['customer.billingCustomer', 'materials', 'serviceOrderStage']);
        if (!$serviceorder->is_closed) {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je kunt versturen naar SnelStart.');
        }
```

- [ ] **Step 5: Pass `closedStageId` from `show`**

In the `show` method, change the returned Inertia array. Replace:

```php
        return inertia('ServiceOrders/ShowPage', [
            'serviceOrder' => $service_order,
            'allMaterials' => Material::all()->load([
                'usageUnit',
            ]),
            'customFields' => $service_order->allCustomFieldsWithValues(),
            'stages'       => ServiceOrderStage::orderBy('order')->get(),
        ]);
```

with:

```php
        return inertia('ServiceOrders/ShowPage', [
            'serviceOrder'  => $service_order,
            'allMaterials'  => Material::all()->load([
                'usageUnit',
            ]),
            'customFields'  => $service_order->allCustomFieldsWithValues(),
            'stages'        => ServiceOrderStage::orderBy('order')->get(),
            'closedStageId' => ServiceOrderStage::where('is_closed_state', true)->value('id'),
        ]);
```

- [ ] **Step 6: Verify**

Run: `php -l app/Http/Controllers/ServiceOrderController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/ServiceOrderController.php
git commit -m "feat(ServiceOrderStages): SO controller now reads is_closed; closed_on follows stage"
```

---

## Task 8: `EventApiController` — advance SO stage on event attach

**Files:**
- Modify: `app/Http/Controllers/EventApiController.php`

- [ ] **Step 1: Add the import**

In `app/Http/Controllers/EventApiController.php`, alongside the existing `use App\Models\Event;` etc., add:

```php
use App\Models\ServiceOrder;
```

(Place it with the other `App\Models` imports.)

- [ ] **Step 2: Call `advanceToPlannedStage()` in `store`**

Locate the existing `store` method. Replace:

```php
        $class = $request->eventable_type;
        $model = $class::findOrFail($request->eventable_id);
        $model->events()->attach($event->id);
```

with:

```php
        $class = $request->eventable_type;
        $model = $class::findOrFail($request->eventable_id);
        $model->events()->attach($event->id);
        if ($model instanceof ServiceOrder) {
            $model->advanceToPlannedStage();
        }
```

- [ ] **Step 3: Call `advanceToPlannedStage()` in `update`**

In the same file, in the `update` method, replace:

```php
            DB::table('eventables')
                ->where('event_id', $event->id)
                ->where('eventable_type', [
                    substr($request->eventable_type, 1),
                ])
                ->delete();

            $model->events()->attach($event->id);
        }
```

with:

```php
            DB::table('eventables')
                ->where('event_id', $event->id)
                ->where('eventable_type', [
                    substr($request->eventable_type, 1),
                ])
                ->delete();

            $model->events()->attach($event->id);
            if ($model instanceof ServiceOrder) {
                $model->advanceToPlannedStage();
            }
        }
```

- [ ] **Step 4: Verify**

Run: `php -l app/Http/Controllers/EventApiController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```
git add app/Http/Controllers/EventApiController.php
git commit -m "feat(ServiceOrderStages): advance SO to planned stage on event attach"
```

---

## Task 9: `PlannerController` — filter unplanned widget by `is_plannable_state`

**Files:**
- Modify: `app/Http/Controllers/PlannerController.php`

- [ ] **Step 1: Update the `unplannedServiceOrders` query**

In `app/Http/Controllers/PlannerController.php`, replace:

```php
            'unplannedServiceOrders' => ServiceOrder::with('customer')
                ->doesntHave('events')
                ->whereNull('project_id')
                ->orderByDesc('created_at')
                ->get(),
```

with:

```php
            'unplannedServiceOrders' => ServiceOrder::with(['customer', 'serviceOrderStage'])
                ->doesntHave('events')
                ->whereNull('project_id')
                ->whereHas('serviceOrderStage', fn ($q) => $q->where('is_plannable_state', true))
                ->orderByDesc('created_at')
                ->get(),
```

- [ ] **Step 2: Verify**

Run: `php -l app/Http/Controllers/PlannerController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```
git add app/Http/Controllers/PlannerController.php
git commit -m "feat(ServiceOrderStages): planner unplanned widget filtered by plannable stage"
```

---

## Task 10: `StepsProgressBar` — mobile branch uses `SelectMenuComponent`

**Files:**
- Modify: `resources/js/Components/UI/StepsProgressBar.vue`

- [ ] **Step 1: Replace the entire mobile branch**

Open `resources/js/Components/UI/StepsProgressBar.vue`. Replace the entire `<div class="md:hidden">…</div>` block (currently containing a Listbox) with:

```vue
        <div class="md:hidden">
            <SelectMenuComponent
                :options="selectOptions"
                :model-value="modelValue"
                @update:modelValue="(v) => $emit('update:modelValue', v)" />
        </div>
```

Keep the existing `<nav aria-label="Progress" class="hidden md:block">…</nav>` (desktop) block exactly as-is.

- [ ] **Step 2: Update the imports**

Replace the existing imports block:

```js
import { computed } from 'vue'
import { Listbox, ListboxButton, ListboxLabel, ListboxOption, ListboxOptions } from '@headlessui/vue'
import { CheckIcon } from '@heroicons/vue/24/solid'
import { ChevronDownIcon } from '@heroicons/vue/20/solid'
```

with:

```js
import { computed } from 'vue'
import { CheckIcon } from '@heroicons/vue/24/solid'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'
```

(The Headless UI Listbox imports and `ChevronDownIcon` are no longer used. `CheckIcon` is still used by the desktop stepper.)

- [ ] **Step 3: Add `selectOptions` computed**

In `<script setup>`, add a new computed near the other computeds:

```js
const selectOptions = computed(() => props.steps.map(s => ({
    value: s.id,
    title: s.name,
    description: s.description,
})))
```

- [ ] **Step 4: Drop `onSelect` and `selectedStep`**

Remove the now-unused `function onSelect(step)` and the `selectedStep` computed. The `currentIndex` computed and `stepStatus(idx)` function stay — they're still used by the desktop stepper.

- [ ] **Step 5: Verify**

Run:
```
node -e "const fs=require('fs'); const s=fs.readFileSync('resources/js/Components/UI/StepsProgressBar.vue','utf8'); console.log('lines:', s.split('\\n').length);"
```
Expected: line count drops (was 123 lines; should be ~80–90 after).

Visually sanity-check:
- The desktop `<nav class="hidden md:block">` block is untouched.
- The mobile block now contains exactly one `<SelectMenuComponent>`.
- No `Listbox*` identifiers remain in the file.

Confirm with: `grep -c "Listbox" resources/js/Components/UI/StepsProgressBar.vue` → expected `0`.

- [ ] **Step 6: Commit**

```
git add resources/js/Components/UI/StepsProgressBar.vue
git commit -m "refactor(StepsProgressBar): mobile branch uses SelectMenuComponent"
```

---

## Task 11: `ServiceOrderStages/IndexPage.vue` — add flag switches

**Files:**
- Modify: `resources/js/Pages/ServiceOrderStages/IndexPage.vue`

- [ ] **Step 1: Update the header row**

Replace:

```vue
            <div class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-1"></div>
                <div class="col-span-2">Volgorde</div>
                <div class="col-span-7">Naam</div>
                <div class="col-span-2 text-right">Acties</div>
            </div>
```

with:

```vue
            <div class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-1"></div>
                <div class="col-span-1">Volgorde</div>
                <div class="col-span-4">Naam</div>
                <div class="col-span-2 text-center">Plannen</div>
                <div class="col-span-2 text-center">Sluiten</div>
                <div class="col-span-1 text-center">Plannable</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
```

- [ ] **Step 2: Update the row template**

Replace:

```vue
                <div v-for="stage in internalStages" :key="stage.id" role="row"
                    class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                    <div class="col-span-1 flex items-center">
                        <Bars4Icon class="size-6 text-gray-500 cursor-move draghandle"
                            v-tooltip="'Sleep om de volgorde aan te passen'" />
                    </div>
                    <div class="col-span-2 text-gray-800 dark:text-slate-200">
                        {{ stage.order }}
                    </div>
                    <div class="col-span-7 pr-4">
                        <EditableTextField type="input" :decoration="false" :model-value="stage.name"
                            @update="(val) => saveStage(stage.id, val)" />
                    </div>
                    <div class="col-span-2 flex justify-end">
                        <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex">
                            <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteStage(stage.id)"
                                v-tooltip="'Verwijder deze fase'" />
                        </div>
                    </div>
                </div>
```

with:

```vue
                <div v-for="stage in internalStages" :key="stage.id" role="row"
                    class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                    <div class="col-span-1 flex items-center">
                        <Bars4Icon class="size-6 text-gray-500 cursor-move draghandle"
                            v-tooltip="'Sleep om de volgorde aan te passen'" />
                    </div>
                    <div class="col-span-1 text-gray-800 dark:text-slate-200">
                        {{ stage.order }}
                    </div>
                    <div class="col-span-4 pr-4">
                        <EditableTextField type="input" :decoration="false" :model-value="stage.name"
                            @update="(val) => saveStage(stage.id, { name: val })" />
                    </div>
                    <div class="col-span-2 flex items-center justify-center">
                        <SwitchComponent :model-value="stage.is_planned_state"
                            @update:modelValue="(v) => saveStage(stage.id, { is_planned_state: v })" />
                    </div>
                    <div class="col-span-2 flex items-center justify-center">
                        <SwitchComponent :model-value="stage.is_closed_state"
                            @update:modelValue="(v) => saveStage(stage.id, { is_closed_state: v })" />
                    </div>
                    <div class="col-span-1 flex items-center justify-center">
                        <SwitchComponent :model-value="stage.is_plannable_state"
                            @update:modelValue="(v) => saveStage(stage.id, { is_plannable_state: v })" />
                    </div>
                    <div class="col-span-1 flex justify-end">
                        <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex">
                            <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteStage(stage.id)"
                                v-tooltip="'Verwijder deze fase'" />
                        </div>
                    </div>
                </div>
```

- [ ] **Step 3: Update `saveStage` to accept a payload object**

Replace:

```js
function saveStage(id, name) {
    router.patch(`/serviceorderstages/${id}`, { name }, { preserveScroll: true })
}
```

with:

```js
function saveStage(id, payload) {
    router.patch(`/serviceorderstages/${id}`, payload, { preserveScroll: true })
}
```

- [ ] **Step 4: Import `SwitchComponent`**

In `<script setup>`, alongside the other `@/Components/UI/...` imports, add:

```js
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
```

- [ ] **Step 5: Verify**

Run:
```
node -e "const s=require('fs').readFileSync('resources/js/Pages/ServiceOrderStages/IndexPage.vue','utf8'); console.log('lines:', s.split('\\n').length, 'switch imports:', (s.match(/SwitchComponent/g)||[]).length);"
```
Expected: lines around 160; `switch imports` at least 4 (1 import + 3 usages).

- [ ] **Step 6: Commit**

```
git add resources/js/Pages/ServiceOrderStages/IndexPage.vue
git commit -m "feat(ServiceOrderStages): add planned/closed/plannable switches"
```

---

## Task 12: `ServiceOrders/ShowPage.vue` — `is_closed` reads + close/reopen via stage

**Files:**
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

- [ ] **Step 1: Add the `closedStageId` prop**

Locate the existing `const props = defineProps({...})` call. Inside the props object, add:

```js
    closedStageId: { type: [Number, null], default: null },
```

(Adjacent to the existing `stages: { type: Array, default: () => [] }` entry.)

- [ ] **Step 2: Replace `serviceOrder.status === 'closed'` everywhere in template**

In the template only (not the script), replace every occurrence of:
- `serviceOrder.status === 'closed'` → `serviceOrder.is_closed`
- `serviceOrder.status !== 'closed'` → `!serviceOrder.is_closed`

There are 7 such occurrences across the template (around lines 118, 129, 138, 162, 182, 263, 267, 273, 281 in the current file — they may have shifted with prior edits; `grep "serviceOrder.status" resources/js/Pages/ServiceOrders/ShowPage.vue` finds all of them).

The line at the existing position ~220 `!serviceOrder.sent_to_administration && serviceOrder.status !== 'closed'` becomes `!serviceOrder.sent_to_administration && !serviceOrder.is_closed`. Same shape for the ~241 occurrence.

- [ ] **Step 3: Replace the close/reopen buttons**

Find the buttons block:

```vue
            <button class="w-full p-4 rounded-md bg-green-600 text-white mt-3 hover:bg-green-700"
                @click="updateStatus('closed')"
                v-if="serviceOrder.status !== 'closed' && hasPermission('serviceorder.close')">Werkbon
                afsluiten</button>
            <button class="w-full p-4 rounded-md bg-blue-500 text-white mt-3" @click="updateStatus('open')"
                v-else-if="serviceOrder.status !== 'open' && hasPermission('serviceorder.reopen')">Werkbon
                heropenen</button>
```

Replace with:

```vue
            <button class="w-full p-4 rounded-md bg-green-600 text-white mt-3 hover:bg-green-700"
                @click="closeViaStage"
                v-if="closedStageId !== null && !serviceOrder.is_closed && hasPermission('serviceorder.close')">Werkbon
                afsluiten</button>
            <button class="w-full p-4 rounded-md bg-blue-500 text-white mt-3" @click="reopenViaStage"
                v-else-if="serviceOrder.is_closed && hasPermission('serviceorder.reopen')">Werkbon
                heropenen</button>
```

- [ ] **Step 4: Replace the `updateStatus` function with stage handlers**

Find and remove the existing `updateStatus` function (around line 542 — `const updateStatus = (newStatus) => { ... }`). Add these two functions in its place:

```js
function closeViaStage() {
    if (!canClose.value) {
        alert('Vul zowel de naam als de handtekening in om de werkbon te kunnen afsluiten.')
        return
    }
    if (!confirm('Weet je zeker dat je de werkbon wilt sluiten? Je kunt er daarna geen wijzigingen meer in aanbrengen.')) {
        return
    }
    onStageChange(props.closedStageId)
}

function reopenViaStage() {
    onStageChange(null)
}
```

(`canClose` and `onStageChange` already exist in this file.)

- [ ] **Step 5: Verify**

Run:
```
grep -n "serviceOrder\.status\|updateStatus" resources/js/Pages/ServiceOrders/ShowPage.vue
```
Expected: no output, OR only `ticket.status !== 'Gesloten'` on line ~519 (that's the ticket-status filter — leave it alone — it refers to a different model's status).

Use the dev server (or `npm run dev` if running) and load the SO show page. Confirm:
- A SO without a closed-state stage shows neither "Werkbon afsluiten" nor "Werkbon heropenen".
- A SO with a configured closed-state stage shows "Werkbon afsluiten" until clicked; clicking sets the stage and switches to "Werkbon heropenen".
- The remarks panel / signature / "Toon historische tickets" gating switches the same way it used to.

- [ ] **Step 6: Commit**

```
git add resources/js/Pages/ServiceOrders/ShowPage.vue
git commit -m "feat(ServiceOrderStages): SO ShowPage reads is_closed; close/reopen routes via stage"
```

---

## Self-Review

**Spec coverage:**

- Database: Migration 1 (flags) → Task 1 step 1. Migration 2 (backfill + drop status) → Task 1 step 2. Enum cleanup → Task 2.
- `ServiceOrderStage` model: fillable + casts → Task 3 step 1.
- `ServiceOrder` model: fillable/casts/accessor/appends/with/advanceToPlannedStage → Task 3 steps 2–4.
- `ServiceOrderUpdateRequest` → Task 4.
- `ServiceOrderStageStoreUpdateRequest` rules → Task 5.
- `ServiceOrderStageController` singleton enforcement → Task 6.
- `ServiceOrderController::update` lifecycle + `emailPdf`/`emailPdfWithJobs`/`sendToSnelStart` is_closed + show's `closedStageId` → Task 7.
- `EventApiController` `advanceToPlannedStage` hook → Task 8.
- `PlannerController` filter → Task 9.
- `StepsProgressBar` mobile → Task 10.
- `ServiceOrderStages` IndexPage flag switches → Task 11.
- `ServiceOrders` ShowPage `is_closed` + close/reopen → Task 12.
- Edge cases (no closed stage / no planned stage / flag toggled off / stage deleted) → covered by the in-place logic; no extra task required.

**Placeholder scan:** Each code block is the exact text to write. No "TBD"/"TODO" left. No "add appropriate error handling" — all error states are concrete (alerts, redirects with flash, no-op returns).

**Type / name consistency:**
- Accessor `is_closed` consistent in: model (`getIsClosedAttribute`), JSON serialisation (`$appends`), PHP callers (`$serviceorder->is_closed`), Vue templates (`serviceOrder.is_closed`).
- Method `advanceToPlannedStage` consistent between Task 3 (definition) and Task 8 (call sites).
- Prop name `closedStageId` consistent: controller passes (Task 7 step 5), ShowPage declares (Task 12 step 1), template references (Task 12 step 3).
- Flag column names `is_planned_state` / `is_closed_state` / `is_plannable_state` consistent across migration, model, request, controllers, frontend payloads.
