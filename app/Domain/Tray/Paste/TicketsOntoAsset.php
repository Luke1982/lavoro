<?php

namespace App\Domain\Tray\Paste;

use App\Domain\Tray\AssetFacts;
use App\Domain\Tray\TicketFacts;
use App\Domain\Tray\TrayKinds;
use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Storingen dropped on a machine: they were reported on the wrong one, and are
 * hung off this one instead. Always asked first, because the storing leaves the
 * machine it was on.
 */
class TicketsOntoAsset implements PasteHandler
{
    public function question(Paste $paste): Question
    {
        $asset = $this->asset($paste);
        $tickets = $this->toMove($paste, $asset);
        $machine = AssetFacts::title($asset);
        $one = $tickets->count() === 1;

        return new Question(
            title: Str::ucfirst(TrayKinds::counted('ticket', $tickets->count())) . ' verhangen?',
            subtitle: 'Naar ' . $machine . '.',
            items: TicketFacts::listed($tickets),
            choices: [new Choice(
                value: 'move',
                label: 'Verhangen naar ' . $machine,
                description: $one
                    ? 'De storing staat daarna bij deze machine, niet meer bij de machine hierboven.'
                    : 'De storingen staan daarna bij deze machine, niet meer bij de machines hierboven.',
                confirm: 'Verhangen',
            )],
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $asset = $this->asset($paste);
        $tickets = $this->toMove($paste, $asset);
        $gate = Gate::forUser($paste->user);

        $tickets->each(function (Ticket $ticket) use ($gate, $asset) {
            $gate->authorize('moveToAsset', $ticket);
            $ticket->update(['asset_id' => $asset->id]);
        });

        return new PasteResult(($tickets->count() === 1 ? 'De storing hangt' : 'De storingen hangen')
            . ' nu aan ' . AssetFacts::title($asset) . '.');
    }

    private function asset(Paste $paste): Asset
    {
        $asset = Asset::visibleTo($paste->user)->with('product.brand')->find($paste->target['id']);

        if (!$asset) {
            throw new Refusal('Deze machine kun je niet openen.');
        }

        return $asset;
    }

    /**
     * A storing on a werkbon stays with that werkbon's customer: moving it to
     * someone else's machine would leave the werkbon holding a stranger's fault.
     *
     * @return Collection<int, Ticket>
     */
    private function toMove(Paste $paste, Asset $asset): Collection
    {
        $tickets = $paste->records
            ->filter(fn (Ticket $ticket) => (int) $ticket->asset_id !== $asset->id)
            ->values();

        if ($tickets->isEmpty()) {
            throw new Refusal(($paste->records->count() === 1 ? 'Deze storing hangt' : 'Deze storingen hangen')
                . ' al aan deze machine.');
        }

        $customer_id = $asset->resolvedCustomerId();

        $tickets->each(function (Ticket $ticket) use ($customer_id) {
            $order = $ticket->serviceOrder;

            if ($order && (int) $order->customer_id !== $customer_id) {
                throw new Refusal('"' . TicketFacts::title($ticket) . '" staat op werkbon ' . $order->number
                    . ' van een andere klant. Haal hem daar eerst af.');
            }
        });

        return $tickets;
    }
}
