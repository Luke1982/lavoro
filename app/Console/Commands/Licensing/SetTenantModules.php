<?php

namespace App\Console\Commands\Licensing;

use App\Models\Tenant;
use App\Services\TenantSubscription;
use Illuminate\Console\Command;

class SetTenantModules extends Command
{
    protected $signature = 'tenant:modules {id} {--add=*} {--remove=*}';

    protected $description = 'Voegt modules toe of haalt ze weg';

    public function handle(): int
    {
        $tenant = $this->tenant();
        if (! $tenant) { return self::FAILURE; }

        $known = \App\Models\Central\Module::on('central')->pluck('key');
        $modules = collect($tenant->modules ?? []);

        foreach ($this->option('add') as $key) {
            if (! $known->contains($key)) { $this->error("Onbekende module: {$key}"); return self::FAILURE; }
            $modules->push($key);
        }

        $modules = $modules->unique()->reject(fn ($k) => in_array($k, $this->option('remove'), true));

        $tenant->update(['modules' => $modules->values()->all()]);
        $this->line('  modules: ' . ($modules->implode(', ') ?: '-'));

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
