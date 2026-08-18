<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\CreateTicketAction;
use App\Actions\Tickets\NewTicket;
use App\Enums\TicketPriorities;
use App\Enums\TicketStatusses;
use App\Http\Requests\TicketBulkUpdateRequest;
use App\Http\Requests\TicketCreateRequest;
use App\Http\Requests\TicketListRequest;
use App\Http\Requests\TicketReadRequest;
use App\Http\Requests\TicketUpdateRequest;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\DocumentCategory;
use App\Models\MaintenanceContract;
use App\Models\NotificationSubscription;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Response;

class TicketController extends Controller
{
    /**
     * List tickets with optional search, status and priority filters.
     *
     * @return Response
     */
    public function index(TicketListRequest $request)
    {
        $data = $request->validated();
        $search = $data['search'] ?? null;
        $statuses_param = $data['statuses'] ?? null;
        $priorities_param = $data['priorities'] ?? null;
        $status_code_search = $data['status_code_search'] ?? null;
        $closed_by_ids_param = $data['closed_by_ids'] ?? null;

        [$status_key_collection, $priority_key_collection, $closed_by_id_collection] = $this->parseFilterParams($data);

        $query = $this->applyTicketFilters(
            Ticket::with([
                'asset.customer',
                'asset.linkedLocation',
                'asset.product.brand',
                'asset.product.productType',
            ]),
            $data
        );

        if ($request->user()->can('viewAny', MaintenanceContract::class)) {
            $contract_columns = [
                'maintenance_contracts.id',
                'maintenance_contracts.customer_id',
                'maintenance_contracts.title',
                'maintenance_contracts.start_date',
                'maintenance_contracts.end_date',
                'maintenance_contracts.cancelled_at',
            ];

            $query->with([
                'asset.maintenanceContracts' => fn ($contracts) => $contracts->active()
                    ->select($contract_columns)
                    ->with('customer:id,name'),
            ]);
        }

        if (!is_string($search) || trim($search) === '') {
            $search = '';
        }

        $appends = ['search' => $search];
        if ($statuses_param) {
            $appends['statuses'] = $statuses_param;
        }
        if ($priorities_param) {
            $appends['priorities'] = $priorities_param;
        }
        if ($status_code_search) {
            $appends['status_code_search'] = $status_code_search;
        }
        if ($closed_by_ids_param) {
            $appends['closed_by_ids'] = $closed_by_ids_param;
        }

        $tickets = $query->orderByDesc('created_at')->paginate(20)->appends($appends);

        foreach ($tickets as $ticket) {
            $this->resolveAssetOwnership($ticket);
        }

        $closed_by_options = User::whereIn(
            'id',
            Ticket::whereNotNull('closed_by_id')->distinct()->pluck('closed_by_id')
        )->orderBy('name')->get(['id', 'name']);

        /**
         * In één keer geteld en tegen de fasen aan gelegd die er zijn, zodat een
         * fase erbij niet stilzwijgend buiten de kaarten valt.
         */
        $counts_per_status = Ticket::query()
            ->selectRaw('status, count(*) as aantal')
            ->groupBy('status')
            ->pluck('aantal', 'status');

        $counts = collect(TicketStatusses::cases())
            ->mapWithKeys(fn (TicketStatusses $status) => [
                $status->name => (int) ($counts_per_status[$status->value] ?? 0),
            ]);

        $average_count = $counts->sum() === 0 ? 0 : $counts->sum() / $counts->count();

        $calc_pct_vs_avg = function ($count, $avg) {
            if ($avg == 0) {
                return $count > 0 ? 100 : 0;
            }

            return round((($count - $avg) / $avg) * 100, 2);
        };

        $open_count = $counts[TicketStatusses::open->name];
        $pending_count = $counts[TicketStatusses::in_behandeling->name];
        $waiting_count = $counts[TicketStatusses::wacht_op_klant->name];
        $closed_count = $counts[TicketStatusses::gesloten->name];

        $open_pct_vs_avg = $calc_pct_vs_avg($open_count, $average_count);
        $pending_pct_vs_avg = $calc_pct_vs_avg($pending_count, $average_count);
        $waiting_pct_vs_avg = $calc_pct_vs_avg($waiting_count, $average_count);
        $closed_pct_vs_avg = $calc_pct_vs_avg($closed_count, $average_count);

        return inertia('Tickets/IndexPage', [
            'tickets' => $tickets,
            'search' => $search,
            'openCount' => $open_count,
            'pendingCount' => $pending_count,
            'waitingCount' => $waiting_count,
            'closedCount' => $closed_count,
            'avgCount' => (int) round($average_count),
            'openPctVsAvg' => $open_pct_vs_avg,
            'pendingPctVsAvg' => $pending_pct_vs_avg,
            'waitingPctVsAvg' => $waiting_pct_vs_avg,
            'closedPctVsAvg' => $closed_pct_vs_avg,
            'activeStatuses' => $status_key_collection->values()->all(),
            'activePriorities' => $priority_key_collection->values()->all(),
            'statusOptions' => TicketStatusses::comboBoxArray(),
            'priorityOptions' => TicketPriorities::comboBoxArray(),
            'activeStatusCodeSearch' => $status_code_search ?? '',
            'activeClosedByIds' => $closed_by_id_collection->values()->all(),
            'closedByOptions' => $closed_by_options,
        ]);
    }

