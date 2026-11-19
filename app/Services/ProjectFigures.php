<?php

namespace App\Services;

use App\Domain\Planning\Clock;
use App\Models\Event;
use App\Models\FreeformMaterial;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\Spreadsheets\AppointmentHours;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The counting behind a project's nacalculatie: its hours, read from the
 * appointments of its werkbonnen, and the value of what went onto them. The
 * export lays it out line by line; the project page and the assistant read the
 * same numbers as a summary, so the screen and the spreadsheet never disagree.
 */
class ProjectFigures
{
    public const MATERIAL = 'Materiaal';

    public const SERVICE = 'Dienst';

    public const FREEFORM = 'Vrije regel';

    /**
     * Where the project stands, for whoever is looking: their own werkbonnen, and
     * money only when they may see a werkbon's finances. The budget amount is the
     * project's own money and needs the right to manage the project's finances.
     *
     * @return array{
     *     orders: array{total: int, closed: int},
     *     milestones: array{total: int, reached: int, overdue: int},
     *     hours: array{budget: ?float, planned: float, worked: float},
     *     money: ?array{budget: ?float, spent: float},
     * }
     */
    public function summary(Project $project, User $viewer): array
    {
        $orders = $project->serviceOrders()->visibleTo($viewer)->with([
            'serviceOrderStage',
            'events.executingUsers',
            'events.executions',
            'materials.category',
            'materials.usageUnit',
            'freeformMaterials',
            'taskInstances.materials.category',
            'taskInstances.materials.usageUnit',
            'taskInstances.freeformMaterials',
        ])->get();

        $hours = $this->hourTotals($this->appointments($orders, Clock::zone())->pluck('hours')->filter());
        $today = Clock::todayAsDate();
        $states = $project->milestones()->get()
            ->map(fn (ProjectMilestone $milestone) => $milestone->lateness($today)['state']);

        return [
            'orders' => [
                'total' => $orders->count(),
                'closed' => $orders->filter->is_closed->count(),
            ],
            'milestones' => [
                'total' => $states->count(),
                'reached' => $states->filter(fn (string $state) => in_array(
                    $state,
                    [ProjectMilestone::REACHED, ProjectMilestone::REACHED_LATE],
                    true,
                ))->count(),
                'overdue' => $states->filter(fn (string $state) => $state === ProjectMilestone::OVERDUE)->count(),
            ],
            'hours' => [
                'budget' => $project->budget_hours,
                'planned' => $hours['Geplande uren'],
                'worked' => $hours['Gewerkte uren'],
            ],
            'money' => $viewer->can('seeFinancials', ServiceOrder::class) ? [
                'budget' => $viewer->can('manageFinancials', $project) ? $project->budget_amount : null,
                'spent' => $this->moneyTotals($this->materialLines($orders, collect()))['Verkoopwaarde'],
            ] : null,
        ];
    }

    /**
     * One line per monteur per appointment; an appointment that is cancelled or
     * has no monteurs is a line without hours. An appointment on two werkbonnen
     * of the project is one appointment.
     *
     * @param  Collection<int, ServiceOrder>  $orders
     * @return Collection<int, array{event: Event, orders: Collection, member: ?User, hours: ?AppointmentHours}>
     */
    public function appointments(Collection $orders, string $timezone): Collection
    {
        $events = $orders->flatMap->events->unique('id')->sortBy(fn (Event $event) => $event->start->getTimestamp());

        return $events->flatMap(function (Event $event) use ($orders, $timezone) {
            $line = [
                'event' => $event,
                'orders' => $orders->filter(fn (ServiceOrder $order) => $order->events->contains($event)),
                'member' => null,
                'hours' => null,
            ];

            if ($event->isCancelled() || $event->executingUsers->isEmpty()) {
                return [$line];
            }

            return $event->executingUsers->sortBy('name')->map(fn (User $member) => [
                ...$line,
                'member' => $member,
                'hours' => AppointmentHours::for($event, $member, $timezone),
            ]);
        })->values();
    }

