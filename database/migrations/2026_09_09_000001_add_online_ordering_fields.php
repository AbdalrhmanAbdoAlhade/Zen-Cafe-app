<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_online_paused')->default(false)->after('is_active');
            $table->string('pause_reason')->nullable()->after('is_online_paused');
            $table->timestamp('paused_at')->nullable()->after('pause_reason');
        });
        Schema::table('menu_items', function (Blueprint $table) {
            $table->unsignedInteger('preparation_time_minutes')->default(0)->after('base_price');
            $table->boolean('is_available_online')->default(true)->after('is_available');
            $table->decimal('vat', 5, 2)->default(0)->after('is_available_online');
            $table->unsignedInteger('calories')->nullable()->after('vat');
            $table->json('allergens')->nullable()->after('calories');
            $table->json('ingredients')->nullable()->after('allergens');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('table_id')->nullable()->change();
            $table->foreignId('qr_code_id')->nullable()->change();
            $table->enum('order_type', ['in_branch', 'pre_order'])->default('in_branch')->after('customer_id');
            $table->unsignedInteger('estimated_preparation_minutes')->default(0)->after('total_amount');
            $table->timestamp('estimated_ready_at')->nullable()->after('estimated_preparation_minutes');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('preparation_time_minutes')->default(0)->after('unit_price');
            $table->decimal('vat', 5, 2)->default(0)->after('preparation_time_minutes');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }
    public function down(): void {
        Schema::table('customers', fn (Blueprint $table) => $table->string('password')->nullable(false)->change());
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['preparation_time_minutes', 'vat']));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'estimated_preparation_minutes', 'estimated_ready_at']);
            $table->foreignId('table_id')->nullable(false)->change();
            $table->foreignId('qr_code_id')->nullable(false)->change();
        });
        Schema::table('menu_items', fn (Blueprint $table) => $table->dropColumn(['preparation_time_minutes', 'is_available_online', 'vat', 'calories', 'allergens', 'ingredients']));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['is_online_paused', 'pause_reason', 'paused_at']));
    }
};
