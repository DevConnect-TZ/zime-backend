<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EpisodeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VideoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication (Firebase ID token -> app session)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/session', [AuthController::class, 'session'])->middleware('throttle:auth');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth.token');
});

/*
|--------------------------------------------------------------------------
| Public catalogue
|--------------------------------------------------------------------------
*/
Route::middleware('auth.optional')->group(function () {
    Route::get('/videos/trending', [VideoController::class, 'trending']);
    Route::get('/videos/popular', [VideoController::class, 'popular']);
    Route::get('/videos', [VideoController::class, 'index']);
    Route::get('/videos/{video}', [VideoController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Payment webhook (signature-verified, unauthenticated)
|--------------------------------------------------------------------------
*/
Route::post('/payments/webhook/sonicpesa', [PaymentController::class, 'webhook'])
    ->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| Authenticated users
|--------------------------------------------------------------------------
*/
Route::middleware('auth.token')->group(function () {
    Route::post('/videos/{video}/stream', [VideoController::class, 'stream']);

    Route::get('/transactions', [TransactionController::class, 'index']);

    Route::prefix('payments')->group(function () {
        Route::get('/gateway', [PaymentController::class, 'gateway']);
        Route::post('/sonicpesa/order', [PaymentController::class, 'createOrder'])->middleware('throttle:payments');
        Route::get('/sonicpesa/orders/{orderId}', [PaymentController::class, 'orderStatus'])->middleware('throttle:payments');
    });

    /*
    |----------------------------------------------------------------------
    | Uploaders & admins: catalogue management + media uploads
    |----------------------------------------------------------------------
    */
    Route::middleware('role:uploader')->group(function () {
        Route::post('/videos', [VideoController::class, 'store']);
        Route::put('/videos/{video}', [VideoController::class, 'update']);
        Route::delete('/videos/{video}', [VideoController::class, 'destroy']);

        Route::post('/videos/{video}/episodes', [EpisodeController::class, 'store']);
        Route::put('/videos/{video}/episodes/{episode}', [EpisodeController::class, 'update']);
        Route::delete('/videos/{video}/episodes/{episode}', [EpisodeController::class, 'destroy']);

        Route::post('/uploads/image', [UploadController::class, 'image']);
        Route::post('/uploads/video', [UploadController::class, 'video']);
        Route::post('/uploads/video/chunk', [UploadController::class, 'videoChunk']);
    });

    /*
    |----------------------------------------------------------------------
    | Admin only: user management + gateway configuration
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::put('/users/{user}/role', [UserController::class, 'updateRole']);
        Route::put('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::post('/users/{user}/unlock-video', [UserController::class, 'unlockVideo']);

        Route::put('/payments/gateway', [PaymentController::class, 'updateGateway']);
    });
});
