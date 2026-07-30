<?php

namespace App\Listeners;

use App\Domain\Signals\Signal;
use App\Enums\UserNotificationType;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Tells the people who asked to be told. Registered by Laravel's listener
 * discovery through the Signal type hint, exactly like the activity trail, so
 * every fact the application already raises is a candidate and no creation path
 * has to know that notifications exist. Only the keys named in
 * UserNotificationType produce anything.
 *
 * The rows are written inside the transaction that raised the signal, so work
 * that rolls back takes its notifications with it and nobody is told about a
 * storing that never happened. That is only safe because these stay in the
 * database: a push message or an e-mail cannot be taken back and would have to
 * wait for the commit.
 *
 * Whoever caused the fact is skipped. They know what they just did, and a bell
 * that rings for your own actions is a bell people stop reading.
 */
class NotifySubscribers
{
    /**
     * A notification that cannot be written must not break the work it was about.
     * The same reasoning as the audit trail: report it and let the save stand.
     */
    public function handle(Signal $signal): void
    {
        try {
            $this->notify($signal);
        } catch (\Throwable $e) {
            Log::error('Kon meldingen niet vastleggen', [
                'signal' => $signal::key(),
                'event_key' => $signal->eventKey(),
                'exception' => $e,
            ]);
        }
    }

    private function notify(Signal $signal): void
    {
        $type = UserNotificationType::tryFrom($signal->eventKey());

        if ($type === null) {
            return;
        }

        $subscribers = $this->subscribersFor($type, $signal->actorId());

        if ($subscribers->isEmpty()) {
            return;
        }

        $subject = $signal->subject();
        $title = $type->titleFor($signal);
        $body = $type->bodyFor($signal);
        $priority = $type->priorityFor($signal);
        $now = now();

        UserNotification::insert($subscribers->map(fn (int $user_id) => [
            'user_id' => $user_id,
            'type' => $type->value,
            'priority' => $priority->value,
            'notificationable_type' => $subject->getMorphClass(),
            'notificationable_id' => $subject->getKey(),
            'title' => $title,
            'body' => $body,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * Subscribed, not the actor, and still allowed to read the thing. The
     * permission is resolved in the query rather than per user, because this runs
     * in the middle of somebody's save.
     *
     * @return Collection<int, int>
     */
    private function subscribersFor(UserNotificationType $type, ?int $actor_id): Collection
    {
        $permission = $type->requiredPermission();

        return User::query()
            ->whereHas('notificationSubscriptions', fn ($query) => $query->where('type', $type->value))
            ->when($actor_id !== null, fn ($query) => $query->whereKeyNot($actor_id))
            ->when($permission !== null, fn ($query) => $query->where(fn ($allowed) => $allowed
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'admin'))
                ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('name', $permission))))
            ->pluck('id');
    }
}
