<?php

use App\Http\Controllers\Api\Auth\AdminAuthController;
use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\StaffAuthController;
use App\Http\Controllers\Api\Cashier\CashierOrderController;
use App\Http\Controllers\Api\Customer\CustomerLoyaltyController;
use App\Http\Controllers\Api\Customer\CustomerProfileController;
use App\Http\Controllers\Api\Kitchen\KitchenOrderController;
use App\Http\Controllers\Api\Menu\MenuAccessController;
use App\Http\Controllers\Api\Menu\MenuOrderController;
use App\Http\Controllers\Api\Menu\OnlineMenuController;
use App\Http\Controllers\Api\Admin\MenuCategoryController;
use App\Http\Controllers\Api\Admin\MenuItemController;
use App\Http\Controllers\Api\Admin\MenuItemBranchController;
use App\Http\Controllers\Api\Admin\TableController;
use App\Http\Controllers\Api\Admin\QrCodeController;
use App\Http\Controllers\Api\Admin\BranchController;
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\LoyaltySettingController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Store\StoreCheckoutController;
use App\Http\Controllers\Api\Store\ProductBrowseController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductCategoryController;
use App\Http\Controllers\Api\Admin\StoreOrderManagementController;
use App\Http\Controllers\Api\Admin\CouponController;
use App\Http\Controllers\Api\CouponValidationController;
use Illuminate\Support\Facades\Route;

/*
|==========================================================================
| PUBLIC ROUTES (بدون تسجيل دخول)
|==========================================================================
*/

