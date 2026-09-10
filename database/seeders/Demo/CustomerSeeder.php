<?php

namespace Database\Seeders\Demo;

use App\Enums\AssetStatusses;
use App\Enums\ContractInterval;
use App\Models\Asset;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\Product;

/**
 * Customers with the installations they have on site, and a maintenance
 * contract for the ones that pay for one.
 */
final class CustomerSeeder
{
    public function __construct(private DemoContext $context) {}

    public function run(): void
    {
        $data = $this->context->data('customers');
        $brands = $this->context->data('catalogue')['brands'];

        /**
         * A migration puts a "Dummy klant" in every new database. In a demo it
         * sits in the customer list between the real ones and reads as a
         * leftover; on a fresh demo nothing points at it.
         */
        Customer::where('name', 'Dummy klant')->doesntHave('assets')->delete();

        foreach ([...$data['customers'], ...$this->households($data['households'])] as $entry) {
            $customer = $this->customer($entry, $data['coordinates']);

            $sites = [[
                'location' => null,
                'assets' => $this->install($customer, null, $entry['installs'], $entry['brand'], $data['recipes'], $brands),
            ]];

            foreach ($entry['sites'] ?? [] as $site) {
                $location = Location::create([
                    'customer_id' => $customer->id,
                    'title' => $site['title'],
                    'address' => $site['address'],
                    'postal_code' => $site['postal_code'],
                    'city' => $site['city'],
                    'country' => 'NL',
                    ...$this->spot($site['city'], $data['coordinates']),
                ]);

                $sites[] = [
                    'location' => $location,
                    'assets' => $this->install($customer, $location, $site['installs'], $entry['brand'], $data['recipes'], $brands),
                ];
            }

            if (!empty($entry['contract'])) {
                $this->contract($customer, $entry['contract'], collect($sites)->pluck('assets')->flatten(1)->all());
            }

            $this->context->customers[] = ['customer' => $customer, 'data' => $entry, 'sites' => $sites];
        }
    }

    /**
     * Households in the same shape as the businesses above them, so the rest of
     * this seeder does not need to know the difference.
     *
     * @return array<int, array<string, mixed>>
     */
    private function households(array $lists): array
    {
        $surnames = $this->context->random->shuffleArray($lists['surnames']);
        $recipes = collect($lists['installs'])->flatMap(fn (array $recipe, string $name) => array_fill(0, $recipe[0], $name))->all();
        $households = [];
        $named = [];

        for ($index = 0; $index < $lists['count']; $index++) {
            $surname = $surnames[$index % count($surnames)];
            $town = $this->context->pick(array_keys($lists['towns']));
            $place = $lists['towns'][$town];
            $first = $this->context->pick($lists['first_names']);

            /**
             * A family name once as "Fam.", after that with an initial, and never
             * the same name twice: two households called S. Boon read as a
             * duplicate, not as two customers.
             */
            $name = isset($named['Fam. ' . ucfirst($surname)]) || $this->context->chance(0.4)
                ? substr($first, 0, 1) . '. ' . $surname
                : 'Fam. ' . ucfirst($surname);

            for ($attempt = 0; isset($named[$name]) && $attempt < 30; $attempt++) {
                $first = $this->context->pick($lists['first_names']);
                $name = substr($first, 0, 1) . '. ' . $surname;
            }

            if (isset($named[$name])) {
                continue;
            }

            $named[$name] = true;
            $recipe = $this->context->pick($recipes);
            $mobile = sprintf('06-%02d %02d %02d %02d', $this->context->between(10, 65), $this->context->between(10, 99),
                $this->context->between(10, 99), $this->context->between(10, 99));

            $households[] = [
                'name' => $name,
                'address' => $this->context->pick($place['streets']) . ' ' . $this->context->between(2, 148),
                'postal_code' => $place['postal'] . $this->context->between(1, 7) . ' '
                    . $this->context->pick(['A', 'B', 'C', 'E', 'G', 'H', 'J', 'K', 'L', 'M', 'N', 'P', 'R', 'T', 'V', 'W'])
                    . $this->context->pick(['A', 'B', 'C', 'D', 'E', 'G', 'H', 'J', 'K', 'L', 'M', 'N', 'P', 'R', 'T', 'X']),
                'city' => $town,
                'phone' => $mobile,
                'email' => str_replace(' ', '', mb_strtolower($surname)) . ($index + 1) . '@thuis.demo',
                'contact' => [$first, $surname, $mobile],
                'installs' => $this->context->chance($lists['solar']) ? [$recipe, 'pv'] : [$recipe],
                'brand' => $this->context->pick($lists['installs'][$recipe][1]),
                'contract' => $this->context->chance($lists['contract']) ? 'Jaarlijks' : null,
                'private' => true,
            ];
        }

        return $households;
    }

