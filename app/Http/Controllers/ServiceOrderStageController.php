<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrderStage;
use App\Http\Requests\ServiceOrderStageReadRequest;
use App\Http\Requests\ServiceOrderStageStoreUpdateRequest;
use App\Http\Requests\ServiceOrderStageReorderRequest;
use Illuminate\Support\Facades\DB;

class ServiceOrderStageController extends Controller
{
    public function index(ServiceOrderStageReadRequest $request)
    {
        $search = $request->get('search', '');
        $query = ServiceOrderStage::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return inertia('ServiceOrderStages/IndexPage', [
            'stages' => $query->orderBy('order')->paginate(25),
            'search' => $search,
        ]);
    }

    public function store(ServiceOrderStageStoreUpdateRequest $request)
    {
        $data = $request->validated();
        if (!isset($data['order'])) {
            $data['order'] = (ServiceOrderStage::max('order') ?? 0) + 1;
        }
        ServiceOrderStage::create($data);

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is aangemaakt');
    }

    public function update(
        ServiceOrderStageStoreUpdateRequest $request,
        ServiceOrderStage $serviceorderstage
    ) {
        $serviceorderstage->update($request->validated());

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is bijgewerkt');
    }

    public function destroy(ServiceOrderStage $serviceorderstage)
    {
        $serviceorderstage->delete();
        return redirect()->back()->with('success', 'Fase is verwijderd');
    }

    public function updateOrder(ServiceOrderStageReorderRequest $request)
    {
        $payload = $request->validated()['payload'];
        DB::transaction(function () use ($payload) {
            foreach ($payload as $row) {
                ServiceOrderStage::where('id', $row['id'])->update(['order' => $row['order']]);
            }
        });
        return redirect()->back();
    }
}
