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
        $highestorder = ServiceCheckValue::where('service_check_id', $request->service_check_id)
            ->max('order') ?? 0;
            $data = $request->validated();
        $data['order'] = $highestorder + 1;
        $newservicecheckvalue = ServiceCheckValue::create($data);

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

    /**
     * Update the order of service check values.
     */
    public function updateOrder(Request $request)
    {
        foreach ($request->payload as $value) {
            ServiceCheckValue::where('id', $value['id'])->update(['order' => $value['order']]);
        }

        return redirect()->back()->with('success', 'Volgorde is bijgewerkt');
    }
}
