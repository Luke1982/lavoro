<?php

namespace App\Domain\Pricing;

/**
 * Every block a price formula can be built from: what flows in, what flows out
 * and what can be set on it. The editor draws its palette and its settings panel
 * from this, the validation checks a graph against it and the calculator works
 * each block out by its type, so a block exists in all three or in none.
 *
 * Two things flow along the wires: machines (a set of machines on the contract)
 * and numbers. A wire only joins two ends of the same kind. Several wires into
 * one input add up: numbers are summed, machines are put together.
 *
 * A number input with a fallback takes that setting while nothing is wired to
 * it, so "× 1,21" needs no separate block for the 1,21.
 *
 * Type names are stored in saved graphs; never rename one.
 */
final class NodeCatalog
{
    public const MACHINES = 'machines';

    public const NUMBER = 'number';

    /**
     * The settings that point at a record: the table it lives in, the name the
     * assistant writes it under (without "_id"), and what a person calls it.
     */
    public const REFERENCES = [
        'product_type' => ['table' => 'product_types', 'setting' => 'product_type', 'noun' => 'producttype'],
        'product' => ['table' => 'products', 'setting' => 'product', 'noun' => 'model'],
        'brand' => ['table' => 'brands', 'setting' => 'brand', 'noun' => 'merk'],
        'attribute' => ['table' => 'product_attributes', 'setting' => 'product_attribute', 'noun' => 'kenmerk'],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $definitions = null;

    public const CATEGORIES = [
        'source' => 'Bronnen',
        'filter' => 'Filters',
        'count' => 'Tellen',
        'math' => 'Rekenen',
        'result' => 'Resultaat',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return self::$definitions ??= self::build();
    }

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        $machines_in = [['id' => 'machines', 'label' => 'Machines', 'kind' => self::MACHINES]];
        $split_out = [
            ['id' => 'match', 'label' => 'Voldoet', 'kind' => self::MACHINES],
            ['id' => 'rest', 'label' => 'Overige', 'kind' => self::MACHINES],
        ];
        $value_out = [['id' => 'value', 'label' => 'Waarde', 'kind' => self::NUMBER]];

        return [
            'machines' => [
                'label' => 'Machines',
                'category' => 'source',
                'description' => 'Alle machines op het contract.',
                'inputs' => [],
                'outputs' => [['id' => 'machines', 'label' => 'Machines', 'kind' => self::MACHINES]],
                'fields' => [],
            ],
            'number' => [
                'label' => 'Bedrag of getal',
                'category' => 'source',
                'description' => 'Een vast bedrag of getal.',
                'inputs' => [],
                'outputs' => $value_out,
                'fields' => [['key' => 'value', 'label' => 'Waarde', 'type' => 'number', 'default' => 0]],
            ],
            'input' => [
                'label' => 'Invoer per contract',
                'category' => 'source',
                'description' => 'Een getal dat je per contract zelf invult, zoals een afgesproken korting of een aantal extra bezoeken.',
                'inputs' => [],
                'outputs' => $value_out,
                'fields' => [
                    ['key' => 'name', 'label' => 'Naam op het contract', 'type' => 'text', 'default' => 'Invoer'],
                    ['key' => 'default', 'label' => 'Standaardwaarde', 'type' => 'number', 'default' => 0],
                ],
            ],
            'contract' => [
                'label' => 'Contract',
                'category' => 'source',
                'description' => 'Hoe vaak per jaar de klant betaalt, en de looptijd van het contract in maanden (zonder einddatum: 12).',
                'inputs' => [],
                'outputs' => [
                    ['id' => 'periods', 'label' => 'Prijsperiodes per jaar', 'kind' => self::NUMBER],
                    ['id' => 'months', 'label' => 'Looptijd in maanden', 'kind' => self::NUMBER],
                ],
                'fields' => [],
            ],
            'filter_type' => [
                'label' => 'Filter op type',
                'category' => 'filter',
                'description' => 'Haalt de machines van één producttype uit de rest; de types eronder tellen mee.',
                'inputs' => $machines_in,
                'outputs' => $split_out,
                'fields' => [['key' => 'product_type_id', 'label' => 'Producttype', 'type' => 'product_type']],
            ],
            'filter_model' => [
                'label' => 'Filter op model',
                'category' => 'filter',
                'description' => 'Haalt de machines van één model uit de catalogus uit de rest.',
                'inputs' => $machines_in,
                'outputs' => $split_out,
                'fields' => [['key' => 'product_id', 'label' => 'Model', 'type' => 'product']],
            ],
            'filter_brand' => [
                'label' => 'Filter op merk',
                'category' => 'filter',
                'description' => 'Haalt de machines van één merk uit de rest.',
                'inputs' => $machines_in,
                'outputs' => $split_out,
                'fields' => [['key' => 'brand_id', 'label' => 'Merk', 'type' => 'brand']],
            ],
            'filter_attribute' => [
                'label' => 'Filter op kenmerk',
                'category' => 'filter',
                'description' => 'Haalt de machines met een bepaald kenmerk uit de rest, zoals een vermogen van 10 kW of meer.',
                'inputs' => $machines_in,
                'outputs' => $split_out,
                'fields' => [
                    ['key' => 'product_attribute_id', 'label' => 'Kenmerk', 'type' => 'attribute'],
                    ['key' => 'operator', 'label' => 'Vergelijking', 'type' => 'select', 'default' => '>=',
                        'options' => self::comparisons() + ['contains' => 'bevat']],
                    ['key' => 'value', 'label' => 'Waarde', 'type' => 'text', 'default' => ''],
                ],
            ],
            'combination' => [
                'label' => 'Combinatie',
                'category' => 'filter',
                'description' => 'Zoekt machines die samen op één adres staan, zoals een compressor met een droger, voor een pakketprijs.',
                'inputs' => $machines_in,
                'outputs' => [
                    ['id' => 'count', 'label' => 'Aantal combinaties', 'kind' => self::NUMBER],
                    ['id' => 'used', 'label' => 'In een combinatie', 'kind' => self::MACHINES],
                    ['id' => 'rest', 'label' => 'Overige', 'kind' => self::MACHINES],
                ],
                'fields' => [
                    ['key' => 'components', 'label' => 'Bestaat uit', 'type' => 'components', 'default' => []],
                ],
            ],
            'count' => [
                'label' => 'Aantal',
                'category' => 'count',
                'description' => 'Telt de machines die je erop aansluit.',
                'inputs' => $machines_in,
                'outputs' => $value_out,
                'fields' => [],
            ],
            'addresses' => [
                'label' => 'Aantal adressen',
                'category' => 'count',
                'description' => 'Telt op hoeveel verschillende adressen die machines staan.',
                'inputs' => $machines_in,
                'outputs' => $value_out,
                'fields' => [],
            ],
            'catalog_value' => [
                'label' => 'Catalogusprijs',
                'category' => 'count',
                'description' => 'Telt de catalogusprijzen van die machines bij elkaar op.',
                'inputs' => $machines_in,
                'outputs' => $value_out,
                'fields' => [[
                    'key' => 'field', 'label' => 'Welke prijs', 'type' => 'select', 'default' => 'retail_price',
                    'options' => ['retail_price' => 'Verkoopprijs', 'purchase_price' => 'Inkoopprijs'],
                ]],
            ],
            'visits' => [
                'label' => 'Onderhoudsbeurten per jaar',
                'category' => 'count',
                'description' => 'Telt hoe vaak die machines samen per jaar onderhoud krijgen, volgens het contract.',
                'inputs' => $machines_in,
                'outputs' => $value_out,
                'fields' => [],
            ],
            'tiers' => [
                'label' => 'Prijs per stuk',
                'category' => 'math',
                'description' => 'Een bedrag per stuk, eventueel met een staffel.',
                'inputs' => [['id' => 'quantity', 'label' => 'Aantal', 'kind' => self::NUMBER]],
                'outputs' => [['id' => 'value', 'label' => 'Bedrag', 'kind' => self::NUMBER]],
                'fields' => [
                    ['key' => 'amount', 'label' => 'Per stuk', 'type' => 'number', 'default' => 0],
                    ['key' => 'steps', 'label' => 'Staffel', 'type' => 'tiers', 'default' => []],
                    ['key' => 'mode', 'label' => 'Staffel geldt voor', 'type' => 'select', 'default' => 'graduated',
                        'options' => [
                            'graduated' => 'alleen de stuks vanaf die stap',
                            'volume' => 'alle stuks, zodra die stap is bereikt',
                        ]],
                ],
            ],
            'sum' => [
                'label' => 'Optellen',
                'category' => 'math',
                'description' => 'Telt alles op wat je erop aansluit.',
                'inputs' => [['id' => 'values', 'label' => 'Waarden', 'kind' => self::NUMBER]],
                'outputs' => [['id' => 'value', 'label' => 'Totaal', 'kind' => self::NUMBER]],
                'fields' => [],
            ],
            'multiply' => self::twoNumbers(
                'Vermenigvuldigen',
                'Vermenigvuldigt A met B, bijvoorbeeld een aantal met een prijs.',
                'Keer',
                1,
            ),
            'divide' => self::twoNumbers(
                'Delen',
                'Deelt A door B, bijvoorbeeld een jaarprijs door het aantal prijsperiodes.',
                'Gedeeld door',
                1,
            ),
            'subtract' => self::twoNumbers(
                'Aftrekken',
                'Trekt B van A af, bijvoorbeeld een vaste korting.',
                'Min',
                0,
            ),
            'minimum' => self::twoNumbers(
                'Minimaal',
                'Nooit minder dan B: je krijgt A, of B als A lager is. Handig voor een minimumprijs.',
                'Minimaal',
                0,
            ),
            'maximum' => self::twoNumbers(
                'Maximaal',
                'Nooit meer dan B: je krijgt A, of B als A hoger is. Handig voor een maximumprijs.',
                'Maximaal',
                0,
            ),
            'percentage' => [
                'label' => 'Korting of toeslag',
                'category' => 'math',
                'description' => 'Telt een percentage op of trekt het af: met een min ervoor is het korting.',
                'inputs' => [['id' => 'value', 'label' => 'Bedrag', 'kind' => self::NUMBER]],
                'outputs' => [
                    ['id' => 'result', 'label' => 'Met percentage', 'kind' => self::NUMBER],
                    ['id' => 'part', 'label' => 'Alleen het percentage', 'kind' => self::NUMBER],
                ],
                'fields' => [['key' => 'percent', 'label' => 'Percentage', 'type' => 'number', 'default' => -10]],
            ],
            'round' => [
                'label' => 'Afronden',
                'category' => 'math',
                'description' => 'Maakt van een krom bedrag een rond bedrag, zoals op hele euro\'s of op 5 euro.',
                'inputs' => [['id' => 'value', 'label' => 'Bedrag', 'kind' => self::NUMBER]],
                'outputs' => $value_out,
                'fields' => [
                    ['key' => 'step', 'label' => 'Op', 'type' => 'select', 'default' => '1',
                        'options' => [
                            '0.05' => '5 cent', '1' => '1 euro', '5' => '5 euro',
                            '10' => '10 euro', '50' => '50 euro', '100' => '100 euro',
                        ]],
                    ['key' => 'direction', 'label' => 'Richting', 'type' => 'select', 'default' => 'nearest',
                        'options' => ['nearest' => 'naar het dichtstbijzijnde bedrag', 'up' => 'naar boven', 'down' => 'naar beneden']],
                ],
            ],
            'condition' => [
                'label' => 'Als … dan',
                'category' => 'math',
                'description' => 'Vergelijkt twee getallen. Klopt het, dan krijg je de waarde bij Dan, anders die bij Anders.',
                'inputs' => [
                    ['id' => 'value', 'label' => 'Als', 'kind' => self::NUMBER],
                    ['id' => 'compare', 'label' => 'Vergeleken met', 'kind' => self::NUMBER, 'fallback' => 'compare'],
                    ['id' => 'then', 'label' => 'Dan', 'kind' => self::NUMBER, 'fallback' => 'then'],
                    ['id' => 'else', 'label' => 'Anders', 'kind' => self::NUMBER, 'fallback' => 'else'],
                ],
                'outputs' => $value_out,
                'fields' => [
                    ['key' => 'operator', 'label' => 'Vergelijking', 'type' => 'select', 'default' => '>=',
                        'options' => self::comparisons()],
                    ['key' => 'compare', 'label' => 'Vergeleken met', 'type' => 'number', 'default' => 0],
                    ['key' => 'then', 'label' => 'Dan', 'type' => 'number', 'default' => 0],
                    ['key' => 'else', 'label' => 'Anders', 'type' => 'number', 'default' => 0],
                ],
            ],
            'output_periodic' => [
                'label' => 'Prijs per periode',
                'category' => 'result',
                'description' => 'Wat het contract per prijsperiode kost: per maand, per halfjaar of per jaar, net als op het contract.',
                'inputs' => [['id' => 'value', 'label' => 'Bedrag', 'kind' => self::NUMBER]],
                'outputs' => [],
                'fields' => [],
            ],
            'output_one_time' => [
                'label' => 'Eenmalige kosten',
                'category' => 'result',
                'description' => 'Wat de klant één keer betaalt, los van de prijs per periode, zoals opstartkosten.',
                'inputs' => [['id' => 'value', 'label' => 'Bedrag', 'kind' => self::NUMBER]],
                'outputs' => [],
                'fields' => [],
            ],
        ];
    }

