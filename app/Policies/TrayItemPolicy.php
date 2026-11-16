<?php

namespace App\Policies;

use App\Models\TrayItem;
use App\Models\User;

/**
 * Everyone who can sign in has a bakje of their own, and only their own.
 *
 * What may go on it, and what pasting it may do, is decided by the records
 * themselves: the bakje only offers what the person can open, and every paste
 * goes through the same rights as doing it by hand.
 */
class TrayItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function paste(User $user): bool
    {
        return true;
    }

    public function clear(User $user): bool
    {
        return true;
    }

    public function update(User $user, TrayItem $tray_item): bool
    {
        return $tray_item->user_id === $user->id;
    }

    public function delete(User $user, TrayItem $tray_item): bool
    {
        return $tray_item->user_id === $user->id;
    }
}
