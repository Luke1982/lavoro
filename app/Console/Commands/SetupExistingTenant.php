<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Services\TenantDbUserProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SetupExistingTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:setup-existing {name} {database}
        {--started-on= : the day billing starts; today by default, "none" to leave it open}';

    protected $description = 'Registers an existing database as a tenant, or brings a registered one back in step';

    /**
     * The same customer, with the database underneath it replaced. Everything
     * that came from the other installation is redone: who may log in, and the
     * MySQL login on a database that was dropped and built again.
     *
     * @param  array<int, string>  $emails
     */
    private function update(Tenant $tenant, array $emails, TenantDbUserProvisioner $provisioner): int
    {
        $tenant->name = $this->argument('name');

        if (filled($this->option('started-on'))) {
            $tenant->subscription_started_on = $this->option('started-on') === 'none'
                ? null
                : CarbonImmutable::parse($this->option('started-on'))->toDateString();
        }

        $tenant->save();

        /** Drops the login and makes it again, so the grants follow the new database. */
        $provisioner->provision($tenant);

        foreach (['public', 'local'] as $disk) {
            File::ensureDirectoryExists(storage_path("tenant-{$tenant->id}/{$disk}"), 0775);
        }

        $before = UserTenantLookup::on('central')->where('tenant_id', $tenant->id)->count();

        DB::connection('central')->table('user_tenant_lookups')->where('tenant_id', $tenant->id)->delete();
        $this->linkUsers($tenant->id, $emails);

        $this->info("Tenant updated: {$tenant->id}");
        $this->line('  database: ' . $this->argument('database'));
        $this->line('  users:    ' . count($emails) . ' (was ' . $before . ')');
        $this->line('  kept:     package, seats, modules, billing, invoices');

        return self::SUCCESS;
    }

    /** @param  array<int, string>  $emails */
    private function linkUsers(string $id, array $emails): void
    {
        $rows = array_map(fn ($email) => ['email' => $email, 'tenant_id' => $id], $emails);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('central')->table('user_tenant_lookups')->insert($chunk);
        }
    }

    public function handle(TenantDbUserProvisioner $provisioner): int
    {
        $this->runAsProvisioner();

        $database = $this->argument('database');
        $prefix = config('tenancy.database.prefix');

        if (!str_starts_with($database, $prefix)) {
            $this->error("The database has to start with {$prefix}. Rename it first.");

            return self::FAILURE;
        }

        /**
         * A database that is already registered is not an error: importing an
         * installation again, to pick up what changed at the source, lands
         * here a second time. Then the customer keeps everything that is ours
         * -- its id, its package, its seats, the day billing started, its
         * invoices -- and only what comes from the other side is redone.
         */
        $tenant = Tenant::on('central')->where('data->tenancy_db_name', $database)->first();
        $id = $tenant?->getTenantKey() ?? (string) Str::uuid();

        /**
         * 'none' leaves billing open on purpose -- for a takeover where the day
         * has not been agreed yet. Nothing is invoiced until it is filled in,
         * and tenant:overview shows it as NONE so it cannot stay forgotten.
         */
        $starts_on = match ($this->option('started-on')) {
            null, '' => now()->toDateString(),
            'none' => null,
            default => CarbonImmutable::parse($this->option('started-on'))->toDateString(),
        };

        $emails = DB::connection('central')->select(
            "SELECT email FROM `{$database}`.users"
        );
        $emails = array_map(fn ($row) => $row->email, $emails);

        $conflicts = UserTenantLookup::on('central')
            ->whereIn('email', $emails)
            ->where('tenant_id', '!=', $id)
            ->pluck('email');

        if ($conflicts->isNotEmpty()) {
            $this->error('These email addresses already exist at another tenant:');
            $conflicts->each(fn ($e) => $this->line("  {$e}"));

            return self::FAILURE;
        }

        if ($tenant) {
            return $this->update($tenant, $emails, $provisioner);
        }

        DB::connection('central')->table('tenants')->insert([
            'id' => $id,
            'name' => $this->argument('name'),
            'data' => json_encode(['tenancy_db_name' => $database]),
            /**
             * With a start date, like tenant:create. Without one there is never
             * anything to invoice and nothing shows it: an imported customer
             * simply keeps working and no bill ever arrives. Today unless a day
             * was agreed, and it can be corrected on the subscription screen.
             *
             * A column and not a key in data: the model lists it among its own
             * columns, so a value in the json is read by nothing.
             */
            'subscription_started_on' => $starts_on,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenant = Tenant::on('central')->findOrFail($id);

        $provisioner->provision($tenant);

        /**
         * The bootstrapper points the disks at these folders but does not
         * create them. Without this the first upload of a new tenant fails, and
         * it is an empty folder nobody misses until that happens.
         */
        foreach (['public', 'local'] as $disk) {
            File::ensureDirectoryExists(
                storage_path("tenant-{$tenant->id}/{$disk}"), 0775
            );
        }

        $this->linkUsers($id, $emails);

        $this->info("Tenant created: {$id}");
        $this->line('  database: ' . $database);
        $this->line('  users:    ' . count($emails));
        $this->line('  billing:  ' . ($starts_on
            ? 'from ' . CarbonImmutable::parse($starts_on)->format('d-m-Y')
            : 'not set -- nothing is invoiced until it is'));

        return self::SUCCESS;
    }
}
