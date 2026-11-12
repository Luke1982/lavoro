<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\SendReport;
use Psr\Log\LoggerInterface;

/**
 * The Android app. Its webview cannot receive web push, so it gets the same
 * messages through Firebase.
 *
 * Data only, never a notification block: the system would draw that one itself
 * while the app is shut, and a banner drawn by the system cannot carry buttons.
 * The app's LavoroMessagingService draws it instead, from these fields.
 *
 * One Firebase project serves every customer, so the credentials are global; the
 * tokens live in each customer's own database.
 */
final class FirebaseChannel implements PushChannel
{
    public function __construct(private LoggerInterface $logger) {}

    /** The service account: a path to its JSON, or the JSON itself. Empty when the app gets no push. */
    public static function credentials(): string
    {
        return trim((string) config('firebase.projects.' . config('firebase.default') . '.credentials'));
    }

    public function isConfigured(): bool
    {
        return self::credentials() !== '';
    }

    public function send(array $batches): void
    {
        $by_user = DeviceToken::whereIn('user_id', array_keys($batches))->get()->groupBy('user_id');

        if ($by_user->isEmpty()) {
            return;
        }

        /** Resolved only now: making it reads the credentials file. */
        $messaging = app(Messaging::class);
        $gone = [];

        foreach ($batches as $user_id => $messages) {
            $tokens = $by_user->get($user_id)?->pluck('token')->all() ?? [];

            if ($tokens === []) {
                continue;
            }

            foreach ($messages as $message) {
                $report = $messaging->sendMulticast($this->cloudMessage($message), $tokens);

                /**
                 * A token Firebase no longer knows belongs to an app that was
                 * removed or reinstalled; nothing will ever arrive there again.
                 */
                $gone = [...$gone, ...$report->unknownTokens(), ...$report->invalidTokens()];

                foreach ($report->failures()->getItems() as $failure) {
                    $this->reportFailure($failure);
                }
            }
        }

        if ($gone !== []) {
            DeviceToken::whereIn('token', array_unique($gone))->delete();
        }
    }

    /**
     * High priority is what lets a message through Android's doze; it is spent only
     * on what is urgent, because Android lowers the priority of an app that spends
     * it on messages nobody needed to see at once.
     */
    private function cloudMessage(PushMessage $message): CloudMessage
    {
        return CloudMessage::new()
            ->withData($message->firebaseData())
            ->withAndroidConfig([
                'priority' => $message->urgency === 'high' ? 'high' : 'normal',
                'ttl' => $message->ttl,
            ]);
    }

    private function reportFailure(SendReport $failure): void
    {
        if ($failure->messageWasSentToUnknownToken() || $failure->messageTargetWasInvalid()) {
            return;
        }

        $this->logger->warning('Appmelding niet bezorgd', [
            'token' => mb_substr($failure->target()->value(), 0, 12) . '…',
            'reason' => $failure->error()?->getMessage(),
        ]);
    }
}
