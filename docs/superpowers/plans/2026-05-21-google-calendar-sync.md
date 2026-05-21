# Google Calendar Sync — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bidirectional (limited, permission-gated) sync between Lavoro `Event` records and per-user Google Calendars, plus admin-managed cross-user calendar grants.

**Architecture:** A new `GoogleCalendarIntegration` per user holds OAuth tokens; `GoogleSyncedCalendar` rows represent each Google calendar this integration owns (own "Lavoro" + one "X — Lavoro" per active grant); `GoogleSyncedEvent` maps Lavoro events to Google event ids and stores etags for echo suppression. Outbound sync is driven by Eloquent observers fanning out to queued jobs; inbound sync runs through `IncomingChangeHandler` which permission-gates every change and reverts unauthorised ones via corrective pushes. Change detection uses Google push notifications (when enabled) with a 5-minute polling fallback via `syncToken`.

**Tech Stack:** Laravel 11, Inertia.js, Vue 3, Tailwind, queue (Laravel queue worker), scheduler, `google/apiclient` ^2.x.

**Reference:** [docs/superpowers/specs/2026-05-21-google-calendar-sync-design.md](../specs/2026-05-21-google-calendar-sync-design.md) — the design spec. Read this if any task is ambiguous.

**Project conventions (from claude.md):**
- PHP variable names: `snake_case`.
- No inline comments; clear names + docblocks only when needed.
- Authorization in Form Requests via `authorize()`, using policies or `hasPermission(...)`.
- No tests unless explicitly requested.
- No git commands in plan steps — implementer commits at their own cadence.

**Phase boundaries are natural shipping checkpoints.** After Phase 4 ends, the app already syncs Lavoro → Google. After Phase 5, polling-based two-way works. Phase 6 adds webhooks. Phase 7 adds cross-user grants.

---

## Codebase Context

| Pattern | Where |
|---|---|
| Permission seeded via migration | `database/migrations/2025_09_17_100000_add_event_see_all_permission.php` |
| Form Request authorization | `app/Http/Requests/EventReadRequest.php` |
| Admin-only route block | `routes/web.php` line 151 (`Route::middleware('admin')->group(...)`) |
| Admin middleware | `App\Http\Middleware\EnsureUserIsAdmin` (registered as `'admin'` alias in `bootstrap/app.php`) |
| `HasExecutingUsers` trait | `app/Models/Traits/HasExecutingUsers.php` — `morphToMany(User::class, 'userable')->wherePivot('type','executing')` |
| `HasOwner` trait | `app/Models/Traits/HasOwner.php` — same morph, pivot type `'owner'`; auto-attaches `Auth::id()` on create |
| Permissions to Inertia frontend | `app/Http/Middleware/HandleInertiaRequests.php` line 47 (`auth.permissions = user->permissionNames()`) |
| Frontend permission check | `Utilities.js` → `hasPermission('foo')` (reads `auth.permissions`) |
| Existing event permissions | `event.read`, `event.see_all` only — no `update`/`delete` exists yet |
| Existing service class style | `app/Services/SnelStartClient.php`, `app/Services/UserAvatarService.php` |
| Events table | `start`, `end` are `dateTime`; `status` is enum `EventStatusses`; `event_type_id` FK; owner attached via `userables` pivot on create |
| Polymorphic morph type | `App\Models\Event` (Laravel uses FQCN by default in the `userable_type` column) |

---

## File Map

### New files

| File | Phase | Responsibility |
|---|---|---|
| `database/migrations/2026_05_22_000001_create_google_calendar_integrations_table.php` | 1 | Per-user OAuth integration row |
| `database/migrations/2026_05_22_000002_create_google_synced_calendars_table.php` | 1 | Per-calendar tracking (own + granted) |
| `database/migrations/2026_05_22_000003_create_google_synced_events_table.php` | 1 | Event ↔ google_event_id mapping + etag |
| `database/migrations/2026_05_22_000004_create_calendar_grants_table.php` | 1 | Admin grants (owner→viewer) |
| `database/migrations/2026_05_22_000005_add_softdeletes_and_origin_to_events_table.php` | 1 | `deleted_at` + `origin` |
| `database/migrations/2026_05_22_000006_add_google_calendar_and_event_permissions.php` | 1 | New permission rows |
| `app/Models/GoogleCalendarIntegration.php` | 1 | |
| `app/Models/GoogleSyncedCalendar.php` | 1 | |
| `app/Models/GoogleSyncedEvent.php` | 1 | |
| `app/Models/CalendarGrant.php` | 1 | |
| `app/Policies/EventPolicy.php` | 1 | `create`, `update`, `delete`, `createOthers` |
| `app/Policies/CalendarGrantPolicy.php` | 1 | `manage` |
| `config/google.php` | 2 | OAuth client config + feature flags |
| `app/Services/Google/GoogleClientFactory.php` | 3 | Builds + refreshes `Google\Client` for integration |
| `app/Http/Controllers/GoogleOAuthController.php` | 3 | `start`, `callback`, `destroy` |
| `app/Http/Controllers/Api/GoogleIntegrationStatusController.php` | 3 | `GET /api/google/integration/status` |
| `app/Jobs/Google/TeardownIntegrationJob.php` | 3 | Disconnect cleanup |
| `resources/js/Components/GoogleCalendarSection.vue` | 3 | Profile page section |
| `lang/nl/google.php` / `lang/en/google.php` | 3 | Strings (extended in later phases) |
| `app/Services/Google/GoogleCalendarApi.php` | 4 | Thin SDK wrapper with retry/backoff |
| `app/Services/Google/EventPayloadBuilder.php` | 4 | Event → Google payload |
| `app/Services/Google/CalendarSyncService.php` | 4 | `pushEvent`, later `pullChanges` |
| `app/Jobs/Google/PushEventJob.php` | 4 | Fan-out per event |
| `app/Jobs/Google/PushEventToCalendarJob.php` | 4 | Single push, etag-recording |
| `app/Jobs/Google/DeleteEventFromGoogleJob.php` | 4 | Single delete |
| `app/Jobs/Google/BackfillCalendarJob.php` | 4 | First-connect backfill |
| `app/Observers/EventObserver.php` | 4 | Lifecycle hooks |
| `app/Services/Google/IncomingChangeHandler.php` | 5 | Permission-gated incoming logic |
| `app/Jobs/Google/PullCalendarChangesJob.php` | 5 | Polling pull |
| `app/Http/Controllers/GoogleWebhookController.php` | 6 | Webhook receiver |
| `app/Jobs/Google/RenewWatchChannelsJob.php` | 6 | Hourly renewal |
| `app/Http/Controllers/Admin/CalendarGrantController.php` | 7 | Admin CRUD |
| `app/Http/Requests/CalendarGrantStoreRequest.php` | 7 | |
| `app/Http/Requests/CalendarGrantDestroyRequest.php` | 7 | |
| `app/Services/Google/GrantSyncService.php` | 7 | onGrantCreated / onGrantRevoked |
| `resources/js/Pages/Admin/CalendarGrants/IndexPage.vue` | 7 | Admin UI |
| `docs/google-calendar-setup.md` | 8 | One-time developer setup walkthrough |

### Modified files

| File | Phases | Change |
|---|---|---|
| `app/Models/Event.php` | 1, 4 | Add `SoftDeletes`, `origin` to fillable+casts, register observer wiring |
| `app/Models/User.php` | 1, 7 | Add `googleCalendarIntegration`, `calendarGrantsOwned`, `calendarGrantsReceived` relations |
| `app/Providers/AppServiceProvider.php` | 4, 5 | Register `EventObserver`, register policies |
| `app/Http/Middleware/HandleInertiaRequests.php` | 3 | Expose `auth.user.googleIntegration` (id, email, disabled_at) |
| `resources/js/Layouts/MainLayout.vue` | 3 | Persistent "reconnect" banner |
| `resources/js/Pages/Users/EditSelfPage.vue` (the existing self-edit page) | 3 | Embed `GoogleCalendarSection.vue` |
| `routes/web.php` | 3, 7 | OAuth routes + admin grants routes |
| `routes/api.php` | 3 | Status endpoint |
| `routes/console.php` | 5, 6 | Scheduled jobs |
| `composer.json` | 2 | `google/apiclient` |
| `.env.example` | 2 | Google env vars |
| `bootstrap/app.php` | 6 | Webhook route in the `web` exclusion list (for CSRF) |

---

# Phase 1 — Schema, models, permissions, policies

This phase is pure DB + models + policies. No Google calls anywhere. End state: you can run `php artisan migrate` cleanly and the schema is in place.

## Task 1.1 — Integration table migration

**Files:**
- Create: `database/migrations/2026_05_22_000001_create_google_calendar_integrations_table.php`

- [ ] **Step 1: Create migration file with this exact content**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('google_account_email');
            $table->string('google_account_sub');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->dateTime('expires_at');
            $table->json('scopes');
            $table->unsignedInteger('backfill_total')->nullable();
            $table->unsignedInteger('backfill_done')->nullable();
            $table->dateTime('connected_at');
            $table->text('last_error')->nullable();
            $table->dateTime('disabled_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_integrations');
    }
};
```

- [ ] **Step 2: Verify**

Run: `php artisan migrate`
Expected: migration runs without error. Run `php artisan migrate:rollback` then `php artisan migrate` again to confirm reversibility.

## Task 1.2 — Synced-calendars table migration

**Files:**
- Create: `database/migrations/2026_05_22_000002_create_google_synced_calendars_table.php`

- [ ] **Step 1: Create migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_synced_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_calendar_integration_id')
                ->constrained('google_calendar_integrations')
                ->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('google_calendar_id');
            $table->string('summary');
            $table->text('sync_token')->nullable();
            $table->uuid('watch_channel_id')->nullable();
            $table->string('watch_channel_token')->nullable();
            $table->string('watch_resource_id')->nullable();
            $table->dateTime('watch_expires_at')->nullable();
            $table->dateTime('last_full_sync_at')->nullable();
            $table->timestamps();
            $table->unique(['google_calendar_integration_id', 'owner_user_id'], 'gsc_integration_owner_unique');
            $table->index('watch_channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_synced_calendars');
    }
};
```

- [ ] **Step 2: Verify migration runs and rolls back cleanly.**

## Task 1.3 — Synced-events mapping table migration

**Files:**
- Create: `database/migrations/2026_05_22_000003_create_google_synced_events_table.php`

- [ ] **Step 1: Create migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_synced_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_synced_calendar_id')
                ->constrained('google_synced_calendars')
                ->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('google_event_id');
            $table->string('etag');
            $table->dateTime('last_pushed_at');
            $table->timestamps();
            $table->unique(['google_synced_calendar_id', 'event_id'], 'gse_cal_event_unique');
            $table->unique(['google_synced_calendar_id', 'google_event_id'], 'gse_cal_googleid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_synced_events');
    }
};
```

- [ ] **Step 2: Verify migration.**

## Task 1.4 — Calendar grants table migration

**Files:**
- Create: `database/migrations/2026_05_22_000004_create_calendar_grants_table.php`

- [ ] **Step 1: Create migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('viewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['owner_user_id', 'viewer_user_id'], 'cg_owner_viewer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_grants');
    }
};
```

