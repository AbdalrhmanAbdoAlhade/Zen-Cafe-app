<?php

use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\StaffAuthController;
use App\Http\Controllers\Api\Cashier\CashierOrderController;
use App\Http\Controllers\Api\Customer\CustomerLoyaltyController;
use App\Http\Controllers\Api\Kitchen\KitchenOrderController;
use App\Http\Controllers\Api\Menu\MenuAccessController;
use App\Http\Controllers\Api\Menu\MenuOrderController;
use App\Http\Controllers\Api\Admin\MenuCategoryController;
use App\Http\Controllers\Api\Admin\MenuItemController;
use App\Http\Controllers\Api\Admin\MenuItemBranchController;
use App\Http\Controllers\Api\Admin\TableController;
use App\Http\Controllers\Api\Admin\QrCodeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin - Menu Management (auth:sanctum → users)
|--------------------------------------------------------------------------
*/
// عام — بدون auth
Route::get('/qr/{qrCode}/image', [QrCodeController::class, 'image']);

Route::middleware('auth:sanctum')
    ->prefix('admin')
    ->group(function () {

        // ===== التصنيفات (الأصناف الرئيسية) =====
      // ===== الطاولات =====
Route::get('branches/{branch}/tables', [TableController::class, 'index']);
Route::post('branches/{branch}/tables', [TableController::class, 'store']);
Route::get('tables/{table}', [TableController::class, 'show']);
Route::put('tables/{table}', [TableController::class, 'update']);
Route::delete('tables/{table}', [TableController::class, 'destroy']);

// ===== QR Codes =====
Route::get('branches/{branch}/qr-codes', [QrCodeController::class, 'index']);
Route::post('branches/{branch}/qr-codes', [QrCodeController::class, 'store']);          // QR فرع أو طاولة
Route::post('tables/{table}/qr-code', [QrCodeController::class, 'generateForTable']); // توليد QR لطاولة
Route::post('qr-codes/{qrCode}/rotate', [QrCodeController::class, 'rotate']);         // تجديد التوكين
Route::post('qr-codes/{qrCode}/manual-code', [QrCodeController::class, 'setManualCode']);
Route::put('qr-codes/{qrCode}', [QrCodeController::class, 'update']);
Route::delete('qr-codes/{qrCode}', [QrCodeController::class, 'destroy']);
  // ===== التصنيفات (الأصناف الرئيسية) =====
Route::get('categories', [MenuCategoryController::class, 'index']);
Route::post('categories', [MenuCategoryController::class, 'store']);
Route::get('categories/{category}', [MenuCategoryController::class, 'show']);
Route::post('categories/{category}', [MenuCategoryController::class, 'update']);
Route::patch('categories/{category}', [MenuCategoryController::class, 'update']);
Route::delete('categories/{category}', [MenuCategoryController::class, 'destroy']);

        // ===== الأصناف (المنتجات) =====
   // ===== الأصناف (المنتجات) =====
Route::get('items', [MenuItemController::class, 'index']);
Route::post('items', [MenuItemController::class, 'store']);
Route::get('items/{item}', [MenuItemController::class, 'show']);
Route::post('items/{item}', [MenuItemController::class, 'update']);
Route::patch('items/{item}', [MenuItemController::class, 'update']);
Route::delete('items/{item}', [MenuItemController::class, 'destroy']);

        // خيارات الصنف (Options + Values)
        Route::post('items/{item}/options', [MenuItemController::class, 'storeOption']);
        Route::put('items/{item}/options/{option}', [MenuItemController::class, 'updateOption']);
        Route::delete('items/{item}/options/{option}', [MenuItemController::class, 'destroyOption']);

        // ===== ربط الصنف بالفرع (الاستوك / التوفر / السعر الخاص) =====
        Route::get('items/{item}/branches', [MenuItemBranchController::class, 'index']);
        Route::post('items/{item}/branches', [MenuItemBranchController::class, 'attach']);
        Route::put('items/{item}/branches/{branch}', [MenuItemBranchController::class, 'update']);
        Route::delete('items/{item}/branches/{branch}', [MenuItemBranchController::class, 'detach']);

        // قائمة سريعة لكل الأصناف المربوطة بفرع معين
        Route::get('branches/{branch}/items', [MenuItemBranchController::class, 'itemsByBranch']);
    });

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
