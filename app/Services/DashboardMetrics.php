<?php

namespace App\Services;

use App\Enums\AssetStatusses;
use App\Enums\EventStatusses;
use App\Enums\TicketStatusses;
use App\Models\Asset;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything the dashboard puts on screen, for one user over one period.
 *
 * Each block is its own method so the controller can skip the ones the user has
 * no right to see — an unseen widget then costs no queries at all.
 */
class DashboardMetrics
{
    /**
     * The period picker in the dashboard header. The comparison label travels
     * with the period so a tile never has to guess what it is comparing against.
     */
    public const PERIODS = [
        'week' => ['label' => 'Deze week', 'compare' => 'vs. vorige week'],
        'last_week' => ['label' => 'Vorige week', 'compare' => 'vs. week ervoor'],
        'month' => ['label' => 'Deze maand', 'compare' => 'vs. vorige maand'],
        'last_30' => ['label' => 'Laatste 30 dagen', 'compare' => 'vs. 30 dagen ervoor'],
    ];

    /** How many fases get a colour of their own before the rest folds into grey. */
    public const COLOUR_SLOTS = 6;

    private CarbonImmutable $start;

    private CarbonImmutable $end;

    private CarbonImmutable $previous_start;

    private CarbonImmutable $previous_end;

    private string $period;

    public function __construct(private User $user, ?string $period = null)
    {
        $this->period = array_key_exists((string) $period, self::PERIODS) ? (string) $period : 'last_30';

        [$this->start, $this->end] = $this->resolveRange($this->period);

        $days = $this->start->diffInDays($this->end) + 1;
        $this->previous_end = $this->start->subDay()->endOfDay();
        $this->previous_start = $this->previous_end->subDays($days - 1)->startOfDay();
    }

    public function period(): array
    {
        return [
            'value' => $this->period,
            'label' => self::PERIODS[$this->period]['label'],
            'compare' => self::PERIODS[$this->period]['compare'],
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'range_label' => $this->rangeLabel(),
        ];
    }

    public static function periodOptions(): array
    {
        return collect(self::PERIODS)
            ->map(fn ($meta, $value) => ['value' => $value, 'title' => $meta['label']])
            ->values()
            ->all();
    }

    /**
     * The five headline tiles. `accent` names a series slot from the dashboard
     * palette; `higher_is_better` tells the tile whether a rise is good news.
     */
    public function kpis(): array
    {
        $created = $this->ordersCreated($this->start, $this->end);
        $created_before = $this->ordersCreated($this->previous_start, $this->previous_end);

        $hours = $this->plannedHours($this->start, $this->end);
        $hours_before = $this->plannedHours($this->previous_start, $this->previous_end);

        $open_tickets = $this->openTicketsOn($this->start, $this->end);
        $open_tickets_before = $this->openTicketsOn($this->previous_start, $this->previous_end);

        $closed = $this->ordersClosed($this->start, $this->end);
        $closed_before = $this->ordersClosed($this->previous_start, $this->previous_end);

        $on_time = $this->onTimeShare($this->start, $this->end);
        $on_time_before = $this->onTimeShare($this->previous_start, $this->previous_end);

        $compare = self::PERIODS[$this->period]['compare'];

        return [
            [
                'key' => 'orders_created',
                'label' => 'Nieuwe werkbonnen',
                'value' => array_sum($created),
                'unit' => '',
                'trend' => $created,
                'delta' => $this->delta(array_sum($created), array_sum($created_before)),
                'compare' => $compare,
                'accent' => 1,
                'higher_is_better' => true,
                'icon' => 'orders',
            ],
            [
                'key' => 'planned_hours',
                'label' => 'Geplande uren',
                'value' => round(array_sum($hours)),
                'unit' => 'u',
                'trend' => $hours,
                'delta' => $this->delta(array_sum($hours), array_sum($hours_before)),
                'compare' => $compare,
                'accent' => 2,
                'higher_is_better' => true,
                'icon' => 'hours',
            ],
            [
                'key' => 'open_tickets',
                'label' => 'Storingen open',
                'value' => end($open_tickets) ?: 0,
                'unit' => '',
                'trend' => $open_tickets,
                'delta' => $this->delta(end($open_tickets) ?: 0, end($open_tickets_before) ?: 0),
                'compare' => $compare,
                'accent' => 5,
                'higher_is_better' => false,
                'icon' => 'tickets',
            ],
            [
                'key' => 'orders_closed',
                'label' => 'Werkbonnen afgerond',
                'value' => array_sum($closed),
                'unit' => '',
                'trend' => $closed,
                'delta' => $this->delta(array_sum($closed), array_sum($closed_before)),
                'compare' => $compare,
                'accent' => 3,
                'higher_is_better' => true,
                'icon' => 'closed',
            ],
            [
                'key' => 'on_time',
                'label' => 'Op tijd afgerond',
                'value' => $on_time,
                'unit' => '%',
                'ring' => $on_time,
                'trend' => [],
                'delta' => $this->delta($on_time, $on_time_before),
                'compare' => $compare,
                'accent' => 6,
                'higher_is_better' => true,
                'icon' => 'ontime',
            ],
        ];
    }

