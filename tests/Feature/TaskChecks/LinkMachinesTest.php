<?php

namespace Tests\Feature\TaskChecks;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Actions\Tasks\RegisterTaskAssetsAction;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskInstance;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Per-machine checks follow the machine they were done on: the machine entered
 * in a unit's box is tied to that unit's checks, and a machine registered
 * without a unit takes the first unit that has none.
 */
class LinkMachinesTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private Product $product;

    private function task(): ServiceOrderTaskInstance
    {
        $template = ServiceOrderTask::create(['title' => 'Airco installatie']);
        $template->checks()->attach(ServiceOrderTaskCheck::create(['title' => 'Rooster schoongemaakt'])->id, [
            'is_required' => true,
            'sort_order' => 1,
        ]);

        $this->product = Product::factory()->create(['bundle' => false, 'registable' => true]);

        return app(CreateTaskInstanceAction::class)->execute([
            'service_order_id' => ServiceOrder::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
            ])->id,
            'service_order_task_id' => $template->id,
            'product_id' => $this->product->id,
            'quantity' => 3,
            'checks_per_unit' => true,
        ]);
    }

    private function machineOf(ServiceOrderTaskInstance $instance, int $unit_index): ?int
    {
        return $instance->checks()->where('unit_index', $unit_index)->value('asset_id');
    }

    public function test_a_machine_registered_for_a_unit_is_linked_to_that_unit(): void
    {
        $instance = $this->task();

        $asset = app(RegisterTaskAssetsAction::class)->execute($instance, [[
            'product_id' => $this->product->id,
            'serial_number' => 'DK-2231-A',
            'container_index' => null,
            'unit_index' => 2,
        ]])->first();

        $this->assertSame($asset->id, $this->machineOf($instance, 2));
        $this->assertNull($this->machineOf($instance, 0));
        $this->assertNull($this->machineOf($instance, 1));
    }

    public function test_a_machine_without_a_unit_fills_the_lowest_free_unit(): void
    {
        $instance = $this->task();
        $register = app(RegisterTaskAssetsAction::class);

        $register->execute($instance, [[
            'product_id' => $this->product->id,
            'serial_number' => 'DK-2231-A',
            'container_index' => null,
            'unit_index' => 2,
        ]]);
        $second = $register->execute($instance, [[
            'product_id' => $this->product->id,
            'serial_number' => 'DK-2231-B',
            'container_index' => null,
        ]])->first();

        $this->assertSame($second->id, $this->machineOf($instance, 0));
        $this->assertNull($this->machineOf($instance, 1));
    }

    public function test_the_serial_form_passes_the_unit_along(): void
    {
        $instance = $this->task();

        $this->actingAs($this->userWithPermissions('serviceordertaskinstance.open_close'))
            ->post("/serviceordertaskinstances/{$instance->id}/assets", [
                'assets' => [[
                    'product_id' => $this->product->id,
                    'serial_number' => 'DK-2231-C',
                    'container_index' => null,
                    'unit_index' => 1,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Asset::where('serial_number', 'DK-2231-C')->value('id'), $this->machineOf($instance, 1));
    }
}
