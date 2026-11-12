<?php

namespace Tests\Feature;

use App\Models\Customer;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A customer missing from the machine form is made on the spot. The endpoint
 * answers that JSON request with the option the picker shows, and keeps sending
 * the pages it already served back where they came from.
 */
class InlineCustomerCreationTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_a_customer_cannot_be_made_without_the_permission_to_make_customers(): void
    {
        $this->actingAs($this->userWith('asset.create'))
            ->postJson('/customers', ['name' => 'Jansen BV'])
            ->assertForbidden();

        $this->assertDatabaseMissing('customers', ['name' => 'Jansen BV']);
    }

    public function test_a_customer_made_from_the_machine_form_comes_back_as_a_picker_option(): void
    {
        $response = $this->actingAs($this->userWith('customer.create'))
            ->postJson('/customers', ['name' => 'Jansen BV', 'city' => 'Utrecht'])
            ->assertCreated();

        $response->assertExactJson([
            'id' => Customer::where('name', 'Jansen BV')->sole()->id,
            'name' => 'Jansen BV – Utrecht',
            'plain_name' => 'Jansen BV',
        ]);
    }

    public function test_a_refused_customer_names_the_field_it_is_about(): void
    {
        $this->actingAs($this->userWith('customer.create'))
            ->postJson('/customers', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_the_customer_pages_still_go_back_with_the_new_customer(): void
    {
        $this->actingAs($this->userWith('customer.create'))
            ->from('/customers')
            ->post('/customers', ['name' => 'Jansen BV'])
            ->assertRedirect('/customers')
            ->assertSessionHas('success', 'Klant aangemaakt.')
            ->assertSessionHas('extra.customer.name', 'Jansen BV');
    }
}
