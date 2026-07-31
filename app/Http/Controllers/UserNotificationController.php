<?php

namespace App\Http\Controllers;

use App\Enums\UserNotificationPriority;
use App\Enums\UserNotificationType;
use App\Http\Requests\UserNotificationAcknowledgeRequest;
use App\Http\Requests\UserNotificationListRequest;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * De pagina toont de hele lijst; het belletje leest hetzelfde over JSON. Dat
 * laatste is met opzet geen Inertia-bezoek: de lijst wordt geopend en leeggemaakt
 * zonder de pagina te verlaten, en alle eigenschappen van een pagina opnieuw
 * ophalen om één melding door te strepen zou de verkeerde ruil zijn.
 */
class UserNotificationController extends Controller
{
    public function index(UserNotificationListRequest $request): Response
    {
        return inertia('UserNotifications/IndexPage', [
            'notifications' => $this->query($request)->paginate(25)->withQueryString()
                ->through(fn (UserNotification $notification) => $this->present($notification)),
            'unread_count' => $this->unreadCount($request),
            'filter' => [
                'unread' => $request->boolean('unread'),
                'important' => $request->boolean('important'),
            ],
        ]);
    }

    public function feed(UserNotificationListRequest $request): JsonResponse
    {
        $notifications = $this->query($request)
            ->paginate($request->integer('per_page') ?: 20)
            ->withQueryString()
            ->through(fn (UserNotification $notification) => $this->present($notification));

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    public function acknowledge(
        UserNotificationAcknowledgeRequest $request,
        UserNotification $usernotification,
    ): JsonResponse {
        $usernotification->acknowledge();

        return $this->respondWith($request, $usernotification);
    }

    public function unacknowledge(
        UserNotificationAcknowledgeRequest $request,
        UserNotification $usernotification,
    ): JsonResponse {
        $usernotification->unacknowledge();

        return $this->respondWith($request, $usernotification);
    }

    /**
     * Alles ineens wegstrepen slaat de modellen over: dit is één update van een
     * hele verzameling, en er is niets aan een rij dat regel voor regel bekeken
     * hoeft te worden.
     */
    public function acknowledgeAll(UserNotificationListRequest $request): JsonResponse
    {
        $request->user()->userNotifications()->unread()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    private function query(UserNotificationListRequest $request)
    {
        return $request->user()
            ->userNotifications()
            ->when($request->boolean('unread'), fn ($query) => $query->unread())
            ->when(
                $request->boolean('important'),
                fn ($query) => $query->where('priority', UserNotificationPriority::hoog->value)
            );
    }

    /**
     * De teller reist mee met de melding, zodat het cijfer op het belletje nooit
     * een tweede vraag nodig heeft om het eens te zijn met wat er net geklikt is.
     */
    private function respondWith(
        UserNotificationAcknowledgeRequest $request,
        UserNotification $notification,
    ): JsonResponse {
        return response()->json([
            'notification' => $this->present($notification),
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    private function unreadCount(Request $request): int
    {
        return $request->user()->userNotifications()->unread()->count();
    }

    /** @return array<string, mixed> */
    private function present(UserNotification $notification): array
    {
        $type = UserNotificationType::tryFrom($notification->type);

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'icon' => $type?->icon(),
            'color' => $type?->color() ?? 'blue',
            'priority' => $notification->priority->value,
            'priority_label' => $notification->priority->label(),
            'title' => $notification->title,
            'body' => $notification->body,
            'notificationable_type' => $notification->notificationable_type,
            'notificationable_id' => $notification->notificationable_id,

            /** Hier uitgerekend, net als voor de service worker, zodat die twee het eens zijn. */
            'url' => $notification->linkPath(),
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }
}
