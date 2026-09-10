<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Replaces tenants:list from the package, which only shows the UUIDs.
 *
 * The number of users comes from user_tenant_lookups and not from the tenants
 * themselves: that is one central query instead of a database switch per
 * tenant, and the answer is the same.
 */
class ListTenants extends Command
{
    protected $signature = 'tenants:list {--check : Kijk ook of elke database er echt is}';

    protected $description = 'Toont alle tenants met naam, database en aantal gebruikers';

    public function handle(): int
    {
        $tenants = Tenant::on('central')->orderBy('name')->get();

        if ($tenants->isEmpty()) {
            $this->warn('Nog geen tenants.');

            return self::SUCCESS;
        }

        $users = DB::connection('central')->table('user_tenant_lookups')
            ->selectRaw('tenant_id, COUNT(*) AS aantal')
            ->groupBy('tenant_id')->pluck('aantal', 'tenant_id');

        $existing = $this->option('check')
            ? collect(DB::connection('central')->select('SELECT SCHEMA_NAME AS n FROM information_schema.schemata'))
                ->pluck('n')->flip()
            : null;

        $rows = $tenants->map(function (Tenant $tenant) use ($users, $existing) {
            $database = $tenant->getInternal('db_name') ?? '—';

            return [
                $tenant->name,
                $database . ($existing !== null && !$existing->has($database) ? ' (ONTBREEKT)' : ''),
                $users[$tenant->getTenantKey()] ?? 0,
                $tenant->package_key ?? '—',
                $tenant->tenancy_db_username ? 'ja' : 'NEE',
                $tenant->getTenantKey(),
            ];
        });

        $this->table(['Naam', 'Database', 'Gebruikers', 'Pakket', 'Login', 'ID'], $rows);

        return self::SUCCESS;
    }
}
