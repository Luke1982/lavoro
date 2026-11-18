<?php

namespace App\Services;

use App\Enums\EventStatusses;
use App\Enums\ServiceJobOutcomes;
use App\Enums\TicketStatusses;
use App\Models\Asset;
use App\Models\Event;
use App\Models\FreeformMaterial;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Spreadsheets\AppointmentHours;
use App\Support\Spreadsheets\SheetWriter;
use App\Support\Spreadsheets\SpreadsheetSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The nacalculatie of a project as an Excel file: an overview with the totals,
 * then a tab per kind of record the totals were counted from.
 *
 * Everything reaches a project through its werkbonnen: the appointments and the
 * hours registered on them, the materials, tasks, storingen and keuringen.
 * Cancelled appointments are listed but plan and register nothing.
 *
 * Money shows only to whoever may see a werkbon's finances, as on the werkbon
 * itself; cost price and margin also need the right to see what materials cost.
 * Prices come from the material list as it is today: a werkbon line keeps no
 * price of its own.
 */
class ProjectExportService
{
    private const MATERIAL = 'Materiaal';

    private const SERVICE = 'Dienst';

    private const FREEFORM = 'Vrije regel';

    private const REACHED = 'Behaald';

    private const REACHED_LATE = 'Te laat behaald';

    private const OVERDUE = 'Over datum';

    private const OPEN = 'Open';

    private const HOUR_COLUMNS = [
        'Geplande uren' => SheetWriter::HOURS,
        'Gewerkte uren' => SheetWriter::HOURS,
        'Gepland voor wat geregistreerd is' => SheetWriter::HOURS,
        'Meer (+) of minder (-) dan gepland' => SheetWriter::HOURS,
        'Nog niet geregistreerd (afspraken)' => null,
        'Nog niet geregistreerd (geplande uren)' => SheetWriter::HOURS,
        'Reistijd (uren)' => SheetWriter::HOURS,
        'Pauze (uren)' => SheetWriter::HOURS,
    ];

    private const MONEY_COLUMNS = [
        'Verkoopwaarde' => SheetWriter::EURO,
        'Waarvan onvoorzien' => SheetWriter::EURO,
        'Kostprijs' => SheetWriter::EURO,
        'Marge' => SheetWriter::EURO,
        'Marge %' => SheetWriter::PERCENT,
    ];

    public function __construct(
        private EventLocationResolver $location_resolver,
        private TaskInstanceVisibility $task_visibility,
    ) {}

    public function build(Project $project, User $viewer, string $timezone): Spreadsheet
    {
        $this->load($project, $viewer);

        $with_money = $viewer->can('seeFinancials', ServiceOrder::class);
        $with_costs = $with_money && $viewer->can('seeFinancials', Material::class);
        $today = Carbon::parse(Carbon::now($timezone)->toDateString());

        $orders = $project->serviceOrders->sortBy('id')->values();
        $appointments = $this->appointments($orders, $timezone);
        $visible_tasks = $orders->mapWithKeys(fn (ServiceOrder $order) => [
            $order->id => $this->task_visibility->visibleTo($order, $viewer),
        ]);
        $materials = $this->materialLines($orders, $visible_tasks->flatten(1)->pluck('id'));
        $orders->each(fn (ServiceOrder $order) => $order->setRelation('taskInstances', $visible_tasks[$order->id]));
        $milestones = $project->milestones->map(fn (ProjectMilestone $item) => $this->milestone($item, $today));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $tab = fn (string $title) => $spreadsheet->addSheet(new Worksheet($spreadsheet, $title));
        $writer = fn (string $title) => new SheetWriter($tab($title), $timezone);

        $overview = $writer('Overzicht');
        $this->projectFacts($overview, $project, $viewer);
        $this->orderFacts($overview, $orders);
        $this->appointmentFacts($overview, $appointments);
        $this->hourFacts($overview, $appointments);
        $this->materialFacts($overview, $materials, $with_money, $with_costs);
        $this->milestoneFacts($overview, $milestones);
        $this->taskFacts($overview, $orders);
        $this->ticketAndJobFacts($overview, $orders);

        $this->writeTechnicians($writer('Uren per monteur'), $appointments);
        $this->writeOrders($writer('Werkbonnen'), $orders, $appointments, $materials, $with_money, $with_costs);
        $this->writeAppointments($writer('Afspraken'), $appointments);
        $this->writeMaterials($writer('Materialen'), $materials, $with_money, $with_costs);
        $this->writeMilestones($writer('Mijlpalen'), $milestones);
        $this->writeTasks($writer('Taken'), $orders);
        $this->writeTickets($writer('Storingen'), $orders);
        $this->writeServiceJobs($writer('Keuringen'), $orders);

        if ($viewer->can('manageFinancials', $project)) {
            SpreadsheetSnapshot::write($tab('Financiële notities'), $project->financial_notes);
        }

        return $spreadsheet;
    }

