<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserUnavailabilityDestroyRequest;
use App\Http\Requests\UserUnavailabilityStoreRequest;
use App\Models\User;
use App\Models\UserUnavailability;

class UserUnavailabilityController extends Controller
{
    public function store(UserUnavailabilityStoreRequest $request, User $user): \Illuminate\Http\RedirectResponse
    {
        $user->unavailabilities()->create($request->validated());

        return back();
    }

    public function destroy(UserUnavailabilityDestroyRequest $request, User $user, UserUnavailability $unavailability): \Illuminate\Http\RedirectResponse
    {
        $unavailability->delete();

        return back();
    }
}
