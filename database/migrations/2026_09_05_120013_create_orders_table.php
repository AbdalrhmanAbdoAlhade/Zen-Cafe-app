<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->foreignId('qr_code_id')->constrained('qr_codes')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->enum('status', [
                'pending', 'accepted', 'rejected', 'preparing', 'ready', 'served', 'paid', 'cancelled',
            ])->default('pending');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
