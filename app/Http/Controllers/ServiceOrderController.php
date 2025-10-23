<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Company;
use App\Models\Activity;
use App\Models\Material;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Enums\ServiceJobOutcomes;
use App\Mail\ServiceOrderPdfMail;
use App\Services\SnelStartClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\PDF as DompdfPdf;
use App\Mail\ServiceOrderWithJobsPdfMail;
use App\Http\Requests\ServiceOrderUpdateRequest;
use App\Http\Requests\ServiceOrderEmailPdfRequest;
use App\Http\Requests\ServiceOrderExportPdfRequest;
use App\Http\Requests\TicketDetachFromServiceOrderRequest;
use App\Http\Requests\ServiceOrderEmailPdfWithChecksRequest;

class ServiceOrderController extends Controller
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
    public function store(Request $request)
    {
        $serviceorder = ServiceOrder::create($request->validate([
            'customer_id' => 'required|exists:customers,id'
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
            };
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
        return inertia('ServiceOrders/ShowPage', [
            'serviceOrder' => ServiceOrder::with([
                'customer.assets.product.brand',
                'customer.assets.product.productType',
                'customer.assets.product.images',
                'servicejobs.asset.product.brand',
                'customer.tickets.asset.product.brand',
                'customer.tickets.asset.product.productType',
                'tickets.asset.product.brand',
                'tickets.asset.product.productType',
                'materials',
                'activities' => function ($q) {
                    $q->orderByDesc('activityables.created_at');
                },
                'remarks.user',
                'events.eventType',
            ])->findOrFail($id),
            'allMaterials' => Material::all()->load([
                'usageUnit',
            ]),
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
        $serviceorder->update($request->validated());
        return redirect()->back()->with('success', 'Werkbon succesvol bijgewerkt.');
    }

    /**
     * Export a PDF of the service order.
     */
    public function exportPdf(ServiceOrderExportPdfRequest $request, ServiceOrder $serviceorder)
    {
        $pdf = $this->generateServiceOrderPdf($serviceorder);
        return $pdf->download('werkbon-' . $serviceorder->id . '.pdf');
    }

    /**
     * Generate PDF and email it to the customer.
     */
    public function emailPdf(ServiceOrderEmailPdfRequest $request, ServiceOrder $serviceorder)
    {
        if ($serviceorder->status !== 'closed') {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je de PDF kunt e-mailen.');
        }
        $serviceorder->load(['customer']);
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
        if (!$serviceorder->sent_to_customer) {
            $serviceorder->sent_to_customer = true;
            $serviceorder->save();
        }

        return redirect()->back()->with('success', 'Werkbon verzonden naar: ' . implode(', ', $recipients));
    }

    public function emailPdfWithJobs(ServiceOrderEmailPdfWithChecksRequest $request, ServiceOrder $serviceorder)
    {
        if ($serviceorder->status !== 'closed') {
            return redirect()->back()->with(
                'error',
                'Sluit de werkbon af voordat je de PDF met keuringen kunt e-mailen.'
            );
        }
        $serviceorder->load(['customer', 'serviceJobs.asset.customer']);
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
        if (!$serviceorder->sent_to_customer) {
            $serviceorder->sent_to_customer = true;
            $serviceorder->save();
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
        if ($serviceorder->status !== 'closed') {
            return redirect()->back()->with('error', 'Sluit de werkbon af voordat je kunt versturen naar SnelStart.');
        }
        $serviceorder->load(['customer.billingCustomer', 'materials']);
        if ($serviceorder->materials->isEmpty()) {
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
        foreach ($serviceorder->materials as $idx => $material) {
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
            \Illuminate\Support\Facades\Log::info('SnelStart verkooporder payload prepared', [
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
            \Illuminate\Support\Facades\Log::error('Fout bij versturen naar SnelStart', [
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
            'serviceJobs.asset.product.brand',
            'serviceJobs.asset.product.productType',
            'tickets.asset.product.brand',
            'tickets.asset.product.productType',
            'materials',
        ]);
        $company = Company::where('is_main', true)->first();
        $logo = Company::pdfLogo($company);
        $description_text = trim((string) ($serviceorder->description ?? ''));
        $pdf = Pdf::loadView('pdf.serviceorder', [
            'serviceOrder' => $serviceorder,
            'logo' => $logo,
            'descriptionText' => $description_text,
            'tickets' => $serviceorder->tickets,
            'jobs' => $serviceorder->serviceJobs,
            'materialsList' => $serviceorder->materials,
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
    public function attachMaterial(Request $request, ServiceOrder $serviceorder, Material $material)
    {
        $serviceorder->materials()->attach($material, [
        'quantity' => $request->input('quantity', 1),
        ]);
        $serviceorder->logActivity(sprintf(
            'Materiaal toegevoegd: %s (aantal %s)',
            $material->name,
            $request->input('quantity', 1)
        ));
        return redirect()->back()->with('success', 'Materiaal succesvol gekoppeld aan de werkbon.');
    }

    public function detachMaterial(ServiceOrder $serviceorder, string $materiable_id)
    {
        $pivotQuery = $serviceorder
            ->materials()
            ->newPivotQuery()
            ->where('materiables.id', $materiable_id);
        $record = $pivotQuery->first();
        $material = null;
        if ($record) {
            $material = Material::find($record->material_id);
        }
        $pivotQuery->delete();
        if ($material) {
            $serviceorder->logActivity(sprintf(
                'Materiaal verwijderd: %s',
                $material->name
            ));
        }

        return redirect()->back()
        ->with('success', 'Materiaal succesvol losgekoppeld van de werkbon.');
    }

    public function updateMateriable(Request $request, ServiceOrder $serviceorder, string $materiable_id)
    {
        $pivotQuery = $serviceorder
            ->materials()
            ->newPivotQuery()
            ->where('materiables.id', $materiable_id);

        $record = $pivotQuery->first();
        $material = null;
        if ($record) {
            $material = Material::find($record->material_id);
        }

        $pivotQuery->update([
            'quantity' => $request->input('quantity', 1),
            'material_role_id' => $request->input('material_role_id', null),
        ]);

        if ($material) {
            $serviceorder->logActivity(sprintf(
                'Materiaal hoeveelheid aangepast: %s naar %s',
                $material->name,
                $request->input('quantity', 1)
            ));
        }

        return redirect()->back()
            ->with('success', 'Materiaal succesvol bijgewerkt.');
    }
}
