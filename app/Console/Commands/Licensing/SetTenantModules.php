<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Module;
use App\Models\Tenant;
use App\Services\TenantSubscription;
use Illuminate\Console\Command;

class SetTenantModules extends Command
{
    protected $signature = 'tenant:modules {id} {--add=*} {--remove=*}';

    protected $description = 'Adds or removes modules';

    public function handle(): int
    {
        $tenant = $this->tenant();
        if (!$tenant) {
            return self::FAILURE;
        }

        $known = Module::on('central')->pluck('key');
        $modules = collect($tenant->modules ?? []);

        foreach ($this->option('add') as $key) {
            if (!$known->contains($key)) {
                $this->error("Unknown module: {$key}");

                return self::FAILURE;
            }
            $modules->push($key);
        }

        $modules = $modules->unique()->reject(fn ($k) => in_array($k, $this->option('remove'), true));

        $tenant->update(['modules' => $modules->values()->all()]);
        $this->line('  modules: ' . ($modules->implode(', ') ?: '-'));

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
