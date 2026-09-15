<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // مثال: {"weight": "250g", "grind": "espresso", "roast": "medium"} أو {"color": "black", "size": "M"}
            $table->json('attributes')->nullable();

            $table->decimal('price_override', 10, 2)->nullable();
            $table->string('sku')->nullable()->unique();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};