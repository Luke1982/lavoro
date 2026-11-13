<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderTaskChecklistDeleteRequest;
use App\Http\Requests\ServiceOrderTaskChecklistStoreUpdateRequest;
use App\Models\ServiceOrderTaskChecklist;

class ServiceOrderTaskChecklistController extends Controller
{
    public function store(ServiceOrderTaskChecklistStoreUpdateRequest $request)
    {
        ServiceOrderTaskChecklist::create($request->validated());

        return redirect()->back()->with('success', 'Checklist is aangemaakt');
    }

    public function update(
        ServiceOrderTaskChecklistStoreUpdateRequest $request,
        ServiceOrderTaskChecklist $serviceordertaskchecklist,
    ) {
        $serviceordertaskchecklist->update($request->validated());

        return redirect()->back()->with('success', 'Checklist is bijgewerkt');
    }

    /**
     * Its links go with it; werkbon tasks keep their own copy of its checks.
     */
    public function destroy(
        ServiceOrderTaskChecklistDeleteRequest $request,
        ServiceOrderTaskChecklist $serviceordertaskchecklist,
    ) {
        $serviceordertaskchecklist->delete();

        return redirect()->back()->with('success', 'Checklist is verwijderd');
    }
}
