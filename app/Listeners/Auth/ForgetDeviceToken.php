<?php

namespace App\Listeners\Auth;

use App\Models\DeviceToken;
use Illuminate\Auth\Events\Logout;

/**
 * A phone that logs out stops receiving that person's notifications, with the
 * addresses and phone numbers in them.
 *
 * The app deletes its own token before it logs out, but on a bad connection that
 * request can fail while the logout goes through. The token this session handed
 * in is remembered in it, and is deleted here, where a logout cannot happen
 * without it. Only for the customers' own guard: the panel has no app.
 */
class ForgetDeviceToken
{
    public function handle(Logout $event): void
    {
        if ($event->guard !== 'web' || !request()->hasSession()) {
            return;
        }

        $token = request()->session()->pull(DeviceToken::SESSION_KEY);

        if ($token) {
            DeviceToken::where('token', $token)->where('user_id', $event->user?->getAuthIdentifier())->delete();
        }
    }
}
