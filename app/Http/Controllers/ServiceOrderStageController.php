<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderStageDeleteRequest;
use App\Http\Requests\ServiceOrderStageReadRequest;
use App\Http\Requests\ServiceOrderStageReorderRequest;
use App\Http\Requests\ServiceOrderStageStoreUpdateRequest;
use App\Models\ServiceOrderStage;
use App\Traits\ReadsPerPage;
use Illuminate\Support\Facades\DB;

class ServiceOrderStageController extends Controller
{
    use ReadsPerPage;

    public function index(ServiceOrderStageReadRequest $request)
    {
        $search = $request->get('search', '');
        $per_page = $this->perPage($request, 25);
        $query = ServiceOrderStage::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return inertia('ServiceOrderStages/IndexPage', [
            'stages' => $query->orderBy('order')->paginate($per_page)->withQueryString(),
            'search' => $search,
            'perPage' => $per_page,
        ]);
    }

    public function store(ServiceOrderStageStoreUpdateRequest $request)
    {
        $data = $request->validated();
        if (! isset($data['order'])) {
            $data['order'] = (ServiceOrderStage::max('order') ?? 0) + 1;
        }

        DB::transaction(function () use ($data) {
            if (! empty($data['is_planned_state'])) {
                ServiceOrderStage::where('is_planned_state', true)
                    ->update(['is_planned_state' => false]);
            }
            if (! empty($data['is_closed_state'])) {
                ServiceOrderStage::where('is_closed_state', true)
                    ->update(['is_closed_state' => false]);
            }
            if (! empty($data['is_invoiced_state'])) {
                ServiceOrderStage::where('is_invoiced_state', true)
                    ->update(['is_invoiced_state' => false]);
            }
            if (! empty($data['is_planning_cancelled_state'])) {
                ServiceOrderStage::where('is_planning_cancelled_state', true)
                    ->update(['is_planning_cancelled_state' => false]);
            }
            ServiceOrderStage::create($data);
        });

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is aangemaakt');
    }

    public function update(
        ServiceOrderStageStoreUpdateRequest $request,
        ServiceOrderStage $serviceorderstage
    ) {
        $data = $request->validated();

        DB::transaction(function () use ($data, $serviceorderstage) {
            if (! empty($data['is_planned_state'])) {
                ServiceOrderStage::where('id', '!=', $serviceorderstage->id)
                    ->where('is_planned_state', true)
                    ->update(['is_planned_state' => false]);
            }
            if (! empty($data['is_closed_state'])) {
                ServiceOrderStage::where('id', '!=', $serviceorderstage->id)
                    ->where('is_closed_state', true)
                    ->update(['is_closed_state' => false]);
            }
            if (! empty($data['is_invoiced_state'])) {
                ServiceOrderStage::where('id', '!=', $serviceorderstage->id)
                    ->where('is_invoiced_state', true)
                    ->update(['is_invoiced_state' => false]);
            }
            if (! empty($data['is_planning_cancelled_state'])) {
                ServiceOrderStage::where('id', '!=', $serviceorderstage->id)
                    ->where('is_planning_cancelled_state', true)
                    ->update(['is_planning_cancelled_state' => false]);
            }
            $serviceorderstage->update($data);
        });

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is bijgewerkt');
    }

    public function destroy(
        ServiceOrderStageDeleteRequest $request,
        ServiceOrderStage $serviceorderstage
    ) {
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
