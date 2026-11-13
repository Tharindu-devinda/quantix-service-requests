<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServiceRequestController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('requests', ServiceRequestController::class)
        ->only(['index', 'store', 'show'])
        ->parameters(['requests' => 'serviceRequest']);

    Route::apiResource('requests', ServiceRequestController::class)
        ->only(['index', 'store', 'show', 'update'])
        ->parameters(['requests' => 'serviceRequest']);
});
