<?php

namespace Tests\Feature\Landlord;

use App\Models\Tenant;
use App\Services\Invoicer;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * Het abonnementsscherm moet teruggeven wat erin staat.
 *
 * De ingangsdatum ging door optional()->format() heen, en optional() op tekst
 * in plaats van een object geeft null terug. Het veld kwam dus altijd leeg in
 * beeld, en de eerste de beste keer opslaan schreef die leegte terug: de
 * ingangsdatum weg, en daarmee werd er voor die klant nooit meer iets
 * gefactureerd. Stil, zonder fout, en pas weken later te zien.
 */
class SubscriptionScreenTest extends TestCase
{
    use MakesLandlordData;

    private function tenant(array $attributes = []): Tenant
    {
        return $this->tenantRow($attributes);
    }

    private function form(Tenant $tenant, array $overrides = []): array
    {
        return [
            'package_key' => $tenant->package_key,
            'billing_period' => $tenant->billing_period,
            'subscription_started_on' => $tenant->subscription_started_on,
            'modules' => [],
            'payment_method' => 'transfer',
            'extra_field_seats' => 0,
            'extra_office_seats' => 0,
            'storage_limit_gb' => 50,
            'discount_type' => 'none',
            ...$overrides,
        ];
    }

    /**
     * Het formulier zoals het scherm het opstuurt. Dit loopt gelijk met
     * SubscriptionForm.vue: centen worden euro's in het veld, en of er korting
     * in euro's of in procenten geldt leidt het scherm af uit wat er staat.
     */
    private function screenPayload(Tenant $tenant, array $overrides = []): array
    {
        $tenant = $tenant->fresh();
        $euro = fn (?int $cents) => ($cents === null || $cents === 0)
            ? ''
            : number_format($cents / 100, 2, '.', '');

        return [
            'subscription_started_on' => $tenant->subscription_started_on,
            'billing_period' => $tenant->billing_period === 'yearly' ? 'yearly' : 'monthly',
            'package_key' => $tenant->package_key ?? '',
            'extra_field_seats' => (int) $tenant->extra_field_seats,
            'extra_office_seats' => (int) $tenant->extra_office_seats,
            'storage_limit_gb' => (int) $tenant->storage_limit_gb,
            'ai_allowance_euro' => $tenant->ai_allowance_micros === null
                ? ''
                : $euro((int) round($tenant->ai_allowance_micros / 10_000)),
            'discount_type' => $tenant->discount_percent
                ? 'percent'
                : ($tenant->discount_cents ? 'euro' : 'none'),
            'discount_euro' => $euro($tenant->discount_cents),
            'discount_percent' => $tenant->discount_percent ?: '',
            'price_override_euro' => $euro($tenant->price_override_cents),
            'invoice_address' => $tenant->invoice_address ?? '',
            'invoice_email' => $tenant->invoice_email ?? '',
            'invoice_postcode' => $tenant->invoice_postcode ?? '',
            'invoice_city' => $tenant->invoice_city ?? '',
            'vat_number' => $tenant->vat_number ?? '',
            'coc_number' => $tenant->coc_number ?? '',
            'payment_method' => $tenant->payment_method === 'direct_debit' ? 'direct_debit' : 'transfer',
            'iban' => $tenant->iban ?? '',
            'account_holder' => $tenant->account_holder ?? '',
            'mandate_reference' => $tenant->mandate_reference ?? '',
            'mandate_signed_on' => $tenant->mandate_signed_on ?? '',
            'modules' => $tenant->modules ?? [],
            'module_prices' => collect($tenant->module_prices ?? [])->map($euro)->all(),
            ...$overrides,
        ];
    }

    private function filled(): Tenant
    {
        return $this->tenant([
            'package_key' => 'team',
            'billing_period' => 'yearly',
            'subscription_started_on' => '2026-04-15',
            'extra_field_seats' => 3,
            'extra_office_seats' => 2,
            'storage_limit_gb' => 120,
            'modules' => ['quotes', 'assistant'],
            'module_prices' => ['assistant' => 1500],
            'ai_allowance_micros' => 33_750_000,
            'price_override_cents' => 12345,
            'discount_percent' => 7,
            'invoice_address' => 'Straatweg 1',
            'invoice_email' => 'fact@klant.nl',
            'invoice_postcode' => '1234 AB',
            'invoice_city' => 'Amsterdam',
            'vat_number' => 'NL001234567B01',
            'coc_number' => '12345678',
            'payment_method' => 'direct_debit',
            'iban' => 'NL91ABNA0417164300',
            'account_holder' => 'Snelweg BV',
            'mandate_reference' => 'LVR-9',
            'mandate_signed_on' => '2026-02-02',
        ]);
    }

    private function save(Tenant $tenant, array $overrides = [])
    {
        return $this->actingAs($this->landlord(), 'landlord')
            ->put(route('landlord.update', $tenant->id), $this->screenPayload($tenant, $overrides));
    }