- [ ] **Step 2: Verify migration.**

## Task 1.5 — Add softdeletes + origin to events

**Files:**
- Create: `database/migrations/2026_05_22_000005_add_softdeletes_and_origin_to_events_table.php`

- [ ] **Step 1: Create migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->softDeletes();
            $table->enum('origin', ['lavoro', 'google'])->default('lavoro')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('origin');
        });
    }
};
```

- [ ] **Step 2: Verify migration.**

## Task 1.6 — Permission seed migration

**Files:**
- Create: `database/migrations/2026_05_22_000006_add_google_calendar_and_event_permissions.php`

- [ ] **Step 1: Create migration**

```php
<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'google_calendar.connect', 'label' => 'Eigen Google Agenda koppelen'],
        ['name' => 'calendar_grant.manage', 'label' => 'Agenda-toegang van gebruikers beheren'],
        ['name' => 'event.create', 'label' => 'Eigen afspraken aanmaken'],
        ['name' => 'event.create_others', 'label' => 'Afspraken voor anderen aanmaken'],
        ['name' => 'event.update', 'label' => 'Eigen afspraken wijzigen'],
        ['name' => 'event.update_others', 'label' => 'Afspraken van anderen wijzigen'],
        ['name' => 'event.delete', 'label' => 'Eigen afspraken verwijderen'],
        ['name' => 'event.delete_others', 'label' => 'Afspraken van anderen verwijderen'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (!Permission::where('name', $permission['name'])->exists()) {
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

- [ ] **Step 2: Verify**

Run: `php artisan migrate` then in `php artisan tinker`:
```
App\Models\Permission::whereIn('name', ['event.update', 'event.delete_others', 'google_calendar.connect'])->pluck('name')
```
Expected: all three names returned.

## Task 1.7 — `Event` model: SoftDeletes + origin

**Files:**
- Modify: `app/Models/Event.php`

- [ ] **Step 1: Replace contents with**

```php
<?php

namespace App\Models;

use App\Enums\EventStatusses;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasOwner;
    use HasExecutingUsers;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'event_type_id',
        'start',
        'end',
        'status',
        'location',
        'origin',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
    ];

    public static function statusses()
    {
        return EventStatusses::comboBoxArray();
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class);
    }

    public function serviceOrders()
    {
        return $this->morphedByMany(ServiceOrder::class, 'eventable');
    }
}
```

- [ ] **Step 2: Verify**

In `php artisan tinker`:
```
$event = App\Models\Event::first();
$event->delete();
App\Models\Event::find($event->id);          // null
App\Models\Event::withTrashed()->find($event->id);  // non-null, deleted_at set
$event->restore();
```
Expected: soft-delete cycle works.

## Task 1.8 — `User` model: integration + grants relations

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Add these methods just before the closing `}` of the class**

```php
    public function googleCalendarIntegration()
    {
        return $this->hasOne(GoogleCalendarIntegration::class);
    }

    public function calendarGrantsOwned()
    {
        return $this->hasMany(CalendarGrant::class, 'owner_user_id');
    }

    public function calendarGrantsReceived()
    {
        return $this->hasMany(CalendarGrant::class, 'viewer_user_id');
    }
```

- [ ] **Step 2: Verify**

After creating the models in tasks 1.9–1.12, in tinker:
```
$user = App\Models\User::first();
$user->googleCalendarIntegration;       // null (nothing connected yet)
$user->calendarGrantsOwned;             // empty collection
```

## Task 1.9 — `GoogleCalendarIntegration` model

**Files:**
- Create: `app/Models/GoogleCalendarIntegration.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class GoogleCalendarIntegration extends Model
{
    protected $fillable = [
        'user_id',
        'google_account_email',
        'google_account_sub',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'backfill_total',
        'backfill_done',
        'connected_at',
        'last_error',
        'disabled_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disabled_at' => 'datetime',
        'scopes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function syncedCalendars()
    {
        return $this->hasMany(GoogleSyncedCalendar::class);
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value === null ? null : Crypt::encryptString($value);
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $value === null ? null : Crypt::encryptString($value);
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }
}
```

- [ ] **Step 2: Verify in tinker that `Crypt` round-trip works**

```
$row = new App\Models\GoogleCalendarIntegration();
$row->access_token = 'abc';
echo $row->access_token;           // abc
echo $row->getAttributes()['access_token'];   // encrypted blob
```

## Task 1.10 — `GoogleSyncedCalendar` model

**Files:**
- Create: `app/Models/GoogleSyncedCalendar.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleSyncedCalendar extends Model
{
    protected $fillable = [
        'google_calendar_integration_id',
        'owner_user_id',
        'google_calendar_id',
        'summary',
        'sync_token',
        'watch_channel_id',
        'watch_channel_token',
        'watch_resource_id',
        'watch_expires_at',
        'last_full_sync_at',
    ];

    protected $casts = [
        'watch_expires_at' => 'datetime',
        'last_full_sync_at' => 'datetime',
    ];

    public function integration()
    {
        return $this->belongsTo(GoogleCalendarIntegration::class, 'google_calendar_integration_id');
    }

    public function ownerUser()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function syncedEvents()
    {
        return $this->hasMany(GoogleSyncedEvent::class);
    }

    public function isOwnersOwnCalendar(): bool
    {
        return $this->owner_user_id === $this->integration->user_id;
    }
}
```

## Task 1.11 — `GoogleSyncedEvent` model

**Files:**
- Create: `app/Models/GoogleSyncedEvent.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleSyncedEvent extends Model
{
    protected $fillable = [
        'google_synced_calendar_id',
        'event_id',
        'google_event_id',
        'etag',
        'last_pushed_at',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function syncedCalendar()
    {
        return $this->belongsTo(GoogleSyncedCalendar::class, 'google_synced_calendar_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
```

## Task 1.12 — `CalendarGrant` model

**Files:**
- Create: `app/Models/CalendarGrant.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarGrant extends Model
{
    protected $fillable = ['owner_user_id', 'viewer_user_id'];

    public function ownerUser()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function viewerUser()
    {
        return $this->belongsTo(User::class, 'viewer_user_id');
    }
}
```

## Task 1.13 — `EventPolicy`

**Files:**
- Create: `app/Policies/EventPolicy.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.create');
    }

    public function createOthers(User $user, User $owner_user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($owner_user->id === $user->id) {
            return $user->hasPermission('event.create');
        }
        return $user->hasPermission('event.create_others');
    }

    public function update(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $owner = $event->owner();
        $is_owner = $owner && $owner->id === $user->id;
        if ($is_owner && $user->hasPermission('event.update')) {
            return true;
        }
        return $user->hasPermission('event.update_others');
    }

    public function delete(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $owner = $event->owner();
        $is_owner = $owner && $owner->id === $user->id;
        if ($is_owner && $user->hasPermission('event.delete')) {
            return true;
        }
        return $user->hasPermission('event.delete_others');
    }
}
```

## Task 1.14 — `CalendarGrantPolicy`

**Files:**
- Create: `app/Policies/CalendarGrantPolicy.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Policies;

use App\Models\User;

class CalendarGrantPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('calendar_grant.manage');
    }
}
```

## Task 1.15 — Register policies

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: At the top of the class, add imports**

```php
use App\Models\CalendarGrant;
use App\Models\Event;
use App\Policies\CalendarGrantPolicy;
use App\Policies\EventPolicy;
use Illuminate\Support\Facades\Gate;
```

- [ ] **Step 2: Inside `boot()`, before any existing logic, add**

```php
Gate::policy(Event::class, EventPolicy::class);
Gate::policy(CalendarGrant::class, CalendarGrantPolicy::class);
```

- [ ] **Step 3: Verify in tinker**

```
$user = App\Models\User::first();
$event = App\Models\Event::first();
$user->can('update', $event);    // boolean (depends on user's perms)
```

---

### Phase 1 checkpoint

Run: `php artisan migrate:fresh` (in a dev DB) → verify all migrations succeed and rollback cleanly with `php artisan migrate:rollback --step=6`. All models load. No code references Google APIs yet.

---

# Phase 2 — Dependency + configuration

## Task 2.1 — Install `google/apiclient`

- [ ] **Step 1:** Run `composer require google/apiclient:^2.18` in the project root.
- [ ] **Step 2:** Verify by running `php -r "require 'vendor/autoload.php'; new Google\Client();"` — no error.

## Task 2.2 — Create `config/google.php`

**Files:**
- Create: `config/google.php`

- [ ] **Step 1: Create file**

```php
<?php

return [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'oauth_redirect_uri' => env('GOOGLE_OAUTH_REDIRECT_URI', 'http://localhost:8000/google/oauth/callback'),

    'scopes' => [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/calendar',
    ],

    'webhook_enabled' => env('GOOGLE_WEBHOOK_ENABLED', false),
    'webhook_url' => env('GOOGLE_WEBHOOK_URL'),

    'sync_lookback_days' => (int) env('GOOGLE_SYNC_LOOKBACK_DAYS', 365),

    'default_event_type_id' => env('GOOGLE_DEFAULT_EVENT_TYPE_ID'),

    'calendar_summary_own' => 'Lavoro',
    'calendar_summary_granted_template' => ':name — Lavoro',
];
```

## Task 2.3 — Update `.env.example`

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Append at the end of `.env.example`**

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_OAUTH_REDIRECT_URI=http://localhost:8000/google/oauth/callback
GOOGLE_WEBHOOK_ENABLED=false
GOOGLE_WEBHOOK_URL=
GOOGLE_SYNC_LOOKBACK_DAYS=365
GOOGLE_DEFAULT_EVENT_TYPE_ID=
```

- [ ] **Step 2:** Copy the same lines into your local `.env`, leaving `CLIENT_ID`/`CLIENT_SECRET` empty for now.

---

### Phase 2 checkpoint

Run: `php artisan config:clear && php artisan tinker -e "config('google.scopes')"` — expected array with 4 strings.

---

# Phase 3 — OAuth connect/disconnect (no event sync yet)

End state: a user can click "Connect Google Calendar" in their profile, complete OAuth, see "Connected as you@example.com", and click "Disconnect" to revoke. No calendar is created in Google yet — that comes in Phase 4 along with backfill.

## Task 3.1 — `GoogleClientFactory`

**Files:**
- Create: `app/Services/Google/GoogleClientFactory.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarIntegration;
use Carbon\Carbon;
use Google\Client;
use Illuminate\Support\Facades\Log;

class GoogleClientFactory
{
    public function unauthenticatedClient(): Client
    {
        $client = new Client();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.oauth_redirect_uri'));
        $client->setScopes(config('google.scopes'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        return $client;
    }

    public function clientFor(GoogleCalendarIntegration $integration): Client
    {
        $client = $this->unauthenticatedClient();

        $client->setAccessToken([
            'access_token' => $integration->access_token,
            'refresh_token' => $integration->refresh_token,
            'expires_in' => max(0, Carbon::parse($integration->expires_at)->diffInSeconds(now(), false)),
            'created' => 0,
        ]);

        if ($integration->expires_at->lt(now()->addSeconds(60))) {
            $this->refresh($client, $integration);
        }

        return $client;
    }

    private function refresh(Client $client, GoogleCalendarIntegration $integration): void
    {
        try {
            $token_set = $client->fetchAccessTokenWithRefreshToken($integration->refresh_token);
        } catch (\Throwable $e) {
            $this->disable($integration, $e->getMessage());
            throw $e;
        }

        if (isset($token_set['error'])) {
            $this->disable($integration, $token_set['error_description'] ?? $token_set['error']);
            throw new \RuntimeException('Google token refresh failed: ' . ($token_set['error_description'] ?? $token_set['error']));
        }

        $integration->access_token = $token_set['access_token'];
        if (!empty($token_set['refresh_token'])) {
            $integration->refresh_token = $token_set['refresh_token'];
        }
        $integration->expires_at = now()->addSeconds((int) ($token_set['expires_in'] ?? 3600));
        $integration->save();
    }

    private function disable(GoogleCalendarIntegration $integration, string $reason): void
    {
        $integration->disabled_at = now();
        $integration->last_error = 'Token refresh failed: ' . $reason;
        $integration->save();
        Log::warning('Google integration disabled', [
            'integration_id' => $integration->id,
            'reason' => $reason,
        ]);
    }
}
```

## Task 3.2 — `GoogleOAuthController`

**Files:**
- Create: `app/Http/Controllers/GoogleOAuthController.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\Google\TeardownIntegrationJob;
use App\Models\GoogleCalendarIntegration;
use App\Services\Google\GoogleClientFactory;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class GoogleOAuthController extends Controller
{
    public function __construct(private GoogleClientFactory $client_factory)
    {
    }

    public function start(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasPermission('google_calendar.connect') || Auth::user()->isAdmin(), 403);

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        $client = $this->client_factory->unauthenticatedClient();
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected_state = $request->session()->pull('google_oauth_state');
        if (!$expected_state || !hash_equals($expected_state, (string) $request->query('state'))) {
            return redirect()->route('me.edit')->with('error', __('google.oauth_state_mismatch'));
        }

        if ($request->has('error')) {
            return redirect()->route('me.edit')->with('error', __('google.oauth_denied'));
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            return redirect()->route('me.edit')->with('error', __('google.oauth_no_code'));
        }

        $client = $this->client_factory->unauthenticatedClient();
        $token_set = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token_set['error'])) {
            return redirect()->route('me.edit')->with('error', __('google.oauth_token_exchange_failed'));
        }

        $client->setAccessToken($token_set);
        $oauth = new \Google\Service\Oauth2($client);
        $userinfo = $oauth->userinfo->get();

        $user = Auth::user();

        $integration = GoogleCalendarIntegration::updateOrCreate(
            ['user_id' => $user->id],
            [
                'google_account_email' => $userinfo->email,
                'google_account_sub' => $userinfo->id,
                'access_token' => $token_set['access_token'],
                'refresh_token' => $token_set['refresh_token'] ?? '',
                'expires_at' => now()->addSeconds((int) ($token_set['expires_in'] ?? 3600)),
                'scopes' => explode(' ', $token_set['scope'] ?? ''),
                'connected_at' => now(),
                'disabled_at' => null,
                'last_error' => null,
            ]
        );

        if ($integration->refresh_token === '') {
            $integration->delete();
            return redirect()->route('me.edit')->with('error', __('google.oauth_no_refresh_token'));
        }

        // Phase 4 will dispatch the BackfillCalendarJob here.
        return redirect()->route('me.edit')->with('success', __('google.connected'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $integration = Auth::user()->googleCalendarIntegration;
        if ($integration) {
            TeardownIntegrationJob::dispatch($integration->id);
        }
        return redirect()->route('me.edit')->with('success', __('google.disconnect_started'));
    }
}
```

## Task 3.3 — `TeardownIntegrationJob` (minimal Phase 3 version)

**Files:**
- Create: `app/Jobs/Google/TeardownIntegrationJob.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Jobs\Google;

use App\Models\GoogleCalendarIntegration;
use App\Services\Google\GoogleClientFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TeardownIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $integration_id)
    {
    }

    public function handle(GoogleClientFactory $client_factory): void
    {
        $integration = GoogleCalendarIntegration::find($this->integration_id);
        if (!$integration) {
            return;
        }

        // Phase 4 will stop watches + delete every google_synced_calendar before this.
        // Phase 6 will also stop watch channels.

        $this->revokeRefreshToken($integration);

        $integration->syncedCalendars()->each(fn ($cal) => $cal->delete());
        $integration->delete();
    }

    private function revokeRefreshToken(GoogleCalendarIntegration $integration): void
    {
        try {
            Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
                'token' => $integration->refresh_token,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google token revoke failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }
}
```

## Task 3.4 — Status endpoint controller

**Files:**
- Create: `app/Http/Controllers/Api/GoogleIntegrationStatusController.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class GoogleIntegrationStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $integration = Auth::user()->googleCalendarIntegration;
        if (!$integration) {
            return response()->json(['connected' => false]);
        }
        return response()->json([
            'connected' => true,
            'email' => $integration->google_account_email,
            'disabled' => $integration->isDisabled(),
            'last_error' => $integration->last_error,
            'backfill_total' => $integration->backfill_total,
            'backfill_done' => $integration->backfill_done,
        ]);
    }
}
```

## Task 3.5 — OAuth routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Inside the existing `Route::group(['middleware' => 'auth'], ...)` block (any logical place), add**

```php
Route::get('google/oauth/start', [\App\Http\Controllers\GoogleOAuthController::class, 'start'])
    ->name('google.oauth.start');
