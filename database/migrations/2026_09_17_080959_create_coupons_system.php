<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ========== الكوبونات الرئيسية ==========
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            $table->enum('type', [
                'percentage',
                'fixed',
                'buy_x_get_y',
                'free_shipping',
            ]);

            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();

            $table->unsignedInteger('buy_quantity')->nullable();
            $table->unsignedInteger('get_quantity')->nullable();
            $table->enum('get_discount_type', ['free', 'percentage', 'fixed'])->nullable();
            $table->decimal('get_discount_value', 10, 2)->nullable();

            $table->decimal('min_order_amount', 10, 2)->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->boolean('is_stackable')->default(false);
            $table->boolean('exclude_discounted_items')->default(false);

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('active_hours')->nullable();

            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);

            $table->json('channels')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['code', 'is_active']);
            $table->index(['starts_at', 'ends_at']);
        });

        // ========== ربط الكوبون بالفروع ==========
        Schema::create('coupon_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unique(['coupon_id', 'branch_id']);
        });

        // ========== ربط الكوبون بمنتجات المتجر ==========
        Schema::create('coupon_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['coupon_id', 'product_id']);
        });

        // ========== ربط الكوبون بأصناف المنيو ==========
        Schema::create('coupon_menu_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->unique(['coupon_id', 'menu_item_id']);
        });

        // ========== ربط الكوبون بتصنيفات المنتجات ==========
        Schema::create('coupon_product_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->unique(['coupon_id', 'product_category_id']);
        });

        // ========== سجل استخدام الكوبونات ==========
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('discount_amount', 10, 2);
            $table->string('code_used');
            $table->timestamps();

            $table->index(['coupon_id', 'customer_id']);
            $table->index('order_id');
        });

        // ========== إضافة حقول الكوبون على جدول الطلبات ==========
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('earned_points')
                ->constrained('coupons')->nullOnDelete();
            $table->string('coupon_code')->nullable()->after('coupon_id');
            $table->decimal('coupon_discount', 10, 2)->default(0)->after('coupon_code');
            $table->boolean('free_shipping')->default(false)->after('coupon_discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'coupon_discount', 'free_shipping']);
        });

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupon_product_category');
        Schema::dropIfExists('coupon_menu_item');
        Schema::dropIfExists('coupon_product');
        Schema::dropIfExists('coupon_branch');
        Schema::dropIfExists('coupons');
    }
};