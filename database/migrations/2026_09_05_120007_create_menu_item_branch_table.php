<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->decimal('price_override', 10, 2)->nullable(); // لو null بيستخدم base_price
            $table->boolean('is_available')->default(true); // توفر خاص بالفرع ده
            $table->boolean('is_featured')->default(false); // عرض خاص بزوار الفرع
            $table->timestamps();

            $table->unique(['menu_item_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_branch');
    }
};
