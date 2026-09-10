<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProvisionerConnection;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creating and cleaning up a tenant, in one place. The commands and the admin
 * panel's worker both run through here, so two versions of "create a tenant"
 * that do slightly different things cannot come into being.
 *
 * Everything in here speaks as lavoro_provisioner. In production that account
 * belongs to a Linux user of its own, so this only works in a process running
 * as that user -- not in a web request.
 */
class TenantProvisioner
{
    /**
     * No constructor that switches the database connection.
     *
     * It used to, and with that, constructing this class was enough to turn a
     * whole request or command over -- even when all it needed was a name. On
     * top of that Laravel builds it before handle() runs, so a command that
     * wanted to elevate itself first never got that far: the error already fell
     * at construction.
     *
     * The switching now happens where it is needed, in create() and destroy().
     */
    private function asProvisioner(): void
    {
        ProvisionerConnection::use();
        ProvisionerConnection::assertUsable();
    }

    /**
     * Static, and deliberately so. The name follows from the prefix and the
     * company name and needs no database -- while constructing this class does
     * switch the connection to the provisioner. That happened by accident while
     * validating the form, after which the whole web request sat on a
     * connection the web server cannot reach.
     */
    public static function databaseNameFor(string $name): string
    {
        return config('tenancy.database.prefix') . Str::slug($name, '_');
    }

    /**
     * @param  array<int, string>  $modules
     * @return array{tenant: Tenant, password: string}
     */
    public function create(string $name, string $email, string $password = '', string $package = 'starter', array $modules = []): array
    {
        $this->asProvisioner();

        $database = self::databaseNameFor($name);

        if (DB::connection('central')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?', [$database]
        )) {
            throw new Refusal("De database {$database} bestaat al. Kies een andere naam.");
        }

        if (DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->exists()) {
            throw new Refusal("{$email} is al in gebruik bij een andere tenant.");
        }

        $password = $password ?: Str::password(16);

        /**
         * The id up front, so that on failure it is beyond doubt which row came
         * from this call. Searching by database name can point at a customer
         * that was already there.
         */
        $id = (string) Str::uuid();

        /**
         * From here on all sorts of things can end up half finished: a row
         * without a database, a database without a user, a user without an
         * admin. If it breaks, everything goes away again -- otherwise
         * leftovers block the next attempt ("that name already exists") and put
         * the admin panel on an error whose message is about a password rather
         * than about what is actually wrong.
         */
        try {
            $tenant = Tenant::create([
                'id' => $id,
                'name' => $name,
                'package_key' => $package,
                'modules' => array_values(array_filter($modules)),
                /** From today, like the subscription; otherwise the first invoice would pro-rate them. */
                'module_started_on' => collect($modules)->filter()
                    ->mapWithKeys(fn (string $key) => [$key => now()->toDateString()])->all(),
                'tenancy_db_name' => $database,
                /**
                 * The subscription runs from today. Without a start date there
                 * is never anything to invoice, and nothing shows it: the
                 * customer simply keeps working and no bill arrives. If the
                 * date is wrong it can be corrected on screen.
                 */
                'subscription_started_on' => now()->toDateString(),
            ]);
        } catch (\Throwable $e) {
            $this->cleanUpAfterFailure(Tenant::on('central')->find($id), $database);

            throw $e;
        }

        /**
         * The bootstrapper points the disks at these folders but does not
         * create them. Without this the first upload of a new tenant fails, and
         * it is an empty folder nobody misses until that happens.
         */
        foreach (['public', 'local'] as $disk) {
            File::ensureDirectoryExists(storage_path("tenant-{$tenant->id}/{$disk}"), 0775);
        }

        try {
            tenancy()->initialize($tenant);

            $this->seed($tenant);

            $admin = User::create([
                'name' => 'Beheerder',
                'email' => $email,
                'password' => Hash::make($password),
                'seat_type' => 'office',
            ]);

            /**
             * Being an admin is a role and not a column. This sat here as
             * 'is_admin' => true, a field that does not exist: it was silently
             * dropped and every new tenant started with a first user who was
             * allowed nowhere.
             */
            $role = Role::where('name', 'admin')->first();

            if (!$role) {
                throw new RuntimeException('De rol admin ontbreekt in de nieuwe database; is het zaaien misgegaan?');
            }

            $admin->roles()->attach($role->id);
        } catch (\Throwable $e) {
            tenancy()->end();

            $this->cleanUpAfterFailure($tenant, $database);

            throw $e;
        } finally {
            tenancy()->end();
        }

