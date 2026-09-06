<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qr_code_id')->constrained('qr_codes')->cascadeOnDelete();
            $table->string('ip')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('distance_from_branch', 10, 2)->nullable(); // بالمتر
            $table->enum('access_method', ['geofence', 'manual_code'])->default('geofence');
            $table->boolean('allowed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_access_logs');
    }
};
