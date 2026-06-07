<?php

namespace App\Notifications;

use App\Models\ServiceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\FCM\FCMChannel;
use NotificationChannels\FCM\FCMMessage;
use NotificationChannels\FCM\Resources\Notification as FCMNotification;

class NewServiceOrderAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ServiceOrder $service_order)
    {
    }

    public function via(object $notifiable): array
    {
        if (empty($notifiable->routeNotificationForFcm())) {
            return [];
        }

        return [FCMChannel::class];
    }

    public function toFcm(object $notifiable): FCMMessage
    {
        return FCMMessage::create()
            ->setNotification(
                FCMNotification::create()
                    ->setTitle('Nieuwe werkbon toegewezen')
                    ->setBody("Werkbon #{$this->service_order->id} is aan u toegewezen.")
            )
            ->setData([
                'type' => 'service_order_assigned',
                'id'   => (string) $this->service_order->id,
            ]);
    }
}
