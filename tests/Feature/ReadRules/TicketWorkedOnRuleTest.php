<?php

namespace Tests\Feature\ReadRules;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\Ticket;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Whoever is planned for the open werkbon a storing is on opens and edits that
 * storing without any right on storingen. Once the werkbon is closed that is over.
 */
class TicketWorkedOnRuleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private ServiceOrder $order;

    private Ticket $ticket;

    private User $planned;

    protected function setUp(): void
    {
        parent::setUp();

        $customer = Customer::factory()->create();
        $this->order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $this->ticket = Ticket::factory()->create([
            'asset_id' => Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => Product::factory()->create()->id])->id,
            'service_order_id' => $this->order->id,
            'subject' => 'Lekt',
        ]);
        $this->planned = $this->userWithPermissions();
        $this->order->addExecutingUser($this->planned->id);
    }

    private function edit(User $user)
    {
        return $this->actingAs($user)->patchJson('/tickets/' . $this->ticket->id, ['subject' => 'Lekt niet meer']);
    }

    public function test_whoever_is_planned_opens_and_edits_the_storing(): void
    {
        $this->actingAs($this->planned)->get('/tickets/' . $this->ticket->id)->assertOk();
        $this->actingAs($this->planned)->from('/tickets/' . $this->ticket->id)
            ->patch('/tickets/' . $this->ticket->id, ['subject' => 'Lekt niet meer'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Lekt niet meer', $this->ticket->fresh()->subject);
    }

    public function test_someone_else_neither_opens_nor_edits_it(): void
    {
        $other = $this->userWithPermissions('serviceorder.read');

        $this->actingAs($other)->getJson('/tickets/' . $this->ticket->id)->assertForbidden();
        $this->edit($other)->assertForbidden();
    }

    public function test_on_a_closed_werkbon_it_is_over(): void
    {
        $closed = ServiceOrderStage::create(['name' => 'Gesloten', 'order' => 99, 'is_closed_state' => true]);
        $this->order->update(['service_order_stage_id' => $closed->id]);

        $this->actingAs($this->planned)->getJson('/tickets/' . $this->ticket->id)->assertForbidden();
        $this->edit($this->planned)->assertForbidden();

        $this->assertSame('Lekt', $this->ticket->fresh()->subject);
    }
}
