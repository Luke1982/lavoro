<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\CompanyStoreRequest;
use App\Http\Requests\CompanyUpdateRequest;
use Illuminate\Http\Request;

/**
 * @mixin \Illuminate\Http\Request
 */
class CompanyController extends Controller
{
    public function index()
    {
        return inertia('Companies/IndexPage', [
            'companies' => Company::orderByDesc('is_main')->orderBy('name')->get(),
        ]);
    }

    /**
     * @param CompanyStoreRequest $request
     */
    public function store(CompanyStoreRequest $request)
    {
        $data = $request->validated();
        $data['is_main'] = $request->boolean('is_main');
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        }
        Company::create($data);
        return back()->with('success', 'Bedrijf aangemaakt.');
    }

    /**
     * @param UpdateCompanyRequest $request
     * @param Company $company
     */
    public function update(CompanyUpdateRequest $request, Company $company)
    {
        $data = $request->validated();
        if ($request->hasFile('logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        }
        if (array_key_exists('is_main', $data)) {
            $data['is_main'] = $request->boolean('is_main');
        }
        $company->update($data);
        return back()->with('success', 'Bedrijf bijgewerkt.');
    }

    /**
     * Inline update for single or multiple simple fields.
     * @param UpdateCompanyRequest $request
     * @param Company $company
     */
    public function inline(CompanyUpdateRequest $request, Company $company)
    {
        $data = $request->validated();
        if (isset($data['is_main'])) {
            $data['is_main'] = $request->boolean('is_main');
        }
        $company->update($data);
        return back()->with('success', 'Bedrijf bijgewerkt.');
    }

    /**
     * Logo only update.
     */
    public function logo(Request $request, Company $company)
    {
        $request->validate(['logo' => 'required|image|max:2048']);
        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }
        $logoPath = $request->file('logo')->store('company-logos', 'public');
        $company->update(['logo_path' => $logoPath]);
        return back()->with('success', 'Logo bijgewerkt.');
    }

    public function destroy(Company $company)
    {
        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }
        $company->delete();
        return back()->with('success', 'Bedrijf verwijderd.');
    }
}
