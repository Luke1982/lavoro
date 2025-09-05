<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MaterialUsageUnit;

class MaterialUsageUnitController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return inertia('Materials/UsageUnitIndexPage', [
            'usageUnits' => MaterialUsageUnit::all(),
        ]);
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
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $unit = MaterialUsageUnit::create($data);

        return redirect()->back()
            ->with('success', 'Materiaal gebruikseenheid is aangemaakt.')
            ->with('extra', $unit);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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
    public function update(Request $request, MaterialUsageUnit $materialusageunit)
    {
        $materialusageunit->update($request->validate([
            'name' => 'required|string|max:255',
        ]));

        return redirect()->back()->with('success', 'Materiaal eenheid is bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MaterialUsageUnit $materialusageunit)
    {
        $materialusageunit->delete();
        return redirect()->back();
    }
}