    private function load(Project $project, User $viewer): void
    {
        $project->load([
            'customer',
            'projectManager',
            'milestones.assignedUser',
            'serviceOrders' => fn ($query) => $query->visibleTo($viewer),
            'serviceOrders.customer',
            'serviceOrders.serviceOrderStage',
            'serviceOrders.executingUsers',
            'serviceOrders.events.eventType',
            'serviceOrders.events.executingUsers',
            'serviceOrders.events.executions',
            ...EventLocationResolver::relations('serviceOrders.events.'),
            'serviceOrders.materials.category',
            'serviceOrders.materials.usageUnit',
            'serviceOrders.freeformMaterials',
            'serviceOrders.taskInstances.serviceOrderTask',
            'serviceOrders.taskInstances.completedBy',
            'serviceOrders.taskInstances.materials.category',
            'serviceOrders.taskInstances.materials.usageUnit',
            'serviceOrders.taskInstances.freeformMaterials',
            'serviceOrders.tickets.asset.product.brand',
            'serviceOrders.tickets.closedBy',
            'serviceOrders.serviceJobs.asset.product.brand',
            'serviceOrders.serviceJobs.completedBy',
        ]);
    }

    /**
     * One line per monteur per appointment; an appointment that is cancelled or
     * has no monteurs is a line without hours. An appointment on two werkbonnen
     * of the project is one appointment.
     *
     * @param  Collection<int, ServiceOrder>  $orders
     * @return Collection<int, array{event: Event, orders: Collection, member: ?User, hours: ?AppointmentHours}>
     */
    private function appointments(Collection $orders, string $timezone): Collection
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
    private function materialLines(Collection $orders, Collection $visible_task_ids): Collection
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
     * @return array<string, int|float> by the headers of HOUR_COLUMNS
     */
    private function hourTotals(Collection $hours): array
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
     * @return array<string, float|null> by the headers of MONEY_COLUMNS
     */
    private function moneyTotals(Collection $lines): array
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

    /**
     * @return array<string, string|null>
     */
    private function moneyColumns(bool $with_money, bool $with_costs): array
    {
        return Arr::only(self::MONEY_COLUMNS, [
            ...($with_money ? ['Verkoopwaarde', 'Waarvan onvoorzien'] : []),
            ...($with_costs ? ['Kostprijs', 'Marge', 'Marge %'] : []),
        ]);
    }

    private function projectFacts(SheetWriter $overview, Project $project, User $viewer): void
    {
        $overview->facts('Project', [
            'Naam' => $project->title,
            'Klant' => $project->customer?->name,
            'Projectleider' => $project->projectManager?->name,
            'Status' => $project->status,
            'Locatie' => $project->location,
            'Startdatum' => $this->date($project->start_date),
            'Einddatum' => $this->date($project->end_date),
            'Geëxporteerd op' => Carbon::now(),
            'Geëxporteerd door' => $viewer->name,
        ], ['Startdatum' => SheetWriter::DATE, 'Einddatum' => SheetWriter::DATE]);
    }

    private function orderFacts(SheetWriter $overview, Collection $orders): void
    {
        $overview->facts('Werkbonnen', [
            'Werkbonnen' => $orders->count(),
            'Open' => $orders->reject->is_closed->count(),
            'Afgesloten' => $orders->filter->is_closed->count(),
            'Waarvan gefactureerd' => $orders->filter->is_invoiced->count(),
        ]);
    }

