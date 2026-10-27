<?php

namespace App\Http\Controllers;

use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
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

        /** Carry on quietly for an unknown address: otherwise this screen tells who exists. */
        if (!$this->initializeTenantFromEmail($request->input('email'))) {
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
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        if (!$this->initializeTenantFromEmail($request->input('email'))) {
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
     * Without a tenant there is no users table to search in. The email address
     * is the only thing the request brings, so that points at the tenant --
     * like when logging in.
     */
    private function initializeTenantFromEmail(?string $email): bool
    {
        if (!$email || tenancy()->initialized) {
            return tenancy()->initialized;
        }

        $lookup = UserTenantLookup::on('central')->where('email', $email)->first();
        $tenant = $lookup ? Tenant::on('central')->find($lookup->tenant_id) : null;

        if (!$tenant) {
            return false;
        }

        tenancy()->initialize($tenant);

        return true;
    }
}
