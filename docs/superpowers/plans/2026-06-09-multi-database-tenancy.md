# Multi-Database Multi-Tenancy Implementation Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Add multi-database multi-tenancy to Lavoro using `stancl/tenancy` v3, where a single domain serves all client companies and the correct database is chosen based on who logs in. The central ("landlord") database also records each tenant's license type and extra module subscriptions.

> **Revised 2026-07-03** against the current codebase. Changes from the original 2026-06-09 version:
>
> - **Task 24 (API) simplified.** The app uses `$middleware->statefulApi()` and there is no `createToken()` call anywhere — every API client (SPA and the Android app) authenticates with stateful Sanctum session cookies. The tenant therefore comes from the session on API requests too; the `X-Tenant-ID` header is kept only as a fallback for future bearer-token clients. The Inertia-prop + axios-default-header frontend steps are dropped.
> - **Task 25 (Google webhook) fixed.** `RenewWatchChannelsJob` *and* `BackfillCalendarJob` already generate a random `watch_channel_token` that `GoogleWebhookController` validates with `hash_equals`. The original plan overwrote that token with a bare tenant id, silently removing the security check and missing the second creation site. Now the token is `"<tenant_key>|<random>"` — the webhook parses the prefix to pick the tenant and the full-token validation stays intact.
> - **Remember-me preserved.** Login currently calls `Auth::attempt(..., true)`. The original plan dropped remember-me; this version stores the tenant id in a long-lived encrypted cookie so the recaller keeps working (Tasks 12 & 15).
> - **Task 16 repurposed.** `LoginPage.vue` no longer renders company branding (it uses the static `/img/logo-neg.svg`), so the old "null-guard the login page" task is obsolete. The `company` prop passed by `AuthController::create()` is dead code and is removed in Task 15. Task 16 now implements the landlord license type + module subscriptions.
> - **Task 19 made concrete.** The user routes use `UserStoreRequest` and `UserUpdateRequest` (see `UserController.php:48,61,97`); `UserUpdateRequest` is also used for `me.update` where no route user exists — the ignore logic accounts for that.
> - **Task 23 seeder updated** for the stage flags added since June (`is_invoiced_state`, 2026-06-17; `is_incomplete_state`, 2026-06-19).
> - **Service worker excluded from caching `/files/`** (new step in Task 14). `public/service-worker.js` caches same-origin GETs cache-first; `/files/images/{id}` ids are per-tenant auto-increments, so the same URL means different files in different tenants — cached responses could leak across tenants on a shared browser.
> - **Central migration filenames re-dated** to `2026_07_03_*` (a real tenant migration dated `2026_06_09` now exists), and migration counts refreshed (176 files today: 2 stay central, 174 move to `tenant/`). *(Superseded by the 2026-07-20 revision below — those counts and dates are no longer current.)*
> - **New code checked and covered:** FCM push notifications (`device_tokens`, `NewServiceOrderAssigned` — queued, so the queue bootstrapper carries tenant context; Firebase credentials are global env, see Known impact), event executions, plan groups, freeform materials, user roles (all ordinary tenant tables), the APK download / assetlinks routes (read `storage_path('app/releases/...')` directly, which the filesystem bootstrapper does not touch — they stay global by design).
> - **Task 29 added:** a repeatable runbook for migrating customers who run their own dedicated-subdomain install (e.g. `spee.lavorofsm.nl`) into the multi-tenant app at `app.lavorofsm.nl`.
>
> **Revised again 2026-07-10** to close out four items previously left as "Known impact" follow-up work:
> - **Task 20 (scheduler) reworked.** Two of the three scheduled ticks used to run a query or delete inline, per tenant, inside the scheduler process. They now only dispatch a queued job per tenant — the tick's cost no longer scales with data volume, addressing the old Known impact point 6.
> - **Task 30 added.** Tests move off SQLite (incompatible with multi-database tenancy) onto a dedicated, clearly-named MySQL test database, with a hard runtime assertion and a narrow-grant MySQL user as independent layers guaranteeing a test run can never reach a live database. Closes the old Known impact point 1.
> - **Task 31 added.** Module subscriptions are now actually enforced — a `tenant.module` route middleware on the SnelStart, Google Calendar, Tickets, and Projects routes, plus matching frontend gating. Closes the old Known impact point 5.
> - **Task 32 added.** Microsoft Graph mail credentials move from a single global env-configured mailbox to per-tenant `general_settings`, with a fallback to the global credentials and a fix for `MailManager`'s mailer caching across tenants on long-running queue workers. Narrows the old Known impact point 4 to just SnelStart and Firebase, which remain global.
>
> **Revised again 2026-07-20** after re-verifying every claim against the codebase. Four of these are correctness bugs that would have broken the implementation, not drift:
>
> - **Task 12 fixed — middleware ordering was wrong (critical).** `$middleware->web(append: [...])` puts the tenancy middleware *after* `SubstituteBindings`, which is the last entry of Laravel 12's default web group (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:485-491`). Route-model binding would then have resolved every `{serviceorder}`, `{customer}`, … against the **central** database and 404'd the entire app. The middleware is now placed with `$middleware->priority([...])`, between `StartSession` and `AuthenticatesRequests`, which is the only way to guarantee ordering across group + route middleware. Task 24 gets the same treatment.
> - **Task 30 fixed — the test transaction wrapped the wrong connection.** stancl's `DatabaseManager::connectToTenant()` hardcodes the tenant connection name to `tenant` (`setDefaultConnection('tenant')`), so `DB::connection('mysql')->beginTransaction()` began a transaction on a *non-tenant* connection: tenant writes would never have rolled back and tests would have polluted each other. Now `DB::connection('tenant')`. Also: `tenant_connection_name` is not a real v3 config key and has been dropped from Task 3.
> - **Task 14 widened — six `storage_path('app/public/…')` call sites bypass the disk abstraction.** The old claim "upload code needs no changes" holds only for `Storage::disk()` calls. `ServiceOrderController.php:850`, `ImageController.php:54` and `:258`, `Company.php:52`, and `resources/views/pdf/servicejob.blade.php:232` all build absolute paths by hand and would silently read/write the *central* storage tree under tenancy — PDFs would render without photos and without a logo. `resources/views/emails/event/appointment_confirmation.blade.php:102` uses `asset('storage/…')`, which becomes a dead link once files leave the public symlink; e-mail clients cannot authenticate, so the logo must be embedded. All six are now explicit steps.
> - **Task 18 fixed — `User` uses `SoftDeletes` since 2026-07-07.** The observer's `deleted` hook fires on *soft* delete, which would have released the email in the central lookup while the row still occupied `users.email` in the tenant — the user could then neither log in nor be recreated. Now: soft delete keeps the lookup, `forceDeleted` removes it, `restored` re-adds it.
> - **Task 20 — a fourth schedule exists.** `maintenancecontracts:generate-serviceorders` (hourly, added 2026-07-10) runs `MaintenanceContractServiceOrderGenerator::generateAllDue()` inline in the tick. It is now a per-tenant queued job like the others. Also corrected: `google-renew-watches` is a `Schedule::job(...)`, not a `dispatch()` call.
> - **Counts and locations refreshed.** 211 migrations today (5 stay central, 209 move to `tenant/`); central migrations re-dated `2026_07_21_*` because `2026_07_03` and later dates are now taken, up to `2026_07_20`. 35 test files use `RefreshDatabase`, not 16. `/storage/` appears in **12** Vue/JS files, not 10 (`Components/ServiceOrders/CloseServiceOrderModal.vue` and `Utilities/Utilities.js:273` are new). Sidebar navigation moved out of `MainLayout.vue` into `resources/js/Composables/useSidebarNav.js`. `snelStartEnabled` now exists in exactly one place (`ServiceOrderController.php:326`) and the SnelStart *customer* import route no longer exists — only `imports/snelstart/materials` and `serviceorders/{serviceorder}/send-snelstart`.
> - **Task 26 fixed for soft deletes.** `User::query()->pluck('email')` silently skipped trashed users, whose emails still occupy `users.email` (Laravel's `unique` rule does not know about soft deletes). Now `withTrashed()`, so a trashed user's email stays reserved centrally and the two checks agree.
> - **Task 27 hardened.** Added the missing `php artisan down`, queue-worker stop, `optimize:clear`, and `queue:restart` steps — Step 1 drops the database the running app is connected to.
> - **Package compatibility verified.** `stancl/tenancy` v3.10.0 requires `illuminate/support ^10.0|^11.0|^12.0|^13.0` — Laravel 12 is supported, no version hunting needed.
> - **Custom bootstrapper renamed** from `FilesystemTenancyBootstrapper` to `TenantStorageBootstrapper`, because the package ships a class with the identical basename and the two behave differently (ours deliberately does not suffix `storage_path()`).

**How it works, in plain terms:**

Today there is one database for everything. After this change there is one small *central* database plus one full database per client company (a "tenant"). When someone logs in, we look up their email in the central database to find which company they belong to, switch every database query to that company's database for the rest of the request, and remember the company in their session (and a long-lived cookie, for remember-me) for later requests.

**What lives where:**

Central database (small, shared infrastructure):
- `tenants` — one row per client company, including its `license_type` and subscribed `modules`
- `user_tenant_lookups` — maps an email to a tenant, used only when logging in
- `jobs`, `job_batches`, `failed_jobs` — the queue, so the worker can read jobs without first knowing the tenant
- `cache`, `cache_locks` — shared store; entries are isolated per tenant by a key prefix
- `sessions` — must be readable before we know the tenant, so it stays central

Tenant database (one per client company, fully isolated):
- `users`, `password_reset_tokens`
- Every business table (customers, assets, service orders, tickets, events, projects, materials, device tokens, location pings, plan groups, etc.)
- `roles`, `permissions`, `user_roles`, `companies`, `general_settings`, Google integration tables — everything else

Uploaded files are fully separated on disk too: each tenant's files live under their own root, `storage/tenant-<id>/public/...` and `storage/tenant-<id>/local/...`, and are served only through authenticated controllers (never a public URL). See Task 14. The Android APK under `storage/app/releases/` is global and intentionally unaffected.

**Three hard constraints this design imposes (read before starting):**

1. **Email must be globally unique across all tenants.** Because we find the tenant *from* the email at login, the same email cannot belong to two different companies. This is enforced at user creation (Task 19) and the central lookup table (Task 6).
2. **Sessions stay on the database driver but pinned to the central connection** (`SESSION_CONNECTION=central`, Task 7). The session is read before tenancy is initialized, so it cannot live in a tenant database.
3. **Cache stays on the database driver but is isolated by a per-tenant key prefix** (Task 10). We do *not* use the package's tag-based cache bootstrapper because the `database` cache store does not support tagging. The prefix approach is **cache-driver-agnostic** — it works identically on `file`, `database`, and `redis`, so adopting Redis later is just a `CACHE_STORE=redis` change with no tenancy code touched.

**Current environment (verified):** `DB_CONNECTION=mysql`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`. This plan keeps all of those drivers — no Redis required.

**Prerequisites:**
- MySQL (multi-database tenancy does not work on SQLite)
- The MySQL user must have the `CREATE DATABASE` privilege
- A full database backup before running the one-time deployment (Task 27)

---

## Task 1: Install the stancl/tenancy package

**Files:** `composer.json`, `config/tenancy.php` (published, replaced in Task 3), `app/Providers/TenancyServiceProvider.php` (published, replaced in Task 11)

- [ ] **Step 1: Add the package**

```bash
composer require stancl/tenancy:"^3.0"
```

If composer reports a Laravel 12 conflict, check the package's GitHub releases for the newest compatible tag and require that instead.

- [ ] **Step 2: Run the installer**

```bash
php artisan tenancy:install
```

This publishes `config/tenancy.php`, `app/Providers/TenancyServiceProvider.php`, and a stub tenants migration. We replace all three.

- [ ] **Step 3: Delete the stub tenants migration (we write our own in Task 6)**

```bash
rm database/migrations/*_create_tenants_table.php 2>/dev/null; true
```

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/tenancy.php app/Providers/TenancyServiceProvider.php
git commit -m "chore: install stancl/tenancy package"
```

---

## Task 2: Add a permanent `central` database connection

The default `mysql` connection gets switched to the tenant's database on every tenant request. We add a second connection, `central`, that is a copy of `mysql` but is never switched. Central-only models and migrations use it explicitly so they always reach the central database.

**Files:** `config/database.php`

- [ ] **Step 1: Add the `central` connection after the `mysql` block**

```php
'central' => [
    'driver'      => 'mysql',
    'url'         => env('DB_URL'),
    'host'        => env('DB_HOST', '127.0.0.1'),
    'port'        => env('DB_PORT', '3306'),
    'database'    => env('DB_DATABASE', 'lavoro'),
    'username'    => env('DB_USERNAME', 'root'),
    'password'    => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset'     => env('DB_CHARSET', 'utf8mb4'),
    'collation'   => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix'      => '',
    'prefix_indexes' => true,
    'strict'      => true,
    'engine'      => null,
    'options'     => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
],
```

- [ ] **Step 2: Commit**

```bash
git add config/database.php
git commit -m "feat(tenancy): add permanent central database connection"
```

---

## Task 3: Replace `config/tenancy.php`

The bootstrappers list is deliberate: the package's `DatabaseTenancyBootstrapper` and `QueueTenancyBootstrapper` are used as-is, but instead of the package's tag-based cache bootstrapper we register our own prefix-based one (built in Task 10), and we do not use the package filesystem bootstrapper (file isolation is done by disk-root repointing in Task 14 — our class is named `TenantStorageBootstrapper` precisely so it is never confused with the package's `FilesystemTenancyBootstrapper`, which additionally suffixes `storage_path()` and which we deliberately avoid).

Two things about the tenant connection, verified against the v3.10 source, that the rest of the plan depends on:

- `DatabaseManager::connectToTenant()` calls `setDefaultConnection('tenant')` — the tenant connection name is **hardcoded to `tenant`** in v3 and is not configurable. Anywhere you need the tenant connection by name (notably the test transactions in Task 30), it is `'tenant'`, never `'mysql'`.
- `RevertToCentralContext` sets the default connection back to `tenancy.database.central_connection`, i.e. `central`. So outside tenancy the default connection is `central`, not `mysql`; both point at the same database (Task 2), so this is invisible in practice.

Note there is **no** `queue.connections.database.central` flag set in Task 9. That is intentional: `QueueTenancyBootstrapper::getPayload()` returns an empty payload for connections marked `central`, which would strip the `tenant_id` from every job and break tenant-aware queued work. Pinning the queue *tables* to the central database (Task 9) is a different thing from marking the queue connection `central`.

**Files:** `config/tenancy.php`

- [ ] **Step 1: Replace the entire file**

```php
<?php

use App\Tenancy\PrefixCacheBootstrapper;
use App\Tenancy\TenantStorageBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

return [
    'tenant_model' => App\Models\Tenant::class,

    'central_domains' => [],

    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        PrefixCacheBootstrapper::class,
        TenantStorageBootstrapper::class,
    ],

    'database' => [
        'central_connection' => 'central',
        'template_tenant_connection' => env('DB_CONNECTION', 'mysql'),
        'prefix' => env('TENANCY_DB_PREFIX', 'lavoro_'),
        'suffix' => '',
        'managers' => [
            'mysql'   => Stancl\Tenancy\Database\Drivers\MySQLDatabaseManager::class,
            'mariadb' => Stancl\Tenancy\Database\Drivers\MySQLDatabaseManager::class,
            'pgsql'   => Stancl\Tenancy\Database\Drivers\PostgreSQLDatabaseManager::class,
        ],
    ],

    'features' => [],

    'migration_parameters' => [
        '--force'    => true,
        '--path'     => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'Database\Seeders\TenantDatabaseSeeder',
    ],
];
```

- [ ] **Step 2: Commit**

```bash
git add config/tenancy.php
git commit -m "feat(tenancy): configure bootstrappers and migration path"
```

---

## Task 4: Create the `Tenant` model

Represents one client company; lives in the central database. The MySQL database name is stored in the JSON `data` column under `tenancy_db_name`. `license_type` and `modules` are real columns (declared as custom columns so stancl does not fold them into `data`). Module gating on the backend is `tenancy()->tenant->hasModule('...')`.

**Files:** `app/Models/Tenant.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    protected $connection = 'central';

    protected $casts = [
        'data'    => 'array',
        'modules' => 'array',
    ];

    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'license_type', 'modules'];
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Tenant.php
git commit -m "feat(tenancy): add Tenant model with license and modules"
```

---

## Task 5: Create the `UserTenantLookup` model

A small central table: given an email, which tenant owns it. Queried only at login and password reset.

**Files:** `app/Models/Central/UserTenantLookup.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class UserTenantLookup extends Model
{
    protected $connection = 'central';
    protected $table = 'user_tenant_lookups';
    protected $primaryKey = 'email';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['email', 'tenant_id'];
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Central/UserTenantLookup.php
git commit -m "feat(tenancy): add UserTenantLookup model"
```

---

## Task 6: Create central database migrations

Both explicitly target the `central` connection. The `email` primary key is what enforces global email uniqueness across tenants. Dated `2026_07_21` so they sort after every existing migration — the newest tenant migrations today are `2026_07_20_*`. **Re-check `ls database/migrations/ | tail -1` at implementation time and bump the date past it if newer migrations have landed since**; the split in Task 8 excludes these three by exact filename, so a stale date here means the wrong files move.

**Files:**
- `database/migrations/2026_07_21_000001_create_tenants_table.php`
- `database/migrations/2026_07_21_000002_create_user_tenant_lookups_table.php`

- [ ] **Step 1: Create the tenants migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('license_type')->default('basic');
            $table->json('modules')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
```

- [ ] **Step 2: Create the user_tenant_lookups migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('user_tenant_lookups', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('user_tenant_lookups');
    }
};
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_21_000001_create_tenants_table.php \
        database/migrations/2026_07_21_000002_create_user_tenant_lookups_table.php
git commit -m "feat(tenancy): add central DB migrations"
```

---

## Task 7: Move the `sessions` table into the central database

The session is read at the very start of every request, before we know the tenant. So the `sessions` table must be in the central database, and the session driver must be pointed at the `central` connection.

The `sessions` table is currently created inside the framework users migration. We remove it from there and create it as its own central migration. Note the central migration keeps `user_id` as a plain indexed column with **no foreign key** — users live in tenant databases.

**Files:**
- `database/migrations/0001_01_01_000000_create_users_table.php` (remove the sessions block)
- `database/migrations/2026_07_21_000003_create_sessions_table.php` (new, central)
- `.env` / `.env.example`

- [ ] **Step 1: Remove the `sessions` block from the users migration**

Open `database/migrations/0001_01_01_000000_create_users_table.php`. It creates three tables: `users`, `password_reset_tokens`, and `sessions`. Delete the entire `Schema::create('sessions', function (Blueprint $table) { ... });` block (and the matching `Schema::dropIfExists('sessions');` in `down()`). Leave `users` and `password_reset_tokens` intact — those belong to the tenant database.

- [ ] **Step 2: Create the central sessions migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sessions');
    }
};
```

- [ ] **Step 3: Point the session driver at the central connection**

Add to `.env` and `.env.example`:

```
SESSION_CONNECTION=central
```

`config/session.php` already reads `'connection' => env('SESSION_CONNECTION')` (verified, line 76), so no config edit is needed. `SESSION_DRIVER=database` stays as-is.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/0001_01_01_000000_create_users_table.php \
        database/migrations/2026_07_21_000003_create_sessions_table.php \
        .env.example
git commit -m "feat(tenancy): move sessions table to central connection"
```

---

## Task 8: Split migrations into central and tenant directories

After this:
- `database/migrations/` holds only central migrations: cache, jobs, tenants, user_tenant_lookups, sessions. `php artisan migrate` runs these against the central database.
- `database/migrations/tenant/` holds everything else (209 files as of 2026-07-20: the users migration plus 208 dated ones). `php artisan tenants:migrate` runs these against each tenant database. Plain `migrate` does not descend into subdirectories, so these are correctly excluded from the central run.

`0001_01_01_000000_create_users_table.php` (now just users + password_reset_tokens after Task 7) moves to tenant. The cache and jobs framework migrations stay central.

**Files:** move ~209 migration files (211 total today − 2 framework migrations that stay central).

- [ ] **Step 1: Move the files**

```bash
mkdir -p database/migrations/tenant

git mv database/migrations/0001_01_01_000000_create_users_table.php database/migrations/tenant/

for f in database/migrations/2025_*.php; do
  git mv "$f" database/migrations/tenant/
done

for f in database/migrations/2026_*.php; do
  base=$(basename "$f")
  if [[ "$base" != "2026_07_21_000001_create_tenants_table.php" && \
        "$base" != "2026_07_21_000002_create_user_tenant_lookups_table.php" && \
        "$base" != "2026_07_21_000003_create_sessions_table.php" ]]; then
    git mv "$f" database/migrations/tenant/
  fi
done
```

- [ ] **Step 2: Verify**

```bash
ls database/migrations/*.php
# Expected exactly these 5:
# 0001_01_01_000001_create_cache_table.php
# 0001_01_01_000002_create_jobs_table.php
# 2026_07_21_000001_create_tenants_table.php
# 2026_07_21_000002_create_user_tenant_lookups_table.php
# 2026_07_21_000003_create_sessions_table.php

ls database/migrations/tenant/ | wc -l   # ~209
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/
git commit -m "feat(tenancy): split migrations into central and tenant directories"
```

---

## Task 9: Pin the queue to the central database

Jobs must always be stored centrally so the worker finds them regardless of tenant context. The `QueueTenancyBootstrapper` records the active tenant in each job payload and re-initializes it on the worker, so queued jobs (Google sync, FCM notifications) still run in the right tenant.

**Files:** `config/queue.php`

- [ ] **Step 1: Set all three database references to `central`**

```php
'database' => [
    'driver'       => 'database',
    'connection'   => 'central',
    'table'        => env('DB_QUEUE_TABLE', 'jobs'),
    'queue'        => env('DB_QUEUE', 'default'),
    'retry_after'  => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => true,
],
```

```php
'batching' => [
    'database' => 'central',
    'table'    => 'job_batches',
],
```

```php
'failed' => [
    'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => 'central',
    'table'    => 'failed_jobs',
],
```

- [ ] **Step 2: Commit**

```bash
git add config/queue.php
git commit -m "feat(tenancy): pin queue tables to central connection"
```

---

## Task 10: Custom prefix-based cache bootstrapper

The `database` cache store does not support tags, so the package's tag-based `CacheTenancyBootstrapper` cannot be used. Instead we set a per-tenant cache key prefix when a tenant is initialized and restore it when tenancy ends. The shared central `cache` table then holds all tenants' entries, isolated by prefix.

**Files:** `app/Tenancy/PrefixCacheBootstrapper.php`

- [ ] **Step 1: Create the bootstrapper**

```php
<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheBootstrapper implements TenancyBootstrapper
{
    protected ?string $original_prefix = null;

    public function __construct(protected Application $app)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->original_prefix = $this->app['config']->get('cache.prefix');
        $this->app['config']->set('cache.prefix', 'tenant_' . $tenant->getTenantKey());
        $this->app['cache']->forgetDriver($this->app['config']->get('cache.default'));
    }

    public function revert(): void
    {
        $this->app['config']->set('cache.prefix', $this->original_prefix);
        $this->app['cache']->forgetDriver($this->app['config']->get('cache.default'));
        $this->original_prefix = null;
    }
}
```

`forgetDriver` discards the resolved cache store so it is rebuilt with the new prefix on next use. The prefix is applied to every key by the store regardless of driver — this is what makes the approach driver-agnostic: it works unchanged on the `database` store today and on `redis` if you switch later.

- [ ] **Step 2: Commit**

```bash
git add app/Tenancy/PrefixCacheBootstrapper.php
git commit -m "feat(tenancy): add prefix-based cache bootstrapper for database cache"
```

---

## Task 11: Write the `TenancyServiceProvider`

When a `Tenant` is created, the `TenantCreated` event triggers a job pipeline that creates the database, runs tenant migrations, then seeds. `BootstrapTenancy` / `RevertToCentralContext` are the package listeners that switch the connection in and out of tenant context.

**Files:** `app/Providers/TenancyServiceProvider.php`, `bootstrap/providers.php`

- [ ] **Step 1: Replace `app/Providers/TenancyServiceProvider.php`**

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Support\JobPipeline;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([CreateDatabase::class, MigrateDatabase::class, SeedDatabase::class])
                ->send(fn (TenantCreated $event) => $event->tenant)
                ->shouldBeQueued(false)
                ->toListener()
        );
    }
}
```

- [ ] **Step 2: Register the provider in `bootstrap/providers.php`**

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\TenancyServiceProvider::class,
];
```

- [ ] **Step 3: Commit**

```bash
git add app/Providers/TenancyServiceProvider.php bootstrap/providers.php
git commit -m "feat(tenancy): register TenancyServiceProvider with tenant-creation pipeline"
```

---

## Task 12: Session-based tenancy middleware (with remember-me cookie fallback)

On every web request, after the session is read, switch to the tenant stored in the session. If the session has no tenant (fresh session revived by the remember-me recaller), fall back to the long-lived `tenant_id` cookie set at login (Task 15) — this is what keeps `Auth::attempt(..., remember: true)` working, because the auth guard resolves the recaller *after* this middleware has already switched the database. Always end tenancy after the response so the connection is not left switched (matters for long-running workers like Octane).

The cookie is encrypted/decrypted automatically by the web group's `EncryptCookies` middleware.

**Files:** `app/Http/Middleware/InitializeTenancyBySession.php`, `bootstrap/app.php`

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class InitializeTenancyBySession
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant_id = session('tenant_id') ?: $request->cookie('tenant_id');

        if ($tenant_id) {
            $tenant = Tenant::on('central')->find($tenant_id);
            if ($tenant) {
                tenancy()->initialize($tenant);
                if (!session('tenant_id')) {
                    session(['tenant_id' => $tenant->id]);
                }
            } else {
                session()->forget('tenant_id');
                cookie()->queue(cookie()->forget('tenant_id'));
            }
        }

        $response = $next($request);

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $response;
    }
}
```

- [ ] **Step 2: Add to the web stack in `bootstrap/app.php` — with an explicit priority, not `append`**

This is the single most order-sensitive change in the plan. The middleware must run:

- **after** `StartSession` (it reads `session('tenant_id')`) and after `EncryptCookies` (it reads the encrypted `tenant_id` cookie);
- **before** `SubstituteBindings`, or every route-model binding (`{serviceorder}`, `{customer}`, …) resolves against the **central** database and 404s;
- **before** the `auth` middleware and `HandleInertiaRequests`, both of which touch `Auth::user()` and therefore query the tenant database.

`$middleware->web(append: [...])` does **not** achieve this. Laravel 12's default web group is `EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings` (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:485-491`) — appending lands the middleware *after* `SubstituteBindings`, which is exactly the failure above. And relative order between group and route middleware is decided by the framework's priority list anyway, so appending is not even deterministic against `auth`.

Register it in the group **and** pin its position in the priority list:

```php
$middleware->web(append: [
    \App\Http\Middleware\InitializeTenancyBySession::class,
    HandleInertiaRequests::class,
]);

$middleware->priority([
    \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \App\Http\Middleware\InitializeTenancyBySession::class,
    \App\Http\Middleware\InitializeTenancyForApi::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
    \Illuminate\Routing\Middleware\ThrottleRequests::class,
    \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
    \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\EnsureTenantHasModule::class,
    \Illuminate\Auth\Middleware\Authorize::class,
]);
```

This is Laravel 12's stock priority array (`Illuminate\Foundation\Http\Kernel::$middlewarePriority`, lines 103–115) with three insertions: the two tenancy initializers right after `StartSession`, and `EnsureTenantHasModule` (Task 31) after `SubstituteBindings` so a module check can rely on a resolved tenant. `InitializeTenancyForApi` (Task 24) and `EnsureTenantHasModule` (Task 31) do not exist yet — either add this block once both classes exist, or add the entries incrementally as each task lands.

- [ ] **Step 3: Verify the resulting order before moving on**

Do not take this on faith — a wrong order here fails as mass 404s that look like a routing problem, not a tenancy problem:

```bash
php artisan route:list --path=serviceorders -v | head -40
```

`InitializeTenancyBySession` must appear before `SubstituteBindings` and before `auth` in the listed middleware for the route. Then log in and open a detail page (`/serviceorders/{id}`) — a 404 on a record that exists means the order is still wrong.

Note the Google webhook route lives in the web group too; it carries no session or cookie, so this middleware is a no-op there (the webhook resolves its tenant itself, Task 25).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Middleware/InitializeTenancyBySession.php bootstrap/app.php
git commit -m "feat(tenancy): initialize tenant from session or remember-cookie on web requests"
```

---

## Task 13: Guard the company Inertia share

`AppServiceProvider` shares company data on every Inertia response, including the login page where no tenant is active. Querying `Company` then hits the central database, which has no `companies` table, and crashes. Return `null` when tenancy is not initialized. The logo URL points at the authenticated file route from Task 14 (not a public `/storage` path), so it resolves per tenant.

**Files:** `app/Providers/AppServiceProvider.php` (the share is at the bottom of `boot()`)

- [ ] **Step 1: Replace the `Inertia::share('company', ...)` closure**

```php
Inertia::share('company', function () {
    if (!tenancy()->initialized) {
        return null;
    }
    $company = Company::where('is_main', true)->first();
    if (!$company) {
        return null;
    }
    $logo_url = $company->logo_path ? url("/files/companies/{$company->id}/logo") : null;
    return [
        'name' => $company->name,
        'logo_url' => $logo_url,
    ];
});
```

- [ ] **Step 2: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "fix: guard company Inertia share when tenancy not initialized"
```

---

## Task 14: Per-tenant storage isolation + authenticated file serving

Each tenant gets a completely separate storage root: `storage/tenant-<id>/public/...` and `storage/tenant-<id>/local/...`. A custom filesystem bootstrapper repoints the `public` and `local` disk roots into the active tenant's folder whenever tenancy is initialized, so **code that goes through `Storage::disk(...)` needs no changes** — `->store('uploaded/...', 'public')` automatically lands inside the tenant's folder, and the stored `path` stays relative (no tenant prefix in the database).

**Code that does *not* go through a disk does need changing, and it is easy to miss.** Six call sites build absolute paths by hand with `storage_path('app/public/…')` or `asset('storage/…')`. These bypass the disk root entirely, so after this task they would keep pointing at the shared central storage tree — silently, with no error: PDFs would render with missing photos and no logo, and imported images would be written where nothing can read them. Step 6 fixes all six. Re-run the grep in Step 6 before implementing, in case more have appeared.

Because files now live outside the web-served `public/storage` symlink, they are no longer reachable by URL. Instead, three small authenticated routes stream them through controllers. Tenant isolation is automatic: a file id from another tenant does not exist in this tenant's database, so route-model binding returns 404. (Documents already download through `DocumentController::download`, which uses `Storage::disk('public')` and therefore works unchanged — no document route is added here. The APK download route reads `storage_path('app/releases/lavoro.apk')` directly, not through a disk, so it intentionally stays global.)

**Files:**
- `app/Tenancy/TenantStorageBootstrapper.php` (new)
- `app/Http/Controllers/FileController.php` (new)
- `routes/web.php`
- `app/Models/User.php` (avatar accessor, `getAvatarAttribute`)
- `public/service-worker.js` (exclude `/files/` from caching)
- The 12 Vue/JS files that hardcode `/storage/${...}` (images and company logos)
- The 6 non-disk path builders: `app/Http/Controllers/ServiceOrderController.php:850`, `app/Http/Controllers/ImageController.php:54` and `:258`, `app/Models/Company.php:52`, `resources/views/pdf/servicejob.blade.php:232`, `resources/views/emails/event/appointment_confirmation.blade.php:102`

- [ ] **Step 1: Create the storage bootstrapper**

This repoints the disk roots only — it deliberately does **not** call `useStoragePath`, so framework storage (logs, compiled views, framework cache, `app/releases`) stays in the normal location; only uploaded-file disks move per tenant. That is also why it is not the package's `FilesystemTenancyBootstrapper`, and why it carries a different name.

```php
<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class TenantStorageBootstrapper implements TenancyBootstrapper
{
    protected array $original_roots = [];

    public function __construct(protected Application $app)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $suffix = 'tenant-' . $tenant->getTenantKey();

        foreach (['local', 'public'] as $disk) {
            $this->original_roots[$disk] = $this->app['config']["filesystems.disks.{$disk}.root"];
            $this->app['config']->set(
                "filesystems.disks.{$disk}.root",
                storage_path("{$suffix}/{$disk}")
            );
            Storage::forgetDisk($disk);
        }
    }

    public function revert(): void
    {
        foreach ($this->original_roots as $disk => $root) {
            $this->app['config']->set("filesystems.disks.{$disk}.root", $root);
            Storage::forgetDisk($disk);
        }
        $this->original_roots = [];
    }
}
```

- [ ] **Step 2: Create the file-serving controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function image(Image $image)
    {
        abort_unless(Storage::disk('public')->exists($image->path), 404);

        return Storage::disk('public')->response($image->path);
    }

    public function avatar(User $user)
    {
        $directory = "users/{$user->id}/avatar";
        $files = Storage::disk('public')->files($directory);
        abort_if(empty($files), 404);

        return Storage::disk('public')->response($files[0]);
    }

    public function companyLogo(Company $company, string $variant = 'main')
    {
        $path = $variant === 'negative' ? $company->logo_negative_path : $company->logo_path;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }
}
```

- [ ] **Step 3: Register the routes inside the authenticated web group in `routes/web.php`**

Add inside the `auth` middleware group (tenancy is already initialized for these requests by the session middleware):

```php
Route::get('files/images/{image}', [\App\Http\Controllers\FileController::class, 'image'])->name('files.image');
Route::get('files/avatars/{user}', [\App\Http\Controllers\FileController::class, 'avatar'])->name('files.avatar');
Route::get('files/companies/{company}/logo/{variant?}', [\App\Http\Controllers\FileController::class, 'companyLogo'])->name('files.companyLogo');
```

- [ ] **Step 4: Change the `User` avatar accessor to return the route URL**

In `app/Models/User.php`, `getAvatarAttribute()` currently ends with `return Storage::url($files[0]);`. Keep the existence checks (so it still returns `null` when no avatar exists and the UI shows initials), but return the authenticated route instead:

```php
$directory = "users/{$this->id}/avatar";

if (!Storage::disk('public')->exists($directory)) {
    return null;
}

$files = Storage::disk('public')->files($directory);

if (empty($files)) {
    return null;
}

return url("/files/avatars/{$this->id}");
```

- [ ] **Step 5: Exclude `/files/` from service-worker caching**

`public/service-worker.js` serves same-origin GETs cache-first. Image/avatar ids are per-tenant auto-increments, so `/files/images/5` is a *different file* in each tenant; a cached copy could be shown to a user of another tenant on a shared browser, and stale copies would survive image replacement. In the `fetch` listener, add `/files/` to the early-return list:

```js
// Let the browser handle assets & API/Inertia calls
if (
    url.pathname.startsWith("/build/") ||
    url.pathname.startsWith("/files/") ||
    event.request.headers.get("X-Inertia") ||
    url.pathname.startsWith("/api/")
) {
    return;
}
```

- [ ] **Step 6: Update the frontend to use the file routes instead of `/storage/`**

Find every hardcoded reference:

```bash
grep -rn "/storage/" resources/js/
```

Apply these conversions across the matching files (verified list as of 2026-07-20 — 12 files, 18 occurrences; re-run the grep in case more were added):

| File | Occurrences |
| --- | --- |
| `Pages/Assets/IndexPage.vue` | 1 |
| `Pages/Assets/ShowPage.vue` | 1 |
| `Pages/Products/IndexPage.vue` | 1 |
| `Pages/Products/ShowPage.vue` | 1 |
| `Pages/ServiceOrders/ShowPage.vue` | 1 |
| `Pages/Companies/IndexPage.vue` | 2 |
| `Pages/Companies/Partials/EditCompanyModal.vue` | 2 |
| `Components/Timeline/TimelineComponent.vue` | 1 |
| `Components/CustomerUpcomingActivity.vue` | 2 |
| `Components/ImageUploadComponent.vue` | 3 |
| `Components/ServiceOrders/CloseServiceOrderModal.vue` (new since last revision) | 2 |
| `Utilities/Utilities.js:273` (new since last revision) | 1 |

Two of these need more than a mechanical swap:

- `Components/Timeline/TimelineComponent.vue` binds `event.thumbnailPath`, a *path* with no accompanying image id. Check what the timeline payload contains; if it carries no id, the backend that builds it must expose the `Image` id alongside (or instead of) the path.
- `Utilities/Utilities.js:273` builds `thumbnail_url` from `asset.product.images[0].path` inside a shared mapper — switch it to `.id` and confirm every consumer of `thumbnail_url` still works.


- Image displays bound to an `Image` model — replace the path build with the id route:

```vue
<!-- before -->
<img :src="`/storage/${image.path}`" />
<!-- after -->
<img :src="`/files/images/${image.id}`" />
```

  Apply the same to `asset.product.images[...]`, `product.main_image[0]`, and any other `Image` model: use its `.id`, not `.path`.

- Company logos — use the company route, with the `negative` variant for the negative logo:

```vue
<!-- before -->
<img :src="`/storage/${company.logo_path}`" />
<img :src="`/storage/${company.logo_negative_path}`" />
<!-- after -->
<img :src="`/files/companies/${company.id}/logo`" />
<img :src="`/files/companies/${company.id}/logo/negative`" />
```

- In `ImageUploadComponent.vue`, a *freshly uploaded* preview may use a local object URL or a path returned from the upload response before an `Image` id exists. Leave object-URL previews as-is; for previews of already-saved images, use `/files/images/${image.id}`. Check each usage in this file specifically. Note the line-383 occurrence feeds an image *editor* (`loadImage: { path, name }`), not an `<img>` — verify the editor accepts the route URL.

- [ ] **Step 7: Fix the six backend path builders that bypass the disk**

Each of these constructs an absolute path or a public URL by hand and therefore ignores the per-tenant disk root set in Step 1. All of them fail *silently* — a missing file is treated as "no image" — so they will not surface in a smoke test unless you look at a rendered PDF and an appointment e-mail specifically.

```bash
grep -rn "storage_path('app/\|asset('storage/" app/ resources/views/
```

1. **`app/Http/Controllers/ServiceOrderController.php:850`** — builds `storage_path('app/public/' . $image->path)` to base64-embed werkbon photos in the PDF. Read through the disk instead, which respects the tenant root:

```php
if (!Storage::disk('public')->exists($image->path)) {
    return null;
}
$contents = Storage::disk('public')->get($image->path);
$mime = Storage::disk('public')->mimeType($image->path);
[$width, $height] = @getimagesizefromstring($contents) ?: [1, 1];
// ...
'data' => 'data:' . $mime . ';base64,' . base64_encode($contents),
```

2. **`app/Models/Company.php:52` (`pdfLogo`)** — same pattern for the company logo on every PDF. Replace `storage_path('app/public/' . $company->logo_path)` with `Storage::disk('public')->exists(...)` / `->get(...)` / `->size(...)`, keeping the existing empty-file and extension checks.

3. **`resources/views/pdf/servicejob.blade.php:232`** — `<img src="{{ storage_path('app/public/' . $img['path']) }}">`. Dompdf reads this straight off disk, so it points at the central tree. Resolve the absolute path in the controller that renders this view (via `Storage::disk('public')->path($img['path'])`, which *is* tenant-aware) and pass it into the view, or pass a data URI like the other PDFs already do.

4. **`app/Http/Controllers/ImageController.php:258`** — `file_put_contents(storage_path('app/public/' . $path) . $filename, ...)` for imported images. Replace the manual `mkdir` + `file_put_contents` with `Storage::disk('public')->put($path . $filename, $image_data)`, which creates directories itself.

5. **`app/Http/Controllers/ImageController.php:54`** — `mkdir(storage_path('app/' . $path))`. **This one is already a latent bug today, independent of tenancy**: it creates `storage/app/uploaded/…` while the very next line stores into the `public` disk at `storage/app/public/uploaded/…`. The `mkdir` has never been doing anything useful — `storePubliclyAs` creates the directory itself. Delete the `$real_path` / `mkdir` block outright rather than porting it.

6. **`resources/views/emails/event/appointment_confirmation.blade.php:102`** — `asset('storage/' . $company->logo_path)`. Once files leave the `public/storage` symlink this URL 404s, and the `/files/` routes are behind `auth`, so an e-mail client can never fetch it. Embed the logo instead, reusing the accessor that already exists for PDFs:

```blade
@php($logo = \App\Models\Company::pdfLogo($company))
@if($logo['data'])
    <img src="{{ $logo['data'] }}" alt="{{ $company->name }}">
@endif
```

The `asset('storage/logo.png')` / `public_path('storage/logo.png')` references in the three PDF blades are a different case: that is a single static fallback logo, not tenant data. It resolves through the untouched `public/storage` symlink and stays global by design — leave it, but be aware it is the same image for every tenant.

- [ ] **Step 8: Commit**

```bash
git add app/Tenancy/TenantStorageBootstrapper.php \
        app/Http/Controllers/FileController.php \
        app/Http/Controllers/ImageController.php \
        app/Http/Controllers/ServiceOrderController.php \
        app/Models/Company.php \
        routes/web.php \
        app/Models/User.php \
        public/service-worker.js \
        resources/views/ \
        resources/js/
git commit -m "feat(tenancy): per-tenant storage roots with authenticated file serving"
```

---

## Task 15: Update the login controller

Look up the tenant from the email, switch to its database, then authenticate. Keep `remember: true` (current behavior) and pair it with a forever `tenant_id` cookie so the recaller can find its database on a fresh session (Task 12 reads it). Also remove the `exists:users,email` rule from the form request — it runs before tenancy is initialized and would query the central database, which has no `users` table. Finally, drop the `company` prop from `create()`: `LoginPage.vue` renders the static `/img/logo-neg.svg` and never reads it, and the query would crash against the central database.

**Files:** `app/Http/Controllers/AuthController.php`, `app/Http/Requests/StoreUpdateAuthRequest.php`

- [ ] **Step 1: Remove the `exists` rule**

```php
public function rules(): array
{
    return [
        'email'    => 'required|string|email',
        'password' => 'required|string',
    ];
}
```

- [ ] **Step 2: Rewrite `AuthController`**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUpdateAuthRequest;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create()
    {
        return inertia('Auth/LoginPage');
    }

    public function store(StoreUpdateAuthRequest $request)
    {
        $lookup = UserTenantLookup::where('email', $request->email)->first();

        if (!$lookup) {
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        $tenant = Tenant::on('central')->findOrFail($lookup->tenant_id);
        tenancy()->initialize($tenant);

        if (!Auth::attempt($request->only('email', 'password'), true)) {
            tenancy()->end();
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        session(['tenant_id' => $tenant->id]);
        cookie()->queue(cookie()->forever('tenant_id', $tenant->id));
        $request->session()->regenerate();

        return redirect()->intended();
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        cookie()->queue(cookie()->forget('tenant_id'));

        return redirect()->route('login');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/AuthController.php app/Http/Requests/StoreUpdateAuthRequest.php
git commit -m "feat(tenancy): resolve tenant before authenticating on login"
```

---

## Task 16: Landlord license type and module subscriptions

The `tenants` table already carries `license_type` and `modules` (Task 6) and the `Tenant` model exposes `hasModule()` (Task 4). This task adds the typed enums, CLI management, and frontend exposure. Management is CLI-only for now — there is no landlord admin UI (YAGNI).

`TenantModule::comboBoxArray()` follows the existing enum convention in `app/Enums/` in case a UI needs it later. The initial module list mirrors the app's optional feature areas; extend the enum as commercial modules are defined.

**Files:**
- `app/Enums/TenantLicenseType.php` (new)
- `app/Enums/TenantModule.php` (new)
- `app/Console/Commands/SetTenantLicense.php` (new)
- `app/Console/Commands/SetTenantModules.php` (new)
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/Utilities/Utilities.js`

- [ ] **Step 1: Create the license type enum**

```php
<?php

namespace App\Enums;

enum TenantLicenseType: string
{
    case Basic      = 'basic';
    case Premium    = 'premium';
    case Enterprise = 'enterprise';

    public static function comboBoxArray(): array
    {
        return array_map(
            fn (self $case) => ['id' => $case->value, 'name' => ucfirst($case->value)],
            self::cases()
        );
    }
}
```

- [ ] **Step 2: Create the module enum**

```php
<?php

namespace App\Enums;

enum TenantModule: string
{
    case SnelStart        = 'snelstart';
    case GoogleCalendar   = 'google_calendar';
    case Projects         = 'projects';
    case Tickets          = 'tickets';
    case LocationTracking = 'location_tracking';

    public static function comboBoxArray(): array
    {
        return array_map(
            fn (self $case) => ['id' => $case->value, 'name' => $case->name],
            self::cases()
        );
    }
}
```

- [ ] **Step 3: Create the `tenant:license` command**

Shows the current license when called without a type; validates against the enum when setting.

```php
<?php

namespace App\Console\Commands;

use App\Enums\TenantLicenseType;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SetTenantLicense extends Command
{
    protected $signature = 'tenant:license {id} {license_type?}';
    protected $description = 'Show or set the license type of a tenant';

    public function handle(): int
    {
        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        $license_type = $this->argument('license_type');

        if (!$license_type) {
            $this->info("Tenant '{$tenant->name}' has license: {$tenant->license_type}");
            return self::SUCCESS;
        }

        if (!TenantLicenseType::tryFrom($license_type)) {
            $valid = implode(', ', array_column(TenantLicenseType::cases(), 'value'));
            $this->error("Invalid license type. Valid types: {$valid}");
            return self::FAILURE;
        }

        $tenant->update(['license_type' => $license_type]);
        $this->info("Tenant '{$tenant->name}' license set to: {$license_type}");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Create the `tenant:modules` command**

Shows subscribed modules when called without options; `--add` and `--remove` are repeatable.

```php
<?php

namespace App\Console\Commands;

use App\Enums\TenantModule;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SetTenantModules extends Command
{
    protected $signature = 'tenant:modules {id} {--add=*} {--remove=*}';
    protected $description = 'Show or change the extra module subscriptions of a tenant';

    public function handle(): int
    {
        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        $add    = $this->option('add');
        $remove = $this->option('remove');
        $valid  = array_column(TenantModule::cases(), 'value');

        foreach (array_merge($add, $remove) as $module) {
            if (!in_array($module, $valid, true)) {
                $this->error("Unknown module '{$module}'. Valid modules: " . implode(', ', $valid));
                return self::FAILURE;
            }
        }

        if ($add || $remove) {
            $modules = collect($tenant->modules ?? [])
                ->merge($add)
                ->unique()
                ->reject(fn ($module) => in_array($module, $remove, true))
                ->values()
                ->all();
            $tenant->update(['modules' => $modules]);
        }

        $current = implode(', ', $tenant->fresh()->modules ?? []) ?: '(none)';
        $this->info("Tenant '{$tenant->name}' modules: {$current}");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Share license and modules with the frontend**

In `app/Http/Middleware/HandleInertiaRequests.php`, add to the array returned by `share()` (next to the existing `auth` key):

```php
'tenant' => tenancy()->initialized ? [
    'license_type' => tenancy()->tenant->license_type,
    'modules'      => tenancy()->tenant->modules ?? [],
] : null,
```

- [ ] **Step 6: Add a `hasModule` helper to `resources/js/Utilities/Utilities.js`**

Follow the same pattern as the existing `hasPermission` helper (which reads `usePage().props.auth.permissions`):

```js
export const hasModule = (name) => {
    const page = usePage();
    const modules = page?.props?.tenant?.modules;
    return Array.isArray(modules) && modules.includes(name);
}
```

Two deliberate differences from `hasPermission`: it is **not** bypassed for admins (a module is a subscription boundary, not a permission), and it returns `false` rather than throwing when `tenant` is absent — which is the case on the login page, where no tenancy is initialized.

- [ ] **Step 7: Verify the commands**

```bash
php artisan tenant:license some-id premium        # after a tenant exists (Task 21/26)
php artisan tenant:modules some-id --add=snelstart --add=projects
php artisan tenant:modules some-id --remove=projects
php artisan tenant:modules some-id                # prints: snelstart
```

- [ ] **Step 8: Commit**

```bash
git add app/Enums/TenantLicenseType.php app/Enums/TenantModule.php \
        app/Console/Commands/SetTenantLicense.php app/Console/Commands/SetTenantModules.php \
        app/Http/Middleware/HandleInertiaRequests.php resources/js/Utilities/Utilities.js
git commit -m "feat(tenancy): landlord license type and module subscriptions"
```

---

## Task 17: Update the password reset flow

Password reset runs before login, so do the same tenant lookup before calling the `Password` facade (which queries `password_reset_tokens` and `users` in the tenant database). Do **not** wrap the new password in `Hash::make()` — the `User` model has a `hashed` cast, so `forceFill` hashes it automatically; wrapping it would double-hash and break login.

**Files:** `app/Http/Controllers/PasswordResetController.php`

- [ ] **Step 1: Rewrite the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function create()
    {
        return inertia('Auth/ForgotPasswordPage');
    }

    public function store(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $this->switchToTenantForEmail($request->email);

        $status = Password::sendResetLink($request->only('email'));

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    public function edit(string $token, Request $request)
    {
        return inertia('Auth/ResetPasswordPage', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->switchToTenantForEmail($request->email);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
                event(new PasswordReset($user));
            }
        );

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    private function switchToTenantForEmail(string $email): void
    {
        $lookup = UserTenantLookup::where('email', $email)->first();
        if (!$lookup) {
            return;
        }
        $tenant = Tenant::on('central')->find($lookup->tenant_id);
        if ($tenant) {
            tenancy()->initialize($tenant);
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/PasswordResetController.php
git commit -m "feat(tenancy): switch to tenant DB in password reset flow"
```

---

## Task 18: Keep the central lookup in sync via a `User` observer

When a user is created, changes email, or is deleted in a tenant, mirror it into the central lookup. The `created` hook refuses to hijack an email already registered to a *different* tenant (defense in depth behind the validation in Task 19).

**`User` uses `SoftDeletes`** (added 2026-07-07, `2026_07_07_080819_add_softdeletes_to_users_table.php`; see `UserController::restore` and `UserRestoreRequest`). This matters more than it looks:

- Eloquent's `deleted` event fires on a **soft** delete. Dropping the lookup row there would free the email centrally while the row still occupies `users.email` in the tenant — and Laravel's `unique:users,email` rule does **not** exclude trashed rows. The email would then be un-loggable-in, un-recreatable in this tenant, and claimable by another tenant, which is exactly the invariant this table exists to protect.
- The rule is therefore: the central lookup tracks whether the email is **taken in the tenant's `users` table**, trashed or not. Soft delete keeps the row, `forceDeleted` removes it, `restored` re-asserts it.

A soft-deleted user cannot log in (the `SoftDeletingScope` hides them from the auth guard's query), so keeping the lookup row costs nothing.

**Files:** `app/Observers/UserObserver.php`, `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create the observer**

```php
<?php

namespace App\Observers;

use App\Models\Central\UserTenantLookup;
use App\Models\User;
use RuntimeException;

class UserObserver
{
    public function created(User $user): void
    {
        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        $existing = UserTenantLookup::on('central')->find($user->email);
        if ($existing && $existing->tenant_id !== $tenant_id) {
            throw new RuntimeException("E-mailadres {$user->email} is al in gebruik bij een andere tenant.");
        }

        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function updated(User $user): void
    {
        if (!$user->isDirty('email')) {
            return;
        }

        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        UserTenantLookup::on('central')->where('email', $user->getOriginal('email'))->delete();
        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function restored(User $user): void
    {
        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function forceDeleted(User $user): void
    {
        UserTenantLookup::on('central')->where('email', $user->email)->delete();
    }
}
```

There is deliberately **no `deleted` hook**. On a soft-deleting model `deleted` fires for soft deletes, and the lookup must survive those — see the explanation above. `forceDeleted` fires only on a true `forceDelete()`, which is when the email genuinely becomes free again.

Also note `updated` fires on the soft-delete write (`deleted_at` changes), but its `isDirty('email')` guard makes that a no-op.

- [ ] **Step 2: Register it in `AppServiceProvider::boot()`** (next to the existing `EventModel::observe` / `Ticket::observe` calls)

```php
\App\Models\User::observe(\App\Observers\UserObserver::class);
```

- [ ] **Step 3: Commit**

```bash
git add app/Observers/UserObserver.php app/Providers/AppServiceProvider.php
git commit -m "feat(tenancy): sync central user lookup on user changes"
```

---

## Task 19: Enforce global email uniqueness at user creation/update

The lookup table's email primary key would throw a raw SQL error if an admin created a user whose email already exists in another tenant. Validate it cleanly instead. The routes use `UserStoreRequest` (store) and `UserUpdateRequest` (update **and** `me.update` via `updateSelf`, where there is no `{user}` route parameter — verified at `UserController.php:55,68,120`). `StoreUserRequest`/`UpdateUserRequest` also exist in `app/Http/Requests/` but are not referenced by the user routes; leave those untouched.

Both requests already have exactly the shape this task assumes: `UserStoreRequest::rules()` has the flat `'email' => 'required|email|unique:users,email'` string, and `UserUpdateRequest::rules()` already computes `$route_user` / `$route_user_id` / `$current_user_id` / `$ignore_id` in that order, so the `$ignore_email` snippet below drops in directly after them.

Consistency note tying this to Task 18: `unique:users,email` counts soft-deleted users, and the central lookup keeps a row for soft-deleted users. The two checks therefore agree — an email belonging to a trashed user is rejected by both, not one.

The `central.` prefix on the unique rule tells the validator to query the central connection.

**Files:** `app/Http/Requests/UserStoreRequest.php`, `app/Http/Requests/UserUpdateRequest.php`

- [ ] **Step 1: Add a global-uniqueness rule to `UserStoreRequest::rules()`**

Replace the current `'email' => 'required|email|unique:users,email',` line:

```php
use Illuminate\Validation\Rule;

// inside rules():
'email' => [
    'required', 'email',
    'unique:users,email',
    Rule::unique('central.user_tenant_lookups', 'email'),
],
```

- [ ] **Step 2: Add the same rule to `UserUpdateRequest`, ignoring the user's current email**

The request already derives `$ignore_id` from the route user or the authenticated user; mirror that logic for the email. Inside `rules()`, after the existing `$ignore_id` computation, add:

```php
$ignore_user = is_object($route_user)
    ? $route_user
    : ($route_user ? \App\Models\User::find($route_user) : null);
$ignore_email = $ignore_user?->email ?? optional(request()->user())->email;
```

and extend the email rules:

```php
'email' => [
    'required',
    'email',
    Rule::unique('users', 'email')->ignore($ignore_id),
    Rule::unique('central.user_tenant_lookups', 'email')->ignore($ignore_email, 'email'),
],
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/UserStoreRequest.php app/Http/Requests/UserUpdateRequest.php
git commit -m "feat(tenancy): validate email is globally unique across tenants"
```

---

## Task 20: Make scheduled tasks run per tenant, without per-tenant work blocking the scheduler tick

Loop over all tenants, switch into each, and dispatch **one queued job per tenant per schedule** — the scheduler tick itself must never run a data query or delete inline, only cheap config-swap + single-row `INSERT INTO jobs` work. The jobs are dispatched from tenant context (not `dispatchSync`) — the `QueueTenancyBootstrapper` records the active tenant in the job payload and re-initializes it automatically when the worker picks it up, so the job body needs no manual `tenancy()->initialize()` call of its own.

`routes/console.php` has **four** schedules as of 2026-07-20 (the plan previously said three). If more exist by implementation time, wrap them in the same per-tenant dispatch-only pattern:

| Schedule | Today | Change |
| --- | --- | --- |
| `google-pull-changes` | `Schedule::call` running a `whereHas` query inline | → per-tenant dispatch of `DispatchTenantCalendarPullsJob` |
| `google-renew-watches` | `Schedule::job(new RenewWatchChannelsJob())` | → per-tenant `RenewWatchChannelsJob::dispatch()` inside the loop (a bare `Schedule::job` has no tenant context and would run against the central database) |
| `prune-location-pings` | `Schedule::call` running a synchronous `DELETE` inline | → per-tenant dispatch of `PruneLocationPingsJob` |
| `maintenancecontracts-generate-serviceorders` (**new since last revision**, 2026-07-10) | `Schedule::command('maintenancecontracts:generate-serviceorders')` calling `MaintenanceContractServiceOrderGenerator::generateAllDue()` inline | → per-tenant dispatch of `GenerateMaintenanceContractServiceOrdersJob` |

The maintenance-contract one is the most important of the four to convert: `generateAllDue()` scans every asset on every active contract and **creates service orders**. Left as a plain `Schedule::command`, it would run exactly once per tick against whatever the default connection happens to be — the central database, which has no `maintenance_contracts` table — so contract generation would break outright for every tenant. The existing Artisan command stays (it is useful for manual runs against a chosen tenant); the schedule stops invoking it directly.

**Files:**
- `app/Jobs/Google/DispatchTenantCalendarPullsJob.php` (new)
- `app/Jobs/PruneLocationPingsJob.php` (new)
- `app/Jobs/GenerateMaintenanceContractServiceOrdersJob.php` (new)
- `routes/console.php`

> **Precondition, easy to forget:** none of this runs at all until a server-level cron invokes `php artisan schedule:run` — there is currently no crontab entry wired up, and `app/Console/Kernel.php`'s `schedule()` is dead code under Laravel 12's `routes/console.php` setup. Confirm the cron exists on the target server before treating any scheduled behaviour as working.

- [ ] **Step 1: Create the calendar-pull dispatch job**

```php
<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchTenantCalendarPullsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        GoogleSyncedCalendar::query()
            ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->pluck('id')
            ->each(fn ($id) => PullCalendarChangesJob::dispatch($id));
    }
}
```

This runs on the worker with tenancy already initialized (tagged by `QueueTenancyBootstrapper` at dispatch time, same as any other tenant-context job), so the `whereHas` query and the `PullCalendarChangesJob` dispatches it makes both land against the correct tenant database automatically.

- [ ] **Step 2: Create the location-ping pruning job**

```php
<?php

namespace App\Jobs;

use App\Models\LocationPing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneLocationPingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        LocationPing::where('recorded_at', '<', now()->subDay())->delete();
    }
}
```

- [ ] **Step 3: Create the maintenance-contract generation job**

```php
<?php

namespace App\Jobs;

use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMaintenanceContractServiceOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MaintenanceContractServiceOrderGenerator $generator): void
    {
        $generator->generateAllDue();
    }
}
```

Leave `App\Console\Commands\GenerateMaintenanceContractServiceOrders` in place for manual runs; only the schedule changes.

- [ ] **Step 4: Rewrite `routes/console.php` so every tick only dispatches**

`cursor()` replaces `get()` so the central tenant list is streamed rather than loaded into memory in one array — cheap either way at today's tenant count, but it's the free half of the "chunking" mitigation Known impact point 6 calls for.

```php
<?php

use App\Jobs\GenerateMaintenanceContractServiceOrdersJob;
use App\Jobs\Google\DispatchTenantCalendarPullsJob;
use App\Jobs\Google\RenewWatchChannelsJob;
use App\Jobs\PruneLocationPingsJob;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        DispatchTenantCalendarPullsJob::dispatch();
        tenancy()->end();
    });
})->everyFiveMinutes()->name('google-pull-changes')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        RenewWatchChannelsJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('google-renew-watches')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        PruneLocationPingsJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('prune-location-pings')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        GenerateMaintenanceContractServiceOrdersJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('maintenancecontracts-generate-serviceorders')->withoutOverlapping();
```

Every tick body is now: swap config, one `INSERT` into the central `jobs` table, revert config — no query, no delete, nothing whose cost scales with a tenant's data volume. The remaining linear cost is strictly "number of tenants × one INSERT", which is the part `withoutOverlapping` can comfortably absorb even at a few hundred tenants.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Google/DispatchTenantCalendarPullsJob.php app/Jobs/PruneLocationPingsJob.php \
        app/Jobs/GenerateMaintenanceContractServiceOrdersJob.php routes/console.php
git commit -m "feat(tenancy): keep per-tenant scheduler ticks dispatch-only"
```

---

## Task 21: `tenant:create` command (with initial admin)

Creates the tenant record (which fires the create→migrate→seed pipeline), then creates an initial admin user inside the new tenant so the company can actually log in. Creating the user fires the observer, which writes the central lookup row. License and modules can be set at creation time (validated against the Task 16 enums).

**Files:** `app/Console/Commands/CreateTenant.php`

- [ ] **Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Enums\TenantLicenseType;
use App\Enums\TenantModule;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create {name} {admin_email} {--database=} {--admin-password=} {--license=basic} {--modules=*}';
    protected $description = 'Create a tenant, its database, and an initial admin user';

    public function handle(): int
    {
        $name     = $this->argument('name');
        $email    = $this->argument('admin_email');
        $database = $this->option('database') ?: 'lavoro_' . Str::slug($name, '_');
        $password = $this->option('admin-password') ?: Str::password(16);
        $license  = $this->option('license');
        $modules  = $this->option('modules');

        if (!TenantLicenseType::tryFrom($license)) {
            $this->error('Invalid license type: ' . $license);
            return self::FAILURE;
        }

        $valid_modules = array_column(TenantModule::cases(), 'value');
        foreach ($modules as $module) {
            if (!in_array($module, $valid_modules, true)) {
                $this->error('Unknown module: ' . $module);
                return self::FAILURE;
            }
        }

        $this->info("Creating tenant '{$name}' (database {$database})...");

        $tenant = Tenant::create([
            'id'              => (string) Str::ulid(),
            'name'            => $name,
            'license_type'    => $license,
            'modules'         => array_values(array_unique($modules)),
            'tenancy_db_name' => $database,
        ]);

        tenancy()->initialize($tenant);

        $user = User::create([
            'name'     => 'Beheerder',
            'email'    => $email,
            'password' => $password,
        ]);

        $admin_role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->syncWithoutDetaching([$admin_role->id]);

        tenancy()->end();

        $this->info("Tenant ID: {$tenant->id}");
        $this->info("License: {$license}");
        $this->info("Admin: {$email}");
        $this->info("Password: {$password}");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Console/Commands/CreateTenant.php
git commit -m "feat(tenancy): add tenant:create with initial admin user"
```

---

## Task 22: `tenant:delete` command (cleanup / failed-creation recovery)

If tenant creation fails partway, or a tenant is offboarded, this drops the database, the central lookup rows, and the tenant record. Deleting the `Tenant` fires the package's `DeleteDatabase` job if wired; to be explicit and safe we drop the database directly.

**Files:** `app/Console/Commands/DeleteTenant.php`

- [ ] **Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteTenant extends Command
{
    protected $signature = 'tenant:delete {id}';
    protected $description = 'Drop a tenant database and remove its central records';

    public function handle(): int
    {
        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        $database = $tenant->getDatabaseName();

        if (!$this->confirm("Permanently drop database '{$database}' and all its data?")) {
            return self::FAILURE;
        }

        DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");
        UserTenantLookup::on('central')->where('tenant_id', $tenant->id)->delete();
        $tenant->delete();

        $this->info("Deleted tenant {$tenant->id} and database {$database}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Console/Commands/DeleteTenant.php
git commit -m "feat(tenancy): add tenant:delete for cleanup"
```

---

## Task 23: `TenantDatabaseSeeder`

Runs automatically when a new tenant database is created. It only seeds what the tenant migrations do not: the company record and default service order stages. Roles and permissions are already created by the existing `seed_*_permissions` migrations, so they must not be duplicated here.

The stage rows carry all six semantic flags currently on `service_order_stages` (verified 2026-07-03): `is_plannable_state`, `is_planned_state`, `is_closed_state`, `is_planning_cancelled_state`, `is_invoiced_state`, `is_incomplete_state`.

**Files:** `database/seeders/TenantDatabaseSeeder.php`

- [ ] **Step 1: Create the seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ServiceOrderStage;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Company::firstOrCreate(
            ['is_main' => true],
            ['name' => tenancy()->tenant->name]
        );

        $default_flags = [
            'is_plannable_state'          => false,
            'is_planned_state'            => false,
            'is_closed_state'             => false,
            'is_planning_cancelled_state' => false,
            'is_invoiced_state'           => false,
            'is_incomplete_state'         => false,
        ];

        $stages = [
            ['name' => 'Nieuw',    'order' => 1, 'is_plannable_state' => true],
            ['name' => 'Gepland',  'order' => 2, 'is_planned_state' => true],
            ['name' => 'Gesloten', 'order' => 3, 'is_closed_state' => true],
        ];

        foreach ($stages as $stage) {
            ServiceOrderStage::firstOrCreate(
                ['name' => $stage['name']],
                array_merge($default_flags, $stage)
            );
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add database/seeders/TenantDatabaseSeeder.php
git commit -m "feat(tenancy): add TenantDatabaseSeeder for company and stages"
```

---

## Task 24: API routes — tenant from the session (header fallback)

The API uses stateful Sanctum: `bootstrap/app.php` calls `$middleware->statefulApi()`, and there are **no bearer tokens anywhere** (`createToken` is never called) — the SPA and the Android app both authenticate with session cookies. `EnsureFrontendRequestsAreStateful` runs the session middleware for requests from stateful origins *before* route middleware, so by the time our middleware runs, `session('tenant_id')` is available. That means API tenancy works exactly like web tenancy, with **no frontend changes at all**.

An `X-Tenant-ID` header is honored as a fallback so a future bearer-token client can work without touching this middleware again. Applied as a named route middleware (not a global prepend) so any future public API endpoint can skip it.

**Files:** `app/Http/Middleware/InitializeTenancyForApi.php`, `bootstrap/app.php`, `routes/api.php`

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class InitializeTenancyForApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant_id = $request->hasSession()
            ? $request->session()->get('tenant_id')
            : null;
        $tenant_id = $tenant_id ?: $request->header('X-Tenant-ID');

        if (!$tenant_id) {
            return response()->json(['message' => 'Tenant kon niet worden bepaald.'], 400);
        }

        $tenant = Tenant::on('central')->find($tenant_id);
        if (!$tenant) {
            return response()->json(['message' => 'Onbekende tenant.'], 400);
        }

        tenancy()->initialize($tenant);

        $response = $next($request);

        tenancy()->end();

        return $response;
    }
}
```

- [ ] **Step 2: Register the alias in `bootstrap/app.php`**

```php
$middleware->alias([
    'admin'      => EnsureUserIsAdmin::class,
    'tenant.api' => \App\Http\Middleware\InitializeTenancyForApi::class,
]);
```

- [ ] **Step 3: Apply it to the authenticated API group in `routes/api.php`**

The whole file is currently one `Route::group(['middleware' => 'auth:sanctum'], ...)`; add `tenant.api` before it:

```php
Route::group(['middleware' => ['tenant.api', 'auth:sanctum']], function () {
    // all existing routes unchanged
});
```

**Ordering matters here for the same reason as Task 12.** The `api` group also ends in `SubstituteBindings`, and many of these routes bind models (`events/{event}`, `images/{image}`, `projects/{project}`, `plan-groups/{group}`, `users/{user}`, …). Listing `tenant.api` first in the array is not sufficient on its own, because Laravel priority-sorts the combined group + route middleware list. `InitializeTenancyForApi` is already included in the Task 12 priority array — confirm it is there before relying on this, and verify with:

```bash
php artisan route:list --path=api/events -v | head -20
```

`InitializeTenancyForApi` must appear before both `SubstituteBindings` and `auth:sanctum`.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Middleware/InitializeTenancyForApi.php bootstrap/app.php routes/api.php
git commit -m "feat(tenancy): resolve tenant from session on API requests"
```

---

## Task 25: Route the Google webhook to the right tenant

The webhook arrives from Google with no session and no cookie. The existing code already round-trips a random secret: `RenewWatchChannelsJob` and `BackfillCalendarJob` both store a `Str::random(40)` token on the calendar row, and `GoogleWebhookController` rejects notifications whose `X-Goog-Channel-Token` fails `hash_equals` against it. **Keep that check.** We only prepend the tenant key to the token — `"<tenant_key>|<random>"` — so the controller can pick the database before looking the channel up; the stored token is the full prefixed string, so the `hash_equals` comparison still covers the entire value Google echoes back.

Channels created before this change carry unprefixed tokens and cannot be routed; they self-heal on renewal (Task 27 Step 6 forces it), and the 5-minute polling schedule covers the gap.

**Files:** `app/Jobs/Google/RenewWatchChannelsJob.php`, `app/Jobs/Google/BackfillCalendarJob.php`, `app/Http/Controllers/GoogleWebhookController.php`

- [ ] **Step 1: Prefix the token in `RenewWatchChannelsJob`**

In `handle()`, the loop currently builds `$token = Str::random(40);`. Change it to:

```php
$token = tenancy()->tenant->getTenantKey() . '|' . Str::random(40);
```

- [ ] **Step 2: Prefix the token in `BackfillCalendarJob::registerWatch()`**

Same change — replace `$token = \Illuminate\Support\Str::random(40);` with:

```php
$token = tenancy()->tenant->getTenantKey() . '|' . \Illuminate\Support\Str::random(40);
```

Both jobs are always dispatched from tenant context (scheduler loop or a tenant web request), so `tenancy()->tenant` is set when they run on the worker via the `QueueTenancyBootstrapper`.

- [ ] **Step 3: Initialize tenancy from the token prefix in `GoogleWebhookController::handle()`**

After the four `$request->header(...)` reads and the `if (!$channel_id || !$resource_id)` guard, and before the `GoogleSyncedCalendar::where('watch_channel_id', $channel_id)->first()` lookup, add:

```php
$token_parts = explode('|', (string) $channel_token, 2);
if (count($token_parts) !== 2) {
    return response('Unknown channel', 404);
}

$tenant = \App\Models\Tenant::on('central')->find($token_parts[0]);
if (!$tenant) {
    return response('Unknown channel', 404);
}

tenancy()->initialize($tenant);
```

The rest of the method (channel lookup, full-token `hash_equals`, resource-id check, `PullCalendarChangesJob::dispatch`) stays exactly as it is — the dispatch happens inside tenant context, so the queue bootstrapper tags the job with the right tenant.

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/Google/RenewWatchChannelsJob.php \
        app/Jobs/Google/BackfillCalendarJob.php \
        app/Http/Controllers/GoogleWebhookController.php
git commit -m "feat(tenancy): route Google webhook to tenant via prefixed channel token"
```

---

## Task 26: `tenant:setup-existing` — register a pre-tenancy database

Registers an already-existing, already-migrated database as a tenant and copies its user emails into the central lookup. Uses a direct insert to skip the `TenantCreated` pipeline, since the database already exists and is already migrated. License and modules for this tenant are set afterwards with the Task 16 commands.

Note `User::withTrashed()` — `User` soft-deletes, and a trashed user's email still occupies `users.email` and still blocks `unique:users,email`. Copying only live users would leave those emails free centrally while unusable in the tenant, and let a *later* tenant claim them; the collision check below would then pass and the invariant would already be broken. This matches the Task 18 observer, which keeps the lookup row through a soft delete.

This command is used twice: for the main install's database during deployment (Task 27), and again for every dedicated-subdomain install that gets absorbed later (Task 29).

**Files:** `app/Console/Commands/SetupExistingTenant.php`

- [ ] **Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetupExistingTenant extends Command
{
    protected $signature = 'tenant:setup-existing {name} {database}';
    protected $description = 'Register an existing pre-tenancy database as a tenant';

    public function handle(): int
    {
        $id = (string) Str::ulid();

        DB::connection('central')->table('tenants')->insert([
            'id'           => $id,
            'name'         => $this->argument('name'),
            'license_type' => 'basic',
            'modules'      => json_encode([]),
            'data'         => json_encode(['tenancy_db_name' => $this->argument('database')]),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $tenant = Tenant::on('central')->findOrFail($id);
        tenancy()->initialize($tenant);

        $emails = User::withTrashed()->pluck('email');

        $conflicts = UserTenantLookup::on('central')
            ->whereIn('email', $emails)
            ->where('tenant_id', '!=', $id)
            ->pluck('email');

        if ($conflicts->isNotEmpty()) {
            tenancy()->end();
            DB::connection('central')->table('tenants')->where('id', $id)->delete();
            $this->error('Aborted: these emails already belong to another tenant:');
            $conflicts->each(fn ($email) => $this->error("  - {$email}"));
            $this->error('Resolve the duplicates in the source database first, then rerun.');
            return self::FAILURE;
        }

        $emails->each(function (string $email) use ($id) {
            UserTenantLookup::on('central')->updateOrCreate(
                ['email' => $email],
                ['tenant_id' => $id]
            );
        });

        tenancy()->end();

        $this->info("Registered '{$this->argument('name')}' as tenant: {$id}");
        $this->info("Populated {$emails->count()} email lookups.");
        $this->warn("Set the license with: php artisan tenant:license {$id} <type>");
        $this->warn("Now migrate existing files into storage/tenant-{$id}/ — see Task 27 Step 5 / Task 29 Step 6.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Console/Commands/SetupExistingTenant.php
git commit -m "feat(tenancy): add tenant:setup-existing command"
```

---

## Task 27: One-time deployment

Destructive and irreversible — run on a backup first. Do this in a maintenance window; all users will be logged out and must log in again.

**Prerequisites:** full MySQL dump taken; `.env` has `DB_CONNECTION=mysql`, `SESSION_CONNECTION=central`; `DB_DATABASE` is the name the central database should have (e.g. `lavoro`).

- [ ] **Step 0: Take the app down and stop everything that writes**

Step 1 drops and recreates the database the running application is connected to. Anything still writing during that window loses data silently, and a queue worker holding a booted container will keep serving stale config afterwards.

```bash
php artisan down

# Stop the queue worker and the scheduler cron (adjust to your process manager)
sudo systemctl stop lavoro-worker      # or: supervisorctl stop lavoro-worker:*
sudo crontab -l                        # confirm/comment the schedule:run entry
```

Confirm nothing is connected before continuing:

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SHOW PROCESSLIST;"
```

- [ ] **Step 1: Dump the existing database and split it**

MySQL has no rename-database command, so copy via dump/restore. The existing data becomes the tenant database; a fresh empty database takes the original name as central.

```bash
EXISTING=lavoro
TENANT_DB=lavoro_tenant_acme

mysqldump -u "$DB_USERNAME" -p"$DB_PASSWORD" "$EXISTING" > /tmp/tenant_backup.sql
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "CREATE DATABASE $TENANT_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$TENANT_DB" < /tmp/tenant_backup.sql
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "DROP DATABASE $EXISTING; CREATE DATABASE $EXISTING CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

- [ ] **Step 2: Clear every cached config, then run the central migrations**

`.env` gained `SESSION_CONNECTION=central` and `config/database.php`, `config/queue.php`, `config/tenancy.php` all changed. A cached config bundle from before the deploy would quietly override all of it — including pointing sessions and the queue at the wrong database.

```bash
php artisan optimize:clear
php artisan migrate --force
```

Creates `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `tenants`, `user_tenant_lookups`, `migrations` in the central database.

Sanity-check that `migrate` did **not** pick up tenant migrations (Task 8 moved them into a subdirectory that plain `migrate` does not descend into) — the central `migrations` table should hold 5 rows, not 200+:

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT COUNT(*) FROM lavoro.migrations;"
```

- [ ] **Step 3: Drop the now-unused `sessions` table from the tenant copy (optional tidy)**

The restored tenant database still contains a `sessions` table from before the split. It is unused (sessions are central now) and harmless; drop it if you want a clean schema:

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$TENANT_DB" -e "DROP TABLE IF EXISTS sessions;"
```

- [ ] **Step 4: Register the existing database as tenant #1 and set its license/modules**

```bash
php artisan tenant:setup-existing "Naam van het bedrijf" lavoro_tenant_acme
```

Record the printed tenant ID — call it `TENANT_ID` for the next steps. Then:

```bash
php artisan tenant:license "$TENANT_ID" premium
php artisan tenant:modules "$TENANT_ID" --add=snelstart --add=google_calendar
```

(Pick the license and modules that match the customer's actual subscription.)

- [ ] **Step 5: Move existing uploaded files into the tenant storage root**

Task 14 puts each tenant's files under `storage/tenant-<id>/public/...`. Existing files currently sit in `storage/app/public/...` (locally: `uploaded/` and `company-logos/`; production may also have `users/` avatar folders). Move them into the new root. **No database path rewrite is needed** — the stored `path` values are relative to the disk root, which is exactly what the per-tenant disk root now resolves against. The `app/releases/` folder (APK) stays where it is.

```bash
TENANT_ID=<paste-from-step-4>
cd storage

mkdir -p "tenant-$TENANT_ID/public"
# Move every existing public upload dir into the tenant's public root
for d in app/public/users app/public/uploaded app/public/company-logos; do
  [ -e "$d" ] && mv "$d" "tenant-$TENANT_ID/public/"
done

# If anything was stored on the private/local disk, move it too:
if [ -d app/private ] && [ -n "$(ls -A app/private 2>/dev/null)" ]; then
  mkdir -p "tenant-$TENANT_ID/local"
  mv app/private/* "tenant-$TENANT_ID/local/"
fi
```

After this, e.g. an image whose stored `path` is `uploaded/customer/5/documents/x.jpg` resolves to `storage/tenant-<id>/public/uploaded/customer/5/documents/x.jpg`, served via `/files/images/<id>`.

- [ ] **Step 6: Force-renew the Google watch channels**

Existing watch channels carry tokens without the tenant prefix (Task 25), so the webhook cannot route them. Expire them so the hourly renewal recreates them with prefixed tokens on the next run (the 5-minute polling schedule keeps sync working meanwhile):

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$TENANT_DB" \
  -e "UPDATE google_synced_calendars SET watch_expires_at = NOW() WHERE watch_channel_id IS NOT NULL;"
```

- [ ] **Step 7: Build front-end assets, bring everything back up, and verify**

```bash
npm run build

# Restart workers so they pick up the new config, then lift maintenance mode
php artisan queue:restart
sudo systemctl start lavoro-worker     # or: supervisorctl start lavoro-worker:*
sudo crontab -e                        # restore the schedule:run entry
php artisan up

mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SHOW TABLES IN lavoro;"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT id, name, license_type, modules FROM lavoro.tenants;"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT COUNT(*) FROM lavoro.user_tenant_lookups;"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SHOW TABLES IN lavoro_tenant_acme;" | head
```

- [ ] **Step 8: Smoke test**

Log in as an existing user, then walk this list. The first four are the failure modes most likely to survive to production, because each of them fails *silently* rather than with an error page:

- [ ] The dashboard loads, and a **detail page opens** (`/serviceorders/{id}`, `/customers/{id}`). A 404 on a record you can see in the index means the Task 12 middleware ordering is wrong and route-model binding is hitting the central database.
- [ ] An existing customer's documents and images display, and a previously uploaded avatar shows.
- [ ] **Export a werkbon to PDF** and confirm the photos *and* the company logo appear — this is the check for the Task 14 Step 7 path builders. A PDF that renders with everything except images means one of them was missed.
- [ ] **Send an appointment confirmation e-mail** and confirm the logo renders in the received message (Task 14 Step 7, item 6).
- [ ] Upload a new image and confirm it lands under `storage/tenant-<id>/public/…`, not `storage/app/public/`.
- [ ] Open the Planner and confirm its API calls return this tenant's events (`tenant.api` resolving from the session).
- [ ] Watch the log for a few minutes after the queue worker restarts — confirm no job fails with "table does not exist", which would mean a job ran without tenant context.
- [ ] Close the browser and reopen — remember-me should log you straight back in (session + tenant cookie).

Existing sessions are gone (the central `sessions` table is fresh), so everyone re-logs in once — expected.

- [ ] **Step 9: Rollback plan**

If the smoke test fails in a way you cannot fix inside the maintenance window, roll back rather than debug in production:

```bash
php artisan down
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "DROP DATABASE $EXISTING; CREATE DATABASE $EXISTING CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$EXISTING" < /tmp/tenant_backup.sql
git checkout <pre-tenancy-tag>
# remove SESSION_CONNECTION from .env
php artisan optimize:clear && npm run build && php artisan up
```

Then move the files back out of `storage/tenant-<id>/public/` into `storage/app/public/` (Step 5 in reverse). Keep `/tmp/tenant_backup.sql` until the customer has used the new setup for at least a week.

---

## Task 28: Verify isolation with a second tenant

- [ ] **Step 1: Create a second tenant with an admin**

```bash
php artisan tenant:create "Tweede Klant BV" admin@tweede.nl --admin-password=secret123 --license=basic --modules=google_calendar
```

Confirm it prints a tenant ID, license, admin email, and password, and does not error. If it hangs, check the MySQL user has `CREATE DATABASE` and that no queue worker is needed (the pipeline runs inline via `shouldBeQueued(false)`).

- [ ] **Step 2: Confirm web isolation**

Log in as `admin@tweede.nl`. Confirm you see an empty data set (only the seeded stages), not the first tenant's data. Upload an image and confirm it lands under `storage/tenant-<second-id>/public/...` and displays via `/files/images/<id>`. Confirm that requesting another tenant's image id returns 404.

- [ ] **Step 3: Confirm the API resolves the tenant from the session**

While logged in as each tenant's user in the browser, open `/api/events?start=...&end=...` (or watch the Planner's own XHR calls) and confirm each tenant only sees their own events. Then confirm the fallback path:

```bash
# Unauthenticated, no session, no header — 400 from tenant.api:
curl http://localhost/api/events -H "Accept: application/json"
```

- [ ] **Step 4: Confirm a queued job runs in the right tenant**

```bash
php artisan queue:work --once --verbose
```

Trigger a Google sync (or any queued import) from one tenant and confirm the data lands in that tenant's database, not the other's or the central one.

- [ ] **Step 5: Confirm license/module data round-trips**

```bash
php artisan tenant:license <second-id>                 # prints: basic
php artisan tenant:modules <second-id>                 # prints: google_calendar
```

And in the browser as the second tenant's user, check the Inertia page props include `tenant: { license_type: 'basic', modules: ['google_calendar'] }`.

---

## Task 29: Migrate a dedicated-subdomain install into the multi-tenant app

Some customers run their own standalone copy of Lavoro on a dedicated subdomain — currently one at `spee.lavorofsm.nl` — each with its own database, storage, and `.env`. This task absorbs such an install into the multi-tenant app at `app.lavorofsm.nl` as a new tenant. It is a **repeatable runbook**: run it once per legacy install, in a maintenance window agreed with that customer. The steps below use the Spee install as the concrete example; substitute names for the next customer.

**Prerequisites:**
- Task 27 is complete: `app.lavorofsm.nl` is live and multi-tenant.
- The legacy install is upgraded to the **latest pre-tenancy release** so its schema matches the files now in `database/migrations/tenant/`. Verify: `php artisan migrate:status` on the legacy host must show every migration Ran and none Pending.
- SSH access to the legacy host, and the central MySQL user has `CREATE DATABASE`.

- [ ] **Step 1: Freeze the legacy install and take a final backup**

On the legacy host:

```bash
php artisan down
mysqldump -u "$OLD_DB_USER" -p"$OLD_DB_PASS" "$OLD_DB_NAME" > /tmp/spee_final.sql
```

Also stop the legacy queue worker and scheduler cron so nothing writes to the database after the dump.

- [ ] **Step 2: Transfer the dump and restore it as a tenant database**

On the central server:

```bash
scp legacy-host:/tmp/spee_final.sql /tmp/spee_final.sql
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "CREATE DATABASE lavoro_tenant_spee CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" lavoro_tenant_spee < /tmp/spee_final.sql
```

- [ ] **Step 3: Drop the infrastructure tables from the copy**

Sessions are central now; the queue/cache tables in the copy are unused (jobs live centrally). Only `sessions` matters — the rest is optional tidying:

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" lavoro_tenant_spee \
  -e "DROP TABLE IF EXISTS sessions, cache, cache_locks, jobs, job_batches, failed_jobs;"
```

- [ ] **Step 4: Check for email collisions, then register the tenant**

Every user email must be globally unique across tenants. Check up front — and include **soft-deleted** users, because their emails still occupy `users.email` and are still copied into the lookup by `tenant:setup-existing` (see Task 26):

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e \
  "SELECT u.email, u.deleted_at FROM lavoro_tenant_spee.users u
   JOIN lavoro.user_tenant_lookups l ON l.email = u.email;"
```

A collision on a *soft-deleted* row on either side is the easy case: force-delete that user in whichever database it is dead in, rather than renaming a live account. A collision between two live accounts (the same person working for two customers, or a shared `info@` address) needs a conversation with the customer — one of the two has to change.

If this returns rows, resolve them with the customer first (change the email in the source database). Then register — the command aborts and rolls back by itself if a collision slipped through:

```bash
php artisan tenant:setup-existing "Spee" lavoro_tenant_spee
```

Record the printed tenant ID as `TENANT_ID`, then set the subscription:

```bash
php artisan tenant:license "$TENANT_ID" premium
php artisan tenant:modules "$TENANT_ID" --add=google_calendar
```

- [ ] **Step 5: Bring the imported schema up to date**

If the central app has gained tenant migrations newer than the legacy release, apply them (already-run migrations are recorded by filename in the imported `migrations` table, so only the new ones execute):

```bash
php artisan tenants:migrate --tenants="$TENANT_ID"
```

This works because Task 8 preserved every migration filename when moving files into `database/migrations/tenant/` — the imported `migrations` table matches on basename, not path. Two things to check before trusting the result:

```bash
# The imported migrations table still lists the three now-central migrations.
# Harmless (their tables are simply unused in the tenant copy) — do not delete the rows,
# or a later `tenants:migrate` will try to recreate sessions/cache/jobs in the tenant DB.
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" lavoro_tenant_spee \
  -e "SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;"
```

Then diff against the first tenant to catch a legacy install that was further behind than `migrate:status` suggested:

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -N -e "SHOW TABLES IN lavoro_tenant_acme;" | sort > /tmp/a.txt
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -N -e "SHOW TABLES IN lavoro_tenant_spee;" | sort > /tmp/b.txt
diff /tmp/a.txt /tmp/b.txt
```

Expect the only differences to be the central-infrastructure tables dropped in Step 3.

- [ ] **Step 6: Copy the uploaded files into the tenant storage root**

On the central server, pull the legacy public disk into `storage/tenant-<id>/public/` (paths in the database are relative, so no rewrite is needed — same principle as Task 27 Step 5):

```bash
mkdir -p "storage/tenant-$TENANT_ID/public" "storage/tenant-$TENANT_ID/local"
rsync -av legacy-host:/path/to/lavoro/storage/app/public/ "storage/tenant-$TENANT_ID/public/"
rsync -av legacy-host:/path/to/lavoro/storage/app/private/ "storage/tenant-$TENANT_ID/local/"
```

- [ ] **Step 7: Re-home the Google Calendar integration**

The imported `google_synced_calendars` rows hold watch channels registered against `https://spee.lavorofsm.nl/google/webhook` with unprefixed tokens. Expire them so the hourly renewal re-registers them against the central webhook URL with tenant-prefixed tokens (5-minute polling covers the gap):

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" lavoro_tenant_spee \
  -e "UPDATE google_synced_calendars SET watch_expires_at = NOW() WHERE watch_channel_id IS NOT NULL;"
```

The stored OAuth refresh tokens keep working — they are not domain-bound. But if the legacy install used its **own** Google Cloud OAuth client, reconnecting later from `app.lavorofsm.nl` requires the central OAuth client instead; in that case expect the customer to redo the Google connection once (Beheer → Google koppeling) and tell them so up front.

- [ ] **Step 8: Redirect the old subdomain**

Keep `spee.lavorofsm.nl` DNS and its TLS certificate alive, but replace the vhost with a permanent redirect so bookmarks, the installed PWA, and password-reset links in old emails all land correctly:

```nginx
server {
    listen 443 ssl;
    server_name spee.lavorofsm.nl;
    # existing ssl_certificate lines stay

    return 301 https://app.lavorofsm.nl$request_uri;
}
```

- [ ] **Step 9: Verify**

- Log in at `app.lavorofsm.nl` with a Spee user's existing email and password (passwords migrate as-is — same hashes). Confirm their customers, service orders, images, and documents all show.
- Confirm `https://spee.lavorofsm.nl` redirects to `https://app.lavorofsm.nl`.
- Confirm a calendar event created in the Planner still syncs to Google.
- Confirm the first tenant's data is untouched and Spee users see none of it.

- [ ] **Step 10: Decommission the legacy install (after a grace period)**

Once the customer confirms everything works — suggest two weeks — remove the legacy app directory, drop its database on the old host, and remove its cron entries and queue worker. Keep the redirect vhost indefinitely; it costs nothing.

**Client-side caveats to communicate to the customer:**

- Everyone logs in again once — sessions and remember-me cookies do not carry over between domains.
- A PWA installed from `spee.lavorofsm.nl` is bound to that origin. The redirect keeps it functional, but users should remove it and install the PWA fresh from `app.lavorofsm.nl`.
- **Check the Android APK's base URL before migrating.** If the build the customer uses points at `spee.lavorofsm.nl`, plan an app update targeting `app.lavorofsm.nl` and have users reinstall and log in again; do not rely on the HTTP client following the 301 with cookies intact. FCM device tokens themselves are app-instance-bound, not domain-bound, so push notifications resume after re-login.

---

## Task 30: Tenant-aware test suite — MySQL only, and it must be structurally impossible to hit a live database

`phpunit.xml` currently pins tests to SQLite `:memory:`, which is fast, trivially isolated (a throwaway in-process database per run), and — critically — physically cannot be a live database. Multi-database tenancy does not work on SQLite (see Prerequisites), so tests must move to MySQL. Moving to MySQL removes the "physically cannot be live" guarantee SQLite gave us for free, so this task rebuilds that guarantee explicitly, in three independent layers, rather than trusting a correctly-set env var:

1. **Distinct database names.** The central test database is `lavoro_test_central`, never `lavoro` (the dev/prod name from Task 2). Tenant test databases get their own prefix, `lavoro_test_` (Task 3 set `lavoro_`), configured via a new env var so it can differ from the runtime prefix without touching `config/tenancy.php` again per environment.
2. **A hard runtime assertion.** The test bootstrap refuses to run — throws before a single query executes — if the resolved central database name doesn't contain `test`. This is the layer that survives someone fat-fingering `.env` or copy-pasting production values into `phpunit.xml` later.
3. **A distinct MySQL user with narrow grants (operational, done once outside the app).** Create a MySQL user that only has privileges on `lavoro_test_%` — even a fully wrong config in (1) and a bypassed assertion in (2) still cannot reach `lavoro` or any `lavoro_<tenant-id>` database, because the user has no grant on it. Document this as a required local/CI setup step; it is not something the application can enforce in code.

**How the existing `RefreshDatabase` test files change:** one shared test tenant is created once per test run (not once per test — creating a MySQL database per test would make the suite very slow), central and tenant migrations run once, and each individual test is wrapped in a transaction on *both* the `central` and `tenant` connections that rolls back after the test — the same isolation guarantee `RefreshDatabase` gave per-test, just spanning two connections instead of one. This logic moves from the per-file `RefreshDatabase` trait into the shared `TestCase`, so `RefreshDatabase` comes out of every file that used it. **35 test files** use it as of 2026-07-20 (the plan previously said 16) — re-run the grep in Step 5.

Two consequences of transaction-rollback isolation that `RefreshDatabase`'s truncate-and-remigrate did not have, and that may surface as test failures during this task:

- `AUTO_INCREMENT` counters are **not** reset between tests, because a rollback does not reclaim them. Any test asserting a literal id (`assertSame(1, $model->id)`) or relying on a predictable id ordering will start failing. Fix those tests to use the actual model's id.
- Code under test that issues DDL, an explicit `DB::beginTransaction()`, or `DB::unprepared` can implicitly commit and defeat the wrapper. `ProjectFinancialNotesMigrationTest` is worth checking first, since it exercises a data migration.

**Files:**
- `phpunit.xml`
- `tests/Concerns/RefreshesTenantDatabase.php` (new)
- `tests/TestCase.php`
- The 35 existing test files using `RefreshDatabase`

- [ ] **Step 1: Confirm the tenant database prefix is env-overridable**

Already handled — `config/tenancy.php` in Task 3 sets `'prefix' => env('TENANCY_DB_PREFIX', 'lavoro_')`. Nothing to change here; just verify it reads from the env var before continuing, since Step 3's guard depends on it.

- [ ] **Step 2: Point `phpunit.xml` at a dedicated, clearly-named MySQL test database**

Replace the sqlite block:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

with:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="3306"/>
<env name="DB_DATABASE" value="lavoro_test_central"/>
<env name="DB_USERNAME" value="lavoro_test"/>
<env name="DB_PASSWORD" value="lavoro_test"/>
<env name="TENANCY_DB_PREFIX" value="lavoro_test_"/>
<env name="SESSION_CONNECTION" value="central"/>
```

Also remove `SESSION_DRIVER` value `array` is fine to keep — the session table itself is never touched by tests that don't explicitly exercise auth, and `SESSION_CONNECTION=central` only matters when the `database` driver is used. Leave `SESSION_DRIVER=array` as-is.

- [ ] **Step 3: Create the tenancy test-setup trait**

```php
<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait RefreshesTenantDatabase
{
    protected static ?Tenant $testTenant = null;

    protected function setUpTenancy(): void
    {
        $central_db = config('database.connections.central.database');
        if (!str_contains($central_db, 'test')) {
            throw new RuntimeException(
                "Refusing to run tests against central database '{$central_db}' — " .
                "its name must contain 'test'. Check phpunit.xml's DB_DATABASE."
            );
        }

        $prefix = config('tenancy.database.prefix');
        if (!str_contains($prefix, 'test')) {
            throw new RuntimeException(
                "Refusing to run tests with tenant database prefix '{$prefix}' — " .
                "it must contain 'test'. Check phpunit.xml's TENANCY_DB_PREFIX."
            );
        }

        if (!static::$testTenant) {
            Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
            static::$testTenant = Tenant::create(['id' => 'test-tenant', 'name' => 'Test Tenant']);
        }

        tenancy()->initialize(static::$testTenant);

        DB::connection('central')->beginTransaction();
        DB::connection('tenant')->beginTransaction();
    }

    protected function tearDownTenancy(): void
    {
        DB::connection('tenant')->rollBack();
        DB::connection('central')->rollBack();
        tenancy()->end();
    }
}
```

**The connection name is `tenant`, not `mysql`.** stancl's `DatabaseManager::connectToTenant()` creates a connection literally named `tenant` and calls `setDefaultConnection('tenant')`; the name is hardcoded in v3 and not configurable (see Task 3). An earlier revision of this plan used `DB::connection('mysql')` here, which begins a transaction on a *different, non-tenant* connection — tenant writes would commit for real and leak into every subsequent test, while the rollback silently succeeded against an untouched connection. Getting this wrong produces order-dependent test failures that look like flakiness.

The two `str_contains(..., 'test')` checks are layer (2) from the description above — they run before any migration or query, on every single test, and throw rather than silently proceeding. `Tenant::create()` synchronously runs the full `TenantCreated` pipeline from Task 11 (`CreateDatabase`, `MigrateDatabase`, `SeedDatabase` — `shouldBeQueued(false)`), so the first test that runs creates a real `lavoro_test_test-tenant` MySQL database, migrates it with the tenant migrations from Task 8, and seeds it with `TenantDatabaseSeeder` (Task 23). It is left behind after the run — cheap to keep, and `migrate:fresh` on the next run only refreshes the central schema, so a stale tenant database from a previous run is simply reused (its migrations already match, since `MigrateDatabase` is idempotent per the framework's migration tracking). If the tenant migrations change between runs and you want a fully clean slate, drop `lavoro_test_test-tenant` manually — it is a throwaway.

- [ ] **Step 4: Use the trait in the base `TestCase`**

Replace `tests/TestCase.php`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshesTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshesTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }
}
```

- [ ] **Step 5: Remove `RefreshDatabase` from the existing test files**

Tenant-database refresh is now handled centrally by `TestCase`, so the per-file trait is redundant (and would try to migrate/refresh the single default connection using Laravel's normal single-connection logic, which doesn't know about the `central` connection at all).

```bash
grep -rl "RefreshDatabase" tests/   # 35 files as of 2026-07-20
```

In each matching file, remove the `use Illuminate\Foundation\Testing\RefreshDatabase;` import and the `use RefreshDatabase;` trait line inside the test class. Leave everything else in those files untouched.

Expect to run the suite iteratively here rather than in one pass — see the two isolation caveats at the top of this task. Convert the files, run `composer test`, and fix fallout in the tests rather than weakening the isolation in `TestCase`.

- [ ] **Step 6: Set up the local/CI test database and user (operational, run once)**

```sql
CREATE DATABASE IF NOT EXISTS lavoro_test_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'lavoro_test'@'127.0.0.1' IDENTIFIED BY 'lavoro_test';
GRANT ALL PRIVILEGES ON `lavoro\_test\_%`.* TO 'lavoro_test'@'127.0.0.1';
FLUSH PRIVILEGES;
```

The escaped underscores in `lavoro\_test\_%` matter — an unescaped `_` is a MySQL wildcard that would also match e.g. `lavoroXtest_evil`. This user cannot create, read, or drop `lavoro` or any `lavoro_<tenant-id>` database — that is layer (3) from the description above, and it holds even if every application-level check in Step 3 were somehow bypassed.

- [ ] **Step 7: Run the suite and verify**

```bash
composer test
```

Expected: all previously-passing tests still pass, running against `lavoro_test_central` and a `lavoro_test_test-tenant` tenant database. Confirm with `SHOW DATABASES LIKE 'lavoro%';` that no test run ever created or touched `lavoro` itself.

- [ ] **Step 8: Commit**

```bash
git add phpunit.xml tests/Concerns/RefreshesTenantDatabase.php tests/TestCase.php tests/
git commit -m "test(tenancy): run the suite against isolated, clearly-named MySQL test databases"
```

---

## Task 31: Enforce module subscriptions on gated features

Task 16 built the data model (`tenants.modules`), the `Tenant::hasModule()` check, the shared `tenant` Inertia prop, and the `hasModule()` JS helper — but nothing consumed them, so a tenant without e.g. the `tickets` module could still use every ticket route. This task wires the actual gates: a backend route middleware (authoritative — this is what actually blocks access) plus frontend nav/UI hiding using the existing helper (a UX nicety, not the security boundary).

Per CLAUDE.md, authorization belongs in Form Requests/policies, not ad-hoc controller checks — module gating is a tenancy/subscription concern that sits a layer above per-user permissions, so it's implemented as route middleware (the same pattern already used for `tenant.api` in Task 24), applied on top of the existing `auth` group and permission checks, not instead of them.

**Files:**
- `app/Http/Middleware/EnsureTenantHasModule.php` (new)
- `bootstrap/app.php`
- `routes/web.php`, `routes/api.php`
- `app/Http/Controllers/ServiceOrderController.php:326` (the only remaining `snelStartEnabled` flag)
- `resources/js/Composables/useSidebarNav.js`
- `resources/js/Components/GoogleCalendarSection.vue`
- `resources/js/Pages/Admin/GeneralSettingsPage.vue`

> **All line numbers below were re-verified 2026-07-20 and this file moves constantly.** Locate each route by its name/controller rather than by line number.

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Enums\TenantModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantHasModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(TenantModule::tryFrom($module) !== null, 500, "Onbekende module '{$module}'.");

        abort_unless(
            tenancy()->initialized && tenancy()->tenant->hasModule($module),
            403,
            'Deze functie is niet beschikbaar in uw abonnement.'
        );

        return $next($request);
    }
}
```

- [ ] **Step 2: Register the alias in `bootstrap/app.php`**, next to `tenant.api` from Task 24

```php
$middleware->alias([
    'admin'        => EnsureUserIsAdmin::class,
    'tenant.api'   => \App\Http\Middleware\InitializeTenancyForApi::class,
    'tenant.module' => \App\Http\Middleware\EnsureTenantHasModule::class,
]);
```

- [ ] **Step 3: Apply it to the module-gated route groups in `routes/web.php`**

Inside the existing `auth` group:

Tickets (lines ~164-168) — wrap the three ticket routes:

```php
Route::middleware('tenant.module:tickets')->group(function () {
    Route::post('tickets/bulk-update', [TicketController::class, 'bulkUpdate'])
        ->name('tickets.bulk-update');
    Route::get('tickets/map', [TicketController::class, 'map'])
        ->name('tickets.map');
    Route::resource('tickets', TicketController::class);
});
```

SnelStart — there are exactly **two** SnelStart routes today (lines ~230 and ~243); the `imports/snelstart/customers` route the previous revision listed no longer exists. They are not adjacent, so either wrap each individually or apply the middleware inline:

```php
Route::post('imports/snelstart/materials', [SnelStartImportController::class, 'importMaterials'])
    ->middleware('tenant.module:snelstart')
    ->name('imports.snelstart.materials');

Route::post('serviceorders/{serviceorder}/send-snelstart', [ServiceOrderController::class, 'sendToSnelStart'])
    ->middleware('tenant.module:snelstart')
    ->name('serviceorders.sendToSnelStart');
```

Note SnelStart *customer* import now happens through the generic Excel import (`CustomerImportController::looksLikeSnelStartExport`, auto-detecting a SnelStart export format from the file header). That is offline file parsing with no SnelStart API involvement, so it is deliberately **not** module-gated — gating it would block a plain spreadsheet upload.

Projects (lines ~314-316) — keep the existing registration order when wrapping:

```php
Route::middleware('tenant.module:projects')->group(function () {
    Route::resource('projects', ProjectController::class);
    Route::get('projects/{project}/timeline', [ProjectController::class, 'timeline'])
        ->name('projects.timeline');
    Route::resource('projectmilestones', ProjectMilestoneController::class);
});
```

Google Calendar (lines ~343-348):

```php
Route::middleware('tenant.module:google_calendar')->group(function () {
    Route::get('google/oauth/start', [GoogleOAuthController::class, 'start'])
        ->name('google.oauth.start');
    Route::get('google/oauth/callback', [GoogleOAuthController::class, 'callback'])
        ->name('google.oauth.callback');
    Route::delete('google/integration', [GoogleOAuthController::class, 'destroy'])
        ->name('google.integration.destroy');
});
```

Location tracking — inside the nested `admin` group (line ~354), wrap the settings route at lines ~381-384:

```php
Route::put('admin/settings/location-tracking', [GeneralSettingsController::class, 'updateLocationTracking'])
    ->middleware('tenant.module:location_tracking')
    ->name('admin.settings.location-tracking');
```

(Use the controller/action already on that route — copy it from the current lines 381-384 rather than retyping the signature from scratch, since the exact method name should match what's there today.)

Also add the same `tenant.module:google_calendar` middleware to the `api.google.integration.status` route in `routes/api.php` (`GET google/integration/status`, an invokable `GoogleIntegrationStatusController`, inside the `tenant.api` group from Task 24), so the status check itself 403s for tenants without the module rather than reporting "not connected".

- [ ] **Step 4: Gate the SnelStart UI at the source — extend the existing `snelStartEnabled` flag**

There is now exactly **one** `snelStartEnabled` producer, `ServiceOrderController.php:326` (the previous revision listed three; `CustomerController` and `CustomerImportController` no longer expose it):

```php
'snelStartEnabled' => filled(config('services.snelstart.client_key')),
```

Change it to also require the module:

```php
'snelStartEnabled' => filled(config('services.snelstart.client_key'))
    && tenancy()->initialized
    && tenancy()->tenant->hasModule('snelstart'),
```

This reuses the exact prop `ServiceOrders/ShowPage.vue` already gates its SnelStart button on (`v-if="snelStartEnabled && hasPermission('snelstart.send_serviceorder')"`, line 448) — no frontend changes needed for SnelStart. The materials-import button is gated by the route middleware from Step 3 alone.

- [ ] **Step 5: Gate the Tickets and Projects nav items**

Navigation moved out of `MainLayout.vue` in the 2026-07 sidebar redesign — it now lives in **`resources/js/Composables/useSidebarNav.js`**. Add `requiresModule` next to the existing `requiresPermission` on these two entries (lines ~116 and ~172):

```js
{ name: 'Storingen', href: '/tickets', icon: ExclamationCircleIcon, current: false, requiresPermission: 'ticket.see_all', requiresModule: 'tickets' },
```

```js
{ name: 'Projecten', href: '/projects', icon: ClipboardDocumentListIcon, current: false, requiresPermission: 'project.read', requiresModule: 'projects' },
```

Extend `canSeeNavItem` (line ~179) to also check it. The module check goes **first**, before the `adminOnly` branch: a module is a subscription boundary, not a permission, so an admin of a tenant that doesn't pay for Projecten must not see it either. (Contrast `hasPermission`, which deliberately returns `true` for admins.)

```js
import { hasModule, hasPermission, initials as getInitials } from '@/Utilities/Utilities'

const canSeeNavItem = (item) => {
    if (item?.requiresModule && !hasModule(item.requiresModule)) return false
    if (item?.adminOnly) return isAdmin.value
    if (item?.requiresAnyPermission) return item.requiresAnyPermission.some(hasPermission)
    if (!item?.requiresPermission) return true
    return hasPermission(item.requiresPermission)
}
```

`canSeeNavItem` is reused by `filteredNavigation`, `filteredLists` and the children filter (lines ~186-195), so this one change covers nested entries too. The file currently imports `{ hasPermission, initials as getInitials }` on line 3 — extend that import rather than adding a second one.

- [ ] **Step 6: Gate the Google Calendar section and location-tracking settings**

In `resources/js/Components/GoogleCalendarSection.vue`, import `hasModule` from `@/Utilities/Utilities` and wrap the section's root template element in `v-if="hasModule('google_calendar')"`.

In `resources/js/Pages/Admin/GeneralSettingsPage.vue`, do the same around the location-tracking settings block, using `hasModule('location_tracking')`.

- [ ] **Step 7: Verify**

- As a tenant without the `tickets` module: `GET /tickets` returns 403; the "Storingen" nav item does not render.
- `php artisan tenant:modules <id> --add=tickets`, reload: the route works and the nav item appears.
- Same pattern for `projects`, `google_calendar`, `snelstart`, `location_tracking`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/EnsureTenantHasModule.php bootstrap/app.php routes/web.php routes/api.php \
        app/Http/Controllers/ServiceOrderController.php \
        resources/js/Composables/useSidebarNav.js resources/js/Components/GoogleCalendarSection.vue \
        resources/js/Pages/Admin/GeneralSettingsPage.vue
git commit -m "feat(tenancy): enforce module subscriptions on gated routes and UI"
```

---

## Task 32: Per-tenant Microsoft Graph mail credentials

Today `Mail::extend('graph', ...)` (`AppServiceProvider.php:83`) builds the transport entirely from `config('services.graph.*')`, which reads global `GRAPH_TENANT_ID` / `GRAPH_CLIENT_ID` / `GRAPH_CLIENT_SECRET` / `GRAPH_USER_ID` / `GRAPH_ENDPOINT` env vars — every tenant sends mail through the same Azure app registration and mailbox. Move these into each tenant's `general_settings` table (already a tenant-scoped table per the top-of-plan table, using the existing `GeneralSetting::get`/`set` key-value helper — the same mechanism Known impact point 4 recommends), read per-request from the active tenant, and fall back to the global env values when a tenant hasn't configured its own mailbox yet, so nothing regresses for the current single tenant during the Task 27 migration.

**The subtlety this task exists to handle:** `Illuminate\Mail\MailManager` caches a resolved `graph` transport instance for the lifetime of the container. In a classic PHP-FPM request that's harmless (one tenant per request/process). On a queue worker processing jobs for multiple tenants in sequence without restarting, the *first* tenant's credentials would get cached and silently reused for a *later* tenant's queued mail (e.g. `SendStandardEmailJob`) in the same worker process. This is fixed by forgetting the resolved mailer whenever tenancy switches, mirroring the `forgetDriver`/`forgetDisk` pattern already used by `PrefixCacheBootstrapper` (Task 10) and `TenantStorageBootstrapper` (Task 14).

**Files:** `app/Providers/AppServiceProvider.php`, `app/Providers/TenancyServiceProvider.php`

- [ ] **Step 1: Rewrite the `Mail::extend('graph', ...)` closure**

In `AppServiceProvider::boot()`, replace the existing closure:

```php
Mail::extend('graph', function () {
    $tenant_setting = fn (string $key, string $config_key) => tenancy()->initialized
        ? GeneralSetting::get($key, config("services.graph.{$config_key}"))
        : config("services.graph.{$config_key}");

    return new GraphTransport(
        tenantId: $tenant_setting('graph_tenant_id', 'tenant_id'),
        clientId: $tenant_setting('graph_client_id', 'client_id'),
        clientSecret: $tenant_setting('graph_client_secret', 'client_secret'),
        fromAddress: config('mail.from.address'),
        userId: $tenant_setting('graph_user_id', 'user_id'),
        graphEndpoint: $tenant_setting('graph_endpoint', 'endpoint'),
        dispatcher: app('events'),
        logger: app('log')->channel()
    );
});
```

Add `use App\Models\GeneralSetting;` to the file's imports.

- [ ] **Step 2: Forget the cached mailer whenever tenancy switches**

In `TenancyServiceProvider::boot()` (Task 11), add listeners for both tenancy events next to the existing ones:

```php
Event::listen(TenancyInitialized::class, function () {
    app('mail.manager')->forgetMailers();
});
Event::listen(TenancyEnded::class, function () {
    app('mail.manager')->forgetMailers();
});
```

These run in addition to (not instead of) the existing `BootstrapTenancy` / `RevertToCentralContext` listeners already registered for those events — Laravel dispatches all listeners for an event, order doesn't matter here since both only affect mailer resolution, not tenancy state itself.

- [ ] **Step 3: Set a tenant's Graph credentials via `GeneralSetting`**

No new CLI command — `general_settings` is a plain key-value tenant table, so this is done the same way any other tenant setting is set today (e.g. through `php artisan tinker` while tenancy is initialized for that tenant, or a future admin UI). Document the four keys tenants can override: `graph_tenant_id`, `graph_client_id`, `graph_client_secret`, `graph_user_id` (optional — omit to send as the shared mailbox), `graph_endpoint` (optional — omit to use the default `https://graph.microsoft.com/v1.0`).

- [ ] **Step 4: Verify**

- With no tenant-specific settings: mail still sends via the global `GRAPH_*` env credentials (unchanged behavior).
- Set `graph_tenant_id`/`graph_client_id`/`graph_client_secret` via `GeneralSetting::set()` for the test tenant from Task 28, send a test mail (`php artisan` equivalent of `TestGraphMail`), confirm it authenticates against the tenant-specific Azure app registration (check the Microsoft Entra sign-in log for that tenant ID, or deliberately misconfigure the secret and confirm the send fails with that tenant's error rather than silently succeeding via the global credentials).
- Dispatch two queued `SendStandardEmailJob`s for two different tenants with different Graph credentials back-to-back on the same worker process; confirm both use their own tenant's credentials (this is the scenario Step 2 fixes — without it, the second job would silently reuse the first tenant's transport).

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php app/Providers/TenancyServiceProvider.php
git commit -m "feat(tenancy): resolve Microsoft Graph mail credentials per tenant"
```

---

## Known impact and follow-up work

1. ~~Existing test suite will break.~~ **Resolved by Task 30.** `phpunit.xml` moves from SQLite `:memory:` to a dedicated MySQL test database (`lavoro_test_central` plus a `lavoro_test_`-prefixed tenant database), with a hard runtime assertion and a narrowly-grants-only MySQL user as two independent layers ensuring a misconfiguration cannot make a test run reach `lavoro` or a real customer database. (Vitest frontend tests are unaffected.)

2. **Login page shows no per-tenant branding.** It already renders the static Lavoro logo today, so nothing regresses; but per-tenant branding before login would require a two-step login (email → resolve tenant → branded password step).

3. **File access is authenticated but not permission-scoped.** Task 14 serves files only to logged-in users of the owning tenant (cross-tenant ids 404 via model binding), which already closes the old world-readable hole. It does not yet apply per-resource permission checks — every authenticated user in the tenant can fetch any file id in that tenant. Adding policy checks in `FileController` is a reasonable follow-up if finer-grained access is required. Relatedly, `Storage::response()` sends no cache-control headers; if browser caching of served files ever becomes a concern, add `Cache-Control: private` in `FileController`.

4. **SnelStart and Firebase (FCM) credentials are still global env vars.** ~~Microsoft Graph~~ Microsoft Graph mail is **resolved per tenant as of Task 32** (tenant `general_settings` keys, falling back to the global env credentials for tenants that haven't configured their own mailbox). SnelStart and Firebase remain shared across all tenants — the same `general_settings` pattern used for Graph in Task 32 applies directly if/when per-tenant SnelStart or FCM credentials are needed; until then SnelStart access is already gated per tenant by the `snelstart` module subscription (Task 31).

5. ~~Module subscriptions are stored but not yet enforced.~~ **Resolved by Task 31.** The `tenant.module` route middleware gates tickets, projects, SnelStart imports/send, and Google Calendar OAuth routes; the `snelStartEnabled` Inertia props and the Tickets/Projects nav items in `useSidebarNav.js` are gated the same way on the frontend. Extending the same middleware to further routes as new module-gated features are added is a one-line addition per route group, not new plumbing.

6. ~~Scheduler scales linearly with tenant count.~~ **Mitigated by Task 20.** Every scheduled tick now only dispatches one queued job per tenant (a config swap plus a single `INSERT` into the central `jobs` table) instead of running a query or delete inline per tenant, so tick cost no longer scales with each tenant's data volume — only with tenant *count*, which is cheap. If tenant count itself grows into the hundreds and the dispatch loop alone becomes the bottleneck, chunking the central tenant list (already using `cursor()` rather than `get()`) or splitting the loop across multiple scheduled entries are the next levers.

7. **Middleware ordering is load-bearing and invisible.** Task 12 pins the tenancy initializers into `$middleware->priority()`. Nothing enforces that a future middleware addition preserves it, and getting it wrong presents as mass 404s that look like a routing bug. If this bites twice, a cheap feature test — hit a bound-model route as a tenant user and assert 200 — is worth more than a comment.

8. **`storage_path()` is a footgun for the lifetime of this codebase.** Task 14 fixes the six current offenders, but nothing prevents new code from writing `storage_path('app/public/…')` again, and the failure is silent (a missing file reads as "no image"). Consider a Pint/PHPStan rule or a grep in CI over `app/` and `resources/views/` for `storage_path('app/` once tenancy is live.

9. **Test isolation changed shape.** Task 30 swaps `RefreshDatabase`'s truncate-and-remigrate for transaction rollback across two connections. Auto-increment ids no longer reset between tests, and any code under test that commits (DDL, explicit transactions) escapes the wrapper. Expect some churn in the 35 converted test files.

10. **Future bearer-token API clients.** No `createToken()` call exists today — all API auth is stateful Sanctum cookies, which Task 24 covers via the session. If a native client later moves to bearer tokens, add a `POST /api/login` (without `tenant.api`) that resolves the tenant from the email, issues the token, and returns the `tenant_id` for the client to send as `X-Tenant-ID` — the fallback in Task 24's middleware already accepts it.
