# Standard e-mails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins define reusable e-mail templates ("Standaard e-mails", with reusable "Standaard bijlagen"), send them to an event's customer manually or automatically on event lifecycle triggers (created/updated/deleted) with per-trigger confirmation levels, and resend/view history from the event edit modal.

**Architecture:** New `standard_emails`/`standard_email_triggers`/`standard_attachments` tables plus a plain pivot; no new "sent e-mail" table — sends are logged via the existing `HasActivities`/`Activity` system (`Event` gains the trait). `EventObserver` (already dispatches jobs for Google sync) dispatches a queued job for `background` triggers; `EventApiController::store/update/destroy` synchronously resolve `confirm`/`allowedit` matches and return them in the same JSON response, since the observer runs before the controller responds. Sending always re-renders placeholders from live event data — never snapshotted.

**Tech Stack:** Laravel 12 (migrations, Eloquent, Policies, Form Requests, Mailables, Jobs, Observers), Inertia + Vue 3, TipTap (`@tiptap/vue-3`, `@tiptap/starter-kit`, `@tiptap/extension-mention`), Tailwind v4 (`lavoro-blue`/`lavoro-green` theme colors).

## Global Constraints

- PHP: snake_case for all variable names.
- No inline comments; prefer clear names and docblocks only when needed.
- No git commands/workflows are to be run by the implementer.
- Do not write automated tests (per project convention) — verify each task manually as described in its steps.
- Authorization: Form Request `authorize()` must call a Policy method via `can()` — never call `hasPermission()` directly inside a Form Request.
- Reuse the `userables`-style pivot conventions already in the codebase; don't introduce parallel structures where an existing mechanism (e.g. `HasActivities`) already fits.
- String concatenation always uses spaces: `$string . ' some other string'`.
- Validation belongs in Form Request `rules()` only.

---

### Task 1: Migrations, enums, and models

**Files:**
- Create: `database/migrations/2026_07_08_100001_create_standard_emails_table.php`
- Create: `database/migrations/2026_07_08_100002_create_standard_email_triggers_table.php`
- Create: `database/migrations/2026_07_08_100003_create_standard_attachments_table.php`
- Create: `database/migrations/2026_07_08_100004_create_standard_email_standard_attachment_table.php`
- Create: `app/Enums/EventTrigger.php`
- Create: `app/Enums/StandardEmailTriggerType.php`
- Create: `app/Models/StandardEmail.php`
- Create: `app/Models/StandardEmailTrigger.php`
- Create: `app/Models/StandardAttachment.php`

**Interfaces:**
- Produces: `EventTrigger` enum (cases: `event_created`, `event_updated`, `event_deleted`), `StandardEmailTriggerType` enum (cases: `background`, `confirm`, `allowedit`) — both use `App\Enums\Traits\EnumComboBoxArrayTrait`, so `::comboBoxArray()` returns `[{id: <case name>, name: <Dutch label>}]`.
- Produces: `StandardEmail` (fillable `name`, `subject`, `body`; `SoftDeletes`; `triggers(): HasMany`; `standardAttachments(): BelongsToMany`).
- Produces: `StandardEmailTrigger` (fillable `standard_email_id`, `trigger`, `trigger_type`; `standardEmail(): BelongsTo`). Both `trigger` and `trigger_type` store the enum case *name* (e.g. `'event_created'`, `'background'`) as a plain string column — not a native Eloquent enum cast.
- Produces: `StandardAttachment` (fillable `name`, `path`, `original_filename`, `mime_type`, `size`; `SoftDeletes`; `standardEmails(): BelongsToMany`).

- [ ] **Step 1: Create the four migrations**

`database/migrations/2026_07_08_100001_create_standard_emails_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_emails', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->longText('body');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_emails');
    }
};
```

`database/migrations/2026_07_08_100002_create_standard_email_triggers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_email_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_email_id')->constrained()->cascadeOnDelete();
            $table->string('trigger');
            $table->string('trigger_type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_email_triggers');
    }
};
```

`database/migrations/2026_07_08_100003_create_standard_attachments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_attachments');
    }
};
```

`database/migrations/2026_07_08_100004_create_standard_email_standard_attachment_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_email_standard_attachment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standard_attachment_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_email_standard_attachment');
    }
};
```

- [ ] **Step 2: Create the two enums**

`app/Enums/EventTrigger.php`:

```php
<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum EventTrigger: string
{
    use EnumComboBoxArrayTrait;

    case event_created = 'Afspraak aangemaakt';
    case event_updated = 'Afspraak gewijzigd';
    case event_deleted = 'Afspraak verwijderd';
}
```

`app/Enums/StandardEmailTriggerType.php`:

```php
<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum StandardEmailTriggerType: string
{
    use EnumComboBoxArrayTrait;

    case background = 'Automatisch versturen';
    case confirm = 'Bevestigen voor verzenden';
    case allowedit = 'Bewerken voor verzenden';
}
```

- [ ] **Step 3: Create the three models**

`app/Models/StandardEmail.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandardEmail extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'subject',
        'body',
    ];

    public function triggers(): HasMany
    {
        return $this->hasMany(StandardEmailTrigger::class);
    }

    public function standardAttachments(): BelongsToMany
    {
        return $this->belongsToMany(StandardAttachment::class, 'standard_email_standard_attachment')
            ->withTimestamps();
    }
}
```

`app/Models/StandardEmailTrigger.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandardEmailTrigger extends Model
{
    protected $fillable = [
        'standard_email_id',
        'trigger',
        'trigger_type',
    ];

    public function standardEmail(): BelongsTo
    {
        return $this->belongsTo(StandardEmail::class);
    }
}
```

`app/Models/StandardAttachment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandardAttachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'path',
        'original_filename',
        'mime_type',
        'size',
    ];

    public function standardEmails(): BelongsToMany
    {
        return $this->belongsToMany(StandardEmail::class, 'standard_email_standard_attachment')
            ->withTimestamps();
    }
}
```

- [ ] **Step 4: Run the migrations**

Run: `php artisan migrate`
Expected: the four new tables appear, no errors.

- [ ] **Step 5: Verify via tinker**

Run:
```bash
php artisan tinker --execute="
\$e = App\Models\StandardEmail::create(['name' => 'Test', 'subject' => 'Hallo', 'body' => '<p>Hi</p>']);
\$e->triggers()->create(['trigger' => 'event_created', 'trigger_type' => 'background']);
\$a = App\Models\StandardAttachment::create(['name' => 'Test bijlage', 'path' => 'x', 'original_filename' => 'x.pdf', 'mime_type' => 'application/pdf', 'size' => 1]);
\$e->standardAttachments()->attach(\$a->id);
echo \$e->fresh(['triggers', 'standardAttachments'])->toJson();
\$e->delete(); \$a->delete();
"
```
Expected: prints JSON with a `triggers` array (1 item, `trigger: event_created`) and a `standard_attachments` array (1 item), no errors.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_08_100001_create_standard_emails_table.php \
    database/migrations/2026_07_08_100002_create_standard_email_triggers_table.php \
    database/migrations/2026_07_08_100003_create_standard_attachments_table.php \
    database/migrations/2026_07_08_100004_create_standard_email_standard_attachment_table.php \
    app/Enums/EventTrigger.php app/Enums/StandardEmailTriggerType.php \
    app/Models/StandardEmail.php app/Models/StandardEmailTrigger.php app/Models/StandardAttachment.php
git commit -m "feat: add standard email, trigger, and attachment models"
```

---

### Task 2: Permissions and policies

**Files:**
- Create: `database/migrations/2026_07_08_100005_seed_standard_email_permissions.php`
- Create: `app/Policies/StandardEmailPolicy.php`
- Create: `app/Policies/StandardAttachmentPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: `StandardEmail`, `StandardAttachment` models from Task 1.
- Produces: `StandardEmailPolicy::manage(User $user): bool` and `StandardAttachmentPolicy::manage(User $user): bool` — no model instance parameter, matching the existing `CalendarGrantPolicy::manage()` pattern (`app/Policies/CalendarGrantPolicy.php`), since there's no per-record ownership distinction for these settings resources. Both registered via `Gate::policy(...)` in `AppServiceProvider::boot()`.

- [ ] **Step 1: Create the permission-seeding migration**

Mirror the existing pattern from `database/migrations/2026_07_01_000004_seed_events_provide_feedback_permission.php`:

```php
<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'standardemail.manage', 'label' => 'Mag standaard e-mails beheren'],
        ['name' => 'standardattachment.manage', 'label' => 'Mag standaard bijlagen beheren'],
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

- [ ] **Step 2: Create the two policies**

`app/Policies/StandardEmailPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;

class StandardEmailPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('standardemail.manage');
    }
}
```

`app/Policies/StandardAttachmentPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;

class StandardAttachmentPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('standardattachment.manage');
    }
}
```

- [ ] **Step 3: Register the policies**

In `app/Providers/AppServiceProvider.php`, add imports next to the existing ones:

```php
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Policies\StandardAttachmentPolicy;
use App\Policies\StandardEmailPolicy;
```

And inside `boot()`, right after the existing `Gate::policy(...)` lines:

```php
        Gate::policy(EventModel::class, EventPolicy::class);
        Gate::policy(CalendarGrant::class, CalendarGrantPolicy::class);
        Gate::policy(UserUnavailability::class, UserUnavailabilityPolicy::class);
        Gate::policy(StandardEmail::class, StandardEmailPolicy::class);
        Gate::policy(StandardAttachment::class, StandardAttachmentPolicy::class);