    /**
     * Every open werkbon split over the fase it stands on now.
     *
     * Deliberately outside the periodekiezer: the vraag this answers is "waar
     * staat het werk dat nog moet", and that stack does not care which week you
     * are looking at. A werkbon zonder fase is open too — nothing has closed it.
     *
     * The colour slot comes from the fase's own place in the fase-volgorde, not
     * from its place among the slices that happen to be filled: a fase keeps its
     * colour when an earlier fase runs empty. There are six slots, and everything
     * past them — plus de werkbonnen zonder fase — folds into one grey rest-slice
     * rather than getting a seventh colour.
     */
    public function openOrdersByStage(): array
    {
        $counts = ServiceOrder::visibleTo($this->user)
            ->where(fn ($q) => $q
                ->whereNull('service_order_stage_id')
                ->orWhereHas('serviceOrderStage', fn ($sq) => $sq
                    ->where('is_closed_state', false)
                    ->where('is_invoiced_state', false)))
            ->selectRaw('service_order_stage_id, count(*) as aantal')
            ->groupBy('service_order_stage_id')
            ->pluck('aantal', 'service_order_stage_id');

        $total = (int) $counts->sum();

        $segments = collect();
        $rest = (int) ($counts[null] ?? 0);
        $rest_is_only_unstaged = true;

        $stages = ServiceOrderStage::orderBy('order')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->values();

        foreach ($stages as $index => $stage) {
            $count = (int) ($counts[$stage->id] ?? 0);

            if ($count === 0) {
                continue;
            }

            if ($index >= self::COLOUR_SLOTS) {
                $rest += $count;
                $rest_is_only_unstaged = false;

                continue;
            }

            $segments->push([
                'id' => $stage->id,
                'name' => $stage->name,
                'count' => $count,
                'slot' => $index + 1,
            ]);
        }

        if ($rest > 0) {
            $segments->push([
                'id' => null,
                'name' => $rest_is_only_unstaged ? 'Geen fase' : 'Overige fases',
                'count' => $rest,
                'slot' => 'rest',
            ]);
        }

        return [
            'total' => $total,
            'segments' => $segments
                ->map(fn ($segment) => [
                    ...$segment,
                    'share' => $total > 0 ? (int) round($segment['count'] / $total * 100) : 0,
                ])
                ->all(),
        ];
    }

