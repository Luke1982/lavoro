<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Services\TenantDbUserProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SetupExistingTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:setup-existing {name} {database}';

    protected $description = 'Registers an existing, already migrated database as a tenant';

    public function handle(TenantDbUserProvisioner $provisioner): int
    {
        $this->runAsProvisioner();

        $database = $this->argument('database');
        $prefix = config('tenancy.database.prefix');

        if (!str_starts_with($database, $prefix)) {
            $this->error("The database has to start with {$prefix}. Rename it first.");

            return self::FAILURE;
        }

        $id = (string) Str::uuid();

        $emails = DB::connection('central')->select(
            "SELECT email FROM `{$database}`.users"
        );
        $emails = array_map(fn ($row) => $row->email, $emails);

        $conflicts = UserTenantLookup::on('central')->whereIn('email', $emails)->pluck('email');

        if ($conflicts->isNotEmpty()) {
            $this->error('These email addresses already exist at another tenant:');
            $conflicts->each(fn ($e) => $this->line("  {$e}"));

            return self::FAILURE;
        }

        DB::connection('central')->table('tenants')->insert([
            'id' => $id,
            'name' => $this->argument('name'),
            'data' => json_encode(['tenancy_db_name' => $database]),
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

        $rows = array_map(fn ($e) => ['email' => $e, 'tenant_id' => $id], $emails);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('central')->table('user_tenant_lookups')->insert($chunk);
        }

        $this->info("Tenant created: {$id}");
        $this->line('  database: ' . $database);
        $this->line('  users:    ' . count($emails));

        return self::SUCCESS;
    }
}
