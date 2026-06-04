<?php

use App\Http\Controllers\Api\GoogleIntegrationStatusController;
use App\Http\Controllers\EventApiController;
use App\Http\Controllers\ProjectApiController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::resource('events', EventApiController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('events/{event}/send-confirmation', [EventApiController::class, 'sendConfirmation']);

    Route::get('projects', [ProjectApiController::class, 'index']);
    Route::get('projectmilestones', [ProjectApiController::class, 'milestones']);

    Route::get('google/integration/status', GoogleIntegrationStatusController::class)
        ->name('api.google.integration.status');
});
