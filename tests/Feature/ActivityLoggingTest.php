<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_order_can_log_activity(): void
    {
        $customer = Customer::factory()->create();
        $order = ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $activity = $order->logActivity('Werkbon per e-mail verzonden');

        $this->assertInstanceOf(Activity::class, $activity);
        $this->assertEquals('email', $activity->category);
        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'description' => 'Werkbon per e-mail verzonden',
            'category' => 'email',
        ]);
        $this->assertTrue($order->activities->contains($activity));
    }
}
