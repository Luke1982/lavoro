<?php

namespace Tests\Feature\Licensing;

use App\Models\Central\ModuleBundle;
use App\Models\Central\Reseller;
use App\Models\Tenant;
use App\Services\TenantSubscription;
use Tests\TestCase;

/**
 * De prijsopbouw waar elke factuur op leunt. Rekent op de gezaaide catalogus:
 * Business 160, Offertes 27,50, Facturen 27,50, AI-assistent 22,50, de bundel
 * Offertes + Facturen 40, en 50 GB opslag inbegrepen.
 */
class TenantSubscriptionTest extends TestCase
{
    private function subscription(array $attributes = []): TenantSubscription
    {
        $tenant = new Tenant(array_merge([
            'package_key' => 'business',
            'modules' => [],
            'extra_field_seats' => 0,
            'extra_office_seats' => 0,
            'storage_limit_gb' => 50,
        ], $attributes));

        return new TenantSubscription($tenant);
    }

    public function test_a_bare_package_costs_the_package_price(): void
    {
        $this->assertSame(16000, $this->subscription()->monthlyTotalCents());
    }

    public function test_a_module_adds_its_own_price(): void
    {
        $this->assertSame(16000 + 2250, $this->subscription(['modules' => ['assistant']])->monthlyTotalCents());
    }

    public function test_a_complete_bundle_replaces_the_loose_module_prices(): void
    {
        $this->assertSame(1, ModuleBundle::on('central')->count(), 'De bundel Offertes + Facturen hoort gezaaid te zijn.');

        $this->assertSame(16000 + 4000, $this->subscription(['modules' => ['quotes', 'invoices']])->monthlyTotalCents());
    }

    public function test_half_a_bundle_pays_the_loose_price(): void
    {
        $this->assertSame(16000 + 2750, $this->subscription(['modules' => ['quotes']])->monthlyTotalCents());
    }

    public function test_extra_seats_use_the_package_rate(): void
    {
        $this->assertSame(
            16000 + 2 * 1000 + 700,
            $this->subscription(['extra_field_seats' => 2, 'extra_office_seats' => 1])->monthlyTotalCents(),
        );
    }

    public function test_extra_storage_is_billed_per_gigabyte_above_the_included_amount(): void
    {
        $this->assertSame(16000 + 10 * 50, $this->subscription(['storage_limit_gb' => 60])->monthlyTotalCents());
    }

    /**
     * Een afgesproken prijs gaat over het pakket. Wat er los bijgekocht wordt
     * komt er bovenop; anders krijgt een klant met een vaste prijs elke module
     * en elke gigabyte er gratis bij.
     */
    public function test_a_price_override_covers_the_package_and_nothing_else(): void
    {
        $this->assertSame(9999, $this->subscription(['price_override_cents' => 9999])->monthlyTotalCents());

        $this->assertSame(
            9999 + 2250 + 3 * 1000,
            $this->subscription([
                'price_override_cents' => 9999,
                'modules' => ['assistant'],
                'extra_field_seats' => 3,
            ])->monthlyTotalCents(),
        );
    }

    public function test_a_module_can_have_a_price_of_its_own(): void
    {
        $this->assertSame(16000 + 1000, $this->subscription([
            'modules' => ['assistant'],
            'module_prices' => ['assistant' => 1000],
        ])->monthlyTotalCents());
    }

    public function test_an_agreed_module_price_of_nothing_is_free_and_not_ignored(): void
    {
        $this->assertSame(16000, $this->subscription([
            'modules' => ['assistant'],
            'module_prices' => ['assistant' => 0],
        ])->monthlyTotalCents());
    }

    /**
     * Een bundelprijs komt uit de catalogus, een eigen moduleprijs is met deze
     * klant afgesproken. Die afspraak gaat voor, anders wordt hij overreden
     * door een bundel die iemand er ooit omheen gezet heeft.
     */
    public function test_an_agreed_module_price_beats_the_bundle(): void
    {
        $this->assertSame(
            16000 + 1500 + 2750,
            $this->subscription([
                'modules' => ['quotes', 'invoices'],
                'module_prices' => ['quotes' => 1500],
            ])->monthlyTotalCents(),
        );
    }

    public function test_a_price_for_a_module_the_customer_does_not_have_costs_nothing(): void
    {
        $this->assertSame(16000, $this->subscription([
            'modules' => [],
            'module_prices' => ['assistant' => 9900],
        ])->monthlyTotalCents());
    }

    public function test_an_unknown_package_costs_nothing_instead_of_crashing(): void
    {
        $this->assertSame(0, $this->subscription(['package_key' => 'bestaat-niet'])->monthlyTotalCents());
    }

    public function test_modules_outside_a_bundle_are_added_on_top_of_it(): void
    {
        $this->assertSame(
            16000 + 4000 + 2250,
            $this->subscription(['modules' => ['quotes', 'invoices', 'assistant']])->monthlyTotalCents(),
        );
    }

