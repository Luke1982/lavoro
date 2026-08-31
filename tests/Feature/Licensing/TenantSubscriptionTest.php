<?php

namespace Tests\Feature\Licensing;

use App\Models\Central\ModuleBundle;
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

    public function test_a_price_override_replaces_the_whole_calculation(): void
    {
        $this->assertSame(9999, $this->subscription([
            'price_override_cents' => 9999,
            'modules' => ['assistant'],
            'extra_field_seats' => 3,
        ])->monthlyTotalCents());
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
