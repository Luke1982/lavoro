# Ticket storingscode, closed_by en activiteiten — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `status_code` text field and `closed_by_id` FK to tickets, auto-populate `closed_by_id`/`closed_on` on close/reopen via a model observer, and log activity entries whenever a ticket's status or priority changes.

**Architecture:** A `TicketObserver` (registered in `AppServiceProvider`) owns all close/reopen side-effects and activity logging via the `updated()` hook. The `HasActivities` trait is added to `Ticket` so `logActivity()` works. Frontend adds the storingscode field to `ShowPage` and a read-only display in `TicketCard`.

**Tech Stack:** Laravel 12, Eloquent Observers, Inertia + Vue 3, `useForm` + `watch` pattern already used throughout this app.

---

## File map

| Action | File |
|--------|------|
| Create | `database/migrations/2026_06_04_000003_add_storingscode_and_closed_by_to_tickets_table.php` |
| Modify | `app/Models/Ticket.php` |
| Modify | `app/Models/Activity.php` |
| Create | `app/Observers/TicketObserver.php` |
| Modify | `app/Providers/AppServiceProvider.php` |
| Modify | `app/Http/Requests/TicketUpdateRequest.php` |
| Modify | `app/Http/Controllers/TicketController.php` |
| Modify | `resources/js/Pages/Tickets/ShowPage.vue` |
| Modify | `resources/js/Components/TicketCard.vue` |

---

## Task 1: Database migration

**Files:**
- Create: `database/migrations/2026_06_04_000003_add_storingscode_and_closed_by_to_tickets_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('status_code')->nullable()->after('priority');
            $table->foreignIdFor(User::class, 'closed_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('status_code');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_id');
            $table->dropColumn('status_code');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_04_000003_add_storingscode_and_closed_by_to_tickets_table` followed by `Migrated`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_04_000003_add_storingscode_and_closed_by_to_tickets_table.php
