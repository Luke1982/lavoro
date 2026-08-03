<?php

namespace Tests\Feature\Assistant;

use App\Domain\Planning\Clock;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\Write\CreateServiceOrderTool;
use App\Domain\Tools\Write\CreateTicketTool;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * What the confirmation button says it will do.
 *
 * The button sits under a paragraph of the model's prose, and after two more
 * questions that paragraph is halfway up the box. "Bevestigen" on its own is
 * then asking somebody to agree to something they would have to scroll back to
 * read — and what they are agreeing to is a record that gets made.
 */
class ConfirmationPreviewTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    /** The machine factory picks an existing product, before any override lands. */
    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->create();
    }

    private function propose(string $tool, array $arguments): array
    {
        $result = app(ToolExecutor::class)->run(new ToolCall(
            $tool,
            $arguments,
            $this->userWithPermissions('ticket.create', 'serviceorder.create', 'asset.read'),
        ));

        return $result->content;
    }

    public function test_a_proposed_storing_says_which_machine_and_how_urgent(): void
    {
        $asset = Asset::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $content = $this->propose(CreateTicketTool::name(), [
            'asset_id' => $asset->id,
            'subject' => 'Lekkage onder binnenunit',
            'description' => 'Water op de vloer.',
            'priority' => 'Hoog',
        ]);

        $this->assertStringContainsString($asset->serial_number, $content['preview']);
        $this->assertStringContainsString('Lekkage onder binnenunit', $content['preview']);
        $this->assertStringContainsString('Hoog', $content['preview']);
    }

    public function test_a_proposed_werkbon_says_for_whom_and_what_goes_on_it(): void
    {
        $customer = Customer::factory()->create(['name' => 'J. van Reemst van Dijk']);
        $asset = Asset::factory()->create(['customer_id' => $customer->id]);

        $content = $this->propose(CreateServiceOrderTool::name(), [
            'customer_id' => $customer->id,
            'description' => 'Installatie airco',
            'asset_ids' => [$asset->id],
        ]);

        $this->assertStringContainsString('J. van Reemst van Dijk', $content['preview']);
        $this->assertStringContainsString('Installatie airco', $content['preview']);
        $this->assertStringContainsString('1 machine', $content['preview']);
    }

    /**
     * Read off the arguments rather than off the prose, so the button cannot
     * describe one thing while carrying out another.
     */
    public function test_the_description_comes_from_what_will_actually_run(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bakker Klimaattechniek']);

        $content = $this->propose(CreateServiceOrderTool::name(), ['customer_id' => $customer->id]);

        $this->assertStringContainsString('Bakker Klimaattechniek', $content['preview']);
        $this->assertSame(['customer_id' => $customer->id], $content['proposed']);
    }

    /**
     * What somebody is agreeing to, in full. Shown only a start time, an afternoon
     * and a whole day read alike; shown no address, a morning gets approved for
     * the wrong building of the right customer. Both are things the person
     * approving can catch in a second and nobody else can catch at all.
     */
    public function test_planning_an_appointment_shows_both_ends_of_it_and_where(): void
    {
        $user = $this->userWith('event.create');
        $customer = Customer::factory()->create(['name' => 'Jansen Elektrotechniek']);
        $site = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Fabrieksweg 8',
            'postal_code' => '7000AA',
            'city' => 'Doetinchem',
        ]);

        $day = Clock::todayAsDate()->addDay()->toDateString();

        $preview = app(ToolExecutor::class)->run(new ToolCall('create_event', [
            'starts_at' => $day . ' 08:00',
            'ends_at' => $day . ' 12:30',
            'user_ids' => [$user->id],
            'event_type' => 'Onderhoud',
            'location_id' => $site->id,
            'create_service_order_for_customer_id' => $customer->id,
        ], $user))->content['preview'];

        $this->assertStringContainsString('08:00', $preview);
        $this->assertStringContainsString('12:30', $preview, 'no end time, so a morning and a whole day look the same');
        $this->assertStringContainsString('Fabrieksweg 8', $preview, 'nothing said where anybody has to be');
        $this->assertStringContainsString('Jansen Elektrotechniek', $preview);
    }

    /**
     * A preview must not show somebody else's address as though it were settled.
     *
     * Asked to plan at Majorlabel, the button read "Dalidastraat 25, Lent" — a
     * real address, of a different customer, on a location the write itself would
     * have refused. Reading that, you approve a morning at the wrong building; the
     * only reason it did not happen is that the write said no afterwards.
     */
    public function test_a_location_belonging_to_another_customer_is_not_shown_as_the_address(): void
    {
        $user = $this->userWith('event.create');
        $mine = Customer::factory()->create(['name' => 'Majorlabel']);
        $theirs = Customer::factory()->create(['name' => 'Iemand anders']);

        $elsewhere = Location::factory()->create([
            'customer_id' => $theirs->id,
            'address' => 'Dalidastraat 25',
            'postal_code' => '6663MJ',
            'city' => 'Lent',
        ]);

        $day = Clock::todayAsDate()->addDay()->toDateString();

        $preview = app(ToolExecutor::class)->run(new ToolCall('create_event', [
            'starts_at' => $day . ' 07:00',
            'ends_at' => $day . ' 15:00',
            'user_ids' => [$user->id],
            'event_type' => 'Onderhoud',
            'location_id' => $elsewhere->id,
            'create_service_order_for_customer_id' => $mine->id,
        ], $user))->content['preview'];

        $this->assertStringNotContainsString('Dalidastraat', $preview, "another customer's address was presented as the site");
        $this->assertStringContainsString('hoort niet bij deze klant', $preview, 'nothing said the location was wrong');
    }
}
