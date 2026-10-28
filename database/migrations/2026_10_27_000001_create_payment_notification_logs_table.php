<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('online_payment_id')->constrained('online_payments')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('channel', 20); // email | whatsapp
            $table->string('status', 20)->default('pending'); // pending | sent | failed | skipped
            $table->string('skip_reason')->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['online_payment_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notification_logs');
    }
};