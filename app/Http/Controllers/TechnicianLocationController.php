<?php

namespace App\Http\Controllers;

use App\Models\LocationPing;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TechnicianLocationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $latest_pings = LocationPing::query()
            ->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('location_pings')
                    ->where('recorded_at', '>=', now()->subHours(8))
                    ->groupBy('user_id');
            })
            ->with('user:id,name,avatar')
            ->get(['id', 'user_id', 'lat', 'lng', 'accuracy', 'speed', 'heading', 'recorded_at']);

        return Inertia::render('Admin/TechnicianMap', [
            'pings' => $latest_pings,
        ]);
    }
}
