<?php

namespace App\Http\Controllers;

use App\Enums\ContractInterval;
use App\Http\Requests\MaintenanceContractTemplateDestroyRequest;
use App\Http\Requests\MaintenanceContractTemplateReadRequest;
use App\Http\Requests\MaintenanceContractTemplateStoreRequest;
use App\Http\Requests\MaintenanceContractTemplateUpdateRequest;
use App\Models\MaintenanceContractTemplate;
use App\Traits\OffersContractTemplates;

class MaintenanceContractTemplateController extends Controller
{
    use OffersContractTemplates;

    public function index(MaintenanceContractTemplateReadRequest $request)
    {
        return inertia('MaintenanceContractTemplates/IndexPage', [
            'templates' => $this->templatesFor($request),
            'contractIntervalOptions' => ContractInterval::comboBoxArray(),
        ]);
    }

    public function show(
        MaintenanceContractTemplateReadRequest $request,
        MaintenanceContractTemplate $maintenancecontracttemplate
    ) {
        if ($request->user()->can('seeFinancials', MaintenanceContractTemplate::class)) {
            $maintenancecontracttemplate->makeVisible(MaintenanceContractTemplate::FINANCIAL_ATTRIBUTES);
        }

        return inertia('MaintenanceContractTemplates/ShowPage', [
            'template' => $maintenancecontracttemplate,
            'contractIntervalOptions' => ContractInterval::comboBoxArray(),
        ]);
    }

    public function store(MaintenanceContractTemplateStoreRequest $request)
    {
        MaintenanceContractTemplate::create($request->validated());

        return redirect()->back()->with('success', 'Contractsjabloon aangemaakt.');
    }

    public function update(
        MaintenanceContractTemplateUpdateRequest $request,
        MaintenanceContractTemplate $maintenancecontracttemplate
    ) {
        $maintenancecontracttemplate->update($request->validated());

        return redirect()->back()->with('success', 'Contractsjabloon bijgewerkt.');
    }

    public function destroy(
        MaintenanceContractTemplateDestroyRequest $request,
        MaintenanceContractTemplate $maintenancecontracttemplate
    ) {
        $maintenancecontracttemplate->delete();

        return redirect()->route('maintenancecontracttemplates.index')
            ->with('success', 'Contractsjabloon verwijderd.');
    }
}
