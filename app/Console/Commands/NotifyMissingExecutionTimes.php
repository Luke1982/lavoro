<?php

namespace App\Console\Commands;

use App\Enums\EventCompletionStatus;
use App\Enums\UserNotificationPriority;
use App\Enums\UserNotificationType;
use App\Jobs\SendWebPushNotificationsJob;
use App\Models\Event;
use App\Models\UserNotification;
use App\Services\EventLocationResolver;
use App\Services\WebPushSender;
use Illuminate\Console\Command;

/**
 * Herinnert monteurs aan uren die nog openstaan.
 *
 * Deze melding heeft geen signaal achter zich, en dat kan ook niet: er gebeurt
 * niets op het moment dat een afspraak voorbij is zonder ingevulde tijden. Het is
 * juist het uitblijven van iets. Daarom kijkt een commando ernaar in plaats van een
 * luisteraar.
 *
 * Hier hoeft niemand zich voor aan te melden. Het gaat over je eigen uren van je
 * eigen afspraak: dat is geen nieuws waarop je kunt intekenen, maar werk dat nog
 * gedaan moet worden.
 *
 * Pas vanaf de dag erna, zodat niemand wordt aangesproken op werk van vanmiddag.
 * En één keer per afspraak per persoon: een herinnering die elke ochtend opnieuw
 * binnenkomt is een herinnering die niemand meer leest.
 */
class NotifyMissingExecutionTimes extends Command
{
    protected $signature = 'notifications:missing-times
        {--days=14 : Hoe ver terug er gekeken wordt}
        {--no-push : Alleen in de app zetten, niets naar de telefoon sturen}';

    protected $description = 'Meldt monteurs dat er tijden van afgelopen afspraken nog niet ingevuld zijn.';

    public function handle(WebPushSender $sender): int
    {
        $type = UserNotificationType::execution_times_missing;
        $since = now()->subDays((int) $this->option('days'))->startOfDay();

        /**
         * De vraag begint bij wie de afspraak moest uitvoeren en niet bij wie er
         * uren heeft geschreven. Een uitvoeringsregel ontstaat pas als iemand er
         * iets mee doet, dus juist de monteur die niets heeft ingevuld heeft er
         * geen, en dat is precies degene voor wie deze herinnering bedoeld is.
         */
        $events = Event::query()
            ->where('end', '<', now()->startOfDay())
            ->where('end', '>=', $since)

            /** Een afgezegde afspraak vraagt niet om uren. */
            ->where('status', '!=', EventCompletionStatus::cancelled->value)
            ->with([
                'executingUsers' => fn ($query) => $query->whereNull('users.deleted_at'),
                'executions',

                /** Alles wat het adres kan opleveren, in één keer mee. */
                ...EventLocationResolver::relations(),
            ])
            ->get();

        /**
         * Eén keer per afspraak per persoon, ook als de melding allang gelezen is:
         * het gaat erom dat het gezegd is, niet dat het nog te zien is. Dat wordt
         * in één vraag opgehaald in plaats van één per regel.
         */
        $already_told = UserNotification::where('type', $type->value)
            ->where('notificationable_type', Event::class)
            ->whereIn('notificationable_id', $events->modelKeys())
            ->get(['user_id', 'notificationable_id'])
            ->map(fn ($row) => $row->user_id . ':' . $row->notificationable_id)
            ->flip();

        $written = [];
        $skipped = 0;

        foreach ($events as $event) {
            foreach ($event->executingUsers as $user) {
                if ($this->timesAreFilled($event, $user->id)) {
                    continue;
                }

                $key = $user->id . ':' . $event->id;

                if ($already_told->has($key)) {
                    $skipped++;

                    continue;
                }

                /**
                 * Bijgehouden tijdens het schrijven: dezelfde monteur kan twee keer
                 * aan één afspraak hangen, en dat blijft één herinnering.
                 */
                $already_told->put($key, true);

                $written[] = UserNotification::create([
                    'user_id' => $user->id,
                    'type' => $type->value,
                    'priority' => UserNotificationPriority::normaal,
                    'notificationable_type' => Event::class,
                    'notificationable_id' => $event->id,
                    'title' => 'Tijden nog niet ingevuld',
                    'body' => $this->sentence($event),
                ])->id;
            }
        }

        $this->push($sender, $written);

        $this->info(count($written) . ' herinnering(en) verstuurd, ' . $skipped . ' al eerder gemeld.');

        return self::SUCCESS;
    }

    /**
     * Ook deze gaat naar de telefoon als daar toestemming voor is. De reden om 's
     * ochtends te draaien is dat iemand het dan ziet, en een melding die alleen in
     * de app staat wordt gezien door wie de app opent.
     *
     * Behalve als het om een achterstand gaat. De eerste keer dat dit over een paar
     * maanden heen kijkt zijn het er tientallen per persoon, en dat is geen
     * herinnering meer maar een storm. Die zet je met --no-push stil in de app.
     *
     * @param  array<int, int>  $notification_ids
     */
    private function push(WebPushSender $sender, array $notification_ids): void
    {
        if ($notification_ids === [] || $this->option('no-push') || !$sender->isConfigured()) {
            return;
        }

        SendWebPushNotificationsJob::dispatch($notification_ids);
    }

    /**
     * Ingevuld is allebei de tijden. Eén van de twee is een halve registratie en
     * daar kan niemand een werkdag mee verantwoorden.
     */
    private function timesAreFilled(Event $event, int $user_id): bool
    {
        $execution = $event->executions->firstWhere('user_id', $user_id);

        return $execution && $execution->actual_start && $execution->actual_end;
    }

    /**
     * Genoeg om de dag terug te vinden zonder de planner open te slaan: welke
     * werkbon, welke klant en waar het was. Wat ontbreekt valt weg in plaats van
     * als leeg streepje mee te reizen, want een afspraak zonder werkbon of zonder
     * klant is een gewone afspraak en geen half ingevuld bericht.
     */
    private function sentence(Event $event): string
    {
        $order = $event->serviceOrders->first();

        $what = $order?->number ?: ($event->name ?: 'De afspraak');
        $when = $event->start ? ' van ' . $event->start->format('d-m-Y') : '';
        $who = $this->customerName($event);
        $where = $event->display_location;

        return $what . $when
            . ($who ? ' voor ' . $who : '')
            . ($where ? ', ' . $where : '')
            . ': nog geen begin- of eindtijd.';
    }

    /**
     * Van de werkbon als die er is, en anders van de afspraak zelf: een afspraak
     * zonder werkbon draagt de klant rechtstreeks.
     */
    private function customerName(Event $event): ?string
    {
        return $event->serviceOrders->first()?->customer?->name
            ?? $event->customers->first()?->name;
    }
}
