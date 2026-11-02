<?php

namespace Tests\Feature\Location;

use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The location picker feeds asset, werkbon and appointment screens, so it is
 * gated on reading customer data — not on the Locaties permission alone.
 */
class LocationAccessTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_the_picker_is_allowed_with_customer_read(): void
    {
        $customer = Customer::factory()->create();
        Location::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->userWith('customer.read'))
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_the_picker_is_allowed_with_location_read(): void
    {
        $customer = Customer::factory()->create();
        Location::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->userWith('location.read'))
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertOk()
            ->assertJsonCount(1);
    }

    /**
     * The field the picker fills carries its own right, and that is enough. A
     * monteur has neither of the two above and was left with an empty box on a
     * machine they were perfectly allowed to move.
     */
    public function test_the_picker_follows_the_right_of_the_field_it_fills(): void
    {
        $customer = Customer::factory()->create();
        Location::factory()->create(['customer_id' => $customer->id]);

        foreach (['asset.update', 'serviceorder.update', 'event.create'] as $permission) {
            $this->actingAs($this->userWith($permission))
                ->getJson("/combo/customers/{$customer->id}/locations")
                ->assertOk()
                ->assertJsonCount(1);
        }
    }

    public function test_the_picker_is_denied_without_a_relevant_permission(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertForbidden();
    }

    /**
     * The picker fetches the whole list once and filters it in the browser, so
     * what you may search a location by has to be in the payload.
     */
    public function test_the_picker_carries_the_address_and_code_to_search_on(): void
    {
        $customer = Customer::factory()->create();
        Location::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Vestiging Noord',
            'location_code' => 'VN-01',
            'address' => 'Rustenburg 24',
            'postal_code' => '6702 AE',
            'city' => 'Wageningen',
        ]);

        $option = $this->actingAs($this->userWith('customer.read'))
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertOk()
            ->json('0');

        $this->assertSame('Vestiging Noord – Wageningen', $option['name']);
        $this->assertStringContainsString('Rustenburg 24', $option['search']);
        $this->assertStringContainsString('6702 AE', $option['search']);
        $this->assertStringContainsString('VN-01', $option['search']);
    }

    public function test_the_picker_only_returns_that_customers_locations(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $mine = Location::factory()->create(['customer_id' => $customer->id]);
        Location::factory()->create(['customer_id' => $other->id]);

        $this->actingAs($this->userWith('customer.read'))
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $mine->id]);
    }
}
