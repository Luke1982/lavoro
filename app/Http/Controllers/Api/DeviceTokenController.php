<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpsertDeviceTokenRequest;
use App\Models\DeviceToken;

class DeviceTokenController extends Controller
{
    public function upsert(UpsertDeviceTokenRequest $request): \Illuminate\Http\JsonResponse
    {
        $token = $request->validated('token');
        $user  = $request->user();

        // Move token to current user if another user previously owned it (re-install / device re-use).
        DeviceToken::where('token', $token)->where('user_id', '!=', $user->id)->delete();

        DeviceToken::updateOrCreate(
            ['token' => $token],
            ['user_id' => $user->id, 'platform' => $request->validated('platform')]
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(UpsertDeviceTokenRequest $request): \Illuminate\Http\JsonResponse
    {
        DeviceToken::where('token', $request->validated('token'))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
