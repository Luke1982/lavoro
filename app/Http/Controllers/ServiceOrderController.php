<?php

namespace App\Http\Controllers;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\NewServiceOrder;
use App\Domain\Signals\ServiceOrders\SalesOrderCreatedInSnelStart;
use App\Domain\Signals\ServiceOrders\ServiceOrderCustomerChanged;
use App\Domain\Signals\ServiceOrders\ServiceOrderEmailed;
use App\Domain\Signals\ServiceOrders\ServiceOrderInvoiceRecorded;
use App\Domain\Signals\ServiceOrders\TicketAttachedToOrder;
use App\Domain\Signals\ServiceOrders\TicketDetachedFromOrder;
use App\Domain\Signals\Signals;
use App\Enums\EventStatusses;
use App\Http\Requests\ServiceOrderAttachMaterialRequest;
use App\Http\Requests\ServiceOrderBulkUpdateRequest;
use App\Http\Requests\ServiceOrderDeleteRequest;
use App\Http\Requests\ServiceOrderDetachMaterialRequest;
use App\Http\Requests\ServiceOrderEmailPdfRequest;
use App\Http\Requests\ServiceOrderEmailPdfWithChecksRequest;
use App\Http\Requests\ServiceOrderExportPdfRequest;
use App\Http\Requests\ServiceOrderIndexRequest;
use App\Http\Requests\ServiceOrderUpdateMateriableRequest;
use App\Http\Requests\ServiceOrderUpdateRequest;
use App\Http\Requests\TicketDetachFromServiceOrderRequest;
use App\Jobs\BulkMoveServiceOrderStageJob;
use App\Mail\ServiceOrderPdfMail;
use App\Mail\ServiceOrderWithJobsPdfMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentCategory;
use App\Models\GeneralSetting;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use App\Models\Product;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTask;
use App\Models\Ticket;
use App\Models\UserRole;
use App\Services\AssetTransferService;
use App\Services\EventLocationResolver;
use App\Services\MateriableService;
use App\Services\ServiceOrderEventWidget;
use App\Services\ServiceOrderLocationResolver;
use App\Services\SnelStartClient;
use App\Traits\ReadsPerPage;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DompdfPdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class ServiceOrderController extends Controller
{
    use ReadsPerPage;

    /** Selections up to this size are moved inline; anything larger goes to the queue. */
    private const BULK_STAGE_INLINE_LIMIT = 40;

    /**
     * Display a listing of the resource.
     */
    public function index(ServiceOrderIndexRequest $request)
    {
        $search = $request->get('search', '');
        $only_stages = array_values(array_filter(
            explode(',', (string) $request->get('onlyStage', '')),
            fn ($v) => is_numeric($v)
        ));
        $only_needs_closing = $request->boolean('onlyNeedsClosing');
        $per_page = $this->perPage($request, 25);

        $user = Auth::user();
        $query = ServiceOrder::with([
            'customer',
            'serviceOrderStage',
            'events' => fn ($q) => $q->orderBy('start'),
            'events.executingUsers:id,name',
        ]);

        $query->visibleTo($user);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('external_purchaseorder_no', 'like', "%{$search}%")
                    ->orWhere('external_invoice_no', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('city', 'like', "%{$search}%");
                    })
                    ->orWhereHas('serviceOrderStage', function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('events.executingUsers', function ($uq) use ($search) {
                        $uq->where('users.name', 'like', "%{$search}%");
                    });
            });
        }

        if (count($only_stages)) {
            $query->whereIn('service_order_stage_id', $only_stages);
        }

        $closed_stage_id = ServiceOrderStage::where('is_closed_state', true)->value('id');
        $only_closed_stage = count($only_stages) === 1 && (int) $only_stages[0] === $closed_stage_id;

        if ($only_needs_closing) {
            $query->whereHas('events', fn ($q) => $q->where('status', '!=', EventStatusses::cancelled->value))
                ->whereDoesntHave('events', fn ($q) => $q
                    ->where('status', '!=', EventStatusses::cancelled->value)
                    ->where('end', '>=', now()))
                ->whereDoesntHave('serviceOrderStage', fn ($q) => $q->where(fn ($sub) => $sub
                    ->where('is_closed_state', true)
                    ->orWhere('is_invoiced_state', true)));
        }

        return inertia('ServiceOrders/IndexPage', [
            'serviceOrders' => $query->orderByDesc($only_closed_stage ? 'closed_on' : 'order_date')
                ->orderByDesc('id')
                ->paginate($per_page)->withQueryString(),
            'stages' => ServiceOrderStage::orderBy('order')->get(),
            'search' => $search,
            'onlyStage' => $only_stages,
            'onlyNeedsClosing' => $only_needs_closing,
            'perPage' => $per_page,
        ]);
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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'project_id' => 'nullable|exists:projects,id',
            'location_id' => [
                'nullable',
                Rule::exists('locations', 'id')
                    ->where(fn ($q) => $q->where('customer_id', $request->input('customer_id'))),
            ],
        ]);

        $serviceorder = app(CreateServiceOrderAction::class)->execute(new NewServiceOrder(
            customer_id: (int) $validated['customer_id'],
            project_id: isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            location_id: isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            asset_ids: array_map('intval', $request->input('assets', [])),
            ticket_ids: array_map('intval', $request->input('tickets', [])),
        ));

        $redirect = $request->has('tickets') || $request->has('assets')
            ? 'serviceorders.show'
            : 'back';

        if ($request->input('json')) {
            return response()->json($serviceorder);
        }

        if ($redirect === 'back' || $request->input('redirect') === false) {
            /** De bon reist mee terug, zodat wie hem meteen wil openen niet hoeft te zoeken. */
            return redirect()->back()->with([
                'success' => 'Werkbon succesvol aangemaakt.',
                'extra' => ['serviceorder' => $serviceorder],
            ]);
        } else {
            return redirect()->route($redirect, $serviceorder->id)
                ->with(
                    'success',
                    'Werkbon succesvol aangemaakt en gekoppeld aan de geselecteerde tickets en/of keuringen.'
                );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceOrderEventWidget $event_widget, string $id)
    {
        $service_order = ServiceOrder::with([
            'serviceOrderStage',
            'servicejobs.asset.product.brand',
            'customer.tickets.asset.product.brand',
            'customer.tickets.asset.product.productType',
            'tickets.asset.product.brand',
            'tickets.asset.product.productType',
            'materials.category',
            'materials.usageUnit',
            'freeformMaterials',
            'activities' => function ($q) {
                $q->visibleTo(Auth::user())
                    ->with(['user:id,name', 'fieldChanges'])
                    ->orderByDesc('activityables.created_at');
            },
            'remarks.user',
            'internalRemarks.user',
            'events.eventType',
            'events.executingUsers:id,name',
            'events.executions',
            ...EventLocationResolver::relations('events.'),
            'customFields',
            'taskInstances.serviceOrderTask',
            'taskInstances.userRoles',
            'taskInstances.materials.category',
            'taskInstances.materials.usageUnit',
            'taskInstances.freeformMaterials',
            'taskInstances.product.brand',
            'taskInstances.product.productType',
            'taskInstances.product.productables.childProduct.brand',
            'taskInstances.product.productables.childProduct.productType',
            'taskInstances.assets',
            'project:id,title,location',
            'documents.category',
            'documents.user:id,name',
            'internalDocuments.category',
            'internalDocuments.user:id,name',
            'images',
            'internalImages',
        ])->findOrFail($id);

        $user = Auth::user();

        /**
         * The customer's machines at every depth, not just the roots. Child machines carry
         * no customer_id, so customer->assets would silently drop them and a job could
         * never be booked on a component.
         */
        $customer_assets = $service_order->customer
            ? $service_order->customer->assetTree([
                'product.brand',
                'product.productType',
                'product.images',
                'linkedLocation',
                'servicejobs:id,asset_id,completed_on',
            ])
            : collect();

        $service_order->taskInstances->each->append('serial_slots');

        $all_task_instances = $service_order->taskInstances;
        $visible_instances = $all_task_instances;

        if (!$user->isAdmin() && !$user->hasPermission('serviceorder.see_all_task_instances')) {
            $role_ids = $service_order->events
                ->flatMap(fn ($event) => $event->executingUserRoleIds($user->id))
                ->unique()
                ->values()
                ->all();

            $visible_instances = $all_task_instances->filter(
                fn ($instance) => $instance->userRoles->isEmpty()
                    || $instance->userRoles->pluck('id')->intersect($role_ids)->isNotEmpty()
            )->values();
        }

        /**
         * Built before the relation is narrowed, so what the order was billed for reads the
         * same for everyone. Which task consumed a line is the part that stays hidden when
         * the user may not see that task.
         */
        $visible_instance_ids = $visible_instances->pluck('id');
        $combined_materials = $this->labelTaskLines($service_order->allMaterials(), $visible_instance_ids);
        $combined_freeform = $this->labelTaskLines($service_order->allFreeformMaterials(), $visible_instance_ids);

        $service_order->setRelation('taskInstances', $visible_instances);

        $stages = ServiceOrderStage::orderBy('order')
            ->with(['activities' => function ($q) use ($service_order) {
                $q->visibleTo(Auth::user())
                    ->whereHas('serviceOrders', fn ($qq) => $qq->whereKey($service_order->id))
                    ->with('user:id,name')
                    ->orderByDesc('activities.created_at');
            }])
            ->get();
        $first_stage_id = $stages->first()?->id;

        $stages_with_meta = $stages->map(function ($stage) use ($service_order, $first_stage_id) {
            $latest = $stage->activities->first();

            $reached_at = $latest?->created_at;
            $reached_by = $latest?->user?->name;

            if (!$reached_at && $stage->id === $first_stage_id) {
                $reached_at = $service_order->created_at;
            }

            $stage->unsetRelation('activities');

            return array_merge($stage->toArray(), [
                'reached_at' => $reached_at,
                'reached_by' => $reached_by,
            ]);
        });

        return inertia('ServiceOrders/ShowPage', [
            'serviceOrder' => $service_order,
            'documentCategories' => DocumentCategory::forPicker(),
            'mapLocation' => ServiceOrderLocationResolver::resolve($service_order),
            'customerAssets' => $customer_assets,
            'allTaskInstances' => $all_task_instances,
            'combinedMaterials' => $combined_materials,
            'combinedFreeformMaterials' => $combined_freeform,
            'usersMissingTimes' => $event_widget->usersMissingTimes($service_order),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'userRoles' => UserRole::orderBy('name')->get(['id', 'name', 'color']),
            'defaultLeadingColor' => GeneralSetting::get('planner_leading_color', 'event'),
            'eventWidgetEvents' => $event_widget->events($service_order, $user),
            'allMaterials' => ($mc = Material::count()) <= 50
                ? Material::with('usageUnit')->get()
                : collect(),
            'materialsUseAjax' => $mc > 50,
            'materialCategories' => MaterialCategory::orderBy('name')->get(['id', 'name']),
            'materialUsageUnits' => MaterialUsageUnit::orderBy('name')->get(['id', 'name']),
            'customFields' => $service_order->allCustomFieldsWithValues(),
            'stages' => $stages_with_meta,
            'closedStageId' => ServiceOrderStage::where('is_closed_state', true)->value('id'),
            'incompleteStageId' => ServiceOrderStage::where('is_incomplete_state', true)->value('id'),
            'availableTasks' => ServiceOrderTask::orderBy('title')->get(['id', 'title', 'description']),
            'projects' => Project::orderBy('title')->get(['id', 'title']),
            'snelStartEnabled' => filled(config('services.snelstart.client_key')),
            'products' => Product::withAttributeData()
                ->orderBy('model')
                ->get()
                ->map->toComboOption(),
        ]);
    }

    /**
     * Swaps the task instance each line carries for the two scalars the frontend needs: the
     * id it edits through, and a label that is only filled in when the user may see the task.
     */
    private function labelTaskLines(Collection $lines, Collection $visible_instance_ids): Collection
    {
        return $lines->each(function (Model $line) use ($visible_instance_ids) {
            $task_instance = $line->task_instance;

            $line->setAttribute('task_instance_id', $task_instance?->id);
            $line->setAttribute(
                'task_label',
                $task_instance && $visible_instance_ids->contains($task_instance->id)
                    ? ($task_instance->effective_title ?: 'Taak zonder titel')
                    : null
            );

            unset($line['task_instance']);
        });
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
    public function update(ServiceOrderUpdateRequest $request, ServiceOrder $serviceorder)
    {
        $data = $request->validated();

        $asset_strategy = $data['asset_strategy'] ?? null;
        $location_map = $data['location_map'] ?? [];
        unset($data['asset_strategy'], $data['location_map']);

        $transfer_roots = $asset_strategy === 'transfer'
            ? $serviceorder->serviceJobs()->with('asset')->get()
                ->pluck('asset')->filter()->map->rootAsset()->unique('id')->values()
            : collect();

        $previous_customer_id = $serviceorder->customer_id;
        $previous_external_invoice_no = $serviceorder->external_invoice_no;

        $serviceorder->load(['customer', 'project']);
        $previous_customer_name = $serviceorder->customer?->name;
        $previous_contract_title = $serviceorder->maintenanceContract?->display_title;

        $stage_requested = array_key_exists('service_order_stage_id', $data);
        $requested_stage_id = $data['service_order_stage_id'] ?? null;
        unset($data['service_order_stage_id']);

        $serviceorder->update($data);

        if ($transfer_roots->isNotEmpty()) {
            app(AssetTransferService::class)->transfer(
                $transfer_roots,
                $serviceorder->customer_id,
                $location_map
            );
        }

        if (array_key_exists('customer_id', $data) && $data['customer_id'] != $previous_customer_id) {
            Signals::dispatch(new ServiceOrderCustomerChanged(
                $serviceorder,
                $previous_customer_id,
                $previous_customer_name,
                $serviceorder->customer()->value('name'),
                $previous_contract_title,
            ));
        }

        if (
            array_key_exists('external_invoice_no', $data)
            && filled($data['external_invoice_no'])
            && blank($previous_external_invoice_no)
        ) {
            Signals::dispatch(new ServiceOrderInvoiceRecorded($serviceorder, $data['external_invoice_no']));
        }

        if ($stage_requested) {
            $serviceorder->moveToStage(
                $requested_stage_id === null ? null : ServiceOrderStage::find($requested_stage_id)
            );
        }

        return redirect()->back()->with('success', 'Werkbon succesvol bijgewerkt.');
    }

    /**
     * Export a PDF of the service order.
     */
    public function exportPdf(ServiceOrderExportPdfRequest $request, ServiceOrder $serviceorder)
    {
        $pdf = $this->generateServiceOrderPdf($serviceorder);

        return $pdf->stream('werkbon-' . $serviceorder->id . '.pdf');
    }

    /**
     * Generate PDF and email it to the customer.
     */
    public function emailPdf(ServiceOrderEmailPdfRequest $request, ServiceOrder $serviceorder)
    {
        $serviceorder->load(['customer', 'serviceOrderStage']);
        if (!$serviceorder->is_closed) {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je de PDF kunt e-mailen.');
        }
        $recipients = array_unique(array_filter([
            $serviceorder->customer?->email,
            $serviceorder->customer?->invoice_email,
        ]));
        if (empty($recipients)) {
            return redirect()->back()->with('error', 'Klant heeft geen e-mailadres.');
        }

        $pdf = $this->generateServiceOrderPdf($serviceorder);

        Mail::to($recipients)->send(new ServiceOrderPdfMail($serviceorder, $pdf->output()));

        Signals::dispatch(new ServiceOrderEmailed($serviceorder, $recipients));
        // Mark as sent to customer
        if (!$serviceorder->sent_to_customer) {
            $serviceorder->sent_to_customer = true;
            $serviceorder->save();
        }

        return redirect()->back()->with('success', 'Werkbon verzonden naar: ' . implode(', ', $recipients));
    }

    public function emailPdfWithJobs(ServiceOrderEmailPdfWithChecksRequest $request, ServiceOrder $serviceorder)
    {
        $serviceorder->load(['customer', 'serviceJobs.asset.customer', 'serviceOrderStage']);
        if (!$serviceorder->is_closed) {
            return redirect()->back()->with(
                'error',
                'Sluit de werkbon af voordat je de PDF met keuringen kunt e-mailen.'
            );
        }
        $recipients = array_unique(array_filter([
            $serviceorder->customer?->email,
            $serviceorder->customer?->invoice_email,
        ]));
        if (empty($recipients)) {
            return redirect()->back()->with('error', 'Klant heeft geen e-mailadres.');
        }
        $orderPdf = $this->generateServiceOrderPdf($serviceorder)->output();
        $jobPdfs = [];
        foreach ($serviceorder->serviceJobs as $job) {
            try {
                $jobPdf = app(ServiceJobController::class)->exportPdfForCombine($job);
                $jobPdfs[$job->id] = $jobPdf;
            } catch (\Throwable $e) {
            }
        }
        Mail::to($recipients)->send(new ServiceOrderWithJobsPdfMail($serviceorder, $orderPdf, $jobPdfs));
        Signals::dispatch(new ServiceOrderEmailed($serviceorder, $recipients, with_jobs: true));
        if (!$serviceorder->sent_to_customer) {
            $serviceorder->sent_to_customer = true;
            $serviceorder->save();
        }
        foreach ($serviceorder->serviceJobs as $job) {
            $job->update(['sent_to_customer' => true]);
        }

        return redirect()->back()->with('success', 'Werkbon + keuringen verzonden naar: ' . implode(', ', $recipients));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceOrderDeleteRequest $request, ServiceOrder $serviceorder)
    {
        $previous_path = (string) parse_url(url()->previous(), PHP_URL_PATH);
        $show_path = (string) parse_url(route('serviceorders.show', $serviceorder), PHP_URL_PATH);
        $returns_to_deleted_order = $previous_path === $show_path
            || str_starts_with($previous_path, $show_path . '/');

        DB::transaction(function () use ($serviceorder) {
            $serviceorder->delete();
        });

        return ($returns_to_deleted_order
            ? redirect()->route('serviceorders.index')
            : redirect()->back())
            ->with('success', 'Werkbon succesvol verwijderd.');
    }

    /**
     * Stuur een serviceorder naar SnelStart als verkooporder.
     */
    public function sendToSnelStart(ServiceOrder $serviceorder, SnelStartClient $client)
    {
        if ($serviceorder->sent_to_administration) {
            return redirect()->back()->with('error', 'Deze werkbon is al verzonden naar SnelStart.');
        }
        $serviceorder->load([
            'customer.billingCustomer',
            'materials',
            'taskInstances.serviceOrderTask',
            'taskInstances.materials',
            'serviceOrderStage',
        ]);
        if (!$serviceorder->is_closed) {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je kunt versturen naar SnelStart.');
        }

        $all_materials = $serviceorder->allMaterials();

        if ($all_materials->isEmpty()) {
            return redirect()->back()->with('error', 'Geen materialen gekoppeld aan deze werkbon.');
        }

        $customer = $serviceorder->customer;
        $customer = $customer?->billingCustomer ?: $customer;
        if (!$customer || !$customer->snelstart_id) {
            return redirect()->back()->with('error', 'Klant heeft geen gekoppeld SnelStart ID.');
        }

        $lines = [];
        $total_excl = 0;
        $skipped_null_quantity = [];
        $skipped_no_snelstart = [];
        foreach ($all_materials as $material) {
            if (!$material->snelstart_id) {
                $skipped_no_snelstart[] = $material->name;

                continue;
            }
            if (is_null($material->pivot->quantity)) {
                $skipped_null_quantity[] = $material->name;

                continue;
            }
            $quantity = (float) $material->pivot->quantity;
            $unit_price = (float) ($material->price ?? 0);
            $line_total = $unit_price * $quantity;
            $total_excl += $line_total;
            $lines[] = [
                'artikel' => [
                    'id' => $material->snelstart_id,
                    'uri' => '/v2/artikelen/' . $material->snelstart_id,
                ],
                'omschrijving' => (string) $material->name . ($material->task_instance
                    ? ' (gebruikt voor ' . ($material->task_instance->effective_title ?: 'taak zonder titel') . ')'
                    : ''),
                'stuksprijs' => (float) $unit_price,
                'aantal' => (float) $quantity,
                'kortingsPercentage' => 0,
                'totaal' => (float) $line_total,
                'extraRegelVelden' => [],
            ];
        }

        if (empty($lines)) {
            return redirect()->back()->with(
                'error',
                'Geen materialen met SnelStart koppeling gevonden voor deze werkbon.'
            );
        }

        $vat_factor = 1.21;
        $delivery_country = $customer->country ? $client->getCountryByIso($customer->country) : null;
        $invoice_country = $customer->postal_country ? $client->getCountryByIso($customer->postal_country) : null;

        $delivery_address = [
            'contactpersoon' => $customer->contactname ?? $customer->name,
            'straat' => $customer->address,
            'postcode' => $customer->postal_code,
            'plaats' => $customer->city,
        ];
        if ($delivery_country) {
            $delivery_address['land'] = [
                'id' => $delivery_country['id'],
                'uri' => '/v2/landen/' . $delivery_country['id'],
            ];
        }

        $invoice_address = [
            'contactpersoon' => $customer->contactname ?? $customer->name,
            'straat' => $customer->postal_address ?: $customer->address,
            'postcode' => $customer->postal_postal_code ?: $customer->postal_code,
            'plaats' => $customer->postal_city ?: $customer->city,
        ];
        if ($invoice_country) {
            $invoice_address['land'] = [
                'id' => $invoice_country['id'],
                'uri' => '/v2/landen/' . $invoice_country['id'],
            ];
        }
        $desc = 'Werkbon ' . $serviceorder->id;
        $payload = [
            'relatie' => [
                'id' => $customer->snelstart_id,
                'uri' => '/v2/relaties/' . $customer->snelstart_id,
            ],
            'procesStatus' => 'Order',
            'datum' => now()->toDateString(),
            'omschrijving' => $desc,
            'orderreferentie' => 'Werkbon ' . $serviceorder->id,
            'memo' => $serviceorder->external_purchaseorder_no,
            'verkooporderBtwIngaveModel' => 'Inclusief',
            'verkoopOrderStatus' => 'InBehandeling',
            'regels' => $lines,
            'totaalExclusiefBtw' => round($total_excl, 2),
            'totaalInclusiefBtw' => round($total_excl * $vat_factor, 2),
            'afleveradres' => $delivery_address,
            'factuuradres' => $invoice_address,
            'extraHoofdVelden' => [],
        ];

        try {
            Log::info('SnelStart verkooporder payload prepared', [
                'omschrijving' => $desc,
                'omschrijving_length' => mb_strlen($desc),
                'regels_count' => count($lines),
            ]);
            $response = $client->post('/verkooporders', $payload);
            $serviceorder->sent_to_administration = true;
            $serviceorder->save();
            Signals::dispatch(new SalesOrderCreatedInSnelStart($serviceorder, $response['id'] ?? null));
            $redirect = redirect()->back()->with(
                'success',
                'Verkooporder aangemaakt in SnelStart (ID: ' . ($response['id'] ?? 'onbekend') . ').'
            );
            $skip_messages = [];
            if (!empty($skipped_null_quantity)) {
                $skip_messages[] = 'Materialen zonder hoeveelheid: ' . implode(', ', $skipped_null_quantity);
            }
            if (!empty($skipped_no_snelstart)) {
                $skip_messages[] = 'Materialen zonder SnelStart ID: ' . implode(', ', $skipped_no_snelstart);
            }
            if (!empty($skip_messages)) {
                $redirect = $redirect->with('error', implode(' | ', $skip_messages));
            }

            return $redirect;
        } catch (\Throwable $e) {
            $model_state_messages = [];
            if (method_exists($e, 'response') && $e->response) {
                try {
                    $json = $e->response->json();
                    if (is_array($json) && isset($json['modelState']) && is_array($json['modelState'])) {
                        foreach ($json['modelState'] as $field => $problems) {
                            if (is_array($problems)) {
                                foreach ($problems as $p) {
                                    $model_state_messages[] = $field . ': ' . $p;
                                }
                            }
                        }
                    }
                } catch (\Throwable $ignore) {
                }
            }
            $combined = $e->getMessage();
            if ($model_state_messages) {
                $combined .= ' | ' . implode(' | ', $model_state_messages);
            }
            Log::error('Fout bij versturen naar SnelStart', [
                'error' => $e->getMessage(),
                'modelState' => $model_state_messages,
            ]);

            return redirect()->back()->with('error', 'Fout bij versturen naar SnelStart: ' . $combined);
        }
    }

    private function generateServiceOrderPdf(ServiceOrder $serviceorder): DompdfPdf
    {
        $serviceorder->load([
            'customer',
            'project',
            'events',
            'events.executingUsers',
            'events.executions',
            'serviceJobs.asset.product.brand',
            'serviceJobs.asset.product.productType',
            'tickets.asset.product.brand',
            'tickets.asset.product.productType',
            'materials.usageUnit',
            'freeformMaterials',
            'taskInstances.serviceOrderTask',
            'taskInstances.materials.usageUnit',
            'taskInstances.freeformMaterials',
            'taskInstances.assets',
            'images',
            'events',
            'project',
            'remarks.user',
        ]);
        $company = Company::where('is_main', true)->first();
        $logo = Company::pdfLogo($company);
        $description_text = trim((string) ($serviceorder->description ?? ''));
        $display_timezone = config('app.display_timezone');
        $first_event = $serviceorder->events->sortBy('start')->first();
        $execution_location = $serviceorder->location_id
            ? $serviceorder->resolved_location
            : ($first_event?->location ?: $serviceorder->resolved_location);
        $planned_date = $first_event
            ? $first_event->start->copy()->setTimezone($display_timezone)
            : $serviceorder->order_date;

        $executing_users = $serviceorder->events
            ->sortBy('start')
            ->flatMap(fn ($event) => $event->executingUsers->map(function ($user) use ($event, $display_timezone) {
                $execution = $event->executions->firstWhere('user_id', $user->id);
                $actual_start = $execution?->actual_start;
                $actual_end = $execution?->actual_end;
                $breaktime_minutes = (int) $user->pivot->breaktime;

                return [
                    'name' => $user->name,
                    'date' => $event->start->copy()->setTimezone($display_timezone),
                    'actual_start' => $actual_start?->copy()->setTimezone($display_timezone),
                    'actual_end' => $actual_end?->copy()->setTimezone($display_timezone),
                    'travel_time_minutes' => (int) ($execution?->travel_time_minutes ?? 0),
                    'breaktime' => $user->pivot->breaktime,
                    'hours' => $actual_start && $actual_end
                        ? round($actual_start->floatDiffInHours($actual_end) - ($breaktime_minutes / 60), 2)
                        : null,
                ];
            }))
            ->values();

        $used_for = fn ($line) => $line->task_instance
            ? ' (gebruikt voor ' . ($line->task_instance->effective_title ?: 'taak zonder titel') . ')'
            : '';

        $map_material = fn ($material) => [
            'quantity' => $material->pivot->quantity,
            'unit' => $material->usageUnit?->name,
            'description' => $material->name . $used_for($material),
            'price' => $material->price,
            'has_price' => true,
        ];
        $map_freeform = fn ($freeform) => [
            'quantity' => $freeform->quantity,
            'unit' => null,
            'description' => $freeform->description . $used_for($freeform),
            'price' => null,
            'has_price' => false,
        ];

        $all_materials = $serviceorder->allMaterials();
        $all_freeform_materials = $serviceorder->allFreeformMaterials();

        $materials_list = $all_materials
            ->reject(fn ($material) => $material->pivot->unforseen)
            ->map($map_material)
            ->concat($all_freeform_materials->reject(fn ($freeform) => $freeform->unforseen)->map($map_freeform))
            ->values();
        $extra_materials_list = $all_materials
            ->filter(fn ($material) => $material->pivot->unforseen)
            ->map($map_material)
            ->concat($all_freeform_materials->filter(fn ($freeform) => $freeform->unforseen)->map($map_freeform))
            ->values();

        $pdf = Pdf::loadView('pdf.serviceorder', [
            'serviceOrder' => $serviceorder,
            'logo' => $logo,
            'descriptionText' => $description_text,
            'plannedDate' => $planned_date,
            'executionLocation' => $execution_location,
            'executingUsers' => $executing_users,
            'tickets' => $serviceorder->tickets,
            'jobs' => $serviceorder->serviceJobs,
            'materialsList' => $materials_list,
            'extraMaterialsList' => $extra_materials_list,
            'taskInstances' => $serviceorder->taskInstances,
            'imageGroups' => $serviceorder->images
                ->sortBy('created_at')
                ->map(function ($image) use ($display_timezone) {
                    $path = storage_path('app/public/' . $image->path);
                    if (!file_exists($path)) {
                        return null;
                    }
                    $mime = mime_content_type($path);
                    [$width, $height] = @getimagesize($path) ?: [1, 1];

                    return [
                        'name' => $image->name,
                        'date' => $image->created_at->copy()->setTimezone($display_timezone)->format('d-m-Y'),
                        'data' => 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path)),
                        'landscape' => ($width ?? 1) >= ($height ?? 1),
                    ];
                })
                ->filter()
                ->groupBy('date')
                ->map(fn ($group) => $group->values()),
            'company' => $company,
            'closingText' => trim((string) GeneralSetting::get('serviceorder_closing_text', '')),
            'remarks' => $serviceorder->remarks,
        ])->setPaper('a4');
        $pdf->getDomPDF()->getOptions()->set('defaultFont', 'Helvetica');

        return $pdf;
    }

    /**
     * Attach a ticket to a service order.
     */
    public function attachTicket(Request $request, ServiceOrder $serviceorder, Ticket $ticket)
    {
        Gate::authorize('attach-to-service-order', $ticket);
        $ticket->update(['service_order_id' => $serviceorder->id]);
        $asset = $ticket->asset()->with(['product.brand', 'product.productType'])->first();
        if ($asset) {
            Signals::dispatch(new TicketAttachedToOrder($serviceorder, $ticket, $asset));
        }

        return redirect()->back()->with('success', 'Ticket succesvol gekoppeld aan de werkbon.');
    }

    /**
     * Detach a ticket from a service order.
     */
    public function detachTicket(
        TicketDetachFromServiceOrderRequest $request,
        ServiceOrder $serviceorder,
        Ticket $ticket
    ) {
        $ticket->update(['service_order_id' => null]);
        $asset = $ticket->asset()->with(['product.brand', 'product.productType'])->first();
        if ($asset) {
            Signals::dispatch(new TicketDetachedFromOrder($serviceorder, $ticket, $asset));
        }

        return redirect()->back()->with('success', 'Ticket succesvol losgekoppeld van de werkbon.');
    }

    /**
     * Attach a material to a service order.
     */
    public function attachMaterial(
        ServiceOrderAttachMaterialRequest $request,
        ServiceOrder $serviceorder,
        Material $material,
        MateriableService $materiables
    ) {
        $materiables->attach($serviceorder, $material, $request->validated());

        return redirect()->back()->with('success', 'Materiaal succesvol gekoppeld aan de werkbon.');
    }

    public function detachMaterial(
        ServiceOrderDetachMaterialRequest $request,
        ServiceOrder $serviceorder,
        string $materiable_id,
        MateriableService $materiables
    ) {
        $materiables->detach($serviceorder, $materiable_id);

        return redirect()->back()
            ->with('success', 'Materiaal succesvol losgekoppeld van de werkbon.');
    }

    public function updateMateriable(
        ServiceOrderUpdateMateriableRequest $request,
        ServiceOrder $serviceorder,
        string $materiable_id,
        MateriableService $materiables
    ) {
        $materiables->update($serviceorder, $materiable_id, $request->validated());

        return redirect()->back()
            ->with('success', 'Materiaal succesvol bijgewerkt.');
    }

    public function bulkUpdate(ServiceOrderBulkUpdateRequest $request)
    {
        $order_ids = $request->input('service_order_ids');
        $stage_id = $request->input('service_order_stage_id');

        /**
         * Every order is moved individually so each one announces its stage change
         * and keeps its history and closing date right. Past a small selection that
         * is too much work for one request, so it goes to the queue and the user is
         * told the list will catch up shortly.
         */
        if (count($order_ids) > self::BULK_STAGE_INLINE_LIMIT) {
            BulkMoveServiceOrderStageJob::dispatch($order_ids, $stage_id, Auth::id());

            return redirect()->back()->with(
                'success',
                count($order_ids) . ' werkbonnen worden op de achtergrond bijgewerkt. '
                    . 'Ververs over een moment om het resultaat te zien.'
            );
        }

        $stage = ServiceOrderStage::find($stage_id);

        ServiceOrder::whereIn('id', $order_ids)
            ->get()
            ->each(fn (ServiceOrder $order) => $order->moveToStage($stage));

        return redirect()->back()->with('success', 'Fase bijgewerkt.');
    }
}
