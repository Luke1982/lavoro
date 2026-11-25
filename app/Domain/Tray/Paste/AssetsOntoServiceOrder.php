<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\AddServiceJobsAction;
use App\Domain\Tray\Kinds\AssetKind;
use App\Enums\ServiceJobOutcomes;
use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Machines dropped on a werkbon: each gets a keuring on it, its parts a combined
 * one, exactly as "Keuring toevoegen" does. Nothing new is made beyond that and
 * nothing is taken away, so it happens at once and can be undone.
 */
class AssetsOntoServiceOrder implements UndoablePasteHandler
{
    public function question(Paste $paste): ?Question
    {
        $this->toAdd($paste, DropTarget::openServiceOrder($paste));

        return null;
    }

    public function perform(Paste $paste): PasteResult
    {
        $order = DropTarget::openServiceOrder($paste);
        $assets = $this->toAdd($paste, $order);

        Gate::forUser($paste->user)->authorize('create', ServiceJob::class);

        ['jobs' => $jobs, 'parts' => $parts] = app(AddServiceJobsAction::class)->execute($order, $assets);

        $message = $jobs->count() === 1
            ? AssetKind::named($assets->first()) . ' staat nu op werkbon ' . $order->number . '.'
            : $jobs->count() . ' machines staan nu op werkbon ' . $order->number . '.';
        $message .= AssetKind::leftAlone($paste->records->diff($assets), 'stond er al op', 'stonden er al op');

        return new PasteResult($message, undo: [
            'service_order_id' => $order->id,
            'service_job_ids' => $jobs->concat($parts)->pluck('id')->all(),
        ]);
    }

    /**
     * Takes off the keuringen the paste made, as long as nobody has started on
     * them yet. One with an outcome is work that was done, and stays.
     */
    public function undo(User $user, array $undo): string
    {
        Gate::forUser($user)->authorize('delete', ServiceJob::class);

        $order = ServiceOrder::visibleTo($user)->findOrFail($undo['service_order_id']);

        if ($order->is_closed) {
            throw new Refusal('Werkbon ' . $order->number . ' is inmiddels afgesloten.');
        }

        $jobs = ServiceJob::where('service_order_id', $order->id)
            ->whereIn('id', $undo['service_job_ids'])
            ->get();

        if ($jobs->contains(fn (ServiceJob $job) => $job->outcome !== ServiceJobOutcomes::nog_geen_uitkomst->value)) {
            throw new Refusal('Aan een van deze keuringen is al gewerkt. Haal hem op de werkbon zelf weg.');
        }

        $jobs->each->delete();

        return ($jobs->count() === 1 ? 'De keuring is' : 'De keuringen zijn')
            . ' weer van werkbon ' . $order->number . ' af.';
    }

    /**
     * The machines that have no keuring on this werkbon yet, each checked
     * against the werkbon's customer.
     *
     * @return Collection<int, Asset>
     */
    private function toAdd(Paste $paste, ServiceOrder $order): Collection
    {
        $present = $order->servicejobs()->pluck('asset_id');
        $assets = $paste->records->reject(fn (Asset $asset) => $present->contains($asset->id))->values();

        if ($assets->isEmpty()) {
            throw new Refusal($paste->these('staat', 'staan') . ' al op werkbon ' . $order->number . '.');
        }

        $assets->each(function (Asset $asset) use ($order) {
            if (!$order->isForTheCustomerOf($asset)) {
                throw new Refusal(AssetKind::named($asset) . ' hoort bij een andere klant dan werkbon '
                    . $order->number . '.');
            }
        });

        return $assets;
    }
}