Route::get('google/oauth/callback', [\App\Http\Controllers\GoogleOAuthController::class, 'callback'])
    ->name('google.oauth.callback');
Route::delete('google/integration', [\App\Http\Controllers\GoogleOAuthController::class, 'destroy'])
    ->name('google.integration.destroy');
```

- [ ] **Step 2: Verify**

Run `php artisan route:list | grep google` — should show all three named routes.

## Task 3.6 — Status route

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add inside the `auth:sanctum` middleware group (or create one if absent)**

```php
Route::get('google/integration/status', \App\Http\Controllers\Api\GoogleIntegrationStatusController::class);
```

## Task 3.7 — Expose integration to Inertia

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`

- [ ] **Step 1: Locate the existing `share()` method's user data block; add the integration summary alongside `permissions`. Inside the `'auth' => [...]` array, ensure the user is shared with an `integration` sub-key. Concretely, change the user portion to**

```php
'user' => $request->user() ? array_merge(
    $request->user()->only(['id', 'name', 'email', 'avatar']),
    [
        'google_integration' => $request->user()->googleCalendarIntegration
            ? [
                'email' => $request->user()->googleCalendarIntegration->google_account_email,
                'disabled_at' => $request->user()->googleCalendarIntegration->disabled_at,
            ]
            : null,
    ]
) : null,
```

(If the existing share already shapes the user differently, splice `google_integration` into that shape — keep existing keys intact.)

- [ ] **Step 2: Verify**

Reload any Inertia page in browser; open Vue devtools and inspect `usePage().props.auth.user.google_integration` — should be `null` (no integration yet).

## Task 3.8 — `GoogleCalendarSection.vue` component

**Files:**
- Create: `resources/js/Components/GoogleCalendarSection.vue`

- [ ] **Step 1: Create file**

```vue
<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();
const status = ref(null);
const polling = ref(null);

const integration = computed(() => page.props.auth.user?.google_integration ?? null);
const connected = computed(() => !!integration.value);
const disabled = computed(() => !!integration.value?.disabled_at);

async function fetchStatus() {
    try {
        const { data } = await axios.get('/api/google/integration/status');
        status.value = data;
        if (data.connected && data.backfill_total && data.backfill_done < data.backfill_total) {
            // keep polling
        } else if (polling.value) {
            clearInterval(polling.value);
            polling.value = null;
        }
    } catch (e) {
        // ignore
    }
}

function startPolling() {
    fetchStatus();
    if (polling.value) return;
    polling.value = setInterval(fetchStatus, 4000);
}

function connect() {
    window.location.href = '/google/oauth/start';
}

function disconnect() {
    if (!confirm('Weet je zeker dat je de Google Agenda-koppeling wilt opheffen?')) return;
    router.delete('/google/integration', { preserveScroll: true });
}

onMounted(() => {
    if (connected.value) startPolling();
});
</script>

<template>
    <div class="rounded border bg-white p-4">
        <h3 class="mb-2 text-lg font-medium">Google Agenda</h3>

        <div v-if="!connected">
            <p class="mb-3 text-sm text-gray-700">
                Koppel je Google Agenda om afspraken automatisch te synchroniseren.
            </p>
            <button class="rounded bg-blue-600 px-4 py-2 text-white" @click="connect">
                Google Agenda koppelen
            </button>
        </div>

        <div v-else>
            <div v-if="disabled" class="mb-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-800">
                Synchronisatie gepauzeerd — koppel opnieuw om verder te gaan.
                <button class="ml-2 underline" @click="connect">Opnieuw koppelen</button>
            </div>

            <p class="text-sm text-gray-700">Gekoppeld als <strong>{{ integration.email }}</strong></p>

            <div
                v-if="status && status.backfill_total && status.backfill_done < status.backfill_total"
                class="mt-2 text-xs text-gray-500"
            >
                Backfill bezig: {{ status.backfill_done }} / {{ status.backfill_total }} afspraken
            </div>

            <button class="mt-3 rounded border px-3 py-1 text-sm" @click="disconnect">Koppeling opheffen</button>
        </div>
    </div>
</template>
```

## Task 3.9 — Embed section in self-edit page

**Files:**
- Modify: the existing user self-edit page (resolve actual path with `php artisan route:list | grep me.edit` → controller method → page name).

- [ ] **Step 1:** Open the resolved page (likely `resources/js/Pages/Users/EditSelfPage.vue`). Add at the top of the `<script setup>` block:

```js
import GoogleCalendarSection from '@/Components/GoogleCalendarSection.vue';
```

- [ ] **Step 2:** Add `<GoogleCalendarSection class="mt-6" />` inside the page's main column, after the existing profile form.

## Task 3.10 — Reconnect banner in `MainLayout.vue`

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue`

- [ ] **Step 1:** Near the top of the layout's main template (before page content slot), add:

```vue
<div
    v-if="$page.props.auth.user?.google_integration?.disabled_at && !bannerDismissed"
    class="bg-amber-100 border-b border-amber-300 px-4 py-2 text-sm text-amber-900 flex items-center justify-between"
>
    <span>Je Google Agenda synchronisatie is gepauzeerd.</span>
    <span>
        <a href="/google/oauth/start" class="underline">Opnieuw koppelen</a>
        <button class="ml-3" @click="dismissBanner">×</button>
    </span>
