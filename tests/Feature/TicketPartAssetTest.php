<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Tests\TestCase;

/**
 * A part asset carries no customer_id/location_id of its own — both live on the
 * root asset it hangs under. Every place a ticket's asset is shown or searched
 * has to resolve through that root instead of assuming a direct relation.
 */
class TicketPartAssetTest extends TestCase
{

    private Product $product;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create();

        /** @var User $admin */
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin->roles()->attach($role->id);
        $this->admin = $admin;
    }

    private function rootAsset(Customer $customer, ?Location $location = null): Asset
    {
        return Asset::factory()->create([
            'customer_id' => $customer->id,
            'location_id' => $location?->id,
            'product_id' => $this->product->id,
            'status' => 'Actief',
        ]);
    }

    private function partAsset(Asset $parent): Asset
    {
        return Asset::factory()->create([
            'customer_id' => null,
            'location_id' => null,
            'parent_asset_id' => $parent->id,
            'product_id' => $this->product->id,
            'status' => 'Actief',
        ]);
    }

    public function test_the_index_page_resolves_customer_and_location_for_a_ticket_on_a_part_asset(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $root = $this->rootAsset($customer, $location);
        $part = $this->partAsset($root);
        $ticket = Ticket::factory()->create(['asset_id' => $part->id, 'status' => 'Open']);

        $response = $this->actingAs($this->admin)->get(route('tickets.index'));

        $response->assertOk();
        $entry = collect($response->inertiaProps('tickets.data'))->firstWhere('id', $ticket->id);

        $this->assertNotNull($entry, 'the ticket on the part asset never reached the index page');
        $this->assertSame($customer->id, $entry['asset']['customer']['id']);
        $this->assertSame($location->id, $entry['asset']['linked_location']['id']);
    }

    public function test_the_show_page_resolves_customer_for_a_ticket_on_a_part_asset(): void
    {
        $customer = Customer::factory()->create();
        $root = $this->rootAsset($customer);
        $part = $this->partAsset($root);
        $ticket = Ticket::factory()->create(['asset_id' => $part->id]);

        $response = $this->actingAs($this->admin)->get(route('tickets.show', $ticket));

        $response->assertOk();
        $this->assertSame($customer->id, $response->inertiaProps('ticket.asset.customer.id'));
    }

    public function test_the_map_includes_a_ticket_on_a_part_asset(): void
    {
        $customer = Customer::factory()->create();
        $root = $this->rootAsset($customer);
        $part = $this->partAsset($root);
        $ticket = Ticket::factory()->create(['asset_id' => $part->id, 'status' => 'Open']);

        $response = $this->actingAs($this->admin)->get(route('tickets.map'));

        $response->assertOk();
        $ticket_ids = collect($response->inertiaProps('items'))
            ->flatMap(fn ($item) => collect($item['tickets'])->pluck('id'));

        $this->assertTrue($ticket_ids->contains($ticket->id), 'the ticket on the part asset never reached the map');
    }

    public function test_the_map_groups_by_location_when_the_root_asset_has_one(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $root = $this->rootAsset($customer, $location);
        Ticket::factory()->create(['asset_id' => $root->id, 'status' => 'Open']);

        $response = $this->actingAs($this->admin)->get(route('tickets.map'));

        $response->assertOk();
        $items = $response->inertiaProps('items');

        $this->assertSame('location', $items[0]['type']);
        $this->assertSame($location->id, $items[0]['id']);
    }

    public function test_searching_by_customer_name_finds_a_ticket_on_a_part_asset(): void
    {
        $customer = Customer::factory()->create(['name' => 'Uniquely Named Customer BV']);
        $root = $this->rootAsset($customer);
        $part = $this->partAsset($root);
        $ticket = Ticket::factory()->create(['asset_id' => $part->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('tickets.index', ['search' => 'Uniquely Named Customer']));

        $response->assertOk();
        $ids = collect($response->inertiaProps('tickets.data'))->pluck('id');

        $this->assertTrue($ids->contains($ticket->id), 'search by root customer name did not find the part-asset ticket');
    }
}
