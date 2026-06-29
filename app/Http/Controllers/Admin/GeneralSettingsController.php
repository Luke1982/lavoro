<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAllowOverrideUnavailabilityRequest;
use App\Http\Requests\Admin\UpdateLocationTrackingSettingsRequest;
use App\Http\Requests\Admin\UpdateServiceOrderClosingTextRequest;
use App\Http\Requests\Admin\UpdateServiceOrderMinImagesRequest;
use App\Models\GeneralSetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GeneralSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/GeneralSettingsPage', [
            'locationTracking' => [
                'start' => GeneralSetting::get('location_tracking_start', '07:00'),
                'end'   => GeneralSetting::get('location_tracking_end', '18:00'),
                'days'  => array_map(
                    'intval',
                    explode(',', GeneralSetting::get('location_tracking_days', '1,2,3,4,5'))
                ),
            ],
            'serviceOrderClosingText'     => GeneralSetting::get('serviceorder_closing_text', ''),
            'allowOverrideUnavailability' => GeneralSetting::get('allow_override_unavailability', '0') === '1',
            'serviceOrderMinImages'       => (int) GeneralSetting::get('serviceorder_min_images', 0),
        ]);
    }

    public function updateServiceOrderClosingText(UpdateServiceOrderClosingTextRequest $request): RedirectResponse
    {
        GeneralSetting::set('serviceorder_closing_text', $request->validated()['serviceorder_closing_text'] ?? '');

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }

    public function updateLocationTracking(UpdateLocationTrackingSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        GeneralSetting::set('location_tracking_start', $data['start']);
        GeneralSetting::set('location_tracking_end', $data['end']);
        GeneralSetting::set('location_tracking_days', implode(',', $data['days']));

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }

    public function updateAllowOverrideUnavailability(
        UpdateAllowOverrideUnavailabilityRequest $request
    ): RedirectResponse {
        GeneralSetting::set('allow_override_unavailability', $request->validated()['value'] ? '1' : '0');

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }

    public function updateServiceOrderMinImages(UpdateServiceOrderMinImagesRequest $request): RedirectResponse
    {
        GeneralSetting::set('serviceorder_min_images', $request->validated()['min_images']);

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }
}
