<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->enum('type', ['branch', 'table'])->default('branch');
            $table->unsignedInteger('radius_override')->nullable();
            $table->string('manual_access_code')->nullable(); // مخزّن مشفّر (hashed)
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
