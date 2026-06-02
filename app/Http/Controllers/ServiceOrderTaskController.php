<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrderTask;
use App\Http\Requests\ServiceOrderTaskReadRequest;
use App\Http\Requests\ServiceOrderTaskStoreUpdateRequest;
use App\Http\Requests\ServiceOrderTaskDeleteRequest;

class ServiceOrderTaskController extends Controller
{
    public function index(ServiceOrderTaskReadRequest $request)
    {
        $search = $request->get('search', '');
        $per_page = (int) ($request->get('perPage') ?: 25);
        $query = ServiceOrderTask::query();

        if ($search) {
            $query->where('title', 'like', "%{$search}%");
        }

        return inertia('ServiceOrderTasks/IndexPage', [
            'tasks'   => $query->orderBy('title')->paginate($per_page)->withQueryString(),
            'search'  => $search,
            'perPage' => $per_page,
        ]);
    }

    public function store(ServiceOrderTaskStoreUpdateRequest $request)
    {
        ServiceOrderTask::create($request->validated());

        return redirect()->route('serviceordertasks.index')
            ->with('success', 'Taak is aangemaakt');
    }

    public function update(ServiceOrderTaskStoreUpdateRequest $request, ServiceOrderTask $serviceordertask)
    {
        $serviceordertask->update($request->validated());

        return redirect()->route('serviceordertasks.index')
            ->with('success', 'Taak is bijgewerkt');
    }

    public function destroy(ServiceOrderTaskDeleteRequest $request, ServiceOrderTask $serviceordertask)
    {
        $serviceordertask->delete();

        return redirect()->back()->with('success', 'Taak is verwijderd');
    }
}
