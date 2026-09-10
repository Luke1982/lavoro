<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy;
use RuntimeException;
use Tests\TestCase;

/**
 * Pairing initialize() and end() by hand goes wrong as soon as something in
 * between throws: the tenant stays open and the rest of the request runs in the
 * previous customer's database. No error, only wrong data. This helper makes
 * that impossible, and that should be nailed down.
 */
class TenancyWithinTest extends TestCase
{
    public function test_the_tenant_is_closed_again_even_when_the_work_throws(): void
    {
        $tenant = tenancy()->tenant;

        try {
            Tenancy::within($tenant, fn () => throw new RuntimeException('stuk'));
            $this->fail('De fout hoort door te komen.');
        } catch (RuntimeException) {
            $this->assertSame(
                $tenant->getTenantKey(),
                tenancy()->tenant?->getTenantKey(),
                'Na een fout hoort de vorige tenant weer actief te zijn.',
            );
        }
    }

    public function test_nesting_returns_to_the_tenant_it_came_from(): void
    {
        $outer = tenancy()->tenant;

        $seen = Tenancy::within($outer, function () use ($outer) {
            Tenancy::within($outer, fn () => null);

            return tenancy()->tenant?->getTenantKey();
        });

        $this->assertSame($outer->getTenantKey(), $seen);
        $this->assertSame($outer->getTenantKey(), tenancy()->tenant?->getTenantKey());
    }

    public function test_it_gives_back_what_the_work_returns(): void
    {
        $this->assertSame(42, Tenancy::within(tenancy()->tenant, fn () => 42));
    }

    public function test_starting_from_nothing_ends_at_nothing(): void
    {
        $tenant = Tenant::on('central')->findOrFail(tenancy()->tenant->getTenantKey());

        tenancy()->end();

        Tenancy::within($tenant, fn () => null);

        $this->assertFalse(tenancy()->initialized, 'Zonder tenant vooraf hoort er ook geen tenant achteraf te zijn.');

        /** De testopzet rekent erop dat de tenant open staat. */
        tenancy()->initialize($tenant);
    }
}
