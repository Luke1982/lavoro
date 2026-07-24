<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpcomingActivitiesCustomerlessAssetTest extends TestCase
{
    use RefreshDatabase;

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

    private function rootAsset(Customer $customer, Carbon $next_service_date): Asset
    {
        return Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'next_service_date' => $next_service_date,
            'status' => 'Actief',
        ]);
    }

    /**
     * A child asset carries no customer_id of its own; it resolves one through its parent.
     */
    private function childAsset(Asset $parent, Carbon $next_service_date): Asset
    {
        return Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $parent->id,
            'product_id' => $this->product->id,
            'next_service_date' => $next_service_date,
            'status' => 'Actief',
        ]);
    }

    /**
     * UpcomingActivities.vue renders `mainAsset.customer.id` without a null guard,
     * so an entry lacking a customer crashes the page.
     */
    private function assertEveryEntryHasACustomer($response, string $prop): void
    {
        $entries = $response->inertiaProps($prop);

        $this->assertNotEmpty($entries, $prop . ' had no entries, so it proves nothing');

        foreach ($entries as $entry) {
            $this->assertNotNull(
                $entry['customer'] ?? null,
                $prop . ' contains asset ' . $entry['id'] . ' without a customer'
            );
        }
    }

    public function test_an_expired_child_asset_never_reaches_the_page_without_a_customer(): void
    {
        $parent = $this->rootAsset(Customer::factory()->create(), now()->subDays(10));
        $this->childAsset($parent, now()->subDays(20));

        $response = $this->actingAs($this->admin)->get(route('upcomingactivities'));

        $response->assertOk();
        $this->assertEveryEntryHasACustomer($response, 'expiredAssets');
    }

    public function test_an_upcoming_child_asset_never_reaches_the_page_without_a_customer(): void
    {
        $parent = $this->rootAsset(Customer::factory()->create(), now()->addDays(20));
        $this->childAsset($parent, now()->addDays(10));

        $response = $this->actingAs($this->admin)->get(route('upcomingactivities'));

        $response->assertOk();
        $this->assertEveryEntryHasACustomer($response, 'upcomingAssets');
    }

    public function test_a_child_asset_is_listed_under_the_customer_of_its_root_asset(): void
    {
        $customer = Customer::factory()->create();
        $parent = $this->rootAsset($customer, now()->subDays(10));
        $child = $this->childAsset($parent, now()->subDays(20));

        $response = $this->actingAs($this->admin)->get(route('upcomingactivities'));

        $response->assertOk();
        $entries = $response->inertiaProps('expiredAssets');

        $this->assertCount(1, $entries);
        $this->assertSame($customer->id, $entries[0]['customer']['id']);

        $listed = Arr::pluck($entries[0]['customer']['upcoming_assets'], 'id');
        $this->assertContains($parent->id, $listed);
        $this->assertContains($child->id, $listed);
    }

    public function test_a_child_asset_is_grouped_at_the_location_of_its_root_asset(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $parent = $this->rootAsset($customer, now()->subDays(10));
        $parent->update(['location_id' => $location->id]);
        $child = $this->childAsset($parent, now()->subDays(20));

        $response = $this->actingAs($this->admin)->get(route('upcomingactivities'));

        $response->assertOk();
        $groups = $response->inertiaProps('expiredAssets')[0]['customer']['location_groups'];

        $this->assertCount(1, $groups, 'the child asset was split off into its own location group');
        $this->assertSame($location->id, $groups[0]['location']['id']);
        $this->assertEqualsCanonicalizing([$parent->id, $child->id], $groups[0]['asset_ids']);
    }
}
