# Domain Signal Layer Implementation Plan (revision 2)

**Goal:** Give Lavoro one uniform, extensible domain event layer so new reactions to
business facts can be added by writing a listener class, without editing the code that
caused the fact.

**Architecture:** A `Signal` interface in `App\Domain\Signals` describes "a business fact
that happened". Signals are dispatched from domain methods on models and services — never
from observers, never from controllers. Listeners subscribe either to a concrete signal
class or to the `Signal` interface itself. The activity log stops being the mechanism and
becomes the first subscriber.

**Tech Stack:** Laravel 12.58, PHP 8.2, `Illuminate\Events\Dispatcher`, auto-discovered
listeners. No new packages.

## Global Constraints

- PHP variables are `snake_case`; methods stay `camelCase`.
- No inline comments. Docblocks only where the reason is non-obvious.
- No new tests (project rule). Existing suite must stay green.
- `./vendor/bin/pint` after every file edit, not batched.
- `!$foo`, never `! $foo`.
- Namespace is `App\Domain\Signals`, **not** `App\Events` — `App\Models\Event` means
  "appointment" and the `Event` facade owns the short name in `AppServiceProvider`.
- All user-facing strings are Dutch.

## Verified baseline

`composer test` → **215 passed, 610 assertions**. Any failure after this point is ours.

---

## Verified assumptions

These were checked against the installed framework, not assumed.

**1. Listeners resolve through interfaces, not parent classes.**
`Dispatcher::addInterfaceListeners()` iterates `class_implements($event_name)`. There is no
`class_parents` walk anywhere in `Dispatcher.php`. So `Signal` **must be an interface** —
an abstract base class would make `Event::listen(Signal::class, …)` silently never fire.
`BaseSignal` exists only to share constructor boilerplate.

**2. `ShouldDispatchAfterCommit` behaves correctly under `RefreshDatabase`.**
Probed directly. Dispatched outside a transaction: fires immediately. Inside a committed
`DB::transaction`: fires after commit. Inside a rolled-back transaction: does not fire. The
outer test-suite transaction does **not** swallow them. This was the single biggest risk to
the design and it is clear.

**3. `HasActivities::logActivity()` accepts `$occurred_at` and silently discards it.**
There is no `occurred_at` column on `activities` in any migration. The parameter is dead.
`RecordActivity` must **not** pass it — doing so would propagate the illusion that activity
timestamps are controllable.

**4. `ServiceOrder` has `protected $with = ['serviceOrderStage', …]`.**
The stage relation is always eager-loaded, and `is_closed` is
`$this->serviceOrderStage?->is_closed_state === true`. Any code that changes the stage must
`setRelation` afterwards or `is_closed` reports the old value.

---

## When to create a signal

This is the rule that keeps the layer from becoming boilerplate sludge. A signal costs
~40 lines; a `logActivity()` call costs one. **Create a signal only when the fact has two or
more emit sites, or two or more consumers.** Everything else stays a plain `logActivity`
call until it earns promotion.

The three signals below qualify: stage change has 5 emit sites, customer change has 2,
assignment has 3 emit sites and 2 consumers.

---

## Behaviour changes this refactor introduces

Stated up front so none of these are discovered in production.

1. **`bulkUpdate` starts logging and starts stamping `closed_on`.** Today it is a mass
   `update()` that bypasses Eloquent entirely: no activity, no `closed_on`. This is a bug
   fix, but it is a change.
2. **Assignment notifications become after-commit.** In `EventApiController::update()` the
   notify calls are currently inside `DB::transaction`. They will no longer fire for
   assignments that roll back. Better, but different.
3. **`moveToCustomer`'s activity line gains quotes.** Today the model method writes
   `Klant gewijzigd van X naar Y (via agenda)` while the controller writes
   `Klant gewijzigd van 'X' naar 'Y'`. Unifying on one signal picks the quoted form. No test
   asserts the unquoted string (checked).
4. **Activity writes for stage/customer changes move outside the surrounding transaction.**
   Accepted tradeoff: a lost log row is strictly better than a log row describing work that
   was rolled back.

