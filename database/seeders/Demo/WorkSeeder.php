<?php

namespace Database\Seeders\Demo;

use App\Enums\EventStatusses;
use App\Enums\ServiceJobOutcomes;
use App\Enums\ServiceOrderTypes;
use App\Enums\TicketPriorities;
use App\Enums\TicketStatusses;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Remark;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The work: two weeks behind, this week and two weeks ahead in the planner,
 * orders still waiting for a date, and the tickets that led to them.
 *
 * Everything is placed relative to now. What ended before now is finished --
 * checklists filled in, the fault written down, a ticket closed -- what is
 * running now is under way, and the rest is planned, thinning out towards the
 * end the way a real planning does.
 */
final class WorkSeeder
{
    /** Which families of the product tree each plan group works on. */
    private const SPECIALISMS = [
        'Team Airco' => ['Airconditioning', 'Koeltechniek'],
        'Team Warmte' => ['Warmtepompen', 'Verwarming', 'Warm tapwater', 'Regeltechniek', 'Zonne-energie'],
        'Service & ventilatie' => ['Ventilatie', 'Airconditioning', 'Verwarming'],
    ];

    /** @var array<string, EventType> */
    private array $event_types = [];

    /** @var array<string, ServiceOrderStage> keyed by flag */
    private array $stages = [];

    /** @var array<string, true> site and family already maintained in this window */
    private array $maintained = [];

    private User $planner;

    private User $desk;

    /** Minute of the day the last appointment ended, for the next one to start after. */
    private int $last_end = 0;

    public function __construct(private DemoContext $context) {}

    public function run(): void
    {
        $work = $this->context->data('work');

        foreach ($work['event_types'] as $name => $color) {
            $this->event_types[$name] = EventType::create(['name' => $name, 'color' => $color]);
        }

        foreach (['is_plannable_state', 'is_planned_state', 'is_closed_state', 'is_invoiced_state', 'is_incomplete_state', 'is_planning_cancelled_state'] as $flag) {
            $this->stages[$flag] = ServiceOrderStage::where($flag, true)->orderBy('order')->firstOrFail();
        }

        $this->planner = $this->context->users['mark@lavorofsm.nl'];
        $this->desk = $this->context->users['lisa@lavorofsm.nl'];

        $this->planning();
        $this->unplanned();
        $this->remoteTickets();
    }

    private function planning(): void
    {
        foreach (range(-14, 18) as $day) {
            if ($this->context->day($day)->isWeekend()) {
                continue;
            }

            $busy = [];

            foreach ($this->context->mechanics as $mechanic) {
                if (isset($busy[$mechanic->id]) || $this->absent($mechanic, $day) || !$this->context->chance($this->fill($day))) {
                    continue;
                }

                if ($this->context->chance(0.07) && ($partner = $this->partner($mechanic, $day, $busy))) {
                    $busy[$partner->id] = true;
                    $this->appointment('install', [$mechanic, $partner], $day, 7 * 60 + 30, 16 * 60);

                    continue;
                }

                $this->workday($mechanic, $day);
            }
        }
    }

    /**
     * How full a day is. The past and this week are nearly full, the coming
     * weeks less so: those are still being filled.
     */
    private function fill(int $day): float
    {
        return match (true) {
            $day < 7 => 0.92,
            $day < 14 => 0.72,
            default => 0.45,
        };
    }

    /**
     * A fault gets a mechanic within days. Booked two weeks out it would be a
     * complaint, not a planning -- further ahead there is only maintenance,
     * installation and the odd survey.
     */
    private function soonEnoughForFaults(int $day): bool
    {
        return $day <= $this->context->now->dayOfWeekIso - 1 + 3;
    }

    private function absent(User $mechanic, int $day): bool
    {
        return $mechanic->email === 'bas@lavorofsm.nl' && $day >= 9 && $day <= 11;
    }

