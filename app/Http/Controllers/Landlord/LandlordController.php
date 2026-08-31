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

    public function addTopup(Request $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $data = $request->validate(['paid_euro' => 'required|numeric|min:0.01', 'note' => 'nullable|string']);

        $rate = \App\Models\Central\PricingSetting::value('ai_topup_cents_per_euro_granted', 200);
        $paid_cents = (int) round((float) $data['paid_euro'] * 100);

        \App\Models\Central\AiTopup::on('central')->create([
            'tenant_id' => $tenant->id,
            'paid_cents' => $paid_cents,
            'granted_micros' => (int) round($paid_cents / max(1, $rate) * 1_000_000),
            'note' => $data['note'] ?? null,
        ]);

        /** Het tegoed is meteen bruikbaar; het geld gaat op de eerstvolgende factuur. */
        \App\Models\Central\PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Extra AI-tegoed' . (($data['note'] ?? null) ? ' (' . $data['note'] . ')' : ''),
            'amount_cents' => $paid_cents,
        ]);

        return back()->with('status', 'Bijkoop toegevoegd.');
    }

    public function resellers()
    {
        $resellers = \App\Models\Central\Reseller::on('central')->orderBy('name')->get()->map(function ($reseller) {
            $tenants = Tenant::on('central')->where('reseller_id', $reseller->id)->get();

            return [
                'reseller' => $reseller,
                'coupons' => \App\Models\Central\Coupon::on('central')->where('reseller_id', $reseller->id)->latest()->get(),
                'tenants' => $tenants,
                'commission' => $tenants->sum(fn ($t) => (new TenantSubscription($t))->commissionCents()),
            ];
        });

        return view('landlord.resellers', ['rows' => $resellers]);
    }

    public function storeReseller(Request $request)
    {
        \App\Models\Central\Reseller::on('central')->create($request->validate([
            'name' => 'required|string',
            'email' => 'nullable|email',
            'commission_percent' => 'required|integer|min:0|max:100',
        ]));

        return back()->with('status', 'Reseller toegevoegd.');
    }

    public function storeCoupon(Request $request)
    {
        $data = $request->validate([
            'reseller_id' => 'required|integer',
            'code' => 'nullable|string|max:40',
            'discount_percent' => 'required|integer|min:1|max:100',
            'discount_months' => 'required|integer|min:1|max:60',
            'aantal' => 'required|integer|min:1|max:50',
        ]);

        for ($i = 0; $i < $data['aantal']; $i++) {
            \App\Models\Central\Coupon::on('central')->create([
                'code' => strtoupper($data['code'] ?: \Illuminate\Support\Str::random(4) . '-' . \Illuminate\Support\Str::random(4)),
                'reseller_id' => $data['reseller_id'],
                'discount_percent' => $data['discount_percent'],
                'discount_months' => $data['discount_months'],
            ]);
        }

        return back()->with('status', $data['aantal'] . ' coupon(s) aangemaakt.');
    }

    public function redeemCoupon(Request $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        try {
            $coupon = app(\App\Services\CouponRedeemer::class)
                ->redeem(strtoupper(trim($request->input('code'))), $tenant);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', "Coupon {$coupon->code} verzilverd.");
    }

    public function invoices(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        return view('landlord.invoices', [
            'tenant' => $tenant,
            'invoices' => \App\Models\Central\Invoice::on('central')->with('lines')
                ->where('tenant_id', $tenant->id)->latest('issued_on')->get(),
            'preview' => (new \App\Services\Invoicer($tenant))->preview(),
        ]);
    }

    public function issueInvoice(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoice = (new \App\Services\Invoicer($tenant))->issue();

        return back()->with('status', "Factuur {$invoice->number} aangemaakt: € "
            . number_format($invoice->total_cents / 100, 2, ',', '.'));
    }

    public function invoicePdf(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('landlord.invoice.pdf', [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'issuer' => \App\Models\Central\IssuerSetting::all_values(),
            'logo' => $this->issuerLogo(),
        ])->download($invoice->number . '.pdf');
    }

    public function invoiceXml(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        return response((new \App\Services\InvoiceUbl($invoice, $tenant))->toXml(), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $invoice->number . '.xml"',
        ]);
    }

    /**
     * Als data-URI en niet als pad: dompdf haalt een bestand alleen op als het
     * dat mag, en een factuur die stil zonder logo uitrolt is lastiger te
     * merken dan een die niet rendert.
     */
    private function issuerLogo(): ?string
    {
        $file = public_path('img/majorlabel-logo.jpg');

        if (! is_readable($file)) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($file));
    }

    private function invoiceOf(string $id, int $invoice_id): array
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoice = \App\Models\Central\Invoice::on('central')->with('lines')
            ->where('tenant_id', $tenant->id)->findOrFail($invoice_id);

        return [$tenant, $invoice];
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
            'ai_topup_euro' => \App\Models\Central\AiTopup::on('central')
                ->where('tenant_id', $tenant->id)->sum('granted_micros') / 1_000_000,
            'sub' => new TenantSubscription($tenant),
            'invoicer' => new \App\Services\Invoicer($tenant),
            'topups' => \App\Models\Central\AiTopup::on('central')->where('tenant_id', $tenant->id)->latest()->get(),
            'topup_rate' => \App\Models\Central\PricingSetting::value('ai_topup_cents_per_euro_granted', 200),
            'reseller' => $tenant->reseller_id ? \App\Models\Central\Reseller::on('central')->find($tenant->reseller_id) : null,
            'packages' => Package::on('central')->orderBy('sort_order')->get(),
            'modules' => Module::on('central')->orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $data = $request->validate([
            'package_key' => 'nullable|string',
            'subscription_started_on' => 'nullable|date',
            'billing_period' => 'required|in:monthly,yearly',
            'invoice_email' => 'nullable|email',
            'invoice_address' => 'nullable|string',
            'invoice_postcode' => 'nullable|string|max:16',
            'invoice_city' => 'nullable|string',
            'vat_number' => 'nullable|string|max:32',
            'coc_number' => 'nullable|string|max:32',
            'extra_field_seats' => 'required|integer|min:0',
            'extra_office_seats' => 'required|integer|min:0',
            'storage_limit_gb' => 'required|integer|min:0',
            'ai_allowance_euro' => 'nullable|numeric|min:0',
            'price_override_euro' => 'nullable|numeric|min:0',
            'discount_type' => 'required|in:none,euro,percent',
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

        $type = $data['discount_type'];

        unset($data['ai_allowance_euro'], $data['price_override_euro'],
            $data['discount_euro'], $data['discount_percent'], $data['discount_type']);

        $before = (new TenantSubscription($tenant))->monthlyTotalCents();

        $tenant->update($data + [
            'modules' => $data['modules'] ?? [],
            'ai_allowance_micros' => $ai,
            'price_override_cents' => $money('price_override_euro'),
            'discount_cents' => $type === 'euro' ? $money('discount_euro') : null,
            'discount_percent' => $type === 'percent' ? (int) ($request->input('discount_percent') ?: 0) : null,
        ]);

        $after = (new TenantSubscription($tenant->refresh()))->monthlyTotalCents();

        $charge = (new \App\Services\Invoicer($tenant))->prorate($before, $after);

        return redirect()->route('landlord.edit', $tenant->id)->with('status', $tenant->name . ' is bijgewerkt.'
            . ($charge ? ' Verrekening van € ' . number_format($charge->amount_cents / 100, 2, ',', '.') . ' staat klaar voor de volgende factuur.' : ''));
    }
}
