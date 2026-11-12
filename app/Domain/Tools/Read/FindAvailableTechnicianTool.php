<?php

namespace App\Domain\Tools\Read;

use App\Domain\Planning\Clock;
use App\Domain\Planning\CrewPlanner;
use App\Domain\Planning\TechnicianAvailability;
use App\Domain\Planning\TechnicianExperience;
use App\Domain\Planning\TechnicianRanking;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Enums\ServiceOrderTypes;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Models\UserPlanGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who can take a job, and when.
 *
 * The right group comes first, and it is a boundary rather than a preference.
 * Asked for an airco installer, this looks at airco installers only; when none
 * of them can, it says so and offers to look wider, instead of quietly putting a
 * plumber on the job because he happened to be free at eight. Within the group
 * it ranks the way a planner ranks: who is free soonest, then who has done the
 * most of this kind of work (TechnicianExperience).
 */
class FindAvailableTechnicianTool implements Tool
{
    /** Past this a "crew" is a project, and the planner screen is the right tool. */
    private const LARGEST_CREW = 4;

    /**
     * How far ahead the diary is searched.
     *
     * Not something the model may set: the schema forbids it, so reading it back
     * was a parameter that could never arrive, quietly defaulting for ever.
     */
    /** Carbon counts Sunday as nought. */
    private const WEEKDAYS = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];

    private const WINDOW_DAYS = 14;

    /**
     * How many days of everybody's diary travel back with an answer.
     *
     * Enough for the follow-up somebody actually asks, and short of sending three
     * weeks of the whole company on every question.
     */
    private const AVAILABILITY_DAYS = 10;

    public static function name(): string
    {
        return 'find_available_technician';
    }

    public function description(): string
    {
        return 'Zoekt wanneer een klus gedaan kan worden en door wie. '
            . 'Gebruik dit voor elke vraag over inplannen — "wie kan er dinsdag", "wanneer kan deze klus". '
            . 'Vraagt de klus een specialisme, kies dan zelf de plan_group die bij het werk past; kan '
            . 'niemand uit die groep, dan krijg je een vraag voor de gebruiker terug. '
            . 'Duurt de klus langer dan een dag, of kan hij met meer mensen sneller, geef dan '
            . 'total_work_minutes in manminuten: je krijgt per ploeggrootte terug op welke dagen het past '
            . 'en welke monteurs dan samen vrij zijn. Zonder total_work_minutes kijkt hij per monteur '
            . 'naar één aaneengesloten blok binnen één werkdag.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'duration_minutes' => [
                    'type' => 'integer',
                    'description' => 'Hoe lang de klus duurt. Standaard de ingestelde klusduur.',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Vanaf welke datum gezocht wordt, als JJJJ-MM-DD. Standaard vandaag.',
                ],
                'plan_group' => [
                    'type' => 'string',
                    'description' => 'De groep waar de klus bij hoort, bijvoorbeeld Electriciens. Er wordt '
                        . 'alleen daarbinnen gezocht.',
                ],
                'include_other_groups' => [
                    'type' => 'boolean',
                    'description' => 'Zoek ook buiten plan_group; alleen als de gebruiker daar ja op zei.',
                ],
                'asset_id' => [
                    'type' => 'integer',
                    'description' => 'De machine waar de klus over gaat; ervaring met dat soort weegt mee.',
                ],
                'product_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'De producten die geplaatst of onderhouden worden; ervaring daarmee '
                        . 'weegt mee.',
                ],
                'job_type' => [
                    'type' => 'string',
                    'enum' => array_column(ServiceOrderTypes::cases(), 'value'),
                    'description' => 'installation (plaatsen), service (storing, onderhoud) of mixed: '
                        . 'welke ervaring het zwaarst telt.',
                ],
                'total_work_minutes' => [
                    'type' => 'integer',
                    'description' => 'Hoeveel werk er in totaal is, in manminuten. Gebruik dit bij klussen '
                        . 'die meer dan een dag duren of met meerdere monteurs gedaan kunnen worden: '
                        . 'drie dagen werk voor een man is 3 x 480 = 1440. Je krijgt dan per ploeggrootte '
                        . 'terug wanneer het kan en met wie, in plaats van beschikbaarheid per monteur.',
                ],
                'user_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Kijk alleen naar deze monteurs. Gebruik dit bij vervolgvragen als '
                        . '"en als alleen Jeremy en Kenneth kunnen?" — reken zulke dingen niet zelf uit, '
                        . 'maar vraag het opnieuw met alleen hun user_id erin.',
                ],
                'crew_size' => [
                    'type' => 'integer',
                    'description' => 'Laat dit weg, tenzij de vraag om precies één ploeggrootte gaat. '
                        . 'Zonder crew_size krijg je alle werkbare ploeggroottes in één antwoord; '
                        . 'roep de tool dus niet meerdere keren aan voor 1, 2 en 3 man.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * Planning someone else's day is what this answers, so it asks the same
     * question the planner screen asks: may you make appointments at all.
     */
    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Event::class);
    }

    /** Duur, datum, groep en machine moeten uit een losse zin komen, en het antwoord is een afweging tussen mensen in plaats van een lijst. */
    public static function difficulty(): int
    {
        return 7;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function execute(ToolCall $call): ToolResult
    {
        $availability = app(TechnicianAvailability::class);

        $duration = $call->integerArgument('duration_minutes') ?? $availability->defaultDurationMinutes();

        $given_date = $call->stringArgument('from_date');

        /**
         * Left to itself this comes back as a PHP parse error with a character
         * position in it, which is no use to anything and reads to the model as
         * though the diary were broken rather than its own date.
         */
        $parsed = $call->dateArgument('from_date');

        if (filled($given_date) && $parsed === null) {
            return ToolResult::failed(
                'Geef from_date als een echte datum in de vorm JJJJ-MM-DD, bijvoorbeeld ' . Clock::today() . '.'
            );
        }

        $today = Clock::todayAsDate();
        $asked_from = $parsed?->startOfDay() ?? $today;

        /**
         * Nobody can be booked last Tuesday. Left alone this answers a question
         * about 2020 with four workable plans in 2020, which is a confident,
         * actionable, entirely useless answer — and reads exactly like a good one.
         */
        $from = $asked_from->lessThan($today) ? $today : $asked_from;

        $days = self::WINDOW_DAYS;
        $limit = (int) config('assistant.max_results', 25);

        $wanted_group = $call->stringArgument('plan_group') ?: null;

        /**
         * A group nobody is in and a group that does not exist give the same
         * answer — everyone, none of them preferred — and the difference matters:
         * the first is worth reporting, the second means the question was never
         * really asked. Left alone it comes back as "niemand heeft die
         * specialisatie", which is a confident answer to something that was
         * never looked up.
         */
        $known = UserPlanGroup::orderBy('name')->pluck('name');

        if ($wanted_group !== null && !$known->contains($wanted_group)) {
            /**
             * "Airco" for "Team Airco" is not a guess when it is the only group
             * with that word in it; refusing it cost a round trip every time.
             */
            $containing = $known->filter(
                fn (string $name) => str_contains(mb_strtolower($name), mb_strtolower($wanted_group))
            );

            if ($containing->count() !== 1) {
                return ToolResult::failed(
                    'Onbekende groep "' . $wanted_group . '". Bestaande groepen: ' . $known->implode(', ') . '.'
                );
            }

            $wanted_group = $containing->first();
        }

        /**
         * Work on a particular kind of machine is specialist work, and skipping
         * the group is how a plumber ends up on an airco: free soonest, never
         * asked about. So the group is chosen on purpose — one of them, or
         * explicitly none — rather than left out by accident.
         */
        $product_ids = $call->integerListArgument('product_ids');
        $asset_id = $call->integerArgument('asset_id');
        $widened = filter_var($call->argument('include_other_groups', false), FILTER_VALIDATE_BOOLEAN);

        if (($product_ids !== [] || $asset_id !== null) && $wanted_group === null && !$widened && $known->isNotEmpty()) {
            return ToolResult::failed(
                'Kies de plangroep die bij dit werk past en zoek opnieuw met plan_group. Bestaande groepen: '
                    . $known->implode(', ') . '. Past geen enkele groep, zoek dan opnieuw met '
                    . 'include_other_groups: true.'
            );
        }

        $working_day = ($availability->workEndHour() - $availability->workStartHour()) * 60;
        $total_work = $call->integerArgument('total_work_minutes');

        /**
         * A job longer than a working day cannot be one unbroken stretch, and
         * saying nobody is free would read as a full diary rather than as work
         * that has to be spread. Spreading it is what total_work_minutes asks
         * for, so the way out is named rather than left to be guessed at.
         */
        if ($total_work === null && $duration > $working_day) {
            return ToolResult::failed(
                'Een klus van ' . $duration . ' minuten past niet in één werkdag van '
                . $working_day . ' minuten (' . $availability->workStartHour() . ':00-'
                . $availability->workEndHour() . ':00). Geef total_work_minutes mee als de klus '
                . 'over meerdere dagen of meerdere monteurs verdeeld mag worden.'
            );
        }

        $job_type = ServiceOrderTypes::tryFrom($call->stringArgument('job_type') ?: 'mixed');

        if ($job_type === null) {
            return ToolResult::failed('job_type is installation, service of mixed.');
        }

        $unknown_products = array_diff($product_ids, Product::whereIn('id', $product_ids)->pluck('id')->all());

        if ($unknown_products !== []) {
            return ToolResult::notFound('Product(en) ' . implode(', ', $unknown_products));
        }

        $ranking = new TechnicianRanking(
            $wanted_group,
            app(TechnicianExperience::class)->of($product_ids, $asset_id, $job_type),
        );

        $users = $availability->plannableUsers();
        $only = $call->integerListArgument('user_ids');

        if ($only !== []) {
            $users = $users->whereIn('id', $only)->values();

            if ($users->isEmpty()) {
                return ToolResult::failed(
                    'Geen van die monteurs is inplanbaar. Zoek de juiste user_id op voordat je hierop filtert.'
                );
            }
        }

        /**
         * Kept to the group unless somebody agreed to more. A question about
         * Jeremy by name is a question about Jeremy, so naming people overrules
         * the group: whoever they are, they were asked for.
         */
        $kept_to_group = $wanted_group !== null && !$widened && $only === [];

        if ($kept_to_group) {
            $users = $users->filter(fn (User $user) => $ranking->inGroup($user))->values();

            if ($users->isEmpty()) {
                return $this->nobodyInGroup(
                    $wanted_group,
                    $from,
                    'Er zit geen inplanbare monteur in de groep ' . $wanted_group . '.',
                    can_wait: false,
                );
            }
        }

        if ($total_work !== null) {
            return $this->crewOptions(
                $users,
                $from,
                $total_work,
                $call->integerArgument('crew_size'),
                $wanted_group,
                $kept_to_group,
                $ranking,
                $days,
            );
        }

        $candidates = [];
        $slots = $availability->firstSlots($users, $from, $duration, $days);

        foreach ($users as $user) {
            $slot = $slots[$user->id] ?? null;

            if ($slot === null) {
                continue;
            }

            $candidates[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'first_free' => [
                    'date' => $slot['date'],
                    'from' => $this->clock($slot['start_min']),
                    'until' => $this->clock($slot['end_min']),
                ],
                'plan_groups' => $user->planGroups->pluck('name')->all(),
                'in_requested_group' => $wanted_group !== null && $ranking->inGroup($user),
                'experience' => $ranking->experienceOf($user->id),
                '_sort' => [
                    !$ranking->inGroup($user),
                    $slot['date'] . str_pad((string) $slot['start_min'], 4, '0', STR_PAD_LEFT),
                    -$ranking->score($user),
                    $user->name,
                ],
            ];
        }

        usort($candidates, fn (array $a, array $b) => $a['_sort'] <=> $b['_sort']);

        $candidates = array_map(function (array $candidate) {
            unset($candidate['_sort']);

            return $candidate;
        }, array_slice($candidates, 0, $limit));

        if ($candidates === [] && $kept_to_group) {
            return $this->nobodyInGroup(
                $wanted_group,
                $from,
                'Niemand uit de groep ' . $wanted_group . ' heeft tussen ' . $from->toDateString() . ' en '
                    . $from->addDays($days - 1)->toDateString() . ' een aaneengesloten blok van ' . $duration
                    . ' minuten vrij.',
            );
        }

        if ($candidates === []) {
            return ToolResult::ok(
                ['technicians' => [], 'note' => 'Niemand heeft in deze periode een aaneengesloten blok van '
                    . $duration . ' minuten vrij.'],
                'Geen monteur met ruimte gevonden.',
            );
        }

        return ToolResult::ok(
            [
                'technicians' => $candidates,
                'searched' => [
                    'from' => $from->toDateString(),
                    'days' => $days,
                    'duration_minutes' => $duration,
                    'only_plan_group' => $kept_to_group ? $wanted_group : null,
                ],
            ],
            count($candidates) . ' monteur(s) met ruimte gevonden.',
        );
    }

    /**
     * Every workable way of staffing the job, cheapest in days first.
     *
     * A crew of one is a real answer here, not a fallback: it is the plan that
     * needs nobody else free at the same moment, and sometimes it is the only one
     * that fits. Sizes nobody could fill are left out rather than reported as
     * empty, so what comes back is a list of things that can actually be done.
     *
     * @param  Collection<int, User>  $users
     */
    private function crewOptions(
        Collection $users,
        CarbonImmutable $from,
        int $total_work,
        ?int $crew_size,
        ?string $wanted_group,
        bool $kept_to_group,
        TechnicianRanking $ranking,
        int $days,
    ): ToolResult {
        if ($total_work < 1) {
            return ToolResult::failed('Geef bij total_work_minutes hoeveel werk er in totaal is, in manminuten.');
        }

        /**
         * A crew of nought, or of nine hundred, is not a full diary — but that is
         * how it came back, and "er is geen ruimte" is the one answer nobody can
         * do anything with.
         */
        $largest = min(self::LARGEST_CREW, max($users->count(), 1));

        /**
         * A crew bigger than the group is a crew that needs outsiders, which is
         * the question to put to the planner — not a complaint about crew_size.
         */
        if ($kept_to_group && $crew_size !== null && $crew_size > $users->count()) {
            return $this->nobodyInGroup(
                $wanted_group,
                $from,
                'De groep ' . $wanted_group . ' heeft ' . $users->count() . ' inplanbare monteur(s); een ploeg van '
                    . $crew_size . ' kan alleen met iemand van buiten de groep.',
                can_wait: false,
            );
        }

        if ($crew_size !== null && ($crew_size < 1 || $crew_size > $largest)) {
            return ToolResult::failed(
                'crew_size moet tussen 1 en ' . $largest . ' liggen (' . $users->count()
                . ' inplanbare monteurs). Laat crew_size weg om alle werkbare ploeggroottes te krijgen.'
            );
        }

        $positions = $ranking->positions($users);
        $planner = app(CrewPlanner::class);
        $names = $this->labels($users);
        $in_group = $users
            ->mapWithKeys(fn (User $user) => [$user->id => $ranking->inGroup($user)])
            ->all();

        $sizes = $crew_size !== null
            ? [$crew_size]
            : range(1, min(self::LARGEST_CREW, max($users->count(), 1)));

        $availability = app(TechnicianAvailability::class);
        $grid = $availability->segmentsFor($users, $from, $days);

        /**
         * A day off can be given up if the person agrees, so a plan that needs
         * one is worth offering — as a question rather than as a fact. It is only
         * computed when nothing else fits, and it never touches appointments:
         * those belong to a customer, not to the mechanic.
         */
        $lenient = null;
        $time_off = null;
        $options = [];

        foreach ($sizes as $size) {
            $option = $planner->optionFrom($grid, $users, $from, $total_work, $size, $positions, $days);
            $overrides = [];

            if ($option === null) {
                $lenient ??= $availability->segmentsFor($users, $from, $days, ignore_time_off: true);
                $time_off ??= $availability->timeOffFor($users, $from, $days);

                $option = $planner->optionFrom($lenient, $users, $from, $total_work, $size, $positions, $days);

                if ($option === null) {
                    continue;
                }

                $overrides = $this->timeOffInTheWay($option, $time_off, $names);

                /**
                 * Found only by setting time off aside, yet nothing was actually
                 * set aside. Offering it as "this costs somebody their day off"
                 * would be a lie, so it is dropped rather than explained away.
                 */
                if ($overrides === []) {
                    continue;
                }
            }

            /**
             * Filling a crew from outside the group asked for is allowed — the
             * alternative is no plan at all — but it has to be said. A crew of
             * four for stucwerk with one person who is not a stucadoor reads
             * exactly like a crew of four stucadoors otherwise.
             */
            $outsiders = $wanted_group === null
                ? []
                : array_values(array_map(
                    fn (int $id) => $names[$id] ?? ('#' . $id),
                    array_filter($option['crew'], fn (int $id) => !($in_group[$id] ?? false)),
                ));

            $options[] = [
                'crew_size' => $option['crew_size'],
                'days_needed' => $option['days_needed'],
                'hours_per_person_per_day' => round($option['minutes_per_person_per_day'] / 60, 1),
                'crew' => array_map(fn (int $id) => $names[$id] ?? ('#' . $id), $option['crew']),
                /**
                 * The numbers beside the names, because the next step needs them
                 * and this was the only place they were missing.
                 *
                 * Told "Alptug & Jeremy" and then asked to plan it, the model had
                 * to find their ids somewhere else, took two off a different list,
                 * and proposed an appointment for Ferhat and Jimmy — right day,
                 * wrong men, and the names in the sentence still said Alptug and
                 * Jeremy. Nothing about the proposal looked wrong.
                 */
                'crew_user_ids' => array_values($option['crew']),
                'not_in_requested_group' => $outsiders,
                'experience' => array_combine(
                    array_map(fn (int $id) => $names[$id] ?? ('#' . $id), $option['crew']),
                    array_map($ranking->experienceOf(...), $option['crew']),
                ),
                'costs_someone_their_day_off' => $overrides,
                'same_crew_throughout' => $option['same_crew_throughout'],
                'days' => array_map(fn (array $day) => $this->dayOf($day['date']) + [
                    'date' => $day['date'],
                    'from' => $this->clock($day['from']),
                    'until' => $this->clock($day['until']),
                    'who' => $option['same_crew_throughout']
                        ? null
                        : array_map(fn (int $id) => $names[$id] ?? ('#' . $id), array_slice($day['user_ids'], 0, $option['crew_size'])),
                ], $option['days']),
            ];
        }

        if ($options === [] && $kept_to_group) {
            return $this->nobodyInGroup(
                $wanted_group,
                $from,
                'Met de ' . $users->count() . ' monteur(s) uit de groep ' . $wanted_group . ' past '
                    . $total_work . ' manminuten werk niet tussen ' . $from->toDateString() . ' en '
                    . $from->addDays($days - 1)->toDateString() . '.',
            );
        }

        if ($options === []) {
            return ToolResult::ok(
                ['options' => [], 'note' => 'Er is in deze periode geen ploeggrootte waarmee ' . $total_work
                    . ' manminuten werk past. Probeer een latere begindatum of een langere periode.'],
                'Geen werkbare planning gevonden.',
            );
        }

        $choice = $this->planChoice($options);

        return ToolResult::ok(
            [
                'options' => $options,
                'choice' => $choice,
                'availability' => $this->freeWindows($grid, $users, $from),
                'searched' => [
                    'from' => $from->toDateString(),
                    'days' => $days,
                    'total_work_minutes' => $total_work,
                    'only_plan_group' => $kept_to_group ? $wanted_group : null,
                    'people_in_plan_group' => $kept_to_group ? $users->count() : null,
                ],
                'note' => 'Elke optie is een manier om dezelfde hoeveelheid werk te verdelen; de monteurs '
                    . 'daarin zijn op die dagen tegelijk vrij. Onder availability staat per monteur per dag '
                    . 'wanneer hij vrij is, als achtergrond bij het antwoord. Neem tijden en dagen altijd '
                    . 'letterlijk over uit options en reken ze nooit zelf uit — ook vandaag en morgen '
                    . 'staan er al bij waar dat zo is; noem een dag nooit zelf "vandaag" of "morgen". '
                    . 'Plan je een optie in, '
                    . 'neem user_ids dan letterlijk over uit crew_user_ids van diezelfde optie en zoek de '
                    . 'monteurs niet opnieuw op naam op — dat leverde een afspraak op met twee andere '
                    . 'monteurs dan de namen in het antwoord. Gaat een vervolgvraag over een andere '
                    . 'ploeg, stel die dan opnieuw met user_ids. Staat er bij een optie iets in '
                    . 'costs_someone_their_day_off, noem die dan altijd met naam en dag erbij en vraag of '
                    . 'dat mag — die planning kan alleen doorgaan als iemand daarmee akkoord gaat.'
                    . ($choice === null ? '' : ' De gebruiker krijgt deze opties als knoppen te zien; '
                    . 'som ze niet nog eens op, zeg kort wat het verschil is en wacht op de klik.'),
            ],
            count($options) . ' werkbare planning(en) gevonden.',
        );
    }

    /**
     * The plans as buttons, so choosing one is a click rather than a sentence.
     *
     * Which crew takes the job was left to the model to ask nicely, and asking
     * nicely happens about half the time — the other half it was a numbered list
     * in prose with nothing to click. The ambiguity is in this data, so this data
     * offers the choice, the way a handful of matching customers already does.
     *
     * The reference carries the monteurs' numbers and the dates: the click hands
     * the follow-up everything it needs, and nobody gets looked up again by name —
     * which is how Alptug and Jeremy once turned into Ferhat and Jimmy.
     *
     * @param  array<int, array<string, mixed>>  $options
     * @return array{question: string, options: array<int, array{label: string, reference: string, link: null}>}|null
     */
    private function planChoice(array $options): ?array
    {
        if (count($options) < 2 || count($options) > 8) {
            return null;
        }

        return [
            'question' => 'Welke bezetting wil je?',
            'options' => array_map(fn (array $option) => [
                'label' => mb_substr(
                    $option['crew_size'] . ' man, ' . $option['days_needed']
                        . ($option['days_needed'] === 1 ? ' dag: ' : ' dagen: ')
                        . $this->daysLine($option['days'])
                        . ' — ' . implode(', ', $option['crew']),
                    0,
                    120,
                ),
                'reference' => 'monteurs ' . implode(', ', $option['crew_user_ids'])
                    . ' op ' . implode(' en ', array_column($option['days'], 'date')),
                'link' => null,
            ], $options),
        ];
    }

    /**
     * The days of one plan, short enough for a button.
     *
     * @param  array<int, array<string, mixed>>  $days
     */
    private function daysLine(array $days): string
    {
        $named = fn (array $day) => $day['weekday'] . ' ' . CarbonImmutable::parse($day['date'])->format('d-m');

        if (count($days) > 3) {
            return count($days) . ' dagen van ' . $named($days[0]) . ' t/m ' . $named($days[count($days) - 1]);
        }

        return implode(' + ', array_map(
            fn (array $day) => $named($day) . ' ' . $day['from'] . '–' . $day['until'],
            $days,
        ));
    }

    /**
     * Whose recorded time off a plan would run straight through, and on which day.
     *
     * @param  array{days: array<int, array{date: string, user_ids: array<int, int>}>}  $option
     * @param  array<int, array<string, array<int, string>>>  $time_off
     * @param  array<int, string>  $names
     * @return array<int, array{who: string, date: string, reason: array<int, string>}>
     */
    private function timeOffInTheWay(array $option, array $time_off, array $names): array
    {
        $clashes = [];

        foreach ($option['days'] as $day) {
            foreach ($day['user_ids'] as $id) {
                $reasons = $time_off[$id][$day['date']] ?? null;

                if ($reasons === null) {
                    continue;
                }

                $clashes[] = [
                    'who' => $names[$id] ?? ('#' . $id),
                    'date' => $day['date'],
                    'reason' => $reasons,
                ];
            }
        }

        return $clashes;
    }

    /**
     * When each person is free, per day, as plain clock times.
     *
     * This is the raw material behind the options rather than a second answer.
     * A follow-up like "en als alleen Jeremy en Kenneth kunnen?" is then a matter
     * of reading what is already on the table instead of searching again, which
     * is the round trip worth saving.
     *
     * Kept to a stretch of days on purpose: the whole window for everybody is a
     * far bigger thing to send than the answer itself, every single question.
     *
     * @param  array<int, array<string, array<int, array{start_min: int, end_min: int}>>>  $grid
     * @param  Collection<int, User>  $users
     * @return array<string, array{user_id: int, vrij: array<string, string>}>
     */
    private function freeWindows(array $grid, Collection $users, CarbonImmutable $from): array
    {
        $windows = [];

        $labels = $this->labels($users);

        foreach ($users as $user) {
            $per_day = [];

            for ($offset = 0; $offset < self::AVAILABILITY_DAYS; $offset++) {
                $date = $from->addDays($offset)->toDateString();
                $segments = $grid[$user->id][$date] ?? null;

                if ($segments === null) {
                    continue;
                }

                $named = $this->dayOf($date);

                $per_day[$date] = $named['weekday'] . ' ' . ($segments === []
                    ? 'geen ruimte'
                    : implode(', ', array_map(
                        fn (array $segment) => $this->clock($segment['start_min']) . '-' . $this->clock($segment['end_min']),
                        $segments,
                    )));
            }

            /**
             * The id travels with the name because the follow-up needs it. Asked
             * to narrow to two people by user_id off a list keyed only by name,
             * there is nothing to look the id up in — so it falls back to working
             * the hours out in its head, which is the one thing it must not do.
             */
            $windows[$labels[$user->id]] = ['user_id' => $user->id, 'vrij' => $per_day];
        }

        return $windows;
    }

    /**
     * A name per person that no two of them share.
     *
     * Names are not unique and this is keyed by them, so two mechanics called Jan
     * would quietly become one — and the one that vanished would look like
     * somebody with no free time at all rather than somebody missing.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, string>
     */
    private function labels(Collection $users): array
    {
        $counts = $users->countBy(fn (User $user) => $user->name);

        return $users
            ->mapWithKeys(fn (User $user) => [
                $user->id => ($counts[$user->name] ?? 1) > 1
                    ? $user->name . ' #' . $user->id
                    : $user->name,
            ])
            ->all();
    }

    /**
     * Nobody from the group can, said as a question rather than solved quietly.
     *
     * Two ways on, both as buttons: let others in, or keep to the group and look
     * further ahead. Which one is right is the planner's call — a specialist in
     * three weeks can be better than a stand-in tomorrow.
     */
    private function nobodyInGroup(string $group, CarbonImmutable $from, string $why, bool $can_wait = true): ToolResult
    {
        $later = $from->addDays(self::WINDOW_DAYS);

        $options = [[
            'label' => 'Ook monteurs buiten ' . $group,
            'reference' => 'zoek opnieuw met include_other_groups',
            'link' => null,
        ]];

        if ($can_wait) {
            $options[] = [
                'label' => 'Alleen ' . $group . ', vanaf ' . $later->format('d-m'),
                'reference' => 'zoek binnen ' . $group . ' vanaf ' . $later->toDateString(),
                'link' => null,
            ];
        }

        return ToolResult::ok(
            [
                'technicians' => [],
                'options' => [],
                'note' => $why . ' Er is alleen binnen de groep gezocht. Vraag de gebruiker of je ook buiten '
                    . 'de groep mag zoeken (dan opnieuw met include_other_groups: true)'
                    . ($can_wait ? ' of dat je binnen de groep verder vooruit zoekt (dan opnieuw met from_date '
                        . $later->toDateString() . ')' : '')
                    . '. De gebruiker krijgt dat als knoppen; vraag het niet nog eens in tekst.',
                'choice' => [
                    'question' => 'Niemand uit ' . $group . ' kan. Hoe verder?',
                    'options' => $options,
                ],
            ],
            'Niemand uit ' . $group . ' kan.',
        );
    }

    /**
     * Which day of the week that date is.
     *
     * Handed bare dates the model works the weekday out itself, and it gets it
     * wrong: "dinsdag 31 juli" for a Friday, in an answer that was otherwise
     * right, which is the kind of mistake somebody only catches if they happen to
     * know. So it is stated rather than left to be derived.
     *
     * Only the name. Whether that day is a working one is not for this to decide
     * — plenty of Saturdays are worked here — and who is available when is
     * already recorded per person as recurring unavailability. A blanket rule
     * about weekends would talk over that real data with a guess.
     *
     * @return array{weekday: string}
     */
    private function dayOf(string $date): array
    {
        $weekday = self::WEEKDAYS[(int) CarbonImmutable::parse($date)->dayOfWeek];

        /**
         * Vandaag and morgen are said outright, for the same reason the weekday
         * is: left to work them out, the model announced a plan that starts today
         * as "morgen al beginnen" — today's date was in its context and the
         * weekday was on the day, and it still did the arithmetic wrong.
         */
        if ($date === Clock::today()) {
            return ['weekday' => 'vandaag (' . $weekday . ')'];
        }

        if ($date === Clock::todayAsDate()->addDay()->toDateString()) {
            return ['weekday' => 'morgen (' . $weekday . ')'];
        }

        return ['weekday' => $weekday];
    }

    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
