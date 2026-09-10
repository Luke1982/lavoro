<?php

namespace Tests\Feature\Landlord;

use App\Jobs\RunTenantProvisioningRequestJob;
use App\Models\Central\LandlordUser;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The button for deleting a customer.
 *
 * The path behind it had existed for months; there was simply no button
 * anywhere, so there was nothing to delete. This test looks at both: that the
 * button is on the screen, and that it only works when the name is typed over
 * literally.
 */
class DeleteTenantTest extends TestCase
{
    private function landlord(): LandlordUser
    {
        return LandlordUser::on('central')->firstOrCreate(
            ['email' => 'delete@majorlabel.nl'],
            ['name' => 'Verwijder', 'password' => 'geheim']
        );
    }

    /**
     * Only the row, without the events around it: creating a tenant normally
     * puts a database and a login in place, and this test is not about that.
     * With those events it would also trip over the database of a previous run.
     */
    private function tenant(): Tenant
    {
        return Tenant::withoutEvents(fn () => Tenant::on('central')->firstOrCreate(
            ['id' => 'knop-test'],
            ['name' => 'Knoptest BV', 'tenancy_db_name' => 'lavoro_test_tenant_knoptest']
        ));
    }

    public function test_the_edit_screen_offers_a_way_to_delete(): void
    {
        $tenant = $this->tenant();

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.edit', $tenant->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Landlord/EditPage')
                ->where('tenant.id', $tenant->id)
                ->where('tenant.name', $tenant->name));
    }

    public function test_a_wrong_name_deletes_nothing(): void
    {
        $tenant = $this->tenant();

        $this->actingAs($this->landlord(), 'landlord')
            ->delete(route('landlord.tenant.destroy', $tenant->id), ['confirm_name' => 'Knoptest'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertSame(0, TenantProvisioningRequest::on('central')
            ->where('tenant_id', $tenant->id)->where('action', 'delete')->count());
    }

    /**
     * The queue is held still here. Otherwise the test really carries the
     * deletion out, and that switches the database connection along the way --
     * which kills the test's transaction and takes the row just written with
     * it. This test is about the button, not about the provisioner.
     */
    public function test_the_exact_name_queues_the_deletion(): void
    {
        Queue::fake();

        $tenant = $this->tenant();

        $this->actingAs($this->landlord(), 'landlord')
            ->delete(route('landlord.tenant.destroy', $tenant->id), ['confirm_name' => $tenant->name])
            ->assertRedirect(route('landlord.index'));

        $request = TenantProvisioningRequest::on('central')
            ->where('tenant_id', $tenant->id)->where('action', 'delete')->first();

        $this->assertNotNull($request, 'Er is geen aanvraag klaargezet om de klant te verwijderen.');

        Queue::assertPushedOn('provisioning', RunTenantProvisioningRequestJob::class,
            fn (RunTenantProvisioningRequestJob $job) => $job->request_id === $request->id);
    }
}
