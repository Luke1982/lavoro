<?php

namespace Tests\Concerns;

use App\Models\Central\LandlordUser;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * The two rows every admin panel test needs.
 *
 * They sat in every test file again, written down slightly differently each
 * time.
 */
trait MakesLandlordData
{
    private int $tenants_made = 0;

    protected function landlord(string $email = 'beheer@majorlabel.nl'): LandlordUser
    {
        return LandlordUser::on('central')->firstOrCreate(
            ['email' => $email],
            ['name' => 'Beheer', 'password' => 'geheim'],
        );
    }

    /**
     * Only the row, without the events around it: creating a customer normally
     * puts a database and a login in place, and these tests are not about that.
     * With those events they would also trip over the database of a previous
     * round.
     */
    protected function tenantRow(array $attributes = []): Tenant
    {
        $this->tenants_made++;

        return Tenant::withoutEvents(fn () => Tenant::on('central')->create([
            'id' => 'test-' . $this->tenants_made . '-' . Str::lower(Str::random(6)),
            'name' => 'Testklant ' . $this->tenants_made,
            'tenancy_db_name' => 'lavoro_test_tenant_test',
            'package_key' => 'starter',
            'billing_period' => 'monthly',
            'storage_limit_gb' => 50,
            ...$attributes,
        ]));
    }
}