</div>
```

- [ ] **Step 2:** In the `<script setup>` block, add:

```js
import { ref } from 'vue';
const bannerDismissed = ref(sessionStorage.getItem('google_banner_dismissed') === '1');
function dismissBanner() {
    bannerDismissed.value = true;
    sessionStorage.setItem('google_banner_dismissed', '1');
}
```

## Task 3.11 — Lang files

**Files:**
- Create: `lang/nl/google.php`
- Create: `lang/en/google.php`

- [ ] **Step 1: `lang/nl/google.php`**

```php
<?php

return [
    'connected' => 'Google Agenda gekoppeld.',
    'disconnect_started' => 'Google Agenda wordt ontkoppeld.',
    'oauth_state_mismatch' => 'Beveiligingscontrole mislukt. Probeer opnieuw.',
    'oauth_denied' => 'Toegang geweigerd.',
    'oauth_no_code' => 'Geen autorisatiecode ontvangen.',
    'oauth_token_exchange_failed' => 'Kon Google-token niet uitwisselen.',
    'oauth_no_refresh_token' => 'Google gaf geen refresh-token terug. Ontkoppel je Lavoro-toegang in je Google-account en koppel opnieuw.',
];
```

- [ ] **Step 2: `lang/en/google.php`** — same keys with English values:

```php
<?php

return [
    'connected' => 'Google Calendar connected.',
    'disconnect_started' => 'Google Calendar is being disconnected.',
    'oauth_state_mismatch' => 'Security check failed. Try again.',
    'oauth_denied' => 'Access denied.',
    'oauth_no_code' => 'No authorisation code received.',
    'oauth_token_exchange_failed' => 'Could not exchange Google token.',
    'oauth_no_refresh_token' => 'Google did not return a refresh token. Revoke Lavoro access in your Google account and reconnect.',
];
```

## Task 3.12 — Seed `google_calendar.connect` to default role

The migration in Task 1.6 created the permission row but did not attach it to any role. Decide which role should default-have this permission (likely the same role as `event.read`).

- [ ] **Step 1:** Find the role currently holding `event.read`:

```
php artisan tinker
>>> App\Models\Role::whereHas('permissions', fn ($q) => $q->where('name', 'event.read'))->pluck('name')
```

- [ ] **Step 2:** Attach `google_calendar.connect` to each returned role via the existing seeder data file (e.g. `database/seeders/data/<role>_permissions.php`), then re-run the seeder for that role. If unsure which seeder to use, ask the user.

## Phase 3 verification (manual end-to-end)

- [ ] **Step 1: Local Google Cloud project setup**

Follow the manual steps below before continuing — you cannot test Phase 3 without them.

1. Create a project at https://console.cloud.google.com.
2. APIs & Services → Library → enable **Google Calendar API**.
3. APIs & Services → OAuth consent screen → External, fill name/email, add scopes `auth/calendar`, `email`, `profile`, `openid`. Add your own Google account as a test user.
4. APIs & Services → Credentials → Create OAuth client ID → Web application. Authorized redirect URI: `http://localhost:8000/google/oauth/callback`. Save.
5. Paste Client ID + Client Secret into your `.env`.

- [ ] **Step 2: Run the app**

```
php artisan serve
php artisan queue:work
```

- [ ] **Step 3: Verify connect**

Log in, go to your profile page, click "Google Agenda koppelen", complete Google consent. Expect to be redirected back with success flash and the section showing `Gekoppeld als <your email>`.

- [ ] **Step 4: Verify status endpoint**

Browser devtools / network → expect `/api/google/integration/status` to return `{ connected: true, email: ... }`.

- [ ] **Step 5: Verify disconnect**

Click "Koppeling opheffen", confirm. Queue worker logs the `TeardownIntegrationJob`. Status endpoint now returns `{ connected: false }`.

- [ ] **Step 6: Verify disabled banner**

In tinker, simulate a refresh failure:

```
$i = App\Models\GoogleCalendarIntegration::factory()->create(['user_id' => auth()->id(), ...]);
// or after re-connecting:
$i = App\Models\User::find(1)->googleCalendarIntegration;
$i->update(['disabled_at' => now(), 'last_error' => 'test']);
```

Reload — expect amber banner in `MainLayout` and disabled state in the section.

---

### Phase 3 checkpoint

OAuth round-trip works end-to-end. No Google calendar is created yet (Phase 4). Integration rows can be created, disabled, and torn down.

---

# Phase 4 — Outbound sync (Lavoro → Google) + backfill

End state: when a user connects, a "Lavoro" Google calendar is created. All their executing events (current and future) are backfilled. Subsequent Lavoro changes (create/update/delete of events, executing user changes) automatically push to Google.

## Task 4.1 — `GoogleCalendarApi` wrapper

**Files:**
- Create: `app/Services/Google/GoogleCalendarApi.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarIntegration;
use Google\Service\Calendar;
use Google\Service\Calendar\Calendar as CalendarResource;
use Google\Service\Calendar\Channel;
use Google\Service\Calendar\Event as GoogleEvent;
use Illuminate\Support\Facades\Log;

class GoogleCalendarApi
{
    public function __construct(private GoogleClientFactory $client_factory)
    {
    }

    private function service(GoogleCalendarIntegration $integration): Calendar
    {
        return new Calendar($this->client_factory->clientFor($integration));
    }

    public function createCalendar(GoogleCalendarIntegration $integration, string $summary): CalendarResource
    {
        $cal = new CalendarResource(['summary' => $summary, 'timeZone' => 'Europe/Amsterdam']);
        return $this->retry(fn () => $this->service($integration)->calendars->insert($cal));
    }

    public function deleteCalendar(GoogleCalendarIntegration $integration, string $google_calendar_id): void
    {
        $this->retry(function () use ($integration, $google_calendar_id) {
            $this->service($integration)->calendars->delete($google_calendar_id);
        });
    }

    public function insertEvent(GoogleCalendarIntegration $integration, string $google_calendar_id, array $payload): GoogleEvent
    {
        $event = new GoogleEvent($payload);
        return $this->retry(fn () => $this->service($integration)->events->insert($google_calendar_id, $event));
    }

    public function patchEvent(GoogleCalendarIntegration $integration, string $google_calendar_id, string $google_event_id, array $payload): GoogleEvent
    {
        $event = new GoogleEvent($payload);
        return $this->retry(fn () => $this->service($integration)->events->patch($google_calendar_id, $google_event_id, $event));
    }

    public function deleteEvent(GoogleCalendarIntegration $integration, string $google_calendar_id, string $google_event_id): void
    {
        $this->retry(function () use ($integration, $google_calendar_id, $google_event_id) {
            $this->service($integration)->events->delete($google_calendar_id, $google_event_id);
        });
    }

    public function listChanges(GoogleCalendarIntegration $integration, string $google_calendar_id, ?string $sync_token, ?string $page_token = null): array
    {
        $params = ['showDeleted' => true, 'maxResults' => 250];
        if ($sync_token) {
            $params['syncToken'] = $sync_token;
        } else {
            $params['timeMin'] = now()->subDays(config('google.sync_lookback_days'))->toRfc3339String();
        }
        if ($page_token) {
            $params['pageToken'] = $page_token;
        }
        $result = $this->retry(fn () => $this->service($integration)->events->listEvents($google_calendar_id, $params));
        return [
            'items' => $result->getItems(),
            'next_page_token' => $result->getNextPageToken(),
            'next_sync_token' => $result->getNextSyncToken(),
        ];
    }

    public function watchCalendar(GoogleCalendarIntegration $integration, string $google_calendar_id, string $channel_id, string $token, string $address, int $ttl_seconds): array
    {
        $channel = new Channel([
            'id' => $channel_id,
            'type' => 'web_hook',
            'address' => $address,
            'token' => $token,
            'params' => ['ttl' => (string) $ttl_seconds],
        ]);
        $result = $this->retry(fn () => $this->service($integration)->events->watch($google_calendar_id, $channel));
        return [
            'resource_id' => $result->getResourceId(),
            'expiration' => $result->getExpiration(),
        ];
    }

    public function stopWatch(GoogleCalendarIntegration $integration, string $channel_id, string $resource_id): void
    {
        try {
            $channel = new Channel(['id' => $channel_id, 'resourceId' => $resource_id]);
            $this->service($integration)->channels->stop($channel);
        } catch (\Throwable $e) {
            Log::info('stopWatch failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    private function retry(callable $fn, int $attempts = 4)
    {
        $delay_ms = 1000;
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $fn();
            } catch (\Google\Service\Exception $e) {
                $code = $e->getCode();
                if (!in_array($code, [429, 500, 502, 503, 504], true)) {
                    throw $e;
                }
                $last = $e;
                usleep(($delay_ms + random_int(0, 250)) * 1000);
                $delay_ms *= 2;
            }
        }
        throw $last;
    }
}
```

## Task 4.2 — `EventPayloadBuilder`

**Files:**
- Create: `app/Services/Google/EventPayloadBuilder.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Services\Google;

use App\Models\Event;

class EventPayloadBuilder
{
    public function build(Event $event): array
    {
        return [
            'summary' => $event->name ?? '(geen titel)',
            'description' => $this->buildDescription($event),
            'location' => $event->location,
            'start' => $this->buildDateTime($event->start, $event->end, true),
            'end' => $this->buildDateTime($event->start, $event->end, false),
        ];
    }

    private function buildDescription(Event $event): string
    {
        $parts = [];

        if (!empty($event->description)) {
            $parts[] = $event->description;
        }

        $event->loadMissing('serviceOrders.customer');
        foreach ($event->serviceOrders as $service_order) {
            $line = 'Service order #' . $service_order->id;
            if ($service_order->customer) {
                $line .= ' — ' . $service_order->customer->name;
            }
            if (!empty($service_order->description)) {
                $line .= "\n" . $service_order->description;
            }
            $parts[] = $line;
        }

        $deep_link = url('/events/' . $event->id);
        $parts[] = "\n— Bekijk in Lavoro: " . $deep_link;

        return implode("\n\n", $parts);
    }

    private function buildDateTime(\DateTimeInterface $start, \DateTimeInterface $end, bool $is_start): array
    {
        $is_all_day = $start->format('H:i:s') === '00:00:00'
            && $end->format('H:i:s') === '00:00:00'
            && $end > $start;

        $dt = $is_start ? $start : $end;

        if ($is_all_day) {
            return ['date' => $dt->format('Y-m-d')];
        }
        return [
            'dateTime' => $dt->format(\DateTimeInterface::RFC3339),
            'timeZone' => 'Europe/Amsterdam',
        ];
    }
}
```

## Task 4.3 — `CalendarSyncService::pushEvent` + skeleton

**Files:**
- Create: `app/Services/Google/CalendarSyncService.php`

- [ ] **Step 1: Create file (the `pullChanges` method is filled in Phase 5)**

