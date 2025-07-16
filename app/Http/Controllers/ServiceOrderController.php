<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderUpdateRequest;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;

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
                'servicejobs.asset.product.brand',
            ])->findOrFail($id),
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
}
