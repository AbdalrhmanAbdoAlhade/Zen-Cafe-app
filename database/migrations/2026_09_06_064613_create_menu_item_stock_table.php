<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->integer('quantity')->default(0)->comment('الكمية المتاحة');
            $table->integer('min_alert')->default(5)->comment('حد التنبيه عند انخفاض المخزون');
            $table->timestamps();

            // منع تكرار نفس المنتج في نفس الفرع
            $table->unique(['menu_item_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_stock');
    }
};