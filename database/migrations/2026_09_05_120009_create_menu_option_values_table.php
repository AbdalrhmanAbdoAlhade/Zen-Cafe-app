<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_option_id')->constrained('menu_options')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('extra_price', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_option_values');
    }
};
