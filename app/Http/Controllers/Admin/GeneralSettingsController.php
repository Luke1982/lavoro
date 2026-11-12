<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAllowOverrideUnavailabilityRequest;
use App\Http\Requests\Admin\UpdateAppointmentReminderRequest;
use App\Http\Requests\Admin\UpdateLocationTrackingSettingsRequest;
use App\Http\Requests\Admin\UpdateMapDueColorsRequest;
use App\Http\Requests\Admin\UpdateServiceOrderClosingTextRequest;
use App\Http\Requests\Admin\UpdateServiceOrderMinImagesRequest;
use App\Http\Requests\Admin\UpdateSnelStartNightlyCustomersRequest;
use App\Models\GeneralSetting;
use App\Services\AppointmentReminders;
use App\Services\SnelStartClient;
use App\Services\SnelStartImport;
use App\Support\MapDueColors;
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
                'end' => GeneralSetting::get('location_tracking_end', '18:00'),
                'days' => array_map(
                    'intval',
                    explode(',', GeneralSetting::get('location_tracking_days', '1,2,3,4,5'))
                ),
            ],
            'serviceOrderClosingText' => GeneralSetting::get('serviceorder_closing_text', ''),
            'allowOverrideUnavailability' => GeneralSetting::get('allow_override_unavailability', '0') === '1',
            'serviceOrderMinImages' => (int) GeneralSetting::get('serviceorder_min_images', 0),
            'snelStartConnected' => SnelStartClient::isConfigured(),
            'snelStartNightlyUpdatesCustomers' => GeneralSetting::get(
                SnelStartImport::NIGHTLY_UPDATES_CUSTOMERS,
                '1'
            ) === '1',
            'mapDueColors' => MapDueColors::get(),
            'mapDueColorsDefaults' => MapDueColors::defaults(),
            'appointmentReminderMinutes' => AppointmentReminders::leadMinutes(),
        ]);
    }

    public function updateAppointmentReminder(UpdateAppointmentReminderRequest $request): RedirectResponse
    {
        AppointmentReminders::saveLeadMinutes($request->validated('minutes'));

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
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

    public function updateSnelStartNightlyCustomers(UpdateSnelStartNightlyCustomersRequest $request): RedirectResponse
    {
        GeneralSetting::set(SnelStartImport::NIGHTLY_UPDATES_CUSTOMERS, $request->validated()['value'] ? '1' : '0');

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }

    public function updateServiceOrderMinImages(UpdateServiceOrderMinImagesRequest $request): RedirectResponse
    {
        GeneralSetting::set('serviceorder_min_images', $request->validated()['min_images']);

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }

    public function updateMapDueColors(UpdateMapDueColorsRequest $request): RedirectResponse
    {
        MapDueColors::save($request->validated());

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }
}
