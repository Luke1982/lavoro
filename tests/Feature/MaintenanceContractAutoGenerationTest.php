<?php

namespace Tests\Feature;

use App\Enums\ContractInterval;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\MaintenanceContract;
use App\Models\Product;
use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MaintenanceContractAutoGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function contractWithAsset(array $contract_attributes = []): MaintenanceContract
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $asset = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
        ]);

        $contract = MaintenanceContract::factory()->create(array_merge([
            'customer_id' => $customer->id,
        ], $contract_attributes));

        $contract->assets()->attach($asset->id, [
            'frequency' => null,
            'frequency_days' => null,
        ]);

        return $contract;
    }

    public function test_generate_all_due_skips_contracts_with_auto_generate_disabled(): void
    {
        $this->contractWithAsset([
            'auto_generate' => false,
            'start_date' => now()->subYears(2)->toDateString(),
        ]);

        $created = app(MaintenanceContractServiceOrderGenerator::class)->generateAllDue();

        $this->assertCount(0, $created);
    }

    public function test_generate_all_due_creates_werkbon_for_due_auto_generate_contract(): void
    {
        $contract = $this->contractWithAsset([
            'auto_generate' => true,
            'start_date' => now()->subYears(2)->toDateString(),
        ]);

        $created = app(MaintenanceContractServiceOrderGenerator::class)->generateAllDue();

        $this->assertCount(1, $created);
        $this->assertDatabaseHas('service_orders', [
            'id' => $created[0]->id,
            'maintenance_contract_id' => $contract->id,
        ]);
        $this->assertSame(1, $created[0]->serviceJobs()->count());
    }

    public function test_generate_all_due_creates_first_werkbon_as_soon_as_contract_starts(): void
    {
        // A contract with no prior generation is due as soon as its start date has
        // passed, not one full interval after — the first service was never done yet.
        $contract = $this->contractWithAsset([
            'auto_generate' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'frequency' => ContractInterval::jaarlijks->value,
        ]);

        $created = app(MaintenanceContractServiceOrderGenerator::class)->generateAllDue();

        $this->assertCount(1, $created);
        $this->assertSame($contract->id, $created[0]->maintenance_contract_id);
    }

    public function test_generate_all_due_skips_when_next_interval_has_not_elapsed(): void
    {
        $contract = $this->contractWithAsset([
            'auto_generate' => true,
            'start_date' => now()->subYears(2)->toDateString(),
            'frequency' => ContractInterval::jaarlijks->value,
        ]);
        // Simulate a werkbon already generated recently — not due again for another year.
        $contract->assets()->newPivotQuery()->update([
            'last_generated_at' => now()->subMonth(),
        ]);

        $created = app(MaintenanceContractServiceOrderGenerator::class)->generateAllDue();

        $this->assertCount(0, $created);
    }

    public function test_auto_generate_interval_overrides_contract_frequency(): void
    {
        $not_due_via_contract_frequency = $this->contractWithAsset([
            'auto_generate' => true,
            'frequency' => ContractInterval::jaarlijks->value,
        ]);
        $not_due_via_contract_frequency->assets()->newPivotQuery()->update([
            'last_generated_at' => now()->subMonths(2),
        ]);

        $due_via_override_interval = $this->contractWithAsset([
            'auto_generate' => true,
            'frequency' => ContractInterval::jaarlijks->value,
            'auto_generate_interval' => ContractInterval::maandelijks->value,
        ]);
        $due_via_override_interval->assets()->newPivotQuery()->update([
            'last_generated_at' => now()->subMonths(2),
        ]);

        $created = app(MaintenanceContractServiceOrderGenerator::class)->generateAllDue();

        $this->assertCount(1, $created);
        $this->assertSame($due_via_override_interval->id, $created[0]->maintenance_contract_id);
    }

    public function test_generate_all_due_skips_cancelled_contracts(): void
    {
        $contract = $this->contractWithAsset([
            'auto_generate' => true,
            'start_date' => now()->subYears(2)->toDateString(),
        ]);
        $contract->cancelled_at = now();
        $contract->save();

        $created = app(MaintenanceContractServiceOrderGenerator::class)->generateAllDue();

        $this->assertCount(0, $created);
    }

    public function test_generate_all_due_skips_future_and_expired_contracts(): void
    {
        $this->contractWithAsset([
            'auto_generate' => true,
            'start_date' => now()->addMonth()->toDateString(),
        ]);

        $this->contractWithAsset([
            'auto_generate' => true,
            'start_date' => now()->subYears(2)->toDateString(),
            'end_date' => now()->subMonth()->toDateString(),
        ]);

        $created = app(MaintenanceContractServiceOrderGenerator::class)->generateAllDue();

        $this->assertCount(0, $created);
    }

    public function test_artisan_command_generates_due_werkbonnen(): void
    {
        $contract = $this->contractWithAsset([
            'auto_generate' => true,
            'start_date' => now()->subYears(2)->toDateString(),
        ]);

        Artisan::call('maintenancecontracts:generate-serviceorders');

        $this->assertDatabaseHas('service_orders', [
            'maintenance_contract_id' => $contract->id,
        ]);
        $this->assertStringContainsString('1 werkbon', Artisan::output());
    }

    public function test_manual_generation_ignores_auto_generate_flag(): void
    {
        $contract = $this->contractWithAsset([
            'auto_generate' => false,
            'start_date' => now()->subMonth()->toDateString(),
            'frequency' => ContractInterval::jaarlijks->value,
        ]);

        $service_order = app(MaintenanceContractServiceOrderGenerator::class)
            ->generateNowForContract($contract);

        $this->assertNotNull($service_order);
        $this->assertDatabaseHas('service_orders', [
            'id' => $service_order->id,
            'maintenance_contract_id' => $contract->id,
        ]);
    }
}
