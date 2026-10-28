<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete(); // الأدمن

            $table->string('channel', 20); // email | whatsapp
            $table->string('to_address'); // الإيميل أو الرقم بعد التنظيف
            $table->string('subject')->nullable(); // للإيميل فقط
            $table->text('body');
            $table->string('status', 20)->default('pending'); // pending | sent | failed
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'channel']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_message_logs');
    }
};