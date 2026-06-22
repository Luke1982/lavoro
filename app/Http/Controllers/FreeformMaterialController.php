<?php

namespace App\Http\Controllers;

use App\Http\Requests\FreeformMaterialDestroyRequest;
use App\Http\Requests\FreeformMaterialStoreRequest;
use App\Http\Requests\FreeformMaterialUpdateRequest;
use App\Models\FreeformMaterial;
use App\Models\ServiceOrder;

class FreeformMaterialController extends Controller
{
    public function store(FreeformMaterialStoreRequest $request, ServiceOrder $serviceorder)
    {
        $validated = $request->validated();
        $serviceorder->freeformMaterials()->create($validated);
        $serviceorder->logActivity(sprintf(
            'Vrije materiaalregel toegevoegd: %s (aantal %s)',
            $validated['description'],
            $validated['quantity']
        ));

        return redirect()->back()->with('success', 'Vrije materiaalregel toegevoegd.');
    }

    public function update(FreeformMaterialUpdateRequest $request, ServiceOrder $serviceorder, FreeformMaterial $freeform_material)
    {
        $freeform_material->update($request->validated());

        return redirect()->back()->with('success', 'Vrije materiaalregel bijgewerkt.');
    }

    public function destroy(FreeformMaterialDestroyRequest $request, ServiceOrder $serviceorder, FreeformMaterial $freeform_material)
    {
        $freeform_material->delete();

        return redirect()->back()->with('success', 'Vrije materiaalregel verwijderd.');
    }
}
