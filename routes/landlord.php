<?php

use App\Http\Controllers\Landlord\LandlordController;
use Illuminate\Support\Facades\Route;

/**
 * Het landlord-paneel draait alleen centraal: deze routes krijgen nooit een
 * tenant, en de tenancy-middleware wordt er expliciet af gehaald omdat die
 * anders de landlord uitlogt zodra er geen tenant is.
 */
Route::prefix('beheer')
    ->middleware(\App\Http\Middleware\UseLandlordGuard::class)
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
            Route::get('catalogus', [LandlordController::class, 'catalogue'])->name('landlord.catalogue');
            Route::get('resellers', [LandlordController::class, 'resellers'])->name('landlord.resellers');
            Route::post('resellers', [LandlordController::class, 'storeReseller'])->name('landlord.reseller.store');
            Route::post('coupons', [LandlordController::class, 'storeCoupon'])->name('landlord.coupon.store');
            Route::post('{tenant}/coupon', [LandlordController::class, 'redeemCoupon'])->name('landlord.coupon.redeem');
            Route::get('{tenant}/facturen', [LandlordController::class, 'invoices'])->name('landlord.invoices');
            Route::post('{tenant}/facturen', [LandlordController::class, 'issueInvoice'])->name('landlord.invoice.issue');
            Route::get('{tenant}/facturen/{invoice}/pdf', [LandlordController::class, 'invoicePdf'])->name('landlord.invoice.pdf');
            Route::get('{tenant}/facturen/{invoice}/xml', [LandlordController::class, 'invoiceXml'])->name('landlord.invoice.xml');
            Route::put('pakket/{package}', [LandlordController::class, 'updatePackage'])->name('landlord.package.update');
            Route::put('module/{module}', [LandlordController::class, 'updateModule'])->name('landlord.module.update');
            Route::put('instelling/{setting}', [LandlordController::class, 'updateSetting'])->name('landlord.setting.update');
            Route::get('{tenant}', [LandlordController::class, 'edit'])->name('landlord.edit');
            Route::put('{tenant}', [LandlordController::class, 'update'])->name('landlord.update');
            Route::post('{tenant}/bijkoop', [LandlordController::class, 'addTopup'])->name('landlord.topup');
        });
    });
