<?php

namespace App\Http\Controllers;

use App\Actions\ServiceOrders\AddServiceJobsAction;
use App\Domain\Signals\ServiceOrders\ServiceJobCompleted;
use App\Domain\Signals\ServiceOrders\ServiceJobEmailed;
use App\Domain\Signals\ServiceOrders\ServiceJobReopened;
use App\Domain\Signals\Signals;
use App\Enums\ServiceCheckTypes;
use App\Enums\ServiceJobOutcomes;
use App\Enums\ServiceJobOutcomes as ServiceJobOutcomeEnum;
use App\Exceptions\Refusal;
use App\Http\Requests\ServiceJobAddMissingInstancesRequest;
use App\Http\Requests\ServiceJobAssetSerialUpdateRequest;
use App\Http\Requests\ServiceJobClearCompletedOnRequest;
use App\Http\Requests\ServiceJobCreateRequest;
use App\Http\Requests\ServiceJobDestroyRequest;
use App\Http\Requests\ServiceJobEmailPdfRequest;
use App\Http\Requests\ServiceJobExportPdfRequest;
use App\Http\Requests\ServiceJobShowRequest;
use App\Http\Requests\ServiceJobUpdateRequest;
use App\Mail\ServiceJobPdfMail;
use App\Models\Asset;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DompdfPdf;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    public function store(ServiceJobCreateRequest $request, AddServiceJobsAction $add)
    {
        $data = $request->validated();

        ['jobs' => $jobs, 'parts' => $parts] = $add->execute(
            ServiceOrder::findOrFail($data['service_order_id']),
            [Asset::findOrFail($data['asset_id'])],
            Arr::except($data, ['service_order_id', 'asset_id']),
        );

        throw_if($jobs->isEmpty(), Refusal::class, 'Deze machine heeft al een keuring op deze werkbon.');

        $message = 'Keuring succesvol aangemaakt.';

        if ($parts !== []) {
            $message .= ' ' . count($parts) . ' gecombineerde keuring(en) aangemaakt voor gerelateerde onderdelen.';
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceJobShowRequest $request, ServiceJob $servicejob)
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
            'asset.linkedLocation',
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
        $missing = $all_checks->filter(fn ($c) => !$existing_ids->contains($c->id))
            ->values()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
            ]);

        return inertia('ServiceJob/ShowPage', [
            'servicejob' => $servicejob,
            'can_update' => Auth::user()->can('update', $servicejob),
            'can_set_serial' => $servicejob->asset !== null
                && Auth::user()->can('setSerial', $servicejob->asset),
            'checkTypesWithOptions' => array_keys(ServiceCheckTypes::getTypesWithOptions()),
            'possibleOutcomes' => ServiceJobOutcomes::comboBoxArray(),
            'missing_checks' => $missing,
            'missing_checks_count' => $missing->count(),
            'parent_job' => $servicejob->parentJob ? [
                'id' => $servicejob->parentJob->id,
                'asset_label' => $servicejob->parentJob->asset->product->brand->name
                    . ' ' . $servicejob->parentJob->asset->product->model
                    . ' (' . ($servicejob->parentJob->asset->serial_number ?? '-') . ')',
                'outcome' => $servicejob->parentJob->outcome,
            ] : null,
            'child_jobs' => $servicejob->childJobs->map(fn ($j) => [
                'id' => $j->id,
                'asset_label' => $j->asset->product->brand->name
                    . ' ' . $j->asset->product->model
                    . ' (' . ($j->asset->serial_number ?? '-') . ')',
                'asset_id' => $j->asset->id,
                'outcome' => $j->outcome,
                'completed_on' => $j->completed_on,
                'days_temporary_approval' => $j->days_temporary_approval,
                'description' => $j->description,
                'check_instances' => $j->checkInstances->map(fn ($ci) => [
                    'id' => $ci->id,
                    'description' => $ci->description,
                    'switch_state' => $ci->switch_state,
                    'service_check_id' => $ci->service_check_id,
                    'service_check' => $ci->serviceCheck ? [
                        'id' => $ci->serviceCheck->id,
                        'name' => $ci->serviceCheck->name,
                        'type' => $ci->serviceCheck->type,
                        'order' => $ci->serviceCheck->order ?? 0,
                        'group' => $ci->serviceCheck->group ? [
                            'id' => $ci->serviceCheck->group->id,
                            'name' => $ci->serviceCheck->group->name,
                            'order' => $ci->serviceCheck->group->order ?? 0,
                        ] : null,
                    ] : null,
                    'values' => $ci->values?->pluck('value')->all() ?? [],
                    'remarks' => ($ci->remarks ?? collect())->map(fn ($r) => $r->content)->all(),
                    'images' => $ci->images ?? [],
                ])->values()->all(),
                'product_type' => [
                    'name' => $j->asset->product->productType?->name ?? '',
                    'service_check_groups' => ($j->asset->product->productType?->serviceCheckGroups ?? collect())
                        ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'order' => $g->order ?? 0])
                        ->values()->all(),
                ],
            ])->values()->all(),
        ]);
    }

    public function bulkComplete(ServiceJobUpdateRequest $request, ServiceJob $servicejob)
    {
        if ($noOutcomeError = $this->guardNoOutcomeWithDate($request)) {
            return $noOutcomeError;
        }

        $jobs = $servicejob->childJobs->prepend($servicejob);
        $this->ensureAllChecksComplete($jobs);

        $data = $request->validated();
        $data['completed_by'] = Auth::user()->id;

        Signals::asBatch(fn () => DB::transaction(function () use ($jobs, $data) {
            foreach ($jobs as $job) {
                $this->complete($job, $data);
            }
        }));

        return redirect()->back()->with('success', 'Alle keuringen opgeslagen.');
    }

    /**
     * Saves a keuring as done, and says so when that is news (ServiceJobCompleted::isNews):
     * a finished keuring saved again as it was is not completed a second time. Moving the
     * machine's next inspection date along with it is MoveMachineServiceDate's business.
     *
     * A finished keuring saved again with the same outcome and days -- completing a
     * bundle saves its parts that were already done too -- keeps who finished it and
     * when: saving it again is not doing it again.
     */
    private function complete(ServiceJob $job, array $data): void
    {
        $previous_outcome = $job->outcome;
        $previous_completed_on = $job->getRawOriginal('completed_on');
        $previous_completed_by = $job->completed_by;
        $previous_days_temporary_approval = $job->days_temporary_approval;

        if ($previous_completed_on !== null && !$this->changesTheConclusion($job, $data)) {
            $data = Arr::except($data, ['completed_on', 'completed_by']);
        }

        $job->update($data);

        $signal = new ServiceJobCompleted(
            $job,
            $previous_outcome,
            $previous_completed_on,
            $previous_completed_by === null ? null : (int) $previous_completed_by,
            $previous_days_temporary_approval === null ? null : (int) $previous_days_temporary_approval,
        );

        if ($signal->isNews()) {
            Signals::dispatch($signal);
        }
    }

    /**
     * Whether these values change what the keuring concluded: its outcome, or the days
     * of its temporary approval. No days and 0 days are the same, as the form sends 0
     * for an outcome that has none.
     */
    private function changesTheConclusion(ServiceJob $job, array $data): bool
    {
        $days = array_key_exists('days_temporary_approval', $data)
            ? $data['days_temporary_approval']
            : $job->days_temporary_approval;

        return ($data['outcome'] ?? $job->outcome) !== $job->outcome
            || (int) $days !== (int) $job->days_temporary_approval;
    }

    private function guardNoOutcomeWithDate(ServiceJobUpdateRequest $request)
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

        return null;
    }

    private function ensureAllChecksComplete($jobs): void
    {
        $messages = [];
        foreach ($jobs as $job) {
            $incomplete = $job->incompleteCheckInstances();
            if ($incomplete->isEmpty()) {
                continue;
            }
            $names = $incomplete->map(fn ($ci) => $ci->serviceCheck?->name)->filter()->unique()->values();
            $messages[] = '"' . $job->asset->product->brand->name . ' ' . $job->asset->product->model
                . '" (' . $incomplete->count() . ' open): ' . $names->implode(', ');
        }
        if (!empty($messages)) {
            throw ValidationException::withMessages([
                'service_checks' => 'Niet alle keurpunten zijn ingevuld — ' . implode(' | ', $messages),
            ]);
        }
    }

    public function addMissingInstances(ServiceJobAddMissingInstancesRequest $request, ServiceJob $servicejob)
    {
        $servicejob->load('asset.product.productType.serviceChecks', 'checkInstances');
        $all_checks = collect($servicejob->asset?->product?->productType?->serviceChecks ?? []);
        $existing_ids = $servicejob->checkInstances->pluck('service_check_id')->filter();
        $missing = $all_checks->filter(fn ($c) => !$existing_ids->contains($c->id));
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
        if ($noOutcomeError = $this->guardNoOutcomeWithDate($request)) {
            return $noOutcomeError;
        }

        $data = $request->validated();
        $data['completed_by'] = Auth::user()->id;
        $service_date_before = $this->machineServiceDate($servicejob);

        DB::transaction(fn () => $this->complete($servicejob, $data));

        $message = $this->serviceDateMessage($service_date_before, $this->machineServiceDate($servicejob));

        return redirect()->back()->with('success', trim('Keuring succesvol bijgewerkt. ' . $message));
    }

    /** The machine's next service date as stored, read fresh: a listener may just have moved it. */
    private function machineServiceDate(ServiceJob $servicejob): ?string
    {
        return $servicejob->asset()->value('next_service_date');
    }

    /** What happened to the machine's next service date, in the words of the flash message. */
    private function serviceDateMessage(?string $before, ?string $after): string
    {
        if ($before === $after || $after === null) {
            return '';
        }

        $new_date = Carbon::parse($after)->format('d-m-Y');

        if ($before === null) {
            return sprintf('De verloopdatum is gezet op %s.', $new_date);
        }

        $days = (int) Carbon::parse($before)->diffInDays(Carbon::parse($after), false);

        if ($days === 0) {
            return '';
        }

        return sprintf(
            'De verloopdatum is met %d dagen %s naar %s.',
            abs($days),
            $days > 0 ? 'verlengd' : 'verkort',
            $new_date
        );
    }

    /**
     * The serial of the machine being inspected, filled in from the keuring
     * itself -- that is where the plate is in front of you.
     */
    public function updateAssetSerial(ServiceJobAssetSerialUpdateRequest $request, ServiceJob $servicejob)
    {
        $servicejob->asset->update(['serial_number' => $request->validated()['serial_number']]);

        return redirect()->back()->with('success', 'Serienummer opgeslagen.');
    }

    /**
     * Opens a finished keuring again. One that was not finished has nothing to undo, so
     * nothing is announced and the machine's date stays where it is.
     */
    public function clearCompletedOn(ServiceJobClearCompletedOnRequest $request, ServiceJob $servicejob)
    {
        $previous_completed_on = $servicejob->getRawOriginal('completed_on');
        $previous_completed_by = $servicejob->completed_by;
        $service_date_before = $this->machineServiceDate($servicejob);

        DB::transaction(function () use ($servicejob, $previous_completed_on, $previous_completed_by) {
            $servicejob->update([
                'completed_on' => null,
                'completed_by' => null,
            ]);

            if ($previous_completed_on !== null) {
                Signals::dispatch(new ServiceJobReopened(
                    $servicejob,
                    $previous_completed_on,
                    $previous_completed_by === null ? null : (int) $previous_completed_by,
                ));
            }
        });

        $message = $this->serviceDateMessage($service_date_before, $this->machineServiceDate($servicejob));

        return redirect()
            ->back()
            ->with(
                'success',
                sprintf(
                    'Datum van afronding succesvol verwijderd.%s Nu kan de keuring opnieuw worden uitgevoerd.',
                    $message === '' ? '' : ' ' . $message
                )
            );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceJobDestroyRequest $request, ServiceJob $servicejob)
    {
        DB::transaction(function () use ($servicejob) {
            $servicejob->childJobs->each->delete();
            $servicejob->delete();
        });

        return redirect()->back()->with('success', 'Keuring succesvol verwijderd.');
    }

    /**
     * Export a PDF representation of the service job checklist.
     */
    public function exportPdf(ServiceJobExportPdfRequest $request, ServiceJob $servicejob)
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
    public function emailPdf(ServiceJobEmailPdfRequest $request, ServiceJob $servicejob)
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

        Signals::dispatch(new ServiceJobEmailed($servicejob->serviceOrder, $servicejob, $recipients));

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

        $asset = $servicejob->asset;
        $product = $asset?->product;
        $customer = $asset?->resolvedCustomer();
        $location = $asset?->resolvedLinkedLocation();
        $address_source = $location ?? $customer;
        $address = trim(collect([
            $address_source?->address,
            trim(($address_source?->postal_code ?? '') . ' ' . ($address_source?->city ?? '')),
        ])->filter()->implode(', '));
        $new_expiry_date = null;
        if ($servicejob->completed_on && $asset?->next_service_date
            && $servicejob->getDaysToAdvanceNextServiceDate($servicejob->days_temporary_approval) !== null) {
            $new_expiry_date = Carbon::parse($asset->next_service_date)->format('d-m-Y');
        }
        $pt_name = trim((string) ($product?->productType?->name ?? 'installatie'));
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

        $childJobSections = $servicejob->childJobs->map(fn ($childJob) => [
            'asset_label' => $childJob->asset->product->brand->name
                . ' ' . $childJob->asset->product->model
                . ' — ' . ($childJob->asset->serial_number ?? '-'),
            'outcome' => $childJob->outcome,
            'groups' => $this->buildRawGroups(
                $childJob->checkInstances,
                $childJob->asset?->product?->productType
            ),
        ])->all();

        $isChildJob = $servicejob->parent_service_job_id !== null;
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
            'location' => $location,
            'address' => $address,
            'newExpiryDate' => $new_expiry_date,
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
            'isChildJob' => $isChildJob,
            'parentJobLabel' => $parentJobLabel,
        ])->setPaper('a4');
        $pdf->getDomPDF()->getOptions()->set('defaultFont', 'Helvetica');

        return $pdf;
    }

    private function buildRawGroups(Collection $instances, $productType): array
    {
        $ptGroups = collect($productType?->serviceCheckGroups ?? [])
            ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'order' => $g->order ?? PHP_INT_MAX, 'items' => []])
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

        $groups = $ptGroups->filter(fn ($g) => count($g['items']) > 0)->sortBy('order')->values()->all();
        if (count($other['items']) > 0) {
            $groups[] = $other;
        }

        return array_map(function ($g) {
            $g['items'] = array_map(fn ($ci) => [
                'check_name' => $ci->serviceCheck?->name,
                'type' => $ci->serviceCheck?->type,
                'description' => $ci->description,
                'switch_state' => $ci->switch_state ?? null,
                'values' => $ci->values?->pluck('value')->all() ?? [],
                'remarks' => ($ci->remarks ?? collect())->map(fn ($r) => $r->content)->all(),
                'images' => $ci->images,
            ], $g['items']);

            return $g;
        }, $groups);
    }
}