---

## File Structure

**Create:**
- `app/Domain/Signals/Signal.php` — the interface the dispatcher listens on.
- `app/Domain/Signals/BaseSignal.php` — abstract, captures actor and timestamp.
- `app/Domain/Signals/ServiceOrderStageChanged.php`
- `app/Domain/Signals/ServiceOrderCustomerChanged.php`
- `app/Domain/Signals/ServiceOrderAssigned.php`
- `app/Listeners/RecordActivity.php`
- `app/Listeners/NotifyAssignedUsers.php`
- `database/migrations/…_add_signal_key_to_activities_table.php`

**Modify:**
- `app/Providers/AppServiceProvider.php`
- `app/Models/Activity.php`
- `app/Models/Traits/HasActivities.php`
- `app/Models/ServiceOrder.php`
- `app/Http/Controllers/ServiceOrderController.php`
- `app/Http/Controllers/EventApiController.php`

**Cut from revision 1:** `SignalRegistry`. Nothing consumes it, the automation-rules feature
that would is explicitly out of scope, and an unused registry rots. It arrives with its
consumer or not at all.

`signal_key` on `activities` is kept despite having no consumer yet, on different grounds:
it is data, and historical rows cannot be backfilled once the moment has passed.

---

## Task 1: The signal contract

**Files:** Create `app/Domain/Signals/Signal.php`, `app/Domain/Signals/BaseSignal.php`

**Produces:** `Signal` with `key()`, `label()`, `subject()`, `activityDescription()`,
`activityCategory()`, `activityMetadata()`, `activityContext()`, `actorId()`.

- [ ] **Step 1: Write `Signal.php`**

```php
<?php

namespace App\Domain\Signals;

use Illuminate\Database\Eloquent\Model;

interface Signal
{
    public static function key(): string;

    public static function label(): string;

    public function subject(): Model;

    public function activityDescription(): ?string;

    public function activityCategory(): string;

    /** @return array<string, mixed>|null */
    public function activityMetadata(): ?array;

    /** @return array<int, Model> */
    public function activityContext(): array;

    public function actorId(): ?int;
}
```

- [ ] **Step 2: Write `BaseSignal.php`**

Properties are deliberately not `readonly`: `SerializesModels::__unserialize()` reassigns
properties by reflection, which throws on an initialised readonly property and would break
every queued listener.

```php
<?php

namespace App\Domain\Signals;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

abstract class BaseSignal implements Signal, ShouldDispatchAfterCommit
{
    use SerializesModels;

    public ?int $actor_id;

    public function __construct()
    {
        $this->actor_id = Auth::id();
    }

    public static function label(): string
    {
        return static::key();
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public function activityMetadata(): ?array
    {
        return null;
    }

    public function activityContext(): array
    {
        return [];
    }

    public function actorId(): ?int
    {
        return $this->actor_id;
    }
}
```

- [ ] **Step 3:** `./vendor/bin/pint app/Domain/Signals/`

---

## Task 2: Activity recording as a subscriber

**Files:** migration, `app/Listeners/RecordActivity.php`, `app/Models/Activity.php`,
`app/Models/Traits/HasActivities.php`, `app/Providers/AppServiceProvider.php`

**Produces:** `logActivity(..., ?string $signal_key = null)`; activities carry `signal_key`.

- [ ] **Step 1:** `php artisan make:migration add_signal_key_to_activities_table --table=activities`

```php
public function up(): void
{
    Schema::table('activities', function (Blueprint $table) {
        $table->string('signal_key')->nullable()->index()->after('category');
    });
}

public function down(): void
{
    Schema::table('activities', function (Blueprint $table) {
        $table->dropColumn('signal_key');
    });
}
```

`down()` drops only the column — both MySQL and SQLite drop the dependent index with it, and
an explicit `dropIndex` is the portability hazard.

- [ ] **Step 2:** Add `'signal_key'` to `Activity::$fillable`.

