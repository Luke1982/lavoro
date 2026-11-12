<?php

namespace App\Services\Push;

use App\Enums\UserNotificationPriority;
use App\Enums\UserNotificationType;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

/**
 * One banner on one screen, in the one shape every channel reads: the service
 * worker for browsers, the native app for Firebase. Built once per notification
 * and handed to every channel, so the browser and the phone never disagree about
 * what a notification says or where tapping it leads.
 */
final readonly class PushMessage
{
    /**
     * Tot en met dit aantal krijgt elke melding zijn eigen banner. Vijf is wat er
     * op een telefoonscherm past zonder dat je gaat vegen om ze weg te krijgen.
     */
    private const SUMMARY_FROM = 5;

    /**
     * @param  array<string, mixed>  $data
     * @param  'high'|'normal'|'low'  $urgency
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data,
        public string $urgency,
        public int $ttl,
    ) {}

    /**
     * Wat elke ontvanger te zien krijgt, per gebruiker.
     *
     * Wat over zijn tijd heen is valt weg: een herinnering aan een afspraak die al
     * begonnen is, is geen bericht meer maar ruis. Wat haast heeft komt altijd los
     * binnen, met zijn knoppen; alleen de rest kan samen één regel worden.
     *
     * @param  Collection<int, UserNotification>  $notifications
     * @return array<int, list<self>>
     */
    public static function batchesFor(Collection $notifications): array
    {
        return $notifications
            ->reject(fn (UserNotification $notification) => $notification->expires_at?->isPast() ?? false)
            ->groupBy('user_id')
            ->map(function (Collection $for_user) {
                [$time_critical, $rest] = $for_user->partition(self::isTimeCritical(...));

                return [
                    ...$time_critical->map(self::fromNotification(...)),
                    ...($rest->count() > self::SUMMARY_FROM
                        ? [self::summary($rest)]
                        : $rest->map(self::fromNotification(...))),
                ];
            })
            ->all();
    }

    private static function fromNotification(UserNotification $notification): self
    {
        $time_critical = self::isTimeCritical($notification);

        return new self(
            title: $notification->title,
            body: $notification->body,
            data: array_filter([
                'type' => 'user_notification',
                'id' => (string) $notification->id,
                'priority' => $notification->priority->value,
                'url' => $notification->linkPath() ?? '/',

                /**
                 * Named after the record rather than the notification, so a second
                 * message about the same storing replaces the first on screen
                 * instead of queueing behind it. A reminder shares the tag of its
                 * appointment, so the message that it moved replaces it too.
                 */
                'tag' => mb_strtolower(class_basename($notification->notificationable_type))
                    . '-' . $notification->notificationable_id,
                'channel' => match (true) {
                    $time_critical => 'reminders',
                    $notification->priority === UserNotificationPriority::hoog => 'urgent',
                    default => 'general',
                },
                'actions' => $notification->actions ?: null,
            ], fn (mixed $value) => $value !== null),
            urgency: $time_critical ? 'high' : self::urgencyFor($notification->priority),
            ttl: $notification->expires_at
                ? max(1, (int) now()->diffInSeconds($notification->expires_at))
                : (int) config('webpush.ttl'),
        );
    }

    /**
     * Eén regel voor de hele stapel. Noemt waar het meeste over ging en hoeveel er
     * nog meer is, en brengt je naar de lijst in plaats van naar één record: welke
     * van de vijftien je bedoelde, weet alleen jij.
     *
     * @param  Collection<int, UserNotification>  $notifications
     */
    private static function summary(Collection $notifications): self
    {
        $count = $notifications->count();
        $titles = $notifications->pluck('title')->unique();
        $urgent = $notifications->contains(
            fn (UserNotification $notification) => $notification->priority === UserNotificationPriority::hoog
        );

        return new self(
            title: $count . ' nieuwe meldingen',
            body: $titles->count() === 1
                ? $titles->first() . ', ' . $count . ' keer.'
                : 'Je hebt ' . lcfirst((string) $notifications->first()->title)
                    . ' en ' . ($count - 1) . ' andere meldingen.',
            data: [
                'type' => 'user_notification_summary',
                'priority' => ($urgent ? UserNotificationPriority::hoog : UserNotificationPriority::normaal)->value,
                'url' => '/usernotifications',

                /**
                 * Altijd dezelfde tag: een tweede samenvatting hoort de eerste te
                 * vervangen. Twee tellingen naast elkaar zijn geen twee berichten
                 * maar één bericht dat zichzelf tegenspreekt.
                 */
                'tag' => 'meldingen-samenvatting',
                'channel' => $urgent ? 'urgent' : 'general',
            ],
            urgency: $urgent ? 'high' : 'normal',
            ttl: (int) config('webpush.ttl'),
        );
    }

    /**
     * What the service worker reads.
     *
     * @return array{notification: array{title: string, body: string}, data: array<string, mixed>}
     */
    public function webPayload(): array
    {
        return [
            'notification' => ['title' => $this->title, 'body' => $this->body],
            'data' => $this->data,
        ];
    }

    /**
     * What the native app reads. Firebase carries data as strings only, so the
     * buttons travel as JSON.
     *
     * @return array<string, string>
     */
    public function firebaseData(): array
    {
        return array_map(
            fn (mixed $value) => is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : (string) $value,
            ['title' => $this->title, 'body' => $this->body, ...$this->data],
        );
    }

    private static function isTimeCritical(UserNotification $notification): bool
    {
        return UserNotificationType::tryFrom($notification->type)?->timeCritical() ?? false;
    }

    /**
     * Urgency tells a push service whether waking a sleeping phone is worth its
     * battery. A storing marked urgent is; the rest can wait for the device to
     * come up on its own.
     */
    private static function urgencyFor(UserNotificationPriority $priority): string
    {
        return match ($priority) {
            UserNotificationPriority::hoog => 'high',
            UserNotificationPriority::laag => 'low',
            default => 'normal',
        };
    }
}
