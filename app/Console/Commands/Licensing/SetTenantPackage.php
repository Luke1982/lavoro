<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use App\Models\Tenant;
use App\Services\TenantSubscription;
use Illuminate\Console\Command;

class SetTenantPackage extends Command
{
    protected $signature = 'tenant:package {id} {key}';

    protected $description = 'Sets a tenant\'s package';

    public function handle(): int
    {
        $tenant = $this->tenant();
        if (!$tenant) {
            return self::FAILURE;
        }

        if (!Package::on('central')->where('key', $this->argument('key'))->exists()) {
            $this->error('Unknown package.');

            return self::FAILURE;
        }

        $tenant->update(['package_key' => $this->argument('key')]);

        $this->info($tenant->name . ': ' . number_format((new TenantSubscription($tenant->refresh()))->monthlyTotalCents() / 100, 2) . ' per month');

        return self::SUCCESS;
    }

    private function tenant(): ?Tenant
    {
        $tenant = Tenant::on('central')->find($this->argument('id'));

        if (!$tenant) {
            $this->error('Unknown tenant.');
        }

        return $tenant;
    }
}
