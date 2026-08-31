<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Central\Module;
use App\Models\Central\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StorageQuota;
use App\Services\TenantSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LandlordController extends Controller
{
    public function showLogin()
    {
        return view('landlord.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (! Auth::guard('landlord')->attempt($data, true)) {
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

    public function index()
    {
        $rows = Tenant::on('central')->orderBy('name')->get()->map(function (Tenant $tenant) {
            $package = Package::on('central')->where('key', $tenant->package_key)->first();

            tenancy()->initialize($tenant);
            $field = User::where('seat_type', 'field')->count();
            $office = User::where('seat_type', 'office')->count();
            $used = (new StorageQuota)->usedBytes();
            tenancy()->end();

            return [
                'tenant' => $tenant,
                'field' => $field,
                'field_limit' => (int) ($package->field_seats ?? 0) + (int) $tenant->extra_field_seats,
                'office' => $office,
                'office_limit' => (int) ($package->office_seats ?? 0) + (int) $tenant->extra_office_seats,
                'used_gb' => round($used / (1024 ** 3), 2),
                'total' => (new TenantSubscription($tenant))->monthlyTotalCents(),
            ];
        });

        return view('landlord.index', [
            'rows' => $rows,
            'monthly' => $rows->sum('total'),
        ]);
    }

    public function edit(string $id)
    {
        return view('landlord.edit', [
            'tenant' => Tenant::on('central')->findOrFail($id),
            'packages' => Package::on('central')->orderBy('sort_order')->get(),
            'modules' => Module::on('central')->orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $data = $request->validate([
            'package_key' => 'nullable|string',
            'extra_field_seats' => 'required|integer|min:0',
            'extra_office_seats' => 'required|integer|min:0',
            'storage_limit_gb' => 'required|integer|min:0',
            'ai_allowance_micros' => 'nullable|integer|min:0',
            'price_override_cents' => 'nullable|integer|min:0',
            'modules' => 'array',
        ]);

        $tenant->update($data + ['modules' => $data['modules'] ?? []]);

        return redirect()->route('landlord.index')->with('status', $tenant->name . ' is bijgewerkt.');
    }
}