    private function appointmentFacts(SheetWriter $overview, Collection $appointments): void
    {
        $events = $appointments->pluck('event')->unique('id');
        $active = $events->reject->isCancelled();
        $by_status = $active->countBy('status');
        $count = fn (EventStatusses $status) => $by_status->get($status->value, 0);

        $overview->facts('Afspraken', [
            'Afspraken' => $active->count(),
            'Afgerond' => $count(EventStatusses::completed),
            'Gepland of bezig' => $count(EventStatusses::planned) + $count(EventStatusses::ongoing),
            'Geannuleerd' => $events->count() - $active->count(),
            'Eerste afspraak' => $active->min('start'),
            'Laatste afspraak' => $active->max('end'),
        ]);
    }

    private function hourFacts(SheetWriter $overview, Collection $appointments): void
    {
        $overview->facts('Uren (alle monteurs samen)', [
            'Monteurs ingezet' => $appointments->pluck('member')->filter()->unique('id')->count(),
            ...$this->hourTotals($appointments->pluck('hours')->filter()),
        ], self::HOUR_COLUMNS, 'Geannuleerde afspraken tellen niet mee. Een afspraak is geregistreerd'
            . ' als de monteur begin en eind heeft ingevuld.');
    }

    private function materialFacts(
        SheetWriter $overview,
        Collection $materials,
        bool $with_money,
        bool $with_costs,
    ): void {
        $priced = $materials->whereNotNull('Verkoopwaarde');

        $overview->facts('Materialen en diensten', [
            'Regels' => $materials->count(),
            'Onvoorziene regels' => $materials->where('Onvoorzien', true)->count(),
            'Vrije regels' => $materials->where('Soort', self::FREEFORM)->count(),
            ...($with_money ? [
                'Verkoopwaarde materialen' => round($priced->where('Soort', self::MATERIAL)->sum('Verkoopwaarde'), 2),
                'Verkoopwaarde diensten' => round($priced->where('Soort', self::SERVICE)->sum('Verkoopwaarde'), 2),
            ] : []),
            ...array_intersect_key($this->moneyTotals($materials), $this->moneyColumns($with_money, $with_costs)),
        ], [
            'Verkoopwaarde materialen' => SheetWriter::EURO,
            'Verkoopwaarde diensten' => SheetWriter::EURO,
            ...self::MONEY_COLUMNS,
        ], $with_money ? 'Prijzen uit de materialenlijst zoals die nu is. Vrije regels hebben geen prijs.' : null);
    }

    private function milestoneFacts(SheetWriter $overview, Collection $milestones): void
    {
        $by_status = $milestones->countBy('Status');

        $overview->facts('Mijlpalen', [
            'Mijlpalen' => $milestones->count(),
            'Behaald' => $by_status->get(self::REACHED, 0) + $by_status->get(self::REACHED_LATE, 0),
            'Waarvan te laat' => $by_status->get(self::REACHED_LATE, 0),
            'Open en over datum' => $by_status->get(self::OVERDUE, 0),
            'Grootste vertraging (dagen)' => $milestones->max('Dagen te laat') ?? 0,
        ]);
    }

    private function taskFacts(SheetWriter $overview, Collection $orders): void
    {
        $tasks = $orders->flatMap->taskInstances;
        $active = $tasks->reject->is_cancelled;

        $overview->facts('Taken', [
            'Taken' => $active->count(),
            'Afgerond' => $active->filter->is_complete->count(),
            'Open' => $active->reject->is_complete->count(),
            'Geannuleerd' => $tasks->count() - $active->count(),
        ]);
    }

    private function ticketAndJobFacts(SheetWriter $overview, Collection $orders): void
    {
        $tickets = $orders->flatMap->tickets;
        $service_jobs = $orders->flatMap->serviceJobs;
        $closed = $tickets->where('status', TicketStatusses::gesloten->value)->count();
        $by_outcome = $service_jobs->countBy('outcome');

        $overview->facts('Storingen en keuringen', [
            'Storingen' => $tickets->count(),
            'Storingen open' => $tickets->count() - $closed,
            'Storingen gesloten' => $closed,
            'Keuringen' => $service_jobs->count(),
            ...collect(ServiceJobOutcomes::cases())->mapWithKeys(fn (ServiceJobOutcomes $outcome) => [
                'Keuringen ' . mb_strtolower($outcome->value) => $by_outcome->get($outcome->value, 0),
            ]),
        ]);
    }