    /**
     * Split the CSV filter params (statuses, priorities, closed_by_ids) into collections.
     */
    private function parseFilterParams(array $data): array
    {
        $status_key_collection = collect(explode(',', (string) ($data['statuses'] ?? '')))
            ->filter(fn ($v) => trim($v) !== '')
            ->unique();
        $priority_key_collection = collect(explode(',', (string) ($data['priorities'] ?? '')))
            ->filter(fn ($v) => trim($v) !== '')
            ->unique();
        $closed_by_id_collection = collect(explode(',', (string) ($data['closed_by_ids'] ?? '')))
            ->filter(fn ($v) => trim($v) !== '')
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique();

        return [$status_key_collection, $priority_key_collection, $closed_by_id_collection];
    }

    /**
     * Apply the search term and status/priority/status_code/closed_by filters to a ticket query.
     * Shared between index() and map() so the two views stay in sync.
     */
    private function applyTicketFilters($query, array $data)
    {
        [$status_key_collection, $priority_key_collection, $closed_by_id_collection] = $this->parseFilterParams($data);

        $status_cases = collect(TicketStatusses::cases())->keyBy(fn ($c) => $c->name);
        $priority_cases = collect(TicketPriorities::cases())->keyBy(fn ($c) => $c->name);

        $active_status_values = $status_key_collection
            ->map(fn ($k) => optional($status_cases->get($k))->value)
            ->filter()
            ->values();
        $active_priority_values = $priority_key_collection
            ->map(fn ($k) => optional($priority_cases->get($k))->value)
            ->filter()
            ->values();

        $search = $data['search'] ?? null;
        $status_code_search = $data['status_code_search'] ?? null;

        if (is_string($search) && trim($search) !== '') {
            $query = $this->applySearch($query, $search);
        }
        if ($active_status_values->isNotEmpty()) {
            $query->whereIn('status', $active_status_values->all());
        }
        if ($active_priority_values->isNotEmpty()) {
            $query->whereIn('priority', $active_priority_values->all());
        }
        if (is_string($status_code_search) && trim($status_code_search) !== '') {
            $query->where('status_code', 'like', '%' . $status_code_search . '%');
        }
        if ($closed_by_id_collection->isNotEmpty()) {
            $query->whereIn('closed_by_id', $closed_by_id_collection->all());
        }

        return $query;
    }

    /**
     * Show all tickets matching the current list filters as markers on a map,
     * grouped by customer (a customer's address holds the coordinates, not the ticket).
     * Closed tickets are excluded: the map is for locating outstanding work, not history.
     *
     * @return Response
     */
    public function map(TicketListRequest $request)
    {
        $data = $request->validated();

        $query = $this->applyTicketFilters(
            Ticket::with('asset.customer', 'asset.linkedLocation')
                ->where('status', '!=', TicketStatusses::gesloten->value),
            $data
        );

        $tickets = $query->get(['id', 'subject', 'priority', 'status', 'asset_id']);

        foreach ($tickets as $ticket) {
            $this->resolveAssetOwnership($ticket);
        }

        $tickets = $tickets->filter(fn ($ticket) => $ticket->asset?->customer !== null)->values();

        $items = $tickets
            ->groupBy(fn ($ticket) => $ticket->asset->linkedLocation
                ? 'loc:' . $ticket->asset->linkedLocation->id
                : 'cust:' . $ticket->asset->customer->id)
            ->map(function ($group) {
                $asset = $group->first()->asset;
                $location = $asset->linkedLocation;
                $customer = $asset->customer;

                return [
                    'type' => $location ? 'location' : 'customer',
                    'id' => $location ? $location->id : $customer->id,
                    'customer_id' => $customer->id,
                    'name' => $location ? ($customer->name . ' — ' . $location->title) : $customer->name,
                    'address' => $location ? $location->address : $customer->address,
                    'postal_code' => $location ? $location->postal_code : $customer->postal_code,
                    'city' => $location ? $location->city : $customer->city,
                    'lat' => $location ? $location->lat : $customer->lat,
                    'lon' => $location ? $location->lon : $customer->lon,
                    'tickets' => $group->map(fn ($ticket) => [
                        'id' => $ticket->id,
                        'subject' => $ticket->subject,
                        'priority' => $ticket->priority,
                        'status' => $ticket->status,
                    ])->values(),
                ];
            })
            ->values();

        return inertia('Tickets/TicketsMap', [
            'items' => $items,
        ]);
    }

