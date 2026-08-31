<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DeleteTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:delete {id} {--force : Niet vragen}';

    protected $description = 'Verwijdert een tenant: database, MySQL-login, bestanden en centrale rijen';

    public function handle(): int
    {
        $this->runAsProvisioner();

        $tenant = Tenant::on('central')->find($this->argument('id'));

        if (! $tenant) {
            $this->error('Onbekende tenant.');

            return self::FAILURE;
        }

        $database = $tenant->getInternal('db_name');
        $users = DB::connection('central')->table('user_tenant_lookups')->where('tenant_id', $tenant->id)->count();

        $this->warn("Dit verwijdert {$tenant->name} onherroepelijk:");
        $this->line("  database:   {$database}");
        $this->line("  gebruikers: {$users}");
        $this->line('  bestanden:  storage/tenant-' . $tenant->id);

        if (! $this->option('force') && ! $this->confirm('Doorgaan?', false)) {
            return self::SUCCESS;
        }

        /** Eerst de centrale rijen: zonder die kan niemand meer inloggen, ook niet halverwege. */
        DB::connection('central')->table('user_tenant_lookups')->where('tenant_id', $tenant->id)->delete();

        $username = $tenant->tenancy_db_username;

        $tenant->delete();

        if ($username) {
            DB::connection(config('tenancy.database.template_tenant_connection', 'mysql'))
                ->statement("DROP USER IF EXISTS '{$username}'@'%'");
        }

        if ($database) {
            DB::connection(config('tenancy.database.template_tenant_connection', 'mysql'))
                ->statement("DROP DATABASE IF EXISTS `{$database}`");
        }

        $files = storage_path('tenant-' . $tenant->id);

        if (File::isDirectory($files)) {
            File::deleteDirectory($files);
        }

        $this->info("{$tenant->name} is verwijderd.");

        return self::SUCCESS;
    }
}
