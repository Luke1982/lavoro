<?php

use App\Domain\Integrations\Area;
use App\Http\Controllers\ActivityListController;
use App\Http\Controllers\Admin\CalendarGrantController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\StandardAttachmentController;
use App\Http\Controllers\Admin\StandardEmailController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AssistantTranscriptionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogImportController;
use App\Http\Controllers\ComboSearchController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerImportController;
use App\Http\Controllers\CustomerUploadController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DialController;
use App\Http\Controllers\DocumentCategoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EventExportController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FreeformMaterialController;
use App\Http\Controllers\GeocodeController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\GoogleWebhookController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\ImportMappingController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\IntegrationWebhookController;
use App\Http\Controllers\InternalAnnouncementController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MaintenanceContractController;
use App\Http\Controllers\MaintenanceContractPriceFormulaController;
use App\Http\Controllers\MaintenanceContractTemplateController;
use App\Http\Controllers\MaterialCategoryController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MaterialSupplierController;
use App\Http\Controllers\MaterialUsageUnitController;
use App\Http\Controllers\NotificationMuteController;
use App\Http\Controllers\NotificationSubscriptionController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\PriceFormulaController;
use App\Http\Controllers\ProductableController;
use App\Http\Controllers\ProductAttributeController;
use App\Http\Controllers\ProductAttributeProductTypeController;
use App\Http\Controllers\ProductAttributeValueableController;
use App\Http\Controllers\ProductAttributeValueController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductMaterialDefaultController;
use App\Http\Controllers\ProductRelationController;
use App\Http\Controllers\ProductSupplierController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectExportController;
use App\Http\Controllers\ProjectMilestoneController;
use App\Http\Controllers\ProjectTemplateController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\QuoteCatalogController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteDecisionController;
use App\Http\Controllers\QuoteLineController;
use App\Http\Controllers\QuoteResponseController;
use App\Http\Controllers\QuoteSettingsController;
use App\Http\Controllers\RemarkController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RoutePlanController;
use App\Http\Controllers\ServiceCheckController;
use App\Http\Controllers\ServiceCheckGroupController;
use App\Http\Controllers\ServiceCheckInstanceController;
use App\Http\Controllers\ServiceCheckValueController;
use App\Http\Controllers\ServiceJobController;
use App\Http\Controllers\ServiceOrderController;
use App\Http\Controllers\ServiceOrderMacroController;
use App\Http\Controllers\ServiceOrderMacroRunController;
use App\Http\Controllers\ServiceOrderServiceJobController;
use App\Http\Controllers\ServiceOrderSignatureController;
use App\Http\Controllers\ServiceOrderStageController;
use App\Http\Controllers\ServiceOrderTaskCheckController;
use App\Http\Controllers\ServiceOrderTaskCheckLinkController;
use App\Http\Controllers\ServiceOrderTaskChecklistController;
use App\Http\Controllers\ServiceOrderTaskChecklistLinkController;
use App\Http\Controllers\ServiceOrderTaskController;
use App\Http\Controllers\ServiceOrderTaskInstanceAssetController;
use App\Http\Controllers\ServiceOrderTaskInstanceController;
use App\Http\Controllers\ServiceOrderTaskInstanceMaterialController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierImportController;
use App\Http\Controllers\TechnicalManagementController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserNotificationController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\UserUnavailabilityController;
use Illuminate\Support\Facades\Route;

