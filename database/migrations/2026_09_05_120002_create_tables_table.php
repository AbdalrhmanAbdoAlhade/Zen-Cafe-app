<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('table_number');
            // qr_code_id هيتضاف كـ Foreign Key في migration منفصلة بعد إنشاء جدول qr_codes
            // (منعًا للتعارض الدائري بين الجدولين)
            $table->unsignedBigInteger('qr_code_id')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'table_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
