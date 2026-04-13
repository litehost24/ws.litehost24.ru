<?php

use App\Http\Controllers\AppBindController;
use App\Http\Controllers\AppManagedSubscriptionController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and assigned to the
| "api" middleware group.
|
*/

Route::post('/telegram/webhook/{secret}', [TelegramWebhookController::class, 'handle'])
    ->name('telegram.webhook');

Route::post('/app/bind', [AppBindController::class, 'store'])->name('app.bind');

Route::middleware(['auth:sanctum', 'app.managed_session'])->prefix('app')->group(function () {
    Route::get('/managed/subscription', [AppManagedSubscriptionController::class, 'show'])
        ->name('app.managed.subscription.show');
    Route::get('/managed/subscription/manifest', [AppManagedSubscriptionController::class, 'manifest'])
        ->name('app.managed.subscription.manifest');
    Route::get('/managed/subscription/config', [AppManagedSubscriptionController::class, 'config'])
        ->name('app.managed.subscription.config');
    Route::post('/unbind', [AppManagedSubscriptionController::class, 'unbind'])
        ->name('app.unbind');
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
