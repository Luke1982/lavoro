<?php

namespace App\Console\Commands;

use App\Models\Central\TenantSuperAdmin;
use App\Models\Tenant;
use App\Services\TenantSuperAdmins;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * MajorLabel's own accounts in a customer, listed or written back.
 *
 * They are kept in the landlord database; the account inside the customer's own
 * database is a copy. Anything that replaces that database throws the copy
 * away, and --sync makes it again.
 */
class TenantSuperAdminsCommand extends Command
{
    protected $signature = 'tenant:superadmins
        {tenant? : the customer, by id or by name; all of them when left out}
        {--sync : write the accounts into the customer database again}';

    protected $description = 'Lists the super admins of a tenant, or writes them back into its database';

    public function handle(TenantSuperAdmins $super_admins): int
    {
        $tenants = $this->chosenTenants();

        if ($tenants->isEmpty()) {
            $this->error('No such customer. See them all with: php artisan tenants:list');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $records = TenantSuperAdmin::where('tenant_id', $tenant->id)->orderBy('email')->get();

            $this->line("<fg=cyan>{$tenant->name}</> ({$records->count()})");

            foreach ($records as $record) {
                $this->line("  {$record->email}");
            }

            if (!$this->option('sync')) {
                continue;
            }

            if ($records->isEmpty()) {
                $this->line('  nothing to write back');

                continue;
            }

            try {
                $this->info('  ' . $super_admins->sync($tenant) . ' written into the customer database');
            } catch (\Throwable $e) {
                $this->error('  could not write them back: ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Tenant> */
    private function chosenTenants(): Collection
    {
        $wanted = $this->argument('tenant');

        if (!$wanted) {
            return Tenant::on('central')->orderBy('name')->get();
        }

        return Tenant::on('central')
            ->where('id', $wanted)
            ->orWhere('name', $wanted)
            ->get();
    }
}
