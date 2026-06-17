<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventReadRequest;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\ServiceOrder;
use App\Models\User;

class EventController extends Controller
{
    public function index(EventReadRequest $request)
    {
        $customer_count = Customer::count();

        return inertia('Events/EventsIndexPage', [
            'eventTypes' => EventType::all(),
            'eventStatusses' => Event::statusses(),
            'noPadding' => true,
            'allCustomers' => $customer_count <= 50
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect(),
            'customersUseAjax' => $customer_count > 50,
            'allServiceOrders' => ServiceOrder::with('customer')->get(),
            'allUsers' => User::select('id', 'name')->get(),
        ]);
    }

    public function show(Event $event)
    {
        $event->load(['serviceOrders']);

        return redirect()->route('serviceorders.show', $event->serviceOrders->first());
    }
}
