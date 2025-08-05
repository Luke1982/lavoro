<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriorities;
use App\Enums\TicketStatusses;
use App\Models\Ticket;
use App\Http\Requests\TicketCreateRequest;
use App\Http\Requests\TicketUpdateRequest;

class TicketController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(TicketCreateRequest $request)
    {
        $ticket = Ticket::create($request->validated());
        return redirect()->back()->with([
            'success' => 'Storing is aangemaakt.',
            'extra' => [
                'ticket' => $ticket,
            ]
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Ticket $ticket)
    {
        $ticket->load(['asset.customer', 'asset.product.productType', 'asset.product.brand', 'images']);
        return inertia('Tickets/ShowPage', [
            'ticket' => $ticket,
            'statusses' => TicketStatusses::comboBoxArray(),
            'priorities' => TicketPriorities::comboBoxArray(),
            'remarks' => $ticket->remarks->load('user'),
        ]);
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
    public function update(TicketUpdateRequest $request, Ticket $ticket)
    {
        $ticket->update($request->validated());
        $message = 'Storing is bijgewerkt, de status is nu \'' . $request->status . '\' en de prioriteit is ' . '\'' . $request->priority . '\'.';

        if ($request->status && strtolower($request->status) === 'gesloten') {
            $ticket->update(['closed_on' => now()]);
        } elseif ($request->status && strtolower($request->status) !== 'gesloten') {
            $ticket->update(['closed_on' => null]);
        }

        return redirect()->back()->with([
            'success' => $message,
            'extra' => [
                'ticket' => $ticket,
            ]
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ticket $ticket)
    {
        $ticket->delete();
        return redirect()->back()->with([
            'success' => 'Storing is verwijderd.',
            'extra' => [
                'ticket' => $ticket,
            ]
        ]);
    }
}
