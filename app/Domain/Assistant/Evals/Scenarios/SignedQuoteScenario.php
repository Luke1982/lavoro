<?php

namespace App\Domain\Assistant\Evals\Scenarios;

use App\Domain\Assistant\Evals\Check;
use App\Domain\Assistant\Evals\EvalFile;
use App\Domain\Assistant\Evals\EvalScenario;
use App\Domain\Assistant\Evals\EvalTranscript;
use App\Domain\Assistant\Evals\QuoteFixture;
use App\Domain\Planning\Clock;
use App\Models\Customer;
use App\Models\ProductType;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Carbon\CarbonImmutable;
use JsonException;

/**
 * A signed offerte dropped in the box, to be turned into a customer, a werkbon
 * with its tasks and machines, an appointment, a contract and a filed document —
 * the nine steps the system prompt lays out.
 *
 * One class for every offerte: what differs between them (the file, who the
 * customer is, what was sold, what the planner answers) is data, and the checks
 * are the same steps every time. What was made is read back from the database
 * before the rollback, not from what the model asked for.
 */
class SignedQuoteScenario extends EvalScenario
{
    private const NOT_PRODUCTS = '/goot|leiding|dakdoorvoer|voorrij|afval|beton|aanbetaling|montagemateriaal|onderhoudscontract/i';

    /** What the JSON beside a real offerte has to hold; contract may be null, but it has to be there. */
    private const FIXTURE_KEYS = [
        'file', 'opening', 'persona', 'quote_number', 'customer', 'products', 'type',
        'work_minutes', 'earliest_days', 'contract', 'keep_document', 'other_location',
    ];

    /** @var array<int, int> Product types that existed before the run. */
    private array $existing_types = [];

    private readonly array $case;

    /**
     * @param  array{
     *     name: string,
     *     file?: string,
     *     quote?: array,
     *     opening: string,
     *     persona: string,
     *     quote_number: string,
     *     customer: array{name: string, address: string, postal_code: string, city: string, existing?: bool},
     *     products: array<int, array{match: array<int, string>, quantity: int}>,
     *     type: string,
     *     work_minutes: int,
     *     earliest_days: int,
     *     contract: ?array{frequency: string, start_year: int},
     *     keep_document: bool,
     *     other_location: bool,
     *     work_address?: array{address: string, city: string},
     *     role?: string,
     * }|array{name: string, fixture: string}  $case  A real offerte under file, or a made-up one under quote that
     *                                              QuoteFixture prints when it is needed. Or only a name and a JSON
     *                                              file holding all of the rest, file relative to the JSON's folder:
     *                                              a real offerte names a real customer, and that stays out of git.
     */
    public function __construct(array $case)
    {
        $this->case = isset($case['fixture']) ? self::fromFixture($case) : $case;
    }

