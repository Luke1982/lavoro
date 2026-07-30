<?php

namespace App\Http\Controllers;

use App\Http\Requests\PushSubscriptionDestroyRequest;
use App\Http\Requests\PushSubscriptionStoreRequest;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;

class PushSubscriptionController extends Controller
{
    /**
     * A browser may hand in the same endpoint again with fresh keys, which is what
     * happens when it renews a subscription on its own. Updating the row keeps the
     * one that works and drops the one that no longer decrypts.
     *
     * An endpoint that belonged to somebody else is taken over rather than
     * refused: a shared device where a second person signs in is a normal day, and
     * the browser has only one subscription to give.
     */
    public function store(PushSubscriptionStoreRequest $request): JsonResponse
    {
        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $request->validated('endpoint')],
            [
                'user_id' => $request->user()->id,
                'public_key' => $request->validated('keys.p256dh'),
                'auth_token' => $request->validated('keys.auth'),
                'content_encoding' => $request->validated('content_encoding') ?? 'aes128gcm',
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]
        );

        return response()->json(['id' => $subscription->id], 201);
    }

    public function destroy(PushSubscriptionDestroyRequest $request): JsonResponse
    {
        PushSubscription::where('endpoint', $request->validated('endpoint'))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['deleted' => true]);
    }
}
