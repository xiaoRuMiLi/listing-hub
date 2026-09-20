<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DesignController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // 公共：登录
    Route::post('auth/login', [AuthController::class, 'login']);

    // 需鉴权（Sanctum Token）
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('me/password', [AuthController::class, 'changePassword']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('tokens', [AuthController::class, 'tokens']);

        // 同步（本地 CLI 主力）
        Route::post('sync/push', [SyncController::class, 'push']);
        Route::get('sync/pull', [SyncController::class, 'pull']);

        // 商品域
        Route::apiResource('products', ProductController::class);

        // 设计域
        Route::apiResource('designs', DesignController::class);

        // 上架域
        Route::get('listings/{id}/children', [ListingController::class, 'children']);
        Route::get('listings/{id}/revisions', [ListingController::class, 'revisions']);
        Route::post('listings/{id}/publish-result', [ListingController::class, 'publishResult']);
        Route::apiResource('listings', ListingController::class);

        // 资产域（OSS 图床）
        Route::post('assets/check', [AssetController::class, 'check']);
        Route::post('assets/upload', [AssetController::class, 'upload']);
        Route::post('assets/mirror', [AssetController::class, 'mirror']);
        Route::get('assets/{id}', [AssetController::class, 'show']);
        Route::delete('assets/{id}', [AssetController::class, 'destroy']);
    });
});
