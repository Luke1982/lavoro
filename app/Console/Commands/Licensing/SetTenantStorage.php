<?php

namespace App\Console\Commands\Licensing;

use App\Models\Tenant;
use App\Services\TenantSubscription;
use Illuminate\Console\Command;

class SetTenantStorage extends Command
{
    protected $signature = 'tenant:storage {id} {--limit=}';

    protected $description = 'Zet de opslaglimiet in GB';

    public function handle(): int
    {
        $tenant = $this->tenant();
        if (! $tenant) { return self::FAILURE; }

        if ($this->option('limit') !== null) {
            $tenant->update(['storage_limit_gb' => max(0, (int) $this->option('limit'))]);
        }

        $this->info($tenant->name . ': ' . number_format((new TenantSubscription($tenant->refresh()))->monthlyTotalCents() / 100, 2) . ' per maand');

        return self::SUCCESS;
    }

    private function tenant(): ?Tenant
    {
        $tenant = Tenant::on('central')->find($this->argument('id'));

        if (! $tenant) {
            $this->error('Onbekende tenant.');
        }

        return $tenant;
    }
}
