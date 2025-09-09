<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceCheckInstanceUpdateRequest;
use App\Models\ServiceCheckInstance;

class ServiceCheckInstanceController extends Controller
{
    public function update(ServiceCheckInstanceUpdateRequest $request, ServiceCheckInstance $servicecheckinstance)
    {
        $validated = $request->validated();

        $servicecheckinstance->update($validated);
        if (isset($validated['values'])) {
            $servicecheckinstance->values()->sync($validated['values']);
        }
        $servicecheckinstance->load('serviceCheck', 'values');

        return redirect()->back()->with(
            'success',
            'Controlepunt "' . $servicecheckinstance->serviceCheck->name . '" is bijgewerkt'
        );
    }
}
