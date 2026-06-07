<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserPlannableRequest;
use App\Models\User;

class UserPlannableController extends Controller
{
    public function __invoke(UpdateUserPlannableRequest $request, User $user)
    {
        $user->update(['plannable' => $request->validated()['plannable']]);
        return response()->noContent();
    }
}
