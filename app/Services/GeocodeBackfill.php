<?php

namespace App\Services;

use App\Enums\EventStatusses;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Support\AddressFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Zoekt de coördinaten op die de kaarten missen.
 *
 * Drie bronnen, in deze volgorde: locaties zonder lat/lon, klanten zonder
 * lat/lon, en de adressen die alleen als vrije tekst op een afspraak staan. De
 * eerste twee krijgen hun coördinaten op het model; de derde kan nergens heen
 * en blijft in de geocache staan, waar het dashboard uit leest.
 *
 * Nominatim is gratis en staat één verzoek per seconde toe. Vandaar het budget
 * en de pauze — en vandaar dat alleen echte verzoeken meetellen: een adres dat
 * al eens opgezocht is kost geen van beide.
 *
 * Adressen die kortgeleden niets opleverden slaat hij over. Zonder dat blijft
 * elke ronde hangen op dezelfde onvindbare adressen en komt hij nooit toe aan
 * de rest.
 */
class GeocodeBackfill
{
    public function __construct(
        private Geocoder $geocoder,
        private EventLocationResolver $resolver,
    ) {}

    /**
     * @param  callable(string, string):void|null  $report  ontvangt ('ok'|'gemist'|'kop', regel)
     * @return array{gevonden: int, gemist: int, resterend: int}
     */
    public function run(int $budget, int $days = 60, bool $all_customers = false, ?callable $report = null): array
    {
        $found = 0;
        $missed = 0;

        $say = $report ?? fn () => null;

        foreach (
            [
                ['Locaties', $this->locationsToFill()],
                ['Klanten', $this->customersToFill($days, $all_customers)],
            ] as [$what, $targets]
        ) {
            if ($targets->isNotEmpty()) {
                $say('kop', $what . ' zonder coördinaten: ' . $targets->count());
            }

            foreach ($targets as $target) {
                if ($this->geocoder->recentlyMissed($target['address'])) {
                    continue;
                }

                $coordinates = $this->geocoder->cached($target['address']);

                if (!$coordinates) {
                    if ($budget <= 0) {
                        return ['gevonden' => $found, 'gemist' => $missed, 'resterend' => 0];
                    }

                    $budget--;
                    $coordinates = $this->lookupOverTheWire($target['address']);
                }

                if (!$coordinates) {
                    $missed++;
                    $say('gemist', $target['label']);

                    continue;
                }

                $target['model']->forceFill($coordinates)->save();
                $found++;
                $say('ok', $target['label']);
            }
        }

        $addresses = $this->appointmentAddressesToFill($days);

        if ($addresses->isNotEmpty()) {
            $say('kop', 'Losse afspraakadressen zonder coördinaten: ' . $addresses->count());
        }

        foreach ($addresses as $address) {
            if ($budget <= 0) {
                return ['gevonden' => $found, 'gemist' => $missed, 'resterend' => 0];
            }

            $budget--;

            if ($this->lookupOverTheWire($address)) {
                $found++;
                $say('ok', $address);

                continue;
            }

            $missed++;
            $say('gemist', $address);
        }

        return ['gevonden' => $found, 'gemist' => $missed, 'resterend' => $budget];
    }

    /** @return Collection<int, array{model: Model, address: string, label: string}> */
    private function locationsToFill(): Collection
    {
        return Location::where(fn ($q) => $q->whereNull('lat')->orWhereNull('lon'))
            ->get()
            ->map(fn (Location $location) => [
                'model' => $location,
                'address' => $location->addressLine(),
                'label' => $location->title ?: $location->addressLine(),
            ])
            ->filter(fn (array $target) => $target['address'] !== '')
            ->values();
    }

    /** @return Collection<int, array{model: Model, address: string, label: string}> */
    private function customersToFill(int $days, bool $all_customers): Collection
    {
        $query = Customer::query()
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lon'))
            ->whereNotNull('city');

        if (!$all_customers) {
            $query->whereHas('serviceOrders.events', fn ($q) => $q->whereBetween('start', $this->window($days)));
        }

        return $query->get()
            ->map(fn (Customer $customer) => [
                'model' => $customer,
                'address' => (string) AddressFormatter::format(
                    $customer->address,
                    $customer->postal_code,
                    $customer->city,
                ),
                'label' => $customer->name,
            ])
            ->filter(fn (array $target) => $target['address'] !== '')
            ->values();
    }

    /** @return Collection<int, string> */
    private function appointmentAddressesToFill(int $days): Collection
    {
        return Event::whereBetween('start', $this->window($days))
            ->where('status', '!=', EventStatusses::cancelled->value)
            ->with([...EventLocationResolver::relations(), 'serviceOrders.linkedLocation'])
            ->get()
            ->filter(fn (Event $event) => $event->serviceOrders->isNotEmpty())
            ->filter(fn (Event $event) => !$this->resolver->coordinates($event))
            ->map(fn (Event $event) => $this->resolver->resolve($event))
            ->filter()
            ->unique()
            ->reject(fn (string $address) => (bool) $this->geocoder->cached($address))
            ->reject(fn (string $address) => $this->geocoder->recentlyMissed($address))
            ->values();
    }

    /** @return array{0: string, 1: string} */
    private function window(int $days): array
    {
        return [
            CarbonImmutable::now()->subDays($days)->startOfDay()->toDateTimeString(),
            CarbonImmutable::now()->addDays($days)->endOfDay()->toDateTimeString(),
        ];
    }

    /**
     * Eén verzoek, met de pauze die Nominatim vraagt erachteraan.
     *
     * @return array{lat: float, lon: float}|null
     */
    private function lookupOverTheWire(string $address): ?array
    {
        $coordinates = $this->geocoder->lookup($address);

        sleep(1);

        return $coordinates;
    }
}
