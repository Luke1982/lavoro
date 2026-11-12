<?php

namespace App\Services;

use App\Domain\Planning\Clock;
use App\Enums\EventStatusses;
use App\Enums\UserNotificationPriority;
use App\Enums\UserNotificationType;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\GeneralSetting;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Herinnert monteurs aan hun volgende afspraak, vlak voor die begint.
 *
 * Er gebeurt niets op het moment dat een afspraak bijna begint, dus er is geen
 * signaal om op te luisteren: een taak kijkt elke paar minuten wat er binnen de
 * ingestelde tijd begint, net als bij de openstaande uren.
 *
 * Het begin is dat van de monteur zelf. Wie op een afspraak van 08:00 tot 17:00
 * pas om 13:00 is ingepland, hoort om 12:00 dat hij om 13:00 ergens moet zijn,
 * niet om 07:00 dat de afspraak om 08:00 begint.
 */
final class AppointmentReminders
{
    public const SETTING = 'appointment_reminder_minutes';

    public const DEFAULT_MINUTES = 60;

    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 240;

    /** Hoe lang na het begin een herinnering nog vanzelf als gelezen wordt gezet. */
    private const ACKNOWLEDGE_WITHIN = 30;

    /** Afspraken die al afgezegd of afgerond zijn: daar hoeft niemand meer heen. */
    private const SETTLED = [EventStatusses::cancelled->value, EventStatusses::completed->value];

    public function __construct(private EventLocationResolver $locations) {}

