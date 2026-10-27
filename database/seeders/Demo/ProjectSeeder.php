<?php

namespace Database\Seeders\Demo;

use App\Enums\EventStatusses;
use App\Enums\ServiceOrderTypes;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTaskInstance;
use App\Models\User;
use RuntimeException;

/**
 * The projects, with their milestones, their budget and a work order per
 * phase.
 *
 * Runs before the planning: the days a team spends on a project are booked in
 * the context, and the planning leaves those free.
 */
final class ProjectSeeder
{
    private EventType $installation;

    /** @var array<string, ServiceOrderStage> keyed by flag */
    private array $stages = [];

    public function __construct(private DemoContext $context) {}

    public function run(): void
    {
        $colors = $this->context->data('work')['event_types'];

        $this->installation = EventType::firstOrCreate(['name' => 'Installatie'], ['color' => $colors['Installatie']]);

        foreach (['is_plannable_state', 'is_planned_state', 'is_closed_state', 'is_invoiced_state'] as $flag) {
            $this->stages[$flag] = ServiceOrderStage::where($flag, true)->orderBy('order')->firstOrFail();
        }

        foreach ($this->context->data('projects') as $entry) {
            $this->project($entry);
        }
    }

    private function project(array $entry): void
    {
        [$customer, $location] = $this->site($entry);
        $leader = $this->person('kees');

        $project = Project::forceCreate([
            'title' => $entry['title'],
            'description' => $entry['description'],
            'location' => $location
                ? "{$location->address}, {$location->postal_code} {$location->city}"
                : "{$customer->address}, {$customer->postal_code} {$customer->city}",
            'start_date' => $this->date($entry['start']),
            'end_date' => $this->date($entry['end']),
            'customer_id' => $customer->id,
            'project_manager_id' => $leader->id,
            'status' => $entry['status'],
            'financial_notes' => $this->budget($entry['budget']),
            'financial_notes_updated_at' => $this->context->at(min($entry['end'], $this->today() - 1), '16:40'),
            'financial_notes_updated_by' => $this->person('eva')->id,
            'created_at' => $this->context->at(min($entry['start'] - 10, $this->today() - 1), '10:15'),
        ]);

        foreach ($entry['milestones'] as [$title, $day, $who]) {
            ProjectMilestone::forceCreate([
                'project_id' => $project->id,
                'title' => $title,
                'projected_date' => $this->date($day),
                'actual_date' => $this->over($day, '17:00') ? $this->date($day) : null,
                'assigned_user_id' => $this->person($who)->id,
            ]);
        }

        $team = array_map($this->person(...), $entry['team']);

        foreach ($entry['phases'] as [$title, $days, $tasks]) {
            $this->phase($project, $entry['start'], $customer, $location, $leader, $team, $title, $days, $tasks);
        }
    }

    /**
     * A work order for one phase, with an appointment for the whole team on
     * each of its days. The tasks are ticked off as the days go by.
     *
     * @param  array<int, User>  $team
     * @param  array<int, int>  $days
     * @param  array<int, string>  $tasks
     */
    private function phase(Project $project, int $from, Customer $customer, ?Location $location, User $leader, array $team, string $title, array $days, array $tasks): void
    {
        $done = count(array_filter($days, fn (int $day) => $this->over($day, '16:00')));
        $finished = $days !== [] && $done === count($days);
        $last = $days === [] ? null : max($days);

        $stage = match (true) {
            $days === [] => $this->stages['is_plannable_state'],
            !$finished => $this->stages['is_planned_state'],
            $this->context->day($last)->lessThan($this->context->now->subDays(7)) => $this->stages['is_invoiced_state'],
            default => $this->stages['is_closed_state'],
        };

        $ordered = $this->context->at(min(($days === [] ? $from : min($days)) - 7, $this->today() - 1), '09:30');

        $order = ServiceOrder::forceCreate([
            'customer_id' => $customer->id,
            'location_id' => $location?->id,
            'project_id' => $project->id,
            'description' => $title,
            'type' => ServiceOrderTypes::installation->value,
            'service_order_stage_id' => $stage->id,
            'created_at' => $ordered,
            'updated_at' => $finished ? $this->context->at($last, '16:00') : $ordered,
            'order_date' => $ordered->setTimezone(DemoContext::zone())->toDateString(),
            'work_completed' => $finished,
            'closed_on' => $finished ? $this->date($last) : null,
            'signed_by' => $finished ? $customer->contactname : null,
        ]);

        $order->owners()->attach($leader->id, ['type' => 'owner']);

        foreach ($tasks as $index => $task) {
            $ticked = $days !== [] && $index < (int) round(count($tasks) * $done / count($days));

            ServiceOrderTaskInstance::forceCreate([
                'service_order_id' => $order->id,
                'title' => $task,
                'is_complete' => $ticked,
                'completed_at' => $ticked ? $this->context->at($days[min($done, count($days)) - 1], '15:30') : null,
                'completed_by' => $ticked ? $team[0]->id : null,
            ]);
        }

        if ($days === []) {
            return;
        }

        foreach ($team as $mechanic) {
            $order->addExecutingUser($mechanic->id);
        }

        foreach ($days as $day) {
            $this->appointment($order, $team, $title, $day, $location);
        }
    }

