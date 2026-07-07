<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderClosingTest extends TestCase
{
    use RefreshDatabase;

    private function admin_user(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function open_and_closed_stages(): array
    {
        $open_stage = ServiceOrderStage::create([
            'name' => 'Open',
            'order' => 1,
            'is_closed_state' => false,
        ]);

        $closed_stage = ServiceOrderStage::create([
            'name' => 'Gesloten',
            'order' => 2,
            'is_closed_state' => true,
        ]);

        return [$open_stage, $closed_stage];
    }

    private function service_order_in_open_stage(ServiceOrderStage $open_stage, array $overrides = []): ServiceOrder
    {
        $customer = Customer::factory()->create();

        return ServiceOrder::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'service_order_stage_id' => $open_stage->id,
            'closed_on' => null,
        ], $overrides));
    }

    public function test_service_order_can_be_closed_when_name_and_signature_are_present(): void
    {
        $admin = $this->admin_user();
        [$open_stage, $closed_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_open_stage($open_stage, [
            'signed_by' => 'Jane Doe',
            'signature_base64' => 'data:image/png;base64,abc123',
        ]);

        $response = $this->actingAs($admin)->patch("/serviceorders/{$service_order->id}", [
            'customer_id' => $service_order->customer_id,
            'service_order_stage_id' => $closed_stage->id,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('service_orders', [
            'id' => $service_order->id,
            'service_order_stage_id' => $closed_stage->id,
        ]);
        $this->assertNotNull($service_order->fresh()->closed_on);
    }

    public function test_service_order_cannot_be_closed_without_signed_by(): void
    {
        $admin = $this->admin_user();
        [$open_stage, $closed_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_open_stage($open_stage, [
            'signed_by' => null,
            'signature_base64' => 'data:image/png;base64,abc123',
        ]);

        $response = $this->actingAs($admin)->patch("/serviceorders/{$service_order->id}", [
            'customer_id' => $service_order->customer_id,
            'service_order_stage_id' => $closed_stage->id,
        ]);

        $response->assertSessionHasErrors('service_order_stage_id');
        $this->assertDatabaseHas('service_orders', [
            'id' => $service_order->id,
            'service_order_stage_id' => $open_stage->id,
        ]);
    }

    public function test_service_order_cannot_be_closed_without_signature(): void
    {
        $admin = $this->admin_user();
        [$open_stage, $closed_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_open_stage($open_stage, [
            'signed_by' => 'Jane Doe',
            'signature_base64' => null,
        ]);

        $response = $this->actingAs($admin)->patch("/serviceorders/{$service_order->id}", [
            'customer_id' => $service_order->customer_id,
            'service_order_stage_id' => $closed_stage->id,
        ]);

        $response->assertSessionHasErrors('service_order_stage_id');
        $this->assertDatabaseHas('service_orders', [
            'id' => $service_order->id,
            'service_order_stage_id' => $open_stage->id,
        ]);
    }

    public function test_service_order_cannot_be_closed_without_signed_by_or_signature(): void
    {
        $admin = $this->admin_user();
        [$open_stage, $closed_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_open_stage($open_stage, [
            'signed_by' => null,
            'signature_base64' => null,
        ]);

        $response = $this->actingAs($admin)->patch("/serviceorders/{$service_order->id}", [
            'customer_id' => $service_order->customer_id,
            'service_order_stage_id' => $closed_stage->id,
        ]);

        $response->assertSessionHasErrors('service_order_stage_id');
        $this->assertDatabaseHas('service_orders', [
            'id' => $service_order->id,
            'service_order_stage_id' => $open_stage->id,
        ]);
    }
}
