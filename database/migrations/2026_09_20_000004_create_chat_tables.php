<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_entries', function (Blueprint $table) {
            $table->id();
            $table->string('intent_key', 50); // branches, hours, menu, order_status, loyalty, shipping, returns, handoff
            $table->json('keywords');         // ["فين طلبي", "حالة الطلب"]
            $table->text('reply_ar');
            $table->text('reply_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['intent_key', 'is_active']);
        });

        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();
            $table->text('welcome_message_ar')->nullable();
            $table->text('welcome_message_en')->nullable();
            $table->text('offline_message_ar')->nullable();
            $table->text('offline_message_en')->nullable();
            $table->json('handoff_triggers')->nullable(); // ["موظف", "خدمة عملاء", "agent"]
            $table->boolean('chat_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('phone', 30)->nullable()->index();
            $table->string('guest_name')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('channel', 20)->default('web'); // web, app
            $table->string('status', 30)->default('bot');  // bot, waiting_agent, with_agent, closed
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('sender_type', 20); // customer, bot, staff
            $table->unsignedBigInteger('sender_id')->nullable(); // customer_id أو staff_id
            $table->text('body');
            $table->json('metadata')->nullable(); // intent, order_id, ...
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('chat_settings');
        Schema::dropIfExists('faq_entries');
    }
};