<?php

namespace App\Domain\Tray\Paste;

use App\Domain\Tray\Kinds\AssetKind;
use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Services\AssetTransferService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Machines dropped on a customer: they move there. Always asked first, because
 * the person picks the location, and because moving a machine to another
 * customer takes it off that customer's contracts. What goes along is in the
 * question, before anything is moved.
 *
 * Within one customer only the location changes; to another customer it is the
 * same transfer the machine form does when the customer is changed.
 */
class AssetsOntoCustomer implements PasteHandler
{
    private const NO_LOCATION = 'location:none';

    public function question(Paste $paste): Question
    {
        $customer = $this->customer($paste);
        $assets = $this->toMove($paste, $customer);
        $transferring = $this->transferring($assets, $customer);
        $leaving = $transferring->isEmpty()
            ? []
            : app(AssetTransferService::class)->preview($transferring, $customer->id)['contracts'];

        return new Question(
            title: AssetKind::heading($assets->count()) . ' verhuizen',
            subtitle: $transferring->isEmpty()
                ? 'Naar een andere locatie van ' . $customer->name . '.'
                : 'Overdragen aan ' . $customer->name . '.',
            items: [...AssetKind::listed($assets), ...$this->partsGoingAlong($assets)],
            choices: $this->choices($paste, $customer, $this->leavingNote($leaving)),
            legend: 'Naar welke locatie?',
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $customer = $this->customer($paste);
        $assets = $this->toMove($paste, $customer);
        $location = $this->chosenLocation($paste, $customer);
        $gate = Gate::forUser($paste->user);

        $assets->each(fn (Asset $asset) => $gate->authorize('update', $asset));

        $transferring = $this->transferring($assets, $customer);

        $assets->diff($transferring)->each(fn (Asset $asset) => $asset->update(['location_id' => $location?->id]));

        if ($transferring->isNotEmpty()) {
            app(AssetTransferService::class)->transfer(
                $transferring,
                $customer->id,
                $this->everyLocationTo($transferring, $location),
            );
        }

        return new PasteResult(($assets->count() === 1
            ? AssetKind::title($assets->first()) . ' staat nu bij '
            : $assets->count() . ' machines staan nu bij ')
            . $customer->name . ', ' . ($location ? AssetKind::locationLabel($location) : 'zonder locatie') . '.');
    }

    protected function customer(Paste $paste): Customer
    {
        return DropTarget::customer($paste);
    }

    /** The location the drop itself already picked, when it was dropped on one. */
    protected function destination(Paste $paste): ?Location
    {
        return null;
    }

    /**
     * Where the machines can go: the location they were dropped on, or else
     * each of the customer's locations and none at all.
     *
     * @return array<int, Choice>
     */
    private function choices(Paste $paste, Customer $customer, string $leaving): array
    {
        $picked = $this->destination($paste);
        $locations = $picked ? collect([$picked]) : $customer->locations()->orderBy('title')->get();

        $choices = $locations->map(fn (Location $location) => new Choice(
            value: 'location:' . $location->id,
            label: AssetKind::locationLabel($location),
            description: $this->underLabel($location, $customer) . '.' . $leaving,
            confirm: 'Verhuizen',
        ))->all();

        if ($picked) {
            return $choices;
        }

        return [...$choices, new Choice(
            value: self::NO_LOCATION,
            label: 'Zonder locatie',
            description: 'Bij ' . $customer->name . ', zonder vaste plek.' . $leaving,
            confirm: 'Verhuizen',
        )];
    }

    /** The address under a location's title; when the address is the label already, whose it is. */
    private function underLabel(Location $location, Customer $customer): string
    {
        return $location->title && $location->addressLine() ? $location->addressLine() : $customer->name;
    }

    /**
     * @param  array<int, array{id: int, label: string}>  $contracts
     */
    private function leavingNote(array $contracts): string
    {
        if ($contracts === []) {
            return '';
        }

        return ' Gaat van ' . (count($contracts) === 1 ? 'contract ' : 'de contracten ')
            . collect($contracts)->pluck('label')->implode(', ') . ' af.';
    }

    /**
     * The parts built into these machines. They have no place of their own, so
     * they go wherever their machine goes; nobody should be surprised to find
     * them gone.
     *
     * @param  Collection<int, Asset>  $assets
     * @return array<int, array{title: string, subtitle: string}>
     */
    private function partsGoingAlong(Collection $assets): array
    {
        return app(AssetTransferService::class)->treeFor($assets)
            ->reject(fn (Asset $asset) => $assets->contains('id', $asset->id))
            ->map(fn (Asset $part) => ['title' => AssetKind::title($part), 'subtitle' => 'Onderdeel, gaat mee'])
            ->values()
            ->all();
    }

    private function chosenLocation(Paste $paste, Customer $customer): ?Location
    {
        if ($paste->answer === self::NO_LOCATION) {
            return null;
        }

        return $customer->locations()->findOrFail((int) Str::after($paste->answer, 'location:'));
    }

    /**
     * Only whole machines move: a part goes where its machine goes. Dropped on
     * a location, a machine already standing there is left out; when that is
     * all of them, there is nothing to do.
     *
     * @return Collection<int, Asset>
     */
    private function toMove(Paste $paste, Customer $customer): Collection
    {
        AssetKind::refuseParts($paste->records, 'verhuist alleen mee met die machine');

        $picked = $this->destination($paste);
        $assets = $paste->records
            ->reject(fn (Asset $asset) => $picked
                && $asset->customer_id === $customer->id
                && $asset->location_id === $picked->id)
            ->values();

        if ($assets->isEmpty()) {
            throw new Refusal($paste->these('staat', 'staan') . ' al op deze locatie.');
        }

        return $assets;
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @return Collection<int, Asset>
     */
    private function transferring(Collection $assets, Customer $customer): Collection
    {
        return $assets->filter(fn (Asset $asset) => $asset->customer_id !== $customer->id)->values();
    }

    /**
     * The transfer maps each old location to a new one; here every machine goes
     * to the same place, wherever it stood. A machine without a location reads
     * its entry under the empty key.
     *
     * @param  Collection<int, Asset>  $assets
     * @return array<int|string, ?int>
     */
    private function everyLocationTo(Collection $assets, ?Location $location): array
    {
        return $assets->mapWithKeys(fn (Asset $asset) => [(string) $asset->location_id => $location?->id])->all();
    }
}
