<?php

namespace App\Console\Commands;

use App\Enums\EventStatusses;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Services\EventLocationResolver;
use App\Services\Geocoder;
use App\Support\AddressFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Vult de coördinaten aan die de kaarten nodig hebben.
 *
 * Zonder lat/lon kan een adres niet op de kaart, en de meeste klanten hebben ze
 * niet: ze worden alleen gezet als iemand ze op het klantformulier opzoekt.
 * Dit commando doet dat werk in bulk.
 *
 * Nominatim is gratis en staat één verzoek per seconde toe, dus er zit een pauze
 * tussen de vragen en een plafond op het aantal. Dat plafond telt alleen echte
 * verzoeken: een adres dat al eens opgezocht is komt uit de cache, kost geen
 * pauze en geen budget. Een tweede rondje over bekende adressen is daarmee zo
 * klaar.
 */
class GeocodeAddresses extends Command
{
    protected $signature = 'geocode:addresses
        {--limit=100 : Hoeveel adressen dit rondje maximaal opgezocht worden}
        {--days=60 : Hoe ver vooruit en terug er naar afspraken gekeken wordt}
        {--all : Alle klanten zonder coördinaten, niet alleen die met afspraken}';

    protected $description = 'Zoekt ontbrekende coördinaten op voor locaties, klanten en de adressen van afspraken';

    public function handle(Geocoder $geocoder, EventLocationResolver $resolver): int
    {
        $budget = (int) $this->option('limit');

        $budget = $this->fillModels('Locaties', $this->locationsToFill(), $geocoder, $budget);
        $budget = $this->fillModels('Klanten', $this->customersToFill(), $geocoder, $budget);
        $budget = $this->warmAppointmentAddresses($geocoder, $resolver, $budget);

        $this->newLine();
        $this->info('Klaar. ' . ($budget > 0
            ? 'Alles binnen bereik is opgezocht.'
            : 'Plafond bereikt — draai nog een rondje voor de rest.'));

        return self::SUCCESS;
    }

    /**
     * Zoekt de adressen op en zet de coördinaten op het model dat erbij hoort.
     *
     * Locaties en klanten verschillen alleen in waar hun adres vandaan komt, dus
     * ze lopen door dezelfde lus. Wat overblijft is per soort twee regels om de
     * lijst op te halen.
     *
     * @param  Collection<int, array{model: Model, address: string, label: string}>  $targets
     */
    private function fillModels(string $what, Collection $targets, Geocoder $geocoder, int $budget): int
    {
        if ($targets->isEmpty()) {
            return $budget;
        }

        $this->line($what . ' zonder coördinaten: ' . $targets->count());

        foreach ($targets as $target) {
            $coordinates = $geocoder->cached($target['address']);

            if (!$coordinates) {
                if ($budget <= 0) {
                    return 0;
                }

                $budget--;
                $coordinates = $this->lookupOverTheWire($geocoder, $target['address']);
            }

            if (!$coordinates) {
                $this->line('  – niet gevonden: ' . $target['label']);

                continue;
            }

            $target['model']->forceFill($coordinates)->save();
            $this->line('  ✓ ' . $target['label']);
        }

        return $budget;
    }

    /** @return Collection<int, array{model: Location, address: string, label: string}> */
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

    /** @return Collection<int, array{model: Customer, address: string, label: string}> */
    private function customersToFill(): Collection
    {
        $query = Customer::query()
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lon'))
            ->whereNotNull('city');

        if (!$this->option('all')) {
            $query->whereHas('serviceOrders.events', fn ($q) => $q->whereBetween('start', $this->window()));
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

    /**
     * Adressen die alleen als vrije tekst bestaan kunnen nergens opgeslagen
     * worden, maar het antwoord blijft wel in de geocache staan — en daar leest
     * het dashboard uit.
     */
    private function warmAppointmentAddresses(Geocoder $geocoder, EventLocationResolver $resolver, int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        $addresses = Event::whereBetween('start', $this->window())
            ->where('status', '!=', EventStatusses::cancelled->value)
            ->with([...EventLocationResolver::relations(), 'serviceOrders.linkedLocation'])
            ->get()
            ->filter(fn (Event $event) => $event->serviceOrders->isNotEmpty())
            ->filter(fn (Event $event) => !$resolver->coordinates($event))
            ->map(fn (Event $event) => $resolver->resolve($event))
            ->filter()
            ->unique()
            ->reject(fn (string $address) => (bool) $geocoder->cached($address))
            ->values();

        if ($addresses->isEmpty()) {
            return $budget;
        }

        $this->line('Losse afspraakadressen zonder coördinaten: ' . $addresses->count());

        foreach ($addresses as $address) {
            if ($budget <= 0) {
                return 0;
            }

            $budget--;

            $found = $this->lookupOverTheWire($geocoder, $address);

            $this->line(($found ? '  ✓ ' : '  – niet gevonden: ') . $address);
        }

        return $budget;
    }

    /** @return array{0: string, 1: string} */
    private function window(): array
    {
        $days = (int) $this->option('days');

        return [
            CarbonImmutable::now()->subDays($days)->startOfDay()->toDateTimeString(),
            CarbonImmutable::now()->addDays($days)->endOfDay()->toDateTimeString(),
        ];
    }

    /**
     * Eén verzoek, met de pauze die Nominatim vraagt erachteraan. De pauze staat
     * hier en niet in Geocoder: alleen dit commando vraagt er meer dan één achter
     * elkaar, en alleen hier is er dus iets om af te remmen.
     *
     * @return array{lat: float, lon: float}|null
     */
    private function lookupOverTheWire(Geocoder $geocoder, string $address): ?array
    {
        $coordinates = $geocoder->lookup($address);

        sleep(1);

        return $coordinates;
    }
}
