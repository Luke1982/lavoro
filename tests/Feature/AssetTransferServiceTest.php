<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Services\AssetTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $old_customer;

    private Customer $new_customer;

    private Product $product;

    private AssetTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->old_customer = Customer::factory()->create();
        $this->new_customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
        $this->service = app(AssetTransferService::class);
    }

    private function root(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'customer_id' => $this->old_customer->id,
            'product_id' => $this->product->id,
        ], $attributes));
    }

    private function child(Asset $parent): Asset
    {
        return Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $parent->id,
            'product_id' => $this->product->id,
        ]);
    }

    private function contractFor(Customer $customer, Asset ...$assets): MaintenanceContract
    {
        $contract = MaintenanceContract::factory()->create(['customer_id' => $customer->id]);

        foreach ($assets as $asset) {
            $contract->assets()->attach($asset->id);
        }

        return $contract;
    }

    public function test_a_transferred_root_belongs_to_the_new_customer(): void
    {
        $root = $this->root();

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertSame($this->new_customer->id, $root->refresh()->customer_id);
    }

    public function test_children_follow_the_root_without_being_touched(): void
    {
        $root = $this->root();
        $child = $this->child($root);
        $grandchild = $this->child($child);

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertNull($child->refresh()->customer_id);
        $this->assertNull($grandchild->refresh()->customer_id);
        $this->assertSame($this->new_customer->id, $child->resolvedCustomerId());
        $this->assertSame($this->new_customer->id, $grandchild->resolvedCustomerId());
    }

    public function test_the_location_is_remapped_onto_the_new_customers_location(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $new_location = Location::factory()->create(['customer_id' => $this->new_customer->id]);
        $root = $this->root(['location_id' => $old_location->id]);

        $this->service->transfer(
            collect([$root]),
            $this->new_customer->id,
            [$old_location->id => $new_location->id]
        );

        $this->assertSame($new_location->id, $root->refresh()->location_id);
    }

    public function test_an_unmapped_location_is_cleared_rather_than_left_pointing_at_the_old_customer(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $root = $this->root(['location_id' => $old_location->id]);

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertNull($root->refresh()->location_id);
    }

    public function test_the_asset_is_detached_from_a_contract_of_the_old_customer(): void
    {
        $root = $this->root();
        $contract = $this->contractFor($this->old_customer, $root);

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertFalse($contract->assets()->where('assets.id', $root->id)->exists());
    }

    public function test_detaching_from_a_contract_is_logged_on_that_contract(): void
    {
        $root = $this->root(['serial_number' => 'SN-TRANSFER-1']);
        $contract = $this->contractFor($this->old_customer, $root);

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertTrue(
            $contract->activities()->where('description', 'like', '%SN-TRANSFER-1%')->exists()
        );
    }

    public function test_a_contract_already_belonging_to_the_new_customer_keeps_the_asset(): void
    {
        $root = $this->root();
        $contract = $this->contractFor($this->new_customer, $root);

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertTrue($contract->assets()->where('assets.id', $root->id)->exists());
    }

    public function test_a_contract_holding_a_child_asset_also_loses_it(): void
    {
        $root = $this->root();
        $child = $this->child($root);
        $contract = $this->contractFor($this->old_customer, $child);

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertFalse($contract->assets()->where('assets.id', $child->id)->exists());
    }

    public function test_every_other_contract_of_the_old_customer_loses_the_asset(): void
    {
        $root = $this->root();
        $first = $this->contractFor($this->old_customer, $root);
        $second = $this->contractFor($this->old_customer, $root);

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertFalse($first->assets()->where('assets.id', $root->id)->exists());
        $this->assertFalse($second->assets()->where('assets.id', $root->id)->exists());
    }

    public function test_service_jobs_and_tickets_stay_on_the_machine(): void
    {
        $root = $this->root();
        $service_order = ServiceOrder::factory()->create(['customer_id' => $this->old_customer->id]);
        $job = ServiceJob::factory()->create([
            'asset_id' => $root->id,
            'service_order_id' => $service_order->id,
        ]);
        $ticket = Ticket::factory()->create(['asset_id' => $root->id]);

        $this->service->transfer(collect([$root]), $this->new_customer->id, []);

        $this->assertSame($root->id, $job->refresh()->asset_id);
        $this->assertSame($root->id, $ticket->refresh()->asset_id);
    }

    public function test_the_preview_lists_the_whole_subtree_that_will_move(): void
    {
        $root = $this->root();
        $child = $this->child($root);

        $preview = $this->service->preview(collect([$root]), $this->new_customer->id);

        $this->assertEqualsCanonicalizing(
            [$root->id, $child->id],
            collect($preview['assets'])->pluck('id')->all()
        );
    }

    public function test_the_preview_lists_the_locations_needing_a_mapping(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $this->root(['location_id' => $old_location->id]);
        $root = Asset::where('location_id', $old_location->id)->firstOrFail();

        $preview = $this->service->preview(collect([$root]), $this->new_customer->id);

        $this->assertSame([$old_location->id], collect($preview['locations'])->pluck('id')->all());
    }

    public function test_the_preview_names_the_contracts_that_would_lose_the_machine(): void
    {
        $root = $this->root();
        $losing = $this->contractFor($this->old_customer, $root);
        $keeping = $this->contractFor($this->new_customer, $root);

        $preview = $this->service->preview(collect([$root]), $this->new_customer->id);

        $contract_ids = collect($preview['contracts'])->pluck('id')->all();
        $this->assertContains($losing->id, $contract_ids);
        $this->assertNotContains($keeping->id, $contract_ids);
    }

    public function test_the_preview_changes_nothing(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $root = $this->root(['location_id' => $old_location->id]);
        $contract = $this->contractFor($this->old_customer, $root);

        $this->service->preview(collect([$root]), $this->new_customer->id);

        $root->refresh();
        $this->assertSame($this->old_customer->id, $root->customer_id);
        $this->assertSame($old_location->id, $root->location_id);
        $this->assertTrue($contract->assets()->where('assets.id', $root->id)->exists());
    }
}
