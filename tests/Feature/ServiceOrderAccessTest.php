<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * One werkbon opens for the people the list shows it to, and for nobody else:
 * typing its address in is not a way around the list.
 */
class ServiceOrderAccessTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function executing(ServiceOrder $order, User $user): void
    {
        $order->executingUsers()->attach($user->id, ['type' => 'executing']);
    }

    public function test_someone_who_reads_only_their_own_cannot_open_another_werkbon(): void
    {
        $order = $this->order();

        $this->actingAs($this->userWith('serviceorder.read_own'))
            ->getJson('/serviceorders/' . $order->id)
            ->assertForbidden();
    }

    public function test_someone_who_reads_only_their_own_opens_the_werkbon_they_execute(): void
    {
        $order = $this->order();
        $user = $this->userWith('serviceorder.read_own');
        $this->executing($order, $user);

        $this->actingAs($user)->get('/serviceorders/' . $order->id)->assertOk();
    }

    public function test_someone_who_reads_werkbonnen_opens_any_of_them(): void
    {
        $this->actingAs($this->userWith('serviceorder.read'))
            ->get('/serviceorders/' . $this->order()->id)
            ->assertOk();
    }

    public function test_the_pdf_of_another_werkbon_is_refused(): void
    {
        $order = $this->order();

        $this->actingAs($this->userWithPermissions('serviceorder.read_own', 'serviceorder.export_pdf'))
            ->getJson('/serviceorders/' . $order->id . '/export/pdf')
            ->assertForbidden();
    }

    public function test_the_appointments_of_another_werkbon_are_refused(): void
    {
        $order = $this->order();

        $this->actingAs($this->userWith('serviceorder.read_own'))
            ->getJson('/api/serviceorders/' . $order->id . '/event-widget')
            ->assertForbidden();
    }

    public function test_the_appointments_of_your_own_werkbon_are_shown(): void
    {
        $order = $this->order();
        $user = $this->userWith('serviceorder.read_own');
        $this->executing($order, $user);

        $this->actingAs($user)
            ->getJson('/api/serviceorders/' . $order->id . '/event-widget')
            ->assertOk();
    }

    public function test_sending_to_the_administration_needs_its_own_permission(): void
    {
        $order = $this->order();

        $this->actingAs($this->userWith('serviceorder.read'))
            ->postJson('/serviceorders/' . $order->id . '/send-to-administration')
            ->assertForbidden();
    }

    public function test_sending_to_the_administration_is_let_through_with_the_permission(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->userWithPermissions('serviceorder.read', 'integration.send_serviceorder'))
            ->postJson('/serviceorders/' . $order->id . '/send-to-administration');

        $this->assertNotSame(403, $response->status());
    }
}
