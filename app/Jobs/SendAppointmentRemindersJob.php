<?php

namespace App\Jobs;

use App\Services\AppointmentReminders;
use App\Services\Push\PushNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One customer's round of reminders. Sent from here rather than through another
 * job: this already runs on the queue, and a second hop only adds minutes to a
 * message whose whole point is being on time.
 */
class SendAppointmentRemindersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(AppointmentReminders $reminders, PushNotifications $push): void
    {
        $now = now();

        /**
         * Opruimen mag mislukken zonder dat de herinneringen van deze ronde
         * blijven liggen: schrijven heeft het opruimen niet nodig.
         */
        try {
            $reminders->tidy($now);
        } catch (\Throwable $e) {
            report($e);
        }

        $push->send($reminders->write($now));
    }
}
