<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\ServiceJob;
use Illuminate\Http\Request;
use App\Enums\ServiceCheckTypes;
use App\Enums\ServiceJobOutcomes;
use App\Http\Requests\ServiceJobCreateRequest;
use App\Models\ServiceOrder;
use App\Http\Requests\ServiceJobUpdateRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

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
        $job = ServiceJob::create($request->validated());
        $serviceOrder = ServiceOrder::with('customer')->find($job->service_order_id);
        if ($serviceOrder) {
            $asset = $job->asset()->with(['product.brand', 'product.productType'])->first();
            if ($asset) {
                $serviceOrder->logActivity(sprintf(
                    'Keuring toegevoegd: %s %s %s (serienummer %s)',
                    $asset->product->productType->name ?? 'Onbekend type',
                    $asset->product->brand->name ?? '',
                    $asset->product->model ?? '',
                    $asset->serial_number ?? '-'
                ));
            }
        }
        return redirect()->back()->with('success', 'Keuring succesvol aangemaakt.');
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceJob $servicejob)
    {
        return inertia('ServiceJob/ShowPage', [
            'servicejob' => $servicejob->load([
                'asset.product.productType.serviceChecks',
                'asset.product.productType.serviceCheckGroups',
                'checkInstances.serviceCheck.values',
                'checkInstances.serviceCheck.group',
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

    /**
     * Export a PDF representation of the service job checklist.
     */
    public function exportPdf(ServiceJob $servicejob)
    {
        $servicejob->load([
            'asset.product.brand',
            'asset.product.productType.serviceCheckGroups',
            'asset.product.productType.serviceChecks',
            'asset.customer',
            'checkInstances.serviceCheck.group',
            'checkInstances.serviceCheck.values',
            'checkInstances.values',
            'serviceOrder',
        ]);

        // Group logic similar to Vue groupedChecks computed property
        $instances = $servicejob->checkInstances; // already ordered by query scope in model
        $ptGroups = collect($servicejob->asset?->product?->productType?->serviceCheckGroups ?? [])
            ->map(fn($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'order' => $g->order ?? PHP_INT_MAX,
                'items' => [],
            ])->keyBy('id');

        $other = [
            'key' => 'other',
            'name' => 'Overige keurpunten',
            'order' => PHP_INT_MAX,
            'items' => [],
        ];

        foreach ($instances as $ci) {
            $gid = $ci->serviceCheck?->group?->id;
            if ($gid && $ptGroups->has($gid)) {
                $group = $ptGroups->get($gid);
                $group['items'][] = $ci;
                $ptGroups->put($gid, $group);
            } else {
                $other['items'][] = $ci;
            }
        }

        $groups = $ptGroups->filter(fn($g) => count($g['items']) > 0)
            ->sortBy('order')
            ->values()
            ->all();
        if (count($other['items']) > 0) {
            $groups[] = $other;
        }

                $pdf = Pdf::loadView('pdf.servicejob', [
                    'serviceJob' => $servicejob,
                    'groups' => $groups,
                ])->setPaper('a4');

                // Force Helvetica as default font to avoid serif fallback
                $pdf->getDomPDF()->getOptions()->set('defaultFont', 'Helvetica');

        return $pdf->download('keuring-' . $servicejob->id . '.pdf');
    }
}
