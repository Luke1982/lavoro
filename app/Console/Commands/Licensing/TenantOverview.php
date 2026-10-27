<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicer;
use App\Services\StorageQuota;
use App\Services\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class TenantOverview extends Command
{
    protected $signature = 'tenant:overview';

    protected $description = 'Seats, storage, monthly price and what is due, per tenant';

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

            /**
             * The start date and what the next invoice would be, because that
             * is where it goes quiet: without a start date nothing is ever
             * invoiced, and the only place that showed was an invoice screen
             * saying zero.
             */
            $invoicer = new Invoicer($tenant);

            $rows[] = [
                $tenant->name . $flag,
                $tenant->package_key ?? '-',
                "{$field}/{$field_limit}",
                "{$office}/{$office_limit}",
                "{$used_gb}/{$limit_gb} GB",
                number_format((new TenantSubscription($tenant))->monthlyTotalCents() / 100, 2),
                /** A plain column with no date cast, so text and not an object. */
                match (true) {
                    $tenant->isDemo() => 'demo',
                    (bool) $tenant->subscription_started_on => CarbonImmutable::parse($tenant->subscription_started_on)->format('d-m-Y'),
                    default => 'NONE',
                },
                $invoicer->isDue()
                    ? number_format($invoicer->preview()['gross_cents'] / 100, 2)
                    : '-',
            ];
        }

        $this->table(
            ['Tenant', 'Package', 'Field', 'Office', 'Storage', 'Per month', 'Since', 'Due now'],
            $rows
        );

        return self::SUCCESS;
    }
}
