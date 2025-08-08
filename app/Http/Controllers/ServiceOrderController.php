<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Material;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use App\Http\Requests\ServiceOrderUpdateRequest;

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
        ServiceOrder::create($request->validate([
            'customer_id' => 'required|exists:customers,id'
        ]));
        return redirect()->back()->with('success', 'Werkbon succesvol aangemaakt.');
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
