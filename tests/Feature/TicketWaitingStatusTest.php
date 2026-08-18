<?php

namespace Tests\Feature;

use App\Enums\TicketStatusses;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * De vierde fase van een storing. Het overzicht telt de fasen met de hand op, dus
 * een nieuwe status die nergens meegeteld wordt is een storing die uit de
 * kaarten verdwijnt zonder dat iemand het merkt.
 */
class TicketWaitingStatusTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

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
     * De kolom is op MySQL een ENUM en kent alleen wat er ooit in gezet is. Een fase
     * bij de enum in PHP zonder migratie erbij levert daar "Data truncated for column
     * 'status'" op, en op SQLite — waar deze test standaard draait — merkt niemand
     * er iets van. Deze schrijft ze allemaal weg en leest ze terug.
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

                /** Vier fasen, drie storingen: het gemiddelde deelt door vier. */
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
