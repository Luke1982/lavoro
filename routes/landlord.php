<?php

use App\Http\Controllers\Landlord\AuthController;
use App\Http\Controllers\Landlord\CatalogueController;
use App\Http\Controllers\Landlord\CollectionController;
use App\Http\Controllers\Landlord\InvoiceController;
use App\Http\Controllers\Landlord\ResellerController;
use App\Http\Controllers\Landlord\SuperAdminController;
use App\Http\Controllers\Landlord\TenantController;
use App\Http\Controllers\Landlord\TopupController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleLandlordInertiaRequests;
use App\Http\Middleware\InitializeTenancyBySession;
use App\Http\Middleware\UseLandlordGuard;
use Illuminate\Support\Facades\Route;

/**
 * Het landlord-paneel draait alleen centraal: deze routes krijgen nooit een
 * tenant, en de tenancy-middleware wordt er expliciet af gehaald omdat die
 * anders de landlord uitlogt zodra er geen tenant is.
 */
Route::prefix('beheer')
    ->middleware([UseLandlordGuard::class, HandleLandlordInertiaRequests::class])
    ->withoutMiddleware([
        InitializeTenancyBySession::class,
        HandleInertiaRequests::class,
    ])
    ->group(function () {
        Route::get('login', [AuthController::class, 'showLogin'])->name('landlord.login');
        Route::post('login', [AuthController::class, 'login'])->name('landlord.login.post');

        Route::middleware('auth:landlord')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('landlord.logout');
            Route::get('/', [TenantController::class, 'index'])->name('landlord.index');
            Route::get('catalogus', [CatalogueController::class, 'catalogue'])->name('landlord.catalogue');
            Route::get('resellers', [ResellerController::class, 'resellers'])->name('landlord.resellers');
            Route::post('resellers', [ResellerController::class, 'storeReseller'])->name('landlord.reseller.store');
            Route::post('coupons', [ResellerController::class, 'storeCoupon'])->name('landlord.coupon.store');
            Route::post('{tenant}/coupon', [ResellerController::class, 'redeemCoupon'])->name('landlord.coupon.redeem');
            Route::post('tenants', [TenantController::class, 'storeTenant'])->name('landlord.tenant.store');
            Route::delete('tenants/{tenant}', [TenantController::class, 'destroyTenant'])->name('landlord.tenant.destroy');
            Route::delete('aanvraag/{request}/wachtwoord', [TopupController::class, 'forgetProvisioningPassword'])
                ->name('landlord.provisioning.forget-password');
            Route::delete('aanvraag/{request}', [TenantController::class, 'destroyProvisioningRequest'])
                ->name('landlord.provisioning.destroy');
            Route::get('aanvragen/status', [TenantController::class, 'provisioningStatus'])
                ->name('landlord.provisioning.status');
            Route::get('incasso', [CollectionController::class, 'collections'])->name('landlord.collections');
            Route::post('incasso', [CollectionController::class, 'exportCollection'])->name('landlord.collections.export');
            Route::get('{tenant}/facturen', [InvoiceController::class, 'invoices'])->name('landlord.invoices');
            Route::post('{tenant}/facturen', [InvoiceController::class, 'issueInvoice'])->name('landlord.invoice.issue');
            Route::post('{tenant}/facturen/{invoice}/mail', [InvoiceController::class, 'mailInvoice'])->name('landlord.invoice.mail');
            Route::get('{tenant}/facturen/{invoice}/pdf', [InvoiceController::class, 'invoicePdf'])->name('landlord.invoice.pdf');
            Route::get('{tenant}/facturen/{invoice}/xml', [InvoiceController::class, 'invoiceXml'])->name('landlord.invoice.xml');
            Route::put('pakket/{package}', [CatalogueController::class, 'updatePackage'])->name('landlord.package.update');
            Route::put('module/{module}', [CatalogueController::class, 'updateModule'])->name('landlord.module.update');
            Route::put('instelling/{setting}', [CatalogueController::class, 'updateSetting'])->name('landlord.setting.update');
            Route::put('facturatie', [CatalogueController::class, 'updateIssuer'])->name('landlord.issuer.update');
            Route::get('{tenant}', [TenantController::class, 'edit'])->name('landlord.edit');
            Route::put('{tenant}', [TenantController::class, 'update'])->name('landlord.update');
            Route::post('{tenant}/bijkoop', [TopupController::class, 'addTopup'])->name('landlord.topup');
            Route::post('{tenant}/superbeheerder', [SuperAdminController::class, 'storeSuperAdmin'])
                ->name('landlord.superadmin.store');
            Route::delete('{tenant}/superbeheerder/{user}', [SuperAdminController::class, 'destroySuperAdmin'])
                ->name('landlord.superadmin.destroy');
        });
    });
