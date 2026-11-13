<?php

namespace Tests\Feature\ServiceJobs;

use App\Enums\ServiceJobOutcomes;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Keuringen for many machines at once: every active machine of the werkbon's
 * customer, or those at one location, skipping machines the werkbon already
 * has. Their parts get keuringen too, exactly as when one machine is added.
 */
class AddServiceJobsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private Customer $customer;

    private ServiceOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
        $this->order = ServiceOrder::factory()->create(['customer_id' => $this->customer->id]);
    }

    private function machine(array $attributes = []): Asset
    {
        return Asset::factory()->create($attributes + [
            'customer_id' => $this->customer->id,
            'product_id' => Product::factory()->create()->id,
            'status' => 'Actief',
        ]);
    }

    private function add(array $data, ?array $permissions = null)
    {
        return $this->actingAs($this->userWithPermissions(...($permissions ?? ['servicejob.create'])))
            ->post("/serviceorders/{$this->order->id}/servicejobs", $data);
    }

    private function machinesOnOrder(): array
    {
        return ServiceJob::where('service_order_id', $this->order->id)->whereNull('parent_service_job_id')
            ->orderBy('asset_id')->pluck('asset_id')->all();
    }

    public function test_every_active_machine_of_the_customer_gets_a_keuring(): void
    {
        $first = $this->machine();
        $second = $this->machine();
        $this->machine(['status' => 'Niet actief']);
        Asset::factory()->create(['customer_id' => Customer::factory()->create()->id, 'status' => 'Actief']);

        $this->add(['location_id' => null])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame([$first->id, $second->id], $this->machinesOnOrder());
    }

    public function test_only_the_machines_at_one_location(): void
    {
        $location = Location::factory()->create(['customer_id' => $this->customer->id]);
        $here = $this->machine(['location_id' => $location->id]);
        $this->machine();

        $this->add(['location_id' => $location->id])->assertSessionHasNoErrors();

        $this->assertSame([$here->id], $this->machinesOnOrder());
    }

    public function test_machines_already_on_the_werkbon_are_skipped(): void
    {
        $already = $this->machine();
        $new = $this->machine();
        ServiceJob::create(['asset_id' => $already->id, 'service_order_id' => $this->order->id, 'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value]);

        $this->add(['location_id' => null])->assertSessionHasNoErrors();

        $this->assertSame([$already->id, $new->id], $this->machinesOnOrder());
        $this->assertSame(1, ServiceJob::where('asset_id', $already->id)->count());
    }

    public function test_parts_get_their_keuringen_too(): void
    {
        $machine = $this->machine();
        $part = Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $machine->id,
            'product_id' => Product::factory()->create()->id,
            'status' => 'Actief',
        ]);

        $this->add(['location_id' => null])->assertSessionHasNoErrors();

        $parent_job = ServiceJob::where('asset_id', $machine->id)->sole();
        $this->assertSame($parent_job->id, ServiceJob::where('asset_id', $part->id)->sole()->parent_service_job_id);
    }

    public function test_nothing_left_to_add_is_refused(): void
    {
        $this->add(['location_id' => null])
            ->assertSessionHas('error', 'Er zijn geen machines meer zonder keuring op deze werkbon.');

        $this->assertSame([], $this->machinesOnOrder());
    }

    public function test_a_location_of_another_customer_is_refused(): void
    {
        $elsewhere = Location::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->machine();

        $this->add(['location_id' => $elsewhere->id])->assertSessionHasErrors('location_id');

        $this->assertSame([], $this->machinesOnOrder());
    }

    public function test_it_needs_the_right_to_add_keuringen(): void
    {
        $this->machine();

        $this->add(['location_id' => null], ['serviceorder.read'])->assertSessionHas('error');

        $this->assertSame([], $this->machinesOnOrder());
    }

    public function test_adding_one_machine_still_adds_its_parts(): void
    {
        $machine = $this->machine();
        $part = Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $machine->id,
            'product_id' => Product::factory()->create()->id,
            'status' => 'Actief',
        ]);

        $this->actingAs($this->userWithPermissions('servicejob.create'))->post('/servicejobs', [
            'service_order_id' => $this->order->id,
            'asset_id' => $machine->id,
            'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ServiceJob::where('asset_id', $part->id)->count());
    }
}
