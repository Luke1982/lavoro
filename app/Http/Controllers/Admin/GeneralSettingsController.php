<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateLocationTrackingSettingsRequest;
use App\Models\GeneralSetting;
use Inertia\Inertia;

class GeneralSettingsController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Admin/GeneralSettingsPage', [
            'locationTracking' => [
                'start' => GeneralSetting::get('location_tracking_start', '07:00'),
                'end'   => GeneralSetting::get('location_tracking_end', '18:00'),
                'days'  => array_map('intval', explode(',', GeneralSetting::get('location_tracking_days', '1,2,3,4,5'))),
            ],
        ]);
    }

    public function updateLocationTracking(UpdateLocationTrackingSettingsRequest $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validated();

        GeneralSetting::set('location_tracking_start', $data['start']);
        GeneralSetting::set('location_tracking_end', $data['end']);
        GeneralSetting::set('location_tracking_days', implode(',', $data['days']));

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }
}
