<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationMuteDestroyRequest;
use App\Http\Requests\NotificationMuteStoreRequest;
use App\Models\NotificationMute;
use Illuminate\Http\RedirectResponse;

/**
 * The switch for a notification everybody gets until they turn it off. Off is a
 * row here, on is its absence, so the Meldingen screen answers both ways.
 */
class NotificationMuteController extends Controller
{
    public function store(NotificationMuteStoreRequest $request): RedirectResponse
    {
        NotificationMute::create([
            'user_id' => $request->subscriber()->id,
            'type' => $request->validated('type'),
        ]);

        return back()->with('success', 'Melding uitgezet.');
    }

    public function destroy(NotificationMuteDestroyRequest $request, NotificationMute $notificationmute): RedirectResponse
    {
        $notificationmute->delete();

        return back()->with('success', 'Melding aangezet.');
    }
}
