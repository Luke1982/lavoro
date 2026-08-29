<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUpdateAuthRequest;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
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
        $lookup = UserTenantLookup::on('central')->where('email', $request->email)->first();

        $tenant = $lookup ? Tenant::on('central')->find($lookup->tenant_id) : null;

        if (! $tenant) {
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        tenancy()->initialize($tenant);

        if (! Auth::attempt($request->only('email', 'password'), true)) {
            tenancy()->end();

            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        $request->session()->put('tenant_id', $tenant->id);
        cookie()->queue(cookie()->forever('tenant_id', $tenant->id));
        $request->session()->regenerate();

        return redirect()->intended();
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        cookie()->queue(cookie()->forget('tenant_id'));

        return redirect()->route('login');
    }
}
