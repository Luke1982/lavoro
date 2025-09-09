<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ServiceCheckInstance;

class ServiceCheckInstanceController extends Controller
{
    public function update(Request $request, ServiceCheckInstance $servicecheckinstance)
    {
        $validated = $request->validate([
            'values'       => 'nullable',
            'description'  => 'nullable|string|max:255',
            'switch_state' => 'nullable|boolean',
        ]);

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
