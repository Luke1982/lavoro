<?php

namespace App\Http\Controllers;

use App\Actions\ServiceOrders\RunServiceOrderMacroAction;
use App\Http\Requests\ServiceOrderMacroRunRequest;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;

class ServiceOrderMacroRunController extends Controller
{
    public function store(
        ServiceOrderMacroRunRequest $request,
        ServiceOrder $serviceorder,
        ServiceOrderMacro $serviceordermacro,
        RunServiceOrderMacroAction $run,
    ) {
        $run->execute($serviceorder, $serviceordermacro, $request->macroRun());

        return redirect()->back()->with('success', 'Macro "' . $serviceordermacro->title . '" uitgevoerd.');
    }
}