        return ['tenant' => $tenant, 'password' => $password];
    }

    /**
     * Seeds the roles, the permissions, the stages and the company.
     *
     * The library does this itself as well, with Artisan::call -- which returns
     * an exit code nobody looks at. If seeding failed, the customer was
     * "created" with only the role that comes from a migration, and nowhere did
     * it say why. That is exactly what happened.
     *
     * Here it is run again -- everything goes through firstOrCreate, so that is
     * allowed -- and it does check whether it worked. If not, the error travels
     * up and the caller cleans up the half customer.
     */
    private function seed(Tenant $tenant): void
    {
        $status = Artisan::call('db:seed', [
            '--class' => TenantDatabaseSeeder::class,
            '--force' => true,
        ]);

        if ($status !== 0) {
            throw new RuntimeException("Het zaaien van {$tenant->name} is mislukt: "
                . trim(Artisan::output()));
        }

        $expected = array_keys(include base_path('database/seeders/data/tenant_roles.php'));
        $missing = array_diff($expected, Role::pluck('name')->all());

        if ($missing !== []) {
            throw new RuntimeException("Na het zaaien ontbreken deze rollen bij {$tenant->name}: "
                . implode(', ', $missing) . '. ' . trim(Artisan::output()));
        }
    }

    /**
     * Rolls a failed creation back: the row, the database, the database account
     * and the folders.
     *
     * Draws no attention to itself when the cleanup fails too. The error that
     * came in here is the one the user should see; a second error on top of it
     * hides exactly the reason it went wrong.
     */
    private function cleanUpAfterFailure(?Tenant $tenant, string $database): void
    {
        /**
         * Only clean up what this call made itself.
         *
         * There used to be a lookup by database name and a DROP DATABASE by
         * name here. That is lethal: if a second attempt for an existing name
         * fails, the cleanup throws away the customer that was already there
         * and working. Deleting must never point at something it did not create
         * itself -- then an error while creating is worse than the error itself.
         */
        if (!$tenant) {
            Log::warning('Aanmaken mislukt voordat er iets bestond; niets op te ruimen', [
                'database' => $database,
            ]);

            return;
        }

        try {
            $this->destroy($tenant);
        } catch (\Throwable $ignored) {
            Log::warning('Opruimen na een mislukte aanmaak lukte niet', [
                'database' => $database,
                'fout' => $ignored->getMessage(),
            ]);
        }
    }

    /**
     * Cleans up everything that belongs to a tenant. The central rows go first:
     * without those nobody can log in any more, not even when it breaks halfway.
     */
    public function destroy(Tenant $tenant): void
    {
        $this->asProvisioner();

        $database = $tenant->getInternal('db_name');
        $username = $tenant->tenancy_db_username;
        $files = storage_path('tenant-' . $tenant->id);

        DB::connection('central')->table('user_tenant_lookups')->where('tenant_id', $tenant->id)->delete();

        $tenant->delete();

        $template = config('tenancy.database.template_tenant_connection', 'mysql');

        if ($username) {
            DB::connection($template)->statement("DROP USER IF EXISTS '{$username}'@'%'");
        }

        if ($database) {
            DB::connection($template)->statement("DROP DATABASE IF EXISTS `{$database}`");
        }

        if (File::isDirectory($files)) {
            File::deleteDirectory($files);
        }
    }

    /** @return array{users: int, database: ?string, files: string} */
    public function summaryFor(Tenant $tenant): array
    {
        return [
            'users' => DB::connection('central')->table('user_tenant_lookups')
                ->where('tenant_id', $tenant->id)->count(),
            'database' => $tenant->getInternal('db_name'),
            'files' => 'storage/tenant-' . $tenant->id,
        ];
    }
}