- [ ] **Step 3:** Add a trailing `?string $signal_key = null` parameter to `logActivity` and
pass it into `Activity::create()`. Every existing call site uses named arguments beyond
`$description` (verified across all 50), so a trailing optional parameter is source-compatible.

- [ ] **Step 4: Write `RecordActivity`**

Does not pass `occurred_at` — see verified assumption 3. Resolves the actor without a query
when it is the current user, which is the overwhelmingly common case.

```php
<?php

namespace App\Listeners;

use App\Domain\Signals\Signal;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RecordActivity
{
    public function handle(Signal $signal): void
    {
        $description = $signal->activityDescription();

        if ($description === null) {
            return;
        }

        $subject = $signal->subject();

        if (!method_exists($subject, 'logActivity')) {
            return;
        }

        $subject->logActivity(
            $description,
            category: $signal->activityCategory(),
            user: $this->resolveActor($signal),
            also_attach_to: $signal->activityContext(),
            metadata: $signal->activityMetadata(),
            signal_key: $signal::key(),
        );
    }

    private function resolveActor(Signal $signal): ?User
    {
        $actor_id = $signal->actorId();

        if ($actor_id === null) {
            return null;
        }

        return $actor_id === Auth::id() ? Auth::user() : User::find($actor_id);
    }
}
```

- [ ] **Step 5:** In `AppServiceProvider::boot()`, using the already-imported `Event` facade:

```php
Event::listen(Signal::class, RecordActivity::class);
```

- [ ] **Step 6:** `./vendor/bin/pint` then `composer test` — expect 215 passing.

---

## Task 3: Stage signal and the single choke point

**Files:** Create `app/Domain/Signals/ServiceOrderStageChanged.php`; modify
`app/Models/ServiceOrder.php:228-271`

**Produces:** `ServiceOrder::moveToStage(?ServiceOrderStage $stage, ?string $reason = null): bool`
— the only supported way to change an order's stage.

- [ ] **Step 1: Write the signal**

```php
<?php

namespace App\Domain\Signals;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderStageChanged extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?ServiceOrderStage $previous_stage,
        public ?ServiceOrderStage $new_stage,
        public ?string $reason = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.stage_changed';
    }

    public static function label(): string
    {
        return 'Werkbon fase gewijzigd';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        if (!$this->new_stage) {
            return 'Fase verwijderd';
        }

        $suffix = $this->reason ? ' (' . $this->reason . ')' : '';

        return 'Fase gewijzigd naar: ' . $this->new_stage->name . $suffix;
    }

    public function activityCategory(): string
    {
        return 'stage';
    }

    public function activityContext(): array
    {
        return $this->new_stage ? [$this->new_stage] : [];
    }

    public function activityMetadata(): ?array
    {
        return [
            'previous_stage_id' => $this->previous_stage?->id,
            'new_stage_id' => $this->new_stage?->id,
        ];
    }
}
```

- [ ] **Step 2: Add `moveToStage()` to `ServiceOrder`**

Three things this must get right, all of which revision 1 got wrong:
compare the **raw foreign key** rather than the loaded relation (which goes stale after a
mass `update()`); own the `closed_on` bookkeeping, because `closed_on` is derived from stage
and leaving it in the controller reintroduces exactly the drift this refactor removes; and
write both columns in **one** save.

```php
public function moveToStage(?ServiceOrderStage $stage, ?string $reason = null): bool
{
    if ($this->service_order_stage_id === $stage?->id) {
        return false;
    }

    $previous_stage = $this->serviceOrderStage;
    $was_closed = $this->is_closed;

    $this->service_order_stage_id = $stage?->id;
    $this->setRelation('serviceOrderStage', $stage);

    $is_closed = $this->is_closed;

    if ($is_closed && !$was_closed) {
        $this->closed_on = now();
    } elseif (!$is_closed && $was_closed) {
        $this->closed_on = null;
    }

    $this->save();

    event(new ServiceOrderStageChanged($this, $previous_stage, $stage, $reason));

    return true;
}
```

