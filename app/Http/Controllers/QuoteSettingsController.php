<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteSettingsReadRequest;
use App\Http\Requests\QuoteUnitDestroyRequest;
use App\Http\Requests\QuoteUnitsOrderRequest;
use App\Http\Requests\QuoteUnitStoreRequest;
use App\Http\Requests\QuoteUnitUpdateRequest;
use App\Models\QuoteUnit;

/** The settings that hold for every quote, chapter by chapter. The first chapter is the units. */
class QuoteSettingsController extends Controller
{
    public function index(QuoteSettingsReadRequest $request)
    {
        return inertia('Quotes/SettingsPage', [
            'units' => QuoteUnit::orderBy('position')->get(['id', 'name']),
        ]);
    }

    public function storeUnit(QuoteUnitStoreRequest $request)
    {
        QuoteUnit::create($request->validated());

        return redirect()->back();
    }

    public function updateUnit(QuoteUnitUpdateRequest $request, QuoteUnit $unit)
    {
        $unit->update($request->validated());

        return redirect()->back();
    }

    public function destroyUnit(QuoteUnitDestroyRequest $request, QuoteUnit $unit)
    {
        $unit->delete();

        return redirect()->back();
    }

    public function orderUnits(QuoteUnitsOrderRequest $request)
    {
        QuoteUnit::putInOrder(array_map('intval', $request->validated('unit_ids')));

        return redirect()->back();
    }
}
