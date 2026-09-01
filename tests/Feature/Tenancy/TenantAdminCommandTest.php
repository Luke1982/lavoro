<?php

namespace Tests\Feature\Tenancy;

use App\Models\Central\UserTenantLookup;
use App\Models\User;
use Tests\TestCase;

/**
 * De eerste beheerder van een nieuwe klant kreeg 'is_admin' => true mee, een
 * kolom die niet bestaat. Dat werd stil weggelaten en elke nieuwe klant begon
 * met een beheerder die nergens bij mocht -- zonder foutmelding, want het
 * aanmaken zelf lukte prima.
 *
 * Beheerder zijn is een rol. Deze test kijkt naar de rol en niet naar het
 * aanmaken, want dat laatste ging nooit mis.
 */
class TenantAdminCommandTest extends TestCase
{
    public function test_the_admin_it_creates_actually_holds_the_admin_role(): void
    {
        $email = 'nieuwe.beheerder-' . uniqid() . '@example.com';

        $this->artisan('tenant:admin', [
            'tenant' => tenancy()->tenant->getTenantKey(),
            'email' => $email,
            '--password' => 'geheimwachtwoord',
        ])->assertSuccessful();

        $user = User::where('email', $email)->firstOrFail();

        $this->assertTrue($user->isAdmin(), 'Een aangemaakte beheerder hoort de rol admin te hebben.');
        $this->assertTrue($user->hasPermission('wat.dan.ook'));
    }

    public function test_it_registers_the_address_so_logging_in_can_find_the_tenant(): void
    {
        $email = 'vindbaar-' . uniqid() . '@example.com';

        $this->artisan('tenant:admin', [
            'tenant' => tenancy()->tenant->getTenantKey(),
            'email' => $email,
        ])->assertSuccessful();

        $this->assertSame(
            tenancy()->tenant->getTenantKey(),
            UserTenantLookup::on('central')->find($email)?->tenant_id,
            'Zonder centrale rij kan het inloggen de klant niet opzoeken.',
        );
    }

    public function test_an_address_of_another_tenant_is_refused(): void
    {
        $email = 'bezet-' . uniqid() . '@example.com';

        UserTenantLookup::on('central')->create(['email' => $email, 'tenant_id' => 'een-andere-tenant']);

        $this->artisan('tenant:admin', [
            'tenant' => tenancy()->tenant->getTenantKey(),
            'email' => $email,
        ])->assertFailed();

        $this->assertNull(User::where('email', $email)->first());
    }
}
