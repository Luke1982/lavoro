<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\RemarkController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\ServiceJobController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ServiceCheckController;
use App\Http\Controllers\ServiceOrderController;
use App\Http\Controllers\MaterialCategoryController;
use App\Http\Controllers\MaterialUsageUnitController;
use App\Http\Controllers\ServiceCheckValueController;
use App\Http\Controllers\ServiceCheckInstanceController;

Route::group(
    ['middleware' => 'auth'],
    function () {
        Route::get('/', function () {
            return inertia('Index/DashBoard');
        });
        Route::resource('customers', CustomerController::class)
            ->only(['index', 'show', 'update']);
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
        Route::post('servicecheckvalues/reorder', [ServiceCheckValueController::class, 'updateOrder']);
        Route::resource('servicecheckinstances', ServiceCheckInstanceController::class)
            ->only(['store', 'update', 'destroy']);
        Route::resource('serviceorders', ServiceOrderController::class);
        Route::post('serviceorders/{serviceorder}/tickets/{ticket}', [ServiceOrderController::class, 'attachTicket'])
            ->name('serviceorders.attachTicket');
        Route::delete('serviceorders/{serviceorder}/tickets/{ticket}', [ServiceOrderController::class, 'detachTicket'])
            ->name('serviceorders.detachTicket');
        Route::post('serviceorders/{serviceorder}/materials/{material}', [ServiceOrderController::class, 'attachMaterial'])
            ->name('serviceorders.attachMaterial');
        Route::delete('serviceorders/{serviceorder}/materials/{materiable_id}', [ServiceOrderController::class, 'detachMaterial'])
            ->name('serviceorders.detachMaterial');
        Route::put('serviceorders/{serviceorder}/materials/{materiable_id}', [ServiceOrderController::class, 'updateMateriable'])
            ->name('serviceorders.updateMateriable');
        Route::resource('servicejobs', ServiceJobController::class);
        Route::resource('images', ImageController::class)->except(['update']);
        Route::post('images/update/{image}', [ImageController::class, 'update'])->name('images.update');
        Route::resource('remarks', RemarkController::class)
            ->only(['store', 'destroy']);
    }
);

Route::get('login', [AuthController::class, 'create'])->name('login');
Route::post('login', [AuthController::class, 'store'])->name('login.store');
Route::delete('logout', [AuthController::class, 'destroy'])->name('logout');