    private function writeTechnicians(SheetWriter $writer, Collection $appointments): void
    {
        $staffed = $appointments->whereNotNull('member');
        $row = fn (string $name, Collection $lines) => [
            'Monteur' => $name,
            'Afspraken' => $lines->count(),
            ...$this->hourTotals($lines->pluck('hours')),
        ];

        $per_member = $staffed
            ->groupBy(fn (array $line) => $line['member']->id)
            ->map(fn (Collection $lines) => $row($lines->first()['member']->name, $lines))
            ->sortBy('Monteur');

        $writer->table(
            ['Monteur', 'Afspraken', ...self::HOUR_COLUMNS],
            $per_member,
            $per_member->count() > 1 ? $row('Totaal', $staffed) : null,
        );
    }

    private function writeOrders(
        SheetWriter $writer,
        Collection $orders,
        Collection $appointments,
        Collection $materials,
        bool $with_money,
        bool $with_costs,
    ): void {
        $materials_per_order = $materials->groupBy('Werkbon');

        $writer->table([
            'Werkbon', 'Omschrijving', 'Klant', 'Fase',
            'Orderdatum' => SheetWriter::DATE,
            'Afgesloten op' => SheetWriter::DATE,
            'Monteurs', 'Afspraken',
            ...Arr::only(self::HOUR_COLUMNS, ['Geplande uren', 'Gewerkte uren', 'Reistijd (uren)']),
            ...$this->moneyColumns($with_money, $with_costs),
            'Taken afgerond', 'Taken totaal', 'Storingen', 'Keuringen',
            'Inkoopordernummer klant', 'Factuurnummer', 'Gefactureerd',
            ...($with_money ? ['Financiële opmerkingen'] : []),
        ], $orders->map(function (ServiceOrder $order) use ($appointments, $materials_per_order) {
            $tasks = $order->taskInstances->reject->is_cancelled;
            $hours = $appointments
                ->filter(fn (array $line) => $line['orders']->contains($order))
                ->pluck('hours')
                ->filter();

            return [
                'Werkbon' => $order->number,
                'Omschrijving' => $order->description,
                'Klant' => $order->customer?->name,
                'Fase' => $order->serviceOrderStage?->name,
                'Orderdatum' => $this->date($order->order_date),
                'Afgesloten op' => $this->date($order->closed_on),
                'Monteurs' => $order->executingUsers->pluck('name')->join(', '),
                'Afspraken' => $order->events->reject->isCancelled()->count(),
                ...$this->hourTotals($hours),
                ...$this->moneyTotals($materials_per_order->get($order->number, collect())),
                'Taken afgerond' => $tasks->filter->is_complete->count(),
                'Taken totaal' => $tasks->count(),
                'Storingen' => $order->tickets->count(),
                'Keuringen' => $order->serviceJobs->count(),
                'Inkoopordernummer klant' => $order->external_purchaseorder_no,
                'Factuurnummer' => $order->external_invoice_no,
                'Gefactureerd' => $order->is_invoiced,
                'Financiële opmerkingen' => $order->financial_comments,
            ];
        }));
    }

    private function writeAppointments(SheetWriter $writer, Collection $appointments): void
    {
        $writer->table(
            ['Afspraak', 'Type', 'Werkbon', 'Monteur', 'Locatie', ...AppointmentHours::COLUMNS],
            $appointments->map(fn (array $line) => [
                'Afspraak' => $line['event']->name ?: $line['event']->eventType?->name,
                'Type' => $line['event']->eventType?->name,
                'Werkbon' => $line['orders']->pluck('number')->join(', '),
                'Monteur' => $line['member']?->name,
                'Locatie' => $this->location_resolver->resolve($line['event']),
                ...($line['hours']?->row() ?? [
                    'Geplande start' => $line['event']->start,
                    'Geplande eind' => $line['event']->end,
                    'Status' => $line['event']->isCancelled() ? EventStatusses::cancelled->value : 'Geen monteur',
                ]),
            ]),
        );
    }

    private function writeMaterials(
        SheetWriter $writer,
        Collection $materials,
        bool $with_money,
        bool $with_costs,
    ): void {
        $euros = fn (string ...$headers) => array_fill_keys($headers, SheetWriter::EURO);

        $writer->table([
            'Werkbon', 'Taak', 'Soort', 'Code', 'Omschrijving', 'Categorie', 'Eenheid', 'Aantal',
            ...($with_money ? $euros('Prijs per eenheid', 'Verkoopwaarde') : []),
            'Onvoorzien',
            ...($with_costs ? $euros('Kostprijs per eenheid', 'Kostprijs', 'Marge') : []),
        ], $materials);
    }

