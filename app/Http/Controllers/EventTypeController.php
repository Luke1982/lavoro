<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventTypeDestroyRequest;
use App\Http\Requests\EventTypeReadRequest;
use App\Http\Requests\EventTypeStoreUpdateRequest;
use App\Models\EventType;

class EventTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(EventTypeReadRequest $request)
    {
        return inertia('Events/EventTypesIndexPage', [
            'eventTypes' => EventType::all(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(EventTypeStoreUpdateRequest $request)
    {
        EventType::create($request->validated());

        return redirect()->back()->with('success', 'Afspraaktype aangemaakt.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EventTypeStoreUpdateRequest $request, EventType $eventtype)
    {
        $eventtype->update($request->validated());

        return redirect()->back()->with('success', 'Afspraaktype bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EventTypeDestroyRequest $request, EventType $eventtype)
    {
        $eventtype->delete();

        return redirect()->back();
    }
}
