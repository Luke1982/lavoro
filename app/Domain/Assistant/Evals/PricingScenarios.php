<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\Evals\Scenarios\CaseScenario;
use App\Domain\Pricing\CompactFormula;
use App\Domain\Pricing\ContractPricing;
use App\Models\Customer;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;

/**
 * Price-formula questions. Whatever the assistant builds or quotes is checked by
 * running the formula through the same evaluator the contracts use, so a right
 * answer is a right number, not a plausible-sounding one. Amounts are compared
 * in whole cents.
 */
final class PricingScenarios
{
    private const AIRCO_TYPES = [
        'Binnendeel wandmodel', 'Binnendeel cassette', 'Binnendeel vloermodel', 'Binnendeel multi split', 'Binnendeel kanaalmodel',
        'Buitendeel single split', 'Buitendeel multi split', 'Buitendeel VRF',
    ];

    /** One indoor and one outdoor unit at one address: the smallest airco there is. */
    private const PAIR = [
        ['quantity' => 1, 'product_type' => 'Binnendeel wandmodel', 'address' => 1],
        ['quantity' => 1, 'product_type' => 'Buitendeel single split', 'address' => 1],
    ];

    /** @return array<int, EvalScenario> */
    public static function all(): array
    {
        return [
            new CaseScenario(
                name: 'nieuwe-formule',
                suite: 'prijzen',
                role: 'admin',
                needs: ['product_types' => self::AIRCO_TYPES],
                opening: 'Maak een prijsformule "Airco onderhoud 2027": €85 per airco-binnendeel per jaar, vanaf het 5e binnendeel €70, en €35 voorrijkosten per adres.',
                persona: implode("\n", [
                    'Je wilt de formule zoals gevraagd. Met "vanaf het 5e binnendeel €70" bedoel je een staffel: alleen het 5e en verdere stuks kosten €70, de eerste vier €85.',
                    'Binnendelen zijn alle soorten airco-binnendeel (wandmodel, cassette, vloermodel, multi split, kanaalmodel). Buitendelen en andere machines kosten niets.',
                    'Prijzen zijn per jaar, excl. btw. Opslaan mag. Is de formule opgeslagen, dan ben je klaar.',
                ]),
                rubric: ['Rekent de formule eerst door op een voorbeeld voordat hij voorstelt op te slaan, en laat dat voorbeeld zien.'],
                checks: function (EvalTranscript $transcript) {
                    $saved = $transcript->executedOf('save_price_formula');
                    $graph = self::savedGraph($saved);

                    return [
                        Check::that('formule opgeslagen', count($saved) === 1, count($saved) . ' keer opgeslagen'),
                        self::costs('3 wandmodellen op 1 adres = €290', $graph, [['quantity' => 3, 'product_type' => 'Binnendeel wandmodel', 'address' => 1]], 29000),
                        self::costs('4 wandmodel + 3 cassette op 2 adressen = €620 (staffel)', $graph, [
                            ['quantity' => 4, 'product_type' => 'Binnendeel wandmodel', 'address' => 1],
                            ['quantity' => 3, 'product_type' => 'Binnendeel cassette', 'address' => 2],
                        ], 62000),
                        self::costs('buitendelen kosten niets: 2+2 op 1 adres = €205', $graph, [
                            ['quantity' => 2, 'product_type' => 'Binnendeel wandmodel', 'address' => 1],
                            ['quantity' => 2, 'product_type' => 'Buitendeel single split', 'address' => 1],
                        ], 20500),
                        Check::that(
                            'doorgerekend vóór het opslaan',
                            ($transcript->firstCallOf('try_price_formula') ?? PHP_INT_MAX) < ($transcript->firstCallOf('save_price_formula') ?? -1),
                            implode(', ', $transcript->toolNames()),
                        ),
                    ];
                },
            ),

            new CaseScenario(
                name: 'wat-kost-het',
                suite: 'prijzen',
                role: 'admin',
                needs: ['formulas' => ['Airco standaard'], 'product_types' => ['Binnendeel wandmodel', 'Monoblock warmtepomp']],
                opening: 'Wat kost de formule Airco standaard per jaar voor een klant met 6 airco-wandmodellen en 2 monoblock warmtepompen op één adres?',
                persona: 'Je wilt alleen het bedrag per jaar weten, en wat er eenmalig bij komt. Krijg je dat, dan ben je klaar.',
                checks: function (EvalTranscript $transcript) {
                    $truth = self::price(self::graphNamed('Airco standaard'), [
                        ['quantity' => 6, 'product_type' => 'Binnendeel wandmodel', 'address' => 1],
                        ['quantity' => 2, 'product_type' => 'Monoblock warmtepomp', 'address' => 1],
                    ]);
                    $answer = $transcript->answers();

                    return [
                        Check::that('doorgerekend met de formule', $transcript->called('try_price_formula'), implode(', ', $transcript->toolNames())),
                        Check::that(
                            'noemt het juiste bedrag',
                            $truth !== null && Euro::mentionedIn($answer, $truth),
                            'verwacht ' . Euro::ofCents($truth) . '; antwoord: ' . mb_substr($answer, 0, 300),
                        ),
                        Check::that('niets opgeslagen of gewijzigd', $transcript->executed === [], count($transcript->executed) . ' actie(s)'),
                    ];
                },
            ),

            new CaseScenario(
                name: 'toepassen',
                suite: 'prijzen',
                role: 'admin',
                needs: ['customers' => ['Hotel Rivierzicht'], 'formulas' => ['Airco standaard']],
                unavailable: fn () => self::contractsOf('Hotel Rivierzicht') === [] ? 'Hotel Rivierzicht heeft hier geen onderhoudscontracten' : null,
                prepare: fn () => ['contracts' => self::contractsOf('Hotel Rivierzicht')],
                opening: 'Zet de formule Airco standaard op alle onderhoudscontracten van Hotel Rivierzicht.',
                persona: 'Ja, toepassen mag, op alle contracten van Hotel Rivierzicht. Is het gedaan, of zegt de assistent dat er niets toe te passen is, dan ben je klaar.',
                rubric: ['Laat vóór de bevestiging per contract de oude en de nieuwe prijs zien.'],
                checks: function (EvalTranscript $transcript, array $state) {
                    $applied = $transcript->executedOf('apply_price_formula');
                    $ids = array_map('intval', $applied[0]['arguments']['contract_ids'] ?? []);
                    sort($ids);

                    return [
                        Check::that('één keer toegepast', count($applied) === 1, count($applied) . ' keer'),
                        Check::that('op precies de contracten van Hotel Rivierzicht', $ids === $state['contracts'], EvalTranscript::text($ids) . ' i.p.v. ' . EvalTranscript::text($state['contracts'])),
                    ];
                },
            ),

            new CaseScenario(
                name: 'uitleg',
                suite: 'prijzen',
                role: 'admin',
                needs: ['formulas' => ['Airco standaard']],
                opening: 'Leg kort uit hoe de formule Airco standaard rekent.',
                persona: 'Je wilt een korte uitleg van hoe de formule rekent. Krijg je die, dan ben je klaar.',
                rubric: ['Klopt de uitleg met de blokken die price_formulas teruggaf: geen bedragen, kortingen of regels die er niet in staan.'],
                checks: function (EvalTranscript $transcript) {
                    $answer = $transcript->answers();
                    $tiers = self::tiers(self::graphNamed('Airco standaard'));
                    $missing = array_filter($tiers, fn (?int $cents) => $cents === null || !Euro::mentionedIn($answer, $cents));
                    $wrong_links = self::formulaLinksElsewhere($answer, 'Airco standaard');

                    return [
                        Check::that('formule gelezen', $transcript->called('price_formulas'), implode(', ', $transcript->toolNames())),
                        Check::that(
                            'noemt de bedragen van de staffel in de formule',
                            $tiers !== [] && $missing === [],
                            $tiers === [] ? 'de formule heeft geen staffel' : 'mist ' . implode(', ', array_map(fn (?int $cents) => Euro::ofCents($cents), $missing)),
                        ),
                        Check::that('links naar de formule gaan naar /priceformulas/', $wrong_links === [], implode(' ', $wrong_links)),
                    ];
                },
            ),

            /** The question as it was asked in the box: a screenshot of a price list, and one line. */
            new CaseScenario(
                name: 'prijslijst-foto',
                suite: 'prijzen',
                role: 'admin',
                needs: ['product_types' => [...self::AIRCO_TYPES, 'CV-ketel', 'Airconditioning']],
                unavailable: fn () => QuoteFixture::canDrawPictures() ? null : 'pdftoppm ontbreekt, het prijslijstje kan niet als plaatje gemaakt worden',
                opening: 'Maak hier een prijsformule van',
                files: [new EvalFile(
                    name: 'prijzen.png',
                    media_type: 'image/png',
                    path: fn () => QuoteFixture::tableImage([
                        ['Voorrijkosten', '€ 79,50', '€ 96,20'],
                        ['Binnen unit', '€ 100,50', '€ 121,61'],
                        ['Buiten unit', '€ 65,50', '€ 79,30'],
                        ['', '€ 245,50', ''],
                    ], 'prijslijst'),
                )],
                persona: implode("\n", [
                    'Je wilt een prijsformule voor airco-onderhoud uit dit prijslijstje, excl. btw. De bedragen gelden per onderhoudsbeurt, ook de voorrijkosten (per adres per beurt).',
                    'Binnenunits zijn alle soorten airco-binnendeel (wandmodel, cassette, vloermodel, multi split, kanaalmodel).',
                    'Buitenunits zijn alle soorten airco-buitendeel (single split, multi split, VRF).',
                    'Voorrijkosten één keer per adres. Andere machines dan airco tellen niet mee.',
                    'Een machine met alleen het soort Airconditioning, zonder binnen- of buitendeel, telt ook niet mee.',
                    'Noem je formule "Airco onderhoud". Opslaan mag. Is hij opgeslagen, dan ben je klaar.',
                ]),
                rubric: ['Vraagt niet naar losse producten als de vraag over soorten machines gaat.'],
                checks: function (EvalTranscript $transcript) {
                    $saved = $transcript->executedOf('save_price_formula');
                    $graph = self::savedGraph($saved);
                    $pickers = array_filter(array_merge(...array_column($transcript->turns, 'choices')), fn (array $choice) => self::offersProducts($choice));

                    return [
                        Check::that('geen keuze tussen losse producten voorgelegd', $pickers === [], implode(' | ', array_column($pickers, 'question'))),
                        Check::that('formule opgeslagen', count($saved) === 1, count($saved) . ' keer'),
                        self::costs('1 binnen + 1 buiten op 1 adres = €245,50', $graph, self::PAIR, 24550),
                        self::costs('2 adressen, 3 binnen + 2 buiten = €591,50', $graph, [
                            ['quantity' => 2, 'product_type' => 'Binnendeel cassette', 'address' => 1],
                            ['quantity' => 1, 'product_type' => 'Buitendeel multi split', 'address' => 1],
                            ['quantity' => 1, 'product_type' => 'Binnendeel wandmodel', 'address' => 2],
                            ['quantity' => 1, 'product_type' => 'Buitendeel single split', 'address' => 2],
                        ], 59150),
                        self::costs('per beurt: twee beurten per jaar kost het dubbele (€491,00)', $graph, self::PAIR, 49100, 'Jaarlijks', 'Halfjaarlijks'),
                        self::costs('per prijsperiode: maandelijks gefactureerd is een twaalfde (€20,46)', $graph, self::PAIR, 2046, 'Maandelijks', 'Jaarlijks'),
                        self::costs('een ander apparaat betaalt geen binnenunitprijs', $graph, [
                            ...self::PAIR,
                            ['quantity' => 1, 'product_type' => 'CV-ketel', 'address' => 1],
                            ['quantity' => 1, 'product_type' => 'Airconditioning', 'address' => 1],
                        ], 24550),
                    ];
                },
            ),
        ];
    }

