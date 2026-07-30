<?php

namespace App\Domain\Tools\Read;

use App\Domain\Planning\Clock;
use App\Domain\Planning\CrewPlanner;
use App\Domain\Planning\TechnicianAvailability;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Event;
use App\Models\GeneralSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserPlanGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who can take a job, and when.
 *
 * Ranked the way a planner ranks: first by who is free soonest, because a
 * perfect match next Friday is no use for a job on Tuesday. Only where two
 * people come free at the same moment does it start asking who is the better
 * fit — the right group first, then whoever has closed the most tickets on that
 * kind of machine.
 *
 * The second tie-break is only as good as the data behind it. If nobody records
 * who closed a ticket it contributes nothing and the ordering quietly falls back
 * to group and name, which is the right way for it to degrade.
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
        return 'Zoekt wanneer een klus gedaan kan worden en door wie, gesorteerd op wie het eerst kan. '
            . 'Gebruik dit voor elke vraag over inplannen — "wie kan er dinsdag", "wanneer kan deze klus". '
            . 'Geef asset_id mee als het om een specifieke machine gaat, dan weegt ervaring met dat merk mee. '
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
                    'description' => 'Naam van de groep die de voorkeur heeft, bijvoorbeeld Electriciens.',
                ],
                'asset_id' => [
                    'type' => 'integer',
                    'description' => 'De machine waar de klus over gaat, zodat ervaring met dat merk meeweegt.',
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

        $duration = $call->integerArgument('duration_minutes')
            ?? (int) GeneralSetting::get('defaultplannerminutes', 480);

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

        $wanted_group = $call->stringArgument('plan_group');

        /**
         * A group nobody is in and a group that does not exist give the same
         * answer — everyone, none of them preferred — and the difference matters:
         * the first is worth reporting, the second means the question was never
         * really asked. Left alone it comes back as "niemand heeft die
         * specialisatie", which is a confident answer to something that was
         * never looked up.
         */
        if (filled($wanted_group)) {
            $known = UserPlanGroup::orderBy('name')->pluck('name');

            if (!$known->contains($wanted_group)) {
                return ToolResult::failed(
                    'Onbekende groep "' . $wanted_group . '". Bestaande groepen: ' . $known->implode(', ') . '.'
                );
            }
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
        $brand_id = $this->brandOf($call->integerArgument('asset_id'));
        $closed_by_brand = $brand_id ? $this->ticketsClosedPerUser($brand_id) : [];

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

        if ($total_work !== null) {
            return $this->crewOptions(
                $users,
                $from,
                $total_work,
                $call->integerArgument('crew_size'),
                $wanted_group,
                $brand_id,
                $closed_by_brand,
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

            $groups = $user->planGroups->pluck('name')->all();

            $candidates[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'first_free' => [
                    'date' => $slot['date'],
                    'from' => $this->clock($slot['start_min']),
                    'until' => $this->clock($slot['end_min']),
                ],
                'plan_groups' => $groups,
                'in_requested_group' => $wanted_group !== null && in_array($wanted_group, $groups, true),
                'closed_tickets_on_this_brand' => $closed_by_brand[$user->id] ?? 0,
                '_sort' => [
                    $slot['date'] . str_pad((string) $slot['start_min'], 4, '0', STR_PAD_LEFT),
                    ($wanted_group !== null && in_array($wanted_group, $groups, true)) ? 0 : 1,
                    -($closed_by_brand[$user->id] ?? 0),
                    $user->name,
                ],
            ];
        }

        usort($candidates, fn (array $a, array $b) => $a['_sort'] <=> $b['_sort']);

        $candidates = array_map(function (array $candidate) {
            unset($candidate['_sort']);

            return $candidate;
        }, array_slice($candidates, 0, $limit));

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
                'searched' => ['from' => $from->toDateString(), 'days' => $days, 'duration_minutes' => $duration],
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
     * @param  array<int, int>  $closed_by_brand
     */
    private function crewOptions(
        Collection $users,
        CarbonImmutable $from,
        int $total_work,
        ?int $crew_size,
        ?string $wanted_group,
        ?int $brand_id,
        array $closed_by_brand,
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

        if ($crew_size !== null && ($crew_size < 1 || $crew_size > $largest)) {
            return ToolResult::failed(
                'crew_size moet tussen 1 en ' . $largest . ' liggen (' . $users->count()
                . ' inplanbare monteurs). Laat crew_size weg om alle werkbare ploeggroottes te krijgen.'
            );
        }

        $ranking = $this->rankingFor($users, $wanted_group, $closed_by_brand);
        $planner = app(CrewPlanner::class);
        $names = $this->labels($users);
        $in_group = $users
            ->mapWithKeys(fn (User $user) => [$user->id => $this->groupRank($user, $wanted_group) === 0])
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
            $option = $planner->optionFrom($grid, $users, $from, $total_work, $size, $ranking, $days);
            $overrides = [];

            if ($option === null) {
                $lenient ??= $availability->segmentsFor($users, $from, $days, ignore_time_off: true);
                $time_off ??= $availability->timeOffFor($users, $from, $days);

                $option = $planner->optionFrom($lenient, $users, $from, $total_work, $size, $ranking, $days);

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
                'not_in_requested_group' => $outsiders,
                'costs_someone_their_day_off' => $overrides,
                'same_crew_throughout' => $option['same_crew_throughout'],
                'days' => array_map(fn (array $day) => [
                    'date' => $day['date'],
                    'from' => $this->clock($day['from']),
                    'until' => $this->clock($day['until']),
                    'who' => $option['same_crew_throughout']
                        ? null
                        : array_map(fn (int $id) => $names[$id] ?? ('#' . $id), array_slice($day['user_ids'], 0, $option['crew_size'])),
                ], $option['days']),
            ];
        }

        if ($options === []) {
            return ToolResult::ok(
                ['options' => [], 'note' => 'Er is in deze periode geen ploeggrootte waarmee ' . $total_work
                    . ' manminuten werk past. Probeer een latere begindatum of een langere periode.'],
                'Geen werkbare planning gevonden.',
            );
        }

        return ToolResult::ok(
            [
                'options' => $options,
                'availability' => $this->freeWindows($grid, $users, $from),
                'searched' => [
                    'from' => $from->toDateString(),
                    'days' => $days,
                    'total_work_minutes' => $total_work,
                ],
                'note' => 'Elke optie is een manier om dezelfde hoeveelheid werk te verdelen; de monteurs '
                    . 'daarin zijn op die dagen tegelijk vrij. Onder availability staat per monteur per dag '
                    . 'wanneer hij vrij is, als achtergrond bij het antwoord. Neem tijden en dagen altijd '
                    . 'letterlijk over uit options en reken ze nooit zelf uit: gaat een vervolgvraag over '
                    . 'een andere ploeg, stel die dan opnieuw met user_ids. Staat er bij een optie iets in '
                    . 'costs_someone_their_day_off, noem die dan altijd met naam en dag erbij en vraag of '
                    . 'dat mag — die planning kan alleen doorgaan als iemand daarmee akkoord gaat.',
            ],
            count($options) . ' werkbare planning(en) gevonden.',
        );
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

                $per_day[$date] = $segments === []
                    ? 'geen ruimte'
                    : implode(', ', array_map(
                        fn (array $segment) => $this->clock($segment['start_min']) . '-' . $this->clock($segment['end_min']),
                        $segments,
                    ));
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
     * Who to pick first when more people are free than the crew needs: the right
     * group, then whoever has seen most of this kind of machine, then by name so
     * the same question twice gives the same answer.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, int>  $closed_by_brand
     * @return array<int, int>
     */
    private function rankingFor(Collection $users, ?string $wanted_group, array $closed_by_brand): array
    {
        $ordered = $users->sortBy([
            fn (User $a, User $b) => $this->groupRank($a, $wanted_group) <=> $this->groupRank($b, $wanted_group),
            fn (User $a, User $b) => ($closed_by_brand[$b->id] ?? 0) <=> ($closed_by_brand[$a->id] ?? 0),
            fn (User $a, User $b) => $a->name <=> $b->name,
        ])->values();

        $ranking = [];

        foreach ($ordered as $position => $user) {
            $ranking[$user->id] = $position;
        }

        return $ranking;
    }

    private function groupRank(User $user, ?string $wanted_group): int
    {
        if ($wanted_group === null) {
            return 0;
        }

        return in_array($wanted_group, $user->planGroups->pluck('name')->all(), true) ? 0 : 1;
    }

    /**
     * How often each person has closed a ticket on a machine of this brand. The
     * signal a planner actually uses is "has this person seen one of these
     * before", and the brand is the closest thing the data holds to that.
     *
     * @return array<int, int>
     */
    private function ticketsClosedPerUser(int $brand_id): array
    {
        return Ticket::query()
            ->whereNotNull('closed_by_id')
            ->whereHas('asset.product', fn ($q) => $q->where('brand_id', $brand_id))
            ->select('closed_by_id', DB::raw('count(*) as total'))
            ->groupBy('closed_by_id')
            ->pluck('total', 'closed_by_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    private function brandOf(?int $asset_id): ?int
    {
        if ($asset_id === null) {
            return null;
        }

        return Asset::with('product:id,brand_id')->find($asset_id)?->product?->brand_id;
    }

    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
