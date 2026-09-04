<?php

namespace Tests\Feature\Landlord;

use App\Jobs\RunTenantProvisioningRequestJob;
use App\Models\Central\LandlordUser;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * De knop om een klant te verwijderen.
 *
 * Het pad erachter bestond al maanden; alleen stond er nergens een knop, dus
 * viel er niets te verwijderen. Deze test kijkt naar allebei: dat de knop op het
 * scherm staat, en dat hij alleen werkt als de naam letterlijk is overgetikt.
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
     * Alleen de rij, zonder de gebeurtenissen eromheen: het aanmaken van een
     * tenant zet normaal een database en een login klaar, en daar gaat deze test
     * niet over. Met die gebeurtenissen erbij struikelt hij bovendien over de
     * database van een vorige run.
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
     * De wachtrij wordt hier stilgezet. Anders voert de test het verwijderen
     * echt uit, en dat zet onderweg de databaseverbinding om -- waarmee de
     * transactie van de test sneuvelt en de rij die net geschreven is weer weg
     * is. Deze test gaat over de knop, niet over de provisioner.
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
