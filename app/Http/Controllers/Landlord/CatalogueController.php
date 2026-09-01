<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\UpdateIssuerRequest;
use App\Http\Requests\Landlord\UpdateModuleRequest;
use App\Http\Requests\Landlord\UpdatePackageRequest;
use App\Http\Requests\Landlord\UpdatePricingSettingRequest;
use App\Models\Central\IssuerSetting;
use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use Illuminate\Support\Facades\DB;

/**
 * Wat er te koop is en tegen welke prijs, plus onze eigen factuurgegevens.
 */
class CatalogueController extends Controller
{
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
