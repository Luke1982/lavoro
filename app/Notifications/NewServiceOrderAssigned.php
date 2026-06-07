<?php

namespace App\Notifications;

use App\Models\ServiceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class NewServiceOrderAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ServiceOrder $service_order)
    {
    }

    public function via(object $notifiable): array
    {
        return empty($notifiable->routeNotificationFor('fcm')) ? [] : [FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create()
            ->notification(
                FcmNotification::create()
                    ->title('Nieuwe werkbon toegewezen')
                    ->body("Werkbon #{$this->service_order->id} is aan u toegewezen.")
            )
            ->data([
                'type' => 'service_order_assigned',
                'id'   => (string) $this->service_order->id,
            ]);
    }
}
