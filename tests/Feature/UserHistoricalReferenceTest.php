<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserHistoricalReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_created_by_and_closed_by_resolve_after_user_is_soft_deleted(): void
    {
        $user = User::factory()->create(['name' => 'Historische Monteur']);
        $product = Product::factory()->create();
        $customer = Customer::factory()->create();
        $asset = Asset::factory()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
        ]);
        $ticket = Ticket::factory()->create([
            'asset_id' => $asset->id,
            'created_by_id' => $user->id,
            'closed_by_id' => $user->id,
        ]);

        $user->delete();

        $fresh_ticket = Ticket::find($ticket->id);
        $this->assertSame('Historische Monteur', $fresh_ticket->createdBy->name);
        $this->assertSame('Historische Monteur', $fresh_ticket->closedBy->name);
        $this->assertNotNull($fresh_ticket->closedBy->deleted_at);
    }

    public function test_service_order_task_instance_completed_by_resolves_after_user_is_soft_deleted(): void
    {
        $user = User::factory()->create(['name' => 'Historische Uitvoerder']);
        $customer = Customer::factory()->create();
        $service_order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $task_instance = ServiceOrderTaskInstance::create([
            'service_order_id' => $service_order->id,
            'title' => 'Historische taak',
            'completed_by' => $user->id,
        ]);

        $user->delete();

        $fresh_instance = ServiceOrderTaskInstance::find($task_instance->id);
        $this->assertSame('Historische Uitvoerder', $fresh_instance->completedBy->name);
    }

    public function test_executing_users_pivot_survives_soft_deletion_of_a_user(): void
    {
        $user = User::factory()->create(['name' => 'Uitvoerende Monteur']);
        $event = Event::factory()->create();
        $event->addExecutingUser($user->id);

        $user->delete();

        $fresh_event = Event::find($event->id);
        $this->assertTrue($fresh_event->hasExecutingUser($user->id));
        $this->assertSame('Uitvoerende Monteur', $fresh_event->executingUsers->first()->name);
    }

    public function test_owners_pivot_survives_soft_deletion_of_a_user(): void
    {
        $user = User::factory()->create(['name' => 'Eigenaar']);
        $event = Event::factory()->create();
        $event->owners()->attach($user->id, ['type' => 'owner']);

        $user->delete();

        $fresh_event = Event::find($event->id);
        $this->assertSame('Eigenaar', $fresh_event->owner()->name);
    }
}
