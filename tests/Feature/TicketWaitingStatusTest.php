<?php

namespace Tests\Feature;

use App\Enums\TicketStatusses;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Ticket;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The fourth stage of an incident. The overview adds the stages up by hand, so
 * a new status that is counted nowhere is an incident disappearing from the
 * cards without anyone noticing.
 */
class TicketWaitingStatusTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function ticketWithStatus(string $status): Ticket
    {
        $asset = Asset::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'product_id' => Product::factory()->create()->id,
        ]);

        return Ticket::factory()->create(['asset_id' => $asset->id, 'status' => $status]);
    }

    public function test_the_status_exists_and_sits_before_gesloten(): void
    {
        $values = array_column(TicketStatusses::cases(), 'value');

        $this->assertContains('Wacht op terugkoppeling klant', $values);
        $this->assertLessThan(
            array_search('Gesloten', $values, true),
            array_search('Wacht op terugkoppeling klant', $values, true),
        );
    }

    /**
     * On MySQL the column is an ENUM and knows only what was once put in it. A
     * stage added to the enum in PHP without a migration gives "Data truncated
     * for column 'status'" there, and on SQLite -- where this test runs by
     * default -- nobody notices a thing. This one writes them all and reads
     * them back.
     */
    public function test_every_status_the_enum_names_survives_a_write(): void
    {
        $ticket = $this->ticketWithStatus(TicketStatusses::open->value);

        foreach (TicketStatusses::cases() as $status) {
            $ticket->update(['status' => $status->value]);

            $this->assertSame($status->value, $ticket->fresh()->status);
        }
    }

    public function test_the_overview_counts_waiting_tickets_in_their_own_card(): void
    {
        $this->ticketWithStatus(TicketStatusses::open->value);
        $this->ticketWithStatus(TicketStatusses::wacht_op_klant->value);
        $this->ticketWithStatus(TicketStatusses::wacht_op_klant->value);

        $this->actingAs($this->userWith('ticket.see_all'))
            ->get('/tickets')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tickets/IndexPage')
                ->where('waitingCount', 2)
                ->where('openCount', 1)

                /** Four stages, three incidents: the average divides by four. */
                ->where('avgCount', 1));
    }

    public function test_the_overview_can_be_filtered_down_to_waiting_tickets(): void
    {
        $this->ticketWithStatus(TicketStatusses::open->value);
        $waiting = $this->ticketWithStatus(TicketStatusses::wacht_op_klant->value);

        $this->actingAs($this->userWith('ticket.see_all'))
            ->get('/tickets?statuses=wacht_op_klant')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $waiting->id));
    }
}
