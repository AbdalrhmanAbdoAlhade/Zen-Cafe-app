<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_settings', function (Blueprint $table) {
            $table->id();
            $table->string('seller_name');
            $table->string('vat_number', 15);
            $table->string('cr_number', 20)->nullable();
            $table->string('address_ar')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('last_invoice_seq')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_settings');
    }
};