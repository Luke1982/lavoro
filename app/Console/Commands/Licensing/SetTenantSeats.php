<?php

namespace App\Console\Commands\Licensing;

use App\Models\Tenant;
use App\Services\TenantSubscription;
use Illuminate\Console\Command;

class SetTenantSeats extends Command
{
    protected $signature = 'tenant:seats {id} {--field=} {--office=}';

    protected $description = 'Past de extra plaatsen aan (+5, -2 of absoluut)';

    public function handle(): int
    {
        $tenant = $this->tenant();
        if (! $tenant) { return self::FAILURE; }

        foreach (['field' => 'extra_field_seats', 'office' => 'extra_office_seats'] as $option => $column) {
            $value = $this->option($option);
            if ($value === null) { continue; }
            $tenant->{$column} = str_starts_with($value, '+') || str_starts_with($value, '-')
                ? max(0, (int) $tenant->{$column} + (int) $value)
                : max(0, (int) $value);
        }

        $tenant->save();

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