git commit -m "feat(tickets): add status_code and closed_by_id columns"
```

---

## Task 2: Update Ticket model and Activity model

**Files:**
- Modify: `app/Models/Ticket.php`
- Modify: `app/Models/Activity.php`

- [ ] **Step 1: Replace Ticket model content**

Full new content for `app/Models/Ticket.php`:

```php
<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RemarkableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    /** @use HasFactory<\Database\Factories\TicketFactory> */
    use HasFactory;
    use RemarkableTrait;
    use HasCustomFields;
    use HasActivities;

    protected $fillable = [
        'asset_id',
        'subject',
        'description',
        'status',
        'priority',
        'status_code',
        'closed_on',
        'closed_by_id',
        'service_order_id',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main'])
            ->withTimestamps();
    }
}
```

- [ ] **Step 2: Add `tickets()` to Activity model**

In `app/Models/Activity.php`, add after the `serviceOrderStages()` method and add the `Ticket` import:

Add import at the top (with other use statements):
```php
use App\Models\Ticket;
```

Add method after `serviceOrderStages()`:
```php
    public function tickets(): MorphToMany
    {
        return $this->morphedByMany(Ticket::class, 'activityable')->withTimestamps();
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Ticket.php app/Models/Activity.php
git commit -m "feat(tickets): add HasActivities trait, closedBy relation, and tickets() on Activity"
```

---

## Task 3: TicketObserver + AppServiceProvider registration

**Files:**
- Create: `app/Observers/TicketObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create the observer**

Create `app/Observers/TicketObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;

class TicketObserver
{
    public function updated(Ticket $ticket): void
    {
        $changes = $ticket->getChanges();

        if (array_key_exists('status', $changes)) {
            $old_status = $ticket->getOriginal('status');
            $new_status = $changes['status'];
            $ticket->logActivity("Status gewijzigd van '{$old_status}' naar '{$new_status}'", category: 'status');
        }

        if (array_key_exists('priority', $changes)) {
            $old_priority = $ticket->getOriginal('priority');
            $new_priority = $changes['priority'];
            $ticket->logActivity("Prioriteit gewijzigd van '{$old_priority}' naar '{$new_priority}'", category: 'status');
        }

        if (array_key_exists('status', $changes)) {
            $new_status = $changes['status'];
            if ($new_status === 'Gesloten') {
                $ticket->closed_by_id = Auth::id();
                $ticket->closed_on    = now();
                $ticket->saveQuietly();
            } elseif ($ticket->getOriginal('status') === 'Gesloten') {
                $ticket->closed_by_id = null;
                $ticket->closed_on    = null;
                $ticket->saveQuietly();
            }
        }
    }
}
```

- [ ] **Step 2: Register the observer in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, add this line inside `boot()`, after the existing `EventModel::observe(...)` line:

```php
\App\Models\Ticket::observe(\App\Observers\TicketObserver::class);
```

- [ ] **Step 3: Commit**

```bash
git add app/Observers/TicketObserver.php app/Providers/AppServiceProvider.php
git commit -m "feat(tickets): add TicketObserver for activity logging and closed_by handling"
```

---

## Task 4: TicketUpdateRequest and TicketController cleanup

**Files:**
- Modify: `app/Http/Requests/TicketUpdateRequest.php`
- Modify: `app/Http/Controllers/TicketController.php`

- [ ] **Step 1: Add `status_code` to TicketUpdateRequest rules**

In `app/Http/Requests/TicketUpdateRequest.php`, update `rules()` to:

```php
public function rules(): array
{
    return [
        'subject'     => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'priority'    => 'nullable|in:' . implode(',', array_map(fn($prio) => $prio->value, TicketPriorities::cases())),
        'status'      => 'nullable|in:' . implode(',', array_map(fn($status) => $status->value, TicketStatusses::cases())),
        'asset_id'    => 'nullable|exists:assets,id',
        'status_code' => 'nullable|string|max:255',
    ];
}
```

- [ ] **Step 2: Remove manual closed_on logic from TicketController::update()**

In `app/Http/Controllers/TicketController.php`, replace the `update()` method with:

```php
public function update(TicketUpdateRequest $request, Ticket $ticket)
{
    $ticket->update($request->validated());
    $message = sprintf(
        "Storing is bijgewerkt, de status is nu '%s' en de prioriteit is '%s'.",
        $request->status,
        $request->priority
    );

    return redirect()->back()->with([
        'success' => $message,
        'extra' => [
            'ticket' => $ticket,
        ]
    ]);
}
```

- [ ] **Step 3: Add `closedBy` to show() eager load**

In `TicketController::show()`, update the `load()` call:

```php
$ticket->load(['asset.customer', 'asset.product.productType', 'asset.product.brand', 'images', 'customFields', 'closedBy']);
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/TicketUpdateRequest.php app/Http/Controllers/TicketController.php
git commit -m "feat(tickets): add status_code to request, remove manual closed_on logic, eager-load closedBy"
```

---

## Task 5: ShowPage.vue — storingscode field and closed_by display

**Files:**
- Modify: `resources/js/Pages/Tickets/ShowPage.vue`

- [ ] **Step 1: Add `status_code` to useForm**

In `<script setup>`, update the `useForm` call:

```js
const form = useForm({
    subject: props.ticket.subject,
    description: props.ticket.description,
    status: initialStatus.id,
    priority: props.priorities.find(p => p.name === props.ticket.priority).id,
    status_code: props.ticket.status_code ?? '',
});
```

- [ ] **Step 2: Add watch for status_code**

After the existing `watch(() => form.description, ...)` block, add:

```js
watch(() => form.status_code, (newVal) => {
    patchTicketField('status_code', newVal);
});
```

- [ ] **Step 3: Add Storingscode row to the template grid**

After the closing `</div>` of the Omschrijving value column and before the Status row (i.e., before `<div class="col-span-2">` for Status), insert:

```html
                    <div class="col-span-12 md:col-span-2">
                        <span class="text-xs font-bold">Storingscode</span>
                    </div>
                    <div class="col-span-12 md:col-span-10">
                        <EditableTextField v-model="form.status_code" class="w-full"
                            :readonly="!hasPermission('ticket.update')" />
                    </div>
```

- [ ] **Step 4: Add closed_by row after the Status row**

After the closing `</div>` pair of the Status ComboBox/span block and before the Prioriteit row, insert:

```html
                    <template v-if="ticket.closed_by">
                        <div class="col-span-12 md:col-span-2">
                            <span class="text-xs font-bold">Gesloten door</span>
                        </div>
                        <div class="col-span-12 md:col-span-10 text-sm text-gray-600 dark:text-slate-300">
                            {{ ticket.closed_by.name }}
                        </div>
                    </template>
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Tickets/ShowPage.vue
git commit -m "feat(tickets): add storingscode field and closed_by display to ShowPage"
```

---

## Task 6: TicketCard.vue — show status_code read-only

**Files:**
- Modify: `resources/js/Components/TicketCard.vue`

- [ ] **Step 1: Add status_code display**

In `resources/js/Components/TicketCard.vue`, find this block (around line 101):

```html
<p v-if="modes.find(m => m === 'simple') === undefined"
    class="text-sm text-gray-500 dark:text-slate-400 mt-2">{{ ticket.description }}</p>
```

Add immediately after it:

```html
<p v-if="ticket.status_code"
    class="text-xs text-gray-400 dark:text-slate-500 mt-1">
    Storingscode: {{ ticket.status_code }}
</p>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/TicketCard.vue
git commit -m "feat(tickets): show status_code in TicketCard when present"
```

---

## Verification checklist

- [ ] Open a ticket's ShowPage: Storingscode field renders, typing saves via `PATCH /tickets/{id}`.
- [ ] Change ticket status via ComboBox: confirm activity row created — `select * from activities where description like 'Status%' order by id desc limit 1;`
- [ ] Set status to "Gesloten": confirm `closed_by_id` and `closed_on` populated on the ticket row.
- [ ] Reopen ticket (set to "Open"): confirm `closed_by_id` and `closed_on` are NULL.
- [ ] Change priority: confirm priority activity row created.
- [ ] View a TicketCard with `status_code` set: storingscode line appears.
