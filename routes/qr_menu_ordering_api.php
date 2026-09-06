<?php

use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\StaffAuthController;
use App\Http\Controllers\Api\Cashier\CashierOrderController;
use App\Http\Controllers\Api\Customer\CustomerLoyaltyController;
use App\Http\Controllers\Api\Kitchen\KitchenOrderController;
use App\Http\Controllers\Api\Menu\MenuAccessController;
use App\Http\Controllers\Api\Menu\MenuOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public - Auth
|--------------------------------------------------------------------------
*/
Route::post('/customer/login', [CustomerAuthController::class, 'login']);
Route::post('/staff/login', [StaffAuthController::class, 'login']);

Route::middleware('auth:customer')->group(function () {
    Route::get('/customer/loyalty', [CustomerLoyaltyController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Public - QR Menu (زبون بعد مسح QR)
|--------------------------------------------------------------------------
*/
Route::prefix('menu/{token}')->group(function () {
    // من غير حماية - بس بيانات الفرع الأساسية
    Route::get('/', [MenuAccessController::class, 'show']);
    Route::post('/verify-location', [MenuAccessController::class, 'verifyLocation']);
    Route::post('/manual-access', [MenuAccessController::class, 'verifyManualCode']);

    // محمي بتوكين الوصول المؤقت اللي بيتولد بعد نجاح التحقق فوق
    Route::middleware('menu.access.verified')->group(function () {
        Route::get('/items', [MenuAccessController::class, 'items']);
        Route::post('/orders', [MenuOrderController::class, 'store']);
        Route::get('/orders/{order}', [MenuOrderController::class, 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| Cashier (auth:staff + role: cashier)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:staff', 'staff.role:cashier,manager'])
    ->prefix('cashier')
    ->group(function () {
        Route::get('/orders', [CashierOrderController::class, 'index']);
        Route::post('/orders/{order}/accept', [CashierOrderController::class, 'accept']);
        Route::post('/orders/{order}/reject', [CashierOrderController::class, 'reject']);
        Route::post('/orders/{order}/served', [CashierOrderController::class, 'markServed']);
        Route::post('/orders/{order}/payment', [CashierOrderController::class, 'payment']);
    });

/*
|--------------------------------------------------------------------------
| Kitchen (auth:staff + role: kitchen)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:staff', 'staff.role:kitchen,manager'])
    ->prefix('kitchen')
    ->group(function () {
        Route::get('/orders', [KitchenOrderController::class, 'index']);
        Route::post('/orders/{order}/start-preparing', [KitchenOrderController::class, 'startPreparing']);
        Route::post('/orders/{order}/mark-ready', [KitchenOrderController::class, 'markReady']);
    });