    /** Someone from the same team who is free that day, for a two-man job. */
    private function partner(User $mechanic, int $day, array $busy): ?User
    {
        $team = $this->groupOf($mechanic);

        foreach ($this->context->mechanics as $candidate) {
            if ($candidate->isNot($mechanic) && !isset($busy[$candidate->id])
                && $this->groupOf($candidate) === $team && !$this->absent($candidate, $day)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A mechanic's day: from eight until half past four, visit after visit with
     * travel in between and a lunch break around noon. How long a visit takes
     * follows from the work -- maintenance on six machines is not the same job
     * as on one.
     */
    private function workday(User $mechanic, int $day): void
    {
        $until = $mechanic->email === 'yusuf@lavorofsm.nl' && $this->context->day($day)->isFriday()
            ? 12 * 60
            : 16 * 60 + 30;
        $minute = 8 * 60;
        $lunch = false;
        $done = ['fault' => 0, 'survey' => 0];

        while ($minute < $until - 45) {
            $preferred = $this->context->pick(['maintenance', 'maintenance', 'maintenance', 'maintenance', 'fault', 'fault', 'survey']);

            /**
             * When the preferred kind has nothing left or does not fit in the
             * time that is left, a shorter or different visit may; the day only
             * ends when none does. Two faults and one survey a day at most: a
             * mechanic chasing five faults in a day is having a very bad week,
             * and surveys are what a salesman does, now and then.
             */
            $placed = null;

            foreach (array_unique([$preferred, 'maintenance', 'fault', 'survey']) as $kind) {
                if (($kind === 'fault' && ($done['fault'] >= 2 || !$this->soonEnoughForFaults($day)))
                    || ($kind === 'survey' && $done['survey'] >= 1)) {
                    continue;
                }

                if ($this->appointment($kind, [$mechanic], $day, $minute, $until)) {
                    $placed = $kind;

                    break;
                }
            }

            if (!$placed) {
                break;
            }

            if (isset($done[$placed])) {
                $done[$placed]++;
            }

            $minute = $this->last_end + $this->context->pick([20, 30, 45]);

            if (!$lunch && $minute >= 12 * 60) {
                $minute += 30;
                $lunch = true;
            }
        }
    }

    /**
     * One visit: the order, its jobs, the appointment in the planner and, for a
     * fault, the ticket it came from. What has already happened is finished
     * the way a mechanic finishes it.
     *
     * @param  array<int, User>  $mechanics
     */
    private function appointment(string $kind, array $mechanics, int $day, int $minute, int $until): bool
    {
        $families = self::SPECIALISMS[$this->groupOf($mechanics[0])] ?? ['Airconditioning'];
        $visit = $this->visit($kind, $families);

        if (!$visit) {
            return false;
        }

        [$customer, $site, $assets, $family, $key] = $visit;
        $work = $this->context->data('work');

        $minutes = (int) (ceil(match ($kind) {
            'install' => $until - $minute,
            'maintenance' => min(240, 60 + 30 * count($assets)),
            'fault' => $this->context->pick([60, 75, 90, 120]),
            default => $this->context->pick([60, 90]),
        } / 15) * 15);

        if ($minute + $minutes > $until) {
            return false;
        }

        /** Only now: a visit that does not fit the day was not made, and the site is still due. */
        if ($kind === 'maintenance') {
            $this->maintained[$key] = true;
        }

        $this->last_end = $minute + $minutes;

        $start = $this->context->at($day, sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60));
        $end = $start->addMinutes($minutes);
        $phase = match (true) {
            $end->lessThanOrEqualTo($this->context->now) => 'done',
            $start->lessThanOrEqualTo($this->context->now) => 'running',
            default => 'planned',
        };

        $fault = $kind === 'fault' ? $this->context->pick($work['faults'][$family]) : null;
        $description = match ($kind) {
            'fault' => $fault[0],
            'install' => $this->context->pick($work['installations']),
            'survey' => $this->context->pick($work['surveys'][$family]),
            default => $this->context->pick($work['maintenance'][$family]),
        };

        $incomplete = $phase === 'done' && $kind !== 'survey' && $this->context->chance(0.06);
        $order = $this->order($customer, $site, $description, $kind, $start, $phase, $incomplete);

        foreach ($mechanics as $mechanic) {
            $order->addExecutingUser($mechanic->id);
        }

        $event = Event::create([
            'event_type_id' => $this->eventType($kind, $phase === 'done' && $this->context->chance(0.1))->id,
            'name' => $description,
            'start' => $start,
            'end' => $end,
            'status' => match ($phase) {
                'done' => EventStatusses::completed->value,
                'running' => EventStatusses::ongoing->value,
                default => EventStatusses::planned->value,
            },
            'location_id' => $site['location']?->id,
        ]);

        $order->events()->attach($event->id);

        /**
         * No registered times: a mechanic's "done" greys the appointment out and
         * hatches it, and a planning full of grey shows nothing of what the
         * planner looks like. The orders and checklists still say what was done.
         */
        foreach ($mechanics as $mechanic) {
            $event->addExecutingUser($mechanic->id);
        }

        foreach ($assets as $asset) {
            $this->job($order, $asset, $kind, $phase, $incomplete, $fault, $mechanics[0], $start);
        }

        if ($fault) {
            $this->ticket($order, $assets[0], $fault, $phase, $customer, $mechanics[0], $start);
        }

        if ($incomplete) {
            $this->remark($order, $mechanics[0], $this->context->pick($work['incomplete']), $end);
        } elseif ($phase === 'done' && $fault) {
            $this->remark($order, $mechanics[0], $fault[2], $end);
        }

        return true;
    }

    /**
     * Where to go and what to look at. Maintenance visits a site once in this
     * window; a fault picks one machine and may come back.
     *
     * @param  array<int, string>  $families
     * @return array{0: Customer, 1: array, 2: array<int, Asset>, 3: string, 4: string}|null
     */
    private function visit(string $kind, array $families): ?array
    {
        $options = [];

        foreach ($this->context->customers as $entry) {
            foreach ($entry['sites'] as $index => $site) {
                foreach ($families as $family) {
                    $assets = array_values(array_filter($site['assets'],
                        fn (Asset $asset) => $this->context->families[$asset->product->productType->name] === $family));

                    $key = $entry['customer']->id . '-' . $index . '-' . $family;

                    if ($assets && !($kind === 'maintenance' && isset($this->maintained[$key]))) {
                        $options[] = [$entry['customer'], $site, $assets, $family, $key];
                    }
                }
            }
        }

        if (!$options) {
            return null;
        }

        [$customer, $site, $assets, $family, $key] = $this->context->pick($options);

        return match ($kind) {
            'fault' => [$customer, $site, [$this->context->pick($assets)], $family, $key],
            'survey' => [$customer, $site, [], $family, $key],
            'install' => [$customer, $site, array_slice($assets, 0, 2), $family, $key],
            default => [$customer, $site, array_slice($assets, 0, 6), $family, $key],
        };
    }

    private function order(Customer $customer, array $site, string $description, string $kind, CarbonImmutable $start, string $phase, bool $incomplete): ServiceOrder
    {
        $stage = match (true) {
            $incomplete => $this->stages['is_incomplete_state'],
            $phase !== 'done' => $this->stages['is_planned_state'],
            $start->lessThan($this->context->now->subDays(7)) && $this->context->chance(0.6) => $this->stages['is_invoiced_state'],
            default => $this->stages['is_closed_state'],
        };

        $finished = $phase === 'done' && !$incomplete;

        /**
         * Made on the day it was ordered, not on the night the demo was built:
         * the dashboard counts new orders by when they were made, and with
         * everything made tonight it reported a jump of three hundred percent.
         * Maintenance comes from contracts and is ordered weeks ahead; a fault
         * is ordered the day it is reported.
         */
        $ordered = $start->subDays(match ($kind) {
            'fault' => $this->context->between(0, 2),
            'maintenance' => $this->context->between(10, 60),
            default => $this->context->between(3, 25),
        })->setTime($this->context->between(7, 16), $this->context->between(0, 59));

        $order = ServiceOrder::forceCreate([
            'customer_id' => $customer->id,
            'location_id' => $site['location']?->id,
            'description' => $description,
            'type' => $kind === 'install' ? ServiceOrderTypes::installation->value : ServiceOrderTypes::service->value,
            'service_order_stage_id' => $stage->id,
            'created_at' => $ordered,
            'updated_at' => $phase === 'done' ? $start : $ordered,
            'order_date' => $ordered->setTimezone(DemoContext::zone())->toDateString(),
            'work_completed' => $finished,
            'closed_on' => $finished ? $start->setTimezone(DemoContext::zone())->toDateString() : null,
            'signed_by' => $finished ? $customer->contactname : null,
        ]);

        $order->owners()->attach(($kind === 'fault' ? $this->desk : $this->planner)->id, ['type' => 'owner']);

        return $order;
    }

    private function eventType(string $kind, bool $found_something): EventType
    {
        return $this->event_types[match ($kind) {
            'fault' => 'Oplossen storing',
            'install' => 'Installatie',
            'survey' => 'Inventarisatie',
            default => $found_something ? 'Controle met storingen' : 'Periodieke controle',
        }];
    }

    /**
     * A job per machine. A finished one has its checklist filled in with values
     * a healthy installation shows, and its next service moved forward.
     */
    private function job(ServiceOrder $order, Asset $asset, string $kind, string $phase, bool $incomplete, ?array $fault, User $mechanic, CarbonImmutable $start): void
    {
        $finished = $phase === 'done' && !$incomplete;

        $outcome = match (true) {
            !$finished => ServiceJobOutcomes::nog_geen_uitkomst,
            $kind === 'fault' => ServiceJobOutcomes::reparatie,
            $this->context->chance(0.03) => ServiceJobOutcomes::afkeur,
            $this->context->chance(0.1) => ServiceJobOutcomes::reparatie,
            default => ServiceJobOutcomes::goedkeur,
        };

        $job = ServiceJob::forceCreate([
            'asset_id' => $asset->id,
            'service_order_id' => $order->id,
            'outcome' => $outcome->value,
            'description' => $finished && $fault ? $fault[2] : null,
            'completed_on' => $finished ? $start->setTimezone(DemoContext::zone())->toDateString() : null,
            'completed_by' => $finished ? $mechanic->id : null,
        ]);

        if (!$finished) {
            return;
        }

        $this->fillIn($job);

        if ($kind === 'maintenance') {
            $asset->forceFill(['next_service_date' => $start->addYear()->toDateString()])->save();
        }
    }

    private function fillIn(ServiceJob $job): void
    {
        $checklists = $this->context->data('checklists');
        $ranges = collect($checklists['lists'])->flatten(1)
            ->mapWithKeys(fn (array $entry) => [$entry[1] => $entry[3]['range'] ?? null]);

        foreach ($job->checkInstances()->with('serviceCheck.values')->get() as $instance) {
            $check = $instance->serviceCheck;

            match ($check->type) {
                'boolean' => $instance->forceFill(['switch_state' => $this->context->chance(0.95)])->save(),
                'number' => $instance->forceFill(['description' => (string) $this->context->decimal(...($ranges[$check->name] ?? [1, 10]))])->save(),
                'radio', 'checkgroup' => $instance->forceFill([
                    'description' => $this->context->chance(0.9) ? $check->values->first()?->value : $check->values->last()?->value,
                ])->save(),
                default => $instance->forceFill(['description' => $this->context->pick($checklists['remarks'])])->save(),
            };
        }
    }

    private function ticket(ServiceOrder $order, Asset $asset, array $fault, string $phase, Customer $customer, User $mechanic, CarbonImmutable $start): void
    {
        $closed = $phase === 'done';

        Ticket::forceCreate([
            'asset_id' => $asset->id,
            'service_order_id' => $order->id,
            'subject' => $fault[0],
            'description' => $fault[1],
            'status' => $closed ? TicketStatusses::gesloten->value : TicketStatusses::in_behandeling->value,
            'priority' => $this->priority($customer),
            'created_by_id' => $this->desk->id,
            'closed_by_id' => $closed ? $mechanic->id : null,
            'closed_on' => $closed ? $start->setTimezone(DemoContext::zone())->toDateString() : null,
            'created_at' => $start->subDays($this->context->between(0, 2))->subHours($this->context->between(1, 6)),
        ]);
    }

    private function priority(Customer $customer): string
    {
        $critical = collect($this->context->customers)->first(fn (array $entry) => $entry['customer']->is($customer))['data']['priority'] ?? false;

        return match (true) {
            $critical => TicketPriorities::hoog->value,
            $this->context->chance(0.25) => TicketPriorities::laag->value,
            default => TicketPriorities::normaal->value,
        };
    }

    private function remark(ServiceOrder $order, User $author, string $content, CarbonImmutable $at): void
    {
        $remark = Remark::forceCreate([
            'user_id' => $author->id,
            'content' => $content,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $order->remarks()->attach($remark->id, ['internal' => false]);
    }

    /**
     * Orders without a date yet: fresh faults the desk took in, maintenance
     * that is overdue, a survey or two, and a visit the customer called off.
     */
    private function unplanned(): void
    {
        $work = $this->context->data('work');

        for ($count = 0; $count < 7; $count++) {
            $entry = $this->context->pick($this->context->customers);
            $site = $this->context->pick($entry['sites']);

            if (!$site['assets']) {
                continue;
            }

            $asset = $this->context->pick($site['assets']);
            $family = $this->context->families[$asset->product->productType->name];
            $fault = $this->context->pick($work['faults'][$family] ?? $work['faults']['Airconditioning']);
            $reported = $this->context->now->subHours($this->context->between(2, 70));

            $order = $this->openOrder($entry['customer'], $site, $fault[0], $reported, $this->desk);

            $order->serviceJobs()->create(['asset_id' => $asset->id, 'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value]);

            Ticket::forceCreate([
                'asset_id' => $asset->id,
                'service_order_id' => $order->id,
                'subject' => $fault[0],
                'description' => $fault[1],
                'status' => TicketStatusses::open->value,
                'priority' => $this->priority($entry['customer']),
                'created_by_id' => $this->desk->id,
                'created_at' => $reported,
            ]);
        }

        $overdue = collect($this->context->customers)
            ->flatMap(fn (array $entry) => collect($entry['sites'])->map(fn (array $site) => [$entry['customer'], $site]))
            ->filter(fn (array $pair) => collect($pair[1]['assets'])->contains(fn (Asset $asset) => $this->overdue($asset)))
            ->take(5);

        foreach ($overdue as [$customer, $site]) {
            $assets = collect($site['assets'])->filter(fn (Asset $asset) => $this->overdue($asset));
            $family = $this->context->families[$assets->first()->product->productType->name];

            $order = $this->openOrder($customer, $site, $this->context->pick($work['maintenance'][$family]) . ' (achterstallig)',
                $this->context->now->subDays($this->context->between(3, 10)), $this->planner);

            foreach ($assets->take(6) as $asset) {
                $order->serviceJobs()->create(['asset_id' => $asset->id, 'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value]);
            }
        }

        foreach ([0, 1] as $survey) {
            $entry = $this->context->pick($this->context->customers);
            $asset = collect($entry['sites'][0]['assets'])->first();
            $family = $asset ? $this->context->families[$asset->product->productType->name] : 'Airconditioning';

            $this->openOrder($entry['customer'], $entry['sites'][0], $this->context->pick($work['surveys'][$family]),
                $this->context->now->subDays(2 + $survey), $this->planner);
        }

        $cancelled = $this->context->pick($this->context->customers);
        $order = $this->openOrder($cancelled['customer'], $cancelled['sites'][0], $this->context->pick($work['maintenance']['Airconditioning']),
            $this->context->now->subDays(9), $this->planner, $this->stages['is_planning_cancelled_state']);

        $this->remark($order, $this->planner, 'Klant heeft de afspraak afgezegd en belt terug voor een nieuwe datum.', $this->context->now->subDays(1));
    }

    /**
     * Read fresh: maintenance earlier in this run moved the date forward. The
     * column has no date cast, so this compares the text, which for Y-m-d is
     * the same thing.
     */
    private function overdue(Asset $asset): bool
    {
        $due = $asset->fresh()->next_service_date;

        return $due !== null && (string) $due < $this->context->now->toDateString();
    }

    private function openOrder(Customer $customer, array $site, string $description, CarbonImmutable $on, User $owner, ?ServiceOrderStage $stage = null): ServiceOrder
    {
        $order = ServiceOrder::forceCreate([
            'customer_id' => $customer->id,
            'location_id' => $site['location']?->id,
            'description' => $description,
            'type' => ServiceOrderTypes::service->value,
            'service_order_stage_id' => ($stage ?? $this->stages['is_plannable_state'])->id,
            'order_date' => $on->setTimezone(DemoContext::zone())->toDateString(),
            'created_at' => $on,
        ]);

        $order->owners()->attach($owner->id, ['type' => 'owner']);

        return $order;
    }

    /** Questions that came in by phone or mail and never needed a visit. */
    private function remoteTickets(): void
    {
        foreach ($this->context->data('work')['remote_tickets'] as $index => [$subject, $description]) {
            $entry = $this->context->customers[($index * 5) % count($this->context->customers)];
            $asset = collect($entry['sites'])->pluck('assets')->flatten(1)->first();

            Ticket::forceCreate([
                'asset_id' => $asset->id,
                'subject' => $subject,
                'description' => $description,
                'status' => $index % 2 === 0 ? TicketStatusses::open->value : TicketStatusses::wacht_op_klant->value,
                'priority' => TicketPriorities::laag->value,
                'created_by_id' => $this->desk->id,
                'created_at' => $this->context->now->subDays($index + 1),
            ]);
        }
    }

    private function groupOf(User $mechanic): string
    {
        return collect($this->context->data('team')['people'])
            ->firstWhere('email', $mechanic->email)['groups'][0] ?? 'Team Airco';
    }
}
