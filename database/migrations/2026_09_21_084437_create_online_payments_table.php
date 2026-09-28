<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 20)->default('mpgs');
            $table->string('gateway_order_id', 40)->unique();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('initiated')->index();
            $table->string('session_id')->nullable();
            $table->string('success_indicator')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('refund_transaction_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
    }
};