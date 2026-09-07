<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Reseller;
use App\Models\Tenant;
use App\Services\TenantSubscription;
use Tests\TestCase;

/**
 * De maandprijs van een abonnement, tot op de cent.
 *
 * De bedragen hieronder staan er met opzet uitgeschreven in plaats van
 * uitgerekend uit dezelfde tabellen: een test die de prijs op dezelfde manier
 * berekent als de code bewijst alleen dat de code zichzelf gelijk geeft.
 *
 * Uit de migraties: starter 2750 (extra buiten 1200, extra binnen 800),
 * team 8750 (1100/750), modules offertes 2750, facturen 2750, assistent 2250,
 * bundel offertes+facturen 4000, opslag 50 GB inbegrepen a 50 cent per GB.
 */
class SubscriptionPricingTest extends TestCase
{
    private int $counter = 0;

    private function tenant(array $attributes = []): Tenant
    {
        $this->counter++;

        return Tenant::withoutEvents(fn () => Tenant::on('central')->create([
            'id' => 'prijs-' . $this->counter,
            'name' => 'Prijstest ' . $this->counter,
            'tenancy_db_name' => 'lavoro_test_tenant_prijs',
            'package_key' => 'starter',
            'storage_limit_gb' => 50,
            ...$attributes,
        ]));
    }

    private function monthly(array $attributes = []): int
    {
        return (new TenantSubscription($this->tenant($attributes)))->monthlyTotalCents();
    }

    public function test_a_bare_package_costs_its_own_price(): void
    {
        $this->assertSame(2750, $this->monthly());
        $this->assertSame(8750, $this->monthly(['package_key' => 'team']));
    }

    public function test_an_unknown_package_costs_nothing_instead_of_crashing(): void
    {
        $this->assertSame(0, $this->monthly(['package_key' => 'bestaat-niet']));
    }

    public function test_extra_seats_are_charged_per_seat(): void
    {
        $this->assertSame(2750 + 3 * 1200, $this->monthly(['extra_field_seats' => 3]));
        $this->assertSame(2750 + 2 * 800, $this->monthly(['extra_office_seats' => 2]));
        $this->assertSame(
            2750 + 3 * 1200 + 2 * 800,
            $this->monthly(['extra_field_seats' => 3, 'extra_office_seats' => 2]),
        );
    }

    public function test_seat_prices_come_from_the_package_not_a_fixed_rate(): void
    {
        $this->assertSame(
            8750 + 1100 + 750,
            $this->monthly(['package_key' => 'team', 'extra_field_seats' => 1, 'extra_office_seats' => 1]),
        );
    }

    public function test_modules_are_charged_one_by_one(): void
    {
        $this->assertSame(2750 + 2250, $this->monthly(['modules' => ['assistant']]));
    }

    public function test_a_bundle_replaces_the_separate_prices_of_its_modules(): void
    {
        $separately = 2750 + 2750;

        $this->assertSame(2750 + 4000, $this->monthly(['modules' => ['quotes', 'invoices']]));
        $this->assertLessThan($separately, 4000);
    }

    public function test_a_bundle_only_counts_when_every_module_in_it_is_taken(): void
    {
        $this->assertSame(2750 + 2750, $this->monthly(['modules' => ['quotes']]));
    }

    public function test_modules_outside_the_bundle_are_added_on_top_of_it(): void
    {
        $this->assertSame(
            2750 + 4000 + 2250,
            $this->monthly(['modules' => ['quotes', 'invoices', 'assistant']]),
        );
    }

    public function test_storage_is_free_up_to_the_included_amount(): void
    {
        $this->assertSame(2750, $this->monthly(['storage_limit_gb' => 50]));
        $this->assertSame(2750, $this->monthly(['storage_limit_gb' => 10]));
    }

    public function test_storage_above_the_included_amount_is_charged_per_gigabyte(): void
    {
        $this->assertSame(2750 + 30 * 50, $this->monthly(['storage_limit_gb' => 80]));
    }

    public function test_an_agreed_price_replaces_the_whole_build_up(): void
    {
        $this->assertSame(14900, $this->monthly([
            'package_key' => 'enterprise',
            'price_override_cents' => 14900,
            'extra_field_seats' => 5,
            'modules' => ['quotes', 'invoices', 'assistant'],
            'storage_limit_gb' => 500,
        ]));
    }

    public function test_a_percentage_discount_comes_off_the_total(): void
    {
        $this->assertSame(
            (int) round((2750 + 30 * 50) * 0.9),
            $this->monthly(['storage_limit_gb' => 80, 'discount_percent' => 10]),
        );
    }

    public function test_a_fixed_discount_comes_off_the_total(): void
    {
        $this->assertSame(2750 - 500, $this->monthly(['discount_cents' => 500]));
    }