    /**
     * Apply multi-word search across subject, product brand/model/type, serial number and customer name.
     * Each word must match at least one of the fields (logical AND across words).
     * Customer name matches by asset tree, not by direct relation, so a ticket on a
     * part asset is still found by the name of the customer its root belongs to.
     */
    private function applySearch($query, ?string $term)
    {
        if (!is_string($term) || trim($term) === '') {
            return $query;
        }
        $words = preg_split('/\s+/', trim($term)) ?: [];
        foreach ($words as $word) {
            $query->where(function ($q) use ($word) {
                $matching_customer_ids = Customer::where('name', 'like', "%{$word}%")->pluck('id')->all();

                $q->where('subject', 'like', "%{$word}%")
                    ->orWhereHas('asset', function ($qa) use ($word) {
                        $qa->where('serial_number', 'like', "%{$word}%");
                    })
                    ->orWhereIn('asset_id', Asset::treeIdsForCustomers($matching_customer_ids))
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
     * A part asset carries no customer or location of its own, so the ticket page
     * would otherwise render against a null customer. Fill both in from the root
     * asset it hangs under, matching Asset::resolvedCustomerId()/resolvedLocationId().
     */
    private function resolveAssetOwnership(Ticket $ticket): void
    {
        $asset = $ticket->asset;
        if (!$asset) {
            return;
        }

        if ($asset->customer === null) {
            $asset->setRelation('customer', $asset->resolvedCustomer());
        }
        if ($asset->linkedLocation === null) {
            $asset->setRelation('linkedLocation', $asset->resolvedLinkedLocation());
        }
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
        $ticket = app(CreateTicketAction::class)->execute(new NewTicket(
            asset_id: (int) $request->validated('asset_id'),
            subject: $request->validated('subject'),
            description: $request->validated('description'),
            status: $request->validated('status'),
            priority: $request->validated('priority'),
            created_by_id: $request->user()->id,
        ));

        return redirect()->back()->with([
            'success' => 'Storing is aangemaakt.',
            'extra' => [
                'ticket' => $ticket,
            ],
        ]);
    }

    /**
     * Show a single ticket with related asset, product and remarks.
     *
     * @return Response
     */
    public function show(TicketReadRequest $request, Ticket $ticket)
    {
        $ticket->load([
            'asset.customer',
            'asset.linkedLocation',
            'asset.product.productType',
            'asset.product.brand',
            'images',
            'customFields',
            'closedBy',
            'createdBy',
            'activities.user',
            'serviceOrder.serviceOrderStage',
            'serviceOrder.events',
            'serviceOrder.executingUsers',
            'documents.category',
            'documents.user:id,name',
        ]);

        $ticket->remarks->load('user');

        $this->resolveAssetOwnership($ticket);

        return inertia('Tickets/ShowPage', [
            'ticket' => $ticket,
            'documentCategories' => DocumentCategory::forPicker(),
            'statusses' => TicketStatusses::comboBoxArray(),
            'priorities' => TicketPriorities::comboBoxArray(),
            'customFields' => $ticket->allCustomFieldsWithValues(),

            /** Null als deze storing niet gevolgd wordt; anders het abonnement om weer uit te zetten. */
            'subscriptionId' => NotificationSubscription::where('user_id', $request->user()->id)
                ->forRecord($ticket)
                ->value('id'),
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

        return redirect()->back()->with([
            'success' => $message,
            'extra' => [
                'ticket' => $ticket,
            ],
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
            ],
        ]);
    }

    public function bulkUpdate(TicketBulkUpdateRequest $request)
    {
        Ticket::whereIn('id', $request->input('ticket_ids'))
            ->update(['status' => $request->input('status')]);

        return redirect()->back()->with('success', 'Status bijgewerkt.');
    }
}
