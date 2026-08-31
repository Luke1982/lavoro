<?php

namespace Tests\Feature\Signals;

use App\Models\Activity;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Ticket;
use Tests\TestCase;

/**
 * Ticket status and priority carry their own values rather than a finished
 * sentence, so the change is queryable. These assert the structured rows, which
 * is the thing a trace is built on.
 */
class TicketSignalsTest extends TestCase
{

    private function ticket(): Ticket
    {
        return Ticket::create([
            'asset_id' => Asset::factory()->create([
                'product_id' => Product::factory()->create()->id,
                'customer_id' => Customer::factory()->create()->id,
            ])->id,
            'subject' => 'Storing',
            'description' => 'Machine doet het niet',
            'status' => 'Open',
            'priority' => 'Laag',
        ]);
    }

    public function test_a_status_change_is_recorded_as_structured_before_and_after(): void
    {
        $ticket = $this->ticket();

        $ticket->update(['status' => 'Gesloten']);

        $activity = Activity::with('fieldChanges')
            ->where('event_key', 'ticket.status_changed')->sole();
        $change = $activity->fieldChanges->sole();

        $this->assertSame('status', $change->field);
        $this->assertSame('Status', $change->label);
        $this->assertSame('Open', $change->old_value);
        $this->assertSame('Gesloten', $change->new_value);
        $this->assertSame(
            "Status gewijzigd van 'Open' naar 'Gesloten'",
            $activity->description
        );
    }

    public function test_a_priority_change_is_recorded_as_structured_before_and_after(): void
    {
        $ticket = $this->ticket();

        $ticket->update(['priority' => 'Hoog']);

        $change = Activity::with('fieldChanges')
            ->where('event_key', 'ticket.priority_changed')->sole()
            ->fieldChanges->sole();

        $this->assertSame('priority', $change->field);
        $this->assertSame('Prioriteit', $change->label);
        $this->assertSame('Laag', $change->old_value);
        $this->assertSame('Hoog', $change->new_value);
    }

    public function test_status_and_priority_are_not_also_logged_by_the_automatic_trail(): void
    {
        $ticket = $this->ticket();

        $ticket->update(['status' => 'Gesloten', 'priority' => 'Hoog']);

        $automatic = Activity::with('fieldChanges')
            ->where('event_key', 'ticket.updated')->get();

        $duplicated = $automatic
            ->flatMap->fieldChanges
            ->pluck('field')
            ->intersect(['status', 'priority']);

        $this->assertCount(0, $duplicated, 'status/priority were logged twice');
    }

    public function test_closing_a_ticket_still_stamps_who_closed_it(): void
    {
        $ticket = $this->ticket();

        $ticket->update(['status' => 'Gesloten']);

        $this->assertNotNull($ticket->fresh()->closed_on);
    }
}
