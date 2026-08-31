<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function create()
    {
        return inertia('Auth/ForgotPasswordPage');
    }

    public function store(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        /** Stil doorlopen bij een onbekend adres: anders vertelt dit scherm wie er bestaat. */
        if (! $this->initializeTenantFromEmail($request->input('email'))) {
            return back()->with('status', __(Password::RESET_LINK_SENT));
        }

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    public function edit(string $token, Request $request)
    {
        return inertia('Auth/ResetPasswordPage', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        if (! $this->initializeTenantFromEmail($request->input('email'))) {
            return back()->withErrors(['email' => __(Password::INVALID_USER)]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    /**
     * Zonder tenant is er geen users-tabel om in te zoeken. Het e-mailadres is
     * het enige dat het verzoek meebrengt, dus dat wijst de tenant aan -- net
     * als bij inloggen.
     */
    private function initializeTenantFromEmail(?string $email): bool
    {
        if (! $email || tenancy()->initialized) {
            return tenancy()->initialized;
        }

        $lookup = UserTenantLookup::on('central')->where('email', $email)->first();
        $tenant = $lookup ? Tenant::on('central')->find($lookup->tenant_id) : null;

        if (! $tenant) {
            return false;
        }

        tenancy()->initialize($tenant);

        return true;
    }
}
