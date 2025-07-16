<?php

use App\Models\ServiceJob;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ServiceJobController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ServiceCheckController;
use App\Http\Controllers\ServiceOrderController;

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
Route::resource('servicechecks', ServiceCheckController::class);
Route::resource('serviceorders', ServiceOrderController::class);
Route::resource('servicejobs', ServiceJobController::class);
Route::resource('images', ImageController::class)->except(['update']);
Route::post('images/update/{image}', [ImageController::class, 'update'])->name('images.update');
