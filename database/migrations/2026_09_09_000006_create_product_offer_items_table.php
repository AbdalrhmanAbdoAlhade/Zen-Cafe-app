<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_offer_id')->constrained('product_offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_offer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_offer_items');
    }
};