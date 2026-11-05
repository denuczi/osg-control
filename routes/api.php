<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\DiagnosticsController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\NetworkUsageController;
use App\Http\Controllers\Api\DevicePairingController;

Route::prefix('v1/agents')->group(function () {
    Route::post('/enroll', EnrollmentController::class)->middleware('throttle:5,1');
    Route::post('/pairing', [DevicePairingController::class, 'create'])->middleware('throttle:30,1');
    Route::get('/pairing/{pairing}', [DevicePairingController::class, 'status'])->middleware('throttle:pairing-poll');
    Route::middleware(['agent', 'throttle:agent'])->group(function () {
        Route::post('/media-events/batch', [AgentController::class, 'mediaEventsBatch']);
        Route::post('/inventory', [AgentController::class, 'inventory']);
        Route::post('/diagnostics', [DiagnosticsController::class, 'snapshot']);
        Route::post('/system-events/batch', [DiagnosticsController::class, 'events']);
        Route::post('/heartbeat', [AgentController::class, 'heartbeat']);
        Route::post('/metrics/batch', [AgentController::class, 'metricsBatch']);
        Route::post('/network-usage/batch', NetworkUsageController::class);
        Route::post('/commands/next', [AgentController::class, 'nextCommand']);
        Route::post('/commands/{command:uuid}/result', [AgentController::class, 'commandResult']);
    });
});