    /** Hoeveel minuten van tevoren, zoals de beheerder het instelde. */
    public static function leadMinutes(): int
    {
        $stored = (int) GeneralSetting::get(self::SETTING, self::DEFAULT_MINUTES);

        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $stored));
    }

    public static function saveLeadMinutes(int $minutes): void
    {
        GeneralSetting::set(self::SETTING, $minutes);
    }

    /** Ruimt op wat sinds de vorige ronde niet meer klopt of niet meer nodig is. */
    public function tidy(CarbonInterface $now): void
    {
        $now = CarbonImmutable::instance($now)->utc();

        $this->withdrawOutdated($now);
        $this->acknowledgePast($now);

        /** Een begin dat voorbij is wordt nooit meer herinnerd, dus de claim erop doet niets meer. */
        EventReminder::where('starts_at', '<=', $now)->delete();
    }

    /**
     * Een herinnering die nog vooruit wijst, maar naar een begin dat er niet meer
     * is, gaat weg met haar claim: de afspraak is afgezegd, voorlopig geworden of
     * verdwenen, de monteur staat er niet meer op, of zijn eigen begin is
     * verschoven. Niet elke verschuiving geeft een signaal, en wie zijn eigen
     * afspraak verzet krijgt daar zelf geen melding van, dus kijkt elke ronde
     * opnieuw. Met de claim weg kan een begin dat terugkomt weer herinnerd worden.
     */
    private function withdrawOutdated(CarbonImmutable $now): void
    {
        $claims = EventReminder::where('starts_at', '>', $now)->get();

        if ($claims->isEmpty()) {
            return;
        }

        $events = Event::query()
            ->whereKey($claims->pluck('event_id')->unique())
            ->with(['executingUsers' => fn ($users) => $users->whereNull('users.deleted_at')])
            ->get()
            ->keyBy('id');

        foreach ($claims as $claim) {
            if ($this->stillHolds($claim, $events->get($claim->event_id))) {
                continue;
            }

            UserNotification::where('user_id', $claim->user_id)
                ->where('type', UserNotificationType::appointment_reminder->value)
                ->where('notificationable_type', Event::class)
                ->where('notificationable_id', $claim->event_id)
                ->where('expires_at', $claim->starts_at)
                ->whereNull('read_at')
                ->delete();

            $claim->delete();
        }
    }

    /**
     * Een herinnering waarvan het moment voorbij is, heeft gedaan waarvoor ze
     * kwam. Ze blijft in de lijst staan maar telt niet meer mee op het belletje,
     * dat anders bij elke monteur elke dag met zijn aantal afspraken oploopt.
     *
     * Alleen wat net voorbij is, ruim genoeg om een paar gemiste rondes op te
     * vangen. Wie een oudere herinnering zelf weer op ongelezen zet, wil hem zo
     * houden, en de rondes hoeven niet elke keer de hele geschiedenis door.
     */
    private function acknowledgePast(CarbonImmutable $now): void
    {
        UserNotification::where('type', UserNotificationType::appointment_reminder->value)
            ->whereBetween('expires_at', [$now->subMinutes(self::ACKNOWLEDGE_WITHIN), $now])
            ->whereNull('read_at')
            ->update(['read_at' => DB::raw('expires_at')]);
    }

    /**
     * Schrijft de herinneringen die nu aan de beurt zijn en geeft ze terug om te
     * versturen.
     *
     * Aan de beurt is een begin dat nog moet komen en binnen de ingestelde tijd
     * valt. Een afspraak die pas binnen die tijd wordt ingepland krijgt er dus nog
     * één, en een herinnering die door een stilstaande planner is blijven liggen
     * gaat alsnog uit zolang de afspraak niet begonnen is. Daarna nooit meer.
     *
     * Wat voor één monteur misgaat wordt gemeld en houdt de rest niet op: wie al
     * herinnerd is, moet ook zijn bericht krijgen.
     *
     * @return Collection<int, UserNotification>
     */
    public function write(CarbonInterface $now): Collection
    {
        $now = CarbonImmutable::instance($now)->utc();
        $until = $now->addMinutes(self::leadMinutes());
        $written = collect();

        foreach ($this->candidates($now, $until) as $event) {
            foreach ($event->executingUsers as $member) {
                $start = $this->startFor($event, $member);

                if ($start <= $now || $start > $until || $this->alreadyThere($event, $member)) {
                    continue;
                }

                try {
                    $written->push($this->remind($event, $member, $start));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $written->filter()->values();
    }

    /**
     * De afspraken op de dag van nu en de dag van het einde van de vooruitblik.
     * Wat later dan de vooruitblik begint doet alleen mee als iemand er eigen
     * tijden op heeft: die kan op dezelfde dag eerder beginnen dan de afspraak.
     *
     * Alleen wat in Lavoro gepland is. Wat uit iemands Google Agenda komt, herinnert
     * Google zelf al, en daar staan ook de verjaardag en de tandarts tussen, en
     * hele dagen die om middernacht zouden "beginnen".
     *
     * @return Collection<int, Event>
     */
    private function candidates(CarbonImmutable $now, CarbonImmutable $until): Collection
    {
        $eligible = fn ($users) => $users
            ->where('users.plannable', true)
            ->whereNull('users.deleted_at')
            ->whereDoesntHave('notificationMutes', fn (Builder $mutes) => $mutes
                ->where('type', UserNotificationType::appointment_reminder->value));

        return Event::query()
            ->where('origin', 'lavoro')
            ->whereNotIn('status', self::SETTLED)
            ->where('is_preliminary', false)
            ->where('start', '>=', Clock::startOfLocalDay($now))
            ->where('start', '<', Clock::startOfLocalDay($until->setTimezone(Clock::zone())->addDay()))
            ->where(fn (Builder $events) => $events
                ->where('start', '<=', $until)
                ->orWhereHas('executingUsers', fn ($users) => $users->where('userables.has_diverging_times', true)))
            ->whereHas('executingUsers', $eligible)
            ->with([
                'executingUsers' => $eligible,
                'executions',
                ...EventLocationResolver::relations(),
            ])
            ->get();
    }

    /**
     * Of een claim nog over een begin gaat dat er is. Een voorlopige afspraak staat
     * nog niet vast, en wie daarvoor de weg op gaat of de klant belt, gaat te
     * vroeg; een afgezegde of afgeronde vraagt niets.
     */
    private function stillHolds(EventReminder $claim, ?Event $event): bool
    {
        $member = $event?->executingUsers->firstWhere('id', $claim->user_id);

        return $member !== null
            && !$event->is_preliminary
            && !in_array($event->status, self::SETTLED, true)
            && $this->startFor($event, $member)->equalTo($claim->starts_at);
    }

    /** Het begin van deze monteur: dat van de afspraak, of zijn eigen tijden erop. */
    private function startFor(Event $event, User $member): CarbonImmutable
    {
        return CarbonImmutable::instance($event->plannedTimesFor($member, Clock::zone())[0])->utc();
    }

    /** Wie zijn uitvoering al gestart heeft, is er al en hoeft niet meer te gaan. */
    private function alreadyThere(Event $event, User $member): bool
    {
        return $event->executions->firstWhere('user_id', $member->id)?->actual_start !== null;
    }

    /**
     * Het begin wordt eerst vastgelegd, en alleen wie dat lukt schrijft de melding.
     * Twee taken die elkaar overlappen kunnen zo niet allebei herinneren: de unieke
     * sleutel beslist, niet een controle vooraf die allebei doorstaan.
     */
    private function remind(Event $event, User $member, CarbonImmutable $start): ?UserNotification
    {
        return DB::transaction(function () use ($event, $member, $start) {
            $claimed = EventReminder::query()->insertOrIgnore([
                'event_id' => $event->id,
                'user_id' => $member->id,
                'starts_at' => $start,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($claimed === 0) {
                return null;
            }

            $address = $this->locations->resolve($event);
            $customer = $event->primaryCustomer();

            return UserNotification::create([
                'user_id' => $member->id,
                'type' => UserNotificationType::appointment_reminder->value,
                'priority' => UserNotificationPriority::normaal,
                'notificationable_type' => $event->getMorphClass(),
                'notificationable_id' => $event->id,
                'title' => UserNotificationType::appointment_reminder->label(),
                'body' => $this->body($event, $customer, $address, $start),
                'actions' => $this->actions($address, $customer),
                'expires_at' => $start,
            ])->setRelation('notificationable', $event);
        });
    }

    /**
     * Eén zin die zegt hoe laat je waar moet zijn; de werkbon opent met een tik.
     * Het tijdstip en niet "over een uur": de taak loopt om de vijf minuten, dus
     * een uur is soms vijfenvijftig minuten, en een klok liegt nooit.
     */
    private function body(Event $event, ?Customer $customer, ?string $address, CarbonImmutable $start): string
    {
        $at = $start->setTimezone(Clock::zone())->format('H:i');
        $what = filled($event->name) ? $event->name : 'je afspraak';

        return match (true) {
            filled($customer?->name) => 'Let op, je moet om ' . $at . ' bij ' . $customer->name . ' zijn'
                . (filled($address) ? ', op ' . $address : '') . '.',
            filled($address) => 'Let op, je moet om ' . $at . ' op ' . $address . ' zijn voor ' . $what . '.',
            default => 'Let op, om ' . $at . ' begint ' . $what . '.',
        };
    }

    /**
     * De knoppen onder de melding: de route naar het adres en het nummer van de
     * klant. Wat er niet is, krijgt geen knop; een knop die nergens heen gaat is
     * erger dan geen knop.
     *
     * Het mobiele nummer eerst, want wie onderweg belt dat hij eraan komt, belt een
     * mens en geen receptie.
     *
     * @return list<array{action: string, title: string, url: string}>
     */
    private function actions(?string $address, ?Customer $customer): array
    {
        $number = PhoneNumber::dialable($customer?->mobile) ?? PhoneNumber::dialable($customer?->phone);

        return array_values(array_filter([
            filled($address) ? [
                'action' => 'route',
                'title' => 'Route',
                'url' => 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($address),
            ] : null,
            $number ? ['action' => 'call', 'title' => 'Bellen', 'url' => 'tel:' . $number] : null,
        ]));
    }
}
