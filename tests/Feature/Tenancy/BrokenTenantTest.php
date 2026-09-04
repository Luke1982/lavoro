<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesASecondTenant;
use Tests\TestCase;

/**
 * Een klant waarvan de database weg is -- halverwege aangemaakt, half opgeruimd
 * -- mag de installatie niet meenemen.
 *
 * Dat gebeurde: de sessie wees naar die klant, tenancy schakelde vrolijk om, en
 * de eerste vraag aan de database liep stuk. Resultaat: 500 op elke pagina,
 * inclusief het inlogscherm, zodat er ook niet meer uit te komen viel.
 */
class BrokenTenantTest extends TestCase
{
    use UsesASecondTenant;

    public function test_a_tenant_without_a_database_does_not_take_the_site_down(): void
    {
        $tenant = $this->secondTenant();
        $database = $tenant->getInternal('db_name');

        /** Eerst bewijzen dat het normaal wél werkt, anders zegt de rest niets. */
        $this->withSession(['tenant_id' => $tenant->getTenantKey()])
            ->get('/login')
            ->assertOk();

        DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");

        try {
            $this->withSession(['tenant_id' => $tenant->getTenantKey()])
                ->get('/login')
                ->assertOk();
        } finally {
            /** Weer opbouwen, anders draait de volgende test tegen niets. */
            DB::connection('central')->statement("CREATE DATABASE IF NOT EXISTS `{$database}`");
            static::$second_tenant_prepared = false;
        }
    }

    /**
     * Het geval uit productie: er zit nog een ingelogde gebruiker in de sessie
     * van een klant waarvan de database weg is.
     *
     * Auth::forgetUser() vergeet alleen het opgehaalde object; het id staat nog
     * in de sessie, dus de guard haalt hem opnieuw op -- en zoekt de
     * users-tabel dan in de centrale database, waar hij niet staat. 500 op elke
     * pagina, ook op het inlogscherm.
     */
    public function test_a_logged_in_session_of_a_vanished_tenant_does_not_break_the_site(): void
    {
        $tenant = $this->secondTenant();
        $database = $tenant->getInternal('db_name');

        DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");

        /**
         * De suite draait standaard mét een tenant. Die moet hier weg, anders
         * komt het verzoek nooit langs de code die deze test bedoelt en slaagt
         * hij om de verkeerde reden -- wat precies gebeurde.
         */
        $guard_key = Auth::guard('web')->getName();
        tenancy()->end();

        try {
            $this->withSession([
                'tenant_id' => $tenant->getTenantKey(),
                $guard_key => 1,
            ])->get('/')->assertRedirect(route('login'));
        } finally {
            DB::connection('central')->statement("CREATE DATABASE IF NOT EXISTS `{$database}`");
            static::$second_tenant_prepared = false;
        }
    }

    public function test_the_session_of_a_vanished_tenant_is_forgotten(): void
    {
        $this->withSession(['tenant_id' => 'bestaat-niet'])
            ->get('/login')
            ->assertOk();

        $this->assertNull(Tenant::on('central')->find('bestaat-niet'));
    }
}
