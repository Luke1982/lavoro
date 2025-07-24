<?php

namespace App\Http\Controllers;

use App\Models\ServiceJob;
use Illuminate\Http\Request;
use App\Enums\ServiceCheckTypes;
use App\Http\Requests\ServiceJobCreateRequest;

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
        ServiceJob::create($request->validated());
        return redirect()->back()->with('success', 'Keuring succesvol aangemaakt.');
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceJob $servicejob)
    {
        return inertia('ServiceJob/ShowPage', [
            'servicejob' => $servicejob->load([
                'asset.product.productType.checks',
                'checkInstances.serviceCheck.values',
                'checkInstances.serviceCheckValue',
                'asset.product.brand',
                'asset.customer',
                'serviceOrder',
            ]),
            'checkTypesWithOptions' => array_keys(ServiceCheckTypes::getTypesWithOptions()),
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
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceJob $servicejob)
    {
        $servicejob->delete();
        return redirect()->back()->with('success', 'Keuring succesvol verwijderd.');
    }
}