    public function test_a_percentage_wins_when_both_kinds_of_discount_are_filled_in(): void
    {
        $this->assertSame(2750 - 275, $this->monthly(['discount_percent' => 10, 'discount_cents' => 500]));
    }

    public function test_a_discount_never_makes_the_price_negative(): void
    {
        $this->assertSame(0, $this->monthly(['discount_cents' => 999999]));
        $this->assertSame(0, $this->monthly(['discount_percent' => 100]));
    }

    public function test_a_coupon_counts_while_it_runs(): void
    {
        $this->assertSame(2750 - 413, $this->monthly([
            'coupon_discount_percent' => 15,
            'coupon_discount_until' => now()->addMonth()->toDateString(),
        ]));
    }

    public function test_a_coupon_stops_counting_once_it_has_run_out(): void
    {
        $this->assertSame(2750, $this->monthly([
            'coupon_discount_percent' => 15,
            'coupon_discount_until' => now()->subDay()->toDateString(),
        ]));
    }

    public function test_a_coupon_without_an_end_date_counts_for_nothing(): void
    {
        $this->assertSame(2750, $this->monthly(['coupon_discount_percent' => 15]));
    }

    public function test_a_coupon_and_a_fixed_discount_stack_and_both_count_over_the_undiscounted_price(): void
    {
        $this->assertSame(2750 - 275 - 275, $this->monthly([
            'discount_percent' => 10,
            'coupon_discount_percent' => 10,
            'coupon_discount_until' => now()->addMonth()->toDateString(),
        ]));
    }

    public function test_commission_is_a_percentage_of_what_the_customer_pays(): void
    {
        $reseller = Reseller::on('central')->create(['name' => 'Wederverkoper', 'commission_percent' => 20]);

        $subscription = new TenantSubscription($this->tenant([
            'package_key' => 'team',
            'reseller_id' => $reseller->id,
            'discount_percent' => 10,
        ]));

        $this->assertSame((int) round(8750 * 0.9), $subscription->monthlyTotalCents());
        $this->assertSame((int) round(8750 * 0.9 * 0.2), $subscription->commissionCents());
    }

    public function test_without_a_reseller_there_is_no_commission(): void
    {
        $this->assertSame(0, (new TenantSubscription($this->tenant()))->commissionCents());
    }

    /**
     * De regels op de factuur moeten optellen tot het bedrag dat geincasseerd
     * wordt. Wijkt dat af, dan klopt de factuur niet met de afschrijving,
     * hoe goed de losse bedragen ook zijn.
     */
    public function test_the_lines_always_add_up_to_the_amount_charged(): void
    {
        $cases = [
            [],
            ['extra_field_seats' => 4, 'extra_office_seats' => 1],
            ['modules' => ['quotes', 'invoices', 'assistant']],
            ['modules' => ['quotes']],
            ['storage_limit_gb' => 250],
            ['package_key' => 'business', 'storage_limit_gb' => 120, 'extra_field_seats' => 2],
            ['discount_percent' => 10, 'storage_limit_gb' => 80],
            ['discount_cents' => 1234, 'modules' => ['assistant']],
            ['price_override_cents' => 9900, 'discount_percent' => 5],
            [
                'package_key' => 'enterprise',
                'extra_field_seats' => 7,
                'extra_office_seats' => 3,
                'modules' => ['quotes', 'invoices', 'assistant'],
                'storage_limit_gb' => 500,
                'discount_percent' => 12,
                'coupon_discount_percent' => 8,
                'coupon_discount_until' => now()->addYear()->toDateString(),
            ],
        ];

        foreach ($cases as $index => $attributes) {
            $subscription = new TenantSubscription($this->tenant($attributes));

            $this->assertSame(
                $subscription->monthlyTotalCents(),
                array_sum(array_column($subscription->breakdown(), 'amount_cents')),
                'geval ' . $index . ' telt niet op tot de maandprijs',
            );
        }
    }

    public function test_a_discount_shows_up_as_its_own_negative_line(): void
    {
        $lines = (new TenantSubscription($this->tenant([
            'discount_percent' => 10,
            'coupon_discount_percent' => 5,
            'coupon_discount_until' => now()->addMonth()->toDateString(),
        ])))->breakdown();

        $discounts = array_values(array_filter($lines, fn ($line) => $line['kind'] === 'discount'));

        $this->assertCount(2, $discounts);
        $this->assertSame('Korting 10%', $discounts[0]['description']);
        $this->assertSame(-275, $discounts[0]['amount_cents']);
        $this->assertSame('Kortingsbon 5%', $discounts[1]['description']);
        $this->assertSame(-138, $discounts[1]['amount_cents']);
    }
}
