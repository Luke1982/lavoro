<?php

use App\Http\Controllers\Landlord\LandlordController;
use Illuminate\Support\Facades\Route;

/**
 * Het landlord-paneel draait alleen centraal: deze routes krijgen nooit een
 * tenant, en de tenancy-middleware wordt er expliciet af gehaald omdat die
 * anders de landlord uitlogt zodra er geen tenant is.
 */
Route::prefix('beheer')
    ->withoutMiddleware([
        \App\Http\Middleware\InitializeTenancyBySession::class,
        \App\Http\Middleware\HandleInertiaRequests::class,
    ])
    ->group(function () {
        Route::get('login', [LandlordController::class, 'showLogin'])->name('landlord.login');
        Route::post('login', [LandlordController::class, 'login'])->name('landlord.login.post');

        Route::middleware('auth:landlord')->group(function () {
            Route::post('logout', [LandlordController::class, 'logout'])->name('landlord.logout');
            Route::get('/', [LandlordController::class, 'index'])->name('landlord.index');
            Route::get('{tenant}', [LandlordController::class, 'edit'])->name('landlord.edit');
            Route::put('{tenant}', [LandlordController::class, 'update'])->name('landlord.update');
        });
    });
