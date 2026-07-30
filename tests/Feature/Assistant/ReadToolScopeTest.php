<?php

namespace Tests\Feature\Assistant;

use App\Domain\Planning\Clock;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A read tool must return exactly what the person would have found by clicking
 * through the interface — no more, and no fewer.
 */
class ReadToolScopeTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function invoke(string $tool, User $user, array $arguments = []): ToolResult
    {
        return app(ToolExecutor::class)->run(new ToolCall($tool, $arguments, $user));
    }

    private function order(?Customer $customer = null): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => ($customer ?? Customer::factory()->create())->id,
        ]);
    }

    public function test_a_technician_only_gets_the_werkbonnen_they_execute(): void
    {
        $user = $this->userWith('serviceorder.read_own');
        $mine = $this->order();
        $mine->syncExecutingUsers([$user->id]);
        $this->order();

        $result = $this->invoke('search_service_orders', $user);
        $ids = array_column($result->content['service_orders'], 'id');

        $this->assertSame([$mine->id], $ids);
    }

    /**
     * An order without a stage is open by ServiceOrder::is_closed, so asking for
     * a stage that says "not closed" would drop every one of them. Reading the
     * absence of a closed stage is what matches the model.
     */
    public function test_only_open_includes_a_werkbon_that_has_no_stage_at_all(): void
    {
        $user = $this->userWith('serviceorder.read');

        $closed_stage = ServiceOrderStage::create([
            'name' => 'Afgesloten', 'order' => 9, 'is_closed_state' => true,
        ]);

        /**
         * ServiceOrder::booted() hands a new order the first stage, so having no
         * stage has to be stated rather than assumed — otherwise this passes for
         * the wrong reason on a database that happens to have no stages yet.
         */
        $stageless = $this->order();
        $stageless->update(['service_order_stage_id' => null]);

        $closed = $this->order();
        $closed->update(['service_order_stage_id' => $closed_stage->id]);

        $result = $this->invoke('search_service_orders', $user, ['only_open' => true]);
        $ids = array_column($result->content['service_orders'], 'id');

        $this->assertContains($stageless->id, $ids, 'a stageless werkbon is open but was hidden');
        $this->assertNotContains($closed->id, $ids, 'a closed werkbon was reported as open');
    }

    /**
     * How much comes back is ours to decide, and there is no argument for it. A
     * model that asks for a thousand anyway is told the argument does not exist
     * rather than quietly handed the ceiling, because an argument silently
     * ignored is indistinguishable from one that was honoured.
     */
    public function test_the_result_limit_cannot_be_argued_past_the_configured_ceiling(): void
    {
        config(['assistant.max_results' => 3]);

        $user = $this->userWith('serviceorder.read');
        ServiceOrder::factory()->count(6)->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);

        $this->assertCount(3, $this->invoke('search_service_orders', $user)->content['service_orders']);

        $argued = $this->invoke('search_service_orders', $user, ['limit' => 1000]);

        $this->assertTrue($argued->is_error, 'an argument this tool does not have was accepted anyway');
        $this->assertStringContainsString('limit', (string) $argued->summary);
    }

    public function test_finding_customers_is_refused_without_the_customer_permission(): void
    {
        $result = $this->invoke('find_customer', $this->userWith('serviceorder.read_own'), ['query' => 'aa']);

        $this->assertTrue($result->is_error);
    }

    public function test_a_technician_only_gets_machines_from_their_own_open_werkbonnen(): void
    {
        $user = $this->userWith('asset.read.relevant.serviceorder');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();

        $mine = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id]);
        $theirs = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id]);

        $order = $this->order($customer);
        $order->syncExecutingUsers([$user->id]);
        Ticket::factory()->create(['asset_id' => $mine->id, 'service_order_id' => $order->id]);

        $result = $this->invoke('find_asset', $user);
        $ids = array_column($result->content['assets'], 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, 'a machine from someone else\'s werkbon leaked');
    }

    /**
     * There is no name column on products; a product is its brand plus its model.
     * Reading a name returns nothing, which is silent rather than loud.
     */
    public function test_a_machine_is_labelled_with_its_brand_and_model(): void
    {
        $user = $this->userWith('asset.read');
        $product = Product::factory()->create(['model' => 'WHR 930']);
        $asset = Asset::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'product_id' => $product->id,
        ]);

        $result = $this->invoke('find_asset', $user, ['serial_number' => $asset->serial_number]);
        $label = $result->content['assets'][0]['product'];

        $this->assertStringContainsString('WHR 930', (string) $label);
        $this->assertStringContainsString((string) $product->brand->name, (string) $label);
    }

    /**
     * A service due today is due. Compared against the moment it is now rather than
     * the day, it sits at midnight, falls just before, and quietly leaves the list
     * that exists to catch it.
     */
    public function test_a_service_due_today_is_in_the_coming_week(): void
    {
        $user = $this->userWith('asset.read');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();

        $today = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'next_service_date' => Clock::today(),
        ]);

        $later = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'next_service_date' => Clock::todayAsDate()->addDays(30)->toDateString(),
        ]);

        $found = collect(
            $this->invoke('find_asset', $user, ['due_within_days' => 7])->content['assets']
        )->pluck('id');

        $this->assertContains($today->id, $found->all(), 'the one due today fell out of the week');
        $this->assertNotContains($later->id, $found->all(), 'something a month out came back as this week');
    }

    /**
     * The one that made this worth building. A model that has a customer's name but
     * not their number sends the name, every filter falls away, and twenty-five
     * machines belonging to six other customers come back under that customer's
     * name. Nothing about the answer looks wrong, which is what makes it bad.
     */
    public function test_a_filter_that_cannot_be_read_refuses_rather_than_falling_away(): void
    {
        $user = $this->userWith('asset.read');
        $product = Product::factory()->create();
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();

        Asset::factory()->create(['customer_id' => $mine->id, 'product_id' => $product->id]);
        Asset::factory()->create(['customer_id' => $theirs->id, 'product_id' => $product->id]);

        $named = $this->invoke('find_asset', $user, ['customer_id' => $mine->name]);

        $this->assertTrue($named->is_error, 'a name where a number belongs returned everybody\'s machines');
        $this->assertStringContainsString('customer_id', (string) $named->summary);

        /** And the number itself still works, so the guard has not closed the door. */
        $numbered = $this->invoke('find_asset', $user, ['customer_id' => $mine->id]);

        $this->assertFalse($numbered->is_error);
        $this->assertCount(1, $numbered->content['assets']);
    }

    /**
     * A near miss on the name of an argument fails the same way: nothing declares
     * "customer", so nothing filters on it, and the whole table comes back.
     */
    public function test_an_argument_that_does_not_exist_is_refused_not_ignored(): void
    {
        $user = $this->userWith('asset.read');

        $result = $this->invoke('find_asset', $user, ['customer' => 12]);

        $this->assertTrue($result->is_error, 'an undeclared argument was quietly dropped');
        $this->assertStringContainsString('customer_id', (string) $result->summary, 'it never says what to use instead');
    }

    /** Numbers written as text are how half the providers send them, and they are fine. */
    public function test_a_number_written_as_text_is_still_a_number(): void
    {
        $user = $this->userWith('asset.read');
        $product = Product::factory()->create();
        $customer = Customer::factory()->create();
        Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id]);

        $result = $this->invoke('find_asset', $user, ['customer_id' => (string) $customer->id]);

        $this->assertFalse($result->is_error, 'a perfectly good id was refused for being quoted');
        $this->assertCount(1, $result->content['assets']);
    }

    /**
     * A lookup that never happened must not be reported as one that came back
     * empty. Asked to research a storing without being given one, the tool used to
     * answer "Storing #? niet gevonden", and a model reading that tells somebody
     * their storing does not exist.
     */
    public function test_a_missing_required_argument_is_said_out_loud(): void
    {
        $user = $this->userWith('ticket.read');

        $result = $this->invoke('research_ticket', $user, []);

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('ticket_id', (string) $result->summary);
        $this->assertStringNotContainsString('niet gevonden', (string) $result->summary, 'it claimed to have looked');
    }
}
