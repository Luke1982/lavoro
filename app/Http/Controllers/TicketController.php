<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriorities;
use App\Enums\TicketStatusses;
use App\Models\Ticket;
use App\Http\Requests\TicketCreateRequest;
use App\Http\Requests\TicketUpdateRequest;
use App\Http\Requests\TicketReadRequest;

class TicketController extends Controller
{
    /**
     * List tickets with optional search, status and priority filters.
     *
     * @param TicketReadRequest $request
     * @return \Inertia\Response
     */
    public function index(TicketReadRequest $request)
    {
        $data = method_exists($request, 'validated') ? $request->validated() : [];
        $search = $data['search'] ?? null;
        $statuses_param   = $data['statuses'] ?? null;
        $priorities_param = $data['priorities'] ?? null;

        $status_key_collection = collect(explode(',', (string)$statuses_param))
            ->filter(fn($v) => trim($v) !== '')
            ->unique();
        $priority_key_collection = collect(explode(',', (string)$priorities_param))
            ->filter(fn($v) => trim($v) !== '')
            ->unique();

        $status_cases   = collect(TicketStatusses::cases())->keyBy(fn($c) => $c->name);
        $priority_cases = collect(TicketPriorities::cases())->keyBy(fn($c) => $c->name);

        $active_status_values = $status_key_collection
            ->map(fn($k) => optional($status_cases->get($k))->value)
            ->filter()
            ->values();
        $active_priority_values = $priority_key_collection
            ->map(fn($k) => optional($priority_cases->get($k))->value)
            ->filter()
            ->values();

        $query = Ticket::with([
            'asset.customer',
            'asset.product.brand',
            'asset.product.productType',
        ]);
        if (is_string($search) && trim($search) !== '') {
            $query = $this->applySearch($query, $search);
        } else {
            $search = '';
        }

        if ($active_status_values->isNotEmpty()) {
            $query->whereIn('status', $active_status_values->all());
        }
        if ($active_priority_values->isNotEmpty()) {
            $query->whereIn('priority', $active_priority_values->all());
        }

        $appends = ['search' => $search];
        if ($statuses_param) {
            $appends['statuses'] = $statuses_param;
        }
        if ($priorities_param) {
            $appends['priorities'] = $priorities_param;
        }

        $tickets = $query->orderByDesc('created_at')->paginate(20)->appends($appends);

        $open_count    = Ticket::where('status', TicketStatusses::open->value)->count();
        $pending_count = Ticket::where('status', TicketStatusses::in_behandeling->value)->count();
        $closed_count  = Ticket::where('status', TicketStatusses::gesloten->value)->count();

        $total_count = $open_count + $pending_count + $closed_count;
        $average_count = $total_count === 0 ? 0 : $total_count / 3;

        $calc_pct_vs_avg = function ($count, $avg) {
            if ($avg == 0) {
                return $count > 0 ? 100 : 0;
            }
            return round((($count - $avg) / $avg) * 100, 2);
        };

        $open_pct_vs_avg    = $calc_pct_vs_avg($open_count, $average_count);
        $pending_pct_vs_avg = $calc_pct_vs_avg($pending_count, $average_count);
        $closed_pct_vs_avg  = $calc_pct_vs_avg($closed_count, $average_count);

        return inertia('Tickets/IndexPage', [
            'tickets'               => $tickets,
            'search'                => $search,
            'openCount'             => $open_count,
            'pendingCount'          => $pending_count,
            'closedCount'           => $closed_count,
            'avgCount'              => (int) round($average_count),
            'openPctVsAvg'          => $open_pct_vs_avg,
            'pendingPctVsAvg'       => $pending_pct_vs_avg,
            'closedPctVsAvg'        => $closed_pct_vs_avg,
            'activeStatuses'    => $status_key_collection->values()->all(),
            'activePriorities'  => $priority_key_collection->values()->all(),
            'statusOptions'     => TicketStatusses::comboBoxArray(),
            'priorityOptions'   => TicketPriorities::comboBoxArray(),
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
     * Show a single ticket with related asset, product and remarks.
     *
     * @param TicketReadRequest $request
     * @param Ticket $ticket
     * @return \Inertia\Response
     */
    public function show(TicketReadRequest $request, Ticket $ticket)
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
