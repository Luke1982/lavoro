<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Logging a storing and opening a werkbon.
 *
 * The failures worth guarding are the ones that produce a perfectly readable
 * record: a werkbon carrying somebody else's machine, a storing on a machine the
 * person who logged it cannot open, a priority no screen knows how to show.
 */
class CreateRecordToolsTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->create();
    }

    private function assetOf(Customer $customer): Asset
    {
        return Asset::factory()->create(['customer_id' => $customer->id]);
    }

    private function carryOut(string $tool, array $arguments, ?User $user = null): ToolResult
    {
        $user ??= $this->userWithPermissions('ticket.create', 'serviceorder.create', 'ticket.read', 'asset.read');

        return app(ToolExecutor::class)->run(new ToolCall(
            $tool,
            $arguments,
            $user,
            confirmation_token: ConfirmationToken::for($tool, $arguments, $user)->encoded(),
        ));
    }

    public function test_a_storing_is_logged_against_its_machine(): void
    {
        $asset = $this->assetOf(Customer::factory()->create());

        $result = $this->carryOut('create_ticket', [
            'asset_id' => $asset->id,
            'subject' => 'Lekkage',
            'description' => 'Water onder de binnenunit.',
        ]);

        $this->assertFalse($result->is_error, json_encode($result->content));

        $ticket = Ticket::sole();

        $this->assertSame($asset->id, $ticket->asset_id);
        $this->assertSame('Open', $ticket->status);
        $this->assertSame('Normaal', $ticket->priority);
    }

    public function test_logging_a_storing_asks_first(): void
    {
        $asset = $this->assetOf(Customer::factory()->create());
        $user = $this->userWith('ticket.create');

        $result = app(ToolExecutor::class)->run(new ToolCall('create_ticket', [
            'asset_id' => $asset->id, 'subject' => 'x', 'description' => 'y',
        ], $user));

        $this->assertSame('bevestiging_nodig', $result->content['status']);
        $this->assertSame(0, Ticket::count());
    }

    /**
     * Every status this application shows comes off an enum, so one that is not
     * on it goes into the column and then belongs to no screen at all.
     */
    public function test_a_status_no_screen_knows_is_refused(): void
    {
        $asset = $this->assetOf(Customer::factory()->create());

        $result = $this->carryOut('create_ticket', [
            'asset_id' => $asset->id,
            'subject' => 'x',
            'description' => 'y',
            'status' => 'Wachtend op onderdelen',
        ]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Ticket::count());
    }

    public function test_a_storing_on_a_machine_somebody_cannot_see_is_refused(): void
    {
        $asset = $this->assetOf(Customer::factory()->create());

        $result = $this->carryOut('create_ticket', [
            'asset_id' => $asset->id, 'subject' => 'x', 'description' => 'y',
        ], $this->userWithPermissions('ticket.create', 'serviceorder.read_own'));

        $this->assertTrue($result->is_error, 'a storing was logged on a machine its author cannot open');
        $this->assertSame(0, Ticket::count());
    }

    public function test_somebody_without_the_permission_cannot_log_one(): void
    {
        $asset = $this->assetOf(Customer::factory()->create());

        $result = $this->carryOut('create_ticket', [
            'asset_id' => $asset->id, 'subject' => 'x', 'description' => 'y',
        ], $this->userWith('asset.read'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Ticket::count());
    }

    public function test_a_werkbon_is_opened_with_its_machines_and_storingen(): void
    {
        $customer = Customer::factory()->create();
        $asset = $this->assetOf($customer);
        $ticket = Ticket::factory()->create(['asset_id' => $asset->id, 'service_order_id' => null]);

        $result = $this->carryOut('create_service_order', [
            'customer_id' => $customer->id,
            'description' => 'Jaarlijks onderhoud',
            'asset_ids' => [$asset->id],
            'ticket_ids' => [$ticket->id],
        ]);

        $this->assertFalse($result->is_error, json_encode($result->content));

        $order = ServiceOrder::sole();

        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('Jaarlijks onderhoud', $order->description);
        $this->assertSame([$asset->id], $order->serviceJobs->pluck('asset_id')->all());
        $this->assertSame($order->id, $ticket->fresh()->service_order_id);
    }

    /**
     * The one that reads perfectly and sends somebody to the wrong address.
     */
    public function test_a_machine_belonging_to_another_customer_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $someone_else = $this->assetOf(Customer::factory()->create());

        $result = $this->carryOut('create_service_order', [
            'customer_id' => $customer->id,
            'asset_ids' => [$someone_else->id],
        ]);

        $this->assertTrue($result->is_error, "another customer's machine was put on the werkbon");
        $this->assertSame(0, ServiceOrder::count());
    }

    public function test_a_storing_already_on_another_werkbon_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $asset = $this->assetOf($customer);
        $existing = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $ticket = Ticket::factory()->create(['asset_id' => $asset->id, 'service_order_id' => $existing->id]);

        $result = $this->carryOut('create_service_order', [
            'customer_id' => $customer->id,
            'ticket_ids' => [$ticket->id],
        ]);

        $this->assertTrue($result->is_error, 'a storing was taken off the werkbon it was already on');
        $this->assertSame($existing->id, $ticket->fresh()->service_order_id);
    }

    public function test_a_location_belonging_to_another_customer_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $elsewhere = Location::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $result = $this->carryOut('create_service_order', [
            'customer_id' => $customer->id,
            'location_id' => $elsewhere->id,
        ]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, ServiceOrder::count());
    }

    /**
     * Nothing at all should be left behind when part of it is refused, so the
     * whole thing runs in one transaction.
     */
    public function test_nothing_is_left_behind_when_part_of_it_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $mine = $this->assetOf($customer);
        $theirs = $this->assetOf(Customer::factory()->create());

        $this->carryOut('create_service_order', [
            'customer_id' => $customer->id,
            'asset_ids' => [$mine->id, $theirs->id],
        ]);

        $this->assertSame(0, ServiceOrder::count());
    }

    public function test_somebody_without_the_permission_cannot_open_one(): void
    {
        $customer = Customer::factory()->create();

        $result = $this->carryOut('create_service_order', [
            'customer_id' => $customer->id,
        ], $this->userWith('serviceorder.read_own'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, ServiceOrder::count());
    }
}