    /** @param  array<int, User>  $team */
    private function appointment(ServiceOrder $order, array $team, string $title, int $day, ?Location $location): void
    {
        $start = $this->context->at($day, '07:30');
        $end = $this->context->at($day, '16:00');

        $event = Event::create([
            'event_type_id' => $this->installation->id,
            'name' => $title,
            'start' => $start,
            'end' => $end,
            'status' => match (true) {
                $end->lessThanOrEqualTo($this->context->now) => EventStatusses::completed->value,
                $start->lessThanOrEqualTo($this->context->now) => EventStatusses::ongoing->value,
                default => EventStatusses::planned->value,
            },
            'location_id' => $location?->id,
        ]);

        $order->events()->attach($event->id);

        foreach ($team as $mechanic) {
            if (isset($this->context->booked[$day][$mechanic->id])) {
                throw new RuntimeException("{$mechanic->name} is on two projects on day {$day}");
            }

            $event->addExecutingUser($mechanic->id);
            $this->context->booked[$day][$mechanic->id] = true;
        }
    }

    /**
     * The budget as the project page's spreadsheet holds it: a line per item,
     * what is left of it, and the totals under them. Amounts as text, the way
     * they read, not as numbers the sheet would show without separators.
     *
     * @param  array<int, array{0: string, 1: int, 2: int, 3: string}>  $lines
     */
    private function budget(array $lines): array
    {
        $euro = fn (int $amount) => '€ ' . number_format($amount, 0, ',', '.');
        $rows = [['Post', 'Begroot', 'Besteed', 'Resterend', 'Opmerking']];

        foreach ($lines as [$item, $budget, $spent, $note]) {
            $rows[] = [$item, $euro($budget), $euro($spent), $euro($budget - $spent), $note];
        }

        $budget = array_sum(array_column($lines, 1));
        $spent = array_sum(array_column($lines, 2));
        $rows[] = ['Totaal', $euro($budget), $euro($spent), $euro($budget - $spent), ''];

        $style = [];
        $last = count($rows);

        foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
            $align = in_array($column, ['A', 'E'], true) ? 'text-align: left;' : 'text-align: right;';

            foreach (range(1, $last) as $row) {
                $style["{$column}{$row}"] = $align . match ($row) {
                    1 => ' font-weight: bold; background-color: #f1f5f9;',
                    $last => ' font-weight: bold; border-top: 1px solid #94a3b8;',
                    default => '',
                };
            }
        }

        return [
            'data' => $rows,
            'style' => $style,
            'mergeCells' => (object) [],
            'columns' => [['width' => 360], ['width' => 110], ['width' => 110], ['width' => 110], ['width' => 300]],
        ];
    }

    /** @return array{0: Customer, 1: ?Location} */
    private function site(array $entry): array
    {
        foreach ($this->context->customers as $candidate) {
            if ($candidate['customer']->name !== $entry['customer']) {
                continue;
            }

            if (!isset($entry['site'])) {
                return [$candidate['customer'], null];
            }

            foreach ($candidate['sites'] as $site) {
                if ($site['location']?->title === $entry['site']) {
                    return [$candidate['customer'], $site['location']];
                }
            }
        }

        throw new RuntimeException("Project for unknown demo customer or site: {$entry['customer']}");
    }

    private function person(string $local): User
    {
        foreach ($this->context->users as $email => $user) {
            if (strtok($email, '@') === $local) {
                return $user;
            }
        }

        throw new RuntimeException("Unknown demo person: {$local}");
    }

    /** Whether that day has passed the given time already. */
    private function over(int $day, string $time): bool
    {
        return $this->context->at($day, $time)->lessThanOrEqualTo($this->context->now);
    }

    private function today(): int
    {
        return $this->context->now->dayOfWeekIso - 1;
    }

    private function date(int $day): string
    {
        $date = $this->context->day($day);

        if ($date->isWeekend()) {
            throw new RuntimeException("Demo project date falls on a weekend: day {$day}");
        }

        return $date->toDateString();
    }
}
