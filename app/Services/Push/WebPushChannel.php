<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

/**
 * The browsers: the PWA, Chrome on a phone, a laptop with the tab closed.
 *
 * Subscriptions that come back as gone are deleted rather than retried. The
 * browser has forgotten them, so nothing can ever be delivered to them again, and
 * keeping them means every later send pays for a request that cannot work.
 */
final class WebPushChannel implements PushChannel
{
    public function __construct(private LoggerInterface $logger) {}

    public function isConfigured(): bool
    {
        return (bool) config('webpush.public_key') && (bool) config('webpush.private_key');
    }

    public function send(array $batches): void
    {
        /**
         * Every subscription for everybody in one go. One notification per person
         * is the normal case, so a query per notification would be a query per
         * recipient for a set that is already known.
         */
        $by_user = PushSubscription::whereIn('user_id', array_keys($batches))->get()->groupBy('user_id');

        if ($by_user->isEmpty()) {
            return;
        }

        /** Built only once there is something to send: making it parses the keypair. */
        $push = $this->client();

        foreach ($batches as $user_id => $messages) {
            foreach ($messages as $message) {
                foreach ($by_user->get($user_id, []) as $subscription) {
                    $push->queueNotification(
                        $this->subscriptionFor($subscription),
                        json_encode($message->webPayload(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ['urgency' => $message->urgency, 'TTL' => $message->ttl],
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
            [],
            new Client(['timeout' => 10]),
            $factory,
            $factory,
            null,
            $this->logger,
        );
    }
}
