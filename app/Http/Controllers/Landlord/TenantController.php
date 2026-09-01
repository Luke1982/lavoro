<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\DestroyTenantRequest;
use App\Http\Requests\Landlord\StoreTenantRequest;
use App\Http\Requests\Landlord\UpdateTenantRequest;
use App\Jobs\RunTenantProvisioningRequestJob;
use App\Models\Central\AiTopup;
use App\Models\Central\Module;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Models\Central\Reseller;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicer;
use App\Services\StorageQuota;
use App\Services\TenantSubscription;
use App\Services\TenantSuperAdmins;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * De klanten zelf: overzicht, abonnement, aanmaken en verwijderen.
 */
class TenantController extends Controller
{
    public function index()
    {
        $rows = Tenant::on('central')->orderBy('name')->get()->map(function (Tenant $tenant) {
            $package = Package::on('central')->where('key', $tenant->package_key)->first();

            /**
             * Via de helper: klapt het bij één klant, dan blijft die tenant
             * anders openstaan en telt de volgende ronde in de database van
             * de vorige.
             */
            [$field, $office, $used] = Tenancy::within($tenant, fn () => [
                User::occupyingSeat('field')->count(),
                User::occupyingSeat('office')->count(),
                (new StorageQuota)->usedBytes(),
            ]);

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
            'packages' => Package::on('central')->orderBy('sort_order')->get(),
            'modules' => Module::on('central')->orderBy('sort_order')->get(),
            /** Werk dat nog loopt of is misgelopen. Geslaagd werk is de tenant zelf. */
            'requests' => TenantProvisioningRequest::on('central')
                ->whereIn('status', ['queued', 'running', 'failed'])
                ->orderByDesc('id')
                ->get(),
            /**
             * Wachtwoorden die nog opgehaald moeten worden, los van dat werk.
             * Alleen van klanten die nog bestaan: het wachtwoord van een
             * weggegooide klant hoort nergens meer te staan.
             */
            'passwords' => TenantProvisioningRequest::on('central')
                ->whereNotNull('generated_password')
                ->whereIn('tenant_id', Tenant::on('central')->pluck('id'))
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function edit(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $spent = (int) DB::connection('central')->table('assistant_usage')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micros');

        $allowance = (int) ($tenant->ai_allowance_micros
            ?? PricingSetting::value('ai_allowance_micros', 12_500_000));

        return view('landlord.edit', [
            'tenant' => $tenant,
            'ai_spent_euro' => $spent / 1_000_000,
            'ai_allowance_euro' => $allowance / 1_000_000,
            'ai_is_default' => $tenant->ai_allowance_micros === null,
            'ai_topup_euro' => AiTopup::on('central')
                ->where('tenant_id', $tenant->id)->sum('granted_micros') / 1_000_000,
            'sub' => new TenantSubscription($tenant),
            'invoicer' => new Invoicer($tenant),
            'topups' => AiTopup::on('central')->where('tenant_id', $tenant->id)->latest()->get(),
            'topup_rate' => PricingSetting::value('ai_topup_cents_per_euro_granted', 200),
            'reseller' => $tenant->reseller_id ? Reseller::on('central')->find($tenant->reseller_id) : null,
            'packages' => Package::on('central')->orderBy('sort_order')->get(),
            'modules' => Module::on('central')->orderBy('sort_order')->get(),
            'superadmins' => app(TenantSuperAdmins::class)->all($tenant),
        ]);
    }

    public function update(UpdateTenantRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        /**
         * Voor en na, want een pakketwissel halverwege de maand levert een
         * verrekening op voor de volgende factuur.
         */
        $before = (new TenantSubscription($tenant))->monthlyTotalCents();

        $tenant->update($request->tenantAttributes());

        $after = (new TenantSubscription($tenant->refresh()))->monthlyTotalCents();
        $charge = (new Invoicer($tenant))->prorate($before, $after);

        return redirect()->route('landlord.edit', $tenant->id)->with(
            'status',
            $tenant->name . ' is bijgewerkt.' . ($charge
                ? ' Verrekening van € ' . number_format($charge->amount_cents / 100, 2, ',', '.')
                    . ' staat klaar voor de volgende factuur.'
                : ''),
        );
    }

    public function storeTenant(StoreTenantRequest $request)
    {
        $data = $request->validated();

        $provisioning = TenantProvisioningRequest::on('central')->create([
            'action' => 'create',
            'name' => $data['name'],
            'email' => $data['email'],
            'package_key' => $data['package_key'],
            'modules' => $data['modules'] ?? [],
        ]);

        RunTenantProvisioningRequestJob::dispatch($provisioning->id)->onQueue('provisioning');

        return back()->with('status', 'Aanvraag klaargezet. Zodra de provisioner klaar is verschijnt '
            . $data['name'] . ' in de lijst, met het wachtwoord erbij.');
    }

    /**
     * Het paneel mag geen database weggooien -- dat doet de provisioner. Hier
     * wordt alleen de opdracht neergelegd, na een naam die letterlijk klopt.
     */
    public function destroyTenant(DestroyTenantRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $provisioning = TenantProvisioningRequest::on('central')->create([
            'action' => 'delete',
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
        ]);

        RunTenantProvisioningRequestJob::dispatch($provisioning->id)->onQueue('provisioning');

        return redirect()->route('landlord.index')
            ->with('status', $tenant->name . ' staat klaar om verwijderd te worden.');
    }
}
