<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\ResetInvoiceNumbersRequest;
use App\Http\Requests\Landlord\RestartInvoiceYearRequest;
use App\Http\Requests\Landlord\UpdateIssuerRequest;
use App\Http\Requests\Landlord\UpdateModuleRequest;
use App\Http\Requests\Landlord\UpdatePackageRequest;
use App\Http\Requests\Landlord\UpdatePricingSettingRequest;
use App\Models\Central\IssuerSetting;
use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Services\InvoiceNumbers;
use App\Services\YearRestart;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wat er te koop is en tegen welke prijs, plus onze eigen factuurgegevens.
 */
class CatalogueController extends Controller
{
    public function catalogue()
    {
        return inertia('Landlord/CataloguePage', [
            'packages' => Package::on('central')->orderBy('sort_order')->get(),
            'modules' => Module::on('central')->orderBy('sort_order')->get(),
            'bundles' => ModuleBundle::on('central')->get(),
            'settings' => PricingSetting::on('central')->orderBy('key')->get(),
            /**
             * As an object and not as a list: empty would otherwise make
             * json_encode produce [], and the screen looks a package key up in
             * it.
             */
            'usage' => (object) DB::connection('central')->table('tenants')
                ->selectRaw('package_key, COUNT(*) AS aantal')->groupBy('package_key')
                ->pluck('aantal', 'package_key')->all(),
            'issuer_rows' => IssuerSetting::on('central')->orderBy('key')->get(),
            'numbering' => $this->numbering(),
        ]);
    }

    /**
     * Where this year's invoice numbers stand: handed out, still on an
     * invoice, and how many of those already reached someone -- what starting
     * the year over would leave to be put right by hand.
     */
    private function numbering(): array
    {
        $numbers = new InvoiceNumbers;
        $year = (int) now()->format('Y');

        $counts = $numbers->ofYear($year)->toBase()->selectRaw(
            'count(*) as issued, count(mailed_at) as mailed, count(collected_at) as collected,'
            . ' count(coalesce(booked_at, booking_error)) as booked'
        )->first();

        return [
            'year' => $year,
            'prefix' => $numbers->prefix($year),
            'counter' => $numbers->counter($year),
            'highest' => $numbers->highest($year),
            ...array_map('intval', (array) $counts),
        ];
    }

    /**
     * Every invoice of this year deleted and the numbering back to 1. Logged
     * with every number that went: some of them may have reached a customer,
     * the bank or e-Boekhouden, and whoever finds one there later should be
     * able to read why it is gone here.
     */
    public function restartYear(RestartInvoiceYearRequest $request)
    {
        $year = (int) now()->format('Y');
        $deleted = (new YearRestart)->restart($year);

        Log::warning('Factuurjaar opnieuw begonnen', [
            'year' => $year,
            'deleted' => $deleted,
            'by' => Auth::guard('landlord')->user()?->email,
        ]);

        return back()->with('status', $year . ' begint opnieuw: ' . count($deleted)
            . ' facturen verwijderd. De volgende factuur wordt ' . (new InvoiceNumbers)->upcoming($year) . '.');
    }

    /**
     * Hands the numbers of withdrawn invoices out again. Written to the log,
     * because a bookkeeper who later finds a number used twice by date should
     * be able to read when and by whom the series was set back.
     */
    public function resetNumbering(ResetInvoiceNumbersRequest $request)
    {
        $numbers = new InvoiceNumbers;
        $year = (int) now()->format('Y');
        $reset = $numbers->reset($year);

        Log::notice('Factuurnummering teruggezet', [
            'year' => $year,
            'from' => $reset['from'],
            'to' => $reset['to'],
            'by' => Auth::guard('landlord')->user()?->email,
        ]);

        return back()->with('status', 'De nummering van ' . $year . ' is teruggezet: de volgende factuur wordt '
            . $numbers->prefix($year) . ($reset['to'] + 1) . '.');
    }

    public function updateIssuer(UpdateIssuerRequest $request)
    {
        $data = $request->validated();

        foreach ($data['issuer'] as $key => $value) {
            IssuerSetting::on('central')->where('key', $key)->update(['value' => (string) $value]);
        }

        return back()->with('status', 'Facturatiegegevens opgeslagen.');
    }

    public function updatePackage(UpdatePackageRequest $request, int $id)
    {
        $data = $request->validated();

        Package::on('central')->findOrFail($id)->update($data);

        return back()->with('status', 'Pakket bijgewerkt.');
    }

    public function updateModule(UpdateModuleRequest $request, int $id)
    {
        $data = $request->validated();

        Module::on('central')->findOrFail($id)->update($data);

        return back()->with('status', 'Module bijgewerkt.');
    }

    public function updateSetting(UpdatePricingSettingRequest $request, int $id)
    {
        $data = $request->validated();

        PricingSetting::on('central')->findOrFail($id)->update($data);

        return back()->with('status', 'Instelling bijgewerkt.');
    }
}
