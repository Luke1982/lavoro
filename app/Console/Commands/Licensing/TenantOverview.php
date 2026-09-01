<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StorageQuota;
use App\Services\TenantSubscription;
use Illuminate\Console\Command;

class TenantOverview extends Command
{
    protected $signature = 'tenant:overview';

    protected $description = 'Plaatsen, opslag en maandprijs per tenant';

    public function handle(): int
    {
        $rows = [];

        foreach (Tenant::on('central')->orderBy('name')->get() as $tenant) {
            $package = Package::on('central')->where('key', $tenant->package_key)->first();

            tenancy()->initialize($tenant);

            $field = User::occupyingSeat('field')->count();
            $office = User::occupyingSeat('office')->count();
            $used = (new StorageQuota)->usedBytes();

            tenancy()->end();

            $field_limit = (int) ($package->field_seats ?? 0) + (int) $tenant->extra_field_seats;
            $office_limit = (int) ($package->office_seats ?? 0) + (int) $tenant->extra_office_seats;
            $limit_gb = (int) $tenant->storage_limit_gb;
            $used_gb = round($used / (1024 ** 3), 1);

            $flag = ($field > $field_limit || $office > $office_limit || $used_gb > $limit_gb) ? ' !' : '';

            $rows[] = [
                $tenant->name . $flag,
                $tenant->package_key ?? '-',
                "{$field}/{$field_limit}",
                "{$office}/{$office_limit}",
                "{$used_gb}/{$limit_gb} GB",
                number_format((new TenantSubscription($tenant))->monthlyTotalCents() / 100, 2),
            ];
        }

        $this->table(['Tenant', 'Pakket', 'Buiten', 'Binnen', 'Opslag', 'Per maand'], $rows);

        return self::SUCCESS;
    }
}
