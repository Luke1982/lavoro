<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A storing has no readership of its own: it is reached through the werkbon or
 * the machine it hangs off. Reading them through a tool must land on the same
 * set as opening them one by one.
 */
class TicketScopeTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    /**
     * The factories for machines and storingen pick an existing product and an
     * existing machine, and they do that before any override is applied — so one
     * of each has to be here before a single line of a test runs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->create();
        Asset::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function invoke(User $user, array $arguments = []): ToolResult
    {
        return app(ToolExecutor::class)->run(new ToolCall('find_tickets', $arguments, $user));
    }

    /** @return array<int, int> */
    private function ticketIds(ToolResult $result): array
    {
        return array_column($result->content['tickets'] ?? [], 'ticket_id');
    }

    /**
     * A storing always has a machine — the column does not allow otherwise — so
     * the machine here belongs to an unrelated customer. That keeps these tests
     * about the werkbon: whatever they prove, they prove through that and not
     * through a machine the person happened to be able to see anyway.
     */
    private function ticketOnOrder(ServiceOrder $order): Ticket
    {
        return Ticket::factory()->create([
            'service_order_id' => $order->id,
            'asset_id' => Asset::factory()->create(['customer_id' => Customer::factory()->create()->id])->id,
        ]);
    }

    public function test_a_technician_gets_the_storingen_on_a_werkbon_they_execute(): void
    {
        $user = $this->userWith('serviceorder.read_own');

        $mine = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $mine->syncExecutingUsers([$user->id]);
        $ticket = $this->ticketOnOrder($mine);

        $this->assertSame([$ticket->id], $this->ticketIds($this->invoke($user)));
    }

    public function test_a_technician_never_gets_a_storing_on_someone_elses_werkbon(): void
    {
        $user = $this->userWith('serviceorder.read_own');

        $theirs = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $hidden = $this->ticketOnOrder($theirs);

        $this->assertSame([], $this->ticketIds($this->invoke($user)));
        $this->assertSame([], $this->ticketIds($this->invoke($user, ['ids' => [$hidden->id]])));
    }

    /**
     * Asking for a number outright is the obvious way round a scope, so it has to
     * land on the same answer as asking for the list.
     */
    public function test_naming_the_number_does_not_get_past_the_scope(): void
    {
        $user = $this->userWith('serviceorder.read_own');
        $hidden = $this->ticketOnOrder(
            ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id])
        );

        $result = $this->invoke($user, ['ids' => [$hidden->id]]);

        $this->assertFalse($result->is_error);
        $this->assertSame([], $this->ticketIds($result));
    }

    public function test_someone_who_may_read_storingen_gets_them_all(): void
    {
        $ticket = $this->ticketOnOrder(
            ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id])
        );

        $this->assertSame([$ticket->id], $this->ticketIds($this->invoke($this->userWith('ticket.read'))));
    }

    /**
     * The machine is the whole reason this tool exists. Without it the assistant
     * cannot say what a storing is even about, which is what sent it rummaging
     * through the timeline and coming back empty handed.
     */
    public function test_the_machine_comes_back_with_the_storing(): void
    {
        $customer = Customer::factory()->create();
        $asset = Asset::factory()->create(['customer_id' => $customer->id]);
        $ticket = Ticket::factory()->create(['asset_id' => $asset->id]);

        $rows = $this->invoke($this->userWith('ticket.read'), ['ids' => [$ticket->id]])->content['tickets'];

        $this->assertSame($asset->id, $rows[0]['asset_id']);
        $this->assertSame($asset->serial_number, $rows[0]['serial_number']);
        $this->assertSame($customer->name, $rows[0]['customer']);
    }

    public function test_a_storing_on_a_machine_someone_may_see_comes_with_it(): void
    {
        $user = $this->userWith('asset.read');
        $asset = Asset::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $ticket = Ticket::factory()->create(['asset_id' => $asset->id]);

        $this->assertSame([$ticket->id], $this->ticketIds($this->invoke($user)));
    }
}
