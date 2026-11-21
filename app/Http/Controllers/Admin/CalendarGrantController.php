<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Users\CalendarAccessGranted;
use App\Domain\Signals\Users\CalendarAccessRevoked;
use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarGrantDestroyRequest;
use App\Http\Requests\CalendarGrantStoreRequest;
use App\Models\CalendarGrant;
use App\Models\User;
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

    /** The calendar in Google is made by a listener, after the grant is saved. */
    public function store(CalendarGrantStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $grant = CalendarGrant::firstOrCreate([
            'owner_user_id' => $data['owner_user_id'],
            'viewer_user_id' => $data['viewer_user_id'],
        ]);
        if ($grant->wasRecentlyCreated) {
            Signals::dispatch(new CalendarAccessGranted($grant));
        }

        return redirect()->back()->with('success', 'Toegang verleend.');
    }

    /**
     * The calendar in Google is taken away by a listener, after the grant is
     * gone. The signal is made first, so a grant is never deleted without it.
     */
    public function destroy(CalendarGrantDestroyRequest $request, CalendarGrant $calendar_grant): RedirectResponse
    {
        $signal = new CalendarAccessRevoked($calendar_grant);
        $calendar_grant->delete();
        Signals::dispatch($signal);

        return redirect()->back()->with('success', 'Toegang ingetrokken.');
    }
}