- [ ] **Step 3: Rewrite `advanceToPlannedStage()` to delegate**

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

    $this->moveToStage($planned, 'door koppeling agenda');
}
```

- [ ] **Step 4: Rewrite `revertToPlanningCancelledStage()` to delegate**

```php
public function revertToPlanningCancelledStage(): void
{
    $cancelled = ServiceOrderStage::where('is_planning_cancelled_state', true)->first();

    if (!$cancelled) {
        return;
    }

    $this->moveToStage($cancelled, 'agenda item verwijderd');
}
```

- [ ] **Step 5:** pint, then `composer test`.

---

## Task 4: Customer signal

**Files:** Create `app/Domain/Signals/ServiceOrderCustomerChanged.php`; modify
`app/Models/ServiceOrder.php:249-260`

Carries **names, not ids**: the activity line quotes the old name and the old customer may
later be deleted, so the log has to survive that.

- [ ] **Step 1: Write the signal**

```php
<?php

namespace App\Domain\Signals;

use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderCustomerChanged extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?string $previous_customer_name,
        public ?string $new_customer_name,
        public ?string $reason = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.customer_changed';
    }

    public static function label(): string
    {
        return 'Werkbon klant gewijzigd';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        $suffix = $this->reason ? ' (' . $this->reason . ')' : '';

        return "Klant gewijzigd van '" . $this->previous_customer_name
            . "' naar '" . $this->new_customer_name . "'" . $suffix;
    }
}
```

- [ ] **Step 2: Rewrite `moveToCustomer()`**

```php
public function moveToCustomer(int $customer_id): void
{
    $previous = $this->customer?->name;

    $this->update([
        'customer_id' => $customer_id,
        'location_id' => null,
    ]);

    $new = $this->refresh()->customer?->name;

    event(new ServiceOrderCustomerChanged($this, $previous, $new, 'via agenda'));
}
```

- [ ] **Step 3:** pint, then `composer test`.

---

## Task 5: Migrate `ServiceOrderController`

**Files:** `app/Http/Controllers/ServiceOrderController.php` lines 424-527 and 1015-1018

- [ ] **Step 1: Pull the stage out of the mass-assigned payload**

Immediately before `$serviceorder->update($data);` (line 435):

```php
$stage_requested = array_key_exists('service_order_stage_id', $data);
$requested_stage_id = $data['service_order_stage_id'] ?? null;
unset($data['service_order_stage_id']);
```

A separate boolean, not a `false` sentinel — the payload legitimately carries `null` to mean
"clear the stage", so absence and null must stay distinguishable without overloading a value.

- [ ] **Step 2: Replace the invoiced-stage block (lines 462-476)**

```php
if (
    array_key_exists('external_invoice_no', $data)
    && filled($data['external_invoice_no'])
    && blank($previous_external_invoice_no)
) {
    $invoiced_stage = ServiceOrderStage::where('is_invoiced_state', true)->first();

    if ($invoiced_stage) {
        $serviceorder->moveToStage($invoiced_stage, 'door extern factuurnummer');
        $stage_requested = false;
    }
}
```

Clearing `$stage_requested` matters: an explicit invoice number wins over whatever stage the
form submitted, and without this the next step would immediately move the order back.

- [ ] **Step 3: Delete lines 478-504 entirely and apply the requested stage**

Lines 478-487 (`$new_is_closed` / `closed_on`) and 489-504 (stage logging) both become dead —
`moveToStage` owns both concerns now. Replace with:

```php
if ($stage_requested) {
    $serviceorder->moveToStage(
        $requested_stage_id === null ? null : ServiceOrderStage::find($requested_stage_id)
    );
}
```

`$previous_is_closed` (line 425) becomes unused and must be deleted with it.

- [ ] **Step 4: Replace the customer-change logging block (lines 506-509)**

```php
if (array_key_exists('customer_id', $data) && $data['customer_id'] != $previous_customer_id) {
    event(new ServiceOrderCustomerChanged(
        $serviceorder,
        $previous_customer_name,
        $serviceorder->customer()->value('name'),
    ));
}
```

- [ ] **Step 5: Fix `bulkUpdate` (lines 1015-1018)**

```php
public function bulkUpdate(ServiceOrderBulkUpdateRequest $request)
{
    $stage = ServiceOrderStage::find($request->input('service_order_stage_id'));

    ServiceOrder::whereIn('id', $request->input('service_order_ids'))
        ->get()
        ->each(fn (ServiceOrder $order) => $order->moveToStage($stage));

    return redirect()->back()->with('success', 'Fase bijgewerkt.');
}
```

No `with('serviceOrderStage')` — `ServiceOrder::$with` already eager-loads it. This trades one
mass `UPDATE` for N saves plus N activity inserts; acceptable because the action is driven
from a filtered page selection, not a whole-table operation.

- [ ] **Step 6:** pint, then `composer test`.

---

## Task 6: Assignment signal

**Files:** Create `app/Domain/Signals/ServiceOrderAssigned.php`,
`app/Listeners/NotifyAssignedUsers.php`; modify `app/Http/Controllers/EventApiController.php`
lines 277-280 and 412-434

- [ ] **Step 1: Write the signal**

`activityDescription()` returns `null` because assignment is not written to the activity log
today and this refactor must not change what users see.

```php
<?php

