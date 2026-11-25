<?php

namespace Tests\Feature\Assistant;

use App\Actions\Pricing\CreatePriceFormulaAction;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\ApplyPriceFormulaTool;
use App\Domain\Tools\Write\BookMaterialsTool;
use App\Domain\Tools\Write\ChangeMaintenanceContractsTool;
use App\Enums\ContractInterval;
use App\Models\AssistantToolCall;
use App\Models\Customer;
use App\Models\MaintenanceContract;
use App\Models\Material;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\BulkSelection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * What the click carries out is what the button said.
 *
 * The approval is sealed with what the person was shown and worked out again
 * on the click. A price that moved in between refuses the click and says what
 * it would be now; what the person never decided on, such as the stock left on
 * the shelf, does not.
 */
class ConfirmationSealTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function propose(User $user, string $tool, array $arguments): ToolResult
    {
        $result = app(ToolExecutor::class)->run(new ToolCall($tool, $arguments, $user));

        $this->assertFalse($result->is_error, json_encode($result->content));
        $this->assertSame('bevestiging_nodig', $result->content['status']);

        return $result;
    }

    private function click(User $user, string $tool, ToolResult $proposal): ToolResult
    {
        return app(ToolExecutor::class)->run(new ToolCall(
            $tool,
            [],
            $user,
            confirmation_token: $proposal->content['confirmation_token'],
        ));
    }

    private function contract(array $attributes = []): MaintenanceContract
    {
        return MaintenanceContract::factory()->create($attributes + [
            'customer_id' => Customer::factory()->create()->id,
            'price' => 100,
            'price_interval' => ContractInterval::jaarlijks->value,
        ]);
    }

    public function test_an_unchanged_indexation_runs_on_the_click(): void
    {
        $user = $this->admin();
        $contract = $this->contract();

        $proposal = $this->propose($user, ChangeMaintenanceContractsTool::name(), [
            'contract_ids' => [$contract->id],
            'increase_percent' => 4,
        ]);

        $this->assertStringContainsString('€100,00 → €104,00', $proposal->content['preview']);

        $result = $this->click($user, ChangeMaintenanceContractsTool::name(), $proposal);

        $this->assertFalse($result->is_error, json_encode($result->content));
        $this->assertSame('104.00', $contract->fresh()->getRawOriginal('price'));
    }

    public function test_a_price_that_moved_since_the_button_refuses_the_click_and_shows_the_new_change(): void
    {
        $user = $this->admin();
        $contract = $this->contract();

        $proposal = $this->propose($user, ChangeMaintenanceContractsTool::name(), [
            'contract_ids' => [$contract->id],
            'increase_percent' => 4,
        ]);

        $contract->update(['price' => 200]);

        $result = $this->click($user, ChangeMaintenanceContractsTool::name(), $proposal);

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('klopt niet meer', $result->summary);
        $this->assertStringContainsString('€200,00 → €208,00', $result->summary);
        $this->assertStringEndsWith("\nVraag het opnieuw.", $result->summary);
        $this->assertSame('200.00', $contract->fresh()->getRawOriginal('price'));
        $this->assertSame('changed_since_confirmation', AssistantToolCall::latest('id')->value('outcome'));
    }

    public function test_extending_a_formula_contract_shows_what_its_formula_comes_to_then(): void
    {
        $user = $this->admin();
        $formula = app(CreatePriceFormulaAction::class)->execute('Per maand looptijd', null, [
            'nodes' => [
                ['id' => 'contract', 'type' => 'contract', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'periodic', 'type' => 'output_periodic', 'position' => ['x' => 300, 'y' => 0], 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'contract', 'sourceHandle' => 'months', 'target' => 'periodic', 'targetHandle' => 'value'],
            ],
        ], $user);

        $start = now()->subMonths(6)->startOfDay();
        $contract = $this->contract([
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addYear()->subDay()->toDateString(),
            'price' => 12,
            'price_formula_version_id' => $formula->versions()->value('id'),
        ]);

        $proposal = $this->propose($user, ChangeMaintenanceContractsTool::name(), [
            'contract_ids' => [$contract->id],
            'extend_months' => 12,
        ]);

        $this->assertStringContainsString('€12,00 → €24,00', $proposal->content['preview']);

        $result = $this->click($user, ChangeMaintenanceContractsTool::name(), $proposal);

        $this->assertFalse($result->is_error, json_encode($result->content));
        $this->assertSame('24.00', $contract->fresh()->getRawOriginal('price'));
    }

    /**
     * A formula can come out below nothing; the contract carries €0,00 then,
     * and the button has to say €0,00, not the negative amount the formula
     * came to.
     */
    public function test_a_formula_below_nothing_shows_and_stores_the_price_the_contract_can_carry(): void
    {
        $user = $this->admin();
        $formula = app(CreatePriceFormulaAction::class)->execute('Korting groter dan de prijs', null, [
            'nodes' => [
                ['id' => 'amount', 'type' => 'number', 'position' => ['x' => 0, 'y' => 0], 'data' => ['value' => -50]],
                ['id' => 'periodic', 'type' => 'output_periodic', 'position' => ['x' => 300, 'y' => 0], 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'amount', 'sourceHandle' => 'value', 'target' => 'periodic', 'targetHandle' => 'value'],
            ],
        ], $user);
        $contract = $this->contract();

        $proposal = $this->propose($user, ApplyPriceFormulaTool::name(), [
            'formula_id' => $formula->id,
            'contract_ids' => [$contract->id],
            'version' => 1,
        ]);

        $this->assertStringContainsString('€100,00 → €0,00 per periode', $proposal->content['preview']);
        $this->assertStringNotContainsString('-€50', $proposal->content['preview']);

        $result = $this->click($user, ApplyPriceFormulaTool::name(), $proposal);

        $this->assertFalse($result->is_error, json_encode($result->content));
        $this->assertSame('0.00', $contract->fresh()->getRawOriginal('price'));
    }

    public function test_a_selection_over_the_bulk_limit_is_refused_before_any_button(): void
    {
        $customer = Customer::factory()->create();
        $row = [
            'customer_id' => $customer->id,
            'start_date' => now()->subYear()->toDateString(),
            'price' => 100,
            'price_interval' => ContractInterval::jaarlijks->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (array_chunk(array_fill(0, BulkSelection::LIMIT + 1, $row), 250) as $chunk) {
            DB::table('maintenance_contracts')->insert($chunk);
        }

        $result = app(ToolExecutor::class)->run(new ToolCall(ChangeMaintenanceContractsTool::name(), [
            'all_running' => true,
            'customer_id' => $customer->id,
            'increase_percent' => 4,
        ], $this->admin()));

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString((string) (BulkSelection::LIMIT + 1), $result->summary);
        $this->assertArrayNotHasKey('confirmation_token', is_array($result->content) ? $result->content : []);
    }

    public function test_a_fixed_end_date_over_all_running_contracts_shortens_none(): void
    {
        $user = $this->admin();
        $customer = Customer::factory()->create();
        $short = $this->contract(['customer_id' => $customer->id, 'end_date' => now()->addMonth()->toDateString()]);
        $long = $this->contract(['customer_id' => $customer->id, 'end_date' => now()->addYears(3)->toDateString()]);
        $new_end = now()->addYear()->toDateString();

        $proposal = $this->propose($user, ChangeMaintenanceContractsTool::name(), [
            'all_running' => true,
            'customer_id' => $customer->id,
            'end_date' => $new_end,
        ]);

        $this->assertStringContainsString('loopt al tot', $proposal->content['preview']);

        $this->assertFalse($this->click($user, ChangeMaintenanceContractsTool::name(), $proposal)->is_error);
        $this->assertSame($new_end, $short->fresh()->end_date->toDateString());
        $this->assertSame(now()->addYears(3)->toDateString(), $long->fresh()->end_date->toDateString());
    }

    public function test_a_booking_still_runs_after_the_stock_moved_for_something_else(): void
    {
        $user = $this->admin();
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $material = Material::create(['name' => 'Filter F7', 'stock' => 10, 'price' => 5]);

        $proposal = $this->propose($user, BookMaterialsTool::name(), [
            'service_order_id' => $order->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 2]],
        ]);

        $this->assertStringContainsString('voorraad 10 → 8', $proposal->content['preview']);

        $material->update(['stock' => 7]);

        $result = $this->click($user, BookMaterialsTool::name(), $proposal);

        $this->assertFalse($result->is_error, json_encode($result->content));
        $this->assertSame(1, $order->materials()->count());
    }
}