    private function writeMilestones(SheetWriter $writer, Collection $milestones): void
    {
        $writer->table([
            'Mijlpaal', 'Omschrijving', 'Verantwoordelijke',
            'Geplande datum' => SheetWriter::DATE,
            'Werkelijke datum' => SheetWriter::DATE,
            'Dagen te laat', 'Status',
        ], $milestones);
    }

    /**
     * @return array<string, mixed> by the headers of the Mijlpalen tab
     */
    private function milestone(ProjectMilestone $milestone, Carbon $today): array
    {
        $planned = $this->date($milestone->projected_date);
        $reached = $this->date($milestone->actual_date);

        $days_late = match (true) {
            $planned === null => null,
            $reached !== null => max(0, (int) $planned->diffInDays($reached, false)),
            $planned->lt($today) => (int) $planned->diffInDays($today, false),
            default => null,
        };

        return [
            'Mijlpaal' => $milestone->title,
            'Omschrijving' => $milestone->description,
            'Verantwoordelijke' => $milestone->assignedUser?->name,
            'Geplande datum' => $planned,
            'Werkelijke datum' => $reached,
            'Dagen te laat' => $days_late,
            'Status' => match (true) {
                $reached !== null => $days_late ? self::REACHED_LATE : self::REACHED,
                $days_late !== null => self::OVERDUE,
                default => self::OPEN,
            },
        ];
    }

    private function writeTasks(SheetWriter $writer, Collection $orders): void
    {
        $writer->table(
            ['Werkbon', 'Taak', 'Aantal', 'Status', 'Afgerond door', 'Afgerond op' => SheetWriter::DATETIME,
                'Reden annulering'],
            $orders->flatMap(fn (ServiceOrder $order) => $order->taskInstances->map(
                fn (ServiceOrderTaskInstance $task) => [
                    'Werkbon' => $order->number,
                    'Taak' => $task->timelineTitle(),
                    'Aantal' => $task->quantity,
                    'Status' => match (true) {
                        $task->is_cancelled => 'Geannuleerd',
                        $task->is_complete => 'Afgerond',
                        default => self::OPEN,
                    },
                    'Afgerond door' => $task->completedBy?->name,
                    'Afgerond op' => $task->completed_at,
                    'Reden annulering' => $task->cancellation_reason,
                ]
            )),
        );
    }

    private function writeTickets(SheetWriter $writer, Collection $orders): void
    {
        $writer->table(
            ['Werkbon', 'Onderwerp', 'Machine', 'Status', 'Prioriteit', 'Gemeld op' => SheetWriter::DATETIME,
                'Gesloten op' => SheetWriter::DATE, 'Gesloten door'],
            $orders->flatMap(fn (ServiceOrder $order) => $order->tickets->map(fn (Ticket $ticket) => [
                'Werkbon' => $order->number,
                'Onderwerp' => $ticket->subject,
                'Machine' => $this->assetLabel($ticket->asset),
                'Status' => $ticket->status,
                'Prioriteit' => $ticket->priority,
                'Gemeld op' => $ticket->created_at,
                'Gesloten op' => $this->date($ticket->closed_on),
                'Gesloten door' => $ticket->closedBy?->name,
            ])),
        );
    }

    private function writeServiceJobs(SheetWriter $writer, Collection $orders): void
    {
        $writer->table(
            ['Werkbon', 'Machine', 'Uitkomst', 'Afgerond op' => SheetWriter::DATE, 'Afgerond door', 'Omschrijving'],
            $orders->flatMap(fn (ServiceOrder $order) => $order->serviceJobs->map(fn (ServiceJob $service_job) => [
                'Werkbon' => $order->number,
                'Machine' => $this->assetLabel($service_job->asset),
                'Uitkomst' => $service_job->outcome,
                'Afgerond op' => $this->date($service_job->completed_on),
                'Afgerond door' => $service_job->completedBy?->name,
                'Omschrijving' => $service_job->description,
            ])),
        );
    }

    private function assetLabel(?Asset $asset): ?string
    {
        $serial = $asset?->serial_number ? 'SN ' . $asset->serial_number : null;

        return collect([$asset?->product?->display_name, $serial])->filter()->join(' · ') ?: null;
    }

    private function date(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