namespace App\Domain\Signals;

use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderAssigned extends BaseSignal
{
    /** @param  array<int, int>  $newly_assigned_user_ids */
    public function __construct(
        public ServiceOrder $service_order,
        public array $newly_assigned_user_ids,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.assigned';
    }

    public static function label(): string
    {
        return 'Werkbon toegewezen';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        return null;
    }
}
```

- [ ] **Step 2: Write the listener** (auto-discovered from the `handle` type hint)

```php
<?php

namespace App\Listeners;

use App\Domain\Signals\ServiceOrderAssigned;
use App\Models\User;
use App\Notifications\NewServiceOrderAssigned;

class NotifyAssignedUsers
{
    public function handle(ServiceOrderAssigned $signal): void
    {
        if ($signal->newly_assigned_user_ids === []) {
            return;
        }

        User::whereIn('id', $signal->newly_assigned_user_ids)
            ->get()
            ->each(fn (User $user) => $user->notify(
                new NewServiceOrderAssigned($signal->service_order)
            ));
    }
}
```

- [ ] **Step 3: Replace the `store()` notify site (lines 277-280)**

```php
if ($notify_service_order) {
    event(new ServiceOrderAssigned($notify_service_order, $notify_user_ids));
}
```

- [ ] **Step 4: Replace both `update()` notify sites (lines 420-433)**

```php
if ($model instanceof ServiceOrder) {
    event(new ServiceOrderAssigned($model, array_values(array_diff($ids, $previously_executing))));
}
```

and

```php
$event->serviceOrders->each(function ($order) use ($ids) {
    $previously_executing = $order->executingUsers()->pluck('users.id')->all();
    $order->syncExecutingUsers($ids);
    $order->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($ids));
    event(new ServiceOrderAssigned($order, array_values(array_diff($ids, $previously_executing))));
});
```

- [ ] **Step 5:** Remove the now-unused `User` / `NewServiceOrderAssigned` imports from
`EventApiController` if nothing else references them.

- [ ] **Step 6:** pint, then `composer test` — expect 215 passing.

---

## Explicitly out of scope

- The other ~45 `logActivity` call sites. Most are single-site, single-consumer and by the
  rule above should stay as they are.
- The `automation_rules` table generalising `standard_email_triggers`. Separate feature,
  needs its own admin UI, and brings the registry with it.
- Removing the `str_contains` category inference in `HasActivities` — it stays until the last
  string call site is gone.
- `EventObserver` and Google sync. High risk, no benefit here.
- The dead `$occurred_at` parameter on `logActivity`. Worth deleting, but it is unrelated
  cleanup and touching a shared signature mid-refactor adds risk for no gain.