    /**
     * Waar de werkbonnen liggen die in deze periode *ingepland* staan — de
     * afspraken bepalen dat, niet de datum opdracht. Dat is de vraag die een
     * kaart op een week beantwoordt: waar moeten we heen.
     *
     * Het adres komt uit EventLocationResolver, dezelfde ladder die de planner
     * en de agenda-export lopen, zodat de speld nooit op een ander adres staat
     * dan waar de afspraak zegt dat hij is. Coördinaten komen van het model dat
     * die ladder aanwijst; heeft dat er geen, dan valt de al opgezochte
     * geocache in — lezen alleen, want een paginaverzoek mag niet gaan wachten
     * op Nominatim.
     *
     * Wat overblijft wordt geteld en meegestuurd. Een kaart die stilletjes
     * minder spelden laat zien dan er afspraken zijn, is erger dan een kaart die
     * zegt hoeveel er ontbreken.
     */
    public function plannedOnMap(): array
    {
        $resolver = app(EventLocationResolver::class);
        $geocoder = app(Geocoder::class);

        $events = Event::visibleTo($this->user)
            ->whereBetween('start', [$this->start, $this->end])
            ->where('status', '!=', EventStatusses::cancelled->value)
            ->with([
                ...EventLocationResolver::relations(),
                'serviceOrders.linkedLocation',
            ])
            ->get();

        $points = [];
        $planned = 0;
        $unplaced = 0;

        foreach ($events as $event) {
            if ($event->serviceOrders->isEmpty()) {
                continue;
            }

            $planned++;

            $coordinates = $this->coordinatesFor($event, $resolver, $geocoder);

            if (!$coordinates) {
                $unplaced++;

                continue;
            }

            [$lat, $lon] = $coordinates;
            $key = $lat . ':' . $lon;

            if (!isset($points[$key])) {
                $points[$key] = [
                    'lat' => $lat,
                    'lon' => $lon,
                    'name' => $event->primaryCustomer()?->name ?? 'Onbekende locatie',
                    'city' => $event->primaryCustomer()?->city,
                    'total' => 0,
                    'appointments' => 0,
                    'done' => 0,
                    'order_ids' => [],
                ];
            }

            $points[$key]['appointments']++;

            if ($event->status === EventStatusses::completed->value) {
                $points[$key]['done']++;
            }

            foreach ($event->serviceOrders as $order) {
                if (!in_array($order->id, $points[$key]['order_ids'], true)) {
                    $points[$key]['order_ids'][] = $order->id;
                    $points[$key]['total']++;
                }
            }
        }

        return [
            'points' => array_values($points),
            'planned' => $planned,
            'unplaced' => $unplaced,
        ];
    }

    /**
     * Coördinaten voor de plek die de resolver aanwijst: eerst die van het model
     * zelf, anders die van het adres als dat al eens opgezocht is.
     *
     * @return array{0: float, 1: float}|null
     */
    private function coordinatesFor(Event $event, EventLocationResolver $resolver, Geocoder $geocoder): ?array
    {
        $place = $resolver->coordinates($event);

        if ($place) {
            return [round((float) $place->lat, 4), round((float) $place->lon, 4)];
        }

        $address = $resolver->resolve($event);

        if (!$address) {
            return null;
        }

        $cached = $geocoder->cached($address);

        return $cached ? [round($cached['lat'], 4), round($cached['lon'], 4)] : null;
    }

