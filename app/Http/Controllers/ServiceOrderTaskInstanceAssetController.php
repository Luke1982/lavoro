<?php

namespace App\Http\Controllers;

use App\Actions\Tasks\RegisterTaskAssetsAction;
use App\Domain\Signals\Signals;
use App\Domain\Signals\Tasks\TaskAssetSerialChanged;
use App\Http\Requests\ServiceOrderTaskInstanceAssetStoreRequest;
use App\Http\Requests\ServiceOrderTaskInstanceAssetUpdateRequest;
use App\Models\Asset;
use App\Models\ServiceOrderTaskInstance;

/**
 * The machines a werkbon task delivers, registered one at a time so a technician can walk
 * away half-way and pick the task back up later.
 *
 * A task for a plain product delivers those machines to the customer directly. A task for
 * a bundle delivers the bundle itself — one machine per sold bundle, carrying no serial —
 * with the parts hanging underneath it, so the customer ends up with the bundle rather
 * than a heap of loose parts.
 */
class ServiceOrderTaskInstanceAssetController extends Controller
{
    public function store(
        ServiceOrderTaskInstanceAssetStoreRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        RegisterTaskAssetsAction $register,
    ) {
        $created = $register->execute($serviceordertaskinstance, $request->validated()['assets']);

        return redirect()->back()->with('success', $created->count() === 1
            ? 'Machine opgeslagen'
            : 'Machines opgeslagen');
    }

    public function update(
        ServiceOrderTaskInstanceAssetUpdateRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        Asset $asset,
    ) {
        $previous = $asset->serial_number;
        $asset->update(['serial_number' => $this->serial($request->validated()['serial_number'] ?? null)]);

        $serviceordertaskinstance->loadMissing('serviceOrder');
        $title = $serviceordertaskinstance->timelineTitle();

        Signals::dispatch(new TaskAssetSerialChanged(
            $serviceordertaskinstance->serviceOrder,
            $serviceordertaskinstance,
            $title,
            $previous,
            $asset->serial_number,
        ));

        return redirect()->back()->with('success', 'Serienummer bijgewerkt');
    }

    private function serial(?string $serial): ?string
    {
        return trim((string) $serial) ?: null;
    }
}
