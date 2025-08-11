<?php

namespace App\Http\Controllers;

use App\Models\Event;

class EventController extends Controller
{
    public function index()
    {
        return inertia('Events/EventsIndexPage', [
            'events' => Event::with('type')->get(),
        ]);
    }
}
