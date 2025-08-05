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
        $user = Auth::user();

        // $existingToken = $user->tokens()->where('name', 'api')->first();

        // $token = $existingToken ? $existingToken->plainTextToken : $user->createToken('api')->plainTextToken;
        // $request->session()->put('token', $token);

        return redirect()->intended();
    }

    public function destroy(Request $request)
    {
        // Auth::user()->tokens()->delete();
        Auth::logout();
        $request->session()->invalidate();
        // $request->session()->regenerateToken();
        return redirect()->route('index');
    }
}