    /**
     * The ids of a customer's maintenance contracts, in order. Without any the
     * scenario is skipped: "nothing to apply" is then the right answer, and
     * would have counted as a pass.
     *
     * @return array<int, int>
     */
    private static function contractsOf(string $customer): array
    {
        return MaintenanceContract::query()
            ->where('customer_id', Customer::query()->where('name', $customer)->value('id'))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** Whether a formula charges what it should for made-up machines. */
    private static function costs(string $name, ?array $graph, array $machines, int $expected_cents, string $price_interval = 'Jaarlijks', string $frequency = 'Jaarlijks'): Check
    {
        $cents = self::price($graph, $machines, $price_interval, $frequency);

        return Check::that($name, $cents === $expected_cents, $graph === null ? 'geen opgeslagen formule' : 'gaf ' . Euro::ofCents($cents));
    }

    /** What a formula charges per price period for made-up machines, in cents, as the contract screen would work it out. */
    private static function price(?array $graph, array $machines, string $price_interval = 'Jaarlijks', string $frequency = 'Jaarlijks'): ?int
    {
        $sample = app(CompactFormula::class)->sample($machines);

        if ($graph === null || $sample['problems'] !== []) {
            return null;
        }

        return Euro::cents(app(ContractPricing::class)->trial($graph, null, $sample['sample'], $price_interval, $frequency)['periodic']);
    }

    private static function savedGraph(array $saved): ?array
    {
        $id = EvalTranscript::idIn($saved[0] ?? null, ['formula_id']);

        return $id === null ? null : PriceFormula::query()->find($id)?->latestVersion?->graph;
    }

    private static function graphNamed(string $name): ?array
    {
        return PriceFormula::query()->where('name', $name)->first()?->latestVersion?->graph;
    }

    /**
     * The amounts of the first staffel in a formula: the price per piece, then
     * the price from each step on.
     *
     * @return array<int, ?int>
     */
    private static function tiers(?array $graph): array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? null) === 'tiers' && ($node['data']['steps'] ?? []) !== []) {
                return [
                    Euro::cents($node['data']['amount'] ?? null),
                    ...array_map(fn (array $step) => Euro::cents($step['amount'] ?? null), $node['data']['steps']),
                ];
            }
        }

        return [];
    }

    /**
     * Markdown links whose text names the formula but that lead somewhere other
     * than the formula's own page.
     *
     * @return array<int, string>
     */
    private static function formulaLinksElsewhere(string $answer, string $formula): array
    {
        preg_match_all('/\[([^\]]*)\]\(([^)\s]*)\)/u', $answer, $links, PREG_SET_ORDER);

        $about_formula = array_filter($links, fn (array $link) => mb_stripos($link[1], 'formule') !== false || mb_stripos($link[1], $formula) !== false);

        return array_values(array_map(
            fn (array $link) => $link[0],
            array_filter($about_formula, fn (array $link) => !str_starts_with($link[2], '/priceformulas/')),
        ));
    }

    /** A choice whose every option is a product record, whoever offered it. */
    private static function offersProducts(array $choice): bool
    {
        $options = $choice['options'] ?? [];

        return $options !== [] && array_filter($options, fn (array $option) => !str_starts_with((string) ($option['link'] ?? ''), '/products/')
            && preg_match('/^product #\d+$/i', (string) ($option['reference'] ?? '')) !== 1) === [];
    }
}
