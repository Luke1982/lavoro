<?php

namespace App\Domain\Tools\Read;

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
use Carbon\CarbonImmutable;
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
    public static function name(): string
    {
        return 'find_available_technician';
    }

    public function description(): string
    {
        return 'Zoekt welke monteurs ruimte hebben voor een klus en wanneer, gesorteerd op wie het eerst kan. '
            . 'Gebruik dit voor elke vraag over inplannen — "wie kan er dinsdag", "wanneer kan deze klus" — '
            . 'en geef asset_id mee als het om een specifieke machine gaat, dan weegt ervaring met dat merk mee.';
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
                'days' => [
                    'type' => 'integer',
                    'description' => 'Hoeveel dagen vooruit gekeken wordt. Standaard 14.',
                ],
                'plan_group' => [
                    'type' => 'string',
                    'description' => 'Naam van de groep die de voorkeur heeft, bijvoorbeeld Electriciens.',
                ],
                'asset_id' => [
                    'type' => 'integer',
                    'description' => 'De machine waar de klus over gaat, zodat ervaring met dat merk meeweegt.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum aantal monteurs.',
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

        $from = $call->stringArgument('from_date')
            ? CarbonImmutable::parse($call->stringArgument('from_date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $days = min($call->integerArgument('days') ?? 14, 60);
        $limit = min($call->integerArgument('limit') ?? 5, (int) config('assistant.max_results', 25));

        $wanted_group = $call->stringArgument('plan_group');
        $brand_id = $this->brandOf($call->integerArgument('asset_id'));
        $closed_by_brand = $brand_id ? $this->ticketsClosedPerUser($brand_id) : [];

        $candidates = [];
        $users = $availability->plannableUsers();
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
