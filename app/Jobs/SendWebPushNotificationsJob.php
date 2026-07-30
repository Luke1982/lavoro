<?php

namespace App\Jobs;

use App\Models\UserNotification;
use App\Services\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Carries the in-app notifications out to the browsers that asked for them.
 *
 * Ids travel rather than models, and the job is dispatched after commit, because
 * a push cannot be taken back: by the time this runs the storing it announces has
 * definitely happened. A notification that was rolled back away is simply not
 * found here, and nothing is sent.
 */
class SendWebPushNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, int>  $notification_ids
     */
    public function __construct(public array $notification_ids) {}

    public function handle(WebPushSender $sender): void
    {
        if ($this->notification_ids === []) {
            return;
        }

        $sender->sendAll(
            UserNotification::whereKey($this->notification_ids)->get()
        );
    }
}
