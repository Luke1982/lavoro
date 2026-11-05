<?php

namespace App\Services;

use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Models\Central\Reseller;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class TenantSubscription
{
    private ?Package $package = null;

    private bool $package_looked_up = false;

    /** @var array<int, array{description: string, kind: string, amount_cents: int, regular_cents?: int}>|null */
    private ?array $chargeable = null;

    public function __construct(private Tenant $tenant) {}

    /**
     * This customer's package, looked up once. It used to be fetched in two
     * places, so every price calculation asked the same question twice.
     */
    private function package(): ?Package
    {
        if (!$this->package_looked_up) {
            $this->package = Package::on('central')->where('key', $this->tenant->package_key)->first();
            $this->package_looked_up = true;
        }

        return $this->package;
    }

    /** The name as it belongs on the invoice, or nothing for an unknown package. */
    public function packageName(): ?string
    {
        return $this->package()?->name;
    }

    /**
     * What the package costs: the fixed price, or else the catalogue price.
     *
     * A fixed price covers the seats and the storage as well. To compare it
     * with a catalogue price -- when one is set or taken away -- that side has
     * to count them too: $with_extras.
     */
    public function packageCents(bool $with_extras = false): int
    {
        if ($this->hasFixedPrice()) {
            return (int) $this->tenant->price_override_cents;
        }

        $extras = $with_extras ? [...$this->seatLines(), ...$this->storageLines()] : [];

        return (int) ($this->package()?->price_cents ?? 0) + array_sum(array_column($extras, 'amount_cents'));
    }

    public function hasFixedPrice(): bool
    {
        return $this->tenant->price_override_cents !== null;
    }

    public function monthlyTotalCents(): int
    {
        return max(0, $this->beforeDiscountCents() - $this->discountCents() - $this->couponDiscountCents());
    }

    /**
     * The subscription itemised, so the invoice shows what is being paid for
     * instead of one amount. The lines add up to monthlyTotalCents();
     * discounts sit in between as a negative line.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int, regular_cents?: int, detail?: string}>
     */
    public function breakdown(): array
    {
        return array_merge($this->chargeableLines(), $this->discountLines());
    }

    /**
     * Everything that is paid for, without the discounts: the package, the
     * extra seats, the modules and the storage.
     *
     * This is the only place that build-up lives. The discount is computed over
     * the sum of it, so if the invoice lines and that sum each did their own
     * arithmetic they could drift apart without anything noticing.
     *
     * Each line carries a detail: why it costs what it costs. The invoice
     * prints only the description; the panel shows both.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int, regular_cents?: int, detail?: string}>
     */
    private function chargeableLines(): array
    {
        if ($this->chargeable !== null) {
            return $this->chargeable;
        }

        $package = $this->package();
        $seats = $this->seatLines();
        $storage = $this->storageLines();

        $subscription = [
            'description' => 'Abonnement Lavoro' . ($package?->name ? ' ' . $package->name : ''),
            'kind' => 'subscription',
            'amount_cents' => (int) ($package->price_cents ?? 0),
            'detail' => $package
                ? 'catalogusprijs, met ' . $package->field_seats . ' buitendienstplekken, '
                    . $package->office_seats . ' kantoorplekken en '
                    . PricingSetting::value('included_storage_gb', 50) . ' GB opslag'
                : 'geen pakket',
        ];

        /**
         * A fixed price is the price of the package with every seat and every
         * gigabyte, however many there are. The line names what it includes and
         * what that would have cost, so nobody has to guess why the amount is
         * lower. Only the modules come on top: those are bought separately, and
         * each can have a price of its own agreed.
         */
        if ($this->hasFixedPrice()) {
            $extras = [...$seats, ...$storage];
            $regular = $subscription['amount_cents'] + array_sum(array_column($extras, 'amount_cents'));

            $subscription = [
                'description' => $subscription['description']
                    . ($extras ? ' (met ' . Arr::join(array_column($extras, 'phrase'), ', ', ' en ') . ')' : ''),
                'kind' => 'subscription',
                'amount_cents' => (int) $this->tenant->price_override_cents,
                'regular_cents' => $regular,
                'detail' => 'vaste prijs, normaal € ' . Money::human($regular),
            ];
            $seats = $storage = [];
        }

        return $this->chargeable = array_map(
            fn (array $line) => Arr::except($line, 'phrase'),
            [$subscription, ...$seats, ...$this->moduleLines(), ...$storage],
        );
    }

    /** @return array<int, array{description: string, kind: string, amount_cents: int, detail: string, phrase: string}> */
    private function seatLines(): array
    {
        $lines = [];

        foreach ([
            ['extra_field_seats', 'extra_field_cents', 'field_seats', 'buitendienstplek', 'buitendienstplekken'],
            ['extra_office_seats', 'extra_office_cents', 'office_seats', 'kantoorplek', 'kantoorplekken'],
        ] as [$count_field, $price_field, $included_field, $one, $many]) {
            $count = (int) $this->tenant->{$count_field};
            $price = (int) ($this->package()?->{$price_field} ?? 0);

            if ($count) {
                $lines[] = [
                    'description' => 'Extra ' . $one . ' (' . $count . ' x)',
                    'kind' => 'seats',
                    'amount_cents' => $count * $price,
                    'detail' => '€ ' . Money::human($price) . ' per plek, bovenop de '
                        . (int) ($this->package()?->{$included_field} ?? 0) . ' in het pakket',
                    'phrase' => $count . ' extra ' . ($count === 1 ? $one : $many),
                ];
            }
        }

        return $lines;
    }

    /** @return array<int, array{description: string, kind: string, amount_cents: int, detail: string, phrase: string}> */
    private function storageLines(): array
    {
        $gb = $this->extraStorageGb();
        $cents = $this->storageCents();

        if (!$cents) {
            return [];
        }

        return [[
            'description' => 'Extra opslag (' . $gb . ' GB)',
            'kind' => 'storage',
            'amount_cents' => $cents,
            'detail' => '€ ' . Money::human(PricingSetting::value('storage_extra_per_gb_cents', 50)) . ' per GB, bovenop de '
                . PricingSetting::value('included_storage_gb', 50) . ' GB in het pakket',
            'phrase' => $gb . ' GB extra opslag',
        ]];
    }

    /** @return array<int, array{description: string, kind: string, amount_cents: int, detail?: string}> */
    private function discountLines(): array
    {
        $lines = [];

        if ($discount = $this->discountCents()) {
            $lines[] = array_filter([
                'description' => $this->tenant->discount_percent
                    ? 'Korting ' . (int) $this->tenant->discount_percent . '%'
                    : 'Korting',
                'kind' => 'discount',
                'amount_cents' => -$discount,
                'detail' => $this->tenant->discount_percent ? null : 'vast bedrag per maand',
            ], fn ($value) => $value !== null);
        }

        if ($coupon = $this->couponDiscountCents()) {
            $lines[] = [
                'description' => 'Kortingsbon ' . (int) $this->tenant->coupon_discount_percent . '%',
                'kind' => 'discount',
                'amount_cents' => -$coupon,
                'detail' => 'tot en met ' . Carbon::parse($this->tenant->coupon_discount_until)->format('d-m-Y'),
            ];
        }

        return $lines;
    }

    /**
     * This customer's modules, each with the price that applies to them.
     *
     * A bundle replaces the individual prices of the modules in it, but only
     * when the customer has all of them: otherwise someone pays for a discount
     * they do not get. If a price has been agreed for one of those modules that
     * one wins -- an agreement someone made by hand should not be run over by a
     * bundle price.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int, regular_cents?: int, detail?: string, module_keys: array<int, string>}>
     */
    private function moduleLines(): array
    {
        $keys = collect($this->tenant->modules ?? []);
        /** Without an amount it is not an agreement; an empty row would push the bundle aside. */
        $agreed = collect($this->tenant->module_prices ?? [])->filter(fn ($price) => $price !== null);
        $lines = [];

        foreach (ModuleBundle::on('central')->get() as $bundle) {
            $complete = collect($bundle->module_keys)->every(fn ($key) => $keys->contains($key));
            $bargained = collect($bundle->module_keys)->contains(fn ($key) => $agreed->has($key));

            if (!$complete || $bargained) {
                continue;
            }

            $lines[] = [
                'description' => $bundle->name,
                'kind' => 'module',
                'amount_cents' => (int) $bundle->price_cents,
                'detail' => 'bundelprijs: alle modules van de bundel staan aan',
                'module_keys' => $bundle->module_keys,
            ];

            $keys = $keys->reject(fn ($key) => in_array($key, $bundle->module_keys, true));
        }

        foreach (Module::on('central')->whereIn('key', $keys)->orderBy('sort_order')->get() as $module) {
            $own = $agreed->get($module->key);

            $lines[] = array_filter([
                'description' => $module->name,
                'kind' => 'module',
                'amount_cents' => $own !== null ? (int) $own : (int) $module->price_cents,
                'regular_cents' => $own !== null ? (int) $module->price_cents : null,
                'detail' => $own !== null ? 'afgesproken prijs, normaal € ' . Money::human((int) $module->price_cents) : null,
                'module_keys' => [$module->key],
            ], fn ($value) => $value !== null);
        }

        return $lines;
    }

    /**
     * The coupon runs out, the manual discount does not. They sit side by side:
     * a customer who came in with a coupon can have been promised something on
     * top of it.
     */
    public function couponDiscountCents(): int
    {
        $until = $this->tenant->coupon_discount_until;
        $percent = (int) ($this->tenant->coupon_discount_percent ?? 0);

        if (!$percent || !$until || now()->startOfDay()->gt(Carbon::parse($until))) {
            return 0;
        }

        return (int) round($this->beforeDiscountCents() * $percent / 100);
    }

    /** What the reseller earns on this customer this month. */
    public function commissionCents(): int
    {
        if (!$this->tenant->reseller_id) {
            return 0;
        }

        $percent = (int) (Reseller::on('central')
            ->find($this->tenant->reseller_id)?->commission_percent ?? 0);

        return (int) round($this->monthlyTotalCents() * $percent / 100);
    }

    /** What it would cost without discount -- the amount the discount is computed over. */
    public function beforeDiscountCents(): int
    {
        return array_sum(array_column($this->chargeableLines(), 'amount_cents'));
    }

    /**
     * Percentage first, then the fixed amount. The other way around, a discount
     * of ten euro plus ten percent would come out lower than what the customer
     * was promised.
     */
    public function discountCents(): int
    {
        $before = $this->beforeDiscountCents();

        /** A discount is an amount or a percentage, never both. */
        if ($this->tenant->discount_percent) {
            return min($before, (int) round($before * (int) $this->tenant->discount_percent / 100));
        }

        return min($before, (int) ($this->tenant->discount_cents ?? 0));
    }

    private function extraStorageGb(): int
    {
        return max(0, (int) $this->tenant->storage_limit_gb - PricingSetting::value('included_storage_gb', 50));
    }

    private function storageCents(): int
    {
        return $this->extraStorageGb() * PricingSetting::value('storage_extra_per_gb_cents', 50);
    }
}
