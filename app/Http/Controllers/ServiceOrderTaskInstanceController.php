<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrderTaskInstance;
use App\Http\Requests\ServiceOrderTaskInstanceStoreRequest;
use App\Http\Requests\ServiceOrderTaskInstanceUpdateRequest;
use App\Http\Requests\ServiceOrderTaskInstanceToggleRequest;
use App\Http\Requests\ServiceOrderTaskInstanceDeleteRequest;

class ServiceOrderTaskInstanceController extends Controller
{
    public function store(ServiceOrderTaskInstanceStoreRequest $request)
    {
        ServiceOrderTaskInstance::create($request->validated());

        return redirect()->back()->with('success', 'Taak is toegevoegd');
    }

    public function update(ServiceOrderTaskInstanceUpdateRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $serviceordertaskinstance->update($request->validated());

        return redirect()->back()->with('success', 'Taak is bijgewerkt');
    }

    public function toggle(ServiceOrderTaskInstanceToggleRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $serviceordertaskinstance->update($request->validated());

        $title  = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';
        $action = $request->validated()['is_complete'] ? 'voltooid' : 'heropend';

        $serviceordertaskinstance->serviceOrder->logActivity(
            "Taak \"{$title}\" {$action}",
            category: 'status',
        );

        return redirect()->back()->with('success', 'Taakstatus bijgewerkt');
    }

    public function destroy(ServiceOrderTaskInstanceDeleteRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $serviceordertaskinstance->delete();

        return redirect()->back()->with('success', 'Taak is verwijderd');
    }
}
