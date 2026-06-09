# Multi-Database Multi-Tenancy Implementation Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Add multi-database multi-tenancy to Lavoro using `stancl/tenancy` v3, where a single domain serves all client companies and the correct database is chosen based on who logs in.

**How it works, in plain terms:**

Today there is one database for everything. After this change there is one small *central* database plus one full database per client company (a "tenant"). When someone logs in, we look up their email in the central database to find which company they belong to, switch every database query to that company's database for the rest of the request, and remember the company in their session for later requests.

**What lives where:**

Central database (small, shared infrastructure):
- `tenants` — one row per client company
- `user_tenant_lookups` — maps an email to a tenant, used only when logging in
- `jobs`, `job_batches`, `failed_jobs` — the queue, so the worker can read jobs without first knowing the tenant
- `cache`, `cache_locks` — shared store; entries are isolated per tenant by a key prefix
- `sessions` — must be readable before we know the tenant, so it stays central

Tenant database (one per client company, fully isolated):
- `users`, `password_reset_tokens`
- Every business table (customers, assets, service orders, tickets, etc.)
- `roles`, `permissions`, `companies`, `general_settings`, Google integration tables — everything else

Uploaded files are fully separated on disk too: each tenant's files live under their own root, `storage/tenant-<id>/public/...` and `storage/tenant-<id>/local/...`, and are served only through authenticated controllers (never a public URL). See Task 14.

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

The bootstrappers list is deliberate: the package's `DatabaseTenancyBootstrapper` and `QueueTenancyBootstrapper` are used as-is, but instead of the package's tag-based cache bootstrapper we register our own prefix-based one (built in Task 10), and we do not use the filesystem bootstrapper (file isolation is done by path prefix in Task 14).

**Files:** `config/tenancy.php`

- [ ] **Step 1: Replace the entire file**

```php
<?php

use App\Tenancy\FilesystemTenancyBootstrapper;
use App\Tenancy\PrefixCacheBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

return [
    'tenant_model' => App\Models\Tenant::class,

    'central_domains' => [],

    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        PrefixCacheBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => 'central',
        'template_tenant_connection' => env('DB_CONNECTION', 'mysql'),
        'tenant_connection_name' => null,
        'prefix' => 'lavoro_',
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

Represents one client company; lives in the central database. The MySQL database name is stored in the JSON `data` column under `tenancy_db_name`.

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

    public static function getCustomColumns(): array
    {
        return ['id', 'name'];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Tenant.php
git commit -m "feat(tenancy): add Tenant model"
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

Both explicitly target the `central` connection. The `email` primary key is what enforces global email uniqueness across tenants.

**Files:**
- `database/migrations/2026_06_09_000001_create_tenants_table.php`
- `database/migrations/2026_06_09_000002_create_user_tenant_lookups_table.php`

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
git add database/migrations/2026_06_09_000001_create_tenants_table.php \
        database/migrations/2026_06_09_000002_create_user_tenant_lookups_table.php
git commit -m "feat(tenancy): add central DB migrations"
```

---

## Task 7: Move the `sessions` table into the central database

The session is read at the very start of every request, before we know the tenant. So the `sessions` table must be in the central database, and the session driver must be pointed at the `central` connection.

The `sessions` table is currently created inside the framework users migration. We remove it from there and create it as its own central migration.

**Files:**
- `database/migrations/0001_01_01_000000_create_users_table.php` (remove the sessions block)
- `database/migrations/2026_06_09_000003_create_sessions_table.php` (new, central)
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

`config/session.php` already reads `'connection' => env('SESSION_CONNECTION')`, so no config edit is needed. `SESSION_DRIVER=database` stays as-is.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/0001_01_01_000000_create_users_table.php \
        database/migrations/2026_06_09_000003_create_sessions_table.php \
        .env.example
git commit -m "feat(tenancy): move sessions table to central connection"
```

---

## Task 8: Split migrations into central and tenant directories

After this:
- `database/migrations/` holds only central migrations: cache, jobs, tenants, user_tenant_lookups, sessions. `php artisan migrate` runs these against the central database.
- `database/migrations/tenant/` holds everything else (~143 files). `php artisan tenants:migrate` runs these against each tenant database. Plain `migrate` does not descend into subdirectories, so these are correctly excluded from the central run.

`0001_01_01_000000_create_users_table.php` (now just users + password_reset_tokens after Task 7) moves to tenant. The cache and jobs framework migrations stay central.

**Files:** move ~143 migration files.

- [ ] **Step 1: Move the files**

```bash
mkdir -p database/migrations/tenant

