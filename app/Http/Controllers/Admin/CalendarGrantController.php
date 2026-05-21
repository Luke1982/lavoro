<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarGrantDestroyRequest;
use App\Http\Requests\CalendarGrantStoreRequest;
use App\Models\CalendarGrant;
use App\Models\User;
use App\Services\Google\GrantSyncService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CalendarGrantController extends Controller
{
    public function index(): Response
    {
        $users = User::orderBy('name')->get(['id', 'name']);
        $grants = CalendarGrant::with(['ownerUser:id,name', 'viewerUser:id,name'])->get();
        return Inertia::render('Admin/CalendarGrants/IndexPage', [
            'users' => $users,
            'grants' => $grants,
        ]);
    }

    public function store(CalendarGrantStoreRequest $request, GrantSyncService $grant_service): RedirectResponse
    {
        $data = $request->validated();
        $grant = CalendarGrant::firstOrCreate([
            'owner_user_id' => $data['owner_user_id'],
            'viewer_user_id' => $data['viewer_user_id'],
        ]);
        if ($grant->wasRecentlyCreated) {
            $grant_service->onGrantCreated($grant);
        }
        return redirect()->back()->with('success', 'Toegang verleend.');
    }

    public function destroy(CalendarGrantDestroyRequest $request, CalendarGrant $calendar_grant, GrantSyncService $grant_service): RedirectResponse
    {
        $grant_service->onGrantRevoked($calendar_grant);
        $calendar_grant->delete();
        return redirect()->back()->with('success', 'Toegang ingetrokken.');
    }
}
