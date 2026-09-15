<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // على orders: بس النوع العام (استلام/شحن) وبيانات الدفع - مفيش تفاصيل شحن هنا
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('fulfillment_type', ['pickup', 'shipping'])->nullable()->after('order_type');
            $table->string('payment_gateway')->nullable()->after('fulfillment_type');
            $table->string('payment_reference')->nullable()->after('payment_gateway');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('menu_item_id')
                ->constrained('product_variants')->nullOnDelete();
        });

        // جدول الشحن - علاقة واحد لواحد مع الأوردر، بيتعمل بس لو fulfillment_type = shipping
        Schema::create('order_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();

            $table->string('recipient_name');
            $table->string('recipient_phone');
            $table->string('city');
            $table->string('address_line');
            $table->string('building')->nullable();
            $table->string('landmark')->nullable();

            $table->decimal('shipping_fee', 10, 2)->default(0);
            $table->enum('status', ['pending', 'shipped', 'delivered'])->default('pending');
            $table->string('tracking_number')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipments');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_type', 'payment_gateway', 'payment_reference']);
        });
    }
};