git mv database/migrations/0001_01_01_000000_create_users_table.php database/migrations/tenant/

for f in database/migrations/2025_*.php; do
  git mv "$f" database/migrations/tenant/
done

for f in database/migrations/2026_*.php; do
  base=$(basename "$f")
  if [[ "$base" != "2026_06_09_000001_create_tenants_table.php" && \
        "$base" != "2026_06_09_000002_create_user_tenant_lookups_table.php" && \
        "$base" != "2026_06_09_000003_create_sessions_table.php" ]]; then
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
# 2026_06_09_000001_create_tenants_table.php
# 2026_06_09_000002_create_user_tenant_lookups_table.php
# 2026_06_09_000003_create_sessions_table.php

ls database/migrations/tenant/ | wc -l   # ~143
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/
git commit -m "feat(tenancy): split migrations into central and tenant directories"
```

---

## Task 9: Pin the queue to the central database

Jobs must always be stored centrally so the worker finds them regardless of tenant context.

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

## Task 12: Session-based tenancy middleware

On every web request, after the session is read, switch to the tenant stored in the session. Always end tenancy after the response so the connection is not left switched (matters for long-running workers like Octane).

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
        $tenant_id = session('tenant_id');

        if ($tenant_id) {
            $tenant = Tenant::on('central')->find($tenant_id);
            if ($tenant) {
                tenancy()->initialize($tenant);
            } else {
                session()->forget('tenant_id');
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

- [ ] **Step 2: Add to the web stack in `bootstrap/app.php`, before `HandleInertiaRequests`**

```php
$middleware->web(append: [
    \App\Http\Middleware\InitializeTenancyBySession::class,
    HandleInertiaRequests::class,
]);
```

It must run before `HandleInertiaRequests` because Inertia shares `Auth::user()`, which queries the database.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/InitializeTenancyBySession.php bootstrap/app.php
git commit -m "feat(tenancy): initialize tenant from session on web requests"
```

---

## Task 13: Guard the company Inertia share

`AppServiceProvider` shares company data on every Inertia response, including the login page where no tenant is active. Querying `Company` then hits the central database, which has no `companies` table, and crashes. Return `null` when tenancy is not initialized. The logo URL points at the authenticated file route from Task 14 (not a public `/storage` path), so it resolves per tenant.

**Files:** `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Add the guard at the top of the `Inertia::share('company', ...)` closure**

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

Each tenant gets a completely separate storage root: `storage/tenant-<id>/public/...` and `storage/tenant-<id>/local/...`. A custom filesystem bootstrapper repoints the `public` and `local` disk roots into the active tenant's folder whenever tenancy is initialized, so **upload code needs no changes** — `->store('uploaded/...', 'public')` automatically lands inside the tenant's folder, and the stored `path` stays relative (no tenant prefix in the database).

Because files now live outside the web-served `public/storage` symlink, they are no longer reachable by URL. Instead, three small authenticated routes stream them through controllers. Tenant isolation is automatic: a file id from another tenant does not exist in this tenant's database, so route-model binding returns 404. (Documents already download through `DocumentController::download`, which uses `Storage::disk('public')` and therefore works unchanged — no document route is added here.)

**Files:**
- `app/Tenancy/FilesystemTenancyBootstrapper.php` (new)
- `app/Http/Controllers/FileController.php` (new)
- `routes/web.php`
- `app/Models/User.php` (avatar accessor)
- The ~10 Vue files that hardcode `/storage/${...}` (images and company logos)

- [ ] **Step 1: Create the filesystem bootstrapper**

This repoints the disk roots only — it deliberately does **not** call `useStoragePath`, so framework storage (logs, compiled views, framework cache) stays in the normal location; only uploaded-file disks move per tenant.

```php
<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class FilesystemTenancyBootstrapper implements TenancyBootstrapper
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

In `app/Models/User.php`, the avatar accessor currently ends with `return Storage::url($files[0]);`. Keep the existence checks (so it still returns `null` when no avatar exists and the UI shows initials), but return the authenticated route instead:

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