    /**
     * Today's afspraken, whoever they belong to that this user may see.
     */
    public function agenda(): array
    {
        $today = CarbonImmutable::now();

        return Event::visibleTo($this->user)
            ->whereBetween('start', [$today->startOfDay(), $today->endOfDay()])
            ->with([
                'eventType:id,name,color',
                'serviceOrders:id,customer_id',
                'serviceOrders.customer:id,name',
                'customers:id,name',
            ])
            ->orderBy('start')
            ->get(['id', 'name', 'start', 'end', 'status', 'event_type_id'])
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'name' => $event->name,
                'start' => $event->start?->toIso8601String(),
                'end' => $event->end?->toIso8601String(),
                'status' => $event->status,
                'type' => $event->eventType?->name,
                'service_order_id' => $event->serviceOrders->first()?->id,
                'customer' => $event->primaryCustomer()?->name,
            ])
            ->all();
    }

    /**
     * Machines whose next keuringsdatum is closest, the date the keuringenlijst
     * works from as well.
     */
    public function upcomingInspections(int $limit = 6): array
    {
        return Asset::visibleTo($this->user)
            ->whereNotNull('next_service_date')
            ->where('status', '!=', AssetStatusses::inactive->value)
            ->whereDate('next_service_date', '>=', CarbonImmutable::now()->toDateString())
            ->with([
                'customer:id,name,city',
                'product:id,model,brand_id,product_type_id',
                'product.productType:id,name',
            ])
            ->orderBy('next_service_date')
            ->take($limit)
            ->get(['id', 'customer_id', 'product_id', 'serial_number', 'next_service_date'])
            ->map(function (Asset $asset) {
                $due = CarbonImmutable::parse($asset->next_service_date)->startOfDay();
                $parts = array_filter([$asset->serial_number, $asset->customer?->name]);

                return [
                    'id' => $asset->id,
                    'title' => $asset->product?->productType?->name ?? $asset->product?->model ?? 'Machine',
                    'subtitle' => implode(' – ', $parts),
                    'date' => $due->toDateString(),
                    'days' => (int) CarbonImmutable::now()->startOfDay()->diffInDays($due),
                ];
            })
            ->all();
    }

    public function recentOrders(int $limit = 6): array
    {
        return ServiceOrder::visibleTo($this->user)
            ->with(['customer:id,name', 'serviceOrderStage:id,name,is_closed_state,is_invoiced_state,is_planned_state'])
            ->orderByDesc('updated_at')
            ->take($limit)
            ->get(['id', 'customer_id', 'description', 'service_order_stage_id', 'updated_at'])
            ->map(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'description' => $order->description,
                'customer' => $order->customer?->name,
                'stage' => $order->serviceOrderStage?->name,
                'is_closed' => (bool) $order->serviceOrderStage?->closesOrder(),
                'is_planned' => (bool) $order->serviceOrderStage?->is_planned_state,
                'updated_at' => $order->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    public function openTickets(int $limit = 6): array
    {
        return Ticket::visibleTo($this->user)
            ->where('status', '!=', TicketStatusses::gesloten->value)
            ->with(['asset:id,customer_id', 'asset.customer:id,name'])
            ->orderByDesc('created_at')
            ->take($limit)
            ->get(['id', 'asset_id', 'subject', 'status', 'priority', 'created_at'])
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'customer' => $ticket->asset?->customer?->name,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function resolveRange(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'last_week' => [$now->subWeek()->startOfWeek()->startOfDay(), $now->subWeek()->endOfWeek()->endOfDay()],
            'month' => [$now->startOfMonth()->startOfDay(), $now->endOfMonth()->endOfDay()],
            'last_30' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            default => [$now->startOfWeek()->startOfDay(), $now->endOfWeek()->endOfDay()],
        };
    }

    /**
     * "20 mei – 26 mei 2026", with the year written once when both ends share it.
     */
    private function rangeLabel(): string
    {
        $months = ['', 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

        $left = $this->start->day . ' ' . $months[$this->start->month];
        $right = $this->end->day . ' ' . $months[$this->end->month] . ' ' . $this->end->year;

        if ($this->start->year !== $this->end->year) {
            $left .= ' ' . $this->start->year;
        }

        return $left . ' – ' . $right;
    }

    /** @return array<int, int> one bucket per day of the range */
    private function emptyBuckets(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = [];

        for ($day = $start->startOfDay(); $day->lte($end); $day = $day->addDay()) {
            $buckets[$day->toDateString()] = 0;
        }

        return $buckets;
    }

    private function ordersCreated(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = $this->emptyBuckets($start, $end);

        $dates = ServiceOrder::visibleTo($this->user)
            ->whereNotNull('order_date')
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('order_date');

        return $this->fill($buckets, $dates);
    }

    private function ordersClosed(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = $this->emptyBuckets($start, $end);

        $dates = ServiceOrder::visibleTo($this->user)
            ->whereNotNull('closed_on')
            ->whereBetween('closed_on', [$start->toDateString(), $end->toDateString()])
            ->pluck('closed_on');

        return $this->fill($buckets, $dates);
    }

    /**
     * Hours are summed in PHP rather than in SQL: the date arithmetic differs per
     * database engine and the number of afspraken in a period is small.
     */
    private function plannedHours(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = $this->emptyBuckets($start, $end);

        $events = Event::visibleTo($this->user)
            ->whereBetween('start', [$start, $end])
            ->where('status', '!=', EventStatusses::cancelled->value)
            ->get(['start', 'end']);

        foreach ($events as $event) {
            if (!$event->start || !$event->end) {
                continue;
            }

            $key = $event->start->toDateString();

            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += max(0, $event->start->diffInMinutes($event->end)) / 60;
            }
        }

        return array_values(array_map(fn ($hours) => round($hours, 1), $buckets));
    }

    /**
     * How many storingen stood open at the close of each day in the range, so the
     * sparkline draws the same quantity the big number states.
     *
     * The line stops at today when the period runs on into the future: how many
     * storingen will stand open on Friday is not known, and drawing today's count
     * across the rest of the week would be a guess dressed up as a measurement.
     */
    private function openTicketsOn(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $end = $end->min(CarbonImmutable::now());

        if ($end->lt($start)) {
            return [0];
        }

        $tickets = Ticket::visibleTo($this->user)
            ->where('created_at', '<=', $end)
            ->where(fn ($q) => $q
                ->whereNull('closed_on')
                ->orWhereDate('closed_on', '>=', $start->toDateString()))
            ->get(['created_at', 'closed_on']);

        $counts = [];

        for ($day = $start->startOfDay(); $day->lte($end); $day = $day->addDay()) {
            $close = $day->endOfDay();

            $counts[] = $tickets
                ->filter(fn ($ticket) => $this->wasOpenAt($ticket, $close))
                ->count();
        }

        return $counts;
    }

    private function wasOpenAt(Ticket $ticket, CarbonImmutable $moment): bool
    {
        if (!$ticket->created_at?->lte($moment)) {
            return false;
        }

        return $ticket->closed_on === null
            || CarbonImmutable::parse($ticket->closed_on)->endOfDay()->gt($moment);
    }

    /**
     * A werkbon counts as on time when it was closed no later than the day of its
     * last afspraak. Werkbonnen that were never planned have no promise to keep
     * and stay out of the ratio entirely.
     */
    private function onTimeShare(CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $orders = ServiceOrder::visibleTo($this->user)
            ->whereNotNull('closed_on')
            ->whereBetween('closed_on', [$start->toDateString(), $end->toDateString()])
            ->with(['events' => fn ($q) => $q->where('status', '!=', EventStatusses::cancelled->value)])
            ->get(['id', 'closed_on']);

        $planned = $orders->filter(fn (ServiceOrder $order) => $order->events->isNotEmpty());

        if ($planned->isEmpty()) {
            return null;
        }

        $on_time = $planned->filter(function (ServiceOrder $order) {
            $last = $order->events->max('end');

            if (!$last) {
                return false;
            }

            return CarbonImmutable::parse($order->closed_on)
                ->startOfDay()
                ->lte(CarbonImmutable::parse($last)->startOfDay());
        });

        return (int) round($on_time->count() / $planned->count() * 100);
    }

    private function fill(array $buckets, Collection $dates): array
    {
        foreach ($dates as $date) {
            $key = CarbonImmutable::parse($date)->toDateString();

            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        return array_values($buckets);
    }

    private function delta(int|float|null $now, int|float|null $before): ?int
    {
        if ($now === null || $before === null || $before <= 0) {
            return null;
        }

        return (int) round(($now - $before) / $before * 100);
    }
}
