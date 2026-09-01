<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Inloggen op het beheerpaneel.
 */
class AuthController extends Controller
{
    public function showLogin()
    {
        return view('landlord.login');
    }

    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        if (!Auth::guard('landlord')->attempt($data, true)) {
            return back()->withErrors(['email' => 'Kon niet inloggen'])->withInput();
        }

        $request->session()->regenerate();

        return redirect()->route('landlord.index');
    }

    public function logout(Request $request)
    {
        Auth::guard('landlord')->logout();
        $request->session()->invalidate();

        return redirect()->route('landlord.login');
    }
}
