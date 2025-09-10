<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriorities;
use App\Enums\TicketStatusses;
use App\Models\Ticket;
use App\Http\Requests\TicketCreateRequest;
use App\Http\Requests\TicketUpdateRequest;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = Ticket::with([
            'asset.customer',
            'asset.product.brand',
            'asset.product.productType',
        ]);
        if (is_string($search) && trim($search) !== '') {
            $query = $this->applySearch($query, $search);
        } else {
            // Normalize to empty string so paginator appends consistent param when needed
            $search = '';
        }

        $tickets = $query
            ->orderByDesc('created_at')
            ->paginate(20)
            ->appends(['search' => $search]);

        return inertia('Tickets/IndexPage', [
            'tickets'      => $tickets,
            'search'       => $search,
            'openCount'    => Ticket::where('status', TicketStatusses::open->value)->count(),
            'pendingCount' => Ticket::where('status', TicketStatusses::in_behandeling->value)->count(),
            'closedCount'  => Ticket::where('status', TicketStatusses::gesloten->value)->count(),
        ]);
    }

    /**
     * Apply multi-word search across subject, product brand/model/type, serial number and customer name.
     * Each word must match at least one of the fields (logical AND across words).
     */
    private function applySearch($query, ?string $term)
    {
        if (!is_string($term) || trim($term) === '') {
            return $query;
        }
        $words = preg_split('/\s+/', trim($term)) ?: [];
        foreach ($words as $word) {
            $query->where(function ($q) use ($word) {
                $q->where('subject', 'like', "%{$word}%")
                    ->orWhereHas('asset', function ($qa) use ($word) {
                        $qa->where('serial_number', 'like', "%{$word}%");
                    })
                    ->orWhereHas('asset.customer', function ($qc) use ($word) {
                        $qc->where('name', 'like', "%{$word}%");
                    })
                    ->orWhereHas('asset.product', function ($qp) use ($word) {
                        $qp->where('model', 'like', "%{$word}%")
                            ->orWhereHas('brand', function ($qb) use ($word) {
                                $qb->where('name', 'like', "%{$word}%");
                            })
                            ->orWhereHas('productType', function ($qt) use ($word) {
                                $qt->where('name', 'like', "%{$word}%");
                            });
                    });
            });
        }
        return $query;
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
            $message = sprintf(
                "Storing is bijgewerkt, de status is nu '%s' en de prioriteit is '%s'.",
                $request->status,
                $request->priority
            );

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
