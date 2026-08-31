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

    public function catalogue()
    {
        return view('landlord.catalogue', [
            'packages' => Package::on('central')->orderBy('sort_order')->get(),
            'modules' => Module::on('central')->orderBy('sort_order')->get(),
            'bundles' => \App\Models\Central\ModuleBundle::on('central')->get(),
            'settings' => \App\Models\Central\PricingSetting::on('central')->orderBy('key')->get(),
            'usage' => \Illuminate\Support\Facades\DB::connection('central')->table('tenants')
                ->selectRaw('package_key, COUNT(*) AS aantal')->groupBy('package_key')->pluck('aantal', 'package_key'),
        ]);
    }

    public function updatePackage(Request $request, int $id)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'field_seats' => 'required|integer|min:0',
            'office_seats' => 'required|integer|min:0',
            'price_cents' => 'required|integer|min:0',
            'extra_field_cents' => 'required|integer|min:0',
            'extra_office_cents' => 'required|integer|min:0',
        ]);

        Package::on('central')->findOrFail($id)->update($data);

        return back()->with('status', 'Pakket bijgewerkt.');
    }

    public function updateModule(Request $request, int $id)
    {
        $data = $request->validate(['name' => 'required|string', 'price_cents' => 'required|integer|min:0']);

        Module::on('central')->findOrFail($id)->update($data);

        return back()->with('status', 'Module bijgewerkt.');
    }

    public function updateSetting(Request $request, int $id)
    {
        $data = $request->validate(['value' => 'required|integer|min:0']);

        \App\Models\Central\PricingSetting::on('central')->findOrFail($id)->update($data);

        return back()->with('status', 'Instelling bijgewerkt.');
    }

    public function edit(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $spent = (int) \Illuminate\Support\Facades\DB::connection('central')->table('assistant_usage')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micros');

        $allowance = (int) ($tenant->ai_allowance_micros
            ?? \App\Models\Central\PricingSetting::value('ai_allowance_micros', 12_500_000));

        return view('landlord.edit', [
            'tenant' => $tenant,
            'ai_spent_euro' => $spent / 1_000_000,
            'ai_allowance_euro' => $allowance / 1_000_000,
            'ai_is_default' => $tenant->ai_allowance_micros === null,
            'sub' => new TenantSubscription($tenant),
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
            'ai_allowance_euro' => 'nullable|numeric|min:0',
            'price_override_euro' => 'nullable|numeric|min:0',
            'discount_euro' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|integer|min:0|max:100',
            'modules' => 'array',
        ]);

        $money = fn (?string $key) => ($data[$key] ?? '') === '' || ! isset($data[$key])
            ? null
            : (int) round((float) $data[$key] * 100);

        $ai = ($data['ai_allowance_euro'] ?? '') === '' || ! isset($data['ai_allowance_euro'])
            ? null
            : (int) round((float) $data['ai_allowance_euro'] * 1_000_000);

        $percent = ($data['discount_percent'] ?? '') === '' ? null : (int) $data['discount_percent'];

        unset($data['ai_allowance_euro'], $data['price_override_euro'], $data['discount_euro'], $data['discount_percent']);

        $tenant->update($data + [
            'modules' => $data['modules'] ?? [],
            'ai_allowance_micros' => $ai,
            'price_override_cents' => $money('price_override_euro'),
            'discount_cents' => $money('discount_euro'),
            'discount_percent' => $percent,
        ]);

        return redirect()->route('landlord.index')->with('status', $tenant->name . ' is bijgewerkt.');
    }
}
