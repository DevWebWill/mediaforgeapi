<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class)->middleware('throttle:health');

    Route::middleware(['auth.api', 'throttle:api'])->group(function () {
        Route::get('operations', [CatalogController::class, 'operations']);
        Route::get('operations/{operation}', [CatalogController::class, 'operation']);
        Route::get('profiles', [CatalogController::class, 'profiles']);
        Route::get('profiles/{profile}', [CatalogController::class, 'profile']);

        Route::post('files', [FileController::class, 'store']);
        Route::get('files', [FileController::class, 'index']);
        Route::get('files/{file}', [FileController::class, 'show']);
        Route::get('files/{file}/download', [FileController::class, 'download']);
        Route::delete('files/{file}', [FileController::class, 'destroy']);

        Route::post('jobs', [JobController::class, 'store']);
        Route::get('jobs', [JobController::class, 'index']);
        Route::get('jobs/{job}', [JobController::class, 'show']);
        Route::post('jobs/{job}/cancel', [JobController::class, 'cancel']);
        Route::get('jobs/{job}/result', [JobController::class, 'result']);
        Route::get('jobs/{job}/logs', [JobController::class, 'logs']);

        Route::post('webhooks', [WebhookController::class, 'store']);
        Route::get('webhooks', [WebhookController::class, 'index']);
        Route::get('webhooks/{webhook}', [WebhookController::class, 'show']);
        Route::patch('webhooks/{webhook}', [WebhookController::class, 'update']);
        Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy']);
        Route::post('webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret']);
        Route::post('webhooks/{webhook}/ping', [WebhookController::class, 'ping']);
        Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
        Route::post('webhooks/{webhook}/deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver']);
    });
});
