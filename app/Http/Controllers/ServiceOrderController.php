<?php

namespace App\Http\Controllers;

use App\Enums\ServiceJobOutcomes;
use App\Models\Ticket;
use App\Models\Material;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use App\Http\Requests\ServiceOrderUpdateRequest;
use App\Models\ServiceJob;
use Barryvdh\DomPDF\Facade\Pdf;

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
                ServiceJob::create([
                    'asset_id' => $asset_id,
                    'service_order_id' => $serviceorder->id,
                    'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value,
                ]);
            };
            $redirect = 'serviceorders.show';
        }

        if ($redirect === 'back' || $request->redirect === false) {
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
            'servicejobs.asset.product.brand',
            'customer.tickets.asset.product.brand',
            'customer.tickets.asset.product.productType',
            'tickets.asset.product.brand',
            'tickets.asset.product.productType',
            'materials',
            'remarks.user'
        ])->findOrFail($id),
        'allMaterials' => Material::all()->load([
            'category',
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
    public function exportPdf(ServiceOrder $serviceorder)
    {
        $serviceorder->load([
            'customer',
            'serviceJobs.asset.product.brand',
            'serviceJobs.asset.product.productType',
            'tickets.asset.product.brand',
            'tickets.asset.product.productType',
            'materials',
        ]);

        $pdf = Pdf::loadView('pdf.serviceorder', [
            'serviceOrder' => $serviceorder,
        ])->setPaper('a4');

        return $pdf->download('werkbon-' . $serviceorder->id . '.pdf');
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
     * Attach a ticket to a service order.
     */
    public function attachTicket(Request $request, ServiceOrder $serviceorder, Ticket $ticket)
    {
        $ticket->update(['service_order_id' => $serviceorder->id]);
        return redirect()->back()->with('success', 'Ticket succesvol gekoppeld aan de werkbon.');
    }

    /**
     * Detach a ticket from a service order.
     */
    public function detachTicket(ServiceOrder $serviceorder, Ticket $ticket)
    {
        $ticket->update(['service_order_id' => null]);
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
        return redirect()->back()->with('success', 'Materiaal succesvol gekoppeld aan de werkbon.');
    }

    public function detachMaterial(ServiceOrder $serviceorder, string $materiable_id)
    {
        $serviceorder
        ->materials()
        ->newPivotQuery()
        ->where('materiables.id', $materiable_id)
        ->delete();

        return redirect()->back()
        ->with('success', 'Materiaal succesvol losgekoppeld van de werkbon.');
    }

    public function updateMateriable(Request $request, ServiceOrder $serviceorder, string $materiable_id)
    {
        $serviceorder
        ->materials()
        ->newPivotQuery()
        ->where('materiables.id', $materiable_id)
        ->update([
            'quantity' => $request->input('quantity', 1),
            'material_role_id' => $request->input('material_role_id', null),
        ]);

        return redirect()->back()
        ->with('success', 'Materiaal succesvol bijgewerkt.');
    }
}
