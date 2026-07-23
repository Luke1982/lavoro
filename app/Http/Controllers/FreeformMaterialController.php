<?php

namespace App\Http\Controllers;

use App\Http\Requests\FreeformMaterialDestroyRequest;
use App\Http\Requests\FreeformMaterialStoreRequest;
use App\Http\Requests\FreeformMaterialUpdateRequest;
use App\Models\FreeformMaterial;
use App\Models\ServiceOrder;
use App\Services\MateriableService;

class FreeformMaterialController extends Controller
{
    public function store(
        FreeformMaterialStoreRequest $request,
        ServiceOrder $serviceorder,
        MateriableService $materiables
    ) {
        $materiables->createFreeform($serviceorder, $request->validated());

        return redirect()->back()->with('success', 'Vrije materiaalregel toegevoegd.');
    }

    public function update(
        FreeformMaterialUpdateRequest $request,
        ServiceOrder $serviceorder,
        FreeformMaterial $freeform_material,
        MateriableService $materiables
    ) {
        $materiables->updateFreeform($serviceorder, $freeform_material, $request->validated());

        return redirect()->back()->with('success', 'Vrije materiaalregel bijgewerkt.');
    }

    public function destroy(
        FreeformMaterialDestroyRequest $request,
        ServiceOrder $serviceorder,
        FreeformMaterial $freeform_material,
        MateriableService $materiables
    ) {
        $materiables->deleteFreeform($serviceorder, $freeform_material);

        return redirect()->back()->with('success', 'Vrije materiaalregel verwijderd.');
    }
}
