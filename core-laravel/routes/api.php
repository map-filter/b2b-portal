<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories');
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/facets', [ProductController::class, 'facets'])->name('facets');
        Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');
    });

    Route::middleware('client')->group(function () {
        Route::get('/cart', [CartController::class, 'show']);
        Route::put('/cart/items/{product}', [CartController::class, 'put']);
        Route::delete('/cart/items/{product}', [CartController::class, 'remove']);
        Route::delete('/cart', [CartController::class, 'clear']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/repeat', [OrderController::class, 'repeat']);
    });
});