    private function customer(array $entry, array $coordinates): Customer
    {
        [$first, $last, $mobile] = $entry['contact'];

        $customer = Customer::forceCreate([
            'name' => $entry['name'],
            'email' => $entry['email'],
            'invoice_email' => $entry['email'],
            'phone' => $entry['phone'],
            'address' => $entry['address'],
            'postal_code' => $entry['postal_code'],
            'city' => $entry['city'],
            'country' => 'NL',
            'contactname' => "{$first} {$last}",
            ...$this->spot($entry['city'], $coordinates),
        ]);

        $contact = Contact::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower(str_replace(' ', '', $first)) . '@' . substr(strrchr($entry['email'], '@'), 1),
            'phone' => $entry['phone'],
            'mobile' => $mobile,
        ]);

        $customer->contacts()->attach($contact->id);

        return $customer;
    }

    /**
     * A spot near the town centre. Not the real address -- that would take a
     * geocoding service at night -- but close enough that the map is right
     * about where the work is.
     *
     * @return array{lat: float, lon: float}|array{}
     */
    private function spot(string $town, array $coordinates): array
    {
        if (!isset($coordinates[$town])) {
            return [];
        }

        [$lat, $lon] = $coordinates[$town];

        return [
            'lat' => round($lat + $this->context->random->getFloat(-0.011, 0.011), 6),
            'lon' => round($lon + $this->context->random->getFloat(-0.018, 0.018), 6),
        ];
    }

    /**
     * The machines of one site. Units of one system come from the brand the
     * customer was sold, so a Daikin outdoor unit does not end up feeding a
     * Mitsubishi indoor unit; a type that brand does not make falls back to one
     * that does.
     *
     * @param  array<int, string>  $installs
     * @return array<int, Asset>
     */
    private function install(Customer $customer, ?Location $location, array $installs, string $brand, array $recipes, array $brands): array
    {
        $assets = [];

        foreach ($installs as $recipe) {
            $installed = $this->context->now->subMonths($this->context->between(4, 96));

            foreach ($recipes[$recipe] as $type => $count) {
                $product = $this->product($type, $brand);

                for ($unit = 0; $unit < $count; $unit++) {
                    $assets[] = Asset::forceCreate([
                        'product_id' => $product->id,
                        'customer_id' => $customer->id,
                        'location_id' => $location?->id,
                        'serial_number' => ($brands[$product->brand->name]['serial'] ?? 'SN')
                            . $this->context->between(1_000_000, 9_999_999),
                        'date_in_service' => $installed->toDateString(),
                        'next_service_date' => $this->nextService(),
                        'status' => AssetStatusses::active->value,
                    ]);
                }
            }
        }

        return $assets;
    }

    private function product(string $type, string $brand): Product
    {
        $candidates = $this->context->products[$type];
        $same_brand = array_values(array_filter($candidates, fn (Product $product) => $product->brand->name === $brand));

        return $this->context->pick($same_brand ?: $candidates);
    }

    /**
     * Mostly in the coming months, a few already overdue: the overview of
     * machines due for service is one of the screens a demo shows, and it has
     * nothing to say when everything is neatly on time.
     */
    private function nextService(): string
    {
        return $this->context->chance(0.12)
            ? $this->context->now->subDays($this->context->between(5, 60))->toDateString()
            : $this->context->now->addDays($this->context->between(10, 330))->toDateString();
    }

    /** @param  array<int, Asset>  $assets */
    private function contract(Customer $customer, string $interval, array $assets): void
    {
        $frequency = ContractInterval::from($interval);
        $visits_per_year = match ($frequency) {
            ContractInterval::maandelijks => 12,
            ContractInterval::halfjaarlijks => 2,
            default => 1,
        };

        /** A visit is a call-out plus a bit per machine; a contract gives some off. */
        $per_visit = 85 + count($assets) * 32;

        $contract = MaintenanceContract::forceCreate([
            'customer_id' => $customer->id,
            'title' => 'Onderhoudscontract ' . $customer->name,
            'start_date' => $this->context->now->subYears($this->context->between(1, 4))->startOfYear()->toDateString(),
            'price' => round($per_visit * $visits_per_year * 0.85 / 5) * 5,
            'price_interval' => ContractInterval::jaarlijks,
            'frequency' => $frequency,
            'manage_frequency_per_asset' => false,
            'auto_generate' => false,
        ]);

        $contract->assets()->attach(collect($assets)->pluck('id')->all());
    }
}
