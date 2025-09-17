<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Customer;
use App\Http\Requests\EventReadRequest;
use App\Models\EventType;
use App\Models\ServiceOrder;

class EventController extends Controller
{
    public function index(EventReadRequest $request)
    {
        return inertia('Events/EventsIndexPage', [
            'eventTypes' => EventType::all(),
            'eventStatusses' => Event::statusses(),
            'noPadding' => true,
            'allCustomers' => Customer::all(),
            'allServiceOrders' => ServiceOrder::with('customer')->get(),
        ]);
    }
}
