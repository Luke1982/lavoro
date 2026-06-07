<?php

use App\Http\Controllers\Api\GoogleIntegrationStatusController;
use App\Http\Controllers\EventApiController;
use App\Http\Controllers\GeneralSettingController;
use App\Http\Controllers\ProjectApiController;
use App\Http\Controllers\UnavailabilityApiController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::resource('events', EventApiController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('events/{event}/send-confirmation', [EventApiController::class, 'sendConfirmation']);

    Route::get('projects', [ProjectApiController::class, 'index']);
    Route::get('projectmilestones', [ProjectApiController::class, 'milestones']);

    Route::get('google/integration/status', GoogleIntegrationStatusController::class)
        ->name('api.google.integration.status');

    Route::get('unavailabilities', [UnavailabilityApiController::class, 'index']);

    Route::put('settings/{key}', [GeneralSettingController::class, 'update']);
});