```

- [ ] **Step 4: Run the migration**

Run: `php artisan migrate`
Expected: `standardemail.manage` and `standardattachment.manage` rows exist.

- [ ] **Step 5: Verify via tinker**

```bash
php artisan tinker --execute="
\$u = App\Models\User::where('is_admin', true)->first() ?? App\Models\User::first();
var_dump(\$u->can('manage', App\Models\StandardEmail::class));
var_dump(\$u->can('manage', App\Models\StandardAttachment::class));
"
```
Expected: both print `bool(true)` for an admin user.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_08_100005_seed_standard_email_permissions.php \
    app/Policies/StandardEmailPolicy.php app/Policies/StandardAttachmentPolicy.php \
    app/Providers/AppServiceProvider.php
git commit -m "feat: add standard email and attachment permissions and policies"
```

---

### Task 3: Event gains HasActivities, and the placeholder renderer service

**Files:**
- Modify: `app/Models/Event.php`
- Create: `app/Services/StandardEmailRenderer.php`

**Interfaces:**
- Consumes: `App\Models\Traits\HasActivities` (already exists, used by `ServiceOrder`/`Ticket`).
- Produces: `Event::logActivity(...)` (inherited from the trait). `StandardEmailRenderer::placeholders(): array<{token: string, label: string}>`, `StandardEmailRenderer::render(string $html, Event $event): string`, `StandardEmailRenderer::defaultRecipient(Event $event): ?string`. Callers must eager-load `serviceOrders.customer` and `customers` on the `Event` before calling `render()`/`defaultRecipient()`.

- [ ] **Step 1: Add HasActivities to Event**

In `app/Models/Event.php`, add the import next to the other trait imports:

```php
use App\Models\Traits\HasActivities;
```

And add `use HasActivities;` to the trait list, keeping alphabetical order with the existing ones:

```php
class Event extends Model
{
    use HasActivities;
    use HasExecutingUsers;
    use HasFactory;
    use HasOwner;
    use RemarkableTrait;
    use SoftDeletes;
```

- [ ] **Step 2: Create the renderer service**

`app/Services/StandardEmailRenderer.php`:

```php
<?php

namespace App\Services;

use App\Models\Event;

class StandardEmailRenderer
{
    public static function placeholders(): array
    {
        return [
            ['token' => '{{event_start_date}}', 'label' => 'Startdatum'],
            ['token' => '{{event_start_time}}', 'label' => 'Starttijd'],
            ['token' => '{{event_end_date}}', 'label' => 'Einddatum'],
            ['token' => '{{event_end_time}}', 'label' => 'Eindtijd'],
            ['token' => '{{event_name}}', 'label' => 'Naam afspraak'],
            ['token' => '{{event_location}}', 'label' => 'Locatie'],
            ['token' => '{{customer_name}}', 'label' => 'Klantnaam'],
        ];
    }

    public static function render(string $html, Event $event): string
    {
        $customer = $event->serviceOrders->first()?->customer ?? $event->customers->first();

        $replacements = [
            '{{event_start_date}}' => $event->start?->format('d-m-Y') ?? '',
            '{{event_start_time}}' => $event->start?->format('H:i') ?? '',
            '{{event_end_date}}' => $event->end?->format('d-m-Y') ?? '',
            '{{event_end_time}}' => $event->end?->format('H:i') ?? '',
            '{{event_name}}' => $event->name ?? '',
            '{{event_location}}' => $event->location ?? '',
            '{{customer_name}}' => $customer?->name ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    public static function defaultRecipient(Event $event): ?string
    {
        $customer = $event->serviceOrders->first()?->customer ?? $event->customers->first();

        return $customer?->email;
    }
}
```

- [ ] **Step 3: Verify via tinker**

```bash
php artisan tinker --execute="
\$event = App\Models\Event::with(['serviceOrders.customer', 'customers'])->first();
\$html = App\Services\StandardEmailRenderer::render('Afspraak: {{event_name}} op {{event_start_date}} om {{event_start_time}} voor {{customer_name}}', \$event);
echo \$html . PHP_EOL;
echo App\Services\StandardEmailRenderer::defaultRecipient(\$event) . PHP_EOL;
\$event->logActivity('Test activiteit voor standaard e-mail');
echo \$event->activities()->latest()->first()->description . PHP_EOL;
"
```
Expected: prints the rendered string with real values substituted (no literal `{{...}}` left), an email or empty string, then the logged activity's description.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Event.php app/Services/StandardEmailRenderer.php
git commit -m "feat: add HasActivities to Event and standard email placeholder renderer"
```

---

### Task 4: Trigger resolver service

**Files:**
- Create: `app/Services/StandardEmailTriggerResolver.php`

**Interfaces:**
- Consumes: `App\Models\StandardEmailTrigger`, `App\Enums\EventTrigger` (Task 1).
- Produces: `StandardEmailTriggerResolver::matching(Event $event, EventTrigger $trigger, array $triggerTypes): \Illuminate\Support\Collection` — returns `StandardEmailTrigger` rows (with `standardEmail` eager-loaded) whose `trigger` matches and whose `trigger_type` is in `$triggerTypes` (an array of enum case-name strings), excluding rows whose `StandardEmail` was soft-deleted.

- [ ] **Step 1: Create the service**

```php
<?php

namespace App\Services;

use App\Enums\EventTrigger;
use App\Models\Event;
use App\Models\StandardEmailTrigger;
use Illuminate\Support\Collection;

class StandardEmailTriggerResolver
{
    /**
     * @param  string[]  $triggerTypes
     * @return Collection<int, StandardEmailTrigger>
     */
    public static function matching(Event $event, EventTrigger $trigger, array $triggerTypes): Collection
    {
        return StandardEmailTrigger::query()
            ->where('trigger', $trigger->name)
            ->whereIn('trigger_type', $triggerTypes)
            ->whereHas('standardEmail')
            ->with('standardEmail')
            ->get();
    }
}
```

- [ ] **Step 2: Verify via tinker**

```bash
php artisan tinker --execute="
\$e = App\Models\StandardEmail::create(['name' => 'Test', 'subject' => 'Onderwerp', 'body' => 'Body']);
\$e->triggers()->create(['trigger' => 'event_created', 'trigger_type' => 'background']);
\$e->triggers()->create(['trigger' => 'event_created', 'trigger_type' => 'confirm']);
\$event = App\Models\Event::first();
\$bg = App\Services\StandardEmailTriggerResolver::matching(\$event, App\Enums\EventTrigger::event_created, ['background']);
\$confirmOrEdit = App\Services\StandardEmailTriggerResolver::matching(\$event, App\Enums\EventTrigger::event_created, ['confirm', 'allowedit']);
echo 'background: ' . \$bg->count() . PHP_EOL;
echo 'confirm/allowedit: ' . \$confirmOrEdit->count() . PHP_EOL;
\$e->delete();
"
```
Expected: `background: 1` and `confirm/allowedit: 1`.

- [ ] **Step 3: Commit**

```bash
git add app/Services/StandardEmailTriggerResolver.php
git commit -m "feat: add standard email trigger resolver service"
```

---

### Task 5: Mailable, blade view, and the send job

**Files:**
- Create: `app/Mail/StandardEmailMail.php`
- Create: `resources/views/emails/standard_email.blade.php`
- Create: `app/Jobs/SendStandardEmailJob.php`

**Interfaces:**
- Consumes: `StandardEmailRenderer` (Task 3), `Event`, `StandardEmail` models.
- Produces: `new StandardEmailMail(string $renderedSubject, string $renderedBody, \Illuminate\Support\Collection $standardAttachments)`. `SendStandardEmailJob::dispatch(int $eventId, int $standardEmailId, ?string $trigger = null)`.
- Note: sent-mail copying to the mailbox's "Sent" folder is already handled application-wide by the existing `App\Listeners\CopyMailToSentFolder` (for `smtp`) and by `GraphTransport`'s hardcoded `saveToSentItems: true` (for `graph`) — nothing new is needed here for that; just send normally via `Mail::to(...)->send(...)`.

- [ ] **Step 1: Create the Mailable**

`app/Mail/StandardEmailMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class StandardEmailMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $renderedSubject,
        public string $renderedBody,
        public Collection $standardAttachments,
    ) {
    }

    public function build(): self
    {
        $mail = $this->subject($this->renderedSubject)
            ->view('emails.standard_email', ['body' => $this->renderedBody]);

        foreach ($this->standardAttachments as $attachment) {
            $mail->attach(Storage::disk('public')->path($attachment->path), [
                'as' => $attachment->original_filename,
                'mime' => $attachment->mime_type,
            ]);
        }

        return $mail;
    }
}
```

- [ ] **Step 2: Create the blade view**

`resources/views/emails/standard_email.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body>
    {!! $body !!}
</body>
</html>
```

- [ ] **Step 3: Create the job**

`app/Jobs/SendStandardEmailJob.php`:

```php
<?php

namespace App\Jobs;

