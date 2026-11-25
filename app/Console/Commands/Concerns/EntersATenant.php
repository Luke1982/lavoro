<?php

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;

/**
 * Switches a command into one customer's database, found by id or by name, or
 * says that there is no such customer.
 */
trait EntersATenant
{
    protected function enterTenant(string $wanted): ?Tenant
    {
        $tenant = Tenant::on('central')->find($wanted)
            ?? Tenant::on('central')->where('name', $wanted)->first();

        if ($tenant === null) {
            $this->error('Geen klant gevonden voor "' . $wanted . '".');

            return null;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
