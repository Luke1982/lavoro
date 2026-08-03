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

/**
 * Vult de coördinaten aan die de kaarten nodig hebben.
 *
 * Zonder lat/lon kan een adres niet op de kaart, en de meeste klanten hebben ze
 * niet: ze worden alleen gezet als iemand ze op het klantformulier opzoekt.
 * Dit commando doet dat werk in bulk.
 *
 * Nominatim is gratis en staat één verzoek per seconde toe, dus er zit bewust
 * een pauze tussen de vragen en een plafond op het aantal. Draai het gerust
 * vaker: alles wat al gevonden is wordt overgeslagen.
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

        $budget = $this->fillLocations($geocoder, $budget);
        $budget = $this->fillCustomers($geocoder, $budget);
        $budget = $this->warmAppointmentAddresses($geocoder, $resolver, $budget);

        $this->newLine();
        $this->info('Klaar. ' . ($budget > 0
            ? 'Alles binnen bereik is opgezocht.'
            : 'Plafond bereikt — draai nog een rondje voor de rest.'));

        return self::SUCCESS;
    }

    private function fillLocations(Geocoder $geocoder, int $budget): int
    {
        $locations = Location::whereNull('lat')->orWhereNull('lon')->get();

        if ($locations->isEmpty()) {
            return $budget;
        }

        $this->line('Locaties zonder coördinaten: ' . $locations->count());

        foreach ($locations as $location) {
            if ($budget <= 0) {
                return 0;
            }

            $address = $location->addressLine();

            if (!$address) {
                continue;
            }

            $budget--;
            $coords = $this->lookupPolitely($geocoder, $address);

            if ($coords) {
                $location->forceFill($coords)->save();
                $this->line('  ✓ ' . $address);

                continue;
            }

            $this->line('  – niet gevonden: ' . $address);
        }

        return $budget;
    }

    private function fillCustomers(Geocoder $geocoder, int $budget): int
    {
        $query = Customer::query()
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lon'))
            ->whereNotNull('city');

        if (!$this->option('all')) {
            $query->whereHas('serviceOrders.events', fn ($q) => $q->whereBetween('start', $this->window()));
        }

        $customers = $query->limit(max($budget, 0))->get();

        if ($customers->isEmpty()) {
            return $budget;
        }

        $this->line('Klanten zonder coördinaten: ' . $customers->count());

        foreach ($customers as $customer) {
            if ($budget <= 0) {
                return 0;
            }

            $address = AddressFormatter::format($customer->address, $customer->postal_code, $customer->city);

            if (!$address) {
                continue;
            }

            $budget--;
            $coords = $this->lookupPolitely($geocoder, $address);

            if ($coords) {
                $customer->forceFill($coords)->save();
                $this->line('  ✓ ' . $customer->name);

                continue;
            }

            $this->line('  – niet gevonden: ' . $customer->name . ' (' . $address . ')');
        }

        return $budget;
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

        $events = Event::whereBetween('start', $this->window())
            ->where('status', '!=', EventStatusses::cancelled->value)
            ->with([...EventLocationResolver::relations(), 'serviceOrders.linkedLocation'])
            ->get();

        $addresses = $events
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

            $this->line(($this->lookupPolitely($geocoder, $address) ? '  ✓ ' : '  – niet gevonden: ') . $address);
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
     * Eén verzoek, met de pauze die Nominatim vraagt. De pauze staat hier en niet
     * in Geocoder, want alleen dit commando vraagt er meer dan één achter elkaar.
     */
    private function lookupPolitely(Geocoder $geocoder, string $address): ?array
    {
        $coords = $geocoder->lookup($address);

        sleep(1);

        return $coords;
    }
}
