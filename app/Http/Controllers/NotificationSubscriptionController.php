<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationSubscriptionDestroyRequest;
use App\Http\Requests\NotificationSubscriptionStoreRequest;
use App\Models\NotificationSubscription;
use Illuminate\Http\RedirectResponse;

/**
 * Subscriptions are set from a form and answer like one, so a refused type comes
 * back as a validation message on the field that was switched.
 */
class NotificationSubscriptionController extends Controller
{
    public function store(NotificationSubscriptionStoreRequest $request): RedirectResponse
    {
        NotificationSubscription::create([
            'user_id' => $request->subscriber()->id,
            'type' => $request->validated('type'),
        ]);

        return back()->with('success', 'Melding aangezet.');
    }

    public function destroy(NotificationSubscriptionDestroyRequest $request, NotificationSubscription $notificationsubscription): RedirectResponse
    {
        $notificationsubscription->delete();

        return back()->with('success', 'Melding uitgezet.');
    }
}
