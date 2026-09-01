<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\DestroyTenantRequest;
use App\Http\Requests\Landlord\ExportCollectionRequest;
use App\Http\Requests\Landlord\ForgetProvisioningPasswordRequest;
use App\Http\Requests\Landlord\StoreTenantRequest;
use App\Jobs\RunTenantProvisioningRequestJob;
use App\Models\Central\AiTopup;
use App\Models\Central\Coupon;
use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PendingCharge;
use App\Models\Central\PricingSetting;
use App\Models\Central\Reseller;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\Iban;
use App\Services\CouponRedeemer;
use App\Services\InvoiceMailer;
use App\Services\Invoicer;
use App\Services\InvoiceUbl;
use App\Services\SepaDirectDebit;
use App\Services\StorageQuota;
use App\Services\TenantSubscription;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LandlordController extends Controller
{
    public function showLogin()
    {
        return view('landlord.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required']);

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

    public function catalogue()
    {
        return view('landlord.catalogue', [
            'packages' => Package::on('central')->orderBy('sort_order')->get(),
            'modules' => Module::on('central')->orderBy('sort_order')->get(),
            'bundles' => ModuleBundle::on('central')->get(),
            'settings' => PricingSetting::on('central')->orderBy('key')->get(),
            'usage' => DB::connection('central')->table('tenants')
                ->selectRaw('package_key, COUNT(*) AS aantal')->groupBy('package_key')->pluck('aantal', 'package_key'),
            'issuer_rows' => IssuerSetting::on('central')->orderBy('key')->get(),
        ]);
    }

    public function updateIssuer(Request $request)
    {
        $data = $request->validate([
            'issuer' => 'required|array',
            'issuer.email' => 'nullable|email',
            'issuer.iban' => ['nullable', new Iban],
            'issuer.payment_days' => 'nullable|integer|min:0|max:120',
            'issuer.*' => 'nullable|string|max:255',
        ]);

        foreach ($data['issuer'] as $key => $value) {
            IssuerSetting::on('central')->where('key', $key)->update(['value' => (string) $value]);
        }

        return back()->with('status', 'Facturatiegegevens opgeslagen.');
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

        PricingSetting::on('central')->findOrFail($id)->update($data);

        return back()->with('status', 'Instelling bijgewerkt.');
    }

    public function addTopup(Request $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $data = $request->validate(['paid_euro' => 'required|numeric|min:0.01', 'note' => 'nullable|string']);

        $rate = PricingSetting::value('ai_topup_cents_per_euro_granted', 200);
        $paid_cents = (int) round((float) $data['paid_euro'] * 100);

        AiTopup::on('central')->create([
            'tenant_id' => $tenant->id,
            'paid_cents' => $paid_cents,
            'granted_micros' => (int) round($paid_cents / max(1, $rate) * 1_000_000),
            'note' => $data['note'] ?? null,
        ]);

        /** Het tegoed is meteen bruikbaar; het geld gaat op de eerstvolgende factuur. */
        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Extra AI-tegoed' . (($data['note'] ?? null) ? ' (' . $data['note'] . ')' : ''),
            'kind' => 'topup',
            'amount_cents' => $paid_cents,
        ]);

        return back()->with('status', 'Bijkoop toegevoegd.');
    }

    public function resellers()
    {
        $resellers = Reseller::on('central')->orderBy('name')->get()->map(function ($reseller) {
            $tenants = Tenant::on('central')->where('reseller_id', $reseller->id)->get();

            return [
                'reseller' => $reseller,
                'coupons' => Coupon::on('central')->where('reseller_id', $reseller->id)->latest()->get(),
                'tenants' => $tenants,
                'commission' => $tenants->sum(fn ($t) => (new TenantSubscription($t))->commissionCents()),
            ];
        });

        return view('landlord.resellers', ['rows' => $resellers]);
    }

    public function storeReseller(Request $request)
    {
        Reseller::on('central')->create($request->validate([
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
            Coupon::on('central')->create([
                'code' => strtoupper($data['code'] ?: Str::random(4) . '-' . Str::random(4)),
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
            $coupon = app(CouponRedeemer::class)
                ->redeem(strtoupper(trim($request->input('code'))), $tenant);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', "Coupon {$coupon->code} verzilverd.");
    }

    public function invoices(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoicer = new Invoicer($tenant);
        [$start, $end] = $invoicer->periodFor(CarbonImmutable::now());

        return view('landlord.invoices', [
            'tenant' => $tenant,
            'invoices' => Invoice::on('central')->with('lines')
                ->where('tenant_id', $tenant->id)->latest('issued_on')->get(),
            'preview' => $invoicer->preview(),
            'is_due' => $invoicer->isDue(),
            'next_period_starts_on' => $end->addDay(),
        ]);
    }

    public function issueInvoice(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoice = (new Invoicer($tenant))->issue();

        return back()->with('status', "Factuur {$invoice->number} aangemaakt: € "
            . number_format($invoice->total_cents / 100, 2, ',', '.'));
    }

    /** Handmatig: er hoort eerst iemand naar de factuur gekeken te hebben. */
    public function mailInvoice(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        if (!(new InvoiceMailer)->send($invoice, $tenant)) {
            return back()->with('error', 'Versturen mislukt: ' . $invoice->fresh()->mail_error);
        }

        return back()->with('status', "Factuur {$invoice->number} verstuurd naar {$tenant->invoice_email}.");
    }

    /** Alles wat geïncasseerd mag worden en nog niet in een bestand zat. */
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

    /** Het wachtwoord van een nieuwe tenant: één keer tonen, dan weg. */
    public function forgetProvisioningPassword(ForgetProvisioningPasswordRequest $request, int $id)
    {
        TenantProvisioningRequest::on('central')
            ->where('id', $id)->update(['generated_password' => null]);

        return back()->with('status', 'Wachtwoord gewist.');
    }

    public function collections()
    {
        return view('landlord.collections', [
            'invoices' => $this->collectable()->get(),
            'issuer' => IssuerSetting::all_values(),
            'collect_on' => now()->addWeekdays(6)->toDateString(),
        ]);
    }

    public function exportCollection(ExportCollectionRequest $request)
    {
        $data = $request->validated();

        $invoices = $this->collectable()
            ->whereIn('id', $data['invoices'])
            ->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', 'Niets te incasseren.');
        }

        $batch = 'LVR-' . now()->format('YmdHis');

        $xml = (new SepaDirectDebit(
            $invoices,
            CarbonImmutable::parse($data['collect_on']),
            $batch,
        ))->toXml();

        /**
         * Pas afstempelen als het bestand er is. Een klant die al op
         * "geïncasseerd" staat terwijl de bank niets gekregen heeft, wordt
         * nooit meer meegenomen.
         */
        Invoice::on('central')
            ->whereIn('id', $invoices->pluck('id'))
            ->update(['collected_at' => now(), 'collection_batch' => $batch]);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $batch . '.xml"',
        ]);
    }

    /**
     * Een factuur mag mee als de klant een machtiging heeft afgegeven en hij
     * nog niet eerder in een bestand zat.
     */
    private function collectable()
    {
        return Invoice::on('central')
            ->with('tenant')
            ->whereNull('collected_at')
            ->whereHas('tenant', fn ($query) => $query
                ->where('payment_method', 'direct_debit')
                ->whereNotNull('iban')
                ->whereNotNull('mandate_reference')
                ->whereNotNull('mandate_signed_on'))
            ->orderBy('number');
    }

    public function invoicePdf(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        return Pdf::loadView('landlord.invoice.pdf', [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'issuer' => IssuerSetting::all_values(),
            'logo' => $this->issuerLogo(),
            'script_font' => 'file://' . resource_path('fonts/DancingScript.ttf'),
        ])->download($invoice->number . '.pdf');
    }

    public function invoiceXml(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        return response((new InvoiceUbl($invoice, $tenant))->toXml(), 200, [
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

        if (!is_readable($file)) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($file));
    }

    private function invoiceOf(string $id, int $invoice_id): array
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoice = Invoice::on('central')->with('lines')
            ->where('tenant_id', $tenant->id)->findOrFail($invoice_id);

        return [$tenant, $invoice];
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
            'payment_method' => 'required|in:transfer,direct_debit',
            'iban' => ['nullable', 'string', 'max:34', new Iban, 'required_if:payment_method,direct_debit'],
            'account_holder' => 'nullable|string|max:70',
            'mandate_reference' => ['nullable', 'string', 'max:35', 'required_if:payment_method,direct_debit'],
            'mandate_signed_on' => ['nullable', 'date', 'required_if:payment_method,direct_debit'],
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

        $money = fn (?string $key) => ($data[$key] ?? '') === '' || !isset($data[$key])
            ? null
            : (int) round((float) $data[$key] * 100);

        $ai = ($data['ai_allowance_euro'] ?? '') === '' || !isset($data['ai_allowance_euro'])
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

        $charge = (new Invoicer($tenant))->prorate($before, $after);

        return redirect()->route('landlord.edit', $tenant->id)->with('status', $tenant->name . ' is bijgewerkt.'
            . ($charge ? ' Verrekening van € ' . number_format($charge->amount_cents / 100, 2, ',', '.') . ' staat klaar voor de volgende factuur.' : ''));
    }
}
