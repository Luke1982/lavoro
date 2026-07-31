<?php

namespace App\Http\Controllers;

use App\Enums\UserNotificationType;
use App\Http\Requests\NotificationSubscriptionDestroyRequest;
use App\Http\Requests\NotificationSubscriptionListRequest;
use App\Http\Requests\NotificationSubscriptionStoreRequest;
use App\Models\NotificationSubscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Subscriptions are set from a form and answer like one, so a refused type comes
 * back as a validation message on the field that was switched.
 */
class NotificationSubscriptionController extends Controller
{
    /**
     * Elk soort melding met de schakelaar erachter. Wat iemand niet mag lezen staat
     * er wel bij, maar uit en op slot: verzwijgen zou de indruk wekken dat het
     * soort niet bestaat, terwijl het gewoon een recht is dat ontbreekt.
     */
    public function index(NotificationSubscriptionListRequest $request): Response
    {
        $subject = $request->filled('user_id')
            ? User::findOrFail($request->integer('user_id'))
            : $request->user();

        $subscribed = NotificationSubscription::where('user_id', $subject->id)
            ->pluck('id', 'type');

        $manages_others = $request->user()->can('manageOthers', NotificationSubscription::class);

        return inertia('NotificationSubscriptions/IndexPage', [
            'subject' => ['id' => $subject->id, 'name' => $subject->name],
            'manages_others' => $manages_others,
            'colleagues' => $manages_others
                ? User::orderBy('name')->get(['id', 'name'])
                : [],
            'types' => array_map(fn (UserNotificationType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'icon' => $type->icon(),
                'color' => $type->color(),
                'permission' => $type->requiredPermission(),
                'allowed' => $type->requiredPermission() === null
                    || $subject->hasPermission($type->requiredPermission()),
                'subscription_id' => $subscribed[$type->value] ?? null,
            ], UserNotificationType::cases()),
        ]);
    }

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
