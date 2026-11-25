<?php

namespace App\Domain\Assistant\Evals;

use App\Actions\Appointments\AppointmentAssignment;
use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\NewAppointment;
use App\Domain\Assistant\Evals\Scenarios\CaseScenario;
use App\Domain\Planning\Clock;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\UserPlanGroup;
use Carbon\CarbonImmutable;

/**
 * Planning questions: booking somebody, finding who can, reading the agenda,
 * moving an appointment, and noticing when something cannot be done. Dates are
 * worked out from today, so the questions keep meaning the same thing on
 * whichever day they run, and times are compared as moments, not as text.
 */
final class PlanningScenarios
{
    /** @return array<int, EvalScenario> */
    public static function all(): array
    {
        $today = CarbonImmutable::parse(Clock::today());
        $tuesday = $today->addWeek()->startOfWeek()->addDay();
        $wednesday = $tuesday->addDay();
        $thursday = $today->next(CarbonImmutable::THURSDAY);
        $next_workday = $today->addDay()->isWeekend() ? $today->next(CarbonImmutable::MONDAY) : $today->addDay();
        $friday_after = $next_workday->next(CarbonImmutable::FRIDAY);
        $day = fn (CarbonImmutable $date) => $date->locale('nl')->isoFormat('dddd D MMMM');

        return [
            new CaseScenario(
                name: 'afspraak-direct',
                suite: 'planning',
                needs: ['users' => ['Jeroen Mulder'], 'customers' => ['Hotel Rivierzicht'], 'event_types' => ['Periodieke controle']],
                opening: 'Plan Jeroen Mulder ' . $day($tuesday) . ' van 9:00 tot 12:00 in bij Hotel Rivierzicht voor het onderhoud van de airco\'s.',
                persona: implode("\n", [
                    'Je wilt Jeroen Mulder op ' . $tuesday->toDateString() . ' van 09:00 tot 12:00 inplannen bij Hotel Rivierzicht voor airco-onderhoud.',
                    'Er is nog geen werkbon voor: als ernaar gevraagd wordt, laat een nieuwe werkbon aanmaken.',
                    'Afspraaksoort: Periodieke controle.',
                    'Staat de afspraak, dan ben je klaar.',
                ]),
                checks: function (EvalTranscript $transcript) use ($tuesday) {
                    $events = $transcript->executedOf('create_event');
                    $made = (array) ($events[0]['content'] ?? []);
                    $jeroen = (int) User::query()->where('name', 'Jeroen Mulder')->value('id');
                    $hotel = (int) Customer::query()->where('name', 'Hotel Rivierzicht')->value('id');
                    $order = ServiceOrder::query()->find($made['service_order_id'] ?? 0);

                    return [
                        Check::that('precies één afspraak ingepland', count($events) === 1, count($events) . ' afspraken'),
                        Check::that(
                            'op de gevraagde tijd',
                            self::startsAndEnds($made, self::at($tuesday, '09:00'), self::at($tuesday, '12:00')),
                            EvalTranscript::text(array_intersect_key($made, ['starts_at' => true, 'ends_at' => true])),
                        ),
                        Check::that('met Jeroen Mulder', array_map('intval', $made['user_ids'] ?? []) === [$jeroen], EvalTranscript::text($made['user_ids'] ?? null)),
                        Check::that('op een werkbon van Hotel Rivierzicht', $order !== null && (int) $order->customer_id === $hotel, 'werkbon ' . EvalTranscript::text($order?->id)),
                        Check::noFailedActions($transcript),
                    ];
                },
            ),

            new CaseScenario(
                name: 'wie-kan',
                suite: 'planning',
                needs: ['customers' => ['Bakkerij Van Ommeren'], 'plan_groups' => ['Team Warmte']],
                opening: 'Wie kan er ' . $day($thursday) . ' 4 uur een warmtepomp onderhouden bij Bakkerij Van Ommeren?',
                persona: 'Je wilt alleen weten wie er kan; je plant nog niets in. Krijg je namen en tijden, dan ben je klaar.',
                checks: function (EvalTranscript $transcript, array $state, ?User $asker) use ($thursday) {
                    $searches = $transcript->callsOf('find_available_technician');
                    $search = $searches[0]['arguments'] ?? [];
                    $minutes = (int) ($search['total_work_minutes'] ?? $search['duration_minutes'] ?? 0);
                    $answer = $transcript->answers();
                    $offered = EvalTranscript::text(array_column($searches, 'content'));
                    $invented = User::query()->pluck('name')
                        ->filter(fn ($name) => $name !== $asker?->name && str_contains($answer, $name) && !str_contains($offered, $name))
                        ->values()->all();
                    $from = (string) ($search['from_date'] ?? Clock::today());

                    return [
                        Check::that('beschikbaarheid gezocht', $searches !== []),
                        Check::that('op de gevraagde dag', $from === $thursday->toDateString(), 'from_date ' . $from . ', gevraagd ' . $thursday->toDateString()),
                        Check::that('voor 4 uur werk', $minutes === 240, $minutes . ' min'),
                        Check::that('in Team Warmte', ($search['plan_group'] ?? '') === 'Team Warmte', 'plan_group ' . EvalTranscript::text($search['plan_group'] ?? null)),
                        Check::that('als service', ($search['job_type'] ?? 'service') === 'service', 'job_type ' . EvalTranscript::text($search['job_type'] ?? null)),
                        Check::that('niets ingepland', $transcript->executed === [], count($transcript->executed) . ' actie(s)'),
                        Check::that('alleen monteurs genoemd die de zoekopdracht gaf', $invented === [], implode(', ', $invented)),
                    ];
                },
            ),

            new CaseScenario(
                name: 'vaag',
                suite: 'planning',
                needs: ['customers' => ['Tandartspraktijk De Linde'], 'plan_groups' => ['Service & ventilatie'], 'event_types' => ['Periodieke controle']],
                opening: 'Plan een onderhoud in bij Tandartspraktijk De Linde.',
                persona: implode("\n", [
                    'Het gaat om onderhoud van de WTW-unit (ventilatie).',
                    'Datum: ' . $day($wednesday) . ' (' . $wednesday->toDateString() . '), in de ochtend. Het is 2 uur werk.',
                    'Wie het doet maakt niet uit, iemand van Service & ventilatie; bij het inplannen kies je de eerste voorgestelde optie.',
                    'Er is nog geen werkbon: laat er een aanmaken. Afspraaksoort: Periodieke controle.',
                    'Staat de afspraak, dan ben je klaar.',
                ]),
                checks: function (EvalTranscript $transcript) use ($wednesday) {
                    $first = array_column($transcript->turns[0]['tools'] ?? [], 'name');
                    $events = $transcript->executedOf('create_event');
                    $made = (array) ($events[0]['content'] ?? []);
                    $start = Clock::fromLocal($made['starts_at'] ?? null);
                    $minutes = EvalTranscript::minutesOf($made);
                    $group = UserPlanGroup::query()->where('name', 'Service & ventilatie')->first()?->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all() ?? [];
                    $crew = array_map('intval', $made['user_ids'] ?? []);

                    return [
                        Check::that('vraagt eerst, plant niet meteen', !in_array('create_event', $first, true), implode(', ', $first)),
                        Check::that('precies één afspraak ingepland', count($events) === 1, count($events) . ' afspraken'),
                        Check::that(
                            'op de gevraagde woensdag in de ochtend',
                            $start !== null && $start->greaterThanOrEqualTo(self::at($wednesday, '00:00')) && $start->lessThan(self::at($wednesday, '12:00')),
                            'begint ' . ($made['starts_at'] ?? '(geen)') . ', gevraagd ' . $wednesday->toDateString() . ' vóór 12:00',
                        ),
                        Check::that('2 uur', $minutes === 120, $minutes . ' min'),
                        Check::that('iemand van Service & ventilatie', $crew !== [] && array_diff($crew, $group) === [], EvalTranscript::text($crew)),
                    ];
                },
            ),

            new CaseScenario(
                name: 'dubbel-geboekt',
                suite: 'planning',
                needs: [
                    'users' => ['Jeroen Mulder'],
                    'customers' => ['Hotel Rivierzicht', 'Autobedrijf Van Leeuwen'],
                    'event_types' => ['Installatie', 'Oplossen storing'],
                ],
                prepare: function () use ($next_workday) {
                    self::appointment('Jeroen Mulder', 'Hotel Rivierzicht', $next_workday, '08:00', '13:00', 'Installatie airco', 'Installatie');
                },
                opening: 'Zet Jeroen Mulder ' . $day($next_workday) . ' om 10:00 voor 2 uur in bij Autobedrijf Van Leeuwen, storing aan de airco.',
                persona: implode("\n", [
                    'Je wilt dat de storing bij Autobedrijf Van Leeuwen ' . $day($next_workday) . ' wordt opgelost, liefst door Jeroen Mulder.',
                    'Kan Jeroen dan niet, kies dan de eerste andere mogelijkheid die de assistent voorstelt.',
                    'Er is geen werkbon: laat er een aanmaken. Afspraaksoort: Oplossen storing.',
                    'Staat er een afspraak, dan ben je klaar.',
                ]),
                rubric: ['Zegt meteen dat Jeroen Mulder op dat moment al is ingepland, en stelt een alternatief voor in plaats van dubbel te boeken.'],
                checks: function (EvalTranscript $transcript) use ($next_workday) {
                    $jeroen = (int) User::query()->where('name', 'Jeroen Mulder')->value('id');
                    $busy_from = self::at($next_workday, '08:00');
                    $busy_to = self::at($next_workday, '13:00');
                    $events = $transcript->executedOf('create_event');
                    $clash = array_filter($events, function (array $action) use ($jeroen, $busy_from, $busy_to) {
                        $made = (array) $action['content'];
                        $start = Clock::fromLocal($made['starts_at'] ?? null);
                        $end = Clock::fromLocal($made['ends_at'] ?? null);

                        return in_array($jeroen, array_map('intval', $made['user_ids'] ?? []), true)
                            && $start !== null && $end !== null && $start->lessThan($busy_to) && $end->greaterThan($busy_from);
                    });

                    return [
                        Check::that('Jeroen niet dubbel geboekt', $clash === [], EvalTranscript::text(array_column($clash, 'content'))),
                        Check::that('wel een afspraak voor de storing', count($events) === 1, count($events) . ' afspraken'),
                    ];
                },
            ),

            new CaseScenario(
                name: 'wat-staat-er',
                suite: 'planning',
                needs: [
                    'users' => ['Niels Peters'],
                    'customers' => ['Restaurant De Gouden Karper', 'Fysiotherapie Waalkade'],
                    'event_types' => ['Periodieke controle', 'Oplossen storing'],
                ],
                prepare: function () use ($next_workday) {
                    self::appointment('Niels Peters', 'Restaurant De Gouden Karper', $next_workday, '08:00', '10:00', 'Onderhoud airco', 'Periodieke controle');
                    self::appointment('Niels Peters', 'Fysiotherapie Waalkade', $next_workday, '13:00', '15:30', 'Storing ventilatie', 'Oplossen storing');
                },
                opening: 'Wat heeft Niels Peters ' . $day($next_workday) . ' op de planning?',
                persona: 'Je wilt alleen het overzicht van die dag. Krijg je dat, dan ben je klaar.',
                checks: function (EvalTranscript $transcript) {
                    $answer = $transcript->answers();

                    return [
                        Check::that('afspraken opgezocht', $transcript->called('find_appointments'), implode(', ', $transcript->toolNames())),
                        Check::that('noemt De Gouden Karper om 08:00', self::onOneLine($answer, 'Gouden Karper', '/\b0?8[:.]00\b/'), mb_substr($answer, 0, 400)),
                        Check::that('noemt Fysiotherapie Waalkade om 13:00', self::onOneLine($answer, 'Fysiotherapie Waalkade', '/\b13[:.]00\b/'), mb_substr($answer, 0, 400)),
                        Check::that('niets gewijzigd', $transcript->executed === [], count($transcript->executed) . ' actie(s)'),
                    ];
                },
            ),

            new CaseScenario(
                name: 'verzetten',
                suite: 'planning',
                needs: ['users' => ['Niels Peters'], 'customers' => ['Fysiotherapie Waalkade'], 'event_types' => ['Oplossen storing']],
                prepare: function () use ($next_workday) {
                    $event = self::appointment('Niels Peters', 'Fysiotherapie Waalkade', $next_workday, '13:00', '15:30', 'Storing ventilatie', 'Oplossen storing');

                    return ['event_id' => $event->id, 'service_order_id' => (int) $event->serviceOrders()->value('service_orders.id')];
                },
                opening: 'Verzet de afspraak van Niels Peters bij Fysiotherapie Waalkade van ' . $day($next_workday) . ' naar ' . $day($friday_after) . ', zelfde tijd.',
                persona: implode("\n", [
                    'Je wilt dat de afspraak van Niels Peters bij Fysiotherapie Waalkade van ' . $next_workday->toDateString() . ' (13:00 tot 15:30)'
                        . ' verplaatst wordt naar ' . $friday_after->toDateString() . ', zelfde tijd, zelfde monteur.',
                    'Een nieuwe afspraak naast de oude wil je niet: de oude moet verschuiven.',
                    'Staat de afspraak op de nieuwe dag, dan ben je klaar.',
                ]),
                rubric: ['Verzet de bestaande afspraak in plaats van een nieuwe te maken, en zegt daarna kort waar hij nu staat.'],
                checks: function (EvalTranscript $transcript, array $state) use ($friday_after) {
                    $moved = array_filter(
                        $transcript->executedOf('change_event'),
                        fn (array $action) => ($action['arguments']['action'] ?? null) === 'verplaatsen' && (int) ($action['arguments']['event_id'] ?? 0) === $state['event_id'],
                    );
                    $event = Event::query()->find($state['event_id']);
                    $on_order = Event::query()->whereHas('serviceOrders', fn ($query) => $query->where('service_orders.id', $state['service_order_id']))->count();
                    $now = $event ? Clock::toLocal($event->start)->format('Y-m-d H:i') . ' – ' . Clock::toLocal($event->end)->format('H:i') : '(weg)';

                    return [
                        Check::that('afspraak verplaatst met change_event', count($moved) === 1, count($moved) . ' keer verplaatst; ' . implode(', ', $transcript->toolNames())),
                        Check::that(
                            'staat op de nieuwe dag, zelfde tijd',
                            $event !== null && Clock::toLocal($event->start)->equalTo(self::at($friday_after, '13:00')) && Clock::toLocal($event->end)->equalTo(self::at($friday_after, '15:30')),
                            'staat nu ' . $now . ', gevraagd ' . $friday_after->toDateString() . ' 13:00 – 15:30',
                        ),
                        Check::that('geen tweede afspraak naast de oude', $transcript->executedOf('create_event') === [] && $on_order === 1, $on_order . ' afspraak/afspraken op de werkbon'),
                        Check::noFailedActions($transcript),
                    ];
                },
            ),
        ];
    }

