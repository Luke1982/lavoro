<?php

use App\Http\Controllers\ActivityListController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PlannerController;
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
use App\Http\Controllers\RoleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMilestoneController;
use App\Http\Controllers\ProductRelationController;
use App\Http\Controllers\ProductableController;
use App\Http\Controllers\ProductAttributeController;
use App\Http\Controllers\ProductAttributeValueController;
use App\Http\Controllers\ProductAttributeProductTypeController;
use App\Http\Controllers\ProductAttributeValueableController;
use App\Http\Controllers\AssetRelationController;
use App\Http\Controllers\TechnicalManagementController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\GoogleWebhookController;
use App\Http\Controllers\ServiceOrderTaskController;
use App\Http\Controllers\ServiceOrderTaskInstanceController;

Route::group(
    ['middleware' => 'auth'],
    function () {
        Route::get('/', DashboardController::class);
        Route::resource('customers', CustomerController::class)
            ->only(['index', 'show', 'update', 'store', 'edit']);
        // coords patch
        Route::patch('customers/{customer}/coords', [CustomerController::class, 'updateCoords'])
            ->name('customers.updateCoords');
        Route::resource('brands', BrandController::class)->except(['show', 'edit', 'create']);
        Route::resource('producttypes', ProductTypeController::class)->except(['show', 'edit', 'create']);
        Route::resource('products', ProductController::class);
        Route::resource('productrelations', ProductRelationController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('productables', ProductableController::class)->only(['store', 'update', 'destroy']);
        Route::resource('productattributes', ProductAttributeController::class)->except(['edit', 'create']);
        Route::post('productattributes/{productattribute}/producttypes/{producttype}', [ProductAttributeProductTypeController::class, 'store'])
            ->name('productattributes.producttypes.store');
        Route::delete('productattributes/{productattribute}/producttypes/{producttype}', [ProductAttributeProductTypeController::class, 'destroy'])
            ->name('productattributes.producttypes.destroy');
        Route::post('productattributes/{productattribute}/producttypes', [ProductAttributeProductTypeController::class, 'sync'])
            ->name('productattributes.producttypes.sync');
        Route::post('productattributes/{productattribute}/values', [ProductAttributeValueController::class, 'store'])
            ->name('productattributevalues.store');
        Route::patch('productattributevalues/{productattributevalue}', [ProductAttributeValueController::class, 'update'])
            ->name('productattributevalues.update');
        Route::delete('productattributevalues/{productattributevalue}', [ProductAttributeValueController::class, 'destroy'])
            ->name('productattributevalues.destroy');
        Route::post('productattributevalueables', [ProductAttributeValueableController::class, 'store'])
            ->name('productattributevalueables.store');
        Route::resource('assets', AssetController::class);
        Route::post('assets/{asset}/child', [AssetController::class, 'storeChild'])
            ->name('assets.storeChild');
        Route::resource('assetrelations', AssetRelationController::class)->only(['store', 'destroy']);
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
        Route::resource('serviceorderstages', \App\Http\Controllers\ServiceOrderStageController::class)
            ->except(['show', 'edit', 'create']);
        Route::post('serviceorderstages/reorder', [
            \App\Http\Controllers\ServiceOrderStageController::class,
            'updateOrder',
        ])->name('serviceorderstages.reorder');
        Route::resource('serviceordertasks', ServiceOrderTaskController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('serviceordertaskinstances', ServiceOrderTaskInstanceController::class)
            ->only(['store', 'update', 'destroy']);
        Route::patch(
            'serviceordertaskinstances/{serviceordertaskinstance}/toggle',
            [ServiceOrderTaskInstanceController::class, 'toggle']
        )->name('serviceordertaskinstances.toggle');
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
        Route::post(
            'servicejobs/{servicejob}/bulk-complete',
            [ServiceJobController::class, 'bulkComplete']
        )->name('servicejobs.bulkComplete');
        Route::resource('images', ImageController::class)->except(['update']);
        Route::post('images/update/{image}', [ImageController::class, 'update'])->name('images.update');
        Route::post('images/{image}/set-main', [ImageController::class, 'setMain'])->name('images.setMain');
        Route::post('images/import-from-url', [ImageController::class, 'importFromUrl'])->name('images.importFromUrl');
        Route::resource('remarks', RemarkController::class)
            ->only(['store', 'destroy']);
        Route::resource('documents', DocumentController::class)
            ->only(['store', 'update', 'destroy']);
        Route::resource('events', EventController::class)
            ->only(['index']);
        Route::get('planner', [PlannerController::class, 'index'])
            ->name('planner.index');
        Route::get('events-test', [PlannerController::class, 'index'])
            ->name('events-test');
        Route::resource('eventtypes', EventTypeController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('customfields', CustomFieldController::class)
            ->except(['show', 'edit', 'create']);
        Route::post('customfields/values', [CustomFieldController::class, 'saveValues'])
            ->name('customfields.saveValues');
        Route::resource('projects', ProjectController::class);
        Route::resource('projectmilestones', ProjectMilestoneController::class)
            ->only(['store', 'update', 'destroy']);
        Route::get('upcomingactivities', [ActivityListController::class, 'getUpcomingActivities'])
            ->name('upcomingactivities'); // requires activitylist.read
        Route::get('upcomingactivities/map', [ActivityListController::class, 'map'])
            ->name('upcomingactivities.map'); // requires activitylist.read
        Route::get('me/edit', [UserController::class, 'editSelf'])->name('me.edit');
        Route::post('me', [UserController::class, 'updateSelf'])->name('me.update');

        Route::get('google/oauth/start', [GoogleOAuthController::class, 'start'])
            ->name('google.oauth.start');
        Route::get('google/oauth/callback', [GoogleOAuthController::class, 'callback'])
            ->name('google.oauth.callback');
        Route::delete('google/integration', [GoogleOAuthController::class, 'destroy'])
            ->name('google.integration.destroy');
        Route::get('technical-management', [TechnicalManagementController::class, 'index'])
            ->name('technical-management.index');
        Route::post('technical-management/test-mail', [TechnicalManagementController::class, 'sendTestMail'])
            ->name('technical-management.sendTestMail');
        Route::middleware('admin')->group(function () {
            Route::patch('companies/{company}/inline', [CompanyController::class, 'inline'])
                ->name('companies.inline');
            Route::post('companies/{company}/logo', [CompanyController::class, 'logo'])
                ->name('companies.logo');
            Route::post('companies/{company}/logo-negative', [CompanyController::class, 'logoNegative'])
                ->name('companies.logoNegative');
            Route::resource('companies', CompanyController::class)->except(['show', 'create', 'edit']);

            Route::resource('users', UserController::class)->except(['destroy', 'show', 'update']);
            Route::post('users/{user}', [UserController::class, 'update'])->name('users.update');

            Route::resource('roles', RoleController::class)->only(['index', 'store', 'update']);

            Route::get(
                'admin/calendar-grants',
                [\App\Http\Controllers\Admin\CalendarGrantController::class, 'index'],
            )->name('admin.calendar-grants.index');
            Route::post(
                'admin/calendar-grants',
                [\App\Http\Controllers\Admin\CalendarGrantController::class, 'store'],
            )->name('admin.calendar-grants.store');
            Route::delete(
                'admin/calendar-grants/{calendar_grant}',
                [\App\Http\Controllers\Admin\CalendarGrantController::class, 'destroy'],
            )->name('admin.calendar-grants.destroy');
        });
    }
);

Route::get('login', [AuthController::class, 'create'])->name('login');
Route::post('login', [AuthController::class, 'store'])->name('login.store');
Route::get('logout', [AuthController::class, 'destroy'])->name('logout');

Route::post('google/webhook', [GoogleWebhookController::class, 'handle'])
    ->name('google.webhook')
    ->middleware('throttle:60,1');