    /**
     * The case as the JSON beside a real offerte describes it, or only its name
     * and where that JSON should be. A JSON that cannot be read makes this one
     * scenario unavailable, said why, rather than every command fail on it.
     */
    private static function fromFixture(array $case): array
    {
        if (!is_file($case['fixture'])) {
            return $case;
        }

        try {
            $stored = json_decode((string) file_get_contents($case['fixture']), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [...$case, 'broken' => 'geen geldige JSON in ' . $case['fixture'] . ': ' . $e->getMessage()];
        }

        $missing = is_array($stored) ? array_diff(self::FIXTURE_KEYS, array_keys($stored)) : self::FIXTURE_KEYS;

        if ($missing !== []) {
            return [...$case, 'broken' => $case['fixture'] . ' mist ' . implode(', ', $missing)];
        }

        if (!is_string($stored['file']) || !is_array($stored['customer']) || !is_array($stored['products'])) {
            return [...$case, 'broken' => $case['fixture'] . ': file hoort tekst te zijn, customer en products een lijst'];
        }

        return [
            ...$stored,
            'name' => $case['name'],
            'fixture' => $case['fixture'],
            'file' => dirname($case['fixture']) . '/' . $stored['file'],
            'persona' => implode("\n", (array) $stored['persona']),
        ];
    }

    public function prepare(): void
    {
        $this->existing_types = ProductType::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function name(): string
    {
        return 'offerte.' . $this->case['name'];
    }

    public function suite(): string
    {
        return 'offerte';
    }

    public function opening(): string
    {
        return $this->case['opening'];
    }

    public function persona(): string
    {
        return $this->case['persona'];
    }

    public function role(): string
    {
        return $this->case['role'] ?? 'admin';
    }

    public function maxMoves(): int
    {
        return 24;
    }

    public function needs(): array
    {
        return ($this->case['customer']['existing'] ?? false) ? ['customers' => [$this->case['customer']['name']]] : [];
    }

    public function unavailable(): ?string
    {
        if (isset($this->case['broken'])) {
            return $this->case['broken'];
        }

        if (isset($this->case['fixture']) && !is_file($this->case['fixture'])) {
            return 'bestand ontbreekt: ' . $this->case['fixture'];
        }

        if (isset($this->case['file']) && !is_file($this->case['file'])) {
            return 'bestand ontbreekt: ' . $this->case['file'];
        }

        return parent::unavailable();
    }

    public function files(): array
    {
        $file = $this->case['file'] ?? null;

        return [new EvalFile(
            name: $file !== null ? basename($file) : $this->case['name'] . '.pdf',
            media_type: 'application/pdf',
            path: fn () => $file ?? QuoteFixture::pdf($this->case['quote'], $this->case['name']),
        )];
    }

    public function rubric(): array
    {
        return [
            'Begint het eerste antwoord met een korte tabel: de klant met adres, de offerteregels met aantal, en de levertijd.',
            'Laat regels met aantal 0 (niet gekozen opties) overal weg.',
            'Stelt elke stap die iets aanmaakt voor als één knop, en de volgende pas nadat de vorige is uitgevoerd.',
        ];
    }

    public function checks(EvalTranscript $transcript): array
    {
        $order = $this->order($transcript);
        $customer_id = $this->customerId($transcript);
        $location_id = EvalTranscript::idIn($transcript->executedOf('create_location')[0] ?? null, ['location_id']);
        $machines = $this->machines($transcript, $order);

        return [
            ...$this->noting($transcript),
            ...$this->customer($transcript, $customer_id),
            ...$this->location($transcript),
            ...$this->werkbon($order, $customer_id, $location_id),
            ...$this->tasks($transcript, $order, $machines),
            ...$this->products($transcript),
            ...$this->search($transcript),
            ...$this->appointments($transcript, $order),
            ...$this->contract($transcript, $machines),
            ...$this->document($transcript, $order),
            Check::noFailedActions($transcript),
        ];
    }

    /** @return array<int, Check> */
    private function noting(EvalTranscript $transcript): array
    {
        $names = $transcript->toolNames();
        $first_write = null;

        foreach ($names as $index => $name) {
            if (EvalTranscript::isWrite($name)) {
                $first_write = $index;

                break;
            }
        }

        $noted = $transcript->firstCallOf('note_file');

        return [Check::that(
            'note_file vóór de eerste aanmaakstap',
            $noted !== null && ($first_write === null || $noted < $first_write),
            'volgorde: ' . implode(', ', $names),
        )];
    }

    /** The customer the werkbon should be for: the one that was there, or the one the run made. */
    private function customerId(EvalTranscript $transcript): ?int
    {
        if ($this->case['customer']['existing'] ?? false) {
            return (int) Customer::query()->where('name', $this->case['customer']['name'])->value('id') ?: null;
        }

        return EvalTranscript::idIn($transcript->executedOf('create_customer')[0] ?? null, ['customer_id']);
    }

    /** @return array<int, Check> */
    private function customer(EvalTranscript $transcript, ?int $customer_id): array
    {
        $wanted = $this->case['customer'];
        $made = $transcript->executedOf('create_customer');

        if ($wanted['existing'] ?? false) {
            return [
                Check::that('bestaande klant gebruikt, geen nieuwe aangemaakt', $made === [], count($made) . ' klant(en) aangemaakt'),
                Check::that('klant opgezocht met find_customer', $transcript->called('find_customer')),
            ];
        }

        $customer = $customer_id ? Customer::query()->find($customer_id) : null;

        return [
            Check::that('precies één nieuwe klant aangemaakt', count($made) === 1, count($made) . ' aangemaakt'),
            Check::that(
                'klant heeft naam en adres van de offerte',
                $customer !== null && $this->mentions($customer->toArray(), [$wanted['name'], $wanted['address'], $wanted['postal_code'], $wanted['city']]),
                EvalTranscript::text($customer?->toArray()),
            ),
        ];
    }

    /** @return array<int, Check> */
    private function location(EvalTranscript $transcript): array
    {
        $made = $transcript->executedOf('create_location');

        if (!$this->case['other_location']) {
            return [Check::that('geen aparte locatie aangemaakt', $made === [], count($made) . ' locatie(s)')];
        }

        return [
            Check::that('aparte locatie aangemaakt', count($made) === 1, count($made) . ' locatie(s)'),
            Check::that(
                'locatie heeft het werkadres van de offerte',
                isset($made[0]) && $this->mentions((array) $made[0]['content'], [$this->case['work_address']['address'], $this->case['work_address']['city']]),
                EvalTranscript::text($made[0]['content'] ?? null),
            ),
        ];
    }

    /**
     * Every werkbon the run made, by create_service_order or by create_event
     * making one along with the appointment, and the first of them.
     *
     * @return array{ids: array<int, int>, model: ?ServiceOrder}
     */
    private function order(EvalTranscript $transcript): array
    {
        $ids = array_map(fn (array $action) => EvalTranscript::idIn($action, ['service_order_id']), [
            ...$transcript->executedOf('create_service_order'),
            ...array_filter(
                $transcript->executedOf('create_event'),
                fn (array $action) => filled($action['arguments']['create_service_order_for_customer_id'] ?? null),
            ),
        ]);
        $ids = array_values(array_unique(array_filter($ids)));

        return ['ids' => $ids, 'model' => isset($ids[0]) ? ServiceOrder::query()->find($ids[0]) : null];
    }

    /** @return array<int, Check> */
    private function werkbon(array $order, ?int $customer_id, ?int $location_id): array
    {
        $made = $order['model'];
        $checks = [
            Check::that('precies één werkbon aangemaakt', count($order['ids']) === 1 && $made !== null, count($order['ids']) . ' werkbon(nen): ' . EvalTranscript::text($order['ids'])),
            Check::that('werkbon is van het soort ' . $this->case['type'], $made?->type?->value === $this->case['type'], 'soort: ' . EvalTranscript::text($made?->type?->value)),
            Check::that(
                'offertenummer in de omschrijving van de werkbon',
                str_contains((string) $made?->description, $this->case['quote_number']),
                (string) ($made?->description ?? '(geen)'),
            ),
        ];

        if ($this->case['other_location']) {
            $checks[] = Check::that(
                'werkbon op de nieuwe locatie',
                $location_id !== null && (int) $made?->location_id === $location_id,
                'location_id ' . EvalTranscript::text($made?->location_id) . ', verwacht ' . EvalTranscript::text($location_id),
            );
        }

        if ($customer_id !== null && $made !== null) {
            $checks[] = Check::that(
                'werkbon hoort bij de klant',
                (int) $made->customer_id === $customer_id,
                'klant #' . $made->customer_id . ', verwacht #' . $customer_id,
            );
        }

        return $checks;
    }

    /**
     * The machines the task calls on the werkbon registered.
     *
     * @return array<int, int>
     */
    private function machines(EvalTranscript $transcript, array $order): array
    {
        $machines = [];

        foreach ($transcript->executedOf('add_service_order_task') as $action) {
            if ($order['model'] !== null && (int) ($action['content']['service_order_id'] ?? 0) === $order['model']->id) {
                array_push($machines, ...array_map(fn (array $machine) => (int) $machine['asset_id'], $action['content']['machines'] ?? []));
            }
        }

        return $machines;
    }

    /**
     * The tasks on the werkbon as they were stored.
     *
     * @param  array<int, int>  $machines
     * @return array<int, Check>
     */
    private function tasks(EvalTranscript $transcript, array $order, array $machines): array
    {
        $made = $order['model'];
        $lines = $made === null ? collect() : ServiceOrderTaskInstance::query()->where('service_order_id', $made->id)->with('product.brand')->get();
        $checks = [];
        $stored = EvalTranscript::text($lines->map(fn ($line) => [
            'product' => $line->product ? trim($line->product->brand?->name . ' ' . $line->product->model) : null,
            'quantity' => $line->quantity,
        ])->all());

        foreach ($this->case['products'] as $wanted) {
            $sold = implode(' ', $wanted['match']);
            $hit = $lines->first(fn ($line) => $line->product !== null
                && $this->mentions(['product' => $line->product->brand?->name . ' ' . $line->product->model], $wanted['match']));

            $checks[] = Check::that('taak met ' . $sold . ' op de werkbon', $hit !== null, $lines->count() . ' taakregel(s): ' . $stored);
            $checks[] = Check::that(
                'aantal ' . $wanted['quantity'] . ' bij ' . $sold,
                $hit !== null && (int) $hit->quantity === $wanted['quantity'],
                $hit === null ? 'geen taak met dit product' : 'aantal ' . $hit->quantity,
            );
        }

        $expected = array_sum(array_column($this->case['products'], 'quantity'));
        $checks[] = Check::that('machines via de taak geregistreerd', count($machines) === $expected, count($machines) . ' machine(s), verwacht ' . $expected);
        $checks[] = Check::that('geen losse create_asset', $transcript->executedOf('create_asset') === []);

        return $checks;
    }

    /** @return array<int, Check> */
    private function products(EvalTranscript $transcript): array
    {
        $made = $transcript->executedOf('create_product');
        $not_products = array_filter($made, fn (array $action) => preg_match(self::NOT_PRODUCTS, EvalTranscript::text($action['arguments'])));
        $new_types = array_filter($made, fn (array $action) => !in_array((int) ($action['content']['product_type_id'] ?? 0), $this->existing_types, true));

        return [
            Check::that(
                'geen product aangemaakt voor goot, leiding, voorrijkosten en dergelijke',
                $not_products === [],
                EvalTranscript::text(array_column($not_products, 'arguments')),
            ),
            Check::that(
                'hooguit één product per verkocht apparaat aangemaakt',
                count($made) <= count($this->case['products']),
                count($made) . ' aangemaakt, ' . count($this->case['products']) . ' verkocht',
            ),
            Check::that(
                'product onder een bestaande productsoort, geen nieuwe soort',
                $new_types === [],
                implode(', ', array_map(fn (array $action) => (string) ($action['content']['product_type'] ?? '?'), $new_types)),
            ),
        ];
    }

    /** @return array<int, Check> */
    private function search(EvalTranscript $transcript): array
    {
        $searches = $transcript->callsOf('find_available_technician');
        $search = end($searches) ?: null;
        $arguments = $search['arguments'] ?? [];
        $from = (string) ($arguments['from_date'] ?? Clock::today());
        $earliest = $this->earliest();

        return [
            Check::that('beschikbaarheid gezocht', $search !== null),
            Check::that('gezocht vanaf de levertijd', $search !== null && $from >= $earliest, 'from_date ' . $from . ', levertijd ' . $earliest),
            Check::that(
                'gezocht met het werk dat de planner noemde (' . $this->case['work_minutes'] . ' min)',
                in_array($this->case['work_minutes'], [(int) ($arguments['total_work_minutes'] ?? 0), (int) ($arguments['duration_minutes'] ?? 0)], true),
                EvalTranscript::text($arguments),
            ),
            Check::that('gezocht met job_type ' . $this->case['type'], ($arguments['job_type'] ?? null) === $this->case['type'], 'job_type ' . EvalTranscript::text($arguments['job_type'] ?? null)),
        ];
    }

    /** @return array<int, Check> */
    private function appointments(EvalTranscript $transcript, array $order): array
    {
        $events = $transcript->executedOf('create_event');
        $order_id = $order['model']?->id;
        $minutes = array_sum(array_map(fn (array $event) => EvalTranscript::minutesOf((array) $event['content']), $events));
        $on_order = array_filter($events, fn (array $event) => $order_id !== null && (int) ($event['content']['service_order_id'] ?? 0) === $order_id);
        $starts = array_map(fn (array $event) => (string) ($event['content']['starts_at'] ?? ''), $events);
        $earliest = Clock::fromLocal($this->earliest() . ' 00:00');

        return [
            Check::that(
                'afspraak ingepland op de werkbon',
                $events !== [] && count($on_order) === count($events),
                count($events) . ' afspraak/afspraken, ' . count($on_order) . ' op werkbon #' . ($order_id ?? '?'),
            ),
            Check::that(
                'afspraak niet vóór de levertijd',
                $events !== [] && array_filter($starts, fn (string $start) => !(Clock::fromLocal($start)?->greaterThanOrEqualTo($earliest) ?? false)) === [],
                implode(', ', $starts) . '; levertijd ' . $this->earliest(),
            ),
            Check::that('afspraken samen ' . $this->case['work_minutes'] . ' minuten', $minutes === $this->case['work_minutes'], $minutes . ' minuten'),
        ];
    }

    /** @return array<int, Check> */
    private function contract(EvalTranscript $transcript, array $machines): array
    {
        $made = $transcript->executedOf('create_maintenance_contract');
        $wanted = $this->case['contract'];

        if ($wanted === null) {
            return [Check::that('geen onderhoudscontract aangemaakt', $made === [], count($made) . ' contract(en)')];
        }

        $contract = (array) ($made[0]['content'] ?? []);
        $covered = array_map(fn (array $asset) => (int) $asset['asset_id'], $contract['assets'] ?? []);

        return [
            Check::that('precies één onderhoudscontract aangemaakt', count($made) === 1, count($made) . ' contract(en)'),
            Check::that(
                'contract met de frequentie en het startjaar van de offerte',
                strcasecmp((string) ($contract['frequency'] ?? ''), $wanted['frequency']) === 0
                    && str_starts_with((string) ($contract['start_date'] ?? ''), (string) $wanted['start_year']),
                'gemaakt ' . EvalTranscript::text(['frequency' => $contract['frequency'] ?? null, 'start_date' => $contract['start_date'] ?? null])
                    . ', verwacht ' . EvalTranscript::text($wanted),
            ),
            Check::that(
                'contract op de geregistreerde machines',
                $machines !== [] && array_diff($machines, $covered) === [],
                'op het contract ' . EvalTranscript::text($covered) . ', machines ' . EvalTranscript::text($machines),
            ),
        ];
    }

    /** @return array<int, Check> */
    private function document(EvalTranscript $transcript, array $order): array
    {
        $documents = $transcript->executedOf('save_document');

        if (!$this->case['keep_document']) {
            return [Check::that('offerte niet bewaard', $documents === [], count($documents) . ' document(en)')];
        }

        return [Check::that(
            'offerte bewaard bij de werkbon',
            count($documents) === 1 && $order['model'] !== null && (int) ($documents[0]['content']['service_order_id'] ?? 0) === $order['model']->id,
            count($documents) . ' document(en)',
        )];
    }

    private function earliest(): string
    {
        return CarbonImmutable::parse(Clock::today())->addDays($this->case['earliest_days'])->toDateString();
    }

    /** Whether every one of the words appears somewhere in the values, ignoring case and spacing. */
    private function mentions(array $values, array $words): bool
    {
        $haystack = mb_strtolower(preg_replace('/\s+/', '', EvalTranscript::text($values)));

        foreach ($words as $word) {
            if (!str_contains($haystack, mb_strtolower(preg_replace('/\s+/', '', $word)))) {
                return false;
            }
        }

        return true;
    }
}