Route::group(
    ['middleware' => 'auth'],
    function () {

        Route::get('files/images/{image}', [FileController::class, 'image'])->name('files.image');
        Route::get('files/images/{image}/original', [FileController::class, 'imageOriginal'])->name('files.imageOriginal');
        Route::get('files/avatars/{user}', [FileController::class, 'avatar'])->name('files.avatar');
        Route::get('files/companies/{company}/logo/{variant?}', [FileController::class, 'companyLogo'])->name('files.companyLogo');

        Route::get('/', DashboardController::class);

        /**
         * Gated by AssistantPolicy in the form request rather than by middleware.
         *
         * Throttled because every call spends real money at a supplier: without
         * it a held key, a retry loop or an impatient double-click turns into a
         * bill, and the allowance it eats belongs to the whole company rather
         * than to the person doing it.
         */
        Route::post('assistant/ask', [AssistantController::class, 'ask'])
            ->middleware('throttle:20,1')
            ->name('assistant.ask')
            ->middleware('tenant.module:assistant');

        /** Throttled for the same reason: every recording is paid for by the minute. */
        Route::post('assistant/transcribe', AssistantTranscriptionController::class)
            ->middleware('throttle:10,1')
            ->name('assistant.transcribe')
            ->middleware('tenant.module:assistant');

        /**
         * Throttled like the rest. It costs nothing at a supplier, but it hands
         * back a transcript of somebody's working day and there is no reason for
         * anyone to want thirty of those a minute.
         */
        Route::get('assistant/history/{conversation}', [AssistantController::class, 'conversation'])
            ->middleware('throttle:60,1')
            ->name('assistant.conversation');

        Route::get('assistant/history', [AssistantController::class, 'history'])
            ->middleware('throttle:60,1')
            ->name('assistant.history');

        /**
         * Picks the conversation up again after a button was clicked. Gated
         * like ask: it spends the same money at the same supplier.
         */
        Route::post('assistant/continue', [AssistantController::class, 'proceed'])
            ->middleware('throttle:20,1')
            ->name('assistant.continue')
            ->middleware('tenant.module:assistant');

        /**
         * Writes a conversation out to a file so it can be handed over for
         * diagnosis. Costs nothing at a supplier and touches no model.
         */
        /** Questions worth asking again, per kind of page. */
        Route::get('assistant/prompts', [AssistantController::class, 'prompts'])
            ->middleware('throttle:60,1')
            ->name('assistant.prompts');

        Route::post('assistant/prompts', [AssistantController::class, 'storePrompt'])
            ->middleware('throttle:30,1')
            ->name('assistant.prompts.store');

        Route::patch('assistant/prompts/{prompt}', [AssistantController::class, 'updatePrompt'])
            ->middleware('throttle:30,1')
            ->name('assistant.prompts.update');

        Route::delete('assistant/prompts/{prompt}', [AssistantController::class, 'destroyPrompt'])
            ->middleware('throttle:30,1')
            ->name('assistant.prompts.destroy');

        /** The parked photos of a conversation: into their storage, or gone. */
        Route::post('assistant/photos/keep', [AssistantController::class, 'keepPhotos'])
            ->middleware('throttle:20,1')
            ->name('assistant.photos.keep');

        Route::delete('assistant/photos', [AssistantController::class, 'discardPhotos'])
            ->middleware('throttle:20,1')
            ->name('assistant.photos.discard');

        Route::post('assistant/report', [AssistantController::class, 'report'])
            ->middleware('throttle:10,1')
            ->name('assistant.report');

        /** Carries out something already agreed to; no model runs here. */
        Route::post('assistant/confirm', [AssistantController::class, 'confirm'])
            ->middleware('throttle:20,1')
            ->name('assistant.confirm')
            ->middleware('tenant.module:assistant');
        /**
         * Feeds the navigation spotlight. Every signed-in user may search; the
         * searchers behind it each apply their own scope, so what comes back is
         * the same set that person would reach by navigating there by hand.
         */
        Route::get('search/global', GlobalSearchController::class)
            ->middleware('throttle:120,1')
            ->name('search.global');
        Route::resource('contacts', ContactController::class)->except(['create', 'edit']);
        Route::resource('maintenancecontracts', MaintenanceContractController::class)->except(['create', 'edit']);
        Route::post(
            'maintenancecontracts/{maintenancecontract}/assets/{asset}',
            [MaintenanceContractController::class, 'attachAsset']
        )->name('maintenancecontracts.attachAsset');
        Route::put(
            'maintenancecontracts/{maintenancecontract}/assets/{assetable_id}',
            [MaintenanceContractController::class, 'updateAssetable']
        )->name('maintenancecontracts.updateAssetable');
        Route::delete(
            'maintenancecontracts/{maintenancecontract}/assets/{assetable_id}',
            [MaintenanceContractController::class, 'detachAsset']
        )->name('maintenancecontracts.detachAsset');
        Route::post(
            'maintenancecontracts/{maintenancecontract}/generate-serviceorders',
            [MaintenanceContractController::class, 'generateServiceOrders']
        )->name('maintenancecontracts.generateServiceOrders');
        Route::resource('maintenancecontracttemplates', MaintenanceContractTemplateController::class)
            ->except(['create', 'edit']);
        Route::put(
            'maintenancecontracts/{maintenancecontract}/price-formula',
            [MaintenanceContractPriceFormulaController::class, 'update']
        )->name('maintenancecontracts.priceFormula');
        Route::post('priceformulas/preview', [PriceFormulaController::class, 'preview'])
            ->middleware('throttle:240,1')
            ->name('priceformulas.preview');
        Route::resource('priceformulas', PriceFormulaController::class)->except(['create', 'edit']);
        Route::put('priceformulas/{priceformula}/graph', [PriceFormulaController::class, 'updateGraph'])
            ->name('priceformulas.graph');
        Route::post(
            'priceformulas/{priceformula}/upgrade-contracts',
            [PriceFormulaController::class, 'upgradeContracts']
        )->name('priceformulas.upgradeContracts');

        /**
         * Offertes, the paid module 'quotes'. The middleware turns the whole
         * chapter away for a customer without it; QuotePolicy asks the same
         * question again for every path that does not come through here.
         */
        Route::middleware('tenant.module:quotes')->group(function () {
            Route::get('quote-settings', [QuoteSettingsController::class, 'index'])->name('quote-settings.index');
            Route::post('quote-settings/units', [QuoteSettingsController::class, 'storeUnit'])->name('quote-settings.units.store');
            Route::put('quote-settings/units/order', [QuoteSettingsController::class, 'orderUnits'])->name('quote-settings.units.order');
            Route::patch('quote-settings/units/{unit}', [QuoteSettingsController::class, 'updateUnit'])->name('quote-settings.units.update');
            Route::delete('quote-settings/units/{unit}', [QuoteSettingsController::class, 'destroyUnit'])->name('quote-settings.units.destroy');
            Route::resource('quotes', QuoteController::class)->except(['create', 'edit']);
            Route::patch('quotes/{quote}/status', [QuoteController::class, 'status'])->name('quotes.status');
            Route::get('quotes/{quote}/preview', [QuoteController::class, 'preview'])->name('quotes.preview');
            Route::get('quotes/{quote}/versions/{version}', [QuoteController::class, 'version'])
                ->scopeBindings()
                ->name('quotes.version');
            Route::post('quotes/{quote}/send', [QuoteDecisionController::class, 'send'])
                ->middleware('throttle:10,1')
                ->name('quotes.send');
            Route::post('quotes/{quote}/accept', [QuoteDecisionController::class, 'accept'])->name('quotes.accept');
            Route::post('quotes/{quote}/service-order', [QuoteDecisionController::class, 'convert'])->name('quotes.convert');
            Route::get('quotes/{quote}/catalog', QuoteCatalogController::class)
                ->middleware('throttle:120,1')
                ->name('quotes.catalog');
            Route::post('quotes/{quote}/products', [QuoteLineController::class, 'storeProduct'])
                ->name('quotes.products.store');
            Route::scopeBindings()->group(function () {
                Route::post('quotes/{quote}/lines', [QuoteLineController::class, 'store'])->name('quotes.lines.store');
                Route::put('quotes/{quote}/lines/order', [QuoteLineController::class, 'reorder'])
                    ->name('quotes.lines.order');
                Route::patch('quotes/{quote}/lines/{line}', [QuoteLineController::class, 'update'])
                    ->name('quotes.lines.update');
                Route::delete('quotes/{quote}/lines/{line}', [QuoteLineController::class, 'destroy'])
                    ->name('quotes.lines.destroy');
                Route::post('products/{product}/material-defaults', [ProductMaterialDefaultController::class, 'store'])
                    ->name('products.materialDefaults.store');
                Route::patch(
                    'products/{product}/material-defaults/{materialDefault}',
                    [ProductMaterialDefaultController::class, 'update']
                )->name('products.materialDefaults.update');
                Route::delete(
                    'products/{product}/material-defaults/{materialDefault}',
                    [ProductMaterialDefaultController::class, 'destroy']
                )->name('products.materialDefaults.destroy');
            });
        });
        Route::resource('customers', CustomerController::class)
            ->only(['index', 'show', 'update', 'store', 'edit']);
        Route::resource('locations', LocationController::class)
            ->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::patch('locations/{location}/coords', [LocationController::class, 'updateCoords'])
            ->name('locations.updateCoords');
        Route::get('combo/customers/{customer}/locations', [ComboSearchController::class, 'locationsForCustomer'])
            ->name('combo.customer.locations');
        Route::get('combo/customers', [ComboSearchController::class, 'customers'])->name('combo.customers');
        Route::get('combo/addresses', [ComboSearchController::class, 'addresses'])
            ->middleware('throttle:120,1')
            ->name('combo.addresses');
        Route::get('combo/assets', [ComboSearchController::class, 'assets'])->name('combo.assets');
        Route::get('combo/users', [ComboSearchController::class, 'users'])->name('combo.users');
        Route::get('combo/eventtypes', [ComboSearchController::class, 'eventTypes'])->name('combo.eventtypes');
        Route::get('combo/materials', [ComboSearchController::class, 'materials'])->name('combo.materials');
        Route::get('combo/products', [ComboSearchController::class, 'products'])->name('combo.products');
        Route::get('combo/suppliers', [ComboSearchController::class, 'suppliers'])->name('combo.suppliers');
        Route::get('combo/serviceorders', [ComboSearchController::class, 'serviceOrders'])->name('combo.serviceorders');
        // coords patch
        Route::patch('customers/{customer}/coords', [CustomerController::class, 'updateCoords'])
            ->name('customers.updateCoords');
        Route::get('geocode', [GeocodeController::class, 'lookup'])->name('geocode.lookup');
        Route::get('geocode/address', [GeocodeController::class, 'address'])
            ->middleware('throttle:120,1')
            ->name('geocode.address');
        Route::post('customers/import/preview', [CustomerImportController::class, 'preview'])
            ->name('customers.import.preview');
        Route::post('customers/import/confirm', [CustomerImportController::class, 'confirm'])
            ->name('customers.import.confirm');
        Route::get('customers/import/example', [CustomerImportController::class, 'example'])
            ->name('customers.import.example');
        Route::post('suppliers/import/preview', [SupplierImportController::class, 'preview'])
            ->name('suppliers.import.preview');
        Route::post('suppliers/import/confirm', [SupplierImportController::class, 'confirm'])
            ->name('suppliers.import.confirm');
        Route::get('suppliers/import/example', [SupplierImportController::class, 'example'])
            ->name('suppliers.import.example');
        Route::resource('suppliers', SupplierController::class)
            ->except(['create', 'edit']);
        Route::post('products/{product}/suppliers', [ProductSupplierController::class, 'store'])
            ->name('products.suppliers.store');
        Route::patch('products/{product}/suppliers/{supplier}', [
            ProductSupplierController::class,
            'update',
        ])->name('products.suppliers.update');
        Route::delete('products/{product}/suppliers/{supplier}', [
            ProductSupplierController::class,
            'destroy',
        ])->name('products.suppliers.destroy');
        Route::resource('brands', BrandController::class)->except(['show', 'edit', 'create']);
        Route::resource('producttypes', ProductTypeController::class)->except(['show', 'edit', 'create']);
        Route::post('products/bulk-update', [ProductController::class, 'bulkUpdate'])
            ->name('products.bulk-update');
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
        Route::post('assets/transfer-preview', [AssetController::class, 'transferPreview'])
            ->name('assets.transferPreview');
        Route::resource('assets', AssetController::class);
        Route::patch('assets/{asset}/location', [AssetController::class, 'updateLocation'])
            ->name('assets.updateLocation');
        Route::post('assets/{asset}/child', [AssetController::class, 'storeChild'])
            ->name('assets.storeChild');
        Route::post('assets/{asset}/children', [AssetController::class, 'attachChild'])
            ->name('assets.attachChild');
        Route::delete('assets/{asset}/parent', [AssetController::class, 'detachParent'])
            ->name('assets.detachParent');
        Route::post('tickets/bulk-update', [TicketController::class, 'bulkUpdate'])
            ->name('tickets.bulk-update');
        Route::get('tickets/map', [TicketController::class, 'map'])
            ->name('tickets.map'); // requires ticket.see_all
        Route::resource('tickets', TicketController::class);
        Route::resource('materials', MaterialController::class)
            ->except(['edit', 'create']);
        Route::post('materials/{material}/suppliers', [
            MaterialSupplierController::class,
            'store',
        ])->name('materials.suppliers.store');
        Route::patch('materials/{material}/suppliers/{supplier}', [
            MaterialSupplierController::class,
            'update',
        ])->name('materials.suppliers.update');
        Route::delete('materials/{material}/suppliers/{supplier}', [
            MaterialSupplierController::class,
            'destroy',
        ])->name('materials.suppliers.destroy');
        Route::prefix('{kind}/imports')->whereIn('kind', ['materials', 'products'])->group(function () {
            Route::post('', [CatalogImportController::class, 'store'])->name('imports.store');
            Route::get('{catalog_import}', [CatalogImportController::class, 'show'])->name('imports.show');
            Route::post('{catalog_import}/mapping', [CatalogImportController::class, 'map'])->name('imports.map');
            Route::post('{catalog_import}/confirm', [CatalogImportController::class, 'confirm'])->name('imports.confirm');
            Route::delete('{catalog_import}', [CatalogImportController::class, 'destroy'])->name('imports.destroy');
        });
        Route::post('import-mappings', [ImportMappingController::class, 'store'])
            ->name('import-mappings.store');
        Route::delete('import-mappings/{import_mapping}', [ImportMappingController::class, 'destroy'])
            ->name('import-mappings.destroy');
        Route::resource('materialcategories', MaterialCategoryController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('materialusageunits', MaterialUsageUnitController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('servicechecks', ServiceCheckController::class)->except(['show', 'edit', 'create']);
        Route::resource('servicecheckvalues', ServiceCheckValueController::class)
            ->only(['store', 'update', 'destroy']);
        Route::resource('servicecheckgroups', ServiceCheckGroupController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('serviceorderstages', ServiceOrderStageController::class)
            ->except(['show', 'edit', 'create']);
        Route::post('serviceorderstages/reorder', [
            ServiceOrderStageController::class,
            'updateOrder',
        ])->name('serviceorderstages.reorder');
        Route::resource('serviceordertasks', ServiceOrderTaskController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('serviceordertaskchecks', ServiceOrderTaskCheckController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::resource('serviceordertaskchecklists', ServiceOrderTaskChecklistController::class)
            ->only(['store', 'update', 'destroy']);
        Route::post('serviceordertaskchecklinks/reorder', [ServiceOrderTaskCheckLinkController::class, 'reorder'])
            ->name('serviceordertaskchecklinks.reorder');
        Route::resource('serviceordertaskchecklinks', ServiceOrderTaskCheckLinkController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['serviceordertaskchecklinks' => 'link']);
        Route::post('serviceordertaskchecklistlinks/reorder', [ServiceOrderTaskChecklistLinkController::class, 'reorder'])
            ->name('serviceordertaskchecklistlinks.reorder');
        Route::resource('serviceordertaskchecklistlinks', ServiceOrderTaskChecklistLinkController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['serviceordertaskchecklistlinks' => 'link']);
        Route::resource('serviceordertaskinstances', ServiceOrderTaskInstanceController::class)
            ->only(['store', 'update', 'destroy']);
        Route::patch(
            'serviceordertaskinstances/{serviceordertaskinstance}/toggle',
            [ServiceOrderTaskInstanceController::class, 'toggle']
        )->name('serviceordertaskinstances.toggle');
        Route::post(
            'serviceordertaskinstances/{serviceordertaskinstance}/sign',
            [ServiceOrderTaskInstanceController::class, 'sign']
        )->name('serviceordertaskinstances.sign');
        Route::delete(
            'serviceordertaskinstances/{serviceordertaskinstance}/sign',
            [ServiceOrderTaskInstanceController::class, 'unsign']
        )->name('serviceordertaskinstances.unsign');
        Route::post(
            'serviceordertaskinstances/{serviceordertaskinstance}/cancel',
            [ServiceOrderTaskInstanceController::class, 'cancel']
        )->name('serviceordertaskinstances.cancel');
        Route::post(
            'serviceordertaskinstances/{serviceordertaskinstance}/assets',
            [ServiceOrderTaskInstanceAssetController::class, 'store']
        )->name('serviceordertaskinstances.assets.store');
        Route::patch(
            'serviceordertaskinstances/{serviceordertaskinstance}/assets/{asset}',
            [ServiceOrderTaskInstanceAssetController::class, 'update']
        )->name('serviceordertaskinstances.assets.update');
        Route::post(
            'serviceordertaskinstances/{serviceordertaskinstance}/materials/{material}',
            [ServiceOrderTaskInstanceMaterialController::class, 'store']
        )->name('serviceordertaskinstances.materials.store');
        Route::put(
            'serviceordertaskinstances/{serviceordertaskinstance}/materials/{materiable_id}',
            [ServiceOrderTaskInstanceMaterialController::class, 'update']
        )->name('serviceordertaskinstances.materials.update');
        Route::delete(
            'serviceordertaskinstances/{serviceordertaskinstance}/materials/{materiable_id}',
            [ServiceOrderTaskInstanceMaterialController::class, 'destroy']
        )->name('serviceordertaskinstances.materials.destroy');
        Route::post(
            'serviceordertaskinstances/{serviceordertaskinstance}/freeform-materials',
            [ServiceOrderTaskInstanceMaterialController::class, 'storeFreeform']
        )->name('serviceordertaskinstances.freeformMaterials.store');
        Route::put(
            'serviceordertaskinstances/{serviceordertaskinstance}/freeform-materials/{freeform_material}',
            [ServiceOrderTaskInstanceMaterialController::class, 'updateFreeform']
        )->name('serviceordertaskinstances.freeformMaterials.update');
        Route::delete(
            'serviceordertaskinstances/{serviceordertaskinstance}/freeform-materials/{freeform_material}',
            [ServiceOrderTaskInstanceMaterialController::class, 'destroyFreeform']
        )->name('serviceordertaskinstances.freeformMaterials.destroy');
        Route::post('servicecheckvalues/reorder', [ServiceCheckValueController::class, 'updateOrder']);
        Route::resource('servicecheckinstances', ServiceCheckInstanceController::class)
            ->only(['store', 'update', 'destroy']);
        Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
        Route::post('integrations', [IntegrationController::class, 'store'])->name('integrations.store');
        Route::put('integrations/{integration}', [IntegrationController::class, 'update'])
            ->name('integrations.update')->whereNumber('integration');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])
            ->name('integrations.destroy')->whereNumber('integration');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])
            ->name('integrations.test')->whereNumber('integration');
        Route::delete('integrations/{integration}/credentials/{key}', [IntegrationController::class, 'forgetCredential'])
            ->name('integrations.forgetCredential')->whereNumber('integration');
        Route::post('integrations/{integration}/pull/{area}', [IntegrationController::class, 'pull'])
            ->name('integrations.pull')
            ->whereNumber('integration')
            ->whereIn('area', array_map(fn (Area $area) => $area->value, Area::synced()));
        Route::post('serviceorders/bulk-update', [ServiceOrderController::class, 'bulkUpdate'])
            ->name('serviceorders.bulk-update');
        Route::resource('serviceorders', ServiceOrderController::class);
        Route::get('serviceorders/{serviceorder}/export/pdf', [ServiceOrderController::class, 'exportPdf'])
            ->name('serviceorders.exportPdf');
        Route::delete('serviceorders/{serviceorder}/signature', [ServiceOrderSignatureController::class, 'destroy'])
            ->name('serviceorders.signature.destroy');
        Route::post('serviceorders/{serviceorder}/email-pdf', [ServiceOrderController::class, 'emailPdf'])
            ->name('serviceorders.emailPdf');
        Route::post(
            'serviceorders/{serviceorder}/email-pdf-with-jobs',
            [ServiceOrderController::class, 'emailPdfWithJobs']
        )->name('serviceorders.emailPdfWithJobs');
        Route::post('serviceorders/{serviceorder}/send-to-administration', [ServiceOrderController::class, 'sendToAdministration'])
            ->name('serviceorders.sendToAdministration');
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
        Route::post(
            'serviceorders/{serviceorder}/freeform-materials',
            [FreeformMaterialController::class, 'store']
        )->name('serviceorders.freeformMaterials.store');
        Route::put(
            'serviceorders/{serviceorder}/freeform-materials/{freeform_material}',
            [FreeformMaterialController::class, 'update']
        )->name('serviceorders.freeformMaterials.update');
        Route::delete(
            'serviceorders/{serviceorder}/freeform-materials/{freeform_material}',
            [FreeformMaterialController::class, 'destroy']
        )->name('serviceorders.freeformMaterials.destroy');
        Route::resource('servicejobs', ServiceJobController::class);
        Route::post('serviceorders/{serviceorder}/servicejobs', [ServiceOrderServiceJobController::class, 'store'])
            ->name('serviceorders.servicejobs.store');
        Route::resource('serviceordermacros', ServiceOrderMacroController::class)->except(['show']);
        Route::post(
            'serviceorders/{serviceorder}/macros/{serviceordermacro}/run',
            [ServiceOrderMacroRunController::class, 'store']
        )->name('serviceorders.macros.run');
        Route::get('servicejobs/{servicejob}/export/pdf', [ServiceJobController::class, 'exportPdf'])
            ->name('servicejobs.exportPdf');
        Route::post('servicejobs/{servicejob}/email-pdf', [ServiceJobController::class, 'emailPdf'])
            ->name('servicejobs.emailPdf');
        Route::post('servicejobs/{servicejob}/clearcompletedon', [ServiceJobController::class, 'clearCompletedOn'])
            ->name('servicejobs.clearCompletedOn');
        Route::patch('servicejobs/{servicejob}/serial', [ServiceJobController::class, 'updateAssetSerial'])
            ->name('servicejobs.updateAssetSerial');
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
        Route::get('images/{image}/annotations', [ImageController::class, 'annotations'])->name('images.annotations');
        Route::post('images/{image}/set-main', [ImageController::class, 'setMain'])->name('images.setMain');
        Route::post('images/{image}/share', [ImageController::class, 'share'])->name('images.share');
        Route::post('images/import-from-url', [ImageController::class, 'importFromUrl'])->name('images.importFromUrl');
        Route::resource('remarks', RemarkController::class)
            ->only(['store', 'destroy']);
        // Registered ahead of the resource so "bulk" is not swallowed by {document}.
        Route::put('documents/bulk-category', [DocumentController::class, 'bulkCategory'])
            ->name('documents.bulkCategory');
        Route::delete('documents/bulk', [DocumentController::class, 'bulkDestroy'])
            ->name('documents.bulkDestroy');
        Route::resource('documents', DocumentController::class)
            ->only(['store', 'update', 'destroy']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])
            ->name('documents.download');
        Route::get('documents/{document}/preview', [DocumentController::class, 'preview'])
            ->name('documents.preview');
        Route::resource('documentcategories', DocumentCategoryController::class)
            ->only(['store', 'update', 'destroy']);
        Route::get('planner', [PlannerController::class, 'index'])
            ->name('planner.index');
        Route::get('planner/export', EventExportController::class)
            ->name('planner.export');
        Route::resource('eventtypes', EventTypeController::class)
            ->except(['show', 'edit', 'create']);
        Route::resource('customfields', CustomFieldController::class)
            ->except(['show', 'edit', 'create']);
        Route::post('customfields/values', [CustomFieldController::class, 'saveValues'])
            ->name('customfields.saveValues');
        Route::resource('standard-emails', StandardEmailController::class)
            ->except(['show', 'create', 'edit']);
        Route::resource('standard-attachments', StandardAttachmentController::class)
            ->except(['show', 'create', 'edit']);
        Route::resource('internalannouncements', InternalAnnouncementController::class)
            ->except(['create', 'edit']);
        // Outside the resource, because acknowledging asks no right on the
        // announcement: everyone who got it may, and nobody else.
        Route::post(
            'internalannouncements/{internalannouncement}/acknowledge',
            [InternalAnnouncementController::class, 'acknowledge']
        )->name('internalannouncements.acknowledge');
        Route::resource('projects', ProjectController::class)->except(['create', 'edit']);
        Route::get('projects/{project}/timeline', [ProjectController::class, 'timeline'])
            ->name('projects.timeline');
        Route::post('projects/{project}/milestones/from-template', [ProjectMilestoneController::class, 'fromTemplate'])
            ->name('projects.milestonesFromTemplate');
        Route::resource('projecttemplates', ProjectTemplateController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::get('projects/{project}/export', ProjectExportController::class)
            ->name('projects.export');
        Route::resource('projectmilestones', ProjectMilestoneController::class)
            ->only(['store', 'update', 'destroy']);
        Route::get('upcomingactivities', [ActivityListController::class, 'getUpcomingActivities'])
            ->name('upcomingactivities'); // requires activitylist.read
        Route::get('upcomingactivities/map', [ActivityListController::class, 'map'])
            ->name('upcomingactivities.map'); // requires activitylist.read

        Route::post('routeplanner', [RoutePlanController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('routeplanner.store');

        Route::get('usernotifications', [UserNotificationController::class, 'index'])
            ->name('usernotifications.index');
        Route::get('usernotifications/feed', [UserNotificationController::class, 'feed'])
            ->name('usernotifications.feed');
        Route::post('usernotifications/read-all', [UserNotificationController::class, 'acknowledgeAll'])
            ->name('usernotifications.acknowledgeAll');
        Route::patch('usernotifications/{usernotification}/read', [UserNotificationController::class, 'acknowledge'])
            ->name('usernotifications.acknowledge');
        Route::delete('usernotifications/{usernotification}/read', [UserNotificationController::class, 'unacknowledge'])
            ->name('usernotifications.unacknowledge');
        Route::resource('notificationsubscriptions', NotificationSubscriptionController::class)
            ->only(['index', 'store', 'destroy']);
        Route::resource('notificationmutes', NotificationMuteController::class)
            ->only(['store', 'destroy']);
        Route::get('dial', DialController::class)->name('dial');
        Route::post('pushsubscriptions', [PushSubscriptionController::class, 'store'])
            ->name('pushsubscriptions.store');
        Route::delete('pushsubscriptions', [PushSubscriptionController::class, 'destroy'])
            ->name('pushsubscriptions.destroy');
        Route::get('me/edit', [UserController::class, 'editSelf'])->name('me.edit');
        Route::post('me', [UserController::class, 'updateSelf'])->name('me.update');

        Route::resource('users', UserController::class)->except(['destroy', 'show', 'update']);
        Route::post('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('users/{user}/restore', [UserController::class, 'restore'])
            ->name('users.restore')
            ->withTrashed();

        Route::resource('userroles', UserRoleController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::post('users/{user}/unavailabilities', [UserUnavailabilityController::class, 'store'])
            ->name('users.unavailabilities.store');
        Route::delete(
            'users/{user}/unavailabilities/{unavailability}',
            [UserUnavailabilityController::class, 'destroy']
        )->name('users.unavailabilities.destroy');

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
        Route::put('technical-management/integrations', [TechnicalManagementController::class, 'updateIntegrations'])
            ->name('technical-management.integrations');
        Route::delete('technical-management/integrations/secrets/{key}', [TechnicalManagementController::class, 'forgetSecret'])
            ->name('technical-management.forgetSecret');

        Route::middleware('admin')->group(function () {
            Route::patch('companies/{company}/inline', [CompanyController::class, 'inline'])
                ->name('companies.inline');
            Route::post('companies/{company}/logo', [CompanyController::class, 'logo'])
                ->name('companies.logo');
            Route::post('companies/{company}/logo-negative', [CompanyController::class, 'logoNegative'])
                ->name('companies.logoNegative');
            Route::resource('companies', CompanyController::class)->except(['show', 'create', 'edit']);

            Route::resource('roles', RoleController::class)->only(['index', 'store', 'update']);

            Route::get(
                'admin/calendar-grants',
                [CalendarGrantController::class, 'index'],
            )->name('admin.calendar-grants.index');
            Route::post(
                'admin/calendar-grants',
                [CalendarGrantController::class, 'store'],
            )->name('admin.calendar-grants.store');
            Route::delete(
                'admin/calendar-grants/{calendar_grant}',
                [CalendarGrantController::class, 'destroy'],
            )->name('admin.calendar-grants.destroy');
            Route::get(
                'admin/settings',
                [GeneralSettingsController::class, 'index'],
            )->name('admin.settings.index');
            Route::put(
                'admin/settings/location-tracking',
                [GeneralSettingsController::class, 'updateLocationTracking'],
            )->name('admin.settings.location-tracking');
            Route::put(
                'admin/settings/serviceorder-closing-text',
                [GeneralSettingsController::class, 'updateServiceOrderClosingText'],
            )->name('admin.settings.serviceorder-closing-text');
            Route::put(
                'admin/settings/allow-override-unavailability',
                [GeneralSettingsController::class, 'updateAllowOverrideUnavailability'],
            )->name('admin.settings.allow-override-unavailability');
            Route::put(
                'admin/settings/appointment-reminder',
                [GeneralSettingsController::class, 'updateAppointmentReminder'],
            )->name('admin.settings.appointment-reminder');
            Route::put(
                'admin/settings/serviceorder-min-images',
                [GeneralSettingsController::class, 'updateServiceOrderMinImages'],
            )->name('admin.settings.serviceorder-min-images');
            Route::put(
                'admin/settings/map-due-colors',
                [GeneralSettingsController::class, 'updateMapDueColors'],
            )->name('admin.settings.map-due-colors');
            Route::put(
                'admin/settings/upcoming-contract-assets',
                [GeneralSettingsController::class, 'updateUpcomingContractAssets'],
            )->name('admin.settings.upcoming-contract-assets');
        });
    }
);

/**
 * Lets Android open a tapped link to this server in the app. Every key that
 * signs an installed copy has to be listed: the Play app signing key for the
 * copies from Google Play, the debug key for a development build.
 */
Route::get('.well-known/assetlinks.json', fn () => response()->json([
    [
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => [
            'namespace' => 'android_app',
            'package_name' => config('services.android.package_name'),
            'sha256_cert_fingerprints' => config('services.android.sha256_cert_fingerprints'),
        ],
    ],
]))->name('app.assetlinks');

/**
 * The upload page for customers. No login: the link from the mail is the key,
 * and the middleware exchanges it for the token before anything happens.
 */
Route::middleware(['accesstoken:ticket.customer_upload', 'throttle:60,1'])->group(function () {
    Route::get('storing/informatie/{token}', [CustomerUploadController::class, 'show'])
        ->name('public.ticket.upload');
    Route::post('storing/informatie/{token}', [CustomerUploadController::class, 'store'])
        ->name('public.ticket.upload.store');
});

/**
 * Where a customer answers a quote, once: accept it or ask for a change. The
 * link opens the customer's database first, so the module is asked inside it.
 */
Route::middleware(['accesstoken:quote.response', 'tenant.module:quotes', 'throttle:30,1'])->group(function () {
    Route::get('offerte/{token}', [QuoteResponseController::class, 'show'])->name('public.quote.respond');
    Route::post('offerte/{token}/akkoord', [QuoteResponseController::class, 'accept'])->name('public.quote.accept');
    Route::post('offerte/{token}/wijziging', [QuoteResponseController::class, 'requestChanges'])->name('public.quote.changes');
});

Route::get('login', [AuthController::class, 'create'])->name('login');
Route::post('login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
Route::get('logout', [AuthController::class, 'destroy'])->name('logout');

Route::get('password/forgot', [PasswordResetController::class, 'create'])->name('password.request');
Route::post('password/forgot', [PasswordResetController::class, 'store'])->name('password.email');
Route::get('password/reset/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
Route::post('password/reset', [PasswordResetController::class, 'update'])->name('password.update');

Route::post('google/webhook', [GoogleWebhookController::class, 'handle'])
    ->name('google.webhook')
    ->middleware('throttle:60,1');

/**
 * Where linked systems report changes. The token in the address is the only
 * credential; see IntegrationWebhookController.
 */
Route::match(['get', 'post'], 'integrations/webhook/{token}', [IntegrationWebhookController::class, 'handle'])
    ->name('integrations.webhook')
    ->middleware('throttle:integration-webhooks');