    public function test_storage_is_free_up_to_the_included_amount(): void
    {
        $this->assertSame(16000, $this->subscription(['storage_limit_gb' => 50])->monthlyTotalCents());
        $this->assertSame(16000, $this->subscription(['storage_limit_gb' => 10])->monthlyTotalCents());
    }

    public function test_a_coupon_without_an_end_date_counts_for_nothing(): void
    {
        $this->assertSame(16000, $this->subscription(['coupon_discount_percent' => 15])->monthlyTotalCents());
    }

    /** Allebei rekenen over de prijs voor korting, niet over elkaars uitkomst. */
    public function test_a_coupon_and_a_manual_discount_stack(): void
    {
        $this->assertSame(16000 - 1600 - 1600, $this->subscription([
            'discount_percent' => 10,
            'coupon_discount_percent' => 10,
            'coupon_discount_until' => now()->addMonth()->toDateString(),
        ])->monthlyTotalCents());
    }

    public function test_a_discount_shows_up_as_its_own_negative_line(): void
    {
        $lines = $this->subscription([
            'discount_percent' => 10,
            'coupon_discount_percent' => 5,
            'coupon_discount_until' => now()->addMonth()->toDateString(),
        ])->breakdown();

        $discounts = array_values(array_filter($lines, fn ($line) => $line['kind'] === 'discount'));

        $this->assertCount(2, $discounts);
        $this->assertSame('Korting 10%', $discounts[0]['description']);
        $this->assertSame(-1600, $discounts[0]['amount_cents']);
        $this->assertSame('Kortingsbon 5%', $discounts[1]['description']);
        $this->assertSame(-800, $discounts[1]['amount_cents']);
    }

    public function test_commission_is_a_percentage_of_what_the_customer_pays(): void
    {
        $reseller = Reseller::on('central')->create(['name' => 'Wederverkoper', 'commission_percent' => 20]);

        $subscription = $this->subscription(['reseller_id' => $reseller->id, 'discount_percent' => 10]);

        $this->assertSame(16000 - 1600, $subscription->monthlyTotalCents());
        $this->assertSame((int) round((16000 - 1600) * 0.2), $subscription->commissionCents());
    }

    public function test_without_a_reseller_there_is_no_commission(): void
    {
        $this->assertSame(0, $this->subscription()->commissionCents());
    }

    public function test_a_percentage_discount_beats_a_leftover_euro_amount(): void
    {
        /** Een korting is een bedrag of een percentage, nooit allebei. */
        $subscription = $this->subscription(['discount_percent' => 10, 'discount_cents' => 99999]);

        $this->assertSame(16000 - 1600, $subscription->monthlyTotalCents());
    }

    public function test_a_euro_discount_comes_off_the_total(): void
    {
        $this->assertSame(16000 - 2500, $this->subscription(['discount_cents' => 2500])->monthlyTotalCents());
    }

    public function test_a_discount_can_never_push_the_price_below_zero(): void
    {
        $this->assertSame(0, $this->subscription(['discount_cents' => 999999])->monthlyTotalCents());
    }

    public function test_an_expired_coupon_no_longer_discounts(): void
    {
        $active = $this->subscription([
            'coupon_discount_percent' => 10,
            'coupon_discount_until' => now()->addMonth()->toDateString(),
        ]);
        $expired = $this->subscription([
            'coupon_discount_percent' => 10,
            'coupon_discount_until' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(16000 - 1600, $active->monthlyTotalCents());
        $this->assertSame(16000, $expired->monthlyTotalCents());
    }

    public function test_the_breakdown_always_sums_to_the_monthly_total(): void
    {
        foreach ([
            [],
            ['modules' => ['assistant']],
            ['modules' => ['quotes', 'invoices', 'assistant'], 'extra_field_seats' => 2],
            ['storage_limit_gb' => 75, 'discount_percent' => 5],
            ['price_override_cents' => 12345, 'discount_cents' => 345],
            ['coupon_discount_percent' => 10, 'coupon_discount_until' => now()->addMonth()->toDateString()],
            ['price_override_cents' => 12345, 'modules' => ['assistant'], 'storage_limit_gb' => 90],
            ['modules' => ['quotes', 'invoices'], 'module_prices' => ['quotes' => 1500]],
            ['modules' => ['assistant'], 'module_prices' => ['assistant' => 0]],
            ['package_key' => 'bestaat-niet', 'modules' => ['assistant']],
        ] as $attributes) {
            $subscription = $this->subscription($attributes);

            $this->assertSame(
                $subscription->monthlyTotalCents(),
                array_sum(array_column($subscription->breakdown(), 'amount_cents')),
                'De uitgesplitste regels horen op te tellen tot het maandbedrag voor ' . json_encode($attributes),
            );
        }
    }
}