    /**
     * Every line on the project's werkbonnen and their tasks, by the headers of
     * the Materialen tab. As on the werkbon, the lines of every task count, so
     * what was billed reads the same for everyone; a line names its task only
     * when the viewer may see that task. Read them before the tasks are narrowed.
     *
     * @param  Collection<int, ServiceOrder>  $orders
     * @param  Collection<int, int>  $visible_task_ids
     * @return Collection<int, array<string, mixed>>
     */
    public function materialLines(Collection $orders, Collection $visible_task_ids): Collection
    {
        $task = fn (Model $line) => $line->task_instance && $visible_task_ids->contains($line->task_instance->id)
            ? $line->task_instance->timelineTitle()
            : null;

        return $orders->flatMap(fn (ServiceOrder $order) => $order->allMaterials()
            ->map(fn (Material $material) => $this->materialLine($order, $material, $task($material)))
            ->concat($order->allFreeformMaterials()->map(fn (FreeformMaterial $line) => [
                'Werkbon' => $order->number,
                'Taak' => $task($line),
                'Soort' => self::FREEFORM,
                'Omschrijving' => $line->description,
                'Aantal' => (float) $line->quantity,
                'Onvoorzien' => (bool) $line->unforseen,
            ])))->values();
    }

    private function materialLine(ServiceOrder $order, Material $material, ?string $task): array
    {
        $quantity = (float) $material->pivot->quantity;
        $sale = round($quantity * (float) $material->price, 2);
        $cost = round($quantity * (float) $material->cost_price, 2);

        return [
            'Werkbon' => $order->number,
            'Taak' => $task,
            'Soort' => $material->is_service ? self::SERVICE : self::MATERIAL,
            'Code' => $material->code,
            'Omschrijving' => $material->name,
            'Categorie' => $material->category?->name,
            'Eenheid' => $material->usageUnit?->name,
            'Aantal' => $quantity,
            'Prijs per eenheid' => (float) $material->price,
            'Verkoopwaarde' => $sale,
            'Onvoorzien' => (bool) $material->pivot->unforseen,
            'Kostprijs per eenheid' => (float) $material->cost_price,
            'Kostprijs' => $cost,
            'Marge' => round($sale - $cost, 2),
        ];
    }

    /**
     * @param  Collection<int, AppointmentHours>  $hours
     * @return array<string, int|float> by the hour headers of the export
     */
    public function hourTotals(Collection $hours): array
    {
        $registered = $hours->filter->isRegistered();
        $unregistered = $hours->reject->isRegistered();
        $worked = $registered->sum('worked_hours');
        $planned_for_worked = $registered->sum('planned_hours');

        return [
            'Geplande uren' => round($hours->sum('planned_hours'), 2),
            'Gewerkte uren' => round($worked, 2),
            'Gepland voor wat geregistreerd is' => round($planned_for_worked, 2),
            'Meer (+) of minder (-) dan gepland' => round($worked - $planned_for_worked, 2),
            'Nog niet geregistreerd (afspraken)' => $unregistered->count(),
            'Nog niet geregistreerd (geplande uren)' => round($unregistered->sum('planned_hours'), 2),
            'Reistijd (uren)' => round($hours->sum('travel_hours'), 2),
            'Pauze (uren)' => round($registered->sum->breakHours(), 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<string, float|null> by the money headers of the export
     */
    public function moneyTotals(Collection $lines): array
    {
        $priced = $lines->whereNotNull('Verkoopwaarde');
        $sale = round($priced->sum('Verkoopwaarde'), 2);
        $cost = round($priced->sum('Kostprijs'), 2);

        return [
            'Verkoopwaarde' => $sale,
            'Waarvan onvoorzien' => round($priced->where('Onvoorzien', true)->sum('Verkoopwaarde'), 2),
            'Kostprijs' => $cost,
            'Marge' => round($sale - $cost, 2),
            'Marge %' => $sale > 0 ? ($sale - $cost) / $sale : null,
        ];
    }
}
