<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\DestroyProvisioningRequestRequest;
use App\Http\Requests\Landlord\DestroyTenantRequest;
use App\Http\Requests\Landlord\LandlordStatusRequest;
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
use App\Support\Money;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * De klanten zelf: overzicht, abonnement, aanmaken en verwijderen.
 */
class TenantController extends Controller
{
    public function index()
    {
        /**
         * Namen die op dit moment worden aangemaakt. Zolang de worker bezig is
         * staat de rij er wel maar zijn de tabellen er nog niet, en dan levert
         * elke telling hieronder een foutmelding op over een tabel die zo
         * meteen bestaat. Dat is geen probleem maar een moment.
         */
        $being_created = TenantProvisioningRequest::on('central')
            ->where('action', 'create')
            ->whereIn('status', ['queued', 'running'])
            ->pluck('name');

        $rows = Tenant::on('central')->orderBy('name')->get()->map(function (Tenant $tenant) use ($being_created) {
            $package = Package::on('central')->where('key', $tenant->package_key)->first();

            /**
             * Via de helper: klapt het bij één klant, dan blijft die tenant
             * anders openstaan en telt de volgende ronde in de database van
             * de vorige.
             */
            /**
             * Eén klant waar iets mis mee is mag de hele lijst niet meenemen.
             * Loopt het aanmaken halverwege stuk, dan staat de rij er wel maar
             * de database niet, en zonder dit stond daarna het hele paneel op
             * een foutmelding -- juist het scherm waar je die klant weer moet
             * kunnen opruimen.
             */
            try {
                [$field, $office, $used] = Tenancy::within($tenant, fn () => [
                    User::occupyingSeat('field')->count(),
                    User::occupyingSeat('office')->count(),
                    (new StorageQuota)->usedBytes(),
                ]);
                $broken = null;
            } catch (\Throwable $e) {
                [$field, $office, $used] = [0, 0, 0];
                $broken = $being_created->contains($tenant->name)
                    ? null
                    : $e->getMessage();
            }

            return [
                'tenant' => $tenant,
                'broken' => $broken,
                'busy' => $being_created->contains($tenant->name),
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
            /**
             * In centen naar het scherm, niet in euro's of miljoensten: het
             * scherm rekende zelf om en deed dat op drie plekken net anders.
             */
            'ai_spent_cents' => Money::fromMicros($spent),
            'ai_allowance_cents' => Money::fromMicros($allowance),
            'ai_is_default' => $tenant->ai_allowance_micros === null,
            'ai_topup_cents' => Money::fromMicros((int) AiTopup::on('central')
                ->where('tenant_id', $tenant->id)->sum('granted_micros')),
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
                ? ' Verrekening van € ' . Money::human($charge->amount_cents)
                    . ' staat klaar voor de volgende factuur.'
                : ''),
        );
    }

    /**
     * Een mislukte aanvraag blijft in het paneel staan tot iemand hem weghaalt.
     * Dat is de bedoeling -- anders verdwijnt de reden waarom het misging -- maar
     * dan moet hij er ook weg kunnen als het opgelost is.
     */
    /**
     * Een korte samenvatting van wat er loopt, zodat het scherm zichzelf kan
     * verversen zolang de provisioner bezig is.
     *
     * Geen inhoud, alleen een vingerafdruk: verandert die, dan is er iets
     * gebeurd en haalt de pagina zichzelf opnieuw op. Zo hoeft dit niet te weten
     * hoe het scherm eruitziet, en blijft er één plek waar dat staat.
     */
    public function provisioningStatus(LandlordStatusRequest $request)
    {
        $requests = TenantProvisioningRequest::on('central')
            ->orderBy('id')
            ->get(['id', 'status'])
            ->map(fn ($row) => $row->id . ':' . $row->status)
            ->implode(',');

        return response()->json([
            'signature' => md5($requests . '|' . Tenant::on('central')->count()),
            'busy' => TenantProvisioningRequest::on('central')
                ->whereIn('status', ['queued', 'running'])->exists(),
        ]);
    }

    public function destroyProvisioningRequest(DestroyProvisioningRequestRequest $request, int $id)
    {
        TenantProvisioningRequest::on('central')
            ->where('id', $id)
            ->where('status', 'failed')
            ->delete();

        return back()->with('status', 'Mislukte aanvraag weggehaald.');
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
