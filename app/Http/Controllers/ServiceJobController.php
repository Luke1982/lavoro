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
use Illuminate\Support\Facades\Mail;
use App\Mail\ServiceJobPdfMail;
use Barryvdh\DomPDF\PDF as DompdfPdf;
use App\Models\Company;
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
        return redirect()->back()->with('success', 'Keuring succesvol aangemaakt.');
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
            'checkInstances.remarks.user',
            'asset.product.brand',
            'asset.customer',
            'serviceOrder',
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
                $pdf = $this->generateServiceJobPdf($servicejob);
                return $pdf->download('keuring-' . $servicejob->id . '.pdf');
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
            'checkInstances.remarks.user',
            'serviceOrder.customer',
        ]);

        $instances = $servicejob->checkInstances; // ordered
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

        $asset = $servicejob->asset;
        $product = $asset?->product;
        $customer = $asset?->customer;
        $pt_name = trim((string) ($product?->productType?->name ?? 'installatie'));
        $pt_name_lower = Str::lower($pt_name);

        $raw_groups = array_map(function ($g) {
            $g['items'] = array_map(function ($ci) {
                $check = $ci->serviceCheck;
                return [
                    'check_name' => $check?->name,
                    'type' => $check?->type,
                    'description' => $ci->description,
                    'values' => $ci->values?->pluck('value')->all() ?? [],
                    'remarks' => ($ci->remarks ?? collect())->map(fn($r) => $r->content)->all(),
                ];
            }, $g['items']);
            return $g;
        }, $groups);

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
        ])->setPaper('a4');
        $pdf->getDomPDF()->getOptions()->set('defaultFont', 'Helvetica');
        return $pdf;
    }
}
