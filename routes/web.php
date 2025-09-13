<?php

use App\Http\Controllers\ActivityListController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\RemarkController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\ServiceJobController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ServiceCheckController;
use App\Http\Controllers\ServiceOrderController;
use App\Http\Controllers\MaterialCategoryController;
use App\Http\Controllers\MaterialUsageUnitController;
use App\Http\Controllers\ServiceCheckValueController;
use App\Http\Controllers\ServiceCheckInstanceController;
use App\Http\Controllers\ServiceCheckGroupController;
use App\Http\Controllers\SnelStartImportController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\UserController;
use App\Models\Customer;
use App\Models\Asset;
use App\Models\ServiceOrder;
use App\Models\ServiceJob;
use App\Models\Ticket;
use App\Enums\AssetStatusses;
use Carbon\Carbon;

Route::group(
    ['middleware' => 'auth'],
    function () {
        Route::get('/', function () {
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
                ->whereNull('closed_on')
                ->orderByDesc('created_at')
                ->take(5)
                ->get(['id', 'customer_id', 'created_at', 'closed_on', 'sent_to_customer']);

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
        });
        Route::resource('customers', CustomerController::class)
            ->only(['index', 'show', 'update', 'store']);
        // coords patch
        Route::patch('customers/{customer}/coords', [CustomerController::class, 'updateCoords'])
            ->name('customers.updateCoords');
        Route::resource('brands', BrandController::class)->except(['show', 'edit', 'create']);
        Route::resource('producttypes', ProductTypeController::class)->except(['show', 'edit', 'create']);
        Route::resource('products', ProductController::class);
        Route::resource('assets', AssetController::class);
        Route::resource('tickets', TicketController::class);
        Route::resource('materials', MaterialController::class)
            ->except(['edit', 'create']);
        Route::resource('materialcategories', MaterialCategoryController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('materialusageunits', MaterialUsageUnitController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('servicechecks', ServiceCheckController::class)->except(['show', 'edit', 'create']);
        Route::resource('servicecheckvalues', ServiceCheckValueController::class)
            ->only(['store', 'update', 'destroy']);
        Route::resource('servicecheckgroups', ServiceCheckGroupController::class)
            ->except(['show', 'edit', 'create']);
        Route::post('servicecheckvalues/reorder', [ServiceCheckValueController::class, 'updateOrder']);
        Route::resource('servicecheckinstances', ServiceCheckInstanceController::class)
            ->only(['store', 'update', 'destroy']);
        // Manual SnelStart imports
        Route::post('imports/snelstart/customers', [SnelStartImportController::class, 'importCustomers'])
            ->name('imports.snelstart.customers');
        Route::post('imports/snelstart/materials', [SnelStartImportController::class, 'importMaterials'])
            ->name('imports.snelstart.materials');
        Route::resource('serviceorders', ServiceOrderController::class);
        Route::get('serviceorders/{serviceorder}/export/pdf', [ServiceOrderController::class, 'exportPdf'])
            ->name('serviceorders.exportPdf');
        Route::post('serviceorders/{serviceorder}/email-pdf', [ServiceOrderController::class, 'emailPdf'])
            ->name('serviceorders.emailPdf');
        Route::post(
            'serviceorders/{serviceorder}/email-pdf-with-jobs',
            [ServiceOrderController::class, 'emailPdfWithJobs']
        )->name('serviceorders.emailPdfWithJobs');
        Route::post('serviceorders/{serviceorder}/send-snelstart', [ServiceOrderController::class, 'sendToSnelStart'])
            ->name('serviceorders.sendToSnelStart');
        Route::post('serviceorders/{serviceorder}/tickets/{ticket}', [ServiceOrderController::class, 'attachTicket'])
            ->name('serviceorders.attachTicket');
        Route::get(
            'serviceorders/{serviceorder}/tickets/{ticket}/detach',
            [ServiceOrderController::class, 'detachTicket']
        )->name('serviceorders.detachTicket');
        Route::post(
            'serviceorders/{serviceorder}/materials/{material}',
            [ServiceOrderController::class, 'attachMaterial']
        )->name('serviceorders.attachMaterial');
        Route::delete(
            'serviceorders/{serviceorder}/materials/{materiable_id}',
            [ServiceOrderController::class, 'detachMaterial']
        )->name('serviceorders.detachMaterial');
        Route::put(
            'serviceorders/{serviceorder}/materials/{materiable_id}',
            [ServiceOrderController::class, 'updateMateriable']
        )->name('serviceorders.updateMateriable');
        Route::resource('servicejobs', ServiceJobController::class);
        Route::get('servicejobs/{servicejob}/export/pdf', [ServiceJobController::class, 'exportPdf'])
            ->name('servicejobs.exportPdf');
        Route::post('servicejobs/{servicejob}/email-pdf', [ServiceJobController::class, 'emailPdf'])
            ->name('servicejobs.emailPdf');
        Route::post('servicejobs/{servicejob}/clearcompletedon', [ServiceJobController::class, 'clearCompletedOn'])
            ->name('servicejobs.clearCompletedOn');
        Route::post(
            'servicejobs/{servicejob}/add-missing-instances',
            [ServiceJobController::class, 'addMissingInstances']
        )->name('servicejobs.addMissingInstances');
        Route::resource('images', ImageController::class)->except(['update']);
        Route::post('images/update/{image}', [ImageController::class, 'update'])->name('images.update');
        Route::resource('remarks', RemarkController::class)
            ->only(['store', 'destroy']);
        Route::resource('events', EventController::class)
            ->only(['index']);
        Route::resource('eventtypes', EventTypeController::class)
            ->except(['show', 'edit', 'create']);
        Route::get('upcomingactivities', [ActivityListController::class, 'getUpcomingActivities'])
            ->name('upcomingactivities');
        Route::get('upcomingactivities/map', [ActivityListController::class, 'map'])
            ->name('upcomingactivities.map');
        Route::patch('companies/{company}/inline', [CompanyController::class, 'inline'])->name('companies.inline');
        Route::post('companies/{company}/logo', [CompanyController::class, 'logo'])->name('companies.logo');
        Route::resource('companies', CompanyController::class)->except(['show', 'create', 'edit']);
        Route::resource('users', UserController::class)->except(['destroy','show', 'update']);
        Route::post('users/{user}', [UserController::class, 'update'])->name('users.update');
    }
);

Route::get('login', [AuthController::class, 'create'])->name('login');
Route::post('login', [AuthController::class, 'store'])->name('login.store');
Route::get('logout', [AuthController::class, 'destroy'])->name('logout');