    /**
     * A block that works two numbers into one. B takes its setting while no wire
     * comes in, so "keer vijfendertig" is one block.
     *
     * @return array<string, mixed>
     */
    private static function twoNumbers(string $label, string $description, string $b_label, int|float $b_default): array
    {
        return [
            'label' => $label,
            'category' => 'math',
            'description' => $description,
            'inputs' => [
                ['id' => 'a', 'label' => 'A', 'kind' => self::NUMBER],
                ['id' => 'b', 'label' => $b_label, 'kind' => self::NUMBER, 'fallback' => 'b'],
            ],
            'outputs' => [['id' => 'value', 'label' => 'Uitkomst', 'kind' => self::NUMBER]],
            'fields' => [['key' => 'b', 'label' => $b_label, 'type' => 'number', 'default' => $b_default]],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $type): ?array
    {
        return self::definitions()[$type] ?? null;
    }

    /**
     * Every product a graph names, in a block or in a part of a combination.
     *
     * @return array<int, int>
     */
    public static function productIdsIn(array $graph): array
    {
        return collect($graph['nodes'] ?? [])
            ->flatMap(fn (array $node) => [
                $node['data']['product_id'] ?? null,
                ...array_column($node['data']['components'] ?? [], 'product_id'),
            ])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{categories: array<string, string>, types: array<int, array<string, mixed>>} */
    public static function forEditor(): array
    {
        $types = [];

        foreach (self::definitions() as $type => $definition) {
            $types[] = ['type' => $type, ...$definition];
        }

        return ['categories' => self::CATEGORIES, 'types' => $types];
    }

    /** @return array<string, string> */
    public static function comparisons(): array
    {
        return ['>=' => '≥', '>' => '>', '=' => '=', '!=' => '≠', '<' => '<', '<=' => '≤'];
    }

    /**
     * A graph to start a new formula from: every machine at one price, so the
     * page opens on something that works and shows how the blocks connect.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function starterGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 'machines', 'type' => 'machines', 'position' => ['x' => 0, 'y' => 80], 'data' => []],
                ['id' => 'count', 'type' => 'count', 'position' => ['x' => 340, 'y' => 80], 'data' => []],
                ['id' => 'price', 'type' => 'tiers', 'position' => ['x' => 680, 'y' => 60],
                    'data' => ['amount' => 50, 'steps' => [], 'mode' => 'graduated']],
                ['id' => 'periodic', 'type' => 'output_periodic', 'position' => ['x' => 1020, 'y' => 80], 'data' => []],
            ],
            'edges' => [
                self::starterEdge('e1', 'machines', 'machines', 'count', 'machines'),
                self::starterEdge('e2', 'count', 'value', 'price', 'quantity'),
                self::starterEdge('e3', 'price', 'value', 'periodic', 'value'),
            ],
        ];
    }

    /** @return array<string, string> */
    private static function starterEdge(string $id, string $source, string $from, string $target, string $to): array
    {
        return ['id' => $id, 'source' => $source, 'sourceHandle' => $from, 'target' => $target, 'targetHandle' => $to];
    }
}