use App\Mail\StandardEmailMail;
use App\Models\Event;
use App\Models\StandardEmail;
use App\Services\StandardEmailRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendStandardEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $eventId,
        public int $standardEmailId,
        public ?string $trigger = null,
    ) {
    }

    public function handle(): void
    {
        $event = Event::with(['serviceOrders.customer', 'customers'])->find($this->eventId);
        $standardEmail = StandardEmail::with('standardAttachments')->find($this->standardEmailId);

        if (! $event || ! $standardEmail) {
            return;
        }

        $to = StandardEmailRenderer::defaultRecipient($event);

        if (! $to) {
            return;
        }

        $subject = StandardEmailRenderer::render($standardEmail->subject, $event);
        $body = StandardEmailRenderer::render($standardEmail->body, $event);

        Mail::to($to)->send(new StandardEmailMail($subject, $body, $standardEmail->standardAttachments));

        $event->logActivity(
            "Standaard e-mail '" . $standardEmail->name . "' verzonden aan " . $to,
            metadata: [
                'standard_email_id' => $standardEmail->id,
                'trigger' => $this->trigger,
                'to' => $to,
                'subject' => $subject,
            ],
        );
    }
}
```

- [ ] **Step 4: Verify via tinker (synchronous dispatch)**

Set `MAIL_MAILER=log` in `.env` if not already, so the send doesn't require real credentials, then:

```bash
php artisan tinker --execute="
\$e = App\Models\StandardEmail::create(['name' => 'Test verzenden', 'subject' => 'Afspraak {{event_name}}', 'body' => '<p>Start: {{event_start_date}} {{event_start_time}}</p>']);
\$event = App\Models\Event::with(['serviceOrders.customer', 'customers'])->first();
App\Jobs\SendStandardEmailJob::dispatchSync(\$event->id, \$e->id, 'event_created');
echo \$event->activities()->latest()->first()?->description . PHP_EOL;
\$e->delete();
"
```
Expected: prints an activity description like `Standaard e-mail 'Test verzenden' verzonden aan ...@...` (or nothing logged if the test event's customer has no e-mail — check `storage/logs/laravel.log` for the rendered mail in that case, since `MAIL_MAILER=log` writes there).

- [ ] **Step 5: Commit**

```bash
git add app/Mail/StandardEmailMail.php resources/views/emails/standard_email.blade.php app/Jobs/SendStandardEmailJob.php
git commit -m "feat: add standard email mailable and send job"
```

---

### Task 6: Wire background triggers into EventObserver

**Files:**
- Modify: `app/Observers/EventObserver.php`

**Interfaces:**
- Consumes: `StandardEmailTriggerResolver::matching()` (Task 4), `SendStandardEmailJob::dispatch()` (Task 5), `EventTrigger`, `StandardEmailTriggerType` enums (Task 1).

- [ ] **Step 1: Update the observer**

Replace the full contents of `app/Observers/EventObserver.php` with:

```php
<?php

namespace App\Observers;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Jobs\SendStandardEmailJob;
use App\Models\Event;
use App\Models\GoogleSyncedEvent;
use App\Models\StandardEmailTrigger;
use App\Services\StandardEmailTriggerResolver;

class EventObserver
{
    public function created(Event $event): void
    {
        PushEventJob::dispatch($event->id);
        $this->dispatchBackgroundStandardEmails($event, EventTrigger::event_created);
    }

    public function updated(Event $event): void
    {
        PushEventJob::dispatch($event->id);
        $this->dispatchBackgroundStandardEmails($event, EventTrigger::event_updated);
    }

    public function deleting(Event $event): void
    {
        if (!$event->isForceDeleting()) {
            return;
        }
        $mappings = GoogleSyncedEvent::where('event_id', $event->id)->get();
        foreach ($mappings as $mapping) {
            DeleteEventFromGoogleJob::dispatch(
                $mapping->id,
                $mapping->google_synced_calendar_id,
                $mapping->google_event_id,
            );
        }
    }

    public function deleted(Event $event): void
    {
        if ($event->isForceDeleting()) {
            return;
        }
        $mappings = GoogleSyncedEvent::where('event_id', $event->id)->get();
        foreach ($mappings as $mapping) {
            DeleteEventFromGoogleJob::dispatch(
                $mapping->id,
                $mapping->google_synced_calendar_id,
                $mapping->google_event_id,
            );
        }
        foreach ($event->serviceOrders as $service_order) {
            $service_order->revertToPlanningCancelledStage();
        }
        $this->dispatchBackgroundStandardEmails($event, EventTrigger::event_deleted);
    }

    public function restored(Event $event): void
    {
        PushEventJob::dispatch($event->id);
    }

    private function dispatchBackgroundStandardEmails(Event $event, EventTrigger $trigger): void
    {
        StandardEmailTriggerResolver::matching($event, $trigger, [StandardEmailTriggerType::background->name])
            ->each(function (StandardEmailTrigger $match) use ($event, $trigger) {
                SendStandardEmailJob::dispatch($event->id, $match->standard_email_id, $trigger->name);
            });
    }
}
```

- [ ] **Step 2: Verify via tinker**

Set queue driver to `sync` for this check if it's not already (or run `php artisan queue:work --once` after), then:

```bash
php artisan tinker --execute="
\$e = App\Models\StandardEmail::create(['name' => 'Background test', 'subject' => 'Hoi', 'body' => 'Body']);
\$e->triggers()->create(['trigger' => 'event_created', 'trigger_type' => 'background']);
\$et = App\Models\EventType::first();
\$event = App\Models\Event::create(['event_type_id' => \$et->id, 'status' => 'Gepland', 'start' => now(), 'end' => now()->addHour()]);
sleep(1);
echo \$event->fresh()->activities()->count() . PHP_EOL;
\$event->forceDelete();
\$e->delete();
"
```
Expected: if `QUEUE_CONNECTION=sync` in `.env`, the activity count is `1` right after creation (job ran inline). If it's `database`/another async driver, run `php artisan queue:work --once` in a second terminal first, then check `App\Models\Event::find($id)->activities()->count()` — either way, confirm exactly one activity was logged for the `event_created` background trigger.

- [ ] **Step 3: Commit**

```bash
git add app/Observers/EventObserver.php
git commit -m "feat: dispatch background standard emails from EventObserver"
```

---

### Task 7: Admin StandardEmail CRUD (backend)

**Files:**
- Create: `app/Http/Requests/StandardEmailStoreRequest.php`
- Create: `app/Http/Requests/StandardEmailUpdateRequest.php`
- Create: `app/Http/Requests/StandardEmailDestroyRequest.php`
- Create: `app/Http/Controllers/Admin/StandardEmailController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `StandardEmail`, `StandardAttachment` models, `EventTrigger`/`StandardEmailTriggerType` enums, `StandardEmailRenderer::placeholders()`.
- Produces: routes `standard-emails.index/store/update/destroy`, Inertia page `Admin/StandardEmails/IndexPage` with props `standardEmails`, `standardAttachments`, `eventTriggers`, `triggerTypes`, `placeholders`.

- [ ] **Step 1: Create the Form Requests**

`app/Http/Requests/StandardEmailStoreRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Models\StandardEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StandardEmailStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardEmail::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'triggers' => 'nullable|array',
            'triggers.*.trigger' => [
                'required_with:triggers', 'string', Rule::in(array_column(EventTrigger::cases(), 'name')),
            ],
            'triggers.*.trigger_type' => [
                'required_with:triggers', 'string', Rule::in(array_column(StandardEmailTriggerType::cases(), 'name')),
            ],
            'standard_attachment_ids' => 'nullable|array',
            'standard_attachment_ids.*' => 'integer|exists:standard_attachments,id',
        ];
    }
}
```

`app/Http/Requests/StandardEmailUpdateRequest.php` (identical to the store request, only the class name differs):

```php
<?php

namespace App\Http\Requests;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Models\StandardEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StandardEmailUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardEmail::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'triggers' => 'nullable|array',
            'triggers.*.trigger' => [
                'required_with:triggers', 'string', Rule::in(array_column(EventTrigger::cases(), 'name')),
            ],
            'triggers.*.trigger_type' => [
                'required_with:triggers', 'string', Rule::in(array_column(StandardEmailTriggerType::cases(), 'name')),
            ],
            'standard_attachment_ids' => 'nullable|array',
            'standard_attachment_ids.*' => 'integer|exists:standard_attachments,id',
        ];
    }
}
```

`app/Http/Requests/StandardEmailDestroyRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\StandardEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StandardEmailDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardEmail::class);
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Create the controller**

`app/Http/Controllers/Admin/StandardEmailController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StandardEmailDestroyRequest;
use App\Http\Requests\StandardEmailStoreRequest;
use App\Http\Requests\StandardEmailUpdateRequest;
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Services\StandardEmailRenderer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StandardEmailController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/StandardEmails/IndexPage', [
            'standardEmails' => StandardEmail::with('triggers', 'standardAttachments')->orderBy('name')->get(),
            'standardAttachments' => StandardAttachment::orderBy('name')->get(['id', 'name']),
            'eventTriggers' => EventTrigger::comboBoxArray(),
            'triggerTypes' => StandardEmailTriggerType::comboBoxArray(),
            'placeholders' => StandardEmailRenderer::placeholders(),
        ]);
    }

    public function store(StandardEmailStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $triggers = $data['triggers'] ?? [];
        $attachment_ids = $data['standard_attachment_ids'] ?? [];
        unset($data['triggers'], $data['standard_attachment_ids']);

        $standard_email = StandardEmail::create($data);
        $standard_email->triggers()->createMany($triggers);
        $standard_email->standardAttachments()->sync($attachment_ids);

        return redirect()->back()->with('success', 'Standaard e-mail aangemaakt.');
    }

    public function update(StandardEmailUpdateRequest $request, StandardEmail $standard_email): RedirectResponse
    {
        $data = $request->validated();
        $triggers = $data['triggers'] ?? [];
        $attachment_ids = $data['standard_attachment_ids'] ?? [];
        unset($data['triggers'], $data['standard_attachment_ids']);

        $standard_email->update($data);
        $standard_email->triggers()->delete();
        $standard_email->triggers()->createMany($triggers);
        $standard_email->standardAttachments()->sync($attachment_ids);

        return redirect()->back()->with('success', 'Standaard e-mail bijgewerkt.');
    }

    public function destroy(StandardEmailDestroyRequest $request, StandardEmail $standard_email): RedirectResponse
    {
        $standard_email->delete();

        return redirect()->back()->with('success', 'Standaard e-mail verwijderd.');
    }
}
```

- [ ] **Step 3: Register routes**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\Admin\StandardEmailController;
```

Then, inside the existing `Route::middleware('admin')->group(function () { ... })` block (`routes/web.php`, right after the `admin/settings/serviceorder-min-images` route and before the closing `});`), add:

```php
            Route::resource('standard-emails', StandardEmailController::class)
                ->except(['show', 'create', 'edit']);
```

- [ ] **Step 4: Verify with tinker + route list**

Run: `php artisan route:list --name=standard-emails`
Expected: shows `GET|HEAD standard-emails`, `POST standard-emails`, `PUT|PATCH standard-emails/{standard_email}`, `DELETE standard-emails/{standard_email}`, all under the `admin` middleware.

