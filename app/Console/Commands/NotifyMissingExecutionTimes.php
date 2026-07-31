<?php

namespace App\Console\Commands;

use App\Enums\UserNotificationPriority;
use App\Enums\UserNotificationType;
use App\Models\Event;
use App\Models\EventUserExecution;
use App\Models\NotificationSubscription;
use App\Models\UserNotification;
use Illuminate\Console\Command;

/**
 * Herinnert monteurs aan uren die nog openstaan.
 *
 * Dit is de enige melding zonder signaal erachter, en dat kan ook niet: er gebeurt
 * niets op het moment dat een afspraak voorbij is zonder ingevulde tijden. Het is
 * juist het uitblijven van iets. Daarom kijkt een commando ernaar in plaats van een
 * luisteraar.
 *
 * Pas vanaf de dag erna, zodat niemand wordt aangesproken op werk van vanmiddag.
 * En één keer per afspraak per persoon: een herinnering die elke ochtend opnieuw
 * binnenkomt is een herinnering die niemand meer leest.
 */
class NotifyMissingExecutionTimes extends Command
{
    protected $signature = 'notifications:missing-times {--days=14 : Hoe ver terug er gekeken wordt}';

    protected $description = 'Meldt monteurs dat er tijden van afgelopen afspraken nog niet ingevuld zijn.';

    public function handle(): int
    {
        $type = UserNotificationType::execution_times_missing;

        $subscribers = NotificationSubscription::where('type', $type->value)->pluck('user_id');

        if ($subscribers->isEmpty()) {
            $this->info('Niemand wil deze melding ontvangen.');

            return self::SUCCESS;
        }

        $since = now()->subDays((int) $this->option('days'))->startOfDay();

        $open = EventUserExecution::query()
            ->whereIn('user_id', $subscribers)
            ->where(fn ($query) => $query->whereNull('actual_start')->orWhereNull('actual_end'))
            ->whereHas('event', fn ($query) => $query
                ->where('end', '<', now()->startOfDay())
                ->where('end', '>=', $since))
            ->with(['event', 'user'])
            ->get();

        $written = 0;

        foreach ($open as $execution) {
            if (!$execution->event || !$execution->user) {
                continue;
            }

            if ($this->alreadyTold($execution)) {
                continue;
            }

            UserNotification::create([
                'user_id' => $execution->user_id,
                'type' => $type->value,
                'priority' => UserNotificationPriority::normaal,
                'notificationable_type' => Event::class,
                'notificationable_id' => $execution->event_id,
                'title' => 'Tijden nog niet ingevuld',
                'body' => $this->sentence($execution),
            ]);

            $written++;
        }

        $this->info($written . ' herinnering(en) verstuurd, ' . ($open->count() - $written) . ' al eerder gemeld.');

        return self::SUCCESS;
    }

    /**
     * Eén keer per afspraak per persoon, ook als de melding allang gelezen is: het
     * gaat erom dat het gezegd is, niet dat het nog te zien is.
     */
    private function alreadyTold(EventUserExecution $execution): bool
    {
        return UserNotification::where('user_id', $execution->user_id)
            ->where('type', UserNotificationType::execution_times_missing->value)
            ->where('notificationable_type', Event::class)
            ->where('notificationable_id', $execution->event_id)
            ->exists();
    }

    private function sentence(EventUserExecution $execution): string
    {
        $event = $execution->event;
        $when = $event->start ? $event->start->format('d-m-Y') : 'een eerdere dag';
        $what = $event->name ?: 'de afspraak';

        return $what . ' van ' . $when . ' heeft nog geen begin- of eindtijd.';
    }
}
