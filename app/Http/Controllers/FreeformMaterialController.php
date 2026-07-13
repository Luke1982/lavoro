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
        $validated = $request->validated();
        $quantity_changed = array_key_exists('quantity', $validated)
            && (float) $validated['quantity'] !== (float) $freeform_material->quantity;

        if ($quantity_changed) {
            $serviceorder->logActivity(sprintf(
                'Vrije materiaalregel hoeveelheid aangepast: %s naar %s',
                $freeform_material->description,
                $validated['quantity']
            ));
        }

        if (array_key_exists('unforseen', $validated) && $validated['unforseen'] !== $freeform_material->unforseen) {
            $serviceorder->logActivity(sprintf(
                'Vrije materiaalregel gemarkeerd als %s: %s',
                $validated['unforseen'] ? 'onvoorzien' : 'voorzien',
                $freeform_material->description
            ));
        }

        $freeform_material->update($validated);

        return redirect()->back()->with('success', 'Vrije materiaalregel bijgewerkt.');
    }

    public function destroy(FreeformMaterialDestroyRequest $request, ServiceOrder $serviceorder, FreeformMaterial $freeform_material)
    {
        $serviceorder->logActivity(sprintf(
            'Vrije materiaalregel verwijderd: %s (aantal %s)',
            $freeform_material->description,
            $freeform_material->quantity
        ));
        $freeform_material->delete();

        return redirect()->back()->with('success', 'Vrije materiaalregel verwijderd.');
    }
}
