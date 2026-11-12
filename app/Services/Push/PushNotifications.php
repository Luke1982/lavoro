<?php

namespace App\Services\Push;

use App\Models\UserNotification;
use Psr\Log\LoggerInterface;

/**
 * Puts notifications on the subscriber's screen even when the app is shut, on
 * every kind of device they asked for it on.
 *
 * Everything here is best effort by design. A push service that is down, a phone
 * that has been off for a week, a browser that dropped its subscription: none of
 * that is a reason for the work that caused the notification to have failed, and
 * the in-app record is already written and waiting either way. One channel that
 * breaks does not keep the other from delivering.
 */
final class PushNotifications
{
    /** @var list<PushChannel> */
    private array $channels;

    public function __construct(
        WebPushChannel $web,
        FirebaseChannel $firebase,
        private LoggerInterface $logger,
    ) {
        $this->channels = [$web, $firebase];
    }

    public function isConfigured(): bool
    {
        return $this->configuredChannels() !== [];
    }

    /**
     * @param  iterable<UserNotification>  $notifications
     */
    public function send(iterable $notifications): void
    {
        $channels = $this->configuredChannels();

        if ($channels === []) {
            return;
        }

        $batches = PushMessage::batchesFor(collect($notifications));

        if ($batches === []) {
            return;
        }

        foreach ($channels as $channel) {
            try {
                $channel->send($batches);
            } catch (\Throwable $e) {
                $this->logger->error('Pushberichten niet verstuurd', [
                    'channel' => class_basename($channel),
                    'exception' => $e,
                ]);
            }
        }
    }

    /** @return list<PushChannel> */
    private function configuredChannels(): array
    {
        return array_values(array_filter(
            $this->channels,
            fn (PushChannel $channel) => $channel->isConfigured()
        ));
    }
}
