<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar');
            $table->string('title_en');
            $table->string('file');                 // مسار الملف
            $table->string('original_name');        // الاسم الأصلي للملف
            $table->string('file_type');            // pdf, word, image, excel
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable(); // بالبايت
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};