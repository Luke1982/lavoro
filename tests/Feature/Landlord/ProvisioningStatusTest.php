<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\LandlordUser;
use App\Models\Central\TenantProvisioningRequest;
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
     * Het paneel begint alleen te vragen zolang er iets loopt. Staat er niets
     * open, dan hoort het stil te blijven -- en staat er wel iets open, dan moet
     * het script er zijn, anders ververst er nooit iets.
     */
    public function test_the_panel_polls_only_while_something_is_running(): void
    {
        TenantProvisioningRequest::on('central')->delete();

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.index'))
            ->assertOk()
            ->assertDontSee('aanvragen\\/status', false);

        TenantProvisioningRequest::on('central')->create([
            'action' => 'delete',
            'status' => 'queued',
            'name' => 'Wegtest',
        ]);

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.index'))
            ->assertOk()
            /** Zoals het in de pagina staat: @json escapet de slashes. */
            ->assertSee('aanvragen\\/status', false)
            ->assertSee('setInterval', false);
    }
}
