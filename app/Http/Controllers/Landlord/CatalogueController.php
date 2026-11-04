<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\ResetInvoiceNumbersRequest;
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

    /** Where this year's invoice numbers stand: handed out, and still on an invoice. */
    private function numbering(): array
    {
        $numbers = new InvoiceNumbers;
        $year = (int) now()->format('Y');

        return [
            'year' => $year,
            'prefix' => $numbers->prefix($year),
            'counter' => $numbers->counter($year),
            'highest' => $numbers->highest($year),
        ];
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
