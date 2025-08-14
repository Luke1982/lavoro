<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\ServiceJob;
use Illuminate\Http\Request;
use App\Enums\ServiceCheckTypes;
use App\Enums\ServiceJobOutcomes;
use App\Http\Requests\ServiceJobCreateRequest;
use App\Http\Requests\ServiceJobUpdateRequest;

class ServiceJobController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ServiceJobCreateRequest $request)
    {
        ServiceJob::create($request->validated());
        return redirect()->back()->with('success', 'Keuring succesvol aangemaakt.');
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceJob $servicejob)
    {
        return inertia('ServiceJob/ShowPage', [
            'servicejob' => $servicejob->load([
                'asset.product.productType.checks',
                'checkInstances.serviceCheck.values',
                'checkInstances.values',
                'asset.product.brand',
                'asset.customer',
                'serviceOrder',
            ]),
            'checkTypesWithOptions' => array_keys(ServiceCheckTypes::getTypesWithOptions()),
            'possibleOutcomes' => ServiceJobOutcomes::comboBoxArray(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ServiceJobUpdateRequest $request, ServiceJob $servicejob)
    {
        $servicejob->update($request->validated());
        $message = '';
        $days = $servicejob->getDaysToAdvanceNextServiceDate(
            $request->days_temporary_approval
        );

        if ($days !== null) {
            $servicejob->asset->update([
                'next_service_date' => Carbon::parse($servicejob->asset->next_service_date)
                    ->addDays($days),
            ]);
            $message = sprintf(
                'De verloopdatum is met %d dagen verlengd naar %s.',
                $days,
                Carbon::parse($servicejob->asset->next_service_date)->format('d-m-Y')
            );
        }

        return redirect()->back()->with('success', 'Keuring succesvol bijgewerkt. ' . $message);
    }

    public function clearCompletedOn(ServiceJob $servicejob)
    {
        $days = $servicejob->getDaysToAdvanceNextServiceDate(
            $servicejob->days_temporary_approval
        );
        $message = '';
        $servicejob->update([
            'completed_on' => null,
        ]);
        if ($days !== null) {
            $servicejob->asset->update([
                'next_service_date' => Carbon::parse($servicejob->asset->next_service_date)
                    ->subDays($days),
            ]);
            $message = sprintf(
                ' De verloopdatum is met %d dagen verkort naar %s.',
                $days,
                Carbon::parse($servicejob->asset->next_service_date)->format('d-m-Y')
            );
        }
        return redirect()
            ->back()
            ->with(
                'success',
                sprintf(
                    'Datum van afronding succesvol verwijderd.%s Nu kan de keuring opnieuw worden uitgevoerd.',
                    $message
                )
            );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceJob $servicejob)
    {
        $servicejob->delete();
        return redirect()->back()->with('success', 'Keuring succesvol verwijderd.');
    }
}
