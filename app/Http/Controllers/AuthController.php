<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUpdateAuthRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create()
    {
        return inertia('Auth/LoginPage');
    }

    public function store(StoreUpdateAuthRequest $request)
    {
        if (!Auth::attempt($request->validated(), true)) {
            throw ValidationException::withMessages([
                'email' => 'Kon niet inloggen'
            ]);
        }

        $request->session()->regenerate();
        return redirect()->intended();
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        return redirect()->route('login');
    }
}