    /** A wall-clock time on a day, as the moment it is. */
    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        return Clock::fromLocal($day->toDateString() . ' ' . $time);
    }

    private static function startsAndEnds(array $made, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return (Clock::fromLocal($made['starts_at'] ?? null)?->equalTo($start) ?? false)
            && (Clock::fromLocal($made['ends_at'] ?? null)?->equalTo($end) ?? false);
    }

    /** Whether one line of the text names the thing and the time together, as a row of an agenda does. */
    private static function onOneLine(string $text, string $name, string $time): bool
    {
        foreach (preg_split('/\R/', $text) as $line) {
            if (str_contains($line, $name) && preg_match($time, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Puts an appointment in the agenda the way the planner screen would, with a werkbon for the customer. */
    private static function appointment(string $mechanic, string $customer, CarbonImmutable $day, string $from, string $to, string $subject, string $type): Event
    {
        return app(CreateAppointmentAction::class)->execute(new NewAppointment(
            attributes: [
                'event_type_id' => EventType::query()->where('name', $type)->firstOrFail()->id,
                'start' => self::at($day, $from)->format('Y-m-d H:i:s'),
                'end' => self::at($day, $to)->format('Y-m-d H:i:s'),
                'name' => $subject,
                'status' => 'Gepland',
            ],
            create_service_order: true,
            customer_id: Customer::query()->where('name', $customer)->firstOrFail()->id,
            assignment: new AppointmentAssignment(user_ids: [User::query()->where('name', $mechanic)->firstOrFail()->id]),
            service_order_description: $subject,
        ));
    }
}
