<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventType;
use Illuminate\Http\Request;

class EventTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return inertia('Events/EventTypesIndexPage', [
            'eventTypes' => EventType::all(),
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
        //
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
    public function update(Request $request, EventType $eventtype)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'color' => 'required|string|max:9',
        ]);

        $eventtype->update($request->all());

        return redirect()->back()->with('success', 'Afspraaktype bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