/*
|--------------------------------------------------------------------------
| Public - Store (متجر مستلزمات القهوة)
|--------------------------------------------------------------------------
*/
Route::prefix('store')->group(function () {
    Route::get('categories', [ProductBrowseController::class, 'categories']);
    Route::get('products', [ProductBrowseController::class, 'index']);
    Route::get('products/{product}', [ProductBrowseController::class, 'show']);
    Route::get('products/{product}/similar', [ProductBrowseController::class, 'similar']);

    Route::post('orders', [StoreCheckoutController::class, 'store']);
    Route::get('orders/{order}', [StoreCheckoutController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Public - Auth (تسجيل دخول الثلاث أنواع)
|--------------------------------------------------------------------------
*/
Route::post('admin/login', [AdminAuthController::class, 'login']);
Route::post('customer/login', [CustomerAuthController::class, 'login']);
Route::post('staff/login', [StaffAuthController::class, 'login']);
/*
|--------------------------------------------------------------------------
| Public - Coupon Validation
|--------------------------------------------------------------------------
*/
Route::post('coupons/validate', [CouponValidationController::class, 'validate']);

/*
|--------------------------------------------------------------------------
| Public - Online Menu (المنيو الأونلاين)
|--------------------------------------------------------------------------
*/
Route::get('online-menu/{branchId}/items/{itemId}', [OnlineMenuController::class, 'itemDetails']);
Route::get('branches', [OnlineMenuController::class, 'index']);

Route::prefix('online-menu/{branchId}')->group(function () {
    Route::get('/', [OnlineMenuController::class, 'show']);
    Route::get('/items', [OnlineMenuController::class, 'items']);
    Route::post('/orders', [OnlineMenuController::class, 'store']);
    Route::get('/orders/{order}', [OnlineMenuController::class, 'showOrder']);
});

/*
|--------------------------------------------------------------------------
| Public - QR Menu (زبون بعد مسح QR)
|--------------------------------------------------------------------------
*/
Route::get('qr/{qrCode}/image', [QrCodeController::class, 'image']);

Route::prefix('menu/{token}')->group(function () {
    Route::get('/', [MenuAccessController::class, 'show']);
    Route::get('/items', [MenuAccessController::class, 'items']);
    Route::post('/orders', [MenuOrderController::class, 'store']);
    Route::get('/orders/{order}', [MenuOrderController::class, 'show']);
});

/*
|==========================================================================
| CUSTOMER ROUTES (auth:customer)
|==========================================================================
*/
Route::middleware('auth:customer')->group(function () {
    // نقاط الولاء وسجل الحركات
    Route::get('customer/loyalty', [CustomerLoyaltyController::class, 'show']);

    // 🆕 بروفايل العميل: بياناته + نقاط الولاء + إحصائياته + آخر 5 طلبات
    Route::get('customer/profile', [CustomerProfileController::class, 'show']);
    Route::patch('customer/profile', [CustomerProfileController::class, 'update']);

    // 🆕 طلبات العميل مع الفلترة (status / order_type / shipping_status / branch_id / from / to / sort)
    Route::get('customer/profile/orders', [CustomerProfileController::class, 'orders']);
    Route::get('customer/profile/orders/{order}', [CustomerProfileController::class, 'orderDetails']);

    // 🆕 إعادة طلب سابق - بدون سلة، بنفس شروط العميل
    Route::post('customer/profile/orders/{order}/reorder', [CustomerProfileController::class, 'reorder']);
});

/*
|==========================================================================
| ADMIN ROUTES (auth:sanctum → users)
|==========================================================================
*/
Route::middleware('auth:sanctum')
    ->prefix('admin')
    ->group(function () {

        /*
        |------------------------------------------------------------------
        | Admin - Auth Profile (متاح لكل الأدمنز)
        |------------------------------------------------------------------
        */
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);

        /*
        |------------------------------------------------------------------
        | Admin - Read Operations (متاح لكل الأدمنز: super_admin, admin, viewer)
        |------------------------------------------------------------------
        */
        // الفروع
        Route::get('branches', [BranchController::class, 'index']);
        Route::get('branches/{branch}', [BranchController::class, 'show']);

        // تصنيفات المنيو
        Route::get('categories', [MenuCategoryController::class, 'index']);
        Route::get('categories/{category}', [MenuCategoryController::class, 'show']);

        // أصناف المنيو
        Route::get('items', [MenuItemController::class, 'index']);
        Route::get('items/{item}', [MenuItemController::class, 'show']);
        Route::get('items/{item}/branches', [MenuItemBranchController::class, 'index']);
        Route::get('branches/{branch}/items', [MenuItemBranchController::class, 'itemsByBranch']);

        // الموظفين
        Route::get('branches/{branch}/staff', [StaffController::class, 'index']);
        Route::get('staff/{staff}', [StaffController::class, 'show']);

        // الطاولات
        Route::get('branches/{branch}/tables', [TableController::class, 'index']);
        Route::get('tables/{table}', [TableController::class, 'show']);

        // QR Codes
        Route::get('branches/{branch}/qr-codes', [QrCodeController::class, 'index']);

        // المتجر - تصنيفات
        Route::get('product-categories', [ProductCategoryController::class, 'index']);

        // المتجر - منتجات
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/low-stock', [ProductController::class, 'lowStock']);
        Route::get('products/{product}', [ProductController::class, 'show']);

        // المتجر - أوردرات
        Route::get('store-orders', [StoreOrderManagementController::class, 'index']);

        // إعدادات الولاء
        Route::get('loyalty-settings', [LoyaltySettingController::class, 'show']);

        /*
        |------------------------------------------------------------------
        | Admin - Write Operations (super_admin + admin فقط)
        |------------------------------------------------------------------
        */
        Route::middleware('user.role:super_admin,admin,manager')->group(function () {

          // ===== الكوبونات =====
            Route::get('coupons', [CouponController::class, 'index']);
            Route::post('coupons', [CouponController::class, 'store']);
            Route::get('coupons/{coupon}', [CouponController::class, 'show']);
            Route::put('coupons/{coupon}', [CouponController::class, 'update']);
            Route::delete('coupons/{coupon}', [CouponController::class, 'destroy']);
            Route::post('coupons/{coupon}/toggle', [CouponController::class, 'toggle']);
            Route::get('coupons/{coupon}/redemptions', [CouponController::class, 'redemptions']);
          
            // ===== الفروع =====
            Route::post('branches', [BranchController::class, 'store']);
            Route::post('branches/{branch}', [BranchController::class, 'update']);
            Route::post('branches/{branch}/set-main', [BranchController::class, 'setMain']);
            Route::post('branches/{branch}/pause', [BranchController::class, 'pause']);
            Route::post('branches/{branch}/resume', [BranchController::class, 'resume']);
            // 🆕 زمن تجهيز الطلب المسبق (وقت الذروة) - أدمن
            Route::put('branches/{branch}/prep-offset', [BranchController::class, 'updatePrepOffset']);

            // ===== تصنيفات المتجر =====
            Route::post('product-categories', [ProductCategoryController::class, 'store']);
            Route::put('product-categories/{category}', [ProductCategoryController::class, 'update']);

            // ===== منتجات المتجر =====
            Route::post('products', [ProductController::class, 'store']);
            Route::put('products/{product}', [ProductController::class, 'update']);
            Route::post('products/{product}/variants', [ProductController::class, 'addVariant']);
            Route::put('variants/{variant}', [ProductController::class, 'updateVariant']);
            Route::post('variants/{variant}/restock', [ProductController::class, 'restock']);

            // ===== أوردرات المتجر والشحن =====
            Route::post('store-orders/{order}/mark-shipped', [StoreOrderManagementController::class, 'markShipped']);
            Route::post('store-orders/{order}/mark-delivered', [StoreOrderManagementController::class, 'markDelivered']);
            Route::post('store-orders/{order}/cancel', [StoreOrderManagementController::class, 'cancel']);

            // ===== إعدادات الولاء =====
            Route::put('loyalty-settings', [LoyaltySettingController::class, 'update']);

            // ===== الموظفين =====
            Route::post('branches/{branch}/staff', [StaffController::class, 'store']);
            Route::put('staff/{staff}', [StaffController::class, 'update']);
            Route::patch('staff/{staff}', [StaffController::class, 'update']);
            Route::delete('staff/{staff}', [StaffController::class, 'destroy']);

            // ===== الطاولات =====
            Route::post('branches/{branch}/tables', [TableController::class, 'store']);
            Route::put('tables/{table}', [TableController::class, 'update']);
            Route::delete('tables/{table}', [TableController::class, 'destroy']);

            // ===== QR Codes =====
            Route::post('branches/{branch}/qr-codes', [QrCodeController::class, 'store']);
            Route::post('tables/{table}/qr-code', [QrCodeController::class, 'generateForTable']);
            Route::post('qr-codes/{qrCode}/rotate', [QrCodeController::class, 'rotate']);
            Route::post('qr-codes/{qrCode}/manual-code', [QrCodeController::class, 'setManualCode']);
            Route::put('qr-codes/{qrCode}', [QrCodeController::class, 'update']);
            Route::delete('qr-codes/{qrCode}', [QrCodeController::class, 'destroy']);

            // ===== تصنيفات المنيو =====
            Route::post('categories', [MenuCategoryController::class, 'store']);
            Route::post('categories/{category}', [MenuCategoryController::class, 'update']);
            Route::patch('categories/{category}', [MenuCategoryController::class, 'update']);
            Route::delete('categories/{category}', [MenuCategoryController::class, 'destroy']);

            // ===== أصناف المنيو =====
            Route::post('items', [MenuItemController::class, 'store']);
            Route::post('items/{item}', [MenuItemController::class, 'update']);
            Route::patch('items/{item}', [MenuItemController::class, 'update']);
            Route::delete('items/{item}', [MenuItemController::class, 'destroy']);

            // خيارات الصنف
            Route::post('items/{item}/options', [MenuItemController::class, 'storeOption']);
            Route::put('items/{item}/options/{option}', [MenuItemController::class, 'updateOption']);
            Route::delete('items/{item}/options/{option}', [MenuItemController::class, 'destroyOption']);

            // ربط الصنف بالفرع
            Route::post('items/{item}/branches', [MenuItemBranchController::class, 'attach']);
            Route::put('items/{item}/branches/{branch}', [MenuItemBranchController::class, 'update']);
            Route::delete('items/{item}/branches/{branch}', [MenuItemBranchController::class, 'detach']);
        });

        /*
        |------------------------------------------------------------------
        | Admin - Critical Operations (super_admin فقط)
        |------------------------------------------------------------------
        */
        Route::middleware('user.role:super_admin')->group(function () {

            // حذف فرع
            Route::delete('branches/{branch}', [BranchController::class, 'destroy']);

            // حذف تصنيف متجر
            Route::delete('product-categories/{category}', [ProductCategoryController::class, 'destroy']);

            // حذف منتج
            Route::delete('products/{product}', [ProductController::class, 'destroy']);

            // إدارة الأدمنز
            Route::get('users', [AdminUserController::class, 'index']);
            Route::post('users', [AdminUserController::class, 'store']);
            Route::put('users/{user}', [AdminUserController::class, 'update']);
            Route::delete('users/{user}', [AdminUserController::class, 'destroy']);
        });
    });

/*
|==========================================================================
| STAFF ROUTES (auth:staff OR auth:sanctum → يدعم الأدمن كمان)
|==========================================================================
| الأدمن يقدر يشوف (GET) فقط، ممنوع من العمليات التعديلية (POST)
|==========================================================================
*/

/*
|--------------------------------------------------------------------------
| Cashier (role: cashier, manager + admin read-only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:staff,sanctum', 'staff.role:cashier,manager'])
    ->prefix('cashier')
    ->group(function () {
        // 📖 read — متاح للأدمن
        Route::get('orders', [CashierOrderController::class, 'index']);
        Route::put('branches/{branch}/prep-offset', [BranchController::class, 'updatePrepOffset']);

        // ✍️ write — للموظفين بس (الأدمن بيترفض من الكنترولر)
        Route::post('orders/{order}/accept', [CashierOrderController::class, 'accept']);
        Route::post('orders/{order}/reject', [CashierOrderController::class, 'reject']);
        Route::post('orders/{order}/served', [CashierOrderController::class, 'markServed']);
        Route::post('orders/{order}/payment', [CashierOrderController::class, 'payment']);
    });

/*
|--------------------------------------------------------------------------
| Kitchen (role: kitchen, manager + admin read-only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:staff,sanctum', 'staff.role:kitchen,manager'])
    ->prefix('kitchen')
    ->group(function () {
        // 📖 read — متاح للأدمن
        Route::get('orders', [KitchenOrderController::class, 'index']);

        // ✍️ write — للموظفين بس (الأدمن بيترفض من الكنترولر)
        Route::post('orders/{order}/start-preparing', [KitchenOrderController::class, 'startPreparing']);
        Route::post('orders/{order}/mark-ready', [KitchenOrderController::class, 'markReady']);
    });

/*
|--------------------------------------------------------------------------
| 🆕 Manager (role: manager) - عمليات مدير الفرع
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:staff,sanctum', 'staff.role:manager'])
    ->prefix('manager')
    ->group(function () {
        // زمن تجهيز الطلب المسبق (وقت الذروة) - نفس دالة الأدمن، متاحة لمدير الفرع بس
        Route::put('branches/{branch}/prep-offset', [BranchController::class, 'updatePrepOffset']);
    });