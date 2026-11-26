<?php

namespace Tests\Feature\ReadRules;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Logging a storing takes the right to log one, on a machine this person may
 * open. The form used to let anyone log one on any machine id.
 */
class TicketCreateRuleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private Asset $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = Asset::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'product_id' => Product::factory()->create()->id,
        ]);
    }

    private function log(User $user)
    {
        return $this->actingAs($user)->from('/assets/' . $this->machine->id)->post('/tickets', [
            'subject' => 'Lekt',
            'description' => 'Er staat water onder.',
            'priority' => 'Normaal',
            'status' => 'Open',
            'asset_id' => $this->machine->id,
        ]);
    }

    public function test_without_the_right_to_log_storingen_nothing_is_logged(): void
    {
        $this->log($this->userWithPermissions('asset.read'))->assertRedirect();

        $this->assertFalse(Ticket::where('asset_id', $this->machine->id)->exists());
    }

    public function test_a_machine_out_of_sight_is_refused(): void
    {
        $this->log($this->userWithPermissions('ticket.create'))->assertSessionHasErrors('asset_id');

        $this->assertFalse(Ticket::where('asset_id', $this->machine->id)->exists());
    }

    public function test_a_storing_on_a_machine_in_sight_is_logged(): void
    {
        $this->log($this->userWithPermissions('ticket.create', 'asset.read'))->assertSessionHasNoErrors();

        $this->assertTrue(Ticket::where('asset_id', $this->machine->id)->exists());
    }
}
