<?php

namespace App\Http\Controllers;

use App\Models\ServiceCheckValue;
use Illuminate\Http\Request;

class ServiceCheckValueController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ServiceCheckValue $servicecheckvalue)
    {
        $validated = $request->validate([
            'value' => 'required|string|max:255',
            'service_check_id' => 'required|exists:service_checks,id',
        ]);

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