- [ ] **Step 5: Update the frontend to use the file routes instead of `/storage/`**

Find every hardcoded reference:

```bash
grep -rn "/storage/" resources/js/
```

Apply these conversions across the matching files (`Assets/IndexPage.vue`, `Assets/ShowPage.vue`, `Products/IndexPage.vue`, `Products/ShowPage.vue`, `ServiceOrders/ShowPage.vue`, `Components/Timeline/TimelineComponent.vue`, `Components/CustomerUpcomingActivity.vue`, `Components/ImageUploadComponent.vue`, `Companies/IndexPage.vue`, `Companies/Partials/EditCompanyModal.vue`):

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

- In `ImageUploadComponent.vue`, a *freshly uploaded* preview may use a local object URL or a path returned from the upload response before an `Image` id exists. Leave object-URL previews as-is; for previews of already-saved images, use `/files/images/${image.id}`. Check each usage in this file specifically.

- [ ] **Step 6: Commit**

```bash
git add app/Tenancy/FilesystemTenancyBootstrapper.php \
        app/Http/Controllers/FileController.php \
        routes/web.php \
        app/Models/User.php \
        resources/js/
git commit -m "feat(tenancy): per-tenant storage roots with authenticated file serving"
```

---

## Task 15: Update the login controller

Look up the tenant from the email, switch to its database, then authenticate. Also remove the `exists:users,email` rule from the form request — it runs before tenancy is initialized and would query the central database, which has no `users` table.

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
        return inertia('Auth/LoginPage', ['company' => null]);
    }

    public function store(StoreUpdateAuthRequest $request)
    {
        $lookup = UserTenantLookup::where('email', $request->email)->first();

        if (!$lookup) {
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        $tenant = Tenant::on('central')->findOrFail($lookup->tenant_id);
        tenancy()->initialize($tenant);

        if (!Auth::attempt($request->only('email', 'password'), false)) {
            tenancy()->end();
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        session(['tenant_id' => $tenant->id]);
        $request->session()->regenerate();

        return redirect()->intended();
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();

        return redirect()->route('login');
    }
}
```

`remember` is `false`; persistent login is out of scope (see limitations).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/AuthController.php app/Http/Requests/StoreUpdateAuthRequest.php
git commit -m "feat(tenancy): resolve tenant before authenticating on login"
```

---

## Task 16: Handle the null company prop on the login page

`AuthController::create()` now always passes `company: null`, and `Inertia::share('company')` is null for unauthenticated pages. The login page must not assume a company object.

**Files:** `resources/js/Pages/Auth/LoginPage.vue`

- [ ] **Step 1: Add null guards in the template**

Wherever the component uses `company.logo_url` or `company.name`, use optional chaining and `v-if`:

```vue
<img v-if="company?.logo_url" :src="company.logo_url" alt="Logo" />
<span v-if="company?.name">{{ company.name }}</span>
```

The `defineProps({ company: Object })` declaration already accepts `null`; only the template needs guarding.

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Auth/LoginPage.vue
git commit -m "fix(login): handle null company before tenant is known"
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

    public function deleted(User $user): void
    {
        UserTenantLookup::on('central')->where('email', $user->email)->delete();
    }
}
```

- [ ] **Step 2: Register it in `AppServiceProvider::boot()`**

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

The lookup table's email primary key would throw a raw SQL error if an admin created a user whose email already exists in another tenant. Validate it cleanly instead, in the form requests used by the user create/update routes.

First confirm which requests the routes use:

```bash
grep -nE "users|User" routes/web.php | grep -iE "store|update|post|put|resource"
```

The repository has both `StoreUserRequest`/`UserStoreRequest` and `UpdateUserRequest`/`UserUpdateRequest`. Apply the rule to whichever pair the user routes actually reference.

**Files:** the user store request and the user update request.

- [ ] **Step 1: Add a global-uniqueness rule to the store request `rules()`**

```php
use Illuminate\Validation\Rule;

// inside rules():
'email' => [
    'required', 'email',
    Rule::unique('central.user_tenant_lookups', 'email'),
],
```

The `central.` prefix tells the validator to query the central connection.

- [ ] **Step 2: Add the same rule to the update request, ignoring the user's current email**

```php
use Illuminate\Validation\Rule;

