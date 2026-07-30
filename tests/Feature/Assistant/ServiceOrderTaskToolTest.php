<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\AddServiceOrderTaskTool;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The part a mechanic actually works from.
 *
 * A werkbon whose description reads "installatie airco" and carries no tasks says
 * install an airco and nothing about which one or how many — the difference
 * between a job sheet and a note.
 */
class ServiceOrderTaskToolTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function carryOut(array $arguments, ?User $user = null): ToolResult
    {
        $user ??= $this->userWithPermissions('serviceordertaskinstance.create', 'serviceorder.read', 'product.read');

        return app(ToolExecutor::class)->run(new ToolCall(
            AddServiceOrderTaskTool::name(),
            $arguments,
            $user,
            confirmation_token: ConfirmationToken::for(AddServiceOrderTaskTool::name(), $arguments, $user)->encoded(),
        ));
    }

    public function test_a_task_records_the_product_and_how_many(): void
    {
        $order = $this->order();
        $product = Product::factory()->create();

        $result = $this->carryOut([
            'service_order_id' => $order->id,
            'description' => 'Binnenunits plaatsen',
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $this->assertFalse($result->is_error, json_encode($result->content));

        $task = ServiceOrderTaskInstance::sole();

        $this->assertSame($order->id, $task->service_order_id);
        $this->assertSame($product->id, $task->product_id);
        $this->assertSame(10, $task->quantity);
    }

    public function test_it_asks_before_it_writes(): void
    {
        $order = $this->order();

        $result = app(ToolExecutor::class)->run(new ToolCall(AddServiceOrderTaskTool::name(), [
            'service_order_id' => $order->id, 'description' => 'Iets doen',
        ], $this->userWithPermissions('serviceordertaskinstance.create', 'serviceorder.read')));

        $this->assertSame('bevestiging_nodig', $result->content['status']);
        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    /**
     * The same rule the form applies. Neither a standard task nor a description
     * is a line on a job sheet that means nothing to whoever reads it.
     */
    public function test_a_task_that_says_nothing_is_refused(): void
    {
        $result = $this->carryOut(['service_order_id' => $this->order()->id]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    public function test_a_quantity_outside_what_the_column_takes_is_refused(): void
    {
        $order = $this->order();

        foreach ([0, -5, 1000] as $quantity) {
            $result = $this->carryOut([
                'service_order_id' => $order->id,
                'description' => 'x',
                'quantity' => $quantity,
            ]);

            $this->assertTrue($result->is_error, 'quantity ' . $quantity . ' was accepted');
        }

        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    public function test_a_task_on_a_werkbon_somebody_cannot_see_is_refused(): void
    {
        $result = $this->carryOut([
            'service_order_id' => $this->order()->id,
            'description' => 'x',
        ], $this->userWithPermissions('serviceordertaskinstance.create', 'serviceorder.read_own'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    public function test_it_needs_its_own_permission(): void
    {
        $result = $this->carryOut([
            'service_order_id' => $this->order()->id,
            'description' => 'x',
        ], $this->userWith('serviceorder.read'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    public function test_a_product_that_does_not_exist_is_refused(): void
    {
        $result = $this->carryOut([
            'service_order_id' => $this->order()->id,
            'description' => 'x',
            'product_id' => 999999,
        ]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    /**
     * Naming a product on a job sheet is reading the catalogue, so it answers to
     * the same permission as reading it anywhere else.
     */
    public function test_a_product_somebody_may_not_read_cannot_be_put_on_a_task(): void
    {
        $product = Product::factory()->create();

        $result = $this->carryOut([
            'service_order_id' => $this->order()->id,
            'description' => 'x',
            'product_id' => $product->id,
        ], $this->userWithPermissions('serviceordertaskinstance.create', 'serviceorder.read'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }
}
