<?php

use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\GoogleIntegrationStatusController;
use App\Http\Controllers\Api\LocationPingController;
use App\Http\Controllers\EventApiController;
use App\Http\Controllers\EventExecutionController;
use App\Http\Controllers\GeneralSettingController;
use App\Http\Controllers\ProjectApiController;
use App\Http\Controllers\UnavailabilityApiController;
use App\Http\Controllers\UserPlanGroupController;
use App\Http\Controllers\UserPlannableController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::post('location/pings', [LocationPingController::class, 'store']);

    Route::post('device-tokens', [DeviceTokenController::class, 'upsert']);
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

    Route::resource('events', EventApiController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('events/search', [EventApiController::class, 'search']);
    Route::post('events/{event}/copy', [EventApiController::class, 'copy']);
    Route::post('events/{event}/send-confirmation', [EventApiController::class, 'sendConfirmation']);
    Route::get('events/{event}/feedback', [EventApiController::class, 'feedback']);
    Route::get('events/{event}/execution', [EventExecutionController::class, 'show']);
    Route::post('events/{event}/execution/transition', [EventExecutionController::class, 'transition']);
    Route::patch('events/{event}/execution', [EventExecutionController::class, 'update']);

    Route::post('remarks', [\App\Http\Controllers\RemarkController::class, 'store']);
    Route::delete('remarks/{remark}', [\App\Http\Controllers\RemarkController::class, 'destroy']);

    Route::post('images', [\App\Http\Controllers\ImageController::class, 'store']);
    Route::delete('images/{image}', [\App\Http\Controllers\ImageController::class, 'destroy']);
    Route::post('images/update/{image}', [\App\Http\Controllers\ImageController::class, 'update']);
    Route::post('images/{image}/set-main', [\App\Http\Controllers\ImageController::class, 'setMain']);

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
    Route::put('users/{user}/plan-groups', [UserPlanGroupController::class, 'syncUserGroups']);

    Route::patch('users/{user}/plannable', UserPlannableController::class);
});