```php
<?php

namespace App\Services\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use App\Models\GoogleSyncedEvent;

class CalendarSyncService
{
    public function __construct(
        private GoogleCalendarApi $api,
        private EventPayloadBuilder $payload_builder,
    ) {
    }

    public function pushEvent(Event $event, GoogleSyncedCalendar $cal): void
    {
        $payload = $this->payload_builder->build($event);
        $mapping = GoogleSyncedEvent::where('google_synced_calendar_id', $cal->id)
            ->where('event_id', $event->id)
            ->first();

        $integration = $cal->integration;

        if ($mapping) {
            $result = $this->api->patchEvent($integration, $cal->google_calendar_id, $mapping->google_event_id, $payload);
            $mapping->update([
                'etag' => $result->getEtag(),
                'last_pushed_at' => now(),
            ]);
            return;
        }

        $result = $this->api->insertEvent($integration, $cal->google_calendar_id, $payload);
        GoogleSyncedEvent::create([
            'google_synced_calendar_id' => $cal->id,
            'event_id' => $event->id,
            'google_event_id' => $result->getId(),
            'etag' => $result->getEtag(),
            'last_pushed_at' => now(),
        ]);
    }

    public function deleteEvent(GoogleSyncedEvent $mapping): void
    {
        $cal = $mapping->syncedCalendar;
        $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $mapping->google_event_id);
        $mapping->delete();
    }
}
```

## Task 4.4 — `PushEventToCalendarJob`

**Files:**
- Create: `app/Jobs/Google/PushEventToCalendarJob.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Jobs\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use App\Services\Google\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushEventToCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 1800, 3600, 7200];

    public function __construct(public int $event_id, public int $google_synced_calendar_id)
    {
    }

    public function handle(CalendarSyncService $sync): void
    {
        $event = Event::find($this->event_id);
        $cal = GoogleSyncedCalendar::find($this->google_synced_calendar_id);
        if (!$event || !$cal) {
            return;
        }
        if ($cal->integration?->isDisabled()) {
            return;
        }
        $sync->pushEvent($event, $cal);

        $integration = $cal->integration;
        if ($integration->backfill_total !== null && ($integration->backfill_done ?? 0) < $integration->backfill_total) {
            $integration->increment('backfill_done');
        }
    }
}
```

## Task 4.5 — `PushEventJob` (fan-out)

**Files:**
- Create: `app/Jobs/Google/PushEventJob.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Jobs\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $event_id)
    {
    }

    public function handle(): void
    {
        $event = Event::find($this->event_id);
        if (!$event) {
            return;
        }

        $owner_ids = $event->owners()->wherePivot('type', 'owner')->pluck('users.id')->all();
        $executing_ids = $event->executingUsers()->pluck('users.id')->all();
        $relevant_user_ids = array_unique(array_merge($owner_ids, $executing_ids));

        if (empty($relevant_user_ids)) {
            return;
        }

        $synced_calendars = GoogleSyncedCalendar::whereIn('owner_user_id', $relevant_user_ids)
            ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->get();

        foreach ($synced_calendars as $cal) {
            PushEventToCalendarJob::dispatch($event->id, $cal->id);
        }
    }
}
```

## Task 4.6 — `DeleteEventFromGoogleJob`

**Files:**
- Create: `app/Jobs/Google/DeleteEventFromGoogleJob.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedEvent;
use App\Services\Google\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteEventFromGoogleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 1800, 3600, 7200];

    public function __construct(public int $google_synced_event_id)
    {
    }

    public function handle(CalendarSyncService $sync): void
    {
        $mapping = GoogleSyncedEvent::find($this->google_synced_event_id);
        if (!$mapping) {
            return;
        }
        if ($mapping->syncedCalendar?->integration?->isDisabled()) {
            return;
        }
        $sync->deleteEvent($mapping);
    }
}
```

## Task 4.7 — `BackfillCalendarJob`

**Files:**
- Create: `app/Jobs/Google/BackfillCalendarJob.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Jobs\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use App\Services\Google\GoogleCalendarApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BackfillCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $google_synced_calendar_id)
    {
    }

    public function handle(GoogleCalendarApi $api): void
    {
        $cal = GoogleSyncedCalendar::find($this->google_synced_calendar_id);
        if (!$cal || $cal->integration->isDisabled()) {
            return;
        }

        $owner_id = $cal->owner_user_id;
        $lookback = now()->subDays(config('google.sync_lookback_days'));

        $event_ids = Event::whereHas('executingUsers', fn ($q) => $q->where('users.id', $owner_id))
            ->where('end', '>=', $lookback)
            ->orderBy('start')
            ->pluck('id');

        $integration = $cal->integration;
        $integration->update([
            'backfill_total' => $event_ids->count(),
            'backfill_done' => 0,
        ]);

        foreach ($event_ids as $event_id) {
            PushEventToCalendarJob::dispatch($event_id, $cal->id);
        }

        // Phase 6 will register the webhook channel here when config('google.webhook_enabled') is true.
    }
}
```

Note: `backfill_done` is incremented inside `PushEventToCalendarJob` (Task 4.4 already includes that increment).

## Task 4.8 — Wire backfill into OAuth callback + connect creates the calendar

**Files:**
- Modify: `app/Http/Controllers/GoogleOAuthController.php`

- [ ] **Step 1:** Replace the comment line `// Phase 4 will dispatch the BackfillCalendarJob here.` with:

```php
$service_api = app(\App\Services\Google\GoogleCalendarApi::class);
$google_cal = $service_api->createCalendar($integration, config('google.calendar_summary_own'));

$synced_cal = \App\Models\GoogleSyncedCalendar::create([
    'google_calendar_integration_id' => $integration->id,
    'owner_user_id' => $user->id,
    'google_calendar_id' => $google_cal->getId(),
    'summary' => $google_cal->getSummary(),
]);

\App\Jobs\Google\BackfillCalendarJob::dispatch($synced_cal->id);
```

## Task 4.9 — Extend `TeardownIntegrationJob` to delete calendars

**Files:**
- Modify: `app/Jobs/Google/TeardownIntegrationJob.php`

- [ ] **Step 1:** Before the `$this->revokeRefreshToken(...)` line in `handle()`, add:

```php
foreach ($integration->syncedCalendars as $cal) {
    try {
        app(\App\Services\Google\GoogleCalendarApi::class)
            ->deleteCalendar($integration, $cal->google_calendar_id);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('deleteCalendar failed during teardown', [
            'cal_id' => $cal->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

## Task 4.10 — `EventObserver`

**Files:**
- Create: `app/Observers/EventObserver.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Observers;

use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Models\Event;
use App\Models\GoogleSyncedEvent;

class EventObserver
{
    public function created(Event $event): void
    {
        PushEventJob::dispatch($event->id);
    }

    public function updated(Event $event): void
    {
        PushEventJob::dispatch($event->id);
    }

    public function deleted(Event $event): void
    {
        $mappings = GoogleSyncedEvent::where('event_id', $event->id)->get();
        foreach ($mappings as $mapping) {
            DeleteEventFromGoogleJob::dispatch($mapping->id);
        }
    }

    public function restored(Event $event): void
    {
        PushEventJob::dispatch($event->id);
    }
}
```

## Task 4.11 — Register observer

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1:** In `boot()`, after the policy registrations, add:

```php
Event::observe(\App\Observers\EventObserver::class);
```

(Make sure `use App\Models\Event;` is present at the top — likely already there from Task 1.15.)

## Task 4.12 — Listener for pivot changes on `executingUsers` / `owners`

The morphToMany pivot doesn't fire model observers, but Eloquent emits `eloquent.attached: App\Models\Event` etc. on the central event bus.

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1:** In `boot()`, add:

```php
\Illuminate\Support\Facades\Event::listen('eloquent.attached: App\Models\Event', function ($event_class, $payload) {
    [$model, $relation, $ids] = $payload + [null, null, []];
    if (!$model instanceof \App\Models\Event) return;
    \App\Jobs\Google\PushEventJob::dispatch($model->id);
});

\Illuminate\Support\Facades\Event::listen('eloquent.detached: App\Models\Event', function ($event_class, $payload) {
    [$model, $relation, $ids] = $payload + [null, null, []];
    if (!$model instanceof \App\Models\Event) return;
    // Re-fan-out: push to remaining relevant calendars, delete from no-longer-relevant ones.
    \App\Jobs\Google\PushEventJob::dispatch($model->id);
    // Detect calendars that should no longer hold this event:
    $event_id = $model->id;
    $still_relevant_user_ids = array_unique(array_merge(
        $model->owners()->wherePivot('type', 'owner')->pluck('users.id')->all(),
        $model->executingUsers()->pluck('users.id')->all(),
    ));
    $stale_mappings = \App\Models\GoogleSyncedEvent::whereHas('syncedCalendar', function ($q) use ($still_relevant_user_ids) {
        $q->whereNotIn('owner_user_id', $still_relevant_user_ids);
    })->where('event_id', $event_id)->get();
    foreach ($stale_mappings as $mapping) {
        \App\Jobs\Google\DeleteEventFromGoogleJob::dispatch($mapping->id);
    }
});
```

The "still relevant" calculation handles the case where a user is removed from executing — we delete the event from their calendar.

## Phase 4 verification (manual end-to-end)

- [ ] **Step 1:** Run `php artisan serve` + `php artisan queue:work`.
- [ ] **Step 2:** Disconnect any previous integration (Phase 3) and reconnect.
- [ ] **Step 3:** In your Google Calendar, expect a new calendar named **"Lavoro"** to appear within ~10s.
- [ ] **Step 4:** In tinker, attach yourself as executing user to an existing event:

```
$event = App\Models\Event::first();
$event->addExecutingUser(auth()->id());
```

Expect the event to appear in the "Lavoro" Google calendar within ~10s.

- [ ] **Step 5:** Update the event's name / time in Lavoro UI. Expect Google to reflect the change.
- [ ] **Step 6:** Delete the event in Lavoro UI. Expect it to disappear from Google.
- [ ] **Step 7:** Detach yourself from a still-existing event:

```
$event->executingUsers()->detach(auth()->id());
```

Expect it to disappear from your Google "Lavoro" calendar.

- [ ] **Step 8:** Disconnect via UI; expect the Google "Lavoro" calendar to be deleted.

If any step fails, check `php artisan queue:listen` output and `storage/logs/laravel.log`.

---

### Phase 4 checkpoint

Outbound sync works. Google-side changes are not yet processed (next phase).

---

# Phase 5 — Polling pull sync (Google → Lavoro)

End state: changes you make in Google Calendar (move event, change title, delete event, create new event) get evaluated and either applied to Lavoro or reverted in Google. Detection is via a 5-minute polling job using `syncToken`.

## Task 5.1 — `IncomingChangeHandler`

**Files:**
- Create: `app/Services/Google/IncomingChangeHandler.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Services\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use App\Models\GoogleSyncedEvent;
use App\Models\User;
use Carbon\Carbon;
use Google\Service\Calendar\Event as GoogleEvent;
use Illuminate\Support\Facades\Log;

