<?php

use App\Http\Controllers\Api\GoogleIntegrationStatusController;
use App\Http\Controllers\EventApiController;
use App\Http\Controllers\GeneralSettingController;
use App\Http\Controllers\ProjectApiController;
use App\Http\Controllers\UnavailabilityApiController;
use App\Http\Controllers\UserPlanGroupController;
use App\Http\Controllers\UserPlannableController;
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

    // Plan groups — reorder MUST be registered before {group} to avoid wildcard capture
    Route::get('plan-groups', [UserPlanGroupController::class, 'index']);
    Route::post('plan-groups', [UserPlanGroupController::class, 'store']);
    Route::put('plan-groups/reorder', [UserPlanGroupController::class, 'reorder']);
    Route::put('plan-groups/{group}', [UserPlanGroupController::class, 'update']);
    Route::delete('plan-groups/{group}', [UserPlanGroupController::class, 'destroy']);
    Route::put('plan-groups/{group}/users/{user}', [UserPlanGroupController::class, 'assignUser']);
    Route::delete('plan-groups/{group}/users/{user}', [UserPlanGroupController::class, 'removeUser']);

    Route::patch('users/{user}/plannable', UserPlannableController::class);
});
