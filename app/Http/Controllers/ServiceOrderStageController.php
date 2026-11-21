<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
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

    private const UNIQUE_FLAGS = [
        'is_planned_state',
        'is_closed_state',
        'is_invoiced_state',
        'is_incomplete_state',
        'is_planning_cancelled_state',
    ];

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
        if (!isset($data['order'])) {
            $data['order'] = (ServiceOrderStage::max('order') ?? 0) + 1;
        }

        DB::transaction(function () use ($data) {
            $this->releaseUniqueFlags($data);
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
            $this->releaseUniqueFlags($data, $serviceorderstage);
            $serviceorderstage->update($data);
        });

        return redirect()->route('serviceorderstages.index')
            ->with('success', 'Fase is bijgewerkt');
    }

    /**
     * A stage that werkbonnen are in cannot go: the database would quietly drop them
     * out of every stage, and with it whether they count as closed or invoiced.
     */
    public function destroy(
        ServiceOrderStageDeleteRequest $request,
        ServiceOrderStage $serviceorderstage
    ) {
        $in_use = $serviceorderstage->serviceOrders()->count();

        throw_if(
            $in_use > 0,
            Refusal::class,
            'Fase "' . $serviceorderstage->name . '" kan niet verwijderd worden: ' . $in_use
                . ($in_use === 1 ? ' werkbon staat' : ' werkbonnen staan') . ' nog in deze fase. '
                . 'Zet ze eerst in een andere fase.'
        );

        $serviceorderstage->delete();

        return redirect()->back()->with('success', 'Fase is verwijderd');
    }

    public function updateOrder(ServiceOrderStageReorderRequest $request)
    {
        $payload = $request->validated()['payload'];
        DB::transaction(function () use ($payload) {
            $stages = ServiceOrderStage::whereIn('id', array_column($payload, 'id'))->get()->keyBy('id');

            foreach ($payload as $row) {
                $stages->get($row['id'])?->update(['order' => $row['order']]);
            }
        });

        return redirect()->back();
    }

    /**
     * Each of these flags marks exactly one stage, so switching one on here switches it
     * off wherever it was. Done stage by stage so the one that lost it says so in its
     * own history.
     *
     * @param  array<string, mixed>  $data
     */
    private function releaseUniqueFlags(array $data, ?ServiceOrderStage $keep = null): void
    {
        foreach (self::UNIQUE_FLAGS as $flag) {
            if (empty($data[$flag])) {
                continue;
            }

            ServiceOrderStage::where($flag, true)
                ->when($keep, fn ($query) => $query->whereKeyNot($keep->getKey()))
                ->get()
                ->each(fn (ServiceOrderStage $stage) => $stage->update([$flag => false]));
        }
    }
}