Then, as an admin user in the browser (`php artisan serve` / `composer run dev` running), visit `/standard-emails` — it will currently 500 because the Inertia page component doesn't exist yet (created in Task 12); confirm instead via tinker that the controller logic is sound:

```bash
php artisan tinker --execute="
\$data = ['name' => 'Bevestiging', 'subject' => 'Uw afspraak', 'body' => '<p>Hallo {{customer_name}}</p>', 'triggers' => [['trigger' => 'event_created', 'trigger_type' => 'confirm']], 'standard_attachment_ids' => []];
\$e = App\Models\StandardEmail::create(collect(\$data)->except(['triggers', 'standard_attachment_ids'])->all());
\$e->triggers()->createMany(\$data['triggers']);
echo \$e->fresh('triggers')->toJson();
\$e->delete();
"
```
Expected: JSON showing the created record with one trigger.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/StandardEmailStoreRequest.php app/Http/Requests/StandardEmailUpdateRequest.php \
    app/Http/Requests/StandardEmailDestroyRequest.php app/Http/Controllers/Admin/StandardEmailController.php routes/web.php
git commit -m "feat: add standard email CRUD backend"
```

---

### Task 8: Admin StandardAttachment CRUD (backend)

**Files:**
- Create: `app/Http/Requests/StandardAttachmentStoreRequest.php`
- Create: `app/Http/Requests/StandardAttachmentUpdateRequest.php`
- Create: `app/Http/Requests/StandardAttachmentDestroyRequest.php`
- Create: `app/Http/Controllers/Admin/StandardAttachmentController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `StandardAttachment` model.
- Produces: routes `standard-attachments.index/store/update/destroy`, Inertia page `Admin/StandardAttachments/IndexPage` with prop `standardAttachments`.

- [ ] **Step 1: Create the Form Requests**

`app/Http/Requests/StandardAttachmentStoreRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\StandardAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StandardAttachmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardAttachment::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'file' => 'required|file|max:10240',
        ];
    }
}
```

`app/Http/Requests/StandardAttachmentUpdateRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\StandardAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StandardAttachmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardAttachment::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }
}
```

