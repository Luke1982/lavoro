<?php

namespace App\Domain\Tools\Read;

use App\Domain\Planning\Clock;
use App\Domain\Tools\Read\Concerns\ReportsTheWholeCount;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ProjectFigures;

/**
 * One rounded picture of a project, so the assistant does not have to make four
 * calls and stitch the answers together.
 *
 * The werkbonnen run through the same visibility scope as the dedicated tool, and
 * the numbers come from ProjectFigures, so the assistant, the project page and
 * the export never disagree about how far a project has got.
 */
class SummarizeProjectTool implements Tool
{
    use ReportsTheWholeCount;

    public static function name(): string
    {
        return 'summarize_project';
    }

    public function description(): string
    {
        return 'Geeft een overzicht van één project: klant, projectleider, status en looptijd, de mijlpalen '
            . 'en hoeveel dagen ze te laat zijn, de werkbonnen en de uren tegenover het budget. Gebruik dit '
            . 'als iemand vraagt hoe een project ervoor staat. De geschiedenis, zoals wie de status '
            . 'veranderde, staat hier niet in: zoek die met search_activity en subject_type "project".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Het id van het project, te vinden met find_project.',
                ],
            ],
            'required' => ['project_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Asked of this project when it exists. One that does not is left to execute,
     * which says so, rather than reported as a refusal.
     */
    public function authorize(User $user, array $arguments): bool
    {
        $project = Project::find((int) ($arguments['project_id'] ?? 0));

        return $project ? $user->can('view', $project) : $user->can('list', Project::class);
    }

    /** Eén argument, maar het vraagt wel te zien dat een overzicht gevraagd wordt in plaats van een losse zoekopdracht. */
    public static function difficulty(): int
    {
        return 3;
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
     * Amounts that are left out are said to be left out, or a missing amount reads
     * as nothing having been spent.
     */
    public function execute(ToolCall $call): ToolResult
    {
        $project = Project::query()
            ->with([
                'customer:id,name',
                'projectManager:id,name',
                'contact',
                'linkedLocation',
                'milestones.assignedUser:id,name',
            ])
            ->find($call->integerArgument('project_id'));

        if (!$project) {
            return ToolResult::notFound('Project');
        }

        $limit = (int) config('assistant.max_results', 25);
        $today = Clock::todayAsDate();

        $visible_orders = ServiceOrder::query()
            ->visibleTo($call->user)
            ->where('project_id', $project->id);

        $orders = (clone $visible_orders)
            ->with('serviceOrderStage:id,name,is_closed_state,is_invoiced_state')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $total_orders = $this->howManyInAll($visible_orders, $orders->count(), $limit);
        $milestone_titles = $project->milestones->pluck('title', 'id');
        $figures = app(ProjectFigures::class)->summary($project, $call->user);

        $content = [
            'project' => [
                'project_id' => $project->id,
                'number' => $project->number,
                'title' => $project->title,
                'description' => $project->description,
                'status' => $project->status,
                'is_closed' => $project->isClosed(),
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'customer' => $project->customer?->name,
                'customer_id' => $project->customer_id,
                'project_leader' => $project->projectManager?->name,
                'contact' => $project->contact?->full_name,
                'location' => $project->addressLine(),
            ],
            'milestones' => $project->milestones->map(fn (ProjectMilestone $milestone) => [
                'milestone_id' => $milestone->id,
                'title' => $milestone->title,
                'projected_date' => $milestone->projected_date,
                'actual_date' => $milestone->actual_date,
                'assigned_to' => $milestone->assignedUser?->name,
                ...$milestone->lateness($today),
            ])->all(),
            'service_orders' => $orders->map(fn (ServiceOrder $order) => [
                'service_order_id' => $order->id,
                'number' => $order->number,
                'description' => $order->description,
                'stage' => $order->serviceOrderStage?->name,
                'is_closed' => $order->is_closed,
                'milestone' => $milestone_titles[$order->project_milestone_id] ?? null,
            ])->all(),
            'figures' => $figures,
        ];

        $notes = [];

        if ($orders->count() < $total_orders) {
            $notes[] = 'Van de ' . $total_orders . ' werkbonnen zie je hier de laatste ' . $orders->count()
                . '; de aantallen in figures gaan over allemaal.';
        }

        if ($figures['money'] === null) {
            $notes[] = 'Bedragen zijn weggelaten omdat deze gebruiker ze niet mag zien; noem of schat er geen.';
        }

        if ($notes !== []) {
            $content['note'] = implode(' ', $notes);
        }

        return ToolResult::ok($content, 'Overzicht van project ' . $project->label() . '.');
    }
}
