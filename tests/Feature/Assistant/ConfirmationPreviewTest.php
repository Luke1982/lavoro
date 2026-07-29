<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\Write\CreateServiceOrderTool;
use App\Domain\Tools\Write\CreateTicketTool;
use App\Models\Asset;
use App\Models\Customer;
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
}
