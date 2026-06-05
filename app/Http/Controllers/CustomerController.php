<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatuses;
use App\Http\Requests\CustomerReadRequest;
use App\Http\Requests\CustomerStoreRequest;
use App\Http\Requests\CustomerUpdateCoordsRequest;
use App\Http\Requests\CustomerUpdateRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductableService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
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
            ->orderBy('name')
            ->paginate(25)
            ->appends(['search' => $search]);

        return inertia('Customers/IndexPage', [
            'customers' => $customers,
            'snelStartEnabled' => filled(config('services.snelstart.client_key')),
        ]);
    }

    public function edit(CustomerReadRequest $request, Customer $customer)
    {
        $customer_count = Customer::count();
        $allCustomers = $customer_count <= 50
            ? Customer::select('id', DB::raw("CONCAT_WS(' – ', name, city) as name"))
                ->where('id', '!=', $customer->id)
                ->orderBy('name')
                ->get()
            : collect();

        return inertia('Customers/EditPage', [
            'customer' => $customer,
            'allCustomers' => $allCustomers,
            'customersUseAjax' => $customer_count > 50,
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
            'activeAssets.openTickets',
            'activeAssets.pendingTickets',
            'activeAssets.closedTickets',
            'openTickets',
            'pendingTickets',
            'closedTickets',
            'serviceOrders.serviceJobs.asset.tickets',
            'serviceOrders.events.eventType',
            'serviceOrders.events.executingUsers:id,name',
            'serviceOrders.events.owners:id,name',
            'projects.projectManager',
            'projects.serviceOrders.serviceJobs',
            'customFields',
            'contacts',
        ]);

        $user = Auth::user();
        $has_all = $user->hasPermission('event.view_all');
        if (! $has_all) {
            foreach ($customer->serviceOrders as $order) {
                $order->setRelation('events', $order->events->filter(function ($e) use ($user) {
                    $executing_ids = $e->executingUsers->pluck('id')->all();
                    $owner_ids = $e->owners->pluck('id')->all();

                    return in_array($user->id, $executing_ids) || in_array($user->id, $owner_ids);
                })->values());
            }
        }

        $customer_count = Customer::count();
        $product_count = Product::count();
        $billing_customer = $customer->billing_customer_id
            ? Customer::find($customer->billing_customer_id, ['id', 'name'])
            : null;
        $allCustomers = $customer_count <= 50
            ? Customer::select('id', DB::raw("CONCAT_WS(' – ', name, city) as name"))
                ->orderBy('name')
                ->get()
            : collect($billing_customer ? [['id' => $billing_customer->id, 'name' => $billing_customer->name]] : []);
        $allProducts = $product_count <= 50
            ? Product::with(['brand', 'productType'])->orderBy('model')->get()
            : collect();

        return inertia('Customers/ShowPage', [
            'customer' => $customer,
            'assets' => $customer->activeAssets,
            'allCustomers' => $allCustomers,
            'customersUseAjax' => $customer_count > 50,
            'allProducts' => $allProducts,
            'productsUseAjax' => $product_count > 50,
            'users' => User::canLeadProjects()->orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatuses::comboBoxArray(),
            'customFields' => $customer->allCustomFieldsWithValues(),
            'requiredProductablesByProduct' => ProductableService::requiredProductablesMap(),
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
        $data = $request->sanitized();

        if (empty($data['snelstart_id'] ?? null)) {
            $data['snelstart_id'] = (string) Str::uuid();
        }

        $customer = Customer::create($data);

        return redirect()->route('customers.index')->with('success', 'Klant aangemaakt.');
    }

    public function updateCoords(CustomerUpdateCoordsRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return response()->json(['ok' => true]);
    }
}
