<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\SpotController;
use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::middleware(['auth:sanctum', EnsureEmailIsVerified::class])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('user', [AuthController::class, 'user'])->name('user');

        Route::apiResource('spots', SpotController::class)->only(['index', 'show', 'store']);
        Route::apiResource('locations', LocationController::class)->only(['index', 'store']);

        Route::apiResource('imports', ImportController::class)->only(['index', 'show', 'store', 'destroy']);
        Route::post('imports/{import}/retry', [ImportController::class, 'retry'])->name('imports.retry');
        Route::post('imports/{import}/convert', [ImportController::class, 'convert'])->name('imports.convert');

        Route::apiResource('items', ItemController::class)->only(['index', 'show', 'store', 'destroy']);
        Route::patch('items/{item}', [ItemController::class, 'update'])->name('items.update');

        Route::get('imports/{import}/photos/{photo}', [PhotoController::class, 'import'])->name('imports.photos.show');
        Route::get('items/{item}/photos/{photo}', [PhotoController::class, 'item'])->name('items.photos.show');
    });
});
