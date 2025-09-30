<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatusses;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $customers = Customer::with(['assets' => function ($q) {
            $q->select('id', 'customer_id', 'next_service_date', 'status', 'serial_number', 'product_id')
              ->with(['product.productType']);
        }])->get(['id', 'name', 'address', 'postal_code', 'city', 'lat', 'lon']);

        $now = Carbon::now();
        $customers->transform(function ($c) use ($now) {
            $eligible = $c->assets->filter(
                fn($a) => $a->next_service_date && $a->status !== AssetStatusses::inactive->value
            );
            $days = $eligible
                ->map(fn($a) => $now->diffInDays(Carbon::parse($a->next_service_date), false))
                ->min();
            $c->next_service_in_days = $days;
            $earliest = $eligible->sortBy(fn($a) => $a->next_service_date)->first();
            if ($earliest) {
                $c->earliest_asset_serial = $earliest->serial_number;
                $c->earliest_asset_product_type = $earliest->product?->productType?->name;
            }
            return $c;
        });

        $stats = [
            'assets' => Asset::count(),
            'serviceOrders' => ServiceOrder::count(),
            'serviceJobs' => ServiceJob::count(),
            'tickets' => Ticket::count(),
        ];

        $openServiceOrders = ServiceOrder::with('customer')
            ->where('status', 'closed')
            ->where(function ($q) {
                $q->where('sent_to_administration', false)
                  ->orWhere('sent_to_customer', false);
            })
            ->orderByDesc('updated_at')
            ->get([
                'id',
                'customer_id',
                'updated_at',
                'closed_on',
                'sent_to_administration',
                'sent_to_customer',
                'status',
            ]);

        $upcomingJobs = ServiceJob::with(['serviceOrder.customer'])
            ->whereNull('completed_on')
            ->orderBy('created_at')
            ->take(5)
            ->get(['id', 'service_order_id', 'created_at', 'completed_on']);

        $recentTickets = Ticket::with(['asset.customer'])
            ->orderByDesc('created_at')
            ->take(5)
            ->get(['id', 'asset_id', 'subject', 'status', 'created_at']);

        return inertia('Index/DashBoard', [
            'customers' => $customers,
            'stats' => $stats,
            'openServiceOrders' => $openServiceOrders,
            'upcomingJobs' => $upcomingJobs,
            'recentTickets' => $recentTickets,
        ]);
    }
}
