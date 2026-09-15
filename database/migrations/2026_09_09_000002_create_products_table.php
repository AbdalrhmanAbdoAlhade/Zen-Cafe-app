<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('slug')->unique();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('base_price', 10, 2);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->string('sku')->nullable()->unique();

            // خاصة بمنتجات البن فقط - nullable لباقي الأدوات
            $table->string('country_of_origin')->nullable();
            $table->date('roast_date')->nullable();
            $table->string('brewing_method')->nullable();

            $table->timestamps();

            $table->index(['is_available', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};