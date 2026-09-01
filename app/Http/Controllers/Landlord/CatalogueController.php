<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Central\IssuerSetting;
use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Rules\Iban;
use Illuminate\Http\Request;
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
}
