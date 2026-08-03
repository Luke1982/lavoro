<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardReadRequest;
use App\Jobs\GeocodeMissingCoordinatesJob;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * A block that the user may not see is not gathered either, so its queries
     * never run — the permission decides the work, not just the rendering.
     */
    public function __invoke(DashboardReadRequest $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $metrics = new DashboardMetrics($user, $request->validated()['period'] ?? null);

        $sees_stats = $user->hasPermission('dashboard.see_stats');
        $sees_orders = $this->seesServiceOrders($user);

        $map = $user->hasPermission('dashboard.see_map') ? $metrics->plannedOnMap() : null;

        /**
         * Kwam de kaart adressen tegen die het niet kon plaatsen, dan gaat het
         * opzoeken naar de wachtrij. Tijdens het tekenen van de pagina kan dat
         * niet — Nominatim staat één vraag per seconde toe — maar erom vragen
         * kan wel, en dan staan ze er de volgende keer op. Anders zou de kaart
         * pas bijtrekken als iemand aan het commando denkt.
         */
        if ($map && $map['unplaced'] > 0) {
            GeocodeMissingCoordinatesJob::request();
        }

        return inertia('Index/DashBoard', [
            'period' => $metrics->period(),
            'periodOptions' => DashboardMetrics::periodOptions(),
            'kpis' => $sees_stats ? $metrics->kpis() : null,
            'openOrders' => $sees_stats ? $metrics->openOrdersByStage() : null,
            'mapPoints' => $map,
            'agenda' => $user->hasPermission('dashboard.see_events') ? $metrics->agenda() : null,
            'upcomingInspections' => $user->hasPermission('dashboard.see_upcoming_servicejobs')
                ? $metrics->upcomingInspections()
                : null,
            'recentOrders' => $sees_orders ? $metrics->recentOrders() : null,
            'openTickets' => $user->hasPermission('dashboard.see_pending_tickets') ? $metrics->openTickets() : null,
        ]);
    }

    /**
     * The werkbonnenlijst is shown to anyone who had a reason to see werkbonnen
     * on the dashboard before, whichever of the four verzendrechten that was.
     */
    private function seesServiceOrders(User $user): bool
    {
        return $user->hasPermission('dashboard.see_open_serviceorders.all')
            || $user->hasPermission('dashboard.see_open_serviceorders.not_sent')
            || $user->hasPermission('dashboard.see_open_serviceorders.sent_administration')
            || $user->hasPermission('dashboard.see_open_serviceorders.sent_customer');
    }
}
