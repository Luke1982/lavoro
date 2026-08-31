<?php

namespace App\Console\Commands\Licensing;

use App\Models\Tenant;
use App\Services\TenantSubscription;
use Illuminate\Console\Command;

class SetTenantOverride extends Command
{
    protected $signature = 'tenant:override {id} {--price=} {--clear}';

    protected $description = 'Zet of wist een vaste maandprijs in centen';

    public function handle(): int
    {
        $tenant = $this->tenant();
        if (! $tenant) { return self::FAILURE; }

        $tenant->update(['price_override_cents' => $this->option('clear') ? null : (int) $this->option('price')]);

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
