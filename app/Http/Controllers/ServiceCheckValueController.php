<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceCheckValueStoreUpdateRequest;
use App\Models\ServiceCheckValue;
use Illuminate\Http\Request;

class ServiceCheckValueController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(ServiceCheckValueStoreUpdateRequest $request)
    {
        $newservicecheckvalue = ServiceCheckValue::create($request->validated());

        return redirect()->back()->with(['success' => 'Waarde is toegevoegd', 'extra' => $newservicecheckvalue]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ServiceCheckValueStoreUpdateRequest $request, ServiceCheckValue $servicecheckvalue)
    {
        $validated = $request->validated();

        $servicecheckvalue->update($validated);

        return redirect()->back()->with('success', 'Waarde is bijgewerkt');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceCheckValue $servicecheckvalue)
    {
        $servicecheckvalue->delete();

        return redirect()->back()->with('success', 'Waarde is verwijderd');
    }
}
