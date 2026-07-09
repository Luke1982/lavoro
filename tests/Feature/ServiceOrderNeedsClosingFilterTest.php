<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderNeedsClosingFilterTest extends TestCase
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

    private function service_order_in_stage(ServiceOrderStage $stage): ServiceOrder
    {
        $customer = Customer::factory()->create();

        return ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
            'service_order_stage_id' => $stage->id,
        ]);
    }

    private function attach_event(ServiceOrder $service_order, array $overrides = []): Event
    {
        $event_type = EventType::first() ?? EventType::factory()->create();

        $event = Event::factory()->create(array_merge([
            'event_type_id' => $event_type->id,
        ], $overrides));

        $event->serviceOrders()->attach($service_order->id);

        return $event;
    }

    private function fetch_ids(User $user, string $query = ''): array
    {
        $response = $this->actingAs($user)->get('/serviceorders' . $query);
        $response->assertOk();

        return collect($response->inertiaProps('serviceOrders.data'))->pluck('id')->all();
    }

    private function fetch_ids_with_filter(User $user): array
    {
        return $this->fetch_ids($user, '?onlyNeedsClosing=1');
    }

    public function test_includes_order_whose_only_event_already_ended_and_stage_is_open(): void
    {
        $admin = $this->admin_user();
        [$open_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_stage($open_stage);
        $this->attach_event($service_order, [
            'status' => 'Afgerond',
            'start' => now()->subDays(2),
            'end' => now()->subDay(),
        ]);

        $this->assertContains($service_order->id, $this->fetch_ids_with_filter($admin));
    }

    public function test_excludes_order_with_a_future_event(): void
    {
        $admin = $this->admin_user();
        [$open_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_stage($open_stage);
        $this->attach_event($service_order, [
            'status' => 'Gepland',
            'start' => now()->addDay(),
            'end' => now()->addDays(2),
        ]);

        $this->assertNotContains($service_order->id, $this->fetch_ids_with_filter($admin));
    }

    public function test_excludes_order_on_a_closed_stage(): void
    {
        $admin = $this->admin_user();
        [, $closed_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_stage($closed_stage);
        $this->attach_event($service_order, [
            'status' => 'Afgerond',
            'start' => now()->subDays(2),
            'end' => now()->subDay(),
        ]);

        $this->assertNotContains($service_order->id, $this->fetch_ids_with_filter($admin));
    }

    public function test_excludes_order_whose_only_event_is_cancelled(): void
    {
        $admin = $this->admin_user();
        [$open_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_stage($open_stage);
        $this->attach_event($service_order, [
            'status' => 'Geannuleerd',
            'start' => now()->subDays(2),
            'end' => now()->subDay(),
        ]);

        $this->assertNotContains($service_order->id, $this->fetch_ids_with_filter($admin));
    }

    public function test_includes_order_with_a_future_cancelled_event_alongside_a_past_completed_one(): void
    {
        $admin = $this->admin_user();
        [$open_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_stage($open_stage);
        $this->attach_event($service_order, [
            'status' => 'Afgerond',
            'start' => now()->subDays(2),
            'end' => now()->subDay(),
        ]);
        $this->attach_event($service_order, [
            'status' => 'Geannuleerd',
            'start' => now()->addDay(),
            'end' => now()->addDays(2),
        ]);

        $this->assertContains($service_order->id, $this->fetch_ids_with_filter($admin));
    }

    public function test_filter_off_by_default_includes_orders_regardless_of_event_timing(): void
    {
        $admin = $this->admin_user();
        [$open_stage] = $this->open_and_closed_stages();

        $service_order = $this->service_order_in_stage($open_stage);
        $this->attach_event($service_order, [
            'status' => 'Gepland',
            'start' => now()->addDay(),
            'end' => now()->addDays(2),
        ]);

        $this->assertContains($service_order->id, $this->fetch_ids($admin));
    }
}
