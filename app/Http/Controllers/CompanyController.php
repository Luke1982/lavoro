<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Company\CompanyLogoReplaced;
use App\Domain\Signals\Signals;
use App\Models\Company;
use App\Http\Requests\CompanyStoreRequest;
use App\Http\Requests\CompanyUpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

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
        if ($request->hasFile('logo_negative')) {
            $data['logo_negative_path'] = $request->file('logo_negative')->store('company-logos', 'public');
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
        unset($data['logo'], $data['logo_negative'], $data['logo_path'], $data['logo_negative_path']);
        if (array_key_exists('is_main', $data)) {
            $data['is_main'] = $request->boolean('is_main');
        }
        $company->update($data);
        if ($request->hasFile('logo')) {
            $this->replaceLogo($company, 'logo_path', $request->file('logo'));
        }
        if ($request->hasFile('logo_negative')) {
            $this->replaceLogo($company, 'logo_negative_path', $request->file('logo_negative'));
        }
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
        $this->replaceLogo($company, 'logo_path', $request->file('logo'));
        return back()->with('success', 'Logo bijgewerkt.');
    }

    /**
     * Negative logo only update.
     */
    public function logoNegative(Request $request, Company $company)
    {
        $request->validate(['logo_negative' => 'required|image|max:2048']);
        $this->replaceLogo($company, 'logo_negative_path', $request->file('logo_negative'));
        return back()->with('success', 'Negatief logo bijgewerkt.');
    }

    /** Its logos are removed by a listener once the deletion is final. */
    public function destroy(Company $company)
    {
        $company->delete();
        return back()->with('success', 'Bedrijf verwijderd.');
    }

    /**
     * Stores the new file and points the company at it. The file it pointed at
     * before is cleaned up by a listener, after the commit.
     */
    private function replaceLogo(Company $company, string $field, UploadedFile $file): void
    {
        $previous_path = $company->{$field};
        $new_path = $file->store('company-logos', 'public');

        $company->update([$field => $new_path]);

        Signals::dispatch(new CompanyLogoReplaced($company, $field, $previous_path, $new_path));
    }
}