class IncomingChangeHandler
{
    public function __construct(
        private GoogleCalendarApi $api,
        private EventPayloadBuilder $payload_builder,
    ) {
    }

    public function apply(GoogleEvent $g_event, GoogleSyncedCalendar $cal): void
    {
        $actor = $cal->integration->user;
        $mapping = GoogleSyncedEvent::where('google_synced_calendar_id', $cal->id)
            ->where('google_event_id', $g_event->getId())
            ->first();

        if ($mapping && $mapping->etag === $g_event->getEtag()) {
            return;
        }

        if ($mapping) {
            $this->applyToExisting($g_event, $cal, $mapping, $actor);
        } else {
            $this->applyToNew($g_event, $cal, $actor);
        }
    }

    private function applyToExisting(GoogleEvent $g_event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        $event = Event::withTrashed()->find($mapping->event_id);
        if (!$event) {
            $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $g_event->getId());
            $mapping->delete();
            return;
        }

        if ($g_event->getStatus() === 'cancelled') {
            $this->handleDeletion($event, $cal, $mapping, $actor);
            return;
        }

        $changed = $this->detectFieldChanges($event, $g_event);

        $this->processTimeChange($changed, $event, $cal, $mapping, $actor);
        $this->processTextChanges($changed, $event, $cal, $mapping, $actor);
    }

    private function handleDeletion(Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        if ($actor->can('delete', $event)) {
            $event->delete();
            return;
        }
        // Re-insert in Google.
        $payload = $this->payload_builder->build($event);
        $result = $this->api->insertEvent($cal->integration, $cal->google_calendar_id, $payload);
        $mapping->update([
            'google_event_id' => $result->getId(),
            'etag' => $result->getEtag(),
            'last_pushed_at' => now(),
        ]);
        $this->logRevert($event, $actor, 'delete');
    }

    private function processTimeChange(array $changed, Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        if (!$changed['time']) {
            return;
        }
        if ($actor->can('update', $event)) {
            $event->update([
                'start' => $changed['new_start'],
                'end' => $changed['new_end'],
            ]);
            return;
        }
        $this->correctivePush($event, $cal, $mapping);
        $this->logRevert($event, $actor, 'time');
    }

    private function processTextChanges(array $changed, Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        if (!$changed['name'] && !$changed['description'] && !$changed['location']) {
            return;
        }

        if ($event->origin === 'lavoro') {
            $this->correctivePush($event, $cal, $mapping);
            $this->logRevert($event, $actor, 'text-lavoro-origin');
            return;
        }

        if (!$actor->can('update', $event)) {
            $this->correctivePush($event, $cal, $mapping);
            $this->logRevert($event, $actor, 'text-unauthorized');
            return;
        }

        $event->update([
            'name' => $changed['new_name'],
            'description' => $changed['new_description'],
            'location' => $changed['new_location'],
        ]);
    }

    private function applyToNew(GoogleEvent $g_event, GoogleSyncedCalendar $cal, User $actor): void
    {
        if ($g_event->getStatus() === 'cancelled') {
            return;
        }

        $owner_user = User::find($cal->owner_user_id);

        $authorized = $actor->isAdmin()
            || ($owner_user->id === $actor->id && $actor->hasPermission('event.create'))
            || $actor->hasPermission('event.create_others');

        if (!$authorized) {
            $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $g_event->getId());
            Log::info('Refused incoming new Google event (no permission)', [
                'cal_id' => $cal->id,
                'actor_id' => $actor->id,
            ]);
            return;
        }

        $start = Carbon::parse($g_event->getStart()->getDateTime() ?? ($g_event->getStart()->getDate() . ' 00:00:00'));
        $end = Carbon::parse($g_event->getEnd()->getDateTime() ?? ($g_event->getEnd()->getDate() . ' 00:00:00'));

        $event = Event::create([
            'name' => $g_event->getSummary() ?? '(geen titel)',
            'description' => $g_event->getDescription(),
            'location' => $g_event->getLocation(),
            'start' => $start,
            'end' => $end,
            'status' => 'Gepland',
            'event_type_id' => config('google.default_event_type_id') ?: \App\Models\EventType::first()->id,
            'origin' => 'google',
        ]);

        $event->addExecutingUser($owner_user->id);

        GoogleSyncedEvent::create([
            'google_synced_calendar_id' => $cal->id,
            'event_id' => $event->id,
            'google_event_id' => $g_event->getId(),
            'etag' => $g_event->getEtag(),
            'last_pushed_at' => now(),
        ]);
    }

    private function detectFieldChanges(Event $event, GoogleEvent $g_event): array
    {
        $new_start = Carbon::parse($g_event->getStart()->getDateTime() ?? ($g_event->getStart()->getDate() . ' 00:00:00'));
        $new_end = Carbon::parse($g_event->getEnd()->getDateTime() ?? ($g_event->getEnd()->getDate() . ' 00:00:00'));
        $new_name = $g_event->getSummary();
        $new_description = $g_event->getDescription();
        $new_location = $g_event->getLocation();

        return [
            'time' => !$event->start->equalTo($new_start) || !$event->end->equalTo($new_end),
            'name' => $event->name !== $new_name,
            'description' => $event->description !== $new_description,
            'location' => $event->location !== $new_location,
            'new_start' => $new_start,
            'new_end' => $new_end,
            'new_name' => $new_name,
            'new_description' => $new_description,
            'new_location' => $new_location,
        ];
    }

    private function correctivePush(Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping): void
    {
        $payload = $this->payload_builder->build($event);
        $result = $this->api->patchEvent($cal->integration, $cal->google_calendar_id, $mapping->google_event_id, $payload);
        $mapping->update([
            'etag' => $result->getEtag(),
            'last_pushed_at' => now(),
        ]);
    }

    private function logRevert(Event $event, User $actor, string $kind): void
    {
        Log::info('Google sync: corrective push applied', [
            'event_id' => $event->id,
            'actor_id' => $actor->id,
            'kind' => $kind,
        ]);
        // The codebase has HasActivities; if Event uses it (it doesn't currently),
        // record an Activity here. For now Log only.
    }
}
```

## Task 5.2 — Extend `CalendarSyncService::pullChanges`

**Files:**
- Modify: `app/Services/Google/CalendarSyncService.php`

- [ ] **Step 1:** Add the `IncomingChangeHandler` to the constructor and add a `pullChanges` method.

Replace the constructor signature:

```php
    public function __construct(
        private GoogleCalendarApi $api,
        private EventPayloadBuilder $payload_builder,
        private IncomingChangeHandler $incoming_handler,
    ) {
    }
```

- [ ] **Step 2:** Add this method to the class:

```php
    public function pullChanges(GoogleSyncedCalendar $cal): void
    {
        $integration = $cal->integration;
        $page_token = null;
        $sync_token = $cal->sync_token;
        $next_sync_token = null;

        try {
            do {
                $result = $this->api->listChanges($integration, $cal->google_calendar_id, $sync_token, $page_token);
                foreach ($result['items'] as $g_event) {
                    $this->incoming_handler->apply($g_event, $cal);
                }
                $page_token = $result['next_page_token'];
                $next_sync_token = $result['next_sync_token'] ?? $next_sync_token;
                $sync_token = null;
            } while ($page_token);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() === 410) {
                $cal->update(['sync_token' => null]);
                $this->pullChanges($cal);
                return;
            }
            throw $e;
        }

        if ($next_sync_token) {
            $cal->update([
                'sync_token' => $next_sync_token,
                'last_full_sync_at' => now(),
            ]);
        }
    }
```

(Add `use App\Models\GoogleSyncedCalendar;` if not already imported.)

## Task 5.3 — `PullCalendarChangesJob`

**Files:**
- Create: `app/Jobs/Google/PullCalendarChangesJob.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use App\Services\Google\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PullCalendarChangesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $google_synced_calendar_id)
    {
    }

    public function handle(CalendarSyncService $sync): void
    {
        $cal = GoogleSyncedCalendar::find($this->google_synced_calendar_id);
        if (!$cal || $cal->integration->isDisabled()) {
            return;
        }
        $sync->pullChanges($cal);
    }
}
```

## Task 5.4 — Scheduled poll

**Files:**
- Modify: `routes/console.php`

- [ ] **Step 1:** Append to the file:

```php
use Illuminate\Support\Facades\Schedule;
use App\Models\GoogleSyncedCalendar;
use App\Jobs\Google\PullCalendarChangesJob;

Schedule::call(function () {
    GoogleSyncedCalendar::query()
        ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
        ->pluck('id')
        ->each(fn ($id) => PullCalendarChangesJob::dispatch($id));
})->everyFiveMinutes()->name('google-pull-changes')->withoutOverlapping();
```

## Phase 5 verification (manual end-to-end)

- [ ] **Step 1:** Start `php artisan serve`, `php artisan queue:work`, and `php artisan schedule:work`.
- [ ] **Step 2:** With your integration connected and an event in the Lavoro calendar, move the event by 1 hour in Google Calendar.
- [ ] **Step 3 (no update permission):** Verify Google reverts the move within ~5 min (check `storage/logs/laravel.log` for "corrective push applied" with kind=`time`).
- [ ] **Step 4 (with permission):** In tinker grant yourself `event.update`:

```
$role = auth()->user()->roles()->first();
$role->permissions()->syncWithoutDetaching(App\Models\Permission::where('name', 'event.update')->pluck('id'));
```

Then move the event again — verify the move sticks in both Google and Lavoro.

- [ ] **Step 5 (description revert):** Edit the description text in Google. Verify it reverts within ~5 min (kind=`text-lavoro-origin`).
- [ ] **Step 6 (delete reject):** Delete the event in Google (you don't yet have `event.delete`). Verify it reappears in Google.
- [ ] **Step 7 (delete accept):** Grant yourself `event.delete`, delete in Google, verify Lavoro soft-deletes it.
- [ ] **Step 8 (Google-origin new event):** Grant yourself `event.create`. Create a new event directly in the "Lavoro" Google calendar. Verify a corresponding Lavoro `Event` appears with `origin = 'google'`, owner = you, executing user = you.

---

### Phase 5 checkpoint

Two-way sync works via polling. Latency is ~5 min. Phase 6 adds near-real-time.

---

# Phase 6 — Webhooks (push notifications)

End state: when webhooks are enabled (`GOOGLE_WEBHOOK_ENABLED=true` + verified public HTTPS URL), incoming changes propagate to Lavoro within seconds. Polling remains as fallback.

## Task 6.1 — Add watchCalendar call to `BackfillCalendarJob`

**Files:**
- Modify: `app/Jobs/Google/BackfillCalendarJob.php`

- [ ] **Step 1:** After the `foreach ($event_ids ...)` loop, add:

```php
if (config('google.webhook_enabled')) {
    $this->registerWatch($cal, $api);
}
```

- [ ] **Step 2:** Add the private helper method to the class:

```php
private function registerWatch(\App\Models\GoogleSyncedCalendar $cal, GoogleCalendarApi $api): void
{
    $channel_id = (string) \Illuminate\Support\Str::uuid();
    $token = \Illuminate\Support\Str::random(40);
    $ttl = 7 * 24 * 60 * 60;

    $result = $api->watchCalendar(
        $cal->integration,
        $cal->google_calendar_id,
        $channel_id,
        $token,
        config('google.webhook_url'),
        $ttl
    );

    $cal->update([
        'watch_channel_id' => $channel_id,
        'watch_channel_token' => $token,
        'watch_resource_id' => $result['resource_id'],
        'watch_expires_at' => \Carbon\Carbon::createFromTimestampMs($result['expiration']),
    ]);
}
```

## Task 6.2 — `GoogleWebhookController`

**Files:**
- Create: `app/Http/Controllers/GoogleWebhookController.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\Google\PullCalendarChangesJob;
use App\Models\GoogleSyncedCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GoogleWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $channel_id = $request->header('X-Goog-Channel-Id');
        $channel_token = $request->header('X-Goog-Channel-Token');
        $resource_id = $request->header('X-Goog-Resource-Id');
        $state = $request->header('X-Goog-Resource-State');

        if (!$channel_id || !$resource_id) {
            return response('Bad Request', 400);
        }

        $cal = GoogleSyncedCalendar::where('watch_channel_id', $channel_id)->first();
        if (!$cal) {
            return response('Unknown channel', 404);
        }

        if (!hash_equals((string) $cal->watch_channel_token, (string) $channel_token)) {
            return response('Forbidden', 403);
        }

        if ((string) $cal->watch_resource_id !== (string) $resource_id) {
            return response('Forbidden', 403);
        }

        if ($state === 'sync') {
            return response('OK', 200);
        }

        PullCalendarChangesJob::dispatch($cal->id);
        return response('OK', 200);
    }
}
```

## Task 6.3 — Webhook route + CSRF exclusion

**Files:**
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1:** In `routes/web.php`, OUTSIDE the `auth` middleware group (at the bottom of the file before any closing block), add:

```php
Route::post('google/webhook', [\App\Http\Controllers\GoogleWebhookController::class, 'handle'])
    ->name('google.webhook')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('throttle:60,1');
