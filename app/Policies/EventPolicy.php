<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.read');
    }

    /** Seeing everybody's appointments, the planner's other rows included. */
    public function seeAll(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.see_all');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.create');
    }

    public function createOthers(User $user, User $owner_user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($owner_user->id === $user->id) {
            return $user->hasPermission('event.create');
        }

        return $user->hasPermission('event.create_others');
    }

    /**
     * Changing an appointment: anybody's with event.update_others, and with
     * event.update one that is yours, because you made it or because you are on
     * it.
     */
    public function update(User $user, Event $event): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('event.update_others')
            || ($user->hasPermission('event.update') && $this->isTheirs($user, $event));
    }

    /** Removing an appointment, by the same rule as changing it. */
    public function delete(User $user, Event $event): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('event.delete_others')
            || ($user->hasPermission('event.delete') && $this->isTheirs($user, $event));
    }

    /** Copying an appointment makes new ones, from one this person can see. */
    public function copy(User $user, Event $event): bool
    {
        return $this->create($user) && Event::query()->visibleTo($user)->whereKey($event->id)->exists();
    }

    /**
     * Reading and sending an appointment's standard e-mails to the customer:
     * anybody's with event.update_others, and with event.update one you made.
     * Being on an appointment is not enough; a mechanic does not write to the
     * customer on the company's behalf.
     */
    public function sendStandardEmail(User $user, Event $event): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('event.update_others')
            || ($user->hasPermission('event.update') && $this->isOwner($user, $event));
    }

    public function executeOwn(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPermission('event.execute') && $event->hasExecutingUser($user->id);
    }

    public function export(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.export');
    }

    public function seeBeyondCurrentWeek(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.see_beyond_current_week');
    }

    public function provideFeedback(User $user, Event $event): bool
    {
        if ($event->serviceOrders()->exists()) {
            return false;
        }

        return $user->isAdmin() || $user->hasPermission('event.provide_feedback');
    }

    public function releaseTimes(User $user, Event $event): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.release_times');
    }

    public function executeOthers(User $user, Event $event, int $target_user_id): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPermission('event.execute_others') && $event->hasExecutingUser($target_user_id);
    }

    /**
     * Read from the crew already loaded when it is, since the planner asks this
     * of every appointment in the week it shows.
     */
    private function isTheirs(User $user, Event $event): bool
    {
        if ($this->isOwner($user, $event)) {
            return true;
        }

        return $event->relationLoaded('executingUsers')
            ? $event->executingUsers->contains('id', $user->id)
            : $event->hasExecutingUser($user->id);
    }

    private function isOwner(User $user, Event $event): bool
    {
        if ($event->relationLoaded('owners')) {
            return $event->owners->contains(fn (User $owner) => $owner->id === $user->id && $owner->pivot?->type === 'owner');
        }

        return $event->owners()->where('users.id', $user->id)->wherePivot('type', 'owner')->exists();
    }
}
