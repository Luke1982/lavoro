<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUpdateAuthRequest;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create()
    {
        $company = Company::where('is_main', true)->first();
        return inertia('Auth/LoginPage', [
            'company' => $company ? [
                'name' => $company->name,
                'logo_url' => $company->logo_path ? asset('storage/' . $company->logo_path) : null,
            ] : null
        ]);
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