```

- [ ] **Step 2:** In `bootstrap/app.php`, locate the `->withMiddleware(...)` block. Add this configuration to exclude the webhook from CSRF:

```php
->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware) {
    // existing alias/group config stays as-is
    $middleware->validateCsrfTokens(except: ['google/webhook']);
})
```

If `validateCsrfTokens(except: ...)` is already configured, just append `'google/webhook'` to the array.

## Task 6.4 — `RenewWatchChannelsJob`

**Files:**
- Create: `app/Jobs/Google/RenewWatchChannelsJob.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use App\Services\Google\GoogleCalendarApi;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RenewWatchChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(GoogleCalendarApi $api): void
    {
        if (!config('google.webhook_enabled')) {
            return;
        }

        $threshold = now()->addHours(24);
        $candidates = GoogleSyncedCalendar::query()
            ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->where(function ($q) use ($threshold) {
                $q->whereNull('watch_expires_at')->orWhere('watch_expires_at', '<=', $threshold);
            })
            ->get();

        foreach ($candidates as $cal) {
            if ($cal->watch_channel_id && $cal->watch_resource_id) {
                $api->stopWatch($cal->integration, $cal->watch_channel_id, $cal->watch_resource_id);
            }

            $channel_id = (string) Str::uuid();
            $token = Str::random(40);
            $ttl = 7 * 24 * 60 * 60;
            $result = $api->watchCalendar(
                $cal->integration,
                $cal->google_calendar_id,
                $channel_id,
                $token,
                config('google.webhook_url'),
                $ttl
            );
            $cal->update([
                'watch_channel_id' => $channel_id,
                'watch_channel_token' => $token,
                'watch_resource_id' => $result['resource_id'],
                'watch_expires_at' => Carbon::createFromTimestampMs($result['expiration']),
            ]);
        }
    }
}
```

## Task 6.5 — Schedule channel renewal + extend teardown

**Files:**
- Modify: `routes/console.php`
- Modify: `app/Jobs/Google/TeardownIntegrationJob.php`

- [ ] **Step 1:** Append to `routes/console.php`:

```php
Schedule::job(new \App\Jobs\Google\RenewWatchChannelsJob())->hourly()->name('google-renew-watches')->withoutOverlapping();
```

- [ ] **Step 2:** In `TeardownIntegrationJob::handle()`, before the `foreach ($integration->syncedCalendars ...)` loop that deletes Google calendars, add:

```php
foreach ($integration->syncedCalendars as $cal) {
    if ($cal->watch_channel_id && $cal->watch_resource_id) {
        try {
            app(\App\Services\Google\GoogleCalendarApi::class)
                ->stopWatch($integration, $cal->watch_channel_id, $cal->watch_resource_id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('stopWatch failed during teardown', ['error' => $e->getMessage()]);
        }
    }
}
```

## Phase 6 verification (requires ngrok or similar)

- [ ] **Step 1:** Run `ngrok http 8000` and note the HTTPS URL.
- [ ] **Step 2:** Verify the ngrok domain in Google Search Console (DNS TXT method or HTML file method).
- [ ] **Step 3:** Add it under APIs & Services → Domain verification.
- [ ] **Step 4:** Update `.env`:

```
GOOGLE_OAUTH_REDIRECT_URI=https://<your-ngrok-domain>/google/oauth/callback
GOOGLE_WEBHOOK_ENABLED=true
GOOGLE_WEBHOOK_URL=https://<your-ngrok-domain>/google/webhook
```

Also update the redirect URI in your OAuth client in Google Cloud Console to match.

- [ ] **Step 5:** Run `php artisan config:clear` then restart serve + queue.
- [ ] **Step 6:** Disconnect, reconnect.
- [ ] **Step 7:** Move an event in Google Calendar — expect the corrective push (or accept, depending on permissions) within ~10s instead of ~5 min.
- [ ] **Step 8:** Check `storage/logs/laravel.log` for incoming webhook hits.

---

### Phase 6 checkpoint

Webhooks deliver near-real-time updates. Polling continues as fallback.

---

# Phase 7 — Cross-user calendar grants

End state: admins can grant Joe access to Peter's calendar. Joe sees a second calendar "Peter — Lavoro" in his Google account with Peter's executing events. Joe can edit those events according to his Lavoro permissions.

## Task 7.1 — `GrantSyncService`

**Files:**
- Create: `app/Services/Google/GrantSyncService.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Services\Google;

use App\Jobs\Google\BackfillCalendarJob;
use App\Models\CalendarGrant;
use App\Models\GoogleSyncedCalendar;
use Illuminate\Support\Facades\Log;

class GrantSyncService
{
    public function __construct(private GoogleCalendarApi $api)
    {
    }

    public function onGrantCreated(CalendarGrant $grant): void
    {
        $viewer = $grant->viewerUser;
        $owner = $grant->ownerUser;
        $integration = $viewer->googleCalendarIntegration;

        if (!$integration || $integration->isDisabled()) {
            return;
        }

        $existing = GoogleSyncedCalendar::where('google_calendar_integration_id', $integration->id)
            ->where('owner_user_id', $owner->id)
            ->first();
        if ($existing) {
            return;
        }

        $summary = str_replace(':name', $owner->name, config('google.calendar_summary_granted_template'));
        $google_cal = $this->api->createCalendar($integration, $summary);

        $cal = GoogleSyncedCalendar::create([
            'google_calendar_integration_id' => $integration->id,
            'owner_user_id' => $owner->id,
            'google_calendar_id' => $google_cal->getId(),
            'summary' => $google_cal->getSummary(),
        ]);

        BackfillCalendarJob::dispatch($cal->id);
    }

    public function onGrantRevoked(CalendarGrant $grant): void
    {
        $viewer = $grant->viewerUser;
        $owner = $grant->ownerUser;
        $integration = $viewer->googleCalendarIntegration;

        if (!$integration) {
            return;
        }

        $cal = GoogleSyncedCalendar::where('google_calendar_integration_id', $integration->id)
            ->where('owner_user_id', $owner->id)
            ->first();
        if (!$cal) {
            return;
        }

        if ($cal->watch_channel_id && $cal->watch_resource_id) {
            $this->api->stopWatch($integration, $cal->watch_channel_id, $cal->watch_resource_id);
        }

        try {
            $this->api->deleteCalendar($integration, $cal->google_calendar_id);
        } catch (\Throwable $e) {
            Log::warning('deleteCalendar failed during grant revoke', ['error' => $e->getMessage()]);
        }

        $cal->delete();
    }
}
```

## Task 7.2 — Hook backfill flow to also process pre-existing grants on first connect

**Files:**
- Modify: `app/Http/Controllers/GoogleOAuthController.php`

- [ ] **Step 1:** After the `BackfillCalendarJob::dispatch($synced_cal->id);` line added in Task 4.8, add:

```php
$grants_received = \App\Models\CalendarGrant::where('viewer_user_id', $user->id)->get();
$grant_service = app(\App\Services\Google\GrantSyncService::class);
foreach ($grants_received as $grant) {
    $grant_service->onGrantCreated($grant);
}
```

## Task 7.3 — Form Requests

**Files:**
- Create: `app/Http/Requests/CalendarGrantStoreRequest.php`
- Create: `app/Http/Requests/CalendarGrantDestroyRequest.php`

- [ ] **Step 1: `CalendarGrantStoreRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Models\CalendarGrant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CalendarGrantStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', CalendarGrant::class);
    }

    public function rules(): array
    {
        return [
            'owner_user_id' => 'required|integer|exists:users,id',
            'viewer_user_id' => 'required|integer|exists:users,id|different:owner_user_id',
        ];
    }
}
```

- [ ] **Step 2: `CalendarGrantDestroyRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Models\CalendarGrant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CalendarGrantDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', CalendarGrant::class);
    }

    public function rules(): array
    {
        return [];
    }
}
```

## Task 7.4 — `CalendarGrantController`

**Files:**
- Create: `app/Http/Controllers/Admin/CalendarGrantController.php`

- [ ] **Step 1: Create file**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarGrantDestroyRequest;
use App\Http\Requests\CalendarGrantStoreRequest;
use App\Models\CalendarGrant;
use App\Models\User;
use App\Services\Google\GrantSyncService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CalendarGrantController extends Controller
{
    public function index(): Response
    {
        $users = User::orderBy('name')->get(['id', 'name']);
        $grants = CalendarGrant::with(['ownerUser:id,name', 'viewerUser:id,name'])->get();
        return Inertia::render('Admin/CalendarGrants/IndexPage', [
            'users' => $users,
            'grants' => $grants,
        ]);
    }

    public function store(CalendarGrantStoreRequest $request, GrantSyncService $grant_service): RedirectResponse
    {
        $data = $request->validated();
        $grant = CalendarGrant::firstOrCreate([
            'owner_user_id' => $data['owner_user_id'],
            'viewer_user_id' => $data['viewer_user_id'],
        ]);
        if ($grant->wasRecentlyCreated) {
            $grant_service->onGrantCreated($grant);
        }
        return redirect()->back()->with('success', 'Toegang verleend.');
    }

    public function destroy(CalendarGrantDestroyRequest $request, CalendarGrant $calendar_grant, GrantSyncService $grant_service): RedirectResponse
    {
        $grant_service->onGrantRevoked($calendar_grant);
        $calendar_grant->delete();
        return redirect()->back()->with('success', 'Toegang ingetrokken.');
    }
}
```

