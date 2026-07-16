<?php

namespace Tests\Feature\Location;

use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The location picker feeds asset, werkbon and appointment screens, so it is
 * gated on reading customer data — not on the Locaties permission alone.
 */
class LocationAccessTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

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

    public function test_the_picker_is_denied_without_a_relevant_permission(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertForbidden();
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
