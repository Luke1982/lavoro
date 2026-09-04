<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\LandlordUser;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * Het beheerpaneel ververst zichzelf zolang de provisioner bezig is. Dat hangt
 * aan één klein antwoord, dus dat antwoord moet kloppen: alleen voor wie
 * ingelogd is, en met een vingerafdruk die verandert zodra er iets gebeurt.
 */
class ProvisioningStatusTest extends TestCase
{
    private function landlord(): LandlordUser
    {
        return LandlordUser::on('central')->firstOrCreate(
            ['email' => 'status@majorlabel.nl'],
            ['name' => 'Status', 'password' => 'geheim']
        );
    }

    public function test_it_is_closed_to_anyone_not_logged_in(): void
    {
        $this->get(route('landlord.provisioning.status'))
            ->assertRedirect(route('landlord.login'));
    }

    public function test_it_reports_whether_the_provisioner_is_busy(): void
    {
        TenantProvisioningRequest::on('central')->where('name', 'Statustest')->delete();

        $quiet = $this->actingAs($this->landlord(), 'landlord')
            ->getJson(route('landlord.provisioning.status'));

        $quiet->assertOk()->assertJson(['busy' => false]);

        TenantProvisioningRequest::on('central')->create([
            'action' => 'create',
            'status' => 'queued',
            'name' => 'Statustest',
            'email' => 'statustest@example.com',
        ]);

        $busy = $this->actingAs($this->landlord(), 'landlord')
            ->getJson(route('landlord.provisioning.status'));

        $busy->assertOk()->assertJson(['busy' => true]);

        /** De vingerafdruk hoort mee te veranderen; daar hangt het verversen aan. */
        $this->assertNotSame(
            $quiet->json('signature'),
            $busy->json('signature'),
            'De vingerafdruk veranderde niet, dus het scherm zou nooit verversen.'
        );
    }

    /**
     * Het overzicht is een Inertia-scherm en haalt zichzelf op zolang er werk
     * loopt. Wat het daarvoor nodig heeft is de stand van de aanvragen; die moet
     * dus in de eigenschappen zitten, en apart op te vragen zijn.
     */
    public function test_the_panel_carries_the_state_it_refreshes_on(): void
    {
        TenantProvisioningRequest::on('central')->delete();

        TenantProvisioningRequest::on('central')->create([
            'action' => 'create',
            'status' => 'queued',
            'name' => 'Wegtest',
            'email' => 'weg@example.com',
        ]);

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Landlord/IndexPage')
                ->has('requests', 1)
                ->where('requests.0.status', 'queued')
                ->where('requests.0.name', 'Wegtest'));
    }

    /**
     * Een klant die nog wordt aangemaakt is geen kapotte klant.
     *
     * Zolang de worker de migraties draait staat de rij er wel maar zijn de
     * tabellen er nog niet. Het overzicht liet dan een rode SQL-fout zien over
     * een tabel die een paar seconden later gewoon bestaat -- precies op het
     * moment dat je voor het eerst kijkt of het gelukt is.
     */
    public function test_a_tenant_being_created_reads_as_busy_and_not_as_broken(): void
    {
        TenantProvisioningRequest::on('central')->delete();

        $tenant = Tenant::withoutEvents(fn () => Tenant::on('central')->firstOrCreate(
            ['id' => 'bezig-test'],
            ['name' => 'Bezigtest BV', 'tenancy_db_name' => 'lavoro_test_tenant_bezigtest']
        ));

        /** Zonder aanvraag is dezelfde klant wél kapot: er valt niet te verbinden. */
        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.index'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.busy', false)
                ->whereNot('rows.0.broken', null));

        TenantProvisioningRequest::on('central')->create([
            'action' => 'create',
            'status' => 'running',
            'name' => $tenant->name,
            'email' => 'bezig@example.com',
        ]);

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.index'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.busy', true)
                ->where('rows.0.broken', null));
    }
}