## Task 7.5 — Admin routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1:** Inside the existing `Route::middleware('admin')->group(function () {...})` block, add:

```php
Route::get('admin/calendar-grants', [\App\Http\Controllers\Admin\CalendarGrantController::class, 'index'])
    ->name('admin.calendar-grants.index');
Route::post('admin/calendar-grants', [\App\Http\Controllers\Admin\CalendarGrantController::class, 'store'])
    ->name('admin.calendar-grants.store');
Route::delete('admin/calendar-grants/{calendar_grant}', [\App\Http\Controllers\Admin\CalendarGrantController::class, 'destroy'])
    ->name('admin.calendar-grants.destroy');
```

## Task 7.6 — Admin UI

**Files:**
- Create: `resources/js/Pages/Admin/CalendarGrants/IndexPage.vue`

- [ ] **Step 1: Create file**

```vue
<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue';

const props = defineProps({
    users: { type: Array, required: true },
    grants: { type: Array, required: true },
});

const selected_user_id = ref(props.users[0]?.id ?? null);

const selected_user = computed(() => props.users.find((u) => u.id === selected_user_id.value));

const viewers_for_selected = computed(() =>
    props.grants
        .filter((g) => g.owner_user_id === selected_user_id.value)
        .map((g) => ({ grant_id: g.id, user: g.viewer_user }))
);

const grant_count_by_user = computed(() => {
    const map = {};
    for (const g of props.grants) {
        map[g.owner_user_id] = (map[g.owner_user_id] ?? 0) + 1;
    }
    return map;
});

const available_to_add = computed(() => {
    const granted_ids = new Set(viewers_for_selected.value.map((v) => v.user.id));
    return props.users.filter((u) => u.id !== selected_user_id.value && !granted_ids.has(u.id));
});

function addViewer(viewer_user_id) {
    router.post('/admin/calendar-grants', {
        owner_user_id: selected_user_id.value,
        viewer_user_id,
    }, { preserveScroll: true });
}

function removeViewer(grant_id) {
    if (!confirm('Toegang intrekken?')) return;
    router.delete(`/admin/calendar-grants/${grant_id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="flex gap-6 p-6">
        <div class="w-1/3">
            <h2 class="mb-2 text-lg font-medium">Gebruikers</h2>
            <ul class="rounded border bg-white">
                <li
                    v-for="user in users"
                    :key="user.id"
                    class="flex cursor-pointer items-center justify-between border-b px-3 py-2 last:border-b-0"
                    :class="{ 'bg-blue-50': user.id === selected_user_id }"
                    @click="selected_user_id = user.id"
                >
                    <span>{{ user.name }}</span>
                    <span v-if="grant_count_by_user[user.id]" class="rounded bg-gray-200 px-2 text-xs">
                        {{ grant_count_by_user[user.id] }}
                    </span>
                </li>
            </ul>
        </div>

        <div class="flex-1">
            <h2 class="mb-2 text-lg font-medium">
                Wie kan de agenda van {{ selected_user?.name }} zien?
            </h2>

            <ul class="mb-4 rounded border bg-white">
                <li v-if="viewers_for_selected.length === 0" class="px-3 py-2 text-sm text-gray-500">
                    Niemand
                </li>
                <li
                    v-for="viewer in viewers_for_selected"
                    :key="viewer.grant_id"
                    class="cursor-pointer border-b px-3 py-2 last:border-b-0 hover:bg-red-50"
                    title="Klik om toegang in te trekken"
                    @click="removeViewer(viewer.grant_id)"
                >
                    {{ viewer.user.name }}
                </li>
            </ul>

            <div>
                <h3 class="mb-1 text-sm font-medium">Voeg gebruiker toe</h3>
                <SelectMenuComponent
                    :options="available_to_add.map((u) => ({ id: u.id, label: u.name }))"
                    placeholder="Kies gebruiker"
                    @select="addViewer($event.id)"
                />
            </div>
        </div>
    </div>
</template>
```

(Adjust `SelectMenuComponent` props/events to match the existing component's API — inspect `resources/js/Components/UI/SelectMenuComponent.vue` for its actual `defineProps` and `defineEmits`.)

## Task 7.7 — Navigation link

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue` (or wherever admin nav lives)

- [ ] **Step 1:** Locate where admin-only nav items are defined (search for `isAdmin` or `admin` in the nav array). Add a child entry under an appropriate parent (e.g. "Beheer"):

```js
{ name: 'Agenda-toegang', href: '/admin/calendar-grants', adminOnly: true }
```

Match the existing nav object shape exactly.

## Phase 7 verification

- [ ] **Step 1:** Sign in as admin. Navigate to `/admin/calendar-grants`.
- [ ] **Step 2:** Select user "Peter" (must be a user with `executingUsers` events). Add "Joe" as a viewer. Verify success flash.
- [ ] **Step 3:** As Joe (already connected to Google), wait ~10s and refresh Google Calendar — expect a new "Peter — Lavoro" calendar with Peter's events.
- [ ] **Step 4:** Without `event.update`, Joe drags one of Peter's events in Google — verify it reverts.
- [ ] **Step 5:** Grant Joe `event.update_others`; verify drag now persists in Lavoro.
- [ ] **Step 6:** Joe creates a new event in "Peter — Lavoro" with `event.create_others` — verify a Lavoro event is created with owner = Peter, executing user = Peter.
- [ ] **Step 7:** Admin removes the grant. Verify "Peter — Lavoro" disappears from Joe's Google account.

---

### Phase 7 checkpoint

All functional requirements complete.

---

# Phase 8 — Documentation

## Task 8.1 — Setup guide

**Files:**
- Create: `docs/google-calendar-setup.md`

- [ ] **Step 1: Create file**

````markdown
# Google Calendar — one-time setup

This document is for **developers and operators**. End users never see any of this — they just click "Connect Google Calendar".

## 1. Create a Google Cloud project

1. Go to https://console.cloud.google.com.
2. Create a new project (or use an existing one for "Lavoro").

## 2. Enable Google Calendar API

1. APIs & Services → Library.
2. Search "Google Calendar API".
3. Click Enable.

## 3. Configure the OAuth consent screen

1. APIs & Services → OAuth consent screen.
2. User type: **External**.
3. Fill in:
   - App name: `Lavoro`
   - Support email: your email
   - App logo: optional
   - Developer contact: your email
4. Scopes — add these:
   - `https://www.googleapis.com/auth/calendar`
   - `openid`
   - `email`
   - `profile`
5. Test users — add the Google accounts you want to use during development.

While the screen is in **Testing** mode, only test users (max ~100) can connect.

## 4. Create OAuth credentials

1. APIs & Services → Credentials → Create credentials → OAuth client ID.
2. Application type: **Web application**.
3. Authorized redirect URIs:
   - `http://localhost:8000/google/oauth/callback` (local dev)
   - `https://<your-prod-domain>/google/oauth/callback` (production, once known)
4. Save. Note the Client ID and Client Secret.

## 5. Configure Lavoro

In `.env`:

```
GOOGLE_CLIENT_ID=<client id from step 4>
GOOGLE_CLIENT_SECRET=<client secret from step 4>
GOOGLE_OAUTH_REDIRECT_URI=http://localhost:8000/google/oauth/callback
GOOGLE_WEBHOOK_ENABLED=false
GOOGLE_WEBHOOK_URL=
GOOGLE_SYNC_LOOKBACK_DAYS=365
GOOGLE_DEFAULT_EVENT_TYPE_ID=
```

Run `php artisan config:clear`.

## 6. Run the supporting processes

In development:

```
php artisan serve
php artisan queue:work
php artisan schedule:work
```

In production:

- Queue worker as a supervised process (Supervisor or systemd).
- Scheduler via cron: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

## 7. Enable webhooks (optional, for near-real-time sync)

Without webhooks, the 5-minute polling job handles all incoming changes — it's slower but works fine.

To enable webhooks:

1. Run a public HTTPS endpoint pointing at your Lavoro app (production URL or `ngrok http 8000` in dev).
2. Verify the domain in Google Search Console.
3. Add it under APIs & Services → Domain verification.
4. In `.env`:

```
GOOGLE_WEBHOOK_ENABLED=true
GOOGLE_WEBHOOK_URL=https://<your-domain>/google/webhook
```

5. Run `php artisan config:clear`. Existing integrations register watches on next reconnect; new integrations register them immediately on connect.

## 8. Going live to all customers

The `auth/calendar` scope is "sensitive" by Google's classification. While the OAuth consent screen is in **Testing** mode, only pre-listed test users can connect.

To allow any user to connect:

1. OAuth consent screen → **Publish app**.
2. Submit for **verification**:
   - Provide a homepage URL.
   - Provide a privacy policy URL.
   - Record a short demo video showing how Lavoro uses the calendar scope.
3. Wait for Google's review (typically 2–6 weeks).

Plan this as a separate milestone — don't block the feature rollout on it. Internal users can connect immediately as test users.

## 9. Disconnect / cleanup

When a user disconnects:
1. The `TeardownIntegrationJob` stops all watch channels, deletes the Google calendars it created, removes our DB rows, and revokes the refresh token.
2. Lavoro events themselves are untouched.
3. If anything fails (e.g., Google API down), the user can simply reconnect; old state will be overwritten.

To force-disconnect a user as admin:

```
php artisan tinker
>>> App\Jobs\Google\TeardownIntegrationJob::dispatch(App\Models\User::find($id)->googleCalendarIntegration->id);
```
````

## Task 8.2 — Cross-link from spec

**Files:**
- Modify: `docs/superpowers/specs/2026-05-21-google-calendar-sync-design.md`

- [ ] **Step 1:** In §10.2 add a sentence: "See `docs/google-calendar-setup.md` for the full step-by-step."

---

### Phase 8 / final verification

- [ ] **Step 1:** A new dev with no prior context should be able to follow `docs/google-calendar-setup.md`, set up `.env`, and complete a full connect→backfill→sync→disconnect cycle.
- [ ] **Step 2:** All seven phases checked off. End-to-end: Lavoro changes flow to Google; permitted Google changes flow back; unpermitted Google changes revert; cross-user grants work; webhooks are optional.

---

## Open follow-ups (not in this plan)

- Submitting OAuth consent screen for Google verification — separate milestone.
- Promoting `IncomingChangeHandler::logRevert` to write `Activity` rows once `Event` adopts `HasActivities` (the spec mentions this; the trait isn't currently on `Event`).
- Notifying users in-app when their incoming Google change is rejected (currently log-only).
- Recurring events, attendees, reminders — out of scope.
