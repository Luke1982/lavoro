<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaterialUsageUnitDestroyRequest;
use App\Http\Requests\MaterialUsageUnitReadRequest;
use App\Http\Requests\MaterialUsageUnitStoreRequest;
use App\Http\Requests\MaterialUsageUnitUpdateRequest;
use App\Models\MaterialUsageUnit;

class MaterialUsageUnitController extends Controller
{
    public function index(MaterialUsageUnitReadRequest $request)
    {
        return inertia('Materials/UsageUnitIndexPage', [
            'usageUnits' => MaterialUsageUnit::orderBy('name')->get(),
        ]);
    }

    public function store(MaterialUsageUnitStoreRequest $request)
    {
        $unit = MaterialUsageUnit::create($request->validated());

        return redirect()->back()
            ->with('success', 'Gebruikseenheid aangemaakt.')
            ->with('extra', $unit);
    }

    public function update(MaterialUsageUnitUpdateRequest $request, MaterialUsageUnit $materialusageunit)
    {
        $materialusageunit->update($request->validated());

        return redirect()->back()->with('success', 'Gebruikseenheid bijgewerkt.');
    }

    public function destroy(MaterialUsageUnitDestroyRequest $request, MaterialUsageUnit $materialusageunit)
    {
        $materialusageunit->delete();

        return redirect()->back()->with('success', 'Gebruikseenheid verwijderd.');
    }
}
