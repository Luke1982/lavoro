<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicedServiceOrderIsClosedTest extends TestCase
{
    use RefreshDatabase;

    private ServiceOrderStage $open_stage;

    private ServiceOrderStage $closed_stage;

    private ServiceOrderStage $invoiced_stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->open_stage = ServiceOrderStage::create(['name' => 'Open', 'order' => 1]);
        $this->closed_stage = ServiceOrderStage::create([
            'name' => 'Gesloten',
            'order' => 2,
            'is_closed_state' => true,
        ]);
        $this->invoiced_stage = ServiceOrderStage::create([
            'name' => 'Gefactureerd',
            'order' => 3,
            'is_invoiced_state' => true,
        ]);
    }

    private function admin_user(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function order_in_stage(ServiceOrderStage $stage): ServiceOrder
    {
        return ServiceOrder::factory()->create(['service_order_stage_id' => $stage->id]);
    }

    public function test_an_invoiced_order_reads_as_closed(): void
    {
        $this->assertTrue($this->order_in_stage($this->invoiced_stage)->fresh()->is_closed);
        $this->assertFalse($this->order_in_stage($this->open_stage)->fresh()->is_closed);
    }

    public function test_moving_straight_to_invoiced_stamps_the_closing_date(): void
    {
        $order = $this->order_in_stage($this->open_stage);

        $order->moveToStage($this->invoiced_stage);

        $this->assertNotNull($order->fresh()->closed_on);
    }

    public function test_invoicing_a_closed_order_keeps_the_closing_date(): void
    {
        $order = $this->order_in_stage($this->open_stage);
        $order->moveToStage($this->closed_stage);
        $closed_on = $order->fresh()->closed_on;

        $order->moveToStage($this->invoiced_stage);

        $this->assertEquals($closed_on, $order->fresh()->closed_on);
    }

    public function test_filtering_on_a_stage_returns_that_stage_alone(): void
    {
        $closed_order = $this->order_in_stage($this->closed_stage);
        $invoiced_order = $this->order_in_stage($this->invoiced_stage);
        $open_order = $this->order_in_stage($this->open_stage);

        $response = $this->actingAs($this->admin_user())
            ->get('/serviceorders?onlyStage=' . $this->closed_stage->id);

        $props = $response->viewData('page')['props'];
        $ids = collect($props['serviceOrders']['data'])->pluck('id');

        $this->assertTrue($ids->contains($closed_order->id));
        $this->assertFalse($ids->contains($invoiced_order->id));
        $this->assertFalse($ids->contains($open_order->id));
        $this->assertSame([$this->closed_stage->id], $props['onlyStage']);
    }

    public function test_the_invoiced_flag_cannot_move_before_the_closed_stage(): void
    {
        $this->actingAs($this->admin_user())
            ->patch('/serviceorderstages/' . $this->open_stage->id, ['is_invoiced_state' => true])
            ->assertSessionHasErrors('is_invoiced_state');

        $this->assertFalse($this->open_stage->fresh()->is_invoiced_state);
        $this->assertTrue($this->invoiced_stage->fresh()->is_invoiced_state);
    }

    public function test_the_closed_flag_cannot_move_after_the_invoiced_stage(): void
    {
        $late_stage = ServiceOrderStage::create(['name' => 'Nazorg', 'order' => 4]);

        $this->actingAs($this->admin_user())
            ->patch('/serviceorderstages/' . $late_stage->id, ['is_closed_state' => true])
            ->assertSessionHasErrors('is_closed_state');

        $this->assertTrue($this->closed_stage->fresh()->is_closed_state);
    }

    public function test_the_closed_stage_cannot_be_given_an_order_past_the_invoiced_stage(): void
    {
        $this->actingAs($this->admin_user())
            ->patch('/serviceorderstages/' . $this->closed_stage->id, ['order' => 4])
            ->assertSessionHasErrors('order');

        $this->assertSame(2, (int) $this->closed_stage->fresh()->order);
    }

    public function test_a_stage_can_still_take_over_the_invoiced_flag(): void
    {
        $late_stage = ServiceOrderStage::create(['name' => 'Nazorg', 'order' => 4]);

        $this->actingAs($this->admin_user())
            ->patch('/serviceorderstages/' . $late_stage->id, ['is_invoiced_state' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($late_stage->fresh()->is_invoiced_state);
        $this->assertFalse($this->invoiced_stage->fresh()->is_invoiced_state);
    }

    public function test_creating_a_stage_is_unaffected(): void
    {
        $this->actingAs($this->admin_user())
            ->post('/serviceorderstages', ['name' => 'Nieuw'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('service_order_stages', ['name' => 'Nieuw']);
    }

    public function test_reordering_cannot_put_the_invoiced_stage_before_the_closed_stage(): void
    {
        $this->actingAs($this->admin_user())
            ->post('/serviceorderstages/reorder', ['payload' => [
                ['id' => $this->open_stage->id, 'order' => 1],
                ['id' => $this->invoiced_stage->id, 'order' => 2],
                ['id' => $this->closed_stage->id, 'order' => 3],
            ]])
            ->assertSessionHasErrors('payload');

        $this->assertSame(2, (int) $this->closed_stage->fresh()->order);
        $this->assertSame(3, (int) $this->invoiced_stage->fresh()->order);
    }

    public function test_a_valid_reorder_still_goes_through(): void
    {
        $this->actingAs($this->admin_user())
            ->post('/serviceorderstages/reorder', ['payload' => [
                ['id' => $this->closed_stage->id, 'order' => 1],
                ['id' => $this->open_stage->id, 'order' => 2],
                ['id' => $this->invoiced_stage->id, 'order' => 3],
            ]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $this->closed_stage->fresh()->order);
    }
}
