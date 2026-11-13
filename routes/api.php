<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RequestCommentController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Http\Controllers\Api\ServiceRequestWorkflowController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('requests', ServiceRequestController::class)
        ->only(['index', 'store', 'show', 'update'])
        ->parameters(['requests' => 'serviceRequest']);

    Route::prefix('requests/{serviceRequest}')
        ->controller(ServiceRequestWorkflowController::class)
        ->group(function () {
            Route::post('submit', 'submit');
            Route::post('decision', 'decision');
            Route::post('assign', 'assign');
            Route::post('status', 'status');
        });

    Route::post('requests/{serviceRequest}/comments', [RequestCommentController::class, 'store']);

    Route::get('notifications', [NotificationController::class, 'index']);
});
