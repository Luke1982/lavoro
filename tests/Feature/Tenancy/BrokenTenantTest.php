<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
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

    public function test_the_session_of_a_vanished_tenant_is_forgotten(): void
    {
        $this->withSession(['tenant_id' => 'bestaat-niet'])
            ->get('/login')
            ->assertOk();

        $this->assertNull(Tenant::on('central')->find('bestaat-niet'));
    }
}