    public function test_the_screen_shows_the_start_date_that_is_stored(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.edit', $tenant->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Landlord/EditPage')
                ->where('tenant.subscription_started_on', '2026-03-01'));
    }

    /**
     * Het scherm opslaan zoals het erbij staat, zonder iets aan te raken, mag
     * niets weggooien. Precies daar ging het mis.
     */
    public function test_saving_the_screen_unchanged_keeps_the_start_date(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        $shown = $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.edit', $tenant->id))
            ->viewData('page')['props']['tenant']['subscription_started_on'];

        $this->actingAs($this->landlord(), 'landlord')
            ->put(route('landlord.update', $tenant->id), $this->form($tenant, [
                'subscription_started_on' => $shown,
            ]))
            ->assertRedirect();

        $this->assertSame('2026-03-01', $tenant->fresh()->subscription_started_on);
    }

    public function test_a_changed_start_date_is_stored_and_shown_again(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        $this->actingAs($this->landlord(), 'landlord')
            ->put(route('landlord.update', $tenant->id), $this->form($tenant, [
                'subscription_started_on' => '2026-05-09',
            ]))
            ->assertRedirect();

        $this->assertSame('2026-05-09', $tenant->fresh()->subscription_started_on);

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.edit', $tenant->id))
            ->assertInertia(fn ($page) => $page->where('tenant.subscription_started_on', '2026-05-09'));
    }

    public function test_a_customer_without_a_start_date_is_flagged_in_the_overview(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => null]);

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($tenant) {
                $rows = collect($page->toArray()['props']['rows']);
                $mine = $rows->firstWhere('id', $tenant->id);

                $this->assertNotNull($mine, 'de klant hoort in het overzicht te staan');
                $this->assertNull($mine['starts_on'], 'het overzicht moet kunnen zien dat er geen datum is');
            });
    }

    /**
     * Het scherm opslaan zonder iets aan te raken hoort niets te veranderen.
     * Aan een van de velden bleek dat niet te kloppen, en dan is er geen enkele
     * reden om aan te nemen dat het bij de rest wel goed zit.
     */
    public function test_saving_the_whole_screen_unchanged_changes_nothing(): void
    {
        $tenant = $this->filled();

        $columns = [
            'package_key', 'billing_period', 'subscription_started_on', 'extra_field_seats',
            'extra_office_seats', 'storage_limit_gb', 'modules', 'module_prices', 'ai_allowance_micros',
            'price_override_cents', 'discount_cents', 'discount_percent', 'invoice_address',
            'invoice_email', 'invoice_postcode', 'invoice_city', 'vat_number', 'coc_number',
            'payment_method', 'iban', 'account_holder', 'mandate_reference', 'mandate_signed_on',
        ];

        $before = collect($tenant->fresh()->getAttributes())->only($columns)->all();

        $this->save($tenant)->assertRedirect();

        $this->assertSame($before, collect($tenant->fresh()->getAttributes())->only($columns)->all());
    }

    public function test_a_discount_in_euros_replaces_one_in_percent(): void
    {
        $tenant = $this->filled();

        $this->save($tenant, ['discount_type' => 'euro', 'discount_euro' => '25.00'])->assertRedirect();

        $this->assertSame(2500, $tenant->fresh()->discount_cents);
        $this->assertNull($tenant->fresh()->discount_percent);
    }

    public function test_no_discount_clears_both_kinds(): void
    {
        $tenant = $this->filled();

        $this->save($tenant, ['discount_type' => 'none'])->assertRedirect();

        $this->assertNull($tenant->fresh()->discount_cents);
        $this->assertNull($tenant->fresh()->discount_percent);
    }

    public function test_clearing_the_agreed_price_brings_back_the_build_up(): void
    {
        $tenant = $this->filled();

        $this->save($tenant, ['price_override_euro' => ''])->assertRedirect();

        $this->assertNull($tenant->fresh()->price_override_cents);
    }

    public function test_clearing_the_ai_allowance_returns_to_the_standard_one(): void
    {
        $tenant = $this->filled();

        $this->save($tenant, ['ai_allowance_euro' => ''])->assertRedirect();

        $this->assertNull($tenant->fresh()->ai_allowance_micros);

        $this->save($tenant->fresh(), ['ai_allowance_euro' => '40.00'])->assertRedirect();

        $this->assertSame(40_000_000, $tenant->fresh()->ai_allowance_micros);
    }

    public function test_modules_can_be_added_and_taken_away(): void
    {
        $tenant = $this->filled();

        $this->save($tenant, ['modules' => ['quotes', 'invoices', 'assistant']])->assertRedirect();
        $this->assertSame(['quotes', 'invoices', 'assistant'], $tenant->fresh()->modules);

        $this->save($tenant->fresh(), ['modules' => []])->assertRedirect();
        $this->assertSame([], $tenant->fresh()->modules);
    }

    public function test_direct_debit_needs_its_mandate_details(): void
    {
        $tenant = $this->filled();

        $this->save($tenant, ['iban' => '', 'mandate_reference' => '', 'mandate_signed_on' => ''])
            ->assertSessionHasErrors(['iban', 'mandate_reference', 'mandate_signed_on']);

        $this->assertSame('NL91ABNA0417164300', $tenant->fresh()->iban);
    }

    public function test_switching_back_to_a_transfer_keeps_the_bank_details_on_file(): void
    {
        $tenant = $this->filled();

        $this->save($tenant, ['payment_method' => 'transfer'])->assertRedirect();

        $this->assertSame('transfer', $tenant->fresh()->payment_method);
        $this->assertSame('NL91ABNA0417164300', $tenant->fresh()->iban);
    }

    public function test_a_module_keeps_the_price_that_was_agreed_for_it(): void
    {
        $tenant = $this->filled();

        $this->save($tenant, ['module_prices' => ['assistant' => '9.95', 'quotes' => '']])->assertRedirect();

        $this->assertSame(['assistant' => 995], $tenant->fresh()->module_prices);
    }

    /**
     * Een prijs hoort bij een module die de klant heeft. Gaat de module eruit,
     * dan hoort de afspraak niet te blijven staan om bij het weer aanzetten
     * stilletjes terug te komen.
     */
    public function test_taking_a_module_away_takes_its_agreed_price_with_it(): void
    {
        $tenant = $this->filled();

        $this->assertSame(['assistant' => 1500], $tenant->module_prices);

        $this->save($tenant, ['modules' => ['quotes']])->assertRedirect();

        $this->assertSame([], $tenant->fresh()->module_prices);
    }

    /**
     * Een verrekening hoort bij een pakketwissel, niet bij een uitbreiding.
     *
     * Wie er een module bij neemt heeft niets gewisseld: die module gaat mee
     * met de eerstvolgende factuur. Een regel die uitrekent hoeveel dagen hij
     * de module al had, maakt de factuur onleesbaar voor een paar euro.
     */
    public function test_adding_a_module_settles_nothing(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $this->save($tenant, ['modules' => ['assistant']])->assertRedirect();

        $this->assertCount(0, (new Invoicer($tenant))->pendingCharges());
    }

    public function test_agreeing_a_price_for_a_module_settles_nothing(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-09-01',
            'modules' => ['assistant'],
        ]);

        $this->save($tenant, ['module_prices' => ['assistant' => '15.00']])->assertRedirect();

        $this->assertSame(['assistant' => 1500], $tenant->fresh()->module_prices);
        $this->assertCount(0, (new Invoicer($tenant))->pendingCharges());
    }

    public function test_extra_seats_and_storage_settle_nothing(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $this->save($tenant, ['extra_field_seats' => 3, 'storage_limit_gb' => 200])->assertRedirect();

        $this->assertCount(0, (new Invoicer($tenant))->pendingCharges());
    }

    public function test_changing_the_package_does_settle(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $this->save($tenant, ['package_key' => 'team'])->assertRedirect();

        $charges = (new Invoicer($tenant))->pendingCharges();

        $this->assertCount(1, $charges);
        $this->assertStringContainsString('Starter naar Team', $charges->first()->description);
    }

    /**
     * Een andere prijs voor hetzelfde pakket is ook een pakketwijziging: de
     * abonnementsregel op de factuur verandert erdoor.
     */
    public function test_changing_the_agreed_package_price_does_settle(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $this->save($tenant, ['price_override_euro' => '10.00'])->assertRedirect();

        $this->assertCount(1, (new Invoicer($tenant))->pendingCharges());
    }

    /** Een pakketwissel en een module in een keer verrekent alleen het pakket. */
    public function test_a_package_switch_with_a_module_settles_only_the_package(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $this->save($tenant, ['package_key' => 'team', 'modules' => ['assistant']])->assertRedirect();

        $charge = (new Invoicer($tenant))->pendingCharges()->first();

        $this->assertSame(-(int) round((8750 - 2750) * 6 / 30), (int) $charge->amount_cents);
    }

    public function test_switching_a_module_on_records_the_day_it_started(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $this->save($tenant, ['modules' => ['assistant']])->assertRedirect();

        $this->assertSame(
            [Carbon::now()->toDateString()],
            array_values($tenant->fresh()->module_started_on),
        );
    }

    public function test_a_module_that_stays_keeps_the_day_it_started(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-09-01',
            'modules' => ['assistant'],
            'module_started_on' => ['assistant' => '2026-06-12'],
        ]);

        $this->save($tenant, ['modules' => ['assistant', 'quotes']])->assertRedirect();

        $dates = $tenant->fresh()->module_started_on;

        $this->assertSame('2026-06-12', $dates['assistant'], 'die stond er al');
        $this->assertSame(Carbon::now()->toDateString(), $dates['quotes'], 'deze is nieuw');
    }

    /** Eruit en er weer in telt opnieuw: anders zou hij met terugwerkende kracht gratis zijn. */
    public function test_switching_a_module_off_forgets_when_it_started(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-09-01',
            'modules' => ['assistant'],
            'module_started_on' => ['assistant' => '2026-06-12'],
        ]);

        $this->save($tenant, ['modules' => []])->assertRedirect();

        $this->assertSame([], $tenant->fresh()->module_started_on);
    }
}
