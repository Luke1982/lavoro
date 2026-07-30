<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserNotificationAcknowledgeRequest;
use App\Http\Requests\UserNotificationListRequest;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;

/**
 * The bell reads over JSON rather than through Inertia props: it is opened and
 * emptied without leaving the page, and reloading every page prop to strike one
 * notification through would be the wrong trade.
 */
class UserNotificationController extends Controller
{
    public function index(UserNotificationListRequest $request): JsonResponse
    {
        $notifications = $request->user()
            ->userNotifications()
            ->when($request->boolean('unread'), fn ($query) => $query->unread())
            ->paginate($request->integer('per_page') ?: 20)
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
     * The count travels back with the notification, so the badge never needs a
     * second call to agree with what was just clicked.
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

    private function unreadCount(UserNotificationListRequest|UserNotificationAcknowledgeRequest $request): int
    {
        return $request->user()->userNotifications()->unread()->count();
    }

    /** @return array<string, mixed> */
    private function present(UserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'priority' => $notification->priority->value,
            'priority_label' => $notification->priority->label(),
            'title' => $notification->title,
            'body' => $notification->body,
            'notificationable_type' => $notification->notificationable_type,
            'notificationable_id' => $notification->notificationable_id,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }
}
