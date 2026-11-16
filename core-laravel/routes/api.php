<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartImportController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Integration\BotController;
use App\Http\Controllers\Api\KaspiPriceListController;
use App\Http\Controllers\Api\Manager;
use App\Http\Controllers\Api\Manager\Kaspi;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TelegramLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/kaspi/pricelist/{token}.xml', KaspiPriceListController::class)->middleware('throttle:30,1');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/telegram/link', [TelegramLinkController::class, 'store']);
    Route::delete('/telegram/link', [TelegramLinkController::class, 'destroy']);
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

        Route::prefix('kaspi')->group(function () {
            Route::get('/dashboard', Kaspi\KaspiReportController::class);
            Route::get('/orders', [Kaspi\KaspiOrderController::class, 'index']);
            Route::get('/cancel-reasons', [Kaspi\KaspiOrderController::class, 'reasons']);
            Route::get('/orders/{order}', [Kaspi\KaspiOrderController::class, 'show']);
            Route::post('/orders/{order}/accept', [Kaspi\KaspiOrderController::class, 'accept']);
            Route::post('/orders/{order}/assemble', [Kaspi\KaspiOrderController::class, 'assemble']);
            Route::post('/orders/{order}/send-code', [Kaspi\KaspiOrderController::class, 'sendCode']);
            Route::post('/orders/{order}/complete', [Kaspi\KaspiOrderController::class, 'complete']);
            Route::post('/orders/{order}/cancel', [Kaspi\KaspiOrderController::class, 'cancel']);
            Route::get('/orders/{order}/waybill', [Kaspi\KaspiOrderController::class, 'waybill']);

            Route::get('/products', [Kaspi\KaspiProductController::class, 'index']);
            Route::post('/products', [Kaspi\KaspiProductController::class, 'store']);
            Route::get('/products/template', [Kaspi\KaspiProductController::class, 'template']);
            Route::post('/products/import', [Kaspi\KaspiProductController::class, 'import']);
            Route::patch('/products/{kaspiProduct}', [Kaspi\KaspiProductController::class, 'update']);
            Route::delete('/products/{kaspiProduct}', [Kaspi\KaspiProductController::class, 'destroy']);

            Route::get('/settings', [Kaspi\KaspiSettingsController::class, 'show']);
            Route::put('/settings', [Kaspi\KaspiSettingsController::class, 'update']);
            Route::post('/sync', [Kaspi\KaspiSettingsController::class, 'sync']);
        });
    });
});

Route::middleware('integration')->prefix('integration')->group(function () {
    Route::post('/telegram/link', [BotController::class, 'link']);
    Route::get('/telegram/users/{chatId}', [BotController::class, 'user'])->whereNumber('chatId');
    Route::get('/users', [BotController::class, 'userByEmail']);
    Route::post('/users/{user}/match', [BotController::class, 'match']);
    Route::post('/users/{user}/cart', [BotController::class, 'addToCart']);
    Route::get('/users/{user}/orders', [BotController::class, 'orders']);
});