// inside rules(); $this->route('user') is the User being edited:
'email' => [
    'required', 'email',
    Rule::unique('central.user_tenant_lookups', 'email')
        ->ignore($this->route('user')?->email, 'email'),
],
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/
git commit -m "feat(tenancy): validate email is globally unique across tenants"
```

---

## Task 20: Make scheduled tasks run per tenant

Loop over all tenants, switch into each, run the work, switch out. The Google jobs are dispatched normally (not `dispatchSync`) — the `QueueTenancyBootstrapper` records the active tenant in the job payload and re-initializes it on the worker, so dispatching async keeps the scheduler fast and the job runs in the correct tenant context.

**Files:** `routes/console.php`

- [ ] **Step 1: Rewrite `routes/console.php`**

```php
<?php

use App\Jobs\Google\PullCalendarChangesJob;
use App\Jobs\Google\RenewWatchChannelsJob;
use App\Models\GoogleSyncedCalendar;
use App\Models\LocationPing;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Tenant::on('central')->get()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);

        GoogleSyncedCalendar::query()
            ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->pluck('id')
            ->each(fn ($id) => PullCalendarChangesJob::dispatch($id));

        tenancy()->end();
    });
})->everyFiveMinutes()->name('google-pull-changes')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->get()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        RenewWatchChannelsJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('google-renew-watches')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->get()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        LocationPing::where('recorded_at', '<', now()->subDay())->delete();
        tenancy()->end();
    });
})->hourly()->name('prune-location-pings')->withoutOverlapping();
```

- [ ] **Step 2: Commit**

```bash
git add routes/console.php
git commit -m "feat(tenancy): run scheduled tasks per tenant"
```

---

## Task 21: `tenant:create` command (with initial admin)

Creates the tenant record (which fires the create→migrate→seed pipeline), then creates an initial admin user inside the new tenant so the company can actually log in. Creating the user fires the observer, which writes the central lookup row.

**Files:** `app/Console/Commands/CreateTenant.php`

- [ ] **Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create {name} {admin_email} {--database=} {--admin-password=}';
    protected $description = 'Create a tenant, its database, and an initial admin user';

    public function handle(): void
    {
        $name     = $this->argument('name');
        $email    = $this->argument('admin_email');
        $database = $this->option('database') ?: 'lavoro_' . Str::slug($name, '_');
        $password = $this->option('admin-password') ?: Str::password(16);

        $this->info("Creating tenant '{$name}' (database {$database})...");

        $tenant = Tenant::create([
            'id'              => (string) Str::ulid(),
            'name'            => $name,
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
        $this->info("Admin: {$email}");
        $this->info("Password: {$password}");
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

        $stages = [
            ['name' => 'Nieuw',    'order' => 1, 'is_plannable_state' => true,  'is_planned_state' => false, 'is_closed_state' => false, 'is_planning_cancelled_state' => false],
            ['name' => 'Gepland',  'order' => 2, 'is_plannable_state' => false, 'is_planned_state' => true,  'is_closed_state' => false, 'is_planning_cancelled_state' => false],
            ['name' => 'Gesloten', 'order' => 3, 'is_plannable_state' => false, 'is_planned_state' => false, 'is_closed_state' => true,  'is_planning_cancelled_state' => false],
        ];

        foreach ($stages as $stage) {
            ServiceOrderStage::firstOrCreate(['name' => $stage['name']], $stage);
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

## Task 24: Sanctum API — tenant from `X-Tenant-ID` header

API routes use `auth:sanctum`, which validates the bearer token against `personal_access_tokens` in the tenant database. To know which database before Sanctum runs, clients send `X-Tenant-ID`. Applied as a **named route middleware** (not a global prepend) so routes that must skip it (an API login endpoint, the Google webhook) can. The Inertia SPA also calls `/api/*`, so it sets the header from a shared Inertia prop.

**Files:** `app/Http/Middleware/InitializeTenancyForApi.php`, `bootstrap/app.php`, `routes/api.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/app.js`

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
        $tenant_id = $request->header('X-Tenant-ID');

        if (!$tenant_id) {
            return response()->json(['message' => 'X-Tenant-ID header is required.'], 400);
        }

        $tenant = Tenant::on('central')->find($tenant_id);
        if (!$tenant) {
            return response()->json(['message' => 'Unknown tenant.'], 400);
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

- [ ] **Step 3: Apply it to the authenticated API routes in `routes/api.php`**

Wrap the existing `auth:sanctum` route groups so `tenant.api` runs first:

```php
Route::middleware(['tenant.api', 'auth:sanctum'])->group(function () {
    // all existing authenticated API routes
});
```

Leave out `tenant.api` for any future API-login route and for the Google webhook (Task 25).

- [ ] **Step 4: Share `tenant_id` as an Inertia prop**

In `app/Http/Middleware/HandleInertiaRequests.php`, add to the `share()` array:

```php
'tenant_id' => fn () => session('tenant_id'),
```

- [ ] **Step 5: Set the Axios default header in `resources/js/app.js`**

Inside the Inertia `createInertiaApp({ setup({ el, App, props, plugin }) { ... } })` callback, before `app.mount(el)`, read the tenant id from the initial page props:

```js
import axios from 'axios'

// inside setup(), before mount:
const tenant_id = props.initialPage.props.tenant_id
if (tenant_id) {
    axios.defaults.headers.common['X-Tenant-ID'] = tenant_id
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/InitializeTenancyForApi.php \
        bootstrap/app.php routes/api.php \
        app/Http/Middleware/HandleInertiaRequests.php \
        resources/js/app.js
git commit -m "feat(tenancy): resolve tenant from X-Tenant-ID for API requests"
```

---

## Task 25: Route the Google webhook to the right tenant

The webhook arrives from Google with no session and no token. Embed the tenant id in the watch-channel token when creating the channel; Google returns it as `X-Goog-Channel-Token` on each notification.

**Files:** `app/Jobs/Google/RenewWatchChannelsJob.php` (channel creation), `app/Http/Controllers/GoogleWebhookController.php`

- [ ] **Step 1: Set the channel token to the tenant id where the watch channel is created**

```php
$channel->setToken(tenancy()->tenant->getTenantKey());
```

- [ ] **Step 2: Initialize tenancy from the header at the top of the webhook handler**

```php
$tenant_id = $request->header('X-Goog-Channel-Token');
if ($tenant_id) {
    $tenant = \App\Models\Tenant::on('central')->find($tenant_id);
    if ($tenant) {
        tenancy()->initialize($tenant);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/Google/RenewWatchChannelsJob.php app/Http/Controllers/GoogleWebhookController.php
git commit -m "feat(tenancy): route Google webhook to tenant via channel token"
```

---

## Task 26: `tenant:setup-existing` — register the current database

Registers the existing database as tenant #1, copies its user emails into the central lookup, and prepares the file-path data migration. Uses a direct insert to skip the `TenantCreated` pipeline, since the database already exists and is already migrated.

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
    protected $description = 'Register the existing database as the first tenant (one-time)';

    public function handle(): void
    {
        $id = (string) Str::ulid();

        DB::connection('central')->table('tenants')->insert([
            'id'         => $id,
            'name'       => $this->argument('name'),
            'data'       => json_encode(['tenancy_db_name' => $this->argument('database')]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenant = Tenant::on('central')->findOrFail($id);
        tenancy()->initialize($tenant);

        $count = 0;
        User::query()->select('email')->cursor()->each(function (User $user) use ($id, &$count) {
            UserTenantLookup::on('central')->updateOrCreate(
                ['email' => $user->email],
                ['tenant_id' => $id]
            );
            $count++;
        });

        tenancy()->end();

        $this->info("Registered '{$this->argument('name')}' as tenant: {$id}");
        $this->info("Populated {$count} email lookups.");
        $this->warn("Now migrate existing files into tenants/{$id}/ — see deployment Task 27, Step 6.");
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

- [ ] **Step 2: Run the central migrations**

```bash
php artisan migrate --force
```

Creates `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `tenants`, `user_tenant_lookups`, `migrations` in the central database.

- [ ] **Step 3: Drop the now-unused `sessions` table from the tenant copy (optional tidy)**

The restored tenant database still contains a `sessions` table from before the split. It is unused (sessions are central now) and harmless; drop it if you want a clean schema:

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$TENANT_DB" -e "DROP TABLE IF EXISTS sessions;"
```

- [ ] **Step 4: Register the existing database as tenant #1**

```bash
php artisan tenant:setup-existing "Naam van het bedrijf" lavoro_tenant_acme
```

Record the printed tenant ID — call it `TENANT_ID` for the next step.

- [ ] **Step 5: Move existing uploaded files into the tenant storage root**

Task 14 puts each tenant's files under `storage/tenant-<id>/public/...`. Existing files currently sit in `storage/app/public/...`. Move them into the new root. **No database path rewrite is needed** — the stored `path` values are relative to the disk root, which is exactly what the per-tenant disk root now resolves against.

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

- [ ] **Step 6: Build front-end assets and verify**

```bash
npm run build

mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SHOW TABLES IN lavoro;"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT COUNT(*) FROM lavoro.user_tenant_lookups;"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SHOW TABLES IN lavoro_tenant_acme;" | head
```

- [ ] **Step 7: Smoke test**

Log in as an existing user. Confirm: the dashboard loads, an existing customer's documents/images display, and a previously uploaded avatar shows. Existing sessions are gone (the central `sessions` table is fresh), so everyone re-logs in — expected.

---

## Task 28: Verify isolation with a second tenant

- [ ] **Step 1: Create a second tenant with an admin**

```bash
php artisan tenant:create "Tweede Klant BV" admin@tweede.nl --admin-password=secret123
```

Confirm it prints a tenant ID, admin email, and password, and does not error. If it hangs, check the MySQL user has `CREATE DATABASE` and that no queue worker is needed (the pipeline runs inline via `shouldBeQueued(false)`).

- [ ] **Step 2: Confirm web isolation**

Log in as `admin@tweede.nl`. Confirm you see an empty data set (only the seeded stages), not the first tenant's data. Upload an image and confirm it lands under `storage/tenant-<second-id>/public/...` and displays via `/files/images/<id>`. Confirm that requesting another tenant's image id returns 404.

- [ ] **Step 3: Confirm the API requires and respects the header**

```bash
# With header — succeeds:
curl http://localhost/api/events -H "Authorization: Bearer <token>" -H "X-Tenant-ID: <tenant-id>" -H "Accept: application/json"
# Without header — 400:
curl http://localhost/api/events -H "Authorization: Bearer <token>" -H "Accept: application/json"
```

- [ ] **Step 4: Confirm a queued job runs in the right tenant**

```bash
php artisan queue:work --once --verbose
```

Trigger a Google sync (or any queued import) from one tenant and confirm the data lands in that tenant's database, not the other's or the central one.

---

## Known impact and follow-up work

1. **Existing test suite will break.** Tests likely run on SQLite with `RefreshDatabase` against the default connection; multi-database tenancy needs MySQL plus a central/tenant split. Before relying on `composer test`, the test bootstrap must create a central schema and at least one tenant, and tenant-scoped tests must initialize a tenant in `setUp()`. This plan does not modify tests (per project convention) but flags that they need a dedicated follow-up.

2. **Remember-me is disabled.** Re-enabling persistent login requires storing `tenant_id` in a long-lived cookie and reading it as a fallback in `InitializeTenancyBySession`.

3. **Login page shows no branding.** Because the tenant is unknown before login, the page is generic. A two-step login (email → resolve tenant → branded password step) would restore per-tenant logos.

4. **File access is authenticated but not permission-scoped.** Task 14 serves files only to logged-in users of the owning tenant (cross-tenant ids 404 via model binding), which already closes the old world-readable hole. It does not yet apply per-resource permission checks (e.g. "can this user view this customer's documents") — every authenticated user in the tenant can fetch any file id in that tenant. Adding policy checks in `FileController` is a reasonable follow-up if finer-grained access is required.

5. **SnelStart and Microsoft Graph credentials are global env vars.** For true per-tenant integrations, move them into each tenant's `general_settings` and resolve them from the active tenant context.

6. **Scheduler scales linearly with tenant count.** Each scheduled tick iterates every tenant sequentially. At dozens of tenants the five-minute Google-pull tick may exceed its window (then `withoutOverlapping` silently skips a run). Revisit with chunking or a dedicated per-tenant scheduling strategy when tenant count grows.

7. **API login endpoint.** If the mobile app authenticates via the web `/login` route today, add a dedicated `POST /api/login` (without `tenant.api`) that resolves the tenant from the email, issues a Sanctum token, and returns both the token and `tenant_id` for the client to store and send as `X-Tenant-ID`.
