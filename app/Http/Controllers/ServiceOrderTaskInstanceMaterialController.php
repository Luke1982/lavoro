<?php

namespace App\Http\Controllers;

use App\Http\Requests\FreeformMaterialDestroyRequest;
use App\Http\Requests\FreeformMaterialStoreRequest;
use App\Http\Requests\FreeformMaterialUpdateRequest;
use App\Http\Requests\ServiceOrderAttachMaterialRequest;
use App\Http\Requests\ServiceOrderDetachMaterialRequest;
use App\Http\Requests\ServiceOrderUpdateMateriableRequest;
use App\Models\FreeformMaterial;
use App\Models\Material;
use App\Models\ServiceOrderTaskInstance;
use App\Services\MateriableService;

/**
 * The same material bookkeeping as on a service order, aimed at one of its tasks. Requests
 * and permissions are shared with the order's routes: what changes is only what the line
 * hangs off, and therefore what the invoice can say it was used for.
 */
class ServiceOrderTaskInstanceMaterialController extends Controller
{
    public function store(
        ServiceOrderAttachMaterialRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        Material $material,
        MateriableService $materiables
    ) {
        $materiables->attach($serviceordertaskinstance, $material, $request->validated());

        return redirect()->back()->with('success', 'Materiaal succesvol gekoppeld aan de taak.');
    }

    public function update(
        ServiceOrderUpdateMateriableRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        string $materiable_id,
        MateriableService $materiables
    ) {
        $materiables->update($serviceordertaskinstance, $materiable_id, $request->validated());

        return redirect()->back()->with('success', 'Materiaal succesvol bijgewerkt.');
    }

    public function destroy(
        ServiceOrderDetachMaterialRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        string $materiable_id,
        MateriableService $materiables
    ) {
        $materiables->detach($serviceordertaskinstance, $materiable_id);

        return redirect()->back()->with('success', 'Materiaal succesvol losgekoppeld van de taak.');
    }

    public function storeFreeform(
        FreeformMaterialStoreRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        MateriableService $materiables
    ) {
        $materiables->createFreeform($serviceordertaskinstance, $request->validated());

        return redirect()->back()->with('success', 'Vrije materiaalregel toegevoegd.');
    }

    public function updateFreeform(
        FreeformMaterialUpdateRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        FreeformMaterial $freeform_material,
        MateriableService $materiables
    ) {
        $materiables->updateFreeform($serviceordertaskinstance, $freeform_material, $request->validated());

        return redirect()->back()->with('success', 'Vrije materiaalregel bijgewerkt.');
    }

    public function destroyFreeform(
        FreeformMaterialDestroyRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        FreeformMaterial $freeform_material,
        MateriableService $materiables
    ) {
        $materiables->deleteFreeform($serviceordertaskinstance, $freeform_material);

        return redirect()->back()->with('success', 'Vrije materiaalregel verwijderd.');
    }
}
