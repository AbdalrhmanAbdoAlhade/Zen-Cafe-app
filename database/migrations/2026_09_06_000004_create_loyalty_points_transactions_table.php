<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_points_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 20);
            $table->unsignedInteger('points');
            $table->unsignedInteger('balance_after');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'type']);
            $table->unique(['customer_id', 'order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_points_transactions');
    }
};
