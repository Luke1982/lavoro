<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\LandlordUser;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * The admin panel refreshes itself while the provisioner is busy. That hangs on
 * one small answer, so that answer has to be right: only for whoever is logged
 * in, and with a fingerprint that changes as soon as something happens.
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

        /** The fingerprint should change along; the refreshing hangs on it. */
        $this->assertNotSame(
            $quiet->json('signature'),
            $busy->json('signature'),
            'De vingerafdruk veranderde niet, dus het scherm zou nooit verversen.'
        );
    }

    /**
     * The overview is an Inertia screen and fetches itself while work is
     * running. What it needs for that is the state of the requests; so that has
     * to be in the properties, and has to be requestable separately.
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
     * A customer still being created is not a broken customer.
     *
     * While the worker runs the migrations the row is there but the tables are
     * not yet. The overview then showed a red SQL error about a table that
     * plainly exists a few seconds later -- exactly at the moment you first
     * look to see whether it worked.
     */
    public function test_a_tenant_being_created_reads_as_busy_and_not_as_broken(): void
    {
        TenantProvisioningRequest::on('central')->delete();

        $tenant = Tenant::withoutEvents(fn () => Tenant::on('central')->firstOrCreate(
            ['id' => 'bezig-test'],
            ['name' => 'Bezigtest BV', 'tenancy_db_name' => 'lavoro_test_tenant_bezigtest']
        ));

        /** Without a request the same customer is broken: there is no connecting. */
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
