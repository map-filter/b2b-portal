<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartImportController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Manager;
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
        Route::post('/cart/import', [CartImportController::class, 'store']);
        Route::get('/cart/import/template', [CartImportController::class, 'template']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/repeat', [OrderController::class, 'repeat']);
        Route::get('/orders/{order}/invoice', [OrderController::class, 'invoice']);
    });

    Route::middleware('staff')->prefix('manager')->name('manager.')->group(function () {
        Route::get('/dashboard', [Manager\ReportController::class, 'dashboard']);
        Route::get('/exchange/imports', [Manager\ExchangeController::class, 'imports']);

        Route::get('/orders', [Manager\OrderController::class, 'index']);
        Route::get('/orders/{order}', [Manager\OrderController::class, 'show']);
        Route::patch('/orders/{order}/status', [Manager\OrderController::class, 'updateStatus']);
        Route::get('/orders/{order}/invoice', [OrderController::class, 'invoice']);

        Route::get('/companies', [Manager\CompanyController::class, 'index']);
        Route::get('/companies/{company}', [Manager\CompanyController::class, 'show']);
        Route::put('/companies/{company}', [Manager\CompanyController::class, 'update']);
        Route::post('/companies/{company}/users', [Manager\CompanyController::class, 'storeUser']);
        Route::patch('/companies/{company}/users/{user}/toggle', [Manager\CompanyController::class, 'toggleUser']);

        Route::get('/companies/{company}/prices', [Manager\ContractPriceController::class, 'index']);
        Route::put('/companies/{company}/prices/{product}', [Manager\ContractPriceController::class, 'upsert']);
        Route::delete('/companies/{company}/prices/{product}', [Manager\ContractPriceController::class, 'destroy']);
    });
});
