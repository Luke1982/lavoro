<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderEventWidgetRequest;
use App\Models\ServiceOrder;
use App\Services\ServiceOrderEventWidget;
use Illuminate\Support\Facades\Auth;

class ServiceOrderEventWidgetController extends Controller
{
    public function show(ServiceOrderEventWidgetRequest $request, ServiceOrder $service_order, ServiceOrderEventWidget $event_widget)
    {
        $service_order->load([
            'events.eventType',
            'events.executingUsers:id,name',
            'events.executions',
        ]);

        return response()->json([
            'events' => $event_widget->events($service_order, Auth::user()),
            'users_missing_times' => $event_widget->usersMissingTimes($service_order),
        ]);
    }
}
