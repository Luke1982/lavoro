<?php

namespace App\Services;

use App\Enums\UserNotificationPriority;
use App\Models\PushSubscription;
use App\Models\UserNotification;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

/**
 * Puts notifications on the subscriber's screen even when the app is shut.
 *
 * Everything here is best effort by design. A push service that is down, a phone
 * that has been off for a week, a browser that dropped its subscription: none of
 * that is a reason for the work that caused the notification to have failed, and
 * the in-app record is already written and waiting either way.
 *
 * Subscriptions that come back as gone are deleted rather than retried. The
 * browser has forgotten them, so nothing can ever be delivered to them again, and
 * keeping them means every later send pays for a request that cannot work.
 */
class WebPushSender
{
    /**
     * Tot en met dit aantal krijgt elke melding zijn eigen banner. Vijf is wat er
     * op een telefoonscherm past zonder dat je gaat vegen om ze weg te krijgen.
     */
    private const SUMMARY_FROM = 5;

    public function __construct(private LoggerInterface $logger) {}

    public function isConfigured(): bool
    {
        return (bool) config('webpush.public_key') && (bool) config('webpush.private_key');
    }

    /**
     * @param  iterable<UserNotification>  $notifications
     */
    public function sendAll(iterable $notifications): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $notifications = collect($notifications);

        /**
         * Every subscription for everybody in one go. One notification per person
         * is the normal case, so a query per notification would be a query per
         * recipient for a set that is already known.
         */
        $by_user = PushSubscription::whereIn('user_id', $notifications->pluck('user_id')->unique())
            ->get()
            ->groupBy('user_id');

        if ($by_user->isEmpty()) {
            return;
        }

        /** Built only once there is something to send: making it parses the keypair. */
        $push = $this->client();

        foreach ($notifications->groupBy('user_id') as $user_id => $for_user) {
            $subscriptions = $by_user->get($user_id, collect());

            if ($subscriptions->isEmpty()) {
                continue;
            }

            foreach ($this->messagesFor($for_user) as $message) {
                foreach ($subscriptions as $subscription) {
                    $push->queueNotification(
                        $this->subscriptionFor($subscription),
                        json_encode($message['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ['urgency' => $message['urgency']],
                    );
                }
            }
        }

        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();

                continue;
            }

            $this->logger->warning('Browsermelding niet bezorgd', [
                'endpoint' => $report->getEndpoint(),
                'reason' => $report->getReason(),
            ]);
        }
    }

    /**
     * Wat er van deze stapel bij één persoon op het scherm komt.
     *
     * Tot en met vijf zijn het losse berichten: dan is elk bericht nog te lezen en
     * gaat elke tik naar de juiste plek. Daarboven wordt het een rij banners die
     * niemand meer wegwerkt, en is één regel die zegt hoeveel het er zijn meer
     * waard dan vijftien die elk iets anders zeggen.
     *
     * @param  Collection<int, UserNotification>  $notifications
     * @return array<int, array<string, mixed>>
     */
    private function messagesFor(Collection $notifications): array
    {
        if ($notifications->count() > self::SUMMARY_FROM) {
            return [$this->summaryMessage($notifications)];
        }

        return $notifications->map(fn (UserNotification $notification) => [
            'payload' => $this->payloadFor($notification),
            'urgency' => $this->urgencyFor($notification),
        ])->all();
    }

    /**
     * Eén regel voor de hele stapel. Noemt waar het meeste over ging en hoeveel er
     * nog meer is, en brengt je naar de lijst in plaats van naar één record: welke
     * van de vijftien je bedoelde, weet alleen jij.
     *
     * @param  Collection<int, UserNotification>  $notifications
     * @return array<string, mixed>
     */
    private function summaryMessage(Collection $notifications): array
    {
        $count = $notifications->count();
        $titles = $notifications->pluck('title')->unique();
        $urgent = $notifications->contains(
            fn (UserNotification $notification) => $notification->priority === UserNotificationPriority::hoog
        );

        return [
            'payload' => [
                'notification' => [
                    'title' => $count . ' nieuwe meldingen',
                    'body' => $titles->count() === 1
                        ? $titles->first() . ', ' . $count . ' keer.'
                        : 'Je hebt ' . lcfirst((string) $notifications->first()->title)
                            . ' en ' . ($count - 1) . ' andere meldingen.',
                ],
                'data' => [
                    'type' => 'user_notification_summary',
                    'priority' => (string) ($urgent
                        ? UserNotificationPriority::hoog->value
                        : UserNotificationPriority::normaal->value),
                    'url' => '/usernotifications',

                    /**
                     * Altijd dezelfde tag: een tweede samenvatting hoort de eerste
                     * te vervangen. Twee tellingen naast elkaar zijn geen twee
                     * berichten maar één bericht dat zichzelf tegenspreekt.
                     */
                    'tag' => 'meldingen-samenvatting',
                ],
            ],
            'urgency' => $urgent ? 'high' : 'normal',
        ];
    }

    /**
     * The shape the service worker already reads, so one push handler serves both
     * this and anything Firebase may send the native app later.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(UserNotification $notification): array
    {
        return [
            'notification' => [
                'title' => $notification->title,
                'body' => $notification->body,
            ],
            'data' => [
                'type' => 'user_notification',
                'id' => (string) $notification->id,
                'priority' => $notification->priority->value,
                'url' => $notification->linkPath() ?? '/',

                /**
                 * Named after the record rather than the notification, so a second
                 * message about the same storing replaces the first on screen
                 * instead of queueing behind it. Two different storingen keep two
                 * banners, which is the point of not tagging them all the same.
                 */
                'tag' => mb_strtolower(class_basename($notification->notificationable_type))
                    . '-' . $notification->notificationable_id,
            ],
        ];
    }

    /**
     * Urgency tells a push service whether waking a sleeping phone is worth its
     * battery. A storing marked urgent is; the rest can wait for the device to
     * come up on its own.
     */
    private function urgencyFor(UserNotification $notification): string
    {
        return match ($notification->priority) {
            UserNotificationPriority::hoog => 'high',
            UserNotificationPriority::laag => 'low',
            default => 'normal',
        };
    }

    private function subscriptionFor(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            'contentEncoding' => $subscription->content_encoding,
        ]);
    }

    /**
     * Guzzle is handed over rather than discovered. Auto-discovery picks whatever
     * PSR-18 client happens to be installed, which is a decision that would be
     * made silently and differently on another machine.
     *
     * The logger is not optional in practice. Without one, the library reports its
     * advice about the GMP extension through trigger_error, and Laravel turns a
     * user notice into a thrown ErrorException: every push would fail on any
     * machine that has neither GMP nor BCMath. With one, the same advice is a line
     * in the log, where it belongs. (Installing php-gmp silences it and makes the
     * encryption faster besides.)
     */
    private function client(): WebPush
    {
        $factory = new HttpFactory;

        return new WebPush(
            [
                'VAPID' => [
                    'subject' => (string) config('webpush.subject'),
                    'publicKey' => (string) config('webpush.public_key'),
                    'privateKey' => (string) config('webpush.private_key'),
                ],
            ],
            ['TTL' => (int) config('webpush.ttl')],
            new Client(['timeout' => 10]),
            $factory,
            $factory,
            null,
            $this->logger,
        );
    }
}
