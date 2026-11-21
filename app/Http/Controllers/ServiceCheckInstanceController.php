<?php

namespace App\Http\Controllers;

use App\Domain\Signals\ServiceOrders\ServiceCheckResultChanged;
use App\Domain\Signals\Signals;
use App\Http\Requests\ServiceCheckInstanceUpdateRequest;
use App\Models\ServiceCheckInstance;

class ServiceCheckInstanceController extends Controller
{
    public function update(ServiceCheckInstanceUpdateRequest $request, ServiceCheckInstance $servicecheckinstance)
    {
        $validated = $request->validated();
        $before = $servicecheckinstance->resultSnapshot();

        $servicecheckinstance->update($validated);
        if (isset($validated['values'])) {
            $servicecheckinstance->values()->sync($validated['values']);
        }
        $servicecheckinstance->load('serviceCheck', 'values');

        Signals::dispatch(new ServiceCheckResultChanged(
            $servicecheckinstance,
            $before,
            $servicecheckinstance->resultSnapshot(),
        ));

        return redirect()->back()->with(
            'success',
            'Controlepunt "' . $servicecheckinstance->serviceCheck->name . '" is bijgewerkt'
        );
    }
}
