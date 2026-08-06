<?php

namespace Tests\Feature\Assistant;

use App\Domain\Planning\Clock;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductRelation;
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
        $ids = array_column($result->content['service_orders'], 'service_order_id');

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
        $ids = array_column($result->content['service_orders'], 'service_order_id');

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
        $ids = array_column($result->content['assets'], 'asset_id');

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
        )->pluck('asset_id');

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

    /**
     * A place on its own is a question, not half of one.
     *
     * "Welke klanten zijn er in Meteren?" was refused three times in a row, each
     * time asking for a name the person had already said they did not have — which
     * was the entire reason they were asking. The tool offered a city argument that
     * could not be used without also knowing the answer.
     */
    public function test_a_place_on_its_own_is_enough_to_search_on(): void
    {
        $user = $this->userWith('customer.read');

        Customer::factory()->create(['name' => 'Majorlabel', 'city' => 'Meteren']);
        Customer::factory()->create(['name' => 'Ster Timmerwerken', 'city' => 'Meteren']);
        Customer::factory()->create(['name' => 'Bouwbedrijf Kreeft', 'city' => 'Ede']);

        $found = $this->invoke('find_customer', $user, ['city' => 'Meteren']);

        $this->assertFalse($found->is_error, 'a place alone was refused: ' . $found->summary);

        $names = collect($found->content['customers'])->pluck('name');

        $this->assertCount(2, $names);
        $this->assertContains('Majorlabel', $names->all());
        $this->assertNotContains('Bouwbedrijf Kreeft', $names->all());
    }

    /**
     * And with the place already given, it must not ask for a place again — that
     * was the loop: eighty customers in Meteren came back as "in welke plaats?".
     */
    public function test_it_does_not_ask_for_the_place_it_was_just_given(): void
    {
        $user = $this->userWith('customer.read');

        Customer::factory()->count(12)->create(['city' => 'Meteren']);

        $found = $this->invoke('find_customer', $user, ['city' => 'Meteren']);

        $this->assertStringNotContainsString('plaats kiezen', (string) $found->summary);
        $this->assertStringContainsString('naam', (string) $found->content['note']);
        $this->assertSame(12, $found->content['matches'], 'it reported how many fitted, not how many there are');
    }

    /**
     * How many there are, not how many fitted.
     *
     * Every search here stops at a ceiling, and every one of them reported the
     * slice as the answer: "25 storingen gevonden" with three hundred and sixty in
     * the table. Nothing in that sentence suggests it is partial, so it gets
     * repeated as a total and somebody plans a week around a number that is wrong
     * by an order of magnitude.
     */
    public function test_a_search_that_hit_its_ceiling_says_how_many_there_really_are(): void
    {
        config(['assistant.max_results' => 3]);

        $user = $this->userWith('serviceorder.read');
        ServiceOrder::factory()->count(9)->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);

        $found = $this->invoke('search_service_orders', $user);

        $this->assertCount(3, $found->content['service_orders']);
        $this->assertSame(9, $found->content['total'], 'it counted what fitted rather than what exists');
        $this->assertSame(3, $found->content['shown']);
        $this->assertStringContainsString('van de 9', (string) $found->summary);
        $this->assertStringContainsString('niet alles', $found->content['note']);
    }

    /**
     * A tool with something of its own to say must not lose the sentence saying
     * the list is partial. Array union keeps the left-hand key, so the three tools
     * that set their own note were silently dropping it.
     */
    public function test_a_tool_with_its_own_note_still_says_the_list_is_partial(): void
    {
        config(['assistant.max_results' => 2]);

        $user = $this->userWith('asset.read');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();

        Asset::factory()->count(5)->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'next_service_date' => Clock::today(),
        ]);

        $found = $this->invoke('find_asset', $user, ['due_within_days' => 30]);

        $this->assertSame(5, $found->content['total']);

        /** Both halves, or one of them is being written over the other. */
        $this->assertStringContainsString('niet alles', $found->content['note'], 'the partial warning was clobbered');
        $this->assertStringContainsString('knoppen', $found->content['note'], "the tool's own note was clobbered");
    }

    /** And a search that fits says so plainly, with no query spent counting. */
    public function test_a_search_that_fits_is_reported_as_the_whole_answer(): void
    {
        config(['assistant.max_results' => 25]);

        $user = $this->userWith('serviceorder.read');
        ServiceOrder::factory()->count(2)->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);

        $found = $this->invoke('search_service_orders', $user);

        $this->assertSame(2, $found->content['total']);
        $this->assertArrayNotHasKey('shown', $found->content, 'a complete answer was described as a slice');
        $this->assertStringContainsString('2 werkbonnen gevonden', (string) $found->summary);
    }

    /**
     * The two filters people actually ask by, which did not exist.
     *
     * "Welke storingen staan open" and "welke Mitsubishi's hebben we" are the
     * ordinary questions, and both had to go through free text — which searches
     * model names and descriptions too, so a brand came back mixed with whatever
     * else happened to mention it. The model reached for status and brand by name
     * and got told they were not arguments.
     */
    public function test_storingen_can_be_narrowed_to_the_ones_still_open(): void
    {
        $user = $this->userWith('ticket.read');
        $asset = Asset::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'product_id' => Product::factory()->create()->id,
        ]);

        Ticket::factory()->create(['asset_id' => $asset->id, 'status' => 'Open']);
        Ticket::factory()->create(['asset_id' => $asset->id, 'status' => 'Gesloten']);

        $open = $this->invoke('find_tickets', $user, ['status' => 'Open']);

        $this->assertCount(1, $open->content['tickets']);
        $this->assertSame('Open', $open->content['tickets'][0]['status']);
    }

    public function test_producten_can_be_narrowed_to_one_brand(): void
    {
        $user = $this->userWith('product.read');
        $wanted = Brand::factory()->create(['name' => 'Mitsubishi']);

        Product::factory()->create(['brand_id' => $wanted->id, 'model' => 'SRK 25 ZS-W']);
        Product::factory()->create([
            'brand_id' => Brand::factory()->create(['name' => 'Daikin'])->id,
            'model' => 'Iets anders',
        ]);

        $found = $this->invoke('find_products', $user, ['brand' => 'Mitsubishi']);

        $this->assertCount(1, $found->content['products']);
        $this->assertSame('Mitsubishi', $found->content['products'][0]['brand']);
    }

    /**
     * The places are counted over everything that matched, not over the handful
     * that fitted.
     *
     * Grouped on the loaded rows it reported "Meteren: 25" for a village with
     * eighty — and that is the number a model reads out, with the honest total
     * sitting right beside it, losing.
     */
    public function test_the_places_are_counted_over_the_whole_match_not_the_slice(): void
    {
        /** Above eight, or the branch that withholds the rows never runs. */
        config(['assistant.max_results' => 10]);

        $user = $this->userWith('customer.read');
        Customer::factory()->count(9)->create(['name' => 'Dijkstra Techniek', 'city' => 'Meteren']);
        Customer::factory()->count(4)->create(['name' => 'Dijkgraaf BV', 'city' => 'Ede']);

        $found = $this->invoke('find_customer', $user, ['query' => 'dijk']);

        $this->assertSame(13, $found->content['matches']);
        $this->assertSame(9, $found->content['per_place']['Meteren'], 'the places were counted off the slice');
        $this->assertSame(4, $found->content['per_place']['Ede']);
    }

    /**
     * A recorded pairing rides along with the product.
     *
     * Which buitenunit goes with a binnenunit kept being answered from the shape
     * of the catalogue — "er is maar één Mitsubishi buitendeel, dus dat zal hem
     * zijn" — while the application has a place to record exactly that. Recorded
     * beats deduced, in both directions.
     */
    public function test_a_recorded_pairing_travels_with_the_product(): void
    {
        $user = $this->userWith('product.read');
        $brand = Brand::factory()->create(['name' => 'Mitsubishi']);

        $binnen = Product::factory()->create(['brand_id' => $brand->id, 'model' => 'SRK 25 ZS-W']);
        $buiten = Product::factory()->create(['brand_id' => $brand->id, 'model' => 'SCM 80ZS-W']);
        $relation = ProductRelation::create(['name' => 'Onderdeel']);

        $binnen->childProducts()->attach($buiten->id, [
            'product_relation_id' => $relation->id,
            'quantity' => 1,
            'is_required' => true,
        ]);

        $rows = collect($this->invoke('find_products', $user, ['query' => 'SRK 25'])->content['products']);
        $paired = $rows->firstWhere('product_id', $binnen->id)['related_products'] ?? [];

        $this->assertCount(1, $paired, 'the recorded buitenunit did not ride along');
        $this->assertSame($buiten->id, $paired[0]['product_id']);
        $this->assertSame('Onderdeel', $paired[0]['relation']);
        $this->assertTrue($paired[0]['required']);

        /** And from the other side, so the buitenunit knows what it belongs to. */
        $other = collect($this->invoke('find_products', $user, ['query' => 'SCM 80'])->content['products']);
        $reverse = $other->firstWhere('product_id', $buiten->id)['related_products'] ?? [];

        $this->assertCount(1, $reverse);
        $this->assertSame($binnen->id, $reverse[0]['product_id']);
        $this->assertSame('Onderdeel van', $reverse[0]['relation']);
    }

    /** No pairing recorded means no key at all — an empty list reads as "checked, none". */
    public function test_a_product_without_pairings_says_nothing_about_them(): void
    {
        $user = $this->userWith('product.read');
        Product::factory()->create([
            'brand_id' => Brand::factory()->create(['name' => 'Zehnder'])->id,
            'model' => 'WHR 930',
        ]);

        $row = collect($this->invoke('find_products', $user, ['query' => 'WHR'])->content['products'])->first();

        $this->assertArrayNotHasKey('related_products', $row, 'absence was dressed up as an empty answer');
    }

    /**
     * A typeplaatje and a catalogue never spell a model the same way.
     *
     * The plate reads SRK35ZS-WF and the catalogue holds "SRK 35 ZS-WF", so the
     * one search that matters most — the number somebody just read off the
     * machine — found nothing at all.
     */
    public function test_a_model_number_is_found_however_it_is_spaced(): void
    {
        $user = $this->userWith('product.read');
        $brand = Brand::factory()->create(['name' => 'Mitsubishi']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'model' => 'SRK 35 ZS-WF']);

        foreach (['SRK35ZS-WF', 'srk35zswf', 'SRK 35 ZS-WF'] as $spelling) {
            $found = $this->invoke('find_products', $user, ['query' => $spelling]);

            $this->assertSame(
                [$product->id],
                array_column($found->content['products'] ?? [], 'product_id'),
                '"' . $spelling . '" found the wrong thing',
            );
        }
    }

    /**
     * The plate says "Mitsubishi Heavy Industries" and the catalogue says
     * "Mitsubishi". Looking only for the longer inside the shorter found no
     * brand at all, and the answer that followed invented a reason why.
     */
    public function test_a_brand_matches_the_longer_name_on_the_plate(): void
    {
        $user = $this->userWith('product.read');
        $mitsubishi = Brand::factory()->create(['name' => 'Mitsubishi']);
        Product::factory()->create(['brand_id' => $mitsubishi->id, 'model' => 'SRK 25 ZS-W']);
        Product::factory()->create([
            'brand_id' => Brand::factory()->create(['name' => 'Tosot'])->id,
            'model' => 'Iets anders',
        ]);

        $found = $this->invoke('find_products', $user, ['brand' => 'Mitsubishi Heavy Industries']);

        $this->assertCount(1, $found->content['products']);
        $this->assertSame('Mitsubishi', $found->content['products'][0]['brand']);
    }
}
