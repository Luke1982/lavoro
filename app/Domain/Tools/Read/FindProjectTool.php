<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Read\Concerns\OffersAChoice;
use App\Domain\Tools\Read\Concerns\ReportsTheWholeCount;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Enums\ProjectStatuses;
use App\Models\Project;
use App\Models\User;

/**
 * Finds projects through the same scope as the project list, with the number of
 * open werkbonnen counted over the ones this person may see.
 */
class FindProjectTool implements Tool
{
    use OffersAChoice;
    use ReportsTheWholeCount;

    /** Not a status of its own: everything that has not been finished or called off. */
    private const RUNNING = 'lopend';

    public static function name(): string
    {
        return 'find_project';
    }

    public function description(): string
    {
        return 'Zoekt projecten op projectnummer, titel, klant of projectleider, eventueel beperkt tot een '
            . 'status of één klant. Gebruik dit zodra een vraag een project noemt en je het project-id nog '
            . 'niet hebt, want summarize_project werkt op dat id.';
    }

    /** Nothing is required: a status or a customer alone is a question too, "welke projecten lopen er". */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Deel van het projectnummer (zoals P-2026-014), de titel, de klantnaam '
                        . 'of de naam van de projectleider.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Niet gestart, Gestart, Afgerond of Geannuleerd, of "lopend" voor alles '
                        . 'wat nog niet afgerond of geannuleerd is.',
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Beperk tot projecten van deze klant, te vinden met find_customer.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('viewAny', Project::class);
    }

    /** Eén zoekterm uit de vraag halen, hooguit met een status of klant erbij. */
    public static function difficulty(): int
    {
        return 2;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    /**
     * Buttons come only when a project was named and several fit it. Asked which
     * projects are running, the list is the answer rather than a choice.
     */
    public function execute(ToolCall $call): ToolResult
    {
        $query = $call->stringArgument('query');

        if (filled($query) && mb_strlen($query) < 2) {
            return ToolResult::failed(
                'Geef minimaal twee tekens om op te zoeken, of laat query weg en zoek op status of klant.'
            );
        }

        $wanted_status = $call->stringArgument('status');
        $running = filled($wanted_status) && mb_strtolower($wanted_status) === self::RUNNING;
        $status = filled($wanted_status) && !$running ? $this->statusNamed($wanted_status) : null;

        if (filled($wanted_status) && !$running && $status === null) {
            return ToolResult::failed(
                'Onbekende status "' . $wanted_status . '". Bestaande statussen: '
                    . collect(ProjectStatuses::cases())->map(fn (ProjectStatuses $case) => $case->value)->implode(', ')
                    . ', of ' . self::RUNNING . ' voor alles wat nog niet afgerond of geannuleerd is.'
            );
        }

        $customer_id = $call->integerArgument('customer_id');
        $limit = (int) config('assistant.max_results', 25);

        $matching = Project::query()
            ->visibleTo($call->user)
            ->when(filled($query), fn ($q) => $q->matchesSearch($call->likeArgument('query')))
            ->when($customer_id, fn ($q) => $q->where('customer_id', $customer_id))
            ->when($running, fn ($q) => $q->active())
            ->when($status !== null, fn ($q) => $q->where('status', $status->value));

        $projects = (clone $matching)
            ->with(['customer:id,name', 'projectManager:id,name'])
            ->withCount(['serviceOrders as open_service_order_count' => fn ($q) => $q
                ->visibleTo($call->user)
                ->whereDoesntHave('serviceOrderStage', fn ($sq) => $sq->closesOrder())])
            ->inNumberOrder()
            ->limit($limit)
            ->get();

        $content = ['projects' => $projects->map(fn (Project $project) => [
            'project_id' => $project->id,
            'number' => $project->number,
            'title' => $project->title,
            'customer' => $project->customer?->name,
            'customer_id' => $project->customer_id,
            'project_leader' => $project->projectManager?->name,
            'status' => $project->status,
            'start_date' => $project->start_date,
            'end_date' => $project->end_date,
            'open_service_order_count' => $project->open_service_order_count,
        ])->all()];

        $choice = filled($query)
            ? $this->choiceOf(
                $projects,
                'Welk project bedoel je?',
                'project',
                'projects',
                fn (Project $project) => trim($project->label() . ' — ' . $project->customer?->name, ' —'),
            )
            : null;

        if ($choice !== null) {
            $content['choice'] = $choice;
            $content['note'] = 'De gebruiker krijgt hier knoppen voor te zien. Som ze niet nog eens op; '
                . 'zeg kort wat je vond en laat hem kiezen.';
        }

        return $this->answerWithCount($content, $projects->count(), $matching, $limit, 'projecten');
    }

    /**
     * Matched against the statuses a project can have rather than as free text,
     * so a status that exists nowhere is refused instead of coming back as nought
     * projecten.
     */
    private function statusNamed(string $name): ?ProjectStatuses
    {
        return collect(ProjectStatuses::cases())->first(
            fn (ProjectStatuses $case) => mb_strtolower($case->value) === mb_strtolower($name)
        );
    }
}
