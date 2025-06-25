<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ServiceCheckController;

Route::get('/', function () {
    return inertia('Index/DashBoard');
});
Route::resource('customers', CustomerController::class)
    ->only(['index', 'show']);
Route::resource('brands', BrandController::class)->except(['show', 'edit', 'create']);
Route::resource('producttypes', ProductTypeController::class)->except(['show', 'edit', 'create']);
Route::resource('products', ProductController::class);
Route::resource('assets', AssetController::class);
Route::resource('tickets', TicketController::class);
Route::resource('servicechecks', ServiceCheckController::class);
