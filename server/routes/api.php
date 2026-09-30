<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeleteController;
use App\Http\Controllers\Api\DesignController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UserController;
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

        // 用户账号
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);

        // 同步（本地 CLI 主力）
        Route::post('sync/push', [SyncController::class, 'push']);
        Route::get('sync/pull', [SyncController::class, 'pull']);

        // 商品域
        Route::apiResource('products', ProductController::class);

        // 设计域
        Route::post('designs/{id}/normalize-images', [DesignController::class, 'normalizeImages']);
        Route::apiResource('designs', DesignController::class);

        // 上架域
        Route::post('listings/oss-images', [ListingController::class, 'ossImages']);
        Route::post('listings/{id}/switch-cdn', [ListingController::class, 'switchCdn']);
        Route::get('listings/{id}/children', [ListingController::class, 'children']);
        Route::get('listings/{id}/revisions', [ListingController::class, 'revisions']);
        // ★ 上架/下架回写：支持按 id 或按 sku（异地/ERP 只需知道 SKU）
        Route::get('listings/by-sku/{sku}', [ListingController::class, 'bySku']);
        Route::post('listings/publish-result', [ListingController::class, 'publishResult']);
        Route::post('listings/{id}/publish-result', [ListingController::class, 'publishResult']);
        Route::post('listings/unpublish', [ListingController::class, 'unpublish']);
        Route::post('listings/{id}/unpublish', [ListingController::class, 'unpublish']);
        Route::post('listings/{id}/normalize-images', [ListingController::class, 'normalizeImages']);
        Route::apiResource('listings', ListingController::class);

        // ★ 删除（软删）2026-09-29：整体删商品 + 分部删子资源
        Route::delete('products/{code}', [DeleteController::class, 'destroyProduct']);
        Route::delete('products/{code}/variants/{external_variant_id}', [DeleteController::class, 'destroyVariant']);
        Route::delete('products/{code}/shipping', [DeleteController::class, 'destroyShipping']);
        Route::delete('designs/by-code/{design_code}', [DeleteController::class, 'destroyDesign']);
        Route::delete('listings/by-sku/{sku}', [DeleteController::class, 'destroyListing']);

        // ★ 维护（R16）：物理清理软删行（幽灵行）——仅管理员
        Route::match(['post', 'delete'], 'maintenance/trashed', [MaintenanceController::class, 'purgeTrashed']);

        // 资产域（OSS 图床）
        Route::post('assets/check', [AssetController::class, 'check']);
        Route::post('assets/upload', [AssetController::class, 'upload']);
        Route::post('assets/mirror', [AssetController::class, 'mirror']);
        Route::get('assets/{id}', [AssetController::class, 'show']);
        Route::delete('assets/{id}', [AssetController::class, 'destroy']);
    });
});