`app/Http/Requests/StandardAttachmentDestroyRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\StandardAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StandardAttachmentDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardAttachment::class);
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Create the controller**

`app/Http/Controllers/Admin/StandardAttachmentController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StandardAttachmentDestroyRequest;
use App\Http\Requests\StandardAttachmentStoreRequest;
use App\Http\Requests\StandardAttachmentUpdateRequest;
use App\Models\StandardAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class StandardAttachmentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/StandardAttachments/IndexPage', [
            'standardAttachments' => StandardAttachment::orderBy('name')->get(),
        ]);
    }

    public function store(StandardAttachmentStoreRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('uploaded/standardattachments', 'public');

        StandardAttachment::create([
            'name' => $request->input('name'),
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        return redirect()->back()->with('success', 'Standaard bijlage geüpload.');
    }

    public function update(StandardAttachmentUpdateRequest $request, StandardAttachment $standard_attachment): RedirectResponse
    {
        $standard_attachment->update($request->validated());

        return redirect()->back()->with('success', 'Standaard bijlage bijgewerkt.');
    }

    public function destroy(StandardAttachmentDestroyRequest $request, StandardAttachment $standard_attachment): RedirectResponse
    {
        Storage::disk('public')->delete($standard_attachment->path);
        $standard_attachment->delete();

        return redirect()->back()->with('success', 'Standaard bijlage verwijderd.');
    }
}
```

- [ ] **Step 3: Register routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\Admin\StandardAttachmentController;
```

And inside the same `admin` middleware group, right after the `standard-emails` resource route added in Task 7:

```php
            Route::resource('standard-attachments', StandardAttachmentController::class)
                ->except(['show', 'create', 'edit']);
```

- [ ] **Step 4: Verify**

Run: `php artisan route:list --name=standard-attachments`
Expected: same shape as Task 7's route list, for `standard-attachments`.

```bash
php artisan tinker --execute="
Illuminate\Support\Facades\Storage::disk('public')->put('uploaded/standardattachments/test.txt', 'hi');
\$a = App\Models\StandardAttachment::create(['name' => 'Test', 'path' => 'uploaded/standardattachments/test.txt', 'original_filename' => 'test.txt', 'mime_type' => 'text/plain', 'size' => 2]);
echo \$a->toJson();
Illuminate\Support\Facades\Storage::disk('public')->delete(\$a->path);
\$a->delete();
"
```
Expected: prints the created record's JSON, no errors.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/StandardAttachmentStoreRequest.php app/Http/Requests/StandardAttachmentUpdateRequest.php \
    app/Http/Requests/StandardAttachmentDestroyRequest.php app/Http/Controllers/Admin/StandardAttachmentController.php routes/web.php
git commit -m "feat: add standard attachment CRUD backend"
```

---

### Task 9: Event standard-email API (list, preview, send, history)

**Files:**
- Create: `app/Http/Requests/EventStandardEmailReadRequest.php`
- Create: `app/Http/Requests/EventSendStandardEmailRequest.php`
- Create: `app/Http/Controllers/EventStandardEmailController.php`
- Modify: `app/Http/Controllers/EventApiController.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `StandardEmailRenderer`, `StandardEmailTriggerResolver`, `StandardEmailMail`, `EventTrigger`/`StandardEmailTriggerType` enums, `Event::logActivity()`.
- Produces: `GET /api/events/{event}/standard-emails`, `GET /api/events/{event}/standard-emails/{standard_email}/preview`, `POST /api/events/{event}/standard-emails/send`, `GET /api/events/{event}/email-history`. `EventApiController::store/update/destroy` JSON responses gain a `pending_standard_emails: [{standard_email_id, name, trigger, trigger_type}]` key.

- [ ] **Step 1: Create the Form Requests**

`app/Http/Requests/EventStandardEmailReadRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventStandardEmailReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        return [];
    }
}
```

`app/Http/Requests/EventSendStandardEmailRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\EventTrigger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventSendStandardEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'standard_email_id' => 'required|integer|exists:standard_emails,id',
            'to' => 'required|email',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'trigger' => ['nullable', 'string', Rule::in(array_column(EventTrigger::cases(), 'name'))],
        ];
    }
}
```

- [ ] **Step 2: Create the controller**

`app/Http/Controllers/EventStandardEmailController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventSendStandardEmailRequest;
use App\Http\Requests\EventStandardEmailReadRequest;
use App\Mail\StandardEmailMail;
use App\Models\Event;
use App\Models\StandardEmail;
use App\Services\StandardEmailRenderer;
use Illuminate\Support\Facades\Mail;

class EventStandardEmailController extends Controller
{
    public function index(EventStandardEmailReadRequest $request, Event $event)
    {
        return response()->json(StandardEmail::orderBy('name')->get(['id', 'name', 'subject']));
    }

    public function preview(EventStandardEmailReadRequest $request, Event $event, StandardEmail $standard_email)
    {
        $event->load(['serviceOrders.customer', 'customers']);

        return response()->json([
            'standard_email_id' => $standard_email->id,
            'to' => StandardEmailRenderer::defaultRecipient($event),
            'subject' => StandardEmailRenderer::render($standard_email->subject, $event),
            'body' => StandardEmailRenderer::render($standard_email->body, $event),
        ]);
    }

    public function send(EventSendStandardEmailRequest $request, Event $event)
    {
        $data = $request->validated();
        $standard_email = StandardEmail::with('standardAttachments')->findOrFail($data['standard_email_id']);

        Mail::to($data['to'])->send(
            new StandardEmailMail($data['subject'], $data['body'], $standard_email->standardAttachments)
        );

        $event->logActivity(
            "Standaard e-mail '" . $standard_email->name . "' verzonden aan " . $data['to'],
            metadata: [
                'standard_email_id' => $standard_email->id,
                'trigger' => $data['trigger'] ?? null,
                'to' => $data['to'],
                'subject' => $data['subject'],
            ],
        );

        return response()->json(['message' => 'E-mail verzonden aan ' . $data['to']]);
    }

    public function history(EventStandardEmailReadRequest $request, Event $event)
    {
        $activities = $event->activities()
            ->where('category', 'email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($activities->map(fn ($activity) => [
            'id' => $activity->id,
            'description' => $activity->description,
            'created_at' => $activity->created_at,
            'standard_email_id' => $activity->metadata['standard_email_id'] ?? null,
            'to' => $activity->metadata['to'] ?? null,
        ]));
    }
}
```

- [ ] **Step 3: Wire pending_standard_emails into EventApiController**

In `app/Http/Controllers/EventApiController.php`, add these imports near the top, alongside the existing ones:

```php
use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Services\StandardEmailTriggerResolver;
```

Add this private method to the class (e.g. right before `private function withUserRoles`):

```php
    private function pendingStandardEmails(Event $event, EventTrigger $trigger): array
    {
        return StandardEmailTriggerResolver::matching(
            $event,
            $trigger,
            [StandardEmailTriggerType::confirm->name, StandardEmailTriggerType::allowedit->name]
        )->map(fn ($match) => [
            'standard_email_id' => $match->standard_email_id,
            'name' => $match->standardEmail->name,
            'trigger' => $trigger->name,
            'trigger_type' => $match->trigger_type,
        ])->values()->all();
    }
```

In `store()`, replace:

```php
        $event->load([
            'eventType', 'serviceOrders.customer', 'serviceOrders.project:id,title,location',
            'executingUsers', 'executions', 'customers',
        ]);

        return response()->json($this->withUserRoles($event), 201);
```

with:

```php
        $event->load([
            'eventType', 'serviceOrders.customer', 'serviceOrders.project:id,title,location',
            'executingUsers', 'executions', 'customers',
        ]);

        return response()->json(array_merge(
            $this->withUserRoles($event)->toArray(),
            ['pending_standard_emails' => $this->pendingStandardEmails($event, EventTrigger::event_created)]
        ), 201);
```

In `update()`, replace the final block:

```php
        $event->load([
            'eventType', 'serviceOrders.customer', 'serviceOrders.project:id,title,location',
            'executingUsers', 'executions', 'customers',
        ]);

        return response()->json($this->withUserRoles($event));
```

with:

```php
        $event->load([
            'eventType', 'serviceOrders.customer', 'serviceOrders.project:id,title,location',
            'executingUsers', 'executions', 'customers',
        ]);

        return response()->json(array_merge(
            $this->withUserRoles($event)->toArray(),
            ['pending_standard_emails' => $this->pendingStandardEmails($event, EventTrigger::event_updated)]
        ));
```

Replace `destroy()` entirely:

```php
    public function destroy(EventDestroyRequest $request, Event $event)
    {
        $event->load(['serviceOrders.customer', 'customers']);
        $pending = $this->pendingStandardEmails($event, EventTrigger::event_deleted);

        $event->delete();

        return response()->json(['pending_standard_emails' => $pending]);
    }
```

- [ ] **Step 4: Register routes**

In `routes/api.php`, add inside the `auth:sanctum` group, right after the existing `events/{event}/feedback` line:

```php
    Route::get('events/{event}/standard-emails', [EventStandardEmailController::class, 'index']);
    Route::get('events/{event}/standard-emails/{standard_email}/preview', [EventStandardEmailController::class, 'preview']);
    Route::post('events/{event}/standard-emails/send', [EventStandardEmailController::class, 'send']);
    Route::get('events/{event}/email-history', [EventStandardEmailController::class, 'history']);
```

And add the import at the top of the file:

```php
use App\Http\Controllers\EventStandardEmailController;
```

- [ ] **Step 5: Verify**

Run: `php artisan route:list --path=events` and confirm the four new routes appear.

Then, with the dev server running and logged in as a user who can update events, use the browser devtools console (or `curl` with a valid session cookie) to hit:
- `GET /api/events/{id}/standard-emails` → expect a JSON array (empty or with your test template).
- Create a `StandardEmail` via tinker first, then `GET /api/events/{id}/standard-emails/{standard_email_id}/preview` → expect `{standard_email_id, to, subject, body}` with placeholders substituted.
- `POST /api/events/{id}/standard-emails/send` with `{standard_email_id, to, subject, body}` → expect `{"message": "E-mail verzonden aan ..."}`, and `GET /api/events/{id}/email-history` afterward shows the new entry.
- Also confirm via the existing planner UI (create/update an event) that the response still works end-to-end — no 500s — since `store`/`update` responses changed shape.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/EventStandardEmailReadRequest.php app/Http/Requests/EventSendStandardEmailRequest.php \
    app/Http/Controllers/EventStandardEmailController.php app/Http/Controllers/EventApiController.php routes/api.php
git commit -m "feat: add event standard email send/preview/history API"
```

---

### Task 10: TipTap dependency and the TipTapEditor component

**Files:**
- Modify: `package.json` (via `npm install`)
- Create: `resources/js/Components/UI/TipTapEditor.vue`

**Interfaces:**
- Produces: `TipTapEditor.vue` — props `modelValue: String`, `placeholders: Array|null` (`[{token, label}]`; when provided, `{{` triggers a click-to-insert placeholder popup; when omitted/empty, plain rich text only), `hasError: Boolean`; emits `update:modelValue` with the editor's HTML. The rendered token text is the literal placeholder string (e.g. `{{event_start_date}}`), so backend `str_replace` substitution keeps working against the saved HTML.

- [ ] **Step 1: Install the TipTap packages**

Run: `npm install @tiptap/vue-3 @tiptap/pm @tiptap/starter-kit @tiptap/extension-mention tippy.js`
Expected: `package.json` and `package-lock.json` gain these five entries under `dependencies`, install succeeds with no errors.

- [ ] **Step 2: Create the component**

`resources/js/Components/UI/TipTapEditor.vue`:

```vue
<template>
    <div class="rounded-lg border overflow-hidden dark:border-slate-600" :class="hasError ? 'ring-1 ring-red-400' : ''">
        <div class="flex items-center gap-1 border-b border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-800 px-2 py-1.5">
            <button type="button" @mousedown.prevent="editor?.chain().focus().toggleBold().run()"
                :class="toolbarBtnClass(editor?.isActive('bold'))">
                <BoldIcon class="h-4 w-4" />
            </button>
            <button type="button" @mousedown.prevent="editor?.chain().focus().toggleItalic().run()"
                :class="toolbarBtnClass(editor?.isActive('italic'))">
                <ItalicIcon class="h-4 w-4" />
            </button>
            <button type="button" @mousedown.prevent="editor?.chain().focus().toggleBulletList().run()"
                :class="toolbarBtnClass(editor?.isActive('bulletList'))">
                <ListBulletIcon class="h-4 w-4" />
            </button>
            <select v-if="placeholders?.length" @change="onPlaceholderPicked"
                class="ml-auto text-xs rounded border-gray-300 dark:bg-slate-900 dark:border-slate-600 dark:text-white focus:ring-lavoro-blue focus:border-lavoro-blue">
                <option value="">Plaatshouder invoegen...</option>
                <option v-for="p in placeholders" :key="p.token" :value="p.token">{{ p.label }}</option>
            </select>
        </div>
        <EditorContent :editor="editor"
            class="prose prose-sm max-w-none dark:prose-invert px-3 py-2 min-h-[10rem] focus:outline-none [&_.ProseMirror]:focus:outline-none" />
    </div>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue'
import { Editor, EditorContent } from '@tiptap/vue-3'
import { useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Mention from '@tiptap/extension-mention'
import tippy from 'tippy.js'
import { BoldIcon, ItalicIcon, ListBulletIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholders: { type: Array, default: null },
    hasError: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

function buildMentionExtension() {
    return Mention.extend({
        name: 'mention',
        renderHTML({ node, HTMLAttributes }) {
            return ['span', { ...HTMLAttributes, class: 'standard-email-placeholder', contenteditable: 'false' }, node.attrs.id]
        },
        renderText({ node }) {
            return node.attrs.id
        },
    }).configure({
        suggestion: {
            char: '{{',
            items: ({ query }) => (props.placeholders || [])
                .filter((p) => p.label.toLowerCase().includes(query.toLowerCase()))
                .slice(0, 10),
            render: () => {
                let popup
                let listEl

                function renderItems(items, command) {
                    listEl.innerHTML = ''
                    items.forEach((item) => {
                        const btn = document.createElement('button')
                        btn.type = 'button'
                        btn.textContent = item.label
                        btn.className = 'block w-full text-left px-3 py-1.5 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700'
                        btn.addEventListener('mousedown', (event) => {
                            event.preventDefault()
                            command({ id: item.token, label: item.label })
                        })
                        listEl.appendChild(btn)
                    })
                }

                return {
                    onStart: (suggestionProps) => {
                        listEl = document.createElement('div')
                        listEl.className = 'bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-lg shadow-lg py-1 min-w-[12rem] max-h-60 overflow-y-auto'
                        renderItems(suggestionProps.items, suggestionProps.command)
                        popup = tippy('body', {
                            getReferenceClientRect: suggestionProps.clientRect,
                            appendTo: () => document.body,
                            content: listEl,
                            showOnCreate: true,
                            interactive: true,
                            trigger: 'manual',
                            placement: 'bottom-start',
                        })
                    },
                    onUpdate: (suggestionProps) => {
                        renderItems(suggestionProps.items, suggestionProps.command)
                        popup[0].setProps({ getReferenceClientRect: suggestionProps.clientRect })
                    },
                    onKeyDown: (suggestionProps) => {
                        if (suggestionProps.event.key === 'Escape') {
                            popup[0].hide()
                            return true
                        }
                        return false
                    },
                    onExit: () => {
                        popup[0].destroy()
                    },
                }
            },
            command: ({ editor, range, props: item }) => {
                editor.chain().focus().insertContentAt(range, [
                    { type: 'mention', attrs: { id: item.id, label: item.label } },
                    { type: 'text', text: ' ' },
                ]).run()
            },
        },
    })
}

function buildExtensions() {
    const extensions = [StarterKit]
    if (props.placeholders?.length) {
        extensions.push(buildMentionExtension())
    }
    return extensions
}

const editor = useEditor({
    content: props.modelValue,
    extensions: buildExtensions(),
    onUpdate: ({ editor: currentEditor }) => {
        emit('update:modelValue', currentEditor.getHTML())
    },
})

function onPlaceholderPicked(event) {
    const token = event.target.value
    if (!token) return
    const label = props.placeholders.find((p) => p.token === token)?.label || token
    editor.value?.chain().focus().insertContent([
        { type: 'mention', attrs: { id: token, label } },
        { type: 'text', text: ' ' },
    ]).run()
    event.target.value = ''
}

function toolbarBtnClass(active) {
    return [
        'p-1.5 rounded text-sm',
        active ? 'bg-lavoro-blue text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-700',
    ]
}

watch(() => props.modelValue, (value) => {
    if (editor.value && value !== editor.value.getHTML()) {
        editor.value.commands.setContent(value, false)
    }
})

onBeforeUnmount(() => {
    editor.value?.destroy()
})
</script>
```

- [ ] **Step 3: Verify with a throwaway page**

Temporarily add `<TipTapEditor v-model="testValue" :placeholders="[{token: '{{event_name}}', label: 'Naam afspraak'}]" />` with `const testValue = ref('<p>hallo</p>')` to any existing Vue page you can reach in the browser (e.g. `resources/js/Pages/Admin/GeneralSettingsPage.vue`, temporarily), run `npm run dev`, open that page, confirm: the toolbar renders, Bold/Italic/bullet list work, typing `{{` opens a click list of placeholders, clicking one inserts a chip showing "Naam afspraak" in the editor, and the underlying HTML (temporarily `console.log(testValue.value)` on a button click) contains the literal text `{{event_name}}`. Then revert that temporary edit.

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json resources/js/Components/UI/TipTapEditor.vue
git commit -m "feat: add TipTap rich text editor with placeholder insertion"
```

---

### Task 11: Standard attachments admin page

**Files:**
- Create: `resources/js/Components/StandardAttachmentUploadComponent.vue`
- Create: `resources/js/Pages/Admin/StandardAttachments/IndexPage.vue`

**Interfaces:**
- Consumes: routes from Task 8 (`standard-attachments.store/update/destroy`).
- Produces: a working `/standard-attachments` Inertia page.

- [ ] **Step 1: Create the upload/list component**

`resources/js/Components/StandardAttachmentUploadComponent.vue`:

```vue
<template>
    <div>
        <form @submit.prevent="upload" class="flex flex-col sm:flex-row gap-3 mb-4">
            <input v-model="name" type="text" placeholder="Naam van de bijlage"
                class="flex-1 rounded-md border-gray-300 dark:border-slate-600 dark:bg-slate-900 dark:text-white shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue" />
            <input ref="fileInput" type="file" @change="onFileSelected"
                class="text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 dark:file:bg-slate-700 file:text-sm file:font-medium hover:file:bg-gray-200" />
            <button type="submit" :disabled="!name || !file || uploading"
                class="px-4 py-2 rounded-md bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                {{ uploading ? 'Uploaden...' : 'Uploaden' }}
            </button>
        </form>

        <ul class="divide-y divide-gray-100 dark:divide-slate-700 rounded-lg border border-gray-100 dark:border-slate-700">
            <li v-for="attachment in attachments" :key="attachment.id"
                class="flex items-center justify-between px-4 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ attachment.name }}</p>
                    <p class="text-xs text-gray-400">{{ attachment.original_filename }}</p>
                </div>
                <button type="button" @click="remove(attachment)"
                    class="text-xs text-red-600 hover:underline shrink-0 ml-3">
                    Verwijderen
                </button>
            </li>
            <li v-if="attachments.length === 0" class="px-4 py-3 text-sm text-gray-400">
                Nog geen standaard bijlagen.
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    attachments: { type: Array, required: true },
})

const name = ref('')
const file = ref(null)
const fileInput = ref(null)
const uploading = ref(false)

function onFileSelected(event) {
    file.value = event.target.files[0] || null
}

function upload() {
    if (!name.value || !file.value) return
    uploading.value = true
    const form = new FormData()
    form.append('name', name.value)
    form.append('file', file.value)
    router.post('/standard-attachments', form, {
        preserveScroll: true,
        onFinish: () => {
            uploading.value = false
            name.value = ''
            file.value = null
            if (fileInput.value) fileInput.value.value = ''
        },
    })
}

function remove(attachment) {
    if (!confirm(`Bijlage "${attachment.name}" verwijderen?`)) return
    router.delete(`/standard-attachments/${attachment.id}`, { preserveScroll: true })
}
</script>
```

- [ ] **Step 2: Create the Inertia page**

`resources/js/Pages/Admin/StandardAttachments/IndexPage.vue`:

```vue
<template>
    <div class="p-6 max-w-2xl">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Standaard bijlagen</h1>
        <StandardAttachmentUploadComponent :attachments="standardAttachments" />
    </div>
</template>

<script setup>
import StandardAttachmentUploadComponent from '@/Components/StandardAttachmentUploadComponent.vue'

defineProps({
    standardAttachments: { type: Array, required: true },
})
</script>
```

- [ ] **Step 3: Verify in the browser**

Run `composer run dev` (or the pieces separately), log in as an admin, visit `/standard-attachments`: upload a small file with a name, confirm it appears in the list; delete it, confirm it disappears and `storage/app/public/uploaded/standardattachments/` no longer has the file (check via `ls storage/app/public/uploaded/standardattachments/` before/after).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/StandardAttachmentUploadComponent.vue resources/js/Pages/Admin/StandardAttachments/IndexPage.vue
git commit -m "feat: add standard attachments admin page"
```

---

### Task 12: Standard emails admin page

**Files:**
- Create: `resources/js/Pages/Admin/StandardEmails/IndexPage.vue`

**Interfaces:**
- Consumes: `TipTapEditor.vue` (Task 10), `ComboBox.vue`, `ModalDialog.vue`, `TextInput.vue` (existing), props from `StandardEmailController::index` (Task 7): `standardEmails`, `standardAttachments`, `eventTriggers`, `triggerTypes`, `placeholders`.

- [ ] **Step 1: Create the page**

`resources/js/Pages/Admin/StandardEmails/IndexPage.vue`:

```vue
<template>
    <div class="p-6 max-w-4xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Standaard e-mails</h1>
            <button type="button" @click="openCreate"
                class="px-4 py-2 rounded-md bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700">
                Nieuwe standaard e-mail
            </button>
        </div>

        <ul class="divide-y divide-gray-100 dark:divide-slate-700 rounded-lg border border-gray-100 dark:border-slate-700">
            <li v-for="email in standardEmails" :key="email.id" class="px-4 py-3 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ email.name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ email.subject }}</p>
                    <div class="flex gap-1 mt-1 flex-wrap">
                        <span v-for="trigger in email.triggers" :key="trigger.id"
                            class="inline-flex items-center rounded-full bg-lavoro-green/20 text-gray-800 dark:text-gray-100 px-2 py-0.5 text-[0.65rem] font-medium">
                            {{ triggerLabel(trigger.trigger) }} · {{ triggerTypeLabel(trigger.trigger_type) }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <button type="button" @click="openEdit(email)" class="text-sm text-lavoro-blue hover:underline">
                        Bewerken
                    </button>
                    <button type="button" @click="remove(email)" class="text-sm text-red-600 hover:underline">
                        Verwijderen
                    </button>
                </div>
            </li>
            <li v-if="standardEmails.length === 0" class="px-4 py-3 text-sm text-gray-400">
                Nog geen standaard e-mails.
            </li>
        </ul>

        <ModalDialog :open="modalOpen" @update:open="modalOpen = $event"
            :title="editingId ? 'Standaard e-mail bewerken' : 'Nieuwe standaard e-mail'" maxWidthClass="sm:max-w-2xl">
            <form @submit.prevent="save" class="space-y-4">
                <TextInput v-model="form.name" label="Naam" type="text" :hasError="Boolean(form.errors.name)"
                    :errorMessage="form.errors.name" />
                <TextInput v-model="form.subject" label="Onderwerp" type="text" :hasError="Boolean(form.errors.subject)"
                    :errorMessage="form.errors.subject" />

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Inhoud</label>
                    <TipTapEditor v-model="form.body" :placeholders="placeholders" :hasError="Boolean(form.errors.body)" />
                    <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Automatisch versturen bij</label>
                        <button type="button" @click="addTrigger" class="text-sm text-lavoro-blue hover:underline">
                            Trigger toevoegen
                        </button>
                    </div>
                    <div v-for="(trigger, index) in form.triggers" :key="index" class="flex gap-2 mb-2 items-start">
                        <ComboBox v-model="trigger.trigger" :options="eventTriggers" :emitValue="true"
                            :initial-id="trigger.trigger" class="flex-1" placeholder="Moment..." />
                        <ComboBox v-model="trigger.trigger_type" :options="triggerTypes" :emitValue="true"
                            :initial-id="trigger.trigger_type" class="flex-1" placeholder="Verzendwijze..." />
                        <button type="button" @click="form.triggers.splice(index, 1)"
                            class="p-2 text-gray-400 hover:text-red-600">
                            <XMarkIcon class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Standaard bijlagen</label>
                    <ComboBox v-model="form.standard_attachment_ids" :options="standardAttachments" multiple
                        :initial-ids="form.standard_attachment_ids" placeholder="Kies bijlagen..." />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="modalOpen = false"
                        class="px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-600 text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                        Annuleren
                    </button>
                    <button type="submit" :disabled="form.processing"
                        class="px-4 py-2 rounded-lg bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                        Opslaan
                    </button>
                </div>
            </form>
        </ModalDialog>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'

const props = defineProps({
    standardEmails: { type: Array, required: true },
    standardAttachments: { type: Array, required: true },
    eventTriggers: { type: Array, required: true },
    triggerTypes: { type: Array, required: true },
    placeholders: { type: Array, required: true },
})

const modalOpen = ref(false)
const editingId = ref(null)

const form = useForm({
    name: '',
    subject: '',
    body: '',
    triggers: [],
    standard_attachment_ids: [],
})

function triggerLabel(name) {
    return props.eventTriggers.find((t) => t.id === name)?.name || name
}

function triggerTypeLabel(name) {
    return props.triggerTypes.find((t) => t.id === name)?.name || name
}

function addTrigger() {
    form.triggers.push({ trigger: props.eventTriggers[0]?.id, trigger_type: props.triggerTypes[0]?.id })
}

function openCreate() {
    editingId.value = null
    form.reset()
    form.clearErrors()
    modalOpen.value = true
}

function openEdit(email) {
    editingId.value = email.id
    form.clearErrors()
    form.name = email.name
    form.subject = email.subject
    form.body = email.body
    form.triggers = email.triggers.map((t) => ({ trigger: t.trigger, trigger_type: t.trigger_type }))
    form.standard_attachment_ids = email.standard_attachments.map((a) => a.id)
    modalOpen.value = true
}

function save() {
    if (editingId.value) {
        form.put(`/standard-emails/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: () => { modalOpen.value = false },
        })
    } else {
        form.post('/standard-emails', {
            preserveScroll: true,
            onSuccess: () => { modalOpen.value = false },
        })
    }
}

function remove(email) {
    if (!confirm(`Standaard e-mail "${email.name}" verwijderen?`)) return
    router.delete(`/standard-emails/${email.id}`, { preserveScroll: true })
}
</script>
```

- [ ] **Step 2: Verify in the browser**

With `composer run dev` running, log in as an admin, visit `/standard-emails`: create a new standard e-mail with a name, subject, some body text including an inserted placeholder chip, one trigger row (`event_created` / `confirm`), and no attachments (or one uploaded in Task 11). Confirm it appears in the list with a trigger badge. Edit it, change the trigger to `background`, save, confirm the badge updates. Delete it, confirm it disappears.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Admin/StandardEmails/IndexPage.vue
git commit -m "feat: add standard emails admin page"
```

---

### Task 13: Instellingen navigation links

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue`

**Interfaces:**
- Consumes: existing `isAdmin`/`currentPath` computed refs, existing `Cog6ToothIcon` import pattern.

- [ ] **Step 1: Add the icon imports**

In the `@heroicons/vue/24/outline` import block near the top of `resources/js/Layouts/MainLayout.vue`, add `EnvelopeIcon` and `PaperClipIcon` to the destructured list of icons already being imported from that package.

- [ ] **Step 2: Add links to the mobile admin block**

In `resources/js/Layouts/MainLayout.vue`, right after the existing Instellingen `<Link>` in the mobile block (the one ending `Instellingen</Link>` around line 135, inside `<div class="px-6 mb-2 space-y-1" v-if="isAdmin">`), add:

```html
                                                <Link @click="sidebarOpen = false" :href="'/standard-emails'" :class="[
                                                    currentPath.startsWith('/standard-emails') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                                ]">
                                                    <EnvelopeIcon class="size-6 shrink-0" />
                                                    Standaard e-mails
                                                </Link>
                                                <Link @click="sidebarOpen = false" :href="'/standard-attachments'" :class="[
                                                    currentPath.startsWith('/standard-attachments') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                                ]">
                                                    <PaperClipIcon class="size-6 shrink-0" />
                                                    Standaard bijlagen
                                                </Link>
```

- [ ] **Step 3: Add links to the desktop admin block**

Right after the desktop Instellingen `<Link>` (the one ending `Instellingen</Link>` around line 281, inside the second `<div class="px-6 mb-2 space-y-1" v-if="isAdmin">`), add the same two links but without the `@click="sidebarOpen = false"` attribute (matching how the surrounding desktop links in that block already omit it):

```html
                                <Link :href="'/standard-emails'" :class="[
                                    currentPath.startsWith('/standard-emails') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                ]">
                                    <EnvelopeIcon class="size-6 shrink-0" />
                                    Standaard e-mails
                                </Link>
                                <Link :href="'/standard-attachments'" :class="[
                                    currentPath.startsWith('/standard-attachments') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                ]">
                                    <PaperClipIcon class="size-6 shrink-0" />
                                    Standaard bijlagen
                                </Link>
```

- [ ] **Step 4: Verify in the browser**

As an admin, confirm both new links appear in the sidebar (desktop width and mobile/hamburger view) right after "Instellingen", navigate to both, confirm the active-state highlight (`bg-gray-800 text-white`) applies when on those pages.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Layouts/MainLayout.vue
git commit -m "feat: add standard emails and attachments links to Instellingen nav"
```

---

### Task 14: Shared EmailPreviewModal component

**Files:**
- Create: `resources/js/Components/EmailPreviewModal.vue`

**Interfaces:**
- Consumes: `TipTapEditor.vue`, `ModalDialog.vue`, `TextInput.vue`.
- Produces: props `open: Boolean`, `eventId: Number`, `standardEmailId: Number`, `to: String`, `subject: String`, `body: String`, `trigger: String|null`, `editable: Boolean` (default `true`); emits `update:open`, `sent`. Posts to `POST /api/events/{eventId}/standard-emails/send` on submit. Used for manual sends, `allowedit`/`confirm` trigger follow-ups (`editable=false` for `confirm`), and resend.

- [ ] **Step 1: Create the component**

```vue
<template>
    <ModalDialog :open="open" @update:open="$emit('update:open', $event)" title="E-mail versturen" maxWidthClass="sm:max-w-2xl">
        <div class="space-y-4">
            <TextInput v-model="localTo" label="Aan" type="email" :disabled="!editable" />
            <TextInput v-model="localSubject" label="Onderwerp" type="text" :disabled="!editable" />
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Bericht</label>
                <TipTapEditor v-if="editable" v-model="localBody" />
                <div v-else class="prose prose-sm max-w-none dark:prose-invert border rounded-lg px-3 py-2 dark:border-slate-600"
                    v-html="localBody" />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-3">
                <button type="button" @click="$emit('update:open', false)"
                    class="px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-600 text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                    Annuleren
                </button>
                <button type="button" @click="send" :disabled="sending"
                    class="px-4 py-2 rounded-lg bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ sending ? 'Versturen...' : 'Versturen' }}
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'

const props = defineProps({
    open: { type: Boolean, required: true },
    eventId: { type: Number, required: true },
    standardEmailId: { type: Number, required: true },
    to: { type: String, default: '' },
    subject: { type: String, default: '' },
    body: { type: String, default: '' },
    trigger: { type: String, default: null },
    editable: { type: Boolean, default: true },
})
const emit = defineEmits(['update:open', 'sent'])

const localTo = ref(props.to)
const localSubject = ref(props.subject)
const localBody = ref(props.body)
const sending = ref(false)

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        localTo.value = props.to
        localSubject.value = props.subject
        localBody.value = props.body
    }
})

async function send() {
    if (sending.value) return
    sending.value = true
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.post(`/api/events/${props.eventId}/standard-emails/send`, {
            standard_email_id: props.standardEmailId,
            to: localTo.value,
            subject: localSubject.value,
            body: localBody.value,
            trigger: props.trigger,
        })
        emit('sent')
        emit('update:open', false)
    } finally {
        sending.value = false
    }
}
</script>
```

- [ ] **Step 2: Verify with a throwaway usage**

Temporarily drop `<EmailPreviewModal :open="true" :event-id="1" :standard-email-id="1" to="test@example.com" subject="Test" body="<p>Hi</p>" @update:open="() => {}" @sent="() => console.log('sent')" />` into any page you can view (e.g. temporarily in `GeneralSettingsPage.vue`), using a real event id and standard email id from your test data. Confirm the modal renders with the TipTap editor, clicking "Versturen" calls the send endpoint (check the Network tab) and logs "sent" to the console on success. Revert the temporary edit.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/EmailPreviewModal.vue
git commit -m "feat: add shared email preview/send modal"
```

---

### Task 15: EventEditModal integration

**Files:**
- Modify: `resources/js/Components/Planner/EventEditModal.vue`

**Interfaces:**
- Consumes: `EmailPreviewModal.vue` (Task 14), `GET /api/events/{id}/standard-emails`, `GET /api/events/{id}/standard-emails/{id}/preview`, `GET /api/events/{id}/email-history` (Task 9), the `pending_standard_emails` field now present on save responses (Task 9).

- [ ] **Step 1: Add imports and state**

In `resources/js/Components/Planner/EventEditModal.vue`, add to the icon import line (currently `import { XMarkIcon, CalendarDaysIcon, TagIcon, CheckCircleIcon, DocumentTextIcon, UserIcon, DocumentIcon, BuildingOffice2Icon, Bars3BottomLeftIcon, UsersIcon, PlusIcon, CheckIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'`) the icon `EnvelopeIcon`.

Add a new import right after the other component imports:

```js
import EmailPreviewModal from '@/Components/EmailPreviewModal.vue'
```

Add this state, near the other `ref()` declarations at the top of `<script setup>` (after `const userToAdd = ref(null)`):

```js
const standardEmails = ref([])
const emailHistory = ref([])
const selectedStandardEmailId = ref(null)
const pendingQueue = ref([])
const previewModal = ref({
    open: false,
    standardEmailId: null,
    to: '',
    subject: '',
    body: '',
    trigger: null,
    editable: true,
})
```

- [ ] **Step 2: Add the fetch/queue functions**

Add these functions right before the existing `function save() {` declaration:

```js
async function loadStandardEmails() {
    if (!form.id) return
    const { data } = await axios.get(`/api/events/${form.id}/standard-emails`)
    standardEmails.value = data
}

async function loadEmailHistory() {
    if (!form.id) return
    const { data } = await axios.get(`/api/events/${form.id}/email-history`)
    emailHistory.value = data
}

async function openPreview(standardEmailId, trigger, editable) {
    const { data } = await axios.get(`/api/events/${form.id}/standard-emails/${standardEmailId}/preview`)
    previewModal.value = {
        open: true,
        standardEmailId,
        to: data.to || '',
        subject: data.subject,
        body: data.body,
        trigger,
        editable,
    }
}

function sendSelectedStandardEmail() {
    if (!selectedStandardEmailId.value) return
    openPreview(selectedStandardEmailId.value, null, true)
    selectedStandardEmailId.value = null
}

function resendHistoryItem(item) {
    if (!item.standard_email_id) return
    openPreview(item.standard_email_id, null, true)
}

function processNextPending() {
    if (pendingQueue.value.length === 0) return
    const next = pendingQueue.value.shift()
    openPreview(next.standard_email_id, next.trigger, next.trigger_type === 'allowedit')
}

function onEmailSent() {
    loadEmailHistory()
    if (pendingQueue.value.length > 0) {
        processNextPending()
    }
}
```

- [ ] **Step 3: Hook pending emails into save() and load on mount**

In `save()`, find the two lines:

```js
            page.props.flash.success = 'Afspraak succesvol bijgewerkt'
```

and

```js
            page.props.flash.success = 'Afspraak succesvol opgeslagen'
```

Right after each of those two lines (still inside their respective `if`/`else` branches, before `emit('saved')` — note `emit('saved')` is shared and comes after the `if/else` block, so add this check right after each success line, inside each branch), insert:

```js
            if (Array.isArray(r.data?.pending_standard_emails) && r.data.pending_standard_emails.length > 0) {
                pendingQueue.value = [...r.data.pending_standard_emails]
                processNextPending()
            }
```

Find the existing `onMounted(() => { ... })` block at the bottom of the script (the one with `requestAnimationFrame`) and add the load calls inside it:

```js
onMounted(() => {
    requestAnimationFrame(() => { visible.value = true })
    if (!form.eventable_id && internalServiceOrders.value.length > 0) {
        form.eventable_id = internalServiceOrders.value[0].id
    }
    if (props.editingExisting && form.id) {
        loadStandardEmails()
        loadEmailHistory()
    }
})
```

- [ ] **Step 4: Add the template section**

In the template, right after the closing `</div>` of the "Uitvoerende gebruikers" section (the `<div class="pb-2">...</div>` block that ends just before the closing `</div>` of the scrollable body, i.e. right before the `<!-- Footer -->` comment), add:

```html
                    <div v-if="editingExisting && form.id" class="pb-2">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                <EnvelopeIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                            </div>
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">E-mail</span>
                        </div>
                        <div class="flex gap-2 mb-3">
                            <ComboBox v-model="selectedStandardEmailId"
                                :options="standardEmails.map(e => ({ id: e.id, name: e.name }))" class="flex-1"
                                placeholder="Kies standaard e-mail..." :emitValue="true" />
                            <button type="button" @click="sendSelectedStandardEmail" :disabled="!selectedStandardEmailId"
                                class="px-4 py-2 rounded-xl bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                                Versturen
                            </button>
                        </div>
                        <div v-if="emailHistory.length" class="flex flex-col gap-2">
                            <div v-for="item in emailHistory" :key="item.id"
                                class="flex items-center justify-between bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <p class="text-gray-700 dark:text-gray-300 truncate">{{ item.description }}</p>
                                    <p class="text-xs text-gray-400">{{ nlDate(item.created_at) }} {{ nlTime(item.created_at) }}</p>
                                </div>
                                <button v-if="item.standard_email_id" type="button" @click="resendHistoryItem(item)"
                                    class="text-xs text-lavoro-blue font-medium hover:underline shrink-0 ml-2">
                                    Opnieuw versturen
                                </button>
                            </div>
                        </div>
                    </div>
```

Then, right after the closing `</Teleport>`'s preceding `</div>` (i.e. as the very last element before `</Teleport>` closes, a sibling to the modal panel `<div>`), add:

```html
        <EmailPreviewModal :open="previewModal.open" @update:open="previewModal.open = $event"
            :event-id="form.id" :standard-email-id="previewModal.standardEmailId" :to="previewModal.to"
            :subject="previewModal.subject" :body="previewModal.body" :trigger="previewModal.trigger"
            :editable="previewModal.editable" @sent="onEmailSent" />
```

- [ ] **Step 5: Verify in the browser**

Create a `StandardEmail` via the new admin page (Task 12) with a `confirm` trigger on `event_created`. In the planner, create a new event with a customer that has an e-mail address: after saving, confirm the preview modal for that pending trigger appears automatically (read-only, since `confirm` → `editable=false`), and clicking "Versturen" sends it and it shows up under "Verzonden e-mails" the next time you reopen that event's edit modal. Also test the manual "Kies standaard e-mail... / Versturen" flow, and "Opnieuw versturen" on a history entry.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Planner/EventEditModal.vue
git commit -m "feat: integrate standard email send/history/pending flow into event modal"
```

---

### Task 16: Planner destroy-response handling

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`

**Interfaces:**
- Consumes: `EmailPreviewModal.vue` (Task 14), the changed `DELETE /api/events/{id}` response shape from Task 9 (now `200` with `{pending_standard_emails: [...]}` instead of `204` with no body).

- [ ] **Step 1: Add the import and state**

Near the top of `resources/js/Components/Planner/ResourcePlannerWidget.vue`'s `<script setup>`, add:

```js
import EmailPreviewModal from '@/Components/EmailPreviewModal.vue'
```

Add state alongside the other top-level `ref()` declarations in this file:

```js
const deletedEventPendingQueue = ref([])
const deletedEventPreviewModal = ref({
    open: false,
    eventId: null,
    standardEmailId: null,
    to: '',
    subject: '',
    body: '',
    trigger: null,
    editable: true,
})
```

- [ ] **Step 2: Update deleteEvent and add the queue processor**

Replace the existing `deleteEvent` function:

```js
async function deleteEvent(ev) {
    if (!confirm(`Weet je zeker dat je afspraak #${ev.id} wilt verwijderen?`)) return
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.delete(`/api/events/${ev.id}`)
        if (r.status !== 204) throw new Error('bad response')
        events.value = events.value.filter(x => x.id !== ev.id)
        if (ev.eventable_id && ev.eventable_type === '\\App\\Models\\ServiceOrder') {
            emit('service-order-unplanned', ev.eventable_id)
        }
        page.props.flash.success = 'Afspraak verwijderd'
    } catch (e) {
        console.error('Failed to delete event', e)
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet verwijderen'
    }
}
```

with:

```js
async function deleteEvent(ev) {
    if (!confirm(`Weet je zeker dat je afspraak #${ev.id} wilt verwijderen?`)) return
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.delete(`/api/events/${ev.id}`)
        if (r.status !== 200) throw new Error('bad response')
        events.value = events.value.filter(x => x.id !== ev.id)
        if (ev.eventable_id && ev.eventable_type === '\\App\\Models\\ServiceOrder') {
            emit('service-order-unplanned', ev.eventable_id)
        }
        page.props.flash.success = 'Afspraak verwijderd'
        if (Array.isArray(r.data?.pending_standard_emails) && r.data.pending_standard_emails.length > 0) {
            deletedEventPendingQueue.value = r.data.pending_standard_emails.map((item) => ({ ...item, eventId: ev.id }))
            processNextDeletedEventPending()
        }
    } catch (e) {
        console.error('Failed to delete event', e)
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet verwijderen'
    }
}

async function processNextDeletedEventPending() {
    if (deletedEventPendingQueue.value.length === 0) return
    const next = deletedEventPendingQueue.value.shift()
    const { data } = await axios.get(`/api/events/${next.eventId}/standard-emails/${next.standard_email_id}/preview`)
    deletedEventPreviewModal.value = {
        open: true,
        eventId: next.eventId,
        standardEmailId: next.standard_email_id,
        to: data.to || '',
        subject: data.subject,
        body: data.body,
        trigger: next.trigger,
        editable: next.trigger_type === 'allowedit',
    }
}

function onDeletedEventEmailSent() {
    if (deletedEventPendingQueue.value.length > 0) {
        processNextDeletedEventPending()
    }
}
```

- [ ] **Step 3: Add the modal to the template**

Find the root template element of `ResourcePlannerWidget.vue` and, as one of its last children (a sibling to any other modals already rendered in this component, or as the last element before the root's closing tag if none), add:

```html
    <EmailPreviewModal :open="deletedEventPreviewModal.open" @update:open="deletedEventPreviewModal.open = $event"
        :event-id="deletedEventPreviewModal.eventId" :standard-email-id="deletedEventPreviewModal.standardEmailId"
        :to="deletedEventPreviewModal.to" :subject="deletedEventPreviewModal.subject"
        :body="deletedEventPreviewModal.body" :trigger="deletedEventPreviewModal.trigger"
        :editable="deletedEventPreviewModal.editable" @sent="onDeletedEventEmailSent" />
```

- [ ] **Step 4: Verify in the browser**

Create a `StandardEmail` with a `confirm` or `allowedit` trigger on `event_deleted`. Delete a matching event from the planner and confirm the preview modal appears after the delete completes, and sending it succeeds (check `email-history` doesn't apply here since the event is gone, but confirm no error is thrown and the mail is actually sent — check `storage/logs/laravel.log` if `MAIL_MAILER=log`).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Planner/ResourcePlannerWidget.vue
git commit -m "feat: handle pending standard emails after event deletion"
```

---

### Task 17: End-to-end verification

**Files:** none (verification only).

- [ ] **Step 1: Full backend check**

Run: `php artisan migrate:status` — confirm all Task 1/2 migrations show `Ran`.
Run: `./vendor/bin/pint --test` — confirm no style violations in the new/modified PHP files (run `./vendor/bin/pint` without `--test` to auto-fix if it reports issues, then re-check).

- [ ] **Step 2: Full frontend build check**

Run: `npm run build`
Expected: build succeeds with no errors (this catches missing imports/typos across all the new Vue files).
Run: `npm run fix:eslint`
Expected: no unresolved lint errors in the new/modified files.

- [ ] **Step 3: Full manual walkthrough**

With `composer run dev` running and logged in as an admin:
1. Go to Instellingen → Standaard e-mails, create one with a `background` trigger on `event_created`, and one with an `allowedit` trigger on `event_updated`. Add a standard attachment (Instellingen → Standaard bijlagen) and attach it to one of them.
2. In the planner, create a new event for a customer with a real-looking e-mail address. Confirm (via `storage/logs/laravel.log` if `MAIL_MAILER=log`, or your test inbox) that the `background` e-mail sent automatically with the attachment included and placeholders substituted correctly (real date/time, not `{{...}}`).
3. Edit that event's time. Confirm the `allowedit` preview modal appears after save, shows the new time already substituted, let it send, and confirm the e-mail shows up in "Verzonden e-mails" in that event's edit modal.
4. Click "Opnieuw versturen" on that history entry — confirm it resends with a fresh preview call (change the event's time again first, then resend, and confirm the *new* time is reflected — this proves live re-rendering, not a frozen snapshot).
5. Manually pick a standard e-mail from the dropdown in the event modal and send it ad hoc.
6. Delete an event that has a matching trigger and confirm that flow works per Task 16's verification step.

- [ ] **Step 4: Report completion**

Once all six walkthrough points pass, the feature is complete — no further steps.
