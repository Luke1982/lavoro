<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Services\TenantDbUserProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetupExistingTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:setup-existing {name} {database}';

    protected $description = 'Registreert een bestaande, al gemigreerde database als tenant';

    public function handle(TenantDbUserProvisioner $provisioner): int
    {
        $this->runAsProvisioner();

        $database = $this->argument('database');
        $prefix = config('tenancy.database.prefix');

        if (! str_starts_with($database, $prefix)) {
            $this->error("De database moet met {$prefix} beginnen. Hernoem hem eerst.");

            return self::FAILURE;
        }

        $id = (string) Str::uuid();

        $emails = DB::connection('central')->select(
            "SELECT email FROM `{$database}`.users"
        );
        $emails = array_map(fn ($row) => $row->email, $emails);

        $conflicts = UserTenantLookup::on('central')->whereIn('email', $emails)->pluck('email');

        if ($conflicts->isNotEmpty()) {
            $this->error('Deze e-mailadressen bestaan al bij een andere tenant:');
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
         * De bootstrapper wijst de schijven naar deze mappen, maar maakt ze niet
         * aan. Zonder dit mislukt de eerste upload van een nieuwe tenant, en het
         * is een lege map die niemand mist tot dat gebeurt.
         */
        foreach (['public', 'local'] as $disk) {
            \Illuminate\Support\Facades\File::ensureDirectoryExists(
                storage_path("tenant-{$tenant->id}/{$disk}"), 0775
            );
        }


        $rows = array_map(fn ($e) => ['email' => $e, 'tenant_id' => $id], $emails);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('central')->table('user_tenant_lookups')->insert($chunk);
        }

        $this->info("Tenant aangemaakt: {$id}");
        $this->line('  database: ' . $database);
        $this->line('  gebruikers: ' . count($emails));

        return self::SUCCESS;
    }
}
