<?php

namespace App\Http\Controllers;

use App\Actions\Customers\CreateCustomerAction;
use App\Domain\Integrations\Area;
use App\Enums\ContractInterval;
use App\Enums\ProjectStatuses;
use App\Http\Requests\CustomerReadRequest;
use App\Http\Requests\CustomerStoreRequest;
use App\Http\Requests\CustomerUpdateCoordsRequest;
use App\Http\Requests\CustomerUpdateRequest;
use App\Models\AssistantFilter;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\Project;
use App\Models\ServiceOrderStage;
use App\Models\User;
use App\Support\NewMachineForm;
use App\Traits\OffersContractTemplates;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    use OffersContractTemplates;

    /**
     * A long-standing customer's history keeps growing with every werkbon done for
     * them; the page shows the latest stretch of it, the assistant searches all of it.
     */
    private const HISTORY_SHOWN = 100;

    /**
     * Display a listing of the resource.
     */
    public function index(CustomerReadRequest $request)
    {
        $search = $request->input('search');
        $customers = Customer::withCount([
            'tickets as open_tickets_count' => fn ($q) => $q->where('tickets.status', 'Open'),
            'tickets as pending_tickets_count' => fn ($q) => $q->where('tickets.status', 'In behandeling'),
            'tickets as closed_tickets_count' => fn ($q) => $q->where('tickets.status', 'Gesloten'),
        ])
            ->when($search !== null && $search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('invoice_email', 'like', "%{$search}%")
                ->orWhere('quotes_email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")
                ->orWhere('website', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")
                ->orWhere('postal_code', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('country', 'like', "%{$search}%")
                ->orWhere('postal_address', 'like', "%{$search}%")
                ->orWhere('postal_postal_code', 'like', "%{$search}%")
                ->orWhere('postal_city', 'like', "%{$search}%")
                ->orWhere('postal_country', 'like', "%{$search}%")
                ->orWhere('location_code', 'like', "%{$search}%")))
            ->tap(AssistantFilter::from($request))
            ->orderBy('name')
            ->paginate(25)
            ->appends(['search' => $search])
            ->withQueryString();

        return inertia('Customers/IndexPage', [
            'customers' => $customers,
            'pullSources' => IntegrationConnection::pullButtons(Area::Customers, $request->user()),
        ]);
    }

    public function edit(CustomerReadRequest $request, Customer $customer)
    {
        $customer_count = Customer::count();
        $allCustomers = $customer_count <= Customer::COMBO_AJAX_THRESHOLD
            ? Customer::select('id', DB::raw("CONCAT_WS(' – ', name, city) as name"))
                ->where('id', '!=', $customer->id)
                ->orderBy('name')
                ->get()
            : collect();

        if ($request->user()->can('seeSensitive', Customer::class)) {
            $customer->makeVisible(Customer::SENSITIVE_ATTRIBUTES);
        }

        return inertia('Customers/EditPage', [
            'customer' => $customer,
            'allCustomers' => $allCustomers,
            'customersUseAjax' => $customer_count > Customer::COMBO_AJAX_THRESHOLD,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(CustomerReadRequest $request, Customer $customer)
    {
        $customer->load([
            'activeAssets.product.brand',
            'activeAssets.product.productType',
            'activeAssets.linkedLocation',
            'activeAssets.childAssets.product.brand',
            'activeAssets.childAssets.product.productType',
            'activeAssets.openTickets',
            'activeAssets.pendingTickets',
            'activeAssets.closedTickets',
            'openTickets',
            'pendingTickets',
            'closedTickets',
            'serviceOrders.customer:id,name,city',
            'serviceOrders.executingUsers:id,name',
            'serviceOrders.taskInstances:id,service_order_id,is_complete,is_cancelled',
            'serviceOrders.events' => fn ($q) => $q->visibleTo($request->user()),
            'serviceOrders.events.eventType',
            'serviceOrders.events.executingUsers:id,name',
            'serviceOrders.events.owners:id,name',
            'projects.projectManager',
            'projects.serviceOrders.customer:id,name,city',
            ...Project::relationsForAddress('projects.serviceOrders.project'),
            'projects.serviceOrders.executingUsers:id,name',
            'projects.serviceOrders.taskInstances:id,service_order_id,is_complete,is_cancelled',
            'projects.serviceOrders.events' => fn ($q) => $q->visibleTo($request->user()),
            'projects.serviceOrders.events.executingUsers:id,name',
            'projects.serviceOrders.events.owners:id,name',
            'customFields',
            'contacts',
            'maintenanceContracts',
            'locations' => fn ($q) => $q->withCount(['assets', 'serviceOrders', 'events']),
            'activities' => fn ($q) => $q->visibleTo($request->user())
                ->with(['user:id,name', 'fieldChanges'])
                ->orderByDesc('activityables.created_at')
                ->limit(self::HISTORY_SHOWN),
        ]);

        if ($request->user()->can('seeSensitive', Customer::class)) {
            $customer->makeVisible(Customer::SENSITIVE_ATTRIBUTES);
        }

        $customer_count = Customer::count();
        $billing_customer = $customer->billing_customer_id
            ? Customer::find($customer->billing_customer_id, ['id', 'name'])
            : null;
        $allCustomers = $customer_count <= Customer::COMBO_AJAX_THRESHOLD
            ? Customer::select('id', DB::raw("CONCAT_WS(' – ', name, city) as name"))
                ->orderBy('name')
                ->get()
            : collect($billing_customer ? [['id' => $billing_customer->id, 'name' => $billing_customer->name]] : []);

        return inertia('Customers/ShowPage', [
            'customer' => $customer,
            'assets' => $customer->activeAssets,
            'allCustomers' => $allCustomers,
            'customersUseAjax' => $customer_count > Customer::COMBO_AJAX_THRESHOLD,
            ...NewMachineForm::propsFor($request->user()),
            'users' => User::canLeadProjects()->orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatuses::comboBoxArray(),
            'customFields' => $customer->allCustomFieldsWithValues(),
            'contractIntervalOptions' => ContractInterval::comboBoxArray(),
            'contractTemplates' => $this->templatesFor($request),
            'serviceOrderStages' => ServiceOrderStage::orderBy('order')->get(),
            'historyShown' => self::HISTORY_SHOWN,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CustomerUpdateRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return redirect()->route('customers.show', $customer)->with('success', 'Klant bijgewerkt.');
    }

    /**
     * Store a newly created customer.
     */
    public function store(CustomerStoreRequest $request)
    {
        $customer = app(CreateCustomerAction::class)->execute($request->sanitized());

        if ($request->wantsJson()) {
            return response()->json($customer->toComboOption(), 201);
        }

        /** Net als bij een storing reist het nieuwe record mee terug. */
        return redirect()->back()->with([
            'success' => 'Klant aangemaakt.',
            'extra' => ['customer' => $customer],
        ]);
    }

    public function updateCoords(CustomerUpdateCoordsRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return response()->json(['ok' => true]);
    }
}
