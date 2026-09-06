<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('menu_option_value_id')->constrained('menu_option_values')->restrictOnDelete();
            $table->decimal('extra_price', 10, 2)->default(0); // نسخة من السعر وقت الطلب
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
    }
};
