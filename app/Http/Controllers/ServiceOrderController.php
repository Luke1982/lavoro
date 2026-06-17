<?php

namespace App\Http\Controllers;

use App\Enums\ServiceJobOutcomes;
use App\Http\Requests\ServiceOrderAttachMaterialRequest;
use App\Http\Requests\ServiceOrderBulkUpdateRequest;
use App\Http\Requests\ServiceOrderDetachMaterialRequest;
use App\Http\Requests\ServiceOrderEmailPdfRequest;
use App\Http\Requests\ServiceOrderEmailPdfWithChecksRequest;
use App\Http\Requests\ServiceOrderExportPdfRequest;
use App\Http\Requests\ServiceOrderIndexRequest;
use App\Http\Requests\ServiceOrderUpdateMateriableRequest;
use App\Http\Requests\ServiceOrderUpdateRequest;
use App\Http\Requests\TicketDetachFromServiceOrderRequest;
use App\Mail\ServiceOrderPdfMail;
use App\Mail\ServiceOrderWithJobsPdfMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use App\Models\Product;
use App\Models\Project;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTask;
use App\Models\Ticket;
use App\Services\SnelStartClient;
use App\Traits\ReadsPerPage;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DompdfPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ServiceOrderController extends Controller
{
    use ReadsPerPage;

    /**
     * Display a listing of the resource.
     */
    public function index(ServiceOrderIndexRequest $request)
    {
        $search = $request->get('search', '');
        $only_stages = array_values(array_filter(
            explode(',', (string) $request->get('onlyStage', '')),
            fn($v) => is_numeric($v)
        ));
        $per_page = $this->perPage($request, 25);

        $user = Auth::user();
        $query = ServiceOrder::with(['customer', 'serviceOrderStage']);

        if (! $user->isAdmin() && ! $user->hasPermission('serviceorder.read')) {
            $query->whereHas('executingUsers', fn($q) => $q->where('users.id', $user->id));
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('external_purchaseorder_no', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (count($only_stages)) {
            $query->whereIn('service_order_stage_id', $only_stages);
        }

        return inertia('ServiceOrders/IndexPage', [
            'serviceOrders' => $query->orderByDesc('created_at')->paginate($per_page)->withQueryString(),
            'stages' => ServiceOrderStage::orderBy('order')->get(),
            'search' => $search,
            'onlyStage' => $only_stages,
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
        $serviceorder = ServiceOrder::create($request->validate([
            'customer_id' => 'required|exists:customers,id',
            'project_id' => 'nullable|exists:projects,id',
        ]));
        $redirect = 'back';

        if ($request->has('tickets')) {
            Ticket::whereIn('id', $request->input('tickets'))
                ->whereNull('service_order_id')
                ->get()
                ->map(fn($ticket) => $this->attachTicket($request, $serviceorder, $ticket));
            $redirect = 'serviceorders.show';
        }
        if ($request->has('assets')) {
            foreach ($request->input('assets') as $asset_id) {
                $job = ServiceJob::create([
                    'asset_id' => $asset_id,
                    'service_order_id' => $serviceorder->id,
                    'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value,
                ]);
                $asset = $job->asset()->with(['product.brand', 'product.productType'])->first();
                if ($asset) {
                    $serviceorder->logActivity(sprintf(
                        'Keuring toegevoegd: %s %s %s (serienummer %s)',
                        $asset->product->productType->name ?? 'Onbekend type',
                        $asset->product->brand->name ?? '',
                        $asset->product->model ?? '',
                        $asset->serial_number ?? '-'
                    ));
                }
            }
            $redirect = 'serviceorders.show';
        }

        if ($request->input('json')) {
            return response()->json($serviceorder);
        }

        if ($redirect === 'back' || $request->input('redirect') === false) {
            return redirect()->back()->with('success', 'Werkbon succesvol aangemaakt.');
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
    public function show(string $id)
    {
        $service_order = ServiceOrder::with([
            'customer.assets.product.brand',
            'customer.assets.product.productType',
            'customer.assets.servicejobs:id,asset_id,completed_on',
            'serviceOrderStage',
            'customer.assets.product.images',
            'servicejobs.asset.product.brand',
            'customer.tickets.asset.product.brand',
            'customer.tickets.asset.product.productType',
            'tickets.asset.product.brand',
            'tickets.asset.product.productType',
            'materials.category',
            'materials.usageUnit',
            'activities' => function ($q) {
                $q->with('user:id,name')->orderByDesc('activityables.created_at');
            },
            'remarks.user',
            'events.eventType',
            'events.executingUsers:id,name',
            'customFields',
            'taskInstances.serviceOrderTask',
            'taskInstances.product.brand',
            'taskInstances.product.productType',
            'taskInstances.product.productables.childProduct.brand',
            'taskInstances.product.productables.childProduct.productType',
            'project:id,title',
            'documents',
            'images',
        ])->findOrFail($id);

        $stages = ServiceOrderStage::orderBy('order')
            ->with(['activities' => function ($q) use ($service_order) {
                $q->whereHas('serviceOrders', fn($qq) => $qq->whereKey($service_order->id))
                    ->with('user:id,name')
                    ->orderByDesc('activities.created_at');
            }])
            ->get();
        $first_stage_id = $stages->first()?->id;

        $stages_with_meta = $stages->map(function ($stage) use ($service_order, $first_stage_id) {
            $latest = $stage->activities->first();

            $reached_at = $latest?->created_at;
            $reached_by = $latest?->user?->name;

            if (! $reached_at && $stage->id === $first_stage_id) {
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
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'allMaterials' => ($mc = Material::count()) <= 50
                ? Material::with('usageUnit')->get()
                : collect(),
            'materialsUseAjax' => $mc > 50,
            'materialCategories' => MaterialCategory::orderBy('name')->get(['id', 'name']),
            'materialUsageUnits' => MaterialUsageUnit::orderBy('name')->get(['id', 'name']),
            'customFields' => $service_order->allCustomFieldsWithValues(),
            'stages' => $stages_with_meta,
            'closedStageId' => ServiceOrderStage::where('is_closed_state', true)->value('id'),
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

        $previous_stage_id = $serviceorder->service_order_stage_id;
        $previous_is_closed = $serviceorder->is_closed;
        $previous_customer_id = $serviceorder->customer_id;
        $previous_project_id = $serviceorder->project_id;
        $previous_external_invoice_no = $serviceorder->external_invoice_no;

        $serviceorder->load(['customer', 'project']);
        $previous_customer_name = $serviceorder->customer?->name;
        $previous_project_title = $serviceorder->project?->title;

        $serviceorder->update($data);

        if (
            array_key_exists('external_invoice_no', $data)
            && filled($data['external_invoice_no'])
            && blank($previous_external_invoice_no)
        ) {
            $invoiced_stage = ServiceOrderStage::where('is_invoiced_state', true)->first();
            if ($invoiced_stage && $serviceorder->service_order_stage_id !== $invoiced_stage->id) {
                $serviceorder->service_order_stage_id = $invoiced_stage->id;
                $serviceorder->save();
                $serviceorder->logActivity(
                    "Fase gewijzigd naar: {$invoiced_stage->name} (door extern factuurnummer)",
                    also_attach_to: [$invoiced_stage]
                );
            }
        }

        $serviceorder->load('serviceOrderStage');
        $new_is_closed = $serviceorder->is_closed;

        if ($new_is_closed && ! $previous_is_closed) {
            $serviceorder->closed_on = now();
            $serviceorder->save();
        } elseif (! $new_is_closed && $previous_is_closed) {
            $serviceorder->closed_on = null;
            $serviceorder->save();
        }

        if (
            array_key_exists('service_order_stage_id', $data)
            && $data['service_order_stage_id'] != $previous_stage_id
        ) {
            if ($data['service_order_stage_id'] === null) {
                $serviceorder->logActivity('Fase verwijderd');
            } else {
                $new_stage = $serviceorder->serviceOrderStage;
                if ($new_stage) {
                    $serviceorder->logActivity(
                        "Fase gewijzigd naar: {$new_stage->name}",
                        also_attach_to: [$new_stage]
                    );
                }
            }
        }

        if (array_key_exists('customer_id', $data) && $data['customer_id'] != $previous_customer_id) {
            $new_customer_name = $serviceorder->customer()->value('name');
            $serviceorder->logActivity("Klant gewijzigd van '{$previous_customer_name}' naar '{$new_customer_name}'");
        }

        if (array_key_exists('project_id', $data) && $data['project_id'] != $previous_project_id) {
            if ($data['project_id'] === null) {
                $serviceorder->logActivity("Project losgekoppeld: '{$previous_project_title}'");
            } else {
                $new_project_title = $serviceorder->project()->value('title');
                if ($previous_project_id === null) {
                    $serviceorder->logActivity("Project gekoppeld: '{$new_project_title}'");
                } else {
                    $serviceorder->logActivity(
                        "Project gewijzigd van '{$previous_project_title}' naar '{$new_project_title}'"
                    );
                }
            }
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
        if (! $serviceorder->is_closed) {
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

        $serviceorder->logActivity('Werkbon per e-mail verzonden naar: ' . implode(', ', $recipients));
        // Mark as sent to customer
        if (! $serviceorder->sent_to_customer) {
            $serviceorder->sent_to_customer = true;
            $serviceorder->save();
        }

        return redirect()->back()->with('success', 'Werkbon verzonden naar: ' . implode(', ', $recipients));
    }

    public function emailPdfWithJobs(ServiceOrderEmailPdfWithChecksRequest $request, ServiceOrder $serviceorder)
    {
        $serviceorder->load(['customer', 'serviceJobs.asset.customer', 'serviceOrderStage']);
        if (! $serviceorder->is_closed) {
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
        $serviceorder->logActivity('Werkbon + keuringen per e-mail verzonden naar: ' . implode(', ', $recipients));
        if (! $serviceorder->sent_to_customer) {
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
    public function destroy(ServiceOrder $serviceorder)
    {
        $serviceorder->delete();

        return redirect()->back()->with('success', 'Werkbon succesvol verwijderd.');
    }

    /**
     * Stuur een serviceorder naar SnelStart als verkooporder.
     */
    public function sendToSnelStart(ServiceOrder $serviceorder, SnelStartClient $client)
    {
        if ($serviceorder->sent_to_administration) {
            return redirect()->back()->with('error', 'Deze werkbon is al verzonden naar SnelStart.');
        }
        $serviceorder->load(['customer.billingCustomer', 'materials', 'serviceOrderStage']);
        if (! $serviceorder->is_closed) {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je kunt versturen naar SnelStart.');
        }
        if ($serviceorder->materials->isEmpty()) {
            return redirect()->back()->with('error', 'Geen materialen gekoppeld aan deze werkbon.');
        }

        $customer = $serviceorder->customer;
        $customer = $customer?->billingCustomer ?: $customer;
        if (! $customer || ! $customer->snelstart_id) {
            return redirect()->back()->with('error', 'Klant heeft geen gekoppeld SnelStart ID.');
        }

        $lines = [];
        $total_excl = 0;
        $skipped_null_quantity = [];
        $skipped_no_snelstart = [];
        foreach ($serviceorder->materials as $idx => $material) {
            if (! $material->snelstart_id) {
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
                'omschrijving' => (string) $material->name,
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
            $adminMessage = 'Werkbon naar administratie verzonden (SnelStart verkooporder ID: ' .
                ($response['id'] ?? 'onbekend') . ').';
            $serviceorder->logActivity($adminMessage);
            $redirect = redirect()->back()->with(
                'success',
                'Verkooporder aangemaakt in SnelStart (ID: ' . ($response['id'] ?? 'onbekend') . ').'
            );
            $skip_messages = [];
            if (! empty($skipped_null_quantity)) {
                $skip_messages[] = 'Materialen zonder hoeveelheid: ' . implode(', ', $skipped_null_quantity);
            }
            if (! empty($skipped_no_snelstart)) {
                $skip_messages[] = 'Materialen zonder SnelStart ID: ' . implode(', ', $skipped_no_snelstart);
            }
            if (! empty($skip_messages)) {
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
            'serviceJobs.asset.product.brand',
            'serviceJobs.asset.product.productType',
            'tickets.asset.product.brand',
            'tickets.asset.product.productType',
            'materials.usageUnit',
            'taskInstances.serviceOrderTask',
            'taskInstances.assets',
            'images',
        ]);
        $company = Company::where('is_main', true)->first();
        $logo = Company::pdfLogo($company);
        $description_text = trim((string) ($serviceorder->description ?? ''));
        $planned_event = $serviceorder->events->sortBy('start')->first();
        $planned_date = $planned_event?->start ?? $serviceorder->created_at;
        $execution_location = $serviceorder->execution_location ?: $serviceorder->project?->location;
        $pdf = Pdf::loadView('pdf.serviceorder', [
            'serviceOrder' => $serviceorder,
            'logo' => $logo,
            'descriptionText' => $description_text,
            'plannedDate' => $planned_date,
            'executionLocation' => $execution_location,
            'tickets' => $serviceorder->tickets,
            'jobs' => $serviceorder->serviceJobs,
            'materialsList' => $serviceorder->materials->reject(fn($material) => $material->pivot->unforseen),
            'extraMaterialsList' => $serviceorder->materials->filter(fn($material) => $material->pivot->unforseen),
            'taskInstances' => $serviceorder->taskInstances,
            'images' => $serviceorder->images->map(function ($image) {
                $path = storage_path('app/public/' . $image->path);
                if (! file_exists($path)) {
                    return null;
                }
                $mime = mime_content_type($path);
                [$width, $height] = @getimagesize($path) ?: [1, 1];

                return [
                    'name' => $image->name,
                    'data' => 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path)),
                    'landscape' => ($width ?? 1) >= ($height ?? 1),
                ];
            })->filter()->values(),
            'company' => $company,
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
            $serviceorder->logActivity(sprintf(
                'Ticket gekoppeld: %s (%s %s %s, serienummer %s)',
                $ticket->subject ?? ('Ticket #' . $ticket->id),
                $asset->product->productType->name ?? 'Type',
                $asset->product->brand->name ?? 'Merk',
                $asset->product->model ?? '',
                $asset->serial_number ?? '-'
            ));
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
            $serviceorder->logActivity(sprintf(
                'Ticket losgekoppeld: %s (%s %s %s, serienummer %s)',
                $ticket->subject ?? ('Ticket #' . $ticket->id),
                $asset->product->productType->name ?? 'Type',
                $asset->product->brand->name ?? 'Merk',
                $asset->product->model ?? '',
                $asset->serial_number ?? '-'
            ));
        }

        return redirect()->back()->with('success', 'Ticket succesvol losgekoppeld van de werkbon.');
    }

    /**
     * Attach a material to a service order.
     */
    public function attachMaterial(
        ServiceOrderAttachMaterialRequest $request,
        ServiceOrder $serviceorder,
        Material $material
    ) {
        $validated = $request->validated();
        $serviceorder->materials()->attach($material, [
            'quantity' => $validated['quantity'],
            'unforseen' => $validated['unforseen'] ?? false,
        ]);
        $material->decrement('stock', $validated['quantity']);
        $serviceorder->logActivity(
            sprintf('Materiaal toegevoegd: %s (aantal %s)', $material->name, $validated['quantity']),
            also_attach_to: [$material],
            metadata: [
                'service_order_id' => $serviceorder->id,
                'service_order_number' => $serviceorder->id,
            ]
        );

        return redirect()->back()->with('success', 'Materiaal succesvol gekoppeld aan de werkbon.');
    }

    public function detachMaterial(
        ServiceOrderDetachMaterialRequest $request,
        ServiceOrder $serviceorder,
        string $materiable_id
    ) {
        $pivotQuery = $serviceorder
            ->materials()
            ->newPivotQuery()
            ->where('materiables.id', $materiable_id);

        $record = $pivotQuery->first();
        $material = $record ? Material::find($record->material_id) : null;
        $quantity = $record ? (float) $record->quantity : 0;

        $pivotQuery->delete();

        if ($material) {
            $material->increment('stock', $quantity);
            $serviceorder->logActivity(
                sprintf('Materiaal verwijderd: %s', $material->name),
                also_attach_to: [$material],
                metadata: [
                    'service_order_id' => $serviceorder->id,
                    'service_order_number' => $serviceorder->id,
                ]
            );
        }

        return redirect()->back()
            ->with('success', 'Materiaal succesvol losgekoppeld van de werkbon.');
    }

    public function updateMateriable(
        ServiceOrderUpdateMateriableRequest $request,
        ServiceOrder $serviceorder,
        string $materiable_id
    ) {
        $pivotQuery = $serviceorder
            ->materials()
            ->newPivotQuery()
            ->where('materiables.id', $materiable_id);

        $record = $pivotQuery->first();
        $material = $record ? Material::find($record->material_id) : null;

        $validated = $request->validated();
        $pivotQuery->update($validated);

        if ($material) {
            if (array_key_exists('quantity', $validated)) {
                $old_quantity = $record->quantity !== null ? (float) $record->quantity : null;
                $new_quantity = (float) $validated['quantity'];
                $delta = $old_quantity !== null ? $new_quantity - $old_quantity : null;
                if ($delta !== null && $delta !== 0.0) {
                    $material->decrement('stock', $delta);
                }
                $serviceorder->logActivity(
                    sprintf('Materiaal hoeveelheid aangepast: %s naar %s', $material->name, $validated['quantity']),
                    also_attach_to: [$material],
                    metadata: [
                        'service_order_id' => $serviceorder->id,
                        'service_order_number' => $serviceorder->id,
                    ]
                );
            }
            if (array_key_exists('unforseen', $validated)) {
                $serviceorder->logActivity(sprintf(
                    'Materiaal gemarkeerd als %s: %s',
                    $validated['unforseen'] ? 'onvoorzien' : 'voorzien',
                    $material->name
                ));
            }
        }

        return redirect()->back()
            ->with('success', 'Materiaal succesvol bijgewerkt.');
    }

    public function bulkUpdate(ServiceOrderBulkUpdateRequest $request)
    {
        ServiceOrder::whereIn('id', $request->input('service_order_ids'))
            ->update(['service_order_stage_id' => $request->input('service_order_stage_id')]);

        return redirect()->back()->with('success', 'Fase bijgewerkt.');
    }
}
