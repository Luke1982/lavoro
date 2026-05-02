<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Company;
use App\Models\ServiceJob;
use Illuminate\Support\Str;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use App\Mail\ServiceJobPdfMail;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Enums\ServiceCheckTypes;
use App\Enums\ServiceJobOutcomes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\PDF as DompdfPdf;
use App\Http\Requests\ServiceJobCreateRequest;
use App\Http\Requests\ServiceJobUpdateRequest;
use App\Models\Asset;
use App\Enums\ServiceJobOutcomes as ServiceJobOutcomeEnum;

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

        // Auto-create service jobs for all child assets
        $parentAsset = Asset::with([
            'childAssets.product.brand',
            'childAssets.product.productType',
        ])->find($job->asset_id);

        $newChildCount = 0;

        foreach ($parentAsset?->childAssets ?? [] as $childAsset) {
            $childJob = ServiceJob::firstOrCreate(
                [
                    'asset_id'         => $childAsset->id,
                    'service_order_id' => $job->service_order_id,
                ],
                [
                    'outcome'               => ServiceJobOutcomes::nog_geen_uitkomst->value,
                    'parent_service_job_id' => $job->id,
                ]
            );

            // If the job already existed, ensure the parent link is set
            if (!$childJob->wasRecentlyCreated && $childJob->parent_service_job_id === null) {
                $childJob->update(['parent_service_job_id' => $job->id]);
            }

            if ($childJob->wasRecentlyCreated) {
                $newChildCount++;
                if ($serviceOrder) {
                    $serviceOrder->logActivity(sprintf(
                        'Gecombineerde keuring toegevoegd voor onderdeel: %s %s %s (serienummer %s)',
                        $childAsset->product->productType->name ?? 'Onbekend type',
                        $childAsset->product->brand->name ?? '',
                        $childAsset->product->model ?? '',
                        $childAsset->serial_number ?? '-'
                    ));
                }
            }
        }

        $childNote = "{$newChildCount} gecombineerde keuring(en) aangemaakt voor gerelateerde onderdelen.";
        $message = $newChildCount > 0
            ? "Keuring succesvol aangemaakt. {$childNote}"
            : 'Keuring succesvol aangemaakt.';

        return redirect()->back()->with('success', $message);
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceJob $servicejob)
    {
        $servicejob->load([
            'asset.product.productType.serviceChecks',
            'asset.product.productType.serviceCheckGroups',
            'checkInstances.serviceCheck.values',
            'checkInstances.serviceCheck.group',
            'checkInstances.values',
            'checkInstances.images',
            'checkInstances.remarks.user',
            'asset.product.brand',
            'asset.customer',
            'serviceOrder',
            'parentJob.asset.product.brand',
            'parentJob.asset.product.productType',
            'parentJob.asset.customer',
            'childJobs.asset.product.brand',
            'childJobs.asset.product.productType',
            'childJobs.checkInstances.serviceCheck.group',
            'childJobs.checkInstances.serviceCheck.values',
            'childJobs.checkInstances.values',
            'childJobs.checkInstances.images',
            'childJobs.checkInstances.remarks.user',
        ]);

        $all_checks = collect($servicejob->asset?->product?->productType?->serviceChecks ?? []);
        $existing_ids = $servicejob->checkInstances->pluck('service_check_id')->filter();
        $missing = $all_checks->filter(fn($c) => !$existing_ids->contains($c->id))
            ->values()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
            ]);

        return inertia('ServiceJob/ShowPage', [
            'servicejob' => $servicejob,
            'checkTypesWithOptions' => array_keys(ServiceCheckTypes::getTypesWithOptions()),
            'possibleOutcomes' => ServiceJobOutcomes::comboBoxArray(),
            'missing_checks' => $missing,
            'missing_checks_count' => $missing->count(),
            'parent_job' => $servicejob->parentJob ? [
                'id'          => $servicejob->parentJob->id,
                'asset_label' => $servicejob->parentJob->asset->product->brand->name
                    . ' ' . $servicejob->parentJob->asset->product->model
                    . ' (' . ($servicejob->parentJob->asset->serial_number ?? '-') . ')',
                'outcome'     => $servicejob->parentJob->outcome,
            ] : null,
            'child_jobs' => $servicejob->childJobs->map(fn($j) => [
                'id'          => $j->id,
                'asset_label' => $j->asset->product->brand->name
                    . ' ' . $j->asset->product->model
                    . ' (' . ($j->asset->serial_number ?? '-') . ')',
                'outcome'     => $j->outcome,
            ])->values()->all(),
        ]);
    }

    public function addMissingInstances(ServiceJob $servicejob)
    {
        $servicejob->load('asset.product.productType.serviceChecks', 'checkInstances');
        $all_checks = collect($servicejob->asset?->product?->productType?->serviceChecks ?? []);
        $existing_ids = $servicejob->checkInstances->pluck('service_check_id')->filter();
        $missing = $all_checks->filter(fn($c) => !$existing_ids->contains($c->id));
        if ($missing->isEmpty()) {
            return redirect()->back()->with('info', 'Geen ontbrekende keurpunten gevonden.');
        }
        foreach ($missing as $check) {
            $servicejob->checkInstances()->create([
                'service_check_id' => $check->id,
            ]);
        }
        return redirect()->back()->with('success', $missing->count() . ' ontbrekende keurpunten toegevoegd.');
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
        if (
            $request->outcome === ServiceJobOutcomeEnum::nog_geen_uitkomst->value &&
            $request->completed_on
        ) {
            return redirect()->back()->with(
                'error',
                'Kies een uitkomst voor de keuring, dit kan niet "Nog geen uitkomst" zijn.'
            );
        }
        $data = $request->validated();
        $data['completed_by'] = Auth::user()->id;
        $servicejob->update($data);
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
            'completed_by' => null,
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
        $pdf = $this->generateServiceJobPdf($servicejob);
        return $pdf->stream('keuring-' . $servicejob->id . '.pdf');
    }

    public function exportPdfForCombine(ServiceJob $servicejob): string
    {
        $pdf = $this->generateServiceJobPdf($servicejob);
        return $pdf->output();
    }

    /**
     * Generate PDF and email it to the customer.
     */
    public function emailPdf(ServiceJob $servicejob)
    {
        $recipients = array_unique(array_filter([
            $servicejob->asset?->customer?->email,
            $servicejob->asset?->customer?->invoice_email,
        ]));

        if (empty($recipients)) {
            return redirect()->back()->with('error', 'Klant heeft geen e-mailadres.');
        }

        $pdf = $this->generateServiceJobPdf($servicejob);

        Mail::to($recipients)->send(new ServiceJobPdfMail($servicejob, $pdf->output()));

        if ($servicejob->serviceOrder) {
            $servicejob->serviceOrder->logActivity('Keuring per e-mail verzonden naar: ' . implode(', ', $recipients));
        }

        return redirect()->back()->with('success', 'Keuring verzonden naar: ' . implode(', ', $recipients));
    }

    /**
     * Shared PDF generation logic for service jobs.
     */
    private function generateServiceJobPdf(ServiceJob $servicejob): DompdfPdf
    {
        $servicejob->load([
            'asset.product.brand',
            'asset.product.productType.serviceCheckGroups',
            'asset.product.productType.serviceChecks',
            'asset.customer',
            'checkInstances.serviceCheck.group',
            'checkInstances.serviceCheck.values',
            'checkInstances.values',
            'checkInstances.images',
            'checkInstances.remarks.user',
            'serviceOrder.customer',
            'completedBy',
            'parentJob.asset.product.brand',
            'parentJob.asset.product.productType',
            'childJobs.asset.product.brand',
            'childJobs.asset.product.productType',
            'childJobs.asset.customer',
            'childJobs.checkInstances.serviceCheck.group',
            'childJobs.checkInstances.serviceCheck.values',
            'childJobs.checkInstances.values',
            'childJobs.checkInstances.images',
            'childJobs.checkInstances.remarks.user',
            'childJobs.asset.product.productType.serviceCheckGroups',
        ]);

        $asset    = $servicejob->asset;
        $product  = $asset?->product;
        $customer = $asset?->customer;
        $pt_name       = trim((string) ($product?->productType?->name ?? 'installatie'));
        $pt_name_lower = Str::lower($pt_name);

        $raw_groups = $this->buildRawGroups(
            $servicejob->checkInstances,
            $product?->productType
        );

        $remarks_text = trim((string) $servicejob->description);
        $outcome = $servicejob->outcome;
        $tmp_days = $servicejob->days_temporary_approval;
        $tmp_until = null;
        if ($outcome === ServiceJobOutcomeEnum::tijdelijk_goedkeur->value && $tmp_days) {
            $tmp_until = optional($servicejob->created_at)->copy()->addDays($tmp_days)->format('d-m-Y');
        }
        $is_temp_approved = $outcome === ServiceJobOutcomeEnum::tijdelijk_goedkeur->value;
        $is_repair = $outcome === ServiceJobOutcomeEnum::reparatie->value;
        $main_company = Company::where('is_main', true)->first();
        $logo = Company::pdfLogo($main_company);

        $childJobSections = $servicejob->childJobs->map(fn($childJob) => [
            'asset_label' => $childJob->asset->product->brand->name
                . ' ' . $childJob->asset->product->model
                . ' — ' . ($childJob->asset->serial_number ?? '-'),
            'outcome' => $childJob->outcome,
            'groups'  => $this->buildRawGroups(
                $childJob->checkInstances,
                $childJob->asset?->product?->productType
            ),
        ])->all();

        $isChildJob     = $servicejob->parent_service_job_id !== null;
        $parentJobLabel = $servicejob->parentJob
            ? $servicejob->parentJob->asset->product->brand->name
                . ' ' . $servicejob->parentJob->asset->product->model
                . ' — ' . ($servicejob->parentJob->asset->serial_number ?? '-')
            : null;

        $pdf = Pdf::loadView('pdf.servicejob', [
            'serviceJob' => $servicejob,
            'asset' => $asset,
            'product' => $product,
            'customer' => $customer,
            'ptName' => $pt_name,
            'ptNameLower' => $pt_name_lower,
            'groups' => $raw_groups,
            'remarksText' => $remarks_text,
            'outcome' => $outcome,
            'tmpDays' => $tmp_days,
            'tmpUntil' => $tmp_until,
            'isTempApproved' => $is_temp_approved,
            'isRepair' => $is_repair,
            'logo' => $logo,
            'company' => $main_company,
            'childJobSections' => $childJobSections,
            'isChildJob'       => $isChildJob,
            'parentJobLabel'   => $parentJobLabel,
        ])->setPaper('a4');
        $pdf->getDomPDF()->getOptions()->set('defaultFont', 'Helvetica');
        return $pdf;
    }

    private function buildRawGroups(\Illuminate\Support\Collection $instances, $productType): array
    {
        $ptGroups = collect($productType?->serviceCheckGroups ?? [])
            ->map(fn($g) => ['id' => $g->id, 'name' => $g->name, 'order' => $g->order ?? PHP_INT_MAX, 'items' => []])
            ->keyBy('id');
        $other = ['key' => 'other', 'name' => 'Overige keurpunten', 'order' => PHP_INT_MAX, 'items' => []];

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

        $groups = $ptGroups->filter(fn($g) => count($g['items']) > 0)->sortBy('order')->values()->all();
        if (count($other['items']) > 0) {
            $groups[] = $other;
        }

        return array_map(function ($g) {
            $g['items'] = array_map(fn($ci) => [
                'check_name'   => $ci->serviceCheck?->name,
                'type'         => $ci->serviceCheck?->type,
                'description'  => $ci->description,
                'switch_state' => $ci->switch_state ?? null,
                'values'       => $ci->values?->pluck('value')->all() ?? [],
                'remarks'      => ($ci->remarks ?? collect())->map(fn($r) => $r->content)->all(),
                'images'       => $ci->images,
            ], $g['items']);
            return $g;
        }, $groups);
    }
}